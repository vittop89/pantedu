/**
 * Phase G8 — Modern unified topbar controller.
 *
 * Responsabilita:
 *   - Attiva/disattiva la `#fm-topbar` server-rendered in base al contesto:
 *     mostra solo su pagine doc_mode=exercises (body.exercise-context con
 *     placeholder #upbar e contenuti `.fm-groupcollex` o layout=exercises).
 *   - Aggiunge classe `body.fm-topbar-active` cosi' che la upbar legacy
 *     possa nascondersi via CSS (vedi css/layout.css).
 *   - Wires button stubs: SalvaTEX/Overleaf/ZIP/GENERA/filtri/Editor.
 *     In G8.1 sono solo stub con toast "G8.x in corso"; gli step
 *     successivi implementano la logica reale.
 *   - Sync state legacy bridge: i click sui bottoni primari aggiornano
 *     gli hidden inputs (#overleaf, #Server) che `utilities.js` legge
 *     per save/restore delle preferenze utente in `print_info.json`
 *     (G22.S6: dopo dismissione print-export.js, restano usati solo
 *     dalle preferenze user-side, non piu' da consumer di TEX export).
 *   - Riaggancio SPA: ascolta `fm:navigated` e ri-attiva su ogni partial
 *     swap (idempotente via dataset flags).
 *
 * Detection (idempotente):
 *   active = body.exercise-context && (
 *       document.querySelector(".fm-groupcollex") ||
 *       document.querySelector("[data-layout=\"exercises\"]")
 *   ) && document.getElementById("fm-topbar")
 */

import { collectRawNodes, extractItemHtml, extractItemBadge, extractItemMark, extractProblemIntroHtml } from "../core/dom-block-extractor.js";
import { fetchJson, fetchCsrf, wafFetch } from "../core/dom-utils.js";

const TOPBAR_ID = "fm-topbar";
const BODY_ACTIVE_CLASS = "fm-topbar-active";
const BIND_FLAG = "fmTopbarBound";

/** G19.45 — wrapper async per FM.Dialog (themed) con fallback nativi
 *  se FM.Dialog non caricato (test env / pre-bootstrap). */
async function fmConfirm(message, title = "Conferma", kind = "warn") {
    if (window.FM?.Dialog?.confirm) return await window.FM.Dialog.confirm(message, { title, kind });
    return window.confirm(message);
}
async function fmPrompt(message, defaultVal = "", title = "Inserisci valore") {
    if (window.FM?.Dialog?.prompt) return await window.FM.Dialog.prompt(message, defaultVal, { title });
    return window.prompt(message, defaultVal);
}

function ensureToast(kind, title, msg) {
    if (window.FM?.ToastManager?.show) {
        window.FM.ToastManager.show(kind, title, msg, 3500);
        return;
    }
    console.info(`[topbar-modern] ${title}: ${msg}`);
}

function isContextActive() {
    // ADR-024 — le pagine PT custom rendono la PROPRIA topbar (componente
    // <fm-pt-document>, look risdoc). La topbar studio (azioni verifiche)
    // resta nascosta per evitare la doppia barra.
    if (document.querySelector(".fm-pt-custom-page")) return false;
    if (!document.body.classList.contains("fm-exercise-context")) return false;
    const hasProblems = !!document.querySelector(".fm-groupcollex");
    const hasExercisesLayout = !!document.querySelector('[data-layout="exercises"]');
    const hasFmDbStudy = !!document.querySelector(".fm-db-study");
    return hasProblems || hasExercisesLayout || hasFmDbStudy;
}

function setBridgeChecked(id, value) {
    const el = document.getElementById(id);
    if (!el) return;
    if (el.checked !== value) {
        el.checked = value;
        el.dispatchEvent(new Event("change", { bubbles: true }));
    }
}

function getBridgeChecked(id) {
    return !!document.getElementById(id)?.checked;
}

function syncButtonPressed(btn, pressed) {
    if (!btn) return;
    btn.setAttribute("aria-pressed", pressed ? "true" : "false");
}

function refreshOverleafToggle(topbar) {
    const btn = topbar.querySelector('[data-fm-action="overleaf"]');
    syncButtonPressed(btn, getBridgeChecked("overleaf"));
}

function readDocTitle() {
    /* Priorita': PT item title > contract h3 > topic h1 della pagestyle.
     * Esclude `.fm-header-body` / `.fm-source-citation` che contengono
     * testo informativo della licenza, non il titolo della verifica. */
    const candidates = [
        ".fm-pt-item-title",
        ".fm-contract-wrap h3",
        ".fm-pagestyle.fm-db-study > h1",
        ".fm-pagestyle.fm-db-study h1",
        ".fm-mappa-study > h1",
        ".fm-pagestyle h1",
    ];
    for (const sel of candidates) {
        const h = document.querySelector(sel);
        const t = h?.textContent?.trim();
        if (t) return t;
    }
    return "";
}

function readDocType() {
    const el = document.querySelector("[data-content-type]");
    const t = el?.dataset?.contentType || "";
    if (!t) return "Documento";
    return t.charAt(0).toUpperCase() + t.slice(1);
}

function updateMeta(topbar) {
    const dt = topbar.querySelector("[data-fm-doctype-label]");
    const tt = topbar.querySelector("[data-fm-title-label]");
    if (dt) dt.textContent = readDocType();
    if (tt) tt.textContent = readDocTitle();
    relocateTotals(topbar);
    relocateSelectorEser(topbar);
    relocateVerTitle(topbar);
    ensureVerTitleHeaderObserver();
}

/** G9.25 — sposta P.TOT-A / P.TOT-R nel topbar meta zone (slot
 *  data-fm-totals-slot). Idempotente: se gia' spostati, no-op.
 *  L'aggiornamento del valore (#SumPtotA/#SumPtotB) e' fatto da
 *  legacy JS via getElementById, non rompiamo nulla. */
function relocateTotals(topbar) {
    const slot = topbar.querySelector("[data-fm-totals-slot]");
    if (!slot) return;
    const a = document.getElementById("SumPtotA");
    const b = document.getElementById("SumPtotB");
    if (!a || !b) return; // legacy non ancora caricato
    const wrapA = a.closest(".fm-sub-wrap-info-school");
    const wrapB = b.closest(".fm-sub-wrap-info-school");
    // Se gia' nel topbar, no-op.
    if (slot.contains(a)) return;
    if (wrapA) slot.appendChild(wrapA);
    if (wrapB) slot.appendChild(wrapB);
}

/** G15 — legge le 4 checkbox InfoVer (Compensa/DSA/griglie/misure) per
 *  passarle al server come flag che governano l'applicazione del template:
 *    dsa            → pack lookup dsa=1 (griglia/footer DSA varianti)
 *    compensa       → include compensa nel footer (solo se DSA)
 *    includeGriglia → include sezione griglia_voti (default: true se checked)
 *    includeMisure  → include sezione criteri/ult_misure (default: true se checked)
 *  Replica semantica del legacy script_sel-mod.js btnCopyver flow. */
function readVerificaFlags() {
    const cb = (id) => !!document.getElementById(id)?.checked;
    return {
        dsa:            cb("DSA"),
        compensa:       cb("Compensa"),
        includeGriglia: cb("griglie"),
        includeMisure:  cb("misure"),
    };
}

/** Raccoglie la Selection JSON dal verifica container.
 *  G18 — strategia container-agnostic: invece di cercare un wrapper
 *  specifico (`.DraggableContainer_ver`, `.fm-draggable-container`,
 *  `#type_verAll`, `.fm-related-verifiche`) — che falliva quando il
 *  primo wrapper trovato NON conteneva il `.fm-groupcollex` selezionato —
 *  itera direttamente TUTTI i `.fm-groupcollex` del documento e filtra per
 *  `.checkboxA:checked`.
 *
 *  Inoltre rilassa il match su `.fm-checkbox-ain`: se il problema ha
 *  `.checkboxA` checked ma nessun `.fm-checkbox-ain` nei suoi item, considera
 *  TUTTI i `.fm-collection__item` come selezionati (semantica CheckAll-A: spunta
 *  intestazione = prendi tutti i quesiti). */
