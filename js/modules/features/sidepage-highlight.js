/**
 * Phase 20 — evidenzia nella sidepage gli item `<li data-content-id="N">`
 * corrispondenti ai contract/mappa attualmente renderizzati in pagina.
 *
 * Nel caso multiarg: più `.fm-contract-wrap[data-id]` sono simultaneamente
 * visibili in #fm-content → tutti i relativi items ottengono la classe
 * `.fm-open` (CSS evidenzia con bordo/sfondo accent).
 *
 * Listener:
 *   - DOMContentLoaded (first paint)
 *   - fm:navigated (SPA nav)
 *   - fm:db-sidepage-rendered (la sidepage è stata popolata dopo load)
 * Il DOM content può arrivare async (verifiche correlate, db sidepage):
 *   osserva #fm-content con MutationObserver (1s debounce) per ri-evidenziare.
 */

const STORAGE_KEY = "fm.clickedContentIds";

// Phase 24.12 — include anche risdoc/strcomp items che usano
// `data-template-id` invece di `data-content-id`. Tutti gli item evidenziabili
// nella sidepage matchano uno dei due attributi.
const PANEL_ITEM_SELECTOR      = ".fm-sb-panel li[data-content-id], .fm-sb-panel li[data-template-id]";
const PANEL_ITEM_LINK_SELECTOR = ".fm-sb-panel li[data-content-id] a[href], .fm-sb-panel li[data-template-id] a[href]";

function getItemId(li) {
    return li.getAttribute("data-content-id") || li.getAttribute("data-template-id") || null;
}

function collectOpenIds(root = document) {
    const ids = new Set();
    // Contract-backed (esercizi/verifiche/mappe/bes/risdoc modern)
    root.querySelectorAll(".fm-contract-wrap[data-id], .fm-mappa-wrap[data-id]").forEach((el) => {
        const id = el.getAttribute("data-id");
        if (id && /^\d+$/.test(id)) ids.add(id);
    });
    // Risdoc view legacy: /risdoc/view/{id} con wrapper .fm-risdoc-view[data-template-id]
    root.querySelectorAll(".fm-risdoc-view[data-template-id]").forEach((el) => {
        const id = el.getAttribute("data-template-id");
        if (id && /^\d+$/.test(id)) ids.add(id);
    });
    return ids;
}

function getClickedIds() {
    try {
        const raw = sessionStorage.getItem(STORAGE_KEY);
        if (!raw) return new Set();
        const arr = JSON.parse(raw);
        return new Set(Array.isArray(arr) ? arr.map(String) : []);
    } catch (_) { return new Set(); }
}

function setClickedIds(set) {
    try { sessionStorage.setItem(STORAGE_KEY, JSON.stringify([...set])); } catch (_) {}
}

/** Regola: evidenzia gli items che sono sia (1) visibili in pagina come
 *  contract/mappa wrap AND (2) cliccati esplicitamente dall'utente nella
 *  sidepage (o target della nav corrente).
 *  Se nessun click esplicito salvato ma c'è 1 solo wrap visibile in pagina
 *  (topic single-item) → evidenzialo comunque (case semplice).
 *  Multiarg: l'utente ha cliccato più items → evidenziane più di uno. */
function applyHighlight() {
    const openIds = collectOpenIds();
    const clicked = getClickedIds();

    // Filtra clicked mantenendo solo quelli ancora visibili in pagina.
    // Se un id non ha più wrap (user ha navigato a un altro topic), lo droppa.
    const activeClicked = new Set([...clicked].filter((id) => openIds.has(id)));

    // Se lo storage è coerente col DOM, highlight = activeClicked.
    // Altrimenti fallback: se c'è 1 solo wrap in pagina, evidenzialo (click
    // probabilmente ha triggerato nav via other tool, ancora non stored).
    let highlightIds = activeClicked;
    if (highlightIds.size === 0 && openIds.size === 1) {
        highlightIds = openIds;
    }

    // Persist set cleaned (drop IDs not in page anymore)
    setClickedIds(activeClicked);

    document.querySelectorAll(PANEL_ITEM_SELECTOR).forEach((li) => {
        const id = getItemId(li);
        li.classList.toggle("fm-open", highlightIds.has(id));
    });
}

