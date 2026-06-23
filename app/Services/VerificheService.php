<?php

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use PDO;
use RuntimeException;

/**
 * Service per endpoint verifiche:
 *  - Discovery cartelle tex_pdf (listFolders)
 *  - Persistenza print_info per-utente (DB + dual-write JSON)
 *  - Save/load/check scelte JSON con versioni v1/v2/v3
 */
final class VerificheService
{
    // Phase 18 — listFolders + resolveParent rimossi: /verifiche/folders
    // non più routato. Topic list è /api/study/topics.json?type=verifica.

    /**
     * Upsert print_info per utente corrente.
     * Scrive su DB (se abilitato) + JSON (se dual-write o DB off).
     */
    public function savePrintInfo(?string $username, array $post): array
    {
        foreach (['indirizzo', 'classe', 'materia'] as $field) {
            if (empty($post[$field])) {
                throw new RuntimeException("Campo $field obbligatorio");
            }
        }
        $indirizzo = (string)$post['indirizzo'];
        $classe    = (string)$post['classe'];
        $materia   = (string)$post['materia'];
        $key       = "{$indirizzo}_{$classe}_{$materia}";
        $payload = [
            'indirizzo' => $indirizzo, 'classe' => $classe, 'materia' => $materia,
            'sezione' => (string)($post['sezione'] ?? ''),
            'anno'    => (string)($post['anno']    ?? ''),
            'verTime' => (string)($post['verTime'] ?? ''),
            'nPrint'  => (string)($post['nPrint']  ?? ''),
            'nPrintDSA' => (string)($post['nPrintDSA'] ?? ''),
            'nPrintDIS' => (string)($post['nPrintDIS'] ?? ''),
            'addressSchool' => (string)($post['addressSchool'] ?? ''),
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        $useDb = $this->dbAvailable($username);
        if ($useDb) {
            $pdo = Database::connection();
            $uid = \App\Support\TeacherContextResolver::userIdFromUsername($username);
            if ($uid > 0) {
                // Fase D — solo FK ids (varchar dropped)
                $L = \App\Support\CurriculumLookup::class;
                $stmt = $pdo->prepare(
                    'INSERT INTO print_info_data
                        (user_id, page_key, indirizzo_id, classe_id, materia_id, n_print)
                     VALUES (?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE
                        indirizzo_id=VALUES(indirizzo_id),
                        classe_id=VALUES(classe_id),
                        materia_id=VALUES(materia_id),
                        n_print=VALUES(n_print)'
                );
                $stmt->execute([
                    (int)$uid, $key,
                    $indirizzo !== '' ? $L::idFromCodeForTeacher('indirizzi', (string)$indirizzo, (int)$uid) : null,
                    $classe    !== '' ? $L::idFromCodeForTeacher('classi', (string)$classe, (int)$uid) : null,
                    $materia   !== '' ? $L::idFromCodeForTeacher('materie', (string)$materia, (int)$uid) : null,
                    (int)($post['nPrint'] ?? 0),
                ]);
            }
        }
        if (Config::get('database.dual_write', true) || !$useDb) {
            $this->writeJsonPrintInfo($key, $payload);
        }
        return ['success' => true, 'message' => 'OK', 'key' => $key];
    }

    /** Carica print_info per (istituto, classe, materia) dal DB, fallback JSON. */
    public function loadPrintInfo(?string $username, array $query): ?array
    {
        foreach (['indirizzo', 'classe', 'materia'] as $f) {
            if (empty($query[$f])) {
                throw new RuntimeException('Parametri mancanti');
            }
        }
        $key = "{$query['indirizzo']}_{$query['classe']}_{$query['materia']}";
        if ($this->dbAvailable($username)) {
            $stmt = Database::connection()->prepare(
                'SELECT pi.* FROM print_info pi JOIN users u ON u.id = pi.user_id
                 WHERE u.username = ? AND pi.page_key = ? LIMIT 1'
            );
            $stmt->execute([$username, $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }
        $data = $this->readJsonPrintInfo();
        return $data[$key] ?? null;
    }

    private function dbAvailable(?string $username): bool
    {
        return Config::get('database.enabled') && Database::isAvailable() && $username !== null && $username !== '';
    }

    private function jsonPath(): string
    {
        return dirname(__DIR__, 2) . '/storage/data/print_info.json';
    }

    /** G19.4 — file scelte per-docente.
     *  Path: `storage/data/scelte/{usernameSafe}/{verPathSlug}.json`
     *  - usernameSafe: `[a-zA-Z0-9._-]+` (no path traversal)
     *  - verPathSlug:  trim leading `/`, replace `/`→`_`, strip ext
     *  Es: user=`superadmin`, verFilePath=`/studio/esercizio/ar/2s/MAT/1`
     *      → `storage/data/scelte/superadmin/studio_esercizio_ar_2s_MAT_1.json`
     */
    private function scelteJsonPath(string $verFilePath, ?string $username): string
    {
        $userSafe = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string)$username) ?? 'unknown';
        if ($userSafe === '' || $userSafe === '_') {
            $userSafe = 'unknown';
        }
        $rel = ltrim($verFilePath, '/');
        // Strip extension (es. `.tex`, `.html`, `.php`)
        $rel = preg_replace('/\.(tex|html?|php)$/i', '', $rel) ?? $rel;
        $slug = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $rel) ?? 'unnamed';
        $slug = trim($slug, '_');
        if ($slug === '') {
            $slug = 'unnamed';
        }
        return dirname(__DIR__, 2) . '/storage/data/scelte/' . $userSafe . '/' . $slug . '.json';
    }

    private function readJsonPrintInfo(): array
    {
        $file = $this->jsonPath();
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($file), true);
        return \is_array($data) ? $data : [];
    }