function buildSelectionFromDOM() {
    const allProblems = document.querySelectorAll(".fm-groupcollex");
    if (!allProblems.length) return null;
    const q = (id) => document.getElementById(id);
    // G19.8 — robust read da select: skip placeholder option ("Scegli la
    // classe:" / disabled) e cerca un valore valido fallback in:
    // sessionStorage → AppState → URL path → default.
    const readSelectValue = (id, fallback) => {
        const el = document.getElementById(id);
        const v = el?.value || "";
        // Placeholder option (disabled, label-as-value) o vuoto → fallback
        if (!v || /^scegli/i.test(v)) return fallback;
        return v;
    };
    // Estrae cls dall'URL `/studio/{ind}/{cls}/{mat}/...` come fallback robusto
    const urlMatch = location.pathname.match(/^\/studio\/(?:esercizio\/)?([^/]+)\/([^/]+)\/([^/]+)/);
    const [, urlIIS = "", urlCLS = "", urlMAT = ""] = urlMatch || [];
    const meta = {
        // G24.fix — `||` (non `??`): se l'input #anno/#verTitle esiste ma è
        // VUOTO ("") il `??` lo terrebbe → Selection lancia missing:anno. Su
        // pagina esercizio l'anno non è compilato → fallback all'anno corrente.
        verTitle:      (q("verTitle")?.value || document.querySelector(".fm-titolo h1")?.textContent?.trim() || "Verifica"),
        selectedIIS:   readSelectValue("sel-iis",
            sessionStorage.getItem("selectedIIS") || window.FM?.AppState?.selectedIIS || urlIIS || window.FM?.Curriculum?.firstCode("indirizzi") || ""),
        selectedCLS:   readSelectValue("sel-cls",
            sessionStorage.getItem("selectedCLS") || window.FM?.AppState?.selectedCLS || urlCLS || window.FM?.Curriculum?.firstCode("classi") || ""),
        selectedMATER: readSelectValue("sel-mater",
            sessionStorage.getItem("selectedMATER") || window.FM?.AppState?.selectedMATER || urlMAT || window.FM?.Curriculum?.firstCode("materie") || ""),
        anno:          (q("anno")?.value || String(new Date().getFullYear())),
        sezione:       q("sezione")?.value        ?? "NOR",
    };
    const problems = [];
    // collectRawNodes/extractItemHtml: vedi js/modules/core/dom-block-extractor.js
    // (single source of truth condivisa con verifiche-print-ui.js).
    // G19.7 — calcola UPFRONT quali versions sono richieste (A, R, o entrambe)
    // dallo stato dei .checkboxA / .checkboxB(.checkboxR) sui .fm-groupcollex header.
    // Se solo .checkboxA → version=A only. Se solo .checkboxB/R → version=R only.
    // Se entrambi → versions=[A, R] (verrà espanso in 8 varianti A/B × {SOL,NOR,DSA,DIS}).
    const anyAChecked = Array.from(allProblems).some(p => {
        const cb = p.querySelector("input.checkboxA, input#checkboxA");
        return cb && cb.checked;
    });
    const anyRChecked = Array.from(allProblems).some(p => {
        // .checkboxR è alias moderno di .checkboxB (recupero).
        const cb = p.querySelector("input.checkboxR, input.checkboxB, input#checkboxB");
        return cb && cb.checked;
    });

    allProblems.forEach((problem, idx) => {
        const checkboxA = problem.querySelector("input.checkboxA, input#checkboxA");
        const checkboxR = problem.querySelector("input.checkboxR, input.checkboxB, input#checkboxB");
        // Includi il problema se almeno UNA delle due (A o R) è checked.
        const aOn = !!checkboxA?.checked;
        const rOn = !!checkboxR?.checked;
        if (!aOn && !rOn) return;
        const collexItems = Array.from(problem.querySelectorAll(".fm-collection__item"));
        // CheckAll semantics: se .checkboxA(o R) checked ma nessun .fm-checkbox-ain
        // (o .checkboxRin) → CheckAll: tutti gli item del problema.
        const anyAinChecked = collexItems.some(el =>
            !!el.querySelector("input.fm-checkbox-ain")?.checked);
        const anyRinChecked = collexItems.some(el =>
            !!el.querySelector("input.fm-checkbox-rin")?.checked
            || !!el.querySelector("input.fm-checkbox-bin")?.checked);
        const items = [];
        collexItems.forEach(el => {
            const ain = el.querySelector("input.fm-checkbox-ain");
            const rin = el.querySelector("input.fm-checkbox-rin, input.fm-checkbox-bin");
            // Skip se nessun item-checkbox è checked nel problem MA c'è uno per
            // questa version specifica e non è checked.
            const aFilter = anyAinChecked && ain && !ain.checked;
            const rFilter = anyRinChecked && rin && !rin.checked;
            if (aOn && !rOn && aFilter) return;          // pure A: rispetta solo Ain
            if (rOn && !aOn && rFilter) return;          // pure R: rispetta solo Rin
            if (aOn && rOn && aFilter && rFilter) return; // both: skip solo se entrambi non checked
            const ptsInput = el.querySelector("input.fm-input-pt, input.inputPt");
            const solInput = el.querySelector("input.checksol, input.checkgiust");
            const ext = extractItemHtml(el);
            // G27.badge — propaga origin + badge per BadgeRenderer SOL.
            const meta = extractItemBadge(el);
            // G27.dsa — propaga marker F/GF item-level.
            const mark = extractItemMark(el);
            items.push({
                html:            ext.html,
                solution:        ext.sol,
                points:          parseFloat(ptsInput?.value || "1") || 1,
                includeSolution: !!(solInput && solInput.checked),
                origin:          meta.origin,
                badge:           meta.badge,
                mark:            mark,
            });
        });
        if (!items.length) return;
        const posInput = problem.querySelector("input.fm-def-position-imp");
        // G27.vf.fix — ContractRenderer emette type via data-type="type_VF|type_RM|type_Collect".
        // Fallback su pattern legacy id="problem-type_VF-..." per .fm-groupcollex dom-side
        // construct (modelli_eser.php legacy). Senza data-type, tutti i problem
        // collassavano a "Collect" → renderVF mai chiamato → tabella V/F mancante.
        const dataType = problem.dataset.type || "";
        const typeMatch = /type_([A-Za-z]+)/.exec(dataType) || /type_([A-Za-z]+)/.exec(problem.id || "");
        problems.push({
            filePath:  location.pathname,
            problemId: problem.id || `p-${idx}`,
            position:  parseInt(posInput?.value || String(idx + 1), 10) || idx + 1,
            type:      typeMatch ? typeMatch[1] : "Collect",
            // HTML (no textContent) → Sanitizer server converte b/i/u/list a LaTeX
            text:      extractProblemIntroHtml(problem),
            items,
        });
    });
    if (!problems.length) return null;
    // versionList: A se solo A, R se solo R, [A, R] se entrambi.
    const versions = [];
    if (anyAChecked) versions.push("A");
    if (anyRChecked) versions.push("R");
    if (!versions.length) versions.push("A"); // fallback (caso edge: items selezionati senza header)
    // `version` field: ammesso solo "A" o "B" da `Selection::fromArray`.
    // Mappa "R" → "B" (back-compat naming legacy A/B).
    const versionField = versions[0] === "R" ? "B" : versions[0];
    return {
        version:  versionField,         // back-compat single-version flow (G16)
        versions: versions,             // G19.7 — multi-version array per il batch
        ...meta,
        problems,
        options: { includeTitlePage: true, includeSolutions: false },
    };
}

/** G18 — diagnostic helper: spiega all'utente PERCHÉ la selezione e' vuota.
 *  Rileva i tre scenari piu' frequenti:
 *    a) Nessun .fm-groupcollex in pagina            → "Apri la sezione Verifiche correlate"
 *    b) .fm-groupcollex presenti ma nessun .checkboxA spuntato → "Spunta la A nell'header"
 *    c) .checkboxA spuntato ma nessun .fm-checkbox-ain     → "Spunta i quesiti nel checkIN giallo" */
function diagnoseEmptySelection() {
    const problems = document.querySelectorAll(".fm-groupcollex");
    if (!problems.length) {
        return "Apri una verifica o la sezione 'Verifiche correlate' prima di generare.";
    }
    const aChecked = document.querySelectorAll(".fm-groupcollex input.checkboxA:checked").length;
    if (!aChecked) {
        return "Spunta la casella 'A' nell'header del problema (riga gialla in alto).";
    }
    const ainChecked = document.querySelectorAll(".fm-groupcollex input.fm-checkbox-ain:checked").length;
    if (!ainChecked) {
        return "Spunta almeno un quesito (casella 'A' nel riquadro giallo del singolo esercizio).";
    }
    return "Selezione non riconosciuta: ricarica la pagina e riprova.";
}

async function postJson(url, payload) {
    const csrf = await fetchCsrf();
    // wafFetch (non fetch grezzo): su 403 waf_challenge ri-risolve la challenge
    // PoW in modo trasparente e ritenta → niente "Errore: security_check_required"
    // né perdita lavoro nel modal SalvaTEX.
    const r = await wafFetch(url, {
        method: "POST",
        credentials: "same-origin",
        headers: {
            "Content-Type":   "application/json",
            "Accept":         "application/json",
            "X-CSRF-Token":   csrf,
        },
        body: JSON.stringify(payload),
    });
    let body = null;
    try { body = await r.json(); } catch (_) { body = { ok: false, error: `HTTP ${r.status}` }; }
    return { status: r.status, body };
}

/** G9.24 — chiude entrambi i drawers (info + filtri) ripristinando
 *  aria-pressed sui rispettivi bottoni topbar. Idempotente. */
function closeAllDrawers() {
    if (document.body.classList.contains("fm-info-open")) {
        document.body.classList.remove("fm-info-open");
        const btn = document.querySelector('#fm-topbar [data-fm-action="info"]');
        if (btn) syncButtonPressed(btn, false);
    }
    if (document.body.classList.contains("fm-filtri-open")) {
        document.body.classList.remove("fm-filtri-open");
        const btn = document.querySelector('#fm-topbar [data-fm-action="filtri"]');
        if (btn) syncButtonPressed(btn, false);
    }
}

