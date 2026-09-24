import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from "vitest";

/**
 * La sidepage dei visitatori senza login chiede per sezione (16/9/2026).
 *
 * Segnalato dall'utente: una mappa del Laboratorio compariva nella sidepage
 * Mappe della home senza login. Il visitatore non riceve la configurazione
 * delle sezioni (/api/sidebar/config vuole l'accesso), quindi il registro resta
 * quello di base, senza `allowedTypes`, e la sidepage chiedeva `type=mappa`:
 * tutte le mappe pubblicate, di qualunque sezione. Il server adesso limita anche
 * quella richiesta (tests/Integration/PubblicoPerSezioneTest.php); qui la parte
 * del browser.
 */
function pagina({ ospite }) {
    document.body.innerHTML = `
        <nav class="sidebar" ${ospite ? 'data-fm-guest="1"' : ""}>
            <select id="sel-iis"><option value="SCI" selected>Scientifico</option></select>
            <select id="sel-cls"><option value="3" selected>3</option></select>
            <select id="sel-mater"><option value="MAT" selected>Matematica</option></select>
            <div class="fm-sb-panel" id="fm-sp-mappe" data-sidepage="mappe"></div>
        </nav>`;
}

let chieste = [];
const finto = vi.fn(async (url) => {
    chieste.push(new URL(String(url), "http://localhost"));
    return new Response(JSON.stringify({ ok: true, rows: [] }), {
        status: 200, headers: { "Content-Type": "application/json" },
    });
});

// Il modulo parte già all'importazione: il fetch finto c'è prima, così nessuna
// richiesta esce davvero.
beforeAll(async () => {
    globalThis.fetch = finto;
    await import("../../js/modules/features/db-sidepage.js");
});

beforeEach(() => {
    chieste = [];
});

afterEach(() => {
    document.body.innerHTML = "";
});

const elenchi = () => chieste.filter((u) => u.pathname.endsWith("/study/content.json"));

describe("sidepage Mappe", () => {
    it("il visitatore senza login chiede per sezione, all'indirizzo pubblico", async () => {
        pagina({ ospite: true });
        await window.FM.loadDbSidepageContent("mappe", "mappa");
        expect(elenchi()).toHaveLength(1);
        const [u] = elenchi();
        expect(u.pathname).toBe("/api/public/study/content.json");
        expect(u.searchParams.get("section")).toBe("mappe");
        expect(u.searchParams.has("type")).toBe(false);
    });

    it("con l'accesso e senza configurazione resta com'era: per tipo, finché la configurazione non arriva", async () => {
        pagina({ ospite: false });
        await window.FM.loadDbSidepageContent("mappe", "mappa");
        const [u] = elenchi();
        expect(u.pathname).toBe("/api/study/content.json");
        expect(u.searchParams.get("type")).toBe("mappa");
    });
});
