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
            return Response::json(['ok' => false, 'error' => 'auth_required'], 401);
        }

        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $req->headers['content-type'] ?? '');
        $body = [];
        if (str_contains($contentType, 'application/json')) {
            $raw = (string) @file_get_contents('php://input');
            $decoded = json_decode($raw, true);
            if (\is_array($decoded)) {
                $body = $decoded;
            }
        } else {
            $body = $req->post;
            if (empty($body)) {
                $raw = (string) @file_get_contents('php://input');
                if ($raw !== '' && ($raw[0] === '{' || $raw[0] === '[')) {
                    $decoded = json_decode($raw, true);
                    if (\is_array($decoded)) {
                        $body = $decoded;
                    }
                }
            }
        }

        $source = (string)($body['source'] ?? '');
        if ($source === '' || \strlen($source) > 1024 * 1024) {
            return Response::json(['ok' => false, 'error' => 'invalid_source_size'], 400);
        }

        $endpoint = (string) Config::get('tex_compile.endpoint', '');
        $secret   = (string) Config::get('tex_compile.secret', '');
        if ($endpoint === '' || $secret === '') {
            return Response::json(['ok' => false, 'error' => 'tex_compile_disabled'], 503);
        }

        try {
            $client = new TexFormatClient(
                endpoint:       $endpoint,
                secret:         $secret,
                timeoutSeconds: 12,
                caBundle:       (string) Config::get('tex_compile.ca_bundle', ''),
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
