/**
 * DOM utilities — Phase 25.A1 (esteso 2026-06-03).
 *
 * Canonico unico per helper prima duplicati in decine di moduli:
 *   - escHtml / escAttr / esc — HTML entity escaping (htmlspecialchars ENT_QUOTES)
 *   - asElement / asElementArray — coercion a Element/Element[] (ex-shim jQuery,
 *     ramo .get() morto rimosso ora che jQuery non esiste più nel bundle)
 *   - parseMeta — JSON.parse fail-safe per metadata_json / metadata
 *   - readScopeSelects — letture #sel-iis/#sel-cls/#sel-mater
 *   - fetchCsrf — token cache 60s in-memory
 *
 * Le copie locali di esc / asElement sono state sostituite con import da qui
 * in ~30 moduli (features, ui, editor, events, state, core, entries).
 * Eccezione voluta: js/modules/editor/html-text-utils.js mantiene varianti
 * con semantica diversa (escHtmlStrict `&#039;` per ContractRenderer, escapeHtml
 * con fallback, escTexJs) — è un modulo curato, non duplicazione.
 *
 * Esporta sia named exports (ES module idiomatic) che attach a window.FM
 * per consumer non-module / E2E debug.
 */

/**
 * Encode HTML entities. Match comportamento `htmlspecialchars(ENT_QUOTES)`.
 * @param {string|number|null|undefined} s
 * @returns {string}
 */
export function escHtml(s) {
    return String(s ?? "").replace(/[&<>"']/g, (c) =>
        ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c])
    );
}

/** Alias per uso in attributi HTML. Stesso encoding (PHP `htmlspecialchars`). */
export const escAttr = escHtml;
/** Alias short. */
export const esc = escHtml;

/**
 * Parse JSON sicuro: legge `row.metadata` (oggetto già parsato) o
 * `row.metadata_json` (string). Ritorna oggetto vuoto su fail.
 * @param {{metadata?: object, metadata_json?: string}|null} row
 * @returns {object}
 */
export function parseMeta(row) {
    if (!row) return {};
    if (row.metadata && typeof row.metadata === "object") return row.metadata;
    if (typeof row.metadata_json === "string" && row.metadata_json !== "") {
        try {
            const v = JSON.parse(row.metadata_json);
            return v && typeof v === "object" ? v : {};
        } catch {
            return {};
        }
    }
    return {};
}

/**
 * Legge i 3 select sidebar che identificano lo scope corrente.
 * Skippa le option `disabled` (placeholder).
 * @returns {{ind: string, cls: string, subj: string}}
 */
export function readScopeSelects() {
    return {
        ind:  readSelect("sel-iis"),
        cls:  readSelect("sel-cls"),
        subj: readSelect("sel-mater"),
    };
}

/**
 * Legge il valore di un <select> ignorando placeholder disabled.
 * @param {string} id
 * @returns {string}
 */
export function readSelect(id) {
    const el = document.getElementById(id);
    if (!el) return "";
    const v = el.value;
    return v && !el.options[el.selectedIndex]?.disabled ? v : "";
}

// ─────── Element coercion ───────
//
// Phase 25 (2026-06-03) — canonico unico per le ~15 copie locali nate dalla
// migrazione jQuery → vanilla. Il ramo jQuery (`typeof arg.get === "function"`)
// è stato rimosso: jQuery non esiste più nel bundle, quel branch era morto.
// Superset comportamentale di tutte le varianti (string selector + Element +
// Document + array-like/NodeList), quindi drop-in per ogni call-site.

/**
 * Coerce a single Element da: selector string, Element, Document, o
 * array-like/NodeList (primo elemento). Ritorna null se non risolvibile.
 * @param {string|Element|Document|ArrayLike<Element>|null|undefined} arg
 * @returns {Element|Document|null}
 */
export function asElement(arg) {
    if (!arg) return null;
    if (typeof arg === "string") return document.querySelector(arg);
    if (arg.nodeType === 1 || arg.nodeType === 9) return arg;
    if (arg[0] && (arg[0].nodeType === 1 || arg[0].nodeType === 9)) return arg[0];
    return null;
}

/**
 * Coerce Element[] da: Array, NodeList/array-like, o singolo Element.
 * Filtra ai soli Element (nodeType 1). Ritorna [] se vuoto/non risolvibile.
 * @param {ArrayLike<Element>|Element|null|undefined} arg
 * @returns {Element[]}
 */
