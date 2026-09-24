/**
 * Un gruppo di quesiti nella pagina studio (`.fm-groupcollex`).
 *
 * Struttura resa da app/Services/ContractRenderer.php (righe 157-172, 419-427,
 * 486-500): il gruppo è un contenitore con una barra `.fm-collapsible` che
 * porta `button.fm-collapse-toggle[aria-expanded]` (il nome accessibile è il
 * titolo del gruppo) e i controlli del gruppo, più un `.content` con i quesiti.
 * Ogni quesito ha i suoi bottoni con `role="button"` e `aria-label`.
 *
 * Nota di comportamento verificata il 2026-09-06: dopo un'operazione che
 * ridisegna il contratto (duplica, elimina) il gruppo torna chiuso, quindi
 * `apri()` è idempotente e va richiamato prima di ogni interazione.
 *
 * Può importare: solo tipi Playwright. Non può importare: api, fixture, factory.
 */
import { expect, type Locator, type Page } from "@playwright/test";

export class GruppoQuesiti {
    readonly root: Locator;
    readonly toggle: Locator;

    constructor(private readonly page: Page, root: Locator) {
        this.root = root;
        this.toggle = root.locator(".fm-collapse-toggle");
    }

    /** Titolo del gruppo (il nome accessibile del suo toggle). */
    async titolo(): Promise<string> {
        return (await this.toggle.innerText()).trim();
    }

    /** Apre il gruppo se è chiuso e attende che risulti aperto. */
    async apri(): Promise<void> {
        await expect(this.toggle).toBeVisible({ timeout: 30_000 });
        if ((await this.toggle.getAttribute("aria-expanded")) !== "true") {
            await this.toggle.click();
        }
        await expect(this.toggle).toHaveAttribute("aria-expanded", "true", { timeout: 30_000 });
    }

    async chiudi(): Promise<void> {
        if ((await this.toggle.getAttribute("aria-expanded")) === "true") {
            await this.toggle.click();
        }
        await expect(this.toggle).toHaveAttribute("aria-expanded", "false");
    }

    get quesiti(): Locator {
        return this.root.locator(".fm-collection__item");
    }

    /** Identificativi dei quesiti nell'ordine in cui compaiono. */
    async idQuesiti(): Promise<string[]> {
        return this.quesiti.evaluateAll((els) => els.map((e) => e.getAttribute("data-id") ?? ""));
    }

    /** Bottone di un quesito per nome accessibile (es. «Modifica quesito»). */
    bottoneQuesito(nome: string, indice = 0): Locator {
        return this.root.getByRole("button", { name: nome }).nth(indice);
    }

    /**
     * Agisce su un controllo dentro il gruppo, riaprendolo se serve.
     *
     * Comportamento dell'app verificato il 2026-09-07: la pagina di studio
     * ridisegna il contratto mentre compone i contenuti differiti (la verifica
     * correlata in fondo, le figure delle sezioni aperte), e a ogni ridisegno
     * il gruppo torna chiuso. Da chiuso, i comandi dei quesiti restano nel
     * documento ma finiscono sotto la barra del gruppo, che intercetta il
     * clic: Playwright riporta l'elemento come «visibile, attivo e fermo» ma
     * coperto da `.fm-wrapchecksol`. Riaprire e riprovare non nasconde una
     * corsa fra processi: rimette la pagina nello stato in cui il comando è
     * raggiungibile, che è quel che farebbe una persona.
     */
    private async agisci(azione: (controllo: Locator) => Promise<void>, controllo: Locator): Promise<void> {
        let ultimo: unknown;
        for (let tentativo = 0; tentativo < 3; tentativo++) {
            await this.apri();
            await controllo.scrollIntoViewIfNeeded();
            try {
                await azione(controllo);
                return;
            } catch (errore) {
                ultimo = errore;
            }
        }
        throw ultimo;
    }

    /** Preme un comando del quesito (es. «Modifica quesito», «Aggiungi quesito»). */
    async premiSuQuesito(nome: string, indice = 0): Promise<void> {
        await this.agisci((c) => c.click({ timeout: 15_000 }), this.bottoneQuesito(nome, indice));
    }

    /** Come sopra, per i comandi del gruppo («Modifica tipologia», «Elimina tipologia»). */
    async premiSulGruppo(nome: string): Promise<void> {
        await this.agisci((c) => c.click({ timeout: 15_000 }), this.bottoneGruppo(nome).first());
    }

    /** Spunta una casella del quesito. */
    async spunta(controllo: Locator): Promise<void> {
        await this.agisci((c) => c.check({ timeout: 15_000 }), controllo);
    }

    /** Scrive in un campo del quesito (punteggio). */
    async scrivi(campo: Locator, valore: string): Promise<void> {
        await this.agisci((c) => c.fill(valore, { timeout: 15_000 }), campo);
    }

    /** Controlli di selezione e punteggio di un quesito. */
    controlliQuesito(indice = 0): { approfondimento: Locator; recupero: Locator; punti: Locator } {
        const quesito = this.quesiti.nth(indice);
        return {
            approfondimento: quesito.locator(".fm-checkbox-ain").first(),
            recupero: quesito.locator(".fm-checkbox-bin").first(),
            punti: quesito.locator(".fm-input-pt").first(),
        };
    }

    /** Casella «Mostra giustificazione» o «Mostra soluzioni» della barra del gruppo. */
    casella(nome: "Mostra giustificazione" | "Mostra soluzioni"): Locator {
        return this.root.getByRole("checkbox", { name: nome });
    }

    /** Controlli del gruppo: «Modifica tipologia», «Elimina tipologia». */
    bottoneGruppo(nome: string): Locator {
        return this.root.getByRole("button", { name: nome });
    }
}
