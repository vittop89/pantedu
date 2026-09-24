// @ts-check
/**
 * Elenchi numerati e figure nel sorgente TeX. @tex
 * Riscrittura di sanitizer_list_tikz_fix.spec.js e
 * salvatex_lavatrice_real_dom.spec.js.
 *
 * Un quesito può contenere un elenco di richieste numerate e una figura. Nel
 * passaggio dalla pagina al documento, quell'elenco deve diventare un
 * `enumerate` con i suoi `\item`, e la figura deve arrivare come sorgente
 * TikZ, non come il disegno già fatto. Nel mezzo va tolto tutto quel che è
 * interfaccia — i pulsanti per marcare i punti, i numeri scritti a mano — e le
 * entità HTML vanno rimesse in caratteri.
 *
 * Il caso storico si chiamava «lavatrice»: un problema con quattro richieste e
 * un disegno, quello su cui il difetto era stato visto.
 *
 * Cosa cambia rispetto a prima: le due password lette dall'ambiente non ci
 * sono più, la verifica prodotta viene cancellata invece di restare nel
 * database, i file salvati su disco «per la revisione visuale» non ci sono più
 * (nessuno li guardava), e le asserzioni sul TeX non sono più dentro un `if`
 * che le saltava quando il salvataggio falliva.
 */
const { test, expect } = require("../support/test");

const FIGURA = [
    "\\begin{tikzpicture}",
    "  \\foreach \\i in {1,...,5} {",
    "    \\ifnum\\i<3 \\draw (\\i,0) circle (1pt); \\fi",
    "  }",
    "  \\draw[->] (-1,0) -- (5,0);",
    "\\end{tikzpicture}",
].join("\n");

/** Le quattro richieste, con i pulsanti e i numeri che mette l'interfaccia. */
const RICHIESTE = [
    "scrivi la legge che esprime $P$ in funzione delle ore $t$ di manodopera",
    "rappresentala nel piano cartesiano.",
    "A quanto ammonta $P$ se il tecnico ha lavorato per un'ora e mezza?",
    "Quante ore ha impiegato il tecnico se il prezzo della riparazione &gt; 179,50 €?",
];

const QUESITO_CON_ELENCO = [
    '<span class="fm-text">Il prezzo </span><span class="fm-latex">$P$</span>',
    '<span class="fm-text"> per la riparazione di una lavatrice prevede 35 € fissi per la chiamata.</span>',
    '<ol class="fm-dsa-li-list">',
    ...RICHIESTE.map((richiesta, indice) =>
        `<li data-fm-dsa-state=""><span class="fm-dsa-li-buttons" aria-label="Marca DSA">`
        + `<button type="button" class="fm-dsa-li-btn fm-dsa-li-F" data-mark="F">F</button>`
        + `<button type="button" class="fm-dsa-li-btn fm-dsa-li-GF" data-mark="GF">GF</button></span>`
        + `<span class="fm-dsa-li-num">${indice + 1}.</span>`
        + `<span class="fm-dsa-li-content">${richiesta}</span></li>`),
    "</ol>",
    `<svg data-tikz-hash="abc" data-tikz-body="${encodeURIComponent(FIGURA)}" xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>`,
].join("");

