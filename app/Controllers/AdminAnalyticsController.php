<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\AdminAnalyticsService;

/**
 * Admin Analytics — pagina HTML + endpoint JSON (Phase 13.5).
 *
 *   GET /admin/analytics                    → page HTML
 *   GET /api/admin/analytics                → snapshot completo
 *   GET /api/admin/analytics/teacher/{id}   → forTeacher() drill-down
 *   GET /api/admin/analytics/cross-search   → search material multi-teacher
 */
final class AdminAnalyticsController
{
    public function page(Request $req): Response
    {
        $view = View::default();
        $body = $view->render('admin/analytics', ['csrf' => Csrf::token()]);
        return Response::html($view->render('layout/shell', [
            'title' => 'Admin Analytics — Pantedu',
            'body'  => $body,
        ]));
    }

    public function snapshot(Request $req): Response
    {
        return Response::json(['ok' => true] + AdminAnalyticsService::default()->snapshot());
    }

    public function forTeacher(Request $req, array $params): Response
    {
        $tid = (int)($params['id'] ?? 0);
        if ($tid <= 0) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        return Response::json(['ok' => true] + AdminAnalyticsService::default()->forTeacher($tid));
    }

    public function crossSearch(Request $req): Response
    {
        $q     = trim((string)($req->query['q']     ?? ''));
        $type  = trim((string)($req->query['type']  ?? '')) ?: null;
        $limit = max(1, min(500, (int)($req->query['limit'] ?? 100)));
        return Response::json(AdminAnalyticsService::default()->crossTeacherSearch($q, $type, $limit));
    }
}