export function asElementArray(arg) {
    if (!arg) return [];
    if (Array.isArray(arg)) return arg.filter((e) => e && e.nodeType === 1);
    if (arg.length !== undefined && arg.nodeType !== 1) return Array.from(arg);
    if (arg.nodeType === 1) return [arg];
    return [];
}

// ─────── Misure / visibilità / eventi (replica subset jQuery, vanilla) ───────
//
// Phase 25 (2026-06-03) — canonico per helper prima duplicati 2-5× nei moduli
// ui/editor/events. Nessun ramo jQuery (erano già vanilla): pura dedup.

/** Dispatch evento bubbling (replica jQuery .trigger). */
export function trigger(el, type) {
    if (!el) return;
    el.dispatchEvent(new Event(type, { bubbles: true }));
}

/** Visible se ha layout box (offsetParent != null) o display != none. */
export function isVisible(el) {
    if (!el) return false;
    if (el.offsetParent !== null) return true;
    return getComputedStyle(el).display !== "none";
}

/** Altezza box; con includeMargin somma margin top/bottom (jQuery .outerHeight(true)). */
export function outerHeight(el, includeMargin = false) {
    if (!el) return 0;
    const rect = el.getBoundingClientRect();
    if (!includeMargin) return rect.height;
    const cs = getComputedStyle(el);
    return rect.height + (parseFloat(cs.marginTop) || 0) + (parseFloat(cs.marginBottom) || 0);
}

/** Larghezza box; con includeMargin somma margin left/right (jQuery .outerWidth(true)). */
export function outerWidth(el, includeMargin = false) {
    const e = asElement(el);
    if (!e) return 0;
    const rect = e.getBoundingClientRect();
    if (!includeMargin) return rect.width;
    const cs = getComputedStyle(e);
    return rect.width + (parseFloat(cs.marginLeft) || 0) + (parseFloat(cs.marginRight) || 0);
}

// ─────── CSRF token cache ───────
//
// Phase 25.A1 — 1 fetch per tab session (60s TTL): molti consumer
// chiamavano fetchCsrf() ripetutamente. Cache locale + invalidate al cambio
// pagina (fm:navigated).
let _csrfCache = { token: "", at: 0 };
const CSRF_TTL_MS = 60_000;

/**
 * Ritorna il CSRF token corrente. Cache in-memory 60s.
 * @returns {Promise<string>}
 */
export async function fetchCsrf() {
    const now = Date.now();
    if (_csrfCache.token && (now - _csrfCache.at) < CSRF_TTL_MS) {
        return _csrfCache.token;
    }
    try {
        const r = await fetch("/auth/csrf", { credentials: "same-origin" });
        const j = await r.json();
        _csrfCache = { token: j.token || "", at: now };
        return _csrfCache.token;
    } catch {
        return "";
    }
}

/** Invalida la cache CSRF (es. dopo logout o cambio pagina). */
export function invalidateCsrfCache() {
    _csrfCache = { token: "", at: 0 };
}

/**
 * G22.S25 — fetch + JSON parse difensivo.
 *
 * Previene il classico errore visibile alla console:
 *   `Uncaught (in promise) SyntaxError: Unexpected token '<', "<!doctype "...`
 * causato da `await res.json()` su una response HTML (session expired
 * redirect a /login, 401/403 con error page, 500 con stacktrace HTML).
 *
 * Comportamento:
 *   - se response non-OK: throw `Error("HTTP {status}")` con .status, .url
 *   - se Content-Type non application/json: throw `FetchJsonError` con
 *     classificazione (`session_expired` se la response punta a /login,
 *     `not_json` altrimenti). Messaggio i18n italiano.
 *   - se body non parsabile come JSON: stesso `FetchJsonError`.
 *
 * Le opzioni di `fetch` sono passthrough. `credentials: "same-origin"`
 * applicato di default se non specificato.
 *
 * @param {string} url
 * @param {RequestInit} [options]
 * @returns {Promise<any>} il body JSON parsato
 */
export class FetchJsonError extends Error {
    constructor(message, { code, status = 0, url = "" } = {}) {
        super(message);
        this.name = "FetchJsonError";
        this.code = code;
        this.status = status;
        this.url = url;
    }
}

