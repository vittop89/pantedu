/**
 * Client delle API dell'amministratore usate dalla suite (AdminController).
 *
 * Responsabilità: per ora solo i contatori di /api/admin/notifications; le
 * altre rotte admin entrano nella fetta che le usa (blueprint §8, fetta 8).
 * L'header X-Audit-Reason richiesto dalle mutazioni lo mette il client
 * (http.ts) su ogni richiesta che scrive; le richieste della pagina non lo
 * ricevono più dalla configurazione, e lo deve mandare l'applicazione.
 *
 * Può importare: http, types. Non può importare: pagine, fixture, factory.
 */
import type { HttpClient } from "./http";
import { AccessApi } from "./access.api";
import { AdozioniAdminApi } from "./adozioni.api";
import { RisdocApi } from "./risdoc.api";
import { TikzApi } from "./tikz.api";
import type { AdminNotifications } from "./types";

export class AdminApi {
    /** I modelli istituzionali: il loro corpo lo cambia solo un amministratore. */
    readonly risdoc: RisdocApi;
    /** Accesso alle risorse riservate senza account. */
    readonly access: AccessApi;
    /** La biblioteca dei modelli TikZ: la cambia solo un amministratore. */
    readonly tikz: TikzApi;
    /** Il catalogo dei libri in adozione di un istituto, a mano. */
    readonly adozioni: AdozioniAdminApi;

    constructor(readonly http: HttpClient) {
        this.risdoc = new RisdocApi(http);
        this.access = new AccessApi(http);
        this.tikz = new TikzApi(http);
        this.adozioni = new AdozioniAdminApi(http);
    }

    async notifications(): Promise<AdminNotifications> {
        return this.http.getJson<AdminNotifications>("/api/admin/notifications");
    }
}
