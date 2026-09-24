<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Services\TexCompile\TexFormatClient;
use Throwable;

/**
 * G22.S15 — POST /tex/format. Riceve sorgente LaTeX (string), invia
 * a VPS /format-tex (latexindent.pl), ritorna formattato.
 */
final class TexFormatController
{
    public function format(Request $req): Response
    {
        if (!Auth::check()) {
            return Response::fail('auth_required', 401);
        }

        // JSON dichiarato, altrimenti il form, altrimenti un corpo che sembra
        // JSON: è la regola di Request::body() (ADR-034), che qui era
        // ricopiata a mano fino al 23/9/2026 (A-52).
        $body = $req->body();

        $source = (string)($body['source'] ?? '');
        if ($source === '' || \strlen($source) > 1024 * 1024) {
            return Response::fail('invalid_source_size', 400);
        }

        $endpoint = (string) Config::get('tex_compile.endpoint', '');
        $secret   = (string) Config::get('tex_compile.secret', '');
        if ($endpoint === '' || $secret === '') {
            return Response::fail('tex_compile_disabled', 503);
        }

        try {
            $client = new TexFormatClient(
                endpoint:       $endpoint,
                secret:         $secret,
                timeoutSeconds: 12,
                caBundle:       \App\Support\BundleCa::percorso() ?? '',
            );
            $r = $client->format($source);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
        if (!$r['ok']) {
            return Response::json([
                'ok' => false, 'error' => 'format_failed',
                'log' => $r['log'] ?? '',
            ], 422);
        }
        return Response::json([
            'ok'        => true,
            'formatted' => $r['formatted'] ?? '',
            'duration_ms' => $r['duration_ms'] ?? null,
        ]);
    }
}
