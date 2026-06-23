<?php

namespace App\Controllers;

use App\Core\AccessLogger;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\AdminNotificationsService;
use App\Services\CurriculumService;
use App\Services\HashGenerator;
use App\Services\LogTailer;
use App\Services\RegistrationService;
use App\Support\Validator;
use Throwable;

final class AdminController
{
    /** GET /admin/access-log?limit=50 */
    public function accessLog(Request $req): Response
    {
        try {
            $v     = new Validator($req->query);
            $limit = $v->int('limit', min: 1, max: 1000, required: false, default: 50);
            return Response::json([
                'ok'     => true,
                'recent' => (new AccessLogger())->recent($limit),
            ]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /** GET /admin/access-stats?type=all|daily_stats|user_stats|... */
    public function accessStats(Request $req): Response
    {
        // Audit 25.R.31 — try/catch uniforme (prima 500 con shape divergente).
        try {
            $type = (string)($req->query['type'] ?? 'all');
            $allowed = ['all','daily_stats','user_stats','institute_stats','class_stats'];
            if (!\in_array($type, $allowed, true)) {
                return Response::json(['ok' => false, 'error' => 'invalid_type'], 400);
            }
            return Response::json(['ok' => true, 'stats' => (new AccessLogger())->stats($type)]);
        } catch (Throwable $e) {
            error_log('[admin] accessStats: ' . $e->getMessage());
            return Response::json(['ok' => false, 'error' => 'internal_error'], 500);
        }
    }

    /** GET /admin/debug-log?lines=50 — tails legacy debug.log + new php_errors.log */
    public function debugLog(Request $req): Response
    {
        try {
            $v     = new Validator($req->query);
            $lines = $v->int('lines', min: 1, max: 2000, required: false, default: 50);
            $tailer = new LogTailer($this->allowedLogFiles());

            $out = [];
            foreach ($tailer->allowed() as $file) {
                $label = basename($file);
                $out[$label] = $tailer->tail($file, $lines);
            }
            return Response::json(['ok' => true, 'logs' => $out]);
        } catch (Throwable $e) {
            error_log('[admin] debugLog: ' . $e->getMessage());
            return Response::json(['ok' => false, 'error' => 'internal_error'], 400);
        }
    }

    /** POST /admin/generate-hash — body: password[, cost] */
    public function generateHash(Request $req): Response
    {
        try {
            $v    = new Validator($req->post);
            $pw   = $v->string('password', min: 4, max: 4096);
            // Audit 25.R.31 — cost massimo 12 (bcrypt cost 14 = ~1.5s/hash → DoS
            // anche da admin autenticato senza bucket dedicato).
            $cost = $v->int('cost', min: 4, max: 12, required: false, default: 12);
            $hash = (new HashGenerator())->generate($pw, $cost);
            Csrf::rotate();
            return Response::json(['ok' => true, 'hash' => $hash]);
        } catch (Throwable $e) {
            error_log('[admin] generateHash: ' . $e->getMessage());
            return Response::json(['ok' => false, 'error' => 'internal_error'], 400);
        }
    }

    /** GET /admin/tools/hash — UI moderna per HashGenerator (rimpiazza
     *  log/admin/generate_hash.php, 532 righe legacy). Il form posta a
     *  /admin/generate-hash via fetch + CSRF. */
    public function hashToolPage(Request $req): Response
    {
        ob_start();
        $pageTitle   = 'PANTEDU — Genera hash';
        $pageContent = $this->hashToolHtml();
        $bodyClass   = '';
        $currentRoute = $req->path;
        include dirname(__DIR__, 2) . '/views/layout/app.php';
        return new Response((string)ob_get_clean(), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function hashToolHtml(): string
    {
        $csrf = htmlspecialchars(Csrf::token(), ENT_QUOTES);
        return <<<HTML
<main style="max-width:720px;margin:2rem auto;padding:1.5rem;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.08)">
  <h1 style="margin-top:0">🔑 Generatore hash password</h1>
  <p class="fm-muted">Hash bcrypt sicuro per `admin_users.json` / `collaborators.json`. Cost 10–14.</p>
  <form id="hash-form" style="display:grid;gap:.6rem;margin-top:1rem">
    <label>Password in chiaro
      <input type="text" name="password" autocomplete="off" required minlength="4"
             style="width:100%;padding:.5rem;font:14px monospace">
    </label>
    <label>Cost factor (più alto = più sicuro ma più lento)
      <input type="number" name="cost" value="12" min="4" max="14" style="width:80px">
    </label>
    <input type="hidden" name="_csrf" value="{$csrf}">
    <button type="submit" class="fm-btn fm-btn--primary" style="justify-self:start">Genera hash</button>
  </form>
  <pre id="hash-out" hidden style="margin-top:1rem;padding:.75rem;background:#f4f4f4;border-radius:4px;word-break:break-all;white-space:pre-wrap"></pre>
  <p id="hash-err" hidden style="color:#c02a2a;margin-top:.5rem"></p>
</main>
<script>
(() => {
  const form = document.getElementById('hash-form');
  const out  = document.getElementById('hash-out');
  const err  = document.getElementById('hash-err');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    err.hidden = out.hidden = true;
    try {
      const fd  = new FormData(form);
      const res = await fetch('/admin/generate-hash', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'fetch',
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        },
        body: new URLSearchParams(fd).toString(),
      });
      const j = await res.json();
      if (!res.ok || !j.ok) throw new Error(j.error || ('HTTP ' + res.status));
      out.textContent = j.hash;
      out.hidden = false;
      // Aggiorna CSRF rotato dal server
      form.querySelector('[name="_csrf"]').value = j.csrf || form.querySelector('[name="_csrf"]').value;
    } catch (ex) {
      err.textContent = '⚠️ ' + (ex.message || ex);
      err.hidden = false;
    }
  });
})();
</script>
HTML;
    }

    /** GET /admin/whoami — authenticated user summary (for the admin UI) */
    public function whoAmI(Request $req): Response
    {
        $u = Auth::user() ?? ['username' => null, 'role' => 'guest'];
        return Response::json(['ok' => true, 'user' => $u]);
    }

    /**
     * GET /api/admin/notifications — JSON counters per banner badge.
     * Polled da .sel-session-banner per mostrare ! / numero quando ci
     * sono pending registrations / failed logins / blocchi attivi.
     */
    public function notifications(Request $req): Response
    {
        $svc = AdminNotificationsService::default();
        // ITEM 1 — scope viewer: Auth::currentInstitute() ritorna null per
        // super-admin (→ count globale) e admin_institute_id per admin non-super
        // (→ count scopato al proprio istituto). Niente nuovo helper.
        $scope = Auth::currentInstitute();
        return Response::json(['ok' => true] + $svc->summary($scope));
    }

    /** GET /admin/dashboard — HTML overview moderno con counters/alert tiles */
    public function dashboard(Request $req): Response
    {
        $logger = new AccessLogger();
        $stats  = $logger->stats('all');
        $today  = date('Y-m-d');

        $curr = new CurriculumService(
            jsonPath: Config::get('app.paths.storage') . '/data/curriculum.json'
        );

        $registrations = new RegistrationService(
            registrationsPath: Config::get('auth.paths.registrations'),
            usersPath:         Config::get('auth.paths.registered_users'),
        );
        $pending = $registrations->pending();

        // Notifications/alerts (Phase 13)
        // ITEM 1 — coerenza col widget JSON /api/admin/notifications: scope viewer.
        // /admin/dashboard è gated solo role:admin (NON super_admin_required),
        // quindi un admin non-super non deve vedere il tile globale.
        $notifications = AdminNotificationsService::default()->summary(Auth::currentInstitute());

        $counts = [
            'total'     => array_sum(array_map(
                fn($d) => (int)($d['total_accesses'] ?? 0),
                $stats['daily_stats'] ?? []
            )),
            'today'     => count($stats['daily_stats'][$today]['unique_users'] ?? []),
            'indirizzi' => count($curr->listActive('indirizzi')),
            'materie'   => count($curr->listActive('materie')),
            'pending'   => count($pending),
        ];

        // Phase 25.R.25 — Recent activity widget (audit log)
        $recentActions = \App\Services\Audit\ContentActionLogger::recent(15);

        $view = View::default();
        $body = $view->render('admin/dashboard', [
            'user'          => Auth::user() ?? ['username' => '-', 'role' => 'guest'],
            'counts'        => $counts,
            'recent'        => $logger->recent(25),
            'recent_actions' => $recentActions,
            'pending'       => $pending,
            'notifications' => $notifications,
            'isSuperAdmin'  => Auth::isSuperAdmin(),
            'csrf'          => \App\Core\Csrf::token(),
        ]);
        return Response::html($view->render('layout/shell', [
            'title'     => 'Admin Dashboard — Pantedu',
            'body'      => $body,
            'bodyClass' => 'fm-area-admin',
        ]));
    }

    /** @return list<string> */
    private function allowedLogFiles(): array
    {
        $base = Config::get('app.paths.base');
        return [
            $base . '/log/errors/debug.log',
            $base . '/storage/logs/php_errors.log',
            $base . '/storage/logs/debug.log',
        ];
    }
}
