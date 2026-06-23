<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\Crypto\TeacherCryptoService;
use App\Services\Crypto\TeacherRecoveryService;
use PDO;
use Throwable;

/**
 * Phase 25.R.5.3 — UI super-admin per stato crypto + log custodia chiavi +
 * registro cooperazione autorità (Art. 33 GDPR + Art. 6(1)(c)).
 *
 * Endpoint:
 *   GET  /admin/crypto-status                  → dashboard stato + log
 *   POST /admin/crypto-status/event            → registra evento custodia
 *
 * Vedi docs/security/operations/authority-cooperation.md per la procedura.
 */
final class AdminCryptoStatusController
{
    public const EVENT_TYPES = [
        'kms_generated', 'kms_rotated', 'kms_backup_created', 'kms_backup_verified',
        'authority_request', 'authority_granted', 'authority_denied',
        'data_recovered', 'data_provided',
        'kek_emergency_access', 'key_destroyed',
    ];

    /** GET /admin/crypto-status */
    public function index(Request $req): Response
    {
        $tcs = new TeacherCryptoService();
        $trs = new TeacherRecoveryService();
        $pdo = Database::connection();

        // Aggregati
        $stats = [
            'kms_configured'        => $tcs->isConfigured(),
            'recovery_configured'   => $trs->isConfigured(),
            'teacher_keys_count'    => (int)$pdo->query('SELECT COUNT(*) FROM teacher_keys')->fetchColumn(),
            'teachers_with_recovery' => (int)$pdo->query('SELECT COUNT(*) FROM teacher_recovery_keys WHERE revoked_at IS NULL')->fetchColumn(),
            'oldest_key'            => $pdo->query('SELECT MIN(created_at) FROM teacher_keys')->fetchColumn() ?: null,
            'latest_rotation'       => $pdo->query('SELECT MAX(rotated_at) FROM teacher_keys')->fetchColumn() ?: null,
        ];

        // Conteggi eventi per tipo
        $eventCounts = [];
        try {
            $rows = $pdo->query(
                'SELECT event_type, COUNT(*) c FROM crypto_custody_events GROUP BY event_type'
            )->fetchAll(PDO::FETCH_KEY_PAIR);
            $eventCounts = $rows ?: [];
        } catch (Throwable $e) {
            // tabella non ancora migrata
        }

        // Ultimi 50 eventi
        $recent = [];
        try {
            $recent = $pdo->query(
                'SELECT * FROM crypto_custody_events ORDER BY occurred_at DESC, id DESC LIMIT 50'
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // tabella non ancora migrata
        }

        $view = View::default();
        $body = $view->render('admin/crypto_status', [
            'stats'       => $stats,
            'eventCounts' => $eventCounts,
            'recent'      => $recent,
            'eventTypes'  => self::EVENT_TYPES,
            'csrf'        => Csrf::token(),
            'flash'       => $_SESSION['flash'] ?? null,
            'user'        => Auth::user() ?? ['username' => '-', 'role' => 'guest'],
        ]);
        unset($_SESSION['flash']);
        return Response::html($view->render('layout/shell', [
            'title' => 'Crypto Status — Admin',
            'body'  => $body,
        ]));
    }

    /**
     * GET /admin/crypto-status/export
     *
     * Query params:
     *   format=csv|json              (default csv)
     *   date_from=YYYY-MM-DD         (filtro occurred_at >=)
     *   date_to=YYYY-MM-DD           (filtro occurred_at <=, inclusivo)
     *   teacher_id=N                 (filtro teacher_id =)
     *   event_types=t1,t2,...        (CSV filtro event_type IN)
     *   signed=1                     (Phase 25.R.22: produce ZIP firmato con manifest HMAC)
     *
     * Modalità signed=1 produce un bundle ZIP contenente:
     *   - export.{csv|json}    dati filtrati
     *   - manifest.json        { sha256, hmac_sha256, exported_at, exported_by, filters, rows_count }
     *
     * HMAC: derivato da KMS_MASTER_KEY via HKDF-like (HMAC con label fixed).
     * Verifica integrità da parte autorità senza esporre KMS_MASTER_KEY:
     *   hmac_sha256 = HMAC(derived_export_key, sha256(export_body))
     *   derived_export_key = HMAC(KMS_MASTER_KEY, "pantedu-export-signing-v1")
     */
    public function export(Request $req): Response
    {
        $format = (string)($req->query['format'] ?? 'csv');
        $format = in_array($format, ['csv', 'json'], true) ? $format : 'csv';
        $signed = !empty($req->query['signed']) && (string)$req->query['signed'] === '1';

        // Filtri perimetro (GDPR minimizzazione)
        $filters = $this->parseExportFilters($req);

        try {
            [$rows, ] = self::fetchCustodyEvents($filters);
        } catch (Throwable $e) {
            error_log('[crypto] export failed: ' . $e->getMessage()); // Audit 25.R.31
            return Response::html('<h1>500 Export failed</h1>', 500);
        }

        $ts = date('Ymd_His');
        $exportBody = self::renderExportBody($format, $rows, $filters);

        if (!$signed) {
            // Comportamento legacy: download diretto file singolo
            return new Response($exportBody, 200, [
                'Content-Type'           => $format === 'json' ? 'application/json; charset=utf-8' : 'text/csv; charset=utf-8',
                'Content-Disposition'    => 'attachment; filename="crypto-custody-events-' . $ts . '.' . $format . '"',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        // Bundle ZIP firmato — Phase 25.R.22
        return self::buildSignedBundle($exportBody, $format, $rows, $filters, $ts);
    }

    /**
     * Legge i filtri da POST body se presente, altrimenti query string.
     * Supporta sia `event_types` (stringa CSV via hidden input JS-aggregated)
     * sia `event_types_arr[]` (array dei checkbox HTML).
     *
     * @return array{date_from:?string,date_to:?string,teacher_id:?int,event_types:list<string>}
     */
    public static function parseExportFilters(Request $req): array
    {
        // Priorità: POST (form submit) → query string (GET endpoint diretto)
        $src = !empty($req->post) ? $req->post : ($req->query ?? []);

        $df  = trim((string)($src['date_from']  ?? ''));
        $dt  = trim((string)($src['date_to']    ?? ''));
        $tid = (int)($src['teacher_id'] ?? 0);
        $maxPerType = (int)($src['max_per_type'] ?? 0);
        $maxPerType = $maxPerType > 0 ? max(1, min(500, $maxPerType)) : null;

        // Phase 25.R.24 — content_ids per export mirato (es. decreto su singoli docs)
        $contentIds = [];
        $cidRaw = trim((string)($src['content_ids'] ?? ''));
        if ($cidRaw !== '') {
            foreach (explode(',', $cidRaw) as $cid) {
                $cid = (int)trim($cid);
                if ($cid > 0) {
                    $contentIds[] = $cid;
                }
            }
        }

        $etList = [];
        // Forma array (checkbox HTML form: event_types_arr[])
        $arr = $src['event_types_arr'] ?? null;
        if (is_array($arr)) {
            foreach ($arr as $e) {
                $e = trim((string)$e);
                if ($e !== '' && in_array($e, self::EVENT_TYPES, true)) {
                    $etList[] = $e;
                }
            }
        }
        // Forma CSV (hidden input aggregato da JS, o query string)
        $etRaw = trim((string)($src['event_types'] ?? ''));
        if ($etRaw !== '' && empty($etList)) {
            foreach (explode(',', $etRaw) as $e) {
                $e = trim($e);
                if ($e !== '' && in_array($e, self::EVENT_TYPES, true)) {
                    $etList[] = $e;
                }
            }
        }
        return [
            'date_from'    => $df !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $df) ? $df : null,
            'date_to'      => $dt !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt) ? $dt : null,
            'teacher_id'   => $tid > 0 ? $tid : null,
            'event_types'  => $etList,
            'max_per_type' => $maxPerType,
            'content_ids'  => $contentIds,
        ];
    }

    /**
     * @return array{0: list<array<string,mixed>>, 1: string}  rows + descrizione SQL filtri applicati
     */
    public static function fetchCustodyEvents(array $filters): array
    {
        $where = [];
        $params = [];
        if ($filters['date_from'] !== null) {
            $where[] = 'occurred_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if ($filters['date_to'] !== null) {
            $where[] = 'occurred_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        if ($filters['teacher_id'] !== null) {
            $where[] = 'teacher_id = ?';
            $params[] = $filters['teacher_id'];
        }
        if (!empty($filters['event_types'])) {
            $placeholders = implode(',', array_fill(0, count($filters['event_types']), '?'));
            $where[] = "event_type IN ($placeholders)";
            foreach ($filters['event_types'] as $et) {
                $params[] = $et;
            }
        }
        $sql = 'SELECT * FROM crypto_custody_events';
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY occurred_at DESC, id DESC';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return [$rows, $sql];
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    public static function renderExportBody(string $format, array $rows, array $filters): string
    {
        if ($format === 'json') {
            return (string)json_encode([
                'exported_at' => date(DATE_ATOM),
                'exported_by' => (string)(Auth::user()['username'] ?? 'unknown'),
                'filters'     => $filters,
                'rows_count'  => count($rows),
                'events'      => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        // CSV
        $columns = [
            'id', 'event_type', 'teacher_id', 'actor_user_id',
            'authority_name', 'authority_ref', 'custodian_name', 'custody_location',
            'description', 'legal_basis', 'evidence_url',
            'occurred_at', 'recorded_at',
        ];
        $body = "\xEF\xBB\xBF" . implode(',', $columns) . "\r\n";
        foreach ($rows as $r) {
            $cells = [];
            foreach ($columns as $col) {
                $v = (string)($r[$col] ?? '');
                // Audit 25.R.31 — neutralizza formula/CSV injection: celle che
                // iniziano con = + - @ (o TAB/CR) sono eseguite come formule
                // all'apertura in Excel/LibreOffice → prefisso apostrofo.
                if ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                    $v = "'" . $v;
                }
                if (str_contains($v, ',') || str_contains($v, '"') || str_contains($v, "\n") || str_contains($v, "\r")) {
                    $v = '"' . str_replace('"', '""', $v) . '"';
                }
                $cells[] = $v;
            }
            $body .= implode(',', $cells) . "\r\n";
        }
        return $body;
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    public static function buildSignedBundle(string $exportBody, string $format, array $rows, array $filters, string $ts): Response
    {
        $exportFile  = 'export.' . $format;
        $sha256      = hash('sha256', $exportBody);
        // Audit 25.R.31 — firma SOLO se KMS_MASTER_KEY configurato; altrimenti il
        // bundle è dichiarato UNSIGNED (niente HMAC con chiave costante pubblica
        // spacciata per firma forte).
        $derivedKey  = self::deriveExportSigningKey();
        $signed      = $derivedKey !== null;
        $hmac        = $signed ? hash_hmac('sha256', $sha256, $derivedKey) : 'UNSIGNED';

        $manifest = [
            'manifest_version'  => '1.0',
            'product'           => 'pantedu',
            'export_purpose'    => 'authority-cooperation',
            'export_file'       => $exportFile,
            'export_format'     => $format,
            'export_sha256'     => $sha256,
            'signed'            => $signed,
            'export_hmac_sha256' => $hmac,
            'hmac_algorithm'    => $signed
                ? 'HMAC-SHA256(HKDF(KMS_MASTER_KEY, "pantedu-export-signing-v1"), sha256(export_body))'
                : 'UNSIGNED — KMS_MASTER_KEY non configurato: integrità verificabile solo via export_sha256',
            'exported_at'       => date(DATE_ATOM),
            'exported_by'       => (string)(Auth::user()['username'] ?? 'unknown'),
            'exporter_user_id'  => (int)(Auth::user()['id'] ?? 0) ?: null,
            'filters_applied'   => $filters,
            'rows_count'        => count($rows),
            'legal_notice'      => 'Bundle generato per cooperazione con Autorità competente. Verifica integrità: '
                                 . 'sha256(export.' . $format . ') deve uguagliare export_sha256; HMAC deve essere '
                                 . 'verificato dal data controller con KMS_MASTER_KEY (off-line).',
        ];
        $manifestJson = (string)json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $zipPath = tempnam(sys_get_temp_dir(), 'fm-authority-export-');
        if ($zipPath === false) {
            return Response::html('<h1>500 ZIP tempfile failed</h1>', 500);
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            return Response::html('<h1>500 ZIP open failed</h1>', 500);
        }
        $zip->addFromString($exportFile, $exportBody);
        $zip->addFromString('manifest.json', $manifestJson);
        $zip->close();

        $zipBody = (string)@file_get_contents($zipPath);
        @unlink($zipPath);

        $filename = "authority-export-{$ts}.zip";
        return new Response($zipBody, 200, [
            'Content-Type'           => 'application/zip',
            'Content-Disposition'    => 'attachment; filename="' . $filename . '"',
            'Content-Length'         => (string)strlen($zipBody),
            'X-Content-Type-Options' => 'nosniff',
            'X-Pantedu-Export-Sha256' => $sha256,
        ]);
    }

    /**
     * Phase 25.R.23 — Bundle ZIP esteso con contenuti decifrati via UserDataExportService.
     *
     * Layout ZIP:
     *   custody/
     *     export.{json|csv}           (audit trail crypto_custody_events filtrati)
     *   profile/, content/, ...       (sezioni decifrate da UserDataExportService)
     *   manifest.json                 (sha256+hmac di TUTTI i file inclusi)
     *
     * @param array<string, \App\Services\Gdpr\Export\ExportSection>|null $extraSections
     */
    public static function buildSignedBundleWithExtras(
        string $exportBody,
        string $format,
        array $rows,
        array $filters,
        string $ts,
        ?array $extraSections = null
    ): Response {
        $custodyFile = 'custody/export.' . $format;
        $sha256      = hash('sha256', $exportBody);
        // Audit 25.R.31 — firma solo con KMS configurato, altrimenti UNSIGNED.
        $derivedKey  = self::deriveExportSigningKey();
        $signed      = $derivedKey !== null;
        $hmac        = $signed ? hash_hmac('sha256', $sha256, $derivedKey) : 'UNSIGNED';

        // Manifest base: file custody (audit trail crypto_custody_events)
        $filesEntry  = [[
            'path'   => $custodyFile,
            'sha256' => $sha256,
            'hmac'   => $hmac,
            'size'   => strlen($exportBody),
        ]];

        $extraSummary = [];
        $extraFiles   = [];

        if (is_array($extraSections)) {
            foreach ($extraSections as $key => $section) {
                $extraSummary[$key] = [
                    'label'       => $section->label,
                    'files_count' => $section->fileCount(),
                    'total_size'  => $section->totalSize(),
                    'summary'     => $section->summary,
                ];
                foreach ($section->files as $f) {
                    // HMAC singolo file per chain-of-custody dettagliata (o UNSIGNED).
                    $fileHmac = $signed ? hash_hmac('sha256', $f->sha256, $derivedKey) : 'UNSIGNED';
                    $filesEntry[] = [
                        'path'   => $f->relativePath,
                        'sha256' => $f->sha256,
                        'hmac'   => $fileHmac,
                        'size'   => $f->size(),
                    ];
                    $extraFiles[$f->relativePath] = $f->content;
                }
            }
        }

        $manifest = [
            'manifest_version'   => '2.0',  // bumped per supporto multi-file
            'product'            => 'pantedu',
            'export_purpose'     => 'authority-cooperation',
            'custody_file'       => $custodyFile,
            'custody_format'     => $format,
            'signed'             => $signed,
            'hmac_algorithm'     => $signed
                ? 'HMAC-SHA256(HKDF(KMS_MASTER_KEY, "pantedu-export-signing-v1"), sha256(file_content))'
                : 'UNSIGNED — KMS_MASTER_KEY non configurato: integrità verificabile solo via sha256',
            'exported_at'        => date(DATE_ATOM),
            'exported_by'        => (string)(Auth::user()['username'] ?? 'unknown'),
            'exporter_user_id'   => (int)(Auth::user()['id'] ?? 0) ?: null,
            'filters_applied'    => $filters,
            'custody_rows_count' => count($rows),
            'sections'           => $extraSummary,
            'files'              => $filesEntry,
            'legal_notice'       => 'Bundle generato per cooperazione con Autorità competente. '
                                  . 'Verifica integrità: per ogni file in `files[]`, sha256(content) '
                                  . 'deve uguagliare il sha256 dichiarato; HMAC deve essere verificato '
                                  . 'dal data controller con KMS_MASTER_KEY (off-line).',
        ];
        $manifestJson = (string)json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $zipPath = tempnam(sys_get_temp_dir(), 'fm-authority-export-');
        if ($zipPath === false) {
            return Response::html('<h1>500 ZIP tempfile failed</h1>', 500);
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            return Response::html('<h1>500 ZIP open failed</h1>', 500);
        }
        // Aggiungi custody → libera $exportBody ASAP
        $zip->addFromString($custodyFile, $exportBody);
        unset($exportBody);

        // Aggiungi file extra UNO A UNO + unset content dopo ogni add per liberare RAM
        foreach (array_keys($extraFiles) as $path) {
            $zip->addFromString($path, $extraFiles[$path]);
            unset($extraFiles[$path]);  // free RAM immediatamente
        }
        unset($extraFiles);

        $zip->addFromString('manifest.json', $manifestJson);
        $zip->close();

        $filename = "authority-export-{$ts}.zip";
        $zipSize  = filesize($zipPath) ?: 0;

        // Phase 25.R.23.1 — STREAMING via X-Serve-File header (Response::serveFile
        // usa readfile() che non carica l'intero ZIP in RAM). Peak memory =
        // dimensione del singolo file più grande nel ZIP, non l'intero ZIP.
        // Cleanup automatico via register_shutdown_function dopo send.
        register_shutdown_function(static function () use ($zipPath) {
            @unlink($zipPath);
        });

        return new Response('', 200, [
            'Content-Disposition'       => 'attachment; filename="' . $filename . '"',
            'Content-Length'            => (string)$zipSize,
            'X-Content-Type-Options'    => 'nosniff',
            'X-Pantedu-Export-Sha256' => $sha256,
            'X-Pantedu-Sections'      => (string)count($extraSummary),
            'X-Serve-File'              => $zipPath,
        ]);
    }

    /**
     * Deriva chiave di firma export da KMS_MASTER_KEY senza esporla.
     * Pattern HKDF-like: HMAC(KMS, label) = chiave separata, distinta dalla
     * crypto key principale. Rotazione KMS_MASTER_KEY rotea anche questa.
     */
    public static function deriveExportSigningKey(): ?string
    {
        $kms = $_ENV['KMS_MASTER_KEY'] ?? $_SERVER['KMS_MASTER_KEY'] ?? '';
        if (!is_string($kms) || $kms === '') {
            // Audit 25.R.31 — NIENTE fallback a chiave costante pubblica: prima
            // restituiva un hash di stringa nota → chiunque poteva forgiare un
            // bundle "firmato" mentre il manifest dichiarava firma forte. Ora
            // null → il bundle è marcato UNSIGNED (fail-explicit, no firma finta).
            return null;
        }
        // KMS_MASTER_KEY è hex 32 byte → decodifica
        $kmsBin = ctype_xdigit($kms) ? hex2bin($kms) : $kms;
        // Audit 25.R.31 — chiave troppo corta = inutilizzabile per firma seria.
        if (!is_string($kmsBin) || strlen($kmsBin) < 16) {
            return null;
        }
        return hash_hmac('sha256', 'pantedu-export-signing-v1', $kmsBin, true);
    }

    /**
     * POST /admin/crypto-status/event — registra evento custodia.
     * Append-only: nessun UPDATE/DELETE supportato.
     */
    public function recordEvent(Request $req): Response
    {
        $type = (string)($req->post['event_type'] ?? '');
        if (!in_array($type, self::EVENT_TYPES, true)) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'event_type non valido'];
            return Response::redirect('/admin/crypto-status');
        }
        $occurredAt = trim((string)($req->post['occurred_at'] ?? '')) ?: date('Y-m-d H:i:s');
        $description = trim((string)($req->post['description'] ?? ''));
        if ($description === '' || mb_strlen($description) < 10) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'description min 10 char'];
            return Response::redirect('/admin/crypto-status');
        }
        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO crypto_custody_events
                    (event_type, teacher_id, actor_user_id, authority_name, authority_ref,
                     custodian_name, custody_location, description, legal_basis,
                     evidence_url, occurred_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $tid = (int)($req->post['teacher_id'] ?? 0);
            $stmt->execute([
                $type,
                $tid > 0 ? $tid : null,
                (int)(Auth::user()['id'] ?? 0) ?: null,
                trim((string)($req->post['authority_name']  ?? '')) ?: null,
                trim((string)($req->post['authority_ref']   ?? '')) ?: null,
                trim((string)($req->post['custodian_name']  ?? '')) ?: null,
                trim((string)($req->post['custody_location'] ?? '')) ?: null,
                $description,
                trim((string)($req->post['legal_basis']     ?? '')) ?: null,
                trim((string)($req->post['evidence_url']    ?? '')) ?: null,
                $occurredAt,
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Evento registrato.'];
        } catch (Throwable $e) {
            error_log('[crypto] recordEvent failed: ' . $e->getMessage()); // Audit 25.R.31
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Operazione fallita.'];
        }
        return Response::redirect('/admin/crypto-status');
    }
}
