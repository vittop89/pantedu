/**
 * VerificaBuilder (M11) — modulo moderno ES6.
 *
 * Flow (Phase 21 — btnAct rimosso, verifica-mode sempre-on per admin):
 *   1. AUTO-ON su body.fm-admin-access: `.fm-collection__item` mostra checkbox
 *      `.js-pick-ex`, body ottiene `.fm-verifica-mode`, #infoVer iniettato,
 *      verifiche correlate caricate.
 *   2. click #btnCopyver → raccoglie id selezionati (data-id),
 *      chiede titolo+variant via prompt, fetch POST /api/verifiche/build
 *      con CSRF header, mostra toast risultato.
 *   3. click #btnCopyeser → export rapido lista id (clipboard) senza
 *      persistere — utile per preview.
 *
 * Gate auth: `body.fm-admin-access` applicata dai Study controller solo se
 * `Auth::hasAccess('admin')`. Studenti/guest → no class → ensureVerificaMode
 * è no-op, editor non viene iniettato. Defense-in-depth: `_upbar_loader.php`
 * strippa già `.selwrapbtncopy` (incluso #btnCopyver) per non-admin.
 *
 * Zero dipendenze jQuery: solo DOM API + fetch. Bind idempotente via
 * dataset flag; ri-invocabile dopo ogni fm:navigated.
 */

import { fetchJson, fetchCsrf } from "../core/dom-utils.js";

const STATE = {
    active: false,
    selected: new Set(),
};

export function initVerificaBuilder() {
    ensureVerificaMode();
    bindGenerateButton();
    bindCopyButton();
    bindPickboxes();
}

/** Phase 21 / G22.S25 — auto-attiva verifica-mode per ogni teacher su
 *  pagina esercizio/verifica.
 *
 *  Idempotente: seconda invocazione no-op. Deattiva al cambio route verso
 *  mappa/risdoc/bes (senza upbar) per evitare #infoVer orfano.
 *
 *  Gate (G22.S25 — esteso a non-admin):
 *   - body.fm-teacher-access: user con Auth::hasAccess('teacher')
 *     (precedente: admin-access, ma ogni teacher costruisce verifiche
 *     dai suoi esercizi, anche recuperati dal pool).
 *   - .fm-upbar nel DOM: server-emessa solo per esercizio/verifica
 *     (source-of-truth invariata da SPA nav). */
function ensureVerificaMode() {
    const isTeacher = document.body.classList.contains("fm-teacher-access");
    const hasUpbar = !!document.querySelector(".fm-upbar");
    // G23 page-doc — skip injection #infoVer per pagine layout=custom
    // (PT-libero, no esercizi da selezionare). Anche per page_doc=true.
    // renderCustomTopicHtml emette .fm-pt-custom-page[data-layout="custom"]
    // come wrapper di riconoscimento (ContentStudyController:1325).
    const isCustomLayout = !!document.querySelector('.fm-pt-custom-page[data-layout="custom"]');
    const shouldActivate = isTeacher && hasUpbar && !isCustomLayout;

    if (!shouldActivate) {
        // Cleanup se precedentemente attivo (nav da esercizio → mappa/risdoc/page-doc).
        if (STATE.active) {
            STATE.active = false;
            document.body.classList.remove("fm-verifica-mode");
            document.querySelectorAll("#scrollbarInfo, #infoVer").forEach((n) => n.remove());
            STATE.selected.clear();
        }
        return;
    }

    if (STATE.active) {
        // Se SPA-nav ha swappato #fm-content, #scrollbarInfo/#infoVer vanno
        // ri-iniettati. injectVerificaUi è idempotente.
        injectVerificaUi();
        return;
    }
    STATE.active = true;
    document.body.classList.add("fm-verifica-mode");
    injectVerificaUi();
    updateGenerateLabel();
}

/** Phase 21 — reset selezione senza chiudere la modalità (prima
 *  toggleActive chiudeva tutto; ora verifica-mode è sempre-on). */
