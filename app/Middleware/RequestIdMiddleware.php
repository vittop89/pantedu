<?php

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * Phase 25.E4 — Request ID correlation middleware.
 *
 * Genera (o passa-attraverso) un X-Request-ID per ogni richiesta:
 *   - Se il client manda `X-Request-ID` header → usalo (max 64 char alphanum)
 *   - Altrimenti genera UUID v4 inline
 *
 * Salva il request_id in $_SERVER['X_REQUEST_ID'] per essere letto da:
 *   - JsonLogger (correlation field 'rid' negli eventi JSON log)
 *   - PrivilegedAccessLogger (correlation con accessi admin)
 *   - Response header `X-Request-ID` (echo-back per debugging client)
 *
 * Permette tracing end-to-end: 1 click utente → N log entries con stesso rid.
 *
 * Applicato globalmente in Kernel pipeline (prima di tutti gli altri).
 */
final class RequestIdMiddleware
{
    private const HEADER_KEY = 'HTTP_X_REQUEST_ID';
    private const SERVER_KEY = 'X_REQUEST_ID';
    private const MAX_LEN = 64;

    public function handle(Request $req, callable $next): Response
    {
        $rid = $this->extract($req) ?? $this->generate();
        $_SERVER[self::SERVER_KEY] = $rid;

        $response = $next($req);
        if ($response instanceof Response) {
            $response->headers['X-Request-ID'] = $rid;
        }
        return $response;
    }

    private function extract(Request $req): ?string
    {
        $headers = $req->server ?? [];
        $raw = $headers[self::HEADER_KEY] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        // Sanitize: alphanum + dash only, max 64 char
        $clean = preg_replace('/[^A-Za-z0-9-]/', '', $raw);
        if ($clean === null || $clean === '') {
            return null;
        }
        return substr($clean, 0, self::MAX_LEN);
    }

    /** Generate UUID v4 inline (no external dep). */
    private function generate(): string
    {
        $b = random_bytes(16);
        // Set version 4 + variant 10
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $hex = bin2hex($b);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
             . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    /** Helper statico per leggere il rid dal contesto attuale (logger). */
    public static function currentRequestId(): ?string
    {
        return $_SERVER[self::SERVER_KEY] ?? null;
    }
}
