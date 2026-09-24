// @vitest-environment node
import { describe, it, expect, beforeAll, afterAll } from "vitest";
import { spawnSync } from "node:child_process";
import { mkdtempSync, writeFileSync, readFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

/**
 * `tools/ci/cancello-semgrep.mjs`, i file che semgrep legge solo in parte
 * (23/9/2026, revisione architetturale A-61).
 *
 * Il cancello contava solo quanti erano, contro un tetto: un file tornato
 * leggibile e uno nuovo letto a metà si compensavano. Ora stampa sempre
 * l'elenco intero e lo confronta con `file_non_letti.elenco` del censimento.
 *
 * Nei due versi, su risultati e censimenti finti: la compensazione fa fallire
 * il giro anche sotto il tetto; un file dell'elenco che si legge per intero si
 * stampa e basta; un censimento senza elenco fa fallire; il tetto resta.
 */
const RADICE = join(dirname(fileURLToPath(import.meta.url)), "..", "..");
const CANCELLO = join(RADICE, "tools", "ci", "cancello-semgrep.mjs");

let cartella;
beforeAll(() => { cartella = mkdtempSync(join(tmpdir(), "cancello-semgrep-")); });
afterAll(() => { rmSync(cartella, { recursive: true, force: true }); });

/** Un risultato di semgrep con 1000 file esaminati e questi letti in parte. */
const risultato = (inParte) => ({
    results: [],
    paths: { scanned: Array.from({ length: 1000 }, (_, i) => `f${i}.php`) },
    errors: inParte.map((p) => ({ level: "warn", type: ["PartialParsing", []], path: p, message: "`readonly` was unexpected" })),
});

/** Un censimento senza riscontri, con il tetto e (se c'è) l'elenco. */
const censimento = (tetto, elenco) => ({
    aggiornato: "2026-09-23",
    perche_esiste: "prova",
    file_non_letti: { quanti: tetto, motivo: "prova", ...(elenco ? { elenco } : {}) },
    soffitti: {},
});

/** Lancia il cancello; esito e testo. */
function lancia(nome, inParte, cens, argomenti = []) {
    const r = join(cartella, `${nome}-risultato.json`);
    const c = join(cartella, `${nome}-censimento.json`);
    writeFileSync(r, JSON.stringify(risultato(inParte)));
    writeFileSync(c, JSON.stringify(cens));
    const esecuzione = spawnSync(process.execPath, [CANCELLO, r, ...argomenti], {
        env: { ...process.env, SEMGREP_CENSIMENTO: c },
        encoding: "utf8",
    });
    return { esito: esecuzione.status, testo: `${esecuzione.stdout}${esecuzione.stderr}`, censimento: c };
}

describe("cancello di semgrep — quali file sono letti in parte, non solo quanti", () => {
    it("tace quando i file letti in parte sono quelli dell'elenco, e li stampa tutti", () => {
        const { esito, testo } = lancia("uguali", ["app/A.php", "app/B.php"], censimento(3, ["app/A.php", "app/B.php"]));
        expect(testo).toContain("File che semgrep legge solo in parte: 2 (tetto 3).");
        expect(testo).toContain("  - app/A.php");
        expect(testo).toContain("  - app/B.php");
        expect(esito).toBe(0);
    });

    it("scatta quando uno sistemato e uno nuovo rotto si compensano, anche sotto il tetto", () => {
        const { esito, testo } = lancia("compensati", ["app/A.php", "app/C.php"], censimento(3, ["app/A.php", "app/B.php"]));
        expect(testo).toContain("1 file letti solo in parte che l'elenco del censimento non ha");
        expect(testo).toContain("      - app/C.php");
        expect(testo).toContain("app/B.php. Toglili");
        expect(esito).toBe(1);
    });

    it("un file dell'elenco che si legge per intero si stampa e non fa fallire", () => {
        const { esito, testo } = lancia("tornato", ["app/A.php"], censimento(3, ["app/A.php", "app/B.php"]));
        expect(testo).toContain("1 file dell'elenco adesso si leggono per intero, o non ci sono più: app/B.php");
        expect(esito).toBe(0);
    });

    it("scatta se il censimento non ha l'elenco", () => {
        const { esito, testo } = lancia("senza-elenco", ["app/A.php"], censimento(3));
        expect(testo).toContain("il censimento non ha l'elenco dei file letti in parte");
        expect(esito).toBe(1);
    });

    it("il tetto resta: oltre il tetto è rosso anche se l'elenco li ha tutti", () => {
        const tutti = ["app/A.php", "app/B.php", "app/C.php", "app/D.php"];
        const { esito, testo } = lancia("oltre", tutti, censimento(3, tutti));
        expect(testo).toContain("4 file semgrep non riesce a leggerli per intero, il tetto è 3");
        expect(esito).toBe(1);
    });

    it("--scrivi-censimento riscrive l'elenco e conserva tetto, motivo e il resto", () => {
        const { esito, censimento: percorso } = lancia("scrivi", ["app/Z.php", "app/A.php"], censimento(7, ["app/B.php"]), ["--scrivi-censimento"]);
        expect(esito).toBe(0);
        const scritto = JSON.parse(readFileSync(percorso, "utf8"));
        expect(scritto.file_non_letti).toEqual({ quanti: 7, motivo: "prova", elenco: ["app/A.php", "app/Z.php"] });
        expect(scritto.perche_esiste).toBe("prova");
    });
});
