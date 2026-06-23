<?php

namespace App\Middleware;

use App\Core\Auth;
use App\Core\PrivilegedAccessLogger;
use App\Core\Request;
use App\Core\Response;
use App\Services\AclPolicy;

/**
 * Phase 18 — Audit obbligatorio per operazioni super-admin.
 *
 * Applicato alle route che toccano content cross-teacher (admin/* read
 * operations). Logga in `privileged_access_log` prima di invocare il
 * next handler. Se il caller NON è super-admin, lascia passare senza log
 * (route può essere admin-only via RoleMiddleware a monte, che gate a
 * parte).
 */
final class SuperAdminAuditMiddleware
{
    public function handle(Request $req, callable $next, string $action = 'admin_read', string $resourceType = 'generic'): Response
    {
        if (Auth::check() && AclPolicy::isSuperAdmin()) {
            $resId = $req->path ?? ($req->server['REQUEST_URI'] ?? null);
            PrivilegedAccessLogger::log(
                action:       $action,
                resourceType: $resourceType,
                resourceId:   $resId,
                reason:       'super_admin_operation',
                outcome:      'ok',
            );
        }
        return $next($req);
    }
}
