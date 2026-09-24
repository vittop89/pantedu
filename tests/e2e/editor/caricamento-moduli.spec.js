// @ts-check
/**
 * Il bundle dell'editor si carica, e con lui i suoi moduli.
 * Riscrittura di g22_s15_editor_smoke.spec.js.
 *
 * È la prova che tiene: un `import` rotto dentro `content-processor.js` non dà
 * un errore visibile: `bootstrap.js` si ferma a metà, `window.FM` resta senza
 * i suoi moduli e le pagine sembrano funzionare finché non si tocca l'editor.
 *
 * Cosa cambia rispetto a prima: niente stampe di console (l'esito era leggibile
 * solo nel registro del giro) e gli errori della pagina li raccoglie la
 * diagnostica comune, che li allega al rapporto quando il test fallisce.
 */
const { test, expect } = require("../support/test");

test("il bundle dell'editor si carica e porta con sé i suoi moduli", async ({ bancoEditor }) => {
    const page = bancoEditor.page;

    // `bancoEditor` ha già chiesto il caricamento: qui si guarda il risultato.
    await expect
        .poll(async () => page.evaluate(() => !!(window.FM?.["Api"] && window.FM?.["LatexRender"])), {
            message: "window.FM non si è popolata: bootstrap.js si è fermato",
            timeout: 30_000,
        })
        .toBe(true);

    // L'indirizzo del modulo si risolve nel browser, non qui: `import()` con un
    // percorso costante il compilatore vorrebbe verificarlo sul disco.
    const modulo = await page.evaluate(async (percorso) => {
        try {
            const m = await import(percorso);
            return { caricato: true, haIlProcessore: typeof m.ContentProcessor === "object" };
        } catch (errore) {
            return { caricato: false, errore: errore instanceof Error ? errore.message : String(errore) };
        }
    }, "/js/modules/editor/content-processor.js");
    expect(modulo.caricato, `content-processor.js non si importa: ${modulo.errore ?? ""}`).toBe(true);
    expect(modulo.haIlProcessore, "il modulo espone ContentProcessor").toBe(true);

    // `jsErrors` raccoglie sia gli errori della pagina sia quelli di console.
});