/** Click sul link nella sidepage: costruisce dinamicamente l'URL con
 *  `?ids=`, a prescindere da quello nell'href del DOM (che potrebbe
 *  essere stale se la sidepage non è stata re-renderizzata post-deploy).
 *   - Click normale → ?ids=<id> (sostituisce)
 *   - Ctrl/Cmd+click → aggiunge l'id agli open correnti + preventDefault
 *   - body.fm-multiarg → equivale a ctrl+click
 *
 *  Il server topicPage filtra $rows ai soli id in ?ids=. Se href DOM
 *  già matcha target → lascia passare (fm-router navig partial);
 *  altrimenti preventDefault + location.href = target. */
function handleSidepageClick(e) {
    const a = e.target.closest(PANEL_ITEM_LINK_SELECTOR);
    if (!a) return;
    const li = a.closest("li[data-content-id], li[data-template-id]");
    const id = li ? getItemId(li) : null;
    if (!id) return;

    // Phase 20 — additive solo via Ctrl/Cmd+click (checkbox +ARGOMENTI
     // rimossa; body.fm-multiarg deprecata).
    const additive = e.ctrlKey || e.metaKey;

    let target;
    try { target = new URL(a.href, location.origin); }
    catch { return; }

    const set = new Set();
    if (additive) {
        // Base: wrap realmente aperti in pagina (.fm-contract-wrap /
        // .fm-mappa-wrap con data-id numerico). Questo è source-of-truth
        // per "cosa è attualmente mostrato", non il target.href che
        // include il NUOVO id per navigation.
        collectOpenIds().forEach((x) => set.add(x));
        // TOGGLE: se l'item cliccato è già aperto → rimuovi (deselect).
        // Altrimenti → aggiungi.
        if (set.has(id)) set.delete(id);
        else set.add(id);
    } else {
        set.add(id);
    }
    if (set.size > 0) {
        target.searchParams.set("ids", [...set].join(","));
    } else {
        // Additive + set vuoto (tolto l'ultimo): rimuovi ?ids= e naviga
        // al topic completo. Evita pagina vuota se user toglie tutto.
        target.searchParams.delete("ids");
    }
    setClickedIds(set);

    // Sovrascrive SEMPRE a.href con la versione corretta. Al click
    // normale, fm-router delegation (bubble) leggerà questo href
    // aggiornato. Su ctrl+click, fm-router skippa (line 45 isInternalClick
    // rifiuta modifier keys) → dobbiamo preventDefault + navigate manuale.
    a.href = target.toString();
    li.classList.add("fm-open");

    if (e.ctrlKey || e.metaKey) {
        e.preventDefault();
        const router = window.fmRouter;
        if (typeof router?.navigate === "function") {
            router.navigate(target.toString());
        } else {
            location.href = target.toString();
        }
    }
    // Click normale senza modificatori: fm-router lo gestisce
    // automaticamente con l'href appena aggiornato.
}

let debounceTimer = null;
function scheduleHighlight() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(applyHighlight, 80);
}

function observeContent() {
    const root = document.getElementById("fm-content") || document.body;
    if (!root || root.dataset.fmHighlightObserved === "1") return;
    root.dataset.fmHighlightObserved = "1";
    const mo = new MutationObserver(scheduleHighlight);
    mo.observe(root, { childList: true, subtree: true });
}

function init() {
    applyHighlight();
    observeContent();
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
} else {
    init();
}
window.addEventListener("fm:navigated", scheduleHighlight);
document.addEventListener("fm:db-sidepage-rendered", scheduleHighlight);
// Phase 24.17 — risdoc/strcomp sidepage render emette evento diverso.
// Listen anche quello per ri-evidenziare il link su reload dopo che la
// sidepage è stata popolata via /api/risdoc/templates (async).
document.addEventListener("fm:risdoc-sidepage-rendered", scheduleHighlight);
document.addEventListener("click", handleSidepageClick, true);

window.FM = window.FM || {};
window.FM.refreshSidepageHighlight = applyHighlight;
