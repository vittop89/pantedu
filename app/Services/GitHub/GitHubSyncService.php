<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Core\Database;
use App\Services\Crypto\TeacherCryptoService;
use PDO;
use RuntimeException;

/**
 * G22.S15.bis Fase 5 — GitHub sync via Personal Access Token (fine-grained).
 *
 * Memorizza configurazione (PAT cifrato envelope con TKEK + repo target) in
 * `teacher_github_sync` (migration 034). Push file via REST v3:
 *   PUT /repos/{owner}/{repo}/contents/{path}
 *   body: { message, content (base64), branch, sha?(se update) }
 *
 * Rate limit: 5000 req/hour authenticated. Per docente ~50 file → safe.
 *
 * Sicurezza:
 *   - PAT mai in chiaro nel DB (envelope encrypt con TKEK del docente)
 *   - PAT fine-grained con scope minimo (Contents: R/W) limita blast radius
 *   - Owner check su tutte le query
 */
final class GitHubSyncService
{
    private const GITHUB_API = 'https://api.github.com';
    private const USER_AGENT = 'Pantedu-Sync/1.0';

    public function __construct(
        private readonly TeacherCryptoService $crypto,
    ) {
    }

    /**
     * Salva configurazione GitHub: cifra il PAT con TKEK, scrive row.
     * Se già esiste, aggiorna (UPSERT).
     *
     * @return array{ok:true, repo_owner:string, repo_name:string, branch:string}
     */
    public function configure(int $teacherId, string $pat, string $repoOwner, string $repoName, string $branch = 'main'): array
    {
        if ($pat === '' || strlen($pat) < 20) {
            throw new RuntimeException('github_pat_invalid');
        }
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,38}$/', $repoOwner)) {
            throw new RuntimeException('github_owner_invalid');
        }
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,99}$/', $repoName)) {
            throw new RuntimeException('github_repo_invalid');
        }
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._\/-]{0,99}$/', $branch)) {
            throw new RuntimeException('github_branch_invalid');
        }

        // Test PAT: chiama /repos/{owner}/{repo} per verificare permessi
        $check = $this->apiRequest($pat, 'GET', "/repos/{$repoOwner}/{$repoName}");
        if ($check['status'] === 404) {
            throw new RuntimeException('github_repo_not_found');
        }
        if ($check['status'] === 401 || $check['status'] === 403) {
            throw new RuntimeException('github_pat_unauthorized');
        }
        if ($check['status'] !== 200) {
            // status=0 → curl error (no rete/DNS/SSL). Includi dettaglio.
            $detail = '';
            if ($check['status'] === 0 && isset($check['body']['error'])) {
                $detail = ': ' . $check['body']['error'];
            }
            throw new RuntimeException('github_check_failed_' . $check['status'] . $detail);
        }

        // Cifra PAT con TKEK del docente
        $env = $this->crypto->encrypt($teacherId, $pat);

        $stmt = Database::connection()->prepare(
            'INSERT INTO teacher_github_sync
                (user_id, repo_owner, repo_name, branch, pat_encrypted, pat_kv)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                repo_owner=VALUES(repo_owner),
                repo_name=VALUES(repo_name),
                branch=VALUES(branch),
                pat_encrypted=VALUES(pat_encrypted),
                pat_kv=VALUES(pat_kv),
                last_error=NULL'
        );
        // Combine iv+tag+ciphertext into single blob for storage
        $blob = $env['iv'] . $env['tag'] . $env['ciphertext'];
        $stmt->execute([$teacherId, $repoOwner, $repoName, $branch, $blob, $env['kv']]);

        return ['ok' => true, 'repo_owner' => $repoOwner, 'repo_name' => $repoName, 'branch' => $branch];
    }

    /** Ritorna config (senza PAT) o null se non configurato. */
    public function getConfig(int $teacherId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT user_id, repo_owner, repo_name, branch, last_sync_at, last_error
             FROM teacher_github_sync WHERE user_id = ?'
        );
        $stmt->execute([$teacherId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function disconnect(int $teacherId): void
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM teacher_github_sync WHERE user_id = ?'
        );
        $stmt->execute([$teacherId]);
    }

    /**
     * Push un singolo file. Crea o aggiorna nel repo. Auto-detect SHA esistente.
     *
     * @return array{ok:bool, action:'created'|'updated'|'unchanged', sha?:string, error?:string}
     */
    public function pushFile(int $teacherId, string $path, string $content, string $commitMessage = ''): array
    {
        $cfg = $this->getConfig($teacherId);
        if (!$cfg) {
            return ['ok' => false, 'action' => 'unchanged', 'error' => 'github_not_configured'];
        }

        $pat = $this->loadPat($teacherId);
        if ($pat === null) {
            return ['ok' => false, 'action' => 'unchanged', 'error' => 'pat_decrypt_failed'];
        }

        // Normalizza path (no leading /, no .. backtraversal)
        $path = ltrim($path, '/');
        if (str_contains($path, '..')) {
            return ['ok' => false, 'action' => 'unchanged', 'error' => 'invalid_path'];
        }

        $owner  = $cfg['repo_owner'];
        $repo   = $cfg['repo_name'];
        $branch = $cfg['branch'];
        $apiPath = "/repos/{$owner}/{$repo}/contents/" . rawurlencode($path);

        // Step 1: GET corrente sha (se file esiste)
        $existing = $this->apiRequest($pat, 'GET', $apiPath . '?ref=' . rawurlencode($branch));
        $existingSha = null;
        if ($existing['status'] === 200) {
            $existingSha = $existing['body']['sha'] ?? null;
            // Skip se content identico (compare base64)
            $remoteB64 = preg_replace('/\s+/', '', (string)($existing['body']['content'] ?? ''));
            $localB64  = base64_encode($content);
            if ($remoteB64 === $localB64) {
                return ['ok' => true, 'action' => 'unchanged', 'sha' => $existingSha];
            }
        }

        // Step 2: PUT (create o update con sha)
        $payload = [
            'message' => $commitMessage !== '' ? $commitMessage : "Sync {$path}",
            'content' => base64_encode($content),
            'branch'  => $branch,
        ];
        if ($existingSha !== null) {
            $payload['sha'] = $existingSha;
        }

        $put = $this->apiRequest($pat, 'PUT', $apiPath, $payload);
        if ($put['status'] !== 200 && $put['status'] !== 201) {
            return [
                'ok' => false,
                'action' => 'unchanged',
                'error' => "http_{$put['status']}: " . substr((string)($put['body']['message'] ?? ''), 0, 100),
            ];
        }
        return [
            'ok' => true,
            'action' => $existingSha === null ? 'created' : 'updated',
            'sha' => $put['body']['content']['sha'] ?? null,
        ];
    }

    public function updateLastSync(int $teacherId, ?string $error = null): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE teacher_github_sync
             SET last_sync_at = ?, last_error = ?
             WHERE user_id = ?'
        );
        $stmt->execute([
            date('Y-m-d H:i:s'),
            $error,
            $teacherId,
        ]);
    }

    /** Decifra il PAT del docente (in-memory only, mai loggato). */
    private function loadPat(int $teacherId): ?string
    {
        $stmt = Database::connection()->prepare(
            'SELECT pat_encrypted, pat_kv FROM teacher_github_sync WHERE user_id = ?'
        );
        $stmt->execute([$teacherId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $blob = (string)$row['pat_encrypted'];
        // iv (12) + tag (16) + ciphertext
        if (strlen($blob) < 28) {
            return null;
        }
        $iv  = substr($blob, 0, 12);
        $tag = substr($blob, 12, 16);
        $ct  = substr($blob, 28);
        try {
            return $this->crypto->decrypt($teacherId, [
                'ciphertext' => $ct,
                'iv'  => $iv,
                'tag' => $tag,
                'kv'  => (int)$row['pat_kv'],
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{status:int, body:mixed}
     */
    private function apiRequest(string $pat, string $method, string $path, ?array $jsonBody = null): array
    {
        $url = self::GITHUB_API . $path;
        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $pat,
                'Accept: application/vnd.github+json',
                'X-GitHub-Api-Version: 2022-11-28',
                'User-Agent: ' . self::USER_AGENT,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        // G22.S15.bis Fase 5 — usa CA bundle bundlato col repo se disponibile.
        // Risolve "SSL certificate problem: unable to get local issuer certificate"
        // su VPS con CA bundle stale o PHP curl non configurato. Fallback: lascia
        // a curl/PHP la default (system CA bundle).
        $caBundle = dirname(__DIR__, 3) . '/storage/ca-bundle/cacert.pem';
        if (is_file($caBundle)) {
            $opts[CURLOPT_CAINFO] = $caBundle;
        }
        curl_setopt_array($ch, $opts);
        if ($jsonBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody, JSON_UNESCAPED_SLASHES));
        }
        $resp   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            return ['status' => 0, 'body' => ['error' => 'curl: ' . $err]];
        }
        $decoded = json_decode((string)$resp, true);
        return ['status' => $status, 'body' => is_array($decoded) ? $decoded : ['raw' => $resp]];
    }
}
