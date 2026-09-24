<?php

namespace App\Controllers;

use App\Core\AccessLogger;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

/**
 * Riceve beacon POST dal router SPA (fm-url-state.js) per ogni
 * navigazione client-side. Senza questo endpoint, l'AccessLogger
 * vedrebbe solo il primo full load: dopo lo SPA fetch è X-Partial
 * verso il controller della pagina e il middleware di log non coglie il
 * contesto di sessione dell'utente visitatore.
 */
final class AnalyticsController
{
    public function navBeacon(Request $req): Response
    {
        if (!Auth::check()) {
            return Response::json(['ok' => true, 'skipped' => 'anon']);
        }
        $url = (string)($req->post['url'] ?? '');
        if ($url === '' || strlen($url) > 2048) {
            return Response::fail('invalid_url', 400);
        }
        $path = parse_url($url, PHP_URL_PATH) ?: $url;

        $user = Auth::user();
        (new AccessLogger())->logAccess(
            $user['username'] ?? 'unknown',
            $user['role']     ?? 'guest',
            $path,
            'spa_nav'
        );
        return Response::ok();
    }
}
