import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";

/**
 * Due segnalazioni dell'utente sul pannello delle credenziali di classe
 * (21/9/2026).
 *
 * 1. «Entrando in /area-docente/profilo non si caricano le credenziali, devo
 *    sempre riaggiornare la pagina per vederle.» Un tentativo solo non basta:
 *    la prima richiesta della pagina può incontrare la verifica di sicurezza
 *    del WAF, o semplicemente non arrivare, e quello che restava era una riga
 *    rossa con il ricaricamento a mano come unica via d'uscita.
 * 2. «Quando si inseriscono le credenziali di classe ci dev'essere scritto
 *    anche username, che manca.» L'username stava solo nella tabella, che si
 *    rilegge dopo; il messaggio della creazione diceva la sola etichetta.
 */

const RIGA = {
    id: 19, label: "2_SCI_MAT_PANTA", access_username: "2b-panta",
    classe: "2", indirizzo: "SCI", active: 1, expires_at: "2027-08-31",
    use_count: 0, last_used_at: null,
};

/** Il pezzo di pagina che il modulo si aspetta di trovare. */
function paginaDelProfilo() {
    document.body.replaceChildren();
    const box = document.createElement("div");
    box.id = "fm-cred-list";
    box.textContent = "Caricamento…";
    const form = document.createElement("form");
    form.id = "fm-cred-form";
    for (const nome of ["username", "password", "aggiunta", "expires_at"]) {
        const i = document.createElement("input");
        i.name = nome;
        form.appendChild(i);
    }
    const feedback = document.createElement("div");
    feedback.id = "fm-cred-feedback";
    document.body.append(box, form, feedback);
    return { box, form, feedback };
}

let initTeacherCredentials;

beforeEach(async () => {
    ({ initTeacherCredentials } = await import("../../js/modules/features/teacher-credentials.js"));
});

afterEach(() => {
    vi.restoreAllMocks();
    delete globalThis.fetch;
});

describe("Credenziali di classe — l'elenco", () => {
    it("se la prima lettura non riesce riprova da sé, e la seconda volta le mostra", async () => {
        const { box } = paginaDelProfilo();
        let chiamate = 0;
        globalThis.fetch = vi.fn(async () => {
            chiamate++;
            if (chiamate === 1) {
                return { ok: false, status: 403, headers: { get: () => "application/json" },
                         clone: () => ({ json: async () => ({ code: "waf_challenge" }) }),
                         json: async () => ({ error: "security_check_required" }) };
            }
            return { ok: true, status: 200, redirected: false, url: "/api/teacher/credentials",
                     headers: { get: () => "application/json" },
                     json: async () => ({ ok: true, credentials: [RIGA] }) };
        });

        initTeacherCredentials({ getInstituteId: () => 106 });

        await vi.waitFor(() => {
            expect(box.querySelectorAll("tbody tr")).toHaveLength(1);
        }, { timeout: 5000 });
        expect(chiamate, "ha riprovato una volta").toBeGreaterThanOrEqual(2);
        expect(box.textContent).toContain("2b-panta");
    });

    it("se non riesce nemmeno la seconda volta lascia un pulsante, non un vicolo cieco", async () => {
        const { box } = paginaDelProfilo();
        globalThis.fetch = vi.fn(async () => ({
            ok: false, status: 500, headers: { get: () => "application/json" },
            clone: () => ({ json: async () => null }),
            json: async () => ({ error: "boom" }),
        }));

        initTeacherCredentials({ getInstituteId: () => 106 });

        await vi.waitFor(() => {
            expect(box.querySelector("button")).not.toBeNull();
        }, { timeout: 5000 });
        expect(box.querySelector("button").textContent).toBe("Riprova");
        expect(box.textContent).toContain("Non sono andate perse");
    });
});

describe("Credenziali di classe — l'username", () => {
    it("la tabella ha la sua colonna", async () => {
        const { box } = paginaDelProfilo();
        globalThis.fetch = vi.fn(async () => ({
            ok: true, status: 200, redirected: false, url: "/api/teacher/credentials",
            headers: { get: () => "application/json" },
            json: async () => ({ ok: true, credentials: [RIGA] }),
        }));

        initTeacherCredentials({ getInstituteId: () => 106 });

        await vi.waitFor(() => {
            expect(box.querySelectorAll("tbody tr")).toHaveLength(1);
        }, { timeout: 5000 });
        const intestazioni = [...box.querySelectorAll("th")].map((t) => t.textContent);
        expect(intestazioni).toContain("Username");
        expect(box.querySelector("tbody code").textContent).toBe("2b-panta");
    });
});
