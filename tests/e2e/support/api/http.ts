/**
 * Client HTTP sulla sessione del browser (page.request).
 *
 * Responsabilità: token CSRF ottenuto una volta da GET /auth/csrf e mandato
 * come header `X-CSRF-Token` (e come campo `_csrf` nei form, come fanno le
 * spec storiche); risposta letta come testo e decodificata come JSON quando
 * possibile; errori con metodo, URL, stato e inizio del corpo. Un 403 che
 * parla di CSRF si ritenta una volta con un token nuovo: il server ha
 * rifiutato la richiesta prima di agire, quindi ripeterla è sicuro.
 *
 * Il WAF fail-closed lascia passare solo la sessione del browser: per questo
 * il client nasce sempre da `page.request`, mai da un contesto API separato.
 *
 * Può importare: types. Non può importare: pagine, fixture, factory.
 */
import type { APIRequestContext, APIResponse } from "@playwright/test";
import type { CsrfToken } from "./types";

export class ApiError extends Error {
    constructor(
        readonly method: string,
        readonly url: string,
        readonly status: number,
        readonly body: string,
    ) {
        super(`[api] ${method} ${url} → ${status}: ${body.slice(0, 300)}`);
        this.name = "ApiError";
    }
}

export interface ApiResult<T> {
    readonly status: number;
    readonly ok: boolean;
    /** Corpo decodificato come JSON, o null se non è JSON. */
    readonly body: T | null;
    readonly text: string;
}

export interface SendOptions {
    /** Corpo JSON (imposta Content-Type: application/json). */
    readonly json?: unknown;
    /** Corpo application/x-www-form-urlencoded; `_csrf` viene aggiunto. */
    readonly form?: Readonly<Record<string, string>>;
    /** Corpo grezzo (es. un PDF): il Content-Type va negli header. */
    readonly data?: Buffer | string;
    readonly headers?: Readonly<Record<string, string>>;
    readonly params?: Readonly<Record<string, string>>;
}

type Method = "GET" | "POST" | "PUT";

/**
 * La motivazione delle mutazioni che la suite fa direttamente alle API.
 *
 * Le rotte dell'amministrazione la pretendono (ADR-008, AUDIT_REASON_MODE
 * enforce): la mette il client, su ogni richiesta che scrive, a meno che la
 * spec non ne passi una sua (anche vuota, per il caso «senza motivazione»).
 * Fino al 23/9/2026 stava in `extraHTTPHeaders` della configurazione, che la
 * aggiungeva anche alle richieste della pagina: un pulsante che non la
 * mandava passava lo stesso (A-69).
 */
export const MOTIVAZIONE_DELLA_SUITE = "Esecuzione della suite end-to-end automatica";

function conMotivazione(headers: Record<string, string>): Record<string, string> {
    const presente = Object.keys(headers).some((k) => k.toLowerCase() === "x-audit-reason");
    return presente ? headers : { ...headers, "X-Audit-Reason": MOTIVAZIONE_DELLA_SUITE };
}

function parseJson<T>(text: string): T | null {
    if (text === "") return null;
    try {
        return JSON.parse(text) as T;
    } catch {
        return null;
    }
}

/**
 * Chi vuole sapere delle risposte 4xx/5xx: la diagnostica della prova.
 *
 * 2026-09-14 — le risposte delle chiamate alle API non passano dagli eventi
 * della pagina, e fino a oggi le ricostruiva a posteriori il registro del
 * server di `php -S` (righe `[esito]`). Contro l'immagine del rilascio quel
 * registro non c'è: nginx non tiene un registro degli accessi, di proposito.
 * Le annota il client, nel rapporto della prova.
 */
export type OsservatoreRisposte = (riga: string) => void;

export class HttpClient {
    private csrfToken: string | null = null;

    constructor(
        readonly request: APIRequestContext,
        /** Nome del ruolo, per i messaggi (docente, secondo docente, admin). */
        readonly label: string,
        private readonly osserva?: OsservatoreRisposte,
    ) {}

