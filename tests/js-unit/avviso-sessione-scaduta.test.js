import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";

/**
 * Segnalazione dell'utente (21/9/2026): «quando entro con un login e poi scade
 * la connessione esce un messaggio in piccolo di security_check; vorrei un
 * popup che avvisi della chiusura della connessione o scadenza dell'accesso».
 *
 * E, subito dopo: «se riprovo ad andare alla homepage mi rimanda alla pagina
 * del login e non riesco in nessun modo ad andare su pantedu.eu». Per questo
 * la finestra non si limita ad avvisare: porta con sé due collegamenti veri —
 * il login, che si ricorda dov'eri, e la pagina pubblica, che si apre senza
 * account. Sono `<a href>`, non pulsanti che chiamano codice: la finestra
 * compare proprio quando qualcosa nella pagina si è rotto.
 */

let avvisaSessioneScaduta;
let togliAvvisoSessione;

beforeEach(async () => {
    document.body.replaceChildren();
    ({ avvisaSessioneScaduta, togliAvvisoSessione } = await import("../../js/modules/ui/avviso-sessione.js"));
    togliAvvisoSessione();
});

afterEach(() => {
    togliAvvisoSessione();
    vi.restoreAllMocks();
});

/** La finestra, come sta nella pagina. */
const finestra = () => document.getElementById("fm-avviso-sessione");

describe("Avviso di accesso scaduto", () => {
    it("dice che cosa è successo, e non con un codice", () => {
        avvisaSessioneScaduta();

        const f = finestra();
        expect(f, "la finestra c'è").not.toBeNull();
        expect(f.getAttribute("role")).toBe("dialog");
        expect(f.getAttribute("aria-modal")).toBe("true");
        expect(f.textContent).toContain("Accesso scaduto");
        expect(f.textContent).not.toContain("security_check");
        expect(f.textContent).not.toContain("401");
    });

    it("offre il login che si ricorda la pagina, e la via d'uscita pubblica", () => {
        window.history.replaceState({}, "", "/studio/esercizio/SCI/2/MAT/3.0?ids=48");
        avvisaSessioneScaduta();

        const entra = finestra().querySelector(".fm-avviso-sessione__entra");
        expect(entra.tagName, "è un collegamento vero, non un pulsante").toBe("A");
        expect(entra.getAttribute("href"))
            .toBe("/login?redirect=" + encodeURIComponent("/studio/esercizio/SCI/2/MAT/3.0?ids=48"));

        const pubblica = finestra().querySelector(".fm-avviso-sessione__pubblica");
        expect(pubblica.tagName).toBe("A");
        expect(pubblica.getAttribute("href"), "la home si apre senza account").toBe("/");
    });

    it("la verifica di sicurezza ha il suo testo, diverso", () => {
        avvisaSessioneScaduta({ motivo: "sicurezza" });

        expect(finestra().textContent).toContain("Verifica di sicurezza scaduta");
        expect(finestra().textContent).not.toContain("Accesso scaduto");
    });

    it("chiamata più volte non apre due finestre", () => {
        avvisaSessioneScaduta();
        avvisaSessioneScaduta();
        avvisaSessioneScaduta({ motivo: "sicurezza" });

        expect(document.querySelectorAll(".fm-avviso-sessione")).toHaveLength(1);
        // Resta la prima: durante una sessione chiusa le richieste che
        // falliscono sono quasi sempre più d'una, e il testo non deve ballare.
        expect(finestra().textContent).toContain("Accesso scaduto");
    });

    it("«Resta qui» la chiude, e quello che c'è a schermo non si tocca", () => {
        const lavoro = document.createElement("div");
        lavoro.id = "lavoro";
        lavoro.textContent = "testo non salvato";
        document.body.prepend(lavoro);
        avvisaSessioneScaduta();

        finestra().querySelector(".fm-avviso-sessione__resta").click();

        expect(finestra()).toBeNull();
        expect(document.getElementById("lavoro").textContent).toBe("testo non salvato");
    });
});

describe("Chi apre l'avviso", () => {
    /** Una risposta come quella che arriva quando la sessione e' finita. */
    const rispostaDiLogin = () => ({
        ok: true,
        status: 200,
        redirected: true,
        url: "https://pantedu.eu/login?redirect=%2Fapi%2Fstudy%2Fcontent.json",
        headers: { get: () => "text/html; charset=utf-8" },
        text: async () => "<!doctype html><title>Login</title>",
    });

    it("una richiesta che finisce sul login apre la finestra, oltre a fallire", async () => {
        const { assertJson } = await import("../../js/modules/core/dom-utils.js");

        await expect(assertJson(rispostaDiLogin())).rejects.toMatchObject({ code: "session_expired" });
        // L'avviso arriva con un import dinamico: un giro di eventi e c'e'.
        await vi.waitFor(() => {
            expect(document.getElementById("fm-avviso-sessione")).not.toBeNull();
        });
        expect(document.getElementById("fm-avviso-sessione").textContent).toContain("Accesso scaduto");
    });
});
