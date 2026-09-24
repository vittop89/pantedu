import { describe, it, expect, beforeEach } from "vitest";
import { riepilogo, anno, confermaSpostamento } from "../../js/modules/features/riepilogo-spostamento.js";

/**
 * Il riepilogo prima di «Sposta di classe» (15/9/2026).
 *
 * Il caso vero: dopo «1A → 1» la pagina è tornata sulla «1», e l'utente ha
 * spostato tutto nella «3» credendo di partire dalla 3A. La finestra deve dire
 * da dove a dove, e mettere in guardia quando cambia l'anno, quando si svuota la
 * classe, e quando la partenza è una sezione senza incarico; e tacere quando
 * non cambia niente per gli studenti.
 */
const titoli = (n, base = "Contenuto") => Array.from({ length: n }, (_, i) => `${base} ${i + 1}`);

describe("riepilogo", () => {
    it("il caso del 15/9: dalla «1» alla «3», tutto, con l'avviso del cambio di anno", () => {
        const r = riepilogo({
            partenza: { code: "1", indirizzo: null, sospesa: false },
            arrivo: { code: "3", indirizzo: null },
            contenuti: ["Numeri naturali e interi", "Numeri razionali", "Monomi e Polinomi", "Funzioni", "Equazioni"],
            verifiche: ["Verifica di prima"],
            totale: 6,
        });

        expect(r.titolo).toBe("Spostare 5 contenuti e 1 verifica da «1» a «3»?");
        expect(r.elenco).toBe("«Numeri naturali e interi», «Numeri razionali», «Monomi e Polinomi», «Funzioni» e altri 2");
        expect(r.avvisi).toHaveLength(2);
        expect(r.avvisi[0]).toContain("Cambi anno di corso, dalla «1» alla «3»");
        expect(r.avvisi[0]).toContain("gli studenti delle prime non li vedranno più");
        expect(r.avvisi[1]).toBe("Sposti tutto: «1» resterà senza materiali.");
    });

    it("dalla sezione senza incarico al suo anno: nessun cambio di anno, ma la sezione sparisce", () => {
        const r = riepilogo({
            partenza: { code: "3A", indirizzo: "SCI", sospesa: true },
            arrivo: { code: "3", indirizzo: null },
            contenuti: titoli(32),
            verifiche: [],
            totale: 40,
        });

        expect(r.titolo).toBe("Spostare 32 contenuti da «3A · SCI» a «3»?");
        expect(r.avvisi.join(" ")).not.toContain("Cambi anno");
        expect(r.avvisi).toEqual(["«3A · SCI» non è più una tua classe: finiti i materiali sparisce dall'elenco, e da qui non potrai riportarceli."]);
    });

    it("dall'anno a una sua sezione: le altre sezioni li perdono", () => {
        const r = riepilogo({
            partenza: { code: "2", indirizzo: null }, arrivo: { code: "2A", indirizzo: "SCI" },
            contenuti: ["Radicali"], verifiche: [], totale: 10,
        });
        expect(r.avvisi).toEqual(["Dall'anno alla sezione: i materiali passano dalla «2» alla sola «2A», e le altre seconde non li vedranno più."]);
    });

    it("fra due sezioni dello stesso anno, una parte dei materiali: nessun avviso", () => {
        const r = riepilogo({
            partenza: { code: "2A", indirizzo: "SCI" }, arrivo: { code: "2B", indirizzo: "SCI" },
            verifiche: ["Verifica A", "Verifica B"], contenuti: [], totale: 5,
        });
        expect(r.titolo).toBe("Spostare 2 verifiche da «2A · SCI» a «2B · SCI»?");
        expect(r.avvisi).toEqual([]);
    });

    it("l'anno di una classe", () => {
        expect([anno("3A"), anno("3"), anno("5ALSS"), anno("X"), anno("")]).toEqual(["3", "3", "5", null, null]);
    });
});

describe("confermaSpostamento", () => {
    beforeEach(() => { document.body.innerHTML = ""; });

    it("mostra titolo e avvisi come testo; «Sposta» risolve true, «Annulla» false", async () => {
        const dati = {
            partenza: { code: "1" }, arrivo: { code: "3" },
            contenuti: ["<b>Numeri</b>"], verifiche: [], totale: 1,
        };
        const sposta = confermaSpostamento(dati);
        let finestra = document.querySelector("dialog.fm-avviso-inc");
        expect(finestra.querySelector("h2").textContent).toBe("Spostare 1 contenuto da «1» a «3»?");
        expect(finestra.querySelectorAll(".fm-avviso-inc__attenzione")).toHaveLength(2);
        expect(finestra.querySelector("b")).toBeNull();
        finestra.querySelector("[data-fm-conferma]").click();
        expect(await sposta).toBe(true);
        expect(document.querySelector("dialog.fm-avviso-inc")).toBeNull();

        const annulla = confermaSpostamento(dati);
        finestra = document.querySelector("dialog.fm-avviso-inc");
        finestra.querySelector(".fm-btn--ghost").click();
        expect(await annulla).toBe(false);
    });
});
