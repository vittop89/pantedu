/**
 * ESLint flat config (Phase 25.E1).
 *
 * Lint per `js/modules/**`, `js/components/**`, `js/entries/**`, i due
 * script del router (`js/fm-router.js`, `js/fm-url-state.js`) e quello del
 * tema all'avvio (`js/tema-iniziale.js`), il service
 * worker e le prove (Playwright, Vitest). Esclude bundle Vite, vendor, file
 * generati e `ui-comp.js` (vedi sotto). La soglia degli avvisi sta in
 * package.json (`npm run lint`), al valore misurato il 23/9/2026: quando gli
 * avvisi calano si abbassa, e non si alza per far passare un avviso nuovo.
 *
 * Stile: rule strette ma con override pragmatici per il codebase
 * esistente (no-unused-vars allow underscore, no-console allow warn/error).
 */

import globals from "globals";

/**
 * Le regole del front-end, in un posto solo: valgono per i moduli ES e per i
 * due script classici del router (23/9/2026), che prima non ne ricevevano
 * nessuna.
 */
const REGOLE_FRONT_END = {
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
    "no-restricted-syntax": ["error", ...vietatiJquery(), ...vietateCopieDellEscape()],
};

/**
 * Le forme vietate di `no-restricted-syntax`, in pezzi riusabili: in una
 * configurazione a blocchi un blocco successivo che ridefinisce la regola
 * sostituisce l'elenco, non lo allunga, e chi aggiunge un divieto a un
 * gruppo di file deve ripetere gli altri.
 */
function vietatiJquery() {
    return [
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
    ];
}

/**
 * 23/9/2026 (revisione architetturale, A-21) — l'escape HTML è una difesa
 * contro l'XSS e sta in un posto solo: `escHtml` di core/dom-utils.js (con gli
 * alias `esc` ed `escAttr`; editor/html-text-utils.js lo riesporta). Ne
 * esistevano venti copie locali, due delle quali non codificavano le
 * virgolette, e una correzione a una non raggiungeva le altre. Si vieta sia
 * il nome (una funzione chiamata come l'escape) sia la sostanza (la tabella
 * `"&" → "&amp;"` scritta a mano, che prende anche le copie con nomi brevi
 * come `esc` o `_e`). core/dom-utils.js ne è escluso più sotto.
 */
function vietateCopieDellEscape() {
    const message = "A-21: l'escape HTML si importa da core/dom-utils.js (escHtml, esc, escAttr), non si riscrive.";
    const nomi = "/^(_|fm)?(escapeHtml|escHtml|EscapeHtml|escAttr|escapeAttr|htmlEscape)$/";
    return [
        { selector: `FunctionDeclaration[id.name=${nomi}]`, message },
        {
            selector: `VariableDeclarator[id.name=${nomi}][init.type=/^(ArrowFunctionExpression|FunctionExpression|LogicalExpression|ConditionalExpression)$/]`,
            message,
        },
        // La tabella scritta a mano: `{ "&": "&amp;" }` o `.replace(/&/g, "&amp;")`.
        { selector: "Property[value.value='&amp;']", message },
        { selector: "CallExpression[callee.property.name=/^replace(All)?$/][arguments.1.value='&amp;']", message },
    ];
}

/**
 * 23/9/2026 (revisione architetturale, A-18) — la sfida del WAF (403
 * `waf_challenge`) la risolve solo `wafFetch` di core/dom-utils.js. Nei file
 * già passati a wafFetch una `fetch` diretta torna a far fallire in silenzio
 * i salvataggi quando la sessione del WAF scade. Gli altri moduli con `fetch`
 * diretta sono ancora molti (voce A-18 del registro del debito): quando uno
 * passa a wafFetch, il suo nome entra qui.
 */
const CLIENT_CHE_RISOLVONO_LA_SFIDA = [
    "js/modules/core/api.js",
    "js/modules/features/checkin-handlers.js",
    "js/modules/features/import-bundle-flow.js",
    "js/modules/features/shortcuts-editor.js",
    "js/entries/teacher-dashboard.js",
];
function vietataFetchDiretta() {
    const message = "A-18: qui la rete passa da wafFetch (o fetchJson) di core/dom-utils.js, che risolve la sfida del WAF e ripete la richiesta.";
    return [
        { selector: "CallExpression[callee.name='fetch']", message },
        { selector: "CallExpression[callee.object.name=/^(window|globalThis|self)$/][callee.property.name='fetch']", message },
    ];
}

