/**
 * Factory delle verifiche salvate via /api/verifica/save-tex-batch.
 *
 * Responsabilità: payload di default identico a quello delle spec storiche
 * (verifica_tex_production), titolo unico per giro, cancellazione di ogni
 * variante prodotta registrata nel teardown. Sostituisce `deleteAllVerifiche`,
 * che voleva cancellare tutte le verifiche del docente (e per un refuso non
 * ne cancellava nessuna).
 *
 * Può importare: api, cleanup-registry, naming, env (tipi). Non può importare: pagine, fixture.
 */
import type { Role } from "../env";
import type { SaveTexBatchPayload, VerificaApi } from "../api/verifica.api";
import type { VerificaBatchResult } from "../api/types";
import type { CleanupRegistry } from "./cleanup-registry";
import type { Naming } from "./naming";

export interface BatchOptions {
    /** Titolo (e `verTitle`); default unico. */
    readonly title?: string;
    readonly versionLabel?: string;
    /** Sottoinsieme di varianti, es. `["A"]`. */
    readonly versions?: readonly string[];
    readonly force?: boolean;
    /** Campi da sovrascrivere nel payload di default. */
    readonly overrides?: Partial<SaveTexBatchPayload>;
}

export class VerificaFactory {
    constructor(
        private readonly api: VerificaApi,
        private readonly cleanup: CleanupRegistry,
        private readonly owner: Role,
        private readonly naming: Naming,
    ) {}

    /** Payload di default: una verifica MAT ar/2s con un gruppo di due quesiti, 8 varianti. */
    basePayload(overrides: Partial<SaveTexBatchPayload> = {}): SaveTexBatchPayload {
        const title = overrides.title ?? overrides.verTitle ?? this.naming.unique("verifica");
        return {
            version: "A",
            verTitle: title,
            selectedIIS: "ar",
            selectedCLS: "2s",
            selectedMATER: "MAT",
            anno: "2025-26",
            sezione: "B",
            problems: [
                {
                    filePath: "/eser/ar/ar2s/MAT/1",
                    problemId: "problem-200",
                    position: 1,
                    type: "Collect",
                    text: "Calcola le seguenti espressioni:",
                    items: [
                        { html: "Esercizio 1: \\(x^2 + 1\\)", points: 4.0, includeSolution: false },
                        { html: "Esercizio 2: \\(\\sin(\\pi)\\)", points: 3.0, includeSolution: false },
                    ],
                },
            ],
            materia: "MAT",
            title,
            dsa: true,
            compensa: true,
            includeGriglia: true,
            includeMisure: true,
            nPrint: 25,
            nPrintDSA: 1,
            nPrintDIS: 1,
            tipologia: "scritto",
            ...overrides,
        };
    }

    /** Registra la cancellazione di varianti salvate direttamente dal test. */
    registerDeletion(ids: readonly number[], label = "verifica"): void {
        for (const id of ids) {
            this.cleanup.add(this.owner, `cancella ${label} ${id}`, async () => {
                const result = await this.api.delete(id);
                if (!result.ok && result.status !== 404) {
                    throw new Error(`POST /api/verifica/${id}/delete → ${result.status}: ${result.text.slice(0, 200)}`);
                }
            });
        }
    }

    /** Salva un batch di varianti e ne registra la cancellazione. */
    async batch(options: BatchOptions = {}): Promise<VerificaBatchResult> {
        const overrides: Partial<SaveTexBatchPayload> = { ...options.overrides };
        if (options.title !== undefined) Object.assign(overrides, { title: options.title, verTitle: options.title });
        if (options.versionLabel !== undefined) Object.assign(overrides, { version_label: options.versionLabel });
        if (options.versions !== undefined) Object.assign(overrides, { versions: options.versions });
        const payload = this.basePayload(overrides);
        const result = await this.api.saveTexBatchOk(payload, { force: options.force ?? false });
        this.registerDeletion(result.docs.map((d) => d.id), `variante di «${payload.title}»`);
        return result;
    }
}
