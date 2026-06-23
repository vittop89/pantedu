<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\Crypto\TeacherRecoveryService;
use App\Services\Maps\MapBlobStore;
use App\Services\Verifica\VerificaDocumentService;
use App\Support\Ulid;
use PDO;
use Throwable;

/**
 * G22.S20 — Import Bundle (Modalità A: bundle plaintext + Recovery Key HMAC).
 *
 * Endpoint:
 *   POST /api/teacher/import-bundle/preview — dry-run, ritorna diff
 *                                              { created[], conflicts[], skipped[] }
 *   POST /api/teacher/import-bundle/apply   — esegue insert per ogni file
 *                                              dopo conferma esplicita.
 *
 * Body JSON (preview e apply):
 *   {
 *     recovery_code: string,          // R hex 64 o base32 52
 *     manifest: { ...payload, hmac },
 *     files: [{path, content_b64}, ...] // chunked, può essere paginato
 *     conflict_strategy: "skip"|"rename"  // solo apply
 *   }
 *
 * Sicurezza:
 *   - CSRF middleware
 *   - Rate limit (config 'import' → 1/15min/teacher)
 *   - HMAC del manifest verificato contro recovery_code (constant-time)
 *   - Owner-only: import scrive SOLO nel teacher autenticato (no
 *     impersonate via exporter_user_id; quel field è solo informativo)
 *   - Quota: max 1000 file, max 500 MB per bundle
 *   - sha256+size del singolo file verificato contro manifest entries
 *     (previene tamper dopo HMAC del manifest)
 */
final class ImportBundleController
{
    private const MAX_FILES = 1000;
    private const MAX_TOTAL_BYTES = 500 * 1024 * 1024; // 500 MB
    private const MAX_SINGLE_FILE = 50 * 1024 * 1024;  // 50 MB
    // Fase C — manifest meta
    private const MAX_MANIFEST_VERSION = 1;
    private const MAX_MANIFEST_AGE_DAYS = 365; // 1 anno (configurable)

    private TeacherRecoveryService $recovery;
    private VerificaDocumentService $verSvc;
    private MapBlobStore $mapStore;

    public function __construct(
        ?TeacherRecoveryService $recovery = null,
        ?VerificaDocumentService $verSvc = null,
        ?MapBlobStore $mapStore = null
    ) {
        $this->recovery = $recovery ?? new TeacherRecoveryService();
        $this->verSvc   = $verSvc ?? new VerificaDocumentService();
        $this->mapStore = $mapStore ?? new MapBlobStore();
    }

    /**
     * Dry-run: verifica HMAC, classifica ogni file in created/conflict/skipped/unsupported.
     * NO write.
     */
    public function preview(Request $req): Response
    {
        return $this->execute($req, /*apply*/ false);
    }

    /**
     * Apply: esegue insert/update secondo conflict_strategy. Ritorna report.
     */
    public function apply(Request $req): Response
    {
        return $this->execute($req, /*apply*/ true);
    }

