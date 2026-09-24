/**
 * Compilazione di una verifica: attesa dell'esito, senza cicli a tempo.
 *
 * Il servizio TeX compila una richiesta per volta: quando è occupato risponde
 * con un errore temporaneo. Le spec storiche riprovavano quattro volte con
 * venti secondi di attesa fissa in mezzo (ottanta secondi buttati anche
 * quando la prima risposta arrivava subito); qui `expect.poll` riprova finché
 * l'esito è buono, con un tetto di tempo dichiarato.
 *
 * Può importare: api, tipi Playwright. Non può importare: fixture, spec.
 */
import { expect } from "@playwright/test";
import type { VerificaApi } from "../api/verifica.api";

export interface CompilaOpzioni {
    /** Motore da usare (es. «pdflatex»); assente = quello predefinito del servizio. */
    readonly engine?: string;
    /** Tetto di tempo complessivo, compresi i tentativi. */
    readonly timeout?: number;
}

/**
 * Compila la variante e aspetta che il servizio risponda con esito positivo.
 * Restituisce l'ultimo esito; fallisce l'asserzione se entro il tetto di tempo
 * la compilazione non riesce.
 */
export async function compilaVerifica(api: VerificaApi, id: number, opzioni: CompilaOpzioni = {}): Promise<void> {
    // Il valore osservato è «riuscita» oppure il dettaglio dell'ultima risposta:
    // così, se il tempo scade, il messaggio di fallimento riporta il motivo vero.
    await expect
        .poll(
            async () => {
                const esito = await api.compile(id, opzioni.engine);
                if (esito.status === 200 && esito.body?.ok === true) return "riuscita";
                return `${esito.status} ${esito.text.slice(0, 160)}`;
            },
            {
                message: `compilazione della verifica ${id}`,
                timeout: opzioni.timeout ?? 180_000,
                intervals: [1_000, 2_000, 5_000, 10_000, 20_000],
            },
        )
        .toBe("riuscita");
}

/** Scarica il PDF e verifica che sia un documento vero (firma e dimensione). */
export async function pdfCompilato(api: VerificaApi, id: number): Promise<Buffer> {
    const pdf = await api.pdf(id);
    expect(pdf.length, `il PDF della verifica ${id} non è vuoto`).toBeGreaterThan(1000);
    expect(pdf.subarray(0, 5).toString("ascii"), "firma del PDF").toBe("%PDF-");
    return pdf;
}
