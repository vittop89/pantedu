<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\TeacherContentRepository;
use Throwable;

/**
 * ContentExportController — area export / compile dei contenuti docente,
 * estratta da TeacherContentController (ADR-029). Endpoint sotto
 * /api/teacher/... (export, texFiles, compilePdf, saveTexFiles, exportHtml,
 * provenance, contract, manifest) — vedi routes/web.php, docs/ROUTES.md,
 * docs/glossary/TeacherContentController.md. Gli helper teacherId/dbReady/
 * findOwnedRow/contentVisibilityPolicy/viewerContext sono duplicati dal
 * controller originale (condivisi, copia volutamente isolata).
 */
final class ContentExportController
{
    private TeacherContentRepository $repo;

    public function __construct(?TeacherContentRepository $repo = null)
    {
        $this->repo = $repo ?? new TeacherContentRepository();
    }

    /**
     * Phase 24.36 — POST /api/teacher/content/{id}/export
     * Genera ZIP TeX (main.tex + doc.tex + risdoc.sty + intestazione + images)
     * dal metadata.body_pt PT AST. Riusa risdoc.sty texCommon comune.
     * mode=zip → URL download. mode=overleaf → overleaf snip URL.
     */
    public function export(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $id  = (int)($params['id'] ?? 0);
        if (!$id) {
            return Response::json(['error' => 'invalid_id'], 400);
        }

        $row = $this->repo->find($id);
        if (!$row) {
            return Response::json(['error' => 'not_found'], 404);
        }
        // ACL: owner o pool condiviso o super-admin
        if (!$this->contentVisibilityPolicy()->canExportOwn((int)$row['teacher_id'], $this->viewerContext($tid), \App\Services\AclPolicy::isSuperAdmin())) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        $b = $this->buildTexBundle($row, $tid);
        if (!$b) {
            return Response::json(['error' => 'no_body_pt', 'detail' => 'Content has no PT body to export'], 400);
        }
        $docName   = $b['docName'];
        $docBody   = $b['docBody'];
        $mainFinal = $b['mainFinal'];
        $styBody   = $b['styBody'];
        $headBody  = $b['headBody'];

        $root = dirname(__DIR__, 2);
        $pubDir = $root . '/storage/risdoc-tmp';
        if (!is_dir($pubDir) && !@mkdir($pubDir, 0775, true) && !is_dir($pubDir)) {
            return Response::json(['error' => 'storage_unavailable'], 500);
        }
        $name = 'content-' . $id . '-' . bin2hex(random_bytes(6)) . '.zip';
        $zipPath = $pubDir . DIRECTORY_SEPARATOR . $name;
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return Response::json(['error' => 'zip_open_failed'], 500);
        }
        $zip->addFromString('main.tex', $mainFinal);
        $zip->addFromString($docName, $docBody);
        if ($styBody !== '') {
            $zip->addFromString('texCommon/risdoc.sty', $styBody);
        }
        if ($headBody !== '') {
            $zip->addFromString('texCommon/intestaLAteX_IIS.tex', $headBody);
        }
        $imgDir = $root . '/storage/templates/risdoc/images';
        if (is_dir($imgDir)) {
            foreach (glob($imgDir . '/*') ?: [] as $img) {
                if (is_file($img)) {
                    $zip->addFile($img, 'images/' . basename($img));
                }
            }
        }
        $zip->close();

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $url = "{$scheme}://{$host}/api/risdoc/exports/" . $name;
        $mode = (string)($req->post['mode'] ?? 'zip');
        if ($mode === 'overleaf') {
            return Response::json(['ok' => true, 'mode' => 'overleaf', 'url' => $url,
                'overleaf_url' => 'https://www.overleaf.com/docs?snip_uri=' . rawurlencode($url)]);
        }
        return Response::json(['ok' => true, 'mode' => 'zip', 'url' => $url]);
    }

    /**
     * ADR-024 — costruisce i 4 file TeX del documento custom dal body_pt
     * (riuso condiviso tra ZIP export, modal tex-files e compile-pdf).
     *
     * I file texCommon (main.tex / risdoc.sty / intestaLAteX_IIS.tex) sono
     * risolti come in risdoc: override condiviso del DOCENTE in DB
     * (OverrideRepository, template_id=0, kind=texCommon) → fallback al file
     * default `storage/templates/risdoc/texCommon/`. Senza questo i texCommon
     * restano vuoti (il file globale non è popolato) → main.tex/sty vuoti →
     * PDF non compilabile.
     *
     * @return array{docName:string,mainFinal:string,docBody:string,styBody:string,headBody:string}|null
     *         null se il documento non ha body_pt.
     */
    private function buildTexBundle(array $row, int $tid): ?array
    {
        $meta = is_string($row['metadata_json'] ?? null) && $row['metadata_json'] !== ''
            ? json_decode($row['metadata_json'], true) : null;
        $bodyPt = is_array($meta['body_pt'] ?? null) ? $meta['body_pt'] : null;
        if (!$bodyPt) {
            return null;
        }
        $context = [
            'fields' => [],
            'state'  => [
                'classe'     => (string)($row['classe']       ?? ''),
                'indirizzo'  => (string)($row['indirizzo']    ?? ''),
                'disciplina' => (string)($row['subject_code'] ?? ''),
                'sezione'    => '',
            ],
        ];
        $title = (string)($row['title'] ?? 'Documento');
        $docBody = '\\section*{' . $this->escTexShort($title) . '}' . "\n\n"
                 . \App\Services\Risdoc\Pt\PtToTex::render($bodyPt, $context);
        $root = dirname(__DIR__, 2);
        $repo = new \App\Services\Risdoc\OverrideRepository();
        // override condiviso docente (template_id=0) → file default.
        $loadTexCommon = static function (string $rel) use ($root, $repo, $tid): string {
            if ($tid > 0) {
                $ov = $repo->find($tid, 0, 'texCommon', $rel);
                if ($ov && isset($ov['body']) && (string)$ov['body'] !== '') {
                    return (string)$ov['body'];
                }
            }
            $abs = $root . '/storage/templates/risdoc/texCommon/' . $rel;
            return is_file($abs) ? (string)file_get_contents($abs) : '';
        };
        $docName = (preg_replace('/[^\w.\-]/', '_', $title) ?: 'documento') . '.tex';
        // Toggle intestazione istituto (metadata.includeHeader, default true).
        $mainFinal = str_replace('%[filetex]', '\\input{' . $docName . '}', $loadTexCommon('main.tex'));
        $includeHeader = !is_array($meta) || !array_key_exists('includeHeader', $meta)
            || filter_var($meta['includeHeader'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
        if (!$includeHeader) {
            $mainFinal = preg_replace(
                '/^[ \t]*\\\\input\{texCommon\/intestaLAteX_IIS(?:\.tex)?\}.*$/m',
                '% [intestazione istituto disattivata dal docente]',
                $mainFinal
            ) ?? $mainFinal;
        }
        return [
            'docName'   => $docName,
            'mainFinal' => $mainFinal,
            'docBody'   => $docBody,
            'styBody'   => $loadTexCommon('risdoc.sty'),
            'headBody'  => $loadTexCommon('intestaLAteX_IIS.tex'),
        ];
    }

    /**
     * ADR-024 — POST /api/teacher/content/{id}/tex-files
     * Lista i file TeX del documento custom per il modal preview (stesso shape
     * di risdoc TexFilesController::getFiles). editable: main.tex/sty/header;
     * il corpo (documento.tex, da body_pt) è read-only (si edita via le card).
     */
    public function texFiles(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $id = (int)($params['id'] ?? 0);
        if (!$id) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        [$row, $err] = $this->findOwnedRow($id, $tid);
        if ($err) {
            return $err;
        }
        $b = $this->buildTexBundle($row, $tid);
        if (!$b) {
            return Response::json(['ok' => false, 'error' => 'no_body_pt'], 400);
        }
        $defs = [
            ['main.tex',                       $b['mainFinal'], true],
            [$b['docName'],                    $b['docBody'],   false],
            ['texCommon/risdoc.sty',           $b['styBody'],   true],
            ['texCommon/intestaLAteX_IIS.tex', $b['headBody'],  true],
        ];
        $files = [];
        foreach ($defs as [$path, $content, $editable]) {
            if ($content === '') {
                continue; // sty/header eventualmente assenti → skip
            }
            $files[] = [
                'path'           => $path,
                'content'        => (string)$content,
                'size'           => strlen((string)$content),
                'editable'       => (bool)$editable,
                'overrideStatus' => 'common',
                'missing'        => false,
            ];
        }
        return Response::json(['ok' => true, 'files' => $files, 'doc_name' => $b['docName']]);
    }

    /**
     * ADR-024 — POST /api/teacher/content/{id}/compile-pdf
     * Compila il bundle TeX → PDF (via TexCompileClient/VPS) applicando gli
     * edit live del modal (body.files override). Risposta = PDF binario inline.
     */
    public function compilePdf(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $id = (int)($params['id'] ?? 0);
        if (!$id) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        [$row, $err] = $this->findOwnedRow($id, $tid);
        if ($err) {
            return $err;
        }
        $b = $this->buildTexBundle($row, $tid);
        if (!$b) {
            return Response::json(['ok' => false, 'error' => 'no_body_pt'], 400);
        }

        $raw = (string)file_get_contents('php://input');
        $reqBody = json_decode($raw, true) ?: [];
        $clientFiles = is_array($reqBody['files'] ?? null) ? (array)$reqBody['files'] : [];

        $bundle = [
            'main.tex'                       => $b['mainFinal'],
            $b['docName']                    => $b['docBody'],
            'texCommon/risdoc.sty'           => $b['styBody'],
            'texCommon/intestaLAteX_IIS.tex' => $b['headBody'],
        ];
        // Immagini (loghi istituto referenziati da intestaLAteX_IIS via
        // \includegraphics{images/...}). compileBundle fa base64 → binari ok.
        $imgDir = dirname(__DIR__, 2) . '/storage/templates/risdoc/images';
        if (is_dir($imgDir)) {
            foreach (glob($imgDir . '/*') ?: [] as $img) {
                if (is_file($img)) {
                    $bundle['images/' . basename($img)] = (string)file_get_contents($img);
                }
            }
        }
        foreach ($clientFiles as $f) {
            if (is_array($f) && isset($f['path'], $f['content'])) {
                // Audit 25.R.31 — path dal client come chiave bundle: normalizza
                // per evitare traversal (../, path assoluti) nel sandbox di compile.
                $p = str_replace('\\', '/', (string)$f['path']);
                $p = ltrim($p, '/');
                if ($p === '' || str_contains($p, '../') || str_contains($p, '..\\')) {
                    continue; // scarta path sospetti
                }
                $bundle[$p] = (string)$f['content'];
            }
        }

        $client = \App\Services\TexCompile\TexCompileClient::tryDefault(60);
        if (!$client) {
            return Response::json(['ok' => false, 'error' => 'tex_compile_disabled'], 503);
        }
        $files = [];
        foreach ($bundle as $p => $c) {
            $files[] = ['path' => $p, 'content' => (string)$c];
        }
        // GeoGebra: \fmgeogebra{base64}{label} → \includegraphics + PDF nel bundle.
        $files = \App\Controllers\Risdoc\TexFilesController::applyGeogebraPreprocess($files, 'content-' . $id);
        try {
            $result = $client->compileBundle(
                files:    $files,
                mainPath: 'main.tex',
                docId:    'content-' . $id . '-' . substr(md5(json_encode($bundle) ?: ''), 0, 10),
                engine:   (string)Config::get('tex_compile.default_engine', 'pdflatex'),
                passes:   (int)Config::get('tex_compile.default_passes', 2),
            );
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'error' => 'compile_failed'], 500);
        }
        if (!($result['ok'] ?? false)) {
            return Response::json([
                'ok'          => false,
                'error'       => 'tex_compile_failed',
                'log_excerpt' => substr((string)($result['log'] ?? ''), 0, 4000),
                'duration_ms' => $result['duration_ms'] ?? null,
            ], 422);
        }
        return new Response(
            body: (string)$result['pdf'],
            status: 200,
            headers: [
                'Content-Type'           => 'application/pdf',
                'Cache-Control'          => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'X-Compile-Duration-Ms'  => (string)($result['duration_ms'] ?? ''),
            ],
        );
    }

    /**
     * ADR-024 — POST /api/teacher/content/{id}/tex-files/save
     * Gli edit TeX (main.tex/risdoc.sty/intestazione) del documento custom sono
     * EFFIMERI: restano nei buffer del modal e vengono inviati a /compile-pdf
     * come override (preview/PDF li riflettono nella sessione). Non persistiti
     * per-documento (i file texCommon sono condivisi). Risponde ok.
     */
    public function saveTexFiles(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $id = (int)($params['id'] ?? 0);
        if (!$id) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        [$row, $err] = $this->findOwnedRow($id, $tid);
        if ($err) {
            return $err;
        }
        $raw = (string)file_get_contents('php://input');
        $body = json_decode($raw, true);
        $n = is_array($body['files'] ?? null) ? count($body['files']) : 0;
        return Response::json(['ok' => true, 'saved' => $n, 'ephemeral' => true]);
    }

    private function escTexShort(string $s): string
    {
        return strtr($s, ['\\' => '\\textbackslash{}', '&' => '\\&', '%' => '\\%',
                          '$' => '\\$', '#' => '\\#', '_' => '\\_',
                          '{' => '\\{', '}' => '\\}']);
    }

    /**
     * G23 Sprint 11 — GET /api/teacher/content/{id}/export-html
     * Esporta il body_pt come pagina HTML standalone pulita e sanitizzata
     * (stile FismaPant): <!doctype html> + CSS page-doc inline + render
     * PtToHtml (già sanitizzato per staticContent via HtmlSanitizer::forPageDoc).
     * Download diretto (Content-Disposition attachment).
     */
    public function exportHtml(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $id = (int)($params['id'] ?? 0);
        if (!$id) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        $row = $this->repo->find($id);
        if (!$row) {
            return Response::json(['error' => 'not_found'], 404);
        }
        if (!$this->contentVisibilityPolicy()->canExportOwn((int)$row['teacher_id'], $this->viewerContext($tid), \App\Services\AclPolicy::isSuperAdmin())) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        $meta = is_string($row['metadata_json'] ?? null) && $row['metadata_json'] !== ''
            ? json_decode($row['metadata_json'], true) : null;
        $bodyPt = is_array($meta['body_pt'] ?? null) ? $meta['body_pt'] : null;
        if (!$bodyPt) {
            return Response::json(['error' => 'no_body_pt'], 400);
        }

        $title = (string)($row['title'] ?? 'Documento');
        $bodyHtml = \App\Services\Risdoc\Pt\PtToHtml::render($bodyPt, [
            'fields' => [],
            'state'  => [
                'classe'     => (string)($row['classe']       ?? ''),
                'indirizzo'  => (string)($row['indirizzo']    ?? ''),
                'disciplina' => (string)($row['subject_code'] ?? ''),
                'sezione'    => '',
            ],
        ]);

        // CSS page-doc inline (legge il modulo _pt-page-doc.css per render
        // standalone fedele alla vista web). Fallback minimal se non leggibile.
        $root = dirname(__DIR__, 2);
        $cssFile = $root . '/css/modules/_pt-page-doc.css';
        $css = is_file($cssFile) ? (string)file_get_contents($cssFile) : '';

        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = "<!doctype html>\n<html lang=\"it\">\n<head>\n"
              . "<meta charset=\"UTF-8\">\n"
              . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n"
              . "<title>" . $esc($title) . "</title>\n"
              . "<style>\n"
              . "body{font-family:'Segoe UI',system-ui,sans-serif;line-height:1.6;max-width:900px;margin:30px auto;padding:0 20px;color:#1e293b}\n"
              . "h1{color:#005A8D;border-bottom:2px solid #7AB8D4;padding-bottom:.3em}\n"
              . $css . "\n"
              . "</style>\n</head>\n<body>\n"
              . "<h1>" . $esc($title) . "</h1>\n"
              . $bodyHtml
              . "\n</body>\n</html>\n";

        $fname = preg_replace('/[^\w.\-]/', '_', $title) ?: 'documento';
        return new Response($html, 200, [
            'Content-Type'        => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $fname . '.html"',
        ]);
    }

    /**
     * Phase 18 — GET /api/teacher/content/{id}/provenance
     * Risale ricorsivamente la catena source_content_id per audit clone.
     */
    public function provenance(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $id  = (int)($params['id'] ?? 0);
        if (!$id) {
            return Response::json(['error' => 'invalid_id'], 400);
        }

        $chain = [];
        $cur   = $id;
        $db    = \App\Core\Database::connection();
        $stmt  = $db->prepare('SELECT id, teacher_id, title, source_content_id FROM teacher_content WHERE id = ?');
        $seen  = [];
        for ($i = 0; $i < 20; $i++) {
            if (isset($seen[$cur])) {
                break;
            }
            $seen[$cur] = true;
            $stmt->execute([$cur]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                break;
            }
            $chain[] = $row;
            if (!$row['source_content_id']) {
                break;
            }
            $cur = (int)$row['source_content_id'];
        }
        return Response::json(['ok' => true, 'chain' => $chain]);
    }

    /**
     * Phase 15 — GET /api/teacher/content/{id}/contract
     *
     * Serve il JSON contract (schema pantedu.content.v1) del content
     * richiesto, letto dallo StorageProvider via storage_key salvato
     * in metadata_json.contract_key. ACL: solo owner può leggere.
     */
    public function contract(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            return Response::json(['error' => 'invalid_id'], 400);
        }

        // Phase 16 — letture centralizzate via ContractRepository. Distingue:
        //   - contratto non esistente o non owned dal teacher → 404
        //   - storage read failure o JSON malformato → 500
        $repo = \App\Services\Contract\ContractRepository::default();
        try {
            $agg = $repo->loadForTeacher($id, $tid);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'read_failed'], 500);
        }
        if (!$agg) {
            return Response::json(['error' => 'no_contract'], 404);
        }

        // Phase 19 — ETag conditional basato su version del contract.
        // Client include il valore in `If-Match` al prossimo save per
        // optimistic locking; se richiesta GET subsequent ha If-None-Match
        // === ETag, rispondiamo 304.
        $etag = '"v' . $agg->version() . '"';
        $clientTag = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($clientTag !== '' && $clientTag === $etag) {
            $r = new Response('', 304);
            $r->headers['ETag'] = $etag;
            $r->headers['Cache-Control'] = 'private, max-age=60, must-revalidate';
            return $r;
        }
        $json = json_encode($agg->data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $r = new Response((string)$json, 200);
        $r->headers['Content-Type']  = 'application/json; charset=UTF-8';
        $r->headers['Cache-Control'] = 'private, max-age=60, must-revalidate';
        $r->headers['ETag']          = $etag;
        return $r;
    }

    /**
     * Phase 15 — GET /api/teacher/manifest/{content_type}
     *
     * Serve il manifest JSON preconfezionato da storage/manifests/
     * con tutti i content del docente corrente raggruppati/ordinati
     * per subject/ind/classe/topic.
     */
    public function manifest(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }

        $type = (string)($params['type'] ?? '');
        if (!in_array($type, ['mappa', 'esercizio', 'verifica', 'document'], true)) {
            return Response::json(['error' => 'invalid_content_type'], 400);
        }
        $path = (string)Config::get('app.paths.storage')
              . '/manifests/teacher_' . $tid . '/' . $type . '.json';
        if (!is_file($path)) {
            return Response::json(['ok' => true, 'content_type' => $type, 'count' => 0, 'items' => []]);
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return Response::json(['error' => 'read_failed'], 500);
        }
        $r = new Response($raw, 200);
        $r->headers['Content-Type']  = 'application/json; charset=UTF-8';
        $r->headers['Cache-Control'] = 'private, max-age=60';
        return $r;
    }

    // ---- helper condivisi (copia da TeacherContentController, ADR-029) ----

    /** Trova la row + ACL (owner o super-admin). @return array{0:?array,1:?Response} */
    private function findOwnedRow(int $id, int $tid): array
    {
        $row = $this->repo->find($id);
        if (!$row) {
            return [null, Response::json(['error' => 'not_found'], 404)];
        }
        // Pilota #1 — gate unico: stesso owner-OR-superadmin di canExportOwn
        // (siti 322/662). Prima era una 4a copia inline dello stesso predicato.
        $ctx = new \App\Domain\ViewerContext(
            role: \App\Domain\Role::tryFromString((string)\App\Core\Auth::role()),
            teacherId: $tid,
        );
        if (
            !(new \App\Domain\ContentVisibilityPolicy())->canExportOwn(
                (int)$row['teacher_id'],
                $ctx,
                \App\Services\AclPolicy::isSuperAdmin()
            )
        ) {
            return [null, Response::json(['error' => 'forbidden'], 403)];
        }
        return [$row, null];
    }

    private function teacherId(): int
    {
        $u = Auth::user();
        if (!$u) {
            return 0;
        }
        return \App\Support\TeacherContextResolver::userIdFromUsername((string)($u['username'] ?? ''));
    }

    private function dbReady(): bool
    {
        return (bool)Config::get('database.enabled') && Database::isAvailable();
    }

    /**
     * Pilota #1 — gate UNICO ownership/export.
     * Le rotte di questo controller sono teacher+ (middleware): il viewer è
     * sempre un docente con user_id risolto. Costruisce il ViewerContext
     * esplicito che la policy consuma (nessun SESSION/DB nel core).
     */
    private function contentVisibilityPolicy(): \App\Domain\ContentVisibilityPolicy
    {
        return new \App\Domain\ContentVisibilityPolicy();
    }

    private function viewerContext(int $teacherId): \App\Domain\ViewerContext
    {
        return \App\Domain\ViewerContext::forTeacher($teacherId, $this->firstInstituteId($teacherId));
    }

    private function firstInstituteId(int $teacherId): int
    {
        return \App\Support\TeacherContextResolver::firstInstituteId($teacherId);
    }
}
