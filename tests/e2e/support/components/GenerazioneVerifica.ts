/**
 * Generazione di una verifica dalla pagina di studio.
 *
 * È il flusso che il docente segue davvero: spunta i quesiti da mettere nella
 * verifica (A per la versione principale, R per il recupero), apre il pannello
 * delle informazioni di stampa dalla barra, compila titolo e copie, poi lancia
 * la generazione.
 *
 * Il comando «genera» è tenuto nascosto dalla barra
 * (`views/partials/_topbar_modern.php`: `fm-d-none` con il commento «UI
 * invisibile»): a premerlo è la pagina, non l'utente, e qui si fa lo stesso.
 * Tutto il resto sono interazioni vere.
 *
 * Può importare: components, tipi Playwright. Non può importare: api, fixture.
 */
import { expect, type Locator, type Page, type Response } from "@playwright/test";
import type { Topbar } from "./Topbar";

export interface InformazioniDiStampa {
    readonly titolo: string;
    readonly anno?: string;
    readonly sezione?: string;
    readonly copie?: number;
    readonly copieDsa?: number;
    readonly copieDis?: number;
    /** Spunta «DSA», che aggiunge le varianti dedicate. */
    readonly conMisureDsa?: boolean;
}

export class GenerazioneVerifica {
    constructor(
        private readonly page: Page,
        private readonly topbar: Topbar,
    ) {}

    private get primoGruppo(): Locator {
        return this.page.locator(".fm-groupcollex").first();
    }

    /**
     * Spunta un quesito per la versione indicata: «A» è la verifica
     * principale, «R» quella di recupero. Si spunta sia la casella del gruppo
     * sia quella del quesito, perché il filtro lavora su entrambi i livelli.
     */
    async selezionaPrimoQuesito(versione: "A" | "R"): Promise<void> {
        const gruppo = this.primoGruppo;

        // Nel gruppo la casella ha un'etichetta legata con `for`, e si comanda
        // da lì. Nel quesito, invece, l'etichetta («A», «R») è solo un fratello
        // dell'input senza `for`: cliccarla non fa nulla, e l'input si spunta
        // per nome accessibile. Niente `force`: aspettare che sia raggiungibile
        // funziona, forzare il clic quando è coperto lo manda a vuoto e lascia
        // la casella com'era.
        // Le due etichette del gruppo hanno la stessa classe e si distinguono
        // dal campo che comandano: `fm-chkA-…` è la versione principale,
        // `fm-chkB-…` il recupero.
        const etichettaDelGruppo = gruppo.locator(`label.labcheck[for^="fm-chk${versione === "A" ? "A" : "B"}"]`).first();
        if (await etichettaDelGruppo.count()) {
            await etichettaDelGruppo.click({ timeout: 15_000 });
        }

        const toggle = gruppo.locator(".fm-collapse-toggle").first();
        if ((await toggle.getAttribute("aria-expanded")) !== "true") {
            await toggle.click();
            await expect(toggle).toHaveAttribute("aria-expanded", "true", { timeout: 15_000 });
        }

        const nome = versione === "A" ? "Approfondimento" : "Recupero";
        const casella = gruppo.locator(".fm-collection__item").first().getByRole("checkbox", { name: nome });
        await casella.scrollIntoViewIfNeeded();
        await casella.check({ timeout: 15_000 });
        await expect(casella).toBeChecked();
    }

    /** Quesiti spuntati, per versione: serve alle asserzioni delle spec. */
    async quesitiSelezionati(): Promise<{ a: number; r: number }> {
        return this.page.evaluate(() => ({
            a: document.querySelectorAll(".fm-groupcollex .fm-checkbox-ain:checked").length,
            r: document.querySelectorAll(".fm-groupcollex .fm-checkbox-bin:checked, .fm-groupcollex .fm-checkbox-rin:checked").length,
        }));
    }

    /** Apre il pannello delle informazioni di stampa e lo compila. */
    async compilaInformazioni(dati: InformazioniDiStampa): Promise<void> {
        await this.topbar.premi("info");
        const pannello = this.page.locator("#infoVer");
        await expect(pannello).toBeVisible({ timeout: 15_000 });

        const scrivi = async (id: string, valore: string) => {
            const campo = this.page.locator(`#${id}`);
            if (await campo.count()) {
                await campo.fill(valore);
                await campo.dispatchEvent("change");
            }
        };
        await scrivi("verTitle", dati.titolo);
        await scrivi("anno", dati.anno ?? "2025-26");
        await scrivi("sezione", dati.sezione ?? "B");
        await scrivi("nPrint", String(dati.copie ?? 10));
        await scrivi("nPrintDSA", String(dati.copieDsa ?? 0));
        await scrivi("nPrintDIS", String(dati.copieDis ?? 0));

        if (dati.conMisureDsa) {
            const dsa = this.page.locator("#DSA");
            // Senza `force`: è una casella normale dentro il pannello delle
            // informazioni, e l'attesa che sia ferma serve — il pannello si
            // apre con un'animazione (vedi `punteggi-vero-falso.spec.js`).
            if (await dsa.count()) await dsa.check();
        }
        await this.page.keyboard.press("Escape");
    }

    /**
     * Lancia la generazione e restituisce la risposta del salvataggio.
     * Il salvataggio può passare da `save-tex` o da `save-tex-batch` a seconda
     * di quante varianti servono: si aspetta l'una o l'altra.
     */
    async genera(): Promise<Response> {
        const salvataggio = this.page.waitForResponse(
            (r) => /\/api\/verifica\/save-tex(-batch)?\b/.test(r.url()) && r.request().method() === "POST",
            { timeout: 60_000 },
        );
        await this.topbar.generaVerifica();
        return salvataggio;
    }
}