    private function writeJsonPrintInfo(string $key, array $payload): void
    {
        $data = $this->readJsonPrintInfo();
        $data[$key] = $payload;
        file_put_contents($this->jsonPath(), json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /** Scelte multiversione (v1/v2/v3) persistite per docente.
     *
     * G19.4 — il path è ora `storage/data/scelte/{username}/{slug-pathname}.json`
     * invece del legacy `{DOCROOT}/{verFilePath-dir}/scelte/{name}.json`.
     * Causa: il pattern legacy era project-scoped (tutti i docenti
     * scrivevano nella stessa dir cartella `scelte/`) → no isolation.
     * Lo slug del pathname è derivato da `$verFilePath` (`/studio/...`)
     * sostituendo `/` con `_` per evitare path traversal.
     */
    public function handleScelte(string $action, string $verFilePath, string $versionKey, array $post, ?string $username = null): array
    {
        if ($verFilePath === '') {
            throw new RuntimeException('Percorso file verifica non specificato');
        }
        if (!preg_match('/^v[1-3]$/', $versionKey)) {
            $versionKey = 'v1';
        }

        $jsonFile = $this->scelteJsonPath($verFilePath, $username);
        $scelteDir = \dirname($jsonFile);

        if (!is_dir($scelteDir)) {
            $old = umask(0);
            $ok  = @mkdir($scelteDir, 0775, true);
            umask($old);
            if (!$ok && !is_dir($scelteDir)) {
                throw new RuntimeException('Impossibile creare la cartella scelte');
            }
        }

        return match ($action) {
            'save'  => $this->sceltaSave($jsonFile, $versionKey, (string)($post['data'] ?? '')),
            'load'  => $this->sceltaLoad($jsonFile, $versionKey),
            'check' => $this->sceltaCheck($jsonFile, $versionKey),
            default => throw new RuntimeException('Azione non riconosciuta'),
        };
    }

    private function sceltaSave(string $jsonFile, string $versionKey, string $raw): array
    {
        if ($raw === '') {
            throw new RuntimeException('Dati non forniti');
        }
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Dati JSON non validi: ' . json_last_error_msg());
        }
        $final = ['versions' => [], 'updatedAt' => null, 'lastVersionSaved' => null];
        if (is_file($jsonFile)) {
            $existing = json_decode((string)file_get_contents($jsonFile), true);
            if (\is_array($existing)) {
                if (isset($existing['versions']) && \is_array($existing['versions'])) {
                    $final = $existing;
                } else {
                    $final['versions']['v1'] = $existing;
                }
            }
        }
        $decoded['savedAt'] = date('Y-m-d H:i:s');
        $final['versions'][$versionKey] = $decoded;
        $final['updatedAt'] = date('Y-m-d H:i:s');
        $final['lastVersionSaved'] = $versionKey;
        if (file_put_contents($jsonFile, json_encode($final, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            throw new RuntimeException('Errore nel salvataggio del file');
        }
        return [
            'success' => true,
            'message' => 'Scelte salvate con successo',
            'data'    => [
                'filePath'      => $jsonFile,
                'timestamp'     => $decoded['savedAt'],
                'versionKey'    => $versionKey,
                'savedVersions' => array_keys($final['versions']),
            ],
        ];
    }

    private function sceltaLoad(string $jsonFile, string $versionKey): array
    {
        if (!is_file($jsonFile)) {
            // "Nessuna scelta salvata" è uno stato NORMALE (prima apertura della
            // verifica): rispondi 200 con success:false (il client usa i default)
            // invece di 404, che il browser logga come errore di rete in console.
            return [
                'success' => false,
                'message' => 'Nessuna scelta salvata per questa verifica',
                'data'    => ['filePath' => $jsonFile, 'versionKey' => $versionKey],
            ];
        }
        $decoded = json_decode((string)file_get_contents($jsonFile), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Errore nella decodifica del JSON: ' . json_last_error_msg());
        }
        if (isset($decoded['versions']) && \is_array($decoded['versions'])) {
            if (!isset($decoded['versions'][$versionKey])) {
                return [
                    'success' => false,
                    'message' => 'Versione non trovata nel file scelte',
                    'data'    => [
                        'filePath'      => $jsonFile,
                        'versionKey'    => $versionKey,
                        'savedVersions' => array_keys($decoded['versions']),
                    ],
                ];
            }
            return ['success' => true, 'message' => 'Scelte caricate con successo', 'data' => $decoded['versions'][$versionKey]];
        }
        if ($versionKey !== 'v1') {
            return [
                'success' => false,
                'message' => 'Versione non trovata nel file legacy',
                'data'    => ['filePath' => $jsonFile, 'versionKey' => $versionKey, 'savedVersions' => ['v1']],
            ];
        }
        return ['success' => true, 'message' => 'Scelte caricate con successo', 'data' => $decoded];
    }

    private function sceltaCheck(string $jsonFile, string $versionKey): array
    {
        $exists = false;
        $lastModified = null;
        $saved = [];
        $lastSaved = null;
        if (is_file($jsonFile)) {
            $exists = true;
            $lastModified = date('Y-m-d H:i:s', (int)filemtime($jsonFile));
            $decoded = json_decode((string)file_get_contents($jsonFile), true);
            if (\is_array($decoded)) {
                if (isset($decoded['versions']) && \is_array($decoded['versions'])) {
                    $saved = array_keys($decoded['versions']);
                    if (isset($decoded['lastVersionSaved']) && preg_match('/^v[1-3]$/', (string)$decoded['lastVersionSaved'])) {
                        $lastSaved = $decoded['lastVersionSaved'];
                    }
                } else {
                    $saved = ['v1'];
                    $lastSaved = 'v1';
                }
            }
        }
        if ($lastSaved === null && $saved !== []) {
            $lastSaved = $saved[0];
        }
        return [
            'success' => true,
            'message' => $exists ? 'File trovato' : 'File non trovato',
            'data'    => [
                'exists'           => $exists,
                'filePath'         => $jsonFile,
                'lastModified'     => $lastModified,
                'versionKey'       => $versionKey,
                'savedVersions'    => $saved,
                'versionExists'    => \in_array($versionKey, $saved, true),
                'lastVersionSaved' => $lastSaved,
            ],
        ];
    }
}
