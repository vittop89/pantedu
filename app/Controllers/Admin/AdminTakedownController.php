<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\Gdpr\TakedownRequestService;
use InvalidArgumentException;

/**
 * Phase 25.P — UI admin per gestione coda Notice & Takedown.
 *
 * Routes:
 *   GET  /admin/takedown               → lista coda
 *   GET  /admin/takedown/{id}          → dettaglio
 *   POST /admin/takedown/{id}/action   → applica azione (rimuovi/sospendi/respingi)
 *
 * Protezione: middleware role:super_admin (solo Vittorio inizialmente).
 *
 * Phase 25.R.3.1 — refactor: render via View+layout/shell.php anziché HTML
 * hardcoded standalone. Layout coerente con altre pagine /admin/* (topbar,
 * breadcrumb, dark theme, role badge).
 */
final class AdminTakedownController
{
    private TakedownRequestService $service;

    public function __construct(?TakedownRequestService $service = null)
    {
        $this->service = $service ?? new TakedownRequestService();
    }

    /** GET /admin/takedown — lista coda. */
    public function index(): Response
    {
        $statusFilter = $_GET['status'] ?? null;
        $statusFilter = is_string($statusFilter) ? $statusFilter : null;
        $pending = $this->service->listPending($statusFilter);

        $view = View::default();
        $body = $view->render('admin/takedown_index', [
            'pending'      => $pending,
            'statusFilter' => $statusFilter,
            'user'         => Auth::user() ?? ['username' => '-', 'role' => 'guest'],
        ]);
        return Response::html($view->render('layout/shell', [
            'title' => 'Takedown — Admin',
            'body'  => $body,
        ]));
    }

    /** GET /admin/takedown/{id} — dettaglio. */
    public function show(Request $req, array $params): Response
    {
        $id = (int)($params['id'] ?? 0);
        $request = $this->service->get($id);
        if ($request === null) {
            return Response::html('<h1>404 — Not Found</h1>', 404);
        }

        $view = View::default();
        $body = $view->render('admin/takedown_show', [
            'request' => $request,
            'csrf'    => Csrf::token(),
            'user'    => Auth::user() ?? ['username' => '-', 'role' => 'guest'],
            'id'      => $id,
        ]);
        return Response::html($view->render('layout/shell', [
            'title' => "Takedown #{$id} — Admin",
            'body'  => $body,
        ]));
    }

    /** POST /admin/takedown/{id}/action — applica azione. */
    public function action(Request $req, array $params): Response
    {
        $id     = (int)($params['id'] ?? 0);
        $action = (string) ($req->post['action'] ?? '');
        $notes  = (string) ($req->post['notes']  ?? '');
        $userId = (int) (Auth::user()['id'] ?? 0);

        if (! in_array($action, TakedownRequestService::ACTIONS, true)) {
            return Response::redirect("/admin/takedown/{$id}?error=invalid_action");
        }
        if (trim($notes) === '') {
            return Response::redirect("/admin/takedown/{$id}?error=notes_required");
        }

        try {
            $newStatus = $action === 'dismissed' ? 'rejected' : 'actioned';
            $this->service->updateStatus($id, $newStatus, $action, $notes, $userId);
        } catch (InvalidArgumentException $e) {
            return Response::redirect("/admin/takedown/{$id}?error=" . urlencode($e->getMessage()));
        }

        return Response::redirect("/admin/takedown/{$id}?ok=1");
    }
}
