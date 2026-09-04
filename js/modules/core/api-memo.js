/**
 * api-memo — module-level memoization + in-flight Promise dedup per GET.
 *
 * Risolve il pattern "stesso URL fetchato 4-7 volte per page load" osservato
 * su /studio/esercizio (origins.json, sources.json, header-page.json).
 *
 * Strategia:
 * - In-flight Map<url, Promise>: due chiamate parallele condividono Promise
 *   → 1 fetch network anziché N.
 * - TTL cache: dopo resolve, response cached per `ttl` ms. Successive
 *   chiamate entro TTL ritornano cached senza fetch.
 * - Invalidate manuale per write-after-read (es. PUT sources.json).
 *
 * Usage:
 *   import { memoFetchJson, invalidateMemo } from "../core/api-memo.js";
 *   const sources = await memoFetchJson("/api/teacher/sources.json", { ttl: 30_000 });
 *   // ...later, after PUT
 *   invalidateMemo("/api/teacher/sources.json");
 */

const _cache = new Map(); // url -> { promise, value, ts, ttl }

/** Default TTL 30s — JSON config endpoints stabili per page session. */
const DEFAULT_TTL_MS = 30_000;

/**
 * Fetch JSON con memoization + in-flight dedup.
 *
 * @param {string} url
 * @param {object} [opts]
 * @param {number} [opts.ttl=30000] TTL cache in ms
 * @param {boolean} [opts.force=false] Skip cache, ri-fetch ed update.
 * @param {string} [opts.credentials="same-origin"]
 * @returns {Promise<any>}
 */
export async function memoFetchJson(url, opts = {}) {
    const ttl = opts.ttl ?? DEFAULT_TTL_MS;
    const force = !!opts.force;
    const now = Date.now();
    const entry = _cache.get(url);

    if (!force && entry) {
        // In-flight: return shared pending Promise.
        if (entry.promise) return entry.promise;
        // TTL valid: return cached resolved value wrapped in Promise.
        if (entry.value !== undefined && now - entry.ts < ttl) {
            return Promise.resolve(entry.value);
        }
    }

    const credentials = opts.credentials ?? "same-origin";
    const promise = fetch(url, { credentials })
        .then((r) => {
            if (!r.ok) throw new Error(`HTTP ${r.status} ${url}`);
            return r.json();
        })
        .then((value) => {
            _cache.set(url, { value, ts: Date.now(), ttl });
            return value;
        })
        .catch((err) => {
            // Clear in-flight on error so retry possible.
            const e = _cache.get(url);
            if (e && !e.value) _cache.delete(url);
            throw err;
        });

    _cache.set(url, { promise, ts: now, ttl });
    return promise;
}

/** Invalidate cache entry per URL (chiamare dopo PUT/POST/DELETE). */
export function invalidateMemo(url) {
    _cache.delete(url);
}

/** Clear intero cache (es. logout, route change major). */
export function clearMemo() {
    _cache.clear();
}

// Expose for inter-module access / debugging.
if (typeof window !== "undefined") {
    window.FM = window.FM || {};
    window.FM.memoFetchJson = memoFetchJson;
    window.FM.invalidateMemo = invalidateMemo;
}
