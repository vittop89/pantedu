// @vitest-environment node
import { describe, it, expect } from "vitest";
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

/**
 * Ogni chiave di `window.FM` la scrive un file solo (revisione
 * architetturale del 23/9/2026, A-48).
 *
 * `window.FM` fa da registro globale dei servizi JS (135 chiavi al 23/9).
 * Ventinove erano scritte due volte: dal modulo, quando viene valutato, e di
 * nuovo da js/modules/bootstrap.js. Con due scritture non si sa quale conti,
 * e il giorno che divergono un modulo funziona o no secondo l'ordine di
 * caricamento. Qui si fissa che una chiave abbia un solo autore.
 *
 * Due eccezioni, dette: `pageActions` è di una pagina d'amministrazione alla
 * volta (ogni entry registra le sue azioni e sulla pagina ce n'è una sola), e
 * `user` è stato che riempie chi lo legge per primo, non un servizio.
 */
const RADICE = join(dirname(fileURLToPath(import.meta.url)), "..", "..");
const PIU_AUTORI_AMMESSI = new Set(["pageActions", "user"]);

/** Per ogni chiave, i file che la scrivono (`window.FM.chiave = …`). */
function autoriPerChiave(file) {
    const autori = new Map();
    for (const percorso of file) {
        const testo = readFileSync(join(RADICE, percorso), "utf8");
        for (const m of testo.matchAll(/(?<![\w.$])window\.FM\.([A-Za-z_$][\w$]*)\s*=(?!=)/g)) {
            if (!autori.has(m[1])) autori.set(m[1], new Set());
            autori.get(m[1]).add(percorso);
        }
    }
    return autori;
}

function doppie(autori) {
    return [...autori]
        .filter(([chiave, chi]) => chi.size > 1 && !PIU_AUTORI_AMMESSI.has(chiave))
        .map(([chiave, chi]) => `${chiave}: ${[...chi].sort().join(", ")}`);
}

describe("il registro window.FM", () => {
    const file = execFileSync("git", ["ls-files", "--cached", "--others", "--exclude-standard", "js"], {
        cwd: RADICE, encoding: "utf8",
    }).split("\n").filter((f) => f.endsWith(".js"));

    it("la misura guarda qualcosa: il registro ha più di cento chiavi", () => {
        expect(autoriPerChiave(file).size).toBeGreaterThan(100);
    });

    it("nessuna chiave ha due autori", () => {
        expect(doppie(autoriPerChiave(file))).toEqual([]);
    });

    it("la guardia scatta se bootstrap riscrive la chiave di un modulo", () => {
        const autori = autoriPerChiave(file);
        autori.get("ToastManager").add("js/modules/bootstrap.js");
        expect(doppie(autori)).toEqual([
            "ToastManager: js/modules/bootstrap.js, js/modules/ui/toast.js",
        ]);
    });
});
