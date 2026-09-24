import { describe, it, expect } from "vitest";
import { pastiglieDellaRiga } from "../../js/modules/features/template-chi-lo-vede.js";

/**
 * Segnalazione dell'utente (21/9/2026), con schermata: «che significano 8
 * override, 8 drift, ok... visib tutti a 0 ecc...».
 *
 * In quella colonna finivano cinque pastiglie, tutte dello stesso azzurro e
 * tre delle quali dicevano zero: parole prese dal database («override»,
 * «drift») e numeri che non chiedono niente a nessuno. Qui si misura la regola
 * nuova: si mostra quello che c'è, si tace quello che non c'è, e si colora
 * solo ciò che chiede di essere guardato.
 */

/** Una riga del catalogo come la manda il server. */
function riga(extra = {}) {
    return {
        id: 3,
        visibility_scope: "public",
        visible_count: 0,
        collab_count: 0,
        override_count: 0,
        drift_count: 0,
        pending_count: 0,
        ...extra,
    };
}

const testi = (p) => p.map((x) => x.testo).join(" | ");

describe("Le pastiglie della riga di un modello", () => {
    it("un modello senza niente addosso mostra solo chi lo vede", () => {
        const p = pastiglieDellaRiga(riga());

        expect(p).toHaveLength(1);
        expect(p[0].testo).toBe("tutti i docenti");
    });

    it("gli zeri non si scrivono: non chiedono niente a nessuno", () => {
        const p = pastiglieDellaRiga(riga({ override_count: 2 }));

        expect(testi(p), "niente «0 visib», «0 collab»").not.toMatch(/\b0\b/);
        expect(p).toHaveLength(2);
    });

    it("i salvataggi dei docenti si contano, e da soli non allarmano", () => {
        const p = pastiglieDellaRiga(riga({ override_count: 8 }));
        const copie = p.find((x) => x.testo.includes("8"));

        expect(copie, "la pastiglia delle copie c'è").toBeTruthy();
        expect(copie.tono, "informazione, non avviso").toBe("neutro");
        expect(copie.titolo.length, "e spiega per esteso passandoci sopra").toBeGreaterThan(30);
    });

    it("si colora solo quello che chiede qualcosa a chi guarda", () => {
        // Regola decisa il 21/9/2026: l'unica pastiglia colorata è quella che
        // aspetta una risposta. Colorare anche i salvataggi non allineati —
        // che da questa pagina non si possono toccare — vuol dire un rapporto
        // che non è mai pulito, e che dopo un mese non si apre più.
        const p = pastiglieDellaRiga(riga({ override_count: 8, drift_count: 8, pending_count: 2 }));
        const avvisi = p.filter((x) => x.tono === "avviso");

        expect(avvisi.map((x) => x.testo)).toEqual(["🛡 2 da approvare"]);
        expect(testi(p), "gli altri numeri ci sono lo stesso").toMatch(/8/);
    });

    it("senza niente da approvare, niente è colorato", () => {
        const p = pastiglieDellaRiga(riga({ override_count: 8, drift_count: 8 }));

        expect(p.filter((x) => x.tono === "avviso")).toEqual([]);
    });

    it("nessuna parola presa dal database", () => {
        // È la ragione della segnalazione: «override» e «drift» non vogliono
        // dire niente per chi amministra una scuola.
        const p = pastiglieDellaRiga(riga({
            visible_count: 3, collab_count: 1, override_count: 8, drift_count: 8, pending_count: 2,
        }));
        const tutto = p.map((x) => `${x.testo} ${x.titolo}`).join(" ").toLowerCase();

        // Parola intera: «collaboratore» e «visibile» sono italiano e vanno
        // benissimo — a essere vietate sono le abbreviazioni della tabella
        // («collab», «visib») e i nomi inglesi delle colonne.
        for (const gergo of ["override", "drift", "visib", "collab", "scope", "pending", "hash"]) {
            expect(tutto, `parola del database in pagina: ${gergo}`).not.toMatch(new RegExp(`\\b${gergo}\\b`));
        }
    });

    it("ogni pastiglia ha il suo perché: nessuna resta senza spiegazione", () => {
        const p = pastiglieDellaRiga(riga({
            visible_count: 3, collab_count: 1, override_count: 8, drift_count: 8, pending_count: 2,
        }));

        for (const x of p) {
            expect(x.testo, "testo").toBeTruthy();
            expect(x.titolo, `titolo di «${x.testo}»`).toBeTruthy();
            expect(["neutro", "avviso"], `tono di «${x.testo}»`).toContain(x.tono);
        }
    });

    it("l'ambito è uno stato, non un allarme: non si colora mai", () => {
        const aperto = pastiglieDellaRiga(riga())[0];
        const chiuso = pastiglieDellaRiga(riga({ visibility_scope: "denied" }))[0];

        expect(aperto.tono).toBe("neutro");
        expect(chiuso.tono, "restringere un modello non è un guasto").toBe("neutro");
        expect(chiuso.testo).toBe("solo su invito");
    });

    it("le spunte 👁 si scrivono solo quando l'ambito lascia qualcuno fuori", () => {
        // Con «tutti i docenti» quelle spunte non aggiungono nessuno: scriverle
        // farebbe credere a un permesso che non esiste.
        const aperto = pastiglieDellaRiga(riga({ visible_count: 3 }));
        const ristretto = pastiglieDellaRiga(riga({ visible_count: 3, visibility_scope: "denied" }));

        expect(testi(aperto), "ambito aperto: niente spunte in pagina").not.toMatch(/👁/);
        expect(testi(ristretto), "ambito ristretto: si vedono").toMatch(/👁 3 in più/);
    });
});
