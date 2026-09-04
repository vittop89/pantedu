<?php

namespace App\Middleware;

use App\Core\Auth;
use App\Core\PrivilegedAccessLogger;
use App\Core\Request;
use App\Core\Response;
use App\Services\AclPolicy;

/**
 * Le *letture* privilegiate del super-admin, a registro.
 *
 * ERA CODICE MORTO (fino al 2026-09-02)
 *
 * Registrato in Kernel come `sadmin_audit`, non compariva in nessuna rotta.
 * Il risultato si vedeva in produzione: `privileged_access_log` conteneva
 * otto sole righe di tipo `read`, tutte da `/admin/infrastructure`, cioe'
 * dall'unico controller che chiamava il logger a mano. Aprire l'elenco delle
 * richieste GDPR, il registro dei data breach o i log altrui non lasciava
 * traccia, mentre l'informativa dichiarava il contrario.
 *
 * COSA COPRE, E COSA NO
 *
 * Solo GET/HEAD, cioe' le letture. Le mutazioni hanno gia' due registri:
 * `audit_reason` (con la motivazione) e `audit_activity_log` (globale, in
 * Kernel). Loggarle anche qui produrrebbe righe doppie che dicono meno.
 *
 * Se il chiamante non e' super-admin non scrive nulla: il gate del ruolo e'
 * di RoleMiddleware / SuperAdminRequiredMiddleware, non suo.
 */
final class SuperAdminAuditMiddleware
{
    private const READ_METHODS = ['GET', 'HEAD'];

    public function handle(Request $req, callable $next, string $action = 'admin_read', string $resourceType = 'generic'): Response
    {
        if (
            \in_array(strtoupper($req->method), self::READ_METHODS, true)
            && Auth::check()
            && AclPolicy::isSuperAdmin()
        ) {
            $resId = $req->path ?? ($req->server['REQUEST_URI'] ?? null);
            PrivilegedAccessLogger::log(
                action:       $action,
                resourceType: $resourceType,
                resourceId:   $resId,
                reason:       'super_admin_read',
                outcome:      'ok',
            );
        }
        return $next($req);
    }
}
