// @ts-check
/**
 * `/admin/monitoring` incorpora Grafana in un iframe: qui si misura quel che
 * di quell'incorporamento è misurabile.
 *
 * PERCHÉ ESISTE
 *   Il 20 settembre 2026 la regressione visiva ha smesso di fotografare il
 *   contenuto di quel riquadro. Non per pigrizia: quel contenuto non è
 *   confrontabile a pixel da nessuna parte. `/grafana/` non è una rotta
 *   dell'applicazione — `routes/web.php` non la nomina — è una `location`
 *   dell'nginx di produzione che fa `auth_request` verso
 *   `/auth/grafana-gate` e poi passa a Grafana su 127.0.0.1:3000. Senza
 *   quell'nginx, cioè qui e in CI, la richiesta arriva al router PHP, che non
 *   la conosce: dentro il riquadro c'era il «404 — Not Found»
 *   dell'applicazione, fotografato per dodici giorni in due immagini attese
 *   su tre. E dove Grafana gira davvero il riquadro mostra un cruscotto vivo,
 *   che cambia a ogni secondo: un'immagine attesa sarebbe sbagliata appena
 *   presa.
 *
 *   Nascondere un riquadro però non è correggerlo: quel che c'era dentro
 *   smette di essere protetto. Questa spec è la risposta a quella obiezione.
 *   Non fotografa: misura le tre cose dell'incorporamento che valgono
 *   davvero, e che fino a oggi non erano coperte da nessuna prova — né qui,
 *   né in `tests/Unit`, né in `tests/Integration` (cercato il 20 settembre
 *   2026: `frame-ancestors`, `X-Frame-Options` e `isSameOriginFrameRoute` non
 *   comparivano in nessun test del repository).
 *
 * COSA NON MISURA, E PERCHÉ
 *   Che dentro il riquadro ci sia Grafana. Non si può da qui: in CI Grafana
 *   non esiste, e l'assenza è voluta, non un guasto — pretendere il cruscotto
 *   renderebbe questa prova rossa per un pezzo d'infrastruttura che la CI non
 *   ha e non deve avere. Quel pezzo si verifica dove vive, sul VPS, ed è la
 *   prova che manca: `tools/webhook/_vps_dash_validate.sh` controlla i
 *   cruscotti dentro Grafana, nessuno controlla che il cancello
 *   `auth_request` lasci passare il super-admin e fermi gli altri.
 */
const { test, expect } = require("../support/test");

/** La pagina che contiene il riquadro. */
const PAGINA = "/admin/monitoring";

/** Quel che il riquadro chiede: lo serve nginx, non l'applicazione. */
const RIQUADRO = "/grafana/";

/**
 * I permessi che l'iframe concede a quel che ci gira dentro.
 * Grafana ha bisogno di script, stessa origin, form, popup e scaricamenti.
 */
const PERMESSI_ATTESI = [
    "allow-scripts",
    "allow-same-origin",
    "allow-forms",
    "allow-popups",
    "allow-downloads",
];

/**
 * Il permesso che non deve esserci. Con `allow-top-navigation` il contenuto
 * del riquadro potrebbe portare altrove la finestra che lo ospita: un
 * cruscotto compromesso si trascinerebbe dietro la sessione
 * dell'amministratore.
 */
const PERMESSO_VIETATO = "allow-top-navigation";

test.describe("Osservazione — il riquadro di Grafana", () => {
    test("la pagina incorpora Grafana una volta sola, e con le sue protezioni", async ({ adminPage }) => {
        await adminPage.goto(PAGINA);

        const riquadri = adminPage.locator(".fm-monitoring-frame iframe");
        // Uno solo: se ne comparisse un secondo, la riga che li nasconde nella
        // regressione visiva coprirebbe anche quello senza che nessuno lo
        // decida.
        await expect(riquadri, "c'è un riquadro, e uno solo").toHaveCount(1);

        const riquadro = riquadri.first();
        const sorgente = (await riquadro.getAttribute("src")) ?? "";
        expect(sorgente, "punta alla location di nginx, non a una rotta dell'app").toContain(RIQUADRO);

        const permessi = (await riquadro.getAttribute("sandbox")) ?? "";
        expect(permessi, "il riquadro è in sandbox").not.toBe("");
        for (const permesso of PERMESSI_ATTESI) {
            expect(permessi, `la sandbox concede ${permesso}`).toContain(permesso);
        }
        // La controprova del verso opposto: la sandbox è un elenco di permessi
        // concessi, quindi controllare solo quelli che ci sono non
        // distinguerebbe una sandbox stretta da una spalancata.
        expect(permessi, `la sandbox NON concede ${PERMESSO_VIETATO}`).not.toContain(PERMESSO_VIETATO);

        expect(
            await riquadro.getAttribute("referrerpolicy"),
            "non si manda l'indirizzo della pagina fuori dall'origin",
        ).toBe("same-origin");
        expect(
            await riquadro.getAttribute("title"),
            "il riquadro ha un nome, che è quel che legge un lettore di schermo",
        ).toBeTruthy();
    });

    test("la CSP lascia incorniciare /grafana/ dalla stessa origin", async ({ adminPage }) => {
        // `SecurityHeadersMiddleware::isSameOriginFrameRoute` fa un'eccezione
        // per questo prefisso, e Kernel applica gli header a **ogni** risposta:
        // vale anche adesso, che senza nginx la risposta è un 404. È
        // l'eccezione che rende possibile il riquadro; senza, il frame
        // resterebbe vuoto e nessuno saprebbe perché (è già successo il 5
        // settembre 2026 con l'editor dei modelli).
        const risposta = await adminPage.request.get(RIQUADRO);
        const intestazioni = risposta.headers();

        expect(
            intestazioni["content-security-policy"] ?? "",
            "frame-ancestors 'self' su /grafana/",
        ).toContain("frame-ancestors 'self'");
        expect(intestazioni["x-frame-options"] ?? "", "e X-Frame-Options coerente").toBe("SAMEORIGIN");
    });

    test("e lo nega alla pagina che lo contiene", async ({ adminPage }) => {
        // Il verso opposto, che è quello che dice se l'eccezione è
        // un'eccezione o la regola. Una `isSameOriginFrameRoute` che
        // rispondesse sempre «sì» passerebbe la prova qui sopra e aprirebbe
        // ogni pagina dell'amministrazione al clickjacking.
        const risposta = await adminPage.request.get(PAGINA);
        const intestazioni = risposta.headers();

        expect(
            intestazioni["content-security-policy"] ?? "",
            "frame-ancestors 'none' sulla pagina di osservazione",
        ).toContain("frame-ancestors 'none'");
        expect(intestazioni["x-frame-options"] ?? "", "e X-Frame-Options coerente").toBe("DENY");
    });
});
