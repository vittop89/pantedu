// @ts-check
/**
 * Generazione di una verifica dalla pagina di studio, con le interazioni vere.
 *
 * Riunisce cinque spec: g18_real_user_genera e g16_batch_generation (il
 * docente spunta i quesiti, compila le informazioni e genera), g19_7 (solo la
 * versione principale produce le varianti A, solo il recupero produce le B) e
 * g19_5/g19_6 (dal gruppo generato si scarica un archivio con tutte le
 * varianti, non con una sola).
 *
 * Cosa cambia rispetto a prima: niente login nella spec e niente attese a
 * tempo fra un passaggio e l'altro; le verifiche prodotte si cancellano; il
 * contenuto su cui si lavora è creato dal test invece di essere il primo che
 * si trova nella terna, così i conteggi delle varianti sono prevedibili.
 * L'archivio si ispeziona con la libreria già in uso invece che estraendolo
 * con un processo esterno.
 */
const { test, expect, estraiZip, cartellaDiLavoro } = require("../support/test");

/**
 * Il gruppo generato va cancellato: gli id arrivano dalla risposta del salvataggio.
 * @param {import("../support/test").VerificaFactory} verificaFactory
 * @param {{ docs?: ReadonlyArray<{ id: number, variant: string, tex_filename?: string }> }} corpo
 * @param {string} etichetta
 */
function registraCancellazione(verificaFactory, corpo, etichetta) {
    const documenti = Array.isArray(corpo?.docs) ? corpo.docs : [];
    verificaFactory.registerDeletion(documenti.map((/** @type {{ id: number }} */ d) => d.id), etichetta);
    return documenti;
}

