/**
 * Strumenti esterni richiesti da alcune spec: compilatore LaTeX locale e
 * servizio TeX.
 *
 * Responsabilità: dire subito e chiaramente che manca un prerequisito, invece
 * di far fallire il test più avanti con un errore incomprensibile (o, peggio,
 * di saltarlo in silenzio: la regola della suite è che un prerequisito assente
 * è un errore, non un salto). Le spec che ne hanno bisogno chiedono la fixture
 * `strumenti` e dichiarano il tag `@pdflatex` o `@tex` nel titolo.
 *
 * Può importare: node, fixture delle pagine. Non può importare: spec.
 */
import { execFileSync } from "node:child_process";
import { test as withPages } from "./pages.fixture";

export type StrumentoEsterno = "pdflatex" | "xelatex";

const disponibilita = new Map<string, boolean>();

/** Vero se il comando risponde a `-version` (risultato ricordato per il worker). */
function comandoDisponibile(comando: string): boolean {
    const noto = disponibilita.get(comando);
    if (noto !== undefined) return noto;
    let esito = false;
    try {
        execFileSync(comando, ["-version"], { stdio: "ignore", timeout: 30_000, windowsHide: true });
        esito = true;
    } catch {
        esito = false;
    }
    disponibilita.set(comando, esito);
    return esito;
}

export class Strumenti {
    /** Fallisce con un messaggio esplicito se il comando non è nel PATH. */
    richiede(...comandi: readonly StrumentoEsterno[]): void {
        const mancanti = comandi.filter((c) => !comandoDisponibile(c));
        if (mancanti.length) {
            throw new Error(
                `[strumenti] ${mancanti.join(", ")} non disponibile nel PATH: questa spec compila LaTeX in locale. `
                + "Installa MiKTeX (o TeX Live) e riapri il terminale; l'elenco dei prerequisiti è in tests/e2e/README.md.",
            );
        }
    }

    disponibile(comando: StrumentoEsterno): boolean {
        return comandoDisponibile(comando);
    }
}

export interface ToolFixtures {
    strumenti: Strumenti;
}

export const test = withPages.extend<ToolFixtures>({
    strumenti: async ({}, use) => {
        await use(new Strumenti());
    },
});
