/**
 * Client di /api/teacher/share/* e /api/teacher/pool/* (ShareGrantsController, PoolController).
 *
 * Responsabilità: grant espliciti su un contenuto, gruppi di colleghi,
 * elenco dei colleghi, materiali visibili nel pool. I corpi sono JSON
 * (`readJsonBody` nel controller).
 *
 * Può importare: http, types. Non può importare: pagine, fixture, factory.
 */
import type { ApiResult, HttpClient } from "./http";
import type {
    Colleagues,
    GrantTarget,
    GrantsSet,
    GroupCreated,
    MembersSet,
    MyShares,
    OkResponse,
    PoolMaterials,
    RecoverResult,
    ShareRef,
    UnshareResult,
} from "./types";

export type GrantSource = "teacher_content" | "verifica_documents";

export interface PoolMaterialsParams {
    readonly content_type?: string;
    readonly subject_code?: string;
    readonly owner_id?: number;
}

export class ShareApi {
    constructor(private readonly http: HttpClient) {}

    /** Sostituisce i grant del contenuto; senza lancio perché il blocco copyright risponde 4xx. */
    async setGrants(source: GrantSource, id: number, grants: readonly GrantTarget[]): Promise<ApiResult<GrantsSet>> {
        return this.http.send<GrantsSet>("POST", `/api/teacher/share/grants/${source}/${id}`, { json: { grants } });
    }

    async createGroup(name: string, description?: string): Promise<GroupCreated> {
        return this.http.postJson<GroupCreated>("/api/teacher/share/groups", description === undefined ? { name } : { name, description });
    }

    async setMembers(groupId: number, memberIds: readonly number[]): Promise<MembersSet> {
        return this.http.postJson<MembersSet>(`/api/teacher/share/groups/${groupId}/members`, { member_ids: memberIds });
    }

    async deleteGroup(groupId: number): Promise<ApiResult<OkResponse>> {
        return this.http.send<OkResponse>("POST", `/api/teacher/share/groups/${groupId}/delete`, { json: {} });
    }

    async colleagues(): Promise<Colleagues> {
        return this.http.getJson<Colleagues>("/api/teacher/share/colleagues");
    }

    async poolMaterials(params: PoolMaterialsParams = {}): Promise<PoolMaterials> {
        const query: Record<string, string> = {};
        if (params.content_type !== undefined) query["content_type"] = params.content_type;
        if (params.subject_code !== undefined) query["subject_code"] = params.subject_code;
        if (params.owner_id !== undefined) query["owner_id"] = String(params.owner_id);
        return this.http.getJson<PoolMaterials>("/api/teacher/pool/materials", query);
    }

    /** Contenuti che il docente ha condiviso nel pool. */
    async myShares(): Promise<MyShares> {
        return this.http.getJson<MyShares>("/api/teacher/pool/my-shares");
    }

    /** Ritira in blocco la condivisione dei contenuti indicati. */
    async unshare(items: readonly ShareRef[]): Promise<UnshareResult> {
        return this.http.postJson<UnshareResult>("/api/teacher/pool/unshare", { items });
    }

    /**
     * Recupera dal pool una copia del contenuto altrui.
     * `targetSubjectId` è l'identificativo della materia *di chi recupera*
     * con lo stesso codice dell'originale (vedi CurriculumApi.subjectId).
     * Senza lancio: il recupero può essere rifiutato (già recuperato, blob mancante).
     */
    async recoverFromPool(id: number, targetSubjectId: number): Promise<ApiResult<RecoverResult>> {
        return this.http.send<RecoverResult>("POST", `/api/teacher/pool/recover/${id}`, {
            json: { target_subject_id: targetSubjectId },
        });
    }
}
