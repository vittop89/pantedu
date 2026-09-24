/**
 * Client di /api/access/* (accesso alle risorse riservate senza un account).
 *
 * Chi non ha un account — uno studente, un genitore — apre una risorsa
 * riservata con le credenziali che il docente gli ha dato. L'applicazione
 * accetta anche un account vero come ripiego: in quel caso il permesso
 * concesso dice di venire da lì.
 *
 * Le password non passano dalle spec: il metodo che usa un account vero se le
 * prende da `env.credentials`, che legge `.env.local` e non le espone.
 *
 * Può importare: http, env (credenziali). Non può importare: pagine, fixture, factory.
 */
import { credentials, type Role } from "../env";
import type { ApiResult, HttpClient } from "./http";

export interface AccessGrant {
    readonly source?: string;
    readonly label?: string;
    readonly classe?: string | null;
    readonly credential_id?: number | null;
}

export interface StudentLoginResult {
    readonly ok?: boolean;
    readonly grant?: AccessGrant;
    readonly grants?: readonly AccessGrant[];
    readonly error?: string;
}

/** Lo stato del portachiavi in sessione: i permessi presenti e gli avvisi da mostrare una volta. */
export interface AccessStatus {
    readonly ok: boolean;
    readonly grant: AccessGrant | null;
    readonly grants: readonly AccessGrant[];
    readonly notices: readonly { readonly label: string; readonly reason: string }[];
    readonly remember_enabled: boolean;
}

export class AccessApi {
    constructor(private readonly http: HttpClient) {}

    /** Il portachiavi della sessione corrente (una GET: nessun gettone in gioco). */
    async status(): Promise<AccessStatus> {
        return this.http.getJson<AccessStatus>("/api/access/status");
    }

    /** Esce da una credenziale sola, o da tutte se non se ne indica una. */
    async studentLogout(credentialId?: number): Promise<{ ok: boolean; grants: readonly AccessGrant[] }> {
        return this.http.postForm("/api/access/student-logout", credentialId ? { credential_id: String(credentialId) } : {});
    }

    /** Accesso con le credenziali di un account vero della suite. */
    async studentLoginComeRuolo(role: Role): Promise<ApiResult<StudentLoginResult>> {
        const { username, password } = credentials(role);
        return this.http.send<StudentLoginResult>("POST", "/api/access/student-login", {
            form: { username, password },
        });
    }

    /** Accesso con credenziali date: serve ai casi che ne provano di sbagliate. */
    async studentLogin(username: string, password: string): Promise<ApiResult<StudentLoginResult>> {
        return this.http.send<StudentLoginResult>("POST", "/api/access/student-login", {
            form: { username, password },
        });
    }
}
