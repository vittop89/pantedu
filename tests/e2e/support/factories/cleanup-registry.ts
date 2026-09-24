/**
 * Registro di pulizia: ogni risorsa creata da un test ha chi la cancella.
 *
 * Responsabilità: raccogliere le azioni di pulizia con il ruolo che le deve
 * eseguire (le richieste passano dalla sessione di quel ruolo) ed eseguirle
 * in ordine inverso nel teardown della fixture di quel ruolo, anche se il
 * test è fallito. Un'azione fallita non ferma le altre: gli errori tornano
 * al chiamante, che li allega al report e fa fallire il test (mai in silenzio).
 *
 * Può importare: env (solo il tipo Role). Non può importare: api, pagine, fixture.
 */
import type { Role } from "../env";

export interface CleanupEntry {
    readonly owner: Role;
    readonly description: string;
    readonly run: () => Promise<void>;
}

export interface CleanupFailure {
    readonly description: string;
    readonly error: string;
}

export class CleanupRegistry {
    private readonly entries: CleanupEntry[] = [];

    /** Registra un'azione; `owner` è il ruolo la cui sessione la eseguirà. */
    add(owner: Role, description: string, run: () => Promise<void>): void {
        this.entries.push({ owner, description, run });
    }

    /** Azioni ancora da eseguire (tutte, o solo quelle di un ruolo). */
    pending(owner?: Role): readonly CleanupEntry[] {
        return this.entries.filter((e) => owner === undefined || e.owner === owner);
    }

    /** Esegue in ordine inverso le azioni del ruolo; restituisce i fallimenti. */
    async runFor(owner: Role): Promise<CleanupFailure[]> {
        const failures: CleanupFailure[] = [];
        const mine = this.entries.filter((e) => e.owner === owner).reverse();
        for (const entry of mine) {
            const index = this.entries.indexOf(entry);
            if (index >= 0) this.entries.splice(index, 1);
            try {
                await entry.run();
            } catch (error) {
                failures.push({
                    description: entry.description,
                    error: error instanceof Error ? error.message : String(error),
                });
            }
        }
        return failures;
    }
}
