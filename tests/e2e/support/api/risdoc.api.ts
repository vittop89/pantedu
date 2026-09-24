/**
 * Client di /api/risdoc/templates/* (modelli istituzionali e loro istanze).
 *
 * Responsabilità: le chiamate che le spec dell'area «risorse docente» usano.
 * I modelli sono documenti dell'Istituto: il docente li vede e li copia, ma il
 * loro corpo lo cambia solo un amministratore (al docente l'app risponde 403).
 * Le copie personali si chiamano istanze, e ognuna può avere le sue modifiche
 * («override»), che restano di chi le ha fatte.
 *
 * Può importare: http, types. Non può importare: pagine, fixture, factory.
 */
import type { APIResponse } from "@playwright/test";
import type { ApiResult, HttpClient } from "./http";
import type { OkResponse } from "./types";

export interface RisdocTemplate {
    readonly id: number;
    readonly name?: string;
    readonly category?: string;
    readonly origin?: string;
    readonly tex_file?: string | null;
    readonly owner_id?: number | null;
    readonly body_pt?: readonly unknown[];
}

export interface TemplateListParams {
    /** `risdoc`, `bes`, … : la sezione della barra laterale da cui vengono. */
    readonly origin?: string;
    /** Con `true` ogni modello porta anche il proprio corpo strutturato. */
    readonly withBodyPt?: boolean;
}

export interface RisdocFile {
    /** «override» quando il contenuto viene da una modifica, non dal modello. */
    readonly source?: string;
    readonly body?: string;
}

export interface RisdocInstance {
    readonly id?: number;
    readonly instance_key?: string;
    readonly label?: string;
}

/**
 * Un modello valorizzato dal docente. `exported_at` c'è dalla migrazione 138
 * (ADR-046): è la data dello scaricamento, da cui decorrono i quindici giorni.
 */
export interface RisdocCompilation {
    readonly id: number;
    readonly compilation_key?: string;
    readonly label?: string;
    readonly data_json?: string;
    readonly updated_at?: string;
    readonly exported_at?: string | null;
}

export class RisdocApi {
    constructor(private readonly http: HttpClient) {}

    async templates(params: TemplateListParams = {}): Promise<readonly RisdocTemplate[]> {
        const query: Record<string, string> = {};
        if (params.origin !== undefined) query["origin"] = params.origin;
        if (params.withBodyPt === true) query["with_body_pt"] = "1";
        const risposta = await this.http.getJson<{ templates?: readonly RisdocTemplate[] }>("/api/risdoc/templates", query);
        return risposta.templates ?? [];
    }

    /**
     * Un modello, il primo dell'elenco. Fallisce con un messaggio esplicito se
     * l'Istituto non ne ha: senza modelli metà delle spec dell'area non ha
     * senso, ed è meglio dirlo che far fallire un `undefined` più avanti.
     */
    async unTemplate(params: TemplateListParams = {}): Promise<RisdocTemplate> {
        const modelli = await this.templates(params);
        const primo = modelli[0];
        if (!primo) {
            throw new Error(`[risdoc] nessun modello dell'Istituto per ${JSON.stringify(params)}`);
        }
        return primo;
    }

    /**
     * Corpo strutturato di partenza di un modello. Senza lancio: al docente
     * l'app risponde 403, ed è una delle cose che le spec verificano.
     */
    async saveBodyPt(id: number, bodyPt: readonly unknown[] | ""): Promise<ApiResult<OkResponse>> {
        return this.http.send<OkResponse>("POST", `/api/risdoc/templates/${id}/body-pt`, {
            form: { body_pt: bodyPt === "" ? "" : JSON.stringify(bodyPt) },
        });
    }

    /**
     * Scheda del modello vista dall'amministrazione: da qui vengono i percorsi
     * dei suoi file (marcatura e schema dei campi), che servono per indirizzare
     * le modifiche.
     */
    async detail(id: number): Promise<{ readonly html_file: string; readonly schema_path: string; readonly tex_file: string | null }> {
        const risposta = await this.http.getJson<{ template?: { html_file?: string; schema_path?: string; tex_file?: string | null } }>(
            `/api/admin/risdoc/templates/${id}`,
        );
        const scheda = risposta.template;
        if (!scheda?.html_file || !scheda.schema_path) {
            throw new Error(`[risdoc] il modello ${id} non dichiara i propri file: ${JSON.stringify(risposta).slice(0, 200)}`);
        }
        return { html_file: scheda.html_file, schema_path: scheda.schema_path, tex_file: scheda.tex_file ?? null };
    }