function resetSelection() {
    STATE.selected.clear();
    document.querySelectorAll(".js-pick-ex:checked").forEach((c) => (c.checked = false));
    updateGenerateLabel();
}

function bindGenerateButton() {
    const btn = document.getElementById("btnCopyver");
    if (!btn || btn.dataset.fmvBound === "1") return;
    btn.dataset.fmvBound = "1";
    btn.addEventListener("click", generateVerifica);
}

function bindCopyButton() {
    const btn = document.getElementById("btnCopyeser");
    if (!btn || btn.dataset.fmvBound === "1") return;
    btn.dataset.fmvBound = "1";
    btn.addEventListener("click", copySelectedIds);
}

/** Delegation: i checkbox `.js-pick-ex` sono generati dal server in
 *  ExerciseStudyController; aggiorniamo lo STATE su ogni change. */
function bindPickboxes() {
    const root = document.getElementById("fm-content") || document.body;
    if (root.dataset.fmvPickBound === "1") return;
    root.dataset.fmvPickBound = "1";
    root.addEventListener("change", (e) => {
        const el = e.target;
        if (!el.classList?.contains("js-pick-ex")) return;
        const id = Number(el.dataset.id);
        if (!id) return;
        el.checked ? STATE.selected.add(id) : STATE.selected.delete(id);
        updateGenerateLabel();
    });
}


/** Phase 16 — caricamento on-demand dell'editor verifica.
 *
 * Consolidamento: ContractRenderer emette server-side .checkIN/.selection/
 * .checkmod/.moveBtn inline. Qui resta solo ciò che NON è server-renderable:
 *   1. #scrollbarInfo (+#infoVer) → via _caricaCheckboxABin (legacy template
 *      editor verifiche con ANNO/TIME/CL/SEZ/...).
 *   2. #infoVer .selector-eser + .scelte-verifica-wrapper → _CaricaSel_EserOr.
 *   3. Populate .origin select options da /origins/origins.json (client-side,
 *      una fetch per tutta la pagina).
 *
 * I step legacy _caricaDivRiservati + InsertCheckPos vengono skippati se il
 * markup è già presente (server-rendered). Girano solo su pagine legacy
 * /eser/*.php che non passano per ContractRenderer.
 */