/** Toggle ⓘ Info drawer: rivela #scrollbarInfo come slide-in panel.
 *  Strategia "no DOM move": l'infoVer (#scrollbarInfo / #infoVer) e' gia'
 *  injectato server-side da UIComp._caricaCheckboxABin / verifica-builder.
 *  Toggliamo body.fm-info-open + CSS posiziona come drawer modernizzato.
 *  G9.24 — mutex con filtri drawer: aprire info chiude filtri (e viceversa). */
function toggleInfoDrawer(btn) {
    // Verifica presenza #scrollbarInfo (potrebbe non essere ancora caricato
    // se l'utente non ha mai attivato verifica-mode).
    const sb = document.getElementById("scrollbarInfo");
    if (!sb) {
        ensureToast("info", "Info verifica",
            "Pannello info non ancora caricato. Spunta almeno un esercizio per attivare il builder.");
        return;
    }
    // G9.25 — opportunistic relocate dei totali se #SumPtotA appena caricato
    const topbar = document.getElementById("fm-topbar");
    if (topbar) relocateTotals(topbar);
    const wasOpen = document.body.classList.contains("fm-info-open");
    closeAllDrawers();
    if (!wasOpen) {
        document.body.classList.add("fm-info-open");
        if (btn) syncButtonPressed(btn, true);
        const onOutside = (e) => {
            if (e.target.closest("#scrollbarInfo, .fm-topbar")) return;
            document.body.classList.remove("fm-info-open");
            if (btn) syncButtonPressed(btn, false);
            document.removeEventListener("click", onOutside, true);
        };
        requestAnimationFrame(() => document.addEventListener("click", onOutside, true));
    }
}

/** G9.21 — DOM merge una-tantum: sposta #sel-origin (ORIGINE wrapgrid)
 *  dentro il primo .wrapgrid che contiene #sel-dif (DIFFICOLTÀ) cosi' una
 *  unica section card mostra entrambi i filtri. Idempotente. */
function mergeFiltriDropdowns() {
    const container = document.querySelector(".fm-upbar-controls-container");
    if (!container) return;
    if (container.dataset.fmDropdownsMerged === "1") return;

    const selDif = document.getElementById("sel-dif");
    const selOrigin = document.getElementById("sel-origin");
    if (!selDif || !selOrigin) return;

    const firstWrap = selDif.closest(".fm-wrapgrid");
    const secondWrap = selOrigin.closest(".fm-wrapgrid");
    if (!firstWrap || !secondWrap || firstWrap === secondWrap) return;

    firstWrap.appendChild(selOrigin);
    if (!secondWrap.children.length) secondWrap.remove();

    // G9.22 — rimuovi anche i .wrapgrid lasciati vuoti dal markup legacy
    // (terzo wrapgrid placeholder dopo CheckAll-R).
    container.querySelectorAll(".fm-wrapgrid").forEach(wg => {
        if (!wg.querySelector("*")) wg.remove();
    });

    container.dataset.fmDropdownsMerged = "1";
}

/** Toggle ⚙ filtri drawer: rivela .upbar-controls-container come slide-in.
 *  Strategia: NON sposta il DOM (preserva tutti gli event handler legacy
 *  via script.js / upbar-controls.js); cambia solo body class +
 *  aria-pressed sul btn. CSS gestisce posizionamento come drawer.
 *  G9.21 — eccezione: una sola DOM-merge dei due dropdown DIFFICOLTÀ+ORIGINE
 *  per layout single-card (il merge non rompe gli event handler che usano
 *  document delegation). */
function toggleFiltriDrawer(btn) {
    mergeFiltriDropdowns();
    const wasOpen = document.body.classList.contains("fm-filtri-open");
    closeAllDrawers();
    if (!wasOpen) {
        document.body.classList.add("fm-filtri-open");
        if (btn) syncButtonPressed(btn, true);
        const onOutside = (e) => {
            if (e.target.closest(".fm-upbar-controls-container, .fm-topbar")) return;
            document.body.classList.remove("fm-filtri-open");
            if (btn) syncButtonPressed(btn, false);
            document.removeEventListener("click", onOutside, true);
        };
        requestAnimationFrame(() => document.addEventListener("click", onOutside, true));
    }
}

/** ZIP: salva → scarica .zip
 *  G19.6 — auto-detect single vs batch sulla base di nPrint/nPrintDSA/nPrintDIS
 *  (stesso pattern di `doGenera`). Se almeno uno > 0 OPPURE #DSA checked,
 *  scarica `/api/verifica/batch/{batchId}/zip` (8 file `{slug}-{A|B}-{NOR|SOL|DSA|DIS}.tex`).
 *  Altrimenti scarica single-doc `/api/verifica/{id}/zip` (1 .tex + README).
 *  shift+click sul btn forza single mode (workaround). */
/** G19.44 — Save unificato + handle 409 conflict con confirm overwrite.
 *  Ritorna `{ok, body}` o `{ok:false, aborted}`. */
async function unifiedSaveBatchWithConflict(sel, btnLabel) {
    let { status, body } = await unifiedSaveBatch(sel);
    if (status === 409 && body?.conflict) {
        const overwrite = await fmConfirm(
            `Versione "${body.conflict.version_label || "(senza nome)"}" già presente per "${sel.verTitle}".\n\nSovrascrivere?`,
            "Versione duplicata",
            "warn",
        );
        if (!overwrite) return { ok: false, aborted: true };
        const retry = await postJson(
            "/api/verifica/save-tex-batch?force=1",
            buildSaveTexBatchPayload(sel),
        );
        status = retry.status;
        body   = retry.body;
    }
    if (status !== 200 || !body?.ok) {
        ensureToast("error", btnLabel, `Errore save: ${body?.error || `HTTP ${status}`}`);
        return { ok: false };
    }
    if (!body.docs || !body.docs.length) {
        ensureToast("error", btnLabel, "Batch vuoto: nessuna variante generata.");
        return { ok: false };
    }
    window.dispatchEvent(new CustomEvent("fm:verifica-saved", { detail: body.docs[0] }));
    return { ok: true, body };
}

async function doZip(btn, _ev) {
    const sel = buildSelectionFromDOM();
    if (!sel) {
        ensureToast("error", "ZIP", diagnoseEmptySelection());
        return;
    }
    if (!sel.verTitle || sel.verTitle === "Verifica") {
        const t = await fmPrompt("Inserisci il titolo della verifica:", sel.verTitle || "", "Titolo verifica");
        if (!t) return;
        sel.verTitle = t.trim();
    }

    btn.disabled = true;
    const lbl = btn.querySelector(".fm-topbar__lbl");
    const oldLbl = lbl?.textContent;
    if (lbl) lbl.textContent = "ZIP…";
    try {
        // G19.44 — ZIP ora SEMPRE batch (4-8 varianti). Single mode rimosso.
        const r = await unifiedSaveBatchWithConflict(sel, "ZIP");
        if (!r.ok) return;
        const body = r.body;
        const a = document.createElement("a");
        a.href = body.zip_url;
        a.download = `batch_${body.batch_id}.zip`;
        a.style.display = "none";
        document.body.appendChild(a);
        a.click();
        a.remove();
        ensureToast("success", "ZIP",
            `${body.docs.length} varianti scaricate (${body.docs.map(d => d.variant).join(", ")}).`);
    } catch (e) {
        ensureToast("error", "ZIP", `Errore di rete: ${e.message}`);
    } finally {
        btn.disabled = false;
        if (lbl && oldLbl) lbl.textContent = oldLbl;
    }
}

/** Legge i conteggi copie da #nPrint/#nPrintDSA/#nPrintDIS InfoVer
 *  per il batch mode (8 varianti A/B × {SOL,NOR,DSA,DIS}). */
function readPrintCounts() {
    const v = (id) => parseInt(document.getElementById(id)?.value || "0", 10) || 0;
    return {
        nPrint:    v("nPrint"),
        nPrintDSA: v("nPrintDSA"),
        nPrintDIS: v("nPrintDIS"),
    };
}

/** GENERA: salva (saveTex o saveTexBatch) → apre modal scelta target.
 *  Decide single vs batch in base ai conteggi copie:
 *    - nPrint o nPrintDSA o nPrintDIS > 0 → batch mode (multi-variante)
 *    - tutti zero → single mode (1 TEX)
 *  shift+click sul btn forza single mode (workaround se l'utente vuole
 *  comunque la singola). */
