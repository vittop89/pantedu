<?php

declare(strict_types=1);

namespace App\Controllers\Risdoc;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\Risdoc\InstitutionalOverrideRepository;
use App\Services\Risdoc\OverrideRepository;
use App\Services\Risdoc\Permission;
use App\Services\Risdoc\TemplateResolver;

/**
 * REST API per risdoc per-teacher templates (Phase 21).
 *
 *   GET    /api/risdoc/templates                    → lista visibili per teacher
 *   GET    /api/risdoc/templates/{id}               → metadata + logic_spec
 *   GET    /api/risdoc/templates/{id}/file          → body risolto (query: kind, path)
 *   GET    /api/risdoc/templates/{id}/overrides     → lista override del teacher
 *   POST   /api/risdoc/templates/{id}/override      → salva override (body: kind, path, content)
 *   POST   /api/risdoc/templates/{id}/override/del  → delete override
 *
 * Tutte le route protette da middleware auth teacher+. canEdit check su save/delete.
 */
final class TemplateController
{
    public function __construct(
        private TemplateResolver $resolver = new TemplateResolver(),
        private OverrideRepository $overrides = new OverrideRepository(), // Phase 24.55 — institutional layer (admin-edited via UI)
        private InstitutionalOverrideRepository $institutional = new InstitutionalOverrideRepository()
    ) {
    }

    public function index(Request $req): Response
    {
        $tid = Permission::currentTeacherId();
        if ($tid === 0 && !Permission::isSuperAdmin()) {
            return Response::json(['error' => 'unauthorized'], 401);
        }

        // Phase 24.58 — colonna `origin` rimossa: si filtra solo per category.
        $category   = $req->query['category'] ?? null;
// Phase 24.50 — opt-in body_pt per template picker UI.
        $withBodyPt = !empty($req->query['with_body_pt']);
        $rows = Permission::isSuperAdmin()
            ? $this->resolver->listAll($category, $withBodyPt)
            : $this->resolver->listForTeacher($tid, $category, $withBodyPt);
// Decode body_pt JSON in array (i client lo consumano come array).
        if ($withBodyPt) {
            foreach ($rows as &$r) {
                if (!empty($r['body_pt']) && is_string($r['body_pt'])) {
                    $decoded = json_decode($r['body_pt'], true);
                    $r['body_pt'] = is_array($decoded) ? $decoded : null;
                } else {
                    $r['body_pt'] = null;
                }
            }
            unset($r);
        }

        return Response::json(['ok' => true, 'count' => count($rows), 'templates' => $rows]);
    }

