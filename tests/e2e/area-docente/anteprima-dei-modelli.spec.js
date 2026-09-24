// @ts-check
/**
 * Anteprima PDF di un file dei modelli, anche quando non è un documento intero.
 * Riscrittura di tre casi di g22_s15bis_fase5_templates_full.spec.js.
 *
 * Dall'editor dei modelli si chiede l'anteprima del file che si sta scrivendo.
 * Il file però può essere un pezzo — un foglio di stile `.sty`, un'intestazione
 * da includere — che da solo non compila: l'applicazione lo avvolge in un
 * documento minimo. Se l'avvolgimento non funziona, la risposta arriva lo
 * stesso ma non è un PDF, e l'anteprima mostra caratteri illeggibili.
 *
 * Cosa cambia rispetto a prima: niente login né gettone di sicurezza chiesto a
 * mano, niente stampe di console, e i tre casi che erano dentro la spec dei
 * modelli stanno qui, dove si vede che sono la stessa cosa vista da tre file
 * diversi.
 *
 * Tutti e tre portano `@tex`: l'anteprima compila davvero, passando dal
 * microservizio TeX, e senza quello non hanno niente da verificare (in
 * integrazione continua sono escluse insieme alle altre `@tex`).
 */
const { test, expect } = require("../support/test");

const FOGLIO_DI_STILE = [
    "\\NeedsTeXFormat{LaTeX2e}",
    "\\ProvidesPackage{verifica}[2026/05/04 Prova]",
    "\\RequirePackage{xcolor}",
    "\\definecolor{provaColore}{RGB}{200,30,30}",
    "",
].join("\n");

/** Chiede l'anteprima e verifica che quel che torna sia davvero un PDF. */
async function anteprima(/** @type {any} */ teacherApi, /** @type {string} */ rotta, /** @type {Record<string, unknown>} */ corpo) {
    const risposta = await teacherApi.http.postRaw(rotta, { json: corpo });
    return { risposta, contenuto: await risposta.body() };
}

test.describe("Area docente — anteprima dei modelli", () => {
    test("un foglio di stile da solo produce un PDF, non caratteri illeggibili @tex", async ({ teacherApi }) => {
        const { risposta, contenuto } = await anteprima(teacherApi, "/api/teacher/verifica/files/preview-pdf", {
            path: "texCommon/verifica.sty",
            content: FOGLIO_DI_STILE,
        });

        expect(risposta.status(), "l'anteprima riesce").toBe(200);
        expect(risposta.headers()["content-type"], "e risponde con un PDF").toContain("pdf");
        expect(contenuto.subarray(0, 5).toString("ascii"), "che comincia come un PDF").toBe("%PDF-");
        expect(contenuto.length, "e non è un guscio vuoto").toBeGreaterThan(1000);
    });

    test("l'intestazione delle verifiche compila da sola @tex", async ({ teacherApi }) => {
        // Senza contenuto: l'applicazione prende il file come sta sul server.
        // È il caso in cui una parentesi non chiusa nel modello fermava tutto.
        const { risposta, contenuto } = await anteprima(teacherApi, "/api/teacher/verifica/files/preview-pdf", {
            path: "texCommon/intestazione.tex",
        });

        expect(risposta.status(), "l'anteprima riesce").toBe(200);
        expect(risposta.headers()["content-type"], "e risponde con un PDF").toContain("pdf");
        expect(contenuto.subarray(0, 5).toString("ascii"), "che comincia come un PDF").toBe("%PDF-");
    });

    test("anche il documento principale delle risorse docente compila @tex", async ({ teacherApi }) => {
        const { risposta, contenuto } = await anteprima(teacherApi, "/api/teacher/risdoc/templates/files/preview-pdf", {
            path: "main.tex",
        });

        expect(risposta.status(), "l'anteprima riesce").toBe(200);
        expect(risposta.headers()["content-type"], "e risponde con un PDF").toContain("pdf");
        expect(contenuto.subarray(0, 5).toString("ascii"), "che comincia come un PDF").toBe("%PDF-");
    });
});
