/**
 * Contratti minimi delle risposte usate dalla suite.
 *
 * Scritti a mano dalle risposte reali dei controller (app/Controllers/*) e
 * dalle spec verdi che le leggono; contengono solo i campi che la suite usa.
 * docs/api/openapi.full.yaml non copre le rotte definite nei gruppi
 * (`$rr->post`), quindi non è una fonte utilizzabile (blueprint §1.2).
 *
 * Può importare: niente.
 */

export interface CsrfToken {
    readonly token?: string;
}

export interface UserInfo {
    readonly authenticated?: boolean;
    readonly username?: string;
}

/** `Response::ok()` → `{ ok: true, ...dati }`; `Response::fail()` → `{ ok: false, error }`. */
export interface OkResponse {
    readonly ok: boolean;
}

export interface ErrorResponse {
    readonly ok?: false;
    readonly error: string;
    readonly detail?: string;
}

// ── Contenuti del docente (TeacherContentController, GroupController, QuesitoController, ContentPublishController) ──

export interface ContentCreated extends OkResponse {
    readonly id: number;
}

export interface ContentRow {
    /** Codici delle etichette, come li serve `GET /api/teacher/content/{id}`. */
    readonly classe?: string | null;
    readonly indirizzo?: string | null;
    readonly materia?: string | null;
    readonly id: number;
    readonly title?: string;
    readonly topic?: string;
    readonly content_type?: string;
    readonly visibility?: string;
    readonly shared_with_pool?: boolean | 0 | 1;
    readonly metadata?: string | Readonly<Record<string, unknown>>;
    /** Solo per le mappe: percorso del disegno cifrato, sotto la cartella del proprietario. */
    readonly map_blob_path?: string | null;
    /** Solo per le mappe: la versione che l'editor manda al salvataggio (409 se è vecchia). */
    readonly map_version?: number | string | null;
}

/** `GET /api/teacher/content?…` → `{ ok, count, rows }`. */
export interface ContentList extends OkResponse {
    readonly count: number;
    readonly rows: readonly ContentRow[];
}

/** `GET /api/teacher/content/{id}` → `{ content: {...} }`. */
export interface ContentDetail {
    readonly content: ContentRow;
}

export interface ContractItem {
    readonly id?: string;
}

export interface ContractGroup {
    readonly id?: string;
    readonly type?: string;
    readonly title?: string;
    readonly items?: readonly ContractItem[];
}

/** `GET /api/teacher/content/{id}/contract` → `ContractAggregate::data()`. */
export interface ContractData {
    readonly version?: number;
    readonly groups?: readonly ContractGroup[];
}

/** `POST /api/teacher/content/{id}/group/add` → `{ ok, groupId, version, html }`. */
export interface GroupAdded extends OkResponse {
    readonly groupId: string;
    readonly version?: number;
}

export interface VisibilitySet extends OkResponse {
    readonly visibility?: string;
}

/** `POST …/share-pool` → flag applicato e contenuto abbinato (esercizio ↔ verifica). */
export interface SharePoolResult {
    readonly ok?: boolean;
    readonly shared_with_pool: boolean;
    readonly counterpart_id: number | null;
    readonly counterpart_type: "esercizio" | "verifica" | null;
}

/** `POST …/export` (mode=zip) → `{ url }` del pacchetto. */
export interface ExportResult {
    readonly ok?: boolean;
    readonly url?: string;
}

// ── Verifiche (VerificaController, VerificaCompileController) ──

export interface VerificaDoc {
    readonly id: number;
    readonly variant: string;
    readonly tex_url: string;
    readonly title?: string;
}

/** `POST /api/verifica/save-tex-batch` → varianti salvate. */
export interface VerificaBatchResult extends OkResponse {
    readonly batch_id?: string;
    readonly docs: readonly VerificaDoc[];
}