export default [
    {
        ignores: [
            "public/build/**",
            "node_modules/**",
            "vendor/**",
            // 2026-09-06 — worktree git di altri rami (ignorati da git): il loro
            // vendor/ porta le fixture JS volutamente rotte di PHPCS (29 errori di parsing).
            ".claude/worktrees/**",
            "storage/**",
            "log/**",
            // 2026-09-23 — tolte otto esclusioni di file e cartelle che non
            // esistono più (`js/script.js`, `js/functions-mod.js`,
            // `js/fm-compat.js`, `js/vendor/**`, `tikzjax-develop/**`, le due
            // `_archive_*`, `js/modules/print/print-export.js`), e con loro
            // l'elenco dei moduli G26 già migrati: un'esclusione morta non
            // protegge niente, e fa credere che il lint guardi meno di quanto
            // guarda. Rientrano nel lint `js/components/**` e i due script del
            // router, `js/fm-router.js` e `js/fm-url-state.js`, caricati da
            // views/layout/app.php su ogni pagina (revisione architetturale del
            // 23/9/2026, A-30).
            // Generati / esterni
            "**/*.min.js",
            "tools/**/*.js",
            // 2026-09-23 — script una tantum fermi (tools/archive/README.md):
            // i tre `.mjs` qui dentro erano gli unici file di tools/archive che
            // ESLint leggeva ancora, senza regole e senza avvisi (A-57).
            "tools/archive/**",
            // Phase 25.E1 — God file legacy, ancora escluso. Il 23/9/2026 aveva
            // 3.453 righe, 30 errori (19 `no-undef`: letture e assegnazioni di
            // variabili mai dichiarate; 11 `prefer-const`) e 235 avvisi. Tutti
            // i `no-undef` stavano nella catena di metodi senza chiamanti
            // (`BtnInOut` → `_processLink` → `_caricaElemRiservati` …), tolta
            // lo stesso giorno (A-45): ora 2.506 righe, 1 errore
            // (`prefer-const`) e 173 avvisi. Voce 25 del registro del debito.
            "js/modules/ui/ui-comp.js",
            // External vendor
            "public/drawio-app/**",
        ],
    },
    {
        // js/entries: entry Vite delle pagine (dal 2026-09-04 anche il JS che era
        // inline nelle viste, revisione P8).
        files: ["js/modules/**/*.js", "js/components/**/*.js", "js/entries/**/*.js"],
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
        rules: REGOLE_FRONT_END,
    },
    {
        files: CLIENT_CHE_RISOLVONO_LA_SFIDA,
        rules: {
            "no-restricted-syntax": ["error", ...vietatiJquery(), ...vietateCopieDellEscape(), ...vietataFetchDiretta()],
        },
    },
    {
        // L'escape di riferimento: l'unico file che può scriverlo (A-21).
        files: ["js/modules/core/dom-utils.js"],
        rules: {
            "no-restricted-syntax": ["error", ...vietatiJquery()],
        },
    },
    {
        // 2026-09-23 — il router SPA e la sincronizzazione dei selettori con
        // l'URL: script classici (IIFE con 'use strict'), caricati con
        // <script defer> da views/layout/app.php su ogni pagina. Erano esclusi
        // «finché legacy», e intanto nessuna regola li guardava (A-30).
        // Con loro js/tema-iniziale.js, la politica del tema all'avvio: il PHP
        // la mette in linea nel <head>, quindi deve reggersi da script (A-50).
        files: ["js/fm-router.js", "js/fm-url-state.js", "js/tema-iniziale.js"],
        languageOptions: {
            ecmaVersion: 2024,
            sourceType: "script",
            globals: {
                ...globals.browser,
                ...globals.es2024,
            },
        },
        rules: REGOLE_FRONT_END,
    },
    {
        // Playwright E2E tests: la maggior parte usa CommonJS (require),
        // dodici spec usano `import` — Playwright li transpila entrambi. Con
        // sourceType "commonjs" ESLint rifiutava gli `import` come errore di
        // parsing (2026-09-04); "module" accetta entrambi perche' `require` e
        // `module` restano globali di Node.
        files: ["tests/e2e/**/*.js"],
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
            // 2026-09-06 — refactoring E2E: le spec importano solo ./support/test
            // (e ./support/env); client HTTP, sessioni e factory arrivano dalle
            // fixture. Vale sia per `require` sia per `import`.
            "no-restricted-syntax": [
                "error",
                {
                    selector: "CallExpression[callee.name='require'][arguments.0.value=/support\\/(api|auth|factories|fixtures|sync)\\//]",
                    message: "Le spec importano solo ./support/test: client, sessioni, factory e segnali arrivano dalle fixture o da test.ts.",
                },
                {
                    selector: "ImportDeclaration[source.value=/support\\/(api|auth|factories|fixtures|sync)\\//]",
                    message: "Le spec importano solo ./support/test: client, sessioni, factory e segnali arrivano dalle fixture o da test.ts.",
                },
            ],
        },
    },
    {
        // 2026-09-07 — le regole scritte in tests/e2e/README.md diventano
        // controlli: finora le rispettava la disciplina, e il conteggio era
        // zero su tutte, ma niente impediva di reintrodurle. Valgono per le
        // sole spec: global-setup.js stampa apposta le URL che ha scoperto, e
        // il supporto in TypeScript ha il suo controllo (npm run e2e:typecheck).
        files: ["tests/e2e/**/*.spec.js"],
        rules: {
            "no-console": ["error", { allow: ["warn", "error"] }],
            "no-restricted-syntax": [
                "error",
                {
                    selector: "CallExpression[callee.name='require'][arguments.0.value=/support\\/(api|auth|factories|fixtures|sync)\\//]",
                    message: "Le spec importano solo ./support/test: client, sessioni, factory e segnali arrivano dalle fixture o da test.ts.",
                },
                {
                    selector: "ImportDeclaration[source.value=/support\\/(api|auth|factories|fixtures|sync)\\//]",
                    message: "Le spec importano solo ./support/test: client, sessioni, factory e segnali arrivano dalle fixture o da test.ts.",
                },
                {
                    selector: "CallExpression[callee.property.name='waitForTimeout']",
                    message: "Niente attese a tempo: asserzione web-first, waitForURL, waitForResponse sull'endpoint vero, o waitForFmEvent su un evento fm:*. Se serve aspettare la fine di un'animazione, attendiAnimazioniFerme.",
                },
                {
                    selector: "Literal[value='networkidle']",
                    message: "Niente networkidle: le pagine dell'applicazione fanno polling e ferme non lo sono mai. Aspetta un segnale dell'app.",
                },
                {
                    selector: "CallExpression[callee.property.name='screenshot'] Property[key.name='path']",
                    message: "Niente schermate salvate a mano: schermata, video e traccia arrivano dal config quando un test fallisce. Per il confronto visivo c'è qualita/regressione-visiva.",
                },
                {
                    selector: "CallExpression[callee.property.name='dispatchEvent'][arguments.0.value='click']",
                    message: "I comandi si premono (locator.click o locator.press), non si attivano con un evento costruito a mano: un clic finto passa anche dove l'utente non arriva.",
                },
            ],
        },
    },
    {
        // 2026-09-23 — il service worker. Fino a oggi ESLint lo leggeva senza
        // applicargli nessuna regola, e dall'8/9 l'espressione di `eJson`
        // conteneva due backspace veri (0x08) al posto dei `\b`: il JSON non si
        // riconosceva più, e nessun controllo se n'è accorto per due settimane.
        // `no-control-regex` li ferma; le altre due sono errori certi e oggi
        // non danno riscontri. È uno script classico, non un modulo.
        files: ["public/sw.js"],
        languageOptions: {
            ecmaVersion: 2024,
            sourceType: "script",
            globals: {
                ...globals.serviceworker,
            },
        },
        rules: {
            "no-control-regex":  "error",
            "no-invalid-regexp": "error",
            "no-undef":          "error",
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
