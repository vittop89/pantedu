<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\PrivilegedAccessLogger;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\InfrastructureMonitorService;

/**
 * Phase 14 — dashboard tecnica super-admin.
 *
 *   GET /admin/infrastructure            (HTML)
 *   GET /api/admin/infrastructure.json   (JSON)
 *
 * Espone SOLO metriche operative (spazio DB/FS, conteggi aggregati,
 * stato backup, oggetti storage). Nessun dato personale studente.
 * Ogni accesso viene loggato con motivo in privileged_access_log.
 */
final class AdminInfrastructureController
{
    private InfrastructureMonitorService $svc;

    public function __construct(?InfrastructureMonitorService $svc = null)
    {
        $this->svc = $svc ?? new InfrastructureMonitorService();
    }

    public function page(Request $req): Response
    {
        if (!Auth::isSuperAdmin()) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $reason = trim((string)($req->query['reason'] ?? 'dashboard_view'));
        PrivilegedAccessLogger::log('read', 'infra_metrics', null, $reason);

        $snapshot = $this->svc->snapshot();
        $view = View::default();
        $body = $view->render('admin/infrastructure', ['snapshot' => $snapshot]);
        return Response::html($view->render('layout/shell', [
            'title' => 'Infrastruttura — Super-Admin',
            'body'  => $body,
            'modal' => false,
        ]));
    }

    public function snapshotJson(Request $req): Response
    {
        if (!Auth::isSuperAdmin()) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $reason = trim((string)($req->query['reason'] ?? ''));
        if ($reason === '') {
            PrivilegedAccessLogger::log('read', 'infra_metrics', null, '', 'denied');
            return Response::json(['error' => 'reason_required'], 400);
        }
        PrivilegedAccessLogger::log('read', 'infra_metrics', null, $reason);
        return Response::json(['ok' => true, 'snapshot' => $this->svc->snapshot()]);
    }
}
