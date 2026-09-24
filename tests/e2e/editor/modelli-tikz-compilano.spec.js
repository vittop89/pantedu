// @ts-check
/**
 * Ogni modello TikZ versionato si disegna senza errori, attraverso
 * l'applicazione, e con le sue misure. @tex
 *
 * I modelli curati nel codice stanno in `storage/templates/tikz/` e il
 * rilascio li porta nella biblioteca dell'istanza (ADR-050). Qui ognuno passa
 * dalla strada del docente — `POST /tikz/render`, il PHP, il servizio TeX,
 * dvisvgm — e deve tornare come SVG, senza errori di pdflatex (dal 24/9/2026
 * un errore è un errore anche se il PDF esce: errori-tex.spec.js).
 *
 * Il risultato grafico si misura con le dimensioni dell'SVG, confrontate con
 * `tests/fixtures/modelli-tikz-misure.json`, che legge anche
 * `tests/tex/test_modelli_tikz.py` sul PDF: una curva che esce dal pannello o
 * un'etichetta finita lontano cambiano la figura di molto più di mezzo punto.
 * Le immagini non si confrontano qui: la regressione visiva ha le sue attese
 * in CI, dove il TeX non c'è.
 *
 * Non passa dalla biblioteca dell'istanza: legge i file del repository, così
 * prova i modelli che il rilascio porterà, non quelli che il database di
 * sviluppo ha oggi.
 */
const fs = require("node:fs");
const path = require("node:path");
const { test, expect } = require("../support/test");

const CARTELLA = path.join(__dirname, "..", "..", "..", "storage", "templates", "tikz");
const MISURE = path.join(__dirname, "..", "..", "fixtures", "modelli-tikz-misure.json");
const TOLLERANZA_PT = 0.5;

/** @type {{ modelli: { gruppo: string, etichetta: string, tipo: string, file: string }[] }} */
const manifesto = JSON.parse(fs.readFileSync(path.join(CARTELLA, "modelli.json"), "utf8"));
/** @type {Record<string, [number, number]>} */
const misure = JSON.parse(fs.readFileSync(MISURE, "utf8"));
/** Una voce per file: lo stesso modello può stare in più gruppi. */
const modelli = [...new Map(manifesto.modelli.filter((m) => m.tipo === "tikz").map((m) => [m.file, m])).values()];

/** Una riga di commento con l'ora: la cache dei disegni è per contenuto, e la prova vuole compilare. */
function senzaCache(/** @type {string} */ sorgente) {
    return sorgente.replace("\\begin{document}", `% prova ${Date.now()}\n\\begin{document}`);
}

test.describe("Editor — i modelli TikZ versionati si disegnano", () => {
    test("ogni modello del manifesto ha le sue misure attese", () => {
        expect(modelli.length, "il manifesto porta modelli TikZ").toBeGreaterThan(0);
        for (const m of modelli) {
            expect(misure[m.file], `misure attese per ${m.file}`).toBeDefined();
        }
    });

    for (const m of modelli) {
        test(`«${m.etichetta}» (${m.file}) si disegna senza errori e con le sue misure @tex`, async ({ adminApi }) => {
            test.setTimeout(120_000);
            const sorgente = fs.readFileSync(path.join(CARTELLA, m.file), "utf8");

            const esito = await adminApi.tikz.render(senzaCache(sorgente));

            expect(esito.errors ?? [], `errori di pdflatex:\n${esito.log ?? ""}`).toEqual([]);
            expect(esito.status, esito.log ?? "").toBe(200);
            const dim = esito.svg.match(/<svg[^>]*\swidth='([\d.]+)pt'[^>]*\sheight='([\d.]+)pt'/);
            expect(dim, "l'SVG dichiara le sue misure").not.toBeNull();
            const [attesaL, attesaA] = misure[m.file] ?? [NaN, NaN];
            expect(Math.abs(Number(dim?.[1]) - attesaL), `larghezza ${dim?.[1]}pt, attesa ${attesaL}pt`).toBeLessThanOrEqual(TOLLERANZA_PT);
            expect(Math.abs(Number(dim?.[2]) - attesaA), `altezza ${dim?.[2]}pt, attesa ${attesaA}pt`).toBeLessThanOrEqual(TOLLERANZA_PT);
        });
    }

    test("controprova: un modello con una chiave sbagliata non passa @tex", async ({ adminApi }) => {
        test.setTimeout(120_000);
        const primo = modelli[0];
        expect(primo, "il manifesto porta almeno un modello").toBeDefined();
        const sorgente = fs.readFileSync(path.join(CARTELLA, primo?.file ?? ""), "utf8");
        const rotto = senzaCache(sorgente).replace("\\begin{tikzpicture}", "\\begin{tikzpicture}\n\\draw[chiave inesistente] (0,0) -- (1,0);");
        expect(rotto).not.toBe(senzaCache(sorgente));

        const esito = await adminApi.tikz.render(rotto);

        expect(esito.status).toBe(422);
        expect((esito.errors ?? []).map((e) => e.message).join("\n")).toContain("chiave inesistente");
    });
});
