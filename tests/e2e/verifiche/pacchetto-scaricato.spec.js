// @ts-check
/**
 * Il pacchetto scaricato dal docente si compila davvero. @pdflatex
 *
 * Riunisce tre spec: g20_02_zip_compile (struttura dello ZIP e compilazione
 * delle quattro varianti), g20_06_compensa_griglia_compact (con la
 * compensazione la griglia esce anche in versione compatta, e solo le varianti
 * per BES/DSA la usano), g20_03_vsc_layout (lo stesso pacchetto in forma
 * distribuita, con i percorsi per Istituto, materia e classe).
 *
 * Il valore di queste tre è che LaTeX gira per davvero sul pacchetto: se un
 * file manca o un percorso è sbagliato, la compilazione fallisce qui e non a
 * casa del docente.
 *
 * Cosa cambia rispetto a prima: lo ZIP si scompatta con la libreria già in
 * uso nel progetto invece di lanciare `unzip` o `Expand-Archive` con un
 * ripiego; i file finiscono in `storage/_tmp/e2e-bundle/` invece che in
 * `C:\tmp`, fuori dal progetto e mai ripulita; la mancanza di `pdflatex` è
 * dichiarata in partenza con un messaggio chiaro invece di far fallire il
 * test più avanti; niente login nella spec.
 */
const {
    test, expect, cartellaDiLavoro, compilaConPdflatex, esiste, estraiZip, leggiFile, scriviFile,
} = require("../support/test");

const VARIANTI = /** @type {const} */ (["NOR", "SOL", "DSA", "DIS"]);

/** Il pacchetto contiene i file comuni, le griglie e le quattro varianti. */
const ATTESI_NELLO_ZIP = [
    "texCommon/verifica.sty",
    "texCommon/intestazione.tex",
    "texCommon/ulteriori_misure.tex",
    "texCommon/BES_DSA/misure_dispensative.tex",
    "texCommon/BES_DSA/compensazione_orale.tex",
    "versioni/main_NOR.tex",
    "versioni/main_SOL.tex",
    "versioni/main_DSA.tex",
    "versioni/main_DIS.tex",
    "versioni/esercizi_NOR.tex",
    "versioni/esercizi_SOL.tex",
    "versioni/esercizi_DSA.tex",
    "versioni/esercizi_DIS.tex",
    "README.txt",
];

