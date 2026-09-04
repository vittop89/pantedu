/**
 * Capability effettive del docente (vanilla ESM) — ADR-028.
 * Fonte unica: GET /api/teacher/capabilities (TeacherCapabilityPolicy::effectiveFor).
 *
 * Usato dall'UI per mostrare SOLO le opzioni consentite, coerentemente con
 * l'enforcement server-side (es. il dropdown "Chi può vederlo" limitato a
 * max_visibility). In SINGLE le caps sono full-permissive → nessun limite.
 *
 * Lookup sincrono dopo il preload (cache al boot); fallback permissivo se non
 * ancora caricato (l'enforcement server resta la rete di sicurezza).
 */
import { memoFetchJson } from "./api-memo.js";

const CAPS_URL = "/api/teacher/capabilities";
/** Default permissivo (= SINGLE / fail-open): allinea a TeacherCapabilityPolicy. */
const DEFAULT_CAPS = {
    sidebar: { mode: "all", sections: [] },
    can_create_section: true,
    doc_types: ["mappa", "esercizio", "verifica", "document", "fork", "link", "custom"],
    max_visibility: "general",
};
/** Rank visibilità (vocabolario publish_scope): class < classes < general. */
const VIS_RANK = { class: 1, classes: 2, general: 3 };

let _caps = null;
let _loadingPromise = null;

export async function loadCaps(force = false) {
    if (_caps && !force) return _caps;
    if (_loadingPromise && !force) return _loadingPromise;
    _loadingPromise = memoFetchJson(CAPS_URL, { ttl: 60_000, force })
        .then((data) => { _caps = (data && data.capabilities) || DEFAULT_CAPS; return _caps; })
        .catch(() => { _caps = DEFAULT_CAPS; return _caps; })
        .finally(() => { _loadingPromise = null; });
    return _loadingPromise;
}

/** Caps correnti (cache); DEFAULT_CAPS finché il preload non ha risposto. */
export function caps() {
    return _caps || DEFAULT_CAPS;
}

/** Visibilità massima consentita ("class"|"classes"|"general"). */
export function maxVisibility() {
    return String(caps().max_visibility || "general");
}

/** Lo scope richiesto è entro il tetto del docente? */
export function visibilityAllowed(scope) {
    const want = VIS_RANK[scope] || 1;
    const cap = VIS_RANK[maxVisibility()] || 3;
    return want <= cap;
}

/** Può creare quel tipo di documento? */
export function canDocType(type) {
    return (caps().doc_types || DEFAULT_CAPS.doc_types).includes(type);
}

// Preload best-effort al boot: popola la cache per i lookup sincroni.
if (typeof document !== "undefined") {
    const kick = () => { loadCaps().catch(() => {}); };
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", kick, { once: true });
    } else {
        kick();
    }
}

if (typeof window !== "undefined") {
    window.FM = window.FM || {};
    window.FM.Caps = { load: loadCaps, caps, maxVisibility, visibilityAllowed, canDocType };
}
