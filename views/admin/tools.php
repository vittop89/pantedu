<?php
/** @var string $csrf */
$page_title    = '🛠️ Admin Tools';
$page_subtitle = 'UI unificata per gestione utenti, sicurezza, registrazioni e strumenti.';
$breadcrumb    = [['label' => 'Tools']];
include __DIR__ . '/_partials/page_head.php';
?>
    <input type="hidden" id="fm-tools-csrf" value="<?= e($csrf) ?>">

    <div class="fm-tabs" role="tablist">
        <button class="fm-tab fm-tab--active" data-tab="notifications">🔔 Notifiche <span class="fm-tab-badge" id="tab-badge-notif"></span></button>
        <button class="fm-tab" data-tab="users">👥 Utenti</button>
        <button class="fm-tab" data-tab="registrations">📝 Registrazioni <span class="fm-tab-badge" id="tab-badge-reg"></span></button>
        <button class="fm-tab" data-tab="logs">📋 Log</button>
        <button class="fm-tab" data-tab="hash">🔑 Hash</button>
    </div>

    <div class="fm-tab-panels">
        <!-- ── NOTIFICATIONS ── -->
        <section class="fm-tab-panel fm-tab-panel--active" data-panel="notifications">
            <h2>Riepilogo</h2>
            <div id="fm-notif-grid" class="fm-grid fm-grid--3">
                <div class="fm-tile"><h3>Caricamento…</h3></div>
            </div>
            <button type="button" class="fm-btn fm-btn--ghost" id="fm-notif-refresh">🔄 Aggiorna</button>
        </section>

        <!-- ── USERS ── -->
        <section class="fm-tab-panel" data-panel="users">
            <h2>Utenti</h2>
            <div class="fm-toolbar">
                <input type="search" id="fm-users-q" class="fm-input fm-max-w-280" placeholder="Cerca username/email/nome…" >
                <select id="fm-users-role" class="fm-input fm-max-w-160" >
                    <option value="">Tutti i ruoli</option>
                    <option value="student">Studente</option>
                    <option value="teacher">Docente</option>
                    <option value="collaborator">Collaboratore</option>
                    <option value="administrator">Admin</option>
                </select>
                <select id="fm-users-status" class="fm-input fm-max-w-140" >
                    <option value="">Tutti gli status</option>
                    <option value="approved">Approved</option>
                    <option value="pending">Pending</option>
                </select>
                <button class="fm-btn fm-btn--primary" id="fm-users-search">Cerca</button>
            </div>
            <div id="fm-users-result"><p class="fm-muted">Inserisci una query e clicca Cerca.</p></div>
        </section>

        <!-- ── REGISTRATIONS ── (sempre visibile: admin approva studenti S1/teacher S2) -->
        <section class="fm-tab-panel" data-panel="registrations">
            <h2>Registrazioni in attesa</h2>
            <div id="fm-reg-result"><p class="fm-muted">Caricamento…</p></div>
        </section>

<?php /* Phase 25.C — tab "Sicurezza" rimosso da /admin/tools.
              Tutto migrato al pannello dedicato /admin/waf:
              - Credentials + IP blocks (auth + manual) → /admin/waf/blocks (Phase 25.R.19 tab unificato)
              - Anomaly detection soglie + alerts → /admin/waf/anomalies
              - WAF pre-route (geo + bot + rules) → tab principali /admin/waf/*
              Card "WAF" su /admin/dashboard è l'unico entry point UI. */ ?>

        <!-- ── LOGS ── -->
        <section class="fm-tab-panel" data-panel="logs">
            <h2>Log di accesso</h2>
            <div class="fm-toolbar">
                <input type="number" id="fm-log-limit" class="fm-input fm-max-w-90" value="50" min="10" max="500">
                <button class="fm-btn fm-btn--primary" id="fm-log-load">Carica</button>
                <a class="fm-btn fm-btn--ghost" href="/admin/access-log?limit=200" target="_blank">JSON raw</a>
            </div>
            <div id="fm-log-result"><p class="fm-muted">Carica per visualizzare.</p></div>
        </section>

        <!-- ── HASH ── -->
        <section class="fm-tab-panel" data-panel="hash">
            <h2>Generatore hash password (bcrypt)</h2>
            <form id="fm-hash-form" autocomplete="off">
                <label class="fm-label">Password
                    <input type="text" name="password" class="fm-input" required minlength="4" autocomplete="off">
                </label>
                <label class="fm-label">Cost
                    <input type="number" name="cost" class="fm-input fm-max-w-80" value="12" min="4" max="14">
                </label>
                <button type="submit" class="fm-btn fm-btn--primary">Genera</button>
                <pre id="fm-hash-out" hidden class="fm-codeblock"></pre>
                <p id="fm-hash-err" class="fm-alert fm-alert--error" hidden></p>
                <button type="button" class="fm-btn fm-btn--ghost" id="fm-hash-copy" hidden>📋 Copia hash</button>
            </form>
        </section>
    </div>
</div>

<?php /* Phase 25.D — CSS estratto in /css/admin.css (auto-load da layout/shell). */ ?>

</div><!-- /.fm-card -->

<script src="/js/modules/features/admin-tools.js" type="module"></script>
