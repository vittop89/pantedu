// @vitest-environment node
import { describe, it, expect } from "vitest";
import { auditReason, auditHeaders, perIntestazione } from "../../js/modules/core/audit-reason.js";

/**
 * La motivazione dev'essere SCRIVIBILE in un'intestazione HTTP.
 *
 * Trovato il 21/9/2026 da una prova end-to-end nuova sul pannello dei
 * permessi: il salvataggio non arrivava mai al server, e in pagina compariva
 * «Failed to read the 'headers' property from 'RequestInit': String contains
 * non ISO-8859-1 code point». La causa era dentro questo modulo — la lineetta
 * lunga «—» (U+2014) che univa il contesto al dettaglio. `fetch` rifiuta la
 * richiesta PRIMA di mandarla se un valore di intestazione esce da
 * ISO-8859-1.
 *
 * Quindi non riguardava solo il bottone nuovo: ogni mutazione che passa un
 * dettaglio (salva matrice, rinomina gruppo, crea modello, approva o rifiuta
 * una revisione con nota) partiva con la stessa intestazione impossibile e
 * moriva sul posto. Il caso senza dettaglio passava, ed è per questo che la
 * cosa è rimasta in piedi dal 2/9/2026.
 *
 * Qui si misura la regola vera, con l'oggetto `Headers` del motore, non con la
 * mia idea di come sia fatta.
 */

/**
 * Quello che il motore fa davvero quando gli dài un'intestazione.
 *
 * Questo file gira nell'ambiente `node` apposta: l'oggetto `Headers` di jsdom
 * NON applica la regola di ISO-8859-1 (misurato: accetta la lineetta lunga
 * senza fiatare), quindi la stessa prova sotto jsdom sarebbe stata verde con
 * il difetto dentro. Quello di Node è undici, e si comporta come Chromium —
 * dove il difetto è saltato fuori.
 */
function scrivibileInIntestazione(valore) {
    try {
        new Headers({ "X-Audit-Reason": valore });
        return true;
    } catch {
        return false;
    }
}

/** La regola, detta senza dipendere da nessuna implementazione. */
function fuoriDaIso88591(valore) {
    return [...String(valore)].filter((c) => c.codePointAt(0) > 0xFF);
}

const CASI_VERI = [
    ["Chi vede il modello #12", "Nessun docente (solo chi spunto qui sotto)"],
    ["Template #7 reso visibile", "docenti: 3, 9"],
    ["Rinomina gruppo template", 'da "modelli" a "Modelli"'],
    ["Rifiuto revisione #4", "formule sbagliate nell'esercizio 3"],
    ["Modifica scheda template #9", "0.0 Piano annuale [modelli]"],
    ["Operazione senza dettaglio", ""],
];

describe("La motivazione per il registro", () => {
    it("si scrive in un'intestazione, in tutti i casi veri del pannello", () => {
        for (const [contesto, dettaglio] of CASI_VERI) {
            const motivo = auditReason(contesto, dettaglio);
            expect(fuoriDaIso88591(motivo), `caratteri impossibili in «${motivo}»`).toEqual([]);
            expect(scrivibileInIntestazione(motivo), `rifiutata: «${motivo}»`).toBe(true);
        }
    });

    it("la prova saprebbe accorgersi del contrario", () => {
        // Controprova dentro la prova: il separatore di prima, con la lineetta
        // lunga, viene rifiutato. Senza questa riga, il giorno in cui `Headers`
        // smettesse di controllare, il caso sopra resterebbe verde senza aver
        // misurato niente.
        expect(scrivibileInIntestazione("Chi vede il modello #12 — Nessun docente")).toBe(false);
        expect(scrivibileInIntestazione("Modello 📋 aggiornato dal pannello")).toBe(false);
    });

    it("le lettere accentate italiane restano: stanno dentro ISO-8859-1", () => {
        const motivo = auditReason("Visibilità cambiata", "però l'Istituto è quello di città");

        expect(motivo).toContain("Visibilità");
        expect(motivo).toContain("però");
        expect(motivo).toContain("città");
        expect(scrivibileInIntestazione(motivo)).toBe(true);
    });

    it("la punteggiatura tipografica diventa quella semplice, non sparisce", () => {
        expect(perIntestazione("prima — dopo")).toBe("prima - dopo");
        expect(perIntestazione("l’esercizio")).toBe("l'esercizio");
        expect(perIntestazione("“virgolette”")).toBe('"virgolette"');
        expect(perIntestazione("eccetera…")).toBe("eccetera...");
    });

    it("quello che proprio non ci sta se ne va, e il resto resta leggibile", () => {
        const motivo = auditReason("Modello 📋 aggiornato", "sezione 🛡 rivista");

        expect(scrivibileInIntestazione(motivo)).toBe(true);
        expect(motivo).toContain("Modello");
        expect(motivo).toContain("aggiornato");
        expect(motivo).toContain("sezione");
        expect(motivo, "niente doppi spazi dove stava l'emoji").not.toMatch(/ {2,}/);
    });

    it("resta una motivazione valida per il middleware: almeno dieci caratteri", () => {
        // Un contesto fatto di sole emoji si svuota: non deve restare una
        // motivazione vuota o di due lettere, che il server rifiuterebbe.
        const motivo = auditReason("📋", "🛡");

        expect(motivo.length).toBeGreaterThanOrEqual(10);
        expect(scrivibileInIntestazione(motivo)).toBe(true);
    });

    it("anche le intestazioni pronte passano dalla stessa regola", () => {
        const h = auditHeaders("gettone", "Chi vede il modello #12", "Nessun docente");

        expect(() => new Headers(h)).not.toThrow();
    });
});
