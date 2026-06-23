<?php

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

/**
 * Admin Tools — pagina HTML unificata con tabs interattive (Phase 13).
 *
 * Sostituisce le pagine legacy log/admin/user_manager.php +
 * log/security/monitoring/dashboard.php + log/security/alerts/*
 * con un'unica UI moderna SPA-style basata su fetch JSON.
 *
 * Tabs:
 *   - Hash         → /admin/generate-hash
 *   - Users        → /api/admin/users + setActive/setRole/delete
 *   - Registrations→ /admin/registrations + approve/reject
 *   - Security     → /api/admin/security/blocked-* + unblock-*
 *   - Logs         → /admin/access-log + recent + filter
 *   - Notifications→ /api/admin/notifications (live)
 *
 * Tabs interagiscono: es. blocco IP da Security crea registro in
 * Logs; approvazione registration aggiorna counter notifications;
 * cambio ruolo user notifica banner.
 */
final class AdminToolsController
{
    public function page(Request $req): Response
    {
        $view = View::default();
        $body = $view->render('admin/tools', [
            'csrf' => Csrf::token(),
        ]);
        return Response::html($view->render('layout/shell', [
            'title' => 'Admin Tools — Pantedu',
            'body'  => $body,
        ]));
    }
}
