/**
 * Credenziali di classe di prova, con nome unico e cancellazione registrata.
 *
 * Responsabilità: creare via API una credenziale del docente della sessione
 * (delimitata a una classe, o valida per tutte), restituire la coppia che lo
 * studente userà per entrare e l'etichetta che il server ha composto, e
 * registrare la cancellazione nel registro di pulizia per il ruolo che l'ha
 * creata. La password è generata qui, come il nome: è un dato di prova, non un
 * segreto, e non sta scritta in nessuna spec.
 *
 * L'etichetta (ADR-044) la compone il server: classe, indirizzo, materie,
 * aggiunta. La fabbrica manda un'aggiunta unica e di lunghezza fissa, così due
 * credenziali dello stesso docente sullo stesso perimetro non si scontrano, e
 * nessuna etichetta è l'inizio di un'altra (i locator cercano per testo).
 *
 * Può importare: api, cleanup-registry, naming, env (solo il tipo Role).
 * Non può importare: pagine, fixture.
 */
import { randomBytes } from "node:crypto";
import type { CredentialsApi } from "../api/credentials.api";
import type { Role } from "../env";
import type { CleanupRegistry } from "./cleanup-registry";
import type { Naming } from "./naming";

export interface CreatedCredential {
    readonly id: number;
    /** L'etichetta composta dal server, come la vedrà lo studente. */
    readonly label: string;
    readonly username: string;
    readonly password: string;
    /** L'aggiunta mandata, in maiuscolo come la scrive il server. */
    readonly aggiunta: string;
}

export interface CredentialOptions {
    /** Codice breve della classe (es. "2"): la credenziale apre solo quella. */
    readonly classe?: string;
    readonly indirizzo?: string;
    /** YYYY-MM-DD; assente = il 31 agosto dell'anno scolastico in corso. */
    readonly expiresAt?: string;
    /** Sigle delle materie dell'etichetta; assenti = nessuna. */
    readonly materie?: readonly string[];
}

/** Dieci caratteri fra lettere e cifre, sempre gli stessi dieci per la stessa chiamata. */
let contatore = 0;
function aggiuntaUnica(): string {
    contatore++;
    const istante = Date.now().toString(36).slice(-7).padStart(7, "0");
    return `E${istante}${contatore.toString(36).padStart(2, "0").slice(-2)}`.toUpperCase();
}

export class CredentialFactory {
    constructor(
        private readonly api: CredentialsApi,
        private readonly cleanup: CleanupRegistry,
        private readonly owner: Role,
        private readonly naming: Naming,
    ) {}

    async create(options: CredentialOptions = {}): Promise<CreatedCredential> {
        // L'username ammette lettere, numeri, punto, trattino e trattino basso,
        // da 3 a 64 caratteri, ed è unico su tutta la piattaforma: il nome
        // unico li rispetta già, si taglia solo la lunghezza.
        const username = this.naming.unique("accesso").toLowerCase().replace(/-/g, "_").slice(0, 64);
        // Solo ASCII stampabili, da 6 a 64: la regola del server.
        const password = `E2e!${randomBytes(6).toString("hex")}`;
        const aggiunta = aggiuntaUnica();

        const result = await this.api.create({
            username,
            password,
            aggiunta,
            ...(options.classe !== undefined ? { classe: options.classe } : {}),
            ...(options.indirizzo !== undefined ? { indirizzo: options.indirizzo } : {}),
            ...(options.expiresAt !== undefined ? { expires_at: options.expiresAt } : {}),
            ...(options.materie !== undefined ? { materie: options.materie } : {}),
        });
        const id = Number(result.body?.id ?? 0);
        const label = String(result.body?.label ?? "");
        if (!result.ok || !result.body?.ok || id <= 0 || label === "") {
            throw new Error(`[credential.factory] creazione fallita (${result.status}): ${result.text.slice(0, 200)}`);
        }
        this.cleanup.add(this.owner, `cancella la credenziale «${label}»`, () => this.api.delete(id));
        return { id, label, username, password, aggiunta };
    }
}
