/**
 * Phase G19.3 — DSA marks (F / GF) per riga `.fm-li-inline` — modern impl.
 *
 * Replica del legacy `ui-comp.js _caricaElemRiservati` (DOM jQuery) in
 * versione modern (no jQuery, document delegation, idempotente).
 *
 * Cosa fa:
 *   1. Inietta `.dsa-wrapper-container` come primo figlio di ogni
 *      `.fm-li-inline` dentro `.fm-collection__item` (verifica-mode). Skipa se già
 *      presente. Genera id univoci `dsa-checkboxF/GF-{item.dataset.id}`.
 *
 *   2. Toggle F → prepend `<span class="fm-add-text-dsa has-checkbox">(*F*) </span>`
 *      al primo container con testo dentro `.fm-collection`. Toggle GF idem
 *      con `(*GF*)`. F e GF sono mutually exclusive per `.fm-li-inline`.
 *
 *   3. Persistenza: stato per-li-inline in `sessionStorage` con chiave
 *      `fm-dsa-marks` → `{liUid: "F"|"GF"|""}`. Serializzato dal
 *      `salva-scelte-btn` insieme al resto della selezione (G19.2).
 *
 *   4. Restore al `fm:verifica-ui-loaded`: ri-applica i marks da
 *      sessionStorage al DOM (dopo che ContractRenderer ha popolato
 *      `.fm-li-inline`).
 *
 *  Perché in chiave moderna:
 *   - No jQuery: pure DOM API (querySelector, classList, addEventListener).
 *   - Idempotente: `dataset.fmDsaInjected = "1"` su `.fm-li-inline` evita
 *     doppi insert su SPA-nav o re-render.
 *   - Document-level delegation per i click → gestisce anche `.fm-li-inline`
 *     creati dopo init (es. nuovi quesiti via add).
 *   - I marks sono dati semantici (sessionStorage struct), non HTML
 *     mutation server-side → no chiamate `/update_dsa_checkbox.php`.
 *     La persistenza cross-session passa per `.salva-scelte-btn` →
 *     `verifiche/scelte` (vedi `verifica-scelte.js`).
 */

const STORAGE_KEY = "fm-dsa-marks";

function loadMarks() {
    try {
        return JSON.parse(sessionStorage.getItem(STORAGE_KEY) || "{}") || {};
    } catch (_) { return {}; }
}

function saveMarks(map) {
    try { sessionStorage.setItem(STORAGE_KEY, JSON.stringify(map)); }
    catch (_) { /* quota — ignora */ }
}

/** UID stabile per ogni `.fm-li-inline`: riusa l'id del `.fm-collection__item` (dataset.id
 *  o id) + posizione del li dentro il container. */
function liUid(li) {
    const item = li.closest(".fm-collection__item");
    const itemId = item?.dataset?.id || item?.id || "";
    if (!itemId) return null;
    const allLis = item.querySelectorAll(".fm-li-inline");
    const idx = Array.prototype.indexOf.call(allLis, li);
    return `${itemId}-li${idx}`;
}

/** Trova il container marker dentro `.fm-collection` (primo div con testo, fallback
 *  primo div). Se manca, ne crea uno per ospitare lo span `(*F*)`. */
function findOrCreateMarkerHost(li) {
    const collex = li.querySelector(".fm-collection");
    if (!collex) return null;
    let host = Array.from(collex.children).find(
        (c) => c.tagName === "DIV" && (c.textContent || "").trim().length > 0,
    );
    if (!host) host = collex.querySelector(":scope > div");
    if (!host) {
        host = document.createElement("div");
        collex.prepend(host);
    }
    // Sposta i text-node diretti di `.fm-collection` nel host
    Array.from(collex.childNodes).forEach((n) => {
        if (n.nodeType === Node.TEXT_NODE && n.textContent.trim()) host.appendChild(n);
    });
    return host;
}

/** G22.S15.bis (rev2) — DEPRECATO: ora i pulsanti F/GF NON mutano il testo.
 *  Lo stato e' solo persistito; la pipeline TeX export verifica leggera' i
 *  marker e iniettera' "(*F*) "/"(*GF*) " nel sorgente .tex finale.
 *  Funzione conservata per BC: rimuove eventuali AddTextDSA residui da
 *  vecchie sessioni ma NON ne aggiunge di nuovi. */
