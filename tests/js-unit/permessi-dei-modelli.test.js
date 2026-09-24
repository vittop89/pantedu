import { describe, it, expect } from "vitest";
import {
    chiLoVede, ambitoInBreve, righeDeiPermessi, riassuntoDeiPermessi, filtraDocenti,
} from "../../js/modules/features/template-chi-lo-vede.js";

/**
 * Segnalazione dell'utente (21/9/2026): «in /admin/templates posso gestire i
 * permessi, ma non vedo nessuna spunta su 👁 ✎ 🛡 per nessun docente — e come
 * docente.uno riesco a vedere i template anche se non dovrei; inoltre
 * fra i docenti c'è admin, che dovrebbe poter fare tutto a prescindere».
 *
 * Le spunte non mentivano: non erano loro a decidere. In `Permission::canView`
 * l'ordine è super-admin → collaboratore → spunta 👁 → **ambito del modello**,
 * e l'ambito vale `public` di default, cioè «tutti i docenti». Il pannello non
 * lo diceva da nessuna parte: mostrava una matrice vuota e lasciava credere
 * che decidesse lei.
 */

describe("Chi vede un modello, detto in parole", () => {
    it("con l'ambito pubblico lo vedono tutti, e la frase dice perché", () => {
        const frase = chiLoVede({ visibility_scope: "public" });

        // Le tre cose che chi legge deve portarsi via: l'effetto, la causa e
        // la mossa. Era proprio quello che mancava alla frase di prima
        // («le spunte non tolgono niente a nessuno»), vera e incomprensibile.
        expect(frase, "l'effetto").toMatch(/tutti i docenti/i);
        expect(frase, "la causa").toMatch(/ambito/i);
        expect(frase, "la mossa: che cosa fare per restringere").toMatch(/stringi/i);
    });

    it("un modello senza ambito è pubblico: è il default del database", () => {
        expect(chiLoVede({})).toMatch(/tutti i docenti/i);
        expect(chiLoVede({ visibility_scope: "boh" }), "un ambito che non conosciamo non inventa una frase")
            .toMatch(/tutti i docenti/i);
    });

    it("con l'ambito chiuso restano le spunte, i collaboratori e gli amministratori", () => {
        const frase = chiLoVede({ visibility_scope: "denied" });

        expect(frase).toContain("spunta");
        expect(frase).toContain("amministratori");
        expect(frase).not.toContain("Tutti i docenti");
    });

    it("con l'ambito per Istituto dice quale, col suo nome", () => {
        const frase = chiLoVede(
            { visibility_scope: "institute", scope_institute_id: 106 },
            [{ id: 106, name: "IIS Galilei" }],
        );

        expect(frase).toContain("IIS Galilei");
    });

    it("un Istituto che non c'è più non diventa una frase finta", () => {
        const frase = chiLoVede({ visibility_scope: "institute", scope_institute_id: 999 }, []);

        expect(frase).toContain("999");
        expect(frase).not.toContain("undefined");
    });

    it("indirizzo e classe dicono quale, e se manca lo dicono", () => {
        expect(chiLoVede({ visibility_scope: "indirizzo", scope_indirizzo: "SCI" })).toContain("SCI");
        expect(chiLoVede({ visibility_scope: "classe", scope_classe: "3" })).toContain("3");
        expect(chiLoVede({ visibility_scope: "indirizzo" })).toContain("non indicato");
    });
});

