// @ts-check
/**
 * Configurazione Playwright per e2e Pantedu.
 *
 * Target: il server di sviluppo in WSL, `bash tools/dev/wsl/server.sh`, su
 * http://127.0.0.1:8765 (dal 10 settembre 2026, al posto di XAMPP su
 * pantedu.local), oppure l'indirizzo in `FM_E2E_BASE_URL` (è così che gira in
 * integrazione continua, su 8000).
 * Autenticazione: tre utenti reali, con la sessione tenuta in cache da
 * `tests/e2e/support/auth/login.ts`; le credenziali arrivano da `.env.local`
 * o dall'ambiente, mai dalle spec.
 */

const { defineConfig, devices } = require("@playwright/test");

// ADR-024 — carica E2E_*/FM_E2E_* da .env.local (gitignored). Le credenziali
// docente NON vivono più hardcoded nei file .spec committati: gli spec leggono
// process.env.E2E_TEACHER_USER / E2E_TEACHER_PASS. Fallback: env già esportate.
// 2026-09-05 — legge anche TEX_COMPILE_ENDPOINT/SECRET: le spec che compilano
// davvero (SyncTeX, PDF) parlano con il microservizio TeX locale
// (tools/dev/tex-service-local.mjs).
// 2026-09-07 — e METRICS_BEARER_TOKEN: le metriche Prometheus sono protette da
// un gettone, che il client di osservabilità legge da qui invece di averlo
// scritto in chiaro dentro la spec.
try {
    const fs = require("fs");
    const path = require("path");
    const raw = fs.readFileSync(path.join(__dirname, ".env.local"), "utf8");
    for (const line of raw.split(/\r?\n/)) {
        const m = line.match(/^\s*(E2E_[A-Z0-9_]+|FM_E2E_[A-Z0-9_]+|TEX_COMPILE_(?:ENDPOINT|SECRET)|METRICS_BEARER_TOKEN)\s*=\s*(.*?)\s*$/);
        const name = m?.[1];
        // 2026-09-07 — un valore non fra virgolette finisce dove comincia un
        // commento in linea: è la regola di dotenv, che l'applicazione usa per
        // leggere lo stesso file. Senza questo taglio il gettone delle metriche
        // arrivava ai test con il commento attaccato, e il server lo rifiutava.
        const value = (m?.[2] ?? "").replace(/\s+#.*$/, "").trim();
        if (m && name !== undefined && !process.env[name]) process.env[name] = value;
    }
} catch (_) { /* .env.local assente in CI: credenziali via env di shell */ }

// Alias storici usati da alcune spec: una sola coppia di credenziali docente.
/** @param {string} name @param {string | undefined} value */
const alias = (name, value) => { if (!process.env[name] && value) process.env[name] = value; };
alias("PLAYWRIGHT_TEST_PASSWORD", process.env.E2E_TEACHER_PASS);
alias("FM_E2E_USER", process.env.E2E_TEACHER_USER || "docente.uno");
alias("FM_E2E_PASS", process.env.E2E_TEACHER_PASS);
alias("FM_E2E_VPS_ENDPOINT", process.env.TEX_COMPILE_ENDPOINT);
alias("FM_E2E_VPS_SECRET", process.env.TEX_COMPILE_SECRET);

const BASE_URL = process.env.FM_E2E_BASE_URL || "http://127.0.0.1:8765";

module.exports = defineConfig({
    testDir: "./tests/e2e",
    // 2026-09-05 — scopre URL di studio con contenuti reali (FM_E2E_ESER_URL, ...)
    // cosi' le spec non dipendono da terne scritte a mano vuote nel DB locale.
    globalSetup: require.resolve("./tests/e2e/global-setup.js"),
    // 2026-09-07 — 90 secondi per test, quasi cinque volte il più lento misurato
    // (18,5 s nel giro del 7 settembre; nessun test supera i venti). Prima
    // erano 30 s nel config e 317 `test.setTimeout` sparsi nelle spec, da 2 a
    // 15 minuti: non proteggevano da niente e facevano sì che un test piantato
    // bloccasse la suite per un quarto d'ora. Restano ventiquattro tetti
    // espliciti, tutti su test che compilano LaTeX o che passano da Lighthouse.
    timeout: 90_000,
    expect: { timeout: 5_000 },
    fullyParallel: false,          // sessioni sidestepping fra test
    // 2026-09-06 — un solo worker: senza questa riga Playwright ne apre metà dei core (16 su
    // questa macchina) e i FILE girano in parallelo su un unico DB e sugli stessi utenti di
    // test: le spec di condivisione si sovrascrivevano i flag a vicenda, verifica_tex_production
    // cancellava le verifiche mentre altre spec le usavano, e sotto quel carico comparivano
    // 403 CSRF e porte esaurite. fullyParallel:false da solo serializza solo i test di un file.
    workers: 1,
    retries: 0,
    // 2026-09-23 — in CI un `test.only` dimenticato fa fallire il giro invece
    // di ridurre la suite alle poche prove marcate, tutte verdi (revisione
    // architetturale, A-29). GitHub imposta `CI` su tutti i runner, anche su
    // quelli di casa; in locale `.only` resta uno strumento di lavoro.
    forbidOnly: !!process.env.CI,
    // 2026-09-23 — in CI `line` al posto di `list`, che stampa una riga per
    // prova; il JSON serve a `tools/ci/salti-e2e.mjs`, che fa fallire il giro
    // se una prova si è saltata senza essere dichiarata. Prima la CI passava
    // `--reporter=line`, che sostituisce questo elenco: `results.json` non si
    // scriveva e i salti non si contavano (A-29).
    reporter: [
        [process.env.CI ? "line" : "list"],
        ["json", { outputFile: "tests/e2e-results/results.json" }],
        ["html", { open: "never" }],
    ],

    use: {
        baseURL:       BASE_URL,
        trace:         "retain-on-failure",  // 2026-09-06 — con retries: 0 «on-first-retry» non registrava mai una trace
        screenshot:    "only-on-failure",
        video:         "retain-on-failure",
        navigationTimeout: 15_000,
        actionTimeout:     8_000,

        // Niente `extraHTTPHeaders` con X-Audit-Reason (23/9/2026, A-69).
        // C'era dal 2/9: valeva per OGNI richiesta del contesto, comprese
        // quelle che la pagina fa da sé — i moduli e le fetch dei pannelli.
        // Così un pulsante che non mandava la motivazione in produzione
        // (AUDIT_REASON_MODE=enforce → 400) qui passava lo stesso: «Sposta»
        // del pannello Sezioni rispondeva 400 da settimane, e la suite era
        // verde. La motivazione delle chiamate dirette alle API la mette ora
        // il client della suite (tests/e2e/support/api/http.ts); quella dei
        // pulsanti deve metterla l'applicazione.
    },

    projects: [
        {
            name: "chromium",
            use: { ...devices["Desktop Chrome"] },
        },
    ],

    outputDir: "tests/e2e-results",
});
