/**
 * G22.S15 — TikZ render client (server-side via VPS pdflatex+dvisvgm).
 *
 * Pipeline per ogni <script type="text/tikz">:
 *   1. Normalize sorgente (mirror della normalize PHP TikzRenderService)
 *   2. SHA-256 → hash cache key
 *   3. GET /tikz/render?hash=H&scope=S   (cache lookup, no body)
 *      - 200 → inline SVG
 *      - 304 → riusa cache browser (gestito da fetch)
 *      - 404 → cache miss, salta a POST
 *   4. POST /tikz/render {tikz, scope, libraries, ...}
 *      - 200 → inline SVG
 *      - 422 → log compile error in DOM
 *      - 503 (tex_compile_disabled) → error overlay inline
 *
 * G22.S15.bis — TikZJax deprecato: nessun fallback client-side WASM. In
 * caso di errore VPS l'utente vede un blocco rosso con il log di compile.
 *
 * Scope:
 *   - 'public'  (default) → cache condivisa, no PII (admin templates)
 *   - 'teacher' → cache cifrata per docente (richiede attributo
 *      data-tikz-scope="teacher" sul tag <script>)
 *
 * Esposto come window.FM.TikzRenderClient + named exports ES6.
 */
import { renameSvgIds, makeSvgPrefix } from "./tikz-svg-ids.js";

const ENDPOINT = "/tikz/render";

// Default usetikzlibrary / pacchetti caricati lato server (matchano i defaults
// del legacy data-tex-packages/data-tikz-libraries).
const DEFAULT_LIBRARIES = ["arrows.meta", "calc"];
const DEFAULT_EXTRA_PACKAGES = ["pgfplots"];

import { fetchCsrf } from "../core/dom-utils.js";
import { senzaNonce } from "./inline-blocks-markers.js";
import { testoErrori } from "./tex-errori.js";

// CSRF centralizzato (meta-tag-first + cache 60s in dom-utils).
const _getCsrf = fetchCsrf;

/**
 * Decode tutte le entita HTML (named, &#NNN;, &#xHH;) via textarea trick.
 * Equivalente a html_entity_decode($s, ENT_QUOTES|ENT_HTML5, 'UTF-8') lato PHP.
 */
function _decodeHtmlEntities(s) {
  if (!s.includes("&")) return s;
  const ta = document.createElement("textarea");
  ta.innerHTML = s;
  return ta.value;
}

/**
 * Normalizza il sorgente TikZ — DEVE matchare TikzRenderService::normalize
 * lato PHP (stesso hash → stessa cache).
 *
 * Step (idempotenti):
 *   1. CRLF/CR → LF
 *   2. <br>, <p>, <span>, <div>, <b>, <i>, <u> rimossi
 *   3. Decode TUTTE le entita HTML (named + numeric) — necessario per
 *      lettere accentate italiane (Quill le emette come &#192;/&#224;).
 *   4. Trailing whitespace per riga collassato
 *   5. >2 LF consecutivi → 2 LF
 *   6. trim + LF finale singolo
 */
export function normalizeTikz(src) {
  let s = String(src || "");
  s = s.replace(/\r\n?/g, "\n");
  s = s.replace(/<br\s*\/?>/gi, "\n");
  s = s.replace(/<\/?(?:p|span|div|b|i|u)\b[^>]*>/gi, "");
  s = _decodeHtmlEntities(s);
  s = s.replace(/[ \t]+\n/g, "\n");
  s = s.replace(/\n{3,}/g, "\n\n");
  s = `${s.trim()}\n`;

  // Inietta font setup helvet/sfdefault (CENTRALIZZATO con TikzRenderService::normalize
  // PHP-side e TexAdhocCompileController::wrapTikzSource). Idempotente.
  // Solo se Case 2 VPS (preamble + \begin{document}, no \documentclass).
  if (/\\begin\s*\{\s*document\s*\}/.test(s)
      && !s.includes("\\documentclass")
      && !s.includes("\\renewcommand{\\familydefault}")) {
    const fontSetup = "\\usepackage[scaled]{helvet}\n"
                    + "\\usepackage[T1]{fontenc}\n"
                    + "\\renewcommand{\\familydefault}{\\sfdefault}\n";
    s = s.replace(/(\\begin\s*\{\s*document\s*\})/, `${fontSetup}$1`);
  }
  return s;
}

