/**
 * La pagina /accesso-classe: dove uno studente senza account entra con la
 * credenziale del docente, e dove vede il proprio portachiavi.
 *
 * Responsabilità: aprire la pagina, entrare (o aggiungere una credenziale)
 * con una coppia username/password, leggere le credenziali elencate e gli
 * avvisi, uscire da una sola credenziale. Il modulo è gestito da
 * js/entries/auth-class-access.js, che dopo l'ingresso manda alla home:
 * l'attesa è sull'indirizzo, non a tempo. L'uscita da una credenziale è un
 * modulo servito dal server (POST /accesso-classe/esci, poi 303 qui): chi
 * chiama aspetta che la voce sparisca, che è l'esito visibile.
 *
 * Può importare: solo tipi Playwright. Non può importare: api, fixture, factory.
 */
import { expect, type Locator, type Page } from "@playwright/test";

export class AccessoClassePage {
    constructor(readonly page: Page) {}

    async vaiA(): Promise<void> {
        await this.page.goto("/accesso-classe");
        await expect(this.page.getByRole("heading", { level: 1 })).toContainText("Accesso per la classe");
    }

    /** Il portachiavi: c'è solo quando la sessione ha almeno una credenziale. */
    get portachiavi(): Locator {
        return this.page.locator(".fm-keychain");
    }

    /** Il modulo con cui si entra, o si aggiunge una credenziale a quelle presenti. */
    get modulo(): Locator {
        return this.page.locator("#fm-class-access-form");
    }

    /** «Entra» senza credenziali in sessione, «Aggiungi» con il portachiavi già aperto. */
    get pulsanteInvio(): Locator {
        return this.modulo.getByRole("button", { name: /^(Entra|Aggiungi)$/ });
    }

    /** L'errore del modulo: una credenziale rifiutata lo rende visibile. */
    get errore(): Locator {
        return this.page.locator("#fm-class-access-error");
    }

    /** Compila e invia il modulo; se l'ingresso riesce l'app manda alla home. */
    async entra(username: string, password: string): Promise<void> {
        await this.compila(username, password);
        await this.pulsanteInvio.click();
        await this.page.waitForURL((url) => url.pathname === "/");
    }

    /** Compila e invia il modulo aspettandosi un rifiuto: si resta qui, con l'errore in vista. */
    async tentaConCredenzialiSbagliate(username: string, password: string): Promise<void> {
        await this.compila(username, password);
        await this.pulsanteInvio.click();
        await expect(this.errore).toBeVisible();
    }

    /** Le etichette delle credenziali nel portachiavi, dalla più vecchia; vuoto se non c'è. */
    async credenziali(): Promise<string[]> {
        return this.page.locator(".fm-keychain__item .fm-keychain__label strong").allTextContents();
    }

    /** La voce del portachiavi con quell'etichetta. */
    voce(etichetta: string): Locator {
        return this.page.locator(".fm-keychain__item").filter({ hasText: etichetta });
    }

    /** Preme «Esci» sulla voce: il server toglie quella credenziale e rimanda qui. */
    async esciDa(etichetta: string): Promise<void> {
        await this.voce(etichetta).getByRole("button", { name: "Esci" }).click();
        await expect(this.voce(etichetta), `la credenziale «${etichetta}» esce dal portachiavi`).toHaveCount(0);
    }

    /** Gli avvisi sulle credenziali cadute dal portachiavi (spente, scadute, eliminate). */
    async avvisi(): Promise<string[]> {
        return this.page.locator(".fm-alert--warn p").allTextContents();
    }

    private async compila(username: string, password: string): Promise<void> {
        await this.modulo.getByLabel("Username della classe").fill(username);
        await this.modulo.getByLabel("Password", { exact: true }).fill(password);
    }
}
