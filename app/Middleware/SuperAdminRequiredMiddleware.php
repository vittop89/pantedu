<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\Risdoc\Permission;

/**
 * G22.S26 — Gate "super-admin only" applicabile via route middleware.
 *
 * Sostituisce il pattern `if (!Permission::canManageAdmin()) { return 403; }`
 * inline ripetuto in ogni handler admin. Una sola source-of-truth per la
 * risposta 403 + comportamento coerente JSON vs HTML.
 *
 * Uso:
 *   $r->group(['middleware' => ['super_admin_required']], function (Router $rr) {
 *       $rr->get('/admin/...', [Controller::class, 'method']);
 *   });
 */
final class SuperAdminRequiredMiddleware
{
    public function handle(Request $req, callable $next): Response
    {
        if (Permission::canManageAdmin()) {
            return $next($req);
        }
        if ($req->wantsJson()) {
            return Response::json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $view = View::default();
        $body = $view->render('errors/generic', [
            'code'    => 403,
            'title'   => 'Accesso negato',
            'message' => 'Questa risorsa è riservata ai super-admin.',
            'icon'    => '🚫',
        ]);
        return Response::html($view->render('layout/shell', [
            'title' => '403 — Forbidden',
            'body'  => $body,
        ]), 403);
    }
}
