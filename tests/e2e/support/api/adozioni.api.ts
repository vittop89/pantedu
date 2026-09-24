/**
 * Client del catalogo dei libri in adozione (ADR-036).
 *
 * Il docente legge i libri delle proprie classi e materie da
 * /api/teacher/adozioni; l'amministratore ne aggiunge o toglie uno a mano da
 * /api/admin/adozioni — la strada che la suite usa per mettere un libro nel
 * catalogo senza caricare un dataset da cinquanta megabyte.
 *
 * Può importare: http. Non può importare: pagine, fixture, factory.
 */
import type { ApiResult, HttpClient } from "./http";

export interface LibroInAdozione {
    readonly id: number;
    readonly anno_scolastico: string;
    readonly classe: string;
    readonly materia: string | null;
    readonly disciplina: string;
    readonly isbn: string;
    readonly titolo: string;
    readonly autori: string | null;
    readonly editore: string | null;
    readonly volume: string | null;
    readonly in_registro: boolean;
    readonly proposta: { readonly key: string; readonly book: string; readonly volume: string; readonly authors: string; readonly isbn: string };
}

export interface AdozioniDelDocente {
    readonly ok: boolean;
    readonly institute_id: number;
    readonly classi_mie: readonly string[];
    readonly materie_mie: readonly string[];
    readonly senza_spunte: boolean;
    readonly catalogo_vuoto: boolean;
    readonly libri: readonly LibroInAdozione[];
}

export interface NuovoLibro {
    readonly institute_id: number;
    readonly anno_scolastico: string;
    readonly classe: string;
    readonly disciplina: string;
    readonly isbn: string;
    readonly titolo: string;
    readonly materia?: string;
    readonly indirizzo?: string;
    readonly autori?: string;
    readonly editore?: string;
    readonly volume?: string;
}

export class AdozioniDocenteApi {
    constructor(private readonly http: HttpClient) {}

    /** I libri delle classi e materie spuntate; `tutte` ignora le spunte. */
    async mie(params: { classe?: string; materia?: string; tutte?: boolean } = {}): Promise<AdozioniDelDocente> {
        const query: Record<string, string> = {};
        if (params.classe) query["classe"] = params.classe;
        if (params.materia) query["materia"] = params.materia;
        if (params.tutte) query["tutte"] = "1";
        return this.http.getJson<AdozioniDelDocente>("/api/teacher/adozioni", query);
    }
}

export class AdozioniAdminApi {
    constructor(private readonly http: HttpClient) {}

    async create(libro: NuovoLibro): Promise<ApiResult<{ ok?: boolean; id?: number; error?: string }>> {
        return this.http.send("POST", "/api/admin/adozioni", { json: libro });
    }

    async delete(id: number): Promise<void> {
        await this.http.postForm<{ ok: boolean }>(`/api/admin/adozioni/${id}/delete`, {});
    }
}