// renameSvgIds e makeSvgPrefix stanno in tikz-svg-ids.js, un modulo puro:
// le prove lo importano senza avviare il rendering automatico di questo
// modulo (23/9/2026: il suo timer di 50 ms scattava a prova finita, senza
// `document`, e faceva rosso Vitest a caso). Qui si riesportano per i
// chiamanti di sempre.
export { renameSvgIds, makeSvgPrefix };

/** SHA-256 hex (Web Crypto). G27.tikz.crypto-fallback — fallback a FNV-1a
 *  sincrono se `crypto.subtle.digest` non disponibile (insecure context,
 *  iframe sandbox, vecchi browser). Senza fallback, tikz-render-client
 *  esplodeva con "Cannot read properties of undefined (reading 'digest')"
 *  e mostrava "[TikZ render error]" placeholder al posto delle figure. */
export async function sha256Hex(text) {
  if (typeof crypto !== "undefined" && crypto?.subtle?.digest) {
    try {
      const buf = new TextEncoder().encode(text);
      const hash = await crypto.subtle.digest("SHA-256", buf);
      return Array.from(new Uint8Array(hash))
        .map((b) => b.toString(16).padStart(2, "0"))
        .join("");
    } catch (e) {
      // SecurityError o altro: fallback sotto.
      console.warn("[tikz] sha256Hex: crypto.subtle fallito, uso FNV-1a fallback", e?.message);
    }
  }
  // Fallback sincrono: FNV-1a 32-bit ripetuto 8 volte con seed diverso
  // per ottenere 256 bit (32 chars hex). Non e' sha256 vero ma e' stabile,
  // collision-resistant per cache key locale, e funziona ovunque.
  const fnv = (s, seed) => {
    let h = seed >>> 0;
    for (let i = 0; i < s.length; i++) {
      h ^= s.charCodeAt(i);
      h = (h + ((h << 1) + (h << 4) + (h << 7) + (h << 8) + (h << 24))) >>> 0;
    }
    return h.toString(16).padStart(8, "0");
  };
  const seeds = [0x811c9dc5, 0x9e3779b9, 0x6a09e667, 0xbb67ae85,
                 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c];
  return seeds.map(s => fnv(text, s)).join("");
}

/**
 * Hash sincrono FNV-1a 32-bit (~10μs per 10KB di input). Usato come
 * chiave per snapshot anti-flicker dell'editor (matching SVG saved
 * → script appena ri-iniettato): NON sostituisce sha256 lato server.
 */
export function quickHash(text) {
  let h = 0x811c9dc5;
  const s = String(text || "");
  for (let i = 0; i < s.length; i++) {
    h = ((h ^ s.charCodeAt(i)) * 0x01000193) >>> 0;
  }
  return h.toString(16).padStart(8, "0");
}

/**
 * Estrai libraries/packages dal tag <script> (data-tikz-libraries,
 * data-tex-packages JSON). Fallback a defaults.
 */
function readPackagesFromScript($script) {
  const out = {
    libraries: DEFAULT_LIBRARIES.slice(),
    pgfplots_libraries: [],
    extra_packages: DEFAULT_EXTRA_PACKAGES.slice(),
  };
  const libs = $script.attr ? $script.attr("data-tikz-libraries") : $script.dataset?.tikzLibraries;
  if (libs) {
    out.libraries = String(libs).split(",").map((s) => s.trim()).filter(Boolean);
  }
  const pkgsRaw = $script.attr ? $script.attr("data-tex-packages") : $script.dataset?.texPackages;
  if (pkgsRaw) {
    try {
      const obj = JSON.parse(pkgsRaw);
      if (obj && typeof obj === "object") {
        out.extra_packages = Object.keys(obj);
      }
    } catch (_) {}
  }
  const pgflibs = $script.attr ? $script.attr("data-pgfplots-libraries") : $script.dataset?.pgfplotsLibraries;
  if (pgflibs) {
    out.pgfplots_libraries = String(pgflibs).split(",").map((s) => s.trim()).filter(Boolean);
  }
  return out;
}

function readScopeFromScript($script, fallback = "public") {
  const v = $script.attr ? $script.attr("data-tikz-scope") : $script.dataset?.tikzScope;
  if (v === "teacher") return "teacher";
  return fallback;
}

