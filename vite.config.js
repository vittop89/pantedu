/**
 * Phase 17 — Vite build parallelo.
 *
 * Non sostituisce subito il caricamento `<script src="/js/modules/bootstrap.js">`
 * sui template PHP: costruisce un bundle hashato in `public/build/` che può
 * essere referenziato a discrezione quando i template vengono migrati.
 *
 * Build:        npm run build
 * Dev (HMR):    npm run dev           (server su :5173)
 * Preview:      npm run preview
 *
 * I path emessi vanno letti dal manifest `public/build/manifest.json` (lato
 * PHP: `App\Support\ViteManifest::url("js/modules/bootstrap.js")` — TODO).
 */
import { defineConfig } from "vite";
import { resolve } from "path";

export default defineConfig({
    root: ".",
    base: "/build/",
    // Phase Roadmap 7 — PostCSS wired in. Plugins definiti in
    // postcss.config.js (postcss-import + preset-env + autoprefixer +
    // cssnano in prod). Vite carica config auto-discovery.
    css: {
        devSourcemap: true,
    },
    // G22.S15.bis Fase 5 — disabilita publicDir copy: vite di default copia
    // tutto `public/` in outDir (causando duplicazione di public/drawio-app
    // 145MB). Gli asset statici sono gia' serviti direttamente da Apache
    // dalla loro location originale, no copy necessaria.
    publicDir: false,
    build: {
        outDir: "public/build",
        emptyOutDir: true,
        // Phase 22.4c — manifest fuori da `.vite/` (default Vite) così
        // server come Apache/nginx con deny su file dot non lo bloccano.
        // Consumer: /build/manifest.json (non /build/.vite/manifest.json).
        manifest: "manifest.json",
        rollupOptions: {
            input: {
                bootstrap: resolve(__dirname, "js/modules/bootstrap.js"),
                "fm-router": resolve(__dirname, "js/fm-router.js"),
                // Phase 22.3b — editor risdoc Portable Text: entry separato
                // per lazy-load (Tiptap ~80kB gz, no impact su bootstrap).
                "risdoc-pt-editor": resolve(__dirname, "js/entries/risdoc-pt-editor.js"),
                // 2026-09-04 — i Web Component risdoc e la shell fm-pt-document
                // erano caricati come moduli sorgente da /risdoc/view e dalla
                // preview admin, con Lit importato da un CDN. Ora Lit viene da
                // npm e si risolve solo nel bundle: le due pagine li caricano
                // via ViteManifest::script() (revisione 2026-09, rilievo A4).
                "risdoc-components": resolve(__dirname, "js/components/risdoc/index.js"),
                "pt-document": resolve(__dirname, "js/components/pt-document/fm-pt-document.js"),
                // Phase G21.1 — Verifica Preview Modal: entry separato per
                // lazy-load (CodeMirror 6 ~30kB gz, no impact su bootstrap).
                "verifica-preview-editor": resolve(__dirname, "js/entries/verifica-preview-editor.js"),
                // G22.S15 — Modal CM6 per TikZ avanzato (folding + preview).
                // Lazy import da checkin-handlers su click "Edit TikZ".
                "tikz-editor-modal": resolve(__dirname, "js/entries/tikz-editor-modal.js"),
                // G22.S15 / Phase 1 — Template Filler (form-based TikZ generator).
                // Lazy import da checkin-handlers su click "Schema modulare".
                "tikz-template-filler": resolve(__dirname, "js/entries/tikz-template-filler.js"),
                // G22.S15 / D — Manager modal con sidebar blocchi (full
                // overview, drag, riordina, edit per-block).
                "tikz-blocks-manager": resolve(__dirname, "js/entries/tikz-blocks-manager.js"),
                // G22.S15.bis — Editor unificato CRUD elementi tex (TikZ
                // grezzo o LaTeX math) con CM6 + preview live. Lazy import
                // da fm-tex-group header (➕) o item row (✏️).
                "tex-element-editor": resolve(__dirname, "js/entries/tex-element-editor.js"),
                // G22.S15.bis Fase 4 — Editor GeoGebra (deployggb.js applet
                // + preview SVG). Lazy import da bottone toolbar GeoGebra.
                "geogebra-editor": resolve(__dirname, "js/entries/geogebra-editor.js"),
                // Phase Roadmap 10 — service worker in un bundle a parte,
                // caricato lazy da bootstrap (requestIdleCallback).
                "perf-sw-register": resolve(__dirname, "js/modules/perf/sw-register.js"),
                // Phase Roadmap 10 — route-specific entries.
                // Caricati conditionally dai template PHP per evitare
                // di bundlare admin code su /login etc.
                "admin": resolve(__dirname, "js/entries/admin.js"),
                // 23/9/2026 — tolto l'input `auth` (js/entries/auth.js): nessuna
                // vista lo chiedeva, e il suo bundle non arrivava a nessun
                // browser (revisione architetturale del 23/9/2026, A-45).
                // 2026-09-04 (revisione P8) — JS che era inline nelle viste
                "area-docente-templates": resolve(__dirname, "js/entries/area-docente-templates.js"),
                "area-docente-profilo":   resolve(__dirname, "js/entries/area-docente-profilo.js"),
                "area-docente-fonti":     resolve(__dirname, "js/entries/area-docente-fonti.js"),
                "area-docente-sposta":    resolve(__dirname, "js/entries/area-docente-sposta.js"),
                "auth-register":          resolve(__dirname, "js/entries/auth-register.js"),
                "admin-sections":         resolve(__dirname, "js/entries/admin-sections.js"),
                "admin-waf-blocks":       resolve(__dirname, "js/entries/admin-waf-blocks.js"),
                "admin-logs":             resolve(__dirname, "js/entries/admin-logs.js"),
                // 2026-09-05 — ultime viste con JavaScript inline portate in entry.
                "admin-templates":              resolve(__dirname, "js/entries/admin-templates.js"),
                "admin-analytics":              resolve(__dirname, "js/entries/admin-analytics.js"),
                "admin-gdpr-authority-export":  resolve(__dirname, "js/entries/admin-gdpr-authority-export.js"),
                "admin-system-deployment":      resolve(__dirname, "js/entries/admin-system-deployment.js"),
                "admin-institutes":             resolve(__dirname, "js/entries/admin-institutes.js"),
                "admin-waf-config":             resolve(__dirname, "js/entries/admin-waf-config.js"),
                "admin-waf-rules":              resolve(__dirname, "js/entries/admin-waf-rules.js"),
                "admin-sidebar-config":         resolve(__dirname, "js/entries/admin-sidebar-config.js"),
                "admin-dashboard":              resolve(__dirname, "js/entries/admin-dashboard.js"),
                // 23/9/2026 (A-17) — la pagina degli strumenti, prima servita grezza da /js/.
                "admin-tools":                  resolve(__dirname, "js/entries/admin-tools.js"),
                "exercises-search":             resolve(__dirname, "js/entries/exercises-search.js"),
                "auth-class-access":            resolve(__dirname, "js/entries/auth-class-access.js"),
                "area-docente-categorie":       resolve(__dirname, "js/entries/area-docente-categorie.js"),
                "teacher-dashboard":            resolve(__dirname, "js/entries/teacher-dashboard.js"),
                // Phase PDF-Import — pagina /teacher/pdf-import (estrazione
                // esercizi da PDF via LLM vision). Entry dedicato, caricato solo
                // dal PdfImportPageController.
                "pdf-import": resolve(__dirname, "js/entries/pdf-import.js"),
                // Pagina dedicata "Modelli per operazione" (/teacher/pdf-import/models).
                "pdf-import-models": resolve(__dirname, "js/entries/pdf-import-models.js"),
            },
            output: {
                entryFileNames: "assets/[name].[hash].js",
                chunkFileNames: "assets/[name].[hash].js",
                assetFileNames: "assets/[name].[hash][extname]",
            },
        },
        sourcemap: false,
        target: "es2020",
        minify: "esbuild",
    },
    server: {
        port: 5173,
        strictPort: false,
        host: "localhost",
    },
});
