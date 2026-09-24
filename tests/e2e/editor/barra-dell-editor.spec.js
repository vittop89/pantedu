// @ts-check
/**
 * La barra dell'editor del quesito, premuta come la premerebbe un docente.
 * Riscrittura di studio_eser_editor_toolbar.spec.js.
 *
 * Il comportamento di ogni funzione — come si annida una lista, come si marca
 * il grassetto, come si sostituisce una parola — è già verificato dalle
 * novantaquattro prove di modulo in `editor/moduli/`. Quello che qui manca, e
 * che quelle non possono dire, è se i comandi della barra sono collegati a
 * quelle funzioni nella pagina vera: un bottone che non fa niente supera tutte
 * le prove di modulo del mondo.
 *
 * Un fatto dell'interfaccia: i comandi del formato hanno un testo proprio
 * («B», «I», «U»), quindi il loro nome accessibile è quella lettera e non il
 * suggerimento; si prendono con `getByTitle`, che è la via semantica per il
 * suggerimento.
 *
 * Cosa cambia rispetto a prima: i comandi si premono davvero, per nome, invece
 * di essere cercati con un `querySelector` dentro `page.evaluate` e attivati
 * con un evento costruito a mano; l'esercizio su cui si lavora nasce dal test
 * invece di essere la copia fissa numero 1293, che veniva rigenerata a ogni
 * test lanciando `node tools/dev/gen_proof_contract.cjs` — un processo esterno
 * non dichiarato, che su un'altra macchina avrebbe fatto fallire tutto; e
 * delle dodici attese a tempo non resta nulla.
 */
const { test, expect } = require("../support/test");

