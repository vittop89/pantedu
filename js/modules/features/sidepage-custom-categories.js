/**
 * Custom-categories storage condiviso (Phase 24.72).
 *
 * Categorie utente-definite per sidepage che dichiarano `customCategories:true`
 * nel registry (verif/bes/risdoc). Persistite in localStorage scoped per:
 *   - username  (chiave prefissata, no leak cross-utente sullo stesso browser)
 *   - scope di cui il docente sceglie la stretta (sempre / indirizzo /
 *     indirizzo+classe / indirizzo+classe+materia)
 *   - bucket logico (origin per risdoc-loader, type per db-loader)
 *
 * Schema:
 *   {
 *     "RECUPERO": { label:"Recupero", ind?, cls?, subj?, bucket:"verifica" },
 *     "ORALE":    { label:"Orale",    bucket:"verifica" },
 *     "STAMPA":   { label:"Stampa",   ind:"sc", cls:"3s", bucket:"strcomp" }
 *   }
 *
 * Le chiavi sono UPPERCASE A-Z0-9_. La label è il testo mostrato nell'header
 * del .fm-db-block. La presenza di ind/cls/subj NON è inferita: viene scelta
 * dall'utente al momento della creazione (prompt "Visibilità scope").
 *
 * Storage key (per-utente, con fallback legacy globale per backward-compat):
 *   "fm.sidepage.customCategories.<username>"
 *
 * Phase 24.72: estratto da risdoc-sidepage.js per condividerlo con db-sidepage.
 * Migra automaticamente la vecchia chiave "fm.risdoc.customCategories.*" a
 * "fm.sidepage.customCategories.*" la prima volta che viene letta.
 */

const KEY_LEGACY_RISDOC_GLOBAL = "fm.risdoc.customCategories";
const KEY_LEGACY_RISDOC_PREFIX = "fm.risdoc.customCategories.";   // + username
const KEY_PREFIX_NEW           = "fm.sidepage.customCategories."; // + username
const KEY_GLOBAL_NEW           = "fm.sidepage.customCategories";

function username() {
    return (typeof window !== "undefined" && window.FM?.user?.username) || "";
}

function storageKey() {
    const u = username();
    return u ? `${KEY_PREFIX_NEW}${u}` : KEY_GLOBAL_NEW;
}

function migrateLegacyOnce(targetKey) {
    if (typeof localStorage === "undefined") return;
    if (localStorage.getItem(targetKey)) return;
    const u = username();
    const sources = u
        ? [`${KEY_LEGACY_RISDOC_PREFIX}${u}`, KEY_LEGACY_RISDOC_GLOBAL]
        : [KEY_LEGACY_RISDOC_GLOBAL];
    for (const k of sources) {
        const raw = localStorage.getItem(k);
        if (raw) { localStorage.setItem(targetKey, raw); return; }
    }
}

/**
 * Legge tutte le custom categories dell'utente corrente.
 * @returns {Record<string, {label:string, ind?:string, cls?:string, subj?:string, bucket?:string}>}
 */
export function loadAll() {
    if (typeof localStorage === "undefined") return {};
    try {
        const k = storageKey();
        migrateLegacyOnce(k);
        const raw = localStorage.getItem(k);
        if (!raw) return {};
        const parsed = JSON.parse(raw);
        return parsed && typeof parsed === "object" ? parsed : {};
    } catch (_) { return {}; }
}

/** @param {Record<string, object>} all */
export function saveAll(all) {
    if (typeof localStorage === "undefined") return;
    try { localStorage.setItem(storageKey(), JSON.stringify(all)); } catch (_) {}
}

/**
 * Filtra le custom categories per:
 *  - bucket   : risdoc-loader passa origin (risdoc/strcomp), db-loader passa type (verifica)
 *  - selezione corrente di indirizzo / classe / materia (se cfg lo specifica)
 * Una categoria senza ind/cls/subj è sempre visibile. Una categoria con scope
 * stretto richiede match esatto.
 *
 * @param {object} opts
 * @param {string} opts.bucket
 * @param {string=} opts.ind
 * @param {string=} opts.cls
 * @param {string=} opts.subj
 * @returns {Array<{key:string, label:string, ind?:string, cls?:string, subj?:string}>}
 */