    private function execute(Request $req, bool $apply): Response
    {
        try {
            $tid = $this->teacherId();
            if (!$tid) {
                return Response::json(['ok' => false, 'error' => 'unauthorized'], 401);
            }
            @set_time_limit(0);
            @ini_set('memory_limit', '1024M');

            // Pre-check size: body può contenere centinaia di file b64 → fail
            // fast con 413 invece di OOM 500 silenzioso.
            $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
            if ($contentLength > self::MAX_TOTAL_BYTES) {
                return Response::json([
                    'ok' => false,
                    'error' => 'request_too_large',
                    'hint' => 'Bundle troppo grande per single-shot upload. Riduci sub-set o usa chunking.',
                    'size' => $contentLength,
                    'limit' => self::MAX_TOTAL_BYTES,
                ], 413);
            }

            $raw = (string)file_get_contents('php://input');
            $body = json_decode($raw, true);
            unset($raw); // libera 100+ MB subito
            if (!is_array($body)) {
                return Response::json(['ok' => false, 'error' => 'invalid_json'], 400);
            }

            $recoveryCode = trim((string)($body['recovery_code'] ?? ''));
            $manifestIn   = is_array($body['manifest'] ?? null) ? $body['manifest'] : null;
            $files        = is_array($body['files']    ?? null) ? $body['files']    : [];
            $strategy     = (string)($body['conflict_strategy'] ?? 'skip');
            if (!in_array($strategy, ['skip', 'rename'], true)) {
                return Response::json(['ok' => false, 'error' => 'invalid_conflict_strategy'], 422);
            }
            if ($recoveryCode === '' || !$manifestIn) {
                return Response::json(['ok' => false, 'error' => 'missing_recovery_or_manifest'], 422);
            }
            if (count($files) > self::MAX_FILES) {
                return Response::json(['ok' => false, 'error' => 'too_many_files'], 413);
            }

            // G22.S20 v2.C2 Fase C — Manifest versioning + expiration check.
            $version = (int)($manifestIn['version'] ?? 0);
            if ($version < 1 || $version > self::MAX_MANIFEST_VERSION) {
                return Response::json([
                    'ok' => false,
                    'error' => 'manifest_version_unsupported',
                    'supported_range' => [1, self::MAX_MANIFEST_VERSION],
                ], 422);
            }
            // Expiration: rifiuta bundle più vecchi di MAX_MANIFEST_AGE_DAYS.
            // Mitigation contro replay con R compromessa: bundle storici
            // restano firmati validamente finché non si revoca R, ma con
            // expiration server-side rifiuta automaticamente quelli stale.
            $exportedAt = strtotime((string)($manifestIn['exported_at'] ?? '')) ?: 0;
            if ($exportedAt > 0) {
                $ageDays = (time() - $exportedAt) / 86400;
                if ($ageDays > self::MAX_MANIFEST_AGE_DAYS) {
                    return Response::json([
                        'ok' => false,
                        'error' => 'manifest_expired',
                        'age_days' => (int)$ageDays,
                        'max_age_days' => self::MAX_MANIFEST_AGE_DAYS,
                        'hint' => 'Riesporta il bundle dal server originale per ottenere un manifest fresco.',
                    ], 403);
                }
            }

            // 1. Verifica HMAC manifest
            $hmac = (string)($manifestIn['hmac'] ?? '');
            $payload = $manifestIn;
            unset($payload['hmac']);
            $valid = $this->recovery->verifyManifestHmac($recoveryCode, $payload, $hmac);
            $this->recovery->logVerifyAttempt(
                $tid,
                $valid,
                $this->clientIp(),
                (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'import-bundle ' . ($apply ? 'apply' : 'preview')
            );
            if (!$valid) {
                return Response::json(['ok' => false, 'error' => 'invalid_recovery_code_or_manifest'], 403);
            }

            // 2. Index manifest entries by path
            $byPath = [];
            $totalBytes = 0;
            foreach (($payload['files'] ?? []) as $f) {
                $p = (string)($f['path'] ?? '');
                if ($p === '') {
                    continue;
                }
                $byPath[$p] = $f;
                $totalBytes += (int)($f['size'] ?? 0);
            }
            if ($totalBytes > self::MAX_TOTAL_BYTES) {
                return Response::json(['ok' => false, 'error' => 'bundle_too_large'], 413);
            }

            // 3. Categorizza file uploadati
            $report = [
                'created'     => [],
                'conflicts'   => [],
                'skipped'     => [],
                'unsupported' => [],
                'errors'      => [],
                'applied'     => 0,
            ];

            // Preview senza files: usa il manifest stesso come fonte di
            // entries, classifica via DB lookup (no sha256 check, no insert).
            // Permette dry-run leggero prima del chunked apply.
            if (!$apply && count($files) === 0) {
                foreach ($byPath as $entryPath => $entry) {
                    $type = (string)($entry['type'] ?? '');
                    $parsed = $this->parseEntryPath($type, $entryPath);
                    if ($parsed === null) {
                        $report['unsupported'][] = ['path' => $entryPath, 'type' => $type];
                        continue;
                    }
                    $conflict = $this->detectConflict($tid, $parsed);
                    $entryReport = [
                        'path'    => $entryPath,
                        'type'    => $parsed['type'],
                        'title'   => $parsed['title'],
                        'materia' => $parsed['materia'] ?? ($parsed['subject_code'] ?? ''),
                    ];
                    if ($conflict) {
                        $report['conflicts'][] = $entryReport + [
                            'resolution' => $strategy === 'skip' ? 'skip' : 'rename',
                        ];
                    } else {
                        $report['created'][] = $entryReport;
                    }
                }
                return Response::json(['ok' => true, 'preview' => true, 'report' => $report]);
            }

            foreach ($files as $f) {
                $path = (string)($f['path'] ?? '');
                $b64  = (string)($f['content_b64'] ?? '');
                if ($path === '' || !isset($byPath[$path])) {
                    $report['skipped'][] = ['path' => $path, 'reason' => 'not_in_manifest'];
                    continue;
                }
                $entry = $byPath[$path];
                $bin = base64_decode($b64, true);
                if ($bin === false) {
                    $report['errors'][] = ['path' => $path, 'reason' => 'invalid_b64'];
                    continue;
                }
                if (strlen($bin) > self::MAX_SINGLE_FILE) {
                    $report['errors'][] = ['path' => $path, 'reason' => 'file_too_large'];
                    continue;
                }
                // Verifica integrity: sha256 + size matches manifest entry
                $expectedSha = (string)($entry['sha256'] ?? '');
                $expectedSize = (int)($entry['size'] ?? -1);
                $actualSha = hash('sha256', $bin);
                if ($expectedSha !== '' && !hash_equals($expectedSha, $actualSha)) {
                    $report['errors'][] = ['path' => $path, 'reason' => 'sha256_mismatch'];
                    continue;
                }
                if ($expectedSize >= 0 && $expectedSize !== strlen($bin)) {
                    $report['errors'][] = ['path' => $path, 'reason' => 'size_mismatch'];
                    continue;
                }

                $type = (string)($entry['type'] ?? '');
                $parsed = $this->parseEntryPath($type, $path);
                if ($parsed === null) {
                    $report['unsupported'][] = ['path' => $path, 'type' => $type];
                    continue;
                }

                $conflict = $this->detectConflict($tid, $parsed);
                $action = $conflict ? 'conflict' : 'create';
                $targetTitle = $parsed['title'];
                if ($conflict && $strategy === 'rename') {
                    $targetTitle = $parsed['title'] . ' (imp ' . date('Y-m-d') . ')';
                }

                $entryReport = [
                    'path'    => $path,
                    'type'    => $parsed['type'],
                    'title'   => $targetTitle,
                    'materia' => $parsed['materia'] ?? ($parsed['subject_code'] ?? ''),
                ];

                if ($action === 'conflict' && $strategy === 'skip') {
                    $report['conflicts'][] = $entryReport + ['resolution' => 'skip'];
                    continue;
                }

                if (!$apply) {
                    // dry-run
                    if ($conflict) {
                        $report['conflicts'][] = $entryReport + ['resolution' => 'rename'];
                    } else {
                        $report['created'][] = $entryReport;
                    }
                    continue;
                }

                // APPLY: write
                try {
                    if ($parsed['type'] === 'verifica-tex') {
                        $doc = $this->verSvc->saveTex([
                            'teacher_id' => $tid,
                            'materia'    => (string)$parsed['materia'],
                            'title'      => $targetTitle,
                            'tex'        => $bin,
                        ]);
                        $this->updateVerificaMetadata(
                            $tid,
                            (int)$doc['id'],
                            (string)($parsed['variant']   ?? ''),
                            (string)($parsed['indirizzo'] ?? ''),
                            (string)($parsed['classe']    ?? ''),
                            $targetTitle,
                            (string)$parsed['materia']
                        );
                    } elseif ($parsed['type'] === 'mappa') {
                        $this->insertMappa($tid, $parsed, $targetTitle, $bin);
                    } elseif ($parsed['type'] === 'esercizio') {
                        $this->insertEsercizio($tid, $parsed, $targetTitle, $bin);
                    } elseif ($parsed['type'] === 'documento') {
                        $this->insertDocumento($tid, $parsed, $targetTitle, $bin);
                    } else {
                        $report['unsupported'][] = ['path' => $path, 'type' => $parsed['type']];
                        continue;
                    }
                    $report['applied']++;
                    if ($conflict) {
                        $report['conflicts'][] = $entryReport + ['resolution' => 'rename'];
                    } else {
                        $report['created'][] = $entryReport;
                    }
                } catch (Throwable $e) {
                    $report['errors'][] = ['path' => $path, 'reason' => $e->getMessage()];
                }
            }

            return Response::json(['ok' => true, 'preview' => !$apply, 'report' => $report]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Parse bundle path → entità.
     * Pattern attesi (cfr. VerificaSyncController::buildLocalBundleManifest):
     *   - verifica-tex: {ist}/{ind}/{cls}/{materia}/verifiche/{title}/{ver}/{filename}
     *   - mappa:        {ist}/{ind}/{cls}/{subject}/mappe/{title}.{ext}
     *
     * @return array{type:string,...}|null
     */
    private function parseEntryPath(string $type, string $path): ?array
    {
        $parts = explode('/', trim($path, '/'));
        if (count($parts) < 4) {
            return null;
        }

        // Helper: 'general' è placeholder usato nel manifest builder per
        // valori NULL → al re-import deve tornare NULL, non stringa.
        $stripGeneral = static fn(?string $v): ?string =>
            ($v === null || $v === '' || strtolower($v) === 'general') ? null : $v;

        if ($type === 'verifica-tex') {
            // {ist}/{ind}/{cls}/{materia}/verifiche/{title}/[{version}/]{filename}
            $idxVerifiche = array_search('verifiche', $parts, true);
            if ($idxVerifiche === false || $idxVerifiche < 3) {
                return null;
            }
            $materia = $parts[$idxVerifiche - 1];
            $title   = $parts[$idxVerifiche + 1] ?? '';
            if ($title === '') {
                return null;
            }
            // Estrai variant dal filename. Pattern G19.49:
            //   A_SOL → {materia}-{slug}-_-SOL.tex
            //   A_NOR → {materia}-{slug}-_-NOR-stampe.tex
            //   B_SOL → {materia}-{slug}-rec-SOL.tex
            //   B_KIND → {materia}-{slug}-rec-KIND-stampe.tex
            $filename = end($parts) ?: '';
            $variant = '';
            if (preg_match('/-(?P<ver>_|rec)-(?P<kind>SOL|NOR|DSA|DIS)(?:-stampe)?\.tex$/i', $filename, $m)) {
                $verLetter = $m['ver'] === 'rec' ? 'B' : 'A';
                $variant = $verLetter . '_' . strtoupper($m['kind']);
            }
            return [
                'type'      => 'verifica-tex',
                'materia'   => $materia,
                'title'     => $title,
                'variant'   => $variant,
                'indirizzo' => $stripGeneral($parts[$idxVerifiche - 3] ?? null),
                'classe'    => $stripGeneral($parts[$idxVerifiche - 2] ?? null),
            ];
        }
        if ($type === 'esercizio' || $type === 'documento') {
            // {ist}/{ind}/{cls}/{subject}/esercizi/{title}.json
            // {ist}/{ind}/{cls}/{subject}/documenti/{title}.json
            $keyword = $type === 'esercizio' ? 'esercizi' : 'documenti';
            $idx = array_search($keyword, $parts, true);
            if ($idx === false || $idx < 3) {
                return null;
            }
            $subject = $parts[$idx - 1];
            $filename = $parts[$idx + 1] ?? '';
            if ($filename === '') {
                return null;
            }
            $title = preg_replace('/\.json$/i', '', $filename) ?: $filename;
            return [
                'type'         => $type,
                'title'        => $title,
                'subject_code' => $subject,
                'indirizzo'    => $stripGeneral($parts[$idx - 3] ?? null),
                'classe'       => $stripGeneral($parts[$idx - 2] ?? null),
            ];
        }
        if ($type === 'mappa') {
            // {ist}/{ind}/{cls}/{subject}/mappe/{title}.{ext}
            $idxMappe = array_search('mappe', $parts, true);
            if ($idxMappe === false || $idxMappe < 3) {
                return null;
            }
            $subject = $parts[$idxMappe - 1];
            $filename = $parts[$idxMappe + 1] ?? '';
            if ($filename === '') {
                return null;
            }
            $title = preg_replace('/\.(drawio|pdf|png|jpg|html)$/i', '', $filename) ?: $filename;
            $ext = '';
            if (preg_match('/\.(drawio|pdf|png|jpg|html)$/i', $filename, $m)) {
                $ext = strtolower($m[1]);
            }
            $mime = match ($ext) {
                'drawio' => 'application/xml',
                'pdf'    => 'application/pdf',
                'png'    => 'image/png',
                'jpg'    => 'image/jpeg',
                'html'   => 'text/html',
                default  => 'application/octet-stream',
            };
            return [
                'type'         => 'mappa',
                'title'        => $title,
                'subject_code' => $subject,
                'indirizzo'    => $stripGeneral($parts[$idxMappe - 3] ?? null),
                'classe'       => $stripGeneral($parts[$idxMappe - 2] ?? null),
                'mime'         => $mime,
            ];
        }
        return null;
    }

    /**
     * Conflict detection per teacher importatore.
     * - verifica-tex: stesso materia + title già esistente
     * - mappa: stesso subject_code + title già esistente
     */
    private function detectConflict(int $teacherId, array $parsed): bool
    {
        $db = Database::connection();
        if ($parsed['type'] === 'verifica-tex') {
            // Match per (materia, title, variant). 8 varianti dello stesso
            // batch hanno stesso title ma variant differente → row distinte,
            // NO conflitto fra di loro. Conflitto solo se esiste già una row
            // con la STESSA combinazione.
            $variant = (string)($parsed['variant'] ?? '');
            $stmt = $db->prepare(
                'SELECT 1 FROM verifica_documents
                  WHERE teacher_id = ? AND materia = ? AND title = ?
                    AND COALESCE(variant, "") = ?
                  LIMIT 1'
            );
            $stmt->execute([$teacherId, $parsed['materia'], $parsed['title'], $variant]);
            return (bool)$stmt->fetchColumn();
        }
        if ($parsed['type'] === 'mappa') {
            $stmt = $db->prepare(
                'SELECT 1 FROM teacher_content
                  WHERE teacher_id = ? AND content_type = "mappa"
                    AND subject_code = ? AND title = ?
                  LIMIT 1'
            );
            $stmt->execute([$teacherId, $parsed['subject_code'], $parsed['title']]);
            return (bool)$stmt->fetchColumn();
        }
        if ($parsed['type'] === 'esercizio' || $parsed['type'] === 'documento') {
            $stmt = $db->prepare(
                'SELECT 1 FROM teacher_content
                  WHERE teacher_id = ? AND content_type = ?
                    AND subject_code = ? AND title = ?
                  LIMIT 1'
            );
            // Opzione A (migr 078): il bundle-kind 'documento' mappa al content_type 'document'.
            $ctype = $parsed['type'] === 'documento' ? 'document' : $parsed['type'];
            $stmt->execute([$teacherId, $ctype, $parsed['subject_code'], $parsed['title']]);
            return (bool)$stmt->fetchColumn();
        }
        return false;
    }

    /**
     * Insert mappa: row teacher_content + blob cifrato via MapBlobStore.
     * Replica MapsController::create senza HTTP overhead.
     */
    private function insertMappa(int $teacherId, array $parsed, string $title, string $plaintext): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        $blobPath = '';
        try {
            // Fase D — solo FK ids (varchar dropped)
            $L = \App\Support\CurriculumLookup::class;
            $indId  = !empty($parsed['indirizzo'])    ? $L::idFromCodeForTeacher('indirizzi', (string)$parsed['indirizzo'], $teacherId) : null;
            $clsId  = !empty($parsed['classe'])       ? $L::idFromCodeForTeacher('classi', (string)$parsed['classe'], $teacherId) : null;
            $subjId = !empty($parsed['subject_code']) ? $L::idFromCodeForTeacher('materie', (string)$parsed['subject_code'], $teacherId) : null;
            $stmt = $pdo->prepare(
                'INSERT INTO teacher_content_data
                    (teacher_id, content_subtype, subject_id, indirizzo_id, classe_id,
                     topic, title, metadata_json, visibility)
                 VALUES (?, "mappa", ?, ?, ?, ?, ?, ?, "draft")'
            );
            $stmt->execute([
                $teacherId,
                $subjId,
                $indId,
                $clsId,
                '',
                $title,
                json_encode(['mappa' => ['display' => 'show'], 'imported' => true], JSON_UNESCAPED_UNICODE),
            ]);
            $contentId = (int)$pdo->lastInsertId();

            $ulid = Ulid::generate();
            $blobPath = $this->mapStore->put($teacherId, $plaintext, $ulid);

            $upd = $pdo->prepare(
                'UPDATE teacher_content_data
                    SET map_blob_path = ?, map_mime = ?, map_size = ?,
                        map_origin = "upload", map_version = 1
                  WHERE id = ? AND teacher_id = ?'
            );
            $upd->execute([$blobPath, $parsed['mime'], strlen($plaintext), $contentId, $teacherId]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($blobPath !== '') {
                try {
                    $this->mapStore->delete($blobPath);
                } catch (Throwable) {
                }
            }
            throw $e;
        }
    }

    /**
     * Risolve l'institute_id primario del teacher (usato per il path del
     * .contract.json all'import esercizio). Ritorna 0 se non associato.
     */
    private function resolveInstituteId(int $teacherId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT institute_id FROM teacher_institutes
              WHERE user_id = ? ORDER BY institute_id ASC LIMIT 1'
        );
        $stmt->execute([$teacherId]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    /**
     * v2 — Insert esercizio: ricostruisce DB row + file .contract.json sul
     * filesystem del teacher importatore (path rimappato al suo institute_id).
     *
     * Bundle JSON shape (cfr. VerificaSyncController::materializeBundleEntry):
     *   {kind, version, db_row:{subject_code,indirizzo,classe,topic,title},
     *    metadata:{...contract_key...}, contract:{...full json...},
     *    contract_relpath: 'institutes/108/private/77/eser/foo.contract.json'}
     */
    private function insertEsercizio(int $teacherId, array $parsed, string $title, string $jsonBundle): void
    {
        $w = json_decode($jsonBundle, true);
        if (!is_array($w) || ($w['kind'] ?? '') !== 'esercizio') {
            throw new \RuntimeException('invalid_esercizio_wrapper');
        }
        $db = Database::connection();
        $instId = $this->resolveInstituteId($teacherId);
        if ($instId === 0) {
            throw new \RuntimeException('teacher_no_institute');
        }

        // Rimappa contract_key all'institute_id + teacher_id del destinatario.
        // Lascia inalterato il filename (basename) per evitare riferimenti
        // incrociati rotti dentro il contract stesso.
        $origRel = (string)($w['contract_relpath'] ?? '');
        $basename = $origRel !== '' ? basename($origRel) : '';
        if ($basename === '') {
            $basename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $title) . '.contract.json';
        }
        $newRel = "institutes/{$instId}/private/{$teacherId}/eser/{$basename}";

        // Aggiorna il metadata_json con il nuovo contract_key
        $meta = is_array($w['metadata'] ?? null) ? $w['metadata'] : [];
        $meta['contract_key'] = $newRel;
        $meta['imported']     = true;
        $meta['imported_from_user_id'] = (int)($w['exporter_user_id'] ?? 0);

        $row = is_array($w['db_row'] ?? null) ? $w['db_row'] : [];

        $db->beginTransaction();
        $writtenPath = '';
        try {
            // Fase D — solo FK ids (varchar dropped)
            $ins = $db->prepare(
                'INSERT INTO teacher_content_data
                    (teacher_id, content_subtype, subject_id, indirizzo_id, classe_id,
                     topic, title, metadata_json, visibility)
                 VALUES (?, "esercizio", ?, ?, ?, ?, ?, ?, "draft")'
            );
            $L = \App\Support\CurriculumLookup::class;
            $eIndRaw  = ($row['indirizzo'] ?? $parsed['indirizzo']) ?: null;
            $eClsRaw  = ($row['classe']    ?? $parsed['classe'])    ?: null;
            $eSubjRaw = (string)($row['subject_code'] ?? $parsed['subject_code']);
            $ins->execute([
                $teacherId,
                $L::idFromCodeForTeacher('materie', $eSubjRaw, $teacherId),
                $eIndRaw !== null ? $L::idFromCodeForTeacher('indirizzi', (string)$eIndRaw, $teacherId) : null,
                $eClsRaw !== null ? $L::idFromCodeForTeacher('classi', (string)$eClsRaw, $teacherId) : null,
                (string)($row['topic'] ?? ''),
                $title,
                json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            // Scrivi .contract.json sul filesystem se il bundle lo include
            if (is_array($w['contract'] ?? null)) {
                $absDir = dirname(__DIR__, 2) . '/storage/objects/' . dirname($newRel);
                if (!is_dir($absDir) && !mkdir($absDir, 0775, true) && !is_dir($absDir)) {
                    throw new \RuntimeException('contract_mkdir_failed');
                }
                $absFile = dirname(__DIR__, 2) . '/storage/objects/' . $newRel;
                $payload = json_encode($w['contract'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (file_put_contents($absFile, $payload) === false) {
                    throw new \RuntimeException('contract_write_failed');
                }
                $writtenPath = $absFile;
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($writtenPath !== '' && is_file($writtenPath)) {
                @unlink($writtenPath);
            }
            throw $e;
        }
    }

    /**
     * v2 — Insert documento: solo DB row (no filesystem). body_html plaintext.
     */
    private function insertDocumento(int $teacherId, array $parsed, string $title, string $jsonBundle): void
    {
        $w = json_decode($jsonBundle, true);
        if (!is_array($w) || ($w['kind'] ?? '') !== 'documento') {
            throw new \RuntimeException('invalid_documento_wrapper');
        }
        $db = Database::connection();
        $row = is_array($w['db_row'] ?? null) ? $w['db_row'] : [];
        $meta = is_array($w['metadata'] ?? null) ? $w['metadata'] : [];
        $meta['imported'] = true;

        // Fase D — solo FK ids (varchar dropped)
        $ins = $db->prepare(
            'INSERT INTO teacher_content_data
                (teacher_id, content_subtype, subject_id, indirizzo_id, classe_id,
                 topic, title, body_html, metadata_json, visibility)
             VALUES (?, "document", ?, ?, ?, ?, ?, ?, ?, "draft")'
        );
        $L = \App\Support\CurriculumLookup::class;
        $dIndRaw  = ($row['indirizzo'] ?? $parsed['indirizzo']) ?: null;
        $dClsRaw  = ($row['classe']    ?? $parsed['classe'])    ?: null;
        $dSubjRaw = (string)($row['subject_code'] ?? $parsed['subject_code']);
        $ins->execute([
            $teacherId,
            $L::idFromCodeForTeacher('materie', $dSubjRaw, $teacherId),
            $dIndRaw !== null ? $L::idFromCodeForTeacher('indirizzi', (string)$dIndRaw, $teacherId) : null,
            $dClsRaw !== null ? $L::idFromCodeForTeacher('classi', (string)$dClsRaw, $teacherId) : null,
            (string)($row['topic'] ?? ''),
            $title,
            (string)($row['body_html'] ?? ''),
            json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * Aggiorna variant/indirizzo/classe della verifica appena creata.
     * Riusa batch_id esistente se trova altra row con stesso (title, materia,
     * teacher_id, sub-variant differente) → ricostruisce il "batch" che era
     * stato esportato. Sennò genera nuovo batch_id ULID.
     */
    private function updateVerificaMetadata(
        int $teacherId,
        int $docId,
        string $variant,
        string $indirizzo,
        string $classe,
        string $title,
        string $materia
    ): void {
        $db = Database::connection();
        // Cerca batch_id condiviso per (teacher, materia, title) escludendo
        // questa stessa row.
        $stmt = $db->prepare(
            'SELECT batch_id FROM verifica_documents
              WHERE teacher_id = ? AND materia = ? AND title = ? AND id != ?
                AND batch_id IS NOT NULL AND batch_id != ""
              LIMIT 1'
        );
        $stmt->execute([$teacherId, $materia, $title, $docId]);
        $batchId = (string)($stmt->fetchColumn() ?: '');
        if ($batchId === '') {
            $batchId = \App\Support\Ulid::generate();
        }
        // Fase D — solo FK ids (varchar dropped)
        $L = \App\Support\CurriculumLookup::class;
        $indId = $indirizzo !== '' ? $L::idFromCodeForTeacher('indirizzi', $indirizzo, $teacherId) : null;
        $clsId = $classe    !== '' ? $L::idFromCodeForTeacher('classi', $classe, $teacherId) : null;
        $upd = $db->prepare(
            'UPDATE verifica_documents_data
                SET variant = ?, indirizzo_id = ?, classe_id = ?, batch_id = ?
              WHERE id = ? AND teacher_id = ?'
        );
        $upd->execute([
            $variant !== '' ? $variant : null,
            $indId,
            $clsId,
            $batchId,
            $docId,
            $teacherId,
        ]);
    }

    private function teacherId(): int
    {
        if (!Auth::check()) {
            return 0;
        }
        $u = Auth::user();
        return (int)($u['id'] ?? 0);
    }

    private function clientIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        return $ip ? substr((string)$ip, 0, 45) : null;
    }
}
