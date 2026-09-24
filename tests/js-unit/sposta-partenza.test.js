import { describe, it, expect, beforeEach } from "vitest";
import { collegaPartenza } from "../../js/modules/features/sposta-partenza.js";

/**
 * Cambiare la classe di partenza ricarica l'elenco (15/9/2026).
 *
 * Il 15/9 il selettore diceva 3A, ma l'elenco e il modulo erano ancora quelli
 * della «1»: lo spostamento è partito dalla «1». Qui: al cambio la pagina si
 * ricarica sulla classe scelta, l'elenco vecchio si chiude e non si invia; se la
 * scelta non cambia, non succede niente.
 */
describe("collegaPartenza", () => {
    let selettore, modulo, invia, elenco, andate;

    beforeEach(() => {
        document.body.innerHTML = `
            <form id="scelta"><select id="sposta-classe-da" name="classe">
                <option value="164" selected>Prima (1)</option><option value="170">3A · SCI</option>
            </select></form>
            <section id="sposta-elenco"><form id="sposta-form">
                <input type="hidden" name="classe_da" value="164">
                <button type="submit" id="sposta-invia">Sposta 55 elementi</button>
            </form></section>`;
        selettore = document.getElementById("sposta-classe-da");
        modulo = document.getElementById("sposta-form");
        invia = document.getElementById("sposta-invia");
        elenco = document.getElementById("sposta-elenco");
        andate = [];
        collegaPartenza(selettore, { modulo, invia, elenco, vai: (f) => andate.push(f.id) });
    });

    const cambia = (valore) => {
        selettore.value = valore;
        selettore.dispatchEvent(new Event("change", { bubbles: true }));
    };

    it("una classe diversa ricarica la pagina e chiude l'elenco vecchio", () => {
        cambia("170");

        expect(andate).toEqual(["scelta"]);
        expect(invia.disabled).toBe(true);
        expect(elenco.classList.contains("fm-sposta-elenco--vecchio")).toBe(true);
        expect(elenco.getAttribute("aria-busy")).toBe("true");

        const invio = new Event("submit", { cancelable: true });
        modulo.dispatchEvent(invio);
        expect(invio.defaultPrevented, "l'elenco della «1» non si invia più").toBe(true);
    });

    it("la stessa classe non ricarica niente e il modulo resta utilizzabile", () => {
        cambia("164");

        expect(andate).toEqual([]);
        expect(invia.disabled).toBe(false);
        const invio = new Event("submit", { cancelable: true });
        modulo.dispatchEvent(invio);
        expect(invio.defaultPrevented).toBe(false);
    });
});
