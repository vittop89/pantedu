<head>
    <title><?= htmlspecialchars($pageTitle ?? 'PANTEDU', ENT_QUOTES) ?></title>
    <meta charset="UTF-8">

    <?php /* G21.4 + Phase C.3 — dark mode anti-FOUC.
            - localStorage "fm_dark_mode" e' la persisted choice utente
              ("1"/"0"). Se null, fallback a prefers-color-scheme.
            - html[data-theme] aggiornato sync per applicare tokens.css
              :root[data-theme] PRIMA del primo paint (no flash bianco). */ ?>
    <script>
        (function () {
            try {
                var s = localStorage.getItem("fm_dark_mode");
                var on;
                if (s === "0") on = false;
                else if (s === "1") on = true;
                else {
                    var m = window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)");
                    on = m ? m.matches : true;
                }
                document.documentElement.setAttribute("data-theme", on ? "dark" : "light");
                if (on) {
                    document.documentElement.classList.add("fm-dark-pre");
                    document.addEventListener("DOMContentLoaded", function () {
                        if (document.body) document.body.classList.add("fm-dark");
                    }, { once: true });
                }
            } catch (e) {}
        })();
    </script>
    <meta name="description" content="Free Web math and physics materials">
    <meta name="keywords" content="matematica, fisica, mappe concettuali">
    <meta name="author" content="{{OPERATORE_NOME}}">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv='cache-control' content='no-cache'>
    <meta http-equiv='expires' content='0'>
    <meta http-equiv='pragma' content='no-cache'>

    <?php /* Phase Roadmap 2 + 9 — CSS loading strategy.
            - Se env FM_CRITICAL_CSS=1: inline critical.css + async main.css
              (FCP -1.5s su Slow 3G).
            - Altrimenti: link sincrono main.css (default safe).
            layout.css resta legacy esterno (deprecazione progressiva).
            Opt-in via .env per safety rollout. */ ?>
    <?php /* 2026-05-24 fix: CSS bundle + cache-busting via filemtime.
            Cloudflare cache CSS con max-age=604800 (1 settimana). Senza
            cache-bust il deploy non arrivava ai browser. main.css usa
            @import nested per components.css + 37 moduli — CF cachava
            i nested file separatamente, modifiche modulari NON arrivavano.

            Soluzione efficient + scalable:
              - tools/build-css-bundle.php genera main.bundle.css concat
                ricorsivo di main.css + tutti @import (preserva @layer).
              - deploy.sh chiama lo script post git pull → bundle rigenerato.
              - head.php link a /css/main.bundle.css?v=<mtime> (1 sola
                request HTTP/2, cache-bust automatic ad ogni deploy).
              - Fallback graceful: se bundle assente (dev senza build),
                usa main.css con @import nested. */
       $cssPath = function (string $relPath): string {
           $clean = '/' . ltrim($relPath, '/');
           $abs = __DIR__ . '/../..' . $clean;
           // VPS deploy fix: PHP stat cache (realpath_cache_ttl 120s) può
           // restituire mtime stantio dopo build-css-bundle.php rebuild.
           // clearstatcache forza fresh stat = ?v=mtime sempre attuale.
           clearstatcache(true, $abs);
           return $clean . (is_file($abs) ? '?v=' . filemtime($abs) : '');
       };
       $useBundle = is_file(__DIR__ . '/../../css/main.bundle.css'); ?>
    <?php if (($_ENV['FM_CRITICAL_CSS'] ?? getenv('FM_CRITICAL_CSS')) === '1' && class_exists(\App\Support\CriticalCss::class)): ?>
        <?= \App\Support\CriticalCss::inline() ?>
    <?php elseif ($useBundle): ?>
        <link rel="stylesheet" href="<?= $cssPath('css/main.bundle.css') ?>" type="text/css">
    <?php else: ?>
        <link rel="stylesheet" href="<?= $cssPath('css/main.css') ?>" type="text/css">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= $cssPath('css/layout.css') ?>" type="text/css">
    <!-- G26.phase7.2 — Chosen CSS rimosso (mai usato in codice moderno; verificato grep .chosen( = 0 hits). -->

    <?php $_fm_isExercise = str_contains((string)($bodyClass ?? ''), 'exercise-context'); ?>
    <?php if ($_fm_isExercise): ?>
        <?php
            // Exercise-context: carica asset esercizi unificati (MathJax v4,
            // Quill, jQuery, jQuery UI, Copilot AI, FA, bootstrap.js).
            // G26.phase7.2 — Chosen rimosso da _exercise_assets.php (unused).
            // Render TikZ via /tikz/render (server-side VPS, vedi ADR-013).
            include __DIR__ . '/_exercise_assets.php';
        ?>
    <?php else: ?>
        <!-- Non-exercise (home/dashboard): solo asset minimi -->
        <link rel="stylesheet" href="<?= $cssPath('css/layout_es.css') ?>" type="text/css">
        <link rel="stylesheet" href="<?= $cssPath('css/layout_editor.css') ?>" type="text/css">
        <!-- Phase 25.Q.4 — Quill self-hosted in /vendor/quill/1.3.6/ per
             eliminare dipendenza CDN (FND-VPS-002 conforme).
             SRI integrity ripristinato con hash calcolato dai file locali
             (mismatch = blocco esecuzione → catch eventuali corruzioni file). -->
        <?php /* Perf 2026-05-24: quill.snow.css solo per editor toolbar
                  (mai visibile prima del click su ✎). Async-load via
                  media-print swap → no render-blocking (650ms saved). */ ?>
        <link rel="stylesheet" href="/vendor/quill/1.3.6/quill.snow.css"
              media="print" data-fm-css="print"
              integrity="sha384-07UbSXbd8HpaOfxZsiO6Y8H1HTX6v0J96b5qP6PKSpYEuSZSYD4GFFHlLRjvjVrL"
              crossorigin="anonymous">
        <script>(function(l){l&&l.addEventListener("load",function(){this.media="all"},{once:true})})(document.currentScript.previousElementSibling)</script>
        <noscript><link rel="stylesheet" href="/vendor/quill/1.3.6/quill.snow.css"></noscript>
        <?php /* Sprint B (2026-06-02): jQuery CDN RIMOSSO dal main app — il
                  bundle è interamente vanilla (legacyBoot/init via DOMContentLoaded,
                  consumer con fallback vanilla). Resta solo in risdoc (template
                  legacy). Quill defer; bootstrap.js type="module" → già deferred. */ ?>
        <!-- G26.phase7.2 — jquery.sticky.js / Chosen rimossi (mai chiamati). -->
        <script defer src="/vendor/quill/1.3.6/quill.min.js"
                integrity="sha384-AOnYUgW6MQR78EvTqtkbNavz7dVI7dtLm7QdcIMBoy06adLIzNT40hHPYl9Rr5m5"
                crossorigin="anonymous"></script>
        <?php
        // 2026-05-24 Fase 2 — Vite manifest entry-point (script + sub-chunks
        // preload). Fallback graceful a bootstrap.dist.js raw se manifest
        // assente (dev senza build). ViteManifest::script() emette:
        //   <link rel="modulepreload"> per ogni sub-chunk statico (parallel fetch)
        //   <script type="module" src="/build/assets/bootstrap.HASH.js" crossorigin>
        if (class_exists(\App\Support\ViteManifest::class)
            && is_file(__DIR__ . '/../../public/build/manifest.json')):
            echo \App\Support\ViteManifest::script('js/modules/bootstrap.js');
        else:
            $_jsEntry = is_file(__DIR__ . '/../../js/modules/bootstrap.dist.js')
                ? 'js/modules/bootstrap.dist.js'
                : 'js/modules/bootstrap.js';
            ?><script type="module" src="<?= $cssPath($_jsEntry) ?>"></script><?php
        endif; ?>
    <?php endif; ?>

    <link rel="icon" type="image/svg+xml"
        href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Cdefs%3E%3CradialGradient id='bg' cx='50%25' cy='50%25' r='50%25'%3E%3Cstop offset='0%25' style='stop-color:%23fff9cc'/%3E%3Cstop offset='100%25' style='stop-color:%23ffeb3b'/%3E%3C/radialGradient%3E%3CradialGradient id='sphere' cx='30%25' cy='30%25' r='70%25'%3E%3Cstop offset='0%25' style='stop-color:%23ffffff;stop-opacity:0.8'/%3E%3Cstop offset='50%25' style='stop-color:%234a90e2'/%3E%3Cstop offset='100%25' style='stop-color:%23003d82'/%3E%3C/radialGradient%3E%3C/defs%3E%3Crect width='32' height='32' fill='url(%23bg)'/%3E%3Ccircle cx='16' cy='16' r='12' fill='url(%23sphere)'/%3E%3Cellipse cx='12' cy='12' rx='3' ry='2' fill='%23ffffff' opacity='0.6'/%3E%3C/svg%3E">

    <?= $pageHead ?? '' ?>
</head>
