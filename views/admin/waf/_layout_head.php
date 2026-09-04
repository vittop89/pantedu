<?php
/**
 * Shared header WAF pages — Phase 25.G uniformazione layout.
 *
 * Delega a /views/admin/_partials/page_head.php per topbar standard +
 * breadcrumb. Aggiunge sotto: WAF mode banner + WAF tabs.
 *
 * Variabili in scope:
 *   $current_tab string  identificatore pagina ("dashboard"|"config"|"rules"|"blocks"|"reports"|"anomalies"|"threat_intel")
 *   $page_title  string  (es. "Reports unificato")
 *   $config      array<string,string>  per banner mode
 *   $user        array
 */
$current_tab = $current_tab ?? '';
$page_title  = $page_title  ?? 'WAF';
$user        = $user        ?? (\App\Core\Auth::user() ?? []);
$config      = $config      ?? [];

// Phase 25.R.22 — Tab aggregation (9 tab → 5):
//   - Anomalies    → split: soglie → Config, lista → Blocks
//   - Diag         → merged into Reports (accordion)
//   - Threat Intel → split: config → Config, sync UI → Blocks, stats → Reports
$tabs = [
    ['key' => 'dashboard', 'href' => '/admin/waf/dashboard', 'label' => '📊 Dashboard'],
    ['key' => 'config',    'href' => '/admin/waf/config',    'label' => '⚙️ Config'],
    ['key' => 'rules',     'href' => '/admin/waf/rules',     'label' => '🛡️ Rules'],
    ['key' => 'blocks',    'href' => '/admin/waf/blocks',    'label' => '🚫 Blocks & Anomalies'],
    ['key' => 'reports',   'href' => '/admin/waf/reports',   'label' => '📈 Reports & Diag'],
];

// ── Sub-nav: WAF mode banner + WAF tabs (HTML stringificato) ──
ob_start();
$enabled = ($config['enabled'] ?? '0') === '1';
$mode    = $config['mode'] ?? 'monitor';
if (!$enabled) {
    echo '<div class="fm-waf-mode-banner off">⚠️ WAF DISABILITATO — nessuna protezione attiva.';
    echo ' Vai a <a href="/admin/waf/config">Config</a> per abilitare.</div>';
} else {
    $cls = $mode === 'enforce' ? 'enforce' : ($mode === 'off' ? 'off' : '');
    echo '<div class="fm-waf-mode-banner ' . $cls . '">';
    echo 'WAF attivo — modalità: <strong>' . htmlspecialchars($mode, ENT_QUOTES) . '</strong></div>';
}
?>
<nav class="fm-admin-tabs">
<?php foreach ($tabs as $t): ?>
    <a class="fm-admin-tab <?= $t['key'] === $current_tab ? 'is-active' : '' ?>"
       href="<?= htmlspecialchars($t['href'], ENT_QUOTES) ?>">
        <?= htmlspecialchars($t['label'], ENT_QUOTES) ?>
    </a>
<?php endforeach; ?>
</nav>
<?php
$sub_nav = (string)ob_get_clean();

// ── Render header standard con sub-nav ──
$breadcrumb = [
    ['label' => 'WAF', 'href' => '/admin/waf/dashboard'],
];
if ($current_tab !== '' && $current_tab !== 'dashboard') {
    $breadcrumb[] = ['label' => $page_title];
}

// L'h1 del partial deve essere "🛡️ WAF — <tab>"; il page_title locale resta
// disponibile per il breadcrumb.
$_waf_subpage = $page_title;
$page_title   = '🛡️ WAF — ' . $page_title;
include __DIR__ . '/../_partials/page_head.php';
$page_title   = $_waf_subpage;
unset($_waf_subpage);
