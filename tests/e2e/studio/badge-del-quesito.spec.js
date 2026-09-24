// @ts-check
/**
 * Il cartellino del quesito: pagina, numero, difficoltà e da che libro viene.
 * Riscrittura di badge_source_citation.spec.js e del primo caso di
 * g19_8_badge_and_curriculum.spec.js.
 *
 * Accanto a ogni quesito c'è un cartellino: il numero dell'esercizio dentro un
 * riquadro colorato, la pagina sotto, i pallini della difficoltà sopra, e — se
 * il quesito dichiara da dove viene — la citazione del libro accanto. È scritto
 * in LaTeX, così la pagina e il documento stampato mostrano la stessa cosa.
 *
 * Quel che si verifica qui è che le due strade portino allo stesso posto: in
 * pagina il cartellino è disegnato con la citazione, e nel sorgente della
 * verifica arriva come macro con i suoi argomenti.
 *
 * Cosa cambia rispetto a prima: la password non è più scritta nella spec — c'era,
 * letta dall'ambiente — il quesito con il cartellino nasce dal test invece di
 * essere «P-351 esercizio 255» del dump locale, e i dati del cartellino si
 * scrivono dall'API invece che costruendo il LaTeX a mano dentro la pagina,
 * che era un modo di verificare la propria copia della funzione.
 */
const { test, expect } = require("../support/test");

/** La fonte a cui si aggancia la citazione, scritta nel registro dal test. */
const FONTE = "e2e_fonte_cartellino";
const LIBRO = { key: FONTE, book: "Manuale di prova E2E", volume: "Vol.1", authors: "Suite E2E" };

/** I dati del cartellino, scritti sul quesito prima di aprire la pagina. */
const CARTELLINO = {
    source_key: FONTE,
    page: "726",
    ex_num: "455",
    difficulty: 2,
    bg_color: "green",
};

test.describe("Studio esercizio — cartellino del quesito", () => {
    /**
     * Mette la fonte nel registro personale del docente e la toglie a fine
     * test: la citazione viene da lì, e senza registro il cartellino resta
     * senza libro.
     * @param {import("../support/test").TeacherApi} teacherApi
     * @param {import("../support/test").CleanupRegistry} cleanup
     */
    async function conLaFonteInRegistro(teacherApi, cleanup) {
        const registro = await teacherApi.sources.registry();
        const prima = registro.sources ?? [];
        if (prima.some((f) => f.key === FONTE)) return;
        cleanup.add("teacher", "rimette il registro delle fonti com'era", async () => {
            await teacherApi.sources.saveRegistry(prima);
        });
        await teacherApi.sources.saveRegistry([...prima, LIBRO]);
    }

    test("in pagina il cartellino porta i suoi dati e la citazione del libro", async ({
        contentFactory, studioEsercizio, teacherApi, teacherPage, cleanup,
    }) => {
        await conLaFonteInRegistro(teacherApi, cleanup);
        const esercizio = await contentFactory.exercise({ groups: 1, itemsPerGroup: 1, publish: true });
        await teacherApi.content.patchQuesito(esercizio.id, "g0_q0", { badge: JSON.stringify(CARTELLINO) });

        await studioEsercizio.vaiA(esercizio.studioUrl);
        await studioEsercizio.gruppo(0).apri();

        const cartellino = teacherPage.locator(".fm-badge[data-source]").first();
        await expect(cartellino, "il cartellino c'è").toBeVisible({ timeout: 30_000 });
        await expect(cartellino, "e dichiara la fonte").toHaveAttribute("data-source", FONTE);
        await expect(cartellino).toHaveAttribute("data-page", CARTELLINO.page);
        await expect(cartellino).toHaveAttribute("data-ex-num", CARTELLINO.ex_num);
        await expect(cartellino).toHaveAttribute("data-difficulty", String(CARTELLINO.difficulty));

        const sorgente = (await cartellino.getAttribute("data-raw")) ?? "";
        expect(sorgente, "la citazione del libro è nel sorgente, come tabella").toMatch(/\\begin\{array\}/);
        expect(sorgente, "e i pallini dicono la difficoltà").toMatch(/\\bullet\\bullet\\circ\\circ/);

        await expect
            .poll(() => cartellino.locator("mjx-container").count(), {
                message: "il cartellino viene disegnato, non lasciato scritto",
                timeout: 30_000,
            })
            .toBeGreaterThan(0);
    });

    test("nel sorgente della verifica il cartellino arriva come macro con i suoi argomenti @tex", async ({
        contentFactory, studioEsercizio, verificaFactory, teacherApi, teacherPage, naming,
    }) => {
        test.setTimeout(300_000);
        const esercizio = await contentFactory.exercise({ groups: 1, itemsPerGroup: 1, publish: true });
        await teacherApi.content.patchQuesito(esercizio.id, "g0_q0", { badge: JSON.stringify(CARTELLINO) });

        await studioEsercizio.vaiA(esercizio.studioUrl);
        await studioEsercizio.topbar.attendiPronta();

        const generazione = studioEsercizio.generazione;
        await generazione.selezionaPrimoQuesito("A");
        const titolo = naming.unique("cartellino");
        await generazione.compilaInformazioni({ titolo, copie: 10 });

        const risposta = await generazione.genera();
        expect(risposta.status(), "il salvataggio riesce").toBe(200);
        const corpo = await risposta.json();
        const documenti = Array.isArray(corpo?.docs) ? corpo.docs : [];
        verificaFactory.registerDeletion(
            documenti.map((/** @type {{ id: number }} */ d) => d.id),
            `variante di «${titolo}»`,
        );

        const conSoluzioni = documenti.find((/** @type {{ variant: string }} */ d) => d.variant === "A_SOL");
        expect(conSoluzioni, "la variante con le soluzioni").toBeDefined();
        if (!conSoluzioni) return;

        const sorgente = await teacherApi.verifica.texFile(conSoluzioni.id, "versioni/esercizi_SOL.tex");
        // Nel documento il cartellino non è LaTeX scritto a mano: è una macro,
        // definita nel preambolo, che riceve fonte, pagina, numero e difficoltà.
        expect(sorgente.content, "il cartellino è la macro, con i suoi argomenti")
            .toMatch(new RegExp(`\\\\badge(\\[[^\\]]*\\])?\\{[^}]*\\}\\{${CARTELLINO.page}\\}\\{${CARTELLINO.ex_num}\\}\\{${CARTELLINO.difficulty}\\}`));

        expect(teacherPage.url(), "si è lavorato sulla pagina dell'esercizio").toContain(String(esercizio.id));
    });
});