/**
 * Verifica che la response sia parsabile come JSON e la decodifica.
 * Usata stand-alone quando il chiamante ha già la Response (es. ETag
 * handling con status 304 prima di chiamare assertJson).
 *
 * @param {Response} res
 * @param {string} url (per error message)
 * @returns {Promise<any>}
 */
export async function assertJson(res, url = res.url) {
    if (res.redirected && /\/login(\b|$|\?)/.test(res.url)) {
        throw new FetchJsonError(
            "Sessione scaduta — ricarica la pagina e accedi di nuovo.",
            { code: "session_expired", status: res.status, url: res.url },
        );
    }
    const ct = (res.headers.get("content-type") || "").toLowerCase();
    const isJson = ct.includes("application/json") || ct.includes("+json");
    if (!isJson) {
        const peek = await res.text().catch(() => "");
        // WAF challenge servita come HTML (endpoint non /api o senza Accept):
        // una fetch non può eseguire lo <script> di fingerprint/PoW. Difesa in
        // profondità — il WAF ora risponde JSON alle XHR, ma se un path sfugge
        // riconosciamo comunque la pagina di verifica e auto-recuperiamo.
        if (/data-waf-(mode|pow)=|Pantedu WAF|\/js\/waf\/fingerprint\.js/i.test(peek)) {
            handleWafChallenge();
            throw new FetchJsonError(
                "Verifica di sicurezza scaduta — ricarico la pagina…",
                { code: "waf_challenge", status: res.status, url: res.url },
            );
        }
        const looksLikeLogin = /<title>[^<]*Login/i.test(peek)
            || /<form[^>]+action="[^"]*\/login/i.test(peek);
        if (looksLikeLogin) {
            throw new FetchJsonError(
                "Sessione scaduta — ricarica la pagina e accedi di nuovo.",
                { code: "session_expired", status: res.status, url: res.url },
            );
        }
        throw new FetchJsonError(
            `Risposta non JSON (HTTP ${res.status}). Endpoint: ${url}`,
            { code: "not_json", status: res.status, url: res.url },
        );
    }
    let data;
    try {
        data = await res.json();
    } catch (e) {
        throw new FetchJsonError(
            `Parse JSON fallito: ${e.message}`,
            { code: "parse_error", status: res.status, url: res.url },
        );
    }
    // WAF challenge in forma JSON (403 {code:'waf_challenge', reload:true}):
    // la verifica anti-bot va rinnovata via navigazione full-page.
    if (data && data.code === "waf_challenge") {
        handleWafChallenge();
        throw new FetchJsonError(
            "Verifica di sicurezza scaduta — ricarico la pagina…",
            { code: "waf_challenge", status: res.status, url: res.url },
        );
    }
    return data;
}

/**
 * Recupero centralizzato dalla WAF challenge: una fetch non può risolverla,
 * ma una navigazione full-page sì (esegue fingerprint.js, rinfresca il cookie
 * waf_session). Ricarica UNA volta (debounce 30s) per evitare loop.
 */
let _wafReloadScheduled = false;
function handleWafChallenge() {
    if (_wafReloadScheduled || typeof window === "undefined") return;
    try {
        const KEY = "fm_waf_reload_at";
        const now = Date.now();
        const last = +(sessionStorage.getItem(KEY) || 0);
        if (now - last < 30_000) return; // già ricaricato di recente: stop loop
        sessionStorage.setItem(KEY, String(now));
        _wafReloadScheduled = true;
        setTimeout(() => { location.reload(); }, 1200);
    } catch (_) { /* sessionStorage non disponibile: nessun auto-reload */ }
}

