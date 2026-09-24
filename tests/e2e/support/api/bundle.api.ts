/**
 * Client della chiave di recupero e del pacchetto di sincronizzazione
 * (TeacherSyncController, ImportBundleController).
 *
 * Responsabilità: generare, revocare e interrogare la chiave di recupero del
 * docente; scaricare il manifesto firmato dei propri contenuti; provare
 * l'importazione di un pacchetto altrui senza scrivere nulla (anteprima).
 * La firma del manifesto è verificata con la chiave di recupero di chi ha
 * esportato: con una chiave sbagliata l'importazione va rifiutata.
 *
 * Può importare: http, types. Non può importare: pagine, fixture, factory.
 */
import type { ApiResult, HttpClient } from "./http";
import type { ImportPreview, OkResponse, RecoveryKeyGenerated, RecoveryKeyStatus, SyncManifest, SyncManifestResponse } from "./types";

export type ConflictStrategy = "rename" | "skip" | "overwrite";

export class BundleApi {
    constructor(private readonly http: HttpClient) {}

    async recoveryKeyStatus(): Promise<RecoveryKeyStatus> {
        return this.http.getJson<RecoveryKeyStatus>("/api/teacher/recovery-key/status");
    }

    async generateRecoveryKey(): Promise<RecoveryKeyGenerated> {
        return this.http.postJson<RecoveryKeyGenerated>("/api/teacher/recovery-key/generate", {});
    }

    /** Senza lancio: revocare una chiave che non c'è non è un errore per il test. */
    async revokeRecoveryKey(): Promise<ApiResult<OkResponse>> {
        return this.http.send<OkResponse>("POST", "/api/teacher/recovery-key/revoke", { json: {} });
    }

    async manifest(): Promise<SyncManifest> {
        return (await this.http.getJson<SyncManifestResponse>("/api/teacher/sync-bundle/manifest")).manifest;
    }

    /**
     * Elenco dei file del pacchetto per la cartella locale, tutte le pagine.
     *
     * La rotta risponde a blocchi: chi la usa deve seguire `hasMore` finché non
     * finisce. I modelli stanno in coda, dopo i contenuti del docente, quindi
     * una lettura parziale non li vedrebbe.
     */
    async localBundlePaths(pageSize = 50): Promise<readonly string[]> {
        const percorsi: string[] = [];
        let offset = 0;
        for (;;) {
            const pagina = await this.http.getJson<{
                files?: readonly { path?: string }[];
                hasMore?: boolean;
                limit?: number;
            }>("/api/teacher/sync-local-bundle", { offset: String(offset), limit: String(pageSize) });
            for (const file of pagina.files ?? []) {
                if (file.path) percorsi.push(file.path);
            }
            if (!pagina.hasMore) return percorsi;
            // Il server ha un tetto suo alla dimensione della pagina (50): si
            // avanza di quanto ha davvero risposto, non di quanto si è chiesto,
            // altrimenti si saltano dei file senza accorgersene.
            offset += pagina.limit ?? pageSize;
            if (offset > 20_000) {
                throw new Error("[bundle] il pacchetto locale non finisce mai: più di ventimila file");
            }
        }
    }

    /** Stato del collegamento con GitHub per la sincronizzazione dei file. */
    async githubStatus(): Promise<{ readonly ok?: boolean; readonly configured?: boolean }> {
        return this.http.getJson<{ ok?: boolean; configured?: boolean }>("/api/teacher/github/status");
    }

    /**
     * Anteprima dell'importazione: nessun file allegato, quindi non scrive
     * niente e riporta solo che cosa creerebbe o dove troverebbe conflitti.
     * Senza lancio: con una chiave sbagliata la risposta è 403.
     */
    async previewImport(options: {
        readonly recoveryCode: string;
        readonly manifest: SyncManifest;
        readonly conflictStrategy: ConflictStrategy;
    }): Promise<ApiResult<ImportPreview>> {
        return this.http.send<ImportPreview>("POST", "/api/teacher/import-bundle/preview", {
            json: {
                recovery_code: options.recoveryCode,
                manifest: options.manifest,
                files: [],
                conflict_strategy: options.conflictStrategy,
            },
        });
    }
}
