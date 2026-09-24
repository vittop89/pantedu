/**
 * Client di /api/teacher/content/* (contenuti del docente e loro contratto).
 *
 * Responsabilità: le sole chiamate che le spec usano, con i campi che i
 * controller leggono davvero (TeacherContentController::store, GroupController::groupAdd,
 * QuesitoController::quesitoDuplicate, ContentPublishController, ContentExportController).
 * Nessuna logica di test: chi crea dati e li cancella è la factory.
 *
 * Può importare: http, types. Non può importare: pagine, fixture, factory.
 */
import type { APIResponse } from "@playwright/test";
import type { ApiResult, HttpClient } from "./http";
import type {
    ContentCreated,
    ContentDetail,
    ContentList,
    ContractData,
    ExportResult,
    GroupAdded,
    OkResponse,
    SharePoolResult,
    VisibilitySet,
} from "./types";

export interface CreateContentFields {
    /** esercizio, verifica, document, mappa (validato dall'app contro la sezione). */
    readonly type: string;
    readonly subject: string;
    readonly indirizzo?: string;
    readonly classe?: string;
    /** Chiave della sezione della sidebar (es. `eser`): senza, il sidepage non elenca il contenuto (ADR-027). */
    readonly section_key?: string;
    readonly topic: string;
    readonly title: string;
    readonly body_html?: string;
    readonly visibility?: "draft" | "published";
    /** Serializzato in JSON nel campo `metadata` (es. `{ body_pt }` per i documenti). */
    readonly metadata?: Readonly<Record<string, unknown>>;
}

export interface AddGroupFields {
    /** Tipo del gruppo, es. `type_Collect` (default dell'app: Collect). */
    readonly type?: string;
    readonly title?: string;
    readonly intro?: string;
    readonly clientId?: string;
}

export interface ContentListParams {
    readonly type?: string;
    readonly subject?: string;
    readonly q?: string;
    readonly limit?: number;
}

export class TeacherContentApi {
    constructor(private readonly http: HttpClient) {}

    async create(fields: CreateContentFields): Promise<ContentCreated> {
        const form: Record<string, string> = {
            type: fields.type,
            subject: fields.subject,
            topic: fields.topic,
            title: fields.title,
            visibility: fields.visibility ?? "draft",
        };
        if (fields.indirizzo !== undefined) form["indirizzo"] = fields.indirizzo;
        if (fields.classe !== undefined) form["classe"] = fields.classe;
        if (fields.section_key !== undefined) form["section_key"] = fields.section_key;
        if (fields.body_html !== undefined) form["body_html"] = fields.body_html;
        if (fields.metadata !== undefined) form["metadata"] = JSON.stringify(fields.metadata);
        return this.http.postForm<ContentCreated>("/api/teacher/content", form);
    }

    async get(id: number): Promise<ContentDetail> {
        return this.http.getJson<ContentDetail>(`/api/teacher/content/${id}`);
    }

    /**
     * Metadati del contenuto già decodificati.
     *
     * La risposta li dà a volte come oggetto e a volte come stringa JSON
     * (campo `metadata` o `metadata_json`, a seconda del percorso che l'ha
     * prodotta): le spec storiche ripetevano ognuna la stessa decodifica con
     * il suo `try/catch`.
     */
    async metadata(id: number): Promise<Record<string, unknown>> {
        const dettaglio = await this.get(id);
        const contenuto = dettaglio.content as unknown as Record<string, unknown>;
        for (const chiave of ["metadata", "metadata_json"]) {
            const valore = contenuto[chiave];
            if (valore && typeof valore === "object") return valore as Record<string, unknown>;
            if (typeof valore === "string" && valore !== "") {
                try {
                    const decodificato: unknown = JSON.parse(valore);
                    if (decodificato && typeof decodificato === "object") return decodificato as Record<string, unknown>;
                } catch {
                    // Prova con l'altro campo: se nessuno dei due è leggibile lo dice il lancio finale.
                }
            }
        }
        throw new Error(`[content] il contenuto ${id} non ha metadati leggibili`);
    }

