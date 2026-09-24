import { describe, it, expect, beforeEach } from "vitest";
import { fillSelect, ripristinaScelte } from "../../js/modules/features/public-sidebar-selectors.js";
import { classiDi, classiNelDom } from "../../js/modules/core/sidebar-cascade.js";

/**
 * I selettori della barra pubblica (visitatore senza login), 15/9/2026.
 *
 * Le classi aggiunte dal catalogo pubblico devono portare il loro indirizzo,
 * come quelle disegnate dal server: la cascata filtra le classi per indirizzo
 * leggendo `data-indirizzo`. Senza, «1A» di Musicale e «1A» di Scientifico non
 * si distinguono.
 */
describe("fillSelect", () => {
    beforeEach(() => {
        document.body.innerHTML = '<select id="sel-cls"><option value="" disabled selected>Scegli la classe:</option></select>';
    });

    it("scrive l'indirizzo della classe sull'opzione, e la cascata filtra per indirizzo", () => {
        fillSelect("sel-cls", [
            { code: "1A", label: "Classe I A", indirizzo: "MUS" },
            { code: "1A", label: "Classe I A", indirizzo: "SCI" },
            { code: "2A", label: "Classe 2A", indirizzo: "SCI" },
        ]);

        const sel = document.getElementById("sel-cls");
        expect(Array.from(sel.options).slice(1).map((o) => o.dataset.indirizzo)).toEqual(["MUS", "SCI", "SCI"]);
        expect(classiDi(classiNelDom(sel), "SCI").map((c) => c.code)).toEqual(["1A", "2A"]);
        expect(classiDi(classiNelDom(sel), "MUS").map((c) => c.code)).toEqual(["1A"]);
    });

    it("una voce senza indirizzo non inventa l'attributo", () => {
        fillSelect("sel-cls", [{ code: "MAT", label: "Matematica" }]);

        expect(document.getElementById("sel-cls").options[1].dataset.indirizzo).toBeUndefined();
    });
});

describe("ripristinaScelte", () => {
    const segnaposto = (testo) => `<option value="" disabled selected>${testo}</option>`;

    beforeEach(() => {
        sessionStorage.clear();
        document.body.innerHTML = `
            <select id="sel-iis">${segnaposto("Scegli l'indirizzo:")}</select>
            <select id="sel-cls">${segnaposto("Scegli la classe:")}</select>
            <select id="sel-mater">${segnaposto("Scegli la materia:")}</select>`;
        // Com'e' al ricaricamento: dom-manager.js scrive il valore ricordato quando
        // le opzioni non ci sono ancora, e il <select> resta senza scelta.
        for (const id of ["sel-iis", "sel-cls", "sel-mater"]) document.getElementById(id).value = "SCI";
        fillSelect("sel-iis", [{ code: "ART", label: "Artistico" }, { code: "SCI", label: "Scientifico" }]);
        fillSelect("sel-cls", [{ code: "1A", indirizzo: "SCI" }, { code: "2A", indirizzo: "SCI" }]);
        fillSelect("sel-mater", [{ code: "FIS" }, { code: "MAT" }]);
    });

    it("rimette le scelte della sessione, e avvisa nell'ordine indirizzo, classe, materia", () => {
        sessionStorage.setItem("selectedIIS", "SCI");
        sessionStorage.setItem("selectedCLS", "2A");
        sessionStorage.setItem("selectedMATER", "MAT");
        const avvisati = [];
        for (const id of ["sel-iis", "sel-cls", "sel-mater"]) {
            document.getElementById(id).addEventListener("change", () => avvisati.push(id));
        }

        ripristinaScelte();

        expect(document.getElementById("sel-iis").value).toBe("SCI");
        expect(document.getElementById("sel-cls").value).toBe("2A");
        expect(document.getElementById("sel-mater").value).toBe("MAT");
        expect(avvisati).toEqual(["sel-iis", "sel-cls", "sel-mater"]);
    });

    it("senza una scelta ricordata, o con una che non c'e' piu', resta il segnaposto e non la prima voce", () => {
        sessionStorage.setItem("selectedCLS", "5Z");

        ripristinaScelte();

        expect(document.getElementById("sel-iis").value).toBe("", "non «Artistico» scelto dal browser");
        expect(document.getElementById("sel-cls").value).toBe("");
        expect(document.getElementById("sel-mater").value).toBe("");
    });
});
