<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\Tikz\TikzRenderException;
use App\Services\Tikz\TikzRenderService;
use Throwable;

/**
 * G22.S15 — Endpoint cache-or-render per blocchi TikZ.
 *
 *   GET  /tikz/render?hash=...&scope=public|teacher
 *      → cerca SVG in cache, ritorna 200 + image/svg+xml o 404
 *
 *   POST /tikz/render
 *      body: {tikz: "...", scope: "public"|"teacher",
 *             libraries: [...], pgfplots_libraries: [...],
 *             extra_packages: [...], border: "2pt"}
 *      → calcola hash, lookup, se miss compila via VPS, ritorna SVG
 *
 * Scope rules:
 *   public:
 *      - WRITE consentito SOLO ad admin (modelli_tikz.php / templates).
 *      - READ aperto (no auth richiesta).
 *   teacher:
 *      - usa Auth::user()['id'] come teacherId; un docente puo' solo
 *        scrivere/leggere la propria cache (no cross-teacher access).
 *      - SVG cifrato envelope AES-256-GCM (TeacherCryptoService).
 *
 * Disabilitazione (rollback): se TEX_COMPILE_ENDPOINT vuoto, il POST
 * ritorna 503 `tex_compile_disabled`. Il GET continua a funzionare per
 * cache pre-esistente (lookup non richiede VPS).
 */
final class TikzRenderController
{
    public function __construct(private ?TikzRenderService $service = null)
    {
        $this->service ??= TikzRenderService::createDefault();
    }

    /**
     * GET /tikz/render?hash=...&scope=...
     * Solo cache lookup (no compile). 404 se miss.
     */
    public function lookup(Request $req): Response
    {
        $hash  = (string)($req->query['hash']  ?? '');
        $scope = (string)($req->query['scope'] ?? TikzRenderService::SCOPE_PUBLIC);

        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
            return Response::json(['ok' => false, 'error' => 'invalid_hash'], 400);
        }
        if (!\in_array($scope, [TikzRenderService::SCOPE_PUBLIC, TikzRenderService::SCOPE_TEACHER], true)) {
            return Response::json(['ok' => false, 'error' => 'invalid_scope'], 400);
        }

        $teacherId = 0;
        if ($scope === TikzRenderService::SCOPE_TEACHER) {
            $teacherId = $this->currentTeacherId();
            if ($teacherId === 0) {
                return Response::json(['ok' => false, 'error' => 'auth_required'], 401);
            }
        }

        if ($this->service === null) {
            // Service non configurato (env mancante). La cache pre-esistente
            // potrebbe non essere disponibile; segnaliamo come miss.
            return Response::json(['ok' => false, 'error' => 'service_unavailable'], 503);
        }

        try {
            $svg = $this->service->lookup($scope, $teacherId, $hash);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        if ($svg === null) {
            // 2026-05-24 — cache miss = 204 No Content (NON 404). 404 era
            // semanticamente corretto ma Chrome devtools logga 4xx in rosso,
            // causando noise inutile su pagine con molti TikZ non ancora
            // cached (first-visit). 204 e' 2xx → silenzioso, e il client
            // tikz-render-client.js gia' tratta qualsiasi non-200 come miss
            // → POST follow-up identico.
            return new Response('', 204, [
                'X-Tikz-Cache' => 'miss',
                'Cache-Control' => 'no-store',
            ]);
        }

        return self::svgResponse($svg, 'cache');
    }

