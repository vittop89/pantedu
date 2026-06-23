<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\TexBuilder\VersionPicker;
use Throwable;

/**
 * G19.49l — Admin endpoint per editare il preambolo TEX system-wide
 * delle verifiche. Storage: file singolo `storage/data/verifica_preamble.tex`
 * (override del default hardcoded in VersionPicker::baseHardcoded()).
 */
final class VerificaPreambleAdminController
{
    private const MAX_BYTES = 200 * 1024; // 200 KB cap defensive

    public function get(Request $req): Response
    {
        if (!$this->guard()) {
            return Response::json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        return Response::json([
            'ok'         => true,
            'current'    => VersionPicker::currentBase(),
            'default'    => VersionPicker::defaultBase(),
            'is_custom'  => is_file(VersionPicker::overrideFilePath()),
        ]);
    }

    public function save(Request $req): Response
    {
        if (!$this->guard()) {
            return Response::json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        try {
            $payload = json_decode((string)file_get_contents('php://input'), true);
            $content = (string)($payload['content'] ?? '');
            if (strlen($content) > self::MAX_BYTES) {
                return Response::json(['ok' => false, 'error' => 'preamble_too_large'], 413);
            }
            // Sanity: il preamble deve avere almeno `\documentclass`.
            if (trim($content) !== '' && !str_contains($content, '\\documentclass')) {
                return Response::json(['ok' => false, 'error' => 'preamble_missing_documentclass'], 422);
            }
            $path = VersionPicker::overrideFilePath();
            $dir  = \dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (trim($content) === '') {
                // Empty content → cancella override (ripristina default)
                if (is_file($path)) {
                    @unlink($path);
                }
                return Response::json(['ok' => true, 'reset' => true]);
            }
            $written = file_put_contents($path, $content);
            if ($written === false) {
                return Response::json(['ok' => false, 'error' => 'write_failed'], 500);
            }
            return Response::json(['ok' => true, 'bytes' => $written]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function reset(Request $req): Response
    {
        if (!$this->guard()) {
            return Response::json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $path = VersionPicker::overrideFilePath();
        if (is_file($path)) {
            @unlink($path);
        }
        return Response::json(['ok' => true, 'reset' => true]);
    }

    private function guard(): bool
    {
        return Auth::check() && Auth::isSuperAdmin();
    }
}
