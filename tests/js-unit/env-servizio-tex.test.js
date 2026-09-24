// @vitest-environment node
import { describe, it, expect, beforeAll, afterAll } from "vitest";
import { spawnSync } from "node:child_process";
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

/**
 * `tools/ci/check-env-servizio-tex.mjs` nei due versi (23/9/2026, revisione
 * architetturale 2026-09, A-43).
 *
 * Il `.env.example` del servizio TeX non elencava cinque variabili che il
 * servizio leggeva. Il controllo confronta le letture (Python di `app/`,
 * `${X}` nelle unità systemd) con il file: qui scatta su una copia finta con
 * una lettura non scritta, tace quando la stessa lettura è scritta (anche
 * commentata), si rifiuta di dire «va bene» se non trova nessuna lettura, e
 * sul servizio vero esce 0.
 */
const RADICE = join(dirname(fileURLToPath(import.meta.url)), "..", "..");
const CONTROLLO = join(RADICE, "tools", "ci", "check-env-servizio-tex.mjs");

let cartella;
beforeAll(() => { cartella = mkdtempSync(join(tmpdir(), "env-servizio-tex-")); });
afterAll(() => { rmSync(cartella, { recursive: true, force: true }); });

/** Un servizio finto: un modulo Python, un'unità e il suo `.env.example`. */
function servizio(nome, { python, unita = "", esempio }) {
    const dir = join(cartella, nome);
    mkdirSync(join(dir, "app"), { recursive: true });
    mkdirSync(join(dir, "systemd"), { recursive: true });
    writeFileSync(join(dir, "app", "modulo.py"), python);
    writeFileSync(join(dir, "systemd", "servizio.service"), unita);
    writeFileSync(join(dir, ".env.example"), esempio);
    return dir;
}

function lancia(dir) {
    const esito = spawnSync(process.execPath, [CONTROLLO], {
        env: { ...process.env, ...(dir ? { SERVIZIO_TEX_DIR: dir } : {}) },
        encoding: "utf8",
    });
    return { stato: esito.status, uscita: esito.stdout + esito.stderr };
}

describe("il .env.example del servizio TeX elenca quello che il servizio legge", () => {
    it("scatta su una variabile letta e non scritta", () => {
        const dir = servizio("manca", {
            python: 'import os\nA = os.environ.get("TEX_PROVA_A", "1")\nB = int(os.getenv("TEX_PROVA_B", "2"))\n',
            esempio: "TEX_PROVA_A=1\n",
        });
        const { stato, uscita } = lancia(dir);
        expect(stato).toBe(1);
        expect(uscita).toContain("TEX_PROVA_B");
        expect(uscita).not.toContain("TEX_PROVA_A  ←");
    });

    it("scatta anche su una variabile che legge solo l'unità systemd", () => {
        const dir = servizio("unita", {
            python: 'import os\nA = os.environ["TEX_PROVA_A"]\n',
            unita: "ExecStart=/bin/servizio --host ${TEX_PROVA_HOST}\n",
            esempio: "TEX_PROVA_A=1\n",
        });
        const { stato, uscita } = lancia(dir);
        expect(stato).toBe(1);
        expect(uscita).toContain("TEX_PROVA_HOST");
    });

    it("tace quando ogni lettura è scritta, anche commentata", () => {
        const dir = servizio("completo", {
            python: 'import os\nA = os.environ.get("TEX_PROVA_A", "1")\nB = int(os.getenv("TEX_PROVA_B", "2"))\n',
            unita: "ExecStart=/bin/servizio --host ${TEX_PROVA_HOST}\n",
            esempio: "TEX_PROVA_A=1\n# TEX_PROVA_B=2\nTEX_PROVA_HOST=\n",
        });
        const { stato, uscita } = lancia(dir);
        expect(stato).toBe(0);
        expect(uscita).toContain("OK: 3 chiavi");
    });

    it("non dice «va bene» se non trova nessuna lettura", () => {
        const dir = servizio("vuoto", { python: "print('niente')\n", esempio: "TEX_PROVA_A=1\n" });
        const { stato, uscita } = lancia(dir);
        expect(stato).toBe(1);
        expect(uscita).toContain("non guarda niente");
    });

    it("sul servizio vero esce 0", () => {
        const { stato, uscita } = lancia(null);
        expect(stato, uscita).toBe(0);
        expect(uscita).toMatch(/OK: \d+ chiavi lette dal servizio TeX/);
    });
});