function applyMarkerVisual(li, kind) {
    const host = findOrCreateMarkerHost(li);
    if (!host) return;
    // Pulizia legacy: rimuovi qualunque "(*F*) "/"(*GF*) " residuo da sessioni
    // pre-G22.S15.bis dove i checkbox mutavano il DOM.
    Array.from(host.querySelectorAll(":scope > span.fm-add-text-dsa")).forEach((s) => s.remove());
    // NIENTE prepend: nessuna mutazione testo. Lo stato e' su data-fm-dsa-state.
    void kind;
    return;
    // eslint-disable-next-line no-unreachable
    if (kind === "F" || kind === "GF") {
        const span = document.createElement("span");
        span.className = "fm-add-text-dsa has-checkbox";
        span.style.display = "inline";
        span.textContent = `(*${kind}*) `;
        host.prepend(span);
    }
}

/** G22.S15.bis (rev2) — pulsanti F/GF (no più checkboxes con text mutation).
 *  Stessa logica dei li-buttons: toggle inset/outset, mutex, persistenza,
 *  NESSUNA modifica al testo. Allineato a renderDsaWrapper PHP. */
function buildDsaWrapper(uid) {
    const wrap = document.createElement("div");
    wrap.className = "dsa-wrapper-container";
    wrap.dataset.fmDsaUid = uid;
    wrap.dataset.fmDsaState = "";
    wrap.innerHTML = `
        <button type="button" class="fm-dsa-li-btn fm-dsa-item-btn fm-dsa-item-F" data-mark="F" title="*F* — facoltativo">F</button>
        <button type="button" class="fm-dsa-li-btn fm-dsa-item-btn fm-dsa-item-GF" data-mark="GF" title="*GF* — giustifica facoltativa">GF</button>
    `;
    return wrap;
}

/** Inject + restore: idempotente per ogni `.fm-li-inline` con `.fm-collection__item` parent.
 *  G22.S15.bis (rev2) — wrapper SSR con pulsanti (no più checkboxes/text mutation).
 *  Restore dello stato avviene via restoreLiButtons (anche per item-level).
 *  applyMarkerVisual disabilitato: lo stato e' SOLO persistito (la pipeline
 *  TeX export verifica leggera' i marker e iniettera' "(*F*) "/"(*GF*) "
 *  nel sorgente .tex finale).
 */
function injectAll(root = document) {
    // Phase 25.Q.12 — skip injection se utente non ha edit scope (student/guest).
    // Il body riceve data-fm-can-edit="0" da app.php per ruoli non-edit.
    // Defense-in-depth: il PHP renderer non emette già il wrapper, ma qui
    // chiudiamo anche la via JS in caso di partial reload via fm-router.
    if (document.body?.dataset?.fmCanEdit === "0") return;
    root.querySelectorAll(".fm-collection__item .fm-li-inline").forEach((li) => {
        if (li.dataset.fmDsaInjected === "1") return;
        const uid = liUid(li);
        if (!uid) return;
        let wrap = li.querySelector(".fm-badge-row .dsa-wrapper-container");
        if (!wrap) {
            wrap = li.querySelector(":scope > .dsa-wrapper-container");
        }
        if (!wrap) {
            wrap = buildDsaWrapper(uid);
            li.prepend(wrap);
        }
        li.dataset.fmDsaInjected = "1";
    });
    // Restore unificato (item-level + li-level)
    restoreLiButtons(root);
}

/** Gestisce click su `.dsa-checkbox` con mutex F/GF + persiste.
 *  Skippa i checkbox `.fm-dsa-li` (marker DSA inline nei `<li>` della traccia,
 *  classe `.fm-dsa-li`): sono toggle locali senza persistenza/mutex. */
