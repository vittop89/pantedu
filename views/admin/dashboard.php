<?php
/** @var array $user */
/** @var array<string,int> $counts */
/** @var array $recent */
/** @var list<array> $pending */
/** @var string $csrf */
/** @var array $notifications */
/** @var bool $isSuperAdmin */
$notif = $notifications ?? [];
$notifTotal = (int)($notif['total'] ?? 0);
$isSuperAdmin = (bool)($isSuperAdmin ?? false);

// Topbar alert (notifiche urgenti) — rendered prima del breadcrumb
$top_alert = null;
if ($notifTotal > 0) {
    ob_start(); ?>
    <div class="fm-alert fm-alert--warn fm-d-flex fm-items-center fm-gap-2" >
        <span class="fm-text-em-xxl">⚠️</span>
        <div>
            <strong><?= (int)$notifTotal ?> elementi richiedono attenzione</strong>
            <ul class="fm-m-0 fm-mt-1 fm-pl-em-md fm-text-em-lg">
                <?php if (!empty($notif['pending_registrations'])): ?>
                    <li><a href="#fm-pending-table"><?= (int)$notif['pending_registrations'] ?> registrazioni in attesa</a></li>
                <?php endif; ?>
                <?php if ($isSuperAdmin && !empty($notif['pending_takedowns'])): ?>
                    <li><a href="/admin/takedown">🛡️ <?= (int)$notif['pending_takedowns'] ?> segnalazioni Notice & Takedown</a></li>
                <?php endif; ?>
                <?php if ($isSuperAdmin && !empty($notif['tos_outdated_users'])): ?>
                    <li><?= (int)$notif['tos_outdated_users'] ?> utenti con ToS non aggiornato</li>
                <?php endif; ?>
                <?php if (!empty($notif['failed_logins_24h'])): ?>
                    <li><?= (int)$notif['failed_logins_24h'] ?> login falliti nelle ultime 24h</li>
                <?php endif; ?>
                <?php if (!empty($notif['blocked_credentials'])): ?>
                    <li><?= (int)$notif['blocked_credentials'] ?> credenziali bloccate</li>
                <?php endif; ?>
                <?php if (!empty($notif['blocked_ips'])): ?>
                    <li><?= (int)$notif['blocked_ips'] ?> IP bloccati</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
    <?php
    $top_alert = (string)ob_get_clean();
}