/** Stesso endpoint, 409 quando titolo e versione esistono già. */
export interface VerificaConflict {
    readonly error: "verifica_version_conflict";
    readonly conflict: { readonly existing_ids: readonly number[] };
}

export interface VerificaListItem {
    readonly id: number;
    readonly title?: string;
}

/** `GET /api/verifica/list` → `{ ok, items, materie }` (non `docs`). */
export interface VerificaList extends OkResponse {
    readonly items: readonly VerificaListItem[];
}

/** `POST /api/verifica/{id}/compile` → esito con cache content-addressed. */
export interface CompileResult extends OkResponse {
    readonly compile?: {
        readonly cache_hit?: boolean;
        readonly engine?: string;
        readonly duration_ms?: number;
    };
}

/**
 * Un file del pacchetto TeX di una verifica. I file binari (immagini, PDF di
 * GeoGebra) arrivano con `content` vuoto e la dimensione reale in `size`:
 * rimandarli così com'è non deve azzerarli.
 */
export interface TexFile {
    readonly path: string;
    readonly content: string;
    readonly is_binary?: boolean;
    readonly size?: number;
}

/** `GET /api/verifica/{id}/tex-files` → sorgenti del pacchetto. */
export interface TexFiles extends OkResponse {
    readonly files: readonly TexFile[];
}

/** `POST /api/verifica/{id}/tex-files` → quante varianti sorelle sono state allineate. */
export interface TexFilesSaved extends OkResponse {
    readonly synced_siblings?: number;
}

// ── Condivisione (ShareGrantsController, PoolController) ──

export interface GrantTarget {
    readonly target_type: "teacher" | "group" | "institute";
    readonly target_id: number;
}

export interface GrantsSet extends OkResponse {
    readonly count: number;
}

export interface GroupCreated extends OkResponse {
    readonly id: number;
}

export interface MembersSet extends OkResponse {
    readonly count: number;
}

export interface Colleague {
    readonly id: number;
    readonly username: string;
    readonly display_name?: string;
}

export interface Colleagues extends OkResponse {
    readonly colleagues: readonly Colleague[];
}

export interface PoolItem {
    readonly source: string;
    readonly id: number;
    readonly content_type?: string;
    readonly title?: string;
    readonly topic?: string;
    readonly subject_code?: string;
    readonly subject_label?: string;
    readonly owner_id?: number;
    readonly owner_name?: string;
    /** Vero se chi chiede ne ha già preso una copia. */
    readonly already_recovered?: boolean;
    /** Presente se chi chiede ha già recuperato una copia dal pool. */
    readonly my_recovered_id?: number | null;
    /** Per una verifica: quante varianti e versioni ha la voce (una voce per verifica dal 14/9/2026). */
    readonly varianti?: number;
    /** Per una verifica: gli id di tutte le sue varianti. */
    readonly ids?: readonly number[];
}

export interface PoolMaterials extends OkResponse {
    readonly items: readonly PoolItem[];
}

/** Riferimento a un contenuto condiviso: la coppia (tabella, identificativo). */
export interface ShareRef {
    readonly source: string;
    readonly id: number;
}

export interface MyShareItem extends ShareRef {
    readonly content_type?: string;
    readonly title?: string;
    readonly topic?: string;
    readonly subject_code?: string;
    readonly shared_with_pool?: boolean;
    readonly grants_count?: number;
    /** Per una verifica: quante varianti e versioni ha la voce. */
    readonly varianti?: number;
    /** Per una verifica: gli id di tutte le sue varianti. */
    readonly ids?: readonly number[];
}

/** `GET /api/teacher/pool/my-shares`. */
export interface MyShares extends OkResponse {
    readonly items: readonly MyShareItem[];
}

/** `POST /api/teacher/pool/unshare`. */
export interface UnshareResult extends OkResponse {
    readonly total: number;
}

/** `POST /api/teacher/pool/recover/{id}` → copia creata per chi recupera. */
export interface RecoverResult extends OkResponse {
    readonly new_id: number;
    readonly content_type?: string;
}