    /**
     * POST /tikz/render — compile-on-demand con cache.
     */
    public function render(Request $req): Response
    {
        if ($this->service === null) {
            return Response::json([
                'ok' => false,
                'error' => 'tex_compile_disabled',
                'hint'  => 'Configura TEX_COMPILE_ENDPOINT in .env',
            ], 503);
        }

        // Il client JS POSTa application/json. $req->post (alias $_POST) e'
        // popolato solo per form-urlencoded; per JSON va letto da php://input.
        // Pattern allineato con altri controller (es. AdminPrintController).
        // Nota: PHP mette `Content-Type` in $_SERVER['CONTENT_TYPE'] (NON in
        // HTTP_CONTENT_TYPE) → $req->headers['content-type'] e' vuoto. Letto
        // direttamente. In sviluppo prefer leggere sempre php://input se
        // presente (XHR/fetch JSON sono il caso di gran lunga prevalente
        // su questo endpoint).
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
            // Heuristica: se $_POST e' vuoto ma php://input ha JSON, parse comunque
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
        $tikz = (string)($body['tikz']  ?? '');
        $scope = (string)($body['scope'] ?? TikzRenderService::SCOPE_PUBLIC);

        if ($tikz === '' || \strlen($tikz) > TikzRenderService::MAX_SOURCE_BYTES) {
            return Response::json(['ok' => false, 'error' => 'tikz_size_invalid'], 400);
        }
        if (!\in_array($scope, [TikzRenderService::SCOPE_PUBLIC, TikzRenderService::SCOPE_TEACHER], true)) {
            return Response::json(['ok' => false, 'error' => 'invalid_scope'], 400);
        }

        // Authorization per scope.
        // - SCOPE_TEACHER: solo il docente proprietario (controller decifra
        //   con la sua KEK; nessun altro puo' decryptare).
        // - SCOPE_PUBLIC: chiunque autenticato puo' compilare. La cache e'
        //   content-addressable (sha256) → no enumerazione possibile, no PII
        //   per design (niente dato personale dovrebbe finire qui — se
        //   contiene PII l'editor JS deve passare scope='teacher'). Rate
        //   limiting + middleware route gia' protegge da abuse. La route
        //   stessa e' in 'role:student' group → utente non auth gia' bloccato.
        $teacherId = 0;
        if ($scope === TikzRenderService::SCOPE_TEACHER) {
            $teacherId = $this->currentTeacherId();
            if ($teacherId === 0) {
                return Response::json(['ok' => false, 'error' => 'auth_required'], 401);
            }
        } elseif (!Auth::check()) {
            return Response::json(['ok' => false, 'error' => 'auth_required'], 401);
        }

        $opts = [
            'libraries'          => self::arr($body['libraries']           ?? []),
            'pgfplots_libraries' => self::arr($body['pgfplots_libraries']  ?? []),
            'extra_packages'     => self::arr($body['extra_packages']      ?? []),
            'border'             => self::strOrDefault($body['border']     ?? '2pt', '2pt'),
        ];

        try {
            $r = $this->service->getOrRender($tikz, $scope, $teacherId, $opts);
        } catch (TikzRenderException $e) {
            return Response::json([
                'ok'    => false,
                'error' => 'compile_failed',
                'log'   => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            return Response::json([
                'ok'    => false,
                'error' => $e->getMessage(),
            ], 400);
        }

        return self::svgResponse($r['svg'], $r['source'], $r['hash'], $r['duration_ms'] ?? null);
    }

    // ─────────────────────── helpers ───────────────────────

    private function currentTeacherId(): int
    {
        if (!Auth::check()) {
            return 0;
        }
        $u = Auth::user();
        $tid = (int)($u['id'] ?? 0);
        // Solo i docenti possono usare scope=teacher (lo studente legge solo
        // public; l'admin scrive solo public). I collaboratori usano l'id
        // del docente proprietario via flow alternativo (TODO collab).
        if (!Auth::hasRole('teacher')) {
            return 0;
        }
        return $tid > 0 ? $tid : 0;
    }

    private static function svgResponse(string $svg, string $source, string $hash = '', ?int $durationMs = null): Response
    {
        $headers = [
            'Content-Type'      => 'image/svg+xml; charset=UTF-8',
            'X-Tikz-Source'     => $source,             // 'cache' | 'compile'
            'Cache-Control'     => 'private, max-age=300, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if ($hash !== '') {
            $headers['X-Tikz-Hash'] = $hash;
        }
        if ($durationMs !== null) {
            $headers['X-Tikz-Compile-Ms'] = (string)$durationMs;
        }
        $resp = new Response($svg, 200, $headers);
        // ETag basato sul SVG content (non source hash): se renderiamo lo
        // stesso source con pipeline diversa (post-fix, version upgrade),
        // l'ETag cambia → browser refetcha invece di servire cache stale.
        $contentTag = $hash !== ''
            ? $hash . ':' . substr(hash('sha256', $svg), 0, 16)
            : substr(hash('sha256', $svg), 0, 32);
        $resp->withETag($contentTag);
        return $resp;
    }

    /** @return list<string> */
    private static function arr(mixed $v): array
    {
        if (!\is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $item) {
            if (\is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }
        return $out;
    }

    private static function strOrDefault(mixed $v, string $default): string
    {
        return \is_string($v) && $v !== '' ? $v : $default;
    }
}