    /**
     * A chi è visibile un modello dell'Istituto: a tutti, a un indirizzo, a
     * una classe, a nessuno. La cambia solo un amministratore, e la rotta
     * pretende una motivazione per il registro degli accessi privilegiati.
     *
     * `motivazione` la sovrascrive: `""` serve al caso in cui non deve
     * arrivare, che è quello che verifica il controllo.
     */
    async setVisibilityScope(
        id: number,
        fields: Readonly<Record<string, string>>,
        motivazione?: string,
    ): Promise<ApiResult<Record<string, unknown>>> {
        return this.http.send<Record<string, unknown>>("POST", `/api/admin/risdoc/templates/${id}/visibility-scope`, {
            form: fields,
            ...(motivazione === undefined ? {} : { headers: { "X-Audit-Reason": motivazione } }),
        });
    }

    /** Schema dei campi del modello, come testo (è un JSON). */
    async schema(id: number): Promise<string> {
        return this.http.getText(`/api/risdoc/templates/${id}/schema`);
    }

    /** Copie personali del modello fatte da chi chiama. */
    async instances(id: number): Promise<ApiResult<{ instances?: readonly RisdocInstance[] }>> {
        return this.http.send<{ instances?: readonly RisdocInstance[] }>("GET", `/api/risdoc/templates/${id}/instances`);
    }

    /** Crea una copia personale del modello e ne restituisce la chiave. */
    async createInstance(id: number, label: string): Promise<string> {
        const creata = await this.http.postForm<{ instance_key?: string }>(`/api/risdoc/templates/${id}/instances`, {
            instance_label: label,
        });
        if (!creata.instance_key) {
            throw new Error(`[risdoc] la copia del modello ${id} non ha restituito una chiave`);
        }
        return creata.instance_key;
    }

    /** Cancellazione senza lancio: serve nella pulizia. */
    async deleteInstance(id: number, instanceKey: string): Promise<ApiResult<OkResponse>> {
        return this.http.send<OkResponse>("POST", `/api/risdoc/templates/${id}/instances/${encodeURIComponent(instanceKey)}/delete`, {
            form: {},
        });
    }

    /**
     * Marcatura di un file del modello, come la vede chi chiama: senza
     * `instanceKey` è quella del documento base, con la chiave è quella della
     * copia. È qui che si vedono (o non si vedono) le modifiche altrui.
     */
    async file(id: number, params: { readonly kind?: string; readonly path: string; readonly instanceKey?: string }): Promise<ApiResult<RisdocFile>> {
        const query: Record<string, string> = { kind: params.kind ?? "html", path: params.path };
        if (params.instanceKey !== undefined) query["instance_key"] = params.instanceKey;
        return this.http.send<RisdocFile>("GET", `/api/risdoc/templates/${id}/file`, { params: query });
    }

    /** Salva una modifica personale del docente su un file del modello. */
    async saveOverride(id: number, fields: Readonly<Record<string, string>>): Promise<ApiResult<OkResponse>> {
        return this.http.send<OkResponse>("POST", `/api/risdoc/templates/${id}/override`, { form: fields });
    }

    /** Toglie la modifica personale, riportando il file com'è nel modello. */
    async deleteOverride(id: number, fields: Readonly<Record<string, string>>): Promise<ApiResult<OkResponse>> {
        return this.http.send<OkResponse>("POST", `/api/risdoc/templates/${id}/override/del`, { form: fields });
    }

    /** Modifica dell'Istituto: vale per tutti, e la fa solo un amministratore. */
    async saveInstitutionalOverride(id: number, fields: Readonly<Record<string, string>>): Promise<ApiResult<OkResponse>> {
        return this.http.send<OkResponse>("POST", `/api/risdoc/templates/${id}/institutional-override`, { form: fields });
    }

