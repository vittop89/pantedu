<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Support\BodyExtractor;

/**
 * Phase 25.E17 — log/* legacy endpoints serving.
 *
 * Sostituisce LegacyController per i 5 path catch-all sotto /log/*:
 *
 *   GET|POST /log/admin/{path*}
 *   GET|POST /log/auth/{path*}
 *   GET|POST /log/logging/{path*}
 *   GET|POST /log/logout/{path*}
 *   GET|POST /log/security/{path*}
 *
 * I file PHP/HTML in `log/{section}/` sono entry point legacy ancora
 * agganciati al frontend (logout widget, login flow alternativo, log viewer
 * per admin). Migrazione individuale → backlog Phase 26+ (richiede analisi
 * di ogni file PHP).
 *
 * Whitelist: solo le 5 sezioni enumerate sono servibili. Path traversal
 * bloccato via realpath + prefix check + null-byte/double-dot strip.
 *
 * Comportamento:
 *   - SPA partial (X-Partial: 1) su HTML/PHP → buffer + extract <body>
 *   - Static asset (.js/.css/.png/.json) → serve diretto, asset cache headers
 *   - Default → passthrough Response::file
 */
final class LogServeController
{
    private const ALLOWED_SECTIONS = ['admin', 'auth', 'logging', 'logout', 'security'];

    private const STATIC_EXTENSIONS = [
        'js', 'mjs', 'css', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp',
        'woff', 'woff2', 'ttf', 'otf', 'json', 'map',
    ];
    private const HTML_LIKE_EXTENSIONS = ['php', 'html', 'htm'];
    private const STATIC_MAX_AGE = 1800;

    public function show(Request $req, array $params): Response
    {
        unset($params);
        $relative = ltrim($req->path, '/');
        $relative = str_replace(["\0", '..'], '', $relative);
        if ($relative === '' || !str_starts_with($relative, 'log/')) {
            return $this->notFound();
        }

        // Verifica sezione whitelisted (log/<section>/...)
        $parts = explode('/', $relative, 3);
        $section = $parts[1] ?? '';
        if (!\in_array($section, self::ALLOWED_SECTIONS, true)) {
            return $this->notFound();
        }

        $base = (string)Config::get('app.paths.legacy');
        $absoluteBase = realpath($base);
        if ($absoluteBase === false) {
            return $this->notFound();
        }
        $target = realpath($absoluteBase . DIRECTORY_SEPARATOR . $relative);
        if ($target === false || !str_starts_with($target, $absoluteBase)) {
            return $this->notFound();
        }
        if (is_dir($target)) {
            foreach (['index.php', 'index.html'] as $idx) {
                $candidate = $target . DIRECTORY_SEPARATOR . $idx;
                if (is_file($candidate)) {
                    $target = $candidate;
                    break;
                }
            }
            if (is_dir($target)) {
                return $this->notFound();
            }
        }
        if (!is_file($target)) {
            return $this->notFound();
        }

        return $this->serveFile($req, $target);
    }

    private function serveFile(Request $req, string $path): Response
    {
        $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        $wantsPartial = ($req->headers['x-partial'] ?? $req->headers['X-Partial'] ?? '') === '1';

        if ($wantsPartial && \in_array($ext, self::HTML_LIKE_EXTENSIONS, true)) {
            ob_start();
            if ($ext === 'php') {
                chdir(dirname($path));
                require $path;
            } else {
                readfile($path);
            }
            $body = BodyExtractor::extract((string)ob_get_clean());
            return new Response(
                body: $body,
                status: 200,
                headers: ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        $response = Response::file($path);
        if (\in_array($ext, self::STATIC_EXTENSIONS, true)) {
            $stat = @stat($path);
            if ($stat !== false) {
                $token = \sprintf('%d-%d', $stat['mtime'], $stat['size']);
                $response = $response->withETag($token, self::STATIC_MAX_AGE);
            }
        }
        return $response;
    }

    private function notFound(): Response
    {
        return Response::html('<h1>404 Not Found</h1>', 404);
    }
}
