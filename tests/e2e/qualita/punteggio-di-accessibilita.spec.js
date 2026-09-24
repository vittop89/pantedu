// @ts-check
/**
 * Il punteggio di accessibilità delle pagine pubbliche.
 * Riscrittura di lighthouse_a11y.spec.js.
 *
 * Lighthouse dà un voto da 0 a 100 sui controlli di accessibilità che si
 * possono fare da soli: contrasto, etichette, ordine delle intestazioni,
 * lingua dichiarata. Copre un quarto scarso dei criteri WCAG — il resto si
 * verifica con axe (`qualita/accessibilita-pubblica`) e a mano — ma è un
 * numero che si può guardare a colpo d'occhio, e che non deve scendere.
 *
 * Lighthouse ha bisogno di comandare il browser da fuori, e per questo apre
 * un browser suo invece di usare quello della suite.
 *
 * Cosa cambia rispetto a prima: il punteggio è verificato con
 * un'asserzione — prima lo stampava e basta, e a bocciare era solo la soglia
 * interna dello strumento — e l'indirizzo di partenza non è più `/` relativo a
 * un browser che non sa dove sia il sito.
 */
const fs = require("node:fs");
const os = require("node:os");
const path = require("node:path");
const { test, expect } = require("../support/test");
const { chromium } = require("@playwright/test");

/** @type {typeof import("playwright-lighthouse").playAudit} */
let esamina;

test.beforeAll(async () => {
    ({ playAudit: esamina } = await import("playwright-lighthouse"));
});

const PAGINE = [
    { percorso: "/", nome: "pagina iniziale" },
    { percorso: "/login", nome: "pagina di accesso" },
    { percorso: "/legal/tos", nome: "condizioni d'uso" },
    { percorso: "/privacy/informativa", nome: "informativa sulla privacy" },
    { percorso: "/accessibility", nome: "dichiarazione di accessibilità" },
];

/** Sotto questo voto la pagina è da sistemare. */
const VOTO_MINIMO = 90;

/**
 * Un browser per Lighthouse, con la porta di debug scelta da lui (14/9/2026).
 *
 * Era la 9222 fissa. La suite della CI e quella lanciata a mano stanno nella
 * stessa WSL: il 14/9/2026 una suite locale partita durante la E2E dopo
 * l'unione ha dato tre `ConnectionClosedError`, e peggio poteva agganciarsi al
 * Chrome dell'altra suite e dare il voto di un'altra pagina. Con
 * `--remote-debugging-port=0` la porta la sceglie Chrome, e la scrive nel file
 * `DevToolsActivePort` del suo profilo: quella letta lì è la porta di questo
 * browser, non una che si spera libera.
 *
 * @param {string | undefined} baseURL
 */
async function browserConPortaPropria(baseURL) {
    const profilo = fs.mkdtempSync(path.join(os.tmpdir(), "pantedu-lighthouse-"));
    const contesto = await chromium.launchPersistentContext(profilo, {
        baseURL,
        args: ["--remote-debugging-port=0"],
    });
    const file = path.join(profilo, "DevToolsActivePort");
    for (let i = 0; i < 200 && !fs.existsSync(file); i++) {
        await new Promise((fatto) => setTimeout(fatto, 50));
    }
    const porta = Number(fs.existsSync(file) ? fs.readFileSync(file, "utf8").split("\n")[0] : NaN);
    if (!Number.isInteger(porta) || porta <= 0) {
        await contesto.close();
        fs.rmSync(profilo, { recursive: true, force: true });
        throw new Error("Chrome non ha scritto la sua porta di debug in DevToolsActivePort");
    }
    return {
        contesto,
        porta,
        chiudi: async () => {
            await contesto.close();
            fs.rmSync(profilo, { recursive: true, force: true });
        },
    };
}

test.describe("Qualità — punteggio di accessibilità", () => {
    for (const { percorso, nome } of PAGINE) {
        test(`la ${nome} sta sopra ${VOTO_MINIMO} su 100`, async ({ baseURL }) => {
            test.setTimeout(300_000);
            const { contesto, porta, chiudi } = await browserConPortaPropria(baseURL);
            try {
                const pagina = await contesto.newPage();
                await pagina.goto(`${baseURL ?? ""}${percorso}`, { waitUntil: "load", timeout: 60_000 });

                const esito = await esamina({
                    page: pagina,
                    port: porta,
                    thresholds: { accessibility: VOTO_MINIMO, performance: 0, "best-practices": 0, seo: 0, pwa: 0 },
                    reports: {
                        formats: { html: true, json: true },
                        name: `accessibilita-${percorso.replace(/\W+/g, "-").replace(/^-|-$/g, "") || "home"}`,
                        directory: "tests/e2e-results/accessibilita",
                    },
                });

                const voto = Math.round((esito?.lhr?.categories?.["accessibility"]?.score ?? 0) * 100);
                expect(voto, `${percorso} ha preso ${voto} su 100`).toBeGreaterThanOrEqual(VOTO_MINIMO);
            } finally {
                await chiudi();
            }
        });
    }
});
