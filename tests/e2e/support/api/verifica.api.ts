/**
 * Client di /api/verifica/* (VerificaController, VerificaCompileController).
 *
 * Responsabilità: salvataggio delle varianti TeX (batch e singolo), elenco,
 * cancellazione, PDF allegato, compilazione, lettura dei .tex. Il payload del
 * batch è quello che le spec mandano oggi (verifica_tex_production,
 * g20_02_zip_compile): nessun campo inventato.
 *
 * Può importare: http, types. Non può importare: pagine, fixture, factory.
 */
import type { ApiResult, HttpClient } from "./http";
import type { CompileResult, OkResponse, TexFile, TexFiles, TexFilesSaved, VerificaBatchResult, VerificaConflict, VerificaList } from "./types";

export interface VerificaProblemItem {
    readonly html: string;
    readonly points: number;
    readonly includeSolution: boolean;
    readonly solution?: string;
}

export interface VerificaProblem {
    readonly filePath: string;
    readonly problemId: string;
    readonly position: number;
    readonly type: string;
    readonly text: string;
    readonly items: readonly VerificaProblemItem[];
}

/** Campi letti da VerificaController::saveTexBatch, come nelle spec storiche. */
export interface SaveTexBatchPayload {
    readonly version: string;
    readonly verTitle: string;
    readonly selectedIIS: string;
    readonly selectedCLS: string;
    readonly selectedMATER: string;
    readonly anno: string;
    readonly sezione: string;
    readonly problems: readonly VerificaProblem[];
    readonly materia: string;
    readonly title: string;
    readonly dsa: boolean;
    readonly compensa: boolean;
    readonly includeGriglia: boolean;
    readonly includeMisure: boolean;
    readonly nPrint: number;
    readonly nPrintDSA: number;
    readonly nPrintDIS: number;
    readonly tipologia: string;
    /** Sottoinsieme di varianti, es. `["A"]`; assente = tutte. */
    readonly versions?: readonly string[];
    readonly version_label?: string;
    readonly indirizzo?: string;
    readonly classe?: string;
    readonly options?: Readonly<Record<string, unknown>>;
}

export interface SaveBatchOptions {
    /** `?force=1`: sovrascrive titolo e versione già esistenti invece del 409. */
    readonly force?: boolean;
}

export class VerificaApi {
    constructor(private readonly http: HttpClient) {}

    /** Salvataggio del batch senza lancio: il 409 di conflitto è un esito che i test verificano. */
    async saveTexBatch(payload: SaveTexBatchPayload, options: SaveBatchOptions = {}): Promise<ApiResult<VerificaBatchResult | VerificaConflict>> {
        const url = "/api/verifica/save-tex-batch" + (options.force ? "?force=1" : "");
        return this.http.send<VerificaBatchResult | VerificaConflict>("POST", url, { json: payload });
    }

    /** Come sopra, ma fallisce se il salvataggio non riesce (uso delle factory). */
    async saveTexBatchOk(payload: SaveTexBatchPayload, options: SaveBatchOptions = {}): Promise<VerificaBatchResult> {
        const result = await this.saveTexBatch(payload, options);
        const body = result.body;
        if (!result.ok || body === null || !("docs" in body)) {
            throw new Error(`[api] POST /api/verifica/save-tex-batch → ${result.status}: ${result.text.slice(0, 300)}`);
        }
        return body;
    }

    /**
     * Salvataggio di una singola verifica (non un gruppo di varianti):
     * `POST /api/verifica/save-tex`. Restituisce il documento creato.
     */
    async saveTex(payload: Readonly<Record<string, unknown>>): Promise<{ readonly id: number }> {
        const salvato = await this.http.postJson<{ id?: number; doc?: { id?: number } }>("/api/verifica/save-tex", payload);
        const id = salvato.id ?? salvato.doc?.id;
        if (typeof id !== "number" || id <= 0) {
            throw new Error(`[verifica] save-tex non ha restituito un identificativo: ${JSON.stringify(salvato).slice(0, 200)}`);
        }
        return { id };
    }

