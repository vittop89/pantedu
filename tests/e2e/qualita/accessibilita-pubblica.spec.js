// @ts-check
/**
 * Accessibilità delle pagine pubbliche (WCAG 2.2 AA).
 * Riscrittura di a11y_wcag_aa.spec.js.
 *
 * Il controllo automatico non dice se una pagina è accessibile — quello lo dice
 * una persona — ma riconosce le violazioni che si possono riconoscere da sole:
 * un'immagine senza descrizione, un campo senza etichetta, un contrasto sotto
 * la soglia, un comando che non si raggiunge da tastiera. Sono quelle che
 * rendono una pagina inutilizzabile a chi usa uno screen reader, e sono anche
 * quelle che rientrano negli obblighi di legge: qui si fermano le violazioni
 * gravi e critiche.
 *
 * Questa è la spec che gira anche in integrazione continua, contro un server
 * PHP senza database: per questo non usa nessuna sessione. Le pagine riservate
 * stanno in `accessibilita-riservata.spec.js`, che ha bisogno del database.
 *
 * Un fatto imparato riscrivendo: il controllo misura il contrasto fra testo e
 * sfondo, e a metà di una dissolvenza il colore che misura è una mescolanza fra
 * quello di partenza e quello d'arrivo — il contrasto risulta insufficiente su
 * elementi che a riposo sono a norma. La spec storica aspettava novecento
 * millisecondi fissi; qui si aspetta che nessuna animazione sia in corso,
 * saltando quelle senza fine come gli indicatori di caricamento.
 */
const { test, expect, preparaPagina, PAGINE_PUBBLICHE } = require("../support/test");
const AxeBuilder = require("@axe-core/playwright").default;

/** Le regole del controllo: WCAG 2.0, 2.1 e 2.2 fino al livello AA, più le buone pratiche. */
const REGOLE = ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22a", "wcag22aa", "best-practice"];

/** Apre la pagina, la analizza e riporta le violazioni gravi in forma leggibile. */
async function violazioniGravi(/** @type {import("@playwright/test").Page} */ pagina, /** @type {string} */ percorso) {
    await preparaPagina(pagina, percorso);

    const esito = await new AxeBuilder({ page: pagina }).withTags(REGOLE).analyze();
    return esito.violations
        .filter((v) => v.impact === "critical" || v.impact === "serious")
        .map((v) => `${v.id} (${v.impact}, ${v.nodes.length} elementi): ${v.description}\n    ${v.nodes[0]?.html?.slice(0, 160) ?? ""}`);
}

test.describe("Qualità — accessibilità delle pagine pubbliche", () => {
    for (const { percorso, nome } of PAGINE_PUBBLICHE) {
        test(`la ${nome} non ha violazioni gravi`, async ({ page }) => {
            const violazioni = await violazioniGravi(page, percorso);
            expect(violazioni, `violazioni su ${percorso}:\n${violazioni.join("\n")}`).toEqual([]);
        });
    }

    test("il collegamento «salta al contenuto» si raggiunge col primo tasto di tabulazione", async ({ page }) => {
        await page.goto("/");
        await page.keyboard.press("Tab");

        const inFocus = await page.evaluate(() => {
            const el = document.activeElement;
            if (!el) return null;
            return {
                tag: el.tagName.toLowerCase(),
                href: el.getAttribute("href"),
                testo: (el.textContent ?? "").trim().slice(0, 60),
            };
        });
        expect(inFocus, "qualcosa riceve il fuoco").not.toBeNull();
        expect(inFocus?.tag, "ed è un collegamento").toBe("a");
        expect(inFocus?.href, "che porta al contenuto della pagina").toMatch(/#fm-content/);
        expect(inFocus?.testo, "e lo dice").toMatch(/salta al contenuto/i);
    });

    test("il comando del tema scuro dichiara il proprio stato a chi non lo vede", async ({ page }) => {
        await page.goto("/");
        const comando = page.locator(".fm-sb-dark, .fm-darkmode-mini").first();
        await expect(comando, "il comando è in pagina").toBeVisible({ timeout: 30_000 });
        await expect(comando, "e dice se è premuto").toHaveAttribute("aria-pressed", /true|false/);
        await expect(comando, "e che cosa fa").toHaveAttribute("aria-label", /modalit.*scura/i);
    });
});
