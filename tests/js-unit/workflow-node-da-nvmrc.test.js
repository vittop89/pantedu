// @vitest-environment node
import { describe, it, expect, beforeAll, afterAll } from "vitest";
import { spawnSync } from "node:child_process";
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

/**
 * Regola 8 di tools/ci/check-workflows.mjs (23/9/2026): ogni lavoro che lancia
 * node, npm o npx prende il Node da `.nvmrc` con setup-node, su ogni runner.
 * Fino a quel giorno setup-node si saltava in casa (`if: ${{ !vars.RUNNER }}`)
 * e girava il Node di sistema della macchina: il passaggio di `.nvmrc` a 22
 * (revisione architetturale, D-5) avrebbe fatto rosso il lavoro obbligatorio
 * «Front-end» finché qualcuno non aggiornava a mano ogni runner.
 *
 * Nei due versi, su workflow finti: scatta sul lavoro senza setup-node, sul
 * setup-node con un `if:` (anche scritto sulla riga del trattino) e sulla
 * versione scritta a mano; tace sulla forma giusta, sulla cache di npm
 * accesa solo sui runner di GitHub e sui lavori che non usano Node.
 */
const RADICE = join(dirname(fileURLToPath(import.meta.url)), "..", "..");
const GUARDIA = join(RADICE, "tools", "ci", "check-workflows.mjs");

let cartella;
beforeAll(() => { cartella = mkdtempSync(join(tmpdir(), "workflow-node-")); });
afterAll(() => { rmSync(cartella, { recursive: true, force: true }); });

/**
 * Esegue la guardia su un workflow con un lavoro solo.
 * @param {string} nome
 * @param {string} passi i passi del lavoro, già indentati di sei spazi
 * @returns {string[]} i problemi della regola 8, uno per riga di intestazione
 */
function problemi(nome, passi) {
    const dir = join(cartella, nome);
    mkdirSync(dir, { recursive: true });
    writeFileSync(join(dir, "w.yml"), `jobs:\n  a:\n    runs-on: x\n    steps:\n${passi}`);
    writeFileSync(join(dir, "package.json"), JSON.stringify({ scripts: { ci: "npm run prova" } }));
    writeFileSync(join(dir, "altro.yml"), "jobs:\n  b:\n    steps:\n      - run: echo npm run prova\n");
    const r = spawnSync(process.execPath, [GUARDIA], {
        env: { ...process.env, WORKFLOWS_DIR: dir, PACKAGE_JSON: join(dir, "package.json") },
        encoding: "utf8",
    });
    return `${r.stdout}${r.stderr}`.split("\n").filter((l) => /✗ w\.yml:\d+ — (il lavoro|il passo setup-node)/.test(l));
}

const NPM_CI = "      - run: npm ci --no-audit --no-fund\n";
const GIUSTO =
    "      - uses: actions/setup-node@v4\n" +
    "        with:\n" +
    '          node-version-file: ".nvmrc"\n' +
    "          cache: ${{ !vars.RUNNER && 'npm' || '' }}\n";

describe("guardia dei workflow — il Node lo sceglie .nvmrc, su ogni runner", () => {
    it("scatta su un lavoro che lancia npm senza setup-node", () => {
        const trovati = problemi("senza", `      - uses: actions/checkout@v4\n${NPM_CI}`);
        expect(trovati).toHaveLength(1);
        expect(trovati[0]).toContain("senza un passo actions/setup-node");
    });

    it("scatta su setup-node saltato in casa, com'era fino al 23/9/2026", () => {
        const saltato =
            "      - uses: actions/setup-node@v4\n" +
            "        if: ${{ !vars.RUNNER }}\n" +
            "        with:\n" +
            '          node-version-file: ".nvmrc"\n';
        const trovati = problemi("saltato", saltato + NPM_CI);
        expect(trovati).toHaveLength(1);
        expect(trovati[0]).toContain("ha un `if:`");
    });

    it("scatta sull'if scritto sulla riga del trattino, prima di uses", () => {
        const primo =
            "      - if: ${{ !vars.RUNNER }}\n" +
            "        uses: actions/setup-node@v4\n" +
            "        with:\n" +
            '          node-version-file: ".nvmrc"\n';
        expect(problemi("if-primo", primo + NPM_CI)).toHaveLength(1);
    });

    it("scatta sulla versione scritta a mano, anche accanto al file", () => {
        const aMano = "      - uses: actions/setup-node@v4\n        with:\n          node-version: \"20\"\n";
        expect(problemi("a-mano", aMano + NPM_CI)[0]).toContain("non prende la versione da .nvmrc");
        const tutteDue = `${GIUSTO}          node-version: "20"\n`;
        expect(problemi("tutte-due", tutteDue + NPM_CI)).toHaveLength(1);
    });

    it("scatta anche quando il lavoro lancia solo node", () => {
        expect(problemi("solo-node", "      - run: node tools/ci/salti-e2e.mjs x.json\n")).toHaveLength(1);
    });

    it("tace sulla forma giusta, con la cache di npm solo sui runner di GitHub", () => {
        expect(problemi("giusto", GIUSTO + NPM_CI)).toHaveLength(0);
        const conNome = `      - name: Set up Node.js\n        uses: actions/setup-node@v4\n        with:\n          node-version-file: .nvmrc\n${NPM_CI}`;
        expect(problemi("con-nome", conNome)).toHaveLength(0);
    });

    it("tace su un lavoro che non usa Node, anche se un nome o un commento lo nomina", () => {
        const php =
            "      - name: npm audit? no, composer\n" +
            "        # npm ci qui non serve\n" +
            "        run: composer audit --locked\n" +
            "      - run: docker run --rm node:22 true\n";
        expect(problemi("php", php)).toHaveLength(0);
    });

    it("i workflow del repository la rispettano", () => {
        const r = spawnSync(process.execPath, [GUARDIA], { encoding: "utf8" });
        expect(`${r.stdout}${r.stderr}`).toMatch(/[1-9]\d* lavori con il Node di \.nvmrc su ogni runner/);
        expect(r.status).toBe(0);
    });
});
