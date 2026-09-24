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
            return Response::fail('forbidden', 403);
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
            return Response::fail('forbidden', 403);
        }
        // Fuori dal try, e rigoroso: fino al 23/9/2026 un corpo troncato o non
        // JSON dava `content` vuoto, e il ramo qui sotto cancellava l'override
        // del preambolo come per un «ripristina». Adesso è un 400 (invalid_json)
        // e un corpo oltre il doppio del tetto (l'escape JSON dei backslash del
        // TeX) un 413, dal lettore comune (ADR-034, A-52).
        $payload = $req->jsonObbligatorio(2 * self::MAX_BYTES + 4096);
        try {
            $content = (string)($payload['content'] ?? '');
            if (strlen($content) > self::MAX_BYTES) {
                return Response::fail('preamble_too_large', 413);
            }
            // Sanity: il preamble deve avere almeno `\documentclass`.
            if (trim($content) !== '' && !str_contains($content, '\\documentclass')) {
                return Response::fail('preamble_missing_documentclass', 422);
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
                return Response::fail('write_failed', 500);
            }
            return Response::json(['ok' => true, 'bytes' => $written]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function reset(Request $req): Response
    {
        if (!$this->guard()) {
            return Response::fail('forbidden', 403);
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
