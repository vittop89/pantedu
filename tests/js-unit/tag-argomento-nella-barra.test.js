/**
 * Il riquadro dell'argomento davanti al titolo, nella barra laterale.
 *
 * Il `topic` non vuol dire la stessa cosa per tutti i tipi: per un esercizio è
 * il numero dell'argomento («3.0»), per una verifica è il titolo dell'esercizio
 * da cui nasce — la chiave con cui il sito ritrova «le verifiche di questo
 * argomento». Finché la verifica non viene rinominata, topic e titolo
 * coincidono, e la barra stampava la stessa frase due volte: il riquadro e poi
 * il titolo a capo. Misurato in produzione il 20/9/2026 sulle verifiche 80, 81,
 * 93, 99 e 106 (topic identico al titolo) contro gli esercizi 46-49 (topic
 * «1.0»…«4.0»).
 *
 * Il riquadro serviva anche da chiave d'ordinamento: si leggeva il suo testo.
 * Per questo la chiave sta ora in `data-topic`, che c'è sempre.
 */
import { describe, it, expect, beforeAll } from "vitest";

let itemLiHtml;
let sortBlockItems;

beforeAll(async () => {
    ({ itemLiHtml, sortBlockItems } = await import("../../js/modules/features/db-sidepage.js"));
});

/** Il markup di una voce, come lo disegna la barra. */
const voce = (r) => itemLiHtml("verifica", "SCI", "5", "MAT", r);

/** Il primo elemento del markup, come nodo. */
function nodo(html) {
    const t = document.createElement("template");
    t.innerHTML = html.trim();
    return t.content.firstElementChild;
}

