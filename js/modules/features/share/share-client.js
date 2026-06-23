/**
 * G22.S25 — Client unificato per il sistema di condivisione contenuti.
 *
 * Centralizza:
 *   - toggleSharePool(source, id, enabled)      — flag 🤝 (teacher_content
 *                                                 o verifica_documents)
 *   - listGrants(source, id)                    — grants espliciti correnti
 *   - setGrants(source, id, grants)             — upsert grants polimorfici
 *   - getColleagues() / getGroups() / getInstitutes()  — con TTL cache
 *
 * Sostituisce fetch ad-hoc sparsi in sidepage-modal-content.js,
 * verifica-detail-modal.js, share-grants-popup.js. Tutti i mutator
 * (POST) usano CSRF token via fetchCsrf().
 *
 * Cache TTL: liste colleghi/gruppi/istituti cambiano raramente
 * → 60s in-memory cache request-scoped riduce 3 fetch a ogni
 * apertura del popup share-grants.
 */

import { fetchCsrf } from "../../core/dom-utils.js";

const CACHE_TTL_MS = 60_000;

const _cache = new Map(); // key → { value, expires }

function _cacheGet(key) {
    const entry = _cache.get(key);
    if (!entry) return null;
    if (entry.expires < Date.now()) {
        _cache.delete(key);
        return null;
    }
    return entry.value;
}

function _cacheSet(key, value) {
    _cache.set(key, { value, expires: Date.now() + CACHE_TTL_MS });
}

/** Invalida cache: usare dopo mutazioni che modificano gruppi/colleghi. */
export function invalidateShareCache(prefix = "") {
    if (!prefix) {
        _cache.clear();
        return;
    }
    for (const key of _cache.keys()) {
        if (key.startsWith(prefix)) _cache.delete(key);
    }
}

async function _getJson(url) {
    const cached = _cacheGet(url);
    if (cached) return cached;
    const r = await fetch(url, { credentials: "same-origin" });
    if (!r.ok) throw new Error(`${url} → ${r.status}`);
    const j = await r.json();
    _cacheSet(url, j);
    return j;
}

/* ─── Read-only list endpoints (TTL cached) ──────────────────────────── */

export async function getColleagues() {
    const j = await _getJson("/api/teacher/share/colleagues");
    return j.colleagues || [];
}

export async function getGroups() {
    const j = await _getJson("/api/teacher/share/groups");
    return j.groups || [];
}

export async function getInstitutes() {
    const j = await _getJson("/api/teacher/institutes");
    return j.institutes || [];
}

/* ─── Per-content grants (NOT cached: per-id varia spesso) ───────────── */

const SOURCES = ["teacher_content", "verifica_documents"];

function _assertSource(source) {
    if (!SOURCES.includes(source)) {
        throw new Error(`Invalid share source: "${source}"`);
    }
}

export async function listGrants(source, id) {
    _assertSource(source);
    const r = await fetch(`/api/teacher/share/grants/${source}/${id}`, {
        credentials: "same-origin",
    });
    if (!r.ok) throw new Error(`listGrants ${source}/${id} → ${r.status}`);
    const j = await r.json();
    return j.grants || [];
}

/* ─── Mutators (CSRF required) ───────────────────────────────────────── */

export async function setGrants(source, id, grants) {
    _assertSource(source);
    const tok = await fetchCsrf();
    const r = await fetch(`/api/teacher/share/grants/${source}/${id}`, {
        method: "POST",
        credentials: "same-origin",
        headers: { "X-CSRF-Token": tok, "Content-Type": "application/json" },
        body: JSON.stringify({ grants, _csrf: tok }),
    });
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || "set_grants_failed");
    return j;
}

/**
 * Toggle flag `shared_with_pool` su una row.
 *
 * Endpoint dipende dal source:
 *   - teacher_content    → /api/teacher/content/{id}/share-pool
 *   - verifica_documents → /api/verifica/{id}/share-pool
 *
 * Entrambi accettano form-encoded `enabled=0|1`. Risposta JSON
 * `{ok, id, shared_with_pool, counterpart_id?, counterpart_type?}`.
 */
export async function toggleSharePool(source, id, enabled) {
    _assertSource(source);
    const url = source === "teacher_content"
        ? `/api/teacher/content/${id}/share-pool`
        : `/api/verifica/${id}/share-pool`;
    const tok = await fetchCsrf();
    const fd = new URLSearchParams();
    fd.set("enabled", enabled ? "1" : "0");
    fd.set("_csrf", tok);
    const r = await fetch(url, {
        method: "POST",
        credentials: "same-origin",
        headers: { "X-CSRF-Token": tok, "Content-Type": "application/x-www-form-urlencoded" },
        body: fd.toString(),
    });
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || "share_pool_failed");
    return j;
}