    async deleteInstitutionalOverride(id: number, fields: Readonly<Record<string, string>>): Promise<ApiResult<OkResponse>> {
        return this.http.send<OkResponse>("POST", `/api/risdoc/templates/${id}/institutional-override/del`, { form: fields });
    }

    /**
     * Scostamento fra la copia del docente e il modello da cui è nata: l'app
     * lo segnala nell'editor quando il modello è cambiato dopo la copia.
     */
    async drift(id: number, instanceKey = ""): Promise<ApiResult<Record<string, unknown>>> {
        return this.http.send<Record<string, unknown>>("GET", `/api/risdoc/templates/${id}/drift`, {
            params: instanceKey === "" ? {} : { instance_key: instanceKey },
        });
    }

    /**
     * Esportazione del modello come pacchetto ZIP.
     *
     * `formState` è quel che il docente ha compilato nel documento — i campi e
     * lo stato della terna — e determina che cosa finisce nel TeX: senza, il
     * pacchetto esce con i valori vuoti.
     *
     * Dal 21/9/2026 il pacchetto È la risposta: prima tornava un JSON con
     * l'indirizzo di un file scritto su disco, e si faceva un secondo giro per
     * prenderlo. Per questo qui torna la risposta grezza: leggerla come testo
     * rovinerebbe l'archivio.
     */
    async export(
        id: number,
        options: { readonly formState?: unknown } = {},
    ): Promise<APIResponse> {
        const form: Record<string, string> = {};
        if (options.formState !== undefined) form["form_state"] = JSON.stringify(options.formState);
        return this.http.postRaw(`/api/risdoc/templates/${id}/export`, { form });
    }

    // ── Compilazioni: il modello valorizzato dal docente ──────────────────
    //
    // Sono le righe che dal 22/9/2026 hanno una scadenza (ADR-046): quindici
    // giorni dall'ultima fra lo scaricamento e la modifica, e comunque il 31
    // agosto se restano ferme. Prima di quella data nessuna spec le toccava.

    /** Le compilazioni del docente per un modello. Senza i dati. */
    async compilations(templateId: number): Promise<readonly RisdocCompilation[]> {
        const risposta = await this.http.getJson<{ compilations?: readonly RisdocCompilation[] }>(
            `/api/risdoc/templates/${templateId}/compilations`,
        );
        return risposta.compilations ?? [];
    }

    /** Dettaglio di una compilazione, con `data_json` già decifrato. */
    async compilation(id: number): Promise<ApiResult<{ compilation?: RisdocCompilation }>> {
        return this.http.send<{ compilation?: RisdocCompilation }>("GET", `/api/risdoc/compilations/${id}`);
    }

    /**
     * Salva (o sovrascrive) una compilazione e ne restituisce l'identificativo.
     * La chiave è quella che il client costruisce dalla terna:
     * `combo_<indirizzo-classe-sezione-disciplina>`.
     */
    async saveCompilation(
        templateId: number,
        fields: Readonly<Record<string, string>>,
    ): Promise<ApiResult<{ id?: number }>> {
        return this.http.send<{ id?: number }>("POST", `/api/risdoc/templates/${templateId}/compilations`, {
            form: fields,
        });
    }

    /**
     * Dichiara che il documento di questa compilazione è stato **scaricato**:
     * da qui parte la grazia prima della cancellazione.
     *
     * Nell'applicazione la chiama il browser dopo aver salvato il file, perché
     * lo scaricamento avviene tutto lì e il server non lo vedrebbe mai.
     */
    async segnaScaricata(id: number): Promise<ApiResult<{ scade_fra_giorni?: number }>> {
        return this.http.send<{ scade_fra_giorni?: number }>("POST", `/api/risdoc/compilations/${id}/scaricata`, {
            form: {},
        });
    }

    /** Cancellazione senza lancio: serve nella pulizia. */
    async deleteCompilation(id: number): Promise<ApiResult<OkResponse>> {
        return this.http.send<OkResponse>("POST", `/api/risdoc/compilations/${id}/delete`, { form: {} });
    }
}