function injectVerificaUi() {
    const uic = window.UIComp;
    if (!uic) {
        console.warn("[verifica] UIComp non disponibile");
        return;
    }
    // Idempotente: se #scrollbarInfo + .checkIN già presenti, no-op.
    const hasServerCheckIN = !!document.querySelector(".fm-collection__item > .fm-check-in");
    const hasInfoVer = !!document.getElementById("scrollbarInfo");
    if (hasInfoVer && hasServerCheckIN) return;

    // Step 1 — #scrollbarInfo/#infoVer (legacy editor template).
    const step1 = typeof uic._caricaCheckboxABin === "function"
        ? Promise.resolve(uic._caricaCheckboxABin()).catch((e) => console.warn("[verifica] step1 fail", e))
        : Promise.resolve();

    step1.then(() => {
        relocateScrollbarInfo();
        // Step 2 — LEGACY FALLBACK: solo pagine senza server-rendered .checkIN.
        // Su /studio/... ContractRenderer già emette .checkIN/.moveBtn/.checkmod,
        // quindi _caricaDivRiservati è no-op sui .fm-collection__item (guard interna:
        // `if ($item.find(".fm-check-in").length === 0)`) ma serve ancora per le
        // eventuali injection legacy (pagine /eser/*.php).
        return new Promise((resolve) => {
            if (hasServerCheckIN) { resolve(); return; } // skip: server-rendered
            if (typeof uic._caricaDivRiservati !== "function") { resolve(); return; }
            try { uic._caricaDivRiservati(() => resolve()); }
            catch (e) { console.warn("[verifica] step2 fail", e); resolve(); }
        });
    }).then(() => {
        // Step 3 — LEGACY FALLBACK: InsertCheckPos popola .PosCheckEs vuoti.
        // Su /studio/... .selection è già inline nel render → skippabile.
        const hasServerSelection = !!document.querySelector(".fm-groupcollex > .fm-pos-check-es .selection");
        if (!hasServerSelection && typeof uic.InsertCheckPos === "function") {
            try { uic.InsertCheckPos(); } catch (e) { console.warn("[verifica] step3 fail", e); }
        }
        // Step 4 — popola #infoVer .selector-eser (+nuovo esercizio dropdown,
        // Seleziona origine, .scelte-verifica-wrapper Salva/Carica Scelte)
        // tramite _CaricaSel_EserOr, che serve tempDiv da preloadElementiRiservati.
        if (typeof uic.preloadElementiRiservati === "function" && typeof uic._CaricaSel_EserOr === "function") {
            try {
                uic.preloadElementiRiservati((tempDiv) => {
                    if (tempDiv) {
                        try { uic._CaricaSel_EserOr(tempDiv); } catch (e) { console.warn("[verifica] step4 fail", e); }
                    }
                });
            } catch (e) { console.warn("[verifica] step4 outer fail", e); }
        }
        // Step 5 — ripristina stato A/R/pt/origin/color per ogni quesito dalla
        // sessionStorage (popolato da checkin-handlers.js onChange/onInput).
        if (typeof window.FM?.restoreCheckinState === "function") {
            try { window.FM.restoreCheckinState(); } catch (e) { console.warn("[verifica] restoreCheckinState fail", e); }
        }
        // Phase 16 — popola .origin select nei .checkIN server-rendered da
        // /api/teacher/sources.json (cached su window.__fmOriginsCache).
        if (typeof window.FM?.populateOriginSelects === "function") {
            try { window.FM.populateOriginSelects(); } catch (e) { console.warn("[verifica] populateOriginSelects fail", e); }
        }
        // Phase 16 — numera `.move-position` + `.move-position-problem` dopo
        // injection dei .checkIN. I .fm-groupcollex di verifica sono caricati al
        // mount via ensureVerificaMode, quindi populatePositionInputs servì
        // alla prima init.
        if (typeof window.FM?.populatePositionInputs === "function") {
            try { window.FM.populatePositionInputs(); } catch (e) { console.warn("[verifica] populatePositionInputs fail", e); }
        }
        // Phase 16 — topic color cycle (legacy pattern): colora .fm-titolo-quesito
        // secondo ciclo white/green/blue/red/purple/orange per topic distinti.
        if (typeof window.FM?.applyTopicColorCycle === "function") {
            try { window.FM.applyTopicColorCycle(); } catch (e) { console.warn("[verifica] applyTopicColorCycle fail", e); }
        }
        // G19 — segnala che #scrollbarInfo / #SumPtotA / .checkIN sono
        // disponibili: la topbar moderna ascolta per spostare i totali nel
        // suo slot (relocateTotals) senza dover aspettare il primo click.
        window.dispatchEvent(new CustomEvent("fm:verifica-ui-loaded"));
        // Step 6 — se siamo su /studio/esercizio/... carica verifica correlata
        // (stesso subject+title) in #type_verAll via /api/study/related-verifiche.html.
        // G27 PERF — DEFERITA: il fetch+inject parte solo quando ci si avvicina
        // in fondo alla pagina (IntersectionObserver), così non compete col
        // render iniziale su mobile/3G. In verifica-mode (admin che costruisce)
        // resta eager (serve subito).
        scheduleRelatedVerifica();
    });
}

/** G27 PERF — defer del caricamento verifica correlata. Posiziona un sentinel
 *  in fondo a #fm-content e fa partire `loadRelatedVerifica()` solo quando si
 *  avvicina al viewport (rootMargin 800px). Se l'utente non scende in fondo,
 *  la correlata non viene scaricata affatto. Eccezione: verifica-mode attiva
 *  (admin) → carica subito. Idempotente (skip se #type_verAll o sentinel già
 *  presenti); su SPA-nav il sentinel sparisce con lo swap di #fm-content. */
