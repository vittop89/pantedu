// @vitest-environment node
import { describe, it, expect } from "vitest";
import { existsSync, readFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import vm from "node:vm";

/**
 * playwright.config.js in CI (23/9/2026, revisione architetturale A-29): un
 * `test.only` dimenticato fa fallire il giro (`forbidOnly`), e il reporter JSON
 * scrive il file che `tools/ci/salti-e2e.mjs` legge dopo la suite.
 *
 * Senza questa prova, togliere `forbidOnly` non rendeva rosso niente: la
 * controprova c'era solo a mano. La configurazione si valuta in un contesto
 * isolato con un `process.env` scelto qui, un `@playwright/test` finto che
 * restituisce l'oggetto così com'è e un `fs` che non legge niente: `.env.local`
 * non si apre, e l'ambiente vero del processo non si tocca.
 */
const RADICE = path.join(path.dirname(fileURLToPath(import.meta.url)), "..", "..");

/**
 * @param {Record<string, string>} env
 * @returns {Record<string, any>} la configurazione che Playwright riceverebbe
 */
function configurazioneCon(env) {
    const codice = readFileSync(path.join(RADICE, "playwright.config.js"), "utf8");
    const modulo = { exports: {} };
    const richiedi = (nome) => {
        if (nome === "@playwright/test") return { defineConfig: (c) => c, devices: { "Desktop Chrome": {} } };
        if (nome === "fs") return { readFileSync: () => { throw new Error("in questa prova non si legge nessun file"); } };
        if (nome === "path") return path;
        throw new Error(`require inatteso in playwright.config.js: ${nome}`);
    };
    richiedi.resolve = (p) => p;
    const fabbrica = vm.runInNewContext(`(function (require, module, exports, __dirname, process) {\n${codice}\n})`, {});
    fabbrica(richiedi, modulo, modulo.exports, RADICE, { env: { ...env } });
    return modulo.exports;
}

describe("playwright.config.js — che cosa vale in CI", () => {
    it("in CI un test.only fa fallire il giro", () => {
        expect(configurazioneCon({ CI: "true" }).forbidOnly).toBe(true);
    });

    it("in locale test.only resta uno strumento di lavoro", () => {
        expect(configurazioneCon({}).forbidOnly).toBe(false);
    });

    // Nella copia pubblica `e2e.yml` non c'è (il sanitizer lo toglie: gira sui
    // runner di casa): lì questa prova non ha niente da confrontare e si salta,
    // invece di fare rossa la CI del repository pubblico (23/9/2026).
    const E2E = path.join(RADICE, ".github", "workflows", "e2e.yml");
    it.skipIf(!existsSync(E2E))("il reporter JSON scrive il file che il passo dei salti di e2e.yml legge", () => {
        const e2e = readFileSync(E2E, "utf8");
        const letto = e2e.match(/node tools\/ci\/salti-e2e\.mjs (\S+)/)?.[1];
        expect(letto).toBeTruthy();
        const reporter = configurazioneCon({ CI: "true" }).reporter;
        const json = reporter.find((/** @type {any[]} */ r) => r[0] === "json");
        expect(json?.[1]?.outputFile).toBe(letto);
    });
});
