// @ts-check
/**
 * Studio di un esercizio: modifica dei gruppi e dei quesiti.
 * Riscrittura di studio_eser_group_quesito.spec.js, fetta 2 del refactoring E2E.
 *
 * Verifica, con la stessa copertura di prima: apertura e chiusura dell'editor
 * del quesito, apertura dell'editor del gruppo, caselle «Giustifica» e
 * «Soluzioni», duplicazione di un quesito, riordino, conferma di eliminazione
 * annullata per quesito e per gruppo, caselle A/R e punteggio.
 *
 * Cosa cambia rispetto a prima:
 *   - l'esercizio è creato dalla spec con la factory (l'originale lavorava su
 *     una copia fissa, la 1293, rigenerata da uno script che riscriveva il
 *     contratto su disco: se un test la lasciava sporca lo sapeva il test dopo);
 *   - le interazioni sono click reali, non `dispatchEvent` (il consenso ai
 *     cookie è pre-impostato per tutta la suite, l'ostacolo che li giustificava
 *     non c'è più);
 *   - le eliminazioni si annullano dalla finestra di conferma vera, invece di
 *     sostituire `FM.Dialog.confirm` con una funzione del test;
 *   - nessuna attesa a tempo: la pagina è pronta quando l'app emette
 *     `fm:verifica-ui-loaded`, il gruppo è aperto quando il suo comando dice
 *     `aria-expanded="true"`, e gli effetti si aspettano con le asserzioni.
 *
 * Nota verificata il 2026-09-06: dopo una modifica che ridisegna il contratto
 * il gruppo torna chiuso, perciò ogni azione riparte da `apri()`.
 */
const { test, expect } = require("../support/test");

test.describe("Studio esercizio — gruppi e quesiti", () => {
    test.beforeEach(async ({ contentFactory, studioEsercizio }) => {
        const esercizio = await contentFactory.exercise({ groups: 2, itemsPerGroup: 2, publish: true });
        await studioEsercizio.vaiA(esercizio.studioUrl);
        await expect(studioEsercizio.titolo).toHaveText(esercizio.title);
    });

    test("l'editor del quesito si apre e si chiude", async ({ studioEsercizio }) => {
        const gruppo = studioEsercizio.gruppo(0);
        const pannello = await studioEsercizio.apriEditorQuesito(gruppo, 0);
        await expect(pannello.locator(".fm-editor-field").first()).toBeVisible();

        await studioEsercizio.chiudiEditorQuesito();
        await expect(studioEsercizio.pannelloEditor).toHaveCount(0);
    });

    test("«Modifica tipologia» apre l'editor del gruppo", async ({ studioEsercizio }) => {
        const gruppo = studioEsercizio.gruppo(0);
        await gruppo.apri();
        await gruppo.premiSulGruppo("Modifica tipologia");
        await expect(studioEsercizio.pannelloEditor.first()).toBeVisible({ timeout: 30_000 });
    });

    test("le caselle «Giustifica» e «Soluzioni» si tolgono e si rimettono", async ({ studioEsercizio }) => {
        const gruppo = studioEsercizio.gruppo(0);
        await gruppo.apri();
        for (const nome of /** @type {const} */ (["Mostra giustificazione", "Mostra soluzioni"])) {
            const casella = gruppo.casella(nome).first();
            await expect(casella).toBeChecked();
            await casella.uncheck();
            await expect(casella).not.toBeChecked();
            await casella.check();
            await expect(casella).toBeChecked();
        }
    });

    test("«Aggiungi quesito» duplica il quesito e il conteggio cresce", async ({ studioEsercizio, teacherPage }) => {
        const gruppo = studioEsercizio.gruppo(0);
        await gruppo.apri();
        const prima = await studioEsercizio.quesiti.count();

        // L'attesa della risposta copre anche i tentativi che il componente fa
        // quando il contratto si ridisegna e il gruppo si richiude.
        const salvataggio = teacherPage.waitForResponse(
            (r) => /\/quesito\/[^/]+\/duplicate$/.test(new URL(r.url()).pathname) && r.request().method() === "POST",
            { timeout: 60_000 },
        );
        await gruppo.premiSuQuesito("Aggiungi quesito", 0);
        expect((await salvataggio).status(), "il duplicato è salvato sul server").toBe(200);

        await expect(studioEsercizio.quesiti).toHaveCount(prima + 1);
    });

    test("«Sposta quesito giù» cambia l'ordine dentro il gruppo", async ({ studioEsercizio }) => {
        const gruppo = await studioEsercizio.gruppoConAlmeno(2);
        await gruppo.apri();
        const prima = await gruppo.idQuesiti();

        await gruppo.premiSuQuesito("Sposta quesito giù", 0);
        await expect.poll(async () => (await gruppo.idQuesiti())[0], {
            message: "il primo quesito non è più in testa",
        }).not.toBe(prima[0]);

        const dopo = await gruppo.idQuesiti();
        expect(dopo, "stessi quesiti, ordine diverso").toHaveLength(prima.length);
        expect([...dopo].sort()).toEqual([...prima].sort());
    });

    test("l'eliminazione di un quesito chiede conferma e, se annullata, non rimuove", async ({ studioEsercizio }) => {
        const gruppo = await studioEsercizio.gruppoConAlmeno(2);
        await gruppo.apri();
        const prima = await studioEsercizio.quesiti.count();

        await gruppo.premiSuQuesito("Elimina quesito", 0);
        const conferma = studioEsercizio.conferma;
        await expect(conferma).toBeVisible();
        await expect(conferma).toContainText("Eliminare quesito");

        await conferma.getByRole("button", { name: "Annulla" }).click();
        await expect(conferma).toBeHidden();
        await expect(studioEsercizio.quesiti).toHaveCount(prima);
    });

    test("l'eliminazione di un gruppo chiede conferma e, se annullata, non rimuove", async ({ studioEsercizio }) => {
        const gruppo = studioEsercizio.gruppo(0);
        await gruppo.apri();
        const gruppiPrima = await studioEsercizio.gruppi.count();

        await gruppo.premiSulGruppo("Elimina tipologia");
        const conferma = studioEsercizio.conferma;
        await expect(conferma).toBeVisible();

        await conferma.getByRole("button", { name: "Annulla" }).click();
        await expect(conferma).toBeHidden();
        await expect(studioEsercizio.gruppi).toHaveCount(gruppiPrima);
    });

    test("le caselle A e R e il punteggio del quesito accettano il valore", async ({ studioEsercizio }) => {
        const gruppo = studioEsercizio.gruppo(0);
        await gruppo.apri();
        const { approfondimento, recupero, punti } = gruppo.controlliQuesito(0);

        await gruppo.spunta(approfondimento);
        await expect(approfondimento).toBeChecked();
        await gruppo.spunta(recupero);
        await expect(recupero).toBeChecked();

        await gruppo.scrivi(punti, "3");
        await expect(punti).toHaveValue("3");
    });
});
