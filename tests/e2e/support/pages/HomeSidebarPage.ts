/**
 * Home del docente con la barra laterale e i suoi pannelli (sidepage).
 *
 * Responsabilità: aprire la home, scegliere la terna, aprire un pannello e
 * attendere che sia popolato dal database, entrare in modalità modifica.
 *
 * Segnali usati (nessuna attesa a tempo): `fm:db-sidepage-rendered`
 * (js/modules/features/db-sidepage.js:194, 309, 598) per i pannelli dei
 * contenuti, `fm:risdoc-sidepage-rendered`
 * (js/modules/features/risdoc-sidepage.js:266) per «Risorse docente»;
 * il bottone «Modifica sezione» porta `data-fm-edit-bound="1"` quando è
 * collegato (js/modules/features/sidepage-edit-toggle.js:69-70) e il blocco
 * in modifica ha `data-edit-active="1"`.
 *
 * Può importare: solo tipi Playwright. Non può importare: api, fixture, factory.
 */
import { expect, type Locator, type Page } from "@playwright/test";

/**
 * Pannelli della barra laterale.
 *
 * Il bottone si individua con `data-sidepage`, l'attributo con cui l'app stessa
 * lega bottone e pannello: l'etichetta visibile non serve, perché il nome delle
 * sezioni è configurabile per Istituto (in locale «RISORSE DOCENTE», nel
 * sorgente della vista «Risorse docente (riservato)») e legarcisi renderebbe il
 * test dipendente dai dati.
 */
export const SIDEPAGE = {
    esercizi: { chiave: "eser", id: "#fm-sp-eser", evento: "fm:db-sidepage-rendered" },
    verifiche: { chiave: "verif", id: "#fm-sp-verif", evento: "fm:db-sidepage-rendered" },
    mappe: { chiave: "mappe", id: "#fm-sp-mappe", evento: "fm:db-sidepage-rendered" },
    laboratorio: { chiave: "lab", id: "#fm-sp-lab", evento: "fm:db-sidepage-rendered" },
    risorseDocente: { chiave: "risdoc", id: "#fm-sp-risdoc", evento: "fm:risdoc-sidepage-rendered" },
    besDsa: { chiave: "bes", id: "#fm-sp-bes", evento: "fm:risdoc-sidepage-rendered" },
} as const;

export type SidepageKey = keyof typeof SIDEPAGE;

export class HomeSidebarPage {
    constructor(readonly page: Page) {}

    async vaiA(): Promise<void> {
        await this.page.goto("/?home=1");
        await expect(this.page.locator("#sel-iis")).toBeVisible();
    }

    /** Sceglie indirizzo, classe e materia: i selettori sono a cascata, in quest'ordine. */
    async scegliTerna(indirizzo: string, classe: string, materia: string): Promise<void> {
        await this.page.locator("#sel-iis").selectOption(indirizzo);
        await this.page.locator("#sel-cls").selectOption(classe);
        await this.page.locator("#sel-mater").selectOption(materia);
    }

    /**
     * Apre il pannello e aspetta l'evento con cui l'app dichiara di averlo
     * popolato. Se è già aperto lo lascia com'è: il comando è un interruttore,
     * e premerlo su un pannello aperto lo chiuderebbe. Lo stato dei pannelli
     * sopravvive al ricaricamento della pagina.
     */
    async apriSidepage(chiave: SidepageKey): Promise<Locator> {
        const { chiave: dataKey, id, evento } = SIDEPAGE[chiave];
        const pannelloAperto = this.page.locator(id);
        if (await pannelloAperto.isVisible()) return pannelloAperto;
        const prima = await this.page.evaluate((e) => window.__fmEvents?.[e] ?? 0, evento);
        await this.page.locator(`button[data-sidepage="${dataKey}"]`).click();
        await this.page.waitForFunction(
            ([e, p]) => (window.__fmEvents?.[e as string] ?? 0) > (p as number),
            [evento, prima] as const,
            { timeout: 30_000 },
        );
        const pannello = this.page.locator(id);
        await expect(pannello).toBeVisible();
        return pannello;
    }

    /**
     * Attende che nel pannello «Verifiche» compaiano anche le verifiche
     * generate, che arrivano dopo con una chiamata loro.
     *
     * Attenzione: il modulo che le disegna è caricato solo dalle pagine di
     * studio. Sulla home il pannello elenca i soli contenuti di tipo verifica
     * del docente, e le verifiche generate non compaiono affatto.
     */
    async attendiVerificheGenerate(pannello: Locator): Promise<void> {
        await expect(
            pannello.locator("li[data-fm-content-kind='verifica']").first(),
            "le verifiche generate non sono comparse nel pannello (il modulo si carica solo dalle pagine di studio)",
        ).toBeVisible({ timeout: 30_000 });
    }

    /**
     * Aspetta che il pannello abbia finito di disegnare le verifiche, senza
     * pretendere che ce ne sia almeno una.
     *
     * Serve dopo aver cancellato: se quella era l'unica verifica del docente,
     * il pannello è legittimamente vuoto e `attendiVerificheGenerate`
     * aspetterebbe per sempre una riga che non deve esserci. Il segnale è il
     * marcatore che il modulo inserisce comunque — il divisore «Verifiche
     * salvate» o il blocco per materia — e c'è tanto con le righe quanto con
     * la scritta «Nessuna verifica salvata»
     * (`js/modules/features/verifica-documents-sidepage.js`).
     */
    async attendiPannelloVerificheDisegnato(pannello: Locator): Promise<void> {
        await expect(
            pannello.locator(".fm-vd-divider, .fm-vd-block, .fm-vd-host").first(),
            "il pannello non ha finito di disegnare le verifiche",
        ).toBeAttached({ timeout: 30_000 });
    }

    /** Chiude il pannello: è lo stesso bottone che lo apre. */
    async chiudiSidepage(chiave: SidepageKey): Promise<void> {
        const { chiave: dataKey, id } = SIDEPAGE[chiave];
        await this.page.locator(`button[data-sidepage="${dataKey}"]`).click();
        await expect(this.page.locator(id)).toBeHidden({ timeout: 15_000 });
    }

    /** Attiva la modifica del primo blocco del pannello e attende lo stato attivo. */
    async attivaModificaSezione(pannello: Locator): Promise<Locator> {
        const bottone = pannello.getByRole("button", { name: "Modifica sezione" }).first();
        await expect(bottone).toHaveAttribute("data-fm-edit-bound", "1");
        await bottone.click();
        const blocco = pannello.locator('ul.fm-db-block[data-edit-active="1"]');
        await expect(blocco.first()).toBeAttached({ timeout: 15_000 });
        return blocco.first();
    }

    /**
     * Preme «+ Nuovo» nella sezione in modifica e attende la finestra.
     *
     * La finestra arriva con un caricamento differito: si aspetta che i suoi
     * campi ci siano, non un tempo.
     */
    async nuovoNellaSezione(pannello: Locator): Promise<Locator> {
        const blocco = pannello.locator('ul.fm-db-block[data-edit-active="1"]').first();
        await blocco.locator(".fm-section-add").first().click({ timeout: 15_000 });
        const finestra = this.page.locator(".fm-modal-backdrop").last();
        await expect(finestra, "la finestra di creazione si apre").toBeVisible({ timeout: 30_000 });
        return finestra;
    }
}
