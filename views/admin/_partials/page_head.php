<?php
/**
 * Admin page header partial — Phase 25.G uniformazione layout.
 *
 * Standard header per tutte le pagine /admin/* (dashboard, tools, analytics,
 * infrastructure, templates, waf/*).  Garantisce:
 *   - breadcrumb consistente
 *   - topbar (h1 + back link + role badge + super-admin + username + password + logout)
 *   - opening div fm-card fm-card--wide (chiudere con </div> a fine pagina!)
 *
 * Parametri attesi nello scope di include:
 *   $page_title    string         titolo H1 (con emoji opzionale)
 *   $page_subtitle string|null    sottotitolo paragrafo
 *   $breadcrumb    list<array{label:string, href?:string}>
 *                                 trail breadcrumb. Sempre auto-prepended con Admin (→ /admin/dashboard); la home pubblica non compare: l'account amministrativo non la usa.
 *                                 Esempio: [['label'=>'WAF', 'href'=>'/admin/waf/dashboard'], ['label'=>'Reports']]
 *   $user          array          Auth::user() (per role/username)
 *   $back_href     string|null    default '/admin/dashboard' (root admin → '/?home=1')
 *   $back_label    string|null    default 'Admin Dashboard' (root admin → 'Torna alla home')
 *   $sub_nav       string|null    HTML extra-nav (es. tabs WAF) renderato dopo topbar
 *   $top_alert     string|null    HTML alert rendered prima del breadcrumb (notifiche urgenti)
 */

/** @var string $page_title */
/** @var string|null $page_subtitle */
/** @var list<array{label:string,href?:string}> $breadcrumb */
/** @var array $user */
/** @var string|null $back_href */
/** @var string|null $back_label */
/** @var string|null $sub_nav */
/** @var string|null $top_alert */

$page_title    = $page_title    ?? 'Admin';
$page_subtitle = $page_subtitle ?? null;
$breadcrumb    = $breadcrumb    ?? [];
$user          = $user          ?? (\App\Core\Auth::user() ?? []);
$back_href     = $back_href     ?? '/admin/dashboard';
$back_label    = $back_label    ?? 'Admin Dashboard';
$sub_nav       = $sub_nav       ?? null;
$top_alert     = $top_alert     ?? null;

$_h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$_role = (string)($user['role'] ?? 'guest');
// 2026-09-04 — decide il solo flag, come fa gia' il dashboard. La condizione
// aggiuntiva `$_role !== 'administrator'` nascondeva la barra degli strumenti
// super-admin (WAF, sistema, istituti, chiavi) proprio all'account
// amministrativo dedicato, che ha ruolo administrator e il flag: con la
// separazione dei privilegi e' l'unico account da cui si amministra.
$_isSuper = \App\Core\Auth::isSuperAdmin();
?>
<div class="fm-card fm-card--wide">

<?php if ($top_alert): ?>
    <?= $top_alert ?>
<?php endif; ?>

