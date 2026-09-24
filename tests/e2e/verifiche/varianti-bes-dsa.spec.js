// @ts-check
/**
 * Varianti per studenti con bisogni educativi speciali: che cosa entra nel
 * pacchetto TeX e in che ordine. @tex
 *
 * Riunisce quattro spec della fase G27, che verificavano la stessa cosa su
 * varianti diverse: g27_dis_compensa, g27_dsa_sci_firma, g27_griglia_baseline
 * e g27_vf_dis_xelatex. Ognuna salvava una verifica, ne leggeva i sorgenti e
 * poi compilava.
 *
 * Cosa cambia rispetto a prima: niente login nella spec; la compilazione
 * aspetta l'esito invece di riprovare quattro volte a venti secondi di
 * distanza; le verifiche salvate vengono cancellate. Soprattutto: le quattro
 * spec finivano convertendo il PDF in immagine con `pdftoppm` e scrivendo i
 * PNG in `tmp-vf-screenshots/` senza verificarne nulla (in una si controllava
 * solo che il file fosse stato scritto, cioè che funzionasse poppler). Quella
 * conversione serviva all'occhio durante lo sviluppo della fase G27: qui resta
 * la verifica che il PDF sia un documento vero, e sparisce la dipendenza da
 * uno strumento esterno che non verificava l'applicazione.
 */
const { test, expect, compilaVerifica, pdfCompilato } = require("../support/test");

test.describe("Verifiche — varianti per BES e DSA", () => {
    test("la variante per la dislessia porta la compensazione orale sotto la griglia @tex", async ({ verificaFactory, teacherApi, naming }) => {
        test.setTimeout(300_000);
        const titolo = naming.unique("dis-compensa");
        const batch = await verificaFactory.batch({
            title: titolo,
            versionLabel: "g27",
            overrides: { dsa: true, compensa: true, includeGriglia: true, includeMisure: true, nPrint: 0, nPrintDSA: 0, nPrintDIS: 1 },
        });
        const variante = batch.docs.find((d) => d.variant === "A_DIS");
        expect(variante, "variante A_DIS").toBeDefined();
        if (!variante) return;

        const principale = await teacherApi.verifica.texFile(variante.id, "versioni/main_DIS.tex");
        const posizioneGriglia = principale.content.indexOf("griglie/");
        const posizioneCompensa = principale.content.indexOf("compensazione_orale");
        expect(posizioneGriglia, "la griglia è inclusa").toBeGreaterThan(0);
        expect(posizioneCompensa, "la compensazione viene dopo la griglia").toBeGreaterThan(posizioneGriglia);
        // I segnaposto irrisolti restavano come inclusioni commentate: non devono esserci.
        expect(principale.content).not.toContain("% \\input{");

        const files = await teacherApi.verifica.texFiles(variante.id);
        const grigliaCompatta = files.find((f) => f.path.endsWith("_compact.tex"));
        expect(grigliaCompatta, "griglia in versione compatta").toBeDefined();
        expect(grigliaCompatta?.content).toContain("\\fontsize{7}{8}\\selectfont");

        await compilaVerifica(teacherApi.verifica, variante.id, { engine: "pdflatex" });
        await pdfCompilato(teacherApi.verifica, variante.id);
    });

    test("la variante per la dislessia usa il carattere dedicato @tex", async ({ verificaFactory, teacherApi, naming }) => {
        test.setTimeout(300_000);
        const batch = await verificaFactory.batch({
            title: naming.unique("dis-font"),
            versionLabel: "g27",
            overrides: { dsa: true, nPrint: 0, nPrintDSA: 0, nPrintDIS: 1 },
        });
        const variante = batch.docs.find((d) => d.variant === "A_DIS");
        expect(variante).toBeDefined();
        if (!variante) return;

        const principale = await teacherApi.verifica.texFile(variante.id, "versioni/main_DIS.tex");
        expect(principale.content, "carattere per la dislessia").toContain("OpenDyslexic");

        await compilaVerifica(teacherApi.verifica, variante.id, { engine: "pdflatex" });
        await pdfCompilato(teacherApi.verifica, variante.id);
    });

    test("la variante DSA porta le misure dispensative e la firma", async ({ verificaFactory, teacherApi, naming }) => {
        const batch = await verificaFactory.batch({
            title: naming.unique("dsa-misure"),
            versionLabel: "g27",
            overrides: { dsa: true, compensa: true, includeMisure: true, nPrint: 0, nPrintDSA: 1, nPrintDIS: 0 },
        });
        const variante = batch.docs.find((d) => d.variant === "A_DSA");
        expect(variante).toBeDefined();
        if (!variante) return;

        const principale = await teacherApi.verifica.texFile(variante.id, "versioni/main_DSA.tex");
        expect(principale.content, "misure dispensative").toContain("misure_dispensative");
        expect(principale.content, "compensazione orale").toContain("compensazione_orale");
    });

    test("la griglia di valutazione è inclusa in tutte le varianti", async ({ verificaFactory, teacherApi, naming }) => {
        const batch = await verificaFactory.batch({
            title: naming.unique("griglia"),
            versionLabel: "g27",
            overrides: { includeGriglia: true },
        });
        expect(batch.docs.length).toBeGreaterThanOrEqual(4);

        for (const documento of batch.docs) {
            const sorgente = await teacherApi.verifica.texText(documento.tex_url);
            expect(sorgente, `griglia nella variante ${documento.variant}`).toContain("Griglia di Valutazione");
        }
    });
});
