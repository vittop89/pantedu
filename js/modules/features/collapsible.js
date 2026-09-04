/**
 * Phase 15 — Collapsible moderno (vanilla, no jQuery).
 *
 * Replica la logica master per il pattern:
 *   <button class="fm-collapsible">Titolo</button>
 *   <div class="content">… esercizi …</div>
 *
 * Click toggle → expand/collapse via `content.style.maxHeight`.
 * Event delegation: funziona anche dopo SPA swap di #fm-content.
 *
 * Escape hatch: click su input/textarea/label interni alla barra
 * (per edit-mode admin) non triggera il toggle — matching master.
 */

const INTERNAL_SELECTORS = [
    ".checkmod", ".wrapcheckgiust", ".wrapchecksol",
    ".input-wrapper-title", ".input-titolo",
    "input", "textarea", "label",
].join(",");

/** Sincronizza aria-expanded del toggle interno con lo stato .active (WCAG 4.1.2). */
function _syncExpanded(btn) {
    const tgl = btn.querySelector(":scope > .fm-collapse-toggle") || btn;
    if (tgl && tgl.setAttribute) tgl.setAttribute("aria-expanded", btn.classList.contains("active") ? "true" : "false");
}

/** Perf (ADR-023) — lazy MathJax: le formule dentro .content sono marcate
 *  `fm-mj-lazy` (MathJax le salta al load via ignoreHtmlClass). Alla prima
 *  apertura rimuoviamo la classe e impaginiamo SOLO questa sezione, poi
 *  ricalcoliamo maxHeight (le formule crescono in altezza). Idempotente: una
 *  volta tolta la classe non si ri-typeset. */
function _ensureTypeset(content) {
    if (!content || !content.classList.contains("fm-mj-lazy")) return;
    content.classList.remove("fm-mj-lazy");
    // G27 PERF — lazy TikZ: segnala l'espansione così tikz-render-client compila
    // (server-side) i TikZ di QUESTA sezione solo ora. Al load i gruppi
    // collassati non scaricano nulla (enorme risparmio su mobile/3G: es. una
    // verifica con 75 TikZ × 2 render esercizio+correlata non parte tutta insieme).
    try {
        window.dispatchEvent(new CustomEvent("fm:collapsible-expanded", { detail: { content } }));
    } catch (_) { /* no-op */ }
    const mj = typeof window !== "undefined" && window.MathJax;
    if (mj && typeof mj.typesetPromise === "function") {
        mj.typesetPromise([content]).then(() => {
            // formule renderizzate → l'altezza è cambiata: riallinea maxHeight
            // se la sezione è (ancora) espansa.
            if (content.style.maxHeight) content.style.maxHeight = content.scrollHeight + "px";
        }).catch(() => { /* typeset non deve rompere il toggle */ });
    }
}

function onClick(e) {
    const btn = e.target.closest("button.fm-collapsible, .fm-collapsible");
    if (!btn) return;
    // Ignora click su controlli interni (editor, checkbox) — il toggle button
    // del titolo NON è interno (deve attivare il toggle).
    if (e.target.closest(INTERNAL_SELECTORS) && e.target !== btn) return;

    btn.classList.toggle("active");
    _syncExpanded(btn);
    const content = btn.nextElementSibling;
    if (!content || !content.classList.contains("content")) return;

    if (content.style.maxHeight) {
        content.style.maxHeight = null;
    } else {
        _ensureTypeset(content);                 // lazy MathJax alla prima apertura
        content.style.maxHeight = content.scrollHeight + "px";
    }
}

// Apre tutti i collapsible della sezione corrente (chiamato su navigate).
// Idempotente: riapplica maxHeight ad ogni chiamata per adattarsi a
// content che cresce (es. post-MathJax typeset o layout async).
function openAll(root = document) {
    root.querySelectorAll("button.fm-collapsible, .fm-collapsible").forEach((btn) => {
        btn.classList.add("active");
        _syncExpanded(btn);
        const content = btn.nextElementSibling;
        if (content && content.classList.contains("content")) {
            _ensureTypeset(content);             // lazy MathJax (verifiche aperte all'apertura)
            // Force layout reflow per ottenere scrollHeight accurato
            void content.offsetHeight;
            content.style.maxHeight = content.scrollHeight + "px";
        }
    });
}

