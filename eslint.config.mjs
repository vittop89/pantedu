/**
 * ESLint flat config (Phase 25.E1).
 *
 * Lint per `js/modules/**` e `js/components/**`. Esclude legacy
 * `script.js`, bundle Vite, vendor, e file generati.
 *
 * Stile: rule strette ma con override pragmatici per il codebase
 * esistente (no-unused-vars allow underscore, no-console allow warn/error).
 */

import globals from "globals";

export default [
    {
        ignores: [
            "public/build/**",
            "node_modules/**",
            "vendor/**",
            "storage/**",
            "log/**",
            "tikzjax-develop/**",
            "js/vendor/**",
            "_archive_*/**",
            "_archive_phase*/**",
            // Legacy non ancora modulare — lint quando estratto in moduli.
            "js/script.js",
            "js/functions-mod.js",
            "js/fm-router.js",
            "js/fm-url-state.js",
            "js/fm-compat.js",
            // Generati / esterni
            "**/*.min.js",
            "tools/**/*.js",
            // Phase 25.E1 — God files legacy (>1000 LOC). Lint esteso a
            // questi in Phase 25.A quando saranno splittati in sub-moduli.
            // Ognuno è singolo-handler tightly-coupled, refactor pre-lint.
            "js/modules/ui/ui-comp.js",
            // G26.phase6.4 — dom-manager migrato a vanilla.
            // G26.phase6.2 — table-manager migrato a vanilla.
            // G26.phase6.6 — editor-system migrato a vanilla.
            // G26.phase6.1 — content-processor migrato a vanilla.
            // G26.phase5.7 — latex-render migrato a vanilla.
            "js/modules/print/print-export.js",
            // G26.phase6.5 — google-apps migrato a vanilla.
            // G26.phase4.4 — google-apps-script migrato a vanilla.
            // G26.phase4.6 — google-drive-latex-saver migrato a vanilla.
            // G26.phase6.3 — event-handler migrato a vanilla (sortable/draggable jq UI boundary).
            // G26.phase5.5 — state-manager migrato a vanilla.
            // G26.phase4.8 — clone-manager migrato a vanilla.
            // G26.phase5.6 — utilities migrato a vanilla.
            // G26.phase4.2/5.7 — data-manager already vanilla (uses window.jQuery boundary).
            // G26.phase3/5.7 — api-jquery already fetch-based (compat shim).
            // G26.phase5.0 — path-file-ver-extractor already vanilla.
            // G26.phase5.3 — path-manager migrato a vanilla.
            // G26.phase4.9 — list-manager migrato a vanilla.
            // G26.phase5.3 — selection-manager migrato a vanilla.
            // G26.phase4.5 — ver-generation-overlay migrato a vanilla.
            // G26.phase5.3 — container-height migrato a vanilla.
            // G26.phase5.3 — batch-delete migrato a vanilla.
            // G26.phase5.3 — checkmod migrato a vanilla.
            // G26.phase5.1 — toast already vanilla (SyncPanel delegated).
            // G26.phase4.7 — print-info migrato a vanilla.
            // G26.phase5.0 — print-client already vanilla.
            // G26.phase5.1 — verifiche-print-ui already vanilla.
            // G26.phase5.4 — checkin-handlers migrato a vanilla.
            // G26.phase5.1 — verifica-builder already vanilla.
            // G26.phase5.2 — admin-banner-badge already vanilla.
            // G26.phase5.2 — admin-tools already vanilla.
            // G26.phase5.2 — admin-risdoc already vanilla.
            // G26.phase5.2 — risdoc-editor already vanilla.
            // G26.phase5.2 — risdoc-text-editor already vanilla.
            // G26.phase5.2 — teacher-templates already vanilla.
            // G26.phase5.1 — upbar-controls already vanilla.
            // G26.phase5.2 — student-resource-auth already vanilla.
            // G26.phase5.1 — collapsible already vanilla.
            // G26.phase5.2 — verifica-sticky already vanilla.
            // G26.phase5.2 — problem-drag already vanilla.
            // G26.phase5.2 — sidepage-highlight already vanilla.
            // G26.phase5.2 — overleaf-progress already vanilla.
            // G26.phase5.1 — bootstrap-compat uses window.jQuery (allowed).
            // G26.phase4.3 — cookie-consent migrato a vanilla.
            // G26.phase5.0 — logout-widget already vanilla.
            // G26.phase5.2 — store already vanilla.
            "js/components/**",  // Lit WCs: lint quando avranno test E2E coverage > 80%
            // G26 — legacy jQuery files. Esci dalla ignore list quando migrato.
            // G26.phase3 — bootstrap.js rimosso (api-jquery ora fetch-based).
            // G26.phase4.1 — admin-verifica-templates rimosso (false positive:
            // $ usato come local shorthand getElementById, ora byId).
            // G26.phase4.2 — app-state, topbar-modern migrati a vanilla.
            // External vendor
            "public/drawio-app/**",
        ],
    },
    {
        files: ["js/modules/**/*.js", "js/components/**/*.js"],
        languageOptions: {
            ecmaVersion: 2024,
            sourceType: "module",
            globals: {
                ...globals.browser,
                ...globals.es2024,
                FM: "readonly",            // window.FM namespace
                jQuery: "readonly",
                $: "readonly",             // legacy compat
                MathJax: "readonly",
                Config: "readonly",        // legacy window.Config namespace
                AppState: "readonly",      // legacy window.AppState namespace
                EditorSystem: "readonly",  // legacy window.EditorSystem
                UIComp: "readonly",        // legacy window.UIComp
                ContentProcessor: "readonly",  // legacy
                ToastManager: "readonly",  // legacy
                EventHendler: "readonly",  // legacy typo namespace
                CheckmodManager: "readonly",
                PathManager: "readonly",
                DataManager: "readonly",
                DomManager: "readonly",
                PathFileVerExtractor: "readonly",
                savedRange: "writable",
                utilities: "readonly",
                LatexRender: "readonly",
                tikzCheck: "writable",
                InTikz: "writable",
                utilitiesPrint: "readonly",
                TableManager: "readonly",
                PlusArgisChecked: "readonly",
                extractor: "writable",
                App: "readonly",
                Api: "readonly",
                Utils: "readonly",
                CookieConsentManager: "readonly",
                currentFile: "writable",
                editor: "readonly",
                DOMManager: "readonly",
                GoogleAppsScript: "readonly",
                ListManager: "readonly",
                ContainerHeightManager: "readonly",
                LatexRender: "readonly",
                ContentProcessor: "readonly",
                ToastManager: "readonly",
                Endpoints: "readonly",
                visitedLinks: "readonly",
                focusedEditorId: "writable",
                reEncodeAccentedChars: "readonly",
            },
        },
        rules: {
            // Errori certi
            "no-undef":               "error",
            "no-unused-vars":         ["warn", {
                argsIgnorePattern: "^_",
                varsIgnorePattern: "^_",
                caughtErrorsIgnorePattern: "^_?$",  // catch (_) idioma comune
            }],
            "no-redeclare":           "error",
            "no-implicit-globals":    "error",
            "no-shadow-restricted-names": "error",
            "no-self-compare":        "error",
            "no-self-assign":         "error",
            "no-unreachable":         "error",
            "no-dupe-keys":           "error",
            "no-dupe-args":           "error",
            "no-duplicate-case":      "error",
            "no-empty-pattern":       "error",
            "no-prototype-builtins":  "error",
            "valid-typeof":           "error",
            "use-isnan":              "error",
            "no-invalid-regexp":      "error",
            "no-misleading-character-class": "error",

            // Stile moderno
            "no-var":                 "error",
            "prefer-const":           ["error", { destructuring: "all" }],
            "prefer-arrow-callback":  "warn",
            "prefer-template":        "warn",
            "no-useless-concat":      "warn",
            "object-shorthand":       ["warn", "always"],
            "no-useless-rename":      "error",
            "prefer-destructuring":   "off",   // troppo intrusivo sul codice esistente

            // Sicurezza
            "no-eval":                "error",
            "no-implied-eval":        "error",
            "no-new-func":            "error",
            "no-script-url":          "error",
            "no-alert":               "off",   // alert/confirm/prompt usati in UI legacy

            // Console: warn allowed (debug), error allowed (toast fallback)
            "no-console":             ["warn", { allow: ["warn", "error", "debug"] }],

            // Disabilitate per evitare false positive sul codebase legacy
            "no-inner-declarations":  "off",
            "no-async-promise-executor": "off",

            // G26 — jQuery removal guard. Vieta nuovi usi di jQuery in moduli
            // post-G24. I file con jQuery legacy sono nell'ignores list sopra;
            // quando migrati, il loro nome esce da ignores e ricade in questa
            // rule. Pattern bloccati:
            //   - `$(selector)` / `$.ajax(...)` / `jQuery(...)`
            //   - `import "jquery"` / `import { ... } from "jquery"`
            //   - `import "./api-jquery"` (force use api.js fetch-based)
            "no-restricted-globals": [
                "error",
                { name: "$",      message: "G26: jQuery vietato — usa querySelector/closest/fetch. Vedi docs/plans/G26-conversion-patterns.md" },
                { name: "jQuery", message: "G26: jQuery vietato — usa vanilla JS." },
            ],
            "no-restricted-imports": [
                "error",
                {
                    paths: [
                        { name: "jquery", message: "G26: jQuery vietato — usa fetch + vanilla DOM." },
                    ],
                    // G26.phase3 — api-jquery.js è ora fetch-based internally.
                    // Restriction rimossa: import legittimo (transition compat).
                    // Future: rename a `api-legacy.js` o consolidare con `api.js`.
                },
            ],
            // Vieta `$.method(...)` (es. $.ajax, $.getJSON). I tutori catturano
            // `$.X` via `MemberExpression[object.name='$']`.
            "no-restricted-syntax": [
                "error",
                {
                    selector: "MemberExpression[object.name='$']",
                    message: "G26: $.X jQuery API vietata — usa fetch/Promise."
                },
                {
                    selector: "CallExpression[callee.name='$']",
                    message: "G26: $() jQuery selector vietato — usa document.querySelector(All)."
                },
                {
                    selector: "CallExpression[callee.name='jQuery']",
                    message: "G26: jQuery() vietato — usa vanilla JS."
                },
            ],
        },
    },
    {
        // Playwright E2E tests usano CommonJS
        files: ["tests/e2e/**/*.js"],
        languageOptions: {
            ecmaVersion: 2024,
            sourceType: "commonjs",
            globals: {
                ...globals.node,
                ...globals.browser,
            },
        },
        rules: {
            "no-undef": "error",
            "no-unused-vars": "warn",
            "no-console": "off",
        },
    },
    {
        // Vitest unit tests (G24+G26) usano ESM
        files: ["tests/js-unit/**/*.js"],
        languageOptions: {
            ecmaVersion: 2024,
            sourceType: "module",
            globals: {
                ...globals.node,
                ...globals.browser,
            },
        },
        rules: {
            "no-undef": "error",
            "no-unused-vars": "warn",
            "no-console": "off",
        },
    },
];
