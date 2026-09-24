/**
 * Client delle fonti del docente (StudySourcesController).
 *
 * Responsabilità: leggere e riscrivere il registro dei libri da cui il docente
 * dice di aver preso i quesiti. È il registro che dà la citazione al cartellino
 * del quesito: senza, il cartellino porta solo numero, pagina e difficoltà.
 *
 * Può importare: http, types. Non può importare: pagine, fixture, factory.
 */
import type { HttpClient } from "./http";

export interface Source {
    readonly key: string;
    readonly book?: string;
    readonly volume?: string;
    readonly authors?: string;
    /** Presenti quando la fonte viene dal catalogo delle adozioni (ADR-036). */
    readonly isbn?: string;
    readonly editore?: string;
}

export interface SourcesRegistry {
    readonly sources?: readonly Source[];
}

/** `GET /api/sources/common` → `{ sources: { <codice>: {book, volume, authors} } }`. */
export interface CommonSources {
    readonly sources?: Readonly<Record<string, Omit<Source, "key">>>;
}

export class SourcesApi {
    constructor(private readonly http: HttpClient) {}

    /** Il registro personale: quello che il cartellino consulta. */
    async registry(): Promise<SourcesRegistry> {
        return this.http.getJson<SourcesRegistry>("/api/teacher/sources.registry.json");
    }

    async saveRegistry(sources: readonly Source[]): Promise<unknown> {
        return this.http.putJson("/api/teacher/sources.registry.json", { sources });
    }

    /** I libri già noti all'applicazione, fra cui scegliere. */
    async common(): Promise<CommonSources> {
        return this.http.getJson<CommonSources>("/api/sources/common");
    }
}
