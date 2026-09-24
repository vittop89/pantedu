/**
 * Client di /api/teacher/credentials: le credenziali di classe del docente.
 *
 * Negli scenari 1 e 2 gli studenti non hanno un account: entrano con una
 * credenziale che il docente crea, legata alla sua classe o a tutte. Il
 * docente la elenca, la crea, la spegne e la cancella da qui; lo studente la
 * usa da /accesso-classe (client `access`).
 *
 * Dal 19 settembre 2026 (ADR-044) l'etichetta la compone il server — classe,
 * indirizzo, materie scelte fra quelle spuntate dal docente, aggiunta
 * facoltativa — e la restituisce nella risposta della creazione: chi crea non
 * la inventa, la legge.
 *
 * Può importare: http. Non può importare: pagine, fixture, factory.
 */
import type { ApiResult, HttpClient } from "./http";

/** Una riga di /api/teacher/credentials. I numeri arrivano dal DB anche come stringhe. */
export interface ClassCredential {
    readonly id: number | string;
    readonly label: string;
    /** Sigle separate da virgola; null per un'etichetta libera di prima del 19/9/2026. */
    readonly materie: string | null;
    readonly aggiunta: string | null;
    readonly access_username: string;
    readonly indirizzo: string | null;
    readonly classe: string | null;
    readonly institute_id: number | string | null;
    readonly active: number | string;
    readonly expires_at: string | null;
    readonly last_used_at: string | null;
    readonly use_count: number | string;
}

/** Una materia spuntata dal docente nell'istituto: la può mettere nell'etichetta. */
export interface MateriaDelDocente {
    readonly code: string;
    readonly label: string;
}

export interface CreateCredentialInput {
    readonly username: string;
    readonly password: string;
    /** Codice breve della classe (es. "2"); assente = tutte le classi del docente. */
    readonly classe?: string;
    readonly indirizzo?: string;
    readonly institute_id?: string;
    /** YYYY-MM-DD; assente = il 31 agosto dell'anno scolastico in corso. */
    readonly expires_at?: string;
    /** Sigle delle materie dell'etichetta, fra quelle spuntate dal docente. */
    readonly materie?: readonly string[];
    /** Aggiunta facoltativa all'etichetta: lettere, cifre, trattino, al massimo 12. */
    readonly aggiunta?: string;
}

export interface CreateCredentialResult {
    readonly ok?: boolean;
    readonly id?: number;
    /** L'etichetta composta dal server. */
    readonly label?: string;
    readonly error?: string;
    /** Non deve esserci: il testo del database resta nel registro del server. */
    readonly detail?: string;
}

export interface RelabelResult {
    readonly ok?: boolean;
    readonly label?: string;
    readonly error?: string;
}

interface CredentialList {
    readonly ok: boolean;
    readonly credentials: readonly ClassCredential[];
    readonly institute_id: number | null;
    readonly materie_disponibili: readonly MateriaDelDocente[];
}

export class CredentialsApi {
    constructor(private readonly http: HttpClient) {}

    async list(): Promise<readonly ClassCredential[]> {
        return (await this.http.getJson<CredentialList>("/api/teacher/credentials")).credentials;
    }

    /** Le materie spuntate dal docente nell'istituto in cui lavora: le sigle ammesse nell'etichetta. */
    async materieDisponibili(): Promise<readonly MateriaDelDocente[]> {
        return (await this.http.getJson<CredentialList>("/api/teacher/credentials")).materie_disponibili;
    }

    /** Crea senza lanciare per lo stato: i casi che provano un rifiuto leggono `status`. */
    async create(input: CreateCredentialInput): Promise<ApiResult<CreateCredentialResult>> {
        const form: Record<string, string> = {
            username: input.username,
            password: input.password,
        };
        if (input.classe !== undefined) form["classe"] = input.classe;
        if (input.indirizzo !== undefined) form["indirizzo"] = input.indirizzo;
        if (input.institute_id !== undefined) form["institute_id"] = input.institute_id;
        if (input.expires_at !== undefined) form["expires_at"] = input.expires_at;
        if (input.materie !== undefined) form["materie"] = input.materie.join(",");
        if (input.aggiunta !== undefined) form["aggiunta"] = input.aggiunta;
        return this.http.send<CreateCredentialResult>("POST", "/api/teacher/credentials", { form });
    }

    /** Ricompone l'etichetta con le materie e l'aggiunta date; non lancia per lo stato. */
    async relabel(id: number, materie: readonly string[], aggiunta = ""): Promise<ApiResult<RelabelResult>> {
        return this.http.send<RelabelResult>("POST", `/api/teacher/credentials/${id}/etichetta`, {
            form: { materie: materie.join(","), aggiunta },
        });
    }

    async toggle(id: number, active: boolean): Promise<{ ok: boolean; active: boolean }> {
        return this.http.postForm(`/api/teacher/credentials/${id}/toggle`, { active: active ? "1" : "" });
    }

    async delete(id: number): Promise<void> {
        await this.http.postForm<{ ok: boolean }>(`/api/teacher/credentials/${id}/delete`, {});
    }
}
