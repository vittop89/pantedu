import { describe, it, expect, beforeEach } from "vitest";
import { disegnaAnteprima, chiediConferma } from "../../js/modules/features/avviso-incarichi.js";

/**
 * La finestra prima di togliere incarichi (15/9/2026).
 *
 * Il `window.confirm` di prima non diceva il docente né i materiali, e diceva a
 * tutti «gli studenti non vedranno più i suoi contenuti», vero solo per gli
 * studenti con account. Qui: il nome, i materiali per sezione, le conseguenze
 * vere per ogni caso, l'esito dell'email; si conferma e si annulla; se il
 * conteggio non arriva si può ancora scegliere; il testo del server resta testo.
 */
const DATI = {
    ok: true,
    docente: "Maria Rossi",
    modalita: "solo_incaricati",
    email: "si",
    sezioni: [
        { code: "2A", descrizione: "3 mappe · 1 esercizio (2 posti in più)", materiali: 4, credenziali: 1, studenti: 0 },
        { code: "2B", descrizione: "nessun materiale", materiali: 0, credenziali: 0, studenti: 0 },
    ],
};

function testo(nodo) {
    return nodo.textContent.replace(/\s+/g, " ");
}

describe("disegnaAnteprima", () => {
    let corpo;
    beforeEach(() => {
        document.body.innerHTML = "";
        corpo = document.createElement("div");
        document.body.append(corpo);
    });

    it("con materiali: il nome, le sezioni per tipo, che cosa resta e l'email", () => {
        const conti = disegnaAnteprima(corpo, DATI, ["2A", "2B"]);
        const t = testo(corpo);

        expect(conti).toEqual({ materiali: 4, credenziali: 1, studenti: 0 });
        expect(t).toContain("Maria Rossi perde queste classi dai suoi menù");
        const righe = [...corpo.querySelectorAll("tbody tr")].map((tr) => [...tr.cells].map((c) => c.textContent));
        expect(righe).toEqual([
            ["2A", "3 mappe · 1 esercizio (2 posti in più)", "1"],
            ["2B", "nessun materiale", "0"],
        ]);
        expect(t).toContain("I materiali non si cancellano e non si spostano");
        expect(t).toContain("da una sezione, se nessuno li sposta, qui sotto puoi portarli sull'anno");
        expect(t).toContain("continua a vedere i materiali");
        expect(t).toContain("Maria Rossi riceverà un'email");
        expect(t).not.toContain("studenti con account");
        expect(t).not.toContain("Gli studenti di quelle sezioni non vedranno più");
    });

    it("senza materiali, con «tutti» e con studenti con account: le conseguenze cambiano", () => {
        disegnaAnteprima(corpo, {
            ...DATI,
            modalita: "tutti",
            email: "senza_indirizzo",
            sezioni: [{ code: "3A", descrizione: "nessun materiale", materiali: 0, credenziali: 0, studenti: 2 }],
        }, ["3A"]);
        const t = testo(corpo);

        expect(corpo.querySelector(".fm-avviso-inc__attenzione")).toBeNull();
        expect(t).toContain("Su questa classe Maria Rossi non ha materiali pubblicati.");
        expect(t).toContain("2 studenti con account di questa classe non vedranno più i suoi contenuti.");
        expect(t).toContain("Nessuna email: Maria Rossi non ha un indirizzo valido.");
        expect(t).not.toContain("I materiali non si cancellano");
    });

    // ADR-043 (15/9/2026): anche gli anni hanno incarichi, e senza niente da fare non si scrive.
    it("un anno senza materiali: perde la classe, niente «porta sull'anno» e nessuna email", () => {
        disegnaAnteprima(corpo, {
            ...DATI,
            email: "niente_da_fare",
            sezioni: [{ code: "5", descrizione: "nessun materiale", materiali: 0, credenziali: 0, studenti: 0 }],
        }, ["5"]);
        const t = testo(corpo);

        expect(t).toContain("Maria Rossi perde questa classe dai suoi menù");
        expect(t).not.toContain("sezione");
        expect(t).toContain("Nessuna email: su questa classe Maria Rossi non ha materiali né credenziali");
        expect(t).not.toContain("riceverà un'email");
    });

    it("un anno con materiali: restano lì, ma non si parla di portarli sull'anno", () => {
        disegnaAnteprima(corpo, {
            ...DATI,
            sezioni: [{ code: "3", descrizione: "2 mappe", materiali: 2, credenziali: 0, studenti: 0 }],
        }, ["3"]);
        const t = testo(corpo);

        expect(t).toContain("li ritrova in «Sposta di classe» come «senza incarico».");
        expect(t).not.toContain("portarli sull'anno");
        expect(t).toContain("Maria Rossi riceverà un'email");
    });

    it("il testo che arriva dal server resta testo", () => {
        disegnaAnteprima(corpo, {
            ...DATI,
            docente: "<img src=x>",
            sezioni: [{ code: "2A", descrizione: "<b>3 mappe</b>", materiali: 3, credenziali: 0, studenti: 0 }],
        }, ["2A"]);

        expect(corpo.querySelector("img")).toBeNull();
        expect(corpo.querySelector("b")).toBeNull();
        expect(testo(corpo)).toContain("<b>3 mappe</b>");
    });
});

describe("chiediConferma", () => {
    beforeEach(() => { document.body.innerHTML = ""; });

    const attendi = () => new Promise((r) => setTimeout(r, 0));

    it("mostra il nome, abilita la conferma a conteggio arrivato e risolve true", async () => {
        const esito = chiediConferma({
            istituto: 1, docente: 7, nomeDocente: "M. Rossi", indirizzo: "SCI", classi: ["2A", "2B"],
            carica: async () => DATI,
        });
        const finestra = document.querySelector("dialog.fm-avviso-inc");
        const [annulla, conferma] = finestra.querySelectorAll(".fm-avviso-inc__piede button");
        expect(annulla.textContent).toBe("Annulla");
        expect(conferma.disabled).toBe(true);

        await attendi();
        expect(finestra.querySelector("h2").textContent).toBe("Togliere gli incarichi a Maria Rossi?");
        expect(conferma.disabled).toBe(false);
        conferma.click();

        expect(await esito).toBe(true);
        expect(document.querySelector("dialog.fm-avviso-inc")).toBeNull();
    });

    it("se il conteggio non arriva lo dice, e annullare risolve false", async () => {
        const esito = chiediConferma({
            istituto: 1, docente: 7, nomeDocente: "M. Rossi", indirizzo: "SCI", classi: ["2A"],
            carica: async () => { throw new Error("rete"); },
        });
        await attendi();
        const finestra = document.querySelector("dialog.fm-avviso-inc");
        expect(testo(finestra)).toContain("Non riesco a contare i materiali");
        const [annulla, conferma] = finestra.querySelectorAll(".fm-avviso-inc__piede button");
        expect(conferma.disabled).toBe(false);
        expect(conferma.textContent).toBe("Togli l'incarico");
        annulla.click();

        expect(await esito).toBe(false);
    });
});