test.describe("Editor — barra degli strumenti del quesito", () => {
    test.beforeEach(async ({ contentFactory, studioEsercizio }) => {
        const esercizio = await contentFactory.exercise({ groups: 1, itemsPerGroup: 2, publish: true });
        await studioEsercizio.vaiA(esercizio.studioUrl);
        await studioEsercizio.apriEditorQuesito(studioEsercizio.gruppo(0), 0);
    });

    test("la barra porta tutti i suoi comandi, e ognuno dice che cosa fa", async ({ teacherPage }) => {
        const barra = teacherPage.locator("#fm-editor-toolbar-global");
        await expect(barra, "la barra è in pagina").toBeVisible({ timeout: 30_000 });

        for (const nome of [
            "Grassetto",
            "Corsivo",
            "Sottolineato",
            "Inserisci link",
            "Inserisci testo DSA",
            "Riformatta TeX/TikZ",
            "Salva backup manuale",
            "Trova e sostituisci",
        ]) {
            await expect(barra.getByTitle(new RegExp(nome, "i")), `il comando «${nome}»`).toBeVisible();
        }
        await expect(barra.locator(".fm-list-snippet-select"), "il selettore delle liste").toBeVisible();
        await expect(barra.getByRole("button", { name: "TeX ▾" }), "il menu dei frammenti TeX").toBeVisible();
        await expect(barra.getByTitle(/GeoGebra/), "e quello di GeoGebra").toBeVisible();
    });

    test("il selettore delle liste inserisce una lista nel campo", async ({ studioEsercizio, teacherPage }) => {
        const campo = studioEsercizio.pannelloEditor.locator(".fm-editor-field").first();
        const quante = async () => campo.locator("ul, ol").count();
        const prima = await quante();

        await campo.click();
        await teacherPage.locator("#fm-editor-toolbar-global .fm-list-snippet-select").selectOption("ul");

        await expect
            .poll(quante, { message: "la lista compare nel campo", timeout: 15_000 })
            .toBeGreaterThan(prima);
    });

    test("un elenco interrotto da un paragrafo riprende dal punto scelto, anche dopo il salvataggio", async ({
        studioEsercizio,
        teacherPage,
    }) => {
        // Il giro del docente (24/9/2026): un elenco a lettere, due Invio per
        // uscirne, un paragrafo, un secondo elenco che deve continuare dalla c,
        // e poi partire dalla lettera che si vuole. Prima la seconda lista
        // ripartiva sempre dalla a., e non c'era modo di cambiarlo.
        const campo = studioEsercizio.pannelloEditor.locator(".fm-editor-field").first();
        const menu = teacherPage.locator("#fm-editor-toolbar-global .fm-list-snippet-select");
        const liste = campo.locator(":scope > ol");

        await campo.evaluate((el) => { el.innerHTML = ""; });
        await campo.click();
        await menu.selectOption("ol-alpha");
        await teacherPage.keyboard.type("primo");
        await teacherPage.keyboard.press("Enter");
        await teacherPage.keyboard.type("secondo");
        await teacherPage.keyboard.press("Enter");
        await teacherPage.keyboard.press("Enter");
        await teacherPage.keyboard.type("Un paragrafo in mezzo.");
        await teacherPage.keyboard.press("Enter");
        await menu.selectOption("ol-alpha");
        await teacherPage.keyboard.type("terzo");

        await expect(liste, "due elenchi separati dal paragrafo").toHaveCount(2);
        await expect(liste.nth(1), "il secondo parte dalla a.").not.toHaveAttribute("start");

        await menu.selectOption("num-continua");
        await expect(liste.nth(1), "«continua» riprende dalla c").toHaveAttribute("start", "3");

        await menu.selectOption("num-inizia");
        const finestra = teacherPage.getByRole("dialog");
        await expect(finestra.locator(".fm-dialog-input"), "la finestra propone il punto di oggi").toHaveValue("c");
        await finestra.locator(".fm-dialog-input").fill("e");
        await finestra.getByRole("button", { name: "OK" }).click();
        await expect(liste.nth(1), "«inizia da…» parte dalla lettera scritta").toHaveAttribute("start", "5");
        await expect(liste.first(), "il primo elenco non si tocca").not.toHaveAttribute("start");

        const quesito = studioEsercizio.gruppo(0).quesiti.first();
        const segni = () => quesito.locator(".fm-dsa-li-num").allTextContents();
        await studioEsercizio.chiudiEditorQuesito();
        await expect.poll(segni, { message: "salvato, il quesito numera a., b. e poi e.", timeout: 30_000 })
            .toEqual(["a.", "b.", "e."]);

        await teacherPage.reload();
        await studioEsercizio.gruppo(0).apri();
        await expect.poll(segni, { message: "e dopo aver ricaricato la pagina, lo stesso", timeout: 30_000 })
            .toEqual(["a.", "b.", "e."]);
    });

    test("i comandi del formato marcano il testo selezionato", async ({ studioEsercizio, teacherPage }) => {
        const campo = studioEsercizio.pannelloEditor.locator(".fm-editor-field").first();
        const barra = teacherPage.locator("#fm-editor-toolbar-global");

        const selezionaTutto = async () => {
            await campo.click();
            await campo.evaluate((el) => {
                const intervallo = document.createRange();
                intervallo.selectNodeContents(el);
                const selezione = window.getSelection();
                selezione?.removeAllRanges();
                selezione?.addRange(intervallo);
            });
        };

        /** @type {[string, string][]} */
        const comandi = [["Grassetto", "strong, b"], ["Corsivo", "em, i"], ["Sottolineato", "u, ins"]];
        for (const [nome, marcatura] of comandi) {
            const prima = await campo.locator(marcatura).count();
            await selezionaTutto();
            await barra.getByTitle(new RegExp(nome, "i")).click();
            await expect
                .poll(async () => campo.locator(marcatura).count(), {
                    message: `«${nome}» cambia la marcatura del testo`,
                    timeout: 15_000,
                })
                .not.toBe(prima);
        }
    });

    test("il menu dei frammenti TeX si apre con i suoi gruppi", async ({ teacherPage }) => {
        await teacherPage.locator("#fm-editor-toolbar-global").getByRole("button", { name: "TeX ▾" }).click();

        const menu = teacherPage.locator(".fm-tex-menu, .fm-tex-groups").first();
        await expect(menu, "il menu si apre").toBeVisible({ timeout: 30_000 });
        expect(await menu.locator("button, [role=button], a").count(), "con le sue voci").toBeGreaterThan(0);
    });

    test("«Trova e sostituisci» apre la propria finestra", async ({ teacherPage }) => {
        await teacherPage.locator("#fm-editor-toolbar-global").getByTitle(/Trova e sostituisci/i).click();

        await expect(
            teacherPage.locator("#fm-findreplace-dialog"),
            "la finestra di ricerca si apre",
        ).toBeVisible({ timeout: 30_000 });

        await teacherPage.keyboard.press("Escape");
    });

    test("il comando di GeoGebra apre le sue scelte", async ({ teacherPage }) => {
        await teacherPage.locator("#fm-editor-toolbar-global").getByTitle(/GeoGebra/).click();

        await expect(
            teacherPage.locator("#fm-ggb-choice"),
            "la scelta di come cominciare si apre",
        ).toBeVisible({ timeout: 30_000 });
        await expect(
            teacherPage.locator('#fm-ggb-choice [data-act="new"]'),
            "con le sue tre strade: un grafico nuovo,",
        ).toBeVisible();
        await expect(teacherPage.locator('#fm-ggb-choice [data-act="catalog"]'), "il proprio catalogo,").toBeVisible();

        await teacherPage.locator('#fm-ggb-choice [data-act="cancel"]').click();
        await expect(teacherPage.locator("#fm-ggb-choice"), "e si chiude").toHaveCount(0);
    });
});
