<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Response;
use Throwable;

/**
 * G22.S7 — Endpoint admin per applicare DB migration via browser.
 *
 * Necessario su hosting condiviso (legacy): niente accesso SSH, niente
 * possibilita' di lanciare `php tools/migrate.php` da terminale. Il
 * super_admin apre /admin/migrate dal browser, vede la lista pending,
 * clicca "Esegui" e le migration vengono applicate.
 *
 * Endpoint:
 *   GET  /admin/migrate          — pagina HTML con stato + bottone esegui
 *   GET  /admin/migrate/status   — JSON {executed: [...], pending: [...]}
 *   POST /admin/migrate/run      — esegue tutte le pending; CSRF + super_admin
 *
 * Sicurezza:
 *   - Auth super_admin only (Auth::isSuperAdmin)
 *   - CSRF su POST /run
 *   - Idempotente: il Migrator skippa quelle gia' eseguite + tracking table
 *   - Lock atomico via `Migrator::acquireLock()` (timeout 60s) per evitare
 *     race con esecuzioni concorrenti
 */
final class AdminMigrateController
{
    /** GET /admin/migrate — pagina HTML semplice */
    public function page(Request $req): Response
    {
        if (!$this->guard()) {
            return Response::redirect('/login');
        }

        $status = $this->getStatus();
        $csrf = \App\Core\Csrf::token();

        $executed = '';
        foreach ($status['executed'] as $f) {
            $executed .= '<li>✓ ' . htmlspecialchars($f) . '</li>';
        }
        $pending = '';
        foreach ($status['pending'] as $f) {
            $pending .= '<li>⧖ ' . htmlspecialchars($f) . '</li>';
        }
        $disabled = empty($status['pending']) ? 'disabled' : '';
        $btnText = empty($status['pending'])
            ? 'Tutte applicate ✓'
            : 'Esegui ' . count($status['pending']) . ' migration';

        $html = <<<HTML
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<title>Migrate DB — Admin</title>
<style>
body { font: 14px/1.5 system-ui, -apple-system, sans-serif; max-width: 760px; margin: 30px auto; padding: 0 16px; color: #222; }
h1 { font-size: 22px; margin: 0 0 8px; }
.muted { color: #666; font-size: 13px; }
.box { background: #f6f8fa; border: 1px solid #ddd; border-radius: 6px; padding: 12px 16px; margin: 12px 0; }
.box h2 { font-size: 15px; margin: 0 0 6px; color: #2a5ac7; }
ul { margin: 4px 0 4px 18px; padding: 0; font-family: ui-monospace, monospace; font-size: 13px; }
button { background: #2a5ac7; color: #fff; border: 0; border-radius: 4px; padding: 10px 20px; font: 600 14px system-ui; cursor: pointer; }
button:disabled { background: #aaa; cursor: not-allowed; }
.warn { color: #c70; }
#out { white-space: pre-wrap; font-family: ui-monospace, monospace; font-size: 12px; background: #1e1e1e; color: #d4d4d4; padding: 12px; border-radius: 4px; max-height: 380px; overflow: auto; display: none; }
</style>
</head>
<body>
<h1>📦 Database Migration</h1>
<p class="muted">Strumento admin per applicare migration al DB di produzione (hosting condiviso (legacy) senza SSH).</p>

<div class="box">
    <h2>Eseguite ({$status['count_executed']})</h2>
    <ul>{$executed}</ul>
</div>

<div class="box">
    <h2>Pending ({$status['count_pending']})</h2>
    <ul>{$pending}</ul>
</div>

<form id="runForm">
    <button type="submit" {$disabled}>{$btnText}</button>
    <span class="warn">⚠ Operazione non reversibile sulle tabelle modificate. Backup DB consigliato prima.</span>
</form>

<pre id="out"></pre>

<script>
document.getElementById('runForm').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const btn = ev.target.querySelector('button');
    const out = document.getElementById('out');
    btn.disabled = true; btn.textContent = 'Esecuzione…';
    out.style.display = 'block';
    out.textContent = 'POST /admin/migrate/run …\\n';
    try {
        const r = await fetch('/admin/migrate/run', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': '{$csrf}', 'Content-Type': 'application/json' },
        });
        const j = await r.json();
        out.textContent += JSON.stringify(j, null, 2);
        if (j.ok) {
            out.textContent += '\\n\\n✓ Migration completate. Ricarica la pagina per vedere il nuovo stato.';
        }
    } catch (e) {
        out.textContent += '\\n[errore] ' + e.message;
    } finally {
        btn.textContent = 'Ricarica per re-checkare';
    }
});
</script>
</body>
</html>
HTML;
        return Response::html($html);
    }

    /** GET /admin/migrate/status — JSON */
    public function status(Request $req): Response
    {
        if (!$this->guard()) {
            return Response::json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        return Response::json(['ok' => true, ...$this->getStatus()], 200);
    }

    /** POST /admin/migrate/run — applica tutte le pending */
    public function run(Request $req): Response
    {
        if (!$this->guard()) {
            return Response::json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        if (!Config::get('database.enabled')) {
            return Response::json(['ok' => false, 'error' => 'db_disabled'], 503);
        }

        try {
            @set_time_limit(120);
            $migrator = new Migrator(Database::connection(), \dirname(__DIR__, 2) . '/database/migrations');
            $executed = $migrator->run(dryRun: false);
            return Response::json([
                'ok'           => true,
                'executed_now' => $executed,
                'count'        => \count($executed),
            ], 200);
        } catch (Throwable $e) {
            return Response::json([
                'ok'    => false,
                'error' => $e->getMessage(),
                'trace' => Config::get('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }

    private function guard(): bool
    {
        return Auth::check() && Auth::isSuperAdmin();
    }

    private function getStatus(): array
    {
        if (!Config::get('database.enabled')) {
            return ['executed' => [], 'pending' => [], 'count_executed' => 0, 'count_pending' => 0];
        }
        $migrator = new Migrator(Database::connection(), \dirname(__DIR__, 2) . '/database/migrations');
        $migrator->ensureTrackingTable();
        $executed = $migrator->executedFilenames();
        $pending  = $migrator->pending();
        return [
            'executed'       => $executed,
            'pending'        => $pending,
            'count_executed' => \count($executed),
            'count_pending'  => \count($pending),
        ];
    }
}
