#!/usr/bin/env node
/**
 * Nessuna prova end-to-end si salta senza essere dichiarata.
 *
 * ── Perché esiste (23 settembre 2026) ─────────────────────────────────────
 *
 * Una prova saltata è verde. La regola di `wiki/testing.md` — «nessun
 * `test.skip` condizionato ai dati: se una fixture manca è un errore» — la
 * rispettava la disciplina, e alla revisione architetturale del 23/9 cinque
 * prove si saltavano in base a ciò che trovavano nel database, fra cui quelle
 * dell'isolamento fra scuole di ADR-037 (A-29): un cambio della semina le
 * avrebbe spente senza un rosso. In più la CI passava `--reporter=line`, che
 * sostituisce i reporter della configurazione, quindi `results.json` non si
 * scriveva e i salti non li contava nessuno.
 *
 * Questo controllo legge il `results.json` del giro e fallisce se una prova è
 * finita saltata (`test.skip`, `test.fixme`, o le prove rimaste dietro una
 * caduta in una `describe.serial`) e non compare in `DICHIARATI`. Fallisce
 * anche se il file non c'è o non contiene prove: un conteggio che non c'è non
 * è zero.
 *
 * ── Come si dichiara un salto ─────────────────────────────────────────────
 *
 * Non si dichiara, di solito: il dato si semina (`tools/ci/seed_e2e_database.php`)
 * o la prova si toglie. Se un salto è davvero voluto — una piattaforma, uno
 * strumento che in CI non c'è — si aggiunge a `DICHIARATI` con il file, il
 * titolo, il motivo e la data, e lo si dice nella pull request. Le spec
 * `@tex`, `@pdflatex` e `@istanza` non passano da qui: `--grep-invert` le
 * toglie dal giro, e nel JSON non compaiono.
 *
 * ── Come si prova ─────────────────────────────────────────────────────────
 *
 * `tests/js-unit/salti-e2e.test.js`, su risultati finti nella forma del
 * reporter JSON di Playwright, nei due versi.
 */

import { readFileSync, existsSync } from "node:fs";
import { pathToFileURL } from "node:url";

/**
 * I salti ammessi. Vuoto al 23/9/2026.
 * @type {Array<{ file: string, titolo: string, motivo: string, dal: string }>}
 */
export const DICHIARATI = [];

/**
 * Tutte le prove del rapporto, con il loro esito finale.
 * @param {any} rapporto il JSON del reporter di Playwright
 * @returns {Array<{ file: string, riga: number, titolo: string, stato: string, motivo: string }>}
 */
export function prove(rapporto) {
    /** @type {Array<{ file: string, riga: number, titolo: string, stato: string, motivo: string }>} */
    const elenco = [];
    /** @param {any} suite @param {string[]} percorso */
    const visita = (suite, percorso) => {
        const titoli = suite.title && !suite.file?.endsWith(suite.title) ? [...percorso, suite.title] : percorso;
        for (const spec of suite.specs ?? []) {
            for (const t of spec.tests ?? []) {
                const salto = (t.annotations ?? []).find((a) => a.type === "skip" || a.type === "fixme");
                elenco.push({
                    file: spec.file ?? suite.file ?? "",
                    riga: spec.line ?? 0,
                    titolo: [...titoli, spec.title].join(" › "),
                    stato: t.status ?? "",
                    motivo: salto?.description ?? "",
                });
            }
        }
        for (const figlia of suite.suites ?? []) visita(figlia, titoli);
    };
    for (const s of rapporto.suites ?? []) visita(s, []);
    return elenco;
}

/**
 * Le prove saltate che non sono fra i salti dichiarati.
 * @param {any} rapporto
 * @param {typeof DICHIARATI} dichiarati
 */
export function saltiNonDichiarati(rapporto, dichiarati = DICHIARATI) {
    return prove(rapporto)
        .filter((p) => p.stato === "skipped")
        .filter((p) => !dichiarati.some((d) => d.file === p.file && d.titolo === p.titolo));
}

function principale() {
    const percorso = process.argv[2] ?? "tests/e2e-results/results.json";
    if (!existsSync(percorso)) {
        console.error(
            `[salti-e2e] ${percorso} non c'è: la suite non ha scritto il suo risultato ` +
                "(il reporter JSON è in playwright.config.js). Un conteggio che non c'è non è zero.",
        );
        process.exit(1);
    }
    const rapporto = JSON.parse(readFileSync(percorso, "utf8"));
    const tutte = prove(rapporto);
    if (tutte.length === 0) {
        console.error(`[salti-e2e] ${percorso} non contiene nessuna prova: il controllo non misurerebbe niente.`);
        process.exit(1);
    }
    const nonDichiarati = saltiNonDichiarati(rapporto);
    if (nonDichiarati.length > 0) {
        console.error(`[salti-e2e] ${nonDichiarati.length} prove saltate senza essere dichiarate:\n`);
        for (const p of nonDichiarati) {
            console.error(`  · ${p.file}:${p.riga} — ${p.titolo}`);
            console.error(`    motivo: ${p.motivo || "(nessuno: una describe.serial caduta prima, o un salto senza descrizione)"}\n`);
        }
        console.error(
            "Una prova saltata è verde senza aver guardato. Se manca un dato, lo si semina\n" +
                "(tools/ci/seed_e2e_database.php) e il salto diventa un expect (wiki/testing.md, «Regole»).\n" +
                "Un salto voluto si dichiara in DICHIARATI, dentro tools/ci/salti-e2e.mjs, con il motivo.",
        );
        process.exit(1);
    }
    const dichiarate = tutte.filter((p) => p.stato === "skipped").length;
    console.log(`[salti-e2e] ${tutte.length} prove, nessuna saltata senza dichiararlo (${dichiarate} salti dichiarati).`);
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? "").href) {
    principale();
}
