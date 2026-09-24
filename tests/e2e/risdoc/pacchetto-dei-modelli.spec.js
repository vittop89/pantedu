// @ts-check
/**
 * Pacchetto TeX dei modelli dell'Istituto: struttura, colori, compilazione.
 * Riscrittura di risdoc_tex_production.spec.js e risdoc_pt_style_overrides.spec.js.
 *
 * Un modello si esporta come pacchetto LaTeX. Il documento principale è l'unico
 * che dichiara la classe del documento; il corpo è un pezzo da includere e non
 * deve dichiararne una propria né aprire e chiudere il documento, altrimenti la
 * compilazione si ferma. Nel corpo non devono restare i segnaposto dei campi:
 * se ne resta uno, nel PDF si legge `[field-professore]` al posto del nome.
 *
 * I colori scelti dal docente finiscono nel foglio di stile del pacchetto,
 * tradotti in componenti RGB.
 *
 * Cosa cambia rispetto a prima: i modelli da provare non sono più sette numeri
 * scritti nel test ma quelli che l'applicazione dichiara di avere con un
 * sorgente TeX; il compilatore LaTeX non è più un percorso assoluto del
 * computer di chi ha scritto la spec ma un prerequisito dichiarato; niente
 * rapporto JSON salvato su disco e niente stampe (l'esito lo dicono le
 * asserzioni), e il pacchetto si apre con la libreria di archiviazione già fra
 * le dipendenze.
 */
const { test, expect, cartellaDiLavoro, compilaConPdflatex, estraiZip, leggiFile } = require("../support/test");

/** @type {Record<string, Record<string, unknown>>} */
const STATO_DI_PROVA = {
    fields: { profilo_classe: "Classe eterogenea, buona partecipazione in generale." },
    state: { classe: "2", sezione: "A", indirizzo: "SCI", disciplina: "MAT", professore: "{{OPERATORE_NOME}}" },
};

/**
 * Modelli che hanno un sorgente TeX: sono quelli che si esportano.
 * L'elenco pubblico non dice quali siano, lo dice la scheda amministrativa.
 */
async function modelliConTex(/** @type {any} */ teacherApi, /** @type {any} */ adminApi) {
    const modelli = await teacherApi.risdoc.templates();
    const conTex = [];
    for (const modello of modelli) {
        const scheda = await adminApi.risdoc.detail(modello.id);
        if (scheda.tex_file) conTex.push(modello.id);
    }
    expect(conTex.length, "almeno un modello dell'Istituto ha un sorgente TeX").toBeGreaterThanOrEqual(1);
    return conTex;
}

/** Esporta il modello, scarica il pacchetto e lo estrae in una cartella di lavoro. */
async function pacchetto(/** @type {any} */ api, /** @type {number} */ id, /** @type {Record<string, unknown>} */ statoModulo = STATO_DI_PROVA) {
    // Dal 21/9/2026 il pacchetto arriva nel corpo della risposta: non c'è più
    // un indirizzo da seguire, e sul server non resta nessun file.
    const risposta = await api.risdoc.export(id, { formState: statoModulo });
    expect(risposta.ok(), `esportazione del modello ${id} → ${risposta.status()}`).toBe(true);
    expect(risposta.headers()["content-type"], `modello ${id}: la risposta è un archivio`).toContain("zip");
    expect(risposta.headers()["content-disposition"], `modello ${id}: arriva come allegato`)
        .toContain("attachment");

    const cartella = cartellaDiLavoro(`risdoc-modello-${id}`);
    return { cartella, file: estraiZip(await risposta.body(), cartella) };
}

