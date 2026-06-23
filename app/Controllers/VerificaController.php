<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Services\TexBuilder;
use App\Services\TexBuilder\Selection;
use App\Services\TexBuilder\VersionPicker;
use App\Services\TexCompile\TexCompileClient;
use App\Services\Verifica\VerificaDocumentService;
use Throwable;

/**
 * Phase G8 — REST per verifica_documents (TEX/PDF cifrato).
 *
 *   POST   /api/verifica/save-tex     → genera + salva TEX (envelope encrypt)
 *   GET    /api/verifica/list         → lista verifiche del docente (filter materia)
 *   GET    /api/verifica/{id}/tex     → download .tex (Content-Disposition)
 *   POST   /api/verifica/{id}/pdf     → upload PDF compilato (G8.8)
 *   GET    /api/verifica/{id}/pdf     → stream PDF inline (G8.8)
 *   POST   /api/verifica/{id}/delete  → cancella verifica + blob
 *
 * Sicurezza:
 *   - middleware csrf + rate sui mutator (vedi routes/web.php)
 *   - teacher_id da session (Auth::id()) — no spoofing
 *   - VerificaDocumentService::requireOwn previene cross-teacher access
 *   - Selection JSON max 2 MiB, TEX risultante max 4 MiB (validati nel service)
 */
final class VerificaController
{
    use VerificaSharedHelpersTrait;

    private VerificaDocumentService $svc;
    private TexBuilder $tex;

    public function __construct(
        ?VerificaDocumentService $svc = null,
        ?TexBuilder $tex = null
    ) {
        $this->svc = $svc ?? new VerificaDocumentService();
        $this->tex = $tex ?? new TexBuilder();
    }

