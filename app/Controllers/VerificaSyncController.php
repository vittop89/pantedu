<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\Crypto\TeacherRecoveryService;
use App\Services\Verifica\VerificaDocumentService;
use Throwable;

/**
 * G22.S15.bis Fase 5+ — Split di VerificaController (era 1383 righe).
 *
 * Responsabilita': sync verifiche su Drive + bundle manifest unificato
 * (per GitHub sync via TeacherGitHubController).
 *
 * Endpoint:
 *   POST /api/verifica/sync-all          → batch sync su Drive (delete-orphans)
 *   POST /api/verifica/sync-local-bundle → bundle paginato per GitHub sync
 *
 * API pubbliche per altri controller:
 *   buildLocalBundleManifest($tid) : array — manifest unificato verifiche
 *                                     + mappe + modelli (texCommon/risdoc/drawio)
 *   materializeBundleEntry($tid, $entry) : array — { path, content (b64) }
 *
 * Helper privati condivisi via VerificaSharedHelpersTrait (teacherId,
 * statusFor, latexErrorExcerpt, publicView, resolveInstituteCodeForTeacher,
 * buildBatchFilename, ...).
 */
final class VerificaSyncController
{
    use VerificaSharedHelpersTrait;

    private VerificaDocumentService $svc;

    public function __construct(?VerificaDocumentService $svc = null)
    {
        $this->svc = $svc ?? new VerificaDocumentService();
    }

    /**
     * G19.47 — POST /api/verifica/sync-all
     *
     * Push BATCH di tutte le verifiche del docente su Drive (best-effort,
     * mirror di MapsController::syncAll). Include delete-orphans (file su
     * Drive ma cancellati in DB). Limit + onlyChanged via $req->post.
     *
     * Response: `{ok, report: {count, ok, skip, error, deleted, items, deleted_items}}`
     */
    public function syncAll(Request $req): Response
    {
        try {
            $teacherId = $this->teacherId();
            // G22.S15.bis Fase 5 — release session lock dopo auth, per permettere
            // navigazione durante sync (PHP session lock altrimenti blocca tutte
            // le richieste successive sulla stessa session finché release).
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            @set_time_limit(0);
            // G19.48 — defensive: Drive sync per-doc carica TEX blob,
            // libera tra iterazioni, ma il delete-orphans elenca tutti
            // i .tex su Drive (lista paginata interna). Alza memory
            // limit per non saturare con docenti grandi.
            @ini_set('memory_limit', '512M');
            $limit = isset($req->post['limit']) ? max(1, (int)$req->post['limit']) : null;
            $onlyChanged = !empty($req->post['onlyChanged']) || !empty($req->post['onlyUnsynced']);
            $deleteOrphans = !isset($req->post['deleteOrphans']) || !empty($req->post['deleteOrphans']);

            $svc = new \App\Services\Drive\VerificaSyncService();
            $report = $svc->syncAllForTeacher($teacherId, $limit, $onlyChanged, $deleteOrphans);
            return Response::json(['ok' => true, 'report' => $report]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->statusFor($e));
        }
    }