/**
 * Memo browser-side: (scope|hash) → SVG. Evita round-trip ripetuti
 * sulla stessa coppia (succede MOLTO in editor live, dove ogni
 * keystroke ricostruisce il preview pane via innerHTML, ricreando
 * gli script tag e ri-triggerando renderAll). Limit 256 entries
 * con eviction LRU semplice (Map JS preserva insert order).
 */
const _memCache = new Map();
const _MEM_CACHE_MAX = 256;

function _memGet(scope, hash) {
  const k = `${scope}|${hash}`;
  const v = _memCache.get(k);
  if (v !== undefined) {
    // refresh LRU
    _memCache.delete(k);
    _memCache.set(k, v);
  }
  return v;
}
function _memSet(scope, hash, svg) {
  const k = `${scope}|${hash}`;
  if (_memCache.has(k)) _memCache.delete(k);
  _memCache.set(k, svg);
  if (_memCache.size > _MEM_CACHE_MAX) {
    const firstKey = _memCache.keys().next().value;
    _memCache.delete(firstKey);
  }
}

/**
 * GET cache lookup. Ritorna stringa SVG o null se miss.
 * @param {string} hash
 * @param {string} scope
 */
export async function lookupCache(hash, scope) {
  const url = `${ENDPOINT}?hash=${encodeURIComponent(hash)}&scope=${encodeURIComponent(scope)}`;
  // G27 PERF — backoff sul 429: la lookup è un cache-check (cheap). Su rate
  // limit (app o Cloudflare/nginx) ATTENDIAMO e ritentiamo invece di trattarlo
  // come miss — altrimenti sprecheremmo un POST render (costoso, ulteriore 429).
  for (let attempt = 0; attempt <= 2; attempt++) {
    const r = await fetch(url, {
      credentials: "same-origin",
      headers: { Accept: "image/svg+xml, application/json" },
    });
    if (r.status === 200) return await r.text();
    // 204 No Content = cache miss (post-2026-05-24, era 404).
    if (r.status === 204 || r.status === 404) return null;
    // 401/403 → no auth per scope teacher; trattiamo come miss
    if (r.status === 401 || r.status === 403) return null;
    if (r.status === 429 && attempt < 2) {
      const ra = parseInt(r.headers.get("Retry-After") || "", 10) || 0;
      const base = ra > 0 ? Math.min(ra * 1000, 5000) : 1000 * (attempt + 1);
      await new Promise((res) => setTimeout(res, base * (0.8 + Math.random() * 0.4)));
      continue;
    }
    // 5xx / 429 esaurito / altro: tratta come miss (POST tentera' compile)
    return null;
  }
  return null;
}

/**
 * Un 422 del render è un intoppo da ritentare o un disegno sbagliato?
 *
 * `errors` sono gli errori di pdflatex (ErroriTex, 24/9/2026): se ci sono è
 * il disegno a essere sbagliato, e ritentare cinque volte con l'attesa non
 * cambia niente. Prima un estratto corto (sotto i 500 caratteri) con un
 * errore vero si ritentava lo stesso. Senza `errors` (servizio di prima, o
 * fallimento non del disegno) vale la regola di prima: transient se il log è
 * corto o non ha righe «!» — probabile concurrency abort.
 */
export function ritentaIl422(j) {
  if (Array.isArray(j?.errors) && j.errors.length > 0) return false;
  const log = (j?.log || "").toString();
  const errorLines = (log.match(/^!/gm) || []).length;
  return log.length < 500 || errorLines === 0;
}

/**
 * POST compile-on-demand. Lancia eccezione se errore irrecuperabile.
 * @returns {Promise<string>} SVG text
 */
