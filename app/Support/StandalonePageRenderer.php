<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Render HTML standalone (full document) per pagine pubbliche fuori layout
 * principale: Trust pages (informativa, security), DPO contact form,
 * eventuali pagine pubbliche future.
 *
 * Caratteristiche:
 *   - Layout fluido fino a 1100px.
 *   - Dark theme: applica `.fm-dark` sul wrapper leggendo
 *     `localStorage.fm_dark_mode` o `prefers-color-scheme: dark` come fallback.
 *   - Mini darkmode toggle in alto a destra.
 *   - **Tutto scoped a `.fm-trust-page` wrapper** così non leaka al body
 *     globale quando la pagina è iniettata via SPA router (`fm-router.js`
 *     invia `X-Partial: 1` e fa `target.innerHTML = response`).
 *   - **Auto-detect partial mode**: se la richiesta arriva con header
 *     `X-Partial: 1` ritorna fragment (no DOCTYPE/html/head/body), così
 *     l'iniezione SPA non corrompe il layout principale.
 */
final class StandalonePageRenderer
{
    /**
     * @param string $title Titolo pagina (HTML-escaped automaticamente).
     * @param string $body  HTML del body principale (già sanitizzato dal caller).
     * @param array<string,mixed> $options
     *     - showFooter: bool (default true)
     *     - extraStyles: string CSS aggiuntivo scoped al wrapper
     *     - partial: bool|null (null = auto-detect da X-Partial header)
     *     - useAppLayout: bool (Phase 25.R.2.4) — quando true e NON partial,
     *       embedda l'inner wrapper dentro `views/layout/app.php` (sidebar +
     *       bottombar) anziché DOCTYPE standalone. Garantisce coerenza UX su
     *       direct hit in nuova tab: l'utente conserva sidebar/topbar.
     *       Default false (back-compat); opt-in dalle trust/legal/DPO pages.
     */
    public static function render(string $title, string $body, array $options = []): string
    {
        $titleEsc    = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $showFooter  = (bool)($options['showFooter'] ?? true);
        $extraStyles = (string)($options['extraStyles'] ?? '');
        $partial     = $options['partial'] ?? self::detectPartial();
        $useAppLayout = (bool)($options['useAppLayout'] ?? false);

        $footer = $showFooter
            ? '<footer class="fm-trust-footer">'
                . 'Pantedu — '
                . '<a href="/">Home</a> · '
                . '<a href="/security">Sicurezza</a> · '
                . '<a href="/privacy/your-data">I tuoi dati</a> · '
                . '<a href="/privacy/informativa">Informativa</a> · '
                . '<a href="/dpo-contact">DPO</a>'
                . '</footer>'
            : '';

        $css = self::scopedStyles() . "\n" . $extraStyles;
        $js  = self::darkModeScript();

        // Wrapper con tutti gli stili + script scoped — funziona sia in
        // direct hit (body parent) sia in SPA partial (#fm-content parent).
        $inner = <<<HTML
            <div class="fm-trust-page" data-fm-trust>
                <style>{$css}</style>
                <button class="fm-trust-page__dark-toggle" type="button" aria-label="Attiva/disattiva tema scuro" title="Tema scuro/chiaro">
                    <span class="fm-trust-page__dark-icon">🌙</span>
                </button>
                <main class="fm-trust-page__main">
                    {$body}
                </main>
                {$footer}
                <script>{$js}</script>
            </div>
            HTML;

        if ($partial) {
            // SPA partial: il fm-router inietta in #fm-content via innerHTML.
            // Niente DOCTYPE/html/head/body — sarebbero filtrati dal browser
            // e i loro attributi/style applicati globalmente.
            return $inner;
        }

        if ($useAppLayout) {
            return self::wrapInAppLayout($title, $inner);
        }

        // Direct URL hit standalone: full HTML document. Body delegato al
        // wrapper, niente stili globali → `:root` di altre app non viene
        // inquinato se per qualche motivo qualcuno embedda questa pagina.
        return <<<HTML
            <!DOCTYPE html>
            <html lang="it">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <meta name="color-scheme" content="light dark">
                <title>{$titleEsc}</title>
                <style>html, body { margin: 0; padding: 0; background: #1a1a24; }</style>
            </head>
            <body>
            <!--email_off-->
                {$inner}
            <!--/email_off-->
            </body>
            </html>
            HTML;
    }

    /**
     * Phase 25.R.2.4 — wrap trust-page wrapper inside layout/app.php so direct
     * hits keep sidebar+bottombar (UX consistency su copy-link → open in new tab).
     */
    private static function wrapInAppLayout(string $title, string $inner): string
    {
        $base = dirname(__DIR__, 2);
        $pageTitle    = $title;
        $pageContent  = $inner;
        $pageHead     = '';
        $pageScripts  = '';
        $bodyClass    = 'fm-trust-context';
        $currentRoute = $_SERVER['REQUEST_URI'] ?? '';
        ob_start();
        include $base . '/views/layout/app.php';
        return (string)ob_get_clean();
    }

    /**
     * Auto-detect SPA partial via header X-Partial. Apache passa custom
     * headers come HTTP_X_PARTIAL. fm-router.js usa proprio quel header.
     */
    private static function detectPartial(): bool
    {
        return (($_SERVER['HTTP_X_PARTIAL'] ?? '') === '1');
    }

    /**
     * CSS scoped al wrapper `.fm-trust-page`. Variables su `.fm-trust-page`
     * (no `:root`) così non leakano al layout host quando iniettato via SPA.
     */
    private static function scopedStyles(): string
    {
        return <<<'CSS'
            .fm-trust-page {
                --fm-bg: #ffffff;
                --fm-fg: #1e293b;
                --fm-fg-muted: #475569;
                /* WCAG 2.1 AA (1.4.3): #94a3b8 su bianco = 2.56:1 (fail).
                   #64748b = 4.76:1 → footer/link conformi. */
                --fm-fg-soft: #64748b;
                --fm-accent: #1e40af;
                --fm-accent-hover: #1e3a8a;
                --fm-border: #e2e8f0;
                --fm-code-bg: #f1f5f9;
                --fm-code-fg: #1e293b;
                --fm-callout-bg: #fef3c7;
                --fm-callout-fg: #78350f;
                --fm-quote-bg: #f8fafc;
                --fm-quote-border: #cbd5e1;
                --fm-table-stripe: #f8fafc;
                --fm-error-bg: #fee2e2;
                --fm-error-fg: #b91c1c;
                --fm-input-bg: #ffffff;
                --fm-input-border: #cbd5e1;

                background: var(--fm-bg);
                color: var(--fm-fg);
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                line-height: 1.65;
                min-height: 100vh;
                box-sizing: border-box;
                position: relative;
            }
            .fm-trust-page.fm-dark {
                --fm-bg: #1a1a24;
                --fm-fg: #e6e6ee;
                --fm-fg-muted: #c0c0cc;
                --fm-fg-soft: #8b8b9a;
                --fm-accent: #93c5fd;
                --fm-accent-hover: #bfdbfe;
                --fm-border: #3a3a4a;
                --fm-code-bg: #2a2a36;
                --fm-code-fg: #e6e6ee;
                --fm-callout-bg: #3f2e10;
                --fm-callout-fg: #fcd34d;
                --fm-quote-bg: #24242e;
                --fm-quote-border: #4b5563;
                --fm-table-stripe: #24242e;
                --fm-error-bg: #402020;
                --fm-error-fg: #ffb0b0;
                --fm-input-bg: #24242e;
                --fm-input-border: #3a3a4a;
            }
            /* Reset child element colors — eredita color dal wrapper. Override
               aggressivo perché il layout host può avere `body.fm-dark` con
               regole tipo `body.fm-dark p { color: ... }` che vincerebbero
               per cascade. Forziamo `inherit` qui. */
            .fm-trust-page,
            .fm-trust-page p,
            .fm-trust-page span,
            .fm-trust-page strong,
            .fm-trust-page b,
            .fm-trust-page em,
            .fm-trust-page li,
            .fm-trust-page td,
            .fm-trust-page th,
            .fm-trust-page label,
            .fm-trust-page blockquote {
                color: var(--fm-fg);
            }
            .fm-trust-page * { box-sizing: border-box; }
            .fm-trust-page__main {
                max-width: 1100px;
                margin: 0 auto;
                padding: 2em 1.5em 4em;
            }
            .fm-trust-page h1 {
                color: var(--fm-accent);
                border-bottom: 2px solid var(--fm-border);
                padding-bottom: 0.3em;
                margin-top: 0.3em;
                line-height: 1.25;
            }
            .fm-trust-page h2 {
                color: var(--fm-accent);
                margin-top: 2em;
                line-height: 1.3;
            }
            .fm-trust-page h3 { color: var(--fm-fg-muted); }
            .fm-trust-page h4 { color: var(--fm-fg-muted); margin-top: 1.5em; }
            .fm-trust-page a {
                color: var(--fm-accent);
                text-decoration: none;
            }
            .fm-trust-page a:hover { text-decoration: underline; color: var(--fm-accent-hover); }
            /* WCAG 2.1 AA (1.4.1 Use of Color): i link dentro blocchi di testo
               devono essere distinguibili senza affidarsi al solo colore →
               sottolineatura sui link in prosa (no su footer/bottoni). */
            .fm-trust-page p a:not(.fm-btn),
            .fm-trust-page li a:not(.fm-btn) { text-decoration: underline; }
            .fm-trust-page a.fm-btn {
                display: inline-block;
                padding: 0.6em 1.2em;
                background: var(--fm-accent);
                color: #fff;
                border-radius: 4px;
                margin: 0.5em 0;
            }
            .fm-trust-page a.fm-btn:hover { background: var(--fm-accent-hover); color: #fff; text-decoration: none; }
            .fm-trust-page code {
                background: var(--fm-code-bg);
                color: var(--fm-code-fg);
                padding: 0.1em 0.3em;
                border-radius: 3px;
                font-family: ui-monospace, "Cascadia Code", monospace;
                font-size: 0.9em;
            }
            .fm-trust-page pre {
                background: var(--fm-code-bg);
                color: var(--fm-code-fg);
                padding: 1em;
                border-radius: 4px;
                overflow-x: auto;
            }
            .fm-trust-page pre code { background: transparent; padding: 0; }
            .fm-trust-page blockquote {
                border-left: 4px solid var(--fm-quote-border);
                background: var(--fm-quote-bg);
                margin: 1em 0;
                padding: 0.6em 1em;
                color: var(--fm-fg-muted);
                border-radius: 0 4px 4px 0;
            }
            .fm-trust-page blockquote p { color: var(--fm-fg-muted); }
            .fm-trust-page ul, .fm-trust-page ol { padding-left: 1.5em; }
            .fm-trust-page li { margin: 0.4em 0; }
            .fm-trust-page hr {
                border: 0;
                border-top: 1px solid var(--fm-border);
                margin: 2em 0;
            }
            .fm-trust-page table {
                border-collapse: collapse;
                width: 100%;
                margin: 1.2em 0;
                font-size: 0.95em;
                border: 1px solid var(--fm-border);
            }
            .fm-trust-page th, .fm-trust-page td {
                border: 1px solid var(--fm-border);
                padding: 0.55em 0.8em;
                text-align: left;
                vertical-align: top;
            }
            .fm-trust-page th {
                background: var(--fm-code-bg);
                font-weight: 600;
            }
            .fm-trust-page tbody tr:nth-child(even) td { background: var(--fm-table-stripe); }
            .fm-trust-page .md-table-placeholder {
                padding: 0.5em 0.8em;
                background: var(--fm-callout-bg);
                color: var(--fm-callout-fg);
                border-radius: 4px;
                font-size: 0.9em;
            }
            /* Form elements (DPO contact) */
            .fm-trust-page label { display: block; margin: 1em 0 0.3em; font-weight: 500; }
            .fm-trust-page label.checkbox-label { display: flex; align-items: center; gap: 0.5em; font-weight: 400; }
            .fm-trust-page input[type="text"],
            .fm-trust-page input[type="email"],
            .fm-trust-page select,
            .fm-trust-page textarea {
                width: 100%;
                padding: 0.6em;
                font-size: 1em;
                border: 1px solid var(--fm-input-border);
                border-radius: 4px;
                margin-top: 0.3em;
                font-family: inherit;
                background: var(--fm-input-bg);
                color: var(--fm-fg);
            }
            .fm-trust-page textarea { resize: vertical; min-height: 8em; }
            .fm-trust-page button[type="submit"] {
                padding: 0.7em 1.5em;
                font-size: 1em;
                cursor: pointer;
                border: 1px solid var(--fm-accent);
                border-radius: 4px;
                background: var(--fm-accent);
                color: #fff;
                margin-top: 1em;
            }
            .fm-trust-page button[type="submit"]:hover { background: var(--fm-accent-hover); border-color: var(--fm-accent-hover); }
            /* WCAG 1.4.3 — in tema scuro --fm-accent è azzurro chiaro (#93c5fd,
               usato anche come colore-testo dei link su fondo scuro): come SFONDO
               del bottone con testo bianco dava 1.8:1. Testo scuro sul bottone →
               contrasto ~13:1. */
            .fm-trust-page.fm-dark button[type="submit"] { color: #0b1220; }
            .fm-trust-page .fm-error-banner {
                color: var(--fm-error-fg);
                background: var(--fm-error-bg);
                padding: 1em;
                border-radius: 4px;
                margin-bottom: 1em;
            }
            /* Footer */
            .fm-trust-page .fm-trust-footer {
                max-width: 1100px;
                margin: 0 auto;
                padding: 1.2em 1.5em 3em;
                font-size: 0.85em;
                color: var(--fm-fg-soft);
                text-align: center;
                border-top: 1px solid var(--fm-border);
            }
            .fm-trust-page .fm-trust-footer a { color: var(--fm-fg-soft); }
            /* Dark toggle pulsante (top-right del wrapper, non global) */
            .fm-trust-page__dark-toggle {
                position: absolute;
                top: 0.8em;
                right: 0.8em;
                width: 34px;
                height: 34px;
                border-radius: 50%;
                border: 1px solid var(--fm-border);
                background: var(--fm-bg);
                color: var(--fm-fg);
                cursor: pointer;
                font-size: 1rem;
                line-height: 1;
                display: flex;
                align-items: center;
                justify-content: center;
                opacity: 0.7;
                transition: opacity 0.2s, transform 0.1s, background 0.2s;
                z-index: 10;
            }
            .fm-trust-page__dark-toggle:hover { opacity: 1; transform: scale(1.05); }
            .fm-trust-page__dark-toggle:active { transform: scale(0.95); }
            .fm-trust-page.fm-dark .fm-trust-page__dark-toggle { color: #ffd700; border-color: rgba(255,255,255,.18); }
            /* Mobile */
            @media (max-width: 720px) {
                .fm-trust-page__main { padding: 1em; }
                .fm-trust-page table { font-size: 0.85em; }
                .fm-trust-page th, .fm-trust-page td { padding: 0.4em 0.5em; }
            }
        CSS;
    }

    private static function darkModeScript(): string
    {
        return <<<'JS'
            (function () {
                var STORAGE_KEY = 'fm_dark_mode';
                var wrapper = document.currentScript && document.currentScript.closest('.fm-trust-page');
                if (!wrapper) {
                    /* fm-router rewireInjectedScripts ricrea gli script: in
                       quel caso document.currentScript può essere null. Cerca
                       l'ultimo wrapper inserito nel DOM. */
                    var all = document.querySelectorAll('.fm-trust-page');
                    wrapper = all[all.length - 1];
                }
                if (!wrapper) return;

                function apply(on) {
                    wrapper.classList.toggle('fm-dark', on);
                    var icon = wrapper.querySelector('.fm-trust-page__dark-icon');
                    if (icon) icon.textContent = on ? '☀' : '🌙';
                }
                /* Init: localStorage > body.fm-dark del layout host > prefers-color-scheme */
                var on = false;
                try {
                    var s = localStorage.getItem(STORAGE_KEY);
                    if (s === '1') on = true;
                    else if (s === '0') on = false;
                    else if (document.body.classList.contains('fm-dark')) on = true;
                    else if (window.matchMedia) on = window.matchMedia('(prefers-color-scheme: dark)').matches;
                } catch (e) {}
                apply(on);
                // Toggle on click
                var btn = wrapper.querySelector('.fm-trust-page__dark-toggle');
                if (btn && !btn.dataset.fmBound) {
                    btn.dataset.fmBound = '1';
                    btn.addEventListener('click', function () {
                        var next = !wrapper.classList.contains('fm-dark');
                        apply(next);
                        try { localStorage.setItem(STORAGE_KEY, next ? '1' : '0'); } catch (e) {}
                        /* Sync con sidebar toggle del layout host se presente */
                        document.body.classList.toggle('fm-dark', next);
                        document.documentElement.classList.toggle('fm-dark', next);
                    });
                }
                // Sync cross-tab
                window.addEventListener('storage', function (e) {
                    if (e.key === STORAGE_KEY) apply(e.newValue === '1');
                });
            })();
        JS;
    }
}