test.describe("Risdoc — pacchetto TeX dei modelli", () => {
    test("ogni modello produce un pacchetto ben formato, senza segnaposto rimasti", async ({ teacherApi, adminApi }) => {
        for (const id of await modelliConTex(teacherApi, adminApi)) {
            const { cartella, file } = await pacchetto(teacherApi, id);

            expect(file, `modello ${id}: documento principale`).toContain("main.tex");
            expect(file, `modello ${id}: foglio di stile`).toContain("texCommon/risdoc.sty");
            expect(file, `modello ${id}: intestazione dell'Istituto`).toContain("texCommon/intestaLAteX_IIS.tex");

            const principale = leggiFile(cartella, "main.tex");
            expect(
                (principale.match(/\\documentclass/g) ?? []).length,
                `modello ${id}: una sola dichiarazione di classe, nel documento principale`,
            ).toBe(1);

            const percorsoCorpo = file.find((n) => n.endsWith(".tex") && n !== "main.tex" && !n.startsWith("texCommon/"));
            expect(percorsoCorpo, `modello ${id}: il pacchetto ha il corpo`).toBeTruthy();
            const corpo = leggiFile(cartella, String(percorsoCorpo));

            expect(corpo, `modello ${id}: il corpo non dichiara una classe`).not.toMatch(/\\documentclass/);
            expect(corpo, `modello ${id}: né apre il documento`).not.toMatch(/\\begin\{document\}/);
            expect(corpo, `modello ${id}: né lo chiude`).not.toMatch(/\\end\{document\}/);
            expect(corpo.length, `modello ${id}: il corpo non è vuoto`).toBeGreaterThan(100);
            expect(corpo.split(/\r?\n/).length, `modello ${id}: il corpo ha più di una riga`).toBeGreaterThanOrEqual(3);

            expect(corpo, `modello ${id}: nessun segnaposto generico rimasto`).not.toMatch(/\[field\](?!-)/);
            expect(corpo, `modello ${id}: nessun segnaposto di campo rimasto`).not.toMatch(/\[field-[a-zA-Z0-9_-]+\]/);
        }
    });

    test("i valori compilati arrivano nel TeX, risolti in parole @istanza", async ({ teacherApi, adminApi }) => {
        const [primo] = await modelliConTex(teacherApi, adminApi);
        const { cartella, file } = await pacchetto(teacherApi, primo);
        const percorsoCorpo = file.find((n) => n.endsWith(".tex") && n !== "main.tex" && !n.startsWith("texCommon/"));
        const corpo = leggiFile(cartella, String(percorsoCorpo));

        expect(corpo, "il testo scritto dal docente c'è").toContain("Classe eterogenea");
        // I codici della terna vengono tradotti nei nomi: nel documento
        // stampato si legge «Scientifico», non «SCI».
        expect(corpo, "la classe non resta un codice").not.toMatch(/\\simplefield\{[^}]*\}\{2\}/);
        expect(corpo, "l'indirizzo nemmeno").not.toMatch(/\\simplefield\{[^}]*\}\{SCI\}/);
        expect(corpo, "né la disciplina").not.toMatch(/\\simplefield\{[^}]*\}\{MAT\}/);
        expect(corpo, "la sezione non è vuota").not.toMatch(/\\simplefield\{Sezione\}\{\}/);
    });

    test("i colori scelti finiscono nel foglio di stile del pacchetto @istanza", async ({ teacherApi, adminApi }) => {
        const [primo] = await modelliConTex(teacherApi, adminApi);
        const { cartella, file } = await pacchetto(teacherApi, primo, {
            fields: {},
            state: {
                ...STATO_DI_PROVA.state,
                styleOverrides: {
                    sectionboxBg: "#ff0000",
                    sectionboxBorder: "#00ff00",
                    titleText: "#0000ff",
                },
            },
        });

        const foglio = file.find((n) => n.endsWith("risdoc.sty"));
        expect(foglio, "il pacchetto ha il foglio di stile").toBeTruthy();
        const stile = leggiFile(cartella, String(foglio));
        expect(stile, "sfondo del riquadro di sezione").toMatch(/\\definecolor\{colorBackTitleSec\}\{RGB\}\{255,0,0\}/);
        expect(stile, "bordo del riquadro").toMatch(/\\definecolor\{borderColor\}\{RGB\}\{0,255,0\}/);
        expect(stile, "colore del titolo").toMatch(/\\definecolor\{titleTextColor\}\{RGB\}\{0,0,255\}/);
    });

    test("@pdflatex i pacchetti dei modelli compilano", async ({ teacherApi, adminApi, strumenti }) => {
        test.setTimeout(900_000);
        strumenti.richiede("pdflatex");

        const falliti = [];
        for (const id of await modelliConTex(teacherApi, adminApi)) {
            const { cartella } = await pacchetto(teacherApi, id);
            const esito = compilaConPdflatex(cartella, "main.tex");
            if (esito.byte === 0) falliti.push(`modello ${id}: ${esito.errore ?? "nessun PDF prodotto"}`);
        }
        expect(falliti, `pacchetti che non compilano:\n${falliti.join("\n\n")}`).toEqual([]);
    });
});
