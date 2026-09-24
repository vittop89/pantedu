<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

/**
 * Phase 25.R follow-up — Pannello /admin/monitoring (super_admin only).
 *
 * Embed Grafana (e in futuro altri tool monitoring) tramite iframe.
 * L'iframe punta a /grafana/ che è dietro nginx auth_request → solo i
 * super_admin loggati a pantedu possono caricarlo (SSO automatico).
 */
final class AdminMonitoringController
{
    public function index(Request $req): Response
    {
        $view = View::default();
        $body = $view->render('admin/monitoring', [
            'user' => Auth::user() ?? ['username' => '-', 'role' => 'guest'],
        ]);
        return Response::html($view->render('layout/shell', [
            'title' => 'Monitoring — Admin',
            'body'  => $body,
        ]));
    }
}
