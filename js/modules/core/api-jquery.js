/**
 * Api (ex ApiJQuery) — wrapper fetch + CSRF con helpers specifici
 * (checkIfPageExists, checkFileProtection, fetchHtmlTemplate,
 * filesInVerifiche, deleteFile, getProcessedHtmlContent, ecc.).
 *
 * G26.phase3 — Migrato da $.ajax (jQuery) a fetch (vanilla). Stesso
 * API surface esposta a window.ApiJQuery / window.FM.ApiJQuery /
 * window.Api per compat con i 31 file legacy che ancora chiamano i
 * suoi metodi. Quando tutti i caller saranno migrati, rinominare in
 * `api-legacy.js` o consolidare con `api.js` (fetch-based primitives).
 *
 * CSRF token cache — rinfrescato quando 403+csrf_invalid o prima POST.
 * Condiviso col modulo ./api.js via /auth/csrf endpoint.
 */
import { Endpoints } from "./endpoints.js";
import { fetchCsrf, invalidateCsrfCache } from "./dom-utils.js";

// CSRF token cache (sync getter per il fast-path di _fetch).
let _csrfToken = null;

/** Sync CSRF getter: cache locale o `<meta name="csrf-token">`. NON fa più
 *  rete sincrona (l'XHR bloccante è stato rimosso, 2026-06-03): il refresh
 *  post-rotazione avviene async via `_refreshCsrf()` nel ramo di retry di
 *  `_fetch`, che gira già in contesto async. Ritorna "" se non disponibile. */
function _getCsrfSync() {
    if (_csrfToken) return _csrfToken;
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta?.content) { _csrfToken = meta.content; return _csrfToken; }
    return "";
}

/** Refresh async del token CSRF dopo una rotazione (419). Delega al canonico
 *  `dom-utils.fetchCsrf` (cache 60s condivisa con api.js + invalidate su
 *  fm:navigated), aggiorna cache locale + `<meta>`. */
async function _refreshCsrf() {
    invalidateCsrfCache();
    const t = await fetchCsrf();
    _csrfToken = t || null;
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && t) meta.setAttribute("content", t);
    return t;
}

function _rotateCsrf() {
    _csrfToken = null;
}

/** Serializza `data` per body x-www-form-urlencoded (compat $.ajax default).
 *  Accetta:
 *   - null/undefined → ""
 *   - string → returned as-is
 *   - FormData → returned as-is
 *   - object plain → URLSearchParams */
function _serializeBody(data) {
    if (data == null) return null;
    if (typeof data === "string") return data;
    if (data instanceof FormData) return data;
    if (data instanceof URLSearchParams) return data.toString();
    if (typeof data === "object") {
        // Spread oggetti nested $.ajax style: per ogni key, se value è
        // array → ripeti param (es. tags[]); se object → JSON.stringify;
        // altrimenti String(value).
        const params = new URLSearchParams();
        for (const [k, v] of Object.entries(data)) {
            if (Array.isArray(v)) {
                v.forEach((item) => params.append(k, item));
            } else if (v != null && typeof v === "object") {
                params.append(k, JSON.stringify(v));
            } else if (v != null) {
                params.append(k, String(v));
            }
        }
        return params.toString();
    }
    return String(data);
}

