// @vitest-environment node
import { describe, it, expect } from "vitest";
import { ESLint } from "eslint";
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import configurazione from "../../eslint.config.mjs";

/**
 * Che cosa guarda ESLint (23/9/2026, revisione architetturale A-30).
 *
 * Fino a quel giorno `js/components/**` e i due script del router
 * (`js/fm-router.js`, `js/fm-url-state.js`, caricati su ogni pagina) erano
 * esclusi, e otto esclusioni puntavano a file che non esistevano più. Un file
 * escluso non dà avvisi: sembra pulito. Qui si prova che i file rientrati
 * ricevono le regole, e che un'esclusione sotto `js/` nomina un file o una
 * cartella che git conosce: l'esclusione morta fa credere che il lint guardi
 * meno di quanto guarda, e il giorno che il file torna lo esclude in silenzio.
 */
const RADICE = join(dirname(fileURLToPath(import.meta.url)), "..", "..");
const eslint = new ESLint({ cwd: RADICE });

describe("ESLint — i file che deve guardare li guarda", () => {
    it("i due script del router ricevono le regole del front-end, come script classici", async () => {
        for (const file of ["js/fm-router.js", "js/fm-url-state.js"]) {
            expect(await eslint.isPathIgnored(file), file).toBe(false);
            const regole = await eslint.calculateConfigForFile(file);
            expect(regole.languageOptions.sourceType, file).toBe("script");
            expect(regole.rules["no-undef"]?.[0], file).toBe(2);
            expect(regole.rules["no-eval"]?.[0], file).toBe(2);
        }
    });

    it("i componenti Lit ricevono le regole dei moduli, sicurezza compresa", async () => {
        const componenti = execFileSync("git", ["ls-files", "js/components/*.js", "js/components/**/*.js"], { cwd: RADICE, encoding: "utf8" })
            .split("\n").filter(Boolean);
        expect(componenti.length).toBeGreaterThan(0);
        for (const file of componenti) {
            expect(await eslint.isPathIgnored(file), file).toBe(false);
        }
        const regole = await eslint.calculateConfigForFile(componenti[0]);
        expect(regole.rules["no-implied-eval"]?.[0]).toBe(2);
    });

    it("le esclusioni sotto js/ nominano file che git conosce", () => {
        const esclusioni = configurazione.flatMap((blocco) => (blocco.files ? [] : blocco.ignores ?? []))
            .filter((voce) => voce.startsWith("js/"));
        const versionati = execFileSync("git", ["ls-files", "js"], { cwd: RADICE, encoding: "utf8" }).split("\n");
        const morte = esclusioni.filter((voce) => {
            const radice = voce.replace(/\/\*\*.*$/, "");
            return !versionati.some((f) => f === radice || f.startsWith(`${radice}/`));
        });
        expect(morte, "esclusioni di file che non esistono").toEqual([]);
    });

    it("la soglia degli avvisi è un numero, non l'assenza di una soglia", () => {
        const pacchetto = JSON.parse(readFileSync(join(RADICE, "package.json"), "utf8"));
        expect(pacchetto.scripts.lint).toMatch(/^eslint --max-warnings=\d+$/);
    });
});
