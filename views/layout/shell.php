<!doctype html>
<!-- data-fm-full-reload="1": layout shell NON è SPA-compatibile (no
     #fm-content). Se navighi qui via partial fetch da una pagina con
     sidebar/SPA router, il router rileva questo marker e forza
     location.href full reload. Senza, #fm-content della pagina
     precedente veniva sostituito con <!doctype html>... rompendo
     tutto (sidebar resta visibile, layout doppio). Phase 25.H. -->
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Pantedu') ?></title>
    <?php /* Phase Roadmap 2 + 2026-05-24 — cache-bust CSS + bundle preference.
            Allinea shell.php a partials/head.php: preferisce main.bundle.css
            (1 file, 1 cache-bust) se presente, fallback main.css con @import.
            Senza cache-bust Cloudflare cache @import nested → stile stale al deploy. */
       $_cssPath = function (string $relPath): string {
           $clean = '/' . ltrim($relPath, '/');
           $abs = __DIR__ . '/../..' . $clean;
           // VPS deploy fix: clearstatcache bypassa PHP realpath_cache_ttl 120s.
           clearstatcache(true, $abs);
           return $clean . (is_file($abs) ? '?v=' . filemtime($abs) : '');
       };
       $_useBundle = is_file(__DIR__ . '/../../css/main.bundle.css');
    ?>
    <?php if ($_useBundle): ?>
        <link rel="stylesheet" href="<?= $_cssPath('css/main.bundle.css') ?>">
    <?php else: ?>
        <link rel="stylesheet" href="<?= $_cssPath('css/main.css') ?>">
    <?php endif; ?>
    <!-- Dark-mode rules (body.fm-dark) live in layout.css; carichiamo
         anche qui per coprire le pagine shell (admin tools/dashboard/
         analytics/registration ecc.) — bootstrap.js applica la classe
         body.fm-dark da localStorage. -->
    <link rel="stylesheet" href="<?= $_cssPath('css/layout.css') ?>">
    <?php
    // Phase 25.D — auto-load admin + WAF CSS centralizzati su path /admin/*.
    // Regole prefissate (.fm-admin-*, .fm-tab, .fm-waf-*) non interferiscono
    // con pagine non-admin.
    $_path = $_SERVER['REQUEST_URI'] ?? '';
    if (str_starts_with($_path, '/admin')):
    ?>
        <link rel="stylesheet" href="/css/admin.css">
        <?php
        // Phase 25.R.25 — waf.css contiene classi shared (.fm-admin-tab, .fm-logs-table,
        // .fm-section-heading-*, .fm-content-search-panel, badge) usate fuori /admin/waf.
        // Caricarlo su tutto /admin/* (regole sono prefissate, no interferenze).
        ?>
        <link rel="stylesheet" href="/css/waf.css">
    <?php endif; ?>
    <?php if (!empty($extraCss)): foreach ((array)$extraCss as $href): ?>
        <link rel="stylesheet" href="<?= e($href) ?>">
    <?php endforeach; endif; ?>
    <?php
    /* 2026-05-24 Fase 2 — Vite manifest entry. Se manifest assente
     * (dev senza build), fallback a bootstrap.dist.js raw con cache-bust. */
    if (class_exists(\App\Support\ViteManifest::class)
        && is_file(__DIR__ . '/../../public/build/manifest.json')):
        echo \App\Support\ViteManifest::script('js/modules/bootstrap.js');
    else:
        $_jsRoot  = __DIR__ . '/../../js/modules/';
        $_jsEntry = is_file($_jsRoot . 'bootstrap.dist.js') ? 'bootstrap.dist.js' : 'bootstrap.js';
        $_jsAbs   = $_jsRoot . $_jsEntry;
        ?>
    <script type="module" src="/js/modules/<?= $_jsEntry ?><?= is_file($_jsAbs) ? '?v=' . filemtime($_jsAbs) : '' ?>"></script><?php
    endif; ?>
</head>
<body class="fm-shell<?= !empty($modal) ? ' fm-shell--modal' : '' ?><?= !empty($bodyClass) ? ' ' . htmlspecialchars($bodyClass, ENT_QUOTES) : '' ?>" data-fm-full-reload="1">
<!--email_off--><?php /* Cloudflare Email Obfuscation OFF (vedi app.php): no script email-decode bloccato da CSP. */ ?>
<a href="#fm-content" class="fm-skip-link">Salta al contenuto principale</a>
<main id="fm-content" tabindex="-1">
<?= $body ?? '' ?>
</main>
<!--/email_off-->
</body>
</html>