<!-- Phase 25.R.22 — breadcrumb + user actions allineati in alto, ai lati opposti -->
<div class="fm-bc-row">
    <nav class="fm-breadcrumb">
        <a href="/admin/dashboard">Admin</a>
        <?php foreach ($breadcrumb as $i => $crumb):
            $isLast = $i === count($breadcrumb) - 1;
            ?>
            <span class="fm-bc-sep">/</span>
            <?php if (!$isLast && !empty($crumb['href'])): ?>
                <a href="<?= $_h($crumb['href']) ?>"><?= $_h($crumb['label']) ?></a>
            <?php else: ?>
                <span><?= $_h($crumb['label']) ?></span>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <div class="fm-tb-actions">
        <span class="fm-status" data-role="<?= $_h($_role) ?>"><?= $_h($_role) ?></span>
        <?php
        // Piano classi-credenziali-scenari, B — lo scenario di esercizio si
        // vede da ogni pagina del pannello: e' il contesto che decide quali
        // aree hanno effetto (AdminAreas), quindi non puo' stare solo dentro
        // Deployment. Link al pannello per chi puo' cambiarlo.
        $_scen      = \App\Support\DeploymentScenario::current();
        $_scenNum   = \App\Support\DeploymentScenario::number($_scen);
        $_scenLabel = \App\Support\DeploymentScenario::label($_scen);
        $_scenIcon  = ['personal' => '👤', 'colleagues' => '👥', 'institute' => '🏫'][$_scen] ?? '';
        ?>
        <?php if ($_isSuper): ?>
            <a class="fm-status" data-scenario="<?= (int)$_scenNum ?>" href="/admin/system/deployment" data-full-reload title="<?= $_h($_scenLabel) ?>"><?= $_scenIcon ?> Scenario <?= (int)$_scenNum ?></a>
        <?php else: ?>
            <span class="fm-status" data-scenario="<?= (int)$_scenNum ?>" title="<?= $_h($_scenLabel) ?>"><?= $_scenIcon ?> Scenario <?= (int)$_scenNum ?></span>
        <?php endif; ?>
        <?php if ($_isSuper): ?>
            <span class="fm-status" data-role="super" title="Accesso tecnico super-admin (tracciato)">🛡️ SUPER-ADMIN</span>
        <?php endif; ?>
        <strong><?= $_h($user['username'] ?? '-') ?></strong>
        <?php /* 15/9/2026 — un bottone solo per email, password e due fattori: l'email prima non si cambiava da nessuna pagina. */ ?>
        <a class="fm-btn fm-btn--ghost fm-btn--sm" href="/me/account" data-full-reload title="Email, password e verifica in due passaggi">👤 Il mio account</a>
        <a class="fm-btn fm-btn--ghost fm-btn--sm" href="/logout">Logout</a>
    </div>
</div>

