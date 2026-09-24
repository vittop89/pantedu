// @vitest-environment node
import { describe, it, expect, beforeAll, afterAll } from "vitest";
import { spawnSync } from "node:child_process";
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

/**
 * Regola 7 di tools/ci/check-workflows.mjs (23/9/2026, revisione architetturale
 * A-28): l'avviso di un workflow guarda tutti i suoi lavori, ne combina gli
 * esiti, e scatta su ogni giro senza nessuno davanti (`schedule`, `push`).
 * Il giorno della revisione l'avviso di compliance.yml guardava solo
 * `reuse-lint`, e quello di e2e.yml solo il giro della domenica.
 *
 * Nei due versi, su workflow finti nella forma di quelli veri.
 */
const RADICE = join(dirname(fileURLToPath(import.meta.url)), "..", "..");
const GUARDIA = join(RADICE, "tools", "ci", "check-workflows.mjs");

let cartella;
beforeAll(() => { cartella = mkdtempSync(join(tmpdir(), "workflow-avvisi-")); });
afterAll(() => { rmSync(cartella, { recursive: true, force: true }); });

/**
 * Un workflow con due lavori e un avviso.
 * @param {{ on: string[], needs: string, se: string, esito: string }} p
 */
function workflow({ on, needs, se, esito }) {
    const inneschi = on.map((e) => (e === "schedule" ? `  schedule:\n    - cron: "0 4 * * 0"` : `  ${e}:`)).join("\n");
    return `name: prova
on:
${inneschi}
jobs:
  primo:
    runs-on: ubuntu-latest
    steps:
      - run: echo uno
  secondo:
    runs-on: ubuntu-latest
    steps:
      - run: echo due
  # un commento a colonna due non è un lavoro
  avviso:
    needs: ${needs}
    if: ${se}
    uses: ./.github/workflows/avvisa-guasto.yml
    with:
      giro: "prova"
      esito: ${esito}
`;
}

/** Esegue la guardia; restituisce le righe della regola 7. */
function problemi(nome, testo) {
    const dir = join(cartella, nome);
    mkdirSync(dir, { recursive: true });
    writeFileSync(join(dir, "w.yml"), testo);
    writeFileSync(join(dir, "package.json"), JSON.stringify({ scripts: { ci: "npm run prova" } }));
    writeFileSync(join(dir, "altro.yml"), "jobs:\n  b:\n    steps:\n      - run: npm run prova\n");
    const r = spawnSync(process.execPath, [GUARDIA], {
        env: { ...process.env, WORKFLOWS_DIR: dir, PACKAGE_JSON: join(dir, "package.json") },
        encoding: "utf8",
    });
    return `${r.stdout}${r.stderr}`.split("\n").filter((l) => /✗ w\.yml:\d+ — (l'avviso|il workflow gira)/.test(l));
}

const COMBINATO = "${{ contains(needs.*.result, 'failure') && 'failure' || 'success' }}";

describe("guardia dei workflow — l'avviso guarda tutto quello che deve", () => {
    it("scatta se l'avviso non dipende da un lavoro (compliance.yml fino al 23/9)", () => {
        const trovati = problemi("uno-solo", workflow({
            on: ["schedule", "workflow_dispatch"], needs: "[primo]",
            se: "${{ always() && github.event_name == 'schedule' }}", esito: "${{ needs.primo.result }}",
        }));
        expect(trovati).toHaveLength(1);
        expect(trovati[0]).toContain("non guarda «secondo»");
    });

    it("scatta se dipende da tutti ma l'esito è di uno solo", () => {
        const trovati = problemi("esito-singolo", workflow({
            on: ["schedule"], needs: "[primo, secondo]",
            se: "${{ always() && github.event_name == 'schedule' }}", esito: "${{ needs.primo.result }}",
        }));
        expect(trovati).toHaveLength(1);
        expect(trovati[0]).toContain("non li combina");
    });

    it("scatta se il workflow gira dopo una spinta e l'avviso no (e2e.yml fino al 23/9)", () => {
        const trovati = problemi("senza-push", workflow({
            on: ["push", "workflow_dispatch", "schedule"], needs: "[primo, secondo]",
            se: "${{ always() && github.event_name == 'schedule' }}", esito: COMBINATO,
        }));
        expect(trovati).toHaveLength(1);
        expect(trovati[0]).toContain("gira su «push» ma l'avviso no");
    });

    it("tace quando guarda tutti i lavori, li combina e scatta su ogni giro senza nessuno davanti", () => {
        expect(problemi("tutto", workflow({
            on: ["push", "workflow_dispatch", "schedule"], needs: "[primo, secondo]",
            se: "${{ always() && (github.event_name == 'schedule' || github.event_name == 'push') }}", esito: COMBINATO,
        }))).toEqual([]);
    });

    it("tace sulle pull request e sui giri a mano: lì chi ha lanciato il giro sta guardando", () => {
        expect(problemi("pull-request", workflow({
            on: ["pull_request", "workflow_dispatch", "schedule"], needs: "[primo, secondo]",
            se: "${{ always() && github.event_name == 'schedule' }}", esito: COMBINATO,
        }))).toEqual([]);
    });

    it("i workflow del repository la rispettano", () => {
        const r = spawnSync(process.execPath, [GUARDIA], { encoding: "utf8" });
        expect(`${r.stdout}${r.stderr}`).toMatch(/[1-9]\d* avvisi che guardano tutti i lavori/);
        expect(r.status).toBe(0);
    });
});