async function doGenera(btn, ev) {
    const sel = buildSelectionFromDOM();
    if (!sel) {
        ensureToast("error", "GENERA", diagnoseEmptySelection());
        return;
    }
    if (!sel.verTitle || sel.verTitle === "Verifica") {
        const t = await fmPrompt("Inserisci il titolo della verifica:", sel.verTitle || "", "Titolo verifica");
        if (!t) return;
        sel.verTitle = t.trim();
    }
    const flags  = readVerificaFlags();
    const counts = readPrintCounts();
    const totalCopies = counts.nPrint + counts.nPrintDSA + counts.nPrintDIS;
    const forceSingle = ev?.shiftKey === true;
    const useBatch = !forceSingle && totalCopies > 0;
    const endpoint = useBatch ? "/api/verifica/save-tex-batch" : "/api/verifica/save-tex";

    btn.disabled = true;
    const lbl = btn.querySelector(".fm-topbar__lbl");
    const oldLbl = lbl?.textContent;
    if (lbl) lbl.textContent = useBatch ? "Batch…" : "Salvataggio…";
    try {
        const { status, body } = await postJson(endpoint, {
            ...sel,
            title: sel.verTitle,
            materia: sel.selectedMATER,
            ...flags,
            ...counts,
        });
        if (status !== 200 || !body.ok) {
            ensureToast("error", "GENERA", `Errore: ${body?.error || `HTTP ${status}`}`);
            return;
        }
        if (useBatch) {
            // batch response: {ok, batch_id, docs:[], zip_url}
            ensureToast("success", "Batch",
                `${body.docs.length} varianti generate (${body.docs.map(d => d.variant).join(", ")}).`);
            window.dispatchEvent(new CustomEvent("fm:verifica-saved", { detail: body.docs[0] }));
            if (typeof window.FM?.openVerificaGeneraModal === "function") {
                window.FM.openVerificaGeneraModal(body.docs[0], {
                    batch_id: body.batch_id,
                    docs:     body.docs,
                    zip_url:  body.zip_url,
                });
            }
        } else {
            window.dispatchEvent(new CustomEvent("fm:verifica-saved", { detail: body.doc }));
            if (typeof window.FM?.openVerificaGeneraModal === "function") {
                window.FM.openVerificaGeneraModal(body.doc);
            } else {
                ensureToast("success", "GENERA", `Verifica salvata (id ${body.doc.id}).`);
            }
        }
    } catch (e) {
        ensureToast("error", "GENERA", `Errore di rete: ${e.message}`);
    } finally {
        btn.disabled = false;
        if (lbl && oldLbl) lbl.textContent = oldLbl;
    }
}

/** G19.44 — Helper: legge `#versione` input come version_label da inviare
 *  al backend (per future dedup conflict logic + display in modal). */
function readVersionLabel() {
    return (document.getElementById("versione")?.value || "").trim();
}

/** G19.44 — Save unificato con saveBatch per tutti i 4 button (SalvaTEX,
 *  VSC, ZIP, Overleaf). Genera 4 varianti SOL/NOR/DSA/DIS [+ B se R].
 *  Ritorna `{ok, batch_id, docs}` o `{ok:false, error}`. */
function buildSaveTexBatchPayload(sel) {
    const flags = readVerificaFlags();
    const counts = readPrintCounts();
    const counts2 = {
        nPrint:    counts.nPrint    || 1,
        nPrintDSA: counts.nPrintDSA || 0,
        nPrintDIS: counts.nPrintDIS || 0,
    };
    return {
        ...sel,
        title: sel.verTitle,
        materia: sel.selectedMATER,
        version_label: readVersionLabel(),
        ...flags,
        ...counts2,
    };
}

async function unifiedSaveBatch(sel) {
    return postJson("/api/verifica/save-tex-batch", buildSaveTexBatchPayload(sel));
}

async function doSalvaTex(btn) {
    const sel = buildSelectionFromDOM();
    if (!sel) {
        ensureToast("error", "SalvaTEX", diagnoseEmptySelection());
        return;
    }
    if (!sel.verTitle || sel.verTitle === "Verifica") {
        const t = await fmPrompt("Inserisci il titolo della verifica:", sel.verTitle || "", "Titolo verifica");
        if (!t) return;
        sel.verTitle = t.trim();
    }
    btn.disabled = true;
    btn.dataset.savingState = "1";
    const oldLabel = btn.querySelector(".fm-topbar__lbl")?.textContent;
    const lbl = btn.querySelector(".fm-topbar__lbl");
    if (lbl) lbl.textContent = "Salvataggio…";
    try {
        // G19.44 — SalvaTEX ora SAVE-BATCH (4-8 varianti) come gli altri.
        // G21 — dopo save, server-side compile PDF per ogni variante.
        const { status, body } = await unifiedSaveBatch(sel);
        if (status === 200 && body.ok && body.docs?.length) {
            ensureToast("success", "SalvaTEX",
                `${body.docs.length} varianti salvate per "${sel.verTitle}".`);
            window.dispatchEvent(new CustomEvent("fm:verifica-saved", { detail: body.docs[0] }));
            // Apri il modal CodeMirror SUBITO con i docs appena salvati: il TEX
            // e' gia' disponibile, l'utente puo' iniziare a editare. Il compile
            // PDF gira in background e l'editor si aggiorna live al pronto.
            if (typeof window.FM?.openVerificaPreview === "function") {
                window.FM.openVerificaPreview(body.docs).catch(e =>
                    console.warn("[topbar-modern] openVerificaPreview:", e));
            }
            // Compile PDF in background (non await): polling jobs non blocca UI
            compilePdfForDocs(body.docs, lbl).catch(e =>
                console.warn("[topbar-modern] compilePdfForDocs:", e));
        } else if (status === 409 && body?.conflict) {
            // G19.44 — backend ritorna conflict su (title+version_label+variant) duplicato
            const overwrite = await fmConfirm(
                `Versione "${body.conflict.version_label || "(senza nome)"}" già presente per "${sel.verTitle}".\n\nSovrascrivere?`,
                "Versione duplicata",
                "warn",
            );
            if (overwrite) {
                // G21.1 fix — usa stesso payload builder del primo invio
                // (incluso il fallback nPrint || 1) per evitare drop varianti.
                const retry = await postJson(
                    "/api/verifica/save-tex-batch?force=1",
                    buildSaveTexBatchPayload(sel),
                );
                if (retry.status === 200 && retry.body.ok) {
                    ensureToast("success", "SalvaTEX", `Sovrascritto.`, 2500);
                    window.dispatchEvent(new CustomEvent("fm:verifica-saved", { detail: retry.body.docs[0] }));
                    if (typeof window.FM?.openVerificaPreview === "function") {
                        window.FM.openVerificaPreview(retry.body.docs).catch(e =>
                            console.warn("[topbar-modern] openVerificaPreview:", e));
                    }
                    compilePdfForDocs(retry.body.docs, lbl).catch(e =>
                        console.warn("[topbar-modern] compilePdfForDocs:", e));
                } else {
                    ensureToast("error", "SalvaTEX", retry.body?.error || `HTTP ${retry.status}`);
                }
            }
        } else {
            const err = body?.error || `HTTP ${status}`;
            ensureToast("error", "SalvaTEX", `Errore: ${err}`);
        }
    } catch (e) {
        ensureToast("error", "SalvaTEX", `Errore di rete: ${e.message}`);
    } finally {
        btn.disabled = false;
        btn.dataset.savingState = "";
        if (lbl && oldLabel) lbl.textContent = oldLabel;
    }
}

/**
 * G21 — Compile PDF server-side per N documenti in parallelo (max 4 concorrenti).
 *
 * Per ogni doc salvato: POST /api/verifica/{id}/compile.
 * Il backend legge il .tex, lo invia al VPS tex-compile-vps, salva il PDF.
 *
 * Aggiorna live il label del bottone con progresso (M/N).
 * A fine: toast con esito (success / partial / fail).
 *
 * Errori non-bloccanti: il TEX è già salvato, quindi anche se il PDF fallisce
 * l'utente non perde lavoro. Può ricompilare manualmente più tardi.
 *
 * @param {Array<{id:number,variant?:string}>} docs
 * @param {HTMLElement|null} lbl  reference al .fm-topbar__lbl per progress
 */
/**
 * G22.S5 — Compile path: async via job queue.
 *
 * Per ogni doc chiama POST /api/verifica/{id}/compile-async che:
 *   - Cache hit S2 (sync, instant) → ritorna 200 con compile.cache_hit=true
 *   - Cache miss → enqueue job, ritorna 202 con {async:true, job_id}
 *
 * Sui job async, polling GET /api/verifica/jobs/{id} con backoff
 * 1s/2s/5s/10s fino a status='done' o 'failed'.
 *
 * Vantaggio rispetto al compile sincrono pre-S5: la richiesta utente non
 * resta bloccata per i 2-5s/variante (8 varianti = 16-40s) ma ritorna in
 * ~10ms (enqueue). UI mostra progress live via polling.
 */
