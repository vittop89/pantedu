<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use Throwable;

/**
 * G22.S15.bis Fase 5 — Gestione librerie shape drawio del docente.
 *
 * Storage filesystem (no DB):
 *   storage/templates/drawio/_default/{categoria}/{nome}.xml   — admin
 *   storage/templates/drawio/teachers/{tid}/{nome}.xml          — teacher
 *
 * Bundle sync paths (vedi VerificaController buildLocalBundleManifest):
 *   {institute}/modelli/drawio/{rel}.xml
 *
 * Endpoints:
 *   GET  /api/teacher/drawio/libraries          — list cascade-resolved
 *   POST /api/teacher/drawio/libraries/upload   — upload XML (multipart)
 *   POST /api/teacher/drawio/libraries/delete   — body {name}
 *
 * Validazione XML: rifiuta file > 1MB, deve avere `<mxlibrary>` root.
 */
final class TeacherDrawioLibraryController
{
    private const MAX_BYTES = 1024 * 1024;          // 1 MB per file
    private const ALLOWED_NAME = '/^[a-zA-Z0-9._-]{1,80}$/';

    public function list(Request $req): Response
    {
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        try {
            $defaultDir = $this->defaultDir();
            $teacherDir = $this->teacherDir($tid);
            $libraries = [];

            $walk = function (string $dir, string $source) use (&$libraries) {
                if (!is_dir($dir)) {
                    return;
                }
                $iter = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iter as $file) {
                    if (!$file->isFile() || strtolower($file->getExtension()) !== 'xml') {
                        continue;
                    }
                    $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($dir))), '/');
                    if ($rel === '') {
                        continue;
                    }
                    // override teacher su default per nome uguale
                    $libraries[$rel] = [
                        'name' => $rel,
                        'size' => $file->getSize(),
                        'source' => $source,
                        'mtime' => date('c', $file->getMTime()),
                    ];
                }
            };
            $walk($defaultDir, 'default');
            $walk($teacherDir, 'teacher');

            return Response::json([
                'ok' => true,
                'libraries' => array_values($libraries),
                'count' => count($libraries),
            ]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function upload(Request $req): Response
    {
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        try {
            if (empty($_FILES['file']['tmp_name'])) {
                return Response::json(['ok' => false, 'error' => 'no_file'], 400);
            }
            $size = (int)($_FILES['file']['size'] ?? 0);
            if ($size <= 0 || $size > self::MAX_BYTES) {
                return Response::json(['ok' => false, 'error' => 'file_size_invalid'], 400);
            }
            $name = (string)($_FILES['file']['name'] ?? '');
            if (!preg_match(self::ALLOWED_NAME, $name)) {
                return Response::json(['ok' => false, 'error' => 'invalid_filename'], 400);
            }
            if (!str_ends_with(strtolower($name), '.xml')) {
                return Response::json(['ok' => false, 'error' => 'must_be_xml'], 400);
            }
            $content = (string)file_get_contents($_FILES['file']['tmp_name']);
            // Validazione XML drawio: deve contenere <mxlibrary> root
            if (stripos($content, '<mxlibrary') === false) {
                return Response::json(['ok' => false, 'error' => 'not_a_drawio_library'], 400);
            }

            $teacherDir = $this->teacherDir($tid);
            if (!is_dir($teacherDir)) {
                if (!@mkdir($teacherDir, 0775, true) && !is_dir($teacherDir)) {
                    return Response::json(['ok' => false, 'error' => 'mkdir_failed'], 500);
                }
            }
            $target = $teacherDir . '/' . $name;
            if (file_put_contents($target, $content) === false) {
                return Response::json(['ok' => false, 'error' => 'write_failed'], 500);
            }

            return Response::json([
                'ok' => true,
                'name' => $name,
                'size' => strlen($content),
                'path' => "modelli/drawio/{$name}",
            ]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function delete(Request $req): Response
    {
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        try {
            $payload = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $name = (string)($payload['name'] ?? '');
            if (!preg_match(self::ALLOWED_NAME, $name)) {
                return Response::json(['ok' => false, 'error' => 'invalid_name'], 400);
            }
            $target = $this->teacherDir($tid) . '/' . $name;
            if (!is_file($target)) {
                return Response::json(['ok' => false, 'error' => 'not_found'], 404);
            }
            if (!@unlink($target)) {
                return Response::json(['ok' => false, 'error' => 'unlink_failed'], 500);
            }
            return Response::json(['ok' => true]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * G22.S15.bis Fase 5 — Save XML libreria via JSON body. Chiamato dal
     * plugin drawio (pantedu-library-relay.js) dopo che l'utente fa
     * "Salva" sull'editor libreria interno di drawio. Sovrascrive il
     * file teacher esistente; crea nuovo se non esiste (stesso path
     * dell'upload multipart).
     */
    public function saveContent(Request $req): Response
    {
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        try {
            $payload = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $name = (string)($payload['name'] ?? '');
            $xml  = (string)($payload['xml']  ?? '');

            if (!preg_match(self::ALLOWED_NAME, $name)) {
                return Response::json(['ok' => false, 'error' => 'invalid_name'], 400);
            }
            if (!str_ends_with(strtolower($name), '.xml')) {
                return Response::json(['ok' => false, 'error' => 'must_be_xml'], 400);
            }
            if (strlen($xml) === 0 || strlen($xml) > self::MAX_BYTES) {
                return Response::json(['ok' => false, 'error' => 'xml_size_invalid'], 400);
            }
            if (stripos($xml, '<mxlibrary') === false) {
                return Response::json(['ok' => false, 'error' => 'not_a_drawio_library'], 400);
            }

            $teacherDir = $this->teacherDir($tid);
            if (!is_dir($teacherDir)) {
                if (!@mkdir($teacherDir, 0775, true) && !is_dir($teacherDir)) {
                    return Response::json(['ok' => false, 'error' => 'mkdir_failed'], 500);
                }
            }
            $target = $teacherDir . '/' . $name;
            $isUpdate = is_file($target);
            if (file_put_contents($target, $xml) === false) {
                return Response::json(['ok' => false, 'error' => 'write_failed'], 500);
            }

            return Response::json([
                'ok' => true,
                'name' => $name,
                'size' => strlen($xml),
                'action' => $isUpdate ? 'updated' : 'created',
            ]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** Read content of a library (cascade resolve teacher → default). */
    public function read(Request $req, array $params): Response
    {
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        $name = (string)($params['name'] ?? '');
        if (!preg_match(self::ALLOWED_NAME, $name)) {
            return Response::json(['ok' => false, 'error' => 'invalid_name'], 400);
        }
        $teacher = $this->teacherDir($tid) . '/' . $name;
        $default = $this->defaultDir() . '/' . $name;
        $abs = is_file($teacher) ? $teacher : (is_file($default) ? $default : null);
        if (!$abs) {
            return Response::json(['ok' => false, 'error' => 'not_found'], 404);
        }
        $content = (string)file_get_contents($abs);
        return new Response($content, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'private, max-age=300',
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

    private function defaultDir(): string
    {
        return dirname(__DIR__, 2) . '/storage/templates/drawio/_default';
    }

    private function teacherDir(int $tid): string
    {
        return dirname(__DIR__, 2) . "/storage/templates/drawio/teachers/{$tid}";
    }
}
