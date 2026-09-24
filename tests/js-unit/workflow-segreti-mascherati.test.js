// @vitest-environment node
import { describe, it, expect, beforeAll, afterAll } from "vitest";
import { execFileSync } from "node:child_process";
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

/**
 * Regola 4 di tools/ci/check-workflows.mjs (15/9/2026): un valore generato che
 * entra in $GITHUB_ENV va mascherato, perché GitHub stampa l'ambiente in testa a
 * ogni passo. Il registro della E2E aveva in chiaro, diciotto volte a giro, le
 * password degli utenti di prova.
 *
 * Nei due versi, su workflow finti: scatta quando il valore finisce
 * nell'ambiente senza `::add-mask::`, tace quando è mascherato o quando il
 * segreto va solo in un file.
 */
const RADICE = join(dirname(fileURLToPath(import.meta.url)), "..", "..");
const GUARDIA = join(RADICE, "tools", "ci", "check-workflows.mjs");

let cartella;
beforeAll(() => { cartella = mkdtempSync(join(tmpdir(), "workflow-segreti-")); });
afterAll(() => { rmSync(cartella, { recursive: true, force: true }); });

/** Esegue la guardia su un workflow con un passo solo; dice se la regola 4 è scattata. */
function scatta(nome, run) {
    const dir = join(cartella, nome);
    mkdirSync(dir, { recursive: true });
    const corpo = run.split("\n").map((r) => `          ${r}`).join("\n");
    writeFileSync(join(dir, "w.yml"), `jobs:\n  a:\n    steps:\n      - name: ${nome}\n        run: |\n${corpo}\n`);
    try {
        execFileSync(process.execPath, [GUARDIA], { env: { ...process.env, WORKFLOWS_DIR: dir }, stdio: "pipe" });
        return false;
    } catch (e) {
        const uscita = String(e.stderr || "");
        return uscita.includes("senza essere mascherato");
    }
}

describe("guardia dei workflow — valori generati nell'ambiente del giro", () => {
    it("scatta con il valore generato direttamente dentro $GITHUB_ENV", () => {
        expect(scatta("diretto", 'echo "X=$(openssl rand -hex 8)" >> "$GITHUB_ENV"')).toBe(true);
    });

    it("scatta con una variabile generata e scritta senza maschera, anche in un gruppo", () => {
        expect(scatta("variabile", 'segreto="$(openssl rand -base64 24)"\necho "PASS=$segreto" >> "$GITHUB_ENV"')).toBe(true);
        expect(scatta("gruppo", 'p="$(openssl rand -base64 24)"\n{\n  echo "PASS=$p"\n} >> "$GITHUB_ENV"')).toBe(true);
    });

    it("tace se il valore è mascherato prima di entrare nell'ambiente", () => {
        expect(scatta("mascherato", 'p="$(openssl rand -base64 24)"\necho "::add-mask::$p"\n{\n  echo "PASS=$p"\n} >> "$GITHUB_ENV"')).toBe(false);
    });

    it("tace se il segreto va in un file e nell'ambiente va solo un percorso", () => {
        expect(scatta("file", 'echo "DATI=$RUNNER_TEMP/d" >> "$GITHUB_ENV"\nsed -i "s|^K=.*|K=$(openssl rand -hex 32)|" .env')).toBe(false);
    });

    it("i workflow del repository la rispettano", () => {
        expect(() => execFileSync(process.execPath, [GUARDIA], { stdio: "pipe" })).not.toThrow();
    });
});