    /**
     * G19.47 — GET /api/teacher/sync-local-bundle
     *
     * Ritorna JSON con TUTTI i file del docente (mappe + verifiche) per
     * scrittura locale via FS Access API. Owner only. Streaming-friendly:
     * la response e' un array di `{type, path, content_b64, size}`.
     */
    public function localBundle(Request $req): Response
    {
        try {
            $teacherId = $this->teacherId();
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            @set_time_limit(0);
            // G19.48 — bundle paginato: il loading di TUTTI i blob (TEX +
            // PDF + mappe) in un'unica risposta JSON saturava il
            // memory_limit (128 MB) per docenti con molti contenuti.
            // L'endpoint ora ritorna `?offset=0&limit=20` con `total` +
            // `hasMore` cosi' il client puo' chunkare le scritture FS
            // Access e mostrare progresso reale.
            @ini_set('memory_limit', '512M');

            $offset = max(0, (int)($req->query['offset'] ?? 0));
            $limit  = max(1, min(50, (int)($req->query['limit'] ?? 20)));

            // Build manifest unico (verifiche TEX + verifiche PDF + mappe)
            // ordinato deterministicamente (verifiche-by-id, mappe-by-id).
            // Manifesto = lista di descrittori SENZA blob: leggero, ok in JSON.
            $manifest = $this->buildLocalBundleManifest($teacherId);
            $total = \count($manifest);
            $slice = \array_slice($manifest, $offset, $limit);

            $files = [];
            foreach ($slice as $entry) {
                try {
                    $files[] = $this->materializeBundleEntry($teacherId, $entry);
                } catch (Throwable $e) {
                    error_log("localBundle entry " . json_encode($entry) . ": " . $e->getMessage());
                }
            }

            return Response::json([
                'ok'      => true,
                'offset'  => $offset,
                'limit'   => $limit,
                'total'   => $total,
                'hasMore' => ($offset + $limit) < $total,
                'files'   => $files,
            ], 200);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->statusFor($e));
        }
    }

    /**
     * G22.S20 — GET /api/teacher/sync-bundle/manifest
     *
     * Calcola manifest signed con HMAC(Recovery Key). Iterata TUTTE le entry
     * del bundle, materializzando blob per ottenere size + sha256. Memory
     * controllato: 1 blob alla volta in heap (no concatenazione).
     *
     * Response: `{ok, manifest: {version, exported_at, exporter_user_id,
     *           institute_code, files:[{path,size,sha256,type}], hmac}}`
     *
     * Errori:
     *   - 412 recovery_key_missing: docente non ha generato Recovery Key
     */
    public function manifestSigned(Request $req): Response
    {
        try {
            $teacherId = $this->teacherId();
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            @set_time_limit(0);
            @ini_set('memory_limit', '512M');

            $recovery = new TeacherRecoveryService();
            if (!$recovery->isConfigured()) {
                return Response::json(['ok' => false, 'error' => 'kms_not_configured'], 500);
            }
            $status = $recovery->status($teacherId);
            if (!$status['exists'] || !empty($status['revoked_at'])) {
                return Response::json([
                    'ok' => false,
                    'error' => 'recovery_key_missing',
                    'hint'  => 'Genera la Recovery Key prima di esportare il bundle (Dashboard → Sicurezza).',
                ], 412);
            }

            $entries = $this->buildLocalBundleManifest($teacherId);
            $files = [];
            foreach ($entries as $entry) {
                try {
                    $mat = $this->materializeBundleEntry($teacherId, $entry);
                    // mat.content è base64; sha256 calcolato sul binary
                    $bin = base64_decode((string)$mat['content'], true) ?: '';
                    $files[] = [
                        'path'   => (string)$mat['path'],
                        'size'   => (int)$mat['size'],
                        'sha256' => hash('sha256', $bin),
                        'type'   => (string)$mat['type'],
                    ];
                } catch (Throwable $e) {
                    error_log("manifestSigned entry " . json_encode($entry) . ": " . $e->getMessage());
                }
            }

            $instCode = $this->resolveInstituteCodeForTeacher($teacherId);
            $username = $this->teacherRecord($teacherId)['username'] ?? '';
            $payload = [
                'version'           => 1,
                'exported_at'       => date('c'),
                'exporter_user_id'  => $teacherId,
                'exporter_username' => (string)$username,
                'institute_code'    => (string)$instCode,
                'files'             => $files,
            ];

            $hmac = $recovery->signManifestForExporter($teacherId, $payload);
            if ($hmac === null) {
                return Response::json(['ok' => false, 'error' => 'recovery_key_missing'], 412);
            }
            $payload['hmac'] = $hmac;

            return Response::json(['ok' => true, 'manifest' => $payload]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->statusFor($e));
        }
    }

    /**
     * G20.0 — Costruisce manifesto del bundle locale del docente.
     * Emette:
     *   - 1 set texCommon (root istituto) — shared
     *   - N griglie (a livello indirizzo)
     *   - per ogni batch: main_*.tex + esercizi_*.tex nel version folder
     *   - 1 entry per ogni mappa (immutata)
     *
     * Ogni entry e' SERVED-INLINE (non bisogna readBlob): texCommon/main/
     * problemi/griglie generati al volo via TexBuilder. Solo mappe e PDF
     * verifica leggono i blob crypto (`blob_path` field).
     *
     * @return list<array{type:string,path:string,content_inline?:string,blob_path?:string,id?:int}>
     */
    public function buildLocalBundleManifest(int $teacherId): array
    {
        $instCode = $this->resolveInstituteCodeForTeacher($teacherId);
        // G19.49b — sanitize forte per Windows/macOS/Linux: rimuove i
        // caratteri vietati `< > : " / \ | ? *` (Windows reserved) +
        // control chars + dot/space trailing. Necessario perche'
        // nomi tipo `Funzioni: definizioni e tipologie` o
        // `f(x)?` falliscono FS Access API write su Windows.
        $clean = static function (string $s): string {
            $s = preg_replace('/[\x00-\x1F\x7F]+/u', '', $s) ?? $s;
            $s = preg_replace('#[<>:"/\\\\|?*]+#u', '_', $s) ?? $s;
            $s = trim($s, '. ');
            return $s !== '' ? $s : 'general';
        };

        // G22.S20 v2 — Canonicalize indirizzo legacy lowercase (sc/cl/li/ar)
        // → UPPER 3-char (SCI/CLA/LIN/ART). Allinea verifiche/mappe/esercizi
        // sotto un'unica gerarchia di cartelle nel bundle, evita doppioni
        // (es. {ist}/ar/... + {ist}/ART/...). Mirror AuthController $map.
        $canonInd = static function (string $s): string {
            $low = strtolower(trim($s));
            $map = [
                'sc' => 'SCI', 'cl' => 'CLA', 'li' => 'LIN', 'ling' => 'LIN',
                'ar' => 'ART', 'af' => 'AFM',
            ];
            if (isset($map[$low])) {
                return $map[$low];
            }
            // Già canonico (SCI/CLA/...) → UPPER per uniformità
            return $s !== '' ? strtoupper($s) : '';
        };

        $manifest = [];
        $repo = new \App\Repositories\VerificaDocumentRepository();
        $verificaDocs = $repo->listForTeacher($teacherId);

        // G19.49 — pre-aggrega kinds per batch_id (per version_folder
        // condiviso tra varianti SOL/NOR/DSA/DIS dello stesso batch).
        $kindsByBatch = [];
        foreach ($verificaDocs as $doc) {
            $bid = (string)($doc['batch_id'] ?? '');
            if ($bid === '') {
                continue;
            }
            $variant = (string)($doc['variant'] ?? '');
            if (preg_match('/(SOL|NOR|DSA|DIS)$/', $variant, $m)) {
                $kindsByBatch[$bid][$m[1]] = true;
            }
        }

        foreach ($verificaDocs as $doc) {
            // G22.S4.B.2 — accetta sia row multi-file (tex_files manifest)
            // sia row legacy (tex_blob_path). Skip solo se nessuno dei due.
            $hasMultiFile = !empty($doc['tex_files']) && \is_array($doc['tex_files']);
            $hasLegacyBlob = !empty($doc['tex_blob_path']);
            if (!$hasMultiFile && !$hasLegacyBlob) {
                continue;
            }

            // G22.S20 v2.C2 Fase C — usa BundlePathBuilder (same source of
            // truth con Drive sync). canonInd resta solo per logging legacy.
            $title = preg_replace('/\s*[—-]\s*[AB]_(SOL|NOR|DSA|DIS)\s*$/u', '', (string)$doc['title']) ?: 'verifica';
            $variant = (string)($doc['variant'] ?? '');
            $filename = self::buildBatchFilename($doc, $variant ?: 'A_NOR');
            $versionFolder = $this->buildVerificaVersionFolder($doc, $kindsByBatch);
            $sub = \App\Support\BundlePathBuilder::verificaPath(
                $instCode,
                $doc['indirizzo'] ?? null,
                $doc['classe']    ?? null,
                (string)$doc['materia'],
                $title,
                $versionFolder,
                $filename
            );
            $subBase = substr($sub, 0, strrpos($sub, '/')); // strip filename per PDF later

            $manifest[] = [
                'type'      => 'verifica-tex',
                'id'        => (int)$doc['id'],
                'path'      => $sub,
                'blob_path' => $hasLegacyBlob ? (string)$doc['tex_blob_path'] : '',
                'multi'     => $hasMultiFile,
            ];
            if (!empty($doc['pdf_blob_path'])) {
                $pdfName = preg_replace('/\.tex$/', '.pdf', $filename);
                $manifest[] = [
                    'type'      => 'verifica-pdf',
                    'id'        => (int)$doc['id'],
                    'path'      => "{$subBase}/{$pdfName}",
                    'blob_path' => (string)$doc['pdf_blob_path'],
                ];
            }
        }

        $stmt = \App\Core\Database::connection()->prepare(
            'SELECT id, title, topic, subject_code, indirizzo, classe,
                    map_blob_path, map_mime
             FROM teacher_content
             WHERE teacher_id = ? AND content_type = "mappa"
               AND map_blob_path IS NOT NULL
             ORDER BY id ASC'
        );
        $stmt->execute([$teacherId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $m) {
            // G22.S20 v2.C2 Fase C — usa BundlePathBuilder.
            $mapPath = \App\Support\BundlePathBuilder::mapPath(
                $instCode,
                $m['indirizzo']    ?? null,
                $m['classe']       ?? null,
                $m['subject_code'] ?? null,
                (string)$m['title'],
                (string)$m['map_mime']
            );
            $manifest[] = [
                'type'      => 'mappa',
                'id'        => (int)$m['id'],
                'path'      => $mapPath,
                'blob_path' => (string)$m['map_blob_path'],
                'mime'      => (string)$m['map_mime'],
            ];
        }

        // ── G22.S15.bis Fase 5 — Modelli docente sotto {institute}/modelli/ ──
        // texCommon e risdoc (cascade default → teacher override) inclusi
        // nel bundle locale per allineamento drive/local/github.
        $instSlug = $clean($instCode);
        $rootPathBase = "{$instSlug}/modelli";

        // (a) modelli/texCommon: defaults in storage/templates/verifiche/_default/texCommon/
        //     overrides in storage/templates/verifiche/teachers/{tid}/texCommon/
        $appRoot = dirname(__DIR__, 2); // app/Controllers/.. → app/.. → root
        $defaultRoot = $appRoot . '/storage/templates/verifiche/_default/texCommon';
        $teacherRoot = $appRoot . "/storage/templates/verifiche/teachers/{$teacherId}/texCommon";
        $emitted = []; // dedup per path relativo
        $walkTpl = function (string $absRoot, string $relPrefix) use (&$manifest, $rootPathBase, &$emitted): void {
            if (!is_dir($absRoot)) {
                return;
            }
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absRoot, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iter as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($absRoot))), '/');
                if ($rel === '') {
                    continue;
                }
                $key = $relPrefix . '/' . $rel;
                $emitted[$key] = $file->getPathname(); // override per path (teacher vince)
            }
        };
        $walkTpl($defaultRoot, 'texCommon');
        $walkTpl($teacherRoot, 'texCommon'); // sovrascrive default
        // (b) modelli/risdoc: defaults in storage/templates/risdoc/texCommon/ +
        //     overrides in DB risdoc_teacher_overrides (template_id=0, kind=texCommon)
        $risdocDefaultRoot = $appRoot . '/storage/templates/risdoc/texCommon';
        $walkTpl($risdocDefaultRoot, 'risdoc');
        // Risdoc teacher overrides via DB
        try {
            $repo = new \App\Services\Risdoc\OverrideRepository();
            foreach (['main.tex', 'risdoc.sty', 'intestaLAteX_IIS.tex'] as $rel) {
                $ov = $repo->find($teacherId, 0, 'texCommon', $rel);
                if ($ov && !empty($ov['body'])) {
                    // override DB → emetti come content_inline (no file su disco)
                    $manifest[] = [
                        'type'           => 'template-inline',
                        'path'           => "{$rootPathBase}/risdoc/{$rel}",
                        'content_inline' => (string)$ov['body'],
                    ];
                    unset($emitted["risdoc/{$rel}"]); // skip default per questo file
                }
            }
        } catch (\Throwable) {
/* repo non disponibile, skip */
        }
        // Emetti file da filesystem (default + teacher overrides)
        foreach ($emitted as $relKey => $absPath) {
            $manifest[] = [
                'type'      => 'template-file',
                'path'      => "{$rootPathBase}/{$relKey}",
                'abs_path'  => $absPath,
            ];
        }

        // (c) modelli/tikz: defaults admin + teacher overrides JSON
        $tikzAdminDefaults = $appRoot . '/storage/data/modelli_tikz_elements.json';
        if (is_file($tikzAdminDefaults)) {
            $manifest[] = [
                'type'      => 'template-file',
                'path'      => "{$rootPathBase}/tikz/elements-default.json",
                'abs_path'  => $tikzAdminDefaults,
            ];
        }
        $tikzTeacherOverrides = $appRoot . "/storage/objects/teachers/{$teacherId}/tikz-overrides.json";
        if (is_file($tikzTeacherOverrides)) {
            $manifest[] = [
                'type'      => 'template-file',
                'path'      => "{$rootPathBase}/tikz/overrides.json",
                'abs_path'  => $tikzTeacherOverrides,
            ];
        }

        // (d) modelli/drawio: shape libraries XML (default + teacher overrides)
        // walkDrawio è separato da walkTpl perché ha namespace own (no
        // pollution di $emitted di texCommon/risdoc).
        $drawioEmitted = [];
        $walkDrawio = function (string $absRoot) use (&$drawioEmitted): void {
            if (!is_dir($absRoot)) {
                return;
            }
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absRoot, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iter as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                if (strtolower($file->getExtension()) !== 'xml') {
                    continue;
                }
                $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($absRoot))), '/');
                if ($rel === '') {
                    continue;
                }
                $drawioEmitted[$rel] = $file->getPathname(); // teacher vince
            }
        };
        $walkDrawio($appRoot . '/storage/templates/drawio/_default');
        $walkDrawio($appRoot . "/storage/templates/drawio/teachers/{$teacherId}");
        foreach ($drawioEmitted as $rel => $absPath) {
            $manifest[] = [
                'type'      => 'template-file',
                'path'      => "{$rootPathBase}/drawio/{$rel}",
                'abs_path'  => $absPath,
            ];
        }

        // ── G22.S20 v2 — Esercizi standalone ───────────────────────────
        // content_type='esercizio' in teacher_content sono record con:
        //  - metadata_json: lookup data + contract_key
        //  - file su filesystem: storage/objects/{contract_key}
        // Esporta entrambi in singolo JSON bundle entry per re-import.
        $stmt = \App\Core\Database::connection()->prepare(
            'SELECT id, subject_code, indirizzo, classe, topic, title, metadata_json
               FROM teacher_content
              WHERE teacher_id = ? AND content_type = "esercizio"
              ORDER BY id ASC'
        );
        $stmt->execute([$teacherId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $e) {
            $path = \App\Support\BundlePathBuilder::esercizioPath(
                $instCode,
                $e['indirizzo']    ?? null,
                $e['classe']       ?? null,
                $e['subject_code'] ?? null,
                (string)$e['title']
            );
            $manifest[] = [
                'type'           => 'esercizio',
                'id'             => (int)$e['id'],
                'path'           => $path,
                'subject_code'   => (string)($e['subject_code'] ?? ''),
                'indirizzo'      => (string)$e['indirizzo'],
                'classe'         => (string)$e['classe'],
                'topic'          => (string)$e['topic'],
                'title'          => (string)$e['title'],
                'metadata_json'  => (string)$e['metadata_json'],
            ];
        }

        // ── G22.S20 v2 — Documenti generici (content_type='documento') ──
        $stmt = \App\Core\Database::connection()->prepare(
            'SELECT id, subject_code, indirizzo, classe, topic, title, metadata_json, body_html
               FROM teacher_content
              WHERE teacher_id = ? AND content_type = "documento"
              ORDER BY id ASC'
        );
        $stmt->execute([$teacherId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $d) {
            $path = \App\Support\BundlePathBuilder::documentoPath(
                $instCode,
                $d['indirizzo']    ?? null,
                $d['classe']       ?? null,
                $d['subject_code'] ?? null,
                (string)$d['title']
            );
            $manifest[] = [
                'type'          => 'documento',
                'id'            => (int)$d['id'],
                'path'          => $path,
                'subject_code'  => (string)($d['subject_code'] ?? ''),
                'indirizzo'     => (string)$d['indirizzo'],
                'classe'        => (string)$d['classe'],
                'topic'         => (string)$d['topic'],
                'title'         => (string)$d['title'],
                'metadata_json' => (string)$d['metadata_json'],
                'body_html'     => (string)($d['body_html'] ?? ''),
            ];
        }

        return $manifest;
    }

    /**
     * G19.49 — `{version_label|"v0"}-{DD_MM_YYYY}-{KINDS}` per il doc.
     * KINDS = ordinati SOL_NOR_DSA_DIS dai variant del batch (passati in
     * `$kindsByBatch` pre-aggregato). Stringa vuota se mancano dati.
     */
    private function buildVerificaVersionFolder(array $doc, array $kindsByBatch): string
    {
        $bid = (string)($doc['batch_id'] ?? '');
        $kindsSet = $kindsByBatch[$bid] ?? [];
        if (!$kindsSet) {
            $variant = (string)($doc['variant'] ?? '');
            if ($variant !== '' && preg_match('/(SOL|NOR|DSA|DIS)$/', $variant, $m)) {
                $kindsSet = [$m[1] => true];
            }
        }
        if (!$kindsSet) {
            return '';
        }

        $order = ['SOL', 'NOR', 'DSA', 'DIS'];
        $kinds = [];
        foreach ($order as $k) {
            if (isset($kindsSet[$k])) {
                $kinds[] = $k;
            }
        }
        if (!$kinds) {
            return '';
        }

        $label = (string)($doc['version_label'] ?? '');
        if ($label === '') {
            $label = 'v0';
        }

        $createdAt = (string)($doc['created_at'] ?? '');
        $ts = $createdAt !== '' ? strtotime($createdAt) : false;
        if ($ts === false || $ts === 0) {
            $ts = time();
        }
        $date = date('d_m_Y', $ts);

        return $label . '-' . $date . '-' . implode('_', $kinds);
    }

    /**
     * G19.48 — legge il blob (TEX/PDF/mappa) di una entry del manifesto e
     * lo decora con `content` base64. Memoria controllata: si tiene solo
     * il blob corrente in heap.
     */
    public function materializeBundleEntry(int $teacherId, array $entry): array
    {
        switch ($entry['type']) {
            case 'verifica-tex':
                $bin = $this->svc->readTex($teacherId, (int)$entry['id']);
                break;
            case 'verifica-pdf':
                $pdf = $this->svc->readPdf($teacherId, (int)$entry['id']);
                $bin = $pdf['binary'];
                break;
            case 'mappa':
                $store = new \App\Services\Maps\MapBlobStore();
                $bin = $store->get($teacherId, (string)$entry['blob_path']);
                break;
            case 'template-file':
                // Modelli su filesystem (default + teacher override texCommon/risdoc)
                $abs = (string)($entry['abs_path'] ?? '');
                if ($abs === '' || !is_file($abs)) {
                    throw new \RuntimeException('template_file_missing:' . ($entry['path'] ?? '?'));
                }
                $bin = (string)file_get_contents($abs);
                break;
            case 'template-inline':
                // Override risdoc DB con content già in memory
                $bin = (string)($entry['content_inline'] ?? '');
                break;
            case 'esercizio':
                // G22.S20 v2 — esporta DB row + .contract.json filesystem
                // come singolo wrapper JSON. metadata_json contiene
                // contract_key che punta a storage/objects/{contract_key}.
                $meta = json_decode((string)$entry['metadata_json'], true) ?: [];
                $contractRel = (string)($meta['contract_key'] ?? '');
                $contractAbs = $contractRel !== ''
                    ? dirname(__DIR__, 2) . '/storage/objects/' . $contractRel
                    : '';
                $contractContent = ($contractAbs !== '' && is_file($contractAbs))
                    ? json_decode((string)file_get_contents($contractAbs), true)
                    : null;
                $wrapper = [
                    'kind'         => 'esercizio',
                    'version'      => 1,
                    'db_row'       => [
                        'subject_code' => $entry['subject_code'] ?? '',
                        'indirizzo'    => $entry['indirizzo']    ?? '',
                        'classe'       => $entry['classe']       ?? '',
                        'topic'        => $entry['topic']        ?? '',
                        'title'        => $entry['title']        ?? '',
                    ],
                    'metadata'     => $meta,
                    'contract'     => $contractContent,
                    'contract_relpath' => $contractRel, // info per ricostruire path
                ];
                $bin = (string)json_encode($wrapper, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                break;
            case 'documento':
                $meta = json_decode((string)$entry['metadata_json'], true) ?: [];
                $wrapper = [
                    'kind'    => 'documento',
                    'version' => 1,
                    'db_row'  => [
                        'subject_code' => $entry['subject_code'] ?? '',
                        'indirizzo'    => $entry['indirizzo']    ?? '',
                        'classe'       => $entry['classe']       ?? '',
                        'topic'        => $entry['topic']        ?? '',
                        'title'        => $entry['title']        ?? '',
                        'body_html'    => $entry['body_html']    ?? '',
                    ],
                    'metadata' => $meta,
                ];
                $bin = (string)json_encode($wrapper, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                break;
            default:
                throw new \RuntimeException('unknown_bundle_type:' . $entry['type']);
        }
        return [
            'type'    => $entry['type'],
            'path'    => (string)$entry['path'],
            'content' => base64_encode($bin),
            'size'    => \strlen($bin),
        ];
    }
}