function scheduleRelatedVerifica() {
    if (document.getElementById("type_verAll")) return;
    // Solo dove c'è contenuto esercizio (la correlata si aggancia ai titoli).
    if (!document.querySelector(".fm-contract-render .fm-titolo h1, .fm-pagestyle .fm-titolo h1")) return;
    // NB: il load INIZIALE si deferisce sempre. I re-load espliciti restano
    // eager: nav multiarg (listener fm:navigated sotto) e window.FM.*RelatedVerifica
    // chiamano loadRelatedVerifica() diretto — sono azioni utente, non il load.
    const container = document.getElementById("fm-content") || document.body;
    if (container.querySelector(":scope > [data-related-sentinel]")) return; // già schedulato
    if (typeof IntersectionObserver === "undefined") {
        const idle = window.requestIdleCallback || ((cb) => setTimeout(cb, 1200));
        idle(() => loadRelatedVerifica());
        return;
    }
    const sentinel = document.createElement("div");
    sentinel.setAttribute("data-related-sentinel", "");
    sentinel.setAttribute("aria-hidden", "true");
    sentinel.style.cssText = "height:1px;width:100%;margin:0;padding:0;";
    container.appendChild(sentinel);
    const io = new IntersectionObserver((entries) => {
        if (!entries.some((e) => e.isIntersecting)) return;
        io.disconnect();
        sentinel.remove();
        loadRelatedVerifica();
    }, { rootMargin: "400px 0px" });
    io.observe(sentinel);
}

/** Phase 15/20 — carica verifiche correlate in fondo a #fm-content.
 *
 *  SINGLE TOPIC: fetch /api/study/related-verifiche.html?subject=X&title=Y
 *  MULTIARG (body.fm-multiarg + ≥2 contract-render): fetch per OGNI topic,
 *    intersect ids dei .fm-collection__item, render solo i quesiti comuni a tutti.
 *
 *  Idempotente: check #type_verAll esistente.
 *
 *  G20.7 — concurrency lock: ctrl+click sidepage triggera dom-manager.js
 *  che dispatcha `fm:navigated` con `multiarg:true`. Due listener fanno
 *  partire `loadRelatedVerifica` async in parallelo (initVerificaBuilder
 *  chain + listener dedicato linea 476). Senza lock entrambi vedono
 *  `existing=null` (nessuno ha ancora creato il section) → race → 2
 *  copie di #type_verAll. Il lock condivide la stessa promise. */
