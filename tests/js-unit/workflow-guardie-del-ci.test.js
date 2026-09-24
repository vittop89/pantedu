// @vitest-environment node
import { describe, it, expect, beforeAll, afterAll } from "vitest";
import { spawnSync } from "node:child_process";
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

/**
 * Regola 5 di tools/ci/check-workflows.mjs (23/9/2026): ogni guardia dello
 * script `npm run ci` la lancia un workflow. `legal:pdf`, `moduli:fascio` e
 * `percorsi:dati` stavano solo nello script, che nessun workflow esegue
 * (revisione architetturale del 23/9/2026, A-25).
 *
 * Nei due versi, su un package.json e dei workflow finti: scatta quando una
 * guardia dello script non compare in nessun workflow (o compare solo in un
 * commento), tace quando un passo la lancia, anche con argomenti in più.
 */
const RADICE = join(dirname(fileURLToPath(import.meta.url)), "..", "..");
const GUARDIA = join(RADICE, "tools", "ci", "check-workflows.mjs");

let cartella;
beforeAll(() => { cartella = mkdtempSync(join(tmpdir(), "workflow-guardie-")); });
afterAll(() => { rmSync(cartella, { recursive: true, force: true }); });

/**
 * Esegue la guardia con uno script `ci` e un workflow finti.
 * @param {string} nome
 * @param {string} ci    lo script `ci` di package.json
 * @param {string[]} run le righe `run:` del workflow, un passo ciascuna
 */
function esegui(nome, ci, run) {
    const dir = join(cartella, nome);
    mkdirSync(join(dir, "workflows"), { recursive: true });
    writeFileSync(join(dir, "package.json"), JSON.stringify({ scripts: { ci } }));
    const passi = run.map((r, i) => `      - name: passo ${i}\n        run: ${r}\n`).join("");
    // Il Node di .nvmrc, come vuole la regola 8: qui si guarda solo la 5.
    const node = '      - uses: actions/setup-node@v4\n        with:\n          node-version-file: ".nvmrc"\n';
    writeFileSync(join(dir, "workflows", "w.yml"), `jobs:\n  a:\n    steps:\n${node}${passi}`);
    const r = spawnSync(process.execPath, [GUARDIA], {
        env: { ...process.env, WORKFLOWS_DIR: join(dir, "workflows"), PACKAGE_JSON: join(dir, "package.json") },
        encoding: "utf8",
    });
    return { esito: r.status, testo: `${r.stdout}${r.stderr}` };
}

describe("guardia dei workflow — le guardie di npm run ci girano in CI", () => {
    it("scatta e nomina la guardia che nessun workflow lancia", () => {
        const { esito, testo } = esegui("manca", "npm run lint && npm run legal:pdf", ["npm run lint -- --format=stylish"]);
        expect(testo).toContain("`npm run legal:pdf` è nello script `ci` ma nessun workflow lo lancia");
        expect(testo).not.toContain("`npm run lint` è nello script");
        expect(esito).toBe(1);
    });

    it("un commento che la nomina non conta, né in coda né a riga intera", () => {
        for (const [nome, run] of [
            ["in-coda", "npm run build  # poi npm run moduli:fascio"],
            ["a-riga", "|\n          npm run build\n          # npm run moduli:fascio"],
        ]) {
            const { esito, testo } = esegui(nome, "npm run build && npm run moduli:fascio", [run]);
            expect(testo, nome).toContain("`npm run moduli:fascio` è nello script `ci`");
            expect(esito, nome).toBe(1);
        }
    });

    it("tace quando ogni guardia ha il suo passo, anche con argomenti in più", () => {
        const { esito, testo } = esegui("tutte", "npm run lint && npm run legal:pdf && npm run percorsi:dati", [
            "npm run lint -- --format=stylish",
            "npm run legal:pdf",
            "npm run percorsi:dati",
        ]);
        expect(testo).toContain("le 3 guardie di `npm run ci` lanciate da un workflow");
        expect(esito).toBe(0);
    });

    it("scatta se lo script ci non lancia niente: un elenco vuoto non è un elenco rispettato", () => {
        const { esito, testo } = esegui("vuoto", "echo niente", ["npm run lint"]);
        expect(testo).toContain("non lancia nessun");
        expect(esito).toBe(1);
    });

    it("i workflow e il package.json del repository la rispettano", () => {
        const r = spawnSync(process.execPath, [GUARDIA], { encoding: "utf8" });
        expect(`${r.stdout}${r.stderr}`).not.toContain("nessun workflow lo lancia");
        expect(r.status).toBe(0);
    });
});
