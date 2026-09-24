/**
 * Client delle informazioni di stampa di una verifica (PrintInfoController).
 *
 * Sono i dati di intestazione che il docente riusa fra una verifica e l'altra:
 * Istituto, anno, sezione, numero di copie. Si identificano con una chiave
 * costruita dai campi (`ar_9_MAT_Z_TestInst`); i salvataggi vecchi hanno una
 * chiave a tre campi, e la cancellazione deve funzionare per entrambe le forme.
 *
 * Può importare: http, types. Non può importare: pagine, fixture, factory.
 */
import type { ApiResult, HttpClient } from "./http";
import type { OkResponse } from "./types";

export interface PrintInfoFields {
    readonly indirizzo: string;
    readonly classe: string;
    readonly materia: string;
    readonly sezione?: string;
    readonly istituto?: string;
    readonly anno?: string;
    readonly nPrint?: string;
    readonly nPrintDSA?: string;
    readonly nPrintDIS?: string;
    readonly verTime?: string;
    readonly addressSchool?: string;
}

export interface PrintInfoSaved extends OkResponse {
    readonly key: string;
}

export interface PrintInfoItem {
    readonly page_key: string;
    readonly indirizzo?: string;
    readonly classe?: string;
    readonly materia?: string;
    readonly sezione?: string;
    readonly istituto?: string;
    readonly anno?: string;
    readonly verTime?: string;
    readonly nPrint?: string;
    readonly nPrintDSA?: string;
    readonly nPrintDIS?: string;
}

export interface PrintInfoList extends OkResponse {
    readonly items: readonly PrintInfoItem[];
}

export interface PrintInfoDeleted extends OkResponse {
    readonly deleted: boolean;
}

export class PrintInfoApi {
    constructor(private readonly http: HttpClient) {}

    async save(fields: PrintInfoFields): Promise<PrintInfoSaved> {
        return this.http.postJson<PrintInfoSaved>("/api/teacher/print-info", fields);
    }

    async list(): Promise<PrintInfoList> {
        return this.http.getJson<PrintInfoList>("/api/teacher/print-info/list");
    }

    /** Il salvataggio con quella sezione, se c'è: è così che i test lo ritrovano. */
    async findBySezione(sezione: string): Promise<PrintInfoItem | undefined> {
        return (await this.list()).items.find((r) => r.sezione === sezione);
    }

    /** Cancella per chiave; senza lancio, così i test possono verificare l'esito. */
    async delete(pageKey: string): Promise<ApiResult<PrintInfoDeleted>> {
        return this.http.send<PrintInfoDeleted>("POST", "/api/teacher/print-info/delete", {
            json: { page_key: pageKey },
        });
    }
}
