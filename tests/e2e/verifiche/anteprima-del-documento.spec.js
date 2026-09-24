// @ts-check
/**
 * L'anteprima del documento: il sorgente da una parte, il PDF dall'altra. @tex
 * Riscrittura di g21_1_preview_modal.spec.js e g21_1_synctex_click.spec.js.
 *
 * Dopo aver generato una verifica, il docente la guarda senza uscire
 * dall'applicazione: una scheda per variante, il sorgente TeX modificabile a
 * sinistra, il PDF a destra, e un comando per ricompilare. Tenendo premuto
 * Ctrl e cliccando sul PDF, il cursore salta al punto corrispondente del
 * sorgente — è quel che serve per correggere qualcosa che si è visto stampato.
 *
 * Cosa cambia rispetto a prima: le varianti sono vere, generate dal test, e
 * non più tre oggetti inventati; soprattutto, la spec non sostituisce più
 * `window.fetch` per rispondere al posto del server — `/auth/csrf` compreso,
 * che riceveva un gettone finto. La compilazione passa dall'applicazione, non
 * da una chiamata diretta al servizio con il suo segreto letto dall'ambiente.
 */
const { test, expect } = require("../support/test");

/**
 * Genera una verifica e restituisce le sue varianti.
 * @param {import("../support/test").VerificaFactory} verificaFactory
 * @param {string} titolo
 */
async function conUnaVerifica(verificaFactory, titolo) {
    const gruppo = await verificaFactory.batch({ title: titolo, versionLabel: "g21" });
    expect(gruppo.docs.length, "le varianti sono state generate").toBeGreaterThan(0);
    return gruppo.docs;
}

/** Carica il modulo dell'anteprima dal pacchetto costruito. */
async function caricaAnteprima(/** @type {import("@playwright/test").Page} */ pagina) {
    return pagina.evaluate(async () => {
        const manifest = await fetch("/build/manifest.json").then((r) => r.json());
        const voce = manifest["js/entries/verifica-preview-editor.js"];
        if (!voce) return { caricato: false, apre: false, chiude: false };
        // @ts-ignore — modulo dell'applicazione caricato dal browser: il percorso non è risolvibile qui
        await import(`/build/${voce.file}`);
        const fm = /** @type {Record<string, Record<string, unknown>>} */ (/** @type {unknown} */ (window.FM));
        return {
            caricato: true,
            apre: typeof fm["VerificaPreview"]?.["openPreview"] === "function",
            chiude: typeof fm["VerificaPreview"]?.["closeModal"] === "function",
        };
    });
}