    /**
     * Modifica i campi di un contenuto. Serve soprattutto a categorizzare una
     * copia recuperata dal pool: il recupero le assegna solo la materia e la
     * lascia senza indirizzo né classe, fra i contenuti «da categorizzare».
     */
    async update(id: number, fields: Partial<Pick<CreateContentFields, "subject" | "indirizzo" | "classe" | "topic" | "title" | "visibility">>): Promise<OkResponse> {
        const form: Record<string, string> = {};
        for (const [chiave, valore] of Object.entries(fields)) {
            if (valore !== undefined) form[chiave] = String(valore);
        }
        return this.http.postForm<OkResponse>(`/api/teacher/content/${id}/update`, form);
    }

    /**
     * Riscrive i metadati del contenuto (`body_pt` compreso) passando dal
     * server, non dalla pagina: serve a cambiare un documento «da fuori»
     * mentre il browser lo tiene aperto, per vedere che cosa legge il browser
     * subito dopo.
     */
    async updateMetadata(id: number, metadata: Readonly<Record<string, unknown>>): Promise<OkResponse> {
        return this.http.postForm<OkResponse>(`/api/teacher/content/${id}/update`, {
            metadata: JSON.stringify(metadata),
        });
    }

    async list(params: ContentListParams = {}): Promise<ContentList> {
        const query: Record<string, string> = {};
        if (params.type !== undefined) query["type"] = params.type;
        if (params.subject !== undefined) query["subject"] = params.subject;
        if (params.q !== undefined) query["q"] = params.q;
        if (params.limit !== undefined) query["limit"] = String(params.limit);
        return this.http.getJson<ContentList>("/api/teacher/content", query);
    }

    /** Cancellazione senza lancio: 404 vuol dire già assente, utile nella pulizia. */
    async delete(id: number): Promise<ApiResult<OkResponse>> {
        return this.http.send<OkResponse>("POST", `/api/teacher/content/${id}/delete`, { form: {} });
    }

    async publish(id: number): Promise<VisibilitySet> {
        return this.http.postForm<VisibilitySet>(`/api/teacher/content/${id}/publish`, {});
    }

    async addGroup(id: number, fields: AddGroupFields = {}): Promise<GroupAdded> {
        const form: Record<string, string> = {};
        if (fields.type !== undefined) form["type"] = fields.type;
        if (fields.title !== undefined) form["title"] = fields.title;
        if (fields.intro !== undefined) form["intro"] = fields.intro;
        if (fields.clientId !== undefined) form["clientId"] = fields.clientId;
        return this.http.postForm<GroupAdded>(`/api/teacher/content/${id}/group/add`, form);
    }

    /**
     * Copia del quesito subito dopo quello indicato. `itemRef` accetta l'id
     * del quesito, `{groupId}_q{indice}` o `g{gruppo}_q{indice}`
     * (ContractAggregate::findItemIndex).
     */
    async duplicateQuesito(id: number, itemRef: string): Promise<OkResponse> {
        return this.http.postForm<OkResponse>(`/api/teacher/content/${id}/quesito/${encodeURIComponent(itemRef)}/duplicate`, {});
    }

    /**
     * Modifica i campi di un quesito già esistente. `itemRef` è lo stesso
     * locator di `duplicateQuesito`; i nomi dei campi sono quelli
     * dell'allowlist del controller (`quesito`, `soluzione`, `points`, ...).
     */
    async patchQuesito(id: number, itemRef: string, fields: Readonly<Record<string, string>>): Promise<OkResponse> {
        return this.http.postForm<OkResponse>(
            `/api/teacher/content/${id}/quesito/${encodeURIComponent(itemRef)}/patch`,
            { ...fields },
        );
    }

    async contract(id: number): Promise<ContractData> {
        return this.http.getJson<ContractData>(`/api/teacher/content/${id}/contract`);
    }

    /** Flag «condiviso nel pool»; senza lancio perché il blocco copyright risponde 4xx. */
    async setPoolSharing(id: number, enabled: boolean): Promise<ApiResult<SharePoolResult>> {
        return this.http.send<SharePoolResult>("POST", `/api/teacher/content/${id}/share-pool`, {
            form: { enabled: enabled ? "1" : "0" },
        });
    }

    async export(id: number): Promise<APIResponse> {
        // Dal 21/9/2026 il pacchetto arriva nel corpo, non per indirizzo.
        return this.http.postRaw(`/api/teacher/content/${id}/export`);
    }
}
