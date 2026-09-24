/**
 * Ambiente della suite E2E, letto e validato in un solo punto.
 *
 * Responsabilità: credenziali dei tre utenti di test, URL di base, dati
 * scoperti dal global setup (`FM_E2E_*`, vedi tools/dev/e2e_urls.php).
 * Le variabili arrivano da `.env.local` tramite playwright.config.js e dal
 * global setup; qui non si legge nessun file. I valori non vengono mai
 * stampati: i messaggi d'errore citano solo il nome della variabile.
 *
 * Può importare: niente. Non può importare: tutto il resto della suite.
 *
 * La validazione è pigra (alla prima richiesta), così un file che importa
 * questo modulo senza usarlo (per esempio in CI senza `.env.local`) non
 * fallisce al caricamento.
 */

export type Role = "teacher" | "teacher2" | "admin";

export interface Credentials {
    readonly username: string;
    readonly password: string;
}

/** Terna indirizzo/classe/materia con i codici brevi del curriculum (es. SCI/2/MAT). */
export interface Terna {
    readonly indirizzo: string;
    readonly classe: string;
    readonly materia: string;
}

/** Valori esposti dal global setup: assenti quando il DB non ha contenuti di quel tipo. */
export interface Discovered {
    readonly eserListUrl?: string;
    readonly eserUrl?: string;
    readonly eserIdsUrl?: string;
    readonly verifListUrl?: string;
    readonly verifUrl?: string;
    readonly verifRmListUrl?: string;
    readonly verifRmUrl?: string;
    readonly verifRmTopic?: string;
    readonly eserRelatedUrl?: string;
    readonly eserRelatedTitle?: string;
    readonly mappaListUrl?: string;
    readonly mappaUrl?: string;
    readonly shareEserId?: number;
    readonly shareVerifId?: number;
    readonly shareTitle?: string;
    readonly shareTopic?: string;
    readonly shareInd?: string;
    readonly shareCls?: string;
    readonly shareSubject?: string;
    readonly shareMappaId?: number;
    readonly shareMappaTitle?: string;
    /** ADR-037 — la scuola del docente in cui sono state scoperte le pagine. */
    readonly scuolaId?: number;
}

/** Vista dell'ambiente senza segreti: è ciò che le spec ricevono dalla fixture `env`. */
export interface PublicEnv {
    readonly baseUrl: string;
    readonly users: Readonly<Record<Role, { readonly username: string }>>;
    readonly discovered: Discovered;
    /** Terna del docente con più contenuti (da FM_E2E_ESER_URL), o SCI/2/MAT come nelle spec storiche. */
    readonly terna: Terna;
}

const DEFAULT_TERNA: Terna = { indirizzo: "SCI", classe: "2", materia: "MAT" };

function read(name: string): string | undefined {
    const value = process.env[name];
    if (value === undefined) return undefined;
    const trimmed = value.trim();
    return trimmed === "" ? undefined : trimmed;
}

function required(name: string, hint: string): string {
    const value = read(name);
    if (value === undefined) {
        throw new Error(`[env] variabile ${name} assente o vuota: ${hint}`);
    }
    return value;
}

function readNumber(name: string): number | undefined {
    const value = read(name);
    if (value === undefined) return undefined;
    const n = Number(value);
    return Number.isFinite(n) && n > 0 ? n : undefined;
}

const credentialsCache = new Map<Role, Credentials>();

/** Credenziali di un ruolo. Solo il modulo di login le usa; le spec non le vedono. */
export function credentials(role: Role): Credentials {
    const cached = credentialsCache.get(role);
    if (cached) return cached;
    let value: Credentials;
    switch (role) {
        case "teacher":
            value = {
                username: read("E2E_TEACHER_USER") ?? "docente.uno",
                password: required("E2E_TEACHER_PASS", "password del docente di test in .env.local"),
            };
            break;
        case "teacher2":
            // Il secondo docente condivide la password del primo (com'è oggi nelle
            // spec di condivisione); E2E_TEACHER2_* la sovrascrive se un giorno cambia.
            value = {
                username: read("E2E_TEACHER2_USER") ?? "docente.due",
                password: read("E2E_TEACHER2_PASS") ?? required("E2E_TEACHER_PASS", "password del docente di test in .env.local"),
            };
            break;
        case "admin":
            value = {
                username: read("FM_E2E_ADMIN_USERNAME") ?? "admin",
                password: required("FM_E2E_ADMIN_PASSWORD", "password dell'amministratore di test in .env.local"),
            };
            break;
    }
    credentialsCache.set(role, value);
    return value;
}

