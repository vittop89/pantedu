/**
 * Phase 24.73 / 24.76 — Override etichette categoria PER-DOCENTE, trasversale a
 * tutti i panel category-grouped (risdoc, bes, verif, sezioni custom).
 *
 * Persistenza: DB (teacher_category_labels) → il nome segue il docente
 * CROSS-DISPOSITIVO. localStorage (chiave fm.sidepage.catLabels.<user>) resta
 * come CACHE SINCRONA: i renderer leggono getOverrides() in modo sincrono al
 * render; hydrate() rinfresca dal DB in background e, se cambia qualcosa,
 * emette `fm:category-labels-hydrated` per ri-renderizzare i pannelli aperti.
 *
 * Fallback legacy alla vecchia chiave risdoc per non perdere le rinomine già
 * fatte. logout-cleanup pulisce il prefisso fm.sidepage.
 */

import { fetchCsrf } from "../core/dom-utils.js";

const LEGACY_GLOBAL = "fm.risdoc.catLabels";

function user() {
    return window.FM?.user?.username || "";
}
function storageKey() {
    const u = user();
    return u ? `fm.sidepage.catLabels.${u}` : "fm.sidepage.catLabels";
}
function legacyKey() {
    const u = user();
    return u ? `fm.risdoc.catLabels.${u}` : LEGACY_GLOBAL;
}

// Cache in-memory (null = non ancora idratata dal DB; oggetto = idratata).
let _cache = null;
let _hydrating = null;

function readLocal() {
    try {
        let raw = localStorage.getItem(storageKey());
        if (!raw) raw = localStorage.getItem(legacyKey()) || localStorage.getItem(LEGACY_GLOBAL);
        if (!raw) return {};
        const parsed = JSON.parse(raw);
        return parsed && typeof parsed === "object" ? parsed : {};
    } catch (_) { return {}; }
}
function writeLocal(all) {
    try { localStorage.setItem(storageKey(), JSON.stringify(all)); } catch (_) {}
}

/** Sincrono: cache DB se idratata, altrimenti localStorage (istantaneo). */
export function getOverrides() {
    return _cache || readLocal();
}

export function setLabel(category, label) {
    const all = { ...(_cache || readLocal()) };
    const val = label == null ? "" : String(label).trim();
    if (val === "") delete all[category];
    else all[category] = val;
    _cache = all;
    writeLocal(all);
    // Persisti su DB (fire-and-forget; localStorage resta cache locale).
    (async () => {
        try {
            const csrf = await fetchCsrf();
            await fetch("/api/teacher/category-labels", {
                method: "POST", credentials: "same-origin",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({ _csrf: csrf, category, label: val }).toString(),
            });
        } catch (_) { /* offline/guest: resta in localStorage */ }
    })();
    return all;
}

/** Etichetta effettiva: override docente → fallback (default/label) → key. */
export function labelOf(category, fallback) {
    const o = getOverrides();
    if (o[category]) return o[category];
    return fallback != null && fallback !== "" ? fallback : category;
}

/**
 * Idrata la cache dal DB (una volta). Su differenza rispetto a quanto già
 * mostrato, emette fm:category-labels-hydrated → i pannelli aperti si
 * ri-renderizzano con le etichette autoritative (utile al primo accesso da
 * un nuovo dispositivo, dove localStorage è vuoto).
 */
export function hydrate() {
    if (_hydrating) return _hydrating;
    _hydrating = (async () => {
        try {
            // Guest: endpoint teacher-only → evita 401 (resta localStorage/server-render).
            if (document.querySelector('nav.sidebar[data-fm-guest="1"]')) return;
            const r = await fetch("/api/teacher/category-labels?_=" + Date.now(), {
                credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" },
            });
            if (!r.ok) return;
            const j = await r.json();
            const fromDb = (j && j.labels && typeof j.labels === "object") ? j.labels : {};
            const before = JSON.stringify(_cache || readLocal());
            _cache = fromDb;
            writeLocal(fromDb);
            if (JSON.stringify(fromDb) !== before) {
                document.dispatchEvent(new CustomEvent("fm:category-labels-hydrated"));
            }
        } catch (_) { /* guest/offline: cache = localStorage */ }
    })();
    return _hydrating;
}

window.FM = window.FM || {};
window.FM.CategoryLabels = { getOverrides, setLabel, labelOf, hydrate };

// Auto-idratazione una volta a load (deferita). Per i guest/studenti il GET
// ritorna {} → no-op. Re-idrata a ogni navigazione SPA (fm:navigated) NO:
// basta una volta + l'evento di reload gestisce i pannelli aperti.
if (typeof document !== "undefined") {
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", () => hydrate());
    } else {
        queueMicrotask(() => hydrate());
    }
}
