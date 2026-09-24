import { describe, it, expect, beforeEach } from "vitest";
import { applicaCatena, classiDi, classiNelDom, riempi, urlCatalogo, valore } from "../../js/modules/core/sidebar-cascade.js";

/**
 * I selettori della sidebar dopo un cambio di istituto o di indirizzo, e la
 * catena fra i tre: indirizzo, poi classe, poi materia.
 *
 * Il caso che ha motivato tutto: istituto "Liceo Musicale" con indirizzo
 * "Scientifico" e classe "1A" ancora selezionati da prima. Una combinazione
 * che non esiste non da' errore — non trova niente, e sembra che i contenuti
 * siano spariti. E il suo contrario: una classe scelta dal codice al posto
 * dell'utente, prima ancora che abbia scelto un indirizzo.
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

    it("senza segnaposto, se la scelta precedente non c'e' piu' prende la prima", () => {
        // Il comportamento storico, per un selettore senza segnaposto.
        const v = riempi(sel, [{ code: "MUS", label: "Musicale" }], "SCI");
        expect(v).toBe("MUS");
    });

    it("con un segnaposto e piu' voci non sceglie al posto dell'utente", () => {
        const voci = [
            { code: "ART", label: "Artistico" },
            { code: "SCI", label: "Scientifico" },
        ];
        expect(riempi(sel, voci, "MUS", "Scegli l'indirizzo:")).toBe("");
        expect(sel.options[0].value).toBe("");
        expect(sel.options[0].disabled).toBe(true);
        expect(sel.options[0].selected).toBe(true);
        expect(sel.options[0].textContent).toBe("Scegli l'indirizzo:");
        expect(valore(sel)).toBe("");
    });

    it("con un segnaposto e una voce sola la prende: un indirizzo scelto c'e'", () => {
        expect(riempi(sel, [{ code: "MUS", label: "Musicale" }], "", "Scegli l'indirizzo:")).toBe("MUS");
        expect(sel.value).toBe("MUS");
    });

    it("con un segnaposto conserva comunque la scelta precedente", () => {
        const voci = [
            { code: "ART", label: "Artistico" },
            { code: "SCI", label: "Scientifico" },
        ];
        expect(riempi(sel, voci, "SCI", "Scegli l'indirizzo:")).toBe("SCI");
    });

    it("il segnaposto lo prende dall'id del selettore", () => {
        document.body.innerHTML = '<select id="sel-cls"></select>';
        const cls = document.getElementById("sel-cls");
        riempi(cls, [{ code: "1A" }, { code: "1B" }], "");
        expect(cls.options[0].textContent).toBe("Scegli la classe:");
        expect(valore(cls)).toBe("");
    });

    it("scrive il corso della classe sull'opzione, per filtrare senza rete", () => {
        riempi(sel, [{ code: "1A", label: "Prima A", indirizzo: "SCI" }, { code: "3", label: "Terza" }], "");
        const perCodice = Object.fromEntries([...sel.options].map((o) => [o.value, o.dataset.indirizzo || null]));
        expect(perCodice).toEqual({ "1A": "SCI", "3": null });
    });

    it("non ripete lo stesso codice due volte", () => {
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

describe("classiNelDom", () => {
    it("legge codice, etichetta e corso dalle opzioni, segnaposto escluso", () => {
        document.body.innerHTML = `
            <select id="sel-cls">
                <option value="" disabled selected>Scegli la classe:</option>
                <option value="1A" data-indirizzo="SCI"> Prima A </option>
                <option value="3" data-indirizzo="">Terza</option>
            </select>`;
        expect(classiNelDom(document.getElementById("sel-cls"))).toEqual([
            { code: "1A", label: "Prima A", indirizzo: "SCI" },
            { code: "3", label: "Terza", indirizzo: null },
        ]);
    });

    it("senza selettore restituisce un elenco vuoto", () => {
        expect(classiNelDom(null)).toEqual([]);
    });
});

describe("applicaCatena", () => {
    let ind;
    let cls;
    let mat;
    beforeEach(() => {
        document.body.innerHTML = `
            <select id="sel-iis">
                <option value="" disabled selected>Scegli l'indirizzo:</option>
                <option value="SCI">Scientifico</option>
                <option value="ART">Artistico</option>
            </select>
            <select id="sel-cls">
                <option value="" disabled selected>Scegli la classe:</option>
                <option value="1A">Prima A</option>
            </select>
            <select id="sel-mater">
                <option value="" disabled selected>Scegli la materia:</option>
                <option value="MAT">Matematica</option>
            </select>`;
        ind = document.getElementById("sel-iis");
        cls = document.getElementById("sel-cls");
        mat = document.getElementById("sel-mater");
    });

    it("senza indirizzo chiude classe e materia", () => {
        const c = applicaCatena(ind, cls, mat);
        expect(c).toEqual({ indirizzo: "", classe: "", materia: "", azzerati: [] });
        expect(cls.disabled).toBe(true);
        expect(mat.disabled).toBe(true);
    });

    it("con l'indirizzo apre la classe, e la materia resta chiusa finche' non c'e' la classe", () => {
        ind.value = "SCI";
        expect(applicaCatena(ind, cls, mat).classe).toBe("");
        expect(cls.disabled).toBe(false);
        expect(mat.disabled).toBe(true);

        cls.value = "1A";
        const c = applicaCatena(ind, cls, mat);
        expect(c.classe).toBe("1A");
        expect(mat.disabled).toBe(false);
    });

    it("se l'indirizzo sparisce, la classe e la materia scelte si azzerano: non resta una classe di un corso non scelto", () => {
        ind.value = "SCI";
        cls.value = "1A";
        mat.value = "MAT";
        applicaCatena(ind, cls, mat);
        ind.selectedIndex = 0; // di nuovo sul segnaposto
        const c = applicaCatena(ind, cls, mat);
        expect(c.azzerati).toEqual(["classe", "materia"]);
        expect(valore(cls)).toBe("");
        expect(valore(mat)).toBe("");
        expect(cls.disabled).toBe(true);
        expect(mat.disabled).toBe(true);
    });

    it("al primo disegno (azzera: false) chiude ma non tocca i valori rimessi da altri", () => {
        // dom-manager.updateSelectsFromState e fm-url-state.js scrivono nei
        // selettori senza passare da qui, uno alla volta: la classe puo'
        // arrivare prima dell'indirizzo, e la materia prima della classe.
        cls.value = "1A";
        mat.value = "MAT";
        const c = applicaCatena(ind, cls, mat, { azzera: false });
        expect(c.azzerati).toEqual([]);
        expect(valore(cls)).toBe("1A");
        expect(valore(mat)).toBe("MAT");
        expect(cls.disabled).toBe(true);
        expect(mat.disabled, "chiusa anche lei: la catena e' rotta a monte").toBe(true);

        ind.value = "SCI";
        applicaCatena(ind, cls, mat, { azzera: false });
        expect(cls.disabled).toBe(false);
        expect(mat.disabled).toBe(false);
    });

    it("il segnaposto non conta come scelta", () => {
        expect(valore(ind)).toBe("");
        ind.value = "ART";
        expect(valore(ind)).toBe("ART");
    });

    it("selettori assenti non fanno esplodere niente", () => {
        expect(applicaCatena(null, null, null)).toEqual({ indirizzo: "", classe: "", materia: "", azzerati: [] });
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

    it("il visitatore senza login legge il catalogo pubblico, non quello del docente", () => {
        // Il 15/9/2026: la barra pubblica chiedeva /api/teacher/curriculum (401),
        // e scegliere l'indirizzo cancellava le classi.
        expect(urlCatalogo(null, true)).toBe("/curriculum");
        expect(urlCatalogo(106, true)).toBe("/curriculum");
        expect(urlCatalogo(null, false)).toBe("/api/teacher/curriculum");
    });
});
