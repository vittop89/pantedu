// @ts-check
/**
 * Quesiti Vero/Falso: tabella nel sorgente TeX e righe per la giustificazione. @tex
 *
 * Riunisce tre spec della fase G27: g27_vf_giustifica_modulation (il tipo del
 * gruppo è riconosciuto anche nella forma con prefisso, e la tabella ha le
 * intestazioni giuste), g27_vf_giust_rows_visual e g27_vf_user_flow_real (le
 * righe tratteggiate per scrivere la giustificazione ci sono sia quando il
 * docente mette il marcatore, sia quando arriva dal modulo senza marcatore).
 *
 * Il segno delle righe di giustificazione nel sorgente è `\cdashline{1-4}`:
 * una riga tratteggiata sotto l'affermazione, dove lo studente scrive.
 *
 * Cosa cambia rispetto a prima: niente login nella spec, la compilazione
 * aspetta l'esito, le verifiche si cancellano da sole, e sparisce la
 * conversione del PDF in immagine che non verificava nulla.
 */
const { test, expect, compilaVerifica, pdfCompilato } = require("../support/test");

const MARCATORE_GIUSTIFICA = '<strong class="fm-sol-label">GIUSTIFICAZIONE</strong> ';

/**
 * Un gruppo Vero/Falso con le affermazioni indicate.
 * @param {string} tipo tipo del gruppo, es. «type_VF» o «VF»
 * @param {ReadonlyArray<{ html: string, solution: string, points: number, includeSolution: boolean }>} items
 */
function gruppoVeroFalso(tipo, items) {
    return {
        filePath: "/eser/ar/ar2s/MAT/1",
        problemId: "vero-falso",
        position: 1,
        type: tipo,
        text: "Indica se le affermazioni sono vere o false:",
        items,
    };
}

