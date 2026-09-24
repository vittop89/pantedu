/**
 * Pacchetto di una verifica estratto su disco e compilato con LaTeX locale.
 *
 * Responsabilità: scompattare lo ZIP (o scrivere l'elenco dei file
 * distribuiti) in una cartella temporanea e compilare i documenti con
 * `pdflatex`, restituendo la dimensione dei PDF prodotti.
 *
 * Due scelte rispetto alle spec storiche:
 *   - l'estrazione usa `adm-zip`, già fra le dipendenze di sviluppo del
 *     progetto, invece di lanciare `unzip` o `Expand-Archive`: un processo
 *     esterno in meno e nessun ripiego da gestire;
 *   - i file finiscono sotto `storage/_tmp/e2e-bundle/` (cartella ignorata da
 *     git e già usata per gli artefatti di lavoro) invece che in `C:\tmp`, che
 *     è fuori dal progetto e non veniva mai ripulita.
 *
 * Può importare: node, adm-zip. Non può importare: api, fixture, spec.
 */
import AdmZip from "adm-zip";
import { execFileSync } from "node:child_process";
import * as fs from "node:fs";
import * as path from "node:path";

const RADICE = path.resolve(__dirname, "..", "..", "..", "..", "storage", "_tmp", "e2e-bundle");

/** Cartella di lavoro vuota per un pacchetto, dentro storage/_tmp. */
export function cartellaDiLavoro(nome: string): string {
    const cartella = path.join(RADICE, nome.replace(/[^\w.-]/g, "_"));
    fs.rmSync(cartella, { recursive: true, force: true });
    fs.mkdirSync(cartella, { recursive: true });
    return cartella;
}

/** Scompatta lo ZIP nella cartella e restituisce i percorsi contenuti. */
export function estraiZip(zip: Buffer, cartella: string): string[] {
    const archivio = new AdmZip(zip);
    archivio.extractAllTo(cartella, true);
    return archivio.getEntries().filter((e) => !e.isDirectory).map((e) => e.entryName);
}

/** Scrive su disco i file di un pacchetto distribuito (percorso + contenuto). */
export function scriviFile(cartella: string, files: readonly { readonly path: string; readonly content: string }[]): void {
    for (const file of files) {
        const destinazione = path.join(cartella, file.path);
        fs.mkdirSync(path.dirname(destinazione), { recursive: true });
        fs.writeFileSync(destinazione, file.content, "utf8");
    }
}

export interface EsitoCompilazione {
    readonly documento: string;
    readonly byte: number;
    /** Ultime righe del registro di LaTeX quando la compilazione fallisce. */
    readonly errore?: string;
}

/**
 * Compila un documento con `pdflatex` dentro la cartella indicata.
 * Non lancia: l'esito (byte prodotti o errore) torna al chiamante, che
 * asserisce quel che gli serve.
 */
export function compilaConPdflatex(cartella: string, documento: string): EsitoCompilazione {
    try {
        execFileSync("pdflatex", ["-interaction=nonstopmode", "-halt-on-error", documento], {
            cwd: cartella,
            stdio: "pipe",
            timeout: 120_000,
            windowsHide: true,
        });
    } catch (errore) {
        const uscita = errore instanceof Error && "stdout" in errore ? String((errore as { stdout?: Buffer }).stdout ?? "") : "";
        return { documento, byte: 0, errore: uscita.split("\n").slice(-15).join("\n") };
    }
    const pdf = path.join(cartella, documento.replace(/\.tex$/, ".pdf"));
    return { documento, byte: fs.existsSync(pdf) ? fs.statSync(pdf).size : 0 };
}

/** Legge un file del pacchetto estratto. */
export function leggiFile(cartella: string, percorso: string): string {
    return fs.readFileSync(path.join(cartella, percorso), "utf8");
}

export function esiste(cartella: string, percorso: string): boolean {
    return fs.existsSync(path.join(cartella, percorso));
}