// ── Curriculum (CurriculumController) ──

export interface CurriculumEntry {
    readonly id: number;
    readonly kind: string;
    readonly code: string;
    readonly label?: string;
    /** Identificativo del docente proprietario della voce. */
    readonly owner_user_id?: number;
    readonly institute_id?: number;
    /** La spunta è accesa (ADR-035: una riga di `curriculum_teacher`). */
    readonly active?: boolean;
    /** Solo per le classi: il corso della sezione, null = vale per tutti. */
    readonly indirizzo?: string | null;
    readonly label_istituto?: string;
}

/** Una voce del vocabolario della scuola, come la vede il docente nel profilo. */
export interface VoceVocabolario {
    readonly code: string;
    readonly label: string;
    readonly group: string | null;
    readonly indirizzo: string | null;
}

export interface VocabolarioIstituto {
    readonly indirizzi: readonly VoceVocabolario[];
    readonly classi: readonly VoceVocabolario[];
    readonly materie: readonly VoceVocabolario[];
}

export interface Curriculum {
    readonly ok?: boolean;
    readonly institute_id?: number | null;
    readonly curriculum: {
        readonly materie?: readonly CurriculumEntry[];
        readonly indirizzi?: readonly CurriculumEntry[];
        readonly classi?: readonly CurriculumEntry[];
    };
    /** Il vocabolario attivo della scuola (solo per il docente nel proprio istituto). */
    readonly institute_vocabolario?: VocabolarioIstituto;
}

// ── Mappe (MapsController) ──

/** `POST /api/maps` (drawio nativo) → contenuto creato e blob cifrato. */
export interface MapCreated extends OkResponse {
    readonly id: number;
    readonly blob_path?: string;
    readonly origin?: string;
    readonly size?: number;
}

/** `GET /api/maps/{id}/signed-url` → il link firmato al disegno, valido dieci minuti. */
export interface MapSignedUrl extends OkResponse {
    readonly id: number;
    readonly mode: string;
    readonly url: string;
    readonly exp: number;
}

/** `POST /api/maps/{id}/update` → 200 con la versione nuova, 409 con quella del server. */
export interface MapUpdated {
    readonly ok?: boolean;
    readonly id?: number;
    readonly size?: number;
    readonly map_version?: number;
    readonly error?: string;
    readonly server_version?: number | null;
}

// ── Pacchetto di sincronizzazione e chiave di recupero (TeacherSyncController) ──

export interface RecoveryKeyStatus extends OkResponse {
    readonly status: {
        readonly exists: boolean;
        readonly created_at?: string | null;
        readonly revoked_at?: string | null;
        readonly download_count?: number;
    };
}

export interface RecoveryKeyGenerated extends OkResponse {
    /** Chiave in esadecimale, 64 caratteri. */
    readonly recovery_hex: string;
}

export interface SyncManifest {
    readonly version: number;
    readonly exported_at: string;
    readonly exporter_user_id: number;
    readonly hmac: string;
    readonly files: readonly unknown[];
}

export interface SyncManifestResponse extends OkResponse {
    readonly manifest: SyncManifest;
}

/** `POST /api/teacher/import-bundle/preview` → prova senza scrivere. */
export interface ImportPreview extends OkResponse {
    readonly preview: boolean;
    readonly report: {
        readonly created?: readonly unknown[];
        readonly conflicts?: readonly unknown[];
        readonly unsupported?: readonly unknown[];
    };
}

// ── Admin (AdminController) ──

/** `GET /api/admin/notifications` → contatori aggregati. */
export interface AdminNotifications extends OkResponse {
    readonly total: number;
    readonly pending_registrations: number;
    readonly blocked_credentials: number;
    readonly blocked_ips: number;
    readonly failed_logins_24h: number;
    readonly new_teacher_content_24h: number;
}
