import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";

/**
 * Gli ultimi gestori in linea, diventati ascoltatori (23/9/2026, revisione
 * architetturale A-19, R-3 passo 5).
 *
 * Con la CSP rigorosa, che in produzione c'è, un `onclick="…"` non parte.
 * Erano rimasti:
 *   - «✕ Esci» della modifica della struttura dei modelli
 *     (TemplateViewController): `window.close()` e poi /admin/templates#risdoc;
 *     ora `data-fm-chiudi-scheda`, in js/modules/core/declarative.js;
 *   - `onclick="this.select()"` sui due codici della chiave di recupero
 *     (js/entries/teacher-dashboard.js); ora `data-fm-seleziona`;
 *   - `onclick="event.stopPropagation();"` sulle caselle delle tabelle
 *     dell'editor (js/modules/editor/table-manager.js); ora un ascoltatore
 *     messo da `_setupCheckboxPositionButtons`.
 *
 * Qui si guarda che i tre comportamenti ci siano senza nessun attributo
 * `on*=` nell'HTML. Sul codice di prima le caselle nascevano con
 * `onclick=`, e il clic risaliva alla cella (in happy-dom come nel browser con
 * la CSP rigorosa, dove il gestore in linea non parte): rossa.
 */

let declarative;
let TableManager;

beforeEach(async () => {
    document.body.replaceChildren();
    declarative = await import("../../js/modules/core/declarative.js");
    ({ TableManager } = await import("../../js/modules/editor/table-manager.js"));
});

afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
});

describe("data-fm-chiudi-scheda", () => {
    it("prova a chiudere la scheda e, se resta aperta, va al percorso", () => {
        vi.useFakeTimers();
        const b = document.createElement("button");
        b.setAttribute("data-fm-chiudi-scheda", "/admin/templates#risdoc");
        const chiudi = vi.fn();
        const vai = vi.fn();

        expect(declarative.chiudiSchedaOVai(b, { chiudi, vai })).toBe("/admin/templates#risdoc");
        expect(chiudi).toHaveBeenCalledOnce();
        expect(vai).not.toHaveBeenCalled();
        vi.advanceTimersByTime(80);
        expect(vai).toHaveBeenCalledWith("/admin/templates#risdoc");
    });

    it("se il browser rifiuta la chiusura va lo stesso; un indirizzo fuori dal sito no", () => {
        vi.useFakeTimers();
        const vai = vi.fn();
        const b = document.createElement("button");
        b.setAttribute("data-fm-chiudi-scheda", "/admin");
        declarative.chiudiSchedaOVai(b, { chiudi: () => { throw new Error("no"); }, vai });
        vi.advanceTimersByTime(80);
        expect(vai).toHaveBeenCalledWith("/admin");

        for (const fuori of ["//altro.example/x", "https://altro.example/", "javascript:alert(1)", ""]) {
            b.setAttribute("data-fm-chiudi-scheda", fuori);
            expect(declarative.chiudiSchedaOVai(b, { chiudi: vi.fn(), vai })).toBeNull();
        }
        vi.advanceTimersByTime(200);
        expect(vai).toHaveBeenCalledTimes(1);
    });

    it("il clic sul bottone arriva al comportamento (delegato sul documento)", () => {
        // Orologio finto: il cambio d'indirizzo dopo 80 ms non deve partire davvero.
        vi.useFakeTimers();
        const chiudi = vi.spyOn(window, "close").mockImplementation(() => {});
        document.body.innerHTML = '<span><button data-fm-chiudi-scheda="/admin">✕ <b>Esci</b></button></span>';
        document.querySelector("b").click();
        expect(chiudi).toHaveBeenCalledOnce();
    });
});

describe("data-fm-seleziona", () => {
    it("il clic su un codice della chiave di recupero ne seleziona tutto il testo", () => {
        document.body.innerHTML = '<textarea readonly data-fm-seleziona class="fm-rk-code">ABCDEF0123</textarea>';
        const t = document.querySelector("textarea");
        const seleziona = vi.spyOn(t, "select");
        t.click();
        expect(seleziona).toHaveBeenCalledOnce();
        expect(t.hasAttribute("onclick")).toBe(false);
    });
});

describe("le caselle delle tabelle dell'editor", () => {
    /** Una cella come quelle dell'editor, con chi ascolta i clic sopra. */
    function cella() {
        const tabella = document.createElement("table");
        tabella.innerHTML = "<tr><td>contenuto</td></tr>";
        document.body.appendChild(tabella);
        const td = tabella.querySelector("td");
        const clicAllaCella = vi.fn();
        td.addEventListener("click", clicAllaCella);
        return { td, clicAllaCella };
    }

    it("una casella aggiunta alla cella cambia stato ma il clic non risale", () => {
        const { td, clicAllaCella } = cella();
        TableManager.updateCheckboxState(td, true);

        const casella = td.querySelector(".fm-checkbox-rm");
        expect(casella).not.toBeNull();
        expect(casella.hasAttribute("onclick"), "nessun gestore in linea").toBe(false);
        casella.click();
        expect(casella.checked).toBe(true);
        expect(clicAllaCella).not.toHaveBeenCalled();

        // Il resto della cella continua ad arrivare alla cella.
        td.querySelector(".fm-cell-content").click();
        expect(clicAllaCella).toHaveBeenCalledOnce();
    });

    it("anche dopo la ricostruzione della cella (con e senza il contenitore)", () => {
        const { td, clicAllaCella } = cella();
        TableManager.updateCheckboxState(td, true);
        TableManager._rebuildCheckboxStructure(td);
        td.querySelector(".fm-checkbox-rm").click();
        expect(clicAllaCella).not.toHaveBeenCalled();

        const altra = cella();
        altra.td.innerHTML = '<input type="checkbox" class="checkbox fm-checkbox-rm"> testo';
        TableManager._rebuildCheckboxStructure(altra.td);
        const casella = altra.td.querySelector(".fm-checkbox-rm");
        expect(casella.hasAttribute("onclick")).toBe(false);
        casella.click();
        expect(altra.clicAllaCella).not.toHaveBeenCalled();
    });
});
