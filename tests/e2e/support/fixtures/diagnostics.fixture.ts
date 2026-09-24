/**
 * Diagnostica per test: errori JavaScript, errori di console, risposte 4xx/5xx
 * e richieste che non hanno ricevuto risposta.
 *
 * Responsabilità: osservare ogni pagina aperta dalle fixture di ruolo (e la
 * `page` anonima), allegare al report un riepilogo leggibile quando serve, e
 * **far fallire il test se la pagina ha prodotto errori JavaScript**.
 *
 * Il controllo è qui e non nelle spec (2026-09-07): prima lo faceva una riga
 * ripetuta in sessantasette test su cinquecentottantanove, e negli altri
 * cinquecento un errore in pagina passava inosservato.
 *
 * Non c'è modo di zittirlo per un singolo test, ed è voluto: una tolleranza
 * per test è la stessa cosa di un errore ingoiato, scritta meglio. L'unico
 * posto dove si dichiara «questo non è un errore dell'applicazione» è il
 * filtro qui sotto, che sta in un file solo e si legge tutto insieme. Se un
 * errore vero salta fuori, o si corregge l'applicazione, o — se è di una
 * libreria di terzi — si allarga il filtro spiegando perché.
 *
 * Può importare: solo Playwright. Non può importare: api, pagine, factory.
 */
import { test as base, type Page } from "@playwright/test";

/** Messaggi di console che non sono errori dell'app (stesso filtro di studio-eser-helpers.js). */
const CONSOLE_NOISE = /Failed to load resource|net::ERR|favicon|gas-client|tikzjax/i;

export class Diagnostics {
    readonly pageErrors: string[] = [];
    readonly consoleErrors: string[] = [];
    readonly failedResponses: string[] = [];
    /**
     * Richieste che non hanno ricevuto **nessuna** risposta: connessione
     * rifiutata, richiesta troncata, nome non risolto.
     *
     * 2026-09-08 — era il buco delle tre sonde. `response` non scatta (non
     * c'è risposta), `pageerror` nemmeno (un modulo che non si carica non
     * lancia un'eccezione non gestita), e il messaggio di console lo prende
     * il filtro del rumore, giustamente, insieme ai `net::ERR` innocui.
     * Risultato: la barra degli strumenti falliva perché
     * `checkin-handlers.js` non si era caricato, e la diagnostica diceva
     * «nessun errore» tre volte di fila. Adesso c'è scritto.
     *
     * Sta nel rapporto, non fra le cause di fallimento: gli annullamenti da
     * navigazione (`net::ERR_ABORTED`) sono normali, e trasformarli in
     * fallimenti sarebbe rumore. Chi legge un test rosso, però, adesso vede
     * cosa non è arrivato.
     */
    readonly failedRequests: string[] = [];
    /** Annotazioni delle fixture (es. origine della sessione). */
    readonly notes: string[] = [];

    /** Le prime righe della pila di un errore: senza, «Cannot read properties of null» non dice dove. */
    private static origine(error: Error): string {
        const righe = (error.stack ?? "").split("\n").slice(1)
            .map((r) => r.trim())
            .filter((r) => r.startsWith("at ") && !/node_modules/.test(r));
        return righe.length ? " — " + righe.slice(0, 2).join(" ← ") : "";
    }

    observe(page: Page): void {
        page.on("pageerror", (error) => this.pageErrors.push(error.message + Diagnostics.origine(error)));
        page.on("console", (message) => {
            if (message.type() !== "error") return;
            const text = message.text();
            if (CONSOLE_NOISE.test(text)) return;
            this.consoleErrors.push(text);
        });
        page.on("response", (response) => {
            if (response.status() >= 400) {
                this.failedResponses.push(`${response.request().method()} ${response.url()} → ${response.status()}`);
            }
        });
        page.on("requestfailed", (request) => {
            const causa = request.failure()?.errorText ?? "(causa ignota)";
            this.failedRequests.push(
                `${request.method()} ${request.url()} [${request.resourceType()}] → ${causa}`,
            );
        });
    }

    /** Errori JS della pagina più errori di console (senza il rumore di rete). */
    get jsErrors(): readonly string[] {
        return [...this.pageErrors.map((e) => `[pageerror] ${e}`), ...this.consoleErrors.map((e) => `[console] ${e}`)];
    }

    report(): string {
        const section = (title: string, lines: readonly string[]): string =>
            `== ${title} (${lines.length}) ==\n${lines.length ? lines.join("\n") : "(nessuno)"}`;
        return [
            section("Annotazioni", this.notes),
            section("Errori JavaScript", this.pageErrors),
            section("Errori di console", this.consoleErrors),
            section("Risposte 4xx/5xx", this.failedResponses),
            section("Richieste senza risposta", this.failedRequests),
        ].join("\n\n");
    }
}

export interface DiagnosticsFixtures {
    diagnostics: Diagnostics;
}

export const test = base.extend<DiagnosticsFixtures>({
    diagnostics: [
        async ({}, use, testInfo) => {
            const diagnostics = new Diagnostics();
            await use(diagnostics);

            const fallito = testInfo.status !== testInfo.expectedStatus;
            const errori = diagnostics.jsErrors;
            if (fallito || errori.length) {
                await testInfo.attach("diagnostica.txt", { body: diagnostics.report(), contentType: "text/plain" });
            }
            // Un test che ha fatto il suo lavoro ma ha lasciato errori in
            // pagina non è passato: l'utente quegli errori li avrebbe avuti.
            if (!fallito && errori.length) {
                throw new Error(
                    `La pagina ha prodotto ${errori.length} ${errori.length === 1 ? "errore" : "errori"} JavaScript:\n`
                    + errori.join("\n"),
                );
            }
        },
        { auto: true },
    ],
    // La `page` anonima viene osservata come le pagine dei ruoli.
    page: async ({ page, diagnostics }, use) => {
        diagnostics.observe(page);
        await use(page);
    },
});
