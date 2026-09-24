// @vitest-environment node
import { describe, it, expect, beforeAll, afterAll } from "vitest";
import { spawnSync } from "node:child_process";
import { mkdtempSync, writeFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

/**
 * `tools/ci/check-env-example.mjs` e il `.env` versionato, nei due versi
 * (23/9/2026).
 *
 * Il difetto (revisione architetturale del 23/9, A-9): il `.env` versionato,
 * che il rilascio monta nel container di produzione, aveva tre chiavi che il
 * codice non legge (`LOG_LEVEL`, `LOG_RETENTION_DAYS`, `FM_VITALS_ENABLED`),
 * rimaste per settimane dopo che `.env.example` le aveva tolte. Sembravano
 * regolare qualcosa. Ora la guardia (`npm run env:check`, dentro
 * `npm run ci`) si ferma su una chiave del `.env` che nessuno legge.
 *
 * Si prova su file finti, passati con `--env-versionato`: scatta con una
 * chiave morta, anche con `export` davanti; tace con chiavi lette, con una
 * chiave morta commentata, e con un file vuoto. Senza il verso muto, una
 * guardia che fallisse sempre passerebbe il primo.
 */
const RADICE = join(dirname(fileURLToPath(import.meta.url)), "..", "..");
const GUARDIA = join(RADICE, "tools", "ci", "check-env-example.mjs");

let cartella;
beforeAll(() => { cartella = mkdtempSync(join(tmpdir(), "env-versionato-")); });
afterAll(() => { rmSync(cartella, { recursive: true, force: true }); });

/** Lancia la guardia su un `.env` finto; esito e uscite. */
function guardia(nome, testo) {
    const file = join(cartella, nome);
    writeFileSync(file, testo);
    const r = spawnSync(process.execPath, [GUARDIA, "--env-versionato", file], { encoding: "utf8" });
    return { esito: r.status, uscita: `${r.stdout}${r.stderr}` };
}

// Chiavi che il codice legge davvero (app/Config/app.php, security.php).
const LETTE = "APP_ENV=production\nAPP_DEBUG=false\nRATE_LIMIT_DISABLED=0\n";

describe("il .env versionato contiene solo chiavi che il codice legge", () => {
    it("scatta con una chiave che nessuno legge", () => {
        const { esito, uscita } = guardia("morta.env", `${LETTE}LOG_LEVEL=info\n`);
        expect(esito).toBe(1);
        expect(uscita).toContain("LOG_LEVEL");
        expect(uscita).toContain("che il codice non legge");
    });

    it("scatta anche con export davanti", () => {
        const { esito, uscita } = guardia("export.env", `${LETTE}export FM_VITALS_ENABLED=1\n`);
        expect(esito).toBe(1);
        expect(uscita).toContain("FM_VITALS_ENABLED");
    });

    it("tace con sole chiavi lette", () => {
        const { esito, uscita } = guardia("lette.env", LETTE);
        expect(esito, uscita).toBe(0);
        expect(uscita).toContain("il .env versionato ne contiene solo di lette");
    });

    it("tace con una chiave morta commentata: non la carica nessuno", () => {
        const { esito, uscita } = guardia("commentata.env", `${LETTE}# LOG_LEVEL=info\n`);
        expect(esito, uscita).toBe(0);
    });

    it("tace con un file vuoto", () => {
        const { esito, uscita } = guardia("vuoto.env", "");
        expect(esito, uscita).toBe(0);
    });

    it("si ferma se il file indicato non c'è, invece di non guardare niente", () => {
        const r = spawnSync(process.execPath, [GUARDIA, "--env-versionato", join(cartella, "non-esiste.env")], {
            encoding: "utf8",
        });
        expect(r.status).toBe(1);
    });
});
