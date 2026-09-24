/**
 * Il riquadro «Credenziali di classe» nel profilo del docente
 * (views/area_docente/profilo.php, js/modules/features/teacher-credentials.js).
 *
 * Responsabilità: aprire il profilo con l'elenco già caricato, creare una
 * credenziale dal modulo, trovare la riga di una credenziale, premerne i
 * comandi. Dopo ogni azione il modulo rilegge l'elenco dal server e ridisegna
 * la tabella: le attese stanno sulle righe (`data-id`, `data-active`), che
 * sono lo stato che l'app stessa dichiara, non a tempo.
 *
 * Dal 19 settembre 2026 (ADR-044) l'etichetta non si scrive: la compone il
 * server con classe, indirizzo, le materie spuntate nel modulo e l'aggiunta.
 * Il modulo ne mostra l'anteprima (`anteprima`), chiesta al server.
 *
 * Può importare: solo tipi Playwright. Non può importare: api, fixture, factory.
 */
import { expect, type Locator, type Page } from "@playwright/test";

export interface NuovaCredenziale {
    readonly username: string;
    readonly password: string;
    /** Aggiunta all'etichetta; assente = nessuna. */
    readonly aggiunta?: string;
    /**
     * Le sigle delle materie da lasciare spuntate; assente = come le propone il
     * modulo (tutte spuntate).
     */
    readonly materie?: readonly string[];
    /** «classe» = la coppia scelta nella barra laterale; «tutte» = credenziale non delimitata. */
    readonly perimetro?: "classe" | "tutte";
}

export class PannelloCredenziali {
    readonly radice: Locator;
    readonly elenco: Locator;
    readonly modulo: Locator;
    /** Il messaggio di esito sotto il modulo. */
    readonly riscontro: Locator;
    /** L'etichetta che il server comporrebbe con le scelte del modulo. */
    readonly anteprima: Locator;
    readonly campoUsername: Locator;
    readonly campoPassword: Locator;
    readonly campoAggiunta: Locator;
    /** Le caselle delle materie del modulo, una per materia spuntata dal docente. */
    readonly caselleMaterie: Locator;

    constructor(readonly page: Page) {
        this.radice = page.locator("#fm-credenziali");
        this.elenco = this.radice.locator("#fm-cred-list");
        this.modulo = this.radice.locator("#fm-cred-form");
        this.riscontro = this.radice.locator("#fm-cred-feedback");
        this.anteprima = this.radice.locator("#fm-cred-anteprima");
        this.campoUsername = this.modulo.getByLabel("Username", { exact: true });
        this.campoPassword = this.modulo.getByLabel("Password", { exact: true });
        this.campoAggiunta = this.modulo.getByLabel("Aggiunta all'etichetta (facoltativa)", { exact: true });
        this.caselleMaterie = this.modulo.locator('#fm-cred-materie input[type="checkbox"][name="materie"]');
    }

    async vaiA(): Promise<void> {
        await this.page.goto("/area-docente/profilo");
        await expect(this.elenco, "l'elenco delle credenziali si carica").not.toContainText("Caricamento", { timeout: 30_000 });
        await expect(this.modulo.locator("#fm-cred-materie-list"), "e le materie del modulo").not.toContainText("Caricamento", { timeout: 30_000 });
    }

    /** Il testo d'aiuto collegato a un campo con aria-describedby. */
    async aiutoDi(campo: Locator): Promise<Locator> {
        const id = await campo.getAttribute("aria-describedby");
        expect(id, "il campo è collegato al suo testo d'aiuto").toBeTruthy();
        return this.page.locator(`[id="${id}"]`);
    }

    /** Il messaggio che il browser mostrerebbe per il campo (fumetto di convalida). */
    async messaggioDelBrowser(campo: Locator): Promise<string> {
        return campo.evaluate((el) => (el as HTMLInputElement).validationMessage);
    }

    /** Lascia spuntate solo le materie date. */
    async scegliMaterie(sigle: readonly string[]): Promise<void> {
        const n = await this.caselleMaterie.count();
        for (let i = 0; i < n; i++) {
            const casella = this.caselleMaterie.nth(i);
            const sigla = (await casella.getAttribute("value")) ?? "";
            await casella.setChecked(sigle.includes(sigla));
        }
    }

    /** Compila il modulo, senza premere «Crea». */
    async compila(dati: NuovaCredenziale): Promise<void> {
        if ((dati.perimetro ?? "tutte") === "tutte") {
            await this.modulo.getByLabel(/Tutte le mie classi/).check();
        }
        if (dati.materie !== undefined) await this.scegliMaterie(dati.materie);
        if (dati.aggiunta !== undefined) await this.campoAggiunta.fill(dati.aggiunta);
        await this.campoUsername.fill(dati.username);
        await this.campoPassword.fill(dati.password);
    }

    /** Compila il modulo e preme «Crea». */
    async crea(dati: NuovaCredenziale): Promise<void> {
        await this.compila(dati);
        await this.modulo.getByRole("button", { name: "Crea" }).click();
    }

    /** La riga della credenziale con quell'username. */
    riga(username: string): Locator {
        return this.elenco.locator("tr[data-id]").filter({ hasText: username });
    }

    /**
     * L'etichetta scritta sulla riga: il `strong` figlio diretto della cella.
     *
     * Il discendente («td.fm-cred-etichetta strong») prendeva anche le sigle
     * dell'editor, che sta dentro la stessa cella e scrive ogni materia in un
     * `strong`: a editor aperto erano quattro elementi, e Playwright rompeva
     * subito per violazione della modalità strict invece di riprovare finché
     * l'elenco si ridisegnava (misurato: 29,6 ms, non i 5 s dell'attesa).
     */
    etichetta(username: string): Locator {
        return this.riga(username).locator("td.fm-cred-etichetta > strong");
    }

    /** L'identificativo che l'app scrive sulla riga. */
    async idDi(username: string): Promise<number> {
        const id = await this.riga(username).getAttribute("data-id");
        expect(id, `la riga di «${username}» porta il proprio identificativo`).toBeTruthy();
        return Number(id);
    }

    /** Il comando sulla riga: «Spegni», «Attiva», «QR», «Rigenera etichetta», «Elimina»… */
    comando(username: string, nome: string): Locator {
        return this.riga(username).getByRole("button", { name: nome, exact: true });
    }

    /** Dalla riga: «Rigenera etichetta», materie e aggiunta, «Salva etichetta». */
    async rigeneraEtichetta(username: string, sigle: readonly string[], aggiunta: string): Promise<void> {
        await this.comando(username, "Rigenera etichetta").click();
        const editor = this.riga(username).getByRole("group", { name: "Nuova etichetta" });
        await expect(editor, "l'editor dell'etichetta si apre nella riga").toBeVisible();
        const caselle = editor.locator('input[type="checkbox"]');
        const n = await caselle.count();
        for (let i = 0; i < n; i++) {
            const casella = caselle.nth(i);
            await casella.setChecked(sigle.includes((await casella.getAttribute("value")) ?? ""));
        }
        await editor.getByLabel("Aggiunta all'etichetta").fill(aggiunta);
        await editor.getByRole("button", { name: "Salva etichetta" }).click();
    }
}
