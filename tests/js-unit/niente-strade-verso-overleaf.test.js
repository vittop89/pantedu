import { describe, it, expect } from "vitest";
import { readFileSync, readdirSync, statSync } from "node:fs";
import { join, dirname, relative } from "node:path";
import { fileURLToPath } from "node:url";

/**
 * Nessuna strada verso Overleaf nel codice servito.
 *
 * Il 21/9/2026 Overleaf è uscito dall'applicazione, per decisione dell'utente e
 * dopo una misura: non c'era un solo percorso vivo. C'era però un bottone che
 * mentiva — visibile, cliccabile, rispondeva «Apertura Overleaf attiva» e non
 * apriva niente — e un modulo nascosto verso overleaf.com iniettato in fondo a
 * ogni pagina, login compreso, che nessuno riempiva né spediva.
 *
 * Questa prova tiene chiusa la porta: se un domani un indirizzo di Overleaf
 * torna nel codice che arriva al browser, qui si vede. Non è una regola di
 * gusto: mandare lì il documento di un docente significa mandarlo a un'azienda
 * terza, e allora va dichiarato nell'informativa e nel registro dei
 * trattamenti — cioè è una decisione, non una riga di codice.
 */

const BASE = join(dirname(fileURLToPath(import.meta.url)), "..", "..");
const CARTELLE = ["js", "views", "app", "css/modules"];

function tuttiIFile(dir) {
    const fuori = [];
    for (const nome of readdirSync(dir)) {
        const p = join(dir, nome);
        if (statSync(p).isDirectory()) fuori.push(...tuttiIFile(p));
        else if (/\.(js|php|css|html)$/.test(nome)) fuori.push(p);
    }
    return fuori;
}

/** Un indirizzo di Overleaf, non la parola: i commenti che raccontano la storia restano. */
const INDIRIZZO = /overleaf\.com/i;

function stradeVersoOverleaf() {
    const trovate = [];
    for (const cartella of CARTELLE) {
        for (const file of tuttiIFile(join(BASE, cartella))) {
            const righe = readFileSync(file, "utf8").split("\n");
            righe.forEach((riga, i) => {
                if (!INDIRIZZO.test(riga)) return;
                // Un commento che spiega perché non c'è più non è una strada.
                const pulita = riga.trim();
                const eCommento = pulita.startsWith("//") || pulita.startsWith("*")
                    || pulita.startsWith("/*") || pulita.startsWith("#");
                if (eCommento) return;
                trovate.push(`${relative(BASE, file)}:${i + 1}`);
            });
        }
    }
    return trovate;
}

describe("Overleaf", () => {
    it("non ha più strade nel codice che arriva al browser", () => {
        const strade = stradeVersoOverleaf();

        expect(strade, `indirizzi di Overleaf rimasti:\n  ${strade.join("\n  ")}`).toEqual([]);
    });

    it("il controllo saprebbe accorgersene", () => {
        // Controprova dentro la prova: sulla stessa espressione, con e senza.
        // Senza questa riga, il giorno che la ricerca smettesse di funzionare
        // la prova sopra resterebbe verde senza aver guardato niente.
        expect(INDIRIZZO.test('window.open("https://www.overleaf.com/docs?snip_uri=" + u)')).toBe(true);
        expect(INDIRIZZO.test('window.open("https://esempio.invalid/docs")')).toBe(false);
    });

    it("la ricerca guarda davvero dei file", () => {
        // Se le cartelle cambiassero nome, `stradeVersoOverleaf` tornerebbe
        // vuota per il motivo sbagliato.
        let quanti = 0;
        for (const cartella of CARTELLE) quanti += tuttiIFile(join(BASE, cartella)).length;

        expect(quanti, "meno file del previsto: la ricerca sta guardando altrove").toBeGreaterThan(200);
    });
});