async function compilePdfForDocs(docs, lbl) {
    if (!Array.isArray(docs) || docs.length === 0) return;

    const total = docs.length;
    let done = 0, ok = 0, fail = 0;
    const failures = [];
    // G27.tikz.warn — accumula warning critici (es. macro undefined) dai
    // compile riusciti per surfacarli come toast separato. Evita che l'utente
    // riceva un "PDF generato" success quando la figura non e' renderizzata.
    const compileWarnings = new Map();  // key: warning.message, val: count

    const updateProgress = () => {
        if (lbl) lbl.textContent = `PDF ${done}/${total}`;
    };
    updateProgress();

    /** Polling singolo job: ritorna {ok, error?} a status terminale. */
    async function pollJob(jobId, variant) {
        const backoffs = [1000, 2000, 5000, 10000]; // ms
        let attempt = 0;
        const deadline = Date.now() + 180_000; // 3 min hard timeout
        while (Date.now() < deadline) {
            await new Promise(r => setTimeout(r, backoffs[Math.min(attempt, backoffs.length - 1)]));
            attempt++;
            try {
                const r = await fetch(`/api/verifica/jobs/${jobId}`, { credentials: "same-origin" });
                if (!r.ok) {
                    return { ok: false, error: `poll HTTP ${r.status}` };
                }
                const body = await r.json();
                if (!body.ok) {
                    return { ok: false, error: body.error || "poll_failed" };
                }
                const status = body.job?.status;
                if (status === "done")   return { ok: true };
                if (status === "failed") return { ok: false, error: body.job?.last_error || "compile_failed" };
                // running/pending/retry → continua polling
            } catch (e) {
                return { ok: false, error: `poll network: ${e.message}` };
            }
        }
        return { ok: false, error: "poll_timeout_180s" };
    }

    // Pool: max 4 enqueue concorrenti (le compile vere girano sul worker
    // cron, qui acceleriamo solo l'invio iniziale + polling).
    const POOL = 4;
    let cursor = 0;
    const worker = async () => {
        while (cursor < docs.length) {
            const doc = docs[cursor++];
            try {
                const r = await postJson(`/api/verifica/${doc.id}/compile-async`, {});
                // Backend states (G22.S7 trigger-on-request):
                //   200 + compile.cache_hit       → S2 cache hit instant
                //   200 + compile.inline=true     → enqueued + processed inline (normale)
                //   202 + job_id                  → enqueued, inline failed/skipped → poll
                if (r.status === 200 && r.body?.ok) {
                    ok++;
                    // G27.tikz.warn — surface non-fatal compile warnings
                    if (Array.isArray(r.body?.warnings)) {
                        for (const w of r.body.warnings) {
                            if (!w?.message) continue;
                            compileWarnings.set(w.message, (compileWarnings.get(w.message) || 0) + 1);
                        }
                    }
                    continue;   // PDF già attached: cache hit o inline OK
                }
                if (r.status === 202 && r.body?.ok && r.body?.job_id) {
                    const poll = await pollJob(r.body.job_id, doc.variant);
                    if (poll.ok) ok++;
                    else { fail++; failures.push({ id: doc.id, variant: doc.variant || "?", error: poll.error, log: "" }); }
                    continue;
                }
                fail++;
                failures.push({
                    id: doc.id, variant: doc.variant || "?",
                    error: r.body?.error || `HTTP ${r.status}`,
                    log: r.body?.log_excerpt || "",
                });
            } catch (e) {
                fail++;
                failures.push({
                    id: doc.id, variant: doc.variant || "?",
                    error: `network: ${e.message}`, log: "",
                });
            } finally {
                done++; updateProgress();
            }
        }
    };
    await Promise.all(Array.from({ length: Math.min(POOL, total) }, worker));

    // G27.tikz.warn — toast warning per macro undefined ecc. (PDF compila ma
    // figure non disegnate). Mostrato in aggiunta al success normale.
    if (compileWarnings.size > 0) {
        const summary = [...compileWarnings.entries()]
            .slice(0, 3)
            .map(([msg, n]) => n > 1 ? `${msg} (${n}x)` : msg)
            .join(' | ');
        ensureToast("warning", "PDF compilato con avvisi",
            `Il PDF e' stato generato ma il log compile segnala: ${summary}. Le figure TikZ relative potrebbero non essere visibili.`,
            10000);
    }

    if (fail === 0) {
        ensureToast("success", "PDF",
            `${ok}/${total} PDF compilati e salvati.`,
            2500);
    } else if (ok > 0) {
        const firstErr = failures[0];
        ensureToast("warning", "PDF",
            `${ok}/${total} PDF OK, ${fail} falliti. Primo errore (${firstErr.variant}): ${firstErr.error}. ${firstErr.log ? firstErr.log.slice(0, 100) : ""}`,
            8000);
    } else {
        const firstErr = failures[0];
        ensureToast("error", "PDF",
            `Compilazione PDF fallita per tutte le ${total} varianti. Errore: ${firstErr.error}. ${firstErr.log ? firstErr.log.slice(0, 100) : ""}`,
            10000);
    }

    // Notifica modal preview che i PDF sono pronti (se gia' aperto puo'
    // refresh-are i pannelli PDF preview). Best-effort, no-op se assente.
    window.dispatchEvent(new CustomEvent("fm:verifica-pdf-batch", {
        detail: { docs, ok, fail, total },
    }));
}

/** G19.22 — VSC button: salva batch → scarica ZIP → tenta vscode://file
 *  sulla cartella radice configurata + sub-path mirror Drive structure
 *  (`{vscRoot}/{materia}/{cls}_{sezione}_{ind}/{title_slug}/`).
 *  shift+click = single-mode (1 TEX) invece di batch.
 *
 *  La radice e' configurata via `doVscSettings` (button ⚙). Storage:
 *  `localStorage["fm.vscode.user_dir"]` (riusa la chiave gia' usata da
 *  verifica-vscode-launch.js, no fork). */
const VSC_ROOT_KEY = "fm.vscode.user_dir";

function getVscRoot() {
    return (localStorage.getItem(VSC_ROOT_KEY) || "").trim();
}

function setVscRoot(v) {
    if (!v || /USERNAME/i.test(v)) {
        localStorage.removeItem(VSC_ROOT_KEY);
    } else {
        localStorage.setItem(VSC_ROOT_KEY, v.trim());
    }
}

function slugForVsc(s) {
    return String(s ?? "")
        .toLowerCase()
        .normalize("NFKD")
        .replace(/[̀-ͯ]/g, "")
        .replace(/[^a-z0-9]+/g, "_")
        .replace(/^_+|_+$/g, "")
        .slice(0, 40) || "verifica";
}

function buildVscPath(root, sel, title) {
    if (!root) return "";
    const norm = root.replace(/[/\\]+$/, "").replace(/\\/g, "/");
    const sub = buildSubPath(sel, title);
    return `${norm}/${sub}`;
}

async function doVscSettings(btn, _ev) {
    const fs = window.FM?.FsAccess;
    const fsSupported = fs?.isSupported?.();

    if (fsSupported) {
        // G19.36 — FS Access API: pick + pair con absolute path.
        const cur = getVscRoot();
        const wantPair = await fmConfirm(
            (cur ? `Radice attuale:\n${cur}\n\n` : "")
          + "Scegli la cartella in cui VSC scriverà direttamente i file (senza dover estrarre ZIP). "
          + "Premi OK per aprire il directory picker, poi inserirai anche il percorso assoluto host.\n\n"
          + "ATTENZIONE: Chrome blocca le cartelle di sistema (C:\\, Windows, Program Files, Users root, Desktop). "
          + "Crea PRIMA una cartella dedicata (es. Documents\\pantedu-vsc) e poi sceglila.",
            "Configurazione cartella VSC",
            "info",
        );
        if (!wantPair) return;
        try {
            const handle = await fs.pickRoot();
            const absPath = await fmPrompt(
                `Hai scelto la cartella "${handle.name}".\n\n`
              + "Inserisci ora il PERCORSO ASSOLUTO host della stessa cartella "
              + "(necessario per costruire vscode://file/{path} — il browser "
              + "non può ricavarlo dall'handle per sicurezza).\n\n"
              + `Es: C:/Users/mario/Documents/${handle.name}`,
                cur || `C:/Users/<utente>/${handle.name}`,
                "Percorso assoluto cartella",
            );
            if (!absPath || /USERNAME|<utente>/i.test(absPath)) {
                ensureToast("info", "VSC", "Path assoluto non fornito: pairing salvato solo come handle (fallback ZIP attivo).");
                return;
            }
            setVscRoot(absPath.trim());
            ensureToast("success", "VSC",
                `Cartella "${handle.name}" pairata: i prossimi VSC scriveranno direttamente li.`,
                5000);
        } catch (e) {
            if (e.name === "AbortError") return; // utente ha annullato
            ensureToast("error", "VSC", `Errore picker: ${e.message}`);
        }
        return;
    }

    // Fallback: solo absolute path (per browser senza FS Access API)
    const cur = getVscRoot();
    const placeholder = cur || "C:/Users/<utente>/pantedu-vsc";
    const next = await fmPrompt(
        "Cartella radice VSC (path assoluto host).\n\n"
      + "Lo ZIP scaricato dovrà essere estratto qui per replicare la struttura Drive "
      + "({materia}/{cls_sez_ind}/{title}/...).\n\n"
      + "Es: C:/Users/mario/pantedu-vsc\n\n"
      + "Tip: usa Chrome/Edge per il directory picker che evita lo ZIP.",
        placeholder,
        "Cartella radice VSC",
    );
    if (next === null) return;
    setVscRoot(next);
    if (next.trim() && !/USERNAME/i.test(next)) {
        ensureToast("success", "VSC", `Radice impostata: ${next.trim()}`);
    } else {
        ensureToast("info", "VSC", "Radice rimossa: VSC chiedera' di nuovo al prossimo click.");
    }
}

const VSC_KIND_ORDER = ["SOL", "NOR", "DSA", "DIS"];