export async function compileTikz(tikzSource, opts = {}) {
  const csrf = await _getCsrf();
  const body = {
    tikz: tikzSource,
    scope: opts.scope || "public",
    libraries: opts.libraries || DEFAULT_LIBRARIES,
    pgfplots_libraries: opts.pgfplots_libraries || [],
    extra_packages: opts.extra_packages || DEFAULT_EXTRA_PACKAGES,
    border: opts.border || "2pt",
  };
  // Retry su 503 (VPS rate-limited / restart) con backoff esponenziale.
  // Previene il "503 Service Temporarily Unavailable" pieno-pagina quando
  // il rendering scarica troppe richieste sul VPS in burst.
  const maxRetries = 5;
  for (let attempt = 0; attempt <= maxRetries; attempt++) {
    const r = await fetch(ENDPOINT, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": csrf,
        Accept: "image/svg+xml, application/json",
      },
      body: JSON.stringify(body),
    });
    if (r.status === 200) return await r.text();
    // Retry su 503 (nginx rate limit), 429 (rate limit app/Cloudflare) o 422
    // con log vuoto (VPS concurrency saturation transient). Backoff esponenziale
    // 1→8s + jitter ±20%; sul 429 rispetta retry_after se presente.
    if (attempt < maxRetries) {
      let retry = false;
      let waitMs = 0;
      if (r.status === 503) {
        retry = true;
      } else if (r.status === 429) {
        // Rate limit (teacher 60/min app, o Cloudflare/nginx). Con sliding
        // window il budget si ricarica gradualmente → i retry drenano la coda
        // invece di lasciare il TikZ vuoto.
        retry = true;
        let ra = parseInt(r.headers.get("Retry-After") || "", 10);
        if (!ra) { try { const j = await r.clone().json(); ra = parseInt(j?.retry_after, 10) || 0; } catch (_) { /* no json */ } }
        waitMs = ra > 0 ? Math.min(ra * 1000, 8000) : 0;
      } else if (r.status === 422) {
        // 422 con compile failure: distingui transient (log vuoto/short)
        // da real LaTeX bug (log con errori specifici "!" multiple line).
        try {
          retry = ritentaIl422(await r.clone().json());
        } catch (_) {
          retry = true;  // JSON malformed → assume transient
        }
      }
      if (retry) {
        const base = waitMs || Math.min(1000 * Math.pow(2, attempt), 8000); // 1,2,4,8,8s
        const jitter = base * (0.8 + Math.random() * 0.4);
        await new Promise((res) => setTimeout(res, jitter));
        continue;
      }
    }
    // Errore non recuperabile: prova a leggere JSON {error, log}
    let detail = `http_${r.status}`;
    let texErrors = [];
    try {
      const j = await r.json();
      detail = j.log || j.error || detail;
      if (Array.isArray(j.errors)) texErrors = j.errors;
    } catch (_) {}
    const err = new Error(detail);
    // Gli errori di pdflatex (24/9/2026): chi mostra l'errore li formatta con
    // la riga del sorgente (tex-errori.js) invece di stampare il log.
    err.texErrors = texErrors;
    err.status = r.status;
    err.disabled = r.status === 503;
    throw err;
  }
}

/**
 * Render singolo blocco TikZ. Ritorna {svg, source: 'cache'|'compile'|'memo'} o lancia.
 *
 * Tre livelli di cache:
 *   1. memo browser-side (Map in RAM)   → 0ms
 *   2. cache server-side (HTTP GET 200) → ~30-100ms
 *   3. compile VPS (HTTP POST 200)      → ~500-3000ms
 */
export async function renderOne(tikzSource, opts = {}) {
  const normalized = normalizeTikz(tikzSource);
  const hash = await sha256Hex(normalized);
  const scope = opts.scope || "public";

  // L1: memo
  const memoed = _memGet(scope, hash);
  if (memoed !== undefined) {
    return { svg: memoed, source: "memo", hash };
  }

  // L2: server cache (HTTP GET)
  const cached = await lookupCache(hash, scope);
  if (cached !== null) {
    _memSet(scope, hash, cached);
    return { svg: cached, source: "cache", hash };
  }

  // L3: compile via VPS
  const svg = await compileTikz(normalized, opts);
  _memSet(scope, hash, svg);
  return { svg, source: "compile", hash };
}

/**
 * Trova tutti gli script <script type="text/tikz"> sotto $root e li
 * sostituisce con SVG inline. In caso di errore compile mostra blocco
 * rosso inline (TikZJax deprecato G22.S15.bis, niente fallback WASM).
 *
 * @param {Element|null} root  - default document
 * @param {Object} options
 * @param {string}  options.defaultScope (default 'public')
 * @returns {Promise<{ok:number, fallback:number, errors:Array}>}
 *   - `fallback` resta nello shape per backward-compat ma e' sempre 0.
 */
