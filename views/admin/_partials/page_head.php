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
 *                                 trail breadcrumb. Sempre auto-prepended con Home > Admin.
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
$_isSuper = \App\Core\Auth::isSuperAdmin() && $_role !== 'administrator';
?>
<div class="fm-card fm-card--wide">

<?php if ($top_alert): ?>
    <?= $top_alert ?>
<?php endif; ?>

<!-- Phase 25.R.22 — breadcrumb + user actions allineati in alto, ai lati opposti -->
<div class="fm-bc-row">
    <nav class="fm-breadcrumb">
        <a href="/?home=1" data-full-reload>🏠 Home</a>
        <span class="fm-bc-sep">/</span>
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
        <?php if ($_isSuper): ?>
            <span class="fm-status" data-role="super" title="Accesso tecnico super-admin (tracciato)">🛡️ SUPER-ADMIN</span>
        <?php endif; ?>
        <strong><?= $_h($user['username'] ?? '-') ?></strong>
        <a class="fm-btn fm-btn--ghost fm-btn--sm" href="/me/change-password">🔐 Password</a>
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
                ['href' => '/admin/templates',      'icon' => '📋', 'label' => 'Templates'],
                ['href' => '/admin/sidebar-config', 'icon' => '📌', 'label' => 'Sidebar',   'super' => true],
                ['href' => '/admin/tools',          'icon' => '🛠️', 'label' => 'Tools'],
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
            $_group_tools = array_values(array_filter(
                $_group_tools,
                fn($t) => empty($t['super']) || $_isSuper
            ));
            if (!$_group_tools) continue;
            $_group_active = false;
            foreach ($_group_tools as $t) {
                if (str_starts_with($_current_path, $t['href'])) { $_group_active = true; break; }
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
                        $_active = str_starts_with($_current_path, $t['href']);
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
    <script>
    /* Phase 25.R.30 — toggle menu a tendina nav admin (1 aperto alla volta). */
    (function () {
        const nav = document.currentScript.previousElementSibling;
        if (!nav || !nav.classList.contains("fm-admin-toolnav--menus")) return;
        function closeAll(except) {
            nav.querySelectorAll(".fm-admin-toolnav__group.is-open").forEach((g) => {
                if (g === except) return;
                g.classList.remove("is-open");
                g.querySelector(".fm-admin-toolnav__grpbtn")?.setAttribute("aria-expanded", "false");
                g.querySelector(".fm-admin-toolnav__menu")?.setAttribute("hidden", "");
            });
        }
        nav.addEventListener("click", (e) => {
            const btn = e.target.closest(".fm-admin-toolnav__grpbtn");
            if (!btn) return;
            const grp = btn.closest(".fm-admin-toolnav__group");
            const open = grp.classList.toggle("is-open");
            closeAll(open ? grp : null);
            btn.setAttribute("aria-expanded", open ? "true" : "false");
            const menu = grp.querySelector(".fm-admin-toolnav__menu");
            if (menu) { if (open) menu.removeAttribute("hidden"); else menu.setAttribute("hidden", ""); }
        });
        document.addEventListener("click", (e) => { if (!e.target.closest(".fm-admin-toolnav__group")) closeAll(null); });
        document.addEventListener("keydown", (e) => { if (e.key === "Escape") closeAll(null); });
    })();
    </script>
    <?php /* fm-tb-actions ora nel fm-bc-row in alto (breadcrumb level) */ ?>
</div>

<?php if ($page_subtitle): ?>
    <p class="fm-muted fm-m-0 fm-mb-4" ><?= $_h($page_subtitle) ?></p>
<?php endif; ?>

<?php if ($sub_nav): ?>
    <?= $sub_nav ?>
<?php endif; ?>
