import { describe, it, expect } from "vitest";
import { readFileSync, readdirSync, statSync } from "node:fs";
import { join, relative } from "node:path";

/**
 * Niente consensi finti e niente misure senza consenso (15/9/2026).
 *
 * Il banner dei cookie aveva l'interruttore «funzionali» già acceso alla prima
 * apertura, e con «Accetta tutti» registrava sul server consensi ad analytics e
 * marketing che non mostrava mai. Il consenso «funzionali» non bloccava davvero
 * diagrams.net, che misurato non imposta cookie né carica risorse da altri
 * domini: il banner è stato tolto. Le misure web-vitals partivano senza
 * consenso, finivano dentro il container e non le leggeva nessuno: tolte.
 *
 * Queste prove guardano il sorgente, perché il difetto era proprio che cosa il
 * codice mandava: nessun file del client registra un consenso da solo, nessuno
 * manda metriche di prestazione, e la pagina non ha caselle di consenso.
 */
const RADICE = join(__dirname, "..", "..");

function fileJs(cartella) {
    const out = [];
    for (const nome of readdirSync(cartella)) {
        const percorso = join(cartella, nome);
        if (statSync(percorso).isDirectory()) {
            if (nome === "vendor" || nome === "node_modules") continue;
            out.push(...fileJs(percorso));
        } else if (nome.endsWith(".js") && !nome.endsWith(".min.js")) {
            out.push(percorso);
        }
    }
    return out;
}

function cheContengono(schema) {
    return fileJs(join(RADICE, "js"))
        .filter((f) => schema.test(readFileSync(f, "utf8")))
        .map((f) => relative(RADICE, f));
}

describe("il client non raccoglie niente senza consenso", () => {
    it("nessun file registra da solo un consenso sul server", () => {
        expect(cheContengono(/\/me\/consents\/grant/)).toEqual([]);
    });

    it("nessun file manda metriche di prestazione", () => {
        expect(cheContengono(/perf\/web-vitals|["']web-vitals["']|\/api\/vitals/)).toEqual([]);
    });

    it("la pagina non ha caselle di consenso ai cookie", () => {
        const modali = readFileSync(join(RADICE, "views", "partials", "modals.php"), "utf8");
        expect(modali).not.toMatch(/data-cookie-type|fm-cookie-modal/);
    });
});
