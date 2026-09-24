/**
 * Pagina di studio di un esercizio: /studio/esercizio/{ind}/{cls}/{materia}/{topic}.
 *
 * Responsabilità: aprire la pagina e attendere che sia pronta davvero, dare
 * accesso ai gruppi, all'editor del quesito e alla finestra di conferma.
 *
 * Il segnale di pronto è `fm:verifica-ui-loaded`, emesso da
 * js/modules/features/verifica-builder.js:208 quando i controlli dei quesiti
 * (caselle A/R, punti, posizioni, origini) sono montati; l'attributo
 * `data-fm-checkin-bound="1"` su <html> conferma che i gestori dei quesiti
 * sono collegati (js/modules/features/checkin-handlers.js:1288-1289).
 * Nessuna attesa a tempo.
 *
 * Può importare: components. Non può importare: api, fixture, factory.
 */
import { expect, type Locator, type Page } from "@playwright/test";
import { GenerazioneVerifica } from "../components/GenerazioneVerifica";
import { GruppoQuesiti } from "../components/GruppoQuesiti";
import { Topbar } from "../components/Topbar";
import { Upbar } from "../components/Upbar";

export class StudioEsercizioPage {
    readonly topbar: Topbar;
    /** Barra dei filtri sopra i quesiti. */
    readonly upbar: Upbar;
    /** Flusso «spunta i quesiti, compila le informazioni, genera la verifica». */
    readonly generazione: GenerazioneVerifica;

    constructor(readonly page: Page) {
        this.topbar = new Topbar(page);
        this.upbar = new Upbar(page);
        this.generazione = new GenerazioneVerifica(page, this.topbar);
    }

    /** Apre l'indirizzo indicato e aspetta che i controlli dei quesiti siano montati. */
    async vaiA(url: string): Promise<void> {
        await this.page.goto(url);
        this.controllaCheNonSiaLAccesso(url);
        await this.page.waitForFunction(() => (window.__fmEvents?.["fm:verifica-ui-loaded"] ?? 0) > 0, null, { timeout: 30_000 });
        await expect(this.page.locator("html")).toHaveAttribute("data-fm-checkin-bound", "1");
        await this.attendiPaginaComposta();
    }

    /**
     * Se il server ha rimandato all'accesso, lo dice subito.
     *
     * 2026-09-08 — senza questo controllo il fallimento era illeggibile: la
     * sessione decadeva, il server rispondeva 302 verso `/login`, e la prova
     * restava trenta secondi ad aspettare `fm:verifica-ui-loaded` — un evento
     * che su una pagina di accesso non può arrivare. L'errore che ne usciva
     * parlava dell'evento e non nominava la causa; ci sono volute la traccia
     * conservata e la riga del 302 per capirlo. Adesso lo dice il messaggio.
     *
     * Perché la sessione decada resta da capire (voce 80 del debito): la
     * fixture la verifica con `/auth/user-info` un attimo prima, e la trova
     * viva.
     */
    private controllaCheNonSiaLAccesso(chiesto: string): void {
        const arrivo = this.page.url();
        if (!/\/login(\?|$)/.test(arrivo)) return;
        throw new Error(
            `la sessione non vale più: ${chiesto} ha rimandato all'accesso (${arrivo}). `
            + "Non è un difetto della pagina di studio.",
        );
    }

    /**
     * Fa arrivare i contenuti differiti e aspetta che la pagina stia ferma.
     *
     * In fondo alla pagina l'app carica la verifica correlata solo quando ci si
     * avvicina (`scheduleRelatedVerifica` in verifica-builder.js): finché non si
     * scorre non parte, e se parte durante un'interazione sposta tutto quello
     * che c'è sopra — i comandi dei quesiti finiscono sotto la barra del gruppo
     * e il clic viene intercettato. Qui si scorre fino in fondo per farla
     * partire subito, si aspetta che altezza e numero di quesiti restino
     * uguali per due letture di seguito, poi si torna in cima. È un'attesa su
     * un fatto osservabile — la pagina ha smesso di crescere — non un ritardo.
     */
    async attendiPaginaComposta(): Promise<void> {
        await this.page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
        let precedente = { altezza: -1, quesiti: -1 };
        await expect
            .poll(
                async () => {
                    const attuale = await this.page.evaluate(() => ({
                        altezza: document.body.scrollHeight,
                        quesiti: document.querySelectorAll(".fm-collection__item").length,
                    }));
                    const fermo = attuale.altezza === precedente.altezza && attuale.quesiti === precedente.quesiti;
                    precedente = attuale;
                    return fermo;
                },
                { message: "la pagina continua a cambiare altezza o numero di quesiti", timeout: 60_000, intervals: [500, 500, 1_000, 2_000] },
            )
            .toBe(true);
        await this.page.evaluate(() => window.scrollTo(0, 0));
    }

    get titolo(): Locator {
        return this.page.locator(".fm-titolo h1").first();
    }

    get gruppi(): Locator {
        return this.page.locator(".fm-groupcollex");
    }

    /** Tutti i quesiti della pagina, di ogni gruppo. */
    get quesiti(): Locator {
        return this.page.locator(".fm-collection__item");
    }

    gruppo(indice: number): GruppoQuesiti {
        return new GruppoQuesiti(this.page, this.gruppi.nth(indice));
    }

    /** Il gruppo con almeno `minimo` quesiti (i test di riordino ne hanno bisogno). */
    async gruppoConAlmeno(minimo: number): Promise<GruppoQuesiti> {
        const totale = await this.gruppi.count();
        for (let i = 0; i < totale; i++) {
            const g = this.gruppo(i);
            if ((await g.quesiti.count()) >= minimo) return g;
        }
        throw new Error(`[studio] nessun gruppo con almeno ${minimo} quesiti fra i ${totale} della pagina`);
    }

    get pannelloEditor(): Locator {
        return this.page.locator(".fm-editor-panel");
    }

    /** Finestra di conferma condivisa (js/modules/ui/fm-dialog.js): «Annulla» / «OK». */
    get conferma(): Locator {
        return this.page.getByRole("dialog");
    }

    /** Apre l'editor del quesito indicato e attende il campo di testo. */
    async apriEditorQuesito(gruppo: GruppoQuesiti, indice = 0): Promise<Locator> {
        await gruppo.apri();
        await gruppo.premiSuQuesito("Modifica quesito", indice);
        await expect(this.pannelloEditor.locator(".fm-editor-field").first()).toBeVisible({ timeout: 30_000 });
        return this.pannelloEditor;
    }

    /** Chiude l'editor con il suo bottone «Chiudi» (il salvataggio è automatico). */
    async chiudiEditorQuesito(): Promise<void> {
        await this.pannelloEditor.getByRole("button", { name: /^Chiudi$/ }).click();
        await expect(this.pannelloEditor).toHaveCount(0, { timeout: 30_000 });
    }
}