    /**
     * POST /api/verifica/save-tex
     *
     * Body JSON Selection (stesso schema di /teacher/print) + opzionali:
     *   - title      string  (override del verTitle)
     *   - materia    string  (override del selectedMATER)
     *   - exercise_ids int[] (snapshot per audit; defaults a problemId numerici)
     *   - variant    string  (NORMAL|DSA|DIS; default NORMAL)
     */
    public function saveTex(Request $req): Response
    {
        try {
            $teacherId = $this->teacherId();
            $payload   = $this->readJsonBody();

            $sel     = Selection::fromArray($payload);
            $variant = (string)($payload['variant'] ?? VersionPicker::NORMAL);
            // G22.S4 — buildFlat ritorna .tex monolitico self-contained.
            // G27.badge — propaga teacher_id+institute_id per BadgeRenderer (SOL).
            $instituteId = \App\Support\TeacherContextResolver::firstInstituteId($teacherId);
            $tex     = $this->tex->buildFlat($sel, $variant, [
                'teacher_id'   => $teacherId,
                'institute_id' => $instituteId,
            ]);

            $title   = trim((string)($payload['title'] ?? $sel->verTitle));
            $materia = trim((string)($payload['materia'] ?? $sel->mater));
            $excIds  = $this->extractExerciseIds($payload, $sel);

            // G10 — costruisce template_context con info docente + selection
            // per substitute placeholders {{KEY}} nei frammenti template.
            // Auth::user() ritorna solo username/role; per first_name/last_name/
            // email serve teacherRecord() che fa lookup DB.
            $context = \App\Services\Verifica\VerificaTemplateStandard::buildContext(
                $this->teacherRecord($teacherId),
                $payload,
                ['materia' => $materia]
            );

            $doc = $this->svc->saveTex([
                'teacher_id'       => $teacherId,
                'materia'          => $materia,
                'title'            => $title,
                'tex'              => $tex,
                'exercise_ids'     => $excIds,
                'fm_db_section'    => (string)($payload['fm_db_section'] ?? 'VERIFICHE'),
                'template_context' => $context,
            ]);

            return Response::json(['ok' => true, 'doc' => $this->publicView($doc)], 200);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->statusFor($e));
        }
    }

    /**
     * G16 — POST /api/verifica/save-tex-batch
     *
     * Genera in batch fino a 8 varianti A/B × {SOL, NOR, DSA, DIS} basate
     * su flag InfoVer (#DSA, #nPrint, #nPrintDSA, #nPrintDIS) o lista
     * esplicita `variants` nel payload.
     *
     * Risposta: { ok, batch_id, docs: [{id, variant, title, ...}] }
     */
    public function saveTexBatch(Request $req): Response
    {
        try {
            $teacherId = $this->teacherId();
            $payload   = $this->readJsonBody();
            // G19.44 — `force=1` query bypassa conflict check (sovrascrive)
            $force = (string)($req->query['force'] ?? '') === '1';

            $sel       = Selection::fromArray($payload);
            $title     = trim((string)($payload['title']   ?? $sel->verTitle));
            $materia   = trim((string)($payload['materia'] ?? $sel->mater));
            $excIds    = $this->extractExerciseIds($payload, $sel);

            $context = \App\Services\Verifica\VerificaTemplateStandard::buildContext(
                $this->teacherRecord($teacherId),
                $payload,
                ['materia' => $materia]
            );

            $result = $this->svc->saveBatch([
                'teacher_id'        => $teacherId,
                'materia'           => $materia,
                'title'             => $title,
                'selection'         => $payload,
                'exercise_ids'      => $excIds,
                'fm_db_section'    => (string)($payload['fm_db_section'] ?? 'VERIFICHE'),
                'template_context' => $context,
                'tipologia'        => (string)($payload['tipologia'] ?? 'scritto'),
                // G19.44 — version_label utente (#versione input) + force flag
                'version_label'    => (string)($payload['version_label'] ?? ''),
                'force'            => $force,
                // Flag InfoVer
                'dsa'              => !empty($payload['dsa']),
                'compensa'         => !empty($payload['compensa']),
                'includeGriglia'   => isset($payload['includeGriglia']) ? !empty($payload['includeGriglia']) : true,
                'includeMisure'    => isset($payload['includeMisure'])  ? !empty($payload['includeMisure'])  : true,
                // Conteggi copie (governano quali varianti generare)
                'nPrint'           => (int)($payload['nPrint']    ?? 0),
                'nPrintDSA'        => (int)($payload['nPrintDSA'] ?? 0),
                'nPrintDIS'        => (int)($payload['nPrintDIS'] ?? 0),
                // G19.7 — versions ['A','R'] dal client (basato sui checkbox
                // A / R sui .fm-groupcollex header). Limita le varianti generate.
                // Es: solo .checkboxA spuntato → versions=['A'] → 4 varianti
                // (A_SOL/A_NOR/A_DSA/A_DIS). Solo R → 4 (B_*). Entrambi → 8.
                'versions'         => $payload['versions'] ?? null,
                // Override esplicito (opzionale, prevale su versions)
                'variants'         => $payload['variants'] ?? null,
            ]);

            $publicDocs = array_map(
                fn($d) => [...$this->publicView($d), 'variant' => $d['variant'] ?? '', 'batch_id' => $d['batch_id'] ?? ''],
                $result['docs']
            );

            return Response::json([
                'ok'       => true,
                'batch_id' => $result['batch_id'],
                'docs'     => $publicDocs,
                'zip_url'  => '/api/verifica/batch/' . $result['batch_id'] . '/zip',
            ], 200);
        } catch (Throwable $e) {
            // G19.44 — conflict (verifica_version_conflict) → 409 con detail
            $msg = $e->getMessage();
            if (str_starts_with($msg, '{') && str_contains($msg, 'verifica_version_conflict')) {
                $info = json_decode($msg, true);
                if (is_array($info)) {
                    return Response::json([
                        'ok' => false,
                        'conflict' => $info,
                        'error' => 'verifica_version_conflict',
                    ], 409);
                }
            }
            return Response::json(['ok' => false, 'error' => $msg], $this->statusFor($e));
        }
    }



    /**
     * GET /api/verifica/list?materia=MAT&section=VERIFICHE&indirizzo=sc&classe=2
     * Lista verifiche del docente per render fm-db-block sidebar (G8.7).
     * G20.5 — `indirizzo` e `classe` filtrano per scope: senza questi parametri
     * la sidebar mostrava verifiche di classi diverse mischiate.
     */
    /**
     * Phase 25.Q.15 — GET /api/study/verifica/list — endpoint pubblico
     * (auth required, no role) per studenti che vedono verifiche condivise
     * con il pool del proprio istituto (shared_with_pool=1), filtrate per
     * indirizzo+classe.
     *
     * Sicurezza:
     *  - Auth obbligatoria (no guest)
     *  - Solo verifiche con shared_with_pool=1 (esplicito consent docente)
     *  - Scope istituto via users.institute_id (student) o teacher_institutes
     *    pivot (teacher/admin — fallback al primo istituto del pivot)
     *  - Niente body TEX/PDF binari nella response, solo metadati
     */
    public function listForStudent(Request $req): Response
    {
        try {
            $u = \App\Core\Auth::user();
            if (!$u) {
                return Response::json(['ok' => false, 'error' => 'unauthorized'], 401);
            }
            $userId = (int)($u['id'] ?? 0);
            if ($userId <= 0) {
                return Response::json(['ok' => false, 'error' => 'invalid_user'], 401);
            }
            // Risolvi istituto: student → users.institute_id, teacher → primo
            // pivot teacher_institutes (fallback comune).
            $pdo = \App\Core\Database::connection();
            $role = (string)\App\Core\Auth::role();
            $instituteId = 0;
            if ($role === 'student') {
                $stmt = $pdo->prepare('SELECT institute_id FROM users WHERE id = ?');
                $stmt->execute([$userId]);
                $instituteId = (int)$stmt->fetchColumn();
            } else {
                $stmt = $pdo->prepare(
                    'SELECT institute_id FROM teacher_institutes
                     WHERE user_id = ? ORDER BY created_at, institute_id LIMIT 1'
                );
                $stmt->execute([$userId]);
                $instituteId = (int)$stmt->fetchColumn();
            }
            if ($instituteId <= 0) {
                return Response::json(['ok' => true, 'items' => [], 'materie' => []], 200);
            }
            $indirizzo = trim((string)($req->query['indirizzo'] ?? ''));
            $classe    = trim((string)($req->query['classe'] ?? ''));
            $rows = (new \App\Repositories\VerificaDocumentRepository())->listSharedForInstitute(
                $instituteId,
                $indirizzo !== '' ? $indirizzo : null,
                $classe !== '' ? $classe : null,
            );
            $items = array_map([$this, 'publicView'], $rows);
            $materie = array_values(array_unique(array_map(fn($r) => (string)$r['materia'], $rows)));
            return Response::json(['ok' => true, 'items' => $items, 'materie' => $materie], 200);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->statusFor($e));
        }
    }

    public function listForTeacher(Request $req): Response
    {
        try {
            $teacherId = $this->teacherId();
            $materia   = trim((string)($req->query['materia'] ?? ''));
            $section   = trim((string)($req->query['section'] ?? ''));
            $indirizzo = trim((string)($req->query['indirizzo'] ?? ''));
            $classe    = trim((string)($req->query['classe'] ?? ''));
            $rows = $this->svc->listForTeacher(
                $teacherId,
                $materia   !== '' ? $materia   : null,
                $indirizzo !== '' ? $indirizzo : null,
                $classe    !== '' ? $classe    : null,
            );
            if ($section !== '') {
                $rows = array_values(array_filter($rows, fn($r) => $r['fm_db_section'] === $section));
            }
            $materie = $this->svc->listMaterieForTeacher($teacherId);
            $items   = array_map([$this, 'publicView'], $rows);
            return Response::json(['ok' => true, 'items' => $items, 'materie' => $materie], 200);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->statusFor($e));
        }
    }

    /**
     * G22.S23 — POST /api/verifica/{id}/share-pool
     * Body: enabled=0|1
     *
     * Toggle shared_with_pool su verifica_documents. Solo l'owner puo'
     * cambiare. Dopo share, i docenti dello stesso istituto vedranno la
     * verifica nel pool dashboard "Recupera materiali da altri docenti".
     */
    public function sharePool(Request $req, array $params): Response
    {
        try {
            $teacherId = $this->teacherId();
            $id = (int)($params['id'] ?? 0);
            if ($id <= 0) {
                return Response::json(['ok' => false, 'error' => 'invalid_id'], 400);
            }
            $raw = $req->post['enabled'] ?? $req->input('enabled', 0);
            $enabled = (bool)\filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            // G22.S25 — delega a SharedContentPolicy (ownership check + update
            // + nessuna propagation per verifica_documents).
            $policy = new \App\Services\Sharing\SharedContentPolicy();
            $result = $policy->toggleSharePool($teacherId, 'verifica_documents', $id, $enabled);
            if (!$result['ok']) {
                $status = $result['error'] === 'forbidden' ? 403 : 400;
                return Response::json(['ok' => false, 'error' => $result['error']], $status);
            }
            return Response::json(['ok' => true, 'id' => $id, 'shared_with_pool' => $enabled]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/verifica/{id}/tex
     * Download .tex con Content-Disposition: attachment.
     */
    public function downloadTex(Request $req, array $params): Response
    {
        try {
            $teacherId = $this->teacherId();
            $id = (int)($params['id'] ?? 0);
            $tex = $this->svc->readTex($teacherId, $id);
            $filename = $this->safeFilename($id, 'tex');
            return new Response(
                body: $tex,
                status: 200,
                headers: [
                    'Content-Type'        => 'application/x-tex; charset=UTF-8',
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                    'Cache-Control'       => 'no-store',
                ],
            );
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->statusFor($e));
        }
    }

    /**
     * GET /api/verifica/{id}/zip
     *
     * Pacchetto ZIP con: verifica_{id}.tex (sempre) + verifica_{id}.pdf
     * (se caricato) + README.txt con metadata. Owner only.
     */
    public function zipExport(Request $req, array $params): Response
    {
        try {
            $teacherId = $this->teacherId();
            $id = (int)($params['id'] ?? 0);
            // Recupera doc + tex + pdf opzionale tramite service.
            $tex = $this->svc->readTex($teacherId, $id);
            $doc = (new \App\Repositories\VerificaDocumentRepository())->find($id);
            if (!$doc) {
                return Response::json(['ok' => false, 'error' => 'verifica_not_found'], 404);
            }
            $pdf = null;
            if (!empty($doc['pdf_blob_path'])) {
                $pdf = $this->svc->readPdf($teacherId, $id);
            }

            // Build zip in tmp file, stream + unlink.
            $tmp = tempnam(sys_get_temp_dir(), 'fmvz_');
            if ($tmp === false) {
                throw new \RuntimeException('verifica_zip_tmp_failed');
            }
            $zip = new \ZipArchive();
            if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
                @unlink($tmp);
                throw new \RuntimeException('verifica_zip_open_failed');
            }
            $base = 'verifica_' . $id;
            $zip->addFromString("$base.tex", $tex);
            if ($pdf) {
                $pdfName = $pdf['filename'] ?: ($base . '.pdf');
                $zip->addFromString($pdfName, $pdf['binary']);
            }
            $readme = "Verifica id: {$id}\n"
                    . "Title:       {$doc['title']}\n"
                    . "Materia:     {$doc['materia']}\n"
                    . "Section:     {$doc['fm_db_section']}\n"
                    . "Created:     {$doc['created_at']}\n"
                    . "Updated:     {$doc['updated_at']}\n"
                    . "TEX size:    {$doc['tex_size']} bytes\n"
                    . ($doc['pdf_size'] ? "PDF size:    {$doc['pdf_size']} bytes\n" : "PDF:         (non caricato)\n")
                    . "\nGenerato da Pantedu — Phase G8 (verifica_documents zip export).\n";
            $zip->addFromString('README.txt', $readme);
            $zip->close();

            $body = file_get_contents($tmp);
            @unlink($tmp);
            if ($body === false) {
                throw new \RuntimeException('verifica_zip_read_failed');
            }

            return new Response(
                body: $body,
                status: 200,
                headers: [
                    'Content-Type'        => 'application/zip',
                    'Content-Disposition' => 'attachment; filename="' . $base . '.zip"',
                    'Content-Length'      => (string)\strlen($body),
                    'Cache-Control'       => 'private, no-store',
                ],
            );
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->statusFor($e));
        }
    }

    /** POST /api/verifica/{id}/delete — cancella row + blob TEX/PDF. */
    public function delete(Request $req, array $params): Response
    {
        try {
            $teacherId = $this->teacherId();
            $id = (int)($params['id'] ?? 0);
            $this->svc->deleteDoc($teacherId, $id);
            return Response::json(['ok' => true], 200);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->statusFor($e));
        }
    }

    /**
     * POST /api/verifica/{id}/pdf — upload PDF compilato.
     *
     * Forme accettate:
     *   - multipart/form-data: $_FILES['file'] (preferito, drag&drop)
     *   - application/pdf body raw (fallback CLI/curl)
     *
     * Validazioni dietro VerificaDocumentService::attachPdf:
     *   max 30 MiB, magic bytes %PDF-, ownership.
     */
    public function uploadPdf(Request $req, array $params): Response
    {
        try {
            $teacherId = $this->teacherId();
            $id = (int)($params['id'] ?? 0);

            $binary = '';
            $filename = '';
            if (
                !empty($_FILES['file']['tmp_name'])
                && is_uploaded_file($_FILES['file']['tmp_name'])
            ) {
                if (($_FILES['file']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                    throw new \RuntimeException('verifica_pdf_upload_error');
                }
                $binary   = (string)file_get_contents($_FILES['file']['tmp_name']);
                $filename = (string)($_FILES['file']['name'] ?? 'verifica.pdf');
            } else {
                $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
                if (stripos($ctype, 'application/pdf') !== false) {
                    $binary = (string)file_get_contents('php://input');
                    $filename = (string)($req->query['filename'] ?? 'verifica.pdf');
                }
            }
            if ($binary === '') {
                throw new \RuntimeException('verifica_pdf_empty');
            }

            $doc = $this->svc->attachPdf($teacherId, $id, $binary, $filename);
            return Response::json(['ok' => true, 'doc' => $this->publicView($doc)], 200);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->statusFor($e));
        }
    }


    /**
     * G22.S10 — GET /api/verifica/{id}/tex-files
     *
     * Lista dei file (`{path, content, size}`) della manifest multi-file.
     * Usata dal preview modal per il file-tree sidebar.
     *
     * Fallback: se la verifica è legacy single-blob, ritorna 1 solo entry
     * `{path: "main.tex", content: <flat>}` per UX uniforme client-side.
     *
     * Risposta: `{ ok:true, files:[{path,content,size}] }`.
     */
    public function getTexFiles(Request $req, array $params): Response
    {
        try {
            $teacherId = $this->teacherId();
            $id = (int)($params['id'] ?? 0);
            $files = $this->svc->readManifestFiles($teacherId, $id);
            if (!$files) {
                // Legacy single-blob fallback: presenta come main.tex unico.
                $flat = $this->svc->readTex($teacherId, $id);
                $files = [['path' => 'main.tex', 'content' => $flat, 'missing' => false]];
            }
            $out = [];
            foreach ($files as $f) {
                $path = (string)$f['path'];
                $content = (string)$f['content'];
                // G22.S15.bis Fase 4 — file binari (es. PDF da
                // attachGeoGebraPdf) NON sono UTF-8: includere il content
                // raw nel JSON farebbe fallire json_encode (response body
                // vuoto). Detect via extension + UTF-8 validity, e per
                // binari emettiamo solo metadata (size, is_binary=true).
                $isBinary = preg_match('/\.(pdf|png|jpe?g|gif|svg)$/i', $path)
                          || !mb_check_encoding($content, 'UTF-8');
                $entry = [
                    'path'    => $path,
                    'size'    => strlen($content),
                    'missing' => (bool)($f['missing'] ?? false),
                    'overrideStatus' => ($f['missing'] ?? false) ? 'missing' : 'common',
                ];
                if ($isBinary) {
                    $entry['content']   = '';      // placeholder
                    $entry['is_binary'] = true;
                } else {
                    $entry['content']   = $content;
                    $entry['is_binary'] = false;
                }
                $out[] = $entry;
            }
            return Response::json(['ok' => true, 'files' => $out], 200);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->statusFor($e));
        }
    }

    /**
     * G22.S10 — POST /api/verifica/{id}/tex-files
     *
     * Salva batch di file della manifest multi-file. Body JSON:
     *   { "files": [{"path": "main.tex", "content": "..."}, ...] }
     *
     * Vincoli:
     *  - almeno 1 file con path === "main.tex"
     *  - path safe-chars only (no ../, no absolute)
     *  - dimensione totale < MAX_TEX_BYTES
     *
     * Riusa blob esistenti se sha256 di un file path è invariato (no churn IO).
     * Aggiorna tex_sha256 sul flat assemblato per cache PDF coherence.
     */
    public function updateTexFiles(Request $req, array $params): Response
    {
        try {
            $teacherId = $this->teacherId();
            $id = (int)($params['id'] ?? 0);

            $raw = (string)file_get_contents('php://input');
            if ($raw === '') {
                return Response::json(['ok' => false, 'error' => 'empty_payload'], 400);
            }
            $body = json_decode($raw, true);
            if (!is_array($body) || !isset($body['files']) || !is_array($body['files'])) {
                return Response::json(['ok' => false, 'error' => 'invalid_payload'], 400);
            }
            $doc = $this->svc->updateTexFiles($teacherId, $id, $body['files']);
            return Response::json([
                'ok' => true,
                'doc' => $this->publicView($doc),
                'synced_siblings' => (int)($doc['_synced_siblings'] ?? 0),
            ], 200);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->statusFor($e));
        }
    }

    /**
     * G21.1 — POST /api/verifica/{id}/tex
     *
     * Aggiorna SOLO il sorgente .tex di una verifica (senza ricompilare).
     * Usato dal preview modal per "Salva senza ricompilare".
     *
     * Body JSON: { "tex": "..." }
     */
    public function updateTex(Request $req, array $params): Response
    {
        try {
            $teacherId = $this->teacherId();
            $id = (int)($params['id'] ?? 0);

            $raw = (string)file_get_contents('php://input');
            if ($raw === '') {
                return Response::json(['ok' => false, 'error' => 'empty_payload'], 400);
            }
            $body = json_decode($raw, true);
            if (!is_array($body) || !isset($body['tex'])) {
                return Response::json(['ok' => false, 'error' => 'invalid_payload'], 400);
            }
            $tex = (string)$body['tex'];
            if (strlen($tex) > 5 * 1024 * 1024) {
                return Response::json(['ok' => false, 'error' => 'tex_too_large'], 413);
            }
            $doc = $this->svc->updateTex($teacherId, $id, $tex);
            return Response::json(['ok' => true, 'doc' => $this->publicView($doc)], 200);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->statusFor($e));
        }
    }

    /**
     * G22.S15.bis Fase 4 — POST /api/verifica/{id}/geogebra-attach
     *
     * Riceve uno SVG generato dall'editor GeoGebra, lo converte in PDF
     * vettoriale via VPS rsvg-convert, lo aggiunge al bundle multi-file
     * della verifica come `geogebra/N.pdf` (find next free N), aggiorna
     * il manifest. Ritorna `{path: "geogebra/N", index: N}` per inserimento
     * `\includegraphics{geogebra/N}` nel CodeMirror.
     *
     * Body JSON: { "svg_b64": "...", "label": "Funz. exp" }
     */
    public function geogebraAttach(Request $req, array $params): Response
    {
        try {
            $teacherId = $this->teacherId();
            $id = (int)($params['id'] ?? 0);

            $raw = (string)file_get_contents('php://input');
            if ($raw === '') {
                return Response::json(['ok' => false, 'error' => 'empty_payload'], 400);
            }
            $body = json_decode($raw, true);
            if (!is_array($body) || !isset($body['svg_b64'])) {
                return Response::json(['ok' => false, 'error' => 'invalid_payload'], 400);
            }
            $svgB64 = (string)$body['svg_b64'];
            $label  = (string)($body['label'] ?? '');
            if ($svgB64 === '') {
                return Response::json(['ok' => false, 'error' => 'svg_missing'], 400);
            }
            if (strlen($svgB64) > 4 * 1024 * 1024) {
                return Response::json(['ok' => false, 'error' => 'svg_too_large'], 413);
            }

            $r = $this->svc->attachGeoGebraPdf($teacherId, $id, $svgB64, $label);
            return Response::json(['ok' => true, 'success' => true] + $r, 200);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->statusFor($e));
        }
    }

    /**
     * GET /api/verifica/{id}/pdf — stream PDF inline (per iframe/preview).
     * Owner only.
     */
    public function viewPdf(Request $req, array $params): Response
    {
        try {
            $teacherId = $this->teacherId();
            $id = (int)($params['id'] ?? 0);
            $pdf = $this->svc->readPdf($teacherId, $id);
            // Inline display: iframe/preview-friendly. Filename in download button.
            $disposition = (string)($req->query['download'] ?? '') === '1' ? 'attachment' : 'inline';
            return new Response(
                body: $pdf['binary'],
                status: 200,
                headers: [
                    'Content-Type'        => $pdf['mime'],
                    'Content-Disposition' => $disposition . '; filename="' . $pdf['filename'] . '"',
                    'Cache-Control'       => 'private, no-store',
                    'X-Content-Type-Options' => 'nosniff',
                ],
            );
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->statusFor($e));
        }
    }

    // ─────── helpers (G22.S15.bis Fase 5+: shared helpers via Trait) ───────


    /**
     * Estrae exercise_ids dal payload. Priorita':
     *   1. payload.exercise_ids (esplicito)
     *   2. parsing problemId numerici (problem-12 → 12)
     */
    private function extractExerciseIds(array $payload, Selection $sel): array
    {
        if (\is_array($payload['exercise_ids'] ?? null)) {
            return array_values(array_filter(array_map('intval', $payload['exercise_ids']), fn($n) => $n > 0));
        }
        $ids = [];
        foreach ($sel->problems as $p) {
            $pid = (string)($p['problemId'] ?? '');
            if (preg_match('/(\d+)/', $pid, $m)) {
                $n = (int)$m[1];
                if ($n > 0) {
                    $ids[] = $n;
                }
            }
        }
        return array_values(array_unique($ids));
    }

    private function safeFilename(int $id, string $ext): string
    {
        // G19 — se il doc ha variant batch, usa il nome legacy-compatible.
        if ($ext === 'tex') {
            try {
                $teacherId = $this->teacherId();
                $doc = $this->svc->find($teacherId, $id);
                if ($doc && !empty($doc['variant'])) {
                    return self::buildBatchFilename($doc, (string)$doc['variant']);
                }
            } catch (Throwable) {
/* fallback al naming generico */
            }
        }
        return 'verifica_' . $id . '.' . $ext;
    }
}