describe("La pastiglia nell'elenco dei modelli", () => {
    it("dice in due parole chi lo vede, e si accorge quando non lo vedono tutti", () => {
        // I due versi: aperto → «tutti i docenti», non chiuso;
        //              ristretto → chiuso.
        expect(ambitoInBreve({ visibility_scope: "public" })).toMatchObject({ testo: "tutti i docenti", chiuso: false });
        expect(ambitoInBreve({ visibility_scope: "denied" }).chiuso).toBe(true);
    });

    it("«chiuso» non si scrive «nessuno», perché sarebbe falso", () => {
        // Con l'ambito più stretto lo vedono comunque le spunte 👁, i
        // collaboratori ✎ e gli amministratori.
        const p = ambitoInBreve({ visibility_scope: "denied" });

        expect(p.testo).toBe("solo su invito");
        expect(p.testo).not.toMatch(/nessuno/i);
        expect(p.titolo).toMatch(/amministratori/);
    });

    it("un modello senza ambito è aperto: è il default del database", () => {
        expect(ambitoInBreve({})).toMatchObject({ testo: "tutti i docenti", chiuso: false });
        expect(ambitoInBreve({ visibility_scope: "boh" }), "un ambito sconosciuto non diventa una pastiglia finta")
            .toMatchObject({ testo: "tutti i docenti", chiuso: false });
    });

    it("gli ambiti stretti portano il loro dettaglio", () => {
        expect(ambitoInBreve({ visibility_scope: "indirizzo", scope_indirizzo: "SCI" }).testo).toBe("solo indirizzo SCI");
        expect(ambitoInBreve({ visibility_scope: "classe", scope_classe: "3" }).testo).toBe("solo classe 3");
        expect(ambitoInBreve({ visibility_scope: "institute" }).chiuso).toBe(true);
    });

    it("il titolo per esteso non lascia mai «undefined» in pagina", () => {
        for (const a of ["public", "denied", "institute", "indirizzo", "classe"]) {
            const p = ambitoInBreve({ visibility_scope: a });
            expect(p.titolo, a).toBeTruthy();
            expect(p.titolo, a).not.toContain("undefined");
            expect(p.testo, a).not.toContain("undefined");
        }
    });
});

describe("Le righe della matrice", () => {
    const DOCENTI = [
        // Un docente inventato: la ricerca non ha bisogno di un nome vero, e il
        // cognome dell'autore in minuscolo usciva nella copia pubblica (22/9/2026).
        { id: 7, username: "bianchi.l", first_name: "Luca", last_name: "Bianchi", is_super_admin: 0 },
        { id: 9, username: "admin", first_name: "", last_name: "", is_super_admin: 1 },
        { id: 12, username: "rossi.m", first_name: "Maria", last_name: "Rossi", is_super_admin: 0 },
    ];

    it("un amministratore non ha caselle: può tutto a prescindere", () => {
        const righe = righeDeiPermessi(DOCENTI, new Set(), new Map());

        expect(righe.find((r) => r.username === "admin").sempre).toBe(true);
        expect(righe.find((r) => r.username === "rossi.m").sempre).toBe(false);
    });

    it("spunte e collaborazioni finiscono nella riga giusta", () => {
        const righe = righeDeiPermessi(DOCENTI, new Set([7]), new Map([[12, true]]));

        const v = righe.find((r) => r.id === 7);
        const m = righe.find((r) => r.id === 12);
        expect(v.visibile).toBe(true);
        expect(v.collaboratore).toBe(false);
        expect(m.collaboratore).toBe(true);
        expect(m.revisione, "collaboratore con revisione").toBe(true);
    });

    it("un collaboratore senza revisione non risulta in revisione", () => {
        const righe = righeDeiPermessi(DOCENTI, new Set(), new Map([[12, false]]));

        expect(righe.find((r) => r.id === 12).collaboratore).toBe(true);
        expect(righe.find((r) => r.id === 12).revisione).toBe(false);
    });

    it("il riassunto conta quello che conta, e non conta gli amministratori fra le spunte", () => {
        const righe = righeDeiPermessi(DOCENTI, new Set([7, 9]), new Map([[12, true]]));

        const testo = riassuntoDeiPermessi(righe);
        expect(testo).toContain("3 docenti");
        expect(testo, "la spunta su admin non si conta: lui può tutto comunque").toContain("1 con la spunta");
        expect(testo).toContain("1 collaboratore");
        expect(testo).toContain("1 amministratore");
    });

    it("la ricerca trova per nome e per username, senza badare alle maiuscole", () => {
        const righe = righeDeiPermessi(DOCENTI, new Set(), new Map());

        expect(filtraDocenti(righe, "ROSSI").map((r) => r.id)).toEqual([12]);
        expect(filtraDocenti(righe, "bianchi").map((r) => r.id)).toEqual([7]);
        expect(filtraDocenti(righe, "  ").length, "una ricerca vuota non nasconde nessuno").toBe(3);
        expect(filtraDocenti(righe, "zzz")).toEqual([]);
    });
});