function onCheckboxChange(e) {
    const cb = e.target;
    if (!cb?.classList?.contains("dsa-checkbox")) return;
    if (cb.classList.contains("fm-dsa-li")) return;  // li-mark: no-op qui
    const li = cb.closest(".fm-li-inline");
    if (!li) return;
    const uid = liUid(li);
    if (!uid) return;

    const isF  = cb.classList.contains("fm-dsa-F")  || cb.id.includes("dsa-checkboxF-");
    const isGF = cb.classList.contains("fm-dsa-GF") || cb.id.includes("dsa-checkboxGF-");
    const wrap = li.querySelector(".dsa-wrapper-container");
    if (!wrap) return;

    let kind = "";
    if (cb.checked) {
        kind = isF ? "F" : isGF ? "GF" : "";
        // Mutex: deseleziona l'altro
        const otherSel = isF ? ".fm-dsa-GF, [id^=dsa-checkboxGF-]" : ".fm-dsa-F, [id^=dsa-checkboxF-]";
        const other = wrap.querySelector(otherSel);
        if (other) other.checked = false;
    }
    applyMarkerVisual(li, kind);
    const marks = loadMarks();
    if (kind) marks[uid] = kind; else delete marks[uid];
    saveMarks(marks);
}

/** G22.S15.bis — Pulsanti F/GF inline nei `<li>` della traccia (server-side).
 *  Toggle inset/outset con mutex F↔GF. NESSUNA mutazione del testo (a differenza
 *  di onCheckboxChange che antepone "(*F*) "/"(*GF*) "); serve solo per
 *  registrare lo stato che la TeX export pipeline leggera' per inserire
 *  i marker nel sorgente .tex della verifica.
 *
 *  Storage: `fm.dsa-li-marks` in sessionStorage = { "<itemUid>::<liIdx>": "F"|"GF" }.
 *  Quando una verifica viene compilata, il backend riceve il payload con i
 *  marker e li applica al rendering TeX (TODO).
 */
const _LI_MARKS_KEY = "fm.dsa-li-marks";
function loadLiMarks() {
    try { return JSON.parse(sessionStorage.getItem(_LI_MARKS_KEY) || "{}"); }
    catch { return {}; }
}
function saveLiMarks(m) {
    try { sessionStorage.setItem(_LI_MARKS_KEY, JSON.stringify(m)); }
    catch { /* quota / private mode: silently */ }
}
/** Compone la chiave persistente per un <li> dato. Path-based per evitare
 *  collisione tra outer-li-0 e sub-li-0 (entrambi avrebbero idx=0 nel parent).
 *  G27.dsa — la chiave include il path completo dei nesting list:
 *  `${itemUid}::${path}` dove path = "0" per outer, "0.1.2" per il 3° figlio
 *  della sub-list nel 2° figlio della outer.
 */
function liButtonKey(li) {
    const item = li.closest(".fm-collection__item");
    if (!item) return null;
    const itemUid = item.dataset?.id || liUid(li) || "?";
    const path = computeItemScopedLiPath(li, item);
    if (path === null) return null;
    return `${itemUid}::${path}`;
}
/** Chiave persistente per i pulsanti DSA a livello item (badge area). */
function itemButtonKey(wrap) {
    const uid = wrap.dataset.fmDsaUid || "";
    return uid ? `item::${uid}` : null;
}
function applyLiButtonState(li, kind) {
    li.dataset.fmDsaState = kind || "";
    const btnF  = li.querySelector(":scope > .fm-dsa-li-buttons .fm-dsa-li-F");
    const btnGF = li.querySelector(":scope > .fm-dsa-li-buttons .fm-dsa-li-GF");
    btnF?.classList.toggle("fm-dsa-active",  kind === "F");
    btnGF?.classList.toggle("fm-dsa-active", kind === "GF");
}
function applyItemButtonState(wrap, kind) {
    wrap.dataset.fmDsaState = kind || "";
    const btnF  = wrap.querySelector(".fm-dsa-item-F");
    const btnGF = wrap.querySelector(".fm-dsa-item-GF");
    btnF?.classList.toggle("fm-dsa-active",  kind === "F");
    btnGF?.classList.toggle("fm-dsa-active", kind === "GF");
}
function onLiButtonClick(e) {
    const btn = e.target?.closest?.(".fm-dsa-li-btn");
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();

    // Caso 1: pulsanti item-level (badge area, classe .fm-dsa-item-btn)
    if (btn.classList.contains("fm-dsa-item-btn")) {
        const wrap = btn.closest(".dsa-wrapper-container");
        if (!wrap) return;
        const key = itemButtonKey(wrap);
        if (!key) return;
        const newKind = btn.dataset.mark || "";
        const cur = wrap.dataset.fmDsaState || "";
        const next = (cur === newKind) ? "" : newKind;
        applyItemButtonState(wrap, next);
        const marks = loadLiMarks();
        if (next) marks[key] = next; else delete marks[key];
        saveLiMarks(marks);
        // G27.dsa.persist — patcha server (item.mark + dsa_marks).
        persistContractItemMarks(btn.closest(".fm-collection__item"));
        return;
    }

    // Caso 2: pulsanti li-level (dentro <li> della traccia)
    const li = btn.closest("li");
    if (!li) return;
    const key = liButtonKey(li);
    if (!key) return;
    const newKind = btn.dataset.mark || "";
    const cur = li.dataset.fmDsaState || "";
    const next = (cur === newKind) ? "" : newKind;
    applyLiButtonState(li, next);
    const marks = loadLiMarks();
    if (next) marks[key] = next; else delete marks[key];
    saveLiMarks(marks);
    // G27.dsa.persist — patcha server (item.mark + dsa_marks).
    persistContractItemMarks(li.closest(".fm-collection__item"));
}