test.describe("Verifiche — pacchetto scaricato dal docente", () => {
    test("lo ZIP contiene tutti i file e le quattro varianti compilano @pdflatex", async ({ verificaFactory, teacherApi, naming, strumenti }) => {
        test.setTimeout(300_000);
        strumenti.richiede("pdflatex");

        const batch = await verificaFactory.batch({
            title: naming.unique("pacchetto"),
            versionLabel: "g20",
            overrides: {
                selectedIIS: "sc", selectedCLS: "3", indirizzo: "sc", classe: "3",
                dsa: true, nPrint: 1, nPrintDSA: 1, nPrintDIS: 1,
                problems: [{
                    filePath: "/x",
                    problemId: "type_Collect_x",
                    position: 1,
                    type: "Collect",
                    text: "Risolvi.",
                    items: [{
                        html: "Determina \\(\\enclose{circle}[mathcolor=red]{x}\\).",
                        solution: "\\(x = 4\\sqrt{19}/10\\)",
                        points: 1,
                        includeSolution: false,
                    }],
                }],
            },
        });
        expect(batch.batch_id, "identificativo del gruppo").toBeTruthy();
        if (!batch.batch_id) return;

        const cartella = cartellaDiLavoro(`zip-${batch.batch_id}`);
        const contenuto = estraiZip(await teacherApi.verifica.batchZip(batch.batch_id), cartella);
        expect(contenuto.length, "lo ZIP non è vuoto").toBeGreaterThan(0);
        for (const percorso of ATTESI_NELLO_ZIP) {
            expect(esiste(cartella, percorso), `manca ${percorso}`).toBe(true);
        }
        // La griglia è per Istituto e materia: basta che ce ne sia una.
        expect(contenuto.some((p) => p.startsWith("griglie/") && p.endsWith(".tex")), "griglia di valutazione").toBe(true);

        for (const variante of VARIANTI) {
            const esito = compilaConPdflatex(`${cartella}/versioni`, `main_${variante}.tex`);
            expect(esito.byte, `la variante ${variante} non compila: ${esito.errore ?? ""}`).toBeGreaterThan(0);
        }
    });

    test("con la compensazione la griglia compatta è usata solo dalle varianti BES e DSA @pdflatex", async ({ verificaFactory, teacherApi, naming, strumenti }) => {
        test.setTimeout(300_000);
        strumenti.richiede("pdflatex");

        const batch = await verificaFactory.batch({
            title: naming.unique("compensazione"),
            versionLabel: "g20",
            overrides: {
                selectedIIS: "sc", selectedCLS: "3", indirizzo: "sc", classe: "3",
                dsa: true, compensa: true, includeGriglia: true,
                nPrint: 1, nPrintDSA: 1, nPrintDIS: 1,
            },
        });
        expect(batch.batch_id).toBeTruthy();
        if (!batch.batch_id) return;

        const cartella = cartellaDiLavoro(`compensa-${batch.batch_id}`);
        const contenuto = estraiZip(await teacherApi.verifica.batchZip(batch.batch_id), cartella);

        const griglie = contenuto.filter((p) => p.startsWith("griglie/") && p.endsWith(".tex"));
        const compatta = griglie.find((p) => p.endsWith("_compact.tex"));
        expect(griglie.some((p) => !p.endsWith("_compact.tex")), "griglia normale").toBe(true);
        expect(compatta, "griglia compatta, prodotta dalla compensazione").toBeTruthy();
        if (!compatta) return;

        const testoCompatta = leggiFile(cartella, compatta);
        expect(testoCompatta, "corpo ridotto").toContain("\\fontsize{7}{8}\\selectfont");
        expect(testoCompatta, "non il corpo della griglia normale").not.toMatch(/\\fontsize\{8\.5\}/);

        const nomeCompatta = compatta.replace(/^griglie\//, "").replace(/\.tex$/, "");
        for (const variante of /** @type {const} */ (["DSA", "DIS"])) {
            expect(leggiFile(cartella, `versioni/main_${variante}.tex`), `${variante} usa la griglia compatta`).toContain(nomeCompatta);
        }
        for (const variante of /** @type {const} */ (["NOR", "SOL"])) {
            expect(leggiFile(cartella, `versioni/main_${variante}.tex`), `${variante} usa la griglia normale`).not.toContain("_compact");
        }

        for (const variante of VARIANTI) {
            const esito = compilaConPdflatex(`${cartella}/versioni`, `main_${variante}.tex`);
            expect(esito.byte, `la variante ${variante} non compila: ${esito.errore ?? ""}`).toBeGreaterThan(0);
        }
    });

    test("il pacchetto distribuito ha i percorsi per Istituto e materia e compila @pdflatex", async ({ verificaFactory, teacherApi, naming, strumenti }) => {
        test.setTimeout(300_000);
        strumenti.richiede("pdflatex");

        const batch = await verificaFactory.batch({
            title: naming.unique("distribuito"),
            versionLabel: "g20",
            overrides: {
                selectedIIS: "sc", selectedCLS: "3", indirizzo: "sc", classe: "3",
                dsa: true, nPrint: 1, nPrintDSA: 1, nPrintDIS: 1,
            },
        });
        expect(batch.batch_id).toBeTruthy();
        if (!batch.batch_id) return;

        /** @type {{ ok: boolean, institute_code?: string, files?: ReadonlyArray<{ path: string, content: string }> }} */
        const distribuito = await teacherApi.http.getJson(`/api/verifica/batch/${batch.batch_id}/files`);
        expect(distribuito.ok).toBe(true);
        expect(distribuito.institute_code, "codice dell'Istituto nei percorsi").toBeTruthy();
        const files = distribuito.files ?? [];
        const percorsi = files.map((f) => f.path);

        for (const comune of ["texCommon/verifica.sty", "texCommon/intestazione.tex"]) {
            expect(percorsi, `manca ${comune}`).toContain(comune);
        }
        expect(percorsi.some((p) => /^sc\/griglie\/.+\.tex$/.test(p)), "griglia sotto l'indirizzo").toBe(true);

        // I documenti stanno sotto indirizzo/classe/materia/verifiche/<titolo>/<versione>.
        const principale = percorsi.find((p) => /^sc\/3\/MAT\/verifiche\/.+\/main_NOR\.tex$/.test(p));
        expect(principale, `nessun main_NOR nei percorsi distribuiti: ${percorsi.slice(0, 8).join(", ")}`).toBeTruthy();
        if (!principale) return;

        const cartella = cartellaDiLavoro(`distribuito-${batch.batch_id}`);
        scriviFile(`${cartella}/${distribuito.institute_code}`, files);
        const cartellaVersione = `${cartella}/${distribuito.institute_code}/${principale.replace(/\/main_NOR\.tex$/, "")}`;

        for (const variante of VARIANTI) {
            const esito = compilaConPdflatex(cartellaVersione, `main_${variante}.tex`);
            expect(esito.byte, `la variante ${variante} non compila: ${esito.errore ?? ""}`).toBeGreaterThan(0);
        }
    });
});