function ternaFrom(eserUrl: string | undefined): Terna {
    const m = eserUrl?.match(/^\/studio\/esercizio\/([^/]+)\/([^/]+)\/([^/]+)/);
    if (!m || m[1] === undefined || m[2] === undefined || m[3] === undefined) return DEFAULT_TERNA;
    return {
        indirizzo: decodeURIComponent(m[1]),
        classe: decodeURIComponent(m[2]),
        materia: decodeURIComponent(m[3]),
    };
}

let publicEnvCache: PublicEnv | null = null;

/** Ambiente senza segreti, calcolato una volta per processo (il worker). */
export function publicEnv(): PublicEnv {
    if (publicEnvCache) return publicEnvCache;
    const discovered: Discovered = {
        eserListUrl: read("FM_E2E_ESER_LIST_URL"),
        eserUrl: read("FM_E2E_ESER_URL"),
        eserIdsUrl: read("FM_E2E_ESER_IDS_URL"),
        verifListUrl: read("FM_E2E_VERIF_LIST_URL"),
        verifUrl: read("FM_E2E_VERIF_URL"),
        verifRmListUrl: read("FM_E2E_VERIF_RM_LIST_URL"),
        verifRmUrl: read("FM_E2E_VERIF_RM_URL"),
        verifRmTopic: read("FM_E2E_VERIF_RM_TOPIC"),
        eserRelatedUrl: read("FM_E2E_ESER_RELATED_URL"),
        eserRelatedTitle: read("FM_E2E_ESER_RELATED_TITLE"),
        mappaListUrl: read("FM_E2E_MAPPA_LIST_URL"),
        mappaUrl: read("FM_E2E_MAPPA_URL"),
        shareEserId: readNumber("FM_E2E_SHARE_ESER_ID"),
        shareVerifId: readNumber("FM_E2E_SHARE_VERIF_ID"),
        shareTitle: read("FM_E2E_SHARE_TITLE"),
        shareTopic: read("FM_E2E_SHARE_TOPIC"),
        shareInd: read("FM_E2E_SHARE_IND"),
        shareCls: read("FM_E2E_SHARE_CLS"),
        shareSubject: read("FM_E2E_SHARE_SUBJECT"),
        shareMappaId: readNumber("FM_E2E_SHARE_MAPPA_ID"),
        shareMappaTitle: read("FM_E2E_SHARE_MAPPA_TITLE"),
        scuolaId: readNumber("FM_E2E_SCUOLA_ID"),
    };
    publicEnvCache = {
        baseUrl: read("FM_E2E_BASE_URL") ?? "http://pantedu.local",
        users: {
            teacher: { username: credentials("teacher").username },
            teacher2: { username: credentials("teacher2").username },
            admin: { username: credentials("admin").username },
        },
        discovered,
        terna: ternaFrom(discovered.eserUrl),
    };
    return publicEnvCache;
}

/**
 * Valore scoperto obbligatorio per una spec: se manca, il test fallisce con un
 * messaggio chiaro (regola della wiki: nessun salto condizionato ai dati).
 */
export function requireDiscovered<K extends keyof Discovered>(key: K): NonNullable<Discovered[K]> {
    const value = publicEnv().discovered[key];
    if (value === undefined) {
        throw new Error(`[env] dato scoperto «${key}» assente: il global setup non ha trovato contenuti adatti nel DB (vedi tools/dev/e2e_urls.php)`);
    }
    return value;
}