/** G27.dsa.persist — Persiste lo stato F/GF dell'intero collex-item sul
 *  contract server-side. Mantiene anche sessionStorage (sync layer per
 *  serializzazione verifica-scelte e per resilienza offline).
 *
 *  Patch payload:
 *    - `mark`: stato del wrapper item-level (.dsa-wrapper-container) →
 *      F/GF/"" (vuoto = nessun marker).
 *    - `dsa_marks`: mappa { "0.1.2": "F" } per ogni <li> con
 *      data-fm-dsa-state non vuoto. Path computato come liButtonKey() ma
 *      SENZA il prefisso itemUid (la chiave e' gia' scoped per item).
 *
 *  Endpoint: `/api/teacher/content/{id}/quesito/{itemRef}/patch` via
 *  `window.FM.patchContractItem` (silent retry su 409).
 */
function persistContractItemMarks(collexItem) {
    if (!collexItem) return;
    const patchFn = window.FM?.patchContractItem;
    if (typeof patchFn !== "function") return;
    // Item-level mark (badge area).
    const wrap = collexItem.querySelector(".dsa-wrapper-container[data-fm-dsa-uid]");
    const itemMark = wrap?.dataset?.fmDsaState || "";
    // Li-level marks: walk <li data-fm-dsa-state> e ricostruisce path
    // posizionale (NB: usa Array.indexOf su parent.children come liButtonKey).
    const dsaMarks = {};
    collexItem.querySelectorAll("li[data-fm-dsa-state]").forEach((li) => {
        const state = li.dataset.fmDsaState || "";
        if (state !== "F" && state !== "GF") return;
        const path = computeItemScopedLiPath(li, collexItem);
        if (path !== null) dsaMarks[path] = state;
    });
    patchFn(collexItem, { mark: itemMark, dsa_marks: dsaMarks });
}

/** Path posizionale di un <li> RELATIVO al collex-item (no itemUid prefix).
 *  Match esatto con la chiave generata server-side da
 *  ContractRenderer::renderBlocks($dsaMarks, $pathPrefix), che indicizza per
 *  posizione nella `.fm-dsa-li-list` (NON conta il <li class="fm-li-inline">
 *  wrapper dell'intero collex-item, ne' altri <li> non-DSA).
 *
 *  Esempi (question/sub):
 *    outer[0] → "0"
 *    outer[1].sub[0] → "1.0"
 *    outer[1].sub[2].sub[1] → "1.2.1"
 *
 *  Esempi (RM cell): prefix `t{tIdx}_cell_{row}_{col}` per disambiguare
 *  tra celle (server: ContractRenderer::renderRmTable):
 *    cell(0,0).li[0] → "t0_cell_0_0.0"
 *    table#1, cell(2,1).li[0].sub[1] → "t1_cell_2_1.0.1"
 *
 *  Ritorna null se il <li> non e' dentro l'item (oppure dentro `.fm-li-inline`
 *  ma fuori da una `.fm-dsa-li-list`, es. il wrapper stesso). */
