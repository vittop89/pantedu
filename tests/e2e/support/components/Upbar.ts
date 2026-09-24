/**
 * Barra dei filtri sopra i quesiti (`.fm-upbar`).
 *
 * Resa dal server con la pagina (views/partials/upbar.html, inclusa da
 * StudioPageRenderer): raccoglie i comandi che nascondono o mostrano quesiti e
 * soluzioni e quelli che spuntano tutti i quesiti in un colpo.
 *
 * Dal 7 settembre 2026 le sei caselle hanno l'etichetta collegata con `for`
 * (era l'unica modifica all'applicazione prevista dal piano del refactoring):
 * prima le etichette erano lì accanto ma slegate, e le spec dovevano cambiare
 * la proprietà `checked` da codice e inviare l'evento a mano. Ora si spuntano
 * come farebbe una persona, cliccando l'etichetta.
 *
 * Può importare: solo tipi Playwright. Non può importare: api, fixture, factory.
 */
import { expect, type Locator, type Page } from "@playwright/test";

/** Etichette dei comandi a casella, così come le legge il docente. */
export type ComandoUpbar = "HideAll Eser" | "HideAll Soluz" | "CheckAll-A" | "CheckAll-R";

/**
 * I due comandi che arrivano solo agli amministratori.
 *
 * `views/partials/_upbar_loader.php` toglie dal markup «ShowChecked-A»,
 * «ShowChecked-R» e il filtro ORIGINE per chiunque non sia amministratore: al
 * docente non arrivano proprio, e nessun foglio di stile può rimetterli. Le
 * spec storiche li azionavano lo stesso, con un `getElementById` che tornava
 * `null` e un gestore che usciva subito: sembravano verifiche, non lo erano.
 * Adesso sono verificati dove esistono, con la sessione dell'amministratore.
 */
export type ComandoUpbarAdmin = "ShowChecked-A" | "ShowChecked-R";

export class Upbar {
    readonly root: Locator;

    constructor(private readonly page: Page) {
        this.root = page.locator(".fm-upbar");
    }

    /**
     * Apre la barra, se è chiusa, e aspetta che i suoi comandi si vedano.
     *
     * Alla prima apertura della pagina la barra è resa ma nascosta
     * (`display: none`): la mostra il comando «filtri» della barra degli
     * strumenti. Le spec storiche non la aprivano — cambiavano la proprietà
     * `checked` delle caselle da codice, cosa che funziona anche su elementi
     * invisibili, e così verificavano il gestore invece del comando.
     */
    async apri(): Promise<void> {
        await expect(this.root).toBeAttached({ timeout: 30_000 });
        const comando = this.page.locator('#fm-topbar [data-fm-action="filtri"]');
        await expect(comando, "il comando «filtri» è nella barra degli strumenti").toBeVisible({ timeout: 30_000 });

        // Quando è aperta, i suoi comandi si vedono: è quello il criterio, non
        // le dimensioni del contenitore, che resta senza altezza propria.
        const primoComando = this.etichetta("CheckAll-A");
        // Il comando alterna: se la pagina si sta ancora componendo, il primo
        // clic può arrivare prima che il gestore sia agganciato.
        for (let tentativo = 0; tentativo < 3; tentativo++) {
            if (await primoComando.isVisible()) return;
            await comando.click({ timeout: 15_000 });
            try {
                await expect(primoComando).toBeVisible({ timeout: 5_000 });
                return;
            } catch {
                // Riprova: al giro successivo lo stato viene riletto.
            }
        }
        await expect(primoComando, "i comandi della barra dei filtri si vedono").toBeVisible({ timeout: 15_000 });
    }

    /** Etichetta di un comando: è lei a comandare la casella, che è nascosta. */
    etichetta(comando: ComandoUpbar | ComandoUpbarAdmin): Locator {
        return this.root.locator("label.fm-label-btn-drop").filter({ hasText: comando }).first();
    }

    casella(comando: ComandoUpbar | ComandoUpbarAdmin): Locator {
        return this.page.getByLabel(comando);
    }

    /** Spunta o toglie la spunta a un comando e aspetta il nuovo stato. */
    async imposta(comando: ComandoUpbar | ComandoUpbarAdmin, spuntato: boolean): Promise<void> {
        const casella = this.casella(comando);
        if ((await casella.isChecked()) === spuntato) return;
        await this.etichetta(comando).click({ timeout: 15_000 });
        await expect
            .poll(async () => casella.isChecked(), {
                message: `il comando «${comando}» doveva risultare ${spuntato ? "attivo" : "spento"}`,
                timeout: 15_000,
            })
            .toBe(spuntato);
    }

    /**
     * Comando «HideAll Probl»: è un bottone, non una casella, e cambia scritta
     * quando i problemi sono ripiegati.
     */
    comandoProblemi(): Locator {
        return this.root.getByRole("button", { name: /All Probl/ });
    }

    /** Bottone che apre il menu di un filtro a tendina (difficoltà, origine). */
    tendina(id: "sel-dif" | "sel-origin"): Locator {
        return this.root.locator(`#${id} .dropdown-button`);
    }

    /** Voci del menu a tendina aperto. */
    voci(id: "sel-dif" | "sel-origin"): Locator {
        return this.root.locator(`#${id} .fm-dropdown-content`);
    }

    /** Conteggi dei quesiti, per le asserzioni delle spec. */
    async conteggi(): Promise<{ totale: number; spuntatiA: number; spuntatiR: number; visibili: number }> {
        return this.page.evaluate(() => ({
            totale: document.querySelectorAll(".fm-checkbox-ain").length,
            spuntatiA: document.querySelectorAll(".fm-checkbox-ain:checked").length,
            spuntatiR: document.querySelectorAll(".fm-checkbox-bin:checked").length,
            visibili: Array.from(document.querySelectorAll(".fm-collection__item"))
                .filter((e) => e instanceof HTMLElement && e.offsetParent !== null).length,
        }));
    }
}