    async list(): Promise<VerificaList> {
        return this.http.getJson<VerificaList>("/api/verifica/list");
    }

    /** Cancellazione senza lancio: 404 vuol dire già assente, utile nella pulizia. */
    async delete(id: number): Promise<ApiResult<OkResponse>> {
        return this.http.send<OkResponse>("POST", `/api/verifica/${id}/delete`, { form: {} });
    }

    async uploadPdf(id: number, filename: string, pdf: Buffer): Promise<ApiResult<OkResponse>> {
        return this.http.send<OkResponse>("POST", `/api/verifica/${id}/pdf`, {
            params: { filename },
            data: pdf,
            headers: { "Content-Type": "application/pdf" },
        });
    }

    /** Compilazione: senza lancio perché l'esito dipende dal servizio TeX (503 se spento). */
    async compile(id: number, engine?: string): Promise<ApiResult<CompileResult>> {
        return this.http.send<CompileResult>("POST", `/api/verifica/${id}/compile`, {
            json: engine === undefined ? {} : { engine },
        });
    }

    /** Sorgenti TeX del pacchetto di una variante. */
    async texFiles(id: number): Promise<readonly TexFile[]> {
        return (await this.http.getJson<TexFiles>(`/api/verifica/${id}/tex-files`)).files;
    }

    /**
     * Un file del pacchetto per percorso (es. «versioni/main_DIS.tex»).
     * Fallisce con l'elenco dei percorsi disponibili: un pacchetto che cambia
     * forma deve dirlo chiaramente, non far fallire un `undefined` più avanti.
     */
    async texFile(id: number, path: string): Promise<TexFile> {
        const files = await this.texFiles(id);
        const trovato = files.find((f) => f.path === path);
        if (!trovato) {
            throw new Error(`[verifica] file «${path}» assente dal pacchetto ${id}; presenti: ${files.map((f) => f.path).join(", ")}`);
        }
        return trovato;
    }

    /**
     * Salva i sorgenti del pacchetto. I file comuni a più varianti vengono
     * allineati anche nelle sorelle: la risposta dice quante ne ha toccate.
     */
    async saveTexFiles(id: number, files: readonly Pick<TexFile, "path" | "content">[]): Promise<TexFilesSaved> {
        return this.http.postJson<TexFilesSaved>(`/api/verifica/${id}/tex-files`, { files });
    }

    /** PDF compilato della variante. */
    async pdf(id: number): Promise<Buffer> {
        const response = await this.http.request.get(`/api/verifica/${id}/pdf`);
        if (!response.ok()) {
            throw new Error(`[verifica] GET /api/verifica/${id}/pdf → ${response.status()}`);
        }
        return response.body();
    }

    /** Pacchetto ZIP di un gruppo di varianti salvate insieme. */
    async batchZip(batchId: string): Promise<Buffer> {
        const response = await this.http.request.get(`/api/verifica/batch/${batchId}/zip`);
        if (!response.ok()) {
            throw new Error(`[verifica] GET /api/verifica/batch/${batchId}/zip → ${response.status()}`);
        }
        return response.body();
    }

    /** Sorgente .tex di una variante (`tex_url` della risposta del batch). */
    async texText(texUrl: string): Promise<string> {
        return this.http.getText(texUrl);
    }

    /** Sorgente .tex principale di una verifica, per identificativo. */
    async tex(id: number): Promise<string> {
        return this.http.getText(`/api/verifica/${id}/tex`);
    }

    /**
     * Condivide (o ritira) la verifica nel pool. Nel pool compare con
     * `source: "verifica_documents"` e `content_type: "verifica_doc"`, una
     * voce per verifica. Vale per tutte le varianti e le versioni della
     * verifica (`varianti` nella risposta). Senza lancio: il blocco copyright
     * risponde 4xx.
     */
    async setPoolSharing(id: number, enabled: boolean): Promise<ApiResult<{ shared_with_pool: boolean; varianti?: number }>> {
        return this.http.send<{ shared_with_pool: boolean; varianti?: number }>("POST", `/api/verifica/${id}/share-pool`, {
            form: { enabled: enabled ? "1" : "0" },
        });
    }
}
