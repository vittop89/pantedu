<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\FileService;
use App\Support\Validator;
use Throwable;

/**
 * HTTP facade for FileService. Each endpoint validates input, then
 * delegates to the service which enforces path + extension + size
 * guards. Legacy responses (plain text) are preserved where callers
 * expect them; new endpoints return JSON.
 */
final class FileController
{
    public function __construct(private ?FileService $files = null)
    {
        $this->files ??= new FileService();
    }

    /** POST /files/save-tex — fileName, fileContent */
    public function saveTex(Request $req): Response
    {
        try {
            $v       = new Validator($req->post);
            $name    = $v->filename('fileName', ['tex'], 120);
            $content = $v->string('fileContent', max: 5 * 1024 * 1024);
            $path    = $this->files->save('temp', $name, $content, 'tex');
            return Response::json(['ok' => true, 'path' => $this->publicize($path)]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /** POST /files/save-latex — same shape, targets verifiche/temp */
    public function saveLatex(Request $req): Response
    {
        try {
            $v       = new Validator($req->post);
            $name    = $v->filename('fileName', ['tex'], 120);
            $content = $v->string('fileContent', max: 5 * 1024 * 1024);
            $path    = $this->files->save('verifiche_temp', $name, $content, 'tex');
            return Response::json(['ok' => true, 'path' => $this->publicize($path)]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /** POST /files/delete-folder — folderPath (absolute-ish from webroot) */
    public function deleteFolder(Request $req): Response
    {
        try {
            $v          = new Validator($req->post);
            $folderPath = $v->webPath('folderPath');
            [$label, $rel] = $this->splitWebrootPath($folderPath);
            $ok = $this->files->deleteFolder($label, $rel);
            return Response::json(['ok' => $ok]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /** POST /files/delete — filePath */
    public function deleteFile(Request $req): Response
    {
        try {
            $v        = new Validator($req->post);
            $filePath = $v->webPath('filePath');
            [$label, $rel] = $this->splitWebrootPath($filePath);
            $ok = $this->files->delete($label, $rel);
            return Response::json(['ok' => $ok]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /** GET /files/list?directory=... */
    public function list(Request $req): Response
    {
        try {
            $v   = new Validator($req->query);
            $dir = $v->webPath('directory');
            [$label, $rel] = $this->splitWebrootPath($dir);
            return Response::json($this->files->listDirectory($label, $rel));
        } catch (Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
    }

    /** Any /files/clear-temp — wipes /temp and /verifiche/temp */
    public function clearTemp(Request $req): Response
    {
        $removed = $this->files->clearRootContents('temp')
                 + $this->files->clearRootContents('verifiche_temp');
        return Response::json(['ok' => true, 'removed' => $removed]);
    }

    /**
     * POST /save_image.php — base64 image/SVG → file su disco con auto-categoria.
     * Body: filePath (webroot path al file di destinazione), fileName, imageContent (base64 o raw), isSvg.
     * Logica derivata dal legacy api/files/save_image.php:
     *  - SVG con prefisso svg_/svg/: salva sotto sub-cartella nel filePath dir
     *  - Altrimenti: auto-cartella img_mat/img_fis/img_geo in base al path
     */
    public function saveImage(Request $req): Response
    {
        try {
            $v = new \App\Support\Validator($req->post);
            // Tutti i campi passano per Validator. filePath è una webPath con
            // charset whitelist + reject traversal/null/absolute. fileName è
            // validato con estensione esplicitamente permessa.
            $filePath     = $v->webPath('filePath');
            $fileName     = $v->filename('fileName', ['svg', 'png', 'jpg', 'jpeg', 'gif', 'webp']);
            $imageContent = $v->string('imageContent', min: 1, max: 20 * 1024 * 1024); // 20MB max
            $isSvg        = ($req->post['isSvg'] ?? '') === 'true';

            \App\Support\SafePath::resolve($filePath, [$_SERVER['DOCUMENT_ROOT']]);
            $absolutePath = $_SERVER['DOCUMENT_ROOT'] . $filePath;

            // Decodifica base64 (support data URL prefix per svg)
            if (str_starts_with($imageContent, 'data:image/svg+xml;base64,')) {
                $imageContent = substr($imageContent, strlen('data:image/svg+xml;base64,'));
            }
            $imageData = base64_decode($imageContent, strict: true);
            if ($imageData === false) {
                return Response::json(['error' => 'invalid_base64'], 400);
            }

            // MIME magic-bytes check: niente payload PHP mascherati da svg/png.
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $allowedByExt = match ($ext) {
                'svg' => ['svg'],
                'png' => ['png'],
                'jpg', 'jpeg' => ['jpeg'],
                'gif' => ['gif'],
                'webp' => ['webp'],
                default => [],
            };
            if (!$allowedByExt) {
                return Response::json(['error' => 'extension_not_allowed', 'ext' => $ext], 400);
            }
            try {
                \App\Support\MimeSniffer::assertAllowed($imageData, $allowedByExt);
            } catch (\Throwable $e) {
                return Response::json(['error' => 'mime_mismatch', 'reason' => $e->getMessage()], 400);
            }
            // SVG-specific: reject <script> inline (XSS vector).
            if ($ext === 'svg' && preg_match('/<script\b/i', $imageData)) {
                return Response::json(['error' => 'svg_script_blocked'], 400);
            }
            // Hardening (audit 2026-06-15, FND-010): defense-in-depth — passa
            // l'SVG dal SvgSanitizer completo (enshrined/svg-sanitize) che rimuove
            // anche onload/on*, <use href=javascript:>, foreignObject, entity
            // esterne ecc. (il solo blocco <script> non bastava). Endpoint
            // admin-gated, ma uniformiamo la sanitizzazione SVG su tutti i path.
            if ($ext === 'svg') {
                $imageData = \App\Services\Security\SvgSanitizer::sanitize($imageData);
            }

            // Path finale: svg folder OR category-auto OR current dir.
            if ($isSvg && (str_starts_with($fileName, 'svg_') || str_starts_with($fileName, 'svg/'))) {
                $svgDirPath = $absolutePath . '/' . dirname($fileName);
                if (!is_dir($svgDirPath) && !mkdir($svgDirPath, 0777, true) && !is_dir($svgDirPath)) {
                    return Response::json(['error' => 'mkdir_failed'], 500);
                }
                $absolutePath = $svgDirPath . '/' . basename($fileName);
            } else {
                $directory = match (true) {
                    str_contains($absolutePath, 'MAT') => 'img_mat',
                    str_contains($absolutePath, 'FIS') => 'img_fis',
                    str_contains($absolutePath, 'GEO') => 'img_geo',
                    default                            => '',
                };
                if ($directory !== '') {
                    $dirPath = dirname($absolutePath) . '/' . $directory;
                    if (!is_dir($dirPath) && !mkdir($dirPath, 0777, true) && !is_dir($dirPath)) {
                        return Response::json(['error' => 'mkdir_failed'], 500);
                    }
                    $absolutePath = $dirPath . '/' . $fileName;
                } else {
                    $absolutePath = dirname($absolutePath) . '/' . $fileName;
                }
            }

            if (file_put_contents($absolutePath, $imageData, LOCK_EX) === false) {
                return Response::json(['error' => 'write_failed'], 500);
            }
            return Response::json(['ok' => true, 'path' => $this->publicize($absolutePath)]);
        } catch (Throwable $e) {
            return Response::json(['error' => 'invalid_input', 'reason' => $e->getMessage()], 400);
        }
    }

    /**
     * POST /save_pdf_file.php — body JSON: salva PDF compilato sotto
     * verifiche/tex_pdf/{iis}/{iis+cls}/{materia}/{verTitle}/{versionFolder}/.
     */
    public function savePdf(Request $req): Response
    {
        try {
            $data = json_decode((string)file_get_contents('php://input'), true) ?: [];
            // Validator applicato a ogni campo input — niente raw POST path.
            $slug = '/^[A-Za-z0-9_\-]{1,32}$/';
            $iis = (string)($data['selectedIIS']   ?? '');
            $cls = (string)($data['selectedCLS']   ?? '');
            $mat = (string)($data['selectedMATER'] ?? '');
            if (!preg_match($slug, $iis)) {
                return Response::json(['success' => false, 'error' => 'invalid_iis'], 400);
            }
            if (!preg_match($slug, $cls)) {
                return Response::json(['success' => false, 'error' => 'invalid_cls'], 400);
            }
            if (!preg_match($slug, $mat)) {
                return Response::json(['success' => false, 'error' => 'invalid_mat'], 400);
            }
            $verTitle      = (string)($data['verTitle']      ?? '');
            $versionFolder = (string)($data['versionFolder'] ?? '');
            // versionFolder opzionale — ora esplicitamente validato.
            if ($versionFolder !== '' && !preg_match($slug, $versionFolder)) {
                return Response::json(['success' => false, 'error' => 'invalid_versionFolder'], 400);
            }
            $fileName = (string)($data['fileName'] ?? '');
            $pdfB64   = (string)($data['pdfContent'] ?? '');
            if ($verTitle === '' || $fileName === '' || $pdfB64 === '') {
                return Response::json(['success' => false, 'error' => 'missing_field'], 400);
            }
            // verTitle sanitizzato: solo alfanumerico + accentate
            $verTitleSafe = trim(str_replace(' ', '_', preg_replace('/[^a-zA-Z0-9_\-àèéìòù ]/', '', $verTitle)), '_');
            if ($verTitleSafe === '') {
                return Response::json(['success' => false, 'error' => 'invalid_verTitle'], 400);
            }
            // fileName forzato a .pdf senza path components
            $fileNameSafe = basename(pathinfo($fileName, PATHINFO_FILENAME)) . '.pdf';
            if (!preg_match('/^[A-Za-z0-9_\-àèéìòù. ]{1,200}$/u', $fileNameSafe)) {
                return Response::json(['success' => false, 'error' => 'invalid_fileName'], 400);
            }

            $pdfContent = base64_decode($pdfB64, strict: true);
            if ($pdfContent === false) {
                return Response::json(['success' => false, 'error' => 'invalid_base64'], 400);
            }
            if (strlen($pdfContent) > 50 * 1024 * 1024) { // 50MB cap
                return Response::json(['success' => false, 'error' => 'pdf_too_large'], 413);
            }
            // MIME check: magic-bytes "%PDF-"
            try {
                \App\Support\MimeSniffer::assertAllowed($pdfContent, ['pdf']);
            } catch (\Throwable $e) {
                return Response::json(['success' => false, 'error' => 'mime_mismatch'], 400);
            }

            $base = dirname(__DIR__, 2) . '/verifiche/tex_pdf';
            $rel  = $iis . '/' . ($iis . $cls) . '/' . $mat . '/' . $verTitleSafe
                  . ($versionFolder !== '' ? '/' . $versionFolder : '');
            $full = $base . '/' . $rel;
            if (!is_dir($full) && !mkdir($full, 0755, true) && !is_dir($full)) {
                return Response::json(['success' => false, 'error' => 'mkdir_failed'], 500);
            }
            $filePath = $full . '/' . $fileNameSafe;
            if (file_put_contents($filePath, $pdfContent, LOCK_EX) === false) {
                return Response::json(['success' => false, 'error' => 'write_failed'], 500);
            }
            return Response::json([
                'success'  => true,
                'filePath' => 'verifiche/tex_pdf/' . $rel . '/' . $fileNameSafe,
                'fileSize' => filesize($filePath),
            ]);
        } catch (Throwable $e) {
            return Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Legacy code supplies a path relative to the document root
     * (e.g. "/eser/ar/eser_ar5s/MAT/foo.tex"). Convert that to
     * (rootLabel, relative) using the FileService root map.
     *
     * @return array{0:string,1:string}
     */
    private function splitWebrootPath(string $webroot): array
    {
        $clean = ltrim(str_replace('\\', '/', $webroot), '/');
        $parts = explode('/', $clean, 2);
        $first = $parts[0];
        $rest  = $parts[1] ?? '';

        // Two-segment roots (e.g. verifiche_temp = verifiche/temp)
        if ($first === 'verifiche' && str_starts_with($rest, 'temp')) {
            return ['verifiche_temp', ltrim(substr($rest, 4), '/')];
        }

        $roots = $this->files->roots();
        if (isset($roots[$first])) {
            return [$first, $rest];
        }

        // Fallbacks by first segment match
        $labelMap = [
            'img' => 'img', 'eser' => 'eser', 'verifiche' => 'verifiche',
            'risdoc' => 'risdoc', 'lab' => 'lab', 'didattica' => 'didattica',
            'mappe' => 'mappe', 'drafts' => 'drafts', 'tex_pdf' => 'tex_pdf',
            'strcomp_bes_altro' => 'strcomp', 'temp' => 'temp',
        ];
        $label = $labelMap[$first] ?? null;
        if ($label === null) {
            throw new \RuntimeException('unknown_root_in_path');
        }
        return [$label, $rest];
    }

    private function publicize(string $absolutePath): string
    {
        $base = rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/');
        $p    = str_replace('\\', '/', $absolutePath);
        return str_starts_with($p, $base) ? substr($p, strlen($base)) : basename($p);
    }
}
