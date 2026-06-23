<?php

declare(strict_types=1);

namespace App\Controllers\Risdoc;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Services\Risdoc\OverrideRepository;
use App\Services\Risdoc\Permission;
use App\Services\Risdoc\TemplateResolver;
use App\Services\GeoGebra\GeoGebraTexPreProcessor;
use App\Services\TexCompile\SvgToPdfClient;
use App\Services\TexCompile\TexCompileClient;

/**
 * G22.S11 — Multi-file TEX/PDF endpoint per modal preview risdoc.
 *
 * Espone:
 *   POST /api/risdoc/templates/{id}/tex-files     → JSON {files:[{path,content,...}]}
 *   POST /api/risdoc/templates/{id}/tex-files/save → save overrides (kind=texCommon)
 *   POST /api/risdoc/templates/{id}/compile-pdf   → bundle al VPS, ritorna PDF
 *
 * Riusa ExportController::buildFiles per coerenza con flusso ZIP/Overleaf.
 *
 * Schema files standard nel modal (uguale al pacchetto ZIP):
 *   - main.tex                       (overridable, kind=texCommon)
 *   - <argomento>.tex                (auto-generato da schema+values, read-only)
 *   - texCommon/risdoc.sty           (overridable)
 *   - texCommon/intestaLAteX_IIS.tex (overridable)
 *
 * Save: solo i 3 file overridable (texCommon/*) accettano write. Il body
 * <argomento>.tex è regenerato server-side ad ogni compile dal schema, quindi
 * editarlo nel modal NON ha effetto persistente (display-only).
 */
final class TexFilesController
{
    public function __construct(
        private TemplateResolver $resolver = new TemplateResolver(),
        private ExportController $exporter = new ExportController(),
    ) {
    }

    /**
     * POST /api/risdoc/templates/{id}/tex-files
     * Body: form_state JSON (POST form-encoded `form_state=<json>`).
     */
    public function getFiles(Request $req, array $params): Response
    {
        $id  = (int)($params['id'] ?? 0);
        $tid = Permission::currentTeacherId();
        if (!Permission::canView($id, $tid)) {
            return Response::json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $tmpl = $this->resolver->findTemplate($id);
        if (!$tmpl) {
            return Response::json(['ok' => false, 'error' => 'template_not_found'], 404);
        }
        $formState = $this->parseFormState((string)($req->post['form_state'] ?? ''));

        try {
            $built = $this->exporter->buildFiles($id, $tid, $tmpl, $formState);
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'error' => 'build_failed', 'detail' => $e->getMessage()], 500);
        }