export function listForBucket({ bucket, ind = "", cls = "", subj = "" }) {
    const all = loadAll();
    const out = [];
    for (const [key, cfg] of Object.entries(all)) {
        if (!cfg || typeof cfg !== "object") continue;
        if (bucket && cfg.bucket && cfg.bucket !== bucket) continue;
        if (cfg.ind  && cfg.ind  !== ind)  continue;
        if (cfg.cls  && cfg.cls  !== cls)  continue;
        if (cfg.subj && cfg.subj !== subj) continue;
        out.push({ key, ...cfg });
    }
    return out;
}

/**
 * Crea (o sovrascrive) una categoria custom per il bucket dato.
 *
 * @param {object} opts
 * @param {string} opts.key        chiave UPPERCASE
 * @param {string} opts.label      etichetta visualizzata
 * @param {string} opts.bucket     origin (risdoc-loader) o type (db-loader)
 * @param {"any"|"ind"|"ind+cls"|"ind+cls+subj"} opts.scope
 * @param {string=} opts.ind
 * @param {string=} opts.cls
 * @param {string=} opts.subj
 */
export function create({ key, label, bucket, scope = "ind+cls", ind = "", cls = "", subj = "" }) {
    const k = String(key || "").trim().toUpperCase().replace(/[^A-Z0-9_]/g, "_").slice(0, 24);
    if (!k) throw new Error("invalid_key");
    const cfg = { label: String(label || k).trim(), bucket: String(bucket || "") };
    if (scope === "ind" || scope === "ind+cls" || scope === "ind+cls+subj") cfg.ind = ind;
    if (scope === "ind+cls" || scope === "ind+cls+subj")                    cfg.cls = cls;
    if (scope === "ind+cls+subj")                                            cfg.subj = subj;
    if (cfg.ind  && !ind)  throw new Error("missing_ind");
    if (cfg.cls  && !cls)  throw new Error("missing_cls");
    if (cfg.subj && !subj) throw new Error("missing_subj");
    const all = loadAll();
    all[k] = cfg;
    saveAll(all);
    return k;
}

/** @param {string} key */
export function remove(key) {
    const all = loadAll();
    if (key in all) { delete all[key]; saveAll(all); return true; }
    return false;
}

/**
 * Prompt UI per creare una categoria. Helper riusabile (gestisce input + scope).
 * Ritorna la chiave creata o `null` se annullato.
 *
 * @param {object} opts
 * @param {string} opts.bucket
 * @param {string=} opts.ind
 * @param {string=} opts.cls
 * @param {string=} opts.subj
 * @returns {string|null}
 */
export async function promptCreate({ bucket, ind = "", cls = "", subj = "" }) {
    const rawKey = await window.FM.Dialog.prompt("Chiave categoria (maiuscola, breve, es. RECUPERO):", "");
    if (!rawKey) return null;
    const label = await window.FM.Dialog.prompt("Etichetta visualizzata (default = chiave):", rawKey.toUpperCase()) || rawKey;
    const scopeChoice = await window.FM.Dialog.prompt(
        "Visibilità scope:\n"
      + "  1 = sempre (qualsiasi indirizzo/classe/materia)\n"
      + "  2 = solo questo INDIRIZZO\n"
      + "  3 = solo questo INDIRIZZO+CLASSE (qualsiasi materia)\n"
      + "  4 = solo questa combinazione INDIRIZZO+CLASSE+MATERIA",
        "3"
    );
    if (scopeChoice === null) return null;
    const scope = scopeChoice === "1" ? "any"
                : scopeChoice === "2" ? "ind"
                : scopeChoice === "4" ? "ind+cls+subj"
                : "ind+cls";
    try {
        return create({ key: rawKey, label, bucket, scope, ind, cls, subj });
    } catch (e) {
        const msg = ({
            invalid_key:  "Chiave non valida.",
            missing_ind:  "Seleziona indirizzo prima.",
            missing_cls:  "Seleziona classe prima.",
            missing_subj: "Seleziona materia prima.",
        })[e.message] || (`Errore: ${  e.message}`);
        alert(msg);
        return null;
    }
}

// Espone su window.FM per consumer non-module (debug / E2E).
if (typeof window !== "undefined") {
    window.FM = window.FM || {};
    window.FM.SidepageCustomCategories = {
        loadAll, saveAll, listForBucket, create, remove, promptCreate,
    };
}