$page_title = '🔧 Admin Dashboard';
$breadcrumb = [];  // Root admin: nessun crumb dopo "Home > Admin"
$back_href  = '/?home=1';
$back_label = 'Torna alla home';
include __DIR__ . '/_partials/page_head.php';
?>

    <?php /* Phase 25.H — Strumenti spostati come icone compatte nella topbar
       (fm-admin-toolnav in _partials/page_head.php). Curriculum rimosso
       (è redirect a /area-docente/profilo — ridondante in admin).
       Phase 25.Q — KPI ridotti: stats accessi (totale + oggi) ora SOLO in
       Analytics (link in topbar). Qui restano solo i counter actionable
       che richiedono attenzione immediata dell'admin. */ ?>
    <section class="fm-admin-kpi">
        <h2 class="fm-admin-kpi__title">⏰ Da gestire</h2>
        <div class="fm-grid fm-grid--3">
            <div class="fm-tile<?= ((int)($counts['pending'] ?? 0) > 0) ? ' fm-tile--alert' : '' ?>">
                <h3>Registrazioni in attesa</h3>
                <div class="fm-big"><?= (int)($counts['pending'] ?? 0) ?></div>
                <div class="fm-muted fm-text-em-md" >Approvazione manuale</div>
            </div>
            <?php if ($isSuperAdmin): ?>
                <a href="/admin/takedown" class="fm-tile<?= ((int)($notif['pending_takedowns'] ?? 0) > 0) ? ' fm-tile--alert' : '' ?>" class="fm-link-reset">
                    <h3>Segnalazioni Takedown</h3>
                    <div class="fm-big"><?= (int)($notif['pending_takedowns'] ?? 0) ?></div>
                    <div class="fm-muted fm-text-em-md" >Notice &amp; Takedown pending</div>
                </a>
                <a href="/admin/tos-log" class="fm-tile<?= ((int)($notif['tos_outdated_users'] ?? 0) > 0) ? ' fm-tile--alert' : '' ?>" class="fm-link-reset">
                    <h3>ToS non aggiornato</h3>
                    <div class="fm-big"><?= (int)($notif['tos_outdated_users'] ?? 0) ?></div>
                    <div class="fm-muted fm-text-em-md" >Utenti con consenso obsoleto</div>
                </a>
            <?php endif; ?>
        </div>
        <p class="fm-muted fm-mt-3 fm-text-em-md" >
            📊 Statistiche complete (accessi, utenti, indirizzi, materie) in
            <a href="/admin/analytics" class="fm-underline">Analytics</a>.
        </p>
    </section>
    <?php /* Phase 25.D — CSS estratto in /css/admin.css (auto-load da layout/shell). */ ?>

    <?php /* Phase 25.Q — Governance super-admin: solo entry NON già presenti in topbar.
             Takedown, Istituti, ToS log, WAF, Analytics sono in topbar.
             Qui rimangono RisDoc templates e Infrastructure (meno frequenti). */ ?>
    <?php if ($isSuperAdmin): ?>
    <section class="fm-admin-kpi fm-mt-8" >
        <h2 class="fm-admin-kpi__title">🛡️ Governance avanzata</h2>
        <div class="fm-grid fm-grid--3">
            <a href="/admin/risdoc" class="fm-tile fm-link-reset" >
                <h3>📚 Template RisDoc</h3>
                <div class="fm-muted fm-text-em-md" >Template istituzionali (LaTeX/PDF)</div>
            </a>
            <a href="/admin/infrastructure" class="fm-tile fm-link-reset" >
                <h3>⚙️ Infrastruttura</h3>
                <div class="fm-muted fm-text-em-md" >Status servizi, backup, log Loki</div>
            </a>
            <a href="/admin/migrate" class="fm-tile fm-link-reset" >
                <h3>🗄️ Migrations DB</h3>
                <div class="fm-muted fm-text-em-md" >Schema versioning + apply</div>
            </a>
        </div>
    </section>
    <?php endif; ?>

    <?php if (!empty($pending)): ?>
    <section class="fm-mt-8">
        <h2 class="fm-title fm-text-17" >✍️ Registrazioni da approvare</h2>
        <table class="fm-table" id="fm-pending-table">
            <thead>
                <tr>
                    <th scope="col">Richiesta</th><th scope="col">Nome</th><th scope="col">Email</th>
                    <th scope="col">Ruolo</th><th scope="col">Username</th><th scope="col">Azioni</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pending as $p): ?>
                    <tr data-id="<?= e($p['id']) ?>">
                        <td><span class="fm-code"><?= e($p['created']) ?></span></td>
                        <td><?= e(trim($p['first_name'] . ' ' . $p['last_name'])) ?></td>
                        <td><?= e($p['email']) ?></td>
                        <td><span class="fm-status" data-role="<?= e($p['role']) ?>"><?= e($p['role']) ?></span></td>
                        <td><span class="fm-code"><?= e($p['username']) ?></span></td>
                        <td>
                            <button class="fm-btn fm-btn--primary fm-reg-approve" data-id="<?= e($p['id']) ?>">✓ Approva</button>
                            <button class="fm-btn fm-btn--danger  fm-reg-reject"  data-id="<?= e($p['id']) ?>">✕ Rifiuta</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>

    <?php /* Phase 25.R.25 — Recent activity widget (content_action_log) */ ?>
    <?php if (!empty($recent_actions)): ?>
    <section class="fm-mt-8">
        <details open>
            <summary class="fm-cursor-pointer fm-fw-600 fm-text-17 fm-py-1">
                📜 Recent activity
                <span class="fm-muted fm-text-em-md fm-fw-400">(ultime <?= count($recent_actions) ?> azioni docenti)</span>
            </summary>
            <?php /* WCAG 2.2 AA (ADR-023): link fuori dal <summary> (era
                      nested-interactive: <a> dentro summary interattivo). */ ?>
            <p class="fm-mt-1 fm-mb-0"><a href="/admin/logs" class="fm-underline fm-text-em-md" data-full-reload>vai a Logs →</a></p>
            <div class="fm-scroll-panel fm-scroll-panel--260 fm-mt-2" tabindex="0" role="region" aria-label="Attività recente">
            <table class="fm-table fm-m-0 fm-text-em-md" >
                <thead class="fm-sticky-top">
                    <tr><th scope="col">Quando</th><th scope="col">Teacher</th><th scope="col">Azione</th><th scope="col">Tipo</th><th scope="col">Content</th></tr>
                </thead>
                <tbody>
                <?php foreach ($recent_actions as $a):
                    $actionBadge = match ($a['action']) {
                        'content_created'     => '🆕 created',
                        'content_updated'     => '✏️ updated',
                        'content_published'   => '🌐 published',
                        'content_unpublished' => '🔒 unpublished',
                        'content_archived'    => '📦 archived',
                        'content_deleted'     => '🗑️ deleted',
                        'content_cloned_from' => '📋 cloned',
                        'content_shared'      => '🔗 shared',
                        'content_exported'    => '📥 exported',
                        default               => $a['action'],
                    };
                ?>
                    <tr>
                        <td><span class="fm-code"><?= e($a['occurred_at']) ?></span></td>
                        <td>#<?= (int)$a['teacher_id'] ?></td>
                        <td><?= e($actionBadge) ?></td>
                        <td><?= e($a['content_type']) ?></td>
                        <td>#<?= (int)$a['content_id'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </details>
    </section>
    <?php endif; ?>

    <section class="fm-mt-8">
        <details>
            <summary class="fm-cursor-pointer fm-fw-600 fm-text-17 fm-py-1">
                Ultimi accessi
                <?php if (!empty($recent)): ?>
                    <span class="fm-muted fm-text-em-md fm-fw-400">(<?= count($recent) ?>)</span>
                <?php endif; ?>
            </summary>
            <?php if (empty($recent)): ?>
                <p class="fm-muted fm-mt-2" >Nessun accesso registrato.</p>
            <?php else: ?>
                <div class="fm-scroll-panel-resizable fm-mt-2">
                <table class="fm-table fm-m-0" >
                    <thead class="fm-sticky-top">
                        <tr>
                            <th scope="col">Quando</th><th scope="col">Utente</th><th scope="col">Ruolo</th>
                            <th scope="col">Indirizzo</th><th scope="col">Classe</th><th scope="col">Materia</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $r): ?>
                            <tr>
                                <td><span class="fm-code"><?= e($r['timestamp'] ?? '') ?></span></td>
                                <td><?= e($r['username'] ?? '') ?></td>
                                <td><span class="fm-status" data-role="<?= e($r['role'] ?? 'guest') ?>">
                                    <?= e($r['role'] ?? '') ?>
                                </span></td>
                                <td><?= e($r['institute_code'] ?? '—') ?></td>
                                <td><?= e($r['class_code']     ?? '—') ?></td>
                                <td><?= e($r['subject']        ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </details>
    </section>

    <section class="fm-mt-8">
        <details>
            <summary class="fm-link">Endpoint JSON raw (debug)</summary>
            <div class="fm-mt-2 fm-grid fm-grid--280 fm-gap-4">
                <div>
                    <h4 class="fm-label-uppercase">📊 Stats & log</h4>
                    <ul class="fm-m-0 fm-pl-em-md fm-text-em-lg">
                        <li><a class="fm-link" href="/api/admin/notifications">/api/admin/notifications</a></li>
                        <li><a class="fm-link" href="/admin/access-log?limit=50">/admin/access-log</a></li>
                        <li><a class="fm-link" href="/admin/access-stats">/admin/access-stats</a></li>
                        <li><a class="fm-link" href="/admin/debug-log">/admin/debug-log</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="fm-label-uppercase">👥 Utenti & registrazioni</h4>
                    <ul class="fm-m-0 fm-pl-em-md fm-text-em-lg">
                        <li><a class="fm-link" href="/admin/registrations">/admin/registrations</a></li>
                        <li><a class="fm-link" href="/api/admin/users?limit=20">/api/admin/users</a></li>
                        <li><a class="fm-link" href="/admin/whoami">/admin/whoami</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="fm-label-uppercase">🛡️ Security</h4>
                    <ul class="fm-m-0 fm-pl-em-md fm-text-em-lg">
                        <li><a class="fm-link" href="/api/admin/security/blocked-credentials">/api/admin/security/blocked-credentials</a></li>
                        <li><a class="fm-link" href="/api/admin/security/blocked-ips">/api/admin/security/blocked-ips</a></li>
                        <li><a class="fm-link" href="/api/admin/security/anomalies">/api/admin/security/anomalies</a></li>
                        <li><a class="fm-link" href="/api/admin/security/live-blocks">/api/admin/security/live-blocks</a></li>
                    </ul>
                </div>
            </div>
        </details>
    </section>
</div>

<script>
(function () {
    const CSRF = <?= json_encode($csrf) ?>;
    async function call(id, action, extra) {
        const body = new URLSearchParams({ _csrf: CSRF, ...(extra || {}) });
        const res  = await fetch('/admin/registrations/' + encodeURIComponent(id) + '/' + action, {
            method: 'POST', body, headers: {Accept: 'application/json'}
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok || !json.ok) {
            alert('Errore: ' + (json.error || res.status));
            return false;
        }
        document.querySelector('tr[data-id="' + CSS.escape(id) + '"]')?.remove();
        return true;
    }
    document.querySelectorAll('.fm-reg-approve').forEach(btn => {
        btn.addEventListener('click', () => {
            if (confirm('Approvare la registrazione?')) call(btn.dataset.id, 'approve');
        });
    });
    document.querySelectorAll('.fm-reg-reject').forEach(btn => {
        btn.addEventListener('click', () => {
            const reason = prompt('Motivo del rifiuto (opzionale):') ?? '';
            call(btn.dataset.id, 'reject', { reason });
        });
    });
})();
</script>

</div><!-- /.fm-card -->