/** G9.20 — chiude tutti i collapsible (rimuovi .active + reset maxHeight).
 *  Usato su pagine esercizio per garantire che eventuali .active server-rendered
 *  o lasciati da SPA precedente vengano resettati. */
function closeAll(root = document) {
    root.querySelectorAll("button.fm-collapsible.active, .fm-collapsible.active").forEach((btn) => {
        btn.classList.remove("active");
        _syncExpanded(btn);
        const content = btn.nextElementSibling;
        if (content && content.classList.contains("content")) {
            content.style.maxHeight = null;
        }
    });
}

// Ricalcola maxHeight dopo MathJax typeset (contenuto cresce → serve expand).
function recompute(root = document) {
    root.querySelectorAll(".fm-collapsible.active").forEach((btn) => {
        const content = btn.nextElementSibling;
        if (content && content.classList.contains("content")) {
            content.style.maxHeight = content.scrollHeight + "px";
        }
    });
}

/** Impagina il contenuto VISIBILE ma ancora marcato `fm-mj-lazy`. Caso (bug):
 *  contenuto MOSTRATO (non collassato) che ha la classe lazy dal server ma non
 *  è mai passato per un expand (_ensureTypeset) → MathJax lo salta
 *  (ignoreHtmlClass) → formule \(...\) grezze. Succede sulle pagine esercizio a
 *  full-load quando il contenuto è iniettato async e resta visibile+lazy.
 *  Tocchiamo SOLO i visibili (offsetHeight>0): i collassati restano lazy =
 *  perf preservata. */
function typesetVisibleLazy(root = document) {
    (root.querySelectorAll ? root : document)
        .querySelectorAll(".fm-mj-lazy")
        .forEach((el) => {
            if (el.offsetParent !== null && el.offsetHeight > 0) _ensureTypeset(el);
        });
}

/** Esegue cb quando MathJax è pronto (typesetPromise). Poll fino a ~12s.
 *  Necessario perché su full-load MathJax è caricato async (vedi
 *  _exercise_assets.php) e fm:mathjax-ready non viene emesso (è per SPA swap). */
function whenMathJaxReady(cb) {
    const ready = () => window.MathJax && typeof window.MathJax.typesetPromise === "function";
    if (window.MathJax && window.MathJax.startup && window.MathJax.startup.promise) {
        window.MathJax.startup.promise.then(cb).catch(cb);
        return;
    }
    if (ready()) { cb(); return; }
    let n = 0;
    const iv = setInterval(() => {
        if (ready()) { clearInterval(iv); cb(); }
        else if (++n > 30) clearInterval(iv);
    }, 400);
}

let bound = false;
function init() {
    if (bound) return;
    bound = true;
    console.log("[collapsible] init, bodyClass=", document.body.className);
    document.addEventListener("click", onClick);
    // Safety net formule visibili+lazy (full-load async): vedi typesetVisibleLazy.
    whenMathJaxReady(() => typesetVisibleLazy());

    // Phase 16 / G9.20 — auto-open SOLO su pagine verifica pure
    // (/studio/verifica/...). Sulle pagine esercizio (/studio/esercizio/...
    // o /eser/...) tutti i collapsible restano CHIUSI, anche quelli della
    // sezione "Verifiche correlate" iniettata via [id^="type_verAll"] —
    // l'utente espande manualmente cio' che gli serve.
    const isVerificaPage = () => /^\/studio\/verifica\//.test(location.pathname);

    window.addEventListener("fm:navigated", () => {
        if (isVerificaPage()) requestAnimationFrame(() => openAll());
        else                  requestAnimationFrame(() => closeAll());
    });

    window.addEventListener("fm:mathjax-ready", () => {
        requestAnimationFrame(() => {
            if (isVerificaPage()) openAll();
            else                  closeAll();
            recompute();
            typesetVisibleLazy();
        });
    });

    const bootApply = () => {
        if (isVerificaPage()) openAll();
        else                  closeAll();
    };
    bootApply();
    setTimeout(bootApply, 300);
    setTimeout(bootApply, 1200);
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
} else {
    init();
}

window.FMCollapsible = { openAll, recompute };
