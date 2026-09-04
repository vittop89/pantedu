import { describe, it, expect, beforeEach } from "vitest";
import { classiDi, riempi, urlCatalogo } from "../../js/modules/core/sidebar-cascade.js";

/**
 * I selettori della sidebar dopo un cambio di istituto o di indirizzo.
 *
 * Il caso che ha motivato tutto: istituto "Liceo Musicale" con indirizzo
 * "Scientifico" e classe "1A" ancora selezionati da prima. Una combinazione
 * che non esiste non da' errore — non trova niente, e sembra che i contenuti
 * siano spariti.
 */
describe("classiDi", () => {
    const classi = [
        { code: "1A", indirizzo: "SCI" },
        { code: "1B", indirizzo: "SCI" },
        { code: "1AA", indirizzo: "ART" },
        { code: "3", indirizzo: null },
    ];

    it("tiene solo le classi dell'indirizzo scelto", () => {
        expect(classiDi(classi, "ART").map((c) => c.code)).toEqual(["1AA", "3"]);
    });

    it("le classi senza indirizzo valgono per tutti", () => {
        // Sono le righe create prima della migration 100. Nasconderle
        // toglierebbe accesso a contenuti che esistono davvero.
        expect(classiDi(classi, "SCI").map((c) => c.code)).toContain("3");
    });

    it("senza indirizzo scelto non filtra niente", () => {
        expect(classiDi(classi, "")).toHaveLength(4);
    });
});

describe("riempi", () => {
    let sel;
    beforeEach(() => {
        document.body.innerHTML = '<select id="s"></select>';
        sel = document.getElementById("s");
    });

    it("mette le etichette e restituisce il valore scelto", () => {
        const v = riempi(sel, [{ code: "SCI", label: "Scientifico" }], "");
        expect(v).toBe("SCI");
        expect(sel.options[0].textContent).toBe("Scientifico");
    });

    it("conserva la scelta precedente se esiste ancora", () => {
        const voci = [
            { code: "ART", label: "Artistico" },
            { code: "SCI", label: "Scientifico" },
        ];
        expect(riempi(sel, voci, "SCI")).toBe("SCI");
    });

    it("se la scelta precedente non c'e' piu' prende la prima", () => {
        // Il caso del cambio scuola: "Scientifico" non esiste al musicale.
        // Lasciare il selettore vuoto sarebbe peggio — sembra rotto.
        const v = riempi(sel, [{ code: "MUS", label: "Musicale" }], "SCI");
        expect(v).toBe("MUS");
    });

    it("non ripete lo stesso codice due volte", () => {
        // Il catalogo ha righe di istituto e righe per-docente con lo stesso
        // codice: senza dedup si vedrebbe due volte la stessa materia.
        riempi(sel, [
            { code: "MAT", label: "Matematica" },
            { code: "MAT", label: "Matematica" },
        ], "");
        expect(sel.options).toHaveLength(1);
    });

    it("ordina per etichetta, non per codice", () => {
        riempi(sel, [
            { code: "SCI", label: "Scientifico" },
            { code: "AAA", label: "Zoologia" },
            { code: "ART", label: "Artistico" },
        ], "");
        expect([...sel.options].map((o) => o.textContent))
            .toEqual(["Artistico", "Scientifico", "Zoologia"]);
    });

    it("su un elenco vuoto non lascia opzioni fantasma", () => {
        riempi(sel, [{ code: "SCI", label: "Scientifico" }], "");
        expect(riempi(sel, [], "SCI")).toBe("");
        expect(sel.options).toHaveLength(0);
    });

    it("un selettore assente non fa esplodere niente", () => {
        expect(riempi(null, [{ code: "X", label: "X" }], "")).toBe("");
    });
});

describe("urlCatalogo", () => {
    it("senza istituto chiede il catalogo dell'istituto attivo in sessione", () => {
        // Il difetto era qui: senza id si usciva subito, e il selettore degli
        // istituti esiste solo per chi ne ha piu' d'uno collegato. Chi non ce
        // l'aveva restava senza catalogo, e cambiare indirizzo non filtrava
        // niente — in silenzio.
        expect(urlCatalogo(null)).toBe("/api/teacher/curriculum");
        expect(urlCatalogo(undefined)).toBe("/api/teacher/curriculum");
        expect(urlCatalogo("")).toBe("/api/teacher/curriculum");
    });

    it("con un istituto lo passa in query", () => {
        expect(urlCatalogo(106)).toBe("/api/teacher/curriculum?institute_id=106");
    });

    it("codifica il parametro invece di concatenarlo a mano", () => {
        expect(urlCatalogo("1 06&x=1")).toBe("/api/teacher/curriculum?institute_id=1%2006%26x%3D1");
    });
});
