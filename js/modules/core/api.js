/**
 * Wrapper fetch + CSRF — usato dai moduli ES6 nuovi (es. print-client).
 *
 * Pattern:
 *   import { Api } from "/js/modules/core/api.js";
 *   const tex = await Api.postBlob("/teacher/print", { selections });
 *
 * Carica il token CSRF lazy via /auth/csrf alla prima POST e lo
 * inietta in `X-CSRF-Token` header. Il legacy script.js continua a
 * usare `Api` definito in `script.js`: questo modulo è
 * complementare, non sostituisce.
 */

// CSRF: delega al canonico dom-utils (cache 60s condivisa + invalidate su
// fm:navigated). Unico store CSRF lato JS — coerente con api-jquery.js.
import { fetchCsrf, invalidateCsrfCache } from "./dom-utils.js";

const getCsrf = fetchCsrf;
const rotateCsrf = invalidateCsrfCache;

async function _fetch(url, opts = {}) {
    // Phase 20 — ordine corretto: `...opts` PRIMA, poi override di
    // credentials/headers. Prima `...opts` spreadato dopo `headers`
    // sovrascriveva le headers merged (Accept+X-Requested-With perse,
    // lasciando solo opts.headers → role middleware vedeva accept vuoto
    // e ritornava 403 in HTML invece di JSON).
    const init = {
        ...opts,
        credentials: "same-origin",
        headers: {
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
            ...(opts.headers || {}),
        },
    };
    const method = (init.method || "GET").toUpperCase();
    if (method !== "GET" && method !== "HEAD") {
        init.headers["X-CSRF-Token"] = await getCsrf();
    }
    const res = await fetch(url, init);
    if (res.status === 419 && !opts._retry && (method !== "GET" && method !== "HEAD")) {
        // CSRF rifiutato (419 standard Laravel-like). 403 NO — può essere
        // role/policy; in quel caso retry è inutile e sprecherebbe round-trip.
        rotateCsrf();
        init.headers["X-CSRF-Token"] = await getCsrf();
        return _fetch(url, { ...opts, _retry: true });
    }
    return res;
}

export const Api = {
    /** GET → JSON */
    async getJson(url) {
        const res = await _fetch(url);
        if (!res.ok) throw new Error(`http_${res.status}`);
        return res.json();
    },

    /** POST JSON body → JSON response */
    async postJson(url, body) {
        const res = await _fetch(url, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(body || {}),
        });
        if (!res.ok) {
            let err = `http_${res.status}`;
            try { err = (await res.json()).error || err; } catch (_) {}
            throw new Error(err);
        }
        return res.json();
    },

    /** PUT JSON body → JSON response */
    async putJson(url, body) {
        const res = await _fetch(url, {
            method: "PUT",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(body || {}),
        });
        if (!res.ok) {
            let err = `http_${res.status}`;
            try { err = (await res.json()).error || err; } catch (_) {}
            throw new Error(err);
        }
        return res.json();
    },

    /** PATCH JSON body → JSON response */
    async patchJson(url, body) {
        const res = await _fetch(url, {
            method: "PATCH",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(body || {}),
        });
        if (!res.ok) {
            let err = `http_${res.status}`;
            try { err = (await res.json()).error || err; } catch (_) {}
            throw new Error(err);
        }
        return res.json();
    },

    /** POST JSON body → Blob (per download .tex/.pdf) */
    async postBlob(url, body) {
        const res = await _fetch(url, {
            method: "POST",
            headers: { "Content-Type": "application/json", Accept: "*/*" },
            body: JSON.stringify(body || {}),
        });
        if (!res.ok) {
            let err = `http_${res.status}`;
            try { err = (await res.json()).error || err; } catch (_) {}
            throw new Error(err);
        }
        return {
            blob:     await res.blob(),
            filename: parseFilename(res.headers.get("Content-Disposition")),
        };
    },

    /** POST form-urlencoded → JSON (per legacy endpoint compat) */
    async postForm(url, fields) {
        const form = new URLSearchParams();
        for (const [k, v] of Object.entries(fields || {})) form.set(k, v);
        const res = await _fetch(url, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: form.toString(),
        });
        if (!res.ok) {
            let err = `http_${res.status}`;
            try { err = (await res.json()).error || err; } catch (_) {}
            throw new Error(err);
        }
        return res.json();
    },

    rotateCsrf,
};

function parseFilename(disposition) {
    if (!disposition) return null;
    const m = /filename\s*=\s*"?([^";]+)"?/i.exec(disposition);
    return m ? decodeURIComponent(m[1]) : null;
}

window.FM = window.FM || {};
window.FM.Api = Api;
