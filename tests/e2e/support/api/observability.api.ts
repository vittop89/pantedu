/**
 * Client di /metrics (esposizione Prometheus) e delle intestazioni di
 * tracciamento delle richieste.
 *
 * L'esposizione delle metriche è protetta da un gettone che sta in
 * `.env.local` (`METRICS_BEARER_TOKEN`): il client se lo prende da sé e non lo
 * restituisce mai, così le spec non lo scrivono e non lo stampano.
 *
 * Può importare: http, env. Non può importare: pagine, fixture, factory.
 */
import type { APIResponse } from "@playwright/test";
import type { HttpClient } from "./http";

function gettone(): string {
    const valore = process.env["METRICS_BEARER_TOKEN"]?.trim();
    if (!valore) {
        throw new Error("[metrics] METRICS_BEARER_TOKEN assente o vuota in .env.local: serve per leggere /metrics");
    }
    return valore;
}

export class ObservabilityApi {
    constructor(private readonly http: HttpClient) {}

    /** Metriche senza gettone: deve rispondere che non è autorizzato. */
    async metricsSenzaGettone(): Promise<APIResponse> {
        return this.http.request.get("/metrics");
    }

    /** Metriche con il gettone nell'intestazione, come le legge Prometheus. */
    async metrics(): Promise<APIResponse> {
        return this.http.request.get("/metrics", { headers: { Authorization: `Bearer ${gettone()}` } });
    }

    /** Metriche col gettone nell'indirizzo: il ripiego per chi non può mandare intestazioni. */
    async metricsConGettoneNellIndirizzo(): Promise<APIResponse> {
        return this.http.request.get("/metrics", { params: { bearer: gettone() } });
    }

    /** Metriche con un gettone sbagliato: deve essere rifiutato. */
    async metricsConGettoneSbagliato(): Promise<APIResponse> {
        return this.http.request.get("/metrics", { headers: { Authorization: "Bearer gettone-sbagliato" } });
    }
}