function sortVscKinds(kinds) {
    const set = new Set((kinds || []).map(k => String(k).toUpperCase()));
    return VSC_KIND_ORDER.filter(k => set.has(k));
}

function todayDateStr() {
    const d = new Date();
    const dd = String(d.getDate()).padStart(2, "0");
    const mm = String(d.getMonth() + 1).padStart(2, "0");
    return `${dd}_${mm}_${d.getFullYear()}`;
}

function getInstituteCode() {
    // G20.0 — priorità AppState.activeInstituteCode (dalla sidebar dropdown),
    // fallback sessionStorage legacy (instituteCode da verifica-scelte.js)
    return (
        window.FM?.AppState?.activeInstituteCode
        || sessionStorage.getItem("activeInstituteCode")
        || sessionStorage.getItem("instituteCode")
        || ""
    ).trim();
}

/** G19.48 — Sub-path mirror Drive sync mappe structure:
 *  `{istituto}/{indirizzo}/{classe}/{materia}/verifiche/{titolo_slug}/{version_folder}/`
 *  - `istituto`  = ministerial code (es. `XXPS00000A`) se in sessionStorage,
 *                  altrimenti slug del nome.
 *  - `indirizzo` = sel.selectedIIS (`ar`, `sc`, ...).
 *  - `classe`    = sel.selectedCLS (`1s`, `2s`, ...). Niente `${sezione}` qui:
 *                  la sezione (NOR/SOL/DSA/DIS) e' ortogonale alla classe e
 *                  finisce nel nome della cartella `version_folder`.
 *  - `materia`   = sel.selectedMATER UPPERCASE (mirror Drive).
 *  - `version_folder` = `{version_label}-{DD_MM_YYYY}-{KINDS}` ordinato
 *                       SOL_NOR_DSA_DIS (kinds presenti nel batch).
 *                       es. `v1-02_06_2026-SOL_NOR_DIS`.
 */
function buildSubPath(sel, title, opts = {}) {
    const code = getInstituteCode();
    const istNameRaw = document.getElementById("istituto")?.value || sel.istituto || "";
    const istituto = code || slugForVsc(istNameRaw) || "istituto";
    const ind     = (sel.selectedIIS   || window.FM?.Curriculum?.firstCode("indirizzi") || "").toLowerCase();
    const cls     = (sel.selectedCLS   || window.FM?.Curriculum?.firstCode("classi")    || "").toLowerCase();
    const materia = (sel.selectedMATER || window.FM?.Curriculum?.firstCode("materie")   || "").toUpperCase();
    const titleSlug = slugForVsc(title);

    let versionFolder = "";
    if (opts.versionLabel || opts.kinds || opts.dateStr) {
        const lbl  = opts.versionLabel || "v0";
        const dt   = opts.dateStr || todayDateStr();
        const ord  = sortVscKinds(opts.kinds || []);
        const kindsStr = ord.length ? ord.join("_") : "ALL";
        versionFolder = `${lbl}-${dt}-${kindsStr}`;
    }
    const base = `${istituto}/${ind}/${cls}/${materia}/verifiche/${titleSlug}`;
    return versionFolder ? `${base}/${versionFolder}` : base;
}

/** G20.0 — Scrive bundle multi-file (texCommon + griglie + main + problemi)
 *  in layout distribuito sotto la cartella istituto. Path delle response
 *  sono gia' relativi al institute root (es. `texCommon/...`,
 *  `sc/griglie/...`, `sc/3/MAT/verifiche/title/v0-DATE-KINDS/main_NOR.tex`).
 *  Il client pre-pende `{institute_code}/` come folder root sul fs locale. */
async function writeBatchToFolder(batchId, sel, title) {
    const fs = window.FM?.FsAccess;
    if (!fs?.isSupported?.()) {
        throw new Error("Browser non supporta File System Access API. Usa Chrome o Edge desktop.");
    }
    let root = await fs.getRoot();
    if (!root) {
        ensureToast("info", "VSC",
            "Scegli una cartella dedicata (NO system folders: Chrome blocca C:\\, Windows, Users root, Desktop). Tip: Documents\\pantedu-vsc",
            6000);
        try {
            root = await fs.pickRoot();
        } catch (e) {
            if (e.name === "AbortError") throw new Error("Cartella non scelta. VSC annullato.");
            throw e;
        }
    }
    const ok = await fs.getOrRequestPermission(root, "readwrite");
    if (!ok) {
        throw new Error("Permesso scrittura negato sulla cartella.");
    }
    const j = await fetchJson(`/api/verifica/batch/${batchId}/files`);
    if (!j.ok || !Array.isArray(j.files)) {
        throw new Error(`Fetch files fallito: ${j.error || "richiesta non riuscita"}`);
    }
    const instCode = j.institute_code || "default";
    let mainPath = "";
    for (const f of j.files) {
        // I path sono relativi al institute root, prepend institute_code
        const dst = `${instCode}/${f.path}`;
        await fs.writeFile(root, dst, f.content);
        if (f.type === "main" && /main_NOR\.tex$/.test(f.path)) {
            mainPath = dst.replace(/\/main_NOR\.tex$/, "");
        }
    }
    return {
        fileCount:    j.files.length,
        sub:          mainPath,    // path version folder per VSCode openFolder
        rootName:     root.name,
        instituteCode: instCode,
    };
}

async function doVsc(btn, ev) {
    const sel = buildSelectionFromDOM();
    if (!sel) {
        ensureToast("error", "VSC", diagnoseEmptySelection());
        return;
    }
    if (!sel.verTitle || sel.verTitle === "Verifica") {
        const t = await fmPrompt("Inserisci il titolo della verifica:", sel.verTitle || "", "Titolo verifica");
        if (!t) return;
        sel.verTitle = t.trim();
    }
    let root = getVscRoot();
    if (!root) {
        const proposed = await fmPrompt(
            "Prima volta: indica la cartella radice VSC (path assoluto).\n"
          + "I file verranno aperti in VSCode da lì.\n\n"
          + "Tip: usa ⚙ per il directory picker (Chrome/Edge) → niente ZIP.\n\n"
          + "Es: C:/Users/mario/pantedu-vsc",
            "C:/Users/<utente>/pantedu-vsc",
            "Cartella radice VSC (prima volta)",
        );
        if (!proposed || /USERNAME|<utente>/i.test(proposed)) {
            ensureToast("info", "VSC", "Configura la radice VSC da ⚙ poi riprova.");
            return;
        }
        setVscRoot(proposed);
        root = proposed.trim();
    }

    btn.disabled = true;
    const lbl = btn.querySelector(".fm-topbar__lbl");
    const oldLbl = lbl?.textContent;
    if (lbl) lbl.textContent = "VSC…";
    try {
        // G19.44 — usa il save unificato con conflict handling
        const r = await unifiedSaveBatchWithConflict(sel, "VSC");
        if (!r.ok) return;
        const body = r.body;

        // G19.38 — VSC = SOLO File System Access API (no ZIP fallback).
        // Se manca pairing/permesso/support → toast errore, niente download.
        let fsResult;
        try {
            fsResult = await writeBatchToFolder(body.batch_id, sel, sel.verTitle);
        } catch (e) {
            ensureToast("error", "VSC",
                `${e.message}\nApri ⚙ per configurare la cartella oppure usa Chrome/Edge desktop.`,
                8000);
            return;
        }

        // G19.46 — Riusa il sub-path full (con version_folder) calcolato in
        // writeBatchToFolder cosi' VSCode apre la cartella della versione
        // appena scritta, non il base.
        const subPath = fsResult.sub;
        const fullPath = `${root.replace(/\\/g, "/").replace(/\/+$/, "")}/${subPath}`;
        // G19.42 — `vscode://vscode.openFolder?...` veniva interpretato
        // come EXTENSION URL (pubisher.extension format) e VSCode tentava
        // di installare un'extension inesistente "vscode.openfolder".
        // Format corretto: `vscode://file/{path}?windowId=_blank`. Il
        // query `windowId=_blank` dice a VSCode di aprire NUOVA finestra
        // (la corrente resta intatta).
        const url = `vscode://file/${fullPath.replace(/^\/+/, "")}?windowId=_blank`;
        console.log("[VSC] opening:", url);
        setTimeout(() => {
            try {
                window.location.href = url;
                ensureToast("success", "VSC",
                    `${fsResult.fileCount} file scritti in "${fsResult.rootName}/${subPath}". VSCode aperto in nuova finestra.`,
                    8000);
            } catch (e) {
                ensureToast("error", "VSC", `Errore apertura VSCode: ${e.message}`);
            }
        }, 400);
    } catch (e) {
        ensureToast("error", "VSC", `Errore di rete: ${e.message}`);
    } finally {
        btn.disabled = false;
        if (lbl && oldLbl) lbl.textContent = oldLbl;
    }
}

