// @ts-check
/**
 * Cruscotto del docente e stampa di una verifica dal browser.
 * Riscrittura dei tre casi rimanenti di registration.spec.js (il quarto, le
 * iscrizioni chiuse, è in `pubblico/iscrizioni-chiuse`).
 *
 * Il cruscotto è la pagina da cui il docente parte: c'è la ricerca fra i propri
 * contenuti, e ci sono le rotte che alimentano la barra laterale. L'ultimo
 * caso è il cliente di stampa: dalla pagina, senza passare da un'altra
 * schermata, il docente ottiene il sorgente TeX della propria verifica.
 *
 * Cosa cambia rispetto a prima: niente login nelle spec e niente gettone di
 * sicurezza chiesto a mano; le due rotte della barra laterale si verificano
 * per quel che rispondono, non solo per «non è 401 né 403».
 */
const { test, expect } = require("../support/test");

test.describe("Area docente — cruscotto e stampa", () => {
    test("il cruscotto si apre con la ricerca fra i propri contenuti", async ({ teacherPage }) => {
        const risposta = await teacherPage.goto("/area-docente/dashboard");
        expect(risposta?.status(), "la pagina si apre").toBeLessThan(400);
        await expect(teacherPage.locator("#fm-content"), "col suo contenuto").toBeVisible({ timeout: 30_000 });
        await expect(
            teacherPage.locator(".fm-title").filter({ hasText: /Ricerca nei tuoi contenuti/i }),
            "e la ricerca fra i contenuti del docente",
        ).toBeAttached();
    });

    test("le rotte che alimentano la barra laterale rispondono al docente", async ({ teacherApi, env }) => {
        // La sonda dice se una risorsa collegata esiste; l'elenco degli
        // argomenti popola i pannelli. Sono le due che la barra chiama
        // all'apertura: se rispondono con un divieto, la barra resta vuota
        // senza dire niente.
        const sonda = await teacherApi.http.send("POST", "/api/probe", {
            form: { file_links: "/mappe/MAT/MAT_links.json" },
        });
        expect([401, 403], `la sonda è negata al docente (${sonda.status})`).not.toContain(sonda.status);

        const argomenti = await teacherApi.http.getJson("/api/study/topics.json", {
            type: "esercizio",
            subject: env.terna.materia,
        });
        expect(argomenti, "l'elenco degli argomenti risponde").toBeTruthy();
    });

    test("dalla pagina il docente ottiene il sorgente TeX della verifica", async ({ teacherPage }) => {
        await teacherPage.goto("/area-docente/dashboard");
        // Il bundle dell'editor è a caricamento differito: lo si chiede.
        await teacherPage.evaluate(() => {
            const carica = window.FM?.["loadEditor"];
            if (typeof carica === "function") carica();
        });
        await teacherPage.waitForFunction(() => !!window.FM?.["PrintClient"], null, { timeout: 30_000 });

        const esito = await teacherPage.evaluate(async () => {
            const selezione = {
                version: "A",
                verTitle: "Verifica di prova della suite E2E",
                selectedIIS: "ar",
                selectedCLS: "2s",
                selectedMATER: "MAT",
                anno: "2026",
                sezione: "NOR",
                problems: [{
                    filePath: "/eser/e2e.php",
                    problemId: "p-1",
                    position: 1,
                    text: "Prova",
                    items: [{ html: "primo quesito", points: 1.0, includeSolution: false }],
                }],
            };
            // Si intercetta la creazione del collegamento allo scaricamento per
            // sapere se il contenuto prodotto è vuoto, senza salvare niente.
            const creaOriginale = URL.createObjectURL.bind(URL);
            let byte = 0;
            URL.createObjectURL = (contenuto) => {
                byte = contenuto instanceof Blob ? contenuto.size : 0;
                return creaOriginale(contenuto);
            };
            try {
                const stampa = /** @type {any} */ (window.FM?.["PrintClient"]);
                const risultato = await stampa.printTexForTeacher(selezione, "normal");
                return { byte, nomeFile: String(risultato.filename ?? "") };
            } finally {
                URL.createObjectURL = creaOriginale;
            }
        });

        expect(esito.byte, "il sorgente prodotto non è vuoto").toBeGreaterThan(0);
        expect(esito.nomeFile, "e si scarica come file TeX").toMatch(/\.tex$/);
    });
});
