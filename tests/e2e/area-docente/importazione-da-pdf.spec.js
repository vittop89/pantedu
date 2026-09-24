// @ts-check
/**
 * L'importazione degli esercizi da un PDF.
 * Riscrittura di pdf_import.spec.js.
 *
 * È lo strumento con cui il docente carica un PDF di esercizi e se li ritrova
 * come quesiti. Il giro completo — caricamento, estrazione, revisione,
 * inserimento — ha bisogno di una chiave di un servizio esterno e di un
 * programma che trasformi le pagine in immagini: non è riproducibile in una
 * suite automatica, e qui non c'è. Quel che si verifica è che lo strumento
 * esista, che dica in che stato è, e che il comando per aprirlo sia nella
 * barra della pagina di studio.
 *
 * Cosa cambia rispetto a prima: niente login nella spec, e le stampe di
 * console non ci sono più.
 */
const { test, expect } = require("../support/test");

test.describe("Area docente — importazione da PDF", () => {
    test("la pagina dello strumento si apre e dice in che stato è", async ({ teacherPage }) => {
        const risposta = await teacherPage.goto("/teacher/pdf-import", { waitUntil: "domcontentloaded" });
        expect(risposta?.status(), "la pagina risponde").toBeLessThan(400);

        await expect(teacherPage.locator(".fm-pdfimport__title"), "e si presenta")
            .toContainText(/Importa esercizi/i);

        // O c'è il modulo per caricare il PDF, o c'è l'avviso che spiega
        // perché non si può: uno dei due, mai nessuno dei due.
        const modulo = await teacherPage.locator("[data-fm-extract]").count();
        const avviso = await teacherPage.locator(".fm-pdfimport__notice").count();
        expect(modulo + avviso, "il modulo di caricamento oppure l'avviso").toBeGreaterThan(0);
    });

    test("il comando per aprirlo è nella barra della pagina di studio", async ({
        contentFactory, studioEsercizio, teacherPage,
    }) => {
        const esercizio = await contentFactory.exercise({ groups: 1, itemsPerGroup: 1, publish: true });
        await studioEsercizio.vaiA(esercizio.studioUrl);
        await studioEsercizio.topbar.attendiPronta();

        await expect(
            teacherPage.locator('#fm-topbar [data-fm-action="pdf-import"]'),
            "il comando è nella barra, uno solo",
        ).toHaveCount(1, { timeout: 30_000 });
    });
});