export const ApiJQuery = {
    /** Core fetch wrapper con CSRF rotate-on-fail. Stessa signature legacy:
     *  `_fetch(url, type, data)` ritorna Promise<json|string>. */
    _fetch(url, type, data, _isRetry) {
        const method = (type || "GET").toUpperCase();
        const headers = {};
        let body = null;
        if (method !== "GET" && method !== "HEAD") {
            const token = _getCsrfSync();
            if (token) headers["X-CSRF-Token"] = token;
            const serialized = _serializeBody(data);
            if (serialized != null) {
                body = serialized;
                if (typeof body === "string") {
                    headers["Content-Type"] = "application/x-www-form-urlencoded; charset=UTF-8";
                }
            }
        }
        // GET con data: serializza come query string
        let fullUrl = url;
        if ((method === "GET" || method === "HEAD") && data != null) {
            const serialized = _serializeBody(data);
            if (serialized && typeof serialized === "string") {
                fullUrl = url + (url.includes("?") ? "&" : "?") + serialized;
            }
        }
        // Cache-bust: $.ajax aveva `cache: false` di default su GET → aggiunge `_=timestamp`
        if ((method === "GET" || method === "HEAD")) {
            fullUrl = fullUrl + (fullUrl.includes("?") ? "&" : "?") + "_=" + Date.now();
        }
        return fetch(fullUrl, {
            method,
            headers,
            body,
            credentials: "same-origin",
        }).then(async (response) => {
            const responseText = await response.text();
            // CSRF retry: 419 o 403/500 con responseText csrf_invalid
            const isCsrfFail = !_isRetry && method !== "GET" && method !== "HEAD"
                && (response.status === 419
                    || (response.status === 403 && /csrf_invalid/i.test(responseText))
                    || (response.status === 500 && /csrf_invalid/i.test(responseText)));
            if (isCsrfFail) {
                console.debug(`[Api._fetch] CSRF stale → rotate + retry ${url}`);
                _rotateCsrf();
                await _refreshCsrf();
                return this._fetch(url, type, data, true);
            }
            if (!response.ok) {
                const isExpected400 = response.status === 400
                    && /not_a_directory|invalid_path|missing/i.test(responseText);
                if (!isExpected400) {
                    console.warn(`[Api._fetch] fetch fail ${url}:`, {
                        status: response.status,
                        responseText: responseText.slice(0, 200),
                    });
                }
                const err = new Error(`Chiamata a ${url} fallita: HTTP ${response.status}`);
                err.status = response.status;
                err.responseText = responseText;
                err.expected = isExpected400;
                throw err;
            }
            // $.ajax default dataType: tenta JSON, fallback text
            const ct = response.headers.get("content-type") || "";
            if (ct.includes("application/json")) {
                try { return JSON.parse(responseText); }
                catch (_) { return responseText; }
            }
            return responseText;
        });
    },

    /** Verifica esistenza pagina via HEAD. 401/403 considerati "exists"
     *  (page protetta, ma esistente). Implementazione vanilla (era già
     *  fetch-based nel legacy). */
    async checkIfPageExists(url) {
        try {
            const response = await fetch(url, { method: "HEAD" });
            return response.ok || response.status === 401 || response.status === 403;
        } catch (error) {
            console.error(`Errore nel verificare l'esistenza di ${url}:`, error);
            return false;
        }
    },

    checkFileProtection(fileUrl) {
        return this._fetch(Endpoints.check.fileProtection, "POST", { fileUrl });
    },

    /** Phase 18 — getServerFiles / getJsonLinks + _cachedPost rimossi:
     *  sidepage ora DB-only via db-sidepage.js. */

    fetchHtmlTemplate(url) {
        return this._fetch(url, "GET", null);
    },

    /** Phase 18 — filesInVerifiche ora interroga DB `teacher_content`
     *  (content_type=verifica) invece del filesystem /verifiche/php/*. */
    async filesInVerifiche() {
        const verfilenames = { MAT: [], GEO: [], FIS: [] };
        try {
            for (const subj of ["MAT", "GEO", "FIS"]) {
                const qs = new URLSearchParams({ type: "verifica", subject: subj });
                const res = await fetch(`/api/study/topics.json?${qs}`, { credentials: "same-origin" });
                if (!res.ok) continue;
                const j = await res.json();
                verfilenames[subj] = (j.topics || []).map((t) => String(t.topic || "").trim()).filter(Boolean);
            }
            return verfilenames;
        } catch (e) {
            console.error("[Api] filesInVerifiche DB query failed:", e);
            return verfilenames;
        }
    },

    deleteFile(data) {
        return this._fetch(Endpoints.files.deleteFile, "POST", data);
    },

    /** Phase 18 — createFile + saveExternalLink deprecati. */
    createFile() {
        console.warn("[Api.createFile] DEPRECATED Phase 18. Usa POST /api/teacher/content");
        return Promise.reject(new Error("deprecated_endpoint_phase18"));
    },
    saveExternalLink() {
        console.warn("[Api.saveExternalLink] DEPRECATED Phase 18. Usa PUT /api/teacher/sources.json");
        return Promise.reject(new Error("deprecated_endpoint_phase18"));
    },

    async getProcessedHtmlContent(templateUrl, argomento, shouldProtectFile = false) {
        try {
            const pageContent = await this.fetchHtmlTemplate(templateUrl);

            if (!pageContent || pageContent.trim() === "") {
                console.error(`❌ Contenuto vuoto ricevuto da ${templateUrl}`);
                console.groupEnd();
                return null;
            }

            const parser = new DOMParser();
            const doc = parser.parseFromString(pageContent, "text/html");

            const parserError = doc.querySelector("parsererror");
            if (parserError) {
                console.error(`❌ Errore nel parsing del contenuto da ${templateUrl}:`, parserError.textContent);
                console.groupEnd();
                return null;
            }

            const isVerifica = templateUrl.includes("_ver");
            const selector = isVerifica ? ".pagestyle_ver" : "html";
            const mainContainer = doc.querySelector(selector);

            if (!mainContainer) {
                console.error(`❌ Contenitore principale non trovato con selettore: ${selector}`);
                console.groupEnd();
                return null;
            }

            const isListSidebar = templateUrl.includes("modello_pag_listSidebar.php");
            const isRisdocToTeX = templateUrl.includes("modello_pag_risdocToTeX.php");
            const isEserTemplate = templateUrl.includes("modello_pag_esercizi.php");
            const isStrcompBesAltro = templateUrl.includes("modello_pag_listSidebar-strcomp_bes_altro.php");

            if (isListSidebar || isStrcompBesAltro) {
                const phpAuthCode = shouldProtectFile
                    ? `<?php include_once $_SERVER['DOCUMENT_ROOT'] . '/log/auth/AuthCode.php'; ?>\n`
                    : "";
                const result = `${phpAuthCode}<!DOCTYPE html>${mainContainer.outerHTML}`;
                console.groupEnd();
                return result;
            }

            let titoloElement = mainContainer.querySelector(".fm-titolo h1") || mainContainer.querySelector(".title");

            if (!titoloElement) {
                const titoloContainer = mainContainer.querySelector(".fm-titolo");
                if (titoloContainer) {
                    titoloElement = doc.createElement("h1");
                    titoloContainer.appendChild(titoloElement);
                } else if (isRisdocToTeX) {
                    const headerTitleDiv = mainContainer.querySelector(".header-title .title");
                    if (headerTitleDiv) {
                        titoloElement = headerTitleDiv;
                    } else {
                        const phpAuthCode = shouldProtectFile
                            ? `<?php include_once $_SERVER['DOCUMENT_ROOT'] . '/log/auth/AuthCode.php'; ?>\n`
                            : "";
                        const result = `${phpAuthCode}<!DOCTYPE html>${mainContainer.outerHTML}`;
                        console.groupEnd();
                        return result;
                    }
                } else {
                    console.error("❌ Elemento titolo non trovato (.fm-titolo h1 o .title).");
                    console.groupEnd();
                    return null;
                }
            }

            if (isVerifica) {
                titoloElement.textContent = `${argomento} (verifica)`;
                const result = mainContainer.innerHTML;
                console.groupEnd();
                return result;
            }
            titoloElement.innerHTML = argomento;

            let result = `<!DOCTYPE html>${mainContainer.outerHTML}`;

            if (shouldProtectFile && (isRisdocToTeX || isEserTemplate)) {
                const phpAuthCode = `<?php include_once $_SERVER['DOCUMENT_ROOT'] . '/log/auth/AuthCode.php'; ?>\n`;
                result = phpAuthCode + result;
            }

            console.groupEnd();
            return result;
        } catch (error) {
            console.error(`❌ Errore in getProcessedHtmlContent per ${templateUrl}:`, error);
            console.groupEnd();
            return null;
        }
    },
};

window.FM = window.FM || {};
window.FM.ApiJQuery = ApiJQuery;
window.ApiJQuery    = ApiJQuery;
window.Api          = ApiJQuery;
