// @ts-check
/**
 * Barra degli strumenti del documento: comandi presenti e al posto giusto.
 *
 * Riunisce g20_07_topbar_logos (le icone dei comandi e le loro immagini) e
 * g20_06_topbar_info_verTitle (i comandi delle informazioni di stampa e delle
 * scelte stanno nella loro zona, e il titolo della verifica non è più lì).
 *
 * Cosa cambia rispetto a prima: niente login nella spec, niente attesa di uno
 * o un secondo e mezzo dopo il caricamento (la barra è pronta quando compare),
 * niente `networkidle` su una pagina che fa richieste in continuazione.
 */
const { test, expect } = require("../support/test");

test.describe("Verifiche — barra degli strumenti", () => {
    test.beforeEach(async ({ contentFactory, studioEsercizio }) => {
        const esercizio = await contentFactory.exercise({ publish: true });
        await studioEsercizio.vaiA(esercizio.studioUrl);
        await studioEsercizio.topbar.attendiPronta();
    });

    test("i comandi del documento portano etichetta e icona attese", async ({ studioEsercizio, teacherApi }) => {
        const topbar = studioEsercizio.topbar;

        await expect(topbar.comando("salvatex").locator(".fm-topbar__lbl"), "etichetta del salvataggio").toHaveText("TEX/PDF");

        // 21/9/2026 — il comando «Overleaf» non c'è più. Era visibile e
        // cliccabile, diceva «Apertura Overleaf attiva» e non apriva niente:
        // l'unico che leggeva quel valore era una funzione che nessuno chiama
        // più. Questa riga è la guardia perché non torni.
        await expect(topbar.root.locator('[data-fm-action="overleaf"]'),
            "il comando Overleaf è stato tolto").toHaveCount(0);

        // Il comando dell'archivio è testuale, non ha icona.
        await expect(topbar.comando("zip").locator("img")).toHaveCount(0);
        await expect(topbar.comando("zip").locator("strong")).toHaveText("ZIP");

        const vsc = topbar.comando("vsc").locator("img.fm-topbar__logo");
        await expect(vsc).toHaveCount(1);
        await expect(vsc).toHaveAttribute("src", "/img/topbar/vscode.svg");

        // L'icona è servita davvero, non solo dichiarata.
        const risposta = await teacherApi.http.send("GET", "/img/topbar/vscode.svg");
        expect(risposta.status, "/img/topbar/vscode.svg servita").toBe(200);
    });

    test("le informazioni di stampa e le scelte stanno ciascuna nella propria zona", async ({ studioEsercizio }) => {
        const topbar = studioEsercizio.topbar;
        const informazioni = topbar.root.locator(".fm-printinfo-actions");
        const scelte = topbar.root.locator(".fm-scelte-verifica-wrapper");

        await expect(informazioni).toHaveCount(1);
        await expect(scelte).toHaveCount(1);

        await expect(informazioni.locator("#savePrintInfoBtn"), "salvataggio delle informazioni").toHaveCount(1);
        await expect(informazioni.locator("#loadPrintInfoBtn"), "caricamento delle informazioni").toHaveCount(1);
        await expect(informazioni.locator('[data-fm-action="info"]'), "comando informazioni").toHaveCount(1);

        // Il titolo della verifica è stato spostato nell'intestazione del
        // documento: nella barra non deve più comparire.
        await expect(informazioni.locator("#verTitle")).toHaveCount(0);
        await expect(informazioni.locator("#verTitlePrefix")).toHaveCount(0);
        await expect(scelte.locator("#verTitle")).toHaveCount(0);

        await expect(scelte.locator(".fm-salva-scelte-btn"), "salvataggio delle scelte").toHaveCount(1);
    });
});
