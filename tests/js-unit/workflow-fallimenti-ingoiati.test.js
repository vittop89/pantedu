// @vitest-environment node
import { describe, it, expect, beforeAll, afterAll } from "vitest";
import { spawnSync } from "node:child_process";
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

/**
 * Regola 6 di tools/ci/check-workflows.mjs (23/9/2026): un controllo non
 * ingoia il proprio fallimento. Il giorno della revisione architetturale
 * (A-27) PHPCS e Lighthouse avevano `continue-on-error: true`, e `a11y.yml` e
 * `lighthouse.yml` scrivevano `npm ci || npm install` e
 * `npm run build || echo …`.
 *
 * Nei due versi, su workflow finti: scatta sulle tre forme, tace sulle forme
 * che falliscono davvero (`|| { …; exit 1; }`), sulle catture di un valore
 * (`$(curl … || echo 000)`) e sulla diagnostica dei passi che girano solo
 * quando il lavoro è già rosso.
 */
const RADICE = join(dirname(fileURLToPath(import.meta.url)), "..", "..");
const GUARDIA = join(RADICE, "tools", "ci", "check-workflows.mjs");

let cartella;
beforeAll(() => { cartella = mkdtempSync(join(tmpdir(), "workflow-ingoiati-")); });
afterAll(() => { rmSync(cartella, { recursive: true, force: true }); });

/**
 * Esegue la guardia su un workflow con un passo solo.
 * @param {string} nome
 * @param {string} run  il corpo di `run: |`
 * @param {string} [extra] altre chiavi del passo (`if:`, `continue-on-error:`)
 * @returns {string[]} i problemi della regola 6, uno per riga di intestazione
 */
function problemi(nome, run, extra = "") {
    const corpo = run.split("\n").map((r) => `          ${r}`).join("\n");
    const chiavi = extra ? `        ${extra}\n` : "";
    return problemiDi(nome, `jobs:\n  a:\n    steps:\n      - name: ${nome}\n${chiavi}        run: |\n${corpo}\n`);
}

/**
 * Come `problemi`, con il workflow scritto per intero.
 * @param {string} nome
 * @param {string} yaml
 * @returns {string[]}
 */
function problemiDi(nome, yaml) {
    const dir = join(cartella, nome);
    mkdirSync(dir, { recursive: true });
    writeFileSync(join(dir, "w.yml"), yaml);
    // Lo script `ci` finto lancia una sola guardia, che il workflow finto nomina
    // in un commento a parte: qui si guarda solo la regola 6.
    writeFileSync(join(dir, "package.json"), JSON.stringify({ scripts: { ci: "npm run prova" } }));
    writeFileSync(join(dir, "altro.yml"), "jobs:\n  b:\n    steps:\n      - run: npm run prova\n");
    const r = spawnSync(process.execPath, [GUARDIA], {
        env: { ...process.env, WORKFLOWS_DIR: dir, PACKAGE_JSON: join(dir, "package.json") },
        encoding: "utf8",
    });
    return `${r.stdout}${r.stderr}`.split("\n").filter((l) => /✗ w\.yml:\d+ — (continue-on-error|«)/.test(l));
}

describe("guardia dei workflow — un controllo non ingoia il proprio fallimento", () => {
    it("scatta sul ripiego da npm ci a npm install", () => {
        expect(problemi("ripiego", "npm ci || npm install")).toHaveLength(1);
    });

    it("scatta su un build che continua lo stesso, per le due forme", () => {
        const trovati = problemi("build-echo", 'npm run build || echo "build non riuscita — continuo"');
        expect(trovati.some((l) => l.includes("«npm run build ||"))).toBe(true);
        expect(trovati.some((l) => l.includes("«|| echo»"))).toBe(true);
    });

    it("scatta su una prova che finisce in || true, anche spezzata su due righe", () => {
        expect(problemi("vero", "vendor/bin/phpunit \\\n    --testsuite=unit \\\n    || true")).toHaveLength(1);
    });

    it("scatta su continue-on-error vero o calcolato, non su false", () => {
        expect(problemi("coe", "composer cs", "continue-on-error: true")).toHaveLength(1);
        expect(problemi("coe-espr", "composer cs", "continue-on-error: ${{ github.event_name == 'schedule' }}")).toHaveLength(1);
        expect(problemi("coe-falso", "composer cs", "continue-on-error: false")).toHaveLength(0);
    });

    // 23/9/2026 — la chiave di un lavoro sta fuori da ogni passo, e la
    // regola guardava solo dentro i passi: `continue-on-error: true` su un
    // lavoro intero passava.
    it("scatta su continue-on-error di un lavoro intero, non su false", () => {
        const lavoro = (chiave) => `jobs:\n  a:\n    runs-on: ubuntu-latest\n    ${chiave}\n    steps:\n      - run: composer cs\n`;
        expect(problemiDi("coe-lavoro", lavoro("continue-on-error: true"))).toHaveLength(1);
        expect(problemiDi("coe-lavoro-dopo", "jobs:\n  a:\n    steps:\n      - run: composer cs\n    continue-on-error: true\n")).toHaveLength(1);
        expect(problemiDi("coe-lavoro-falso", lavoro("continue-on-error: false"))).toHaveLength(0);
    });

    it("scatta su || echo dopo un comando qualunque, in un passo che non è diagnostica", () => {
        expect(problemi("cp-echo", 'cp .env.example .env || echo "APP_URL=x" > .env')).toHaveLength(1);
    });

    it("tace su un fallimento che esce con un errore", () => {
        expect(problemi("esce", 'npm ci || { echo "::error::npm ci fallito"; exit 1; }')).toHaveLength(0);
        expect(problemi("esce-var", 'composer install || exit "$?"')).toHaveLength(0);
        expect(problemi("grep", 'grep -q "x" f || { echo "::error::manca"; exit 1; }')).toHaveLength(0);
    });

    it("tace su una cattura di valore e sulla diagnostica dei passi che girano solo da rossi", () => {
        expect(problemi("cattura", "C=$(curl -s -o /dev/null -w '%{http_code}' \"$U\" || echo 000)\n[ \"$C\" = 200 ] || exit 1")).toHaveLength(0);
        expect(problemi("diagnostica", 'mysql -e "SELECT 1" \\\n    || echo "  (non leggibile)"', "if: failure()")).toHaveLength(0);
        // Lo stesso `|| echo` in un passo qualunque scatta.
        expect(problemi("non-diagnostica", 'mysql -e "SELECT 1" \\\n    || echo "  (non leggibile)"', "if: ${{ !cancelled() }}")).toHaveLength(1);
    });

    it("un commento che le nomina non conta", () => {
        expect(problemi("commento", "# era: npm ci || npm install\nnpm ci")).toHaveLength(0);
    });

    it("i workflow del repository la rispettano", () => {
        const r = spawnSync(process.execPath, [GUARDIA], { encoding: "utf8" });
        expect(`${r.stdout}${r.stderr}`).toContain("nessun controllo che ingoia il proprio fallimento");
        expect(r.status).toBe(0);
    });
});
