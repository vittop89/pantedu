/**
 * Barra degli strumenti del documento (`#fm-topbar`).
 *
 * Struttura resa da views/partials/_topbar_modern.php: una barra con
 * `role="toolbar"` e i comandi identificati da `data-fm-action`
 * (salvatex, zip, vsc, vsc-settings, genera, filtri, editor,
 * info). L'attributo è il modo in cui l'app stessa lega i comandi ai loro
 * gestori, e resta stabile mentre le etichette cambiano: «SalvaTEX» oggi si
 * chiama «TEX/PDF».
 *
 * Il comando «genera» è deliberatamente invisibile (`fm-d-none`): lo scatena
 * la pagina, non l'utente, e le spec lo azionano come fa l'app.
 *
 * Può importare: solo tipi Playwright. Non può importare: api, fixture, factory.
 */
import { expect, type Locator, type Page } from "@playwright/test";

export type AzioneTopbar =
    | "salvatex"
    | "zip"
    | "vsc"
    | "vsc-settings"
    | "genera"
    | "filtri"
    | "editor"
    | "info";

export class Topbar {
    readonly root: Locator;

    constructor(private readonly page: Page) {
        this.root = page.locator("#fm-topbar");
    }

    async attendiPronta(): Promise<void> {
        await expect(this.root).toBeVisible({ timeout: 30_000 });
    }

    comando(azione: AzioneTopbar): Locator {
        return this.root.locator(`[data-fm-action="${azione}"]`);
    }

    /** Zona della barra: «target» (documento), «eser», «printinfo», «scelte». */
    zona(nome: string): Locator {
        return this.root.locator(`.fm-topbar__zone--${nome}`);
    }

    /** Premi un comando visibile della barra. */
    async premi(azione: AzioneTopbar): Promise<void> {
        const comando = this.comando(azione);
        await comando.scrollIntoViewIfNeeded();
        await comando.click({ timeout: 15_000 });
    }

    /**
     * Aziona il comando «genera», che la barra tiene nascosto perché lo
     * scatena la pagina: si preme come fa l'app, senza fingere un clic
     * dell'utente su qualcosa che l'utente non vede.
     */
    async generaVerifica(): Promise<void> {
        await expect(this.comando("genera")).toBeAttached();
        await this.comando("genera").dispatchEvent("click");
    }

    /** Etichetta testuale di un comando (i bottoni con testo, es. ZIP). */
    async etichetta(azione: AzioneTopbar): Promise<string> {
        return (await this.comando(azione).innerText()).trim();
    }
}
