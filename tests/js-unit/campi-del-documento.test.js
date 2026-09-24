import { describe, it, expect } from "vitest";
import { campiOfferti, CAMPI_DEL_DOCUMENTO } from "../../js/modules/risdoc/campi-del-documento.js";

/**
 * Segnalazione dell'utente (21/9/2026): «cliccando su "Campo" mi esce un popup
 * con il solo `sezione`, ma ci dovrebbero essere molti più campi — classe,
 * docente, disciplina, indirizzo…».
 *
 * L'elenco delle proposte era «le chiavi che per caso stanno nello stato del
 * documento adesso»: su un modello aperto senza contesto, una voce sola. E lo
 * stato porta anche impostazioni tecniche, che come campi non vogliono dire
 * niente — inserire `[field-includeHeader]` stampa il segnaposto.
 */

describe("I campi da proporre nel popup «Campo»", () => {
    it("ci sono sempre tutti, anche su un documento appena aperto", () => {
        const offerti = campiOfferti({}, []);

        for (const atteso of ["classe", "sezione", "indirizzo", "disciplina",
                              "nome_docente", "istituto", "sede", "anno_scolastico"]) {
            expect(offerti, `manca ${atteso}`).toContain(atteso);
        }
    });

    it("le impostazioni della pagina non sono campi da stampare", () => {
        const offerti = campiOfferti({
            classe: "3",
            includeHeader: false,
            includeHeaderHtml: true,
            pageOrientation: "landscape",
            styleOverrides: { titleText: "#123456" },
        }, []);

        expect(offerti).not.toContain("includeHeader");
        expect(offerti).not.toContain("includeHeaderHtml");
        expect(offerti).not.toContain("pageOrientation");
        expect(offerti).not.toContain("styleOverrides");
        expect(offerti).toContain("classe");
    });

    it("quello che il documento ha in più si propone lo stesso", () => {
        const offerti = campiOfferti({ plesso: "Sede centrale", numero_studenti: 24 }, []);

        expect(offerti).toContain("plesso");
        expect(offerti).toContain("numero_studenti");
    });

    it("i selettori dichiarati dalla sezione entrano nell'elenco", () => {
        expect(campiOfferti({}, ["sezione", "coordinatore"])).toContain("coordinatore");
    });

    it("nessun nome compare due volte, e l'ordine è sempre lo stesso", () => {
        const offerti = campiOfferti({ classe: "3", sezione: "B" }, ["classe", "disciplina"]);

        expect(new Set(offerti).size).toBe(offerti.length);
        expect(offerti.slice(0, CAMPI_DEL_DOCUMENTO.length)).toEqual(CAMPI_DEL_DOCUMENTO);
    });

    it("uno stato che non c'è non fa saltare niente", () => {
        expect(campiOfferti(undefined, undefined)).toEqual(CAMPI_DEL_DOCUMENTO);
        expect(campiOfferti(null, null)).toEqual(CAMPI_DEL_DOCUMENTO);
    });
});