test.describe("Verifiche — quesiti Vero/Falso", () => {
    test("il tipo del gruppo è riconosciuto anche nella forma con prefisso", async ({ verificaFactory, teacherApi, naming }) => {
        const batch = await verificaFactory.batch({
            title: naming.unique("vf-tipo"),
            versionLabel: "g27",
            overrides: {
                problems: [gruppoVeroFalso("type_VF", [
                    { html: "Prima affermazione.", solution: "V", points: 1, includeSolution: false },
                    { html: "Seconda affermazione.", solution: "F", points: 1, includeSolution: false },
                ])],
            },
        });
        const senzaSoluzioni = batch.docs.find((d) => d.variant === "A_NOR");
        expect(senzaSoluzioni).toBeDefined();
        if (!senzaSoluzioni) return;

        const esercizi = await teacherApi.verifica.texFile(senzaSoluzioni.id, "versioni/esercizi_NOR.tex");
        expect(esercizi.content, "intestazioni della tabella").toContain("\\textbf{\\#} & \\textbf{Affermazione} & \\textbf{V} & \\textbf{F}");
        // Con il tipo non riconosciuto il gruppo veniva reso come elenco puntato.
        expect(esercizi.content, "non è un elenco puntato").not.toContain("[label={\\textbf{\\Alph*)},leftmargin=1.5em]");
    });

    test("il marcatore di giustificazione produce le righe per scrivere", async ({ verificaFactory, teacherApi, naming }) => {
        const batch = await verificaFactory.batch({
            title: naming.unique("vf-giustifica"),
            versionLabel: "g27",
            overrides: {
                problems: [gruppoVeroFalso("type_VF", [
                    { html: "Affermazione con giustificazione.", solution: MARCATORE_GIUSTIFICA, points: 1, includeSolution: false },
                ])],
            },
        });
        const senzaSoluzioni = batch.docs.find((d) => d.variant === "A_NOR");
        expect(senzaSoluzioni).toBeDefined();
        if (!senzaSoluzioni) return;

        const esercizi = await teacherApi.verifica.texFile(senzaSoluzioni.id, "versioni/esercizi_NOR.tex");
        expect(esercizi.content, "riga tratteggiata per la giustificazione").toContain("\\cdashline{1-4}");
    });

    test("le righe per la giustificazione ci sono una per affermazione @tex", async ({ verificaFactory, teacherApi, naming }) => {
        test.setTimeout(300_000);
        const batch = await verificaFactory.batch({
            title: naming.unique("vf-righe"),
            versionLabel: "g27",
            overrides: {
                problems: [gruppoVeroFalso("type_VF", [
                    { html: "Il quadrato ha quattro lati uguali.", solution: MARCATORE_GIUSTIFICA, points: 1, includeSolution: false },
                    { html: "Il triangolo equilatero ha tutti gli angoli retti.", solution: MARCATORE_GIUSTIFICA, points: 1, includeSolution: false },
                    { html: "Il cerchio ha infiniti assi di simmetria.", solution: MARCATORE_GIUSTIFICA, points: 1, includeSolution: false },
                ])],
            },
        });
        const senzaSoluzioni = batch.docs.find((d) => d.variant === "A_NOR");
        expect(senzaSoluzioni).toBeDefined();
        if (!senzaSoluzioni) return;

        const esercizi = await teacherApi.verifica.texFile(senzaSoluzioni.id, "versioni/esercizi_NOR.tex");
        const righe = (esercizi.content.match(/\\cdashline\{1-4\}/g) ?? []).length;
        expect(righe, "una riga tratteggiata per affermazione").toBe(3);

        await compilaVerifica(teacherApi.verifica, senzaSoluzioni.id, { engine: "pdflatex" });
        await pdfCompilato(teacherApi.verifica, senzaSoluzioni.id);
    });

    test("la tabella ha la spaziatura dichiarata e la soluzione evidenzia la giustificazione", async ({ verificaFactory, teacherApi, naming }) => {
        // Da g27_vf_table_visual: le misure della tabella stanno nel sorgente,
        // e nella variante con le soluzioni la giustificazione è evidenziata.
        const batch = await verificaFactory.batch({
            title: naming.unique("vf-stile"),
            versionLabel: "g27",
            overrides: {
                problems: [gruppoVeroFalso("type_VF", [
                    { html: "Affermazione con giustificazione.", solution: MARCATORE_GIUSTIFICA, points: 1, includeSolution: true },
                    { html: "Affermazione semplice.", solution: "V", points: 1, includeSolution: true },
                ])],
            },
        });
        const senzaSoluzioni = batch.docs.find((d) => d.variant === "A_NOR");
        const conSoluzioni = batch.docs.find((d) => d.variant === "A_SOL");
        expect(senzaSoluzioni && conSoluzioni, "le due varianti della versione A").toBeTruthy();
        if (!senzaSoluzioni || !conSoluzioni) return;

        const esercizi = await teacherApi.verifica.texFile(senzaSoluzioni.id, "versioni/esercizi_NOR.tex");
        expect(esercizi.content, "altezza delle righe").toContain("\\renewcommand{\\arraystretch}{1.4}");
        expect(esercizi.content, "spaziatura delle colonne").toContain("\\setlength{\\tabcolsep}{6pt}");
        expect(esercizi.content, "riga per la giustificazione").toContain("\\cdashline{1-4}");

        const soluzioni = await teacherApi.verifica.texFile(conSoluzioni.id, "versioni/esercizi_SOL.tex");
        expect(soluzioni.content, "giustificazione evidenziata").toContain("\\fcolorbox{gray!50}{gray!10}{\\textbf{Giustifica}}");
    });

    test("le righe ci sono anche quando le affermazioni arrivano senza marcatore @tex", async ({ verificaFactory, teacherApi, naming }) => {
        test.setTimeout(300_000);
        const batch = await verificaFactory.batch({
            title: naming.unique("vf-modulo"),
            versionLabel: "g27",
            overrides: {
                problems: [gruppoVeroFalso("VF", [
                    { html: "Prima affermazione.", solution: "", points: 1, includeSolution: false },
                    { html: "Seconda affermazione.", solution: "", points: 1, includeSolution: false },
                    { html: "Terza affermazione.", solution: "", points: 1, includeSolution: false },
                ])],
            },
        });
        const senzaSoluzioni = batch.docs.find((d) => d.variant === "A_NOR");
        expect(senzaSoluzioni).toBeDefined();
        if (!senzaSoluzioni) return;

        const esercizi = await teacherApi.verifica.texFile(senzaSoluzioni.id, "versioni/esercizi_NOR.tex");
        const righe = (esercizi.content.match(/\\cdashline\{1-4\}/g) ?? []).length;
        expect(righe, "una riga tratteggiata per affermazione").toBe(3);

        await compilaVerifica(teacherApi.verifica, senzaSoluzioni.id, { engine: "pdflatex" });
    });
});
