// @ts-check
/**
 * Modalità modifica: il pannello dell'editor e quella delle sezioni.
 * Riscrittura di edit_btn_modal_persist.spec.js e
 * edit_mode_persist_after_refresh.spec.js.
 *
 * Sono i due casi dell'area che passano davvero dall'interfaccia: aprire
 * l'editor di un quesito e verificare che il pannello non si chiuda da solo, e
 * lasciare attiva la modalità modifica di una sezione, che deve sopravvivere a
 * un nuovo disegno del pannello.
 *
 * Cosa cambia rispetto a prima: niente login nella spec, niente attese a tempo,
 * niente stampe di console e schermate salvate su disco, e l'esercizio su cui
 * si lavora nasce dal test.
 *
 * Un fatto imparato riscrivendo: la barra degli strumenti dell'editor non sta
 * dentro il pannello, è unica per la pagina (`#fm-editor-toolbar-global`) e
 * l'applicazione la mostra finché resta aperto almeno un pannello.
 */
const { test, expect } = require("../support/test");

test.describe("Editor — modalità modifica", () => {
    test("aprendo l'editor di un quesito il pannello resta aperto", async ({ contentFactory, studioEsercizio }) => {
        const esercizio = await contentFactory.exercise({ groups: 1, itemsPerGroup: 2, publish: true });
        await studioEsercizio.vaiA(esercizio.studioUrl);

        const gruppo = studioEsercizio.gruppo(0);
        const pannello = await studioEsercizio.apriEditorQuesito(gruppo, 0);

        // Il pannello ha il campo di scrittura, e resta lì: prima capitava che
        // un secondo evento lo richiudesse.
        const campo = pannello.locator(".fm-editor-field").first();
        await expect(campo).toBeVisible();
        await expect(
            studioEsercizio.page.locator("#fm-editor-toolbar-global"),
            "la barra degli strumenti dell'editor si mostra",
        ).toBeVisible({ timeout: 15_000 });

        // Un clic dentro il campo non chiude nulla.
        await campo.click();
        await expect(campo).toBeVisible();
        await expect(pannello, "il pannello è ancora uno solo").toHaveCount(1);
    });

    test("la modalità modifica di una sezione sopravvive alla chiusura del pannello", async ({ contentFactory, homeDocente, env, naming }) => {
        const { indirizzo, classe, materia } = env.terna;
        const titolo = naming.unique("modifica-attiva");
        await contentFactory.exercise({ topic: titolo, title: titolo, terna: env.terna, publish: true });

        await homeDocente.vaiA();
        await homeDocente.scegliTerna(indirizzo, classe, materia);
        const pannello = await homeDocente.apriSidepage("esercizi");
        const blocco = await homeDocente.attivaModificaSezione(pannello);
        const sezione = await blocco.getAttribute("data-section");

        // Il comando che apre la modifica mostra la spunta finché resta attiva.
        const comando = pannello.getByRole("button", { name: "Modifica sezione" }).first();
        await expect(comando, "il comando segnala che la modifica è attiva").toHaveText("✓");

        // Chiudendo e riaprendo, il pannello si ridisegna leggendo dal
        // database: lo stato di modifica deve tornare com'era. È il caso che
        // la spec storica verificava chiamando il ridisegno da codice.
        await homeDocente.chiudiSidepage("esercizi");
        const riaperto = await homeDocente.apriSidepage("esercizi");
        await expect(
            riaperto.locator(`ul.fm-db-block[data-edit-active="1"][data-section="${sezione}"]`),
            "la stessa sezione è ancora in modifica",
        ).toBeAttached({ timeout: 30_000 });
        await expect(
            riaperto.locator(`ul.fm-db-block[data-section="${sezione}"] .fm-section-add`).first(),
            "e i comandi della modifica si vedono",
        ).toBeVisible({ timeout: 15_000 });
    });
    test("il pannello porta i dati del quesito e disegna quel che si scrive", async ({
        contentFactory, studioEsercizio, teacherPage,
    }) => {
        // Il pannello non è solo il testo: accanto ci sono i dati del quesito
        // (difficoltà, pagina, numero, colore, categoria) e un'anteprima che
        // segue quel che si scrive. L'anteprima è il motivo per cui il campo
        // conserva il sorgente LaTeX invece della formula già disegnata.
        const esercizio = await contentFactory.exercise({ groups: 1, itemsPerGroup: 1, publish: true });
        await studioEsercizio.vaiA(esercizio.studioUrl);
        const pannello = await studioEsercizio.apriEditorQuesito(studioEsercizio.gruppo(0), 0);

        const dati = await pannello.locator(".fm-editor-meta").evaluateAll((campi) =>
            campi.map((c) => c.getAttribute("data-field")));
        for (const campo of ["difficulty", "page", "ex_num", "bg_color", "category_label"]) {
            expect(dati, `il dato «${campo}»`).toContain(campo);
        }

        const scrittura = pannello.locator('[data-field="quesito"]').first();
        await expect(scrittura, "il campo del quesito c'è").toBeVisible();
        expect(
            await scrittura.evaluate((el) => el.innerHTML),
            "e conserva il sorgente, non la formula già disegnata",
        ).not.toMatch(/mjx-container|mjx-math/);

        const anteprima = pannello.locator(".fm-editor-preview").first();
        await expect(anteprima, "l'anteprima c'è").toBeAttached({ timeout: 15_000 });
        await scrittura.click();
        await teacherPage.keyboard.type(" segno di riconoscimento");
        await expect(anteprima, "e segue quel che si scrive")
            .toContainText("segno di riconoscimento", { timeout: 15_000 });
    });
});