let _loadingPromise = null;
async function loadRelatedVerifica() {
    if (_loadingPromise) return _loadingPromise;
    _loadingPromise = _loadRelatedVerificaImpl().finally(() => { _loadingPromise = null; });
    return _loadingPromise;
}
async function _loadRelatedVerificaImpl() {
    const route = document.getElementById("fm-content")?.dataset?.route || location.pathname;
    const m = route.match(/^\/studio\/esercizio\/([^/]+)\/([^/]+)\/([^/]+)\/(.+)$/);
    if (!m) return;
    const [, , , subj] = m;

    const titles = Array.from(document.querySelectorAll(
        ".fm-contract-render .fm-titolo h1, .fm-pagestyle .fm-titolo h1"
    )).map((h) => h.textContent?.trim()).filter(Boolean);
    if (!titles.length) return;
    const uniqueTitles = [...new Set(titles)];

    // Idempotenza: se #type_verAll già presente per lo stesso set di topic
    // skippa. Se il count è cambiato (multiarg aggiunge argomento nuovo),
    // rimuovi il precedente e ri-renderizza con intersect aggiornato.
    const existing = document.getElementById("type_verAll");
    if (existing) {
        const prevCount = Number(existing.getAttribute("data-multiarg-count") || "1");
        if (prevCount === uniqueTitles.length) return;
        existing.remove();
    }

    try {
        const fetches = uniqueTitles.map((title) => {
            const qs = new URLSearchParams({ subject: subj, title });
            return fetch(`/api/study/related-verifiche.html?${qs}`, { credentials: "same-origin" })
                .then((r) => (r.ok ? r.text() : ""))
                .catch(() => "");
        });
        const results = await Promise.all(fetches);
        const valid = results.filter((h) => h && h.trim() && !h.trim().startsWith("<!--"));
        if (!valid.length) return;

        // Phase 20 — UNIONE per-argomento con separatori + dedup per-wrap.
        //
        // Struttura risultante:
        //   <section id="type_verAll" class="fm-related-verifiche fm-multiarg-union">
        //     <header class="fm-titolo"><h2>Verifiche correlate</h2></header>
        //     <section class="fm-topic-section" data-topic="Radicali">
        //       <h3 class="fm-titolo">Radicali</h3>
        //       <div class="fm-contract-wrap" data-id="123">
        //         <div class="fm-contract-render">...fm-groupcollex.../>
        //       </div>
        //     </section>
        //     <section class="fm-topic-section" data-topic="Equazioni">
        //       <h3 class="fm-titolo">Equazioni</h3>
        //       <div class="fm-contract-wrap" data-id="456">...</div>
        //     </section>
        //   </section>
        //
        // I .fm-contract-wrap conservano data-id → checkin-handlers ruota
        // edit/delete/move sull'endpoint corretto (/api/teacher/content/{id}/...).
        // populatePositionInputs numera .move-position-problem da 1 per OGNI
        // section (parentElement distinto) → ogni argomento ha enumerazione
        // indipendente.
        const domes = valid.map((h) => {
            const d = document.createElement("div");
            d.innerHTML = h;
            return d;
        });
        const seenWrapIds = new Set();
        const root = document.createElement("div");
        const typeVerAll = document.createElement("section");
        typeVerAll.id = "type_verAll";
        typeVerAll.className = "fm-related-verifiche";
        if (uniqueTitles.length > 1) typeVerAll.classList.add("fm-multiarg-union");
        typeVerAll.setAttribute("data-multiarg-count", String(uniqueTitles.length));
        // Phase 20 — header con checkbox "Mostra esercizi studenti":
        // visibile solo in body.fm-esercizio-multiarg (≥2 ids in URL), toggla
        // body.fm-show-student-ex per ri-mostrare gli esercizi assegnati agli
        // studenti (nascosti di default in modalità multiarg così il docente
        // vede solo gli esercizi da aggregare in verifica).
        typeVerAll.innerHTML = ''
            + '<header class="fm-titolo fm-related-header">'
            // G20.7 — <h2>Verifiche correlate</h2> rimosso (richiesta utente).
            // L'header resta come ancora di posizionamento per verTitle/Prefix
            // (relocateVerTitleHeader li monta qui).
            +   '<label class="fm-toggle-student-ex" title="Mostra anche gli esercizi assegnati agli studenti">'
            +     '<input type="checkbox" id="fm-show-student-ex">'
            +     '<span>Mostra esercizi studenti</span>'
            +   '</label>'
            + '</header>';

        domes.forEach((dom, idx) => {
            const topicTitle = uniqueTitles[idx] || "";
            dom.querySelectorAll(".fm-contract-wrap[data-id]").forEach((wrap) => {
                const id = wrap.dataset.id;
                if (!id || seenWrapIds.has(id)) return; // dedup wrap cross-fetch
                seenWrapIds.add(id);
                const section = document.createElement("section");
                section.className = "fm-topic-section";
                section.setAttribute("data-topic", topicTitle);
                if (topicTitle) {
                    // a11y (WCAG 1.3.1 heading-order): la sezione-topic è una
                    // sottosezione dell'<h1> di pagina (titolo topic/materia) →
                    // h2, non h3 (evita il salto h1→h3). Il topic resta leggibile
                    // anche da `data-topic` (source-of-truth, settato sotto).
                    const h = document.createElement("h2");
                    h.className = "fm-titolo";
                    h.textContent = topicTitle;
                    section.appendChild(h);
                }
                section.appendChild(wrap);
                typeVerAll.appendChild(section);
                // Phase 20 — .fm-titolo esterno (+ data-topic) source-of-truth per il topic.
                // Rimuovi .fm-titolo interno del .fm-contract-render (dup visivo).
                if (topicTitle) {
                    wrap.querySelector(":scope > .fm-contract-render > .fm-titolo")?.remove();
                }
            });
        });
        root.appendChild(typeVerAll);
        const wrap = root;

        const container = document.getElementById("fm-content") || document.body;
        container.appendChild(wrap);

        // Phase 20 — bind checkbox "Mostra esercizi studenti" (toggla
        // body.fm-show-student-ex). Stato iniziale off in multiarg mode.
        const showStudentCb = typeVerAll.querySelector("#fm-show-student-ex");
        if (showStudentCb) {
            showStudentCb.checked = document.body.classList.contains("fm-show-student-ex");
            showStudentCb.addEventListener("change", () => {
                document.body.classList.toggle("fm-show-student-ex", showStudentCb.checked);
            });
        }

        if (window.MathJax?.typesetPromise) {
            try { await window.MathJax.typesetPromise([wrap]); } catch (_) {}
        }
        reinjectLegacyControls();
    } catch (e) {
        console.warn("[verifica] loadRelatedVerifica fail", e);
    }
}

