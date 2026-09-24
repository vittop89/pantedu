import { describe, it, expect } from "vitest";
import { readFileSync, readdirSync } from "node:fs";
import { resolve, join } from "node:path";
import { Endpoints } from "../../js/modules/core/endpoints.js";

/**
 * Il giro morto del vecchio editor TikZ: la misura che dice che è morto.
 *
 * Il vecchio editor serializzava l'SVG dal DOM e lo spediva a
 * `POST /files/save-image`, che lo scriveva su disco; alla riapertura lo
 * rileggeva da lì. Tutto il giro partiva da `svg[data-tikz-script-id]`, e
 * quell'attributo lo scriveva **solo il codice del giro stesso**: il
 * disegnatore in servizio (`js/modules/editor/tikz-render-client.js`) mette
 * `data-tikz-hash`, `data-tikz-source`, `data-tikz-srckey`,
 * `data-tikz-tagopen` e `data-tikz-body`, e la cache degli SVG sta dietro
 * `/tikz/render`.
 *
 * Due prove, nessuna delle quali si può superare stando zitti:
 *
 * 1. `Endpoints.files` non ha più le due voci — e ha ancora quelle in
 *    servizio, così una lettura sbagliata dell'oggetto si vede subito;
 * 2. nessun file di `js/` **scrive** `data-tikz-script-id`. Leggerlo è
 *    ammesso: restano un paio di guardie che saltano un rendering se l'SVG
 *    c'è già, e una lettura non tiene in vita niente. Il riconoscitore è
 *    provato nei due versi qui sotto.
 */

const RADICE_JS = resolve(__dirname, "../../js");

/** I file .js sotto js/, ricorsivamente. */
function fileJs(cartella) {
    const fuori = [];
    for (const voce of readdirSync(cartella, { withFileTypes: true })) {
        const percorso = join(cartella, voce.name);
        if (voce.isDirectory()) fuori.push(...fileJs(percorso));
        else if (voce.isFile() && voce.name.endsWith(".js")) fuori.push(percorso);
    }
    return fuori;
}

/**
 * Le scritture di `data-tikz-script-id` in un testo: `setAttribute`,
 * `dataset.tikzScriptId = …` e l'attributo dentro una stringa di HTML.
 * Una `querySelector("svg[data-tikz-script-id]")` non è una scrittura.
 */
function scrittureDellAttributo(sorgente) {
    const schemi = [
        /setAttribute\(\s*["'`]data-tikz-script-id["'`]/g,
        /dataset\.tikzScriptId\s*=[^=]/g,
        // Dentro una stringa di HTML. Il `(?<!\[)` tiene fuori il selettore
        // `svg[data-tikz-script-id="…"]`, che è una lettura.
        /(?<!\[)data-tikz-script-id\s*=\s*["'`]?\$?\{/g,
    ];
    const trovate = [];
    for (const schema of schemi) {
        for (const riscontro of sorgente.matchAll(schema)) trovate.push(riscontro[0]);
    }
    return trovate;
}

describe("il giro morto del vecchio editor TikZ", () => {
    it("Endpoints.files non espone più i salvataggi del vecchio editor", () => {
        expect(Endpoints.files, "saveImage è tornato: la rotta /files/save-image non esiste più").not.toHaveProperty("saveImage");
        expect(Endpoints.files, "saveTex è tornato: la rotta /files/save-tex non esiste più").not.toHaveProperty("saveTex");
    });

    it("Endpoints.files espone ancora quelli in servizio", () => {
        // Controllo positivo: se leggessi l'oggetto sbagliato, mancherebbero
        // anche questi e la prova di sopra sarebbe un verde che non misura.
        for (const voce of ["saveLatex", "savePdf", "deleteFile", "deleteFolder", "deleteTemp", "list"]) {
            expect(Endpoints.files, `Endpoints.files.${voce} non c'è più`).toHaveProperty(voce);
        }
    });

    it("nessun modulo scrive più data-tikz-script-id", () => {
        const colpevoli = [];
        for (const percorso of fileJs(RADICE_JS)) {
            const scritture = scrittureDellAttributo(readFileSync(percorso, "utf8"));
            if (scritture.length > 0) colpevoli.push(`${percorso.slice(RADICE_JS.length + 1)} (${scritture.length})`);
        }
        expect(colpevoli, [
            "Qualcuno ha rimesso la scrittura di data-tikz-script-id.",
            "È l'attributo del vecchio giro su disco: chi lo scrive fa rivivere",
            "rami che nessuno chiama più (il salvataggio in servizio passa da",
            "/tikz/save-svg e dalla cache di /tikz/render).",
            `Trovato in: ${colpevoli.join(", ")}`,
        ].join(" ")).toEqual([]);
    });

    it("il riconoscitore vede una scrittura e non una lettura", () => {
        expect(scrittureDellAttributo(`svg.setAttribute("data-tikz-script-id", id);`)).toHaveLength(1);
        expect(scrittureDellAttributo(`el.dataset.tikzScriptId = nuovoId;`)).toHaveLength(1);
        expect(scrittureDellAttributo("const html = `<svg data-tikz-script-id=\"${id}\">`;")).toHaveLength(1);
        expect(scrittureDellAttributo(`el.querySelector("svg[data-tikz-script-id]")`)).toEqual([]);
        expect(scrittureDellAttributo("el.querySelector(`svg[data-tikz-script-id=\"${id}\"]`)")).toEqual([]);
        expect(scrittureDellAttributo(`// una volta si scriveva data-tikz-script-id`)).toEqual([]);
    });
});
