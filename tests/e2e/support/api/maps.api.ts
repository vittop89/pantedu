/**
 * Client delle mappe concettuali (MapsController).
 *
 * Responsabilità: creare una mappa in formato drawio nativo, leggerne il
 * disegno dal link firmato e salvarlo come fa l'editor. Il disegno viene
 * cifrato con la chiave del docente e salvato fuori dal database: la risposta
 * riporta il percorso del blob, che comincia con l'identificativo del
 * proprietario. Una mappa recuperata dal pool deve avere un blob proprio.
 *
 * Può importare: http, types. Non può importare: pagine, fixture, factory.
 */
import type { ApiResult, HttpClient } from "./http";
import type { MapCreated, MapSignedUrl, MapUpdated } from "./types";

export interface CreateMapFields {
    readonly xml: string;
    readonly title: string;
    readonly topic: string;
    readonly subject: string;
    readonly indirizzo: string;
    readonly classe: string;
    readonly visibility?: "draft" | "published";
}

export class MapsApi {
    constructor(private readonly http: HttpClient) {}

    async createDrawio(fields: CreateMapFields): Promise<MapCreated> {
        return this.http.postForm<MapCreated>("/api/maps", {
            mode: "drawio_native",
            xml: fields.xml,
            title: fields.title,
            topic: fields.topic,
            subject: fields.subject,
            indirizzo: fields.indirizzo,
            classe: fields.classe,
            visibility: fields.visibility ?? "published",
        });
    }

    /**
     * Il disegno come lo legge il visualizzatore: link firmato, poi i byte.
     * Si restituiscono i byte, non il testo: una lettera accentata persa si
     * vede solo confrontando i byte.
     */
    async scarica(id: number): Promise<Buffer> {
        const firmato = await this.http.getJson<MapSignedUrl>(`/api/maps/${id}/signed-url`, { mode: "view" });
        const risposta = await this.http.request.get(firmato.url);
        if (!risposta.ok()) {
            throw new Error(`[maps] GET del link firmato della mappa ${id} → ${risposta.status()}`);
        }
        return risposta.body();
    }

    /**
     * Il salvataggio dell'editor (POST /api/maps/{id}/update). Non lancia sullo
     * stato: chi chiama controlla 200 o 409.
     */
    async aggiorna(id: number, xml: string, mapVersion: number): Promise<ApiResult<MapUpdated>> {
        return this.http.send<MapUpdated>("POST", `/api/maps/${id}/update`, {
            form: { xml, map_version: String(mapVersion) },
        });
    }
}