<div class="fm-topbar">
    <h1><?= $_h($page_title) ?></h1>
    <nav class="fm-admin-toolnav fm-admin-toolnav--menus" aria-label="Admin tools">
        <?php
        $_current_path = $_SERVER['REQUEST_URI'] ?? '';
        // Phase 25.R.30 — raggruppamento rivisto + menu a tendina per gruppo.
        //   Panoramica (overview/osservabilità) · Istituto & contenuti (config) ·
        //   Conformità (GDPR + legale, fuse) · Sicurezza & infra.
        //   'super' = visibile solo super-admin. I tool non-super restano per
        //   tutti gli admin (Dashboard/Tools/Templates/Analytics).
        $_tool_groups = [
            'Panoramica' => [
                ['href' => '/admin/dashboard',  'icon' => '🏠', 'label' => 'Dashboard'],
                ['href' => '/admin/analytics',  'icon' => '📊', 'label' => 'Analytics'],
                ['href' => '/admin/monitoring', 'icon' => '📈', 'label' => 'Monitor', 'super' => true],
                ['href' => '/admin/logs',       'icon' => '📜', 'label' => 'Logs',    'super' => true],
            ],
            'Istituto & contenuti' => [
                ['href' => '/admin/institutes',     'icon' => '🏫', 'label' => 'Istituti',  'super' => true],
                ['href' => '/admin/sections',       'icon' => '🎯', 'label' => 'Sezioni',   'super' => true, 'area' => 'sections'],
                ['href' => '/admin/templates',      'icon' => '📋', 'label' => 'Templates'],
                ['href' => '/admin/sidebar-config', 'icon' => '📌', 'label' => 'Sidebar',   'super' => true],
                ['href' => '/admin',                'icon' => '🛠️', 'label' => 'Tools'],
            ],
            'Conformità' => [
                ['href' => '/admin/data-requests',         'icon' => '🗃️', 'label' => 'DSR',       'super' => true],
                ['href' => '/admin/data-breach',           'icon' => '🚨', 'label' => 'Breach',     'super' => true],
                ['href' => '/admin/subprocessors',         'icon' => '🏢', 'label' => 'Sub-proc',   'super' => true],
                ['href' => '/admin/gdpr/authority-export', 'icon' => '⚖️', 'label' => 'Authority',  'super' => true],
                ['href' => '/admin/takedown',              'icon' => '⚠️', 'label' => 'Takedown',   'super' => true],
                ['href' => '/admin/tos-log',               'icon' => '📜', 'label' => 'ToS log',    'super' => true],
            ],
            'Sicurezza & infra' => [
                ['href' => '/admin/crypto-status',     'icon' => '🔐', 'label' => 'Crypto',     'super' => true],
                ['href' => '/admin/waf/dashboard',     'icon' => '🛡️', 'label' => 'WAF',        'super' => true],
                ['href' => '/admin/backup',            'icon' => '💾', 'label' => 'Backup',     'super' => true],
                ['href' => '/admin/system/deployment', 'icon' => '⚙️', 'label' => 'Deployment', 'super' => true],
            ],
        ];
        foreach ($_tool_groups as $_group_label => $_group_tools):
            // Filtra i tool super-only per gli admin non-super; salta gruppi vuoti.
            // 'area' = chiave in AdminAreas: la voce compare solo dove ha
            // effetto nello scenario corrente (la pagina resta raggiungibile
            // per URL, con l'avviso in testa).
            $_group_tools = array_values(array_filter(
                $_group_tools,
                fn($t) => (empty($t['super']) || $_isSuper)
                    && (empty($t['area']) || \App\Support\AdminAreas::isActive($t['area']))
            ));
            if (!$_group_tools) continue;
            // 2026-09-04 — «/admin» e' ora la pagina degli strumenti: come prefisso
            // combacerebbe con ogni pagina del pannello, quindi per quella voce si
            // confronta il percorso esatto (piu' il vecchio /admin/tools).
            $_matches = static fn(string $href, string $path): bool => $href === '/admin'
                ? (bool)preg_match('#^/admin(?:/tools)?(?:\?|$)#', $path)
                : str_starts_with($path, $href);
            $_group_active = false;
            foreach ($_group_tools as $t) {
                if ($_matches($t['href'], $_current_path)) { $_group_active = true; break; }
            }
        ?>
            <div class="fm-admin-toolnav__group<?= $_group_active ? ' is-active' : '' ?>" role="group" aria-label="<?= $_h($_group_label) ?>">
                <button type="button" class="fm-admin-toolnav__grpbtn"
                        aria-haspopup="true" aria-expanded="false">
                    <span class="fm-admin-toolnav__grplbl"><?= $_h($_group_label) ?></span>
                    <span class="fm-admin-toolnav__caret" aria-hidden="true">▾</span>
                </button>
                <div class="fm-admin-toolnav__menu" role="menu" hidden>
                    <?php foreach ($_group_tools as $t):
                        $_active = $_matches($t['href'], $_current_path);
                    ?>
                        <a class="fm-admin-toolnav__item<?= $_active ? ' is-active' : '' ?>"
                           href="<?= $_h($t['href']) ?>"
                           data-full-reload role="menuitem"
                           aria-label="<?= $_h($t['label']) ?>">
                            <span aria-hidden="true"><?= $t['icon'] ?></span>
                            <span class="fm-admin-toolnav__lbl"><?= $_h($t['label']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </nav>
    <?= \App\Support\ViteManifest::script('js/entries/admin.js') ?>
    <?php /* fm-tb-actions ora nel fm-bc-row in alto (breadcrumb level) */ ?>
</div>

<?php if ($page_subtitle): ?>
    <p class="fm-muted fm-m-0 fm-mb-4" ><?= $_h($page_subtitle) ?></p>
<?php endif; ?>

<?php if ($sub_nav): ?>
    <?= $sub_nav ?>
<?php endif; ?>