export async function renderAll(root = document, options = {}) {
  const defaultScope = options.defaultScope || "public";

  const scripts = Array.from(
    (root || document).querySelectorAll('script[type^="text/tikz"]')
  );
  const stats = { ok: 0, fallback: 0, errors: [] };
  if (!scripts.length) return stats;

  // Promise pool con concorrenza max 4 (matchato col semaforo VPS).
  const pool = 4;
  let idx = 0;

  async function worker() {
    while (true) {
      const i = idx++;
      if (i >= scripts.length) return;
      const script = scripts[i];
      const source = script.textContent || (script.innerHTML || "");
      const pkgs = readPackagesFromScript(script);
      const scope = readScopeFromScript(script, defaultScope);
      // G22.S15 — tagOpen + body originali: l'elemento che prende il posto
      // dello <script> è l'unico posto dove la sorgente resta, e l'editor la
      // rilegge da lì. Si calcolano PRIMA del try: servono anche quando il
      // render fallisce (vedi il ramo catch).
      // Il `nonce` della risposta non entra: è di quella risposta e sola, e
      // se finisse in `data-tikz-tagopen` tornerebbe nel contratto.
      const _scriptOuter = script.outerHTML || "";
      const _tagOpenMatch = _scriptOuter.match(/^<script[^>]*>/i);
      const _tagOpen = senzaNonce(_tagOpenMatch ? _tagOpenMatch[0] : '<script type="text/tikz">');
      const _body = source;
      try {
        const r = await renderOne(source, {
          scope,
          libraries: pkgs.libraries,
          pgfplots_libraries: pkgs.pgfplots_libraries,
          extra_packages: pkgs.extra_packages,
        });
        // Isola IDs SVG per evitare collisioni nel DOM (vedi renameSvgIds).
        const isolatedSvg = renameSvgIds(r.svg, makeSvgPrefix(r.hash));

        // Inline SVG: replaceWith preserva ordine DOM.
        const wrapper = document.createElement("div");
        wrapper.innerHTML = isolatedSvg;
        const svgEl = wrapper.querySelector("svg") || wrapper.firstElementChild;
        // Compute the same normalized as renderOne would, for snapshot key.
        const _normForKey = normalizeTikz(source);
        const _srcKey = quickHash(_normForKey);
        if (svgEl) {
          svgEl.setAttribute("data-tikz-hash", r.hash);
          svgEl.setAttribute("data-tikz-source", r.source);
          svgEl.setAttribute("data-tikz-srckey", _srcKey);
          svgEl.setAttribute("data-tikz-tagopen", encodeURIComponent(_tagOpen));
          svgEl.setAttribute("data-tikz-body", encodeURIComponent(_body));
          script.replaceWith(svgEl);
        } else {
          wrapper.setAttribute("data-tikz-hash", r.hash);
          wrapper.setAttribute("data-tikz-srckey", _srcKey);
          wrapper.setAttribute("data-tikz-tagopen", encodeURIComponent(_tagOpen));
          wrapper.setAttribute("data-tikz-body", encodeURIComponent(_body));
          script.replaceWith(wrapper);
        }
        stats.ok++;
      } catch (err) {
        const testo = err.texErrors?.length ? testoErrori(err.texErrors, source) : (err.message || String(err));
        stats.errors.push({ scriptId: script.id, error: testo, texErrors: err.texErrors || [] });
        // G22.S15.bis — nessun fallback WASM: mostra blocco errore inline.
        const errBox = document.createElement("div");
        errBox.className = "fm-tikz-error-messages-block";
        errBox.style.cssText = "border:1px solid red;color:red;padding:10px;margin:10px 0;white-space:pre-wrap;font-family:monospace;font-size:0.9em;max-height:250px;overflow-y:auto;";
        errBox.textContent = `[TikZ render error]\n${testo}`;
        // 18 settembre 2026 — il riquadro d'errore porta la sorgente come la
        // porta l'SVG riuscito. Prima non la portava: quando il servizio TeX
        // non rispondeva, la figura spariva dal DOM e restava solo il testo
        // rosso. Il docente apriva l'editor, salvava, e nel contratto al posto
        // della figura finiva «[TikZ render error]\nErrore di rete…» — la
        // sorgente TikZ perduta (verifica 75, esercizio 16). Stessi attributi
        // dell'SVG: chi rilegge non deve sapere se il render è andato bene.
        errBox.setAttribute("data-tikz-tagopen", encodeURIComponent(_tagOpen));
        errBox.setAttribute("data-tikz-body", encodeURIComponent(_body));
        script.replaceWith(errBox);
      }
    }
  }

  await Promise.all(Array.from({ length: pool }, () => worker()));
  return stats;
}

