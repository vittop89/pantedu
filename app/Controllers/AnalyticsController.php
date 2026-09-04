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
 * verso LegacyController e il middleware di log non coglie il
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
            return Response::json(['ok' => false, 'error' => 'invalid_url'], 400);
        }
        $path = parse_url($url, PHP_URL_PATH) ?: $url;

        $user = Auth::user();
        (new AccessLogger())->logAccess(
            $user['username'] ?? 'unknown',
            $user['role']     ?? 'guest',
            $path,
            'spa_nav'
        );
        return Response::json(['ok' => true]);
    }

    /**
     * Web Vitals RUM beacon — Phase Roadmap (perf monitoring).
     *
     * Riceve metric da js/modules/perf/web-vitals.js:
     *   { name, value, rating, id, navigationType, url, viewport,
     *     connection, rtt, downlink }
     *
     * Storage: append-only NDJSON in logs/web-vitals/YYYY-MM-DD.ndjson.
     * Rotation: log_rotate.php gestira' rotation 30gg (TTL).
     * Aggregazione: dashboard Grafana consuma via promtail (Loki).
     *
     * Privacy: nessun user-id, IP hashed, UA truncated. GDPR-safe.
     */
    public function webVitals(Request $req): Response
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '' || strlen($raw) > 2048) {
            return Response::json(['ok' => false], 400);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return Response::json(['ok' => false], 400);
        }

        $name = (string)($data['name'] ?? '');
        $allowedNames = ['CLS', 'FCP', 'FID', 'INP', 'LCP', 'TTFB'];
        if (!in_array($name, $allowedNames, true)) {
            return Response::json(['ok' => false], 400);
        }

        $value = (float)($data['value'] ?? 0);
        if ($value < 0 || $value > 60000) {
            return Response::json(['ok' => false], 400);
        }

        $entry = [
            't'    => date('c'),
            'name' => $name,
            'val'  => $value,
            'rate' => (string)($data['rating'] ?? ''),
            'url'  => self::sanitizePath((string)($data['url'] ?? '/')),
            'vp'   => self::sanitizeViewport((string)($data['viewport'] ?? '')),
            'cn'   => self::sanitizeConn((string)($data['connection'] ?? '')),
            'rtt'  => isset($data['rtt']) ? (int)$data['rtt'] : null,
            'dl'   => isset($data['downlink']) ? (float)$data['downlink'] : null,
            'iph'  => self::hashIp($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
        ];

        $dir = __DIR__ . '/../../logs/web-vitals';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/' . date('Y-m-d') . '.ndjson';
        @file_put_contents(
            $file,
            json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );
        // 204-equivalent: empty JSON, sendBeacon-friendly
        return Response::json(['ok' => true], 204);
    }

    private static function sanitizePath(string $url): string
    {
        $p = parse_url($url, PHP_URL_PATH) ?: '/';
        if (strlen($p) > 200) {
            $p = substr($p, 0, 200);
        }
        // Strip IDs/hashes per cardinality protection (es. /map/12345 -> /map/:id)
        $p = (string)preg_replace('#/\d{3,}#', '/:id', $p);
        $p = (string)preg_replace('#/[a-f0-9]{16,}#i', '/:hash', $p);
        return $p;
    }

    private static function sanitizeViewport(string $v): string
    {
        return preg_match('/^\d{2,5}x\d{2,5}$/', $v) ? $v : '';
    }

    private static function sanitizeConn(string $c): string
    {
        $ok = ['slow-2g', '2g', '3g', '4g', '5g', 'unknown'];
        return in_array($c, $ok, true) ? $c : 'unknown';
    }

    private static function hashIp(string $ip): string
    {
        // SHA256(ip + daily salt) — daily-rotated salt prevents
        // cross-day correlation, satisfies GDPR pseudonymization.
        $salt = date('Y-m-d');
        return substr(hash('sha256', $ip . '|' . $salt), 0, 16);
    }
}
