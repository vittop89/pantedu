// @ts-check
/**
 * Il catalogo dei modelli: la colonna che riassume ogni riga.
 *
 * Segnalazione dell'utente (21/9/2026), con schermata: «che significano 8
 * override, 8 drift, ok... visib tutti a 0 ecc...», e il bottone «Gestisci»
 * tagliato a metà dal bordo.
 *
 * Misurato allora: la colonna è a larghezza fissa e le pastiglie non andavano
 * a capo, quindi il contenuto usciva dalla cella e finiva disegnato SOPRA i
 * bottoni della colonna accanto — sforava anche la riga con tutti zeri. E ogni
 * pastiglia era dipinta dello stesso azzurro da una regola più forte di quelle
 * dei colori, così «da riallineare» era indistinguibile da «copie».
 *
 * Qui si misura quello che si vede: niente che esce dalla cella, niente zeri
 * scritti, e ogni pastiglia con la sua spiegazione.
 */
const { test, expect } = require("../support/test");

test.describe("Amministrazione — il catalogo dei modelli", () => {
    test("le pastiglie stanno dentro la loro colonna", async ({ adminPage }) => {
        await adminPage.goto("/admin/templates");
        const riga = adminPage.locator("tr[data-template-id]").first();
        await expect(riga).toBeVisible();

        // Si misura il bordo destro delle PASTIGLIE, non quello del loro
        // contenitore: con `flex-wrap: nowrap` il contenitore resta largo
        // quanto la cella e sono i figli a uscire. La prima versione di questa
        // riga guardava il contenitore, e passava anche col difetto dentro.
        const sfora = await riga.evaluate((tr) => {
            const cella = tr.querySelector("td.c-stats");
            if (!cella) return Number.NaN; // la colonna non c'è: lo dice l'asserzione
            const destra = cella.getBoundingClientRect().right;
            const figli = [...cella.querySelectorAll(".fm-ar-inline-list > *")];
            return Math.round(Math.max(...figli.map((f) => f.getBoundingClientRect().right)) - destra);
        });
        expect(Number.isNaN(sfora), "la colonna delle pastiglie c'è").toBe(false);

        expect(sfora, "le pastiglie non finiscono sopra la colonna accanto").toBeLessThanOrEqual(1);
    });

    /**
     * Nota onesta: con i dati seminati questa prova NON scatta col difetto
     * dentro — misurato il 21/9/2026: lì il traboccamento è di 23px e finisce
     * nel margine, mentre nella schermata dell'utente era di 41px e copriva
     * «Gestisci». Quella che misura è la prova qui sopra; questa dice il
     * sintomo com'è stato raccontato, e vale da guardia se peggiora.
     */
    test("i bottoni delle azioni si vedono tutti", async ({ adminPage }) => {
        await adminPage.goto("/admin/templates");
        const riga = adminPage.locator("tr[data-template-id]").first();
        const gestisci = riga.locator('button[data-action="manage"]');

        await expect(gestisci).toBeVisible();
        // Il traboccamento arriva da sinistra e copre il bordo dei bottoni, non
        // il loro centro: si guarda chi c'è davvero sotto il dito a pochi pixel
        // dall'inizio di ognuno. Guardare solo il centro passava col difetto.
        const coperti = await riga.evaluate((tr) => {
            const fuori = [];
            for (const b of tr.querySelectorAll(".fm-ar-actions > *")) {
                const r = b.getBoundingClientRect();
                const sopra = document.elementFromPoint(r.left + 3, r.top + r.height / 2);
                if (sopra !== b && !b.contains(sopra)) {
                    fuori.push(`${b.textContent.trim().slice(0, 12)} ← ${sopra?.className || "?"}`);
                }
            }
            return fuori;
        });

        expect(coperti, "niente disegnato sopra i bottoni delle azioni").toEqual([]);
    });

    test("niente zeri e niente parole del database", async ({ adminPage }) => {
        await adminPage.goto("/admin/templates");
        const celle = adminPage.locator("td.c-stats");
        await expect(celle.first()).toBeVisible();

        // Una pastiglia per volta, unite da uno spazio: prese in blocco i testi
        // si attaccano («tutti0 visib0 collab»), e i confini di parola delle
        // espressioni qui sotto non scattano più. Prima versione: passava col
        // difetto in pagina.
        const testo = (await adminPage.locator("td.c-stats .fm-ar-pill, td.c-stats span").allTextContents())
            .join(" ").toLowerCase();

        expect(testo, "nessuno zero scritto").not.toMatch(/\b0\b/);
        for (const gergo of ["override", "drift", "visib", "collab"]) {
            expect(testo, `parola del database in pagina: ${gergo}`).not.toMatch(new RegExp(`\\b${gergo}\\b`));
        }
    });

    test("ogni pastiglia dice che cosa significa, passandoci sopra", async ({ adminPage }) => {
        await adminPage.goto("/admin/templates");
        const pastiglie = adminPage.locator("td.c-stats .fm-ar-pill");
        // La tabella la disegna il JavaScript dopo il caricamento: `count()`
        // non aspetta niente, e senza questa riga la prova conterebbe zero
        // pastiglie su una pagina ancora vuota.
        await expect(pastiglie.first()).toBeVisible();
        const quante = await pastiglie.count();
        expect(quante, "almeno una pastiglia per riga").toBeGreaterThan(0);

        for (let i = 0; i < quante; i++) {
            const titolo = await pastiglie.nth(i).getAttribute("title");
            expect(titolo, `la pastiglia ${i} è senza spiegazione`).toBeTruthy();
            expect((titolo || "").length, `la spiegazione della pastiglia ${i} è troppo corta`).toBeGreaterThan(15);
        }
    });
});
