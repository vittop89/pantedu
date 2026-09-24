import { describe, it, expect } from "vitest";
import { gruppiDelleClassi, NOTA_ANNI, soloPresenti, chiaveClasse } from "../../js/modules/features/catalogo-classi.js";

/**
 * Le classi del catalogo nel profilo. Dal 15/9/2026 (ADR-042) un anno
 * appartiene a un corso: il catalogo di prova è quello dell'istituto in
 * produzione dopo la migrazione 132, ridotto: scientifico con 1–3, 2A e 2B;
 * artistico con 1–2 e 2AA; architettura con 3 e 3AR.
 */
const CORSI = [
    { code: "SCI", label: "Scientifico" },
    { code: "ART", label: "Artistico" },
    { code: "AAA", label: "Architettura e ambiente" },
];
const CLASSI = [
    { code: "1", indirizzo: "SCI" }, { code: "2", indirizzo: "SCI" }, { code: "3", indirizzo: "SCI" },
    { code: "2A", indirizzo: "SCI" }, { code: "2B", indirizzo: "SCI" },
    { code: "2AA", indirizzo: "ART" }, { code: "1", indirizzo: "ART" }, { code: "2", indirizzo: "ART" },
    { code: "3AR", indirizzo: "AAA" }, { code: "3", indirizzo: "AAA" },
];

const titoli = (gruppi) => gruppi.map((g) => g.titolo);
const sigle = (g) => g.voci.map((v) => v.code);

describe("gruppiDelleClassi", () => {
    it("un gruppo per corso spuntato, con prima i suoi anni e poi le sue sezioni", () => {
        const gruppi = gruppiDelleClassi(CLASSI, CORSI, new Set(["SCI", "ART"]));
        expect(titoli(gruppi)).toEqual(["Scientifico (SCI)", "Artistico (ART)"]);
        expect(sigle(gruppi[0])).toEqual(["1", "2", "3", "2A", "2B"]);
        expect(sigle(gruppi[1])).toEqual(["1", "2", "2AA"]);
    });

    it("il caso del 15/9: sotto architettura solo i suoi anni, non la prima e la seconda", () => {
        const [aaa] = gruppiDelleClassi(CLASSI, CORSI, new Set(["AAA"]));
        expect(aaa.titolo).toBe("Architettura e ambiente (AAA)");
        expect(sigle(aaa)).toEqual(["3", "3AR"]);
        expect(aaa.voci.every((v) => v.indirizzo === "AAA")).toBe(true);
    });

    it("lascia fuori anni e sezioni dei corsi non spuntati", () => {
        expect(titoli(gruppiDelleClassi(CLASSI, CORSI, new Set(["SCI"])))).toEqual(["Scientifico (SCI)"]);
        expect(gruppiDelleClassi(CLASSI, CORSI, new Set())).toEqual([]);
    });

    it("un corso spuntato che la scuola non ha a catalogo va in fondo con la sua sigla", () => {
        const voci = [...CLASSI, { code: "1", indirizzo: "MUS" }];
        expect(titoli(gruppiDelleClassi(voci, CORSI, new Set(["SCI", "MUS"])))).toEqual(["Scientifico (SCI)", "MUS"]);
    });

    it("la nota degli anni sta nei corsi che ne hanno, e dice che valgono in quell'indirizzo", () => {
        const soloSezioniArt = CLASSI.filter((c) => c.indirizzo !== "ART" || c.code === "2AA");
        const [sci, art] = gruppiDelleClassi(soloSezioniArt, CORSI, new Set(["SCI", "ART"]));
        expect(sci.nota).toBe(NOTA_ANNI);
        expect(art.nota).toBeUndefined();
        expect(NOTA_ANNI).toContain("in questo indirizzo");
    });

    it("le sezioni senza corso (prima della migrazione 100) restano spuntabili, in un gruppo a parte", () => {
        const voci = [{ code: "3B", indirizzo: null }, ...CLASSI];
        expect(titoli(gruppiDelleClassi(voci, CORSI, new Set(["SCI"])))).toEqual(["Senza indirizzo", "Scientifico (SCI)"]);
    });
});

describe("chiaveClasse", () => {
    it("due anni con la stessa sigla in corsi diversi sono due classi", () => {
        expect(chiaveClasse({ code: "3", indirizzo: "SCI" })).not.toBe(chiaveClasse({ code: "3", indirizzo: "ART" }));
        expect(chiaveClasse({ code: "3", indirizzo: "SCI" })).toBe(chiaveClasse({ code: "3", indirizzo: "SCI" }));
        expect(chiaveClasse({ code: "3B" })).toBe(chiaveClasse({ code: "3B", indirizzo: null }));
    });
});

describe("soloPresenti", () => {
    it("toglie null, undefined e false, e lascia nodi e testi", () => {
        const p = document.createElement("p");
        expect(soloPresenti(p, null, undefined, false, "testo")).toEqual([p, "testo"]);
    });
    it("con replaceChildren non lascia la parola «null»", () => {
        const pannello = document.createElement("div");
        pannello.replaceChildren(...soloPresenti(document.createElement("p"), null));
        expect(pannello.textContent).not.toContain("null");
        const senzaFiltro = document.createElement("div");
        senzaFiltro.replaceChildren(document.createElement("p"), null);
        expect(senzaFiltro.textContent, "il difetto: replaceChildren scrive null come testo").toContain("null");
    });
});
