/**
 * Banco di prova dell'editor: una pagina qualsiasi con il modulo dell'editor
 * caricato e i suoi ganci di prova disponibili.
 *
 * Serve alle spec di `tests/e2e/editor/moduli/`, che non sono percorsi
 * dell'utente ma prove del modulo dentro al browser: costruiscono una sezione
 * con `window.FM.__buildSectionForTest`, la attaccano al documento, chiamano
 * una funzione dell'editor e guardano il risultato nel DOM o negli stili
 * calcolati. Il browser serve perché quelle funzioni lavorano su selezione,
 * `contenteditable` e `getComputedStyle`, cose che fuori da un browser non
 * esistono; l'applicazione intorno, invece, non c'entra.
 *
 * Prima ognuna di quelle spec compilava il modulo di login e apriva la pagina
 * da sé: venti righe uguali ripetute sedici volte, e sedici login veri. Qui il
 * banco si prepara una volta sola sulla sessione già aperta del docente.
 *
 * L'editor è a caricamento differito: i ganci esistono solo dopo
 * `window.FM.loadEditor()`, che è idempotente.
 *
 * Può importare: solo tipi Playwright. Non può importare: api, fixture, factory.
 */
import { expect, type Page } from "@playwright/test";

export class BancoEditor {
    constructor(readonly page: Page) {}

    /**
     * Apre la pagina e aspetta che i ganci dell'editor siano montati.
     *
     * `/?home=1` è la scelta storica di queste spec: è la pagina più leggera
     * che carichi il bundle dell'applicazione.
     */
    async apri(): Promise<void> {
        await this.page.goto("/?home=1");
        await this.page.waitForFunction(() => typeof window.FM?.["loadEditor"] === "function", null, { timeout: 30_000 });
        await this.page.evaluate(() => {
            const carica = window.FM?.["loadEditor"];
            if (typeof carica === "function") carica();
        });
        await expect
            .poll(async () => this.page.evaluate(() => typeof window.FM?.["__buildSectionForTest"] === "function"), {
                message: "i ganci di prova dell'editor non si sono montati",
                timeout: 30_000,
            })
            .toBe(true);
    }

    /**
     * Toglie dal documento le sezioni costruite da un test precedente.
     *
     * Le prove attaccano le sezioni a `document.body` e non le tolgono: dentro
     * un test solo non dà fastidio, fra un test e l'altro sì, perché i
     * selettori tornerebbero il vecchio campo.
     */
    async pulisci(): Promise<void> {
        await this.page.evaluate(() => {
            document.querySelectorAll("body > .fm-editor-section, body > .fm-editor-wrap").forEach((el) => el.remove());
        });
    }
}
