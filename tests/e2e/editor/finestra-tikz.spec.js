// @ts-check
/**
 * Le due finestre con cui si disegna una figura: quella del sorgente e quella
 * dei modelli riempibili. @tex
 * Riscrittura di g22_s15_modal_smoke.spec.js e g22_s15_template_filler.spec.js.
 *
 * La prima apre il sorgente TikZ in un editor di codice con l'anteprima
 * accanto: si scrive, si guarda, si salva, e il sorgente torna nel campo del
 * quesito. La seconda serve a chi il TikZ non lo scrive: sceglie un modello —
 * lo schema modulare per lo studio del segno — e ne riempie le caselle; alla
 * conferma il sorgente lo costruisce l'applicazione.
 *
 * Cosa cambia rispetto a prima: `/auth/csrf` non è più intercettato (era il
 * gettone di sicurezza, sostituito con uno finto) e l'anteprima è una
 * compilazione vera, non una risposta preparata dal test.
 */
const { test, expect } = require("../support/test");

/** Una figura semplice, come quella che si inserisce dal menu TeX. */
const SORGENTE = "\\begin{tikzpicture}\n\\draw[->] (0,0) -- (2,2);\n\\end{tikzpicture}";

test.describe("Editor — finestre delle figure", () => {
    test("la finestra del sorgente si apre con l'anteprima, e salvando riporta la figura nel campo @tex", async ({
        bancoEditor,
    }) => {
        const page = bancoEditor.page;

        const aperta = await page.evaluate(async (sorgente) => {
            const campo = document.createElement("textarea");
            campo.id = "campo-della-figura";
            campo.value = `<script type="text/tikz">\n${sorgente}\n</` + "script>";
            document.body.appendChild(campo);

            const manifest = await fetch("/build/manifest.json").then((r) => r.json());
            await import("/build/" + manifest["js/entries/tikz-editor-modal.js"].file);
            const fm = /** @type {Record<string, (...a: unknown[]) => unknown>} */ (/** @type {unknown} */ (window.FM));
            fm["openTikzModal"]?.(campo);
            return true;
        }, SORGENTE);
        expect(aperta).toBe(true);

        const finestra = page.locator(".fm-tikz-modal-backdrop");
        await expect(finestra, "la finestra si apre").toBeVisible({ timeout: 30_000 });
        await expect(finestra.locator(".cm-editor"), "con l'editor di codice montato").toBeVisible({ timeout: 30_000 });
        await expect(
            finestra.locator(".fm-tikz-modal-preview").locator("svg, img, canvas").first(),
            "e l'anteprima disegnata",
        ).toBeVisible({ timeout: 120_000 });
        // Il verso opposto di errori-tex.spec.js: un disegno pulito non porta
        // errori, e la figura non è marcata come parziale.
        await expect(finestra.locator(".fm-tikz-modal-preview .err"), "senza errori").toHaveCount(0);
        await expect(finestra.locator("canvas.fm-tikz-modal-pdf-parziale")).toHaveCount(0);

        await finestra.locator('.fm-tikz-modal-toolbar button[data-act="save"]').click();
        await expect(finestra, "salvando la finestra si chiude").toHaveCount(0, { timeout: 30_000 });

        const nelCampo = await page.evaluate(() => {
            const campo = /** @type {HTMLTextAreaElement | null} */ (document.querySelector("#campo-della-figura"));
            const valore = campo?.value ?? "";
            campo?.remove();
            return valore;
        });
        expect(nelCampo, "il sorgente è tornato nel campo").toContain("\\begin{tikzpicture}");
    });

    test("la finestra dei modelli si apre riempita, e alla conferma consegna il sorgente @tex", async ({
        bancoEditor,
    }) => {
        test.setTimeout(300_000);
        const page = bancoEditor.page;

        await page.evaluate(async () => {
            const manifest = await fetch("/build/manifest.json").then((r) => r.json());
            await import("/build/" + manifest["js/entries/tikz-template-filler.js"].file);
            /** @type {{ tikz: string | null, dati: { id?: string, schemas?: unknown[] } | null }} */
            const consegnato = { tikz: null, dati: null };
            const globale = /** @type {Record<string, unknown>} */ (/** @type {unknown} */ (window));
            globale["__consegnato"] = consegnato;
            const fm = /** @type {Record<string, (...a: unknown[]) => unknown>} */ (/** @type {unknown} */ (window.FM));
            fm["openTemplateFiller"]?.("schema-modulare", null, (/** @type {string} */ tikz, /** @type {{ id?: string, schemas?: unknown[] }} */ dati) => {
                consegnato.tikz = tikz;
                consegnato.dati = dati;
            });
        });

        const finestra = page.locator(".fm-tplf-backdrop");
        await expect(finestra, "la finestra dei modelli si apre").toBeVisible({ timeout: 30_000 });
        expect(await finestra.locator(".fm-tplf-tabs button").count(), "con le sue schede")
            .toBeGreaterThanOrEqual(2);
        expect(await finestra.locator("table.fm-tplf-table").count(), "e le tabelle da riempire")
            .toBeGreaterThanOrEqual(2);
        await expect(finestra.locator(".fm-tplf-preview svg").first(), "l'anteprima viene disegnata")
            .toBeVisible({ timeout: 120_000 });

        await finestra.locator('button[data-act="save"]').click();
        await expect(finestra, "confermando la finestra si chiude").toHaveCount(0, { timeout: 30_000 });

        const consegna = await page.evaluate(() => {
            const globale = /** @type {Record<string, unknown>} */ (/** @type {unknown} */ (window));
            const c = /** @type {{ tikz: string | null, dati: { id?: string, schemas?: unknown[] } | null }} */ (globale["__consegnato"]);
            return {
                haIlDocumento: !!c?.tikz?.includes("\\begin{document}"),
                haLoSchema: !!c?.tikz?.includes("\\schemaModulare"),
                lunghezza: c?.tikz?.length ?? 0,
                modello: c?.dati?.id,
                quantiSchemi: c?.dati?.schemas?.length,
            };
        });
        expect(consegna.modello, "il modello consegnato è quello scelto").toBe("schema-modulare");
        expect(consegna.quantiSchemi, "con il suo schema").toBe(1);
        expect(consegna.haIlDocumento, "il sorgente è un documento completo").toBe(true);
        expect(consegna.haLoSchema, "e contiene il comando dello schema").toBe(true);
        expect(consegna.lunghezza, "preambolo e corpo, non due righe").toBeGreaterThan(2000);
    });

    test("il modello riempito produce il sorgente atteso, con i valori messi al loro posto", async ({ bancoEditor }) => {
        // Qui non si apre nessuna finestra: si dà al modello un riempimento
        // completo — tre righe di segni, i valori sull'asse, le etichette — e
        // si guarda il sorgente che ne esce.
        const esito = await bancoEditor.page.evaluate(async () => {
            // @ts-ignore — modulo dell'applicazione caricato dal browser: il percorso non è risolvibile qui
            const mod = await import("/js/modules/editor/tikz-templates/schema-modulare.js");
            const dati = {
                id: "schema-modulare",
                version: 1,
                globalParams: {
                    spacing: 5, topTextY: 1, bottomTextPadding: 1,
                    highlightFill: "red!70", highlightBorder: "red!40!black",
                    highlightText: "white", highlightRadius: "0.2cm", highlightBorderWidth: "0.4pt",
                },
                schemas: [{
                    xShift: "0",
                    xValues: [
                        { pos: 1, value: "$2a$" },
                        { pos: 2, value: "$0$" },
                        { pos: 3, value: "$-\\frac{a}{4}$" },
                    ],
                    rows: [
                        { y: 0.5, equation: "$N(x)>0$", signs: ["$+$", "$+$", "$-$", "$+$"], circles: [{ idx: 2, type: "draw" }, { idx: 3, type: "draw" }], highlights: [] },
                        { y: 1.5, equation: "$D(x)>0$", signs: ["$+$", "$+$", "$+$", "$+$"], circles: [{ idx: 1, type: "draw" }], highlights: [] },
                        { y: 2.5, equation: "$\\frac{N(x)}{D(x)}$", signs: ["$+$", "$+$", "$-$", "$+$"], circles: [{ idx: 1, type: "draw" }, { idx: 2, type: "draw" }, { idx: 3, type: "draw" }], highlights: [3] },
                    ],
                    solution: { signs: [], circles: [], highlightIdx: [], text: "" },
                    labelAbove: "$\\text{se }a<0$",
                    labelBelow: "$0<x<-\\dfrac{a}{4}$",
                }],
            };
            const sorgente = mod.renderTikz(dati);
            return {
                errori: mod.validate(dati),
                haIlComandoDiBase: sorgente.includes("\\schemaModulareCore"),
                chiamaLoSchema: sorgente.includes("\\schemaModulare{\\firstSchema}"),
                portaIValoriSullAsse: sorgente.includes("1/{$2a$}, 2/{$0$}, 3/{$-\\frac{a}{4}$}"),
                portaLEtichettaSopra: sorgente.includes("\\schemaTextAbove{\\topTextY}{$\\text{se }a<0$}"),
            };
        });

        expect(esito.errori, "il riempimento è valido").toEqual([]);
        expect(esito.haIlComandoDiBase, "il preambolo definisce il comando").toBe(true);
        expect(esito.chiamaLoSchema, "e il corpo lo chiama").toBe(true);
        expect(esito.portaIValoriSullAsse, "i valori finiscono sull'asse nell'ordine dato").toBe(true);
        expect(esito.portaLEtichettaSopra, "e l'etichetta sopra è quella scritta").toBe(true);
    });
});
