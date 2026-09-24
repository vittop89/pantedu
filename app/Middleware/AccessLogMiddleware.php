<?php

namespace App\Middleware;

use App\Core\AccessLogger;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

/**
 * Statistiche di navigazione su `access_log.json`: quante visite, di chi, su
 * quale materia. File troncato a mille voci, e per contare va bene.
 *
 * NON e' il registro delle operazioni. Quello e' `audit_activity_log`, scritto
 * da App\Services\Audit\ActivityLogger e applicato globalmente in Kernel —
 * globalmente perche' appenderlo alle sole rotte che oggi hanno 'log'
 * significherebbe scoprire fra sei mesi che le rotte pubbliche (login,
 * registrazione, consenso genitoriale) non erano coperte. Vedi migration 098.
 */
final class AccessLogMiddleware
{
    public function handle(Request $req, callable $next): Response
    {
        $response = $next($req);

        if (Auth::check() && $response->status < 400) {
            (new AccessLogger())->logAccess(
                Auth::user()['username'] ?? 'unknown',
                Auth::role(),
                $req->server['REQUEST_URI'] ?? $req->path,
                'access'
            );
        }
        return $response;
    }
}