        $files = [
            ['path' => 'main.tex',                          'content' => $built['mainFinal'], 'editable' => true,  'kind' => 'texCommon', 'rel' => 'main.tex'],
            ['path' => $built['docName'],                   'content' => $built['doc'],       'editable' => false, 'kind' => 'generated', 'rel' => null],
            ['path' => 'texCommon/risdoc.sty',              'content' => $built['styBody'],   'editable' => true,  'kind' => 'texCommon', 'rel' => 'risdoc.sty'],
            ['path' => 'texCommon/intestaLAteX_IIS.tex',    'content' => $built['headBody'],  'editable' => true,  'kind' => 'texCommon', 'rel' => 'intestaLAteX_IIS.tex'],
        ];
        $overrides = $built['overrides'] ?? [];
        $out = [];
        foreach ($files as $f) {
            $status = $f['kind'] === 'generated'
                ? 'common'
                : ($overrides[$f['path']] ?? 'common');
            $out[] = [
                'path'           => $f['path'],
                'content'        => (string)$f['content'],
                'size'           => strlen((string)$f['content']),
                'editable'       => (bool)$f['editable'],
                'overrideStatus' => $status,
                'missing'        => $status === 'missing',
            ];
        }
        return Response::json(['ok' => true, 'files' => $out, 'doc_name' => $built['docName']], 200);
    }

    /**
     * POST /api/risdoc/templates/{id}/tex-files/save
     * Body: { files: [{path, content}, ...] }. Salva overrides texCommon
     * SOLO per i 3 file editable (main.tex, risdoc.sty, intestaLAteX_IIS.tex).
     * Il body <argomento>.tex è regenerato → non salvabile come override qui.
     */
    public function saveFiles(Request $req, array $params): Response
    {
        $id  = (int)($params['id'] ?? 0);
        $tid = Permission::currentTeacherId();
        if (!Permission::canView($id, $tid) || $tid <= 0) {
            return Response::json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $raw = (string)file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body) || !isset($body['files']) || !is_array($body['files'])) {
            return Response::json(['ok' => false, 'error' => 'invalid_payload'], 400);
        }

        // Whitelist path → relativeName texCommon.
        $allowed = [
            'main.tex'                       => 'main.tex',
            'texCommon/risdoc.sty'           => 'risdoc.sty',
            'texCommon/intestaLAteX_IIS.tex' => 'intestaLAteX_IIS.tex',
        ];

        $repo = new OverrideRepository();
        $saved = [];
        $skipped = [];
        foreach ($body['files'] as $f) {
            if (!is_array($f) || !isset($f['path'], $f['content'])) {
                continue;
            }
            $path = (string)$f['path'];
            $rel  = $allowed[$path] ?? null;
            if ($rel === null) {
                $skipped[] = $path; // non editable (es. <argomento>.tex auto-generato)
                continue;
            }
            $content = (string)$f['content'];
            // Normalizza il main.tex: il modal mostra \input{<docName>} GIÀ RISOLTO.
            // Persistendolo così, se l'argomento del template cambia (→ cambia il
            // nome del file contenuto) il riferimento resta stantio → al compile
            // "File `<vecchio>.tex' not found". Riconvertiamo al segnaposto
            // %[filetex], che buildFiles ri-risolve sempre al docName corrente.
            if ($rel === 'main.tex') {
                $tmplRow = $this->resolver->findTemplate($id);
                if (is_array($tmplRow) && isset($tmplRow['argomento'])) {
                    $docName = $this->exporter->sanitizeName((string)$tmplRow['argomento']) . '.tex';
                    $content = str_replace('\\input{' . $docName . '}', '%[filetex]', $content);
                }
            }
            try {
                $repo->saveText(
                    $tid,
                    $id,
                    'texCommon',
                    $rel,
                    $content,
                    'manual-' . date('Y-m-d')
                );
                $saved[] = $path;
            } catch (\Throwable $e) {
                return Response::json([
                    'ok' => false,
                    'error' => 'save_failed',
                    'detail' => $e->getMessage(),
                    'path' => $path,
                ], 500);
            }
        }
        return Response::json(['ok' => true, 'saved' => $saved, 'skipped' => $skipped], 200);
    }

    /**
     * POST /api/risdoc/templates/{id}/compile-pdf
     * Body: { form_state: <json>, files: [{path, content}] }
     * Compila il bundle multi-file via VPS tex-compile e ritorna PDF inline.
     *
     * I `files` opzionali consentono di compilare con edit non salvati (live
     * preview): se presenti, sovrascrivono il content di quei path nel bundle.
     */
    public function compilePdf(Request $req, array $params): Response
    {
        $id  = (int)($params['id'] ?? 0);
        $tid = Permission::currentTeacherId();
        if (!Permission::canView($id, $tid)) {
            return Response::json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $tmpl = $this->resolver->findTemplate($id);
        if (!$tmpl) {
            return Response::json(['ok' => false, 'error' => 'template_not_found'], 404);
        }

        $raw = (string)file_get_contents('php://input');
        $body = json_decode($raw, true) ?: [];
        $formState = is_array($body['form_state'] ?? null) ? (array)$body['form_state'] : [];
        $clientFiles = is_array($body['files'] ?? null) ? (array)$body['files'] : [];

        try {
            $built = $this->exporter->buildFiles($id, $tid, $tmpl, $formState);
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'error' => 'build_failed', 'detail' => $e->getMessage()], 500);
        }

        // Bundle base
        $bundle = [
            'main.tex'                          => $built['mainFinal'],
            $built['docName']                   => $built['doc'],
            'texCommon/risdoc.sty'              => $built['styBody'],
            'texCommon/intestaLAteX_IIS.tex'    => $built['headBody'],
        ];
        // Immagini (loghi istituto in intestaLAteX_IIS via \includegraphics{images/…}).
        // Senza, il compile della preview falliva (l'export ZIP le includeva già).
        $imgDir = dirname(__DIR__, 3) . '/storage/templates/risdoc/images';
        if (is_dir($imgDir)) {
            foreach (glob($imgDir . '/*') ?: [] as $img) {
                if (is_file($img)) {
                    $bundle['images/' . basename($img)] = (string)file_get_contents($img);
                }
            }
        }
        // Override con edit live dal browser
        foreach ($clientFiles as $f) {
            if (is_array($f) && isset($f['path'], $f['content'])) {
                $bundle[(string)$f['path']] = (string)$f['content'];
            }
        }

        $client = TexCompileClient::tryDefault(60);
        if (!$client) {
            return Response::json(['ok' => false, 'error' => 'tex_compile_disabled'], 503);
        }
        $files = [];
        foreach ($bundle as $p => $c) {
            $files[] = ['path' => $p, 'content' => (string)$c];
        }
        // GeoGebra: converte i marker \fmgeogebra{base64}{label} in
        // \includegraphics + PDF nel bundle (stesso preprocessor della verifica).
        // Best-effort: errore conversione → compila col bundle invariato.
        $files = self::applyGeogebraPreprocess($files, 'risdoc-' . $id);
        try {
            $result = $client->compileBundle(
                files:    $files,
                mainPath: 'main.tex',
                docId:    'risdoc-' . $id . '-' . substr(md5(json_encode($bundle) ?: ''), 0, 10),
                engine:   (string)Config::get('tex_compile.default_engine', 'pdflatex'),
                passes:   (int)Config::get('tex_compile.default_passes', 2),
            );
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'error' => 'compile_failed', 'detail' => $e->getMessage()], 500);
        }

        if (!($result['ok'] ?? false)) {
            return Response::json([
                'ok'          => false,
                'error'       => 'tex_compile_failed',
                // tail del log: gli errori LaTeX (! …) sono vicino al punto di
                // arresto, in fondo — non nei primi 4000 char (preambolo).
                'log_excerpt' => substr((string)($result['log'] ?? ''), -6000),
                'duration_ms' => $result['duration_ms'] ?? null,
            ], 422);
        }

        // Risposta = PDF binario inline (consumer = modal preview che lo
        // renderizza con PDF.js). Frontend usa response.blob() / arrayBuffer.
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

    private function parseFormState(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }

    /**
     * Pre-processa i marker GeoGebra (\fmgeogebra{base64}{label}) in un bundle
     * multi-file: converte gli SVG in PDF via VPS rsvg-convert e li aggiunge al
     * bundle, sostituendo i marker con \includegraphics. Mode-agnostico: usato
     * dal compile risdoc e teacher-content (oltre alla verifica). Best-effort:
     * se il servizio è assente o fallisce, ritorna i file invariati.
     *
     * @param list<array{path:string, content:string}> $files
     * @return list<array{path:string, content:string}>
     */
    public static function applyGeogebraPreprocess(array $files, string $docId): array
    {
        $hasMarker = false;
        foreach ($files as $f) {
            if (strpos((string)($f['content'] ?? ''), '\\fmgeogebra') !== false) {
                $hasMarker = true;
                break;
            }
        }
        if (!$hasMarker) {
            return $files;
        }

        $endpoint = (string)Config::get('tex_compile.endpoint', '');
        $secret   = (string)Config::get('tex_compile.secret', '');
        if ($endpoint === '' || $secret === '') {
            return $files;
        }

        try {
            $svgClient = new SvgToPdfClient(
                $endpoint,
                $secret,
                min(15, (int)Config::get('tex_compile.timeout', 60)),
                (string)Config::get('tex_compile.ca_bundle', ''),
            );
            $pre = new GeoGebraTexPreProcessor($svgClient);
            return $pre->processBundle($files, $docId);
        } catch (\Throwable $e) {
            error_log('[geogebra-pre] ' . $docId . ' failed (best-effort): ' . $e->getMessage());
            return $files;
        }
    }
}