test.describe("Verifiche — anteprima del documento", () => {
    test("il pacchetto dell'anteprima è dichiarato e si carica con i suoi comandi", async ({ teacherPage }) => {
        const manifest = await (await teacherPage.request.get("/build/manifest.json")).json();
        expect(manifest["js/entries/verifica-preview-editor.js"], "la voce è nel manifest").toBeDefined();

        await teacherPage.goto("/area-docente");
        const modulo = await caricaAnteprima(teacherPage);
        expect(modulo, "il modulo si carica e apre e chiude l'anteprima").toEqual({
            caricato: true, apre: true, chiude: true,
        });
    });

    test("senza documenti l'anteprima non si apre", async ({ teacherPage }) => {
        await teacherPage.goto("/area-docente");
        await caricaAnteprima(teacherPage);

        const esito = await teacherPage.evaluate(() => {
            const fm = /** @type {Record<string, Record<string, (...a: unknown[]) => unknown>>} */ (
                /** @type {unknown} */ (window.FM));
            try {
                fm["VerificaPreview"]?.["openPreview"]?.([]);
                return { errore: null, aperta: !!document.getElementById("fm-vp-modal") };
            } catch (e) {
                return { errore: String(e), aperta: false };
            }
        });
        expect(esito.errore, "e non va in errore").toBeNull();
        expect(esito.aperta, "la finestra resta chiusa").toBe(false);
    });

    test("con le varianti generate si apre una scheda per ciascuna, con il sorgente accanto @tex", async ({
        verificaFactory, teacherPage, naming,
    }) => {
        test.setTimeout(300_000);
        const varianti = await conUnaVerifica(verificaFactory, naming.unique("anteprima"));
        const daMostrare = varianti.slice(0, 2).map((d) => ({ id: d.id, variant: d.variant, title: "Anteprima" }));
        expect(daMostrare.length, "almeno due varianti da confrontare").toBe(2);

        await teacherPage.goto("/area-docente");
        await caricaAnteprima(teacherPage);
        await teacherPage.evaluate((documenti) => {
            const fm = /** @type {Record<string, Record<string, (...a: unknown[]) => unknown>>} */ (
                /** @type {unknown} */ (window.FM));
            fm["VerificaPreview"]?.["openPreview"]?.(documenti);
        }, daMostrare);

        const finestra = teacherPage.locator("#fm-vp-modal");
        await expect(finestra, "l'anteprima si apre").toBeVisible({ timeout: 60_000 });
        await expect(teacherPage.locator(".fm-vp-title"), "e si presenta").toContainText(/Anteprima/i);

        const schede = teacherPage.locator(".fm-vp-tab");
        await expect(schede, "una scheda per variante").toHaveCount(2, { timeout: 30_000 });
        for (const [indice, documento] of daMostrare.entries()) {
            await expect(schede.nth(indice), `la scheda della variante ${documento.variant}`)
                .toContainText(documento.variant);
        }

        await expect(teacherPage.locator(".fm-vp-editor-host .cm-editor"), "il sorgente è modificabile")
            .toBeVisible({ timeout: 60_000 });
        await expect(teacherPage.locator('[data-act="rebuild"]'), "c'è il comando per ricompilare").toBeVisible();
        await expect(teacherPage.locator('[data-act="auto-rebuild"]'), "e quello per farlo da solo").toBeAttached();
        await expect(teacherPage.locator('select[data-act="engine"]'), "il motore parte da pdflatex")
            .toHaveValue("pdflatex");

        await expect(
            teacherPage.locator(".fm-vp-pdf-help"),
            "e l'anteprima spiega come saltare dal PDF al sorgente",
        ).toContainText(/Ctrl\+click/);

        await teacherPage.locator('[data-act="close"]').click();
        await expect(finestra, "e si chiude").toHaveCount(0, { timeout: 30_000 });
    });

    test("Ctrl+clic sul PDF porta il cursore nel punto corrispondente del sorgente @tex", async ({
        verificaFactory, teacherPage, naming,
    }) => {
        test.setTimeout(600_000);
        const varianti = await conUnaVerifica(verificaFactory, naming.unique("synctex"));
        const documento = varianti[0];
        expect(documento, "una variante da guardare").toBeDefined();
        if (!documento) return;

        await teacherPage.goto("/area-docente");
        await caricaAnteprima(teacherPage);
        await teacherPage.evaluate((doc) => {
            const fm = /** @type {Record<string, Record<string, (...a: unknown[]) => unknown>>} */ (
                /** @type {unknown} */ (window.FM));
            fm["VerificaPreview"]?.["openPreview"]?.([doc]);
        }, { id: documento.id, variant: documento.variant, title: "SyncTeX" });

        await expect(teacherPage.locator("#fm-vp-modal")).toBeVisible({ timeout: 60_000 });
        await teacherPage.locator('[data-act="rebuild"]').click();

        const tela = teacherPage.locator(".fm-vp-pdf-canvas").first();
        await expect(tela, "il PDF viene disegnato").toBeVisible({ timeout: 300_000 });

        /** La riga del sorgente su cui sta il cursore. */
        const rigaDelCursore = () =>
            teacherPage.evaluate(() => {
                const fm = /** @type {Record<string, Record<string, () => { cm?: { state: { selection: { main: { head: number } }, doc: { lineAt(p: number): { number: number } } } }}>>} */ (
                    /** @type {unknown} */ (window.FM));
                const cm = fm["VerificaPreview"]?.["getState"]?.()?.cm;
                if (!cm) return 0;
                return cm.state.doc.lineAt(cm.state.selection.main.head).number;
            });
        expect(await rigaDelCursore(), "il cursore parte dall'inizio").toBe(1);

        const riquadro = await tela.boundingBox();
        expect(riquadro, "la tela ha una sua misura").not.toBeNull();
        if (!riquadro) return;

        await teacherPage.keyboard.down("Control");
        await teacherPage.mouse.click(riquadro.x + riquadro.width * 0.5, riquadro.y + riquadro.height * 0.4);
        await teacherPage.keyboard.up("Control");

        await expect
            .poll(rigaDelCursore, { message: "il cursore salta nel sorgente", timeout: 30_000 })
            .toBeGreaterThan(1);
        await expect(
            teacherPage.locator("[data-status-info]"),
            "e la barra di stato dice dove è andato",
        ).toContainText(/SyncTeX/i, { timeout: 15_000 });
    });
});
