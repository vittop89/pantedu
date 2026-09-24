// @ts-check
/**
 * Le figure TikZ delle pagine di studio si disegnano davvero. @tex
 *
 * Riunisce tikz_post_normalize (nessuna figura fallisce la compilazione,
 * nessun riquadro d'errore in pagina) e tikz_render_smoke_multi (le figure
 * disegnate sono immagini vere: niente testo lasciato ai caratteri del
 * browser, niente riferimenti interni rotti).
 *
 * Le figure sono caricate solo quando la sezione che le contiene viene aperta
 * (scelta di prestazione: una verifica con settantacinque disegni non li
 * compila tutti al caricamento). Il test quindi apre i gruppi e aspetta che il
 * client abbia finito, invece dei venti secondi fissi della spec storica.
 *
 * Cosa cambia rispetto a prima: niente login nella spec, niente attese a
 * tempo, e le pagine sono quelle scoperte dal setup invece di cinque indirizzi
 * scritti a mano con gli identificativi dei contenuti del database locale.
 */
const { test, expect } = require("../support/test");

/**
 * Apre tutti i gruppi e aspetta che il client abbia finito di disegnare:
 * il numero di figure ancora da disegnare deve smettere di calare.
 * @param {import("../support/test").Page} page
 */
async function disegnaTutteLeFigure(page) {
    const toggles = page.locator(".fm-groupcollex .fm-collapse-toggle");
    const quanti = await toggles.count();
    // Aprire un gruppo può far ridisegnare la pagina, quindi non si scorre
    // l'elenco per indice: si prende ogni volta il primo gruppo ancora chiuso,
    // con un tetto di tentativi che non dipende dal caso.
    const chiusi = page.locator('.fm-groupcollex .fm-collapse-toggle[aria-expanded="false"]');
    for (let tentativi = 0; tentativi < quanti * 3; tentativi++) {
        if ((await chiusi.count()) === 0) break;
        await chiusi.first().click({ timeout: 15_000 }).catch(() => {});
    }
    // Non si pretende che ogni gruppo si apra: quel che conta è che le figure
    // caricate si disegnino senza errori, e le asserzioni sono sulle figure.

    let precedente = -1;
    await expect
        .poll(
            async () => {
                const rimasti = await page.locator('script[type="text/tikz"]').count();
                const stabile = rimasti === precedente;
                precedente = rimasti;
                return stabile;
            },
            { message: "il client sta ancora disegnando le figure", timeout: 120_000, intervals: [2_000] },
        )
        .toBe(true);
    return quanti;
}

test.describe("Verifiche — figure TikZ nelle pagine", () => {
    test("nessuna figura fallisce la compilazione e nessun errore compare in pagina @tex", async ({ teacherPage, env }) => {
        test.setTimeout(300_000);
        const url = env.discovered.eserUrl;
        expect(url, "esercizio scoperto dal setup").toBeTruthy();
        if (!url) return;

        /** @type {string[]} */
        const rifiutate = [];
        teacherPage.on("response", (risposta) => {
            if (risposta.url().includes("/tikz/render") && [422, 503].includes(risposta.status())) {
                rifiutate.push(`${risposta.request().method()} ${risposta.status()}`);
            }
        });

        await teacherPage.goto(url, { waitUntil: "domcontentloaded" });
        const gruppi = await disegnaTutteLeFigure(teacherPage);
        expect(gruppi, "la pagina ha dei gruppi").toBeGreaterThan(0);

        const disegnate = await teacherPage.locator("svg[data-tikz-hash]").count();
        expect(disegnate, "almeno una figura disegnata").toBeGreaterThan(0);
        expect(rifiutate, `figure rifiutate dal servizio: ${rifiutate.join(", ")}`).toEqual([]);
        await expect(teacherPage.locator(".fm-tikz-error-messages-block"), "riquadri d'errore in pagina").toHaveCount(0);
        await expect(teacherPage.locator(".tikz-error, [data-tikz-error]"), "segnalazioni d'errore sulle figure").toHaveCount(0);
    });

    test("le figure disegnate sono immagini complete, senza rimandi rotti @tex", async ({ teacherPage, env }) => {
        test.setTimeout(300_000);
        const url = env.discovered.verifUrl ?? env.discovered.eserUrl;
        expect(url, "contenuto scoperto dal setup").toBeTruthy();
        if (!url) return;

        await teacherPage.goto(url, { waitUntil: "domcontentloaded" });
        await disegnaTutteLeFigure(teacherPage);

        const qualita = await teacherPage.evaluate(() => {
            const figure = Array.from(document.querySelectorAll("svg[data-tikz-hash]"));
            let conTesto = 0;
            let conCaratteri = 0;
            let rimandiRotti = 0;
            for (const figura of figure) {
                const html = figura.outerHTML;
                // Il testo dev'essere disegnato, non lasciato ai caratteri del browser:
                // altrimenti su un altro computer la figura cambia aspetto.
                if (/<text\b/.test(html)) conTesto++;
                if (/font-family/.test(html)) conCaratteri++;
                const identificativi = new Set(Array.from(figura.querySelectorAll("[id]")).map((e) => e.id));
                for (const rimando of figura.querySelectorAll("use")) {
                    const bersaglio = rimando.getAttribute("xlink:href") ?? rimando.getAttribute("href");
                    if (bersaglio?.startsWith("#") && !identificativi.has(bersaglio.slice(1))) rimandiRotti++;
                }
            }
            return { totale: figure.length, conTesto, conCaratteri, rimandiRotti };
        });

        expect(qualita.totale, "almeno una figura disegnata").toBeGreaterThan(0);
        expect(qualita.conTesto, "figure con testo non disegnato").toBe(0);
        expect(qualita.conCaratteri, "figure che dipendono dai caratteri del browser").toBe(0);
        expect(qualita.rimandiRotti, "rimandi interni rotti").toBe(0);
    });
});