// G27 — re-solve WAF TRASPARENTE (single-flight). Riusa /js/waf/fingerprint.js
// (ZERO duplicazione del PoW solver): lo inietta con data-waf-reload="0" così NON
// ricarica la pagina, e attende l'evento `waf:resolved` per sapere quando la
// waf_session è stata rinfrescata. Single-flight: N richieste challenge-ate in
// parallelo condividono UN solo re-solve. Sicurezza invariata vs reload: stesso
// PoW (stesso costo), stessa validazione fingerprint, ban/rate-limit lato server
// intatti. Ritorna true se la challenge è stata risolta.
let _wafSolveInFlight = null;
let _wafSolveCount = 0;
const _WAF_SOLVE_MAX = 3; // anti-loop: oltre questo non re-solve (fallback reload)
function solveWafChallenge(pow) {
    if (_wafSolveInFlight) return _wafSolveInFlight;
    // Anti-loop HARD: se il re-solve non sta sbloccando (challenge ripetuta),
    // smetti di iniettare fingerprint.js — altrimenti gli <script> si accumulano
    // in <head> e il SW (staleWhileRevalidate) rifetcha all'infinito ("la pagina
    // cresce in continuazione"). Oltre il cap lascia il fallback (reload) ad
    // assertJson.
    if (_wafSolveCount >= _WAF_SOLVE_MAX) return Promise.resolve(false);
    if (typeof document === "undefined" || !pow || !pow.token) return Promise.resolve(false);
    _wafSolveCount++;
    _wafSolveInFlight = new Promise((resolve) => {
        let done = false;
        const s = document.createElement("script");
        const finish = (ok) => {
            if (done) return;
            done = true;
            window.removeEventListener("waf:resolved", onResolved);
            try { s.remove(); } catch (_) {} // CLEANUP: niente accumulo di <script> in <head>
            if (ok) _wafSolveCount = 0; // sbloccato: resetta il contatore
            resolve(ok);
        };
        const onResolved = (e) => finish(!!(e && e.detail && e.detail.ok));
        window.addEventListener("waf:resolved", onResolved);
        // URL FISSO (niente ?resolve=timestamp): il SW lo cacha UNA volta; un nuovo
        // <script> con stesso src ri-esegue comunque l'IIFE (legge data-waf-pow).
        s.src = "/js/waf/fingerprint.js";
        s.setAttribute("data-waf-mode", pow.mode || "invisible");
        s.setAttribute("data-waf-reload", "0"); // re-solve trasparente: NIENTE reload
        s.setAttribute("data-waf-pow", pow.token);
        if (pow.bits) s.setAttribute("data-waf-pow-bits", String(pow.bits));
        s.onerror = () => finish(false);
        document.head.appendChild(s);
        setTimeout(() => finish(false), 12000); // safety: il PoW non dovrebbe superarlo
    }).finally(() => { _wafSolveInFlight = null; });
    return _wafSolveInFlight;
}

// G27 — fetch WAF-aware (ritorna Response grezza): su 403 `waf_challenge`
// ri-risolve la challenge in modo TRASPARENTE e ritenta UNA volta. È l'UNICO
// punto in cui vive il retry (lo usa fetchJson e va usato dalle fetch del modal
// anteprima al posto di `fetch` grezzo → niente 403 non gestiti, niente reload).
// Bounded via `__wafRetried`: se il retry fallisce, il chiamante/assertJson
// applicano il fallback (reload).
export async function wafFetch(url, options = {}) {
    const opts = { credentials: "same-origin", ...options };
    let res = await fetch(url, opts);
    if (res.status === 403 && !opts.__wafRetried) {
        const data = await res.clone().json().catch(() => null);
        if (data && data.code === "waf_challenge" && data.pow) {
            const solved = await solveWafChallenge({ token: data.pow, bits: data.powBits, mode: data.mode });
            if (solved) res = await fetch(url, { ...opts, __wafRetried: true });
        }
    }
    return res;
}

export async function fetchJson(url, options = {}) {
    const opts = { credentials: "same-origin", ...options };
    if (!opts.headers) opts.headers = {};
    if (!("Accept" in opts.headers) && !("accept" in opts.headers)) {
        opts.headers.Accept = "application/json";
    }
    const res = await wafFetch(url, opts);
    return assertJson(res, url);
}

// Auto-invalidate su SPA nav (fm-router emette fm:navigated)
if (typeof window !== "undefined") {
    window.addEventListener("fm:navigated", invalidateCsrfCache);
    window.FM = window.FM || {};
    window.FM.DomUtils = {
        esc, escHtml, escAttr, asElement, asElementArray,
        trigger, isVisible, outerHeight, outerWidth,
        parseMeta, readScopeSelects, readSelect,
        fetchCsrf, invalidateCsrfCache, fetchJson, wafFetch, assertJson, FetchJsonError,
    };
}
