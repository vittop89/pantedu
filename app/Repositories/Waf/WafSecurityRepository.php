<?php

declare(strict_types=1);

namespace App\Repositories\Waf;

use App\Core\Config;
use App\Core\Database;
use PDO;
use Throwable;

/**
 * WAF Security Repository — Phase 25.F modernization.
 *
 * Consolidamento storage delle protezioni brute-force auth:
 *   - blocked_credentials.json  → tabella `waf_blocked_credentials`
 *   - blocked_ips.json          → tabella `waf_blocked_ips` (con `section`)
 *
 * DB è source of truth. I file JSON restano scritti come read-cache per
 * AuthCode legacy (`<dati>/storage/security/blocked_*.json`, definiti in
 * `app/Config/auth.php`) — single direction sync DB→JSON dopo ogni mutazione.
 *
 * Import idempotente: alla prima istanziazione importa eventuali record
 * presenti nei JSON nelle tabelle (con `source='legacy_json'`).
 */
final class WafSecurityRepository
{
    private string $credJsonPath;
    private string $ipsJsonPath;
    private static bool $importDone = false;

    public function __construct(
        private readonly ?PDO $pdo = null,
        ?string $credJsonPath = null,
        ?string $ipsJsonPath = null,
    ) {
        // I percorsi li dice `app/Config/auth.php`, che è anche chi li fa
        // leggere a `App\Services\BlockList`: chi SCRIVE e chi LEGGE guardavano
        // in due posti diversi, perché qui si ripartiva da `app.paths.base` —
        // sempre la radice del repository, che nel container è l'immagine. Una
        // definizione sola, e non possono più divergere.
        $base = \App\Support\PercorsiDati::base(dirname(__DIR__, 3));
        $this->credJsonPath = $credJsonPath
            ?? (string)(Config::get('auth.paths.blocked_credentials')
                ?? $base . '/storage/security/blocked_credentials.json');
        $this->ipsJsonPath = $ipsJsonPath
            ?? (string)(Config::get('auth.paths.blocked_ips')
                ?? $base . '/storage/security/blocked_ips.json');
        $this->maybeImportLegacyJson();
    }

    private function db(): PDO
    {
        return $this->pdo ?? Database::connection();
    }

    // ============================================================
    // CREDENTIALS (username-level lockout)
    // ============================================================

    /** @return list<array<string,mixed>> */
    public function listBlockedCredentials(): array
    {
        try {
            $sql = "SELECT id, username, reason, blocked_at, expires_at, blocked_by, source
                    FROM waf_blocked_credentials
                    WHERE expires_at IS NULL OR expires_at > NOW()
                    ORDER BY blocked_at DESC";
            $stmt = $this->db()->query($sql);
            return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable) {
            return [];
        }
    }