/** Phase 16 — i nuovi .fm-groupcollex/.fm-collection__item in #type_verAll hanno già
 *  server-rendered .checkIN/.selection/.checkmod/.moveBtn (ContractRenderer
 *  emette il markup). Serve solo popolare i .origin select appena iniettati
 *  e ribindare eventuali handler (checkin-handlers usa delegation su
 *  document, quindi auto-attiva). */
function reinjectLegacyControls() {
    if (typeof window.FM?.populateOriginSelects === "function") {
        try { window.FM.populateOriginSelects(); } catch (e) { console.warn("[verifica] populate fail", e); }
    }
    if (typeof window.FM?.restoreCheckinState === "function") {
        try { window.FM.restoreCheckinState(); } catch (e) { console.warn("[verifica] restore fail", e); }
    }
    // Phase 20 — numera .move-position-problem + .move-position per ogni
    // .fm-contract-render (parentElement distinto → numerazione indipendente
    // per ogni topic section in union mode).
    if (typeof window.FM?.populatePositionInputs === "function") {
        try { window.FM.populatePositionInputs(); } catch (e) { console.warn("[verifica] positions fail", e); }
    }
    if (typeof window.FM?.applyTopicColorCycle === "function") {
        try { window.FM.applyTopicColorCycle(); } catch (e) { console.warn("[verifica] color fail", e); }
    }
}

/** Phase 15: _caricaCheckboxABin prepende #scrollbarInfo a <body> quando
 *  #header_page non esiste. Nelle pagine /studio/ manca header_page → il
 *  blocco finisce full-width e invade la sidebar. Lo riallochiamo come primo
 *  figlio di #fm-content per ereditarne larghezza/padding. */
function relocateScrollbarInfo() {
    const sc = document.getElementById("scrollbarInfo");
    const content = document.getElementById("fm-content");
    if (!sc || !content) return;
    // Se è già dentro #fm-content, no-op
    if (content.contains(sc)) return;
    // Lascia in place se c'è #header_page (pattern legacy valido)
    if (document.getElementById("header_page")) return;
    content.prepend(sc);
}

function updateGenerateLabel() {
    const btn = document.getElementById("btnCopyver");
    if (!btn) return;
    btn.textContent = STATE.selected.size
        ? `GENERA-VER (${STATE.selected.size})`
        : "GENERA-VER";
}