    /**
     * Phase 24.50 — POST /api/risdoc/templates/{id}/body-pt (super-admin only).
     * Salva il PT AST seed che i teacher possono copiare nel proprio
     * teacher_content via modal "Stile esercizi → Parti da template".
     *
     * Body: form-encoded `body_pt=<JSON>` (array PT) o `body_pt=` per pulire.
     */
    public function saveBodyPt(Request $req, array $params): Response
    {
        if (!Permission::isSuperAdmin()) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $id = (int)($params['id'] ?? 0);
        if ($id === 0) {
            return Response::json(['error' => 'invalid_id'], 400);
        }

        $tmpl = $this->resolver->findTemplate($id);
        if (!$tmpl) {
            return Response::json(['error' => 'not_found'], 404);
        }

        $raw = $req->post['body_pt'] ?? '';
        $bodyPt = null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return Response::json(['error' => 'invalid_body_pt'], 400);
            }
            $bodyPt = $decoded;
        } elseif (is_array($raw)) {
            $bodyPt = $raw;
        }

        $this->resolver->saveBodyPt($id, $bodyPt);
        return Response::json(['ok' => true, 'id' => $id, 'cleared' => $bodyPt === null]);
    }

    public function show(Request $req, array $params): Response
    {
        $tid = Permission::currentTeacherId();
        $id  = (int)($params['id'] ?? 0);
        if ($id === 0) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        if (!Permission::canView($id, $tid)) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        $tmpl = $this->resolver->findTemplate($id);
        if (!$tmpl) {
            return Response::json(['error' => 'not_found'], 404);
        }

        return Response::json([
            'ok'       => true,
            'template' => $tmpl,
            'role'     => $this->role($id, $tid),
        ]);
    }

    /**
     * GET /api/risdoc/templates/{id}/schema
     * Serve il contenuto del template JSON schema (Plan A/B).
     */
    public function schema(Request $req, array $params): Response
    {
        $tid = Permission::currentTeacherId();
        $id  = (int)($params['id'] ?? 0);
        if (!Permission::canView($id, $tid)) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        $tmpl = $this->resolver->findTemplate($id);
        if (!$tmpl || empty($tmpl['schema_path'])) {
            return Response::json(['error' => 'schema_not_set'], 404);
        }
        $schemaPath = (string)$tmpl['schema_path'];
// Phase 24.56 — resolver 3-layer (teacher → institutional → file).
        // Permette ad admin di modificare lo schema via UI: l'institutional
        // override sovrascrive il file su disco per tutti i docenti.
        $body = null;
        $resolved = $this->resolver->resolveFile($tid, $id, 'schema', $schemaPath);
        // NB: trattare un body risolto VUOTO come "assente" → fallback al file.
        // Un override istituzionale vuoto (o un file svuotato a monte) non deve
        // restituire 200 con corpo vuoto: il client fa .json() e crasha con
        // "Unexpected end of JSON input". Meglio servire il file committato o,
        // se anch'esso vuoto/assente, un 404 esplicito.
        if (
            $resolved && isset($resolved['body']) && $resolved['body'] !== null
            && \trim((string)$resolved['body']) !== ''
        ) {
            $body = $resolved['body'];
        } else {
            // Fallback: legge file direttamente (kind=schema relativo a root).
            $abs = \dirname(__DIR__, 3) . '/' . \ltrim($schemaPath, '/');
            $fileBody = \is_file($abs) ? (string)\file_get_contents($abs) : '';
            if (\trim($fileBody) === '') {
                return Response::json([
                    'error'  => 'schema_empty_or_missing',
                    'detail' => 'Schema vuoto/assente sul server: ' . $schemaPath
                              . ' — ripristinare il file (git checkout) o l\'override.',
                ], 404);
            }
            $body = $fileBody;
        }

        $r = new Response((string)$body, 200);
        $r->headers['Content-Type']  = 'application/json';
// Phase 24.56 — no cache: invalidato istantaneamente dopo edit admin.
        $r->headers['Cache-Control'] = 'private, no-cache';
        return $r;
    }

    /**
     * GET /api/risdoc/templates/{id}/tex?compilation_id=N
     * Genera il TeX di un template da schema JSON + compilation data (Plan A.3).
     * Se compilation_id non fornito, usa l'ultima compilation del docente.
     */
    public function tex(Request $req, array $params): Response
    {
        $tid = Permission::currentTeacherId();
        $id  = (int)($params['id'] ?? 0);
        if (!Permission::canView($id, $tid)) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        $tmpl = $this->resolver->findTemplate($id);
        if (!$tmpl) {
            return Response::json(['error' => 'not_found'], 404);
        }
        $schemaPath = (string)($tmpl['schema_path'] ?? '');
        if ($schemaPath === '') {
            return Response::json(['error' => 'schema_not_set'], 404);
        }
        $abs = dirname(__DIR__, 3) . '/' . ltrim($schemaPath, '/');
        if (!is_file($abs)) {
            return Response::json(['error' => 'schema_missing'], 404);
        }

        // Recupera compilation_id (query) o ultima del docente
        $cid = (int)($req->query['compilation_id'] ?? 0);
        $data = ['fields' => [], 'state' => []];
        if ($cid > 0) {
            $c = (new \App\Services\Risdoc\CompilationRepository())->find($tid, $cid);
            if ($c) {
                $data = json_decode((string)$c['data_json'], true) ?: $data;
            }
        } else {
            $stmt = Database::connection()->prepare('SELECT data_json FROM risdoc_compilations WHERE teacher_id=? AND template_id=? ORDER BY updated_at DESC LIMIT 1');
            $stmt->execute([$tid, $id]);
            $raw = (string)$stmt->fetchColumn();
            if ($raw !== '') {
                $data = json_decode($raw, true) ?: $data;
            }
        }

        // Se il template ha un body_pt (PT AST seed, ADR-026), il CORPO TeX si
        // rende da quello via PtToTex — è la fonte autoritativa del contenuto
        // (stesso path del fork teacher_content). Le schema.sections sono solo
        // metadata/struttura: per i doc seedati (es. verifiche/glossario) non
        // contengono il corpo, quindi il TexBuilder schema-driven darebbe vuoto.
        $bodyPtRaw = $tmpl['body_pt'] ?? null;
        $bodyPt = \is_string($bodyPtRaw) && $bodyPtRaw !== ''
            ? json_decode($bodyPtRaw, true)
            : (\is_array($bodyPtRaw) ? $bodyPtRaw : null);
        if (\is_array($bodyPt) && $bodyPt !== [] && isset($bodyPt[0]['_type'])) {
            $ctx = ['fields' => (array)($data['fields'] ?? []), 'state' => (array)($data['state'] ?? [])];
            $tex = \App\Services\Risdoc\Pt\PtToTex::render($bodyPt, $ctx);
        } else {
            $tex = (new \App\Services\Risdoc\TexBuilder($abs))->build($data);
        }
        $r = new Response($tex, 200);
        $r->headers['Content-Type']  = 'text/plain; charset=UTF-8';
        $r->headers['Cache-Control'] = 'private, no-cache';
        return $r;
    }

    public function file(Request $req, array $params): Response
    {
        $tid = Permission::currentTeacherId();
        $id  = (int)($params['id'] ?? 0);
        if (!Permission::canView($id, $tid)) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        $kind = (string)($req->query['kind'] ?? 'html');
        $path = (string)($req->query['path'] ?? '');
// Phase 24.58 — instance_key (default '' = istanza base)
        $instanceKey = (string)($req->query['instance_key'] ?? '');
// Phase 24.28 — texCommon: bypass resolver, lookup diretto
        // storage/templates/risdoc/texCommon/{path} con override per-teacher.
        if ($kind === 'texCommon') {
            return $this->loadTexCommonFile($tid, $id, $path);
        }

        if ($kind === 'image') {
            // stream image — con resolver per-template + override per-teacher.
            $result   = $this->resolver->resolveFile($tid, $id, $kind, $path, $instanceKey);
            $absolute = null;
            if ($result) {
                $absolute = $result['absolute_path'] ?? null;
                if (($result['source'] ?? '') === 'override' && ($result['image_hash'] ?? null)) {
                    $absolute = dirname(__DIR__, 3) . '/storage/overrides/teacher_' . $tid . '/' . $result['image_hash'];
                }
            }
            // G27 — Fallback alle immagini istituzionali CONDIVISE (stemma_REP,
            // logo_scuola, ...) che vivono in storage/templates/risdoc/images/
            // (globali, NON per-template). Per una VERIFICA l'{id} è il doc id,
            // non un template → il resolver fallisce: serviamo la globale.
            // Path SANIFICATO (solo basename con estensione whitelist) → niente
            // traversal. Asset non sensibili (stemma nazionale, logo scuola).
            if (!$absolute || !is_file($absolute)) {
                $base = basename(str_replace('\\', '/', $path));
                if ($base !== '' && preg_match('/^[A-Za-z0-9._-]+\.(png|jpe?g|svg|gif|webp)$/', $base)) {
                    $global = dirname(__DIR__, 3) . '/storage/templates/risdoc/images/' . $base;
                    if (is_file($global)) {
                        $absolute = $global;
                    }
                }
            }
            if (!$absolute || !is_file($absolute)) {
                return Response::json(['error' => 'image_missing'], 404);
            }
            $mime = $this->guessMime($absolute);
            $bin = @file_get_contents($absolute);
            $r = new Response((string)$bin, 200);
            $r->headers['Content-Type']  = $mime;
            $r->headers['Cache-Control'] = 'private, max-age=300';
            return $r;
        }

        $result = $this->resolver->resolveFile($tid, $id, $kind, $path, $instanceKey);
        if (!$result) {
            return Response::json(['error' => 'file_not_found'], 404);
        }

        return Response::json([
            'ok'             => true,
            'kind'           => $kind,
            'path'           => $path,
            'body'           => $result['body'],
            'source'         => $result['source'],
            'source_version' => $result['source_version'],
        ]);
    }

    /**
     * GET /api/risdoc/templates/{id}/json-files
     * Lista tutti i path .json disponibili nella directory origin del template
     * (es. competenze_DM2007, programmi_svolti, obiettivi_disciplinari_*).
     * Risposta: { files: [{ path, size }] }
     */
    /**
     * GET /api/risdoc/templates/{id}/drift
     * Ritorna gli override del teacher corrente con source_version != current source_hash.
     */
    /**
     * GET /api/risdoc/shared/{file}
     * Serve gli asset globali risdoc (risdoc.js, risdoc.css, etc.) da
     * storage/templates/risdoc/ bypassando .htaccess hosting legacy. Whitelist
     * esplicita di estensioni sicure.
     */
    /**
     * GET /risdoc/{path*}  — catch-all che serve file da storage/templates/risdoc/
     * con override lookup, per compatibilità con path legacy usati da risdoc.js.
     *
     * Esempi risolti:
     *   /risdoc/risdoc.js                               → storage/templates/risdoc/risdoc.js
     *   /risdoc/risdoc.css
     *   /risdoc/texCommon/risdoc.sty
     *   /risdoc/MODELLI/tex/0.0_DOC-...-MODELLI.tex
     *   /risdoc/competenze_DM2007/competenze_DM2007.json
     *   /risdoc/images/logo_scuola.png
     *
     * Esclusi: path che coincidono con view/edit/api (registrati prima nel router).
     */
    public function legacyPath(Request $req, array $params): Response
    {
        $tid = Permission::currentTeacherId();
        if ($tid === 0 && !Permission::isSuperAdmin()) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $rel = (string)($params['path'] ?? '');
        if ($rel === '' || str_contains($rel, '..')) {
            return Response::json(['error' => 'invalid_path'], 400);
        }

        $root = dirname(__DIR__, 3);
        $abs  = $root . '/storage/templates/risdoc/' . str_replace(['\\'], '/', $rel);
// Se match override per-teacher (JSON o asset), cercalo
        if (preg_match('/\.json$/i', $rel)) {
            $tmplId = $this->findTemplateForJsonPath($rel);
            if ($tmplId !== null) {
                $ov = (new \App\Services\Risdoc\OverrideRepository())->find($tid, $tmplId, 'json', $rel);
                if ($ov && $ov['body'] !== null) {
                    return $this->mimeResponse($rel, (string)$ov['body']);
                }
            }
        }
        if (!is_file($abs)) {
            return Response::json(['error' => 'not_found'], 404);
        }
        return $this->mimeResponse($rel, (string)@file_get_contents($abs));
    }

    /**
     * GET /api/risdoc/options-sources — Phase 24.19
     * Catalogo dei JSON path e folder path disponibili sotto storage/templates/risdoc/
     * per popolare il selector nel popover del PT editor (modalità File/Folder).
     *
     * Response: {
     *   files:   [{path, label, size}, ...]  // .json files con path relativo
     *   folders: [{path, label}, ...]         // cartelle che contengono subdir state-based
     * }
     */
    public function optionsSources(Request $req): Response
    {
        $tid = Permission::currentTeacherId();
        if ($tid === 0 && !Permission::isSuperAdmin()) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $root = dirname(__DIR__, 3) . '/storage/templates/risdoc';
        $files = [];
        $folders = [];
// Scan ricorsivo, max depth 4, solo directory non-legacy
        $skip = ['images', 'texCommon', 'MODELLI', 'RISORSE'];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST,);
        $it->setMaxDepth(4);
        foreach ($it as $entry) {
            $rel = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
            $top = explode('/', $rel)[0];
            if (\in_array($top, $skip, true)) {
                continue;
            }
            if ($entry->isFile() && strtolower($entry->getExtension()) === 'json') {
                $files[] = [
                    'path'  => $rel,
                    'label' => $this->labelFromPath($rel),
                    'size'  => $entry->getSize(),
                ];
            }
            if ($entry->isDir()) {
        // Folder "state-based": contiene subdir tipo LSc/mat/xxx.json
                $glob = glob($entry->getPathname() . '/*/*/*.json');
                if (is_array($glob) && count($glob) > 0) {
                    $folders[] = [
                        'path'  => $rel,
                        'label' => $this->labelFromPath($rel),
                    ];
                }
            }
        }
        usort($files, fn($a, $b) => strcmp($a['path'], $b['path']));
        usort($folders, fn($a, $b) => strcmp($a['path'], $b['path']));
        return Response::json(['files' => $files, 'folders' => $folders]);
    }

    private function labelFromPath(string $rel): string
    {
        $parts = explode('/', $rel);
        $base = end($parts);
        $base = preg_replace('/\.json$/i', '', $base);
        $base = str_replace(['_', '-'], ' ', $base);
        return ucfirst(trim($base));
    }

    /**
     * Phase 24.28 — load texCommon file con override lookup.
     * Whitelist file: main.tex, risdoc.sty, intestaLAteX_IIS.tex.
     */
    private function loadTexCommonFile(int $tid, int $id, string $path): Response
    {
        $allowed = ['main.tex', 'risdoc.sty', 'intestaLAteX_IIS.tex'];
        if (!\in_array($path, $allowed, true)) {
            return Response::json(['error' => 'invalid_texcommon_path', 'allowed' => $allowed], 400);
        }
        $root = dirname(__DIR__, 3);
        $abs  = $root . '/storage/templates/risdoc/texCommon/' . $path;
        $base = is_file($abs) ? (string)file_get_contents($abs) : '';
        $repo = new \App\Services\Risdoc\OverrideRepository();
        $ov = $repo->find($tid, $id, 'texCommon', $path);
        $body = ($ov && $ov['body'] !== null) ? (string)$ov['body'] : $base;
        return Response::json([
            'ok'           => true,
            'kind'         => 'texCommon',
            'path'         => $path,
            'body'         => $body,
            'source'       => $ov ? 'override' : 'master',
            'has_override' => (bool)$ov,
            'allowed'      => $allowed,
        ]);
    }

    private function findTemplateForJsonPath(string $rel): ?int
    {
        // Match semplice: il primo template che ha questo path nei json_deps.
        // Se più template matchano, serve l'id esplicito (non disponibile qui).
        $stmt = \App\Core\Database::connection()->prepare("SELECT id FROM risdoc_templates WHERE JSON_CONTAINS(json_deps, JSON_QUOTE(?)) LIMIT 1");
        try {
            $stmt->execute([$rel]);
            $id = (int)$stmt->fetchColumn();
            return $id > 0 ? $id : null;
        } catch (\Throwable) {
                return null;
        }
    }

    private function mimeResponse(string $file, string $body): Response
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = [
            'js' => 'application/javascript', 'css' => 'text/css',
            'json' => 'application/json', 'tex' => 'text/plain',
            'sty' => 'text/plain', 'html' => 'text/html', 'htm' => 'text/html',
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif', 'svg' => 'image/svg+xml',
        ][$ext] ?? 'application/octet-stream';
        $r = new Response($body, 200);
        $r->headers['Content-Type']  = $mime . ($mime === 'image/png' ? '' : '; charset=UTF-8');
        $r->headers['Cache-Control'] = 'private, max-age=300';
        return $r;
    }

    public function sharedAsset(Request $req, array $params): Response
    {
        if (Permission::currentTeacherId() === 0 && !Permission::isSuperAdmin()) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $file = (string)($params['file'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9._-]+\.(js|css)$/', $file)) {
            return Response::json(['error' => 'invalid_file'], 400);
        }
        $root = dirname(__DIR__, 3);
        $abs  = $root . '/storage/templates/risdoc/' . $file;
        if (!is_file($abs)) {
            return Response::json(['error' => 'not_found'], 404);
        }
        $body = (string)@file_get_contents($abs);
        $mime = str_ends_with($file, '.js') ? 'application/javascript' : 'text/css';
        $r = new Response($body, 200);
        $r->headers['Content-Type']  = $mime . '; charset=UTF-8';
        $r->headers['Cache-Control'] = 'private, max-age=300';
        return $r;
    }

    public function driftStatus(Request $req, array $params): Response
    {
        $tid = Permission::currentTeacherId();
        $id  = (int)($params['id'] ?? 0);
        if (!Permission::canView($id, $tid)) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        $tmpl = $this->resolver->findTemplate($id);
        if (!$tmpl) {
            return Response::json(['error' => 'not_found'], 404);
        }

        $stmt = \App\Core\Database::connection()->prepare('SELECT kind, relative_path, source_version, updated_at
             FROM risdoc_teacher_overrides
             WHERE teacher_id=? AND template_id=? AND source_version != ?');
        $stmt->execute([$tid, $id, (string)$tmpl['source_hash']]);
        $drifted = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return Response::json([
            'ok' => true,
            'current_source_hash' => $tmpl['source_hash'],
            'drifted' => $drifted,
        ]);
    }

    public function jsonFiles(Request $req, array $params): Response
    {
        $tid = Permission::currentTeacherId();
        $id  = (int)($params['id'] ?? 0);
        if (!Permission::canEdit($id, $tid)) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        $tmpl = $this->resolver->findTemplate($id);
        if (!$tmpl) {
            return Response::json(['error' => 'template_not_found'], 404);
        }

        // Phase 24.58 — colonna `origin` rimossa: cartella asset unica risdoc.
        $originBase = dirname(__DIR__, 3) . '/storage/templates/risdoc';
        $files = [];
        if (is_dir($originBase)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($originBase, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!$f->isFile() || strtolower($f->getExtension()) !== 'json') {
                    continue;
                }
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($originBase) + 1));
                $files[] = ['path' => $rel, 'size' => $f->getSize()];
            }
            usort($files, fn($a, $b) => strcmp($a['path'], $b['path']));
        }
        return Response::json(['ok' => true, 'files' => $files]);
    }

    public function overridesList(Request $req, array $params): Response
    {
        $tid = Permission::currentTeacherId();
        $id  = (int)($params['id'] ?? 0);
        if (!Permission::canView($id, $tid)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $rows = $this->overrides->listByTeacher($tid, $id);
        // 2026-05-28 — Includi anche le immagini di SISTEMA condivise (default
        // risdoc/images/*) così l'admin manager mostra subito stemma Repubblica
        // e logo scuola anche se non sono mai stati overrideati. Sono read-only:
        // l'override per-teacher SOSTITUISCE quella di sistema con stesso path
        // (es. caricare images/logo_scuola.png personalizzato).
        $systemImages = $this->listSystemImages();
        return Response::json([
            'ok' => true,
            'overrides' => $rows,
            'system_images' => $systemImages,
        ]);
    }

    /** Enumera le immagini default condivise (storage/templates/risdoc/images/*).
     *  Ritorna [{relative_path, size, mtime}, ...]. Non include sub-folder
     *  (loghi/stemmi vivono al root images/ per convenzione). */
    private function listSystemImages(): array
    {
        $base = dirname(__DIR__, 3) . '/storage/templates/risdoc/images';
        if (!is_dir($base)) {
            return [];
        }
        $out = [];
        $entries = @scandir($base);
        if (!is_array($entries)) {
            return [];
        }
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $full = $base . '/' . $e;
            if (!is_file($full)) {
                continue;
            }
            // Solo immagini (estensione semplice)
            if (!preg_match('/\.(png|jpe?g|gif|svg|webp)$/i', $e)) {
                continue;
            }
            $out[] = [
                'kind' => 'image',
                'relative_path' => 'images/' . $e,
                'source' => 'system',
                'size' => filesize($full),
                'mtime' => filemtime($full),
            ];
        }
        return $out;
    }

    public function overrideSave(Request $req, array $params): Response
    {
        $tid = Permission::currentTeacherId();
        $id  = (int)($params['id'] ?? 0);
        if ($tid === 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        // Phase 24.64 — override teacher è proprietà del docente; richiede
        // canView (vede il template) non canEdit (modifica template stesso).
        // Le righe scritte sono in risdoc_teacher_overrides scoped al tid.
        if (!Permission::canView($id, $tid)) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        $kind = (string)($req->post['kind'] ?? '');
        $path = (string)($req->post['path'] ?? '');
// Phase 24.58 — instance_key + instance_label optional
        $instanceKey   = (string)($req->post['instance_key']   ?? '');
        $instanceLabel = isset($req->post['instance_label']) ? (string)$req->post['instance_label'] : null;
        if ($kind === '') {
            return Response::json(['error' => 'kind_required'], 400);
        }
        if (!in_array($kind, ['html','tex','css','json','image','texCommon'], true)) {
            return Response::json(['error' => 'invalid_kind'], 400);
        }

        $tmpl = $this->resolver->findTemplate($id);
        if (!$tmpl) {
            return Response::json(['error' => 'template_not_found'], 404);
        }
        $srcVersion = (string)$tmpl['source_hash'];
        try {
            if ($kind === 'image') {
        // image: upload multipart via $_FILES['file']
                if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                    return Response::json(['error' => 'upload_missing'], 400);
                }
                $tmp = $_FILES['file']['tmp_name'];
                $hash = hash_file('sha256', $tmp) ?: '';
                if ($hash === '') {
                    return Response::json(['error' => 'hash_failed'], 500);
                }
                $dir = dirname(__DIR__, 3) . '/storage/overrides/teacher_' . $tid;
                if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                    return Response::json(['error' => 'storage_unavailable'], 500);
                }
                if (!is_file("$dir/$hash") && !@move_uploaded_file($tmp, "$dir/$hash")) {
                    return Response::json(['error' => 'upload_failed'], 500);
                }
                $oid = $this->overrides->saveImage($tid, $id, $path, $hash, $srcVersion, $instanceKey, $instanceLabel);
                return Response::json(['ok' => true, 'id' => $oid, 'image_hash' => $hash, 'instance_key' => $instanceKey]);
            }

            $body = (string)($req->post['body'] ?? '');
            $oid  = $this->overrides->saveText($tid, $id, $kind, $path, $body, $srcVersion, $instanceKey, $instanceLabel);
            return Response::json(['ok' => true, 'id' => $oid, 'instance_key' => $instanceKey]);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'save_failed', 'detail' => $e->getMessage()], 500);
        }
    }

    public function overrideDelete(Request $req, array $params): Response
    {
        $tid = Permission::currentTeacherId();
        $id  = (int)($params['id'] ?? 0);
        if ($tid === 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        // Phase 24.64 — vedi overrideSave: canView su risorsa templates,
        // delete scoped a teacher_id=$tid (proprio override).
        if (!Permission::canView($id, $tid)) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        $kind = (string)($req->post['kind'] ?? '');
        $path = (string)($req->post['path'] ?? '');
        $instanceKey = (string)($req->post['instance_key'] ?? '');
        if ($kind === '') {
            return Response::json(['error' => 'kind_required'], 400);
        }

        $ok = $this->overrides->delete($tid, $id, $kind, $path, $instanceKey);
        return Response::json(['ok' => $ok]);
    }

    // ─────── Phase 24.58 — Instances API ───────

    /** GET /api/risdoc/templates/{id}/instances — lista istanze del docente. */
    public function instancesList(Request $req, array $params): Response
    {
        $tid = Permission::currentTeacherId();
        $id  = (int)($params['id'] ?? 0);
        if (!Permission::canView($id, $tid)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $rows = $this->overrides->listInstances($tid, $id);
        return Response::json(['ok' => true, 'instances' => $rows]);
    }

    /**
     * GET /api/risdoc/teacher/instances — tutte istanze del docente
     * cross-template, raggruppate per template_id. Usato dalla sidepage
     * per renderizzare le istanze sotto i template istituzionali.
     */
    public function teacherAllInstances(Request $req): Response
    {
        $tid = Permission::currentTeacherId();
        if ($tid === 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $rows = $this->overrides->listAllInstancesByTeacher($tid);
        return Response::json(['ok' => true, 'instances' => $rows]);
    }

    /**
     * POST /api/risdoc/templates/{id}/instances — crea nuova istanza.
     * Body: instance_label (richiesto). Genera instance_key slug.
     *
     * Phase 24.64 — usa canView (non canEdit): l'istanza è proprietà
     * del docente che la crea, non modifica il template istituzionale.
     * Chiunque può vedere il template può forkarlo.
     */
    public function instancesCreate(Request $req, array $params): Response
    {
        $tid = Permission::currentTeacherId();
        $id  = (int)($params['id'] ?? 0);
        if ($tid === 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        if (!Permission::canView($id, $tid)) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        $label = trim((string)($req->post['instance_label'] ?? ''));
        if ($label === '') {
            return Response::json(['error' => 'label_required'], 400);
        }

        $tmpl = $this->resolver->findTemplate($id);
        if (!$tmpl) {
            return Response::json(['error' => 'template_not_found'], 404);
        }

        // Phase 25.B2 — race-safe instance_key generation.
        //
        // Vecchio flow (race window): listInstances → array taken → PHP
        // while-loop → INSERT IGNORE. Due request concorrenti con stesso
        // label entrambe passavano lo stesso slug (no collision visible
        // yet) → INSERT race → una win, l'altra IGNORE silenzioso, ENTRAMBE
        // API "ok" con la stessa key (caller all'oscuro del conflitto).
        //
        // Nuovo flow (atomic): INSERT con ON DUPLICATE KEY UPDATE id=id;
        // se rowCount === 1 siamo creator, se 0 retry con suffisso.
        // Max 50 iterazioni paranoid (in pratica 1-2 collision max).
        $base = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '-', $label));
        $base = trim($base, '-');
        if ($base === '') {
            $base = 'inst';
        }
        $base = substr($base, 0, 56);
        $sourceHash = (string)$tmpl['source_hash'];
        $key = $base;
        $created = false;
        for ($i = 1; $i <= 50; $i++) {
            $created = $this->overrides->createInstanceMarker($tid, $id, $key, $label, $sourceHash);
            if ($created) {
                break;
            }
            $key = $base . '-' . $i;
        }
        if (!$created) {
            return Response::json(['error' => 'instance_key_collision_exhausted'], 409);
        }
        return Response::json(['ok' => true, 'instance_key' => $key, 'instance_label' => $label]);
    }

    /** POST /api/risdoc/templates/{id}/instances/{key}/delete */
    public function instancesDelete(Request $req, array $params): Response
    {
        $tid = Permission::currentTeacherId();
        $id  = (int)($params['id'] ?? 0);
        if ($tid === 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        if (!Permission::canView($id, $tid)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $key = (string)($params['key'] ?? '');
        if ($key === '') {
            return Response::json(['error' => 'key_required'], 400);
        }
        $n = $this->overrides->deleteInstance($tid, $id, $key);
        return Response::json(['ok' => true, 'deleted_rows' => $n]);
    }

    /** POST /api/risdoc/templates/{id}/instances/{key}/rename — body: instance_label */
    public function instancesRename(Request $req, array $params): Response
    {
        $tid = Permission::currentTeacherId();
        $id  = (int)($params['id'] ?? 0);
        if ($tid === 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        if (!Permission::canView($id, $tid)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $key = (string)($params['key'] ?? '');
        $label = trim((string)($req->post['instance_label'] ?? ''));
        if ($key === '' || $label === '') {
            return Response::json(['error' => 'key_and_label_required'], 400);
        }
        $n = $this->overrides->renameInstance($tid, $id, $key, $label);
        return Response::json(['ok' => true, 'updated_rows' => $n]);
    }

    // ─────── Phase 24.55 — Institutional override (admin-edited baseline) ───────

    /** GET /api/risdoc/templates/{id}/institutional-overrides — super-admin only */
    public function institutionalOverridesList(Request $req, array $params): Response
    {
        if (!Permission::isSuperAdmin()) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $id = (int)($params['id'] ?? 0);
        if ($id === 0) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        $tmpl = $this->resolver->findTemplate($id);
        if (!$tmpl) {
            return Response::json(['error' => 'template_not_found'], 404);
        }
        $rows = $this->institutional->listForTemplate($id);
        return Response::json(['ok' => true, 'overrides' => $rows]);
    }

    /**
     * POST /api/risdoc/templates/{id}/institutional-override
     *
     * Permessi (G22.S26):
     *   - super-admin: applica direttamente
     *   - collaboratore con requires_review=0: applica direttamente
     *   - collaboratore con requires_review=1: la modifica viene messa in
     *     coda risdoc_template_pending_changes (status=pending) per
     *     approvazione/rifiuto del super-admin
     *
     * body: kind, path, content (per text) | $_FILES['file'] (per image).
     */
    public function institutionalOverrideSave(Request $req, array $params): Response
    {
        $id = (int)($params['id'] ?? 0);
        if ($id === 0) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        $teacherId = Permission::currentTeacherId();
        $isAdmin   = Permission::isSuperAdmin();
        $isCollab  = $teacherId > 0 && Permission::isCollaborator($id, $teacherId);
        if (!$isAdmin && !$isCollab) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        // G22.S26 — policy decisione enqueue centralizzata in ReviewFlow.
        $needsReview = \App\Services\Risdoc\ReviewFlow::shouldEnqueueFor($id, $teacherId);

        $kind = (string)($req->post['kind'] ?? '');
        $rawPath = (string)($req->post['path'] ?? '');
        if ($kind === '') {
            return Response::json(['error' => 'kind_required'], 400);
        }
        if (!\in_array($kind, ['html','tex','css','json','image','texCommon','schema'], true)) {
            return Response::json(['error' => 'invalid_kind'], 400);
        }
        // G22.S26 — path traversal guard. Schema kind ammette path vuoto
        // (la coppia template_id+kind=schema è univoca).
        try {
            $path = \App\Services\Risdoc\ReviewFlow::sanitizePath($rawPath);
        } catch (\RuntimeException $e) {
            return Response::json(['error' => 'invalid_path', 'detail' => $e->getMessage()], 400);
        }

        $tmpl = $this->resolver->findTemplate($id);
        if (!$tmpl) {
            return Response::json(['error' => 'template_not_found'], 404);
        }
        $srcVersion = (string)$tmpl['source_hash'];
        $reviewNote = trim((string)($req->post['review_note'] ?? '')) ?: null;
        try {
            // Image kind: file binario letto da $_FILES['file'].
            if ($kind === 'image') {
                if (!isset($_FILES['file']) || $_FILES['file']['error'] !== \UPLOAD_ERR_OK) {
                    return Response::json(['error' => 'upload_missing'], 400);
                }
                $tmp = (string)$_FILES['file']['tmp_name'];
                // G22.S26 — MIME validation: rifiuta upload non-immagine
                // (un attacker potrebbe spedire script/eseguibili in un
                // form kind=image se non controlliamo il content type).
                try {
                    \App\Services\Risdoc\ReviewFlow::validateImageUpload($tmp);
                } catch (\RuntimeException $e) {
                    return Response::json(['error' => 'invalid_image', 'detail' => $e->getMessage()], 415);
                }
                if ($needsReview) {
                    $bin = \file_get_contents($tmp);
                    if ($bin === false) {
                        return Response::json(['error' => 'upload_read_failed'], 500);
                    }
                    $review = new \App\Services\Risdoc\ReviewFlow($this->institutional);
                    $pid = $review->submit($id, $teacherId, $kind, $path, \base64_encode($bin), 'base64', $reviewNote);
                    return Response::json(['ok' => true, 'pending_id' => $pid, 'status' => 'pending_review']);
                }
                // Diretto (admin o collab senza review):
                $hash = \hash_file('sha256', $tmp);
                $base = \dirname(__DIR__, 3) . '/storage/overrides/institutional';
                if (!\is_dir($base) && !\mkdir($base, 0o775, true) && !\is_dir($base)) {
                    return Response::json(['error' => 'storage_mkdir_failed'], 500);
                }
                $dest = $base . '/' . $hash;
                if (!\is_file($dest) && !\copy($tmp, $dest)) {
                    return Response::json(['error' => 'storage_write_failed'], 500);
                }
                $rid = $this->institutional->saveImage($id, $path, $hash, $srcVersion, $teacherId ?: null);
                return Response::json(['ok' => true, 'id' => $rid, 'image_hash' => $hash]);
            }
            $body = (string)($req->post['content'] ?? '');
            if ($needsReview) {
                $review = new \App\Services\Risdoc\ReviewFlow($this->institutional);
                $pid = $review->submit($id, $teacherId, $kind, $path, $body, 'utf8', $reviewNote);
                return Response::json(['ok' => true, 'pending_id' => $pid, 'status' => 'pending_review']);
            }
            $rid = $this->institutional->saveText($id, $kind, $path, $body, $srcVersion, $teacherId ?: null);
            return Response::json(['ok' => true, 'id' => $rid]);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'save_failed', 'detail' => $e->getMessage()], 500);
        }
    }

    /** POST /api/risdoc/templates/{id}/institutional-override/del — super-admin only */
    public function institutionalOverrideDelete(Request $req, array $params): Response
    {
        if (!Permission::isSuperAdmin()) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $id = (int)($params['id'] ?? 0);
        if ($id === 0) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        $kind = (string)($req->post['kind'] ?? '');
        $path = (string)($req->post['path'] ?? '');
        if ($kind === '') {
            return Response::json(['error' => 'kind_required'], 400);
        }
        $ok = $this->institutional->delete($id, $kind, $path);
        return Response::json(['ok' => $ok]);
    }

    // ─────── helpers ───────

    private function role(int $templateId, int $teacherId): string
    {
        if (Permission::isSuperAdmin()) {
            return 'super-admin';
        }
        // G22.S26 — owner deprecato. Solo super-admin / collab / viewer.
        if (Permission::isCollaborator($templateId, $teacherId)) {
            return 'collab';
        }
        return 'viewer';
    }

    private function guessMime(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return [
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'svg'  => 'image/svg+xml',
            'webp' => 'image/webp',
        ][$ext] ?? 'application/octet-stream';
    }
}
