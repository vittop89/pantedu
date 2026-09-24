import { describe, it, expect, beforeAll, afterAll, vi } from "vitest";

/**
 * Il pannello «Log di accesso» di /admin mostra il registro, con l'impronta
 * della sessione e non il suo id (23/9/2026).
 *
 * Dal 23/9/2026 `access_log.json` non conserva più `session_id()` in chiaro:
 * chi legge il registro poteva adottare la sessione di un docente (revisione
 * architetturale 2026-09, A-64). Al suo posto c'è `session_fingerprint`, che
 * raggruppa le richieste della stessa sessione. La tabella la mostra.
 *
 * Lo stesso giorno si è visto che la tabella non mostrava niente: leggeva
 * `logs` e `action_type`, mentre /admin/access-log manda `recent` e `action`.
 * Col registro pieno diceva «Nessun record.». La prova usa la forma vera della
 * risposta di AdminController::accessLog.
 */

const RISPOSTA = {
    ok: true,
    recent: [
        {
            timestamp: "2026-09-23 10:15:00", username: "zz_docente", role: "teacher",
            action: "access", ip_address: "192.0.2.10", linkref: "/studio/mappa",
            session_fingerprint: "0123456789abcdef",
        },
        {
            timestamp: "2026-09-23 10:14:00", username: "zz_docente", role: "teacher",
            action: "login", ip_address: "192.0.2.10", linkref: "/",
            session_fingerprint: "0123456789abcdef",
        },
    ],
};

function pannello() {
    document.body.innerHTML = `
        <input type="hidden" id="fm-tools-csrf" value="gettone-di-prova">
        <input type="number" id="fm-log-limit" value="50">
        <button id="fm-log-load">Carica</button>
        <div id="fm-log-result"></div>`;
}

const richieste = [];

describe("pannello del registro degli accessi", () => {
    beforeAll(async () => {
        vi.stubGlobal("fetch", async (url) => {
            richieste.push(String(url));
            const corpo = String(url).startsWith("/admin/access-log") ? RISPOSTA : { ok: true, notifications: [] };
            return new Response(JSON.stringify(corpo), { status: 200, headers: { "Content-Type": "application/json" } });
        });
        pannello();
        await import("../../js/modules/features/admin-tools.js");
    });

    afterAll(() => {
        vi.unstubAllGlobals();
    });

    it("mostra le voci che /admin/access-log manda, con l'azione", async () => {
        document.getElementById("fm-log-load").click();
        await vi.waitFor(() => {
            expect(document.querySelectorAll("#fm-log-result tbody tr")).toHaveLength(2);
        });
        expect(richieste).toContain("/admin/access-log?limit=50");
        const prima = document.querySelector("#fm-log-result tbody tr");
        expect(prima.textContent).toContain("zz_docente");
        expect(prima.textContent).toContain("access");
        expect(document.getElementById("fm-log-result").textContent).not.toContain("Nessun record");
    });

    it("mostra l'impronta della sessione, e nessuna colonna con l'id", () => {
        const intestazioni = [...document.querySelectorAll("#fm-log-result thead th")].map((th) => th.textContent);
        expect(intestazioni).toContain("Sessione");
        const celle = [...document.querySelectorAll("#fm-log-result tbody tr")]
            .map((tr) => [...tr.querySelectorAll("td")].map((td) => td.textContent.trim()));
        const colonna = intestazioni.indexOf("Sessione");
        expect(celle.map((riga) => riga[colonna])).toEqual(["0123456789abcdef", "0123456789abcdef"]);
    });

    it("col registro vuoto lo dice", async () => {
        RISPOSTA.recent = [];
        document.getElementById("fm-log-load").click();
        await vi.waitFor(() => {
            expect(document.getElementById("fm-log-result").textContent).toContain("Nessun record.");
        });
    });
});
