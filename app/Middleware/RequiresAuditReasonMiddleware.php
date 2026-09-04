<?php

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Config;
use App\Core\PrivilegedAccessLogger;
use App\Core\Request;
use App\Core\Response;
use App\Services\AclPolicy;

/**
 * Phase 25.B4 — Middleware "audit reason required" per operazioni admin
 * che mutano dati cross-teacher.
 *
 * Comportamento:
 *   - Verifica presenza di una `audit_reason` come:
 *       - header HTTP `X-Audit-Reason: ...`
 *       - body POST/PUT field `_audit_reason: ...`
 *   - Min 10 caratteri (no "test", "ok", "...") per forzare motivazione
 *     significativa.
 *   - Logga in `privileged_access_log` con reason effettiva (non hardcoded
 *     come `SuperAdminAuditMiddleware`).
 *
 * Modalità (Config `audit.reason_mode` o env `AUDIT_REASON_MODE`):
 *   - `enforce`  → 400 se reason mancante/breve (target produzione)
 *   - `warn`     → log warning + lascia passare (default soft-launch)
 *   - `disabled` → middleware no-op (per fase migrazione client legacy)
 *
 * Applicato in routes a:
 *   - tutti gli endpoint /api/admin/**  POST/DELETE
 *   - tutti gli endpoint che toccano institutional_override / visibility-scope
 *
 * NB: SuperAdminAuditMiddleware esiste già ma logga reason hardcoded
 * `'super_admin_operation'`. Questo middleware è complementare: forza che
 * il caller fornisca una motivazione concreta (audit trail con context).
 */
final class RequiresAuditReasonMiddleware
{
    public const MODE_ENFORCE  = 'enforce';
    public const MODE_WARN     = 'warn';
    public const MODE_DISABLED = 'disabled';

    private const MIN_REASON_LENGTH = 10;
    private const MAX_REASON_LENGTH = 255;

    public function handle(Request $req, callable $next, string $action = 'admin_mutation', string $resourceType = 'generic'): Response
    {
        // Skippa per non-super-admin: gating role gestito altrove (RoleMiddleware).
        if (!Auth::check() || !AclPolicy::isSuperAdmin()) {
            return $next($req);
        }

        $mode = $this->mode();
        if ($mode === self::MODE_DISABLED) {
            return $next($req);
        }

        $reason = $this->extractReason($req);
        $valid = $reason !== null
            && strlen($reason) >= self::MIN_REASON_LENGTH
            && strlen($reason) <= self::MAX_REASON_LENGTH;

        if (!$valid) {
            // Sempre log (anche in warn mode) per audit trail completo.
            PrivilegedAccessLogger::log(
                action:       $action,
                resourceType: $resourceType,
                resourceId:   $req->path ?? null,
                reason:       'MISSING_OR_INVALID_AUDIT_REASON',
                outcome:      $mode === self::MODE_ENFORCE ? 'denied' : 'warn',
            );
            if ($mode === self::MODE_ENFORCE) {
                return Response::json([
                    'error'   => 'audit_reason_required',
                    'message' => sprintf(
                        'Provide X-Audit-Reason header or _audit_reason field (%d-%d chars).',
                        self::MIN_REASON_LENGTH,
                        self::MAX_REASON_LENGTH
                    ),
                ], 400);
            }
            // warn mode: passa, ma con audit log
            return $next($req);
        }

        // Reason valida: log + propaga al PrivilegedAccessLogger
        PrivilegedAccessLogger::log(
            action:       $action,
            resourceType: $resourceType,
            resourceId:   $req->path ?? null,
            reason:       $reason,
            outcome:      'ok',
        );
        return $next($req);
    }

    /**
     * Modalità configurata via Config('audit.reason_mode') o env. Default 'warn'
     * per soft-launch: i client moderni si adattano gradualmente.
     */
    private function mode(): string
    {
        $env = getenv('AUDIT_REASON_MODE');
        $cfg = Config::get('audit.reason_mode');
        $mode = strtolower((string)($env ?: $cfg ?: self::MODE_WARN));
        return in_array($mode, [self::MODE_ENFORCE, self::MODE_WARN, self::MODE_DISABLED], true)
            ? $mode
            : self::MODE_WARN;
    }

    /**
     * Estrae reason da header HTTP o body POST/PUT. Trim + sanitize.
     */
    private function extractReason(Request $req): ?string
    {
        // Header X-Audit-Reason (preferred)
        $headers = $req->server ?? [];
        $headerKey = 'HTTP_X_AUDIT_REASON';
        if (isset($headers[$headerKey]) && is_string($headers[$headerKey])) {
            $r = trim($headers[$headerKey]);
            if ($r !== '') {
                return $r;
            }
        }

        // Body field _audit_reason (per form submission legacy)
        if (isset($req->post['_audit_reason']) && is_string($req->post['_audit_reason'])) {
            $r = trim($req->post['_audit_reason']);
            if ($r !== '') {
                return $r;
            }
        }

        return null;
    }
}
