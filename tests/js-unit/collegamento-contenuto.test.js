import { describe, it, expect } from "vitest";
import { hrefContenutoRisdoc } from "../../js/modules/features/collegamento-contenuto.js";

/**
 * Il collegamento di un contenuto nelle sidepage BES/DSA e Risorse docente
 * (15/9/2026): una mappa o un esercizio creati lì aprivano la pagina dei
 * documenti BES/DSA, che rispondeva «Nessun documento per questa combinazione».
 */
const TERNA = { ind: "SCI", cls: "2", subj: "MAT", topic: "9.9" };

describe("hrefContenutoRisdoc", () => {
    it("una mappa, un esercizio, una verifica aprono la pagina del loro tipo, proprio loro", () => {
        for (const tipo of ["mappa", "esercizio", "verifica"]) {
            for (const origine of ["strcomp", "risdoc"]) {
                expect(hrefContenutoRisdoc({ id: 42, tipo, origine, ...TERNA }))
                    .toBe(`/studio/${tipo}/SCI/2/MAT/9.9?ids=42`);
            }
        }
    });

    it("un documento apre la pagina della sua sidepage, come prima", () => {
        expect(hrefContenutoRisdoc({ id: 42, tipo: "document", origine: "strcomp", ...TERNA })).toBe("/studio/bes/SCI/2/MAT/9.9");
        expect(hrefContenutoRisdoc({ id: 42, tipo: "document", origine: "risdoc", ...TERNA })).toBe("/studio/risdoc/SCI/2/MAT/9.9");
        // ADR-030 — terna_scoped: sempre questa riga.
        expect(hrefContenutoRisdoc({ id: 42, tipo: "document", origine: "risdoc", ternaScoped: true, ...TERNA }))
            .toBe("/studio/risdoc/SCI/2/MAT/9.9?ids=42");
    });

    it("un tipo sconosciuto o assente resta un documento, e l'argomento si codifica", () => {
        expect(hrefContenutoRisdoc({ id: 7, tipo: "", origine: "strcomp", ...TERNA })).toBe("/studio/bes/SCI/2/MAT/9.9");
        expect(hrefContenutoRisdoc({ id: 7, tipo: "lab", origine: "risdoc", ...TERNA })).toBe("/studio/risdoc/SCI/2/MAT/9.9");
        expect(hrefContenutoRisdoc({ id: 7, tipo: "mappa", origine: "risdoc", ...TERNA, topic: "Piano cartesiano" }))
            .toBe("/studio/mappa/SCI/2/MAT/Piano%20cartesiano?ids=7");
    });
});
