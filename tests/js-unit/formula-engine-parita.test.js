/**
 * Parità fra il motore di formule JavaScript (`js/modules/risdoc/pt/formula-engine.js`,
 * per l'editor) e il suo specchio PHP (`FormulaEngine.php`, per il render
 * server e il PDF). Vedi il commento in testa alla prova gemella
 * `tests/Unit/Risdoc/Pt/FormulaEngineParitaTest.php` per il perché: ADR-031
 * dichiarava prove mai registrate (revisione architetturale del 23/9/2026,
 * A-20, voce 125 del registro del debito).
 *
 * Questa prova e quella PHP non si parlano fra loro: leggono entrambe
 * `tests/fixtures/formule/*.json` e ciascuna interroga il proprio motore. Se
 * uno dei due motori diverge dall'altro, la stessa lista di casi attesi fa
 * fallire una delle due (o entrambe, se il caso attesto stesso era sbagliato).
 *
 * Per aggiungere un caso: una voce nuova in uno dei file di
 * `tests/fixtures/formule/` (vedi ADR-031 § «Prove di parità»).
 */
import { describe, it, expect } from "vitest";
import { readdirSync, readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { computeTableValues } from "../../js/modules/risdoc/pt/formula-engine.js";

const __dirname = dirname(fileURLToPath(import.meta.url));
const CARTELLA_FIXTURE = join(__dirname, "../fixtures/formule");

const file = readdirSync(CARTELLA_FIXTURE).filter((f) => f.endsWith(".json")).sort();
if (file.length === 0) {
    throw new Error(`nessun file di casi in ${CARTELLA_FIXTURE}`);
}

for (const nomeFile of file) {
    const contenuto = JSON.parse(readFileSync(join(CARTELLA_FIXTURE, nomeFile), "utf8"));
    const baseNome = nomeFile.replace(/\.json$/, "");

    describe(`formule (${baseNome})`, () => {
        for (const caso of contenuto.casi) {
            it(`${caso.nome} — il motore JS dà il risultato atteso`, () => {
                const risultato = computeTableValues(caso.griglia, caso.opzioni || {});
                for (const [coordinate, celleAttese] of Object.entries(caso.atteso)) {
                    const [r, c] = coordinate.split(",").map(Number);
                    const ottenuta = (risultato[r] || [])[c];
                    expect(ottenuta, `${baseNome}::${caso.nome} — manca la cella (${r},${c})`).toBeTruthy();
                    for (const campo of ["display", "value", "error"]) {
                        if (!(campo in celleAttese)) continue;
                        const msg = `${baseNome}::${caso.nome} — campo «${campo}» della cella (${r},${c})`;
                        if (campo === "value" && typeof celleAttese[campo] === "number" && !Number.isInteger(celleAttese[campo])) {
                            expect(ottenuta[campo], msg).toBeCloseTo(celleAttese[campo], 9);
                        } else {
                            expect(ottenuta[campo], msg).toBe(celleAttese[campo]);
                        }
                    }
                }
            });
        }
    });
}
