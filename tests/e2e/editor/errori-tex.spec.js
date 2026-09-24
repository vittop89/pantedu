// @ts-check
/**
 * Gli errori di pdflatex si vedono sempre, anche quando il PDF esce. @tex
 *
 * pdflatex compila in nonstopmode: davanti a una chiave TikZ sbagliata scrive
 * l'errore nel log, rimedia come può e produce lo stesso un PDF, con il
 * disegno a metà. Fino al 24/9/2026 il servizio TeX giudicava solo
 * dall'esistenza del PDF: l'anteprima mostrava una figura incompleta senza
 * una parola, e il modal «Editor TikZ avanzato» diceva «ok». Il log compariva
 * solo quando il PDF non usciva affatto — «a volte si vede il log», com'era
 * stato segnalato.
 *
 * Qui con il servizio TeX vero: l'anteprima SVG risponde 422 con gli errori,
 * e il modal li mostra con sotto la figura parziale. Il verso opposto — un
 * disegno pulito passa senza errori — sta nella prima prova e in
 * finestra-tikz.spec.js.
 */
const { test, expect } = require("../support/test");

/** Un disegno che produce un PDF ma con un errore: la chiave non esiste. */
function conErrore() {
    return "\\begin{document}\n\\begin{tikzpicture}\n"
        + "\\draw[kin inesistente] (0,0) -- (1,0);\n"
        + "\\draw[->] (0,0) -- (2,2);\n"
        + `% prova ${Date.now()}\n`
        + "\\end{tikzpicture}\n\\end{document}";
}

test.describe("Editor — gli errori di pdflatex", () => {
    test("l'anteprima di un disegno con un errore risponde con l'errore, quella di uno pulito con l'SVG @tex", async ({ adminApi }) => {
        test.setTimeout(120_000);

        const rotto = await adminApi.tikz.render(conErrore());
        expect(rotto.status, "prima del 24/9/2026 qui c'era 200 e una figura a metà").toBe(422);
        expect(rotto.errors?.length, "un errore").toBe(1);
        expect(rotto.errors?.[0]?.message ?? "").toContain("kin inesistente");
        expect(rotto.log ?? "", "il log comincia dall'errore").toMatch(/^pdflatex ha trovato 1 errore:/);

        const pulito = await adminApi.tikz.render(conErrore().replace("[kin inesistente]", ""));
        expect(pulito.status, "senza l'errore il disegno passa").toBe(200);
        expect(pulito.svg).toContain("<svg");
    });

    test("l'anteprima nelle pagine e nell'editor dei modelli mostra l'errore con la riga del sorgente @tex", async ({ bancoEditor }) => {
        // renderAll è quello delle pagine e dell'editor di /admin/templates
        // (tex-element-editor.js): il riquadro rosso al posto della figura.
        test.setTimeout(120_000);
        const esito = await bancoEditor.page.evaluate(async (sorgente) => {
            // @ts-ignore — modulo dell'applicazione caricato dal browser: il percorso non è risolvibile qui
            const client = await import("/js/modules/editor/tikz-render-client.js");
            const scatola = document.createElement("div");
            const script = document.createElement("script");
            script.type = "text/tikz";
            script.textContent = sorgente;
            scatola.appendChild(script);
            document.body.appendChild(scatola);
            const stats = await client.renderAll(scatola, { defaultScope: "public" });
            const riquadro = scatola.querySelector(".fm-tikz-error-messages-block");
            const testo = riquadro?.textContent ?? "";
            scatola.remove();
            return { errori: stats.errors.length, testo, primo: stats.errors[0]?.error ?? "" };
        }, conErrore());

        expect(esito.errori, "la figura è in errore").toBe(1);
        expect(esito.testo).toContain("1 errore di pdflatex:");
        expect(esito.testo).toContain("riga 3 del sorgente");
        expect(esito.testo).toContain("kin inesistente");
        expect(esito.primo, "lo stesso testo arriva all'editor dei modelli").toContain("riga 3 del sorgente");
    });

    test("il modal mostra gli errori e sotto la figura parziale @tex", async ({ bancoEditor }) => {
        test.setTimeout(180_000);
        const page = bancoEditor.page;

        await page.evaluate(async (sorgente) => {
            const campo = document.createElement("textarea");
            campo.id = "campo-con-errore";
            campo.value = `<script type="text/tikz">\n${sorgente}\n</` + "script>";
            document.body.appendChild(campo);
            const manifest = await fetch("/build/manifest.json").then((r) => r.json());
            await import("/build/" + manifest["js/entries/tikz-editor-modal.js"].file);
            const fm = /** @type {Record<string, (...a: unknown[]) => unknown>} */ (/** @type {unknown} */ (window.FM));
            fm["openTikzModal"]?.(campo);
        }, conErrore());

        const finestra = page.locator(".fm-tikz-modal-backdrop");
        await expect(finestra, "la finestra si apre").toBeVisible({ timeout: 30_000 });
        const errore = finestra.locator(".fm-tikz-modal-preview .err");
        await expect(errore, "gli errori si vedono").toBeVisible({ timeout: 120_000 });
        await expect(errore).toHaveAttribute("data-tex-errors", "1");
        await expect(errore).toContainText("1 errore di pdflatex:");
        await expect(errore).toContainText("kin inesistente");
        // La riga dell'editor, non quella del documento compilato (che ha la
        // classe e i font in testa): nel sorgente la chiave sta alla riga 3.
        await expect(errore).toContainText("riga 3 del sorgente");
        await expect(
            finestra.locator("canvas.fm-tikz-modal-pdf-parziale"),
            "e sotto la figura parziale, da non scambiare per quella buona",
        ).toBeVisible({ timeout: 60_000 });
        await expect(finestra.locator(".fm-tikz-modal-preview .preview-status")).toHaveText("1 errore TeX — figura parziale");

        await finestra.locator('.fm-tikz-modal-toolbar button[data-act="cancel"]').click();
        await expect(finestra).toHaveCount(0, { timeout: 30_000 });
        await page.evaluate(() => document.querySelector("#campo-con-errore")?.remove());
    });
});
