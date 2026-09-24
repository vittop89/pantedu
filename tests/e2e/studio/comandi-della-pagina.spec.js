// @ts-check
/**
 * I comandi della pagina di studio, premuti uno per uno con il loro effetto.
 * Riscrittura di buttons_smoke.spec.js e all_buttons_coverage.spec.js.
 *
 * Le due spec storiche premevano una trentina di comandi ciascuna e non
 * verificavano quasi niente: l'intestazione di `buttons_smoke` lo dichiarava
 * («Il test NON fallisce se qualche endpoint è 404/500 — si limita a
 * RAPPORTARE»), e ogni clic passava da una funzione che inghiottiva l'errore e
 * stampava «[skip]». In 920 righe c'erano trentatré asserzioni, e centotré
 * attese a tempo.
 *
 * Qui i comandi che non sono già coperti da `studio/gruppo-e-quesito` — il
 * salvataggio rapido, la modalità modifica del gruppo, i campi della
 * posizione, l'intestazione della pagina, l'eliminazione confermata — sono
 * premuti e ognuno verifica il proprio effetto. Alla fine si controlla che
 * durante tutto il giro non sia arrivato nessun errore JavaScript e nessuna
 * risposta 4xx o 5xx: era l'unica cosa che le spec storiche verificavano
 * davvero, e resta.
 */
const { test, expect } = require("../support/test");

test.describe("Studio esercizio — comandi della pagina", () => {
    test.beforeEach(async ({ contentFactory, studioEsercizio }) => {
        const esercizio = await contentFactory.exercise({ groups: 1, itemsPerGroup: 3, publish: true });
        await studioEsercizio.vaiA(esercizio.studioUrl);
    });

    test("chiudendo l'editor il quesito si salva da solo", async ({ studioEsercizio, teacherPage }) => {
        // Non c'è un comando di salvataggio da premere: il quesito si salva
        // chiudendo l'editor. Il bottone «Salva quesito» è nel documento ma
        // l'applicazione lo tiene nascosto da sé (voce 53 del debito); le spec
        // storiche lo premevano con un clic finto da codice.
        const gruppo = studioEsercizio.gruppo(0);
        await studioEsercizio.apriEditorQuesito(gruppo, 0);

        const salvataggio = teacherPage.waitForResponse(
            (r) => /\/api\/teacher\/content\//.test(r.url()) && r.request().method() === "POST",
            { timeout: 30_000 },
        );
        await studioEsercizio.chiudiEditorQuesito();
        expect((await salvataggio).status(), "la chiusura salva, e il salvataggio riesce").toBeLessThan(400);
    });

    test("«Modifica tipologia» apre la modifica del gruppo, e premuto di nuovo la chiude salvando", async ({
        studioEsercizio, teacherPage,
    }) => {
        const gruppo = studioEsercizio.gruppo(0);
        await gruppo.apri();

        await gruppo.premiSulGruppo("Modifica tipologia");
        await expect(
            teacherPage.locator('.fm-groupcollex[data-fm-editing="1"]').first(),
            "il gruppo entra in modifica",
        ).toBeAttached({ timeout: 15_000 });

        // Lo stesso comando è anche quello che chiude: il salvataggio parte lì.
        await gruppo.premiSulGruppo("Modifica tipologia");
        await expect(
            teacherPage.locator('.fm-groupcollex[data-fm-editing="1"]'),
            "e premuto di nuovo la chiude",
        ).toHaveCount(0, { timeout: 30_000 });
    });

    test("i campi della posizione dicono dov'è il quesito e dov'è il gruppo", async ({ studioEsercizio, teacherPage }) => {
        await studioEsercizio.gruppo(0).apri();

        const posizioneQuesito = teacherPage.locator(".fm-collection__item .fm-move-position").first();
        await expect(posizioneQuesito, "il campo della posizione del quesito è popolato").toHaveValue(/^\d+$/, { timeout: 15_000 });

        const posizioneGruppo = teacherPage.locator(".fm-groupcollex .fm-checkmod .fm-move-position-problem").first();
        await expect(posizioneGruppo, "e quello del gruppo").toHaveValue(/^\d+$/, { timeout: 15_000 });
    });

    test("l'intestazione della pagina si apre in modifica e si chiude salvando", async ({ teacherPage }) => {
        const comando = teacherPage.locator("#modHeaderBtn").first();
        await expect(comando, "il comando di modifica dell'intestazione è in pagina").toBeVisible({ timeout: 30_000 });

        await comando.click();
        const editor = teacherPage.locator(".fm-header-editor");
        await expect(editor, "l'editor dell'intestazione si apre").toBeVisible({ timeout: 15_000 });
        await expect(editor.locator(".fm-header-html"), "col campo del testo").toBeVisible();
        await expect(
            editor.locator(".fm-header-auto-cb"),
            "e la scelta se aggiungere le fonti in fondo",
        ).toBeAttached();

        const salvataggio = teacherPage.waitForResponse(
            (r) => /header-page\.json/.test(r.url()) && r.request().method() === "PUT",
            { timeout: 30_000 },
        );
        await editor.getByRole("button", { name: "Salva" }).click();
        expect((await salvataggio).status(), "il salvataggio risponde bene").toBeLessThan(400);
        await expect(editor, "e l'editor si chiude").toHaveCount(0, { timeout: 15_000 });
    });

    test("confermando l'eliminazione, il quesito sparisce davvero", async ({ studioEsercizio, teacherPage }) => {
        const gruppo = studioEsercizio.gruppo(0);
        await gruppo.apri();

        const prima = (await gruppo.idQuesiti()).length;
        expect(prima, "il gruppo ha più di un quesito").toBeGreaterThan(1);

        await gruppo.premiSuQuesito("Elimina quesito", prima - 1);
        await teacherPage.getByRole("dialog").getByRole("button", { name: /^\s*OK\s*$/ }).click();

        await expect
            .poll(async () => (await gruppo.idQuesiti()).length, {
                message: "il quesito eliminato sparisce",
                timeout: 30_000,
            })
            .toBe(prima - 1);
    });

    test("ogni quesito porta il proprio selettore dell'origine, con le sue voci", async ({ studioEsercizio, teacherPage }) => {
        await studioEsercizio.gruppo(0).apri();

        const origini = teacherPage.locator(".fm-collection__item select.origin").first();
        await expect(origini, "il selettore dell'origine è nel quesito").toBeAttached({ timeout: 30_000 });
        expect(
            await origini.locator("option").count(),
            "e offre più di una voce",
        ).toBeGreaterThan(1);
    });
});
