<?php

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * Phase 25.E4 — Request ID correlation.
 *
 * Genera (o passa-attraverso) un X-Request-ID per ogni richiesta:
 *   - Se il client manda `X-Request-ID` valido (alfanumerico e trattini,
 *     max 64 caratteri) → usalo
 *   - Altrimenti genera UUID v4 inline
 *
 * Salva il request_id in $_SERVER['X_REQUEST_ID'] per essere letto da:
 *   - PrivilegedAccessLogger (correlation con accessi admin)
 *   - Telemetry (trace_id)
 *   - Response header `X-Request-ID` (echo-back per debugging client,
 *     emesso da Kernel::applySecurityHeaders)
 *
 * Permette tracing end-to-end: 1 click utente → N log entries con stesso rid.
 *
 * Non sta nella pipeline dei middleware: Kernel::handle() chiama ensure()
 * come prima cosa, cosi' il rid esiste anche per gli errori precedenti al
 * match della rotta. handle() resta per chi volesse usarlo come middleware
 * ordinario; fino al 2026-09-04 il Kernel duplicava questa logica inline e
 * l'alias di rotta 'request_id' non era usato da nessuna rotta.
 */
final class RequestIdMiddleware
{
    private const HEADER_KEY = 'HTTP_X_REQUEST_ID';
    private const SERVER_KEY = 'X_REQUEST_ID';
    private const VALID = '/^[A-Za-z0-9-]{1,64}$/';

    public function handle(Request $req, callable $next): Response
    {
        $rid = self::ensure($req);

        $response = $next($req);
        if ($response instanceof Response) {
            $response->headers['X-Request-ID'] = $rid;
        }
        return $response;
    }

    /**
     * Imposta $_SERVER['X_REQUEST_ID'] se manca e lo ritorna. Idempotente:
     * una seconda chiamata nella stessa richiesta restituisce lo stesso id.
     */
    public static function ensure(Request $req): string
    {
        $current = $_SERVER[self::SERVER_KEY] ?? '';
        if (is_string($current) && $current !== '') {
            return $current;
        }
        $rid = $req->server[self::HEADER_KEY] ?? '';
        if (!is_string($rid) || !preg_match(self::VALID, $rid)) {
            $rid = self::generate();
        }
        $_SERVER[self::SERVER_KEY] = $rid;
        return $rid;
    }

    /** Generate UUID v4 inline (no external dep). */
    private static function generate(): string
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
