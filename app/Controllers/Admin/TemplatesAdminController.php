<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

/**
 * Phase G13.5 — Admin Templates entrypoint (super_admin only).
 * Aggrega RisDoc + Verifiche in un'unica pagina con tabs.
 *
 * Phase 25.H — migrato a layout/shell.php uniforme (era layout/app.php
 * legacy con sidebar). Stesso pattern di AdminToolsController +
 * AdminAnalyticsController + WafAdminController.
 */
final class TemplatesAdminController
{
    public function page(Request $req): Response
    {
        if (!Auth::check() || !Auth::isSuperAdmin()) {
            return Response::html('<h1>403</h1><p>Solo super-admin.</p>', 403);
        }
        ob_start();
        require __DIR__ . '/../../../views/admin/templates.php';
        $body = (string)ob_get_clean();

        $viewer = View::default();
        $html = $viewer->render('layout/shell', [
            'title' => 'Templates — Pantedu',
            'body'  => $body,
        ]);
        return Response::html($html);
    }
}
