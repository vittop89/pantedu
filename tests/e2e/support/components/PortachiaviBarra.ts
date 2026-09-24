/**
 * Il banner della credenziale di classe nella barra laterale della home, per
 * chi non ha un account (views/partials/sidebar.php, blocco
 * `$_fmGrant !== null`). Lì il server disegna il portachiavi: una riga per
 * credenziale, con «Esci» sulla singola quando ce n'è più d'una; nella riga
 * dei comandi della barra c'è l'«Esci» da tutte.
 *
 * Il riquadro JavaScript `#fm-resource-auth` non c'entra: il server non lo
 * disegna all'ospite puro e `student-resource-auth.js` lo nasconde davanti a
 * qualunque banner di sessione, quindi non compare mai (voce del debito).
 *
 * Le uscite sono moduli serviti dal server: dalla singola si torna a
 * /accesso-classe con il resto del portachiavi, da tutte alla home senza
 * banner. Le attese stanno su quegli esiti.
 *
 * Può importare: solo tipi Playwright. Non può importare: api, fixture, factory.
 */
import { expect, type Locator, type Page } from "@playwright/test";

export class PortachiaviBarra {
    constructor(readonly page: Page) {}

    async vaiAllaHome(): Promise<void> {
        await this.page.goto("/");
    }

    /** L'elenco delle credenziali nel banner; assente senza credenziali. */
    get portachiavi(): Locator {
        return this.page.locator(".fm-session-keychain");
    }

    voce(etichetta: string): Locator {
        return this.portachiavi.locator("li").filter({ hasText: etichetta });
    }

    /** «Esci» sulla voce: il server toglie quella credenziale e manda a /accesso-classe. */
    async esciDa(etichetta: string): Promise<void> {
        await this.voce(etichetta).getByRole("button", { name: "Esci", exact: true }).click();
        await this.page.waitForURL((url) => url.pathname === "/accesso-classe");
    }

    /** L'«Esci» della riga dei comandi: svuota il portachiavi e torna alla home, senza banner. */
    async esciDaTutte(): Promise<void> {
        await this.page.locator(".sel-wrapper-actions").getByRole("button", { name: "Esci", exact: true }).click();
        await expect(this.portachiavi, "senza credenziali il banner non c'è").toHaveCount(0);
    }

    /**
     * La riga sotto il portachiavi: la scritta «Credenziale del docente,
     * nessun account» e, dove ci sono più docenti, «➕ Aggiungi».
     */
    get collegamenti(): Locator {
        return this.page.locator(".fm-session-banner .fm-session-links");
    }

    /** Le sezioni della barra, per `data-sidepage`, nell'ordine in cui compaiono. */
    async sezioni(): Promise<string[]> {
        return this.page.locator("#fm-sb-scroll button.fm-sb-sec[data-sidepage]").evaluateAll(
            (bottoni) => bottoni.map((b) => (b as HTMLElement).dataset["sidepage"] ?? "").filter((k) => k !== ""),
        );
    }

    /**
     * Apre la sezione e aspetta che il suo caricatore dichiari di averla
     * disegnata (`fm:db-sidepage-rendered` o `fm:risdoc-sidepage-rendered`).
     * Richiede i contatori degli eventi sul contesto (`installEventRecorder`,
     * prima di aprire la pagina).
     */
    async apriSezione(chiave: string): Promise<Locator> {
        const disegnate = (): Promise<number> => this.page.evaluate(() =>
            (window.__fmEvents?.["fm:db-sidepage-rendered"] ?? 0) + (window.__fmEvents?.["fm:risdoc-sidepage-rendered"] ?? 0));
        const prima = await disegnate();
        await this.page.locator(`#fm-sb-scroll button.fm-sb-sec[data-sidepage="${chiave}"]`).click();
        await this.page.waitForFunction(
            (p) => (window.__fmEvents?.["fm:db-sidepage-rendered"] ?? 0) + (window.__fmEvents?.["fm:risdoc-sidepage-rendered"] ?? 0) > p,
            prima,
            { timeout: 30_000 },
        );
        const pannello = this.page.locator(`#fm-sp-${chiave}`);
        await expect(pannello, `la sezione «${chiave}» si apre`).toBeVisible();
        return pannello;
    }
}
