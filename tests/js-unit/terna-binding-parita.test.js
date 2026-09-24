/**
 * Parità fra `TernaBinding` JavaScript (`js/modules/risdoc/pt/terna-binding.js`,
 * per l'editor) e il suo specchio PHP (`TernaBinding.php`, per il render
 * server e la migrazione). Vedi il commento in testa alla prova gemella
 * `tests/Unit/Risdoc/Pt/TernaBindingParitaTest.php` per il perché.
 *
 * Questa prova e quella PHP non si parlano fra loro: leggono entrambe
 * `tests/fixtures/terna-binding/*.json` e ciascuna interroga il proprio
 * motore. Le due API non sono a specchio esatto — il PHP ha `applyAndStrip`
 * in un colpo solo, qui si compone `splitTernaStore` + `applyTernaValues` +
 * `stripTernaStore` — quello che deve coincidere è il RISULTATO.
 *
 * Normalizzazione: nei casi «estrai» dove nessun campo è stato estratto per
 * una chiave-terna, il delta atteso (scritto dal PHP) è un array vuoto `[]`
 * (PHP non distingue un array vuoto da un oggetto vuoto — vedi il commento
 * nella prova PHP gemella), mentre qui il motore produce `{}`. `normalizza`
 * converte ogni array vuoto incontrato in `{}` PRIMA del confronto, su
 * entrambi i lati: un array o un oggetto pieni restano confrontati per
 * contenuto come sempre, quindi una vera divergenza di valore continua a far
 * fallire la prova.
 */
import { describe, it, expect } from "vitest";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import {
    applyTernaValues, stripTernaStore, splitTernaStore, extractTernaValues,
} from "../../js/modules/risdoc/pt/terna-binding.js";

const __dirname = dirname(fileURLToPath(import.meta.url));
const CARTELLA_FIXTURE = join(__dirname, "../fixtures/terna-binding");

function normalizza(v) {
    if (Array.isArray(v)) {
        if (v.length === 0) return {};
        return v.map(normalizza);
    }
    if (v && typeof v === "object") {
        const out = {};
        for (const k of Object.keys(v)) out[k] = normalizza(v[k]);
        return out;
    }
    return v;
}

const applica = JSON.parse(readFileSync(join(CARTELLA_FIXTURE, "applica.json"), "utf8"));
const estrai = JSON.parse(readFileSync(join(CARTELLA_FIXTURE, "estrai.json"), "utf8"));

describe("terna-binding — applica e strippa", () => {
    for (const caso of applica.casi) {
        it(`${caso.nome} — il motore JS dà il risultato atteso`, () => {
            // Deep clone: le funzioni mutano i blocchi in-place.
            const blocks = JSON.parse(JSON.stringify(caso.blocchi));
            const { blocks: clean, store } = splitTernaStore(blocks);
            applyTernaValues(clean, store, caso.ternaKey);
            const risultato = stripTernaStore(clean);
            expect(normalizza(risultato)).toEqual(normalizza(caso.atteso));
        });
    }
});

describe("terna-binding — estrai", () => {
    for (const caso of estrai.casi) {
        it(`${caso.nome} — il motore JS dà il delta atteso`, () => {
            const blocks = JSON.parse(JSON.stringify(caso.blocchi));
            const store = extractTernaValues(blocks, caso.ternaKey, {});
            expect(normalizza(blocks)).toEqual(normalizza(caso.atteso.blocchi));
            expect(normalizza(store)).toEqual(normalizza(caso.atteso.store));
        });
    }
});
