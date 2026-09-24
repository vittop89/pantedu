import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from "vitest";
import { eBozza, segnoBozzaHtml, CLASSE_BOZZA } from "../../js/modules/features/segno-bozza.js";

/**
 * Le bozze nella barra laterale del docente (16/9/2026, richiesta dell'utente):
 * la voce cambia stile e mostra un occhio barrato, il concetto di «non
 * visibile». Qui il loader delle sidepage Mappe, Laboratorio, Esercizi e
 * Verifiche (db-sidepage.js) e il rifacimento della voce dopo una modifica; la
 * sidepage BES/DSA e Risorse e il giro dal modale sono nella spec
 * tests/e2e/area-docente/bozze-in-sidebar.spec.js.
 */
let righe = [];
const finto = vi.fn(async () => new Response(JSON.stringify({ ok: true, rows: righe }), {
    status: 200, headers: { "Content-Type": "application/json" },
}));

// Il modulo parte già all'importazione: il fetch finto c'è prima.
beforeAll(async () => {
    globalThis.fetch = finto;
    await import("../../js/modules/features/db-sidepage.js");
});

beforeEach(() => {
    document.body.innerHTML = `
        <nav class="sidebar">
            <select id="sel-iis"><option value="SCI" selected>Scientifico</option></select>
            <select id="sel-cls"><option value="3" selected>3</option></select>
            <select id="sel-mater"><option value="MAT" selected>Matematica</option></select>
            <div class="fm-sb-panel" id="fm-sp-mappe" data-sidepage="mappe"></div>
        </nav>`;
    righe = [
        { id: 1, topic: "1.0", title: "Pubblicata", visibility: "published" },
        { id: 2, topic: "2.0", title: "In bozza", visibility: "draft" },
    ];
});

afterEach(() => {
    document.body.innerHTML = "";
});

const voce = (id) => document.querySelector(`#fm-sp-mappe li[data-content-id="${id}"]`);

describe("il segno delle bozze", () => {
    it("solo la bozza ha lo stile e l'occhio barrato, con il testo per i lettori di schermo", async () => {
        await window.FM.loadDbSidepageContent("mappe", "mappa");

        const bozza = voce(2);
        expect(bozza.classList.contains(CLASSE_BOZZA)).toBe(true);
        const segno = bozza.querySelector("a > .fm-item-bozza");
        expect(segno, "il segno sta nel collegamento").not.toBeNull();
        expect(segno.getAttribute("title")).toMatch(/non la vedono gli studenti/);
        expect(segno.querySelector("svg").getAttribute("aria-hidden")).toBe("true");
        expect(bozza.querySelector("a").textContent).toBe("Bozza, non visibile: In bozza");

        const pubblicata = voce(1);
        expect(pubblicata.classList.contains(CLASSE_BOZZA)).toBe(false);
        expect(pubblicata.querySelector(".fm-item-bozza")).toBeNull();
        expect(pubblicata.querySelector("a").textContent).toBe("Pubblicata");
    });

    it("dopo una modifica il segno segue la visibilità, nei due versi", async () => {
        await window.FM.loadDbSidepageContent("mappe", "mappa");
        const aggiorna = (id, visibility) => window.FM.dbSidepageUpdateItem({
            sidepageKey: "mappe", type: "mappa",
            row: { id, topic: `${id}.0`, title: id === 1 ? "Pubblicata" : "In bozza", visibility },
        });

        expect(aggiorna(1, "draft")).toBe(true);
        expect(voce(1).classList.contains(CLASSE_BOZZA)).toBe(true);
        expect(aggiorna(2, "published")).toBe(true);
        expect(voce(2).classList.contains(CLASSE_BOZZA)).toBe(false);
        expect(voce(2).querySelector(".fm-item-bozza")).toBeNull();
    });

    it("eBozza e il segno", () => {
        expect(eBozza({ visibility: "draft" })).toBe(true);
        for (const riga of [{ visibility: "published" }, { visibility: "archived" }, {}, null, undefined]) {
            expect(eBozza(riga)).toBe(false);
        }
        expect(segnoBozzaHtml()).toContain('class="fm-sr-only"');
    });
});