    public function isCredentialBlocked(string $username): bool
    {
        try {
            $stmt = $this->db()->prepare(
                "SELECT 1 FROM waf_blocked_credentials
                 WHERE username = ?
                 AND (expires_at IS NULL OR expires_at > NOW())
                 LIMIT 1"
            );
            $stmt->execute([$username]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    public function blockCredential(
        string $username,
        ?string $reason = null,
        ?string $blockedBy = null,
        ?\DateTimeImmutable $expiresAt = null,
        string $source = 'manual',
        bool $syncJson = true,
    ): bool {
        if ($username === '') {
            return false;
        }
        try {
            $sql = "INSERT INTO waf_blocked_credentials
                        (username, reason, blocked_by, expires_at, source)
                    VALUES (:u, :r, :by, :exp, :src)
                    ON DUPLICATE KEY UPDATE
                        reason     = COALESCE(VALUES(reason), reason),
                        blocked_by = COALESCE(VALUES(blocked_by), blocked_by),
                        expires_at = VALUES(expires_at),
                        source     = VALUES(source)";
            $stmt = $this->db()->prepare($sql);
            $stmt->execute([
                ':u'   => $username,
                ':r'   => $reason,
                ':by'  => $blockedBy,
                ':exp' => $expiresAt?->format('Y-m-d H:i:s'),
                ':src' => $source,
            ]);
            if ($syncJson) {
                $this->syncCredentialsToJson();
            }
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function unblockCredential(string $username): int
    {
        try {
            $stmt = $this->db()->prepare("DELETE FROM waf_blocked_credentials WHERE username = ?");
            $stmt->execute([$username]);
            $this->syncCredentialsToJson();
            return $stmt->rowCount();
        } catch (Throwable) {
            return 0;
        }
    }

    // ============================================================
    // IPS (per-section blocking — auth brute-force protection)
    // ============================================================

    /**
     * Lista IP bloccati legati al flusso auth (con section).
     * Esclude blacklist manuale WAF (section NULL → /admin/waf/blocks#blacklist).
     *
     * @return list<array<string,mixed>>
     */
    public function listBlockedIpsAuth(): array
    {
        try {
            $sql = "SELECT id, ip_or_cidr AS ip, section, reason, created_at AS blocked_at,
                           created_by AS blocked_by, expires_at, hit_count, source
                    FROM waf_blocked_ips
                    WHERE section IS NOT NULL
                    AND (expires_at IS NULL OR expires_at > NOW())
                    ORDER BY created_at DESC";
            $stmt = $this->db()->query($sql);
            return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable) {
            return [];
        }
    }

    public function isIpBlockedForSection(string $ip, string $section = ''): bool
    {
        try {
            $sql = "SELECT 1 FROM waf_blocked_ips
                    WHERE ip_or_cidr = :ip
                    AND (section IS NULL OR section = '' OR section = :sec)
                    AND (expires_at IS NULL OR expires_at > NOW())
                    LIMIT 1";
            $stmt = $this->db()->prepare($sql);
            $stmt->execute([':ip' => $ip, ':sec' => $section]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    public function blockIp(
        string $ip,
        ?string $section = null,
        ?string $reason = null,
        ?int $userId = null,
        ?string $blockedBy = null,
        ?\DateTimeImmutable $expiresAt = null,
        string $source = 'manual',
    ): bool {
        if ($ip === '') {
            return false;
        }
        try {
            // INSERT … ON DUPLICATE KEY UPDATE su (ip, section) (uk_ip_section)
            $reasonCombined = $blockedBy ? trim(($reason ?? '') . ' [by ' . $blockedBy . ']') : $reason;
            $sql = "INSERT INTO waf_blocked_ips
                        (ip_or_cidr, reason, section, source, created_by, expires_at)
                    VALUES (:ip, :r, :sec, :src, :uid, :exp)
                    ON DUPLICATE KEY UPDATE
                        reason     = COALESCE(VALUES(reason), reason),
                        source     = VALUES(source),
                        expires_at = VALUES(expires_at)";
            $stmt = $this->db()->prepare($sql);
            $stmt->execute([
                ':ip'  => $ip,
                ':r'   => $reasonCombined,
                ':sec' => $section ?: null,
                ':src' => $source,
                ':uid' => $userId,
                ':exp' => $expiresAt?->format('Y-m-d H:i:s'),
            ]);
            $this->syncIpsToJson();
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function unblockIp(string $ip, ?string $section = null): int
    {
        try {
            if ($section !== null && $section !== '') {
                $stmt = $this->db()->prepare(
                    "DELETE FROM waf_blocked_ips WHERE ip_or_cidr = ? AND section = ?"
                );
                $stmt->execute([$ip, $section]);
            } else {
                $stmt = $this->db()->prepare(
                    "DELETE FROM waf_blocked_ips WHERE ip_or_cidr = ? AND (section IS NULL OR section = '')"
                );
                $stmt->execute([$ip]);
            }
            $rows = $stmt->rowCount();
            $this->syncIpsToJson();
            return $rows;
        } catch (Throwable) {
            return 0;
        }
    }

    // ============================================================
    // COUNTERS (per Reports)
    // ============================================================

    /**
     * @return array{blocked_credentials:int, blocked_ips_auth:int, blocked_ips_manual:int, blocked_ips_total:int}
     */
    public function counters(): array
    {
        try {
            $cred = (int)$this->db()->query(
                "SELECT COUNT(*) FROM waf_blocked_credentials
                 WHERE expires_at IS NULL OR expires_at > NOW()"
            )?->fetchColumn();
            $auth = (int)$this->db()->query(
                "SELECT COUNT(*) FROM waf_blocked_ips
                 WHERE section IS NOT NULL
                 AND (expires_at IS NULL OR expires_at > NOW())"
            )?->fetchColumn();
            $man = (int)$this->db()->query(
                "SELECT COUNT(*) FROM waf_blocked_ips
                 WHERE section IS NULL
                 AND (expires_at IS NULL OR expires_at > NOW())"
            )?->fetchColumn();
            return [
                'blocked_credentials' => $cred,
                'blocked_ips_auth'    => $auth,
                'blocked_ips_manual'  => $man,
                'blocked_ips_total'   => $auth + $man,
            ];
        } catch (Throwable) {
            return [
                'blocked_credentials' => 0,
                'blocked_ips_auth'    => 0,
                'blocked_ips_manual'  => 0,
                'blocked_ips_total'   => 0,
            ];
        }
    }

    // ============================================================
    // LEGACY JSON SYNC (back-compat AuthCode legacy reader)
    // ============================================================

    /** @return bool `false` se la cache non è stata scritta (motivo nel registro). */
    private function syncCredentialsToJson(): bool
    {
        try {
            $rows = $this->listBlockedCredentials();
            $out = array_map(static fn(array $r) => [
                'username'   => $r['username'],
                'blocked_at' => $r['blocked_at'],
                'reason'     => $r['reason'] ?? '',
                'blocked_by' => $r['blocked_by'] ?? 'system',
            ], $rows);

            return $this->writeJson($this->credJsonPath, $out);
        } catch (Throwable $e) {
            return $this->cacheNonScritta('lettura dal database (' . $e->getMessage() . ')', $this->credJsonPath);
        }
    }

    /** @return bool `false` se la cache non è stata scritta (motivo nel registro). */
    private function syncIpsToJson(): bool
    {
        try {
            $rows = $this->listBlockedIpsAuth();
            $out = array_map(static fn(array $r) => [
                'ip'         => $r['ip'],
                'section'    => $r['section'],
                'blocked_at' => $r['blocked_at'],
                'reason'     => $r['reason'] ?? '',
                'blocked_by' => $r['blocked_by'] ?? 'system',
            ], $rows);

            return $this->writeJson($this->ipsJsonPath, $out);
        } catch (Throwable $e) {
            return $this->cacheNonScritta('lettura dal database (' . $e->getMessage() . ')', $this->ipsJsonPath);
        }
    }

    /**
     * One-shot import su prima istanza per request: legge JSON e
     * insert idempotente in DB. Source='legacy_json'.
     */
    private function maybeImportLegacyJson(): void
    {
        if (self::$importDone) {
            return;
        }
        self::$importDone = true;
        try {
            // Skip se DB già popolato
            $hasCred = (int)$this->db()->query("SELECT COUNT(*) FROM waf_blocked_credentials")?->fetchColumn();
            $hasIp   = (int)$this->db()->query("SELECT COUNT(*) FROM waf_blocked_ips WHERE source = 'legacy_json'")?->fetchColumn();
            // Il 20/9/2026 la cache è passata da `<dati>/log/data` a
            // `<dati>/storage/security`: un'istanza già in servizio ha i suoi
            // blocchi solo nella vecchia, e l'import è il posto giusto per
            // andarli a prendere — una volta, e poi il database è la verità.
            $credJson = is_file($this->credJsonPath)
                ? $this->credJsonPath
                : (string)(Config::get('auth.paths.blocked_credentials_legacy') ?? '');
            $ipsJson = is_file($this->ipsJsonPath)
                ? $this->ipsJsonPath
                : (string)(Config::get('auth.paths.blocked_ips_legacy') ?? '');
            if ($hasCred === 0 && $credJson !== '' && is_file($credJson)) {
                $raw  = (string)@file_get_contents($credJson);
                $rows = json_decode($raw, true);
                if (is_array($rows)) {
                    foreach ($rows as $r) {
                        $u = trim((string)($r['username'] ?? ''));
                        if ($u === '') {
                            continue;
                        }
                        $this->insertImportedCredential($u, $r);
                    }
                }
            }
            if ($hasIp === 0 && $ipsJson !== '' && is_file($ipsJson)) {
                $raw  = (string)@file_get_contents($ipsJson);
                $rows = json_decode($raw, true);
                if (is_array($rows)) {
                    foreach ($rows as $r) {
                        $ip = trim((string)($r['ip'] ?? ''));
                        if ($ip === '') {
                            continue;
                        }
                        $this->insertImportedIp($ip, $r);
                    }
                }
            }
            $this->rigeneraCacheMancante();
        } catch (Throwable) {
        }
    }

    /**
     * Se la cache non c'è, la si rifà dal database — che è la fonte di verità.
     *
     * Serve al giorno del rilascio. Il 20/9/2026 questi due file sono passati
     * da `<dati>/log/data` a `<dati>/storage/security`: il database ha già i
     * suoi blocchi, quindi l'import legacy qui sopra non fa niente, e la
     * sincronizzazione DB→JSON gira solo DOPO una mutazione. Fra il rilascio e
     * il primo blocco o sblocco fatto a mano il file nuovo non esisteva, e
     * `BlockList::isIpBlockedForSection()` — l'unico posto dove i blocchi per
     * sezione si verificano davvero, perché quel controllo non passa dal
     * database — rispondeva `false` per chiunque. Misurato.
     *
     * Non è un ripiego sul percorso vecchio di proposito: un ripiego resta lì
     * per anni e fa credere che le due copie vadano d'accordo. Qui il file
     * nuovo nasce subito e completo, e da quel momento non serve più niente.
     */
    private function rigeneraCacheMancante(): void
    {
        if (!is_file($this->credJsonPath)) {
            $this->syncCredentialsToJson();
        }
        if (!is_file($this->ipsJsonPath)) {
            $this->syncIpsToJson();
        }
    }

    private function insertImportedCredential(string $username, array $r): void
    {
        try {
            $stmt = $this->db()->prepare(
                "INSERT IGNORE INTO waf_blocked_credentials
                    (username, reason, blocked_at, blocked_by, source)
                 VALUES (?, ?, COALESCE(?, NOW()), ?, 'legacy_json')"
            );
            $stmt->execute([
                $username,
                (string)($r['reason'] ?? 'legacy_import'),
                $this->normalizeTs($r['blocked_at'] ?? null),
                (string)($r['blocked_by'] ?? 'system'),
            ]);
        } catch (Throwable) {
        }
    }

    private function insertImportedIp(string $ip, array $r): void
    {
        try {
            $stmt = $this->db()->prepare(
                "INSERT IGNORE INTO waf_blocked_ips
                    (ip_or_cidr, section, reason, created_at, source)
                 VALUES (?, ?, ?, COALESCE(?, NOW()), 'legacy_json')"
            );
            $section = (string)($r['section'] ?? '');
            $stmt->execute([
                $ip,
                $section !== '' ? $section : null,
                (string)($r['reason'] ?? 'legacy_import'),
                $this->normalizeTs($r['blocked_at'] ?? null),
            ]);
        } catch (Throwable) {
        }
    }

    private function normalizeTs(mixed $ts): ?string
    {
        if (!is_string($ts) || $ts === '') {
            return null;
        }
        $t = strtotime($ts);
        return $t > 0 ? date('Y-m-d H:i:s', $t) : null;
    }

    /** @param list<array<string,mixed>> $rows */
    /**
     * Scrive la cache, e dice se c'è riuscita.
     *
     * Prima taceva: `@mkdir`, `@file_put_contents` e `@rename` dentro un
     * `try {} catch (Throwable) {}` vuoto. Misurato il 20/9/2026 con
     * `storage/` a 0555: due Warning di PHP, nessuna eccezione, nessun file e
     * nessun ritorno. Non è un dettaglio di forma — i blocchi per SEZIONE
     * passano solo di qui (`App\Services\BlockList`, che `Auth::attempt()`
     * interroga a ogni accesso): una cache non scritta è un blocco che non
     * vale, e nessuno se ne accorge.
     */
    private function writeJson(string $path, array $rows): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return $this->cacheNonScritta('mkdir', $dir);
        }
        $json = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return $this->cacheNonScritta('json_encode', $path);
        }
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return $this->cacheNonScritta('file_put_contents', $tmp);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return $this->cacheNonScritta('rename', $path);
        }

        return true;
    }

    private function cacheNonScritta(string $passo, string $percorso): bool
    {
        error_log(
            "[waf] cache dei blocchi non scritta: {$passo} su «{$percorso}» — "
            . 'i blocchi per sezione li legge BlockList da questo file, e senza non valgono; '
            . 'controllare i permessi della cartella dei dati',
        );

        return false;
    }
}
