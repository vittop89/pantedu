/**
 * Catalogo curriculum DINAMICO (vanilla ESM) — code → label per
 * indirizzi / classi / materie. Fonte unica: GET /curriculum
 * (institute-scoped, vedi CurriculumController::index + CurriculumLookup).
 *
 * Sostituisce le mappe legacy hardcoded sparse nel frontend
 * (es. `if (indirizzo === "SCI") "Scientifico"`, preset `["MAT","GEO","FIS"]`,
 * default `selectedIIS = "SCI"`): i codici sono liberi e dinamici per istituto,
 * quindi NESSUNA tabella statica — si interroga il catalogo reale.
 *
 * Lookup sincrono dopo il preload (cache popolata al boot); fallback graceful
 * al codice stesso se il catalogo non è ancora caricato o il codice è ignoto.
 */
import { memoFetchJson } from "./api-memo.js";

/** Route pubblica del catalogo (CurriculumController::index). */
const CURRICULUM_URL = "/curriculum";
const KINDS = ["indirizzi", "classi", "materie"];

/** @type {{indirizzi:Map<string,string>,classi:Map<string,string>,materie:Map<string,string>}} */
const _labels = { indirizzi: new Map(), classi: new Map(), materie: new Map() };
let _loaded = false;
let _loadingPromise = null;

function _ingest(curriculum) {
    if (!curriculum || typeof curriculum !== "object") return;
    for (const kind of KINDS) {
        const list = Array.isArray(curriculum[kind]) ? curriculum[kind] : [];
        const map = _labels[kind];
        map.clear();
        for (const entry of list) {
            const code = (entry?.code ?? "").toString().trim();
            if (!code) continue;
            map.set(code.toUpperCase(), (entry?.label ?? code).toString());
        }
    }
    _loaded = true;
}

/**
 * Carica (memoizzato) il catalogo curriculum dell'istituto attivo.
 * @param {boolean} force  bypassa la cache.
 * @returns {Promise<typeof _labels>}
 */
export async function loadCurriculum(force = false) {
    if (_loaded && !force) return _labels;
    if (_loadingPromise && !force) return _loadingPromise;
    _loadingPromise = memoFetchJson(CURRICULUM_URL, { ttl: 60_000, force })
        .then((data) => { _ingest(data?.curriculum); return _labels; })
        .catch(() => _labels)
        .finally(() => { _loadingPromise = null; });
    return _loadingPromise;
}

/**
 * Label dinamico per un codice. Fallback al codice stesso (graceful) — MAI una
 * mappa hardcoded. Es. labelFor("indirizzi","SCI") → "Scientifico".
 * @param {"indirizzi"|"classi"|"materie"} kind
 * @param {string} code
 * @returns {string}
 */
export function labelFor(kind, code) {
    const c = (code ?? "").toString().trim();
    if (!c) return "";
    return _labels[kind]?.get(c.toUpperCase()) ?? c;
}

/** Tutti i codici noti per un kind (UPPER). @returns {string[]} */
export function codesFor(kind) {
    return Array.from(_labels[kind]?.keys() ?? []);
}

/**
 * Primo codice disponibile per un kind — per i DEFAULT dinamici (rimpiazza i
 * vecchi default hardcoded tipo "SCI"/"2"/"MAT"). Stringa vuota se ignoto.
 * @returns {string}
 */
export function firstCode(kind) {
    const it = _labels[kind]?.keys().next();
    return it && !it.done ? it.value : "";
}

// Preload best-effort al boot: popola la cache così i lookup sincroni
// (labelFor) funzionano senza attendere. Non blocca il rendering.
if (typeof document !== "undefined") {
    const kick = () => { loadCurriculum().catch(() => {}); };
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", kick, { once: true });
    } else {
        kick();
    }
}

if (typeof window !== "undefined") {
    window.FM = window.FM || {};
    window.FM.Curriculum = { load: loadCurriculum, labelFor, codesFor, firstCode };
}