    private annota(method: string, url: string, status: number): void {
        if (status >= 400 && this.osserva) {
            this.osserva(`${method} ${url} → ${status} [api, ${this.label}]`);
        }
    }

    /** Token CSRF della sessione, letto una volta e rinnovato su richiesta. */
    async csrf(refresh = false): Promise<string> {
        if (!refresh && this.csrfToken !== null) return this.csrfToken;
        const response = await this.request.get("/auth/csrf");
        this.annota("GET", "/auth/csrf", response.status());
        const text = await response.text();
        const token = parseJson<CsrfToken>(text)?.token;
        if (!response.ok() || !token) {
            throw new ApiError("GET", "/auth/csrf", response.status(), text);
        }
        this.csrfToken = token;
        return token;
    }

    /** Richiesta che non lancia mai per lo stato HTTP: i test che aspettano un 409 o un 403 la usano. */
    async send<T>(method: Method, url: string, options: SendOptions = {}): Promise<ApiResult<T>> {
        let result = await this.sendOnce<T>(method, url, options, false);
        if ((method === "POST" || method === "PUT") && result.status === 403 && /csrf/i.test(result.text)) {
            result = await this.sendOnce<T>(method, url, options, true);
        }
        return result;
    }

    private async sendOnce<T>(method: Method, url: string, options: SendOptions, refreshToken: boolean): Promise<ApiResult<T>> {
        let headers: Record<string, string> = { ...options.headers };
        let form: Record<string, string> | undefined;
        if (method === "POST" || method === "PUT") {
            const token = await this.csrf(refreshToken);
            headers["X-CSRF-Token"] = token;
            headers = conMotivazione(headers);
            if (options.form) form = { ...options.form, _csrf: token };
        }
        const response = await this.request.fetch(url, {
            method,
            headers,
            ...(options.params ? { params: options.params } : {}),
            ...(form ? { form } : {}),
            ...(options.json !== undefined ? { data: options.json } : {}),
            ...(options.data !== undefined ? { data: options.data } : {}),
        });
        this.annota(method, url, response.status());
        const text = await response.text();
        return { status: response.status(), ok: response.ok(), body: parseJson<T>(text), text };
    }

    private async expectJson<T>(method: Method, url: string, result: ApiResult<T>): Promise<T> {
        if (!result.ok || result.body === null) {
            throw new ApiError(method, url, result.status, result.text);
        }
        return result.body;
    }

    async getJson<T>(url: string, params?: Readonly<Record<string, string>>): Promise<T> {
        return this.expectJson(url === "" ? "GET" : "GET", url, await this.send<T>("GET", url, params ? { params } : {}));
    }

    async getText(url: string): Promise<string> {
        const result = await this.send<never>("GET", url);
        if (!result.ok) throw new ApiError("GET", url, result.status, result.text);
        return result.text;
    }

    async postJson<T>(url: string, json: unknown): Promise<T> {
        return this.expectJson("POST", url, await this.send<T>("POST", url, { json }));
    }

    /**
     * POST che restituisce la risposta grezza, con il gettone di sicurezza già
     * messo. Serve quando quel che torna è binario — un PDF, un archivio — e
     * leggerlo come testo lo rovinerebbe.
     */
    async postRaw(url: string, options: Pick<SendOptions, "json" | "form" | "headers"> = {}): Promise<APIResponse> {
        const token = await this.csrf();
        const headers = conMotivazione({ ...options.headers, "X-CSRF-Token": token });
        const response = await this.request.fetch(url, {
            method: "POST",
            headers,
            ...(options.form ? { form: { ...options.form, _csrf: token } } : {}),
            ...(options.json !== undefined ? { data: options.json } : {}),
        });
        this.annota("POST", url, response.status());
        return response;
    }

    async postForm<T>(url: string, form: Readonly<Record<string, string>>): Promise<T> {
        return this.expectJson("POST", url, await this.send<T>("POST", url, { form }));
    }

    /** PUT con corpo JSON: lo usano le rotte che sostituiscono un documento intero. */
    async putJson<T>(url: string, json: unknown): Promise<T> {
        return this.expectJson("PUT", url, await this.send<T>("PUT", url, { json }));
    }
}