describe("Barra laterale — il riquadro dell'argomento", () => {
    it("l'esercizio mostra il numero dell'argomento accanto al titolo", () => {
        const html = voce({ id: 48, topic: "3.0", title: "Continuità e grafico probabile e asintoti", content_type: "esercizio" });
        expect(html).toContain('<span class="fm-numarg"');
        expect(html).toContain(">3.0</span>");
        expect(html).toContain("Continuità e grafico probabile e asintoti");
    });

    it("la verifica non rinominata non ripete il titolo nel riquadro", () => {
        const html = voce({ id: 81, topic: "Derivate", title: "Derivate", content_type: "verifica" });
        const li = nodo(html);
        expect(li.querySelectorAll(".fm-numarg")).toHaveLength(0);
        // Quello che si legge sullo schermo: il titolo, una volta sola.
        expect(li.textContent.trim()).toBe("Derivate");
        // La chiave resta, invisibile: senza, l'ordinamento si perderebbe.
        expect(li.dataset.topic).toBe("Derivate");
    });

    it("la verifica rinominata dichiara da quale argomento viene", () => {
        const html = voce({ id: 99, topic: "Derivate", title: "Verifica di recupero", content_type: "verifica" });
        const li = nodo(html);
        expect(li.querySelector(".fm-numarg")?.textContent).toBe("Derivate");
        expect(li.querySelector(".fm-numarg")?.getAttribute("title")).toBe("Argomento: Derivate");
        expect(li.querySelector("a")?.textContent).toContain("Verifica di recupero");
    });


    // 20/9/2026 — richiesta dell'utente: «i link delle verifiche derivano
    // sempre dai corrispettivi esercizi, quindi devono presentare sempre lo
    // stesso tag». Il numero lo risolve il server (RigheDellaBarra) e lo manda
    // in `numero_esercizio`; qui si guarda che la barra lo preferisca al
    // titolo ripetuto, e che senza numero tutto resti com'era.
    it("la verifica porta il numero dell'esercizio da cui nasce", () => {
        const li = nodo(voce({
            id: 81, topic: "Derivate", title: "Derivate",
            content_type: "verifica", numero_esercizio: "4.0",
        }));
        expect(li.querySelector(".fm-numarg")?.textContent).toBe("4.0");
        expect(li.querySelector(".fm-numarg")?.getAttribute("title")).toBe("Dall'esercizio «Derivate»");
        // Il titolo si legge una volta sola, e la chiave dell'ordine non cambia.
        expect(li.querySelector("a")?.textContent.trim()).toBe("Derivate");
        expect(li.dataset.topic).toBe("Derivate");
    });

    it("anche la verifica rinominata mostra il numero, non il titolo dell'esercizio", () => {
        const li = nodo(voce({
            id: 99, topic: "Derivate", title: "Verifica di recupero",
            content_type: "verifica", numero_esercizio: "4.0",
        }));
        expect(li.querySelector(".fm-numarg")?.textContent).toBe("4.0");
        expect(li.querySelector("a")?.textContent).toContain("Verifica di recupero");
    });

    it("senza numero la verifica resta com'era", () => {
        // Il caso della 105 (due esercizi con lo stesso titolo) e della 99
        // (esercizio cancellato): il server non manda niente, e la barra
        // ricade sulla regola di prima.
        const uguale = nodo(voce({ id: 105, topic: "Sistemi lineari", title: "Sistemi lineari", content_type: "verifica" }));
        expect(uguale.querySelectorAll(".fm-numarg")).toHaveLength(0);
        const rinominata = nodo(voce({ id: 106, topic: "Sistemi lineari", title: "Recupero", content_type: "verifica" }));
        expect(rinominata.querySelector(".fm-numarg")?.textContent).toBe("Sistemi lineari");
    });

    it("senza argomento non c'è riquadro, e l'attributo resta vuoto", () => {
        const li = nodo(voce({ id: 734, topic: "", title: "Limiti", content_type: "mappa" }));
        expect(li.querySelectorAll(".fm-numarg")).toHaveLength(0);
        expect(li.dataset.topic).toBe("");
    });

    it("l'ordine regge anche quando il riquadro non si vede", () => {
        const ul = document.createElement("ul");
        ul.className = "fm-db-block";
        for (const r of [
            { id: 3, topic: "Limiti", title: "Limiti", content_type: "verifica" },
            { id: 1, topic: "Derivate", title: "Derivate", content_type: "verifica" },
            { id: 2, topic: "Integrali", title: "Integrali", content_type: "verifica" },
        ]) {
            ul.appendChild(nodo(voce(r)));
        }
        expect([...ul.querySelectorAll("li")].map((li) => li.dataset.topic))
            .toEqual(["Limiti", "Derivate", "Integrali"]);
        sortBlockItems(ul);
        expect([...ul.querySelectorAll("li")].map((li) => li.dataset.topic))
            .toEqual(["Derivate", "Integrali", "Limiti"]);
    });

    it("l'ordine numerico degli esercizi non cambia", () => {
        const ul = document.createElement("ul");
        ul.className = "fm-db-block";
        for (const r of [
            { id: 49, topic: "4.0", title: "Derivate", content_type: "esercizio" },
            { id: 46, topic: "1.0", title: "Studio di funzioni (intro)", content_type: "esercizio" },
            { id: 47, topic: "10.0", title: "Serie", content_type: "esercizio" },
        ]) {
            ul.appendChild(nodo(voce(r)));
        }
        sortBlockItems(ul);
        expect([...ul.querySelectorAll("li")].map((li) => li.dataset.topic))
            .toEqual(["1.0", "4.0", "10.0"]);
    });

    it("una voce disegnata dal server, senza attributo, si ordina ancora dal riquadro", () => {
        const ul = document.createElement("ul");
        ul.className = "fm-db-block";
        ul.innerHTML = `
            <li data-content-id="2"><span class="fm-numarg">2.0</span> <a href="#">Due</a></li>
            <li data-content-id="1"><span class="fm-numarg">1.0</span> <a href="#">Uno</a></li>
        `;
        sortBlockItems(ul);
        expect([...ul.querySelectorAll("li")].map((li) => li.querySelector(".fm-numarg").textContent))
            .toEqual(["1.0", "2.0"]);
    });
});