/** G19.22 — relocateSelectorEser: sposta `.selector-eser` dal `#wrapInfoVer`
 *  legacy nel topbar slot `[data-fm-eser-slot]`. Idempotente.
 *
 *  Defensivo contro duplicati: `_caricaCheckboxABin` (ui-comp.js) clona
 *  il template Elementi_Riservati.html ad ogni attivazione, creando una
 *  nuova `.selector-eser`. Se ne troviamo piu' di una, manteniamo solo
 *  l'ultima (la piu' recente, con event handlers freschi) e la sposta
 *  nel slot. Le copie precedenti vengono rimosse per evitare duplicate
 *  ID (`#fm-create-exercise-btn`, `#savePrintInfoBtn`, `#loadPrintInfoBtn`). */
function relocateSelectorEser(topbar) {
    const slot = topbar.querySelector("[data-fm-eser-slot]");
    if (!slot) return;
    const all = document.querySelectorAll(".fm-selector-eser");
    if (!all.length) return;
    const fresh = all[all.length - 1];
    // Rimuovi i precedenti (non l'ultimo) per evitare duplicate id nel DOM.
    all.forEach(s => { if (s !== fresh) s.remove(); });
    if (!slot.contains(fresh)) {
        slot.appendChild(fresh);
    }
    // G19.22+fix-flicker — marca elemento come mountato per disabilitare
    // CSS `visibility:hidden` di `.fm-selector-eser:not(.fm-mounted)`.
    fresh.classList.add("fm-mounted");
}

/** G20.7 — relocateVerTitle: ora ha 2 obiettivi distinti.
 *
 *  1) zone PrintInfo (.fm-printinfo-actions) → ordine:
 *       [💾 save | 📂 load | ⓘ Info]
 *     Info viene RICREATO via JS se mancante (ui-comp re-clona il template
 *     .selector-eser e perderemmo il bottone se lo spostassimo da una
 *     sorgente esterna).
 *
 *  2) zone Scelte (.scelte-verifica-wrapper) → ordine:
 *       [💾 salva | 📂 carica | #versione | v1v2v3]
 *     (verTitlePrefix + verTitle NON sono piu' qui dopo G20.7-update:
 *      sono spostati nella header `.fm-titolo.fm-related-header` da
 *      relocateVerTitleHeader() observer).
 *     #versione viene SPOSTATO dal legacy #wrapInfoSchool per
 *     preservare lo stato user. ui-comp.js rescue-lo-riporta in
 *     #wrapInfoSchool prima del .remove() della .selector-eser. */
function relocateVerTitle(topbar) {
    const eserZone = topbar.querySelector("[data-fm-eser-slot]");
    if (!eserZone) return;

    // Zone 2: Info button dentro .fm-printinfo-actions, dopo loadPrintInfoBtn.
    const actions = eserZone.querySelector(".fm-printinfo-actions");
    const loadBtn = actions?.querySelector("#loadPrintInfoBtn");
    if (actions && loadBtn) {
        let infoBtn = actions.querySelector('[data-fm-action="info"]');
        if (!infoBtn) {
            infoBtn = document.createElement("button");
            infoBtn.type = "button";
            infoBtn.className = "fm-topbar__btn fm-topbar__btn--icon fm-topbar__btn--in-eser";
            infoBtn.dataset.fmAction = "info";
            infoBtn.title = "Info verifica (anno, classe, sezione, versione, num.copie, studente, opzioni)";
            infoBtn.setAttribute("aria-label", "Info verifica");
            infoBtn.innerHTML = '<span class="fm-topbar__ico" aria-hidden="true">ⓘ</span><span class="fm-topbar__lbl">Info</span>';
            loadBtn.after(infoBtn);
        } else if (infoBtn.previousElementSibling !== loadBtn) {
            loadBtn.after(infoBtn);
        }
    }

    // Zone 3: #versione prima di .scelte-versioni in .scelte-verifica-wrapper.
    // verTitle/Prefix NON piu' qui — ora vivono in .fm-related-header
    // (vedi relocateVerTitleHeader observer sotto).
    const scelte = eserZone.querySelector(".fm-scelte-verifica-wrapper");
    const versioni = scelte?.querySelector(".fm-scelte-versioni");
    if (scelte && versioni) {
        const versione = document.getElementById("versione");
        if (versione && (versione.parentNode !== scelte || versione.nextElementSibling !== versioni)) {
            versioni.before(versione);
        }
    }

    // PDF-Import launcher dentro .scelte-verifica-wrapper. RICREATO via JS (come
    // il bottone Info qui sopra): ui-comp.js re-clona il template .selector-eser
    // ad ogni attivazione, quindi un bottone presente solo nell'HTML andrebbe
    // perso/non aggiornato. Idempotente: append una sola istanza.
    if (scelte) {
        let pdfBtn = eserZone.querySelector('[data-fm-action="pdf-import"]');
        if (!pdfBtn) {
            pdfBtn = document.createElement("button");
            pdfBtn.type = "button";
            pdfBtn.className = "fm-pdfimport-launch";
            pdfBtn.dataset.fmAction = "pdf-import";
            pdfBtn.title = "Importa esercizi da un PDF (estrazione LLM)";
            pdfBtn.setAttribute("aria-label", "Importa da PDF");
            pdfBtn.textContent = "📄";
            scelte.appendChild(pdfBtn);
        } else if (pdfBtn.parentNode !== scelte) {
            scelte.appendChild(pdfBtn);
        }
    }
}

/** G20.7-update — relocateVerTitleHeader: sposta #verTitlePrefix + #verTitle
 *  dentro `.fm-titolo.fm-related-header` (la sezione "Verifiche correlate"
 *  creata dinamicamente da verifica-builder loadRelatedVerifica). Il
 *  header puo' non esistere al primo activate(): un MutationObserver lo
 *  attende e fa il move appena disponibile. Idempotente. */
function relocateVerTitleHeader(header) {
    if (!header) return false;
    const prefix = document.getElementById("verTitlePrefix");
    const input  = document.getElementById("verTitle");
    let moved = false;
    if (prefix && prefix.parentNode !== header) { header.appendChild(prefix); moved = true; }
    if (input  && input.parentNode  !== header) { header.appendChild(input);  moved = true; }
    return moved;
}
let _verTitleHeaderObserver = null;
function ensureVerTitleHeaderObserver() {
    if (_verTitleHeaderObserver) return;
    // Tentativo immediato se gia' montato
    const existing = document.querySelector(".fm-titolo.fm-related-header");
    if (existing) relocateVerTitleHeader(existing);
    _verTitleHeaderObserver = new MutationObserver(() => {
        const h = document.querySelector(".fm-titolo.fm-related-header");
        if (h) relocateVerTitleHeader(h);
    });
    _verTitleHeaderObserver.observe(document.body, { childList: true, subtree: true });
}

function handleAction(action, btn, ev) {
    switch (action) {
        case "salvatex":
            doSalvaTex(btn);
            break;
        case "overleaf": {
            // Toggle del bridge #overleaf — letto da utilities.js per
            // save/restore preference in print_info.json (G22.S6).
            const next = !getBridgeChecked("overleaf");
            setBridgeChecked("overleaf", next);
            // Mutex con #Server: Overleaf attivo → #Server off (semantica legacy).
            if (next) setBridgeChecked("Server", false);
            syncButtonPressed(btn, next);
            ensureToast("info", "Overleaf", next ? "Apertura Overleaf attiva" : "Overleaf disattivato");
            break;
        }
        case "zip":
            doZip(btn, ev);
            break;
        case "vsc":
            doVsc(btn, ev);
            break;
        case "vsc-settings":
            doVscSettings(btn, ev);
            break;
        // G19.22 — back-compat: alcuni test E2E usano ancora
        // `[data-fm-action="genera"]` come locator. Non c'e' piu' un button
        // server-side con quell'action, ma se un caller lo dispatcha (test
        // che fa attach button via evaluate, oppure listener legacy) lo
        // mappiamo a doGenera (modal Overleaf/Server/Locale).
        case "genera":
            doGenera(btn, ev);
            break;
        case "info":
            toggleInfoDrawer(btn);
            break;
        case "filtri":
            toggleFiltriDrawer(btn);
            break;
        case "editor":
            if (typeof window.FM?.openVerificaTemplatesModal === "function") {
                window.FM.openVerificaTemplatesModal();
            } else {
                ensureToast("error", "Editor", "Modulo template non caricato.");
            }
            break;
        case "pdf-import": {
            // Tool estrazione esercizi da PDF in nuova scheda. Passa il contesto
            // del documento corrente (nome, ids, indirizzo/classe/materia, gruppi)
            // via localStorage (stesso origin, cross-tab) per pre-popolare la pagina.
            try {
                localStorage.setItem("fm_pdfimport_ctx", JSON.stringify(collectPdfImportContext()));
            } catch (_) { /* contesto best-effort */ }
            window.open("/area-docente/pdf-import", "_blank", "noopener");
            break;
        }
        default:
            console.warn("[topbar-modern] azione sconosciuta:", action);
    }
}

/**
 * Raccoglie il contesto del documento esercizio corrente per il tool PDF-Import:
 * indirizzo/classe/materia dall'URL /studio/esercizio/{ind}/{cls}/{mat}/{topic},
 * id contenuto da ?ids=, titolo dalla topbar, e i nomi dei gruppi (contenitori)
 * dai .fm-collapse-toggle presenti in pagina.
 */