test.describe("Verifiche — generazione dalla pagina", () => {
    test.beforeEach(async ({ contentFactory, studioEsercizio }) => {
        const esercizio = await contentFactory.exercise({ groups: 1, itemsPerGroup: 2, publish: true });
        await studioEsercizio.vaiA(esercizio.studioUrl);
        await studioEsercizio.topbar.attendiPronta();
    });

    test("il docente spunta un quesito, compila le informazioni e genera le quattro varianti", async ({ studioEsercizio, verificaFactory, naming }) => {
        const generazione = studioEsercizio.generazione;
        await generazione.selezionaPrimoQuesito("A");

        const selezionati = await generazione.quesitiSelezionati();
        expect(selezionati.a, "un quesito spuntato per la versione principale").toBeGreaterThan(0);

        const titolo = naming.unique("generazione");
        await generazione.compilaInformazioni({ titolo, copie: 10 });

        const risposta = await generazione.genera();
        expect(risposta.status(), "il salvataggio riesce").toBe(200);
        const corpo = await risposta.json();
        expect(corpo.ok).toBe(true);

        const documenti = registraCancellazione(verificaFactory, corpo, `variante di «${titolo}»`);
        // Senza misure per BES/DSA le varianti sono due: quella con le soluzioni
        // per il docente e quella da stampare per gli studenti.
        expect(documenti.map((d) => d.variant).sort(), "varianti della versione principale").toEqual(["A_NOR", "A_SOL"]);
        for (const documento of documenti) {
            expect(documento.tex_filename, `nome del file per ${documento.variant}`).toBeTruthy();
        }
    });

    test("spuntando solo il recupero si ottengono le varianti della versione B", async ({ studioEsercizio, verificaFactory, naming }) => {
        const generazione = studioEsercizio.generazione;
        await generazione.selezionaPrimoQuesito("R");

        const titolo = naming.unique("recupero");
        await generazione.compilaInformazioni({ titolo, copie: 10 });

        const risposta = await generazione.genera();
        expect(risposta.status()).toBe(200);
        const corpo = await risposta.json();
        const documenti = registraCancellazione(verificaFactory, corpo, `variante di recupero di «${titolo}»`);

        expect(documenti.map((/** @type {{ variant: string }} */ d) => d.variant).sort(), "varianti del recupero")
            .toEqual(["B_NOR", "B_SOL"]);
        for (const documento of documenti) {
            // Il nome del file dice che è la versione di recupero: al posto
            // della sezione compare «rec» (nella versione principale c'è la
            // sezione, o un trattino basso quando non è indicata).
            // La variante da stampare aggiunge «-stampe» in coda.
            expect(documento.tex_filename, `nome del file per ${documento.variant}`).toMatch(/-rec-(SOL|NOR|DSA|DIS)(-stampe)?\.tex$/);
        }
    });

    test("con le misure per DSA il gruppo generato porta tutte le varianti e l'archivio le contiene", async ({ studioEsercizio, verificaFactory, teacherApi, naming }) => {
        const generazione = studioEsercizio.generazione;
        await generazione.selezionaPrimoQuesito("A");

        const titolo = naming.unique("archivio");
        await generazione.compilaInformazioni({ titolo, copie: 10, copieDsa: 1, copieDis: 1, conMisureDsa: true });

        const risposta = await generazione.genera();
        expect(risposta.status()).toBe(200);
        const corpo = await risposta.json();
        const documenti = registraCancellazione(verificaFactory, corpo, `variante di «${titolo}»`);
        expect(documenti.length, "almeno le quattro varianti della versione A").toBeGreaterThanOrEqual(4);
        expect(corpo.batch_id, "identificativo del gruppo").toBeTruthy();

        // L'archivio del gruppo, non della singola variante: deve contenerle tutte.
        const zip = await teacherApi.verifica.batchZip(corpo.batch_id);
        expect(zip.subarray(0, 2).toString("ascii"), "è un archivio ZIP").toBe("PK");

        const cartella = cartellaDiLavoro(`generazione-${corpo.batch_id}`);
        const contenuto = estraiZip(zip, cartella);
        expect(contenuto, "istruzioni per il docente").toContain("README.txt");
        for (const variante of ["SOL", "NOR", "DSA", "DIS"]) {
            expect(contenuto, `documento principale della variante ${variante}`).toContain(`versioni/main_${variante}.tex`);
        }
        expect(contenuto.length, "l'archivio del gruppo, non di una singola verifica").toBeGreaterThanOrEqual(5);
    });

    test("le formule finiscono nel sorgente come le ha scritte il docente, non come sono disegnate @tex", async ({
        contentFactory, studioEsercizio, verificaFactory, teacherApi, naming,
    }) => {
        test.setTimeout(240_000);
        // La pagina disegna le formule, e il disegno è fatto di caratteri
        // Unicode (√, 𝑥). Se la selezione raccogliesse quelli invece della
        // sorgente, il documento non compilerebbe: pdflatex non sa cosa farsene
        // di un carattere «radice quadrata». Era successo, per le formule
        // dentro elenchi e tabelle. Qui si guarda il sorgente prodotto.
        const esercizio = await contentFactory.exercise({
            groups: 1,
            itemsPerGroup: 1,
            publish: true,
            itemHtml: "<p>Calcola \\(\\sqrt{x^2+1}\\) e semplifica.</p>",
        });
        await studioEsercizio.vaiA(esercizio.studioUrl);
        await studioEsercizio.topbar.attendiPronta();

        const generazione = studioEsercizio.generazione;
        await generazione.selezionaPrimoQuesito("A");
        const titolo = naming.unique("formule-sorgente");
        await generazione.compilaInformazioni({ titolo, copie: 10 });

        const risposta = await generazione.genera();
        expect(risposta.status(), "il salvataggio riesce").toBe(200);
        const corpo = await risposta.json();
        const documenti = registraCancellazione(verificaFactory, corpo, `variante di «${titolo}»`);
        const perStudenti = documenti.find((d) => d.variant === "A_NOR");
        expect(perStudenti, "la variante da stampare").toBeDefined();
        if (!perStudenti) return;

        const sorgente = await teacherApi.verifica.texFile(perStudenti.id, "versioni/esercizi_NOR.tex");
        expect(sorgente.content, "la formula c'è, scritta in LaTeX").toMatch(/\\sqrt/);
        expect(
            sorgente.content,
            "e nessun carattere del disegno è finito nel sorgente",
        ).not.toMatch(/[√\u{1D400}-\u{1D7FF}]/u);
    });
});