function computeItemScopedLiPath(li, collexItem) {
    if (!collexItem.contains(li)) return null;
    if (li.classList.contains("fm-li-inline")) return null;
    const segments = [];
    let cur = li;
    while (cur && cur.tagName === "LI" && !cur.classList.contains("fm-li-inline")) {
        const list = cur.parentElement;
        if (!list) break;
        if (!list.classList?.contains("fm-dsa-li-list")) break;
        segments.unshift(Array.from(list.children).indexOf(cur));
        const ancestorLi = list.closest("li");
        if (!ancestorLi || ancestorLi.classList.contains("fm-li-inline")) break;
        if (!collexItem.contains(ancestorLi)) break;
        cur = ancestorLi;
    }
    if (!segments.length) return null;
    const base = segments.join(".");
    // RM cell scoping: se il li e' dentro `td.rm-option`, prepend prefisso
    // cella per disambiguare tra celle (vedi server renderRmTable).
    const cellTd = li.closest("td.rm-option[data-row][data-col]");
    if (cellTd) {
        const r = cellTd.getAttribute("data-row");
        const c = cellTd.getAttribute("data-col");
        const wrap = cellTd.closest(".fm-rm-tables-wrap");
        const table = cellTd.closest("table.fm-rm-table");
        const tIdx = (wrap && table)
            ? Array.from(wrap.querySelectorAll("table.fm-rm-table")).indexOf(table)
            : 0;
        return `t${tIdx}_cell_${r}_${c}.${base}`;
    }
    return base;
}
/** Restore stato pulsanti dopo render/page reload. Idempotente. */
function restoreLiButtons(root = document) {
    const marks = loadLiMarks();
    if (!Object.keys(marks).length) return;
    // li-level
    root.querySelectorAll(".fm-dsa-li-list > li").forEach((li) => {
        const key = liButtonKey(li);
        if (!key) return;
        const kind = marks[key];
        if (kind === "F" || kind === "GF") applyLiButtonState(li, kind);
    });
    // item-level (badge area)
    root.querySelectorAll(".dsa-wrapper-container[data-fm-dsa-uid]").forEach((wrap) => {
        if (!wrap.querySelector(".fm-dsa-item-btn")) return; // skip legacy checkboxes
        const key = itemButtonKey(wrap);
        if (!key) return;
        const kind = marks[key];
        if (kind === "F" || kind === "GF") applyItemButtonState(wrap, kind);
    });
}

let _bound = false;
function init() {
    if (_bound) return;
    _bound = true;
    document.addEventListener("change", onCheckboxChange, true);
    document.addEventListener("click",  onLiButtonClick, true);
    // Restore pulsanti F/GF su page load + dopo SPA navigation.
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", () => restoreLiButtons(), { once: true });
    } else {
        restoreLiButtons();
    }
    window.addEventListener("fm:navigated", () => restoreLiButtons());
    // Init iniziale
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", () => injectAll(), { once: true });
    } else {
        injectAll();
    }
    window.addEventListener("fm:navigated", () => injectAll());
    window.addEventListener("fm:verifica-ui-loaded", () => injectAll());
    // Re-run quando il DOM dei .fm-collection__item cambia (nuovi quesiti via add)
    const obs = new MutationObserver((mutations) => {
        for (const m of mutations) {
            for (const node of m.addedNodes) {
                if (node.nodeType !== 1) continue;
                if (node.matches?.(".fm-li-inline") || node.querySelector?.(".fm-li-inline")) {
                    injectAll(node.parentNode || document);
                    return;
                }
            }
        }
    });
    obs.observe(document.body, { childList: true, subtree: true });
}

init();

window.FM = window.FM || {};
window.FM.DsaMarks = { injectAll, loadMarks, saveMarks };