function collectPdfImportContext() {
    const ctx = { ts: Date.now() };
    const m = location.pathname.match(/\/studio\/esercizio\/([^/]+)\/([^/]+)\/([^/]+)/);
    if (m) {
        ctx.indirizzo = decodeURIComponent(m[1]);
        ctx.classe    = decodeURIComponent(m[2]);
        ctx.materia   = decodeURIComponent(m[3]);
    }
    const ids = new URLSearchParams(location.search).get("ids") || "";
    ctx.ids = (ids.split(",")[0] || "").trim();
    const titleEl = document.querySelector("[data-fm-title-label]");
    ctx.title = ((titleEl && titleEl.textContent) || document.title || "").trim();
    // Contenitori = SOLO i gruppi delle VERIFICHE correlate (dentro #type_verAll),
    // NON i gruppi esercizio/studente. Ogni verifica è un documento separato →
    // ogni contenitore porta il content_id (data-id numerico del wrap verifica).
    // Normalizza il tipo gruppo come ContractRenderer::normalizeType (VF/RM/Collect).
    const normType = (t) => {
        t = String(t || "");
        if (/^(type_)?VF/i.test(t)) return "VF";
        if (/^(type_)?RM/i.test(t)) return "RM";
        return "Collect";
    };
    const containers = [];
    const seen = new Set();
    document.querySelectorAll('[id^="type_verAll"] .fm-collapse-toggle').forEach((tg) => {
        const g = (tg.textContent || "").replace(/\s+/g, " ").trim();
        if (!g) return;
        const wrap = tg.closest("[data-id]");
        const cid = wrap ? (wrap.getAttribute("data-id") || "").trim() : "";
        if (!/^\d+$/.test(cid)) return; // solo documenti reali (id numerico)
        const gc = tg.closest(".fm-groupcollex");
        const gtype = normType(gc ? gc.getAttribute("data-type") : "");
        const key = cid + "::" + g;
        if (seen.has(key)) return;
        seen.add(key);
        containers.push({ content_id: cid, group: g, type: gtype });
    });
    ctx.containers = containers;
    // Elenco verifiche correlate (anche senza gruppi) per l'opzione "nuovo gruppo".
    const verifiche = [];
    const seenV = new Set();
    document.querySelectorAll('[id^="type_verAll"] .fm-contract-wrap[data-id]').forEach((w) => {
        const cid = (w.getAttribute("data-id") || "").trim();
        if (!/^\d+$/.test(cid) || seenV.has(cid)) return;
        seenV.add(cid);
        const h = w.querySelector(".fm-related-header, .fm-titolo, h1, h2, h3");
        const title = h ? (h.textContent || "").replace(/\s+/g, " ").trim() : "";
        verifiche.push({ content_id: cid, title: title || ("Verifica #" + cid) });
    });
    ctx.verifiche = verifiche;
    return ctx;
}

function bindTopbar(topbar) {
    if (topbar.dataset[BIND_FLAG] === "1") return;
    topbar.dataset[BIND_FLAG] = "1";

    topbar.addEventListener("click", (e) => {
        const btn = e.target.closest("[data-fm-action]");
        if (!btn || !topbar.contains(btn)) return;
        e.preventDefault();
        handleAction(btn.dataset.fmAction, btn, e);
    });

    // Sync iniziale: stato Overleaf riflesso dal bridge.
    refreshOverleafToggle(topbar);
}

function activate() {
    const topbar = document.getElementById(TOPBAR_ID);
    if (!topbar) return;
    const shouldShow = isContextActive();
    if (shouldShow) {
        topbar.hidden = false;
        document.body.classList.add(BODY_ACTIVE_CLASS);
        updateMeta(topbar);
        bindTopbar(topbar);
        observeTopbarHeight(topbar);
    } else {
        topbar.hidden = true;
        document.body.classList.remove(BODY_ACTIVE_CLASS);
        document.documentElement.style.removeProperty("--fm-topbar-h");
    }
}

/** Phase 25.Q.14 — riflette l'altezza reale della topbar (che varia
 *  quando la zona --eser viene popolata da ui-comp) nella CSS custom
 *  property `--fm-topbar-h`, usata da layout.css come padding-top di
 *  #fm-content. Cosi' #header_page resta sotto la topbar senza overlap
 *  indipendentemente dal numero di righe della topbar. */
function observeTopbarHeight(topbar) {
    if (topbar.dataset.fmHeightObserverBound === "1") return;
    topbar.dataset.fmHeightObserverBound = "1";
    const sync = () => {
        const h = Math.ceil(topbar.getBoundingClientRect().height) || 50;
        document.documentElement.style.setProperty("--fm-topbar-h", `${h}px`);
    };
    sync();
    if (typeof ResizeObserver === "function") {
        new ResizeObserver(sync).observe(topbar);
    } else {
        window.addEventListener("resize", sync);
    }
}

// Init: SPA-friendly. Riaggancio dopo partial swap.
function init() {
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", activate, { once: true });
    } else {
        activate();
    }
    window.addEventListener("fm:navigated", () => {
        setTimeout(activate, 0);
    });
    // G19 — verifica-builder dispatcha questo dopo `_caricaCheckboxABin`:
    // a quel punto #SumPtotA/#SumPtotB esistono e relocateTotals può
    // spostarli nel topbar slot senza dover aspettare un click utente.
    window.addEventListener("fm:verifica-ui-loaded", () => {
        setTimeout(activate, 0);
        // updatePointsTotal iniziale: i .total-pointsA/B partono da 0,
        // ma se restoreCheckinState ha ripristinato delle scelte dalla
        // sessionStorage, vogliamo che #SumPtotA/B rifletta la somma.
        // G26 — vanilla iterazione sui .fm-groupcollex (era $(".fm-groupcollex").each).
        const u = window.FM?.utilities;
        if (u?.updateGlobalPointsTotal) {
            document.querySelectorAll(".fm-groupcollex").forEach((el) => {
                try { u.updatePointsTotal(el); } catch (_) {}
            });
        }
    });
    // G9.25 — re-attiva su click checkbox A (verifica-mode caricamento di
    // #scrollbarInfo + #SumPtotA/B) cosi' relocateTotals sposta i totali.
    document.addEventListener("change", (e) => {
        // G19 — anche checkboxAin/Bin e input-pt triggera relocateTotals
        // (i totali appaiono nel topbar slot solo se #SumPtotA è già montato).
        if (e.target.matches?.(".checkboxA, .checkboxB, .checkbox-A, .checkbox-R, .fm-checkbox-ain, .fm-checkbox-bin, .fm-input-pt")) {
            setTimeout(activate, 200);
            setTimeout(activate, 800);
        }
    });
    document.addEventListener("click", (e) => {
        if (e.target.closest?.(".checkboxA, .js-pick-ex")) {
            setTimeout(activate, 200);
            setTimeout(activate, 800);
        }
    });

    // G18 — `.labcheckIN` rendered without `for` attribute (template
    // Elementi_Riservati cloned senza id univoco). Click sul label NON
    // toggla il checkbox sibling — bug invisibile per l'utente che vede
    // "Nessun esercizio selezionato" pur cliccando sull'etichetta.
    // Fix delegation: intercept click su `.labcheckIN` e toggle del primo
    // input checkbox precedente nello stesso `.ABin` parent.
    document.addEventListener("click", (e) => {
        const lbl = e.target.closest?.(".labcheckIN");
        if (!lbl) return;
        // Already handled by browser if `for` is set
        if (lbl.htmlFor) return;
        // Cerca il checkbox sibling (precedente nello stesso wrapper)
        const wrap = lbl.parentElement;
        const cb = wrap?.querySelector("input.fm-checkbox-ain, input.fm-checkbox-bin");
        if (!cb) return;
        cb.checked = !cb.checked;
        cb.dispatchEvent(new Event("change", { bubbles: true }));
        e.preventDefault();
    });

    // G19.21 — toggle `#wrapInfoStudent` (verifica nominativa optional).
    document.addEventListener("click", (e) => {
        const btn = e.target.closest?.("#fm-toggle-student");
        if (!btn) return;
        e.preventDefault();
        const wrap = document.getElementById("wrapInfoStudent");
        if (!wrap) return;
        const willShow = wrap.hasAttribute("hidden");
        if (willShow) {
            wrap.removeAttribute("hidden");
            btn.setAttribute("aria-pressed", "true");
            wrap.querySelector("#nome")?.focus();
        } else {
            wrap.setAttribute("hidden", "");
            btn.setAttribute("aria-pressed", "false");
            // Pulisce i campi quando l'utente nasconde (verifica torna anonima)
            const nome = wrap.querySelector("#nome");
            const cogn = wrap.querySelector("#cognome");
            if (nome) nome.value = "";
            if (cogn) cogn.value = "";
        }
    });
}

init();

// Esponi per debugging / test manuali.
window.FM = window.FM || {};
window.FM.TopbarModern = { activate, isContextActive };
// Esposto SOLO per E2E tests: permette di invocare il builder senza UI.
// Non rimuovere senza aggiornare tests/e2e/*.spec.js.
window.FM.__buildSelectionFromDOM_forTest = buildSelectionFromDOM;