test.describe("Verifiche — elenchi e figure nel sorgente TeX", () => {
    /** @type {string} */
    let sorgente;

    test.beforeEach(async ({ verificaFactory, teacherApi, naming }) => {
        test.setTimeout(300_000);
        const gruppo = await verificaFactory.batch({
            title: naming.unique("elenco-e-figura"),
            versionLabel: "g22",
            overrides: {
                problems: [{
                    filePath: "/eser/ar/ar2s/MAT/1",
                    problemId: "elenco-e-figura",
                    position: 1,
                    type: "Collect",
                    text: "Risolvi il seguente problema.",
                    items: [{ html: QUESITO_CON_ELENCO, solution: "", points: 1, includeSolution: false }],
                }],
            },
        });
        const perStudenti = gruppo.docs.find((d) => d.variant === "A_NOR") ?? gruppo.docs[0];
        expect(perStudenti, "una variante da leggere").toBeDefined();
        if (!perStudenti) return;
        sorgente = (await teacherApi.verifica.texFile(perStudenti.id, "versioni/esercizi_NOR.tex")).content;
    });

    test("le quattro richieste diventano un elenco numerato, una per punto @tex", () => {
        expect(sorgente, "l'elenco è un ambiente enumerate").toMatch(/\\begin\{enumerate\}/);
        // Uno per la richiesta principale più uno per ciascuna delle quattro.
        expect((sorgente.match(/\\item\b/g) ?? []).length, "cinque punti in tutto")
            .toBeGreaterThanOrEqual(5);
        for (const richiesta of ["scrivi la legge", "rappresentala", "ammonta", "Quante ore"]) {
            expect(sorgente, `la richiesta «${richiesta}» c'è`).toContain(richiesta);
        }
        expect(sorgente, "e i punti sono rientrati, come li lascia il formattatore").toMatch(/\n[\t ]+\\item/);
    });

    test("dell'interfaccia non arriva niente nel documento @tex", () => {
        expect(sorgente, "niente elenchi HTML").not.toMatch(/<ol[\s>]/);
        expect(sorgente, "niente punti HTML").not.toMatch(/<li[\s>]/);
        expect(sorgente, "niente pulsanti per marcare i punti").not.toContain("fm-dsa-li-buttons");
        expect(sorgente, "niente disegno già fatto").not.toMatch(/<svg/);
        expect(sorgente, "le entità sono tornate caratteri").not.toMatch(/&(gt|lt|amp|nbsp);/);
        expect(sorgente, "il maggiore è un maggiore").toContain("> 179,50");
    });

    test("la figura arriva come sorgente, e il preambolo resta fuori @tex", () => {
        expect(sorgente, "la figura c'è").toMatch(/\\begin\{tikzpicture\}/);
        expect(sorgente, "con il suo ciclo").toContain("\\foreach");
        expect(sorgente, "e la sua condizione").toMatch(/\\ifnum\\i\s*<\s*3/);

        const dentroLaFigura = sorgente.match(/\\begin\{tikzpicture\}[\s\S]*?\\end\{tikzpicture\}/)?.[0] ?? "";
        expect(dentroLaFigura, "nessun pacchetto dichiarato dentro la figura").not.toMatch(/\\usepackage\b/);
        expect(dentroLaFigura, "e nessun inizio di documento").not.toMatch(/\\begin\{document\}/);
    });
});

test.describe("Verifiche — l'elenco raccolto dalla pagina", () => {
    test("un elenco scritto nel quesito arriva numerato nel documento generato @tex", async ({
        contentFactory, studioEsercizio, verificaFactory, teacherApi, naming,
    }) => {
        test.setTimeout(300_000);
        // Qui non si costruisce la selezione a mano: si scrive l'elenco nel
        // quesito, si apre la pagina e si genera. È la strada che percorre il
        // docente, e quella dove l'elenco veniva perso perché chi raccoglieva
        // il contenuto non guardava dentro `<ol class="fm-dsa-li-list">`.
        const esercizio = await contentFactory.exercise({
            groups: 1,
            itemsPerGroup: 1,
            publish: true,
            itemHtml: '<p>Rispondi:</p><ol class="fm-dsa-li-list">'
                + RICHIESTE.map((r) => `<li><span class="fm-dsa-li-content">${r}</span></li>`).join("")
                + "</ol>",
        });
        await studioEsercizio.vaiA(esercizio.studioUrl);
        await studioEsercizio.topbar.attendiPronta();

        const generazione = studioEsercizio.generazione;
        await generazione.selezionaPrimoQuesito("A");
        const titolo = naming.unique("elenco-dalla-pagina");
        await generazione.compilaInformazioni({ titolo, copie: 10 });

        const risposta = await generazione.genera();
        expect(risposta.status(), "il salvataggio riesce").toBe(200);
        const corpo = await risposta.json();
        const documenti = Array.isArray(corpo?.docs) ? corpo.docs : [];
        verificaFactory.registerDeletion(
            documenti.map((/** @type {{ id: number }} */ d) => d.id),
            `variante di «${titolo}»`,
        );

        const perStudenti = documenti.find((/** @type {{ variant: string }} */ d) => d.variant === "A_NOR");
        expect(perStudenti, "la variante da stampare").toBeDefined();
        if (!perStudenti) return;

        const prodotto = (await teacherApi.verifica.texFile(perStudenti.id, "versioni/esercizi_NOR.tex")).content;
        expect(prodotto, "l'elenco è arrivato").toMatch(/\\begin\{enumerate\}/);
        expect((prodotto.match(/\\item\b/g) ?? []).length, "con i suoi punti").toBeGreaterThanOrEqual(4);
        expect(prodotto, "e il testo delle richieste").toContain("scrivi la legge");
    });
});
