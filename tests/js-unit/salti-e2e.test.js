// @vitest-environment node
import { describe, it, expect, beforeAll, afterAll } from "vitest";
import { spawnSync } from "node:child_process";
import { mkdtempSync, writeFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import { prove, saltiNonDichiarati } from "../../tools/ci/salti-e2e.mjs";

/**
 * `tools/ci/salti-e2e.mjs` (23/9/2026, revisione architetturale A-29): il giro
 * end-to-end fallisce se una prova si è saltata senza essere dichiarata.
 *
 * Nei due versi, su rapporti finti nella forma del reporter JSON di
 * Playwright (file → describe → spec → test): scatta su un `test.skip`, su un
 * `test.fixme` e su una prova rimasta dietro una `describe.serial` caduta;
 * tace su un giro senza salti e su un salto dichiarato; e si ferma se il
 * rapporto non c'è o è vuoto.
 */
const RADICE = join(dirname(fileURLToPath(import.meta.url)), "..", "..");
const CONTROLLO = join(RADICE, "tools", "ci", "salti-e2e.mjs");

let cartella;
beforeAll(() => { cartella = mkdtempSync(join(tmpdir(), "salti-e2e-")); });
afterAll(() => { rmSync(cartella, { recursive: true, force: true }); });

/** Una prova nella forma del reporter JSON. */
const prova = (titolo, stato, annotazioni = [], riga = 10) => ({
    title: titolo, file: "area-docente/dove-vale.spec.js", line: riga,
    tests: [{ status: stato, expectedStatus: stato === "skipped" ? "skipped" : "passed", annotations: annotazioni, results: [{ status: stato === "expected" ? "passed" : stato }] }],
});

/** Un rapporto con un file e un describe. */
const rapporto = (...specs) => ({
    suites: [{
        title: "area-docente/dove-vale.spec.js", file: "area-docente/dove-vale.spec.js", specs: [],
        suites: [{ title: "Area docente — Dove vale", file: "area-docente/dove-vale.spec.js", specs, suites: [] }],
    }],
    stats: {},
});

/** Lancia il controllo su un file. */
function lancia(nome, contenuto) {
    const percorso = join(cartella, nome);
    if (contenuto !== undefined) writeFileSync(percorso, typeof contenuto === "string" ? contenuto : JSON.stringify(contenuto));
    const r = spawnSync(process.execPath, [CONTROLLO, percorso], { encoding: "utf8" });
    return { esito: r.status, testo: `${r.stdout}${r.stderr}` };
}

describe("controllo dei salti della suite end-to-end", () => {
    it("legge le prove con il titolo del describe e il file", () => {
        const elenco = prove(rapporto(prova("pubblica altrove", "expected")));
        expect(elenco).toEqual([{
            file: "area-docente/dove-vale.spec.js", riga: 10,
            titolo: "Area docente — Dove vale › pubblica altrove", stato: "expected", motivo: "",
        }]);
    });

    it("tace su un giro senza salti", () => {
        const { esito, testo } = lancia("verde.json", rapporto(prova("a", "expected"), prova("b", "expected")));
        expect(testo).toContain("2 prove, nessuna saltata senza dichiararlo");
        expect(esito).toBe(0);
    });

    it("scatta su un test.skip condizionato e ne stampa il motivo", () => {
        const r = rapporto(prova("a", "expected"), prova("pubblica altrove", "skipped", [{ type: "skip", description: "il docente di prova non ha spuntato un posto" }], 51));
        const { esito, testo } = lancia("salto.json", r);
        expect(testo).toContain("area-docente/dove-vale.spec.js:51 — Area docente — Dove vale › pubblica altrove");
        expect(testo).toContain("motivo: il docente di prova non ha spuntato un posto");
        expect(esito).toBe(1);
    });

    it("scatta su un fixme e su una prova rimasta dietro una serie caduta", () => {
        const r = rapporto(prova("da sistemare", "skipped", [{ type: "fixme" }]), prova("dopo la caduta", "skipped"));
        expect(saltiNonDichiarati(r, [])).toHaveLength(2);
        expect(lancia("fixme.json", r).esito).toBe(1);
    });

    it("tace su un salto dichiarato, e solo su quello", () => {
        const r = rapporto(prova("solo su Linux", "skipped", [{ type: "skip" }]), prova("altro", "skipped", [{ type: "skip" }]));
        const dichiarati = [{ file: "area-docente/dove-vale.spec.js", titolo: "Area docente — Dove vale › solo su Linux", motivo: "prova", dal: "2026-09-23" }];
        const restano = saltiNonDichiarati(r, dichiarati);
        expect(restano.map((p) => p.titolo)).toEqual(["Area docente — Dove vale › altro"]);
    });

    it("si ferma se il rapporto non c'è o non ha prove: un conteggio che non c'è non è zero", () => {
        const assente = lancia("assente.json");
        expect(assente.testo).toContain("non c'è");
        expect(assente.esito).toBe(1);
        const vuoto = lancia("vuoto.json", { suites: [], stats: {} });
        expect(vuoto.testo).toContain("non contiene nessuna prova");
        expect(vuoto.esito).toBe(1);
    });
});