// Espone per legacy (non-module consumer).
const TikzRenderClient = {
  normalizeTikz, sha256Hex, quickHash, lookupCache, compileTikz, renderOne, renderAll,
  renameSvgIds, makeSvgPrefix,
};
if (typeof window !== "undefined") {
  window.FM = window.FM || {};
  window.FM.TikzRenderClient = TikzRenderClient;
}

// G22.S15 — auto-render su page load + MutationObserver per content
// inserito dopo DOMContentLoaded (es. via fetch/SPA/UIComp.._caricaDivRiservati).
// Idempotente: _memCache + replaceWith.
if (typeof window !== "undefined" && typeof document !== "undefined") {
  const SEL = 'script[type^="text/tikz"]';

  // G27 PERF — rendering LAZY. I TikZ dei gruppi COLLASSATI (marcati
  // `.fm-mj-lazy`, come il lazy MathJax) NON si compilano al load: partono solo
  // all'espansione del gruppo (evento `fm:collapsible-expanded`). Prima si
  // faceva `renderAll(document)` eager → su una pagina studio con esercizio +
  // verifica correlata = ~150 TikZ scaricati tutti subito anche se collassati
  // (brutale su mobile/3G). Ora: collassato = 0 richieste finché non lo apri.
  const _renderVisible = (reason = "init") => {
    // Un timer può scattare quando la pagina (o l'ambiente di una prova) non
    // c'è più: allora non c'è niente da rendere.
    if (typeof document === "undefined") return;
    const all = document.querySelectorAll(SEL);
    if (!all.length) return;
    // Container dei soli TikZ NON dentro una sezione collassata.
    const containers = new Set();
    all.forEach((s) => {
      if (s.closest(".fm-mj-lazy")) return;   // collassato → rimanda all'apertura
      const c = s.parentElement;
      if (c) containers.add(c);
    });
    if (!containers.size) return;
    if (window.FM?.DEBUG_TIKZ) console.info(`[tikz-lazy] render visible reason=${reason} containers=${containers.size}`);
    containers.forEach((c) => renderAll(c, { defaultScope: "public" })
      .catch((e) => console.error("[tikz-lazy] render failed:", e)));
  };

  // Trigger 1: page load (solo i TikZ già visibili — fuori da collapsibili)
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => _renderVisible("DOMContentLoaded"), { once: true });
  } else {
    setTimeout(() => _renderVisible("late-import"), 50);
  }

  // Trigger 2: eventi SPA / mathjax (ri-osserva i visibili dopo swap)
  window.addEventListener("fm:mathjax-ready", () => _renderVisible("mathjax-ready"));
  window.addEventListener("fm:navigated",     () => setTimeout(() => _renderVisible("navigated"), 100));

  // Trigger 3: ESPANSIONE di un gruppo collassato → compila i TikZ di QUELLA
  // sezione (e riallinea il maxHeight del collapsible: gli SVG crescono).
  window.addEventListener("fm:collapsible-expanded", (e) => {
    const content = e?.detail?.content;
    if (!content || !content.querySelector?.(SEL)) return;
    renderAll(content, { defaultScope: "public" }).then(() => {
      if (content.style && content.style.maxHeight) {
        content.style.maxHeight = `${content.scrollHeight}px`;
      }
    }).catch((err) => console.error("[tikz-lazy] expand render:", err));
  });

  // Trigger 4: MutationObserver — nuovi <script type="text/tikz"> iniettati
  // (fetch/SPA/verifica correlata) → ri-valuta i VISIBILI (i collassati restano
  // in attesa dell'apertura). Debounced 200ms.
  let _moTimer = null;
  const _mo = new MutationObserver((muts) => {
    for (const mut of muts) {
      for (const n of mut.addedNodes) {
        if (n.nodeType !== 1) continue;
        if (n.matches?.(SEL) || n.querySelector?.(SEL)) {
          clearTimeout(_moTimer);
          _moTimer = setTimeout(() => _renderVisible("mutation"), 200);
          return;
        }
      }
    }
  });
  if (document.body) _mo.observe(document.body, { childList: true, subtree: true });
  else document.addEventListener("DOMContentLoaded", () => _mo.observe(document.body, { childList: true, subtree: true }), { once: true });
}

export default TikzRenderClient;