async function generateVerifica() {
    if (!STATE.selected.size) {
        toast("Seleziona almeno un esercizio (spunta i checkbox sui quesiti).", "warn");
        return;
    }
    const title = await window.FM.Dialog.prompt("Titolo verifica:", suggestedTitle());
    if (title == null) return; // annullato
    const variant = (await window.FM.Dialog.prompt(
        "Variante (normal, dsa, dislessico, versioneA, versioneR):",
        "normal",
    ) || "normal").trim();
    const includeSolutions = await window.FM.Dialog.confirm("Includere le soluzioni nel TeX?");

    const body = new URLSearchParams();
    body.set("title", title);
    body.set("variant", variant);
    body.set("includeSolutions", includeSolutions ? "1" : "");
    for (const id of STATE.selected) body.append("exerciseIds[]", String(id));

    try {
        const csrf = await fetchCsrf();
        body.set("_csrf", csrf);
        const json = await fetchJson("/api/verifiche/build", {
            method: "POST",
            headers: {
                "X-Requested-With": "fetch",
                "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
            },
            body: body.toString(),
        });
        if (!json.ok) throw new Error(json.error || "richiesta non riuscita");
        toast(`Verifica #${json.id} salvata (${json.count} esercizi, ${json.tex_length} bytes).`, "ok");
        resetSelection(); // deseleziona quesiti senza uscire da verifica-mode
    } catch (e) {
        toast("Errore generazione verifica: " + (e.message || e), "err");
    }
}

async function copySelectedIds() {
    if (!STATE.selected.size) {
        toast("Nessun esercizio selezionato.", "warn");
        return;
    }
    const ids = Array.from(STATE.selected).sort((a, b) => a - b);
    try {
        await navigator.clipboard.writeText(ids.join(","));
        toast(`Copiati ${ids.length} id negli appunti.`, "ok");
    } catch {
        toast("Impossibile copiare: " + ids.join(","), "warn");
    }
}

function suggestedTitle() {
    const h1 = document.querySelector(".fm-titolo h1, #fm-content h1");
    const base = h1 ? h1.textContent.trim() : "Verifica";
    return `${base} — ${new Date().toISOString().slice(0, 10)}`;
}

function toast(msg, kind = "ok") {
    if (window.FM?.ToastManager?.show) {
        window.FM.ToastManager.show(msg, kind);
        return;
    }
    const el = document.createElement("div");
    el.textContent = msg;
    el.style.cssText =
        "position:fixed;top:80px;right:20px;z-index:9999;padding:10px 14px;border-radius:6px;color:#fff;" +
        "font:14px/1.4 system-ui;max-width:360px;box-shadow:0 6px 18px rgba(0,0,0,.35);" +
        (kind === "err" ? "background:#c02a2a" : kind === "warn" ? "background:#c78a2a" : "background:#1d7a3a");
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 4200);
}

// Auto-init su fm:navigated (SPA)
window.addEventListener("fm:navigated", initVerificaBuilder);
document.addEventListener("DOMContentLoaded", initVerificaBuilder);
// G19/lazy fix — se modulo caricato DOPO DOMContentLoaded (lazy import),
// l'event listener sopra non firerà mai. Self-init se DOM già pronto.
if (document.readyState !== "loading") {
    initVerificaBuilder();
}

// Phase 20/21 — su nav multiarg, se verifica-mode è attiva (admin),
// ricalcola verifica intersect con il set aggiornato di topic in pagina.
window.addEventListener("fm:navigated", (e) => {
    if (!STATE.active) return;
    if (!e.detail?.multiarg) return;
    loadRelatedVerifica().catch(() => {});
});

window.FM = window.FM || {};
window.FM.initVerificaBuilder = initVerificaBuilder;
window.FM.loadRelatedVerifica = loadRelatedVerifica;
window.FM.reloadRelatedVerifica = async () => {
    document.getElementById("type_verAll")?.remove();
    return loadRelatedVerifica();
};
