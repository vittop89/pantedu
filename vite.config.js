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
                // Phase Roadmap 10 — perf entries split-bundle (RUM + SW).
                // Caricati lazy da bootstrap (requestIdleCallback) cosi'
                // non bloccano paint critical path.
                "perf-web-vitals": resolve(__dirname, "js/modules/perf/web-vitals.js"),
                "perf-sw-register": resolve(__dirname, "js/modules/perf/sw-register.js"),
                // Phase Roadmap 10 — route-specific entries.
                // Caricati conditionally dai template PHP per evitare
                // di bundlare admin code su /login etc.
                "admin": resolve(__dirname, "js/entries/admin.js"),
                "auth":  resolve(__dirname, "js/entries/auth.js"),
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
