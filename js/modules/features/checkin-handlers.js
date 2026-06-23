// G22.S15 — import statici (sync) per anti-flicker in updatePreview.
import { normalizeTikz, quickHash, renderAll as tikzRenderAll } from "../editor/tikz-render-client.js";
// G22.S15.bis Fase 6 — sync bidirezionale altezza textarea ⇄ preview.
import { installResizeSync } from "../editor/resize-sync.js";
// G23 — Rendering RM table centralizzato (single source of truth client).
import {
    renderRmTablesWrap,
    syncCellsShape as _syncCellsShapeShared,
    extractCellContent as _extractCellContentShared,
    stateFromContract as _rmStateFromContract,
    normalizeColType,
    COL_TYPES,
} from "../render/rm-table-view.js";
// G23.fix4 — Serializzazione field centralizzata (load+save uniformi per
// quesito/giustificazione/soluzione/intro/celle).
import * as FieldSerializer from "../editor/field-serializer.js";
// G24.phase4 — Client XSS sanitization (defense-in-depth, server è authoritative).
import { sanitizeBlockContent } from "../security/html-sanitize-client.js";
// G24.refactor2 — Multi-tab cooperative lock per editor inline.
import { acquireLock as _acquireEditorLock, releaseLock as _releaseEditorLock } from "./editor-multitab-lock.js";
// G24.refactor5.step1 — Utility escape HTML/TeX estratte dal monolite.
import {
    escHtml, escapeHtml, escHtmlStrict, escAttr, nl2br,
    containsInlineHtml, escTexJs,
} from "../editor/html-text-utils.js";
// G24.refactor5.step2 — Multi-TikZ / GeoGebra markers helpers estratti.
import {
    tikzMarker, ggbMarker,
    collapseTikzBlocks, expandTikzMarkers,
    collapseGeoGebraBlocks, expandGeoGebraMarkers,
} from "../editor/inline-blocks-markers.js";
// G24.refactor5.step3 — Caret/selection utilities estratte dal monolite.
// G24.faseE — `setRangeAtOffsets` (ex find-replace-dialog) ora qui.
import {
    ceCaretOffset, ceSetCaret, ceSelectRange,
    caretAtNodeStart, caretAtNodeEnd,
    placeCaretAtStart, placeCaretAtEnd, placeCaretInFirstLi,
    setRangeAtOffsets,
} from "../editor/caret-utils.js";
// G24.refactor5.step4 — List edit utilities (indent/outdent/findLi/insertHtml).
import {
    findEnclosingLi, indentListItem, outdentListItem,
    makeEmptyList, getEnclosingBlock, fragmentToLines, insertHtmlAtCaret,
} from "../editor/list-edit-utils.js";
// G24.refactor5.step5 + G24.faseE + G24.faseB.3 — Find & Replace dialog
// lazy-loaded via DialogHost registry. setRangeAtOffsets in caret-utils.
/** Lazy-load + apri Find & Replace dialog via DialogHost. */
function openFindReplaceDialog(panel, opts) {
    return dialogHost.open("find-replace", { ...(opts || {}), panel });
}
// G24.refactor5.step7 — Phase 16 topic color cycle utilities.
import {
    TOPIC_COLOR_CYCLE,
    applyColorToCollexItem, applyTopicColorCycle,
    rgbToColorName,
} from "../editor/color-utils.js";
// G24.refactor5.step9 — RM cell popup preview flottante (focus textarea).
// G24.cleanup — positionCellPopup / renderCellPopup non chiamati direttamente
// dal monolite (sono internal a showCellPopupPreview / updateCellPopupPreview).
import {
    showCellPopupPreview, hideCellPopupPreview, updateCellPopupPreview,
} from "../editor/cell-popup-preview.js";
// G24.refactor5.step6prep — UndoManager snapshot-based Ctrl+Z/Y per contenteditable.
import { UndoManager } from "../editor/undo-manager.js";
// G24.refactor5.step6 — Inline format toggle + wrap + insert link.
import {
    expandRangeToInlineAncestors, handleInlineBoxExit,
    normalizeInlineBlockNesting, captureSelectionAsTextOffsets,
    toggleInlineFormat, wrapSnippet,
    wrapAsElement, insertEditableInlineBox, insertLinkDialog,
} from "../editor/inline-format.js";
// G24.refactor5.step8a — _makeEditableField low-level factory.
// G24.faseA.2 — EditorFieldBuilder composer per createEditableField wiring.
import { makeEditableField, EditorFieldBuilder } from "../editor/editable-field-factory.js";
// G24.refactor5.step8b — Section builders semplici (metadata + meta-input
// + badge-color + radio). Builder complessi (buildSection, buildRmLayout,
// buildSingleTableCard) restano nel monolite per cross-deps.
import {
    buildMetadataSection, buildMetaInput,
    buildBadgeColorField, buildRadioSection,
} from "../editor/section-builders.js";
// G24.refactor5.step10 — Helper isolati del TeX dropdown subsystem (5
// funzioni). buildTexDropdown + dialogs CM6/filler restano nel monolite
// (subsistema interconnesso ~900 LOC).
import {
    makeSectionLabel, confirmDialog,
    extractTemplateData, findFocusedTextarea, insertIntoQuesito,
} from "../editor/tex-dropdown-helpers.js";
// G24.faseA.1 — RmLayoutModel domain object con observers.
import { RmLayoutModel } from "../editor/rm-layout-model.js";
// G24.faseB.1 — Service centralizzato workspace TikZ (fetch chain + cache).
import { texWorkspace } from "../editor/tex-workspace-service.js";
// G24.faseB.2 — Conflict resolver strategy pattern per 409 optimistic-lock.
import { resolveByMode } from "../editor/conflict-resolver.js";
// G24.faseB.3 — DialogHost: register-by-id + dynamic import per dialog rari.
import { dialogHost } from "../editor/dialog-host.js";
// G24.faseD — EditorSession state machine per item/group lifecycle.
import { EditorSession, ItemEditorSession, GroupEditorSession } from "../editor/editor-session.js";
// G24.faseC-final — RM layout view factory (buildRmLayoutSection / buildSingleTableCard).
import { createRmLayoutView } from "../editor/rm-layout-view.js";
// G24.faseC-final — Preview scroll sync (pure DOM math).
import { syncPreviewScroll } from "../editor/preview-scroll.js";
// G24.bundle-split — block-dialogs e crud-dialogs lazy-loaded via dynamic
// import. Chunk separati nel bundle; caricati al primo dialog open.
// G24.faseC-dropdown-view — UI building per il TeX dropdown globale (static).
import { createDropdownView } from "../editor/tex-dropdown/dropdown-view.js";
// G24.faseC-buildSection — Factory section builder full (textarea + preview).
import { createBuildSectionView } from "../editor/section-builder-full.js";
// G24.faseC-textarea-enhancements — Factory list-key + textarea hotkeys.
import { createListKeyHandlers, createEnhanceTextarea } from "../editor/textarea-enhancements.js";

import { fetchCsrf } from "../core/dom-utils.js";

/** G24.bundle-split — Lazy-init block dialogs via dynamic import. Chunk
 *  separato nel bundle, caricato al primo dialog open. */
let _blockDialogsPromise = null;
function _ensureBlockDialogs() {
    if (!_blockDialogsPromise) {
        _blockDialogsPromise = import("../editor/tex-dropdown/block-dialogs.js")
            .then((mod) => mod.createBlockDialogs({ toast, updatePreview }));
    }
    return _blockDialogsPromise;
}
/** Thin wrappers async (await dynamic import). */
async function _openTemplateFiller(ta, templateId, editBlockIdx) {
    const dialogs = await _ensureBlockDialogs();
    return dialogs.openTemplateFiller(ta, templateId, editBlockIdx);
}
async function _openTikzModalForBlock(ta, blockIdx) {
    const dialogs = await _ensureBlockDialogs();
    return dialogs.openTikzModalForBlock(ta, blockIdx);
}
async function _openGeoGebraEditorForBlock(ta, idx) {
    const dialogs = await _ensureBlockDialogs();
    return dialogs.openGeoGebraEditorForBlock(ta, idx);
}

/** G24.bundle-split — Lazy-init CRUD dialogs via dynamic import. */
let _crudDialogsPromise = null;
function _ensureCrudDialogs() {
    if (!_crudDialogsPromise) {
        _crudDialogsPromise = import("../editor/tex-dropdown/crud-dialogs.js")
            .then((mod) => mod.createCrudDialogs({
                toast, confirmDialog, escapeHtml, apiPost,
                texWorkspace,
                findFocusedTextarea, insertIntoQuesito, extractTemplateData,
            }));
    }
    return _crudDialogsPromise;
}
/** Thin wrappers async per i 7 CRUD dialog. */
async function openGroupRenameDialog(groupKey, items = null) {
    const d = await _ensureCrudDialogs();
    return d.openGroupRenameDialog(groupKey, items);
}
async function _openTexElementEditor(opts) {
    const d = await _ensureCrudDialogs();
    return d.openTexElementEditor(opts);
}
async function _openTexInsertEditor(getPanel, opts) {
    const d = await _ensureCrudDialogs();
    return d.openTexInsertEditor(getPanel, opts);
}
async function _resetWorkspaceFull(refreshMenu) {
    const d = await _ensureCrudDialogs();
    return d.resetWorkspaceFull(refreshMenu);
}
async function _openNewOrImportDialog(refreshMenu) {
    const d = await _ensureCrudDialogs();
    return d.openNewOrImportDialog(refreshMenu);
}
async function _openTexNewElementInWorkspace(opts) {
    const d = await _ensureCrudDialogs();
    return d.openTexNewElementInWorkspace(opts);
}
async function _openFillerForTemplateRow(getPanel, groupKey, item, refreshMenu) {
    const d = await _ensureCrudDialogs();
    return d.openFillerForTemplateRow(getPanel, groupKey, item, refreshMenu);
}

// G24.dialoghost-register — Registry centralizzata di tutti i dialog
// per discovery (`dialogHost.list()`) + uniform API esterna (E2E test,
// debug). I wrapper sopra mantengono i call-site originali; DialogHost
// è alternative entry point con stesso underlying flow.
dialogHost.register("tex.template-filler", (opts) => _openTemplateFiller(opts.ta, opts.templateId, opts.editBlockIdx));
dialogHost.register("tex.tikz-modal",       (opts) => _openTikzModalForBlock(opts.ta, opts.blockIdx));
dialogHost.register("tex.geogebra-editor",  (opts) => _openGeoGebraEditorForBlock(opts.ta, opts.idx));
dialogHost.register("tex.group-rename",     (opts) => openGroupRenameDialog(opts.groupKey, opts.items));
dialogHost.register("tex.element-editor",   (opts) => _openTexElementEditor(opts));
dialogHost.register("tex.insert-editor",    (opts) => _openTexInsertEditor(opts.getPanel, opts));
dialogHost.register("tex.reset-workspace",  (opts) => _resetWorkspaceFull(opts.refreshMenu));
dialogHost.register("tex.new-or-import",    (opts) => _openNewOrImportDialog(opts.refreshMenu));
dialogHost.register("tex.new-element",      (opts) => _openTexNewElementInWorkspace(opts));
dialogHost.register("tex.filler-row",       (opts) => _openFillerForTemplateRow(opts.getPanel, opts.groupKey, opts.item, opts.refreshMenu));

/** Lazy-init dropdown UI: factory risolta al primo build del dropdown.
 *  G24.bundle-split — passa `getCrud: _ensureCrudDialogs` (async getter)
 *  invece di un'istanza statica, per consentire dynamic import dei crud. */
let _dropdownView = null;
function _ensureDropdownView() {
    if (_dropdownView) return _dropdownView;
    _dropdownView = createDropdownView({
        texWorkspace, makeSectionLabel, escapeHtml, apiPost,
        confirmDialog, toast,
        inlineSnippets: INLINE_SNIPPETS,
        tikzActions: {
            insertCode: _insertTikzCodeBlock,
            openManager: _openTikzBlocksManager,
            openTemplateFiller: _openTemplateFiller,
        },
        getCrud: _ensureCrudDialogs,
    });
    return _dropdownView;
}
/** Thin wrapper sostituisce la legacy buildTexDropdownGlobal. */
function buildTexDropdownGlobal(getPanel) {
    return _ensureDropdownView().buildTexDropdownGlobal(getPanel);
}

/** Lazy-init section builder full: deps (bindPreview, updatePreview,
 *  enhanceTextarea, attachListKeyHandlers, _expandedValue) sono function
 *  declarations hoisted ma defined più in basso nel file. */
let _sectionBuilderView = null;
function _ensureSectionBuilderView() {
    if (_sectionBuilderView) return _sectionBuilderView;
    _sectionBuilderView = createBuildSectionView({
        makeEditableField,
        collapseTikzBlocks, collapseGeoGebraBlocks,
        expandedValue: _expandedValue,
        bindPreview, updatePreview,
        enhanceTextarea,
        undoManager: UndoManager,
        attachListKeyHandlers,
        getBlockDialogs: _ensureBlockDialogs,
    });
    return _sectionBuilderView;
}
/** Thin wrapper: delega al factory. */
function buildSection(label, initialValue, fieldKey = null) {
    return _ensureSectionBuilderView().buildSection(label, initialValue, fieldKey);
}

/** Lazy-init textarea enhancements factories. Le deps sono function
 *  declarations hoisted (saveBackupSnapshot, _formatLatexInField, ecc.). */
let _listKeyHandlers = null;
function _ensureListKeyHandlers() {
    if (_listKeyHandlers) return _listKeyHandlers;
    _listKeyHandlers = createListKeyHandlers({
        expandRangeToInlineAncestors,
        handleInlineBoxExit,
        findEnclosingLi, indentListItem, outdentListItem,
        undoManager: UndoManager,
        saveBackupSnapshot,
        openFindReplaceDialog,
        formatLatexInField: _formatLatexInField,
        toggleInlineFormat,
        insertTabAtCaret: _insertTabAtCaret,
    });
    return _listKeyHandlers;
}
let _enhanceTextareaApi = null;
function _ensureEnhanceTextarea() {
    if (_enhanceTextareaApi) return _enhanceTextareaApi;
    _enhanceTextareaApi = createEnhanceTextarea({
        formatAndSaveLatex: _formatAndSaveLatex,
    });
    return _enhanceTextareaApi;
}
/** Thin wrapper: delega ai factory. */
function attachListKeyHandlers(field) {
    return _ensureListKeyHandlers().attach(field);
}
function enhanceTextarea(ta) {
    return _ensureEnhanceTextarea().attach(ta);
}

/** Lazy-init RM view: la factory viene risolta al primo uso quando tutte
 *  le dipendenze (createEditableField, rebuildRmTables, extractCellContent,
 *  updateCellPopupPreview, hideCellPopupPreview) sono definite. */
let _rmLayoutView = null;
function _ensureRmLayoutView() {
    if (_rmLayoutView) return _rmLayoutView;
    _rmLayoutView = createRmLayoutView({
        createEditableField,
        rebuildRmTables,
        extractCellContent,
        updateCellPopupPreview,
        hideCellPopupPreview,
    });
    return _rmLayoutView;
}
/** Thin wrapper: delega alla factory inizializzata lazy. */
function buildRmLayoutSection(firstTable, totalCells, item) {
    return _ensureRmLayoutView().buildRmLayoutSection(firstTable, totalCells, item);
}
function buildSingleTableCard(model, idx) {
    return _ensureRmLayoutView().buildSingleTableCard(model, idx);
}

// ─────────────────────────────────────────────────────────────────────
// G22.S15 — Multi-TikZ helpers
//
// Un quesito puo' contenere N blocchi `<script type="text/tikz">`.
// Per evitare di scrollare 200+ righe di sorgente nel textarea
// dell'editor inline, ogni blocco viene RIMOSSO dal textarea visibile
// e sostituito con un MARKER tipo `⟨🔍 TikZ #1⟩`. I blocchi originali
// sono conservati su `textarea._tikzBlocks` come array.
//
// L'utente clicca un bottone "🔍 Modifica TikZ #N" → modal CM6 sul
// body del blocco specifico. Save modal → aggiorna `_tikzBlocks[N]`
// + dispatch `input` per re-render preview.
//
// Su saveItemEditor (commit panel) i marker vengono ri-espansi nel
// value finale inviato al server.
// ─────────────────────────────────────────────────────────────────────

// G24.refactor5.step2 — Multi-TikZ / GeoGebra markers helpers estratti in
// `editor/inline-blocks-markers.js`. Alias underscore mantengono call-site.
// G24.cleanup — Alias dead rimossi: _tikzMarker, _ggbMarker (mai usati).
const _collapseTikzBlocks  = collapseTikzBlocks;
const _expandTikzMarkers   = expandTikzMarkers;
const _collapseGeoGebraBlocks = collapseGeoGebraBlocks;
const _expandGeoGebraMarkers  = expandGeoGebraMarkers;

/**
 * Wrapper toolbar/keyboard: format LaTeX dell'editor in focus.
 * Triggerato da pulsante ✨ o Ctrl+Shift+F.
 *
 * SOLO formattazione (latexindent VPS), NO save server. L'utente
 * decide quando salvare con Ctrl+S (backup) o click "Salva modifiche".
 */
async function _formatLatexInField(panel) {
    const ta = panel?._focusedTextarea
        || panel?.querySelector?.(".fm-editor-field")
        || window.__fmFocusedTA;
    if (!ta) { toast?.("Nessun editor in focus", "warn"); return; }
    const before = ta.value;
    let formatted = before;
    let usedFallback = false;
    try {
        formatted = await _formatTexViaVps(before);
    } catch (e) {
        usedFallback = true;
        formatted = _formatLatexHeuristic(before);
    }
    if (formatted === before) {
        toast?.("Già formattato", "info");
        return;
    }
    UndoManager?.save?.(ta);
    if (ta.tagName === "TEXTAREA") {
        const caret = ta.selectionStart;
        ta.value = formatted;
        const newCaret = Math.min(caret, formatted.length);
        ta.setSelectionRange(newCaret, newCaret);
    } else {
        ta.value = formatted; // shim → innerHTML
    }
    ta.dispatchEvent(new Event("input", { bubbles: true }));
    // Re-render preview immediato
    ta._lastRenderedValue = undefined;
    const pv = ta.closest(".fm-editor-row")?.querySelector(".fm-editor-preview");
    if (pv) updatePreview(ta, pv);
    toast?.(usedFallback ? "Formattato (fallback locale)" : "Formattato", "ok");
}

/** G22.S15 — Ctrl+Shift+F handler: format LaTeX via latexindent.pl (VPS) +
 *  save IN-PLACE (NON chiude l'edit mode, l'utente continua a editare).
 *  Fallback a formatter euristico se VPS irraggiungibile. */
async function _formatAndSaveLatex(ta) {
    const before = ta.value;
    let formatted = before;
    let usedFallback = false;
    let fallbackReason = "";
    try {
        formatted = await _formatTexViaVps(before);
    } catch (e) {
        console.warn("[format-tex] VPS fallito, fallback heuristic:", e.message);
        usedFallback = true;
        fallbackReason = e.message || "errore VPS";
        formatted = _formatLatexHeuristic(before);
    }
    if (formatted !== before) {
        const caret = ta.selectionStart;
        ta.value = formatted;
        const newCaret = Math.min(caret, formatted.length);
        ta.setSelectionRange(newCaret, newCaret);
        ta.dispatchEvent(new Event("input", { bubbles: true }));
    }
    // Save IN-PLACE: chiama il flusso di patch del panel SENZA closeItemEditor.
    // Estrae fields come fa saveItemEditor ma lascia il panel aperto.
    const panel = ta.closest(".fm-editor-panel");
    const item  = panel?.parentElement?.closest(".fm-collection__item");
    let savedOk = false;
    if (panel && item) {
        try {
            savedOk = await _saveItemEditorInPlace(item, panel);
        } catch (e) {
            console.error("[format-tex] save in-place fail:", e);
        }
    }
    // Aggiorna preview immediatamente (no debounce) per feedback visivo
    ta._lastRenderedValue = undefined;
    const pvWrap = ta.closest(".fm-editor-row")?.querySelector(".fm-editor-preview");
    if (pvWrap) updatePreview(ta, pvWrap);

    // Toast con info su engine + fallback
    const engine = usedFallback ? `fallback heuristic (${fallbackReason})` : "latexindent.pl";
    const status = savedOk ? "salvato" : "(save fallito, vedi console)";
    toast(`Formattato via ${engine} — ${status}`, savedOk ? "ok" : "warn");
}

/** G22.S15 — Save in-place: stesso flow di saveItemEditor MA non chiude
 *  closeItemEditor / toggleEditMode. Usato da Ctrl+S in editor.
 *  Ritorna true se save server-side OK. */
async function _saveItemEditorInPlace(item, panel) {
    const id = item.dataset.id;
    const fields = _captureEditorFields(panel);
    if (!fields || Object.keys(fields).length === 0) return false;

    // Phase 24.78 — _captureEditorFields ricostruisce options[] dal content
    // delle celle (editor panel) MA: (1) non include il value-soluzione N/T/B
    // (vive negli input della tabella renderizzata, non nel panel); (2) setta
    // correct=false perché lo stato "corretto" di checkbox(X)/radio(V) vive come
    // classe .rm-correct sul <td> della tabella live, non nel panel. Senza questo
    // merge il patch azzerava sia i value N/T sia i flag corretto → uscendo da
    // edit mode sparivano numeri/testi E le spunte. Leggiamo entrambi per indice.
    if (Array.isArray(fields.options) && fields.options.length) {
        const tds = item.querySelectorAll(".fm-rm-table td.rm-option");
        fields.options.forEach((op, i) => {
            if (!op) return;
            const td = tds[i];
            if (!td) return;
            // correct flag dallo stato live (checkbox X / radio V)
            op.correct = td.classList.contains("rm-correct");
            // value-soluzione (text/number/button)
            const numInp = td.querySelector(".fm-rm-num");
            const txtInp = td.querySelector(".fm-rm-text");
            const btn    = td.querySelector(".fm-rm-btn");
            if (numInp)      op.value = numInp.value;
            else if (txtInp) op.value = txtInp.value;
            else if (btn)    { const v = btn.textContent.trim(); if (v && v !== "btn") op.value = v; }
        });
    }

    const op = contractOpUrl(item, "patch");
    // G24.refactor2 — autosave silent: 409 retry senza dialog UI
    const r = op
        ? await apiPost(op.url, fields, { ifMatchVersion: op.version, silent: true })
        : { ok: false, skipped: true };
    if (r?.ok) {
        applyEditsToDom(item, fields);
        // NO commit qui: autosave silenzioso può essere chiamato N volte.
        // commit() rimuove binding + blocca per 5s → spostato in _flushAutosaveAndClose.
        return true;
    } else if (r?.skipped) {
        saveState(id, "edit", fields);
        applyEditsToDom(item, fields);
        return true;
    }
    return false;
}

/** Format LaTeX/TikZ via VPS latexindent. Splitta sui marker tikz, formatta
 *  solo i segmenti di testo (non i marker), riassembla. */
async function _formatTexViaVps(src) {
    const markerRe = /⟨🔍 TikZ #\d+⟩/g;
    const segments = [];
    let last = 0;
    let m;
    while ((m = markerRe.exec(src)) !== null) {
        segments.push({ kind: "text", val: src.slice(last, m.index) });
        segments.push({ kind: "marker", val: m[0] });
        last = m.index + m[0].length;
    }
    segments.push({ kind: "text", val: src.slice(last) });

    // Formatta i segmenti text in parallelo via /tex/format
    const csrf = await fetchCsrf();
    const formattedSegs = await Promise.all(segments.map(async (s) => {
        if (s.kind !== "text" || !s.val.trim()) return s.val;
        const r = await fetch("/tex/format", {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": csrf,
                Accept: "application/json",
            },
            body: JSON.stringify({ source: s.val }),
        });
        if (!r.ok) throw new Error(`VPS format HTTP ${r.status}`);
        const j = await r.json();
        if (!j.ok) throw new Error(j.error || "format failed");
        return j.formatted || s.val;
    }));
    return formattedSegs.join("");
}

/** Formatter euristico LaTeX/TikZ. Applica indentazione a 2 spazi:
 *   - Ogni \\begin{X} apre un livello
 *   - Ogni \\end{X} chiude un livello
 *   - Linee vuote consecutive collassate a max 1
 *   - Trailing whitespace per riga rimosso
 *  NON modifica il contenuto dei marker `⟨🔍 TikZ #N⟩`.
 *  NON modifica il contenuto inside math `\\(...\\)` o `\\[...\\]` (sono inline). */
function _formatLatexHeuristic(src) {
    // Split rispetto ai marker per preservarli
    const markerRe = /⟨🔍 TikZ #\d+⟩/g;
    const segments = [];
    let last = 0;
    let m;
    markerRe.lastIndex = 0;
    while ((m = markerRe.exec(src)) !== null) {
        segments.push({ kind: "text", val: src.slice(last, m.index) });
        segments.push({ kind: "marker", val: m[0] });
        last = m.index + m[0].length;
    }
    segments.push({ kind: "text", val: src.slice(last) });

    // Format ogni segmento "text"
    const formatted = segments.map((s) => {
        if (s.kind !== "text") return s.val;
        return _indentLatexLines(s.val);
    }).join("");
    return formatted;
}

function _indentLatexLines(text) {
    const lines = text.split(/\r?\n/);
    const out = [];
    let level = 0;
    let prevEmpty = false;
    const indentStep = "  ";
    for (const rawLine of lines) {
        const line = rawLine.replace(/[ \t]+$/, ""); // trim trailing
        const trimmed = line.replace(/^\s+/, "");
        if (trimmed === "") {
            if (!prevEmpty) out.push("");
            prevEmpty = true;
            continue;
        }
        prevEmpty = false;
        // Decrement level BEFORE if line starts with \end{...} or close-brace
        if (/^\\end\{/.test(trimmed) || /^[}\]]/.test(trimmed)) {
            level = Math.max(0, level - 1);
        }
        out.push(indentStep.repeat(level) + trimmed);
        // Increment level AFTER if line starts with \begin{...} (whole line)
        if (/^\\begin\{/.test(trimmed)) {
            level++;
        }
    }
    // Rimuovi blank lines finali
    while (out.length && out[out.length - 1] === "") out.pop();
    return out.join("\n");
}

/** G22.S15 — Estrae il "raw source" del contenuto da un container DOM
 *  (.fm-collection, .fm-sol, .fm-giustsol). Combina:
 *    - .fm-text/.fm-latex/.fm-badge → data-raw o textContent
 *    - svg[data-tikz-hash]          → ricostruisce <script type="text/tikz">
 *      via attributi data-tikz-tagopen e data-tikz-body (URL-encoded).
 *    - script[type=text/tikz]       → outerHTML (script ancora non
 *      renderizzato a SVG, lo prendo as-is)
 *  Risultato: stringa adatta a `_collapseTikzBlocks` per estrarre i blocchi
 *  TikZ in markers + _tikzBlocks. */
/** Variante di _extractRawWithTikz che ESCLUDE il `<strong class="fm-sol-label">`
 *  ("SOLUZIONE"/"GIUSTIFICAZIONE") emesso dal renderer come visual label —
 *  non parte del content editabile. */
function _extractRawWithoutLabel(container) {
    if (!container) return "";
    const clone = container.cloneNode(true);
    clone.querySelectorAll(".fm-sol-label").forEach((s) => s.remove());
    return _extractRawWithTikz(clone);
}

function _extractRawWithTikz(container) {
    if (!container) return "";
    // Ogni part = {raw, inline}. Inline = fm-text/fm-latex/fm-badge/textNode.
    // Block = TikZ/GeoGebra/ol/ul/fallback recursive.
    // Separator: inline↔inline = " " (con dedup whitespace e prima di
    // punteggiatura per evitare " ," " ." ); altro boundary = "\n".
    const parts = [];
    for (const node of container.childNodes) {
        if (node.nodeType === 3) {
            const t = (node.textContent || "").trim();
            if (t) parts.push({ raw: node.textContent, inline: true });
            continue;
        }
        if (node.nodeType !== 1) continue;
        const el = node;
        if (el.matches?.(".fm-text, .fm-latex, .fm-badge")) {
            const raw = el.dataset?.raw;
            if (raw) parts.push({ raw, inline: true });
            else parts.push({ raw: (el.textContent || "").trim(), inline: true });
        } else if (el.matches?.("svg[data-tikz-hash]")) {
            const tagOpen = decodeURIComponent(el.getAttribute("data-tikz-tagopen") || '<script type="text/tikz">');
            const body    = decodeURIComponent(el.getAttribute("data-tikz-body") || "");
            parts.push({ raw: `${tagOpen + body  }</` + `script>`, inline: false });
        } else if (el.matches?.("script[type^='text/tikz']")) {
            parts.push({ raw: el.outerHTML, inline: false });
        } else if (el.matches?.(".fm-geogebra-wrap")) {
            parts.push({ raw: el.outerHTML, inline: false });
        } else if (el.matches?.("ol, ul")) {
            // Lista (DSA o standard): emetti outerHTML preservando struttura.
            // Rimuove le UI DSA (F/GF buttons + .fm-dsa-li-num) e unwrappa
            // .fm-dsa-li-content per avere markup pulito nel contenteditable.
            parts.push({ raw: _cleanListForEditor(el), inline: false });
        } else {
            parts.push({ raw: _extractRawWithTikz(el), inline: false });
        }
    }
    // Join smart: inline-inline = " " (salvo whitespace/punteggiatura adiacente);
    // altri = "\n".
    let out = "";
    for (let i = 0; i < parts.length; i++) {
        const p = parts[i];
        if (i === 0) { out = p.raw; continue; }
        const prev = parts[i - 1];
        let sep;
        if (prev.inline && p.inline) {
            // Skip spazio se prev finisce whitespace o p inizia con whitespace/punteggiatura
            if (/\s$/.test(prev.raw) || /^[\s,.;:!?)\]]/.test(p.raw)) sep = "";
            else sep = " ";
        } else {
            sep = "\n";
        }
        out += sep + p.raw;
    }
    return out.trim();
}

/**
 * Pulisce un <ol>/<ul> server-rendered per l'edit-mode contenteditable:
 *   1. Rimuove .fm-dsa-li-buttons (UI F/GF) — non parte del sorgente
 *   2. Rimuove .fm-dsa-li-num (marker numerico testuale) — sostituito da CSS
 *   3. Unwrap .fm-dsa-li-content (sposta children al posto dello span)
 *   4. Sostituisce .fm-text/.fm-latex con il loro data-raw (text node)
 *
 * Il risultato è un <ol class="fm-dsa-li-list" data-fm-list-style="..."> con
 * <li> contenenti SOLO il sorgente (raw text + nested liste). I marker visivi
 * vengono ri-applicati dal CSS list-style + ::marker dell'editor.
 */
function _cleanListForEditor(listEl) {
    const clone = listEl.cloneNode(true);
    clone.querySelectorAll(".fm-dsa-li-buttons, .fm-dsa-li-num").forEach((n) => n.remove());
    clone.querySelectorAll(".fm-dsa-li-content").forEach((span) => {
        const parent = span.parentNode;
        while (span.firstChild) parent.insertBefore(span.firstChild, span);
        span.remove();
    });
    clone.querySelectorAll(".fm-text, .fm-latex").forEach((span) => {
        const raw = span.getAttribute("data-raw");
        if (raw === null) return;
        // Parse raw come HTML: il `data-raw` può contenere inline tag
        // (<b>/<i>/<u>/...). Se lo sostituiamo con un TEXT node, l'outerHTML
        // li escaperebbe (`&lt;b&gt;`) → editor mostra tag come testo letterale.
        const tmp = document.createElement("template");
        tmp.innerHTML = raw;
        const frag = tmp.content;
        const parent = span.parentNode;
        if (!parent) return;
        // Inserisce ogni child del fragment prima dello span, poi rimuove
        while (frag.firstChild) parent.insertBefore(frag.firstChild, span);
        span.remove();
    });
    // data-fm-dsa-state attribute non serve in edit mode
    clone.querySelectorAll("[data-fm-dsa-state]").forEach((li) => {
        li.removeAttribute("data-fm-dsa-state");
    });
    return clone.outerHTML;
}

/** Restituisce il valore "espanso" del textarea (markers → blocks).
 *  Espande sia i TikZ markers (`⟨🔍 TikZ #N⟩`) sia i GeoGebra markers
 *  (`⟨📐 GeoGebra #N⟩`). Se non ci sono blocchi, ritorna ta.value as-is. */
function _expandedValue(ta) {
    let v = ta.value;
    if (ta._tikzBlocks && ta._tikzBlocks.length) v = _expandTikzMarkers(v, ta._tikzBlocks);
    if (ta._geogebraBlocks && ta._geogebraBlocks.length) v = _expandGeoGebraMarkers(v, ta._geogebraBlocks);
    return v;
}

/** G22.S15 — Costruisce un array di blocchi `{type, ...}` dal textarea.
 *  Output compatibile col contract-schema:
 *    - text chunks → {type:'text', content:'...'}
 *    - tikz scripts → {type:'tikz', script:'...', tex_packages:..., tikz_libs:...,
 *                       data_template_id:..., data_template_data:...}
 *  Se non ci sono blocchi TikZ, ritorna [{type:'text', content:<value>}] (uguale
 *  al wrapping che il backend faceva ma client-side).
 */
function _buildBlocksFromTextarea(ta) {
    const tikzBlocks = ta._tikzBlocks || [];
    const ggbBlocks  = ta._geogebraBlocks || [];
    // Strip ZWS placeholder usato da _wrapAsElement (collapsed wrap → garantisce
    // caret dentro l'inline element). Char invisibile, no significato semantico.
    // Anche tag inline vuoti residuali da split: <b></b>, <i></i>, ecc.
    // Strip <br> residui-only: contenteditable vuoto inserisce auto un <br>
    // come placeholder DOM (Firefox + Chrome); senza questa pulizia il save
    // emette `<br>` letterale e ContractRenderer lo escapa in data-raw.
    let value = (ta.value || "")
        .replace(/​/g, "")
        .replace(/<(b|strong|i|em|u|s|sub|sup)>\s*<\/\1>/gi, "");
    // Caso "campo vuoto": solo whitespace + <br>(/) → considera vuoto
    if (/^(\s|<br\s*\/?>)*$/i.test(value)) value = "";

    // G23.fix5 — Pre-clean: strippa DSA wrappers (.fm-dsa-li-num,
    // .fm-dsa-li-buttons) + unwrap .fm-dsa-li-content. Se l'editor textarea
    // contiene questi span (copy-paste da render server, oppure re-open con
    // stato non-canonico), il parser includerebbe "a." "b." come falsi text
    // block CONCATENATI al content reale (es. "d" → "b.d"). Idempotente:
    // se assenti, no-op zero costo.
    if (/fm-dsa-li-(num|buttons|content)/.test(value) || /fm-text\b|fm-latex\b/.test(value)) {
        const cleaner = document.createElement("div");
        cleaner.innerHTML = value;
        cleaner.querySelectorAll(".fm-dsa-li-buttons, .fm-dsa-li-num").forEach(n => n.remove());
        cleaner.querySelectorAll(".fm-dsa-li-content").forEach(span => {
            const parent = span.parentNode;
            if (!parent) return;
            while (span.firstChild) parent.insertBefore(span.firstChild, span);
            span.remove();
        });
        // Replace fm-text/fm-latex with data-raw value (preserve LaTeX source)
        cleaner.querySelectorAll(".fm-text[data-raw]").forEach(span => {
            const raw = span.getAttribute("data-raw") || "";
            const tmp = document.createElement("template");
            tmp.innerHTML = raw;
            const parent = span.parentNode;
            if (!parent) return;
            while (tmp.content.firstChild) parent.insertBefore(tmp.content.firstChild, span);
            span.remove();
        });
        cleaner.querySelectorAll(".fm-latex[data-raw]").forEach(span => {
            const raw = span.getAttribute("data-raw") || "";
            const replacement = document.createElement("span");
            replacement.className = "fm-latex";
            replacement.setAttribute("data-raw", raw);
            replacement.innerHTML = raw;
            span.replaceWith(replacement);
        });
        cleaner.querySelectorAll("[data-fm-dsa-state]").forEach(li => {
            li.removeAttribute("data-fm-dsa-state");
        });
        value = cleaner.innerHTML;
    }

    // Step 1: split su marker TikZ/GeoGebra (esistente).
    const markerRe = /(⟨🔍 TikZ #(\d+)⟩|⟨📐 GeoGebra #(\d+)⟩)/g;
    markerRe.lastIndex = 0;
    const segments = [];
    let last = 0;
    let m;
    while ((m = markerRe.exec(value)) !== null) {
        if (m.index > last) segments.push({ kind: "text", text: value.slice(last, m.index) });
        if (m[2] !== undefined) {
            const idx = parseInt(m[2], 10) - 1;
            const blk = tikzBlocks[idx];
            if (blk) segments.push({ kind: "block", block: _tikzBlockToContractBlock(blk) });
        } else if (m[3] !== undefined) {
            const idx = parseInt(m[3], 10) - 1;
            const blk = ggbBlocks[idx];
            if (blk) segments.push({ kind: "block", block: {
                type: "geogebra",
                ggb_b64: blk.ggb_b64 || "",
                svg:     blk.svg || "",
                label:   blk.label || "",
                width:   blk.width || "",
            } });
        }
        last = m.index + m[0].length;
    }
    if (last < value.length) segments.push({ kind: "text", text: value.slice(last) });

    // Step 2: per ogni text segment, parsa <ol>/<ul> in block list strutturati.
    // Pattern: lista innermost-first via parser DOMParser (supporta nested).
    const blocks = [];
    for (const seg of segments) {
        if (seg.kind === "block") { blocks.push(seg.block); continue; }
        const text = seg.text;
        if (!text.trim()) continue;
        // Quick check: contiene <ol>/<ul>?
        if (!/<\s*(ol|ul)\b/i.test(text)) {
            blocks.push({ type: "text", content: text });
            continue;
        }
        // Parse via DOMParser per gestire nesting correttamente.
        try {
            const parser = new DOMParser();
            const doc = parser.parseFromString(`<div>${text}</div>`, "text/html");
            const root = doc.body.firstChild;
            const out = [];
            _flattenChildrenToBlocks(root, out);
            for (const b of out) blocks.push(b);
        } catch (e) {
            blocks.push({ type: "text", content: text });
        }
    }
    // G24.phase4 — Client pre-sanitize: scan blocks text/list e applica
    // sanitizeBlockContent ai text content che contengono tag inline.
    // Defense-in-depth: server è authoritative (HtmlSanitizer), questo è
    // UX hint per ridurre payload malicious in transit (paste untrusted).
    _sanitizeBlocksClientSide(blocks);
    return blocks;
}

/** G24.phase4 — Walker che applica sanitizeBlockContent a text content
 *  con inline HTML, ricorsivo su list items. Mutates in-place. */
function _sanitizeBlocksClientSide(blocks) {
    if (!Array.isArray(blocks)) return;
    for (const b of blocks) {
        if (!b || typeof b !== "object") continue;
        if (b.type === "text" && typeof b.content === "string"
            && /<(b|strong|i|em|u|s|sub|sup|a|span)\b/i.test(b.content)) {
            b.content = sanitizeBlockContent(b.content);
        } else if (b.type === "list" && Array.isArray(b.items)) {
            for (const item of b.items) _sanitizeBlocksClientSide(item);
        }
    }
}

/**
 * Itera i child nodes di `root` producendo blocchi contract:
 * - text node con whitespace → ignorato
 * - text node con contenuto → block text
 * - <ol>/<ul> → block list (recursive su <li>)
 * - altri tag → estrai textContent come text block (fallback safe)
 */
function _flattenChildrenToBlocks(root, out) {
    let textBuf = "";
    const flushText = () => {
        if (textBuf.trim()) out.push({ type: "text", content: textBuf.trim() });
        textBuf = "";
    };
    // Tag inline preservati nel content del block text (HTML markup mantenuto
    // per roundtrip post-save → server Sanitizer convertirà a LaTeX).
    const INLINE_PRESERVE = /^(b|strong|i|em|u|s|sub|sup|a)$/i;

    for (const child of root.childNodes) {
        if (child.nodeType === Node.TEXT_NODE) {
            textBuf += child.textContent;
            continue;
        }
        if (child.nodeType !== Node.ELEMENT_NODE) continue;
        const tag = child.tagName.toLowerCase();
        if (tag === "ol" || tag === "ul") {
            flushText();
            out.push(_olUlToListBlock(child));
        } else if (tag === "br") {
            textBuf += "\n";
        } else if (child.classList?.contains("fm-latex")) {
            // Span semantico LaTeX: emette block dedicato `{type:'latex'}`.
            // Senza unwrap, l'outerHTML del span verrebbe storato come testo
            // letterale → corruzione roundtrip (escape doppio).
            flushText();
            out.push({ type: "latex", content: child.getAttribute("data-raw") || child.textContent || "" });
        } else if (child.classList?.contains("fm-text") || child.classList?.contains("fm-badge")) {
            // Span semantico testo/badge: unwrappa al valore data-raw originale.
            // Evita che lo span markup contamini il content del text block parent
            // (al re-save lo span verrebbe ri-emesso doppio dal renderer → loop).
            textBuf += child.getAttribute("data-raw") || child.textContent || "";
        } else if (child.classList?.contains("giustifica")) {
            // G23.fix16 — Span `.giustifica` IGNORATO durante capture dell'intro.
            // È un field SEPARATO nel group editor (sezione dedicata), non
            // inline nel testo. Server emette span dedicato leggendo
            // `g.giustifica` string del contract.
            // (revert fix15: niente block type 'giustifica')
        } else if (INLINE_PRESERVE.test(tag)) {
            // Inline format/styling: preserva outerHTML così il content
            // del block text round-trippa correttamente (Sanitizer lato
            // server converte <b>/<i>/<u>/<a> in LaTeX).
            textBuf += child.outerHTML;
        } else if (tag === "span") {
            // G23 — <span> generico (incluso .fm-dsa-li-content residuo): se
            // contiene OL/UL nested, recurse via _flattenChildrenToBlocks
            // (preserva struttura lista). Altrimenti serialize inline.
            if (child.querySelector("ol, ul")) {
                _flattenChildrenToBlocks(child, out);
                // Non aggiungere a textBuf: i blocks già pushati via recursion.
                // Ma le text node inline del span vanno catturate.
                // _flattenChildrenToBlocks chiama flushText interno per ogni OL/UL.
            } else {
                textBuf += _serializeInlinePreserving(child);
            }
        } else if (tag === "div" || tag === "p") {
            // Block container (creato dal browser su Enter in contenteditable):
            // recurse per preservare tag inline interni + newline finale.
            // textContent perderebbe <b>/<i>/<u> dentro al <div>.
            // G23 — se contiene OL/UL, recurse anche per liste.
            if (child.querySelector("ol, ul")) {
                _flattenChildrenToBlocks(child, out);
            } else {
                textBuf += `${_serializeInlinePreserving(child)  }\n`;
            }
        } else {
            textBuf += child.textContent;
        }
    }
    flushText();
}

/** Serializza ricorsivamente il subtree preservando tag inline (b/i/u/...)
 *  e convertendo <br>/<div>/<p> in newline. Usato per <div>/<p> generati
 *  dal browser quando l'utente preme Enter in contenteditable. */
function _serializeInlinePreserving(node) {
    const INLINE_PRESERVE = /^(b|strong|i|em|u|s|sub|sup|a)$/i;
    let buf = "";
    for (const child of node.childNodes) {
        if (child.nodeType === Node.TEXT_NODE) {
            buf += child.textContent;
            continue;
        }
        if (child.nodeType !== Node.ELEMENT_NODE) continue;
        const tag = child.tagName.toLowerCase();
        if (tag === "br") { buf += "\n"; continue; }
        // Span semantici fm-text/fm-latex/fm-badge: unwrap al data-raw value
        // (NO outerHTML, NO markup leak roundtrip).
        if (child.classList?.contains("fm-text") || child.classList?.contains("fm-badge") ||
            child.classList?.contains("fm-latex")) {
            buf += child.getAttribute("data-raw") || child.textContent || "";
            continue;
        }
        if (INLINE_PRESERVE.test(tag)) { buf += child.outerHTML; continue; }
        if (tag === "span") {
            // <span> generico: unwrap, no semantic
            buf += _serializeInlinePreserving(child);
            continue;
        }
        if (tag === "div" || tag === "p") {
            buf += `${_serializeInlinePreserving(child)  }\n`;
            continue;
        }
        buf += child.textContent;
    }
    return buf;
}

/** G23 — Normalizza dsa_section nei list block di una cella RM:
 *  - top-level list → 'options'  (marker letter visibile, no F/GF)
 *  - nested list    → 'sub'      (marker nativo browser, no F/GF)
 *  Mutates blocks in-place. Idempotent. */
function _normalizeListSectionForCell(blocks, depth = 0) {
    if (!Array.isArray(blocks)) return;
    for (const b of blocks) {
        if (!b || b.type !== "list") continue;
        b.dsa_section = depth === 0 ? "options" : "sub";
        for (const item of (b.items || [])) {
            // item è un array di blocks → recurse
            _normalizeListSectionForCell(item, depth + 1);
        }
    }
}

function _olUlToListBlock(el) {
    const block = {
        type: "list",
        ordered: el.tagName.toLowerCase() === "ol",
        items: [],
    };
    const styleType = el.getAttribute("type");
    if (styleType) block.list_style = styleType;
    const startAttr = el.getAttribute("start");
    if (startAttr) block.start = parseInt(startAttr, 10) || 1;
    const sec = el.getAttribute("data-dsa-section");
    if (sec) block.dsa_section = sec;
    // PRESET stile gerarchico (Google Docs-like): preserva attribute per
    // round-trip editor → save → render.
    const preset = el.getAttribute("data-fm-list-style");
    if (preset) block.list_preset = preset;
    for (const li of el.children) {
        if (li.tagName.toLowerCase() !== "li") continue;
        const itemBlocks = [];
        _flattenChildrenToBlocks(li, itemBlocks);
        block.items.push(itemBlocks);
    }
    return block;
}

/** Mirror lato JS di ContractRenderer.renderBlocks() — converte un array
 *  di blocchi `{type, ...}` in stringa HTML adatta a `innerHTML`.
 *  Se l'argomento e' gia' una stringa (legacy), passa-through. */
function _toHtml(value) {
    if (typeof value === "string") return value;
    if (!Array.isArray(value)) return "";
    return value.map((b) => {
        const renderer = BLOCK_RENDERERS[b?.type];
        return renderer ? renderer(b) : "";
    }).join("");
}

/** Registry BLOCK_RENDERERS: type → renderer function. Mirror lato JS di
 *  ContractRenderer.renderBlocks() PHP. Aggiungere un nuovo block type = una
 *  nuova entry (open-closed principle, no modifica `_toHtml`). */
const BLOCK_RENDERERS = {
    text: (b) => {
        const c = String(b.content ?? "");
        const esc = _escHtml(c);
        // Tag SEMPRE <span>: <p> auto-chiuderebbe su <div>/<p> interni →
        // corruzione DOM al reload. nl2br SEMPRE per andate a capo; escape
        // solo se NO inline tag (altrimenti distrugge <b>/<i>/<u>).
        const visible = _containsInlineHtml(c) ? _nl2br(c) : _nl2br(esc);
        return `<span class="fm-text" data-raw="${esc}">${visible}</span>`;
    },
    latex: (b) => {
        const c = String(b.content ?? "");
        return `<span class="fm-latex" data-raw="${_escHtml(c)}">${c}</span>`;
    },
    tikz: (b) => {
        const pkg = _escAttr(b.tex_packages ?? "");
        const lib = _escAttr(b.tikz_libs ?? "");
        const tid = _escAttr(b.data_template_id ?? "");
        const tdat = _escAttr(b.data_template_data ?? "");
        const script = String(b.script ?? "");
        let attrs = ' type="text/tikz" data-show-console="true"';
        if (pkg)  attrs += ` data-tex-packages='${pkg}'`;
        if (lib)  attrs += ` data-tikz-libraries="${lib}"`;
        if (tid)  attrs += ` data-template-id="${tid}"`;
        if (tdat) attrs += ` data-template-data="${tdat}"`;
        return `<script${attrs}>${script}</` + `script>`;
    },
    geogebra: (b) => {
        const ggb = _escAttr(b.ggb_b64 ?? "");
        const lbl = _escAttr(b.label ?? "");
        const wid = _escAttr(b.width ?? "");
        const svg = String(b.svg ?? "");
        let attrs = "";
        if (ggb) attrs += ` data-ggb-base64="${ggb}"`;
        if (lbl) attrs += ` data-ggb-label="${lbl}"`;
        if (wid) attrs += ` data-ggb-width="${wid}"`;
        const m = String(b.width || "").match(/^(\d+(?:\.\d+)?)%$/);
        if (m) attrs += ` style="max-width:${m[1]}%;width:${m[1]}%;"`;
        return `<span class="fm-geogebra-wrap"${attrs}>${svg}</span>`;
    },
    list: (b) => _renderListBlock(b),
};

/**
 * Render block list → HTML (mirror lato server di ContractRenderer.renderBlocks).
 *
 * G27.dsa.fix — Allineato col PHP renderBlocks (struttura uniforme per
 * tutti i livelli):
 *   - Sezioni question/sub/options ricevono F/GF buttons
 *   - TUTTI i <li> hanno [F/GF span][marker span][content span] in flex
 *   - Sub-list senza preset → marker default per depth (decimal/alpha/roman)
 *   - Solution/justification: NO F/GF, struttura comunque uniforme col marker
 *
 * @param {Object} b — block {type:"list", ordered, items, list_preset?, dsa_section?, start?}
 * @param {number} depth — profondita' di nesting (0=outer); usato per marker
 *   default quando preset assente.
 */
function _renderListBlock(b, depth = 0, rootPreset = null) {
    // Il preset è gerarchico: solo il ROOT lo porta. I livelli annidati ne
    // ereditano i marker PER-LIVELLO (prima usavano defaultPresetForDepth →
    // marker sbagliati, es. arrow-bullet ➤/♦/● diventava ➤/•/•).
    if (depth === 0) rootPreset = b.list_preset || "";
    const tag = b.ordered ? "ol" : "ul";
    const sec = _escAttr(b.dsa_section || "options");
    const styleAttr = b.list_style ? ` type="${_escAttr(b.list_style)}"` : "";
    const startAttr = b.start != null ? ` start="${parseInt(b.start, 10) || 1}"` : "";
    const presetAttr = b.list_preset ? ` data-fm-list-style="${_escAttr(b.list_preset)}"` : "";
    // G27.dsa.fix — F/GF buttons emessi per TUTTE le sezioni question-related
    // (question, sub, options). Solution/justification: NO F/GF.
    const isQuestion = (b.dsa_section === "question"
                     || b.dsa_section === "sub"
                     || b.dsa_section === "options");
    const dsaBtns = isQuestion
        ? `<span class="fm-dsa-li-buttons" aria-label="Marca DSA">`
        +   `<button type="button" class="fm-dsa-li-btn fm-dsa-li-F" data-mark="F">F</button>`
        +   `<button type="button" class="fm-dsa-li-btn fm-dsa-li-GF" data-mark="GF">GF</button>`
        + `</span>`
        : ``;
    const liAttr = isQuestion ? ` data-fm-dsa-state=""` : ``;
    const startIdx = parseInt(b.start, 10) || 1;
    let liIdx = startIdx - 1;
    // Ricorsione: sub-items in section "sub" se siamo question/sub, altrimenti
    // mantieni section corrente (es. options → sub-list options).
    const nextSection = (b.dsa_section === "question" || b.dsa_section === "sub") ? "sub" : (b.dsa_section || "options");
    const liHtmlArr = (b.items || []).map((item) => {
        liIdx++;
        const inner = Array.isArray(item)
            ? item.map((blk) => {
                if (blk?.type === "list") {
                    return _renderListBlock({ ...blk, dsa_section: nextSection }, depth + 1, rootPreset);
                }
                return _toHtml([blk]);
            }).join("")
            : _escHtml(String(item || ""));
        const marker = _computeListMarker(rootPreset, liIdx, !!b.ordered, depth);
        return `<li${liAttr}>${dsaBtns}`
            + `<span class="fm-dsa-li-num">${_escHtml(marker)}</span>`
            + `<span class="fm-dsa-li-content">${inner}</span>`
            + `</li>`;
    });
    return `<${tag} class="fm-dsa-li-list" data-dsa-section="${sec}"${styleAttr}${startAttr}${presetAttr}>${liHtmlArr.join("")}</${tag}>`;
}

// Marker PER-LIVELLO di ogni preset (mirror Sanitizer::PRESET_LEVELS + CSS
// ::marker). UL = char letterali; OL = codici formato (UA=Alfa-maiusc, la=alfa-
// minusc, UR=Roman-maiusc, lr=roman-minusc, 0D=decimal-zero, D=decimal) +
// suffisso . o ). "" = default OL (1./a./i.). Il marker del livello D = LEVELS[min(D,2)].
const _UL_LEVELS = {
    "arrow-bullet": ["➤", "♦", "●"],
    "star-circle":  ["★", "○", "■"],
    "":             ["●", "○", "■"], // ul senza preset: cerchio pieno / vuoto / quadrato pieno (glifi geometrici grandi, dimensioni uniformi — • e ▪ erano troppo piccoli)
};
const _OL_LEVELS = {
    "alpha-decimal":      ["UA.", "D.",  "la."],
    "lower-alpha-roman":  ["la.", "lr.", "D."],
    "roman-alpha":        ["UR.", "UA.", "D."],
    "decimal-zero":       ["0D.", "la.", "lr."],
    "paren":              ["D)",  "la)", "lr)"],
    "alpha-paren":        ["UA)", "D)",  "la)"],
    "lower-alpha-paren":  ["la)", "lr)", "D)"],
    "roman-paren":        ["UR)", "UA)", "D)"],
    "decimal-zero-paren": ["0D)", "la)", "lr)"],
    "":                   ["D.",  "la.", "lr."], // ol senza preset
};
function _markerFromCode(code, idx) {
    if (!/[.)]$/.test(code)) return code; // bullet letterale (➤ ♦ ● ★ ○ ■)
    const suffix = code.slice(-1);
    const core = code.slice(0, -1);
    const alpha = (n, up) => String.fromCharCode((up ? 65 : 97) + ((n - 1) % 26));
    const roman = (num, up) => {
        const map = [[1000,"M"],[900,"CM"],[500,"D"],[400,"CD"],[100,"C"],[90,"XC"],
                     [50,"L"],[40,"XL"],[10,"X"],[9,"IX"],[5,"V"],[4,"IV"],[1,"I"]];
        let out = ""; let n = num;
        for (const [v, s] of map) while (n >= v) { out += s; n -= v; }
        return up ? out : out.toLowerCase();
    };
    let s;
    switch (core) {
        case "UA": s = alpha(idx, true); break;
        case "la": s = alpha(idx, false); break;
        case "UR": s = roman(idx, true); break;
        case "lr": s = roman(idx, false); break;
        case "0D": s = (idx < 10 ? "0" : "") + idx; break;
        default:   s = String(idx); // "D"
    }
    return s + suffix;
}
/**
 * Marker testuale per il livello `depth` di una lista col preset `preset`.
 * Level-aware: i nested ereditano la gerarchia del preset ROOT (mirror PHP
 * ContractRenderer::computeListMarker + Sanitizer::PRESET_LEVELS).
 */
function _computeListMarker(preset, idx, isOrdered, depth = 0) {
    if (!isOrdered) {
        const lv = _UL_LEVELS[preset];
        return lv ? lv[Math.min(depth, lv.length - 1)] : "●";
    }
    const lv = _OL_LEVELS[preset] || _OL_LEVELS[""];
    return _markerFromCode(lv[Math.min(depth, lv.length - 1)], idx);
}

/**
 * Quick check: il content contiene markup inline preservato?
 * (b/strong/i/em/u/s/sub/sup/a/span — tag pattern matching).
 */
// G24.refactor5.step1 — _escHtml / _escAttr / _nl2br / _containsInlineHtml
// estratti in `editor/html-text-utils.js` (import in cima al file). Alias locali
// (underscored) mantengono il pattern di "private helper" in fundamental block.
const _containsInlineHtml = containsInlineHtml;
const _escHtml = escHtmlStrict;
const _escAttr = escAttr;
const _nl2br = nl2br;

/** Trasforma un _tikzBlocks[i] (`{tagOpen, body, tagClose}`) in un block
 *  contract `{type:'tikz', script, tex_packages?, tikz_libs?, data_template_id?,
 *  data_template_data?}`. Estrae attributi dal tagOpen via regex. */
function _tikzBlockToContractBlock(blk) {
    const out = {
        type: "tikz",
        script: (blk.body || "").replace(/^\n+/, "").replace(/\n+$/, ""),
    };
    const tagOpen = blk.tagOpen || "";
    const pkg  = tagOpen.match(/data-tex-packages=(["'])([\s\S]*?)\1/i);
    const lib  = tagOpen.match(/data-tikz-libraries=(["'])([\s\S]*?)\1/i);
    const tid  = tagOpen.match(/data-template-id=(["'])([\s\S]*?)\1/i);
    const tdat = tagOpen.match(/data-template-data=(["'])([\s\S]*?)\1/i);
    if (pkg)  out.tex_packages    = pkg[2];
    if (lib)  out.tikz_libs       = lib[2];
    if (tid)  out.data_template_id   = tid[2];
    if (tdat) out.data_template_data = tdat[2];
    return out;
}

// Esponi helper su window.FM cosi' altri moduli (es. editor-draft-autosave)
// possono salvare i valori ESPANSI invece dei marker `⟨🔍 TikZ #N⟩`.
if (typeof window !== "undefined") {
    window.FM = window.FM || {};
    window.FM.MultiTikzHelpers = {
        expandedValue: _expandedValue,
        collapseTikzBlocks: _collapseTikzBlocks,
        expandTikzMarkers: _expandTikzMarkers,
        collapseGeoGebraBlocks: _collapseGeoGebraBlocks,
        expandGeoGebraMarkers: _expandGeoGebraMarkers,
    };
}

/** G22.S15 / D — apre il modal "Gestione blocchi TikZ" (sidebar + edit
 *  per-block). Lazy load del bundle alla prima apertura. */
async function _openTikzBlocksManager(ta) {
    try {
        if (!window.FM?.openTikzBlocksManager) {
            const cacheBust = `?t=${Date.now()}`;
            const res = await fetch(`/build/manifest.json${cacheBust}`, { credentials: "same-origin", cache: "no-store" });
            if (!res.ok) throw new Error(`manifest HTTP ${res.status}`);
            const manifest = await res.json();
            const entry = manifest["js/entries/tikz-blocks-manager.js"];
            if (!entry) throw new Error("entry tikz-blocks-manager assente");
            await import(/* @vite-ignore */ `/build/${entry.file}`);
        }
        window.FM.openTikzBlocksManager(ta);
    } catch (e) {
        console.error("[blocks-manager] load failed:", e);
        if (typeof ToastManager !== "undefined") ToastManager.show("error", "Manager blocchi", `Errore: ${  e.message}`, 5000);
    }
}

/** G22.S15 — inserisce un nuovo blocco TikZ codice grezzo (vuoto) nella
 *  textarea, lo collassa a marker `⟨🔍 TikZ #N⟩`, ridisegna i bottoni-edit,
 *  e apre subito il modal CM6 sul nuovo block per scriverci dentro. */
function _insertTikzCodeBlock(ta) {
    const seed = `\\begin{tikzpicture}\n  \\draw (0,0) -- (2,2);\n\\end{tikzpicture}`;
    const newBlock = {
        tagOpen:  '<script type="text/tikz" data-show-console="true">',
        body:     `\n${  seed  }\n`,
        tagClose: '</' + 'script>',
    };
    ta._tikzBlocks = ta._tikzBlocks || [];
    ta._tikzBlocks.push(newBlock);
    const newMarker = `⟨🔍 TikZ #${ta._tikzBlocks.length}⟩`;
    // Inserisce il marker alla posizione del cursore (o alla fine).
    const start = ta.selectionStart ?? ta.value.length;
    const end   = ta.selectionEnd   ?? ta.value.length;
    const insert = (start === 0 || ta.value[start - 1] === "\n") ? newMarker : `\n${  newMarker}`;
    ta.value = ta.value.slice(0, start) + insert + ta.value.slice(end);
    if (typeof ta._renderTikzButtons === "function") ta._renderTikzButtons();
    ta._lastRenderedValue = undefined;
    ta.dispatchEvent(new Event("input", { bubbles: true }));
    // Apre subito il CM6 modal sul block appena creato.
    const newIdx = ta._tikzBlocks.length - 1;
    setTimeout(() => _openTikzModalForBlock(ta, newIdx), 50);
}

/** Apre il Template Filler (Phase 1) per generare/editare TikZ via form.
 *  Lazy-load del bundle. Su save: inserisce nuovo `<script type="text/tikz"
 *  data-template-id=...>` in fondo al textarea, OPPURE aggiorna l'esistente
 *  con stesso data-template-id (round-trip).
 *  @param {HTMLTextAreaElement} ta
 *  @param {string} templateId
 *  @param {number|null} editBlockIdx  index of _tikzBlocks to overwrite, or null = append new
 */

/**
 * Phase 15 — CheckIN handlers (per-quesito controls).
 *
 * Ogni .fm-collection__item riceve .checkIN iniettato da UIComp._caricaDivRiservati
 * con:
 *   - .ABin: checkboxAin (A) + checkboxBin (R) → selezione versioni verifica
 *   - .input-wrapper-pt > .fm-input-pt → punteggio quesito
 *   - .origin-selector > .origin → cambia fonte (libro/edizione)
 *   - .color-selector > .colorSelect → categoria colore
 *   - .editQuesito:
 *       .editQ.addBtn         + nuovo quesito
 *       .editQ.clone          clona quesito
 *       .editQ.single-modificaBtn  modifica contenuto
 *       .editQ.single-quick-saveBtn  salva rapido (inizialmente hidden)
 *       .editQ.removeBtn      elimina quesito
 *   - .moveQuesito:
 *       .move-up-btn / .move-down-btn  sposta su/giù
 *       .move-position       input posizione numerica
 *   - .sync-quesito-btn      [RIMOSSO G22.S15] era un placeholder no-op
 *
 * Strategia Phase 15:
 *   - Tutti i binding via event delegation su document (idempotenti).
 *   - Stato A/R + pt persistito in sessionStorage per sessione (KV "fmv").
 *   - Origin/color → applicano classe CSS subito + POST /api/teacher/content/{id}/meta
 *     (stub: se API non pronta → toast "salvataggio differito").
 *   - Add/Clone/Edit/Delete: confirm + POST /api/teacher/content/quesito/{id}/{action}.
 *   - Move up/down: riordino DOM locale (persistenza tramite /reorder).
 *   - Sync: fetch GET /api/teacher/content/{id}.json + re-render singolo quesito.
 */

function bindCheckinHandlers() {
    // document.dataset non esiste (HTMLDocument); usa documentElement (<html>).
    const flag = document.documentElement;
    if (flag.dataset.fmCheckinBound === "1") return;
    flag.dataset.fmCheckinBound = "1";

    // Delegation: single listener per tipo (change/click) filtra target
    document.addEventListener("change", onCheckinChange, true);
    document.addEventListener("click", onCheckinClick, true);
    document.addEventListener("input", onCheckinInput, true);
    // A11y: gli stepper ▲▼ (.fm-stepper) sono aria-hidden (duplicano l'input
    // number). Hanno tabindex=-1 ma il click li mette comunque a fuoco →
    // "Blocked aria-hidden ... descendant retained focus". preventDefault sul
    // mousedown impedisce il focus (il click parte lo stesso → stepper ok).
    document.addEventListener("mousedown", (e) => {
        if (e.target.closest?.(".fm-stepper__btn")) e.preventDefault();
    }, true);
    // A11y (WCAG 2.1.1 Keyboard) — i controlli-icona dell'editor verifiche
    // (clona/modifica/quick-save/elimina/aggiungi quesito + modifica/save/
    // elimina gruppo) sono <div role="button" tabindex="0"> click-only (vedi
    // Elementi_Riservati.html): qui Invio/Spazio sintetizzano un click — che
    // onCheckinClick gestisce già. Scoped ai soli controlli marcati role=button,
    // così non interferisce con la digitazione né con altri elementi.
    document.addEventListener("keydown", (e) => {
        if (e.key !== "Enter" && e.key !== " " && e.key !== "Spacebar") return;
        const ctrl = e.target?.closest?.(
            '.fm-edit-q[role="button"], .edit[role="button"]'
        );
        if (!ctrl) return;
        e.preventDefault();   // Spazio non deve scrollare la pagina
        ctrl.click();
    }, true);
}

function onCheckinChange(e) {
    const t = e.target;
    if (!t?.closest) return;

    // Phase 16 — reorder .fm-groupcollex via input numerico (.move-position-problem).
    if (t.classList.contains("fm-move-position-problem")) {
        const problem = t.closest(".fm-groupcollex");
        const pos = parseInt(t.value, 10);
        if (problem && Number.isFinite(pos) && pos >= 1) {
            moveProblemToPosition(problem, pos);
            // L'ordine deve persistere SENZA dover cliccare 💾: auto-save
            // silenzioso debounced delle scelte (include groupOrder).
            try { window.FM?.VerificaScelte?.autosave?.(); } catch (_) { /* no-op */ }
        }
        return;
    }
    // Phase 16 — reorder .fm-collection__item via input numerico (.move-position).
    if (t.classList.contains("fm-move-position")) {
        const item = t.closest(".fm-collection__item");
        const pos = parseInt(t.value, 10);
        if (item && Number.isFinite(pos) && pos >= 1) {
            moveItemToPosition(item, pos);
            try { window.FM?.VerificaScelte?.autosave?.(); } catch (_) { /* no-op */ }
        }
        return;
    }

    // G23.fix14 — Persist checkbox A/R a livello GRUPPO (.fm-groupcollex header).
    // Questi NON sono in .fm-collection__item, quindi vanno gestiti PRIMA dell'early
    // return su item.dataset.id. Persistenza in sessionStorage `fmv.group.<id>`.
    if (t.classList.contains("checkboxA") || t.classList.contains("checkboxB")) {
        const problem = t.closest(".fm-groupcollex");
        const problemId = problem?.id || null;
        const key = t.classList.contains("checkboxA") ? "A" : "B";
        // Phase 24.77 — evidenziazione pill via class-toggle (fallback robusto a
        // `:has(.checkbox:checked)` che in alcune build/cache non stila il
        // discendente). A → verde, R → arancione (CSS .labcheck.is-on-a/-b).
        const lbl = t.parentElement?.querySelector(".labcheck");
        if (lbl) lbl.classList.toggle(key === "A" ? "is-on-a" : "is-on-b", t.checked);
        if (problemId) saveState(`group.${problemId}`, key, t.checked);
        // Ricomputa totali punti (vanilla: updatePointsTotal accetta Element).
        if (problem) {
            const u = window.FM?.utilities;
            if (u?.updatePointsTotal) {
                try { u.updatePointsTotal(problem); } catch (_) { /* no-op */ }
            }
        }
        return;
    }

    const item = t.closest(".fm-collection__item");
    const id = item?.dataset?.id;
    if (!id) return;

    if (t.classList.contains("fm-checkbox-ain")) {
        saveState(id, "A", t.checked);
        item.classList.toggle("fmv-selected-A", t.checked);
        updatePointsTotalsFor(item);
    } else if (t.classList.contains("fm-checkbox-bin")) {
        saveState(id, "B", t.checked);
        item.classList.toggle("fmv-selected-B", t.checked);
        updatePointsTotalsFor(item);
    } else if (t.classList.contains("origin")) {
        // Phase 16 — origin change → patch contract-scoped su item.
        const newOrigin = t.value;
        item.className = `${item.className
            .split(/\s+/)
            .filter((c) => !c.startsWith("new_origin_") && !/^(mmb|pcf|cdm|fte|rdg|personal)/.test(c))
            .join(" ")
             } ${  newOrigin}`;
        saveState(id, "origin", newOrigin);
        rebuildBadgeForOrigin(item, newOrigin).catch(() => {});
        // Patch include badge.source_key: il renderer legge il libro/volume
        // da $it['badge']['source_key'], non da $it['source'] top-level.
        patchContractItem(item, {
            origin: newOrigin,
            source: newOrigin,
            badge: { source_key: newOrigin },
        });
        refreshHeaderPageCitations().catch(() => {});
    } else if (t.classList.contains("fm-color-select")) {
        saveState(id, "color", t.value);
        item.dataset.fmvColor = t.value;
        applyColorToCollexItem(item, t.value);
        patchContractItem(item, { color: t.value });
    } else if (t.classList.contains("fm-checkbox-rm")) {
        // G23.fix9 — Toggle "risposta corretta" su una RM cell. Solo teacher/admin.
        // Flip rm-correct class su parent td + patch options[idx].correct su server.
        if (!document.body.classList.contains("fm-admin-access")
            && !document.body.classList.contains("fm-teacher-access")) {
            return;
        }
        const td = t.closest("td.rm-option");
        if (!td) return;
        td.classList.toggle("rm-correct", t.checked);
        t.classList.toggle("solchecked", t.checked);
        // Capture current options[] from DOM cells + send patch
        _saveRmCorrectFlags(item);
    } else if (t.classList.contains("fm-rm-num") || t.classList.contains("fm-rm-text")) {
        // Phase 24.78 — valore-soluzione celle N/text: persiste su change (blur).
        // Solo teacher/admin (è la soluzione del docente).
        if (!document.body.classList.contains("fm-admin-access")
            && !document.body.classList.contains("fm-teacher-access")) {
            return;
        }
        if (!t.closest("td.rm-option")) return;
        _invalidateRmOptionsCache(item); // forza rebuild con il nuovo value
        _saveRmCorrectFlags(item);       // ricostruisce options (con value) + patch
    }
}

/** G23.fix11 — Helper centralizzato: estrai option contract format da `<td>`
 *  RM cell DOM. Pipeline standard: extractCellContent → fake textarea →
 *  _buildBlocksFromTextarea → _normalizeListSectionForCell. Riusato sia da
 *  _saveRmCorrectFlags (click checkbox) sia da _captureEditorFields (save
 *  panel). Unico punto di derivazione options[] da DOM. */
function _rmCellDomToOption(td, idx) {
    const cellHtml = _extractCellContentShared(td);
    const fakeTa = _makeEditableField();
    fakeTa.value = cellHtml;
    const contentBlocks = _buildBlocksFromTextarea(fakeTa);
    _normalizeListSectionForCell(contentBlocks);
    const op = {
        letter:  String.fromCharCode(97 + idx),
        correct: td.classList.contains("rm-correct"),
        content: contentBlocks,
    };
    // Phase 24.78 — valore-soluzione per colonne T (text) / N (number) / B
    // (button label). È l'unico modo per persistere ciò che il docente inserisce
    // negli input delle celle (bug: prima non veniva catturato → perso al save).
    const numInp = td.querySelector(".fm-rm-num");
    const txtInp = td.querySelector(".fm-rm-text");
    const btn    = td.querySelector(".fm-rm-btn");
    if (numInp) op.value = numInp.value;
    else if (txtInp) op.value = txtInp.value;
    else if (btn) { const v = btn.textContent.trim(); if (v && v !== "btn") op.value = v; }
    return op;
}

/** G24.refactor3 — Lazy-init + cache options[] su item per evitare N+1 parse
 *  ad ogni click checkbox. La cache è invalidata quando il content cell cambia
 *  (cell editor save) — vedi `_invalidateRmOptionsCache`.
 *
 *  Use cases:
 *    - Click checkbox correct → toggle flag locale + PATCH (no re-parse celle)
 *    - Save cell editor → invalidate → next click rebuild
 *
 *  Performance: N click checkbox = 1 build cache + N flip flag (vs N×O(N) parse). */
function _getRmOptionsCache(item) {
    if (!item) return null;
    if (Array.isArray(item._fmCachedOptions)) return item._fmCachedOptions;
    const tds = item.querySelectorAll(".fm-rm-table td.rm-option");
    if (!tds.length) return null;
    // Build cache una volta dal DOM corrente
    item._fmCachedOptions = Array.from(tds).map((td, i) => _rmCellDomToOption(td, i));
    return item._fmCachedOptions;
}

/** Invalidata quando cell content cambia: cleanup forza rebuild al prossimo
 *  _getRmOptionsCache. */
function _invalidateRmOptionsCache(item) {
    if (item) delete item._fmCachedOptions;
}

/** G23.fix9 + G24.refactor3 — Toggle correct flag in cache + PATCH server.
 *  Pre-fix: N click = N×O(N) DOM parse. Post-fix: 1 build cache + flip O(1)
 *  per click. Su save cell editor la cache viene invalidata cosi' il prossimo
 *  click ricostruisce con content aggiornato. */
function _saveRmCorrectFlags(item) {
    if (!item) return;
    const options = _getRmOptionsCache(item);
    if (!options) return;
    // Sync correct flag con DOM corrente (potrebbe essere cambiato da altri td flip)
    const tds = item.querySelectorAll(".fm-rm-table td.rm-option");
    options.forEach((op, i) => {
        if (tds[i]) op.correct = tds[i].classList.contains("rm-correct");
    });
    patchContractItem(item, { options });
}

/** Phase 16 — wrapper per patch a livello contract-item. Risolve l'URL
 *  contract-scoped dal wrapper `.fm-contract-wrap[data-id]` + passa la
 *  version dal `data-version` per optimistic locking. Skip silenzioso se il
 *  wrapper manca (es. contract-fallback legacy senza JSON). */
function patchContractItem(item, patch) {
    const op = contractOpUrl(item, "patch");
    if (!op) return;
    // G24.refactor2 — patch background (origin/color/checkbox correct):
    // silent retry su 409 senza dialog UI (l'utente non sta esplicitamente
    // salvando, è auto-persist su change → silent retry è UX corretto).
    apiPost(op.url, patch, { ifMatchVersion: op.version, silent: true });
}

/** Phase 16 — Topic color cycle (replica legacy UIComp._enforceTopicColorCycle).
 *  Per ogni .fm-groupcollex, i .fm-collection__item con STESSO titolo_quesito condividono
 *  colore; ogni nuovo topic avanza nel ciclo. Applicato al load/fm:navigated. */
// G24.refactor5.step7 — TOPIC_COLOR_CYCLE + applyColorToCollexItem +
// applyTopicColorCycle + rgbToColorName estratti in `editor/color-utils.js`.
// Re-export su window.FM per legacy debug/test (vedi linea ~8442).

function onCheckinInput(e) {
    const t = e.target;
    if (!t?.classList?.contains("fm-input-pt")) return;
    const item = t.closest(".fm-collection__item");
    const id = item?.dataset?.id;
    if (id) saveState(id, "pt", t.value);
    // G19 — port del legacy script_sel-mod.js: ricalcola .total-pointsA/B
    // del problema + #SumPtotA/B globale ad ogni edit del punteggio.
    updatePointsTotalsFor(item);
}

/** G19 — bridge modern → legacy utilities.updatePointsTotal($problem).
 *  utilities richiede un jQuery wrapper; lo costruiamo solo se jQuery è
 *  presente (sempre vero in admin context, dove il legacy module è
 *  caricato). Aggiorna .total-pointsA/B per il problema + #SumPtotA/B
 *  globale (cfr. utilities.js updatePointsTotal + updateGlobalPointsTotal).
 *
 *  Replica esatta della semantica legacy:
 *    $(document).on("change", ".checkboxA, .checkboxB, .fm-checkbox-ain, .fm-checkbox-bin", () => {
 *      if (Ain || Bin) utilities.updatePointsTotal(problem);
 *    });
 *    $(document).on("input change", ".fm-input-pt", () => utilities.updatePointsTotal(problem)); */
function updatePointsTotalsFor(item) {
    if (!item) return;
    const problem = item.closest(".fm-groupcollex");
    if (!problem) return;
    const u = window.FM?.utilities;
    if (u?.updatePointsTotal) {
        try { u.updatePointsTotal(problem); } catch (_) { /* no-op */ }
    }
}

async function onCheckinClick(e) {
    const t = e.target;
    if (!t?.closest) return;

    // Phase 24.77 — Stepper custom ▲▼ (BEM .fm-stepper__btn) generico per input
    // number (gli spinner nativi sono poco visibili nella toolbar scura). Usato
    // da `.fm-position` (step 1), riordino gruppo `.fm-move-position-problem`
    // (step 1) e VF total points (step 0.5). Legge step/min dall'input e
    // ridispaccia input+change come un edit manuale.
    const stepBtn = t.closest(".fm-stepper__btn");
    if (stepBtn) {
        e.preventDefault(); e.stopPropagation();
        const inp = stepBtn.closest(".fm-stepper")?.previousElementSibling;
        if (inp && inp.tagName === "INPUT") {
            const step = parseFloat(inp.step) || 1;
            const hasMin = inp.min !== "" && inp.min != null;
            const min = hasMin ? parseFloat(inp.min) : -Infinity;
            const cur = parseFloat(inp.value);
            const up = stepBtn.classList.contains("fm-stepper__btn--up");
            let next;
            if (!Number.isFinite(cur)) {
                // primo click da campo vuoto → min (o 0 se nessun min)
                next = hasMin ? min : 0;
            } else {
                // arrotonda alla griglia dello step per evitare derive floating point
                next = Math.round((cur + (up ? step : -step)) / step) * step;
                if (next < min) next = min;
            }
            const decimals = (String(step).split(".")[1] || "").length;
            inp.value = decimals ? next.toFixed(decimals) : String(next);
            inp.dispatchEvent(new Event("input", { bubbles: true }));
            inp.dispatchEvent(new Event("change", { bubbles: true }));
        }
        return;
    }

    // Phase 16 — gestione buttons a LIVELLO .fm-collapsible (.checkmod .editEser):
    //   .modificaBtn       → edit INTERO gruppo .fm-groupcollex (tutti i quesiti)
    //   .quick-saveBtn    → save inline quick
    //   .eliminaBtn        → delete INTERO gruppo
    const problemBtn = t.closest(".fm-checkmod .fm-modifica-btn, .fm-checkmod .fm-elimina-btn, .checkmod .fm-quick-save-btn, .checkmod .fm-close-btn");
    if (problemBtn) {
        e.preventDefault(); e.stopPropagation();
        const problem = problemBtn.closest(".fm-groupcollex");
        if (!problem) return;
        const isEditing = problem.dataset.fmEditing === "1";
        if (problemBtn.classList.contains("fm-modifica-btn")) {
            // Edit toggle: edit mode → flush autosave + close. Autosave già
            // persiste continuamente → "save" finale = solo chiusura.
            if (isEditing) _flushGroupAutosaveAndClose(problem);
            else toggleProblemEditMode(problem, true);
        } else if (problemBtn.classList.contains("fm-elimina-btn")) {
            if (await window.FM.Dialog.confirm(`Eliminare l'intero gruppo "${problem.querySelector(".fm-collapsible")?.firstChild?.textContent?.trim() || ""}"?`)) {
                deleteProblem(problem);
            }
        } else if (problemBtn.classList.contains("fm-quick-save-btn")) {
            _flushGroupAutosaveAndClose(problem);
        } else if (problemBtn.classList.contains("fm-close-btn")) {
            // Legacy fallback (closeBtn non più creato): revert via panel snapshot.
            revertGroupEditorChanges(problem);
        }
        return;
    }

    const item = t.closest(".fm-collection__item");
    if (!item) return;
    const id = item.dataset.id;

    const btn = t.closest(".fm-edit-q, .fm-move-up-btn, .fm-move-down-btn");
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();

    if (btn.classList.contains("fm-add-btn")) {
        // Phase 17 — duplicazione persistente via contract-scoped API.
        // Crea una copia del quesito (nuovo UUID) subito dopo l'originale,
        // salva nel contract JSON, clona il nodo DOM con il nuovo id.
        duplicateQuesito(item);
    } else if (btn.classList.contains("fm-clone")) {
        // Phase 17 — clone cross-file verifica → esercizi corrispondente.
        // Match su subject+topic. Se il gruppo parent esiste nell'esercizio
        // (stesso titolo) → append item; altrimenti clona l'intero gruppo.
        const inVerifica = !!item.closest('.fm-contract-wrap[data-kind="verifica"]')
                        || !!item.closest('#type_verAll');
        if (!inVerifica) {
            toast("Clone disponibile solo su esercizi di verifica", "info");
            return;
        }
        const proceed = await window.FM.Dialog.confirm(
            `Clonare il quesito nell'esercizio corrispondente (zona studenti)?`,
            { title: "Clona in esercizi", okLabel: "Continua", cancelLabel: "Annulla" });
        if (!proceed) return;
        // Scelta copyright-safe: cancel = "Solo fonte" (default), ok = "full".
        const full = await window.FM.Dialog.confirm(
            `Includere anche TRACCIA e SOLUZIONI?\n\nPer esercizi tratti da libri protetti da copyright scegli "Solo fonte": verrà copiato solo badge + riferimento bibliografico.`,
            { title: "Cosa copiare", okLabel: "Includi tutto", cancelLabel: "Solo fonte", kind: "warn" });
        cloneItemToEser(item, full ? "full" : "source");
    } else if (btn.classList.contains("fm-single-modifica-btn")) {
        // Edit toggle: in edit mode → flush autosave + close (autosave già
        // persiste continuamente, "save" finale = solo chiusura).
        if (item.dataset.fmItemEditing === "1") {
            const panel = item.querySelector(".fm-editor-panel");
            if (panel) _flushAutosaveAndClose(item, panel);
        } else {
            toggleEditMode(item, true);
        }
    } else if (btn.classList.contains("fm-single-quick-save-btn")) {
        const panel = item.querySelector(".fm-editor-panel");
        if (panel) _flushAutosaveAndClose(item, panel);
    } else if (btn.classList.contains("fm-remove-btn")) {
        // Un .fm-groupcollex non può rimanere senza .fm-collection__item (non ha senso un
        // gruppo esercizio vuoto): blocca la delete dell'ultimo item.
        const group = item.closest(".fm-groupcollex");
        const siblings = group ? group.querySelectorAll(".fm-collection__item") : [item];
        if (siblings.length <= 1) {
            toast("Impossibile eliminare l'unico quesito del gruppo", "warn");
            return;
        }
        if (await window.FM.Dialog.confirm(`Eliminare quesito #${id}?`)) deleteItem(item);
    } else if (btn.classList.contains("fm-move-up-btn")) {
        moveItem(item, -1);
    } else if (btn.classList.contains("fm-move-down-btn")) {
        moveItem(item, +1);
    }
}

/** Phase 17 — duplicazione server-side di un quesito.
 *  Flow:
 *    1. POST /api/teacher/content/{contractId}/quesito/{itemRef}/duplicate
 *    2. Response contiene `newId` (UUID generato dal server) + `version`.
 *    3. Clona il DOM, sostituisce `data-id` col nuovo id, inserisce after.
 *    4. Aggiorna `data-version` sul wrapper (optimistic locking next save).
 *    5. Rinumera `.move-position` via populatePositionInputs.
 *
 *  Fallback: se il wrapper non ha un contract-id numerico (synthetic),
 *  clonazione locale-only (compat legacy dev pages). */
async function duplicateQuesito(item) {
    const wrap = item.closest(".fm-contract-wrap");
    const contractId = wrap?.dataset.id;
    if (!/^\d+$/.test(contractId || "")) {
        // Fallback: clone locale senza persistenza
        const clone = item.cloneNode(true);
        clone.removeAttribute("data-id");
        clone.classList.add("fmv-cloned");
        item.after(clone);
        if (window.FMCollapsible?.recompute) {
            requestAnimationFrame(() => window.FMCollapsible.recompute());
            setTimeout(() => window.FMCollapsible.recompute(), 400);
        }
        toast("Quesito clonato (non persistito)", "info");
        return;
    }
    const itemRef = encodeURIComponent(item.dataset.id || "");
    const version = parseInt(wrap.dataset.version || "0", 10) || 0;
    const url = `/api/teacher/content/${contractId}/quesito/${itemRef}/duplicate`;
    const r = await apiPost(url, {}, { ifMatchVersion: version });
    if (!r?.ok) {
        toast("Duplicazione non riuscita", "err");
        return;
    }
    // Clone DOM con nuovo id
    const clone = item.cloneNode(true);
    clone.dataset.id = r.newId;
    clone.classList.remove("fmv-selected-A", "fmv-selected-B");
    // Reset checkbox A/R del clone (default unchecked)
    clone.querySelectorAll(".fm-checkbox-ain, .fm-checkbox-bin").forEach((c) => { c.checked = false; });
    item.after(clone);
    // Re-run helper per popolare move-position + origin + cycle colori
    try { window.FM?.populatePositionInputs?.(); } catch (_) {}
    try { window.FM?.populateOriginSelects?.(); } catch (_) {}
    // G22.S15 — il nuovo elemento DOM cresce il scrollHeight di .content
    // collapsible, ma maxHeight resta lockato → contenuto invisibile,
    // .fm-groupcollex non si espande. Forza recompute.
    if (window.FMCollapsible?.recompute) {
        requestAnimationFrame(() => window.FMCollapsible.recompute());
        setTimeout(() => window.FMCollapsible.recompute(), 400);
    }
    toast(`Quesito duplicato (#${r.newId.slice(0, 8)})`, "ok");
}

/** Phase 17 — cross-file clone verifica → esercizio corrispondente.
 *
 *  Match server-side su `subject_code + topic`. Se il gruppo parent esiste
 *  nell'esercizio (titolo uguale) → append item al gruppo. Se il gruppo NON
 *  esiste → clona tutto il gruppo (con solo questo item). Il server ritorna
 *  `{eserContentId, groupId, newItemId, createdGroup}`.
 */
async function cloneItemToEser(item, mode = "source") {
    const wrap = item.closest(".fm-contract-wrap");
    const contractId = wrap?.dataset.id;
    if (!/^\d+$/.test(contractId || "")) {
        toast("Clone cross-file richiede un contract DB", "warn");
        return;
    }
    const itemRef = encodeURIComponent(item.dataset.id || "");
    const safeMode = mode === "full" ? "full" : "source";
    const url = `/api/teacher/content/${contractId}/quesito/${itemRef}/clone-to-eser?mode=${safeMode}`;
    const r = await apiPost(url, {});
    if (!r?.ok) {
        const msg = r?.message || "Clone non riuscito (esercizio corrispondente non trovato?)";
        toast(msg, "err");
        return;
    }
    // Aggiorna il DOM col duplicato (se l'esercizio è in pagina); fallback reload.
    try {
        injectClonedItemToEser(item, r, safeMode);
    } catch (e) {
        console.error("clone-to-eser: inject DOM fallita → reload", e);
        location.reload();
        return;
    }
    const suffix = safeMode === "source" ? " (solo fonte)" : " (completo)";
    const label = r.createdGroup
        ? `Nuovo gruppo creato in esercizi${suffix} (#${String(r.newItemId).slice(0, 8)})`
        : `Quesito aggiunto al gruppo esistente${suffix} (#${String(r.newItemId).slice(0, 8)})`;
    toast(label, "ok");
}

/** Inietta nel DOM il quesito clonato dentro l'esercizio di destinazione, se
 *  presente in pagina (verifica correlata in `#type_verAll` sotto l'esercizio).
 *  Append chirurgico se il gruppo esiste già; reload come fallback robusto
 *  (gruppo nuovo creato server-side, esercizio non in pagina, container assente)
 *  così l'utente vede SEMPRE il risultato. */
function injectClonedItemToEser(srcItem, r, mode) {
    const eserWrap = document.querySelector(
        `.fm-contract-wrap[data-id="${r.eserContentId}"]:not([data-kind="verifica"])`);
    if (!eserWrap || r.createdGroup) { location.reload(); return; }
    const group = [...eserWrap.querySelectorAll(".fm-groupcollex")].find((g) => g.id === r.groupId);
    if (!group) { location.reload(); return; }
    const container = group.querySelector("ol.fm-collexercise") || group.querySelector(".Aff");
    if (!container) { location.reload(); return; }

    const node = srcItem.cloneNode(true);
    node.dataset.id = r.newItemId;
    node.classList.remove("fmv-selected-A", "fmv-selected-B");
    node.querySelectorAll(".fm-checkbox-ain, .fm-checkbox-bin").forEach((c) => { c.checked = false; });
    if (mode === "source") {
        redactItemToSource(node);
    }
    container.appendChild(node);
    eserWrap.dataset.version = r.eserVersion;

    if (window.FMCollapsible?.recompute) {
        requestAnimationFrame(() => window.FMCollapsible.recompute());
        setTimeout(() => window.FMCollapsible.recompute(), 400);
    }
    try { window.FM?.populatePositionInputs?.(); } catch (_) {}
    try { window.FM?.populateOriginSelects?.(); } catch (_) {}
    try { window.MathJax?.typesetPromise?.([node]); } catch (_) {}
}

/** Redazione "Solo fonte" sul nodo item clonato (copyright-safe): conserva
 *  badge + fonte (checkIN), sostituisce la traccia con il testo placeholder e
 *  le soluzioni con "...". Allineato a ContractRepository::stripItemToSource. */
function redactItemToSource(node) {
    const PLACEHOLDER = "Traccia e soluzioni reperibili nel testo in adozione";
    // Traccia (problema del libro): primo .fm-collection (non RM) → placeholder.
    const q = node.querySelector(".fm-collection:not(.collexTab)");
    if (q) q.innerHTML = '<p class="pt">' + PLACEHOLDER + "</p>";
    // Svolgimento del docente CONSERVATO; solo il contenuto dei <span class="dots">
    // (risultati/risposte finali) → "...".
    node.querySelectorAll("span.dots").forEach((s) => { s.textContent = "..."; });
}

/** Phase 17 — Edit mode a livello .fm-groupcollex (GROUP) — replica legacy
 *  `modificaBtn`: rende editabili `.fm-testo` (intro gruppo) + titolo
 *  `.fm-collapsible`, NON i body dei singoli quesiti (quello è
 *  `single-modificaBtn`). Snapshot del valore pre-edit su dataset per
 *  permettere "close without save". */
function toggleProblemEditMode(problem, on) {
    if (!problem) return;
    // Apertura/chiusura centralizzata via factory generica.
    // openGroupEditor + closeGroupEditor gestiscono icon swap (per essere
    // robusti anche quando chiamati direttamente da _flush/revert handlers).
    if (on) openGroupEditor(problem);
    else closeGroupEditor(problem);

    // Autosave bind/unbind via API generica EditorDraft.
    // Mountpoint badge status: dentro la `.checkmod` (header del gruppo).
    const groupRef = problem.id || "";
    const key = groupRef ? `group-${groupRef}` : `group-${Math.random().toString(36).slice(2)}`;
    if (on) {
        problem._fmGroupAutosaveKey = key;
        const statusContainer = problem.querySelector(".fm-checkmod") || problem.querySelector(":scope > .fm-collapsible") || problem;
        window.FM?.EditorDraft?.bindOn?.({
            key,
            watchEl: problem,
            statusContainer,
            saveFn: async () => {
                if (!window.FM?.EditorServerAutosave?.saveGroup) return false;
                return await window.FM.EditorServerAutosave.saveGroup(problem);
            },
            getFields: () => _captureGroupFields(problem),
        });
    } else if (problem._fmGroupAutosaveKey) {
        window.FM?.EditorDraft?.commit?.(problem._fmGroupAutosaveKey);
        delete problem._fmGroupAutosaveKey;
    }
}

/** Factory generica per montare un editor inline `.fm-editor-panel`.
 *  Centralizza la struttura UI (header + sections + actions) condivisa
 *  da item editor (collex-item) e group editor (problem).
 *
 *  @param {Element} mountTarget — Element dove appendere il panel
 *  @param {Object} opts
 *  @param {string} opts.headerLabel — Es. "Editor — Collect" / "Editor — Gruppo"
 *  @param {Array<{label:string, value:string}>} opts.sections — Sections via buildSection
 *  @param {string} opts.autosaveKey — Chiave bind autosave (es. "item-X" / "group-Y")
 *  @param {Function} opts.saveFn — async () => bool — server save silenzioso
 *  @param {Function} opts.captureFn — () => fields — per IDB backup
 *  @param {Function} [opts.applyFn] — (fields) => void — applica al display post-save (default: noop)
 *  @param {Function} [opts.onClose] — callback alla chiusura panel
 *  @param {Element} [opts.insertBefore] — riferimento per insertBefore (default: appendChild)
 *  @param {string} [opts.lockKey] — G24.refactor4: chiave lock multi-tab cooperativo
 *  @param {string} [opts.lockKind] — G24.refactor4: label per toast (es. "Quesito" / "Gruppo")
 *  @returns {{panel: Element, snapshot: Object}}
 */
function mountInlineEditor(mountTarget, opts) {
    // G23.fix10 — Centralizza ensureGlobalToolbar: TUTTI gli inline editor
    // (item + group) attivano la toolbar globale. Prima solo openItemEditor
    // la chiamava → group editor (apertura via .modificaBtn) non aveva
    // toolbar attiva. Mount factory è il punto unico → fix one-shot.
    ensureGlobalToolbar();

    const panel = document.createElement("div");
    panel.className = "fm-editor-panel";
    panel.style.cssText =
        "margin:8px 0;padding:10px;background:#fffbe6;border:2px dashed #b58900;border-radius:6px;position:relative;display:block;width:100%;box-sizing:border-box;";

    // Header con close X
    const header = document.createElement("div");
    header.className = "fm-editor-panel-header";
    header.style.cssText = "font:600 13px system-ui;color:#7a5b00;margin-bottom:6px;display:flex;justify-content:space-between;align-items:center;gap:8px";
    const headerLeft = document.createElement("div");
    headerLeft.style.cssText = "display:flex;align-items:center;gap:8px;flex:1";
    const headerTxt = document.createElement("span");
    headerTxt.textContent = opts.headerLabel || "Editor";
    headerLeft.appendChild(headerTxt);
    const closeX = document.createElement("button");
    closeX.type = "button";
    closeX.textContent = "×";
    closeX.title = "Chiudi";
    closeX.style.cssText = "background:transparent;border:none;font-size:20px;cursor:pointer;color:#7a5b00;padding:0 6px;line-height:1";
    closeX.addEventListener("click", () => opts.onClose?.());
    header.appendChild(headerLeft);
    header.appendChild(closeX);
    panel.appendChild(header);

    // G23.fix11 — Section providers polymorphic:
    //   - `{label, value, field?}` → buildSection (con field key esplicito opt)
    //   - `Function` → call returning Element
    //   - `Element` → appendChild diretto
    // G23.fix17 — opt `field` separa data-field da label (es. label
    // "TITOLO GRUPPO" + field "fm-titolo").
    for (const s of (opts.sections || [])) {
        let el = null;
        if (typeof s === "function") {
            el = s();
        } else if (s instanceof Element) {
            el = s;
        } else if (s && typeof s.label === "string") {
            el = buildSection(s.label, s.value ?? "", s.field || null);
        }
        if (el) panel.appendChild(el);
    }

    // Action buttons
    const actions = document.createElement("div");
    actions.style.cssText = "display:flex;gap:8px;margin-top:10px;justify-content:flex-end";
    const cancelBtn = document.createElement("button");
    cancelBtn.type = "button";
    cancelBtn.textContent = "Annulla modifiche";
    cancelBtn.title = "Ripristina lo stato precedente all'apertura dell'editor";
    cancelBtn.style.cssText = "padding:6px 12px;background:#ddd;border:1px solid #aaa;border-radius:4px;cursor:pointer";
    cancelBtn.addEventListener("click", () => opts.onRevert?.());
    const closeBtn = document.createElement("button");
    closeBtn.type = "button";
    closeBtn.textContent = "Chiudi";
    closeBtn.title = "Le modifiche sono già state salvate automaticamente";
    closeBtn.style.cssText = "padding:6px 12px;background:#2a5ac7;color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:600";
    closeBtn.addEventListener("click", () => opts.onClose?.());
    actions.appendChild(cancelBtn);
    actions.appendChild(closeBtn);
    panel.appendChild(actions);

    // Mount (insertBefore se fornito, altrimenti appendChild)
    if (opts.insertBefore && opts.insertBefore.parentNode === mountTarget) {
        mountTarget.insertBefore(panel, opts.insertBefore);
    } else {
        mountTarget.appendChild(panel);
    }

    // Focus prima textarea + snapshot pre-edit
    let snapshot = null;
    setTimeout(() => {
        panel.querySelector(".fm-editor-field")?.focus();
        snapshot = opts.captureFn?.();
        panel._fmPreEditSnapshot = snapshot;
    }, 100);

    // Autosave bind via API generica
    if (opts.autosaveKey && opts.saveFn) {
        window.FM?.EditorDraft?.bindOn?.({
            key: opts.autosaveKey,
            watchEl: panel,
            statusContainer: headerLeft, // fallback solo se non c'è topbar
            saveFn: opts.saveFn,
            getFields: opts.captureFn,
        });
    }

    // G24.refactor4 — Multi-tab cooperative lock acquisition centralizzata.
    // Storia: prima ogni open*Editor copiava il pattern acquireLock + callbacks
    // freeze/unfreeze (toast warn + opacity .6 + dataset fmLockedByOther).
    // Ora la factory lo fa una volta sola e annota panel.dataset.fmLockKey,
    // così `_unmountInlineEditor` può rilasciare al close.
    if (opts.lockKey) {
        panel.dataset.fmLockKey = opts.lockKey;
        const kindLabel = opts.lockKind || "Editor";
        _acquireEditorLock(opts.lockKey, {
            onLockedByOther: () => {
                if (typeof toast === "function") {
                    toast(`⚠ ${kindLabel} aperto in un altro tab. Editing locale ignorato fino a chiusura altro tab.`, "warn");
                }
                panel.dataset.fmLockedByOther = "1";
                panel.style.opacity = "0.6";
            },
            onReleased: () => {
                delete panel.dataset.fmLockedByOther;
                panel.style.opacity = "";
            },
        });
    }

    return { panel, getSnapshot: () => panel._fmPreEditSnapshot };
}

/** G24.refactor4 — Smonta panel inline editor + rilascia lock multi-tab.
 *  Helper unico per closeItemEditor / closeGroupEditor: legge `data-fm-lock-key`
 *  dal panel, chiama releaseLock, rimuove DOM. Evita di richiedere alla close*
 *  di conoscere la lockKey schema (gestita internamente dal panel.dataset). */
function _unmountInlineEditor(panel) {
    if (!panel) return;
    const lockKey = panel.dataset?.fmLockKey;
    if (lockKey) _releaseEditorLock(lockKey);
    panel.remove();
}

/** Sync icon/title del modificaBtn group-level. Chiamato da open/close per
 *  garantire UI coerente anche quando handlers (_flush, revert) chiudono il
 *  panel saltando toggleProblemEditMode. */
function _syncGroupToolbarUI(problem, editing) {
    const m = problem.querySelector(".fm-checkmod .fm-modifica-btn");
    if (!m) return;
    const img = m.querySelector("img");
    if (img) {
        img.src = editing ? "/img/close.svg" : "/img/edit.svg";
        img.alt = editing ? "Chiudi" : "Modifica";
    }
    m.title = editing
        ? "Chiudi editor (modifiche salvate automaticamente)"
        : "Modifica tipologia";
}

/** Apri editor inline del gruppo (titolo + testo) usando la factory generica. */
function openGroupEditor(problem) {
    if (!problem) return;
    if (problem.querySelector(":scope > .fm-editor-panel")) return; // già aperto

    // Estrai titolo + intro originali dal DOM render.
    // G23.fix4 — Intro: load come HTML (preserve nested OL, fm-text raw,
    // TikZ markers) via FieldSerializer.loadFieldHtml. Mirror della logica
    // usata per quesito/giustificazione/soluzione/celle. textContent legacy
    // strippava nested OL → ogni save round-trip flatten.
    const coll = problem.querySelector(":scope > .fm-collapsible");
    const titleText = coll ? [...coll.childNodes]
        .filter((n) => n.nodeType === Node.TEXT_NODE)
        .map((n) => n.textContent).join("").trim() : "";
    const testoEl = problem.querySelector(":scope > .content .fm-testo > div")
                 || problem.querySelector(":scope > .content .fm-testo");
    const introText = FieldSerializer.loadFieldHtml(testoEl);
    // G23.fix16 — Field separato `giustifica` per gruppi VF/RM.
    const giustText = FieldSerializer.loadGiustificaText(testoEl);
    const groupType = problem.dataset?.type || "";
    const isVfOrRm = /^(type_)?(VF|RM)/i.test(groupType);

    // Hide originali durante edit (panel inline mostra le sezioni)
    if (coll) coll.dataset.fmGroupEditHide = "1";
    if (testoEl) {
        const wrap = testoEl.closest(".fm-testo") || testoEl;
        wrap.dataset.fmGroupEditHide = "1";
        wrap.style.display = "none";
    }

    const groupRef = problem.id || `g${Array.from(problem.parentNode?.children || []).indexOf(problem)}`;
    const autosaveKey = `group-${groupRef}`;
    problem._fmGroupAutosaveKey = autosaveKey;

    // G23.fix17 — Sezioni: "Titolo Gruppo" + "Testo" + "Giustifica" (VF/RM).
    // `field` esplicito mantiene data-field compatibile con _captureGroupFields
    // (key === "fm-titolo" / "fm-testo" / "giustifica").
    const sections = [
        { label: "Titolo gruppo", field: "titolo", value: titleText },
        { label: "Testo",         field: "testo",  value: introText },
    ];
    if (isVfOrRm) {
        sections.push({
            label: "Giustifica",
            field: "giustifica",
            value: giustText || "Giustifica adeguatamente le risposte",
        });
    }

    // Panel posizionato SUBITO PRIMA di `.content` (sopra agli item), così
    // l'editor sta nel flusso naturale del gruppo invece che in fondo.
    const contentEl = problem.querySelector(":scope > .content");
    mountInlineEditor(problem, {
        headerLabel: "Editor — Gruppo",
        sections,
        autosaveKey,
        saveFn: async () => {
            if (!window.FM?.EditorServerAutosave?.saveGroup) return false;
            return await window.FM.EditorServerAutosave.saveGroup(problem);
        },
        captureFn: () => _captureGroupFields(problem),
        onRevert: () => revertGroupEditorChanges(problem),
        onClose: () => _flushGroupAutosaveAndClose(problem),
        insertBefore: contentEl,
        // G24.refactor4 — Lock multi-tab centralizzato nella factory
        lockKey: autosaveKey,
        lockKind: "Gruppo",
    });

    problem.dataset.fmEditing = "1";
    _syncGroupToolbarUI(problem, true);

    // G24.faseD — Annota GroupEditorSession sul problem
    const session = new GroupEditorSession(problem, {
        lockKey: autosaveKey,
        capture: () => _captureGroupFields(problem),
        save: async () => {
            if (!window.FM?.EditorServerAutosave?.saveGroup) return false;
            return await window.FM.EditorServerAutosave.saveGroup(problem);
        },
    });
    const groupPanel = problem.querySelector(":scope > .fm-editor-panel");
    if (groupPanel) session.mount(groupPanel);
}

/** Chiudi editor gruppo: rimuove panel, restore display, sync UI toolbar. */
function closeGroupEditor(problem) {
    if (!problem) return;
    // G24.faseD — Notifica session se presente
    EditorSession.for(problem)?.unmount();
    // G24.refactor4 — _unmountInlineEditor gestisce release lock + remove DOM
    _unmountInlineEditor(problem.querySelector(":scope > .fm-editor-panel"));
    // Restore display originali (testo + collapsible)
    problem.querySelectorAll("[data-fm-group-edit-hide]").forEach((el) => {
        el.style.removeProperty("display");
        delete el.dataset.fmGroupEditHide;
    });
    problem.dataset.fmEditing = "";
    _syncGroupToolbarUI(problem, false);
}

/** G23.fix4 — Cattura fields editabili del gruppo (titolo + intro) come
 *  PATCH payload. Intro USES BLOCKS (uniforme con quesito/giustificazione/
 *  soluzione/celle): parsed via FieldSerializer.captureFieldBlocks → array
 *  di blocchi contract schema. Titolo resta plain text.
 *
 *  Sorgente: editor panel se attivo, altrimenti display DOM (fallback). */
function _captureGroupFields(problem) {
    if (!problem) return null;
    const panel = problem.querySelector(":scope > .fm-editor-panel");
    if (panel) {
        const fields = {};
        panel.querySelectorAll(".fm-editor-field").forEach((ta) => {
            const key = ta.dataset.field;
            if (key === "titolo")          fields.title = FieldSerializer.captureFieldText(ta);
            else if (key === "testo")      fields.intro = FieldSerializer.captureFieldBlocks(ta);
            // G23.fix16 — Field separato 'giustifica' per VF/RM. Plain text.
            else if (key === "giustifica") fields.giustifica = FieldSerializer.captureFieldText(ta);
        });
        return fields;
    }
    // Fallback: legge dal display (caso panel non più presente).
    // Intro: blocks da DOM via _buildBlocksFromTextarea su clone con HTML
    // estratto via _extractRawWithTikz (preserva nested OL).
    const testoEl = problem.querySelector(".fm-testo > div") || problem.querySelector(".fm-testo");
    let intro = [];
    if (testoEl) {
        const html = FieldSerializer.loadFieldHtml(testoEl);
        // Wrap in temp contenteditable-like per _buildBlocksFromTextarea
        const tmp = _makeEditableField();
        tmp.value = html;
        intro = FieldSerializer.captureFieldBlocks(tmp);
    }
    const title = problem.querySelector(".fm-collapsible .fm-title-edit")?.textContent?.trim()
        || problem.querySelector(":scope > .fm-collapsible")?.firstChild?.textContent?.trim()
        || "";
    return { title, intro };
}

/** G23.fix4 — Apply fields al display del gruppo (post-save autosave).
 *  Intro: render array di blocks via FieldSerializer.blocksToHtml (mantiene
 *  nested OL + fm-text/fm-latex spans). textContent legacy strippava HTML. */
// G24.faseD-migration — Group appliers registrati nel registry centrale
// (kind=group). Ogni applier mutate `fm-groupcollex` DOM per il field specifico.
EditorSession.registerApplier("group", "title", (problem, val) => {
    const coll = problem.querySelector(":scope > .fm-collapsible");
    if (coll) {
        [...coll.childNodes].filter((n) => n.nodeType === Node.TEXT_NODE).forEach((n) => n.remove());
        coll.insertBefore(document.createTextNode(`${val  } `), coll.firstChild);
    }
});
EditorSession.registerApplier("group", "intro", (problem, val) => {
    const inner = problem.querySelector(":scope > .content .fm-testo > div");
    if (!inner) return;
    // G23.fix16 — Render intro blocks → HTML. Giustifica è ora field
    // SEPARATO (fields.giustifica), non più dentro intro. Preserva
    // span.giustifica esistente nel DOM (sarà aggiornato sotto se
    // fields.giustifica presente nel patch).
    const existingGiust = inner.querySelector(".fm-giustifica")?.outerHTML || "";
    const html = FieldSerializer.blocksToHtml(val);
    inner.innerHTML = html + (existingGiust ? ` ${  existingGiust}` : "");
    if (window.MathJax?.typesetPromise) {
        window.MathJax.typesetPromise([inner]).catch(() => {});
    }
});
EditorSession.registerApplier("group", "giustifica", (problem, val) => {
    // G23.fix16 — Aggiorna span.giustifica con custom text se patch contiene
    // fields.giustifica. Default "Giustifica adeguatamente le risposte".
    const inner = problem.querySelector(":scope > .content .fm-testo > div");
    if (!inner) return;
    let giustSpan = inner.querySelector(".fm-giustifica");
    if (!giustSpan) {
        giustSpan = document.createElement("span");
        giustSpan.className = "fm-giustifica";
        inner.appendChild(document.createTextNode(" "));
        inner.appendChild(giustSpan);
    }
    const text = String(val || "Giustifica adeguatamente le risposte");
    giustSpan.textContent = ` ${  text}`;
});

/** Group fields applier: delega a session registry. Legacy signature
 *  conserva back-compat per call site fuori session context (revert,
 *  flush close). */
function _applyGroupFieldsToDom(problem, fields) {
    if (!problem || !fields) return;
    const session = EditorSession.for(problem);
    if (session) {
        session.applyToDOM(fields);
    } else {
        // Fallback diretto al registry (per call site senza session attiva,
        // es. revertGroupEditorChanges dopo unmount). Costruisco un proxy
        // session-like per applyToDOM.
        const proxy = { target: problem, kind: "group" };
        EditorSession.prototype.applyToDOM.call({
            target: problem, kind: "group",
        }, fields);
    }
}

/** Revert: PATCH server con snapshot pre-edit + close. */
async function revertGroupEditorChanges(problem) {
    const panel = problem.querySelector(":scope > .fm-editor-panel");
    const snapshot = panel?._fmPreEditSnapshot;
    if (!snapshot) {
        closeGroupEditor(problem);
        return;
    }
    if (!await window.FM.Dialog.confirm("Annullare tutte le modifiche del gruppo fatte in questa sessione?")) return;
    const ref = _resolveGroupRef(problem);
    if (ref) {
        const url = `/api/teacher/content/${ref.contractId}/group/${encodeURIComponent(ref.groupRef)}/patch`;
        const r = await apiPost(url, snapshot, { ifMatchVersion: ref.version });
        if (r?.ok) {
            _applyGroupFieldsToDom(problem, snapshot);
            toast("Modifiche annullate", "ok");
        }
    }
    if (problem._fmGroupAutosaveKey) {
        window.FM?.EditorDraft?.commit?.(problem._fmGroupAutosaveKey);
        delete problem._fmGroupAutosaveKey;
    }
    closeGroupEditor(problem);
}

/** Flush autosave + commit + close (Chiudi button del group editor).
 *  G23.fix12 — Cancella timer pendente prima del save manuale (race-safe). */
async function _flushGroupAutosaveAndClose(problem) {
    const draftApi = window.FM?.EditorDraft;
    const autoKey = problem._fmGroupAutosaveKey;
    if (autoKey) draftApi?.cancelScheduled?.(autoKey);
    try {
        if (window.FM?.EditorServerAutosave?.saveGroup) {
            await window.FM.EditorServerAutosave.saveGroup(problem);
        }
    } catch (_) { /* silenzioso */ }
    // Applica fields finali al display
    const fields = _captureGroupFields(problem);
    if (fields) _applyGroupFieldsToDom(problem, fields);
    if (autoKey) {
        draftApi?.commit?.(autoKey);
        delete problem._fmGroupAutosaveKey;
    }
    closeGroupEditor(problem);
}

/** Risolve groupRef per il PATCH endpoint. */
function _resolveGroupRef(problem) {
    const wrap = problem.closest(".fm-contract-wrap");
    const contractId = wrap?.dataset.id;
    if (!wrap || !/^\d+$/.test(contractId || "")) return null;
    let groupRef = problem.id || "";
    if (!groupRef) {
        const problems = Array.from(wrap.querySelectorAll(".fm-groupcollex"));
        const gi = problems.indexOf(problem);
        if (gi < 0) return null;
        groupRef = `g${gi}`;
    }
    return { contractId, groupRef, version: parseInt(wrap.dataset.version || "0", 10) || 0 };
}

/** Server save silenzioso per group editor (autosave Google Docs-style).
 *  Mirror di saveProblemEdits ma senza toast / no toggleProblemEditMode(false). */
async function _saveGroupEditInPlace(problem) {
    const fields = _captureGroupFields(problem);
    if (!fields) return false;
    const ref = _resolveGroupRef(problem);
    if (!ref) return false;
    const url = `/api/teacher/content/${ref.contractId}/group/${encodeURIComponent(ref.groupRef)}/patch`;
    // Server endpoint accetta { title, intro } direttamente
    const r = await apiPost(url, fields, { ifMatchVersion: ref.version });
    return !!r?.ok;
}

async function deleteProblem(problem) {
    // Phase 20 — delete gruppo persistente via
    // POST /api/teacher/content/{id}/group/{groupRef}/delete.
    // groupRef = problem.id (UUID dal ContractRenderer) oppure `g<gi>` via
    // DOM position come fallback.
    const wrap = problem.closest(".fm-contract-wrap");
    const contractId = wrap?.dataset.id;
    if (!wrap || !/^\d+$/.test(contractId || "")) {
        problem.remove();
        toast("Gruppo rimosso localmente (contract synthetic)", "info");
        return;
    }
    let groupRef = problem.id || "";
    if (!groupRef) {
        const problems = Array.from(wrap.querySelectorAll(".fm-groupcollex"));
        const gi = problems.indexOf(problem);
        if (gi < 0) { toast("Gruppo non individuabile", "warn"); return; }
        groupRef = `g${gi}`;
    }
    const version = parseInt(wrap.dataset.version || "0", 10) || 0;
    const url = `/api/teacher/content/${contractId}/group/${encodeURIComponent(groupRef)}/delete`;
    const r = await apiPost(url, {}, { ifMatchVersion: version });
    if (r?.ok) {
        problem.remove();
        toast("Gruppo eliminato", "ok");
    } else if (r?.skipped) {
        problem.remove();
        toast("Gruppo rimosso localmente", "info");
    } else {
        problem.style.opacity = "0.3";
        toast("Eliminazione gruppo differita", "warn");
    }
}

/** Toggle edit mode per-quesito.
 *
 * Strategia Phase 16:
 *   - Raw LaTeX/text è in `data-raw` dei .fm-latex/.fm-text (preservato dal
 *     renderer prima di MathJax typeset).
 *   - Apre editor inline type-aware: VF (risposta+giust), RM (opzioni+giust),
 *     Collect (soluzione). Fallback generico: textarea full raw.
 *   - Sostituisce .fm-collection/.wrapsolVF/.rm-options con textarea, save triggerra
 *     API POST (stub) + rebuild DOM + MathJax.typesetPromise.
 */
function toggleEditMode(item, on) {
    // Phase 18 — replica modificaBtn group-level: swap icona edit.svg ↔
    // save.svg sullo stesso btn .single-modificaBtn. Niente più
    // .single-quick-saveBtn separato (redundante: la modifica stessa
    // diventa save al secondo click).
    const modBtn = item.querySelector(".fm-edit-q.fm-single-modifica-btn");
    if (modBtn) {
        const img = modBtn.querySelector("img");
        if (img) {
            img.src = on ? "/img/close.svg" : "/img/edit.svg";
            img.alt = on ? "Chiudi" : "Modifica";
        }
        modBtn.title = on
            ? "Chiudi editor (modifiche salvate automaticamente)"
            : "Modifica quesito";
    }
    const quickSave = item.querySelector(".fm-single-quick-save-btn");
    if (quickSave) quickSave.style.display = "none"; // sempre nascosto post-Phase 18

    item.dataset.fmItemEditing = on ? "1" : "";

    if (on) {
        openItemEditor(item);
    } else {
        closeItemEditor(item);
    }
}

/** Costruisce editor inline in base al tipo di gruppo (.fm-groupcollex[data-type]). */
function openItemEditor(item) {
    if (item.querySelector(".fm-editor-panel")) return; // già aperto
    const problem = item.closest(".fm-groupcollex");
    // Phase 20 — data-type viene dal contract JSON come valore raw
    // (es. "type_VF", "type_RMulti", "type_Collect"). Normalizza alle 3
    // famiglie logiche: VF | RM | Collect. Senza questo, type_RMulti
    // finiva nel branch Collect, mostrando il solo editor
    // Quesito+Soluzione invece del pannello RM tabelle.
    const rawType = problem?.dataset?.type || "Collect";
    const groupType = /^(type_)?VF/i.test(rawType) ? "VF"
                    : /^(type_)?RM/i.test(rawType) ? "RM"
                    : "Collect";
    // Phase 16 — marca il .fm-groupcollex per il sticky comportamento (CSS:
    // Il sticky stacking di `.fm-collapsible.active` è gestito dal modulo
    // `verifica-sticky.js` (replica legacy updateStickyTops). La classe
    // `fm-problem-editing` resta utile come marker visivo per il `.fm-groupcollex`
    // attualmente in editing.
    if (problem) problem.classList.add("fm-problem-editing");

    // G23.fix11 — Refactor: openItemEditor delega a `mountInlineEditor` factory
    // (stesso pattern di openGroupEditor). Elimina ~85 LOC di duplicazione UI
    // (panel/header/closeX/actions/cancel/close). Section providers polymorphic
    // permettono sezioni custom (metadata, RM layout, VF radio) accanto al
    // builder standard `{label, value}` di buildSection.
    const collex = item.querySelector(".fm-collection");
    const quesitoRaw = _extractRawWithTikz(collex);

    // Type-specific section providers
    const sections = [
        () => buildMetadataSection(item),
        { label: "Quesito", value: quesitoRaw },
    ];
    if (groupType === "VF") {
        const ans = item.querySelector(".fm-wrapsol-vf .fm-sol")?.textContent?.trim() || "";
        sections.push(
            () => buildRadioSection("Risposta", ["V", "F"], ans.startsWith("V") ? "V" : ans.startsWith("F") ? "F" : ""),
            { label: "Giustificazione", value: _extractRawWithoutLabel(item.querySelector(".fm-giustsol")) || "" },
        );
    } else if (groupType === "RM") {
        const firstTable = item.querySelector(".fm-rm-table");
        const existingCells = item.querySelectorAll(".fm-rm-table > tbody > tr > td, .fm-rm-table > tr > td").length;
        sections.push(
            () => buildRmLayoutSection(firstTable, existingCells, item),
            { label: "Giustificazione", value: _extractRawWithoutLabel(item.querySelector(".fm-giustsol")) || "" },
        );
    } else {
        const sol = item.querySelector(".fm-sol");
        sections.push({ label: "Soluzione", value: _extractRawWithoutLabel(sol) || "" });
    }

    const { panel } = mountInlineEditor(item, {
        headerLabel: `Editor — ${groupType}`,
        sections,
        autosaveKey: `item-${item.dataset.id}`,
        saveFn: async () => _saveItemEditorInPlace(item, panel),
        captureFn: () => _captureEditorFields(panel),
        onRevert: () => revertEditorChanges(item, panel),
        onClose: () => _flushAutosaveAndClose(item, panel),
        // G24.refactor4 — Lock multi-tab centralizzato nella factory
        lockKey: item.dataset.id ? `item-${item.dataset.id}` : null,
        lockKind: "Quesito",
    });

    // G24.faseD — Annota EditorSession sull'item (per future introspection
    // + applier registry). State machine encapsulato; le legacy function
    // (_saveItemEditorInPlace, revertEditorChanges) restano back-compat.
    const session = new ItemEditorSession(item, {
        lockKey: item.dataset.id ? `item-${item.dataset.id}` : null,
        capture: () => _captureEditorFields(panel),
        save: async () => _saveItemEditorInPlace(item, panel),
    });
    session.mount(panel);

    // Post-mount: snapshot pre-edit (mountInlineEditor lo fa via timeout 100ms,
    // ma openItemEditor history espone `item._fmPreEditSnapshot` come proprietà
    // dell'item per revertEditorChanges che legge da lì). Mantengo signature.
    setTimeout(() => {
        item._fmPreEditSnapshot = _captureEditorFields(panel);
    }, 100);

    // G22.S15 — recompute collapsible scrollHeight post-mount
    if (window.FMCollapsible?.recompute) {
        requestAnimationFrame(() => window.FMCollapsible.recompute());
        setTimeout(() => window.FMCollapsible.recompute(), 800);
    }

    // G19.14 — draft recovery (item-specific via item.dataset.id)
    const draftApi = window.FM?.EditorDraft;
    if (draftApi && item.dataset.id) {
        draftApi.recover(panel, item.dataset.id);
    }
}

function closeItemEditor(item) {
    // G24.refactor4 — _unmountInlineEditor gestisce release lock + remove DOM
    // G24.faseD — Notifica EditorSession se presente
    EditorSession.for(item)?.unmount();
    _unmountInlineEditor(item.querySelector(".fm-editor-panel"));
    // Phase 16 — rimuovi sticky class dal .fm-groupcollex se non ci sono altri editor
    // attivi dentro di esso.
    const problem = item.closest(".fm-groupcollex");
    if (problem && !problem.querySelector(".fm-editor-panel")) {
        problem.classList.remove("fm-problem-editing");
    }
    // G22.S15 — la rimozione del pannello fa shrink scrollHeight; recompute
    // per restringere il maxHeight del .content collapsible.
    if (window.FMCollapsible?.recompute) {
        requestAnimationFrame(() => window.FMCollapsible.recompute());
    }
    // Se non c'è nessun altro panel aperto → nascondi toolbar globale
    if (!document.querySelector(".fm-editor-panel")) {
        const tb = document.getElementById("fm-editor-toolbar-global");
        if (tb) tb.style.display = "none";
        requestAnimationFrame(() => window.FM?.updateStickyTops?.());
    }
}

/** Crea (idempotente) la toolbar globale subito dopo #scrollbarInfo/#infoVer.
 *  Visibile solo quando esiste almeno un `.fm-editor-panel` aperto.
 *  Focus tracker globale: il panel ultimo-attivo riceve le azioni. */
function ensureGlobalToolbar() {
    let tb = document.getElementById("fm-editor-toolbar-global");
    if (!tb) {
        tb = buildGlobalToolbar();
        tb.id = "fm-editor-toolbar-global";
        // Inserisci subito dopo #scrollbarInfo (che contiene #infoVer) se presente,
        // altrimenti al top di #fm-content.
        const anchor = document.getElementById("scrollbarInfo") || document.getElementById("infoVer");
        if (anchor?.parentNode) {
            anchor.parentNode.insertBefore(tb, anchor.nextSibling);
        } else {
            const content = document.getElementById("fm-content") || document.body;
            content.insertBefore(tb, content.firstChild);
        }
    }
    tb.style.display = "";
    // Ricomputa stacking: toolbar ora in sticky stack (sopra h1 e collapsible).
    requestAnimationFrame(() => window.FM?.updateStickyTops?.());
}

/** Raccoglie la sorgente raw (data-raw) dai nodi MathJax-compiled, fallback
 *  textContent. Include .fm-badge (che ora è emesso come \(...\) LaTeX block
 *  con data-raw preservato). */
function collectRaw(nodes) {
    const parts = Array.from(nodes || []).map((n) => {
        const raw = n.dataset?.raw;
        if (raw !== undefined && raw !== "") return raw;
        // Fallback: per nodi senza data-raw, prendi textContent pulito da duplicati MathJax.
        // MathJax inietta mjx-assistive-mml duplicato → rimuovi.
        const clone = n.cloneNode(true);
        clone.querySelectorAll("mjx-assistive-mml, mjx-container > *:not(mjx-math)").forEach(x => x.remove());
        return (clone.textContent || "").trim();
    }).filter(Boolean);
    return parts.join(" ");
}

/**
 * Crea un `<div contenteditable>` con shim API `value` / selectionStart /
 * selectionEnd / setSelectionRange / dispatchEvent("input"), drop-in
 * compatibile con codice che assume textarea-like API.
 *
 * Differenza chiave: l'HTML inserito (`<ol>`, `<b>`, ecc.) viene RESO
 * visivamente nel browser, non mostrato come testo grezzo. I tag `<script
 * type="text/tikz">` e `<svg>` SONO catturati come HTML inerte (browser
 * non esegue script con type custom; svg renderizzato OK).
 *
 * NB: `selectionStart/selectionEnd` sono offset in `textContent` (caret
 * position visiva). `value` getter/setter agisce su `innerHTML`. Per
 * `value.slice(start, end)` calling code, il textContent può divergere
 * dall'innerHTML — gli inserimenti via `_insertAtCaret(html)` usano Range
 * API per inserire HTML alla caret position senza string-slicing.
 */
// G24.refactor5.step8a — _makeEditableField estratto in
// `editor/editable-field-factory.js`. Alias underscore preserva i call-site.
const _makeEditableField = makeEditableField;

// G24.refactor5.step3 — Caret/selection utils estratti in `editor/caret-utils.js`.
// Alias underscore mantengono i call-site originali del monolite.
// G24.cleanup — _ceCaretOffset, _ceSetCaret rimossi (dead alias).
const _ceSelectRange      = ceSelectRange;
const _caretAtNodeStart   = caretAtNodeStart;
const _caretAtNodeEnd     = caretAtNodeEnd;
const _placeCaretAtStart  = placeCaretAtStart;
const _placeCaretAtEnd    = placeCaretAtEnd;
const _placeCaretInFirstLi = placeCaretInFirstLi;

/**
 * Phase 22 — Factory unica per i field contenteditable di TUTTI gli editor:
 * quesito, soluzione, giustificazione, titolo gruppo, intro gruppo, celle RM.
 * Centralizza:
 *   - shim `.value` getter/setter (drop-in textarea-like)
 *   - data-field/dataset arbitrari
 *   - placeholder via attribute
 *   - cssText
 *   - input handler con debounce
 *   - popup-preview-on-focus opzionale (con blur guard scroll-friendly)
 *   - rich editing: B/I/U via toolbar globale + Tab/Shift+Tab indent
 *     liste + auto-bracket + Undo/Redo + copy/paste con format preservato
 *
 * @param {Object} opts
 * @param {string}            opts.field       — valore di data-field
 * @param {string}            [opts.value]     — valore iniziale (innerHTML)
 * @param {string}            [opts.placeholder] — data-placeholder
 * @param {Object<string,string>} [opts.dataset] — altre keys dataset (es. {row:"0",col:"1"})
 * @param {string}            [opts.style]     — cssText inline
 * @param {Function}          [opts.onInput]   — callback(ta) post-debounce
 * @param {number}            [opts.debounce]  — ms (default 400)
 * @param {boolean}           [opts.popupPreview] — true → focus mostra popup
 * @param {boolean}           [opts.richEditing=true] — attach Tab/Undo/auto-bracket
 * @returns {HTMLElement} contenteditable div con shim .value
 */
function createEditableField(opts = {}) {
    // G24.faseA.2 — Rifattorizzato a EditorFieldBuilder composer pattern.
    // Mixin opt-in con dependency injection esplicita (no global lookup).
    const builder = new EditorFieldBuilder()
        .setAttrs(opts)
        .setInitialValue(opts.value);

    if (opts.richEditing !== false) {
        builder.withRichEditing({ enhanceTextarea, attachListKeyHandlers });
    }
    if (typeof opts.onInput === "function") {
        builder.withDebouncedInput(opts.onInput, opts.debounce ?? 400);
    }
    if (opts.popupPreview) {
        builder.withPopupPreview({
            show: showCellPopupPreview,
            hide: hideCellPopupPreview,
            popupId: "fm-cell-popup-preview",
        });
    }
    return builder.build();
}


/**
 * Editor wysiwyg: handler tasti per liste (allineato a Google Docs / Word).
 *
 *   Tab    in <li>:  indent (sub-list nested), anche se caret a inizio riga
 *   Shift+Tab in <li>: outdent (esci dalla nested list parent)
 *   Enter  su <li> VUOTO: outdent (legacy "exit list" pattern)
 *   Tab    fuori da lista: insert "\t" carattere (per indent paragraph)
 *
 * Solo per <div class="fm-editor-field" contenteditable> (skip textarea
 * che ha già auto-bracket via enhanceTextarea).
 */

/**
 * ArrowRight/ArrowLeft a boundary di span editable (dots/AddTextDSA):
 * sposta caret FUORI dallo span. Senza questo, l'utente non può uscire
 * dalla casella inline.
 *
 *   - ArrowRight a fine span → caret subito DOPO lo span (sibling text)
 *   - ArrowLeft a inizio span → caret subito PRIMA dello span
 *
 * Crea text node sentinel di 1 spazio se non esiste un sibling adiacente,
 * così il caret ha sempre dove posizionarsi.
 *
 * Ritorna true se ha gestito il movimento (caller fa preventDefault).
 */
/** Espande in-place le boundary di `range` per includere gli ancestor
 *  inline (b/i/u/...) interamente coperti dalla selezione. Usato in copy
 *  così "ccc" selezionato dentro <u>ccc</u> esporta <u>ccc</u>, non solo
 *  "ccc" plain. */
// G24.refactor5.step6 — _expandRangeToInlineAncestors / _handleInlineBoxExit
// estratti in `editor/inline-format.js`. Alias underscore preservano call-site.
const _expandRangeToInlineAncestors = expandRangeToInlineAncestors;
const _handleInlineBoxExit          = handleInlineBoxExit;


// G24.refactor5.step3 — _caretAtNodeStart / _caretAtNodeEnd estratti.

/**
 * Trova l'<li> ancestor del nodo (DENTRO il field). null se non in lista.
 */
// G24.refactor5.step4 — _findEnclosingLi / _indentListItem / _outdentListItem
// estratti in `editor/list-edit-utils.js`. Alias underscore preservati.
const _findEnclosingLi  = findEnclosingLi;
const _indentListItem   = indentListItem;
const _outdentListItem  = outdentListItem;

// G24.refactor5.step3 — _placeCaretAtStart estratto in `editor/caret-utils.js`.

const FM_TAB_WIDTH = 4;

/**
 * Inserisce una tabulazione "smart" al caret: avanza al prossimo tab-stop
 * (colonna multipla di FM_TAB_WIDTH dalla riga corrente).
 *
 * Esempi (TAB_WIDTH=4):
 *   col=0 → 4 spazi (arriva a col 4)
 *   col=3 → 1 spazio (arriva a col 4)
 *   col=4 → 4 spazi (arriva a col 8)
 *   col=10 → 2 spazi (arriva a col 12)
 *
 * Caret COLLASSATO a fine spazi inseriti, così Tab successivi avanzano.
 *
 * NB: TEX export — gli spazi multipli in TeX collassano in 1 spazio
 * (whitespace handling LaTeX). Per allineamento reale serve `\hspace*{Ncm}`
 * o ambiente `tabular`. Per ora lasciamo i visual spaces nel HTML; se
 * l'esercizio richiede allineamento preciso nel PDF, l'utente userà TikZ
 * o tabular esplicitamente.
 *
 * @param {HTMLElement} field — contenteditable element
 * @param {boolean} shift — Shift+Tab: rimuovi spazi se all'inizio riga
 */
function _insertTabAtCaret(field, shift = false) {
    const sel = window.getSelection();
    if (!sel.rangeCount) return;
    const range = sel.getRangeAt(0);
    range.deleteContents();
    const column = _columnAtCaret(field, range.startContainer, range.startOffset);
    if (shift) {
        // Shift+Tab fuori da lista: rimuovi fino a TAB_WIDTH spazi precedenti
        // (de-indent). Cerco il textNode immediatamente prima del caret.
        _removeSpacesBeforeCaret(field, range);
        return;
    }
    const spacesNeeded = FM_TAB_WIDTH - (column % FM_TAB_WIDTH);
    const textNode = document.createTextNode(" ".repeat(spacesNeeded));
    range.insertNode(textNode);
    // Caret COLLASSATO a fine textNode (NON selezionato!)
    const newRange = document.createRange();
    newRange.setStartAfter(textNode);
    newRange.collapse(true);
    sel.removeAllRanges();
    sel.addRange(newRange);
}

/**
 * Calcola la column (0-based) del caret nella riga corrente.
 * Riga = chars dal precedente boundary (\n in textContent, <br>, block tag boundary).
 */
function _columnAtCaret(field, container, offset) {
    let column = 0;
    let found = false;

    function walk(node) {
        if (found) return;
        if (node === container) {
            if (node.nodeType === Node.TEXT_NODE) {
                // Conto chars del textNode fino a offset, considerando \n interni
                const before = node.textContent.slice(0, offset);
                const lastNl = before.lastIndexOf("\n");
                column = lastNl >= 0 ? before.length - lastNl - 1 : column + before.length;
            } else {
                // Container è un element: walk i child fino a offset
                const limit = Math.min(offset, node.childNodes.length);
                for (let i = 0; i < limit; i++) walk(node.childNodes[i]);
            }
            found = true;
            return;
        }
        if (node.nodeType === Node.TEXT_NODE) {
            const t = node.textContent;
            const lastNl = t.lastIndexOf("\n");
            if (lastNl >= 0) column = t.length - lastNl - 1;
            else column += t.length;
        } else if (node.nodeType === Node.ELEMENT_NODE) {
            const tag = node.tagName;
            if (tag === "BR") {
                column = 0;
            } else if (/^(DIV|P|LI|UL|OL|H[1-6]|BLOCKQUOTE|PRE|TR|TABLE)$/i.test(tag)) {
                // Block element: nuova riga prima e dopo
                column = 0;
                for (const c of node.childNodes) walk(c);
                if (!found) column = 0;
            } else {
                for (const c of node.childNodes) walk(c);
            }
        }
    }
    walk(field);
    return column;
}

/**
 * Shift+Tab fuori da lista: rimuovi fino a TAB_WIDTH spazi precedenti il caret.
 */
function _removeSpacesBeforeCaret(field, range) {
    const node = range.startContainer;
    if (node.nodeType !== Node.TEXT_NODE) return;
    const offset = range.startOffset;
    const before = node.textContent.slice(0, offset);
    // Match fino a TAB_WIDTH spazi finali
    const m = before.match(/( {1,4})$/);
    if (!m) return;
    const removeCount = m[1].length;
    node.textContent = before.slice(0, -removeCount) + node.textContent.slice(offset);
    const newRange = document.createRange();
    newRange.setStart(node, offset - removeCount);
    newRange.collapse(true);
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(newRange);
}

// G24.refactor5.step6prep — UndoManager estratto in `editor/undo-manager.js`.
// Enabler per step 6 (inline-format) e step 8 (section-builders).

/** G22.S15 — Sincronizza scroll del preview a quello del textarea.
 *  Approccio combinato:
 *   1. Quando il textarea scrolla (auto-scroll su typing o scroll manuale),
 *      la preview segue proporzionalmente: ratio = ta.scrollTop / scrollable.
 *      Vantaggio: il textarea sa dove visualizzare il cursore (auto-scroll
 *      del browser), preview lo replica.
 *   2. Caret-based ratio come fallback (per casi dove ta non scrolla, es.
 *      tutto dentro viewport).
 *   3. Edge-snap: se caret a fine testo → preview a fine; se a inizio → top. */
// G24.faseC-final — _syncPreviewScroll estratto in `editor/preview-scroll.js`.
const _syncPreviewScroll = syncPreviewScroll;

/** Debounce input → re-render preview. */
function bindPreview(ta, pv) {
    // G22.S15.bis Fase 6 — sync bidirezionale altezza ta ⇄ pv.
    // Idempotente: skipa se già installato. Disconnect automatico via
    // MutationObserver quando ta esce dal DOM (chiusura editor).
    installResizeSync(ta, pv);
    let timer = null;
    ta.addEventListener("input", () => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            updatePreview(ta, pv);
            // Sync scroll dopo render iniziale + dopo MathJax async (650ms
            // copre tipico typeset MathJax).
            requestAnimationFrame(() => _syncPreviewScroll(ta, pv));
            setTimeout(() => _syncPreviewScroll(ta, pv), 650);
        }, 300);
    });
    // Sync immediato sui movimenti di scroll del textarea (typing,
    // scroll manuale, caret movement che fa auto-scroll).
    ta.addEventListener("scroll", () => _syncPreviewScroll(ta, pv));
    // Sync su movimento cursore senza scroll (es. Home, End)
    const moveSync = () => _syncPreviewScroll(ta, pv);
    ta.addEventListener("keyup", (e) => {
        if (e.key.startsWith("Arrow") || e.key === "PageUp" || e.key === "PageDown"
            || e.key === "Home" || e.key === "End") moveSync();
    });
    ta.addEventListener("click", moveSync);
}

/** G19.15 — Enhance textarea LaTeX editor con scorciatoie tastiera +
 *  auto-bracket completion. No deps esterne (no TipTap/CodeMirror), solo
 *  manipolazione del selectionStart/End del DOM textarea.
 *
 *  Shortcuts:
 *    Ctrl+B → wrap selection in \textbf{}
 *    Ctrl+I → wrap selection in \textit{}
 *    Ctrl+M → insert \( \) (math inline) e posiziona caret in mezzo
 *    Ctrl+Shift+M → insert \[ \] (math display)
 *    Tab    → insert 2 spazi (no perde focus)
 *    {      → insert "{}" auto-completing, caret in mezzo
 *    [      → insert "[]" auto-completing, caret in mezzo
 *    \      → mostra hint snippet (futuro autocomplete; ora no-op)
 *
 *  Idempotente: skipa se già enhanced (`dataset.fmEnhanced=1`).
 */

async function updatePreview(ta, pv) {
    // G22.S15 — anti-flicker: tre tecniche combinate
    //
    //  1. SHORT-CIRCUIT su source identico: il debounce + memo cache lato
    //     tikz-render-client annulla i round-trip ma comunque innerHTML
    //     reset = repaint costoso. Skip totale se nulla e' cambiato.
    //
    //  2. SNAPSHOT SVG per hash: prima di azzerare `pv`, cattura ogni
    //     <svg data-tikz-hash="..."> e tienilo in una mappa hash→Node
    //     clone. Dopo il reset, i nuovi <script type="text/tikz"> con
    //     stesso hash li sostituisce SUBITO con il nodo clonato (zero
    //     fetch, zero flash). Solo gli script con hash NUOVO cadono nel
    //     flow async (renderAll → memo/cache/compile).
    //
    //  3. HEIGHT LOCK: durante il transient innerHTML reset il container
    //     collassava a 0px (script tag inert). Blocca min-height al
    //     valore corrente e rilascia post-render via rAF.
    // G22.S15 — fingerprint sync key: include sia ta.value sia i blocchi
    // TikZ collassati (modificarli in modal → ta.value resta uguale ma
    // blocchi cambiano → dobbiamo invalidare la short-circuit cache).
    const fingerprint = `${ta.value  }│${  ta._tikzBlocks ? ta._tikzBlocks.map(b => b.body).join("§") : ""}`;
    if (ta._lastRenderedValue === fingerprint) return;
    ta._lastRenderedValue = fingerprint;

    // Snapshot SVG attualmente renderizzati, indicizzati per `data-tikz-srckey`
    // (quickHash sync della sorgente normalizzata, generato da renderAll).
    // Cloniamo i nodi per preservarli oltre il prossimo innerHTML reset.
    const prevHeight = pv.offsetHeight;
    const savedBySrcKey = new Map();
    pv.querySelectorAll('svg[data-tikz-srckey]').forEach((svg) => {
        savedBySrcKey.set(svg.getAttribute('data-tikz-srckey'), svg.cloneNode(true));
    });
    // Lock dell'altezza per evitare collapse durante il transient
    if (prevHeight > 0) {
        pv.style.minHeight = `${prevHeight  }px`;
    }

    // Reset + restore SINCRONO (no await fra reset e replace → no flicker).
    // Il browser non ha ancora dipinto: appendChild/replaceWith che
    // accadono prima del prossimo paint sono "atomic" dal punto di vista
    // visuale.
    // G22.S15 — espande i marker `⟨🔍 TikZ #N⟩` nei blocchi reali prima
    // di renderizzare. Cosi' il preview vede i `<script type="text/tikz">`
    // anche se il textarea visibile mostra solo i marker.
    pv.innerHTML = _expandedValue(ta);

    if (savedBySrcKey.size > 0) {
        const scripts = pv.querySelectorAll('script[type^="text/tikz"]');
        for (const script of scripts) {
            const normalized = normalizeTikz(script.textContent || script.innerHTML || '');
            const key = quickHash(normalized);
            const saved = savedBySrcKey.get(key);
            if (saved) script.replaceWith(saved);
        }
    }

    // MathJax typeset per LaTeX inline/block
    const mj = window.MathJax;
    if (mj?.typesetPromise) {
        try { await mj.typesetPromise([pv]); } catch (_) { /* ignore */ }
    }

    // Render eventuali script TikZ rimasti (hash nuovo o snapshot mancata).
    await processTikzScripts(pv);

    // Rilascia height lock dopo che il browser ha dipinto. Se il preview
    // e' cresciuto (SVG nuovo + grande), il .content collapsible
    // contenitore deve estendersi: forza recompute del FMCollapsible.
    requestAnimationFrame(() => {
        pv.style.minHeight = '';
        // G22.S15.bis Fase 6 — il sync altezza textarea ⇄ preview è ora
        // gestito dal modulo `editor/resize-sync.js` (installResizeSync
        // chiamato da bindPreview). Il modulo usa MutationObserver sul
        // pv per misurare l'altezza naturale del contenuto e impostare
        // ta.minHeight solo quando serve, senza creare loop con il drag
        // del corner-resize CSS della textarea.
        if (window.FMCollapsible?.recompute) {
            window.FMCollapsible.recompute();
        }
    });
}

/** G22.S15 — Trova tutti i `<script type="text/tikz">` in root e li
 *  sostituisce con SVG inline via VPS (pdflatex+dvisvgm) tramite il
 *  modulo `tikz-render-client.js`. Cache content-addressable (SHA-256
 *  del sorgente normalizzato) — re-edit dello stesso TikZ usa cache.
 *
 *  G22.S15.bis — TikZJax DEPRECATO: nessun fallback client-side WASM.
 *  In caso di VPS irraggiungibile, errori → blocco rosso inline.
 *
 *  Niente piu' upload SVG su disco via /tikz/save-svg: la cache vive
 *  sotto storage/cache/tikz/ ed e' gestita lato server. Vedi ADR-013.
 */
async function processTikzScripts(root) {
    const scripts = Array.from(root.querySelectorAll('script[type="text/tikz"]'));
    if (!scripts.length) return;

    try {
        const stats = await tikzRenderAll(root, {
            defaultScope: "public",
        });
        if (stats.errors?.length) {
            console.warn("[preview] tikz render errors:", stats.errors);
        }
    } catch (err) {
        console.error("[preview] tikz render failed:", err);
    }
}

/** Phase 16 — lifecycle SVG TikZ: dopo che il render server-side ha
 *  sostituito <script> con <svg>, salviamo l'SVG su disco via POST
 *  /tikz/save-svg così il renderer finale (PDF / TeX build) può
 *  referenziarlo.
 *
 *  Path scheme: institutes/{inst}/private/{teacher}/eser/svg/{itemId}__{seq}.svg
 *  Per ora usiamo folderName="svg" + fileName derivato dall'item data-id + seq.
 *  La pipeline TeX legge da questa stessa path legacy `eser/{ind}/svg/...`.
 */
async function saveGeneratedTikzSvgs(root) {
    // Root è il preview editor o la content area. Cerca SVG generati da tikz.
    const item = root.closest(".fm-collection__item") || root.parentNode?.closest(".fm-collection__item");
    const itemId = item?.dataset?.id || "preview";
    // Skip se synthetic id (no persistence per quesiti non salvati)
    if (!/^\d+$/.test(itemId) && !itemId.includes("_q")) return;

    const svgs = Array.from(root.querySelectorAll("svg"));
    for (let i = 0; i < svgs.length; i++) {
        const svg = svgs[i];
        if (svg.dataset.fmSaved === "1") continue; // idempotenza
        const svgXml = new XMLSerializer().serializeToString(svg);
        const fileName = `tikz_${itemId}__${i}.svg`;
        try {
            const res = await apiPost("/tikz/save-svg", {
                filePath: "svg",
                folderName: "svg",
                fileName,
                svgContent: svgXml,
            });
            if (res?.ok || res?.success) {
                svg.dataset.fmSaved = "1";
                svg.dataset.fmSvgPath = res.path || `svg/${fileName}`;
            }
        } catch (_) { /* skip silent */ }
    }
}

/** Phase 16 — Elimina SVG TikZ orfani (chiamato quando quesito rimosso o
 *  TikZ code cambiato prima del re-save). Path passa filePath + fileName. */
async function deleteTikzSvg(filePath, fileName) {
    try {
        await apiPost("/tikz/delete-svg", { filePath, fileName });
    } catch (_) { /* skip */ }
}

/** Phase 16 — Editor toolbar GLOBALE (sopra tutti gli editor attivi).
 *
 *  Layout: [List ▾] [TeX ▾] [🔗] [SOL] [DSA] [💾] [B_E ▾] [🔍] [🤖]
 *
 *  Agisce sul textarea in focus globalmente tracked via `window.__fmFocusedTA`.
 *  `getActivePanel()` risale dal TA al suo .fm-editor-panel (per backup/restore
 *  per-item). Fallback: primo panel aperto.
 */
function buildGlobalToolbar() {
    const bar = document.createElement("div");
    bar.className = "fm-editor-toolbar";
    // Phase 16 — NO sticky: la toolbar sta dove è (subito dopo #infoVer)
    // e scorre via con la pagina. Chi deve rimanere visibile è il .fm-groupcollex
    // con .fm-collection__item in edit (classe .fm-problem-editing + position:sticky).
    bar.style.cssText = "display:flex;flex-wrap:wrap;gap:4px;padding:6px;background:#f3f3f7;border:1px solid #ccc;border-radius:4px;margin:10px 0;z-index:100;align-items:center";

    // `panelRef` getter: ritorna il panel in scope per il textarea focused.
    const getPanel = () => getActivePanel();

    bar.appendChild(buildListSelectGlobal(getPanel));
    bar.appendChild(buildTexDropdownGlobal(getPanel));
    bar.appendChild(buildGeoGebraButton(getPanel));
    // Inline format HTML (B/I/U): WYSIWYG bold/italic/underline.
    // Differenza vs TeX \\textbf: emette <b>/<i>/<u> reali → preview
    // grafica nel contenteditable + persist via INLINE_PRESERVE pattern.
    const bBtn = makeToolbarBtn("B", "Grassetto — toggle (Ctrl+B)", () => _toggleInlineFormat(getPanel(), "b"));
    bBtn.style.fontWeight = "bold";
    bBtn.classList.add("fm-fmtbtn", "fm-fmtbtn-b");
    bar.appendChild(bBtn);
    const iBtn = makeToolbarBtn("I", "Corsivo — toggle (Ctrl+I)", () => _toggleInlineFormat(getPanel(), "i"));
    iBtn.style.fontStyle = "italic";
    iBtn.classList.add("fm-fmtbtn", "fm-fmtbtn-i");
    bar.appendChild(iBtn);
    const uBtn = makeToolbarBtn("U", "Sottolineato — toggle (Ctrl+U)", () => _toggleInlineFormat(getPanel(), "u"));
    uBtn.style.textDecoration = "underline";
    uBtn.classList.add("fm-fmtbtn", "fm-fmtbtn-u");
    bar.appendChild(uBtn);
    // Stato attivo: aggiorna inset/highlight quando selezione è dentro
    // un <b>/<i>/<u>. Listener globale su selectionchange + focus tracking.
    const _updateFmtState = () => _updateInlineFormatBtnState(bBtn, iBtn, uBtn);
    document.addEventListener("selectionchange", _updateFmtState);
    document.addEventListener("focusin", _updateFmtState);
    document.addEventListener("keyup", _updateFmtState);
    bar.appendChild(makeToolbarBtn("🔗", "Inserisci link (URL + testo visibile)", () => insertLinkDialog(getPanel())));
    bar.appendChild(makeToolbarBtn("...", 'Inserisci "dots" (soluzione/risposta breve evidenziata)',
        () => _insertEditableInlineBox(getPanel(), "dots", "")));
    bar.appendChild(makeToolbarBtn("DSA", "Inserisci testo DSA (visibile solo variante DSA)",
        () => _insertEditableInlineBox(getPanel(), "AddTextDSA", "**")));
    bar.appendChild(makeToolbarBtn("✨", "Riformatta TeX/TikZ via latexindent (Ctrl+Shift+F)",
        () => _formatLatexInField(getPanel())));
    bar.appendChild(makeToolbarBtn("💾", "Salva backup manuale (Ctrl+S)", () => saveBackupSnapshot(getPanel())));
    bar.appendChild(buildBackupDropdownGlobal(getPanel));
    bar.appendChild(makeToolbarBtn("🔍", "Trova e sostituisci (Ctrl+F)", () => openFindReplaceDialog(getPanel())));
    bar.appendChild(makeToolbarBtn("🤖", "AI Copilot", () => {
        if (window.CopilotAI?.togglePanel) window.CopilotAI.togglePanel();
        else toast("Copilot AI non disponibile", "warn");
    }));

    // Tracker globale focusin per:
    //   - editor inline item (.fm-editor-panel descendants)
    //   - editor inline gruppo (.fm-testo[contenteditable], .fm-title-edit)
    // Toolbar globale agisce su window.__fmFocusedTA — coerente per tutti.
    document.addEventListener("focusin", (e) => {
        const t = e.target;
        if (!t) return;
        const inPanel = t.closest?.(".fm-editor-panel");
        const isPanelField = inPanel && (
            t.tagName === "TEXTAREA"
            || (t.classList?.contains("fm-editor-field") && t.isContentEditable)
        );
        const isGroupField = !inPanel && t.isContentEditable && (
            t.classList?.contains("fm-editor-field")
            || t.classList?.contains("fm-title-edit")
            || t.closest?.(".fm-testo[contenteditable], .fm-title-edit")
        );
        if (!isPanelField && !isGroupField) return;
        window.__fmFocusedTA = t;
        const panel = inPanel || t.closest(".fm-groupcollex");
        if (panel) panel._focusedTextarea = t;
    });

    return bar;
}

/** Aggiorna stato active dei pulsanti B/I/U basato su ancestor della
 *  selezione corrente. Aggiunge `.active` class quando caret è dentro
 *  un <b>/<strong>, <i>/<em>, <u> rispettivamente. */
function _updateInlineFormatBtnState(bBtn, iBtn, uBtn) {
    const sel = window.getSelection();
    let inB = false, inI = false, inU = false;
    if (sel && sel.rangeCount) {
        const ta = window.__fmFocusedTA;
        const range = sel.getRangeAt(0);
        if (ta && ta.contains(range.startContainer)) {
            let n = range.startContainer;
            while (n && n !== ta) {
                if (n.nodeType === 1) {
                    const tag = n.tagName.toLowerCase();
                    if (tag === "b" || tag === "strong") inB = true;
                    else if (tag === "i" || tag === "em") inI = true;
                    else if (tag === "u") inU = true;
                }
                n = n.parentNode;
            }
        }
    }
    bBtn.classList.toggle("fm-fmtbtn-active", inB);
    iBtn.classList.toggle("fm-fmtbtn-active", inI);
    uBtn.classList.toggle("fm-fmtbtn-active", inU);
}

/** Risolve il panel "attivo" per le azioni toolbar: quello che contiene
 *  il textarea in focus più recente, oppure il primo panel aperto. */
function getActivePanel() {
    const ta = window.__fmFocusedTA;
    if (ta?.isConnected) {
        const p = ta.closest(".fm-editor-panel");
        if (p) { p._focusedTextarea = ta; return p; }
    }
    return document.querySelector(".fm-editor-panel");
}

/** Varianti "global" che risolvono panel via getPanel() callback. */
function buildListSelectGlobal(getPanel) {
    const sel = document.createElement("select");
    sel.title = "Inserisci lista HTML strutturata (preview render + LaTeX export)";
    sel.className = "fm-list-snippet-select";
    // Stili Google Docs-like: il dropdown nativo <select> non rende
    // anteprime grafiche, ma testo è chiaro abbastanza ("1./a./i.").
    // Stili PRESET gerarchici applicati via data-fm-list-style.
    [
        ["", "List"],
        // — Bullet
        ["ul",                 "● ○ ■"],
        ["ul-arrow",           "➤ ♦ ●"],
        ["ul-star",            "★ ○ ■"],
        // — Numerati con .
        ["ol",                 "1. a. i."],
        ["ol-Alpha",           "A. 1. a."],
        ["ol-alpha",           "a. i. 1."],
        ["ol-Roman",           "I. A. 1."],
        ["ol-zero",            "01. a. i."],
        // — Numerati con )
        ["ol-paren",           "1) a) i)"],
        ["ol-Alpha-paren",     "A) 1) a)"],
        ["ol-alpha-paren",     "a) i) 1)"],
        ["ol-Roman-paren",     "I) A) 1)"],
        ["ol-zero-paren",      "01) a) i)"],
    ].forEach(([v, l]) => {
        const opt = document.createElement("option"); opt.value = v; opt.textContent = l; sel.appendChild(opt);
    });
    sel.addEventListener("change", () => {
        if (sel.value) insertListSnippet(getPanel(), sel.value);
        sel.value = "";
    });
    return sel;
}


function buildBackupDropdownGlobal(getPanel) {
    const wrap = document.createElement("div");
    wrap.className = "fm-backup-dropdown";
    const btn = document.createElement("button");
    btn.type = "button"; btn.textContent = "⏪ ▾";
    btn.title = "Ripristina backup salvati (rollback snapshot dei textarea)";
    btn.className = "fm-topbar__btn fm-backup-dropdown-btn";
    const menu = document.createElement("div");
    menu.className = "fm-backup-dropdown-menu";
    btn.addEventListener("click", (e) => {
        e.preventDefault();
        const panel = getPanel();
        const item = panel?.closest?.(".fm-collection__item");
        const id = item?.dataset?.id || "noid";
        let snaps = [];
        try { snaps = JSON.parse(sessionStorage.getItem(`fmv.backup.${id}`) || "[]"); } catch {}
        menu.innerHTML = "";
        if (!snaps.length) {
            const e = document.createElement("div");
            e.className = "fm-backup-dropdown-empty";
            e.textContent = "Nessun backup salvato";
            menu.appendChild(e);
        } else {
            snaps.forEach((s) => {
                const it = document.createElement("button");
                it.type = "button";
                it.className = "fm-backup-dropdown-item";
                const d = new Date(s.ts).toLocaleString("it-IT", { dateStyle: "short", timeStyle: "medium" });
                const prev = (s.value || "").slice(0, 40).replace(/\n/g, " ").replace(/<[^>]+>/g, "");
                const strong = document.createElement("strong");
                strong.textContent = s.field;
                const small = document.createElement("small");
                small.textContent = prev + (s.value?.length > 40 ? "…" : "");
                it.appendChild(strong);
                it.appendChild(document.createTextNode(` · ${  d}`));
                it.appendChild(document.createElement("br"));
                it.appendChild(small);
                it.addEventListener("click", async (ev) => {
                    ev.preventDefault();
                    const ta = panel?.querySelector?.(`[data-field="${CSS.escape(s.field)}"]`);
                    if (ta && await window.FM.Dialog.confirm(`Ripristinare backup del ${d}?`)) {
                        ta.value = s.value;
                        ta.dispatchEvent(new Event("input", { bubbles: true }));
                        toast("Backup ripristinato", "ok");
                    }
                    menu.classList.remove("fm-backup-dropdown-menu--open");
                });
                menu.appendChild(it);
            });
        }
        menu.classList.toggle("fm-backup-dropdown-menu--open");
    });
    document.addEventListener("click", (e) => {
        if (!wrap.contains(e.target)) menu.classList.remove("fm-backup-dropdown-menu--open");
    });
    wrap.appendChild(btn); wrap.appendChild(menu);
    return wrap;
}

function makeToolbarBtn(label, title, onClick) {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.textContent = label;
    btn.title = title;
    btn.style.cssText = "padding:4px 10px;background:#3a3a3a;color:#ddd;border:1px solid #555;border-radius:3px;cursor:pointer;font:12px/1.2 system-ui";
    btn.addEventListener("click", (e) => { e.preventDefault(); onClick(); });
    return btn;
}

/* ─────────────────────────────────────────────────────────────────
 * G22.S15.bis Fase 4 — Bottone GeoGebra in toolbar:
 *   Click → modal scelta 3 opzioni (nuovo / catalogo / .ggb upload)
 *   → modal editor lazy-loaded (deployggb.js + preview SVG)
 *   → ➕ Aggiungi inserisce blocco con marker ⟨📐 GeoGebra #N⟩ nel textarea
 *   → 💾 Salva nel catalogo POST /geogebra/catalog/save
 *   → 🔄 Reset / ✕ Chiudi
 * Multi-block markers system: ta._geogebraBlocks[] analoga a _tikzBlocks[].
 * ───────────────────────────────────────────────────────────────── */

function buildGeoGebraButton(getPanel) {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.title = "Inserisci grafico GeoGebra (nuovo, dal mio catalogo, o file .ggb)";
    btn.style.cssText = "display:inline-flex;align-items:center;gap:5px;padding:3px 10px;background:#3a3a3a;color:#ddd;border:1px solid #555;border-radius:3px;cursor:pointer;font:12px/1.2 system-ui";
    btn.innerHTML = '<img src="/img/geogebra.svg" width="18" height="18" alt="" style="display:block"> GeoGebra ▾';
    btn.addEventListener("click", (e) => {
        e.preventDefault();
        _openGeoGebraChoiceDialog(getPanel);
    });
    return btn;
}

/** Modal scelta pre-inserimento: 3 opzioni (nuovo / catalogo / .ggb upload). */
async function _openGeoGebraChoiceDialog(getPanel) {
    document.getElementById("fm-ggb-choice")?.remove();
    const dlg = document.createElement("div");
    dlg.id = "fm-ggb-choice";
    dlg.style.cssText = "position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:100040;display:flex;align-items:center;justify-content:center;font:13px/1.4 system-ui";
    dlg.innerHTML = `
        <div style="background:#1e1e1e;color:#ddd;border:1px solid #444;border-radius:8px;box-shadow:0 12px 48px rgba(0,0,0,0.6);min-width:520px;max-width:92vw;overflow:hidden">
            <div style="padding:12px 16px;background:#2a2a2a;border-bottom:1px solid #444;font-weight:600;display:flex;align-items:center;gap:8px">
                <img src="/img/geogebra.svg" width="22" height="22" style="display:block">
                <span style="flex:1">GeoGebra — scegli come iniziare</span>
                <button data-act="cancel" style="padding:4px 10px;background:#3a3a3a;color:#ddd;border:1px solid #555;border-radius:4px;cursor:pointer">✕</button>
            </div>
            <div style="padding:16px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
                <button data-act="new" style="padding:18px 14px;background:#1e3a1e;border:1px solid #66bb6a;border-radius:6px;cursor:pointer;color:#a5d6a7;text-align:left;display:flex;flex-direction:column;gap:6px">
                    <div style="font-size:24px">➕</div>
                    <strong>Nuovo grafico</strong>
                    <small style="color:#999;font-weight:normal">Apri GeoGebra vuoto e crea da zero</small>
                </button>
                <button data-act="catalog" style="padding:18px 14px;background:#2a3a5a;border:1px solid #4a6a9a;border-radius:6px;cursor:pointer;color:#9bc1ff;text-align:left;display:flex;flex-direction:column;gap:6px">
                    <div style="font-size:24px">📚</div>
                    <strong>Mio catalogo</strong>
                    <small style="color:#999;font-weight:normal">Carica un grafico salvato in precedenza</small>
                </button>
                <button data-act="upload" style="padding:18px 14px;background:#3a3a3a;border:1px solid #777;border-radius:6px;cursor:pointer;color:#ddd;text-align:left;display:flex;flex-direction:column;gap:6px">
                    <div style="font-size:24px">📂</div>
                    <strong>File .ggb</strong>
                    <small style="color:#999;font-weight:normal">Carica un file GeoGebra dal tuo computer</small>
                </button>
            </div>
        </div>`;
    document.body.appendChild(dlg);

    const close = () => { dlg.remove(); document.removeEventListener("keydown", esc); };
    const esc = (e) => { if (e.key === "Escape") close(); };
    document.addEventListener("keydown", esc);

    dlg.addEventListener("click", async (e) => {
        const a = e.target.closest("button")?.dataset?.act;
        if (!a) { if (e.target === dlg) close(); return; }
        if (a === "cancel") { close(); return; }
        if (a === "new") {
            close();
            await _openGeoGebraEditor(getPanel, { initialGgbBase64: null });
        } else if (a === "catalog") {
            close();
            await _openGeoGebraCatalogPicker(getPanel);
        } else if (a === "upload") {
            close();
            const ggb = await _pickGgbFile();
            if (ggb) await _openGeoGebraEditor(getPanel, { initialGgbBase64: ggb });
        }
    });
}

/** File picker locale per .ggb → ritorna base64 (no upload server). */
function _pickGgbFile() {
    return new Promise((resolve) => {
        const inp = document.createElement("input");
        inp.type = "file";
        inp.accept = ".ggb,application/zip";
        inp.style.display = "none";
        inp.addEventListener("change", () => {
            const f = inp.files?.[0];
            inp.remove();
            if (!f) return resolve(null);
            const reader = new FileReader();
            reader.onload = () => {
                const dataUrl = String(reader.result || "");
                const base64 = dataUrl.split(",", 2)[1] || "";
                resolve(base64);
            };
            reader.onerror = () => { toast("Errore lettura file", "err"); resolve(null); };
            reader.readAsDataURL(f);
        });
        document.body.appendChild(inp);
        inp.click();
    });
}

/** Picker catalogo personale: lista item con thumb SVG. */
async function _openGeoGebraCatalogPicker(getPanel) {
    let items = [];
    try {
        const r = await fetch("/geogebra/catalog", { credentials: "same-origin", cache: "no-store" });
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        const j = await r.json();
        items = Array.isArray(j?.items) ? j.items : [];
    } catch (e) {
        toast(`Errore caricamento catalogo: ${e.message}`, "err");
        return;
    }
    if (items.length === 0) {
        toast("Catalogo personale vuoto. Apri un nuovo grafico e usa 💾 Salva nel catalogo.", "info");
        await _openGeoGebraEditor(getPanel, { initialGgbBase64: null });
        return;
    }
    document.getElementById("fm-ggb-catalog")?.remove();
    const dlg = document.createElement("div");
    dlg.id = "fm-ggb-catalog";
    dlg.style.cssText = "position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:100040;display:flex;align-items:center;justify-content:center;font:13px/1.4 system-ui";
    dlg.innerHTML = `
        <div style="background:#1e1e1e;color:#ddd;border:1px solid #444;border-radius:8px;box-shadow:0 12px 48px rgba(0,0,0,0.6);width:780px;max-width:96vw;max-height:88vh;display:flex;flex-direction:column;overflow:hidden">
            <div style="padding:12px 16px;background:#2a2a2a;border-bottom:1px solid #444;font-weight:600;display:flex;align-items:center">
                <span style="flex:1">📚 Mio catalogo GeoGebra (${items.length})</span>
                <button data-act="cancel" style="padding:4px 10px;background:#3a3a3a;color:#ddd;border:1px solid #555;border-radius:4px;cursor:pointer">✕</button>
            </div>
            <div class="fm-ggb-list" style="flex:1;overflow-y:auto;padding:6px;display:grid;grid-template-columns:repeat(auto-fill, minmax(220px,1fr));gap:8px"></div>
        </div>`;
    document.body.appendChild(dlg);
    const list = dlg.querySelector(".fm-ggb-list");
    items.forEach((it) => {
        const card = document.createElement("div");
        card.style.cssText = "background:#2a2a2a;border:1px solid #444;border-radius:6px;padding:8px;display:flex;flex-direction:column;gap:6px";
        const lbl = document.createElement("div");
        lbl.textContent = it.label || "(senza nome)";
        lbl.style.cssText = "font-weight:600;color:#ddd;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap";
        const thumb = document.createElement("div");
        thumb.style.cssText = "background:#fff;border-radius:4px;height:120px;display:flex;align-items:center;justify-content:center;overflow:hidden";
        if (it.svg_cached) thumb.innerHTML = it.svg_cached;
        else thumb.innerHTML = '<span style="color:#999;font-size:11px">no preview</span>';
        const actions = document.createElement("div");
        actions.style.cssText = "display:flex;gap:4px";
        const loadBtn = document.createElement("button");
        loadBtn.type = "button"; loadBtn.textContent = "📂 Apri"; loadBtn.title = "Apri questo grafico nell'editor";
        loadBtn.style.cssText = "flex:1;padding:5px;background:#2a5ac7;color:#fff;border:none;border-radius:3px;cursor:pointer;font:11px system-ui";
        loadBtn.addEventListener("click", async () => {
            try {
                const r = await fetch(`/geogebra/catalog/${encodeURIComponent(it.id)}`, { credentials: "same-origin" });
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                const j = await r.json();
                const ggb = j?.item?.ggb_b64;
                if (!ggb) throw new Error("ggb mancante");
                dlg.remove();
                await _openGeoGebraEditor(getPanel, { initialGgbBase64: ggb, initialLabel: j.item.label, itemId: it.id });
            } catch (err) {
                toast(`Errore: ${err.message}`, "err");
            }
        });
        const delBtn = document.createElement("button");
        delBtn.type = "button"; delBtn.textContent = "🗑️"; delBtn.title = "Elimina dal catalogo";
        delBtn.style.cssText = "padding:5px 8px;background:#3a1e1e;color:#fff;border:1px solid #c02a2a;border-radius:3px;cursor:pointer;font:11px system-ui";
        delBtn.addEventListener("click", async () => {
            const ok = await confirmDialog({
                title: "Elimina dal catalogo",
                message: `Eliminare "${it.label}" dal catalogo personale?`,
                confirmLabel: "Elimina", danger: true,
            });
            if (!ok) return;
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || "";
            const r = await fetch("/geogebra/catalog/delete", {
                method: "POST", credentials: "same-origin",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf, "Accept": "application/json" },
                body: JSON.stringify({ id: it.id }),
            });
            const j = await r.json().catch(() => null);
            if (r.ok && (j?.success === true || j?.ok === true)) {
                card.remove();
                toast("Eliminato", "ok");
            } else {
                toast(`Errore: ${j?.error || r.status}`, "err");
            }
        });
        actions.appendChild(loadBtn);
        actions.appendChild(delBtn);
        card.appendChild(lbl);
        card.appendChild(thumb);
        card.appendChild(actions);
        list.appendChild(card);
    });

    const close = () => { dlg.remove(); document.removeEventListener("keydown", esc); };
    const esc = (e) => { if (e.key === "Escape") close(); };
    document.addEventListener("keydown", esc);
    dlg.addEventListener("click", (e) => {
        if (e.target?.dataset?.act === "cancel" || e.target === dlg) close();
    });
}

/** Lazy load tex-geogebra-editor entry + apri modal con onAdd → insert nel quesito. */
async function _openGeoGebraEditor(getPanel, { initialGgbBase64 = null, initialLabel = "", itemId = null } = {}) {
    if (!window.FM?.openGeoGebraEditor) {
        try {
            const cacheBust = `?t=${Date.now()}`;
            const res = await fetch(`/build/manifest.json${cacheBust}`, { credentials: "same-origin", cache: "no-store" });
            if (!res.ok) throw new Error(`manifest HTTP ${res.status} — npm run build`);
            const manifest = await res.json();
            const entry = manifest["js/entries/geogebra-editor.js"];
            if (!entry) throw new Error("entry geogebra-editor assente — npm run build");
            await import(/* @vite-ignore */ `/build/${entry.file}`);
            if (!window.FM?.openGeoGebraEditor) throw new Error("bundle non popola FM.openGeoGebraEditor");
        } catch (e) {
            toast(`Errore caricamento GeoGebra: ${e.message}`, "err");
            return;
        }
    }
    window.FM.openGeoGebraEditor({
        initialGgbBase64, initialLabel, itemId,
        onAdd: async ({ ggb_b64, svg, label, width }) => {
            const ta = findFocusedTextarea(getPanel);
            if (!ta) {
                toast("Apri un quesito in modalità modifica e clicca su un editor prima di aggiungere", "warn");
                return false;
            }
            ta._geogebraBlocks = ta._geogebraBlocks || [];
            ta._geogebraBlocks.push({ ggb_b64, svg, label: label || "", width: width || "" });
            const newMarker = `⟨📐 GeoGebra #${ta._geogebraBlocks.length}⟩`;
            ta.value = (ta.value.trim() ? `${ta.value  }\n` : "") + newMarker;
            ta._lastRenderedValue = undefined;
            if (typeof ta._renderTikzButtons === "function") ta._renderTikzButtons();
            ta.dispatchEvent(new Event("input", { bubbles: true }));
            toast("Grafico GeoGebra aggiunto al quesito", "ok");
            return true;
        },
        onSavedToCatalog: ({ id, label }) => {
            // best-effort: il modal mostra già toast da deployggb-editor.js
        },
        onCancel: () => {},
    });
}

/** Phase 16 — Editor compatto "Fonte" triggered da `.fas.fa-edit.edit-btn`.
 *
 *  UI: popover floating vicino al pulsante con 5 campi form (code, title,
 *  volume, publisher, authors). Il dato è letto/scritto su
 *  `/api/teacher/sources.json` (GET/PUT) — solo il docente vede/modifica
 *  le proprie fonti. Al salvataggio: update in place + invalidazione cache
 *  `window.__fmTeacherSources` + refresh di tutti i `<select class="origin">`. */
function bindSourceEditDelegation() {
    if (document.documentElement.dataset.fmSourceEditBound === "1") return;
    document.documentElement.dataset.fmSourceEditBound = "1";
    document.addEventListener("click", (e) => {
        const btn = e.target.closest(".fas.fa-edit.edit-btn, i.fa-edit.edit-btn");
        if (!btn) return;
        // Risali al link `<a data-value="...">` che porta il codice origine.
        const anchor = btn.closest("a[data-value]");
        const code = anchor?.dataset?.value
            || btn.closest("[data-origin]")?.dataset?.origin
            || "";
        if (!code) return;
        e.preventDefault(); e.stopPropagation();
        openSourceInlineEditor(code, btn);
    }, true);
}

async function openSourceInlineEditor(code, anchorEl) {
    // Chiudi eventuali popover aperti
    document.querySelectorAll(".fm-source-editor").forEach((n) => n.remove());

    const data = await loadTeacherSources({ force: true });
    const src = data?.sources?.[code] || {};
    const isNew = !data?.sources?.[code];

    const pop = document.createElement("div");
    pop.className = "fm-source-editor";
    // Phase Roadmap Sprint 6 — BEM migration: classi __* sostituiscono
    // legacy .fm-se-* (token-based via _source-editor.css module).
    pop.innerHTML = `
        <div class="fm-source-editor__header">
            <div class="fm-source-editor__title">${isNew ? "Nuova fonte" : "Modifica fonte"}</div>
            <button type="button" class="fm-source-editor__close" title="Chiudi" aria-label="Chiudi editor fonte">×</button>
        </div>
        <form class="fm-source-editor__form" autocomplete="off">
            <label class="fm-source-editor__field">
                <span class="fm-source-editor__label">Code</span>
                <input class="fm-source-editor__input" name="code" required pattern="[A-Za-z0-9_\\-]{1,64}" placeholder="es. mmb_v1_ed3">
            </label>
            <label class="fm-source-editor__field">
                <span class="fm-source-editor__label">Titolo libro</span>
                <input class="fm-source-editor__input" name="title" placeholder="es. Matematica multimediale.blu">
            </label>
            <label class="fm-source-editor__field">
                <span class="fm-source-editor__label">Volume / edizione</span>
                <input class="fm-source-editor__input" name="volume" placeholder="es. Vol.1 Ed.3">
            </label>
            <label class="fm-source-editor__field">
                <span class="fm-source-editor__label">Editore</span>
                <input class="fm-source-editor__input" name="publisher" placeholder="es. ZANICHELLI">
            </label>
            <label class="fm-source-editor__field">
                <span class="fm-source-editor__label">Autori</span>
                <input class="fm-source-editor__input" name="authors" placeholder="es. Bergamini - Barozzi">
            </label>
            <div class="fm-source-editor__status" role="status" aria-live="polite"></div>
            <div class="fm-source-editor__actions">
                ${isNew ? "" : '<button type="button" class="fm-source-editor__btn fm-source-editor__btn--delete" title="Elimina questa fonte">Elimina</button>'}
                <button type="button" class="fm-source-editor__btn fm-source-editor__btn--cancel">Annulla</button>
                <button type="submit" class="fm-source-editor__btn fm-source-editor__btn--save">Salva</button>
            </div>
        </form>
    `;
    document.body.appendChild(pop);
    positionFloatingPopover(pop, anchorEl);

    const form = pop.querySelector(".fm-source-editor__form");
    form.elements.code.value     = src.code || code || "";
    form.elements.title.value    = src.title || "";
    form.elements.volume.value   = src.volume || "";
    form.elements.publisher.value= src.publisher || "";
    form.elements.authors.value  = src.authors || "";
    // Se il codice è già esistente, blocca rename accidentale (read-only).
    if (!isNew) form.elements.code.readOnly = true;

    const status = pop.querySelector(".fm-source-editor__status");
    const setStatus = (txt, isErr = false) => {
        status.textContent = txt;
        status.classList.toggle("fm-source-editor__status--error", !!isErr);
        // Back-compat: alcune view legacy potrebbero ascoltare ".is-error"
        status.classList.toggle("is-error", !!isErr);
    };

    const close = () => pop.remove();
    pop.querySelector(".fm-source-editor__close").addEventListener("click", close);
    pop.querySelector(".fm-source-editor__btn--cancel").addEventListener("click", close);

    // Chiudi al click esterno (delegato una volta).
    const outside = (e) => {
        if (!pop.contains(e.target) && !e.target.closest(".fas.fa-edit.edit-btn, i.fa-edit.edit-btn")) {
            close(); document.removeEventListener("click", outside, true);
        }
    };
    setTimeout(() => document.addEventListener("click", outside, true), 0);

    form.addEventListener("submit", async (e) => {
        e.preventDefault();
        const newCode = form.elements.code.value.trim();
        if (!/^[A-Za-z0-9_\-]{1,64}$/.test(newCode)) {
            setStatus("Code non valido (solo A-Z, 0-9, _, -)", true); return;
        }
        const next = { ...(data?.sources || {}) };
        next[newCode] = {
            code: newCode,
            title:     form.elements.title.value.trim(),
            volume:    form.elements.volume.value.trim(),
            publisher: form.elements.publisher.value.trim(),
            authors:   form.elements.authors.value.trim(),
        };
        try {
            setStatus("Salvataggio…");
            await saveTeacherSources({ sources: next });
            setStatus("Salvato.");
            toast(`Fonte "${newCode}" salvata`, "ok");
            refreshOriginOptions();
            setTimeout(close, 500);
        } catch (err) {
            setStatus(`Errore: ${err.message}`, true);
        }
    });

    const delBtn = pop.querySelector(".fm-source-editor__btn--delete");
    if (delBtn) {
        delBtn.addEventListener("click", async () => {
            if (!await window.FM.Dialog.confirm(`Eliminare la fonte "${code}"?`)) return;
            const next = { ...(data?.sources || {}) };
            delete next[code];
            try {
                await saveTeacherSources({ sources: next });
                toast(`Fonte "${code}" eliminata`, "ok");
                refreshOriginOptions();
                close();
            } catch (err) { setStatus(`Errore: ${err.message}`, true); }
        });
    }
}

function positionFloatingPopover(pop, anchorEl) {
    const r = anchorEl.getBoundingClientRect();
    const W = 320;
    const left = Math.min(window.innerWidth - W - 8, Math.max(8, r.left + r.width + 6));
    const top = Math.max(8, r.top);
    pop.style.position = "fixed";
    pop.style.left = `${left}px`;
    pop.style.top  = `${top}px`;
    pop.style.width = `${W}px`;
    pop.style.zIndex = "10000";
    // Se va fuori dal viewport in basso → posiziona sopra
    requestAnimationFrame(() => {
        const pr = pop.getBoundingClientRect();
        if (pr.bottom > window.innerHeight - 8) {
            pop.style.top = `${Math.max(8, window.innerHeight - pr.height - 8)}px`;
        }
    });
}

/** Dopo modifica/creazione/eliminazione di una fonte, aggiorna tutti i
 *  `<select class="origin">` e il dropdown `.dropdown-content_gen` legacy. */
async function refreshOriginOptions() {
    // Invalida cache nello store centralizzato
    window.FM?.store?.set("cache.teacherSources", null);
    window.FM?.store?.set("cache.origins", null);
    const data = await loadTeacherSources({ force: true });
    const codes = Object.keys(data?.sources || {}).sort();
    // Rebuild <select.origin> su tutti i .fm-collection__item
    document.querySelectorAll(".fm-collection__item .origin").forEach((sel) => {
        const current = sel.value;
        sel.innerHTML = "";
        const def = document.createElement("option");
        def.value = "origine"; def.textContent = "origine";
        sel.appendChild(def);
        for (const c of codes) {
            const opt = document.createElement("option");
            opt.value = c; opt.textContent = c;
            sel.appendChild(opt);
        }
        if (codes.includes(current)) sel.value = current;
        sel.dataset.populated = "1";
    });
    // Rebuild dropdown legacy `.dropdown-content_gen` (iniettato da ui-comp.js).
    document.querySelectorAll("#infoVer .fm-selector-eser .fm-dropdown-content-gen").forEach((dd) => {
        dd.innerHTML = codes.map((v) =>
            `<label><input type="checkbox" class="fm-option-checkbox">`
            + `<a href="#" data-value="${v}">${v}`
            + `<i class="fas fa-edit edit-btn"></i>`
            + `<i class="fas fa-times fm-remove-btn"></i></a></label>`
        ).join("");
    });
}

/** Select "List" con 5 tipi di lista (replica legacy #listType). */
function buildListSelect(panel) {
    const sel = document.createElement("select");
    sel.title = "Inserisci lista HTML strutturata (preview render + LaTeX export)";
    sel.className = "fm-list-snippet-select";
    const options = [
        { value: "", label: "List" },
        { value: "ul",                label: "● ○ ■" },
        { value: "ul-arrow",          label: "➤ ♦ ●" },
        { value: "ul-star",           label: "★ ○ ■" },
        { value: "ol",                label: "1. a. i." },
        { value: "ol-Alpha",          label: "A. 1. a." },
        { value: "ol-alpha",          label: "a. i. 1." },
        { value: "ol-Roman",          label: "I. A. 1." },
        { value: "ol-zero",           label: "01. a. i." },
        { value: "ol-paren",          label: "1) a) i)" },
        { value: "ol-Alpha-paren",    label: "A) 1) a)" },
        { value: "ol-alpha-paren",    label: "a) i) 1)" },
        { value: "ol-Roman-paren",    label: "I) A) 1)" },
        { value: "ol-zero-paren",     label: "01) a) i)" },
    ];
    for (const o of options) {
        const opt = document.createElement("option");
        opt.value = o.value; opt.textContent = o.label;
        sel.appendChild(opt);
    }
    sel.addEventListener("change", () => {
        if (sel.value) insertListSnippet(panel, sel.value);
        sel.value = ""; // reset a "List"
    });
    return sel;
}

/**
 * Inserisce una lista HTML strutturata nel textarea attivo del panel.
 * Mappa: ul → <ul>, ol → <ol>, ol-paren → <ol type="1">, ol-Alpha → <ol type="A">,
 *        ol-alpha → <ol type="a">.
 *
 * Il save converte poi il textarea in blocchi contract via
 * _buildBlocksFromTextarea che parsa <ol>/<ul> in block list strutturato.
 */
function insertListSnippet(panel, kind) {
    const ta = panel?._focusedTextarea
        || panel?.querySelector?.(".fm-editor-field")
        || window.__fmFocusedTA;
    if (!ta) return;
    const fieldMap = { quesito: "question", soluzione: "solution", giustificazione: "justification" };
    const section = fieldMap[ta.dataset?.field] || "question";
    // Mappa kind → {tag, typeAttr, listStyle}
    //   tag: "ol" o "ul"
    //   typeAttr: HTML attr `type` (legacy single-level marker, "1"/"A"/"a"/"I"/"i")
    //   listStyle: data-fm-list-style preset gerarchico CSS-driven
    const HTML_TPL = {
        // Bullet
        "ul":                { tag: "ul", typeAttr: "", listStyle: "" },                    // ●→○→■
        "ul-arrow":          { tag: "ul", typeAttr: "", listStyle: "arrow-bullet" },        // ➤→♦→●
        "ul-star":           { tag: "ul", typeAttr: "", listStyle: "star-circle" },         // ★→○→■
        // Numerati con . (suffisso default browser)
        "ol":                { tag: "ol", typeAttr: "", listStyle: "" },                    // 1.→a.→i.
        "ol-Alpha":          { tag: "ol", typeAttr: "", listStyle: "alpha-decimal" },       // A.→1.→a.
        "ol-alpha":          { tag: "ol", typeAttr: "", listStyle: "lower-alpha-roman" },   // a.→i.→1.
        "ol-Roman":          { tag: "ol", typeAttr: "", listStyle: "roman-alpha" },         // I.→A.→1.
        "ol-zero":           { tag: "ol", typeAttr: "", listStyle: "decimal-zero" },        // 01.→a.→i.
        // Numerati con ) (custom marker via CSS counter)
        "ol-paren":          { tag: "ol", typeAttr: "", listStyle: "paren" },               // 1)→a)→i)
        "ol-Alpha-paren":    { tag: "ol", typeAttr: "", listStyle: "alpha-paren" },         // A)→1)→a)
        "ol-alpha-paren":    { tag: "ol", typeAttr: "", listStyle: "lower-alpha-paren" },   // a)→i)→1)
        "ol-Roman-paren":    { tag: "ol", typeAttr: "", listStyle: "roman-paren" },         // I)→A)→1)
        "ol-zero-paren":     { tag: "ol", typeAttr: "", listStyle: "decimal-zero-paren" },  // 01)→a)→i)
    };
    const cfg = HTML_TPL[kind];
    if (!cfg) return;

    if (ta.tagName === "TEXTAREA") {
        // Textarea path: stamp HTML al caret position (raw value injection).
        const typeAttrHtml = cfg.typeAttr ? ` type="${cfg.typeAttr}"` : "";
        const styleAttrHtml = cfg.listStyle ? ` data-fm-list-style="${cfg.listStyle}"` : "";
        const open = `<${cfg.tag} class="fm-dsa-li-list" data-dsa-section="${section}"${typeAttrHtml}${styleAttrHtml}><li></li></${cfg.tag}>`;
        _insertHtmlAtCaret(ta, open);
        ta.focus();
        ta.dispatchEvent(new Event("input", { bubbles: true }));
        return;
    }

    // contenteditable: comportamento standard wysiwyg.
    ta.focus();
    UndoManager.save(ta);  // snapshot pre-azione per Ctrl+Z
    _wysiwygInsertList(ta, cfg.tag, cfg.typeAttr, section, cfg.listStyle);
    ta.dispatchEvent(new Event("input", { bubbles: true }));
}

/** Lista (ol/ul) PIÙ ESTERNA che contiene `node` restando dentro `field`. */
function _outermostListInField(field, node) {
    let n = node, found = null;
    while (n && n !== field) {
        if (n.nodeType === 1 && /^(OL|UL)$/.test(n.tagName)) found = n;
        n = n.parentNode;
    }
    return found;
}

/**
 * Cambia IN PLACE tipo/marker di una lista esistente (no nuova lista).
 *  - stesso tag → aggiorna solo attributi (caret preservato)
 *  - tag diverso (ol↔ul) → rimpiazza l'elemento spostando i figli, caret nel 1° li
 */
function _changeListTypeInPlace(list, tag, typeAttr, section, listStyle) {
    const want = tag.toUpperCase();
    // Converte TUTTE le liste dell'albero (root + nested) al tag voluto e PULISCE
    // gli attributi preset sui nested: così SOLO il root porta data-fm-list-style
    // e il preset gerarchico (CSS `[data-fm-list-style] > li > ol/ul`) si applica
    // a OGNI livello. Prima: cambiava solo il root (i nested tenevano i loro
    // marker/tag → livelli annidati non aggiornati, specie su ol↔ul).
    const all = [list, ...list.querySelectorAll("ol, ul")];
    let newRoot = list, converted = false;
    for (const el of all) {
        let target = el;
        if (el.tagName !== want) {
            const nl = document.createElement(tag);
            if (el.className) nl.className = el.className;
            while (el.firstChild) nl.appendChild(el.firstChild);
            el.replaceWith(nl);
            target = nl;
            converted = true;
        }
        if (el === list) newRoot = target;
        if (!target.classList.contains("fm-dsa-li-list")) target.classList.add("fm-dsa-li-list");
        if (target !== newRoot) {          // nested: niente preset proprio
            target.removeAttribute("data-fm-list-style");
            target.removeAttribute("type");
        }
    }
    // applica preset/marker SOLO al root
    if (section) newRoot.setAttribute("data-dsa-section", section);
    if (listStyle) newRoot.setAttribute("data-fm-list-style", listStyle);
    else newRoot.removeAttribute("data-fm-list-style");
    if (typeAttr) newRoot.setAttribute("type", typeAttr);
    else newRoot.removeAttribute("type");
    if (converted) _placeCaretInFirstLi(newRoot);
}

/**
 * Logica wysiwyg standard per inserimento lista in un contenteditable.
 * Espande la selezione corrente alle "righe intere" coinvolte e le
 * sostituisce con una lista <ol>/<ul> con un <li> per riga.
 *
 * Una RIGA è definita come la sequenza di nodi inline tra due "boundary"
 * top-level del field (i boundary sono: `<br>`, `</div>`, `</p>`, oppure
 * inizio/fine field). Caratteristiche:
 *   - testo grezzo nel field root (no wrapper) → la "riga" è il segmento
 *     tra due `<br>` (o tra inizio field e primo `<br>`, ecc.)
 *   - <div>/<p> figlio diretto del field → la "riga" è il block stesso
 *
 * Casi:
 *   1. Caret in posizione vuota (no testo nella riga corrente)
 *      → lista con UN <li> vuoto, caret dentro
 *   2. Caret/selezione su 1 riga con testo
 *      → quella riga (intera, non solo selezione) diventa l'unico <li>
 *   3. Selezione che attraversa N righe (anche solo pezzi)
 *      → N <li> con il contenuto INTERO di ogni riga
 *
 * Riferimento legacy: ListManager.changeListType (functions-mod.js master).
 */
function _wysiwygInsertList(field, tag, typeAttr, section, listStyle) {
    const sel = window.getSelection();
    if (!sel || !sel.rangeCount) return;
    const range = sel.getRangeAt(0);
    if (!field.contains(range.startContainer)) {
        const list = _makeEmptyList(tag, typeAttr, section, 1, listStyle);
        field.appendChild(list);
        _placeCaretInFirstLi(list);
        return;
    }

    // Bug fix: se il caret è GIÀ dentro una lista, CAMBIA il tipo/preset di
    // quella lista (i suoi marker) invece di inserirne una nuova annidata.
    // Applica al contenitore lista PIÙ ESTERNO del field: i preset sono
    // gerarchici (data-fm-list-style sul root guida i marker di tutti i
    // livelli via CSS). Prima: veniva sempre creata una nuova lista.
    const outerList = _outermostListInField(field, range.startContainer);
    if (outerList) {
        _changeListTypeInPlace(outerList, tag, typeAttr, section, listStyle);
        return;
    }

    // Identifica le "righe top-level" del field, ognuna con range associato.
    const lines = _splitFieldIntoLines(field);
    if (!lines.length) {
        // Field completamente vuoto → lista 1 li
        const list = _makeEmptyList(tag, typeAttr, section, 1, listStyle);
        field.appendChild(list);
        _placeCaretInFirstLi(list);
        return;
    }

    // Trova le righe intersecate dal range. Per range collapsed (caret-only)
    // usa la stessa logica "start" per entrambi → evita off-by-one su brNode
    // separators tra righe vuote (es. caret tra due <br> consecutivi).
    const startLineIdx = _findLineForPoint(lines, range.startContainer, range.startOffset, "start", field);
    const endLineIdx = range.collapsed
        ? startLineIdx
        : _findLineForPoint(lines, range.endContainer, range.endOffset, "end", field);
    const fromIdx = Math.min(startLineIdx, endLineIdx);
    const toIdx = Math.max(startLineIdx, endLineIdx);
    const selectedLines = lines.slice(fromIdx, toIdx + 1);

    // Caso 1: 1 sola riga vuota → lista 1 li vuoto, inserita al posto della riga
    if (selectedLines.length === 1 && _lineIsEmpty(selectedLines[0])) {
        const list = _makeEmptyList(tag, typeAttr, section, 1, listStyle);
        const line = selectedLines[0];
        // Anchor: primo nodo della riga, OR il <br> separator se solo `<br>`.
        // Catturo nextSibling come reference STABILE prima del remove (gli anchor
        // verrebbero rimossi se sono nodes/brNode della riga stessa).
        let nextSiblingRef = null;
        const firstNode = line.nodes[0];
        if (firstNode?.parentNode === field) {
            nextSiblingRef = firstNode.nextSibling;
        } else if (line.brNode?.parentNode === field) {
            nextSiblingRef = line.brNode.nextSibling;
        }
        // Rimuovi nodi vuoti della riga + il <br> separator
        line.nodes.forEach((n) => n.parentNode?.removeChild(n));
        if (line.brNode?.parentNode === field) line.brNode.remove();
        // Insert prima del nextSibling stabile; fallback appendChild se null
        if (nextSiblingRef && nextSiblingRef.parentNode === field) {
            field.insertBefore(list, nextSiblingRef);
        } else {
            field.appendChild(list);
        }
        _placeCaretInFirstLi(list);
        return;
    }

    // Caso 2/3: 1 o più righe → 1 li per riga, contenuto intero.
    //
    // Strategia: inserisco la lista vuota AL POSTO GIUSTO (prima del primo
    // nodo della prima riga selezionata) PRIMA di muovere i nodi. Poi sposto
    // ogni nodo della riga nel suo <li>; gli appendChild rimuovono i nodi
    // dal field automaticamente. Infine rimuovo i `<br>` separatori orfani
    // (quelli tra righe consumate dalla lista).
    //
    // Vantaggi vs muovi-poi-inserisci:
    //  - la lista resta inserita al posto corretto anche se i nodi figli
    //    si spostano (le posizioni nel field cambiano).

    // Anchor della prima riga: primo nodo, OR <br> separator se riga vuota.
    let firstAnchor = selectedLines[0].nodes[0];
    if (!firstAnchor?.parentNode || firstAnchor.parentNode !== field) {
        firstAnchor = selectedLines[0].brNode || null;
    }
    const list = _makeEmptyList(tag, typeAttr, section, 0, listStyle);
    if (firstAnchor && firstAnchor.parentNode === field) {
        field.insertBefore(list, firstAnchor);
    } else {
        field.appendChild(list);
    }

    // Sposta i nodi delle righe nei rispettivi <li>. Per <div>/<p> top-level
    // unwrap il contenuto (vogliamo <li>X</li> non <li><div>X</div></li>).
    selectedLines.forEach((line) => {
        const li = document.createElement("li");
        line.nodes.forEach((n) => {
            if (n.nodeType === Node.ELEMENT_NODE
                && (n.tagName === "DIV" || n.tagName === "P")) {
                while (n.firstChild) li.appendChild(n.firstChild);
                n.remove();
            } else {
                li.appendChild(n);
            }
        });
        list.appendChild(li);
    });
    if (!list.children.length) list.appendChild(document.createElement("li"));

    // Rimuovi <br> separatori orfani: dopo aver consumato le righe N-1
    // adiacenti, ogni hasBrEnd ha lasciato un `<br>` orfano nel field.
    // Cerco tra prevSibling (br che chiudeva line[0] ed era SUBITO PRIMA del
    // suo firstNode → ora è SUBITO PRIMA della list) e nextSibling (br che
    // chiudeva l'ultima line consumata → SUBITO DOPO la list).
    const brsToConsume = selectedLines.filter((l) => l.hasBrEnd).length;
    let removed = 0;
    while (removed < brsToConsume) {
        const next = list.nextSibling;
        if (next && next.nodeType === Node.ELEMENT_NODE && next.tagName === "BR") {
            next.remove();
            removed++;
            continue;
        }
        break;
    }

    _placeCaretAtEnd(list.querySelector("li"));
}

/**
 * Splitta i child nodes top-level del field in "righe logiche".
 * Una riga = sequenza di nodi inline + eventuale `<br>` di chiusura,
 * OPPURE un singolo `<div>/<p>/<ol>/<ul>` block.
 *
 * Ritorna array di {nodes: Node[], hasBrEnd: boolean}.
 * I nodi NON sono rimossi; restano nel DOM (saranno spostati al replace).
 */
function _splitFieldIntoLines(field) {
    const lines = [];
    let current = [];
    const isBlock = (n) =>
        n.nodeType === Node.ELEMENT_NODE
        && /^(DIV|P|OL|UL|LI|BLOCKQUOTE|PRE|H[1-6]|TABLE)$/i.test(n.tagName);
    const isBr = (n) =>
        n.nodeType === Node.ELEMENT_NODE && n.tagName === "BR";

    for (const child of Array.from(field.childNodes)) {
        if (isBr(child)) {
            // <br> chiude la riga corrente. Traccia il `<br>` come `brNode`
            // così l'insert può usarlo come anchor quando `nodes[]` è vuoto
            // (riga vuota = solo separatore `<br>` senza testo).
            lines.push({ nodes: current, hasBrEnd: true, brNode: child });
            current = [];
        } else if (isBlock(child)) {
            // Block standalone è una riga propria. Flush la riga inline
            // accumulata se c'è.
            if (current.length > 0) {
                lines.push({ nodes: current, hasBrEnd: false });
                current = [];
            }
            lines.push({ nodes: [child], hasBrEnd: false, isBlock: true });
        } else {
            // text node o inline element
            current.push(child);
        }
    }
    if (current.length > 0) lines.push({ nodes: current, hasBrEnd: false });
    return lines;
}

/**
 * Trova l'indice della riga che contiene il punto (container, offset).
 *
 * Casi:
 *  - container è un nodo dentro una riga (text/element) → trova la riga via contains
 *  - container è il field stesso → usa offset per pescare il child corrispondente
 *  - which="end" e child === <br> dopo riga K → ritorna K (non la successiva)
 */
function _findLineForPoint(lines, container, offset, which, field) {
    // Normalizza: se container === field, il "vero" target è il childNodes[offset]
    // (o l'ultimo child se offset >= length).
    if (container === field) {
        if (which === "start") {
            const child = field.childNodes[offset] || field.childNodes[0];
            if (child) return _findLineByNode(lines, child);
            return 0;
        } else {
            // end: prendi childNodes[offset-1] (l'ultimo nodo INCLUSO nel range)
            const idx = Math.max(0, offset - 1);
            const child = field.childNodes[idx];
            if (child) return _findLineByNode(lines, child);
            return Math.max(0, lines.length - 1);
        }
    }
    return _findLineByNode(lines, container);
}

function _findLineByNode(lines, node) {
    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        for (const n of line.nodes) {
            if (n === node || (n.contains && n.contains(node))) {
                return i;
            }
        }
        // Controlla anche il <br> separator: caret posizionato AT/AFTER il br
        // (riga vuota) → matcha la riga che il br chiude.
        if (line.brNode && line.brNode === node) return i;
    }
    return Math.max(0, lines.length - 1);
}

function _lineIsEmpty(line) {
    return line.nodes.every((n) => {
        if (n.nodeType === Node.TEXT_NODE) return n.textContent.trim() === "";
        if (n.nodeType === Node.ELEMENT_NODE) return n.textContent.trim() === "";
        return true;
    });
}

/**
 * Sostituisce le righe selezionate con la lista (singolo elemento).
 * - Per righe inline: rimuove i nodi della riga e il <br> trailing
 * - Per blocchi standalone: replaceWith
 * - Inserisce la lista al posto della prima riga rimossa
 */
function _replaceLinesWithList(field, selectedLines, list) {
    const firstNode = selectedLines[0].nodes[0];
    const insertBefore = firstNode?.parentNode === field ? firstNode : null;

    // Rimuovi tutti i nodi delle righe selezionate (e i loro <br> trailing)
    selectedLines.forEach((line) => {
        line.nodes.forEach((n) => n.parentNode?.removeChild(n));
        // Rimuovi il <br> dopo questa riga, se esisteva
        if (line.hasBrEnd) {
            // Il <br> è un sibling che non è nei `nodes`; lo trovo cercando
            // il primo <br> tra il next-sibling del campo dove erano i nodi.
            // Strategy: NOP qui, l'abbiamo già escluso dai nodes; il <br>
            // originale è ancora nel field ma orfano della riga.
        }
    });

    // Cleanup <br> orfani che separavano le righe rimosse
    Array.from(field.childNodes)
        .filter((n) => n.tagName === "BR")
        .slice(0, selectedLines.filter((l) => l.hasBrEnd).length)
        .forEach((br, i) => {
            // best-effort: lascio i <br> che non separano righe nostre
        });

    if (insertBefore && insertBefore.parentNode === field) {
        field.insertBefore(list, insertBefore);
    } else {
        field.appendChild(list);
    }
    // Rimuovi <br> che ora sono orfani (consecutive br all'inizio o fine)
    while (list.previousSibling && list.previousSibling.nodeType === Node.ELEMENT_NODE
        && list.previousSibling.tagName === "BR") {
        list.previousSibling.remove();
    }
    while (list.nextSibling && list.nextSibling.nodeType === Node.ELEMENT_NODE
        && list.nextSibling.tagName === "BR"
        && selectedLines.some((l) => l.hasBrEnd)) {
        // se l'ultima riga selezionata aveva un <br> trailing, rimuovilo
        list.nextSibling.remove();
        break;
    }
}

// G24.refactor5.step4 — _makeEmptyList / _getEnclosingBlock / _fragmentToLines
// estratti in `editor/list-edit-utils.js`.
const _makeEmptyList     = makeEmptyList;
const _getEnclosingBlock = getEnclosingBlock;
const _fragmentToLines   = fragmentToLines;

// G24.refactor5.step3 — _placeCaretInFirstLi / _placeCaretAtEnd estratti.

// G24.refactor5.step4 — _insertHtmlAtCaret estratto in `editor/list-edit-utils.js`.
const _insertHtmlAtCaret = insertHtmlAtCaret;

/** Phase 16 — Dropdown TeX moderno: snippet inline comuni + gruppi dinamici
 *  da `/modelli_tikz.json` con accordion.
 *
 *  UI:
 *    ┌─ TeX ▾ ─────────────────────────┐
 *    │ ── Snippet comuni ──            │ (inline, always visible)
 *    │ [TeX \(\)] [TeX \[\]] [Frac] .. │
 *    │ ── Templates DB ──              │
 *    │ ▶ FISICA (6)                    │ (accordion, click to expand)
 *    │   cinematica 1D                 │
 *    │   Dati problema                 │
 *    │   ...                           │
 *    │ ▶ equ. di 2° grado (1)          │
 *    │ ▶ geometria (1)                 │
 *    │ ...                             │
 *    │ ── Azioni ──                    │
 *    │ [➕ Nuovo] [🗑️ Elimina] [✏️ Modifica] │
 *    └─────────────────────────────────┘
 *
 *  Click su elemento → fetch contenuto da cache JSON + insertSnippet.
 *  Action button → apre form modale (stub Phase 17 per ora).
 */
const INLINE_SNIPPETS = [
    ["\\( \\)",         (p) => wrapSnippet(p, "\\(", "\\)")],
    ["\\[ \\]",         (p) => wrapSnippet(p, "\\[", "\\]")],
    ["\\dfrac",         (p) => insertSnippet(p, "\\dfrac{}{}")],
    ["\\sqrt",          (p) => insertSnippet(p, "\\sqrt{}")],
    ["\\sum",           (p) => insertSnippet(p, "\\sum_{i=1}^{n}")],
    ["\\int",           (p) => insertSnippet(p, "\\int_{a}^{b}")],
    ["vmatrix",         (p) => insertSnippet(p, "\\begin{vmatrix} a & b \\\\ c & d \\end{vmatrix}")],
    ["array",           (p) => insertSnippet(p, "\\begin{array}{|c|c|}\\hline a & b \\\\\\hline\\end{array}")],
    ["cases",           (p) => insertSnippet(p, "\\begin{cases}\na \\\\\nb\n\\end{cases}")],
    ["V/F",             (p) => insertSnippet(p, "\\(\\text{V}\\,\\square\\quad \\text{F}\\,\\square\\quad \\) ")],
    ["\\textbf",        (p) => wrapSnippet(p, "\\textbf{", "}")],
    ["\\textit",        (p) => wrapSnippet(p, "\\textit{", "}")],
];


// G24.refactor5.step10 — makeSectionLabel estratto in `editor/tex-dropdown-helpers.js`.
// G24.faseE — buildTexDropdown / loadTexGroups (panel-only) / insertTikzContent
// rimossi: dead code legacy (l'unico chiamante era panel-level che non esiste
// più — sostituito da buildTexDropdownGlobal).

/* ─────────────────────────────────────────────────────────────────
 * G22.S15.bis — Modali CRUD per fm-tex-group / item:
 *   confirmDialog       — popup conferma (sostituisce await window.FM.Dialog.confirm())
 *   openGroupRenameDialog — input rinomina gruppo
 *   _openTexElementEditor — lazy CM6 + preview, mode new|edit
 * Tutti i dialoghi sono creati on-the-fly, una sola istanza alla
 * volta tramite #fm-confirm-dialog / #fm-group-rename-dialog (id univoci).
 * ───────────────────────────────────────────────────────────────── */

// G24.refactor5.step10 — confirmDialog estratto in `editor/tex-dropdown-helpers.js`.


/** Save backup del textarea in focus → sessionStorage. */
function saveBackupSnapshot(panel) {
    const ta = panel._focusedTextarea || panel.querySelector(".fm-editor-field");
    if (!ta) { toast("Nessun campo in focus", "warn"); return; }
    const item = panel.closest(".fm-collection__item");
    const id = item?.dataset?.id || "noid";
    const field = ta.dataset.field || "unknown";
    const ts = Date.now();
    const key = `fmv.backup.${id}`;
    let snapshots = [];
    try { snapshots = JSON.parse(sessionStorage.getItem(key) || "[]"); } catch {}
    snapshots.unshift({ ts, field, value: ta.value });
    // Mantieni solo ultimi 10
    snapshots = snapshots.slice(0, 10);
    try { sessionStorage.setItem(key, JSON.stringify(snapshots)); } catch {}
    toast(`Backup salvato (${field})`, "ok");
}

/** Dropdown "B_E" per restaurare backup. */
function buildBackupDropdown(panel) {
    const wrap = document.createElement("div");
    wrap.className = "fm-backup-dropdown";
    wrap.style.cssText = "position:relative;display:inline-block";
    const btn = document.createElement("button");
    btn.type = "button";
    btn.textContent = "⏪ ▾";
    btn.title = "Ripristina backup salvati (rollback snapshot dei textarea)";
    const menu = document.createElement("div");
    menu.style.cssText = "display:none;position:absolute;top:100%;right:0;background:#fff;border:1px solid #ccc;border-radius:3px;box-shadow:0 4px 12px rgba(0,0,0,0.15);z-index:1000;min-width:240px;max-height:300px;overflow-y:auto;padding:4px 0";

    btn.addEventListener("click", (e) => {
        e.preventDefault();
        const item = panel.closest(".fm-collection__item");
        const id = item?.dataset?.id || "noid";
        let snapshots = [];
        try { snapshots = JSON.parse(sessionStorage.getItem(`fmv.backup.${id}`) || "[]"); } catch {}
        menu.innerHTML = "";
        if (!snapshots.length) {
            const empty = document.createElement("div");
            empty.style.cssText = "padding:8px 12px;color:#999;font:12px system-ui;font-style:italic";
            empty.textContent = "Nessun backup salvato";
            menu.appendChild(empty);
        } else {
            snapshots.forEach((snap, i) => {
                const it = document.createElement("button");
                it.type = "button";
                const date = new Date(snap.ts).toLocaleString("it-IT", { dateStyle: "short", timeStyle: "medium" });
                const preview = (snap.value || "").slice(0, 40).replace(/\n/g, " ");
                it.innerHTML = `<strong>${snap.field}</strong> · ${date}<br><small style="color:#666">${escapeHtml(preview)}...</small>`;
                it.style.cssText = "display:block;width:100%;text-align:left;padding:6px 12px;border:none;border-bottom:1px solid #eee;background:transparent;cursor:pointer;font:12px/1.3 system-ui";
                it.addEventListener("mouseenter", () => (it.style.background = "#e8eeff"));
                it.addEventListener("mouseleave", () => (it.style.background = "transparent"));
                it.addEventListener("click", async (ev) => {
                    ev.preventDefault();
                    const ta = panel.querySelector(`[data-field="${CSS.escape(snap.field)}"]`);
                    if (ta && await window.FM.Dialog.confirm(`Ripristinare backup del ${date}?`)) {
                        ta.value = snap.value;
                        ta.dispatchEvent(new Event("input", { bubbles: true }));
                        toast("Backup ripristinato", "ok");
                    }
                    menu.style.display = "none";
                });
                menu.appendChild(it);
            });
        }
        menu.style.display = menu.style.display === "none" ? "block" : "none";
    });
    document.addEventListener("click", (e) => {
        if (!wrap.contains(e.target)) menu.style.display = "none";
    });
    wrap.appendChild(btn);
    wrap.appendChild(menu);
    return wrap;
}

// G22.S15.bis Fase 5+ — delegate canonical escHtml (full encoding).
// G24.refactor5.step1 — escapeHtml estratto in `editor/html-text-utils.js`.
// (definizione locale rimossa, alias dichiarato a top-file)

/**
 * Find & Replace dialog VS Code-like:
 *   - regex flag (.* support)
 *   - case-sensitive flag (Aa)
 *   - whole word flag (Ab)
 *   - in-selection flag (limita a selezione)
 *   - prev/next match navigation (F3/Shift+F3)
 *   - replace one / replace all
 *   - highlight TUTTE le occorrenze tramite <mark class="fm-fr-hit">
 *
 * @param {Element|Object} panel
 * @param {Object} [opts] {initialQuery, initialReplace}
 */
// G24.faseE — Find/replace highlight helpers solo internal al dialog (lazy).
// Alias `_setRangeAtOffsets` mappato a caret-utils per call-site monolite.
const _setRangeAtOffsets = setRangeAtOffsets;

/**
 * In contenteditable, seleziona Range corrispondente a [start, end] offset
 * sul textContent del field.
 */
// G24.refactor5.step3 — _ceSelectRange estratto in `editor/caret-utils.js`.

/** Inserisce testo alla position del cursore nel textarea in focus. */
function insertSnippet(panel, text) {
    const ta = panel._focusedTextarea || panel.querySelector(".fm-editor-field");
    if (!ta) return;
    const start = ta.selectionStart ?? ta.value.length;
    const end   = ta.selectionEnd   ?? ta.value.length;
    ta.value = ta.value.slice(0, start) + text + ta.value.slice(end);
    ta.focus();
    const pos = start + text.length;
    ta.setSelectionRange(pos, pos);
    ta.dispatchEvent(new Event("input", { bubbles: true })); // trigger preview
}

// G24.refactor5.step6 — inline-format estratto in `editor/inline-format.js`.
// Alias underscore preservano i call-site del monolite. wrapSnippet e
// insertLinkDialog sono importati direttamente (nomi non-prefixed).
// G24.cleanup — _normalizeInlineBlockNesting, _captureSelectionAsTextOffsets
// rimossi (dead alias post-extract inline-format).
const _toggleInlineFormat            = toggleInlineFormat;
const _insertEditableInlineBox       = insertEditableInlineBox;
const _wrapAsElement                 = wrapAsElement;

// G24.refactor5.step8b — Section builders semplici (4 funzioni) estratte in
// `editor/section-builders.js`. buildRmLayoutSection / buildSingleTableCard
// restano qui per via di molte cross-deps (rebuildRmTables, _syncCellsShape).

/** Phase 16 — RM layout controls con live DOM sync + per-table structure.
 *
 *  Model:
 *    rmLayout = {
 *      orientation: 'horizontal' | 'vertical',
 *      tables: [
 *        { rows, cols, typecell, mixtr, mixcol, mpagew, specificWidth },
 *        ...
 *      ]
 *    }
 *
 *  Global controls (top):
 *    - Numero tabelle (+/- → aggiunge/rimuove)
 *    - Orientamento tabelle (horizontal/vertical)
 *
 *  Per-table (card per indice):
 *    - Righe, Colonne
 *    - typecell (select con suggerimenti: |X|X|, |X|V|, |V|V|, |X|X|X|, ecc)
 *    - Mix righe, Mix colonne
 *    - Larghezza piena (mpagew flag), Larghezza specifica (px, sovrascrive mpagew)
 *
 *  Su OGNI change → `rebuildRmTables(item, rmLayout)` ricostruisce il DOM
 *  `.fm-rm-table` in-place (live preview).
 *
 *  G24.faseC-final — buildRmLayoutSection / buildSingleTableCard estratti
 *  in `editor/rm-layout-view.js` come factory con DI. Vedi `_rmLayoutView`.
 */
function extractCellContent(td) {
    return _extractCellContentShared(td);
}

/** Phase 16 — Carica le fonti personali del docente (cache via FM.store).
 *  Invalida automaticamente su saveTeacherSources (che aggiorna lo store). */
async function loadTeacherSources({ force = false } = {}) {
    const st = window.FM?.store;
    if (!force) {
        const cached = st?.get("cache.teacherSources");
        if (cached) return cached;
    }
    let data = { sources: {} };
    try {
        // memoFetchJson dedup chiamate parallele + TTL 30s.
        data = await window.FM.memoFetchJson("/api/teacher/sources.json", { force });
    } catch { /* ignore */ }
    st?.set("cache.teacherSources", data);
    return data;
}

async function saveTeacherSources(data) {
    const res = await fetch("/api/teacher/sources.json", {
        method: "PUT",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": getCsrfTokenSafe() },
        body: JSON.stringify(data),
    });
    const j = await res.json().catch(() => ({}));
    if (!res.ok || j?.error) throw new Error(j?.message || j?.error || `HTTP ${res.status}`);
    window.FM?.store?.set("cache.teacherSources", data);
    // Invalida memo cache: prossima loadTeacherSources legge fresh.
    window.FM?.invalidateMemo?.("/api/teacher/sources.json");
    return j;
}

function getCsrfTokenSafe() {
    return document.querySelector('meta[name="csrf-token"]')?.content || "";
}

/** Phase 16 — Aggrega le fonti attive dai `.fm-collection__item .origin` della pagina.
 *
 *  SCOPE: solo esercizi (NON `.fm-contract-wrap[data-kind="verifica"]` e
 *  NON dentro `#type_verAll`) — la sezione è dedicata agli studenti che
 *  vedono il riferimento bibliografico della pagina di esercizi corrente.
 *
 *  OUTPUT: se 1 fonte → "Fonte delle citazioni: <em>X</em>". Se >1 → bullet
 *  list `<ul>` con una fonte per riga. Se 0 o `auto_citations=false` → sezione
 *  vuota/nascosta.
 *
 *  Chiamata al load, fm:navigated, fm:mathjax-ready e su cambio origine. */
export async function refreshHeaderPageCitations() {
    const targets = document.querySelectorAll("#header_page .fm-source-citation");
    if (!targets.length) return;

    // Se il docente ha disattivato le citazioni automatiche → nascondi.
    const auto = window.FM?.store?.get("cache.headerPage")?.auto_citations !== false;
    if (!auto) {
        targets.forEach((el) => { el.innerHTML = ""; el.style.display = "none"; });
        return;
    }

    // Raccogli origin codes SOLO dai collex-item dell'esercizio (no verifica).
    const codes = new Set();
    document.querySelectorAll(".fm-collection__item .origin").forEach((sel) => {
        if (sel.closest('#type_verAll')) return;
        if (sel.closest('.fm-contract-wrap[data-kind="verifica"]')) return;
        const v = (sel.value || "").trim();
        if (v && v !== "origine") codes.add(v);
    });

    const data = await loadTeacherSources();
    const parts = [];
    for (const code of codes) {
        const s = data?.sources?.[code];
        if (!s) continue;
        const segs = [s.title, s.volume, s.publisher, s.authors].filter(Boolean);
        if (segs.length) parts.push(segs.join(" - "));
    }
    // Fallback: <meta name="fm-source-citation"> server-side (solo se siamo
    // fuori dallo scope verifica — quelli vanno ignorati anche qui).
    if (!parts.length) {
        document.querySelectorAll('meta[name="fm-source-citation"]').forEach((m) => {
            if (m.closest('#type_verAll')) return;
            if (m.closest('.fm-contract-wrap[data-kind="verifica"]')) return;
            const t = (m.content || "").trim();
            if (t && !parts.includes(t)) parts.push(t);
        });
    }

    targets.forEach((el) => {
        if (!parts.length) {
            el.innerHTML = "";
            el.style.display = "none";
            return;
        }
        el.style.display = "";
        if (parts.length === 1) {
            el.innerHTML = `<strong>Fonte delle citazioni:</strong> <em>${escHtml(parts[0])}</em>`;
        } else {
            const items = parts.map((p) => `<li><em>${escHtml(p)}</em></li>`).join("");
            el.innerHTML = `<strong>Fonte delle citazioni:</strong><ul class="fm-source-list">${items}</ul>`;
        }
    });
}

// G24.refactor5.step1 — escHtml estratto in `editor/html-text-utils.js`.
// (definizione locale rimossa, import attivo a top-file)

/** Phase 16 — Rebuild `.fm-badge` LaTeX source quando l'utente cambia origin.
 *
 *  Lookup priority:
 *    1. `/api/teacher/sources.json` — dictionary personale del docente:
 *       `{code -> {title, volume, publisher, authors}}` (editabile da toolbar).
 *    2. `/api/teacher/sources.registry.json` — registry legacy (array) come
 *       fallback per dati importati da vecchio CMS.
 *
 *  Entrambi cached su window.__fmTeacherSources + __fmSourcesRegistry. */
async function rebuildBadgeForOrigin(item, origin) {
    const badge = item.querySelector(".fm-badge");
    if (!badge) return;

    const common = await loadTeacherSources();

    // Cerca in SOURCES_COMMON (object by code key)
    let title = "", volume = "", authors = "";
    const s = common?.sources?.[origin];
    if (s) {
        title  = s.title || "";
        // Combina volume + publisher (es. "Vol.2 Ed.3 - ZANICHELLI")
        volume = s.volume && s.publisher ? `${s.volume} - ${s.publisher}`
               : (s.volume || s.publisher || "");
        authors = s.authors || "";
    } else {
        // Fallback: teacher registry (array format Phase 15)
        let registry = window.__fmSourcesRegistry;
        if (!registry) {
            try {
                const res = await fetch(`/api/teacher/sources.registry.json`, { credentials: "same-origin" });
                if (res.ok) registry = await res.json();
            } catch { /* ignore */ }
            window.__fmSourcesRegistry = registry || { sources: [] };
        }
        const src = (registry.sources || []).find(x => x.key === origin || x.short === origin);
        title   = src?.book    || origin;
        volume  = src?.volume  || "";
        authors = src?.authors || "";
    }

    const page  = badge.dataset.page   || "";
    const exNum = badge.dataset.exNum  || "";
    const bg    = badge.dataset.bgColor || "gray";
    const diff  = parseInt(badge.dataset.difficulty || "0", 10) || 0;
    const dots  = Array.from({ length: 4 }, (_, i) => i < diff ? "\\bullet" : "\\circ").join("");
    const book  = title;

    // Ricostruisci sorgente LaTeX
    let tex = "\\(";
    tex += "\\begin{array}{|c|}\\hline";
    if (book)    tex += `\\small{\\text{${escTexJs(book)}}}\\\\[-5pt]`;
    if (volume)  tex += `\\tiny{\\text{${escTexJs(volume)}}}\\\\[-5pt]`;
    if (authors) tex += `\\tiny{\\text{${escTexJs(authors)}}}\\\\[-5pt]`;
    tex += "\\hline\\end{array}\\quad";
    tex += `\\overset{\\color{red}\\huge ${dots}}{`;
    tex += `\\underset{\\text{P-}${escTexJs(page)}}{`;
    tex += `\\bbox[border: 1px solid white; background: ${bg},3pt]{`;
    tex += `{\\mathmakebox[cm][c]{\\textcolor{white}{\\large ${escTexJs(exNum)}}}}`;
    tex += "}}}\\quad\\)";

    badge.dataset.source = origin;
    badge.dataset.raw    = tex;
    badge.innerHTML      = tex;

    // Re-typeset MathJax sul badge
    if (window.MathJax?.typesetPromise) {
        try { await window.MathJax.typesetPromise([badge]); } catch (_) {}
    }
}

// G24.refactor5.step1 — escTexJs estratto in `editor/html-text-utils.js`.
// (definizione locale rimossa, import attivo a top-file)

/** Phase 16 — popup preview flottante al focus su cell textarea.
 *  Usa `position:fixed` (viewport-relative, nessuna necessità di scrollX/Y).
 *  Posizionato accanto al textarea, styling gestito da CSS (`.fm-cell-popup-preview`)
 *  per permettere dark mode override. */
// G24.refactor5.step9 — Cell popup preview estratto in
// `editor/cell-popup-preview.js`. Le funzioni sono importate direttamente
// (no alias underscore: le 5 funzioni hanno già nomi non-prefixed e
// pubblici nei call-site del monolite).

/** G23 — Re-export shared (single source of truth in `render/rm-table-view.js`). */
function syncCellsShape(t) {
    return _syncCellsShapeShared(t);
}

/** G23 — Helper input numerico con `.fm-editor-rmlayout` marker opzionale per
 *  far sì che `_captureEditorFields` raccolga il valore in `rmLayout.{name}`. */
function makeNumInput(label, name, value, min, max, onCh, opts = {}) {
    const w = document.createElement("label");
    w.style.cssText = "display:flex;flex-direction:column;gap:2px;font:12px/1.3 system-ui";
    const lbl = document.createElement("span");
    lbl.style.cssText = "font-weight:600;color:#333";
    lbl.textContent = label;
    const inp = document.createElement("input");
    inp.type = "number"; inp.min = String(min); inp.max = String(max); inp.step = "1";
    inp.value = String(value);
    inp.style.cssText = "padding:4px 6px;border:1px solid #ccc;border-radius:3px;font:13px/1 system-ui";
    if (opts.rmLayoutField) {
        inp.classList.add("fm-editor-rmlayout");
        inp.dataset.field = opts.rmLayoutField;
        if (opts.tableIdx != null) inp.dataset.tableIdx = String(opts.tableIdx);
    }
    inp.addEventListener("change", () => {
        const n = Math.max(min, Math.min(max, Number(inp.value) || min));
        inp.value = String(n);
        onCh(n);
    });
    w.appendChild(lbl);
    w.appendChild(inp);
    return w;
}

/** G23 — Ricostruisce le `.fm-rm-table` DOM dallo state editor usando il modulo
 *  centralizzato `render/rm-table-view.js`. Markup IDENTICO a server-side
 *  `ContractRenderer::renderRmTable()` (no `.rm-letter`, lettere derivate solo
 *  in TeX Sanitizer dall'indice cella).
 *
 *  Strategia preservazione contenuto:
 *    - Se cells vuoto al first-open: snapshot da existing `<td>` via
 *      `extractCellContent` (supporta entrambi markup legacy/moderno).
 *    - Se cells popolato (editor live): usa t.cells direttamente.
 *
 *  CorrectMask propagato dal cell DOM esistente (classe `rm-correct`) per
 *  preservare il flag "risposta corretta" durante il rebuild. */
function rebuildRmTables(state) {
    const item = state.item;
    if (!item) return;

    // Snapshot existing (per preservare correct flags + content non ancora in state.cells)
    const existingWrap  = item.querySelector(".fm-rm-tables-wrap");
    const existingTables = existingWrap
        ? Array.from(existingWrap.querySelectorAll(".fm-rm-table"))
        : Array.from(item.querySelectorAll(".fm-rm-table"));
    const existingCells = [];
    existingTables.forEach((tbl) => {
        tbl.querySelectorAll("td.rm-option").forEach((td) => existingCells.push(td));
    });

    // Snapshot correctMask per ogni tabella
    const correctMasks = state.tables.map((t, idx) => {
        const mask = [];
        for (let r = 0; r < t.rows; r++) {
            const row = [];
            for (let c = 0; c < t.cols; c++) {
                row.push(false);
            }
            mask.push(row);
        }
        const tblEl = existingTables[idx];
        if (tblEl) {
            tblEl.querySelectorAll("td.rm-option.rm-correct").forEach((td) => {
                const r = parseInt(td.dataset.row, 10);
                const c = parseInt(td.dataset.col, 10);
                if (!Number.isNaN(r) && !Number.isNaN(c) && mask[r]?.[c] !== undefined) {
                    mask[r][c] = true;
                }
            });
        }
        return mask;
    });

    // Phase 24.78 — Snapshot dei value N/T (soluzione docente) dalle tabelle
    // esistenti PRIMA del rebuild. Senza questo, ogni change:table (es. cambio
    // tipo colonna) ricostruiva il DOM perdendo i value digitati → l'utente
    // vedeva sparire i numeri/testi entrando/uscendo da edit mode.
    const valueMasks = state.tables.map((t, idx) => {
        const vmask = [];
        for (let r = 0; r < t.rows; r++) vmask.push(new Array(t.cols).fill(""));
        const tblEl = existingTables[idx];
        if (tblEl) {
            tblEl.querySelectorAll("td.rm-option").forEach((td) => {
                const r = parseInt(td.dataset.row, 10);
                const c = parseInt(td.dataset.col, 10);
                const inp = td.querySelector("input.fm-rm-text, input.fm-rm-num");
                if (inp && inp.value !== "" && vmask[r]?.[c] !== undefined) {
                    vmask[r][c] = inp.value;
                }
            });
        }
        return vmask;
    });

    // Riempie celle vuote con content da existing DOM (first-open fallback)
    let globalCellIdx = 0;
    state.tables.forEach((t) => {
        _syncCellsShapeShared(t);
        for (let r = 0; r < t.rows; r++) {
            for (let c = 0; c < t.cols; c++) {
                if (!(t.cells[r][c] && String(t.cells[r][c]).trim())) {
                    const ex = existingCells[globalCellIdx];
                    if (ex) t.cells[r][c] = _extractCellContentShared(ex);
                }
                globalCellIdx++;
            }
        }
    });

    // Rendering centralizzato (markup mirrored con server)
    const wrap = renderRmTablesWrap(state, { correctMasks, valueMasks });

    // Insert nel DOM: rimuovi vecchio wrap + inserisci nuovo
    const collex = item.querySelector(".fm-collection");
    const giust  = item.querySelector("details.fm-giustsol, .fm-giustsol");
    if (existingWrap) {
        existingWrap.replaceWith(wrap);
    } else {
        existingTables.forEach((t) => t.remove());
        if (giust) giust.parentNode.insertBefore(wrap, giust);
        else if (collex) collex.parentNode.insertBefore(wrap, collex.nextSibling);
        else item.appendChild(wrap);
    }

    if (window.MathJax?.typesetPromise) {
        window.MathJax.typesetPromise([wrap]).catch(() => {});
    }
    processTikzScripts(wrap).catch(() => {});
}

/** Cattura TUTTI i fields editabili di un `.fm-editor-panel` come PATCH payload.
 *  Single source of truth: usato da snapshot pre-edit, autosave server, save+close.
 *
 *  Include: textareas (.fm-editor-field), radio (.fm-editor-radio), meta
 *  (.fm-editor-meta → distribuiti in badge/metadata), options (.fm-editor-correct),
 *  RM layout (.fm-editor-rmlayout). */
function _captureEditorFields(panel) {
    if (!panel) return {};
    const fields = {};
    panel.querySelectorAll(".fm-editor-field").forEach((ta) => {
        fields[ta.dataset.field] = _buildBlocksFromTextarea(ta);
    });
    panel.querySelectorAll(".fm-editor-radio:checked").forEach((r) => {
        fields[r.dataset.field] = r.value;
    });
    // Meta inputs → distribuiti in badge sub-fields + metadata.category_label
    const meta = {};
    panel.querySelectorAll(".fm-editor-meta").forEach((inp) => {
        meta[inp.dataset.field] = inp.value;
    });
    if (Object.keys(meta).length) {
        const badge = {};
        if (meta.page !== undefined)       badge.page = String(meta.page);
        if (meta.ex_num !== undefined)     badge.ex_num = String(meta.ex_num);
        if (meta.bg_color !== undefined)   badge.bg_color = String(meta.bg_color);
        if (meta.difficulty !== undefined) badge.difficulty = parseInt(meta.difficulty, 10) || 0;
        if (Object.keys(badge).length) fields.badge = badge;
        const metadata = {};
        if (meta.category_label !== undefined) metadata.category_label = meta.category_label;
        if (Object.keys(metadata).length) fields.metadata = metadata;
    }
    // RM options (correct + content array) — legacy buildOptionSection path
    const options = [];
    panel.querySelectorAll(".fm-editor-correct").forEach((cb) => {
        const idx = Number(cb.dataset.optionIdx);
        options[idx] = { correct: cb.checked, content: fields[`option-${idx}`] || "" };
    });
    // Phase 22 — RM grid cells (`.fm-editor-field[data-field^="rm-cell-"]`) →
    // mappa su `options[]` con letter ordinata r*cols+c. Senza questo passo,
    // i contract patch non includevano il content delle celle e ContractRenderer
    // emergeva `Es1/Es2/Es3/Es4` placeholders (synthesizeRmOptions fallback).
    const rmCells = [];
    panel.querySelectorAll('.fm-editor-field[data-field^="rm-cell-"]').forEach((ta) => {
        const r = parseInt(ta.dataset.row, 10);
        const c = parseInt(ta.dataset.col, 10);
        if (Number.isNaN(r) || Number.isNaN(c)) return;
        // G23 — Forza dsa_section='options' su tutti i list block (top-level)
        // e 'sub' su nested. Senza, applier client riemette F/GF buttons per
        // contract con dsa_section="question" residuo (copy-paste cross-section).
        const cellBlocks = fields[ta.dataset.field] || [];
        _normalizeListSectionForCell(cellBlocks);
        rmCells.push({ r, c, content: cellBlocks });
        delete fields[ta.dataset.field];
    });
    if (rmCells.length) {
        // Derivo cols dal max(c)+1 (più affidabile di rmLayout in caso di edit live).
        const cols = Math.max(...rmCells.map(x => x.c)) + 1;
        rmCells.forEach(({ r, c, content }) => {
            const idx = r * cols + c;
            const letter = String.fromCharCode(97 + idx);
            options[idx] = {
                ...(options[idx] || {}),
                letter,
                correct: options[idx]?.correct ?? false,
                content,
            };
        });
    }
    if (options.length) fields.options = options.filter(Boolean);
    // G23 — RM layout strutturato:
    //   - field globali (table_count, orientation) → rmLayout.{field}
    //   - field per-tabella (`{key}_<idx>`, es. `rows_0`, `cols_0`, `typecell_0`,
    //     `mixtr_0`, `mixcol_0`, `mpagew_0`, `specificWidth_0`)
    //     → rmLayout.tables[idx].{key}
    //   - aggrega anche top-level rows/cols/typecell della prima tabella per
    //     backward-compat con renderer server (legge `rmLayout.rows/cols/typecell`).
    const rmLayout = { tables: [] };
    panel.querySelectorAll(".fm-editor-rmlayout").forEach((inp) => {
        const key = inp.dataset.field;
        const val = inp.type === "checkbox" ? inp.checked : inp.value;
        const m = key.match(/^([a-zA-Z]+)_(\d+)$/);
        if (m) {
            const fieldName = m[1];
            const tableIdx = parseInt(m[2], 10);
            while (rmLayout.tables.length <= tableIdx) rmLayout.tables.push({});
            const tbl = rmLayout.tables[tableIdx];
            if (inp.type === "number") {
                const n = parseInt(val, 10);
                tbl[fieldName] = Number.isFinite(n) ? n : val;
            } else if (inp.type === "checkbox") {
                tbl[fieldName] = !!val;
            } else {
                tbl[fieldName] = val;
            }
        } else {
            rmLayout[key] = val;
        }
    });
    // Top-level fallback dalla prima tabella (compat ContractRenderer attuale).
    if (rmLayout.tables[0]) {
        const t0 = rmLayout.tables[0];
        if (t0.rows != null)         rmLayout.rows = t0.rows;
        if (t0.cols != null)         rmLayout.cols = t0.cols;
        if (t0.typecell != null)     rmLayout.typecell = t0.typecell;
        if (t0.mixtr != null)        rmLayout.mixtr = t0.mixtr;
        if (t0.mixcol != null)       rmLayout.mixcol = t0.mixcol;
        if (t0.mpagew != null)       rmLayout.mpagew = t0.mpagew;
        if (t0.specificWidth != null) rmLayout.specificWidth = t0.specificWidth;
    }
    if (rmLayout.tables.length === 0) delete rmLayout.tables;
    if (Object.keys(rmLayout).length) fields.rmLayout = rmLayout;
    return fields;
}

/** Revert: PATCH server con snapshot pre-edit + applyEditsToDom + close.
 *  Se snapshot mancante, fallback close semplice (autosave già applicato). */
async function revertEditorChanges(item, panel) {
    const snapshot = item._fmPreEditSnapshot;
    if (!snapshot || Object.keys(snapshot).length === 0) {
        // Niente snapshot → fallback close semplice
        closeItemEditor(item);
        toggleEditMode(item, false);
        return;
    }
    if (!await window.FM.Dialog.confirm("Annullare tutte le modifiche fatte in questa sessione?")) return;
    const op = contractOpUrl(item, "patch");
    if (op) {
        const r = await apiPost(op.url, snapshot, { ifMatchVersion: op.version });
        if (r?.ok) {
            applyEditsToDom(item, snapshot);
            toast("Modifiche annullate", "ok");
            window.FM?.EditorDraft?.commit?.(item.dataset.id);
        } else {
            toast("Errore nel ripristino", "warn");
        }
    }
    delete item._fmPreEditSnapshot;
    closeItemEditor(item);
    toggleEditMode(item, false);
}

/** Flush autosave pendente (sync) e poi chiude editor. Garantisce che
 *  l'ultima modifica venga persistita prima del close (Google Docs-style).
 *
 *  G23.fix12 — Cancella PRIMA il timer autosave pendente per evitare race
 *  condition: se l'utente clicca Chiudi durante il debounce, il timer
 *  scaduto durante il save manuale lanciava una SECONDA PATCH concorrente
 *  con `ifMatchVersion` ormai obsoleto → 409 conflict + dati persi. */
async function _flushAutosaveAndClose(item, panel) {
    const draftApi = window.FM?.EditorDraft;
    // G23.fix12 — cancella timer pendente prima del save manuale
    draftApi?.cancelScheduled?.(item.dataset.id);
    try {
        await _saveItemEditorInPlace(item, panel);
        draftApi?.commit?.(item.dataset.id);
    } catch (_) { /* autosave silenzioso */ }
    delete item._fmPreEditSnapshot;
    closeItemEditor(item);
    toggleEditMode(item, false);
}

async function saveItemEditor(item, panel) {
    const id = item.dataset.id;
    const fields = _captureEditorFields(panel);
    // Save via contract-scoped patch (Phase 16 — optimistic locking).
    const op = contractOpUrl(item, "patch");
    const r = op
        ? await apiPost(op.url, fields, { ifMatchVersion: op.version })
        : { ok: false, skipped: true };
    if (r?.ok) {
        toast("Contenuto aggiornato", "ok");
        applyEditsToDom(item, fields);
        // G19.14 — commit: rimuovi draft dopo save success
        window.FM?.EditorDraft?.commit?.(id);
    } else if (r?.skipped) {
        toast("Modifiche salvate localmente (API edit in Phase 17)", "info");
        saveState(id, "edit", fields);
        applyEditsToDom(item, fields);
    } else {
        toast("Salvataggio differito", "warn");
    }
    closeItemEditor(item);
    toggleEditMode(item, false);
}

/** Helper: aggiorna container `<details>/<div>` con label preservato.
 *  Funziona sia con legacy `<summary>` che con nuovo `<strong class="fm-sol-label">`.
 *  Preserva l'eventuale `<div>` wrapper interno (caso `.fm-giustsol` di VF/RM che
 *  server-render emette come `<div class="fm-giustsol"><div>label + blocks</div></div>`).
 *  Senza questa preservazione, post-save il layout flex/css si rompeva. */
function _applyContainerWithLabel(container, blocks) {
    if (!container) return;
    // Trova target reale per innerHTML: se c'è un wrapper interno (div > strong/summary),
    // aggiornalo invece dell'outer (preserva struttura .fm-giustsol > <div> > ...).
    const inner = container.querySelector(":scope > div");
    const target = (inner && inner.querySelector("summary, .fm-sol-label")) ? inner : container;
    const label = target.querySelector("summary, .fm-sol-label")?.outerHTML || "";
    const sep = label && !label.includes("</summary>") ? " " : "";
    target.innerHTML = label + sep + _toHtml(blocks);
}

/** Registry FIELD_APPLIERS: mappa nome-campo → funzione DOM updater.
 *  Signature: `(item, val, allFields)` — `allFields` permette appliers
 *  cross-campo (es. `options` ha bisogno di `rmLayout` per rebuild RM table).
 *  Aggiungere un nuovo field = aggiungere una entry al registry, NO modifica
 *  applyEditsToDom (open-closed principle). */
const FIELD_APPLIERS = {
    quesito: (item, val) => {
        const collex = item.querySelector(".fm-collection");
        if (collex) collex.innerHTML = _toHtml(val);
    },
    soluzione: (item, val) => {
        _applyContainerWithLabel(item.querySelector("details.fm-sol, .fm-sol"), val);
    },
    giustificazione: (item, val) => {
        _applyContainerWithLabel(item.querySelector("details.fm-giustsol, .fm-giustsol"), val);
    },
    metadata: (item, val) => {
        if (val?.category_label !== undefined) {
            const cat = item.querySelector(".fm-titolo-quesito");
            if (cat) cat.textContent = val.category_label;
        }
    },
    badge: (item, val) => {
        if (val?.difficulty !== undefined) {
            const diff = Math.max(0, Math.min(4, Number(val.difficulty) || 0));
            item.className = item.className.replace(/\bdiff\d\b/, `diff${diff}`);
        }
    },
    // G23 — RM table: rebuild in-place dalla coppia (options, rmLayout).
    // Usa renderRmTablesWrap centralizzato (markup parity con server).
    options: (item, val, allFields) => {
        applyRmTableEdits(item, val, allFields?.rmLayout);
    },
    rmLayout: (item, val, allFields) => {
        // Evita double-apply: se options già processato in questa chiamata
        // (FIELD_APPLIERS iterato in ordine inserzione), rmLayout no-op.
        if (allFields?.options !== undefined) return;
        applyRmTableEdits(item, null, val);
    },
    // G23 — VF answer: flip class V/F del `.fm-sol.V|F` toggle senza reload.
    answer: (item, val) => {
        const sol = item.querySelector(".fm-wrapsol-vf .fm-sol");
        if (!sol) return;
        const cls = (val === "F") ? "F" : "V";
        sol.classList.remove("V", "F");
        sol.classList.add(cls);
        sol.setAttribute("title", `Risposta: ${cls}`);
    },
};

/** G23 — Applier RM table: ricostruisce `.fm-rm-tables-wrap` da contract data.
 *  Usato post-save (FIELD_APPLIERS.options) per evitare reload pagina.
 *
 *  G24.refactor3 — Invalida cache options[] (cell content cambiato) cosi'
 *  il prossimo click checkbox ricostruisce dal nuovo DOM. */
function applyRmTableEdits(item, options, rmLayout) {
    _invalidateRmOptionsCache(item);
    // Se options è null, leggo le opzioni correnti dal DOM (extract cella)
    let optsArr = options;
    if (!Array.isArray(optsArr)) {
        const tds = item.querySelectorAll(".fm-rm-table td.rm-option");
        // Phase 24.78 — riusa _rmCellDomToOption: cattura ANCHE op.value (N/T/B)
        // dagli input live. Senza, il re-render rmLayout-only (es. cambio tipo
        // colonna) azzerava i value-soluzione già impostati.
        optsArr = Array.from(tds).map((td, i) => _rmCellDomToOption(td, i));
    }
    // G23 — Normalizza dsa_section in tutti i content cells (retro-compat con
    // contract legacy che hanno dsa_section="question" salvato erroneamente).
    optsArr.forEach((op) => {
        if (Array.isArray(op?.content)) _normalizeListSectionForCell(op.content);
    });
    const state = _rmStateFromContract(optsArr, rmLayout || {}, (blocks) => _toHtml(blocks));
    // Rendering centralizzato — Phase 24.78: passa valueMasks per ripopolare gli
    // input N/T post-save (altrimenti uscendo da edit mode i value sparivano).
    const wrap = renderRmTablesWrap(state, { correctMasks: state._correctMasks, valueMasks: state._valueMasks });

    const existingWrap = item.querySelector(".fm-rm-tables-wrap");
    if (existingWrap) {
        existingWrap.replaceWith(wrap);
    } else {
        const giust  = item.querySelector("details.fm-giustsol, .fm-giustsol");
        const collex = item.querySelector(".fm-collection");
        if (giust) giust.parentNode.insertBefore(wrap, giust);
        else if (collex) collex.parentNode.insertBefore(wrap, collex.nextSibling);
        else item.appendChild(wrap);
    }
    if (window.MathJax?.typesetPromise) {
        window.MathJax.typesetPromise([wrap]).catch(() => {});
    }
    processTikzScripts(wrap).catch(() => {});
}

// G24.faseD-migration — Registra FIELD_APPLIERS nel registry centrale
// `EditorSession` (kind=item) per estensibilità open/closed. Domain modules
// (RM, TikZ, metadata) possono aggiungere nuovi field handler con
// `EditorSession.registerApplier("item", "foo", fn)` senza toccare il
// monolite. Il loop legacy in applyEditsToDom resta come fallback.
for (const [key, applier] of Object.entries(FIELD_APPLIERS)) {
    EditorSession.registerApplier("item", key, (target, value, session) => {
        // Adattamento signature: registry passa (target, value, session),
        // legacy applier accetta (item, val, allFields). Le call site
        // passano session.preEditSnapshot? No — allFields è il payload
        // corrente. Lo recuperiamo dal session se possibile, altrimenti
        // None (signature flessibile sui field "options" / "rmLayout").
        const allFields = session?._lastApplyFields || {};
        applier(target, value, allFields);
    });
}

/** Aggiorna il DOM con i fields salvati + re-esegue MathJax typeset.
 *  Itera il registry FIELD_APPLIERS — estendibile senza toccare questa funzione.
 *  G24.faseD-migration: se esiste session attiva per item, delega a
 *  session.applyToDOM() (che usa lo stesso registry). */
function applyEditsToDom(item, fields) {
    const session = EditorSession.for(item);
    if (session) {
        // Pass fields tramite session state per gli applier cross-campo
        // (options + rmLayout)
        session._lastApplyFields = fields;
        session.applyToDOM(fields);
        delete session._lastApplyFields;
    } else {
        for (const [key, applier] of Object.entries(FIELD_APPLIERS)) {
            if (fields[key] !== undefined) applier(item, fields[key], fields);
        }
    }
    // Rebuild .fm-badge LaTeX dopo edit badge (page/ex_num/bg_color/difficulty).
    // Senza questo, badge visivo non si aggiorna e GENERA in stessa sessione
    // emette vecchio data-raw (extractItemHtml lo legge).
    const badgeMeta = {
        ...(fields.badge || {}),
        ...(fields.metadata?.category_label !== undefined
            ? { category_label: fields.metadata.category_label }
            : {}),
    };
    if (Object.keys(badgeMeta).length > 0) {
        rebuildBadgeFromMetadata(item, badgeMeta).catch(() => {});
    }
    // MathJax typeset per aggiornare le formule LaTeX sostituite
    if (window.MathJax?.typesetPromise) {
        window.MathJax.typesetPromise([item]).catch(() => {});
    }
    // G22.S15 — render dei <script type="text/tikz"> appena iniettati
    // in .fm-collection / .fm-sol / .fm-giustsol via tikz-render-client (memo cache,
    // server-side compile). Senza questo, gli script restano inert nel
    // DOM e l'utente vede il pre-edit.
    processTikzScripts(item).catch((err) => {
        console.error("[applyEditsToDom] tikz render failed:", err);
    });
    // Recompute eventuali contenitori collapsible dopo che SVG cresce.
    if (window.FMCollapsible?.recompute) {
        setTimeout(() => window.FMCollapsible.recompute(), 800);
    }
}

/** G19.8 — Mirror della logica `ContractRenderer::renderBadge()` PHP in
 *  client-side. Aggiorna `.fm-badge` data-attributes E ricostruisce il
 *  `data-raw` LaTeX in modo che il prossimo `extractItemHtml()` (per
 *  GENERA) raccolga il badge aggiornato. Se il `.fm-badge` non esiste e
 *  arriva metadata valida, INJECT un nuovo badge come primo child di
 *  `.fm-li-inline` (stesso layout del server render).
 *
 *  Mappa `meta`:
 *    difficulty   (0-4)  → `\bullet × N + \circ × (4-N)`
 *    page         text   → `P-{page}` underset
 *    ex_num       text   → `\large {ex_num}` nel fcolorbox
 *    bg_color     name   → fill color del fcolorbox (xcolor compatible)
 *    category_label text → bg-color del .fm-titolo-quesito (gestito sopra)
 */
async function rebuildBadgeFromMetadata(item, meta) {
    let badge = item.querySelector(".fm-badge");

    // Se non c'è badge ma metadata valida → INJECT un nuovo .fm-badge.
    if (!badge && (meta.page || meta.ex_num || meta.difficulty)) {
        badge = document.createElement("span");
        badge.className = "fm-badge fm-latex";
        const liInline = item.querySelector(".fm-li-inline");
        if (liInline?.firstChild) liInline.insertBefore(badge, liInline.firstChild);
        else item.appendChild(badge);
    }
    if (!badge) return;

    // Aggiorna data-attributes con i nuovi valori (preserva quelli non specificati).
    if (meta.page !== undefined)        badge.dataset.page = String(meta.page);
    if (meta.ex_num !== undefined)      badge.dataset.exNum = String(meta.ex_num);
    if (meta.difficulty !== undefined)  badge.dataset.difficulty = String(meta.difficulty);
    if (meta.bg_color !== undefined)    badge.dataset.bgColor = String(meta.bg_color);

    // Lookup origin sources per il book/volume/authors header.
    const origin = badge.dataset.source || "";
    const common = await loadTeacherSources();
    let book = "", volume = "", authors = "";
    if (origin) {
        const s = common?.sources?.[origin];
        if (s) {
            book = s.title || "";
            volume = s.volume && s.publisher ? `${s.volume} - ${s.publisher}`
                   : (s.volume || s.publisher || "");
            authors = s.authors || "";
        }
    }

    const page  = badge.dataset.page   || "";
    const exNum = badge.dataset.exNum  || "";
    const bg    = badge.dataset.bgColor || "gray";
    const diff  = Math.max(0, Math.min(4, parseInt(badge.dataset.difficulty || "0", 10) || 0));
    const dots  = Array.from({ length: 4 }, (_, i) => i < diff ? "\\bullet" : "\\circ").join("");

    let tex = "\\(";
    if (book || volume || authors) {
        tex += "\\begin{array}{|c|}\\hline";
        if (book)    tex += `\\small{\\text{${escTexJs(book)}}}\\\\[-5pt]`;
        if (volume)  tex += `\\tiny{\\text{${escTexJs(volume)}}}\\\\[-5pt]`;
        if (authors) tex += `\\tiny{\\text{${escTexJs(authors)}}}\\\\[-5pt]`;
        tex += "\\hline\\end{array}\\quad";
    }
    tex += `\\overset{\\color{red}\\huge ${dots}}{`;
    tex += `\\underset{\\text{P-}${escTexJs(page)}}{`;
    tex += `\\bbox[border: 1px solid white; background: ${bg},3pt]{`;
    tex += `{\\mathmakebox[cm][c]{\\textcolor{white}{\\large ${escTexJs(exNum)}}}}`;
    tex += "}}}\\quad\\)";

    badge.dataset.raw = tex;
    badge.innerHTML   = tex;

    if (window.MathJax?.typesetPromise) {
        try { await window.MathJax.typesetPromise([badge]); } catch (_) {}
    }
}

async function deleteItem(item) {
    const id = item.dataset.id;
    // Phase 16 — prima di rimuovere il nodo, cancella gli SVG TikZ associati
    // (path pattern: svg/tikz_{id}__{seq}.svg). Best-effort, fallisce silent.
    const svgs = Array.from(item.querySelectorAll("svg[data-fm-svg-path]"));
    for (const svg of svgs) {
        const path = svg.dataset.fmSvgPath;
        if (path) {
            const parts = path.split("/");
            const fileName = parts.pop();
            const filePath = parts.join("/");
            deleteTikzSvg(filePath, fileName);
        }
    }
    const op = contractOpUrl(item, "delete");
    if (!op) {
        item.remove();
        toast(`Quesito #${id} rimosso localmente`, "info");
        return;
    }
    try {
        const r = await apiPost(op.url, {}, { ifMatchVersion: op.version });
        if (r?.ok) {
            item.remove();
            toast(`Quesito #${id} eliminato`, "ok");
        } else if (r?.skipped) {
            item.remove();
            toast("Quesito rimosso localmente", "info");
        } else {
            item.style.opacity = "0.3";
            toast("Eliminazione differita", "warn");
        }
    } catch {
        toast("Errore eliminazione", "err");
    }
}

/** Sposta item di ±1 tra i fratelli (all'interno dello stesso .collexercise). */
function moveItem(item, delta) {
    const sib = delta < 0 ? item.previousElementSibling : item.nextElementSibling;
    if (!sib?.classList?.contains("fm-collection__item")) return;
    if (delta < 0) item.parentNode.insertBefore(item, sib);
    else item.parentNode.insertBefore(sib, item);
    populatePositionInputs(item.closest(".fm-groupcollex") || document);
}

/** Phase 16 — muove un .fm-collection__item alla posizione 1-based specificata,
 *  all'interno del suo .collexercise genitore. */
function moveItemToPosition(item, pos) {
    const container = item.parentElement;
    if (!container) return;
    const siblings = [...container.children].filter((el) => el.classList.contains("fm-collection__item"));
    const total = siblings.length;
    if (!total) return;
    const targetIdx = Math.min(Math.max(pos, 1), total) - 1;
    const currentIdx = siblings.indexOf(item);
    if (targetIdx === currentIdx) return;
    const ref = siblings[targetIdx];
    if (targetIdx > currentIdx) ref.after(item);
    else container.insertBefore(item, ref);
    populatePositionInputs(item.closest(".fm-groupcollex") || document);
}

/** Phase 16 — muove un .fm-groupcollex alla posizione 1-based specificata, tra
 *  fratelli con la stessa classe nel container (.fm-draggable-container o altro). */
function moveProblemToPosition(problem, pos) {
    const container = problem.parentElement;
    if (!container) return;
    const siblings = [...container.children].filter((el) => el.classList.contains("fm-groupcollex"));
    const total = siblings.length;
    if (!total) return;
    const targetIdx = Math.min(Math.max(pos, 1), total) - 1;
    const currentIdx = siblings.indexOf(problem);
    if (targetIdx === currentIdx) return;
    const ref = siblings[targetIdx];
    if (targetIdx > currentIdx) ref.after(problem);
    else container.insertBefore(problem, ref);
    populatePositionInputs(document);
}

/** Phase 16 — popola `.move-position-problem` (livello .fm-groupcollex) e
 *  `.move-position` (livello .fm-collection__item) con la loro posizione 1-based.
 *  Sostituisce il placeholder "#" del markup server. Chiamata al load, a
 *  fm:navigated e dopo ogni riordino. */
function populatePositionInputs(root = document) {
    // Problem-level: per ciascun parent, numera i .fm-groupcollex diretti.
    // `.move-position-problem` è ora dentro `.checkmod` della `.fm-collapsible`
    // (sub-descendant del .fm-groupcollex) — uso descendant selector ma filtro
    // `closest(".fm-groupcollex") === p` per evitare match di .fm-groupcollex annidati.
    const parents = new Set();
    root.querySelectorAll(".fm-groupcollex").forEach((p) => p.parentElement && parents.add(p.parentElement));
    parents.forEach((container) => {
        const problems = [...container.children].filter((el) => el.classList.contains("fm-groupcollex"));
        problems.forEach((p, i) => {
            const input = p.querySelector(".fm-move-position-problem");
            if (input && input.closest(".fm-groupcollex") === p) input.value = String(i + 1);
        });
    });
    // Item-level: per ciascun .fm-groupcollex, numera i .fm-collection__item.
    root.querySelectorAll(".fm-groupcollex").forEach((problem) => {
        problem.querySelectorAll(".fm-collection__item").forEach((it, i) => {
            const input = it.querySelector(".fm-move-position");
            if (input) input.value = String(i + 1);
        });
    });
}

async function syncItem(item) {
    const id = item.dataset.id;
    // Skip synthetic IDs (es. "cx_xxx_0" / "groupId_q0") — l'endpoint
    // /api/study/content/{id}.json risolve solo numeric DB ids, evita 404.
    if (!/^\d+$/.test(String(id))) {
        toast(`Sync non disponibile (id locale #${id})`, "info");
        return;
    }
    try {
        const res = await fetch(`/api/study/content/${id}.json`, { credentials: "same-origin" });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const j = await res.json();
        toast(`Quesito #${id} sincronizzato (${j.content?.updated_at || "ok"})`, "ok");
    } catch (e) {
        toast(`Errore sync: ${e.message || e}`, "err");
    }
}

/** Stato A/R/pt/origin/color per-quesito in sessionStorage (chiave fmv.{id}). */
function saveState(id, key, val) {
    const k = `fmv.${id}`;
    let s = {};
    try { s = JSON.parse(sessionStorage.getItem(k) || "{}"); } catch {}
    s[key] = val;
    try { sessionStorage.setItem(k, JSON.stringify(s)); } catch {}
}

/** Phase 16 — popola i `<select class="origin">` server-rendered con i
 *  codici di `/api/teacher/sources.json` (sources keys = origini conosciute
 *  dal docente). Niente più origins.json statico: la lista è derivata dalle
 *  fonti personali, quindi ciascun docente vede solo le proprie.
 *
 *  Cache su `FM.store.cache.origins`. `data-current` → preselect. */
export async function populateOriginSelects() {
    // Seleziona tutti, inclusi già-populati se attualmente vuoti (retry):
    const allSelects = document.querySelectorAll(".fm-collection__item .origin");
    const selects = Array.from(allSelects).filter((s) => {
        return !s.dataset.populated || s.options.length <= 1;
    });
    if (!selects.length) return;
    const st = window.FM?.store;
    let origins = st?.get("cache.origins");
    if (!Array.isArray(origins) || origins.length === 0) {
        const data = await loadTeacherSources({ force: origins?.length === 0 });
        origins = Object.keys(data?.sources || {}).sort();
        st?.set("cache.origins", origins);
    }
    // Build options fragment una volta sola
    const frag = document.createDocumentFragment();
    const defOpt = document.createElement("option");
    defOpt.value = "origine"; defOpt.textContent = "origine";
    frag.appendChild(defOpt);
    for (const o of origins) {
        const opt = document.createElement("option");
        opt.value = o; opt.textContent = o;
        frag.appendChild(opt);
    }
    selects.forEach((sel) => {
        sel.innerHTML = "";
        sel.appendChild(frag.cloneNode(true));
        sel.dataset.populated = "1";
        const current = sel.dataset.current || "";
        if (current) {
            // Phase 24.77 — se la fonte salvata del quesito NON è tra le origini
            // del docente, aggiungila comunque come opzione così il select
            // mostra l'origine corrente (prima cadeva sul placeholder "origine").
            if (!Array.from(sel.options).some((o) => o.value === current)) {
                const opt = document.createElement("option");
                opt.value = current; opt.textContent = current;
                sel.appendChild(opt);
            }
            sel.value = current;
        } else {
            sel.value = "origine";
        }
    });
}

/** Ripristina stato salvato: chiama dopo che .checkIN è iniettato. */
export function restoreCheckinState() {
    document.querySelectorAll(".fm-collection__item[data-id]").forEach((item) => {
        const id = item.dataset.id;
        let s;
        try { s = JSON.parse(sessionStorage.getItem(`fmv.${id}`) || "{}"); } catch { return; }
        if (s.A) {
            const a = item.querySelector(".fm-checkbox-ain");
            if (a) a.checked = !!s.A;
        }
        if (s.B) {
            const b = item.querySelector(".fm-checkbox-bin");
            if (b) b.checked = !!s.B;
        }
        if (s.pt) {
            const p = item.querySelector(".fm-input-pt");
            if (p) p.value = s.pt;
        }
        if (s.origin) {
            const o = item.querySelector(".origin");
            if (o) o.value = s.origin;
        }
        if (s.color) {
            const c = item.querySelector(".fm-color-select");
            if (c) c.value = s.color;
        }
    });
}

/** POST helper con optimistic locking. Se `ifMatchVersion` è fornito,
 *  include header `If-Match: "v<N>"` (Step 2). Su 409 → warning toast +
 *  retry automatico 1 volta ricaricando la version dal wrapper aggiornato.
 *  Skip se l'id del contract nell'URL non è numerico. */
/** G24.refactor2 — apiPost ora supporta `silent` flag: se true, il 409
 *  retry silenzioso (autosave background). Se false (save manuale),
 *  dialog "Reload / Overwrite" per decisione informata. */
async function apiPost(url, body, { ifMatchVersion = null, _retry = false, silent = false } = {}) {
    const idMatch = url.match(/\/content\/(\d+)\//);
    if (!idMatch && url.includes("/content/")) {
        // synthetic id come primo segmento → skip (contract-level id assente)
        return { ok: false, status: 0, skipped: true };
    }
    try {
        const csrf = await fetchCsrf();
        // URLSearchParams non serializza nested objects → JSON-stringify
        // esplicito per options/metadata/rmLayout. Il backend accetta
        // stringhe che iniziano per `[` o `{` e le decodifica.
        const data = new URLSearchParams();
        data.set("_csrf", csrf);
        for (const [k, v] of Object.entries(body || {})) {
            if (v != null && typeof v === "object") {
                data.set(k, JSON.stringify(v));
            } else if (v != null) {
                data.set(k, String(v));
            }
        }
        const headers = { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" };
        if (ifMatchVersion != null) headers["If-Match"] = `"v${ifMatchVersion}"`;
        const res = await fetch(url, {
            method: "POST", headers, body: data.toString(), credentials: "same-origin",
        });
        if (res.status === 404) return { ok: false, status: 404, skipped: true };
        if (res.status === 409 && !_retry) {
            // G24.faseB.2 — Conflict resolution via Strategy pattern.
            // silent=true → SilentRetryStrategy; silent=false → InteractivePromptStrategy.
            // Vedi `editor/conflict-resolver.js`.
            const j = await res.json().catch(() => ({}));
            const actual = (j && Number.isFinite(j.actual)) ? j.actual : null;
            const decision = resolveByMode(silent, { url, body, actual });
            if (decision.action === "retry") {
                const wrap = findWrapperByUrlContract(url);
                if (wrap) wrap.dataset.version = String(decision.ifMatchVersion);
                return apiPost(url, body, {
                    ifMatchVersion: decision.ifMatchVersion,
                    _retry: true,
                    silent: true,  // retry sempre silent (post-decision già presa)
                });
            }
            if (decision.action === "reload") {
                if (typeof window !== "undefined") window.location.reload();
                return { ok: false, status: 409 };
            }
            // action === "abort" (no actual version disponibile)
            toast("Conflitto di versione — ricarica la pagina", "warn");
            return { ok: false, status: 409 };
        }
        if (!res.ok) return { ok: false, status: res.status };
        const j = await res.json();
        // Sincronizza version sul wrapper DOM così la prossima write usa il valore corrente.
        if (Number.isFinite(j?.version)) {
            const wrap = findWrapperByUrlContract(url);
            if (wrap) wrap.dataset.version = String(j.version);
        }
        return j;
    } catch {
        return null;
    }
}

function findWrapperByUrlContract(url) {
    const m = url.match(/\/content\/(\d+)\//);
    if (!m) return null;
    return document.querySelector(`.fm-contract-wrap[data-id="${m[1]}"]`);
}

/** Helper: per un `.fm-collection__item`, costruisci l'URL contract-scoped per
 *  un'operazione `op` (patch/delete/move) + ritorna anche la version
 *  corrente da includere in If-Match.
 *
 *  Se `item.dataset.id` è assente o è un id sintetico client (`cx_*`,
 *  generato da `UIComp._ensureCollexItemIds`), fallback al locator di
 *  posizione `g<gi>_q<ii>` supportato da ContractAggregate::findItemIndex
 *  (index del .fm-groupcollex dentro il wrap + index della collex-item dentro il
 *  .fm-groupcollex). Questo permette di salvare anche quando il contract non ha
 *  ancora assegnato UUID stabili agli items. */
function contractOpUrl(item, op) {
    const wrap = item.closest(".fm-contract-wrap");
    if (!wrap) return null;
    const contractId = wrap.dataset.id;
    if (!/^\d+$/.test(contractId || "")) return null;
    let raw = (item.dataset.id || "").trim();
    if (!raw || /^cx_/.test(raw)) {
        const problem = item.closest(".fm-groupcollex");
        if (!problem) return null;
        const problems = Array.from(wrap.querySelectorAll(".fm-groupcollex"));
        const gi = problems.indexOf(problem);
        const items = Array.from(problem.querySelectorAll(".fm-collection__item"));
        const ii = items.indexOf(item);
        if (gi < 0 || ii < 0) return null;
        raw = `g${gi}_q${ii}`;
    }
    const itemRef = encodeURIComponent(raw);
    const version = parseInt(wrap.dataset.version || "0", 10) || 0;
    return {
        url: `/api/teacher/content/${contractId}/quesito/${itemRef}/${op}`,
        version,
    };
}

function toast(msg, kind = "ok") {
    // FM.ToastManager.show(type, title, message, duration) — map kind→type + titles IT
    const tm = window.FM?.ToastManager;
    if (tm?.show) {
        const map = { ok: ["success", "OK"], warn: ["warning", "Attenzione"], err: ["error", "Errore"], info: ["info", "Info"] };
        const [type, title] = map[kind] || ["info", "Info"];
        try { tm.show(type, title, String(msg)); return; } catch { /* fallthrough */ }
    }
    const el = document.createElement("div");
    el.textContent = msg;
    el.style.cssText =
        `position:fixed;bottom:20px;right:20px;z-index:9999;padding:10px 14px;border-radius:6px;color:#fff;` +
        `font:14px/1.4 system-ui;max-width:360px;box-shadow:0 6px 18px rgba(0,0,0,.35);${ 
        kind === "err" ? "background:#c02a2a" : kind === "warn" ? "background:#c78a2a"
            : kind === "info" ? "background:#2a5ac7" : "background:#1d7a3a"}`;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 4200);
}

/** Phase 16 — `body.fm-upbar-hidden` toggle observer: permette al CSS
 *  `body.fm-upbar-hidden #scrollbarInfo { top: 0 }` di applicarsi.
 *  La logica sticky stacking è delegata a `features/verifica-sticky.js`
 *  (replica legacy `updateStickyTops`). */
function bindUpbarClassMirror() {
    const upbar = document.querySelector(".fm-upbar");
    if (!upbar) return;
    const sync = () => {
        const hidden = upbar.classList.contains("upbar-hidden");
        document.body.classList.toggle("fm-upbar-hidden", hidden);
    };
    sync();
    new MutationObserver(sync).observe(upbar, {
        attributes: true, attributeFilter: ["class"],
    });
}

/** Phase 24.77 — Ridistribuzione punti VERO/FALSO sulla pagina
 *  /studio/esercizio. `utilities.initializeVFProblems()` rende editabili i
 *  totali punti (`.total-pointsA/B` span → input numerico) e ridistribuisce
 *  i punti tra i quesiti selezionati (`.fm-checkbox-ain/-bin:checked`),
 *  aggiornando `#SumPtotA/#SumPtotB` nella topbar. I suoi caller originali
 *  (ui-comp `finalizeReservedSetup`, state-manager) NON girano su questa
 *  pagina, quindi la innesciamo qui. Idempotente: initializeVFProblems salta
 *  i gruppi già provvisti di `.fm-vf-total-points-input`. Gated al contesto
 *  editing (teacher/admin): i totali sono uno strumento docente. */
function initVFProblemsOnPage() {
    if (!document.body.classList.contains("fm-admin-access")
        && !document.body.classList.contains("fm-teacher-access")) return;
    if (!document.querySelector('.fm-groupcollex[id*="type_VF"]')) return;
    const u = window.FM?.utilities || window.utilities;
    try { u?.initializeVFProblems?.(); } catch (_) { /* no-op */ }
}

const onInit = () => {
    bindCheckinHandlers();
    populatePositionInputs();
    bindUpbarClassMirror();
    bindSourceEditDelegation();
    // Phase 24.77 — VF point redistribution. Il contract può renderizzare
    // lazy (post-DOMContentLoaded / fm:navigated); ritentiamo a breve oltre
    // al pass immediato e al typeset MathJax (vedi handler fm:mathjax-ready).
    initVFProblemsOnPage();
    setTimeout(initVFProblemsOnPage, 250);
    setTimeout(initVFProblemsOnPage, 800);
    // Popola `.origin-selector > select.origin` da `/api/teacher/sources.json`.
    // Retry async: se la fetch fallisce inizialmente, riparte al prossimo
    // fm:navigated (onInit è idempotente, re-populate refresha selects vuoti).
    populateOriginSelects()
        .then(() => refreshHeaderPageCitations())
        .catch(() => { /* silent: retry next navigation */ });
    // Re-aggrega le citazioni anche dopo typeset MathJax (contenuto async).
    window.addEventListener("fm:mathjax-ready", () => {
        refreshHeaderPageCitations().catch(() => {});
        _fixGeoGebraSvgViewBox(document);
        initVFProblemsOnPage();
    });
    // G22.S15.bis Fase 5 — fix SVG GeoGebra senza viewBox.
    //   GeoGebra esporta SVG con `width="843" height="603"` ma SENZA viewBox.
    //   Senza viewBox, il CSS `width: 100%` ridimensiona l'elemento ma il
    //   contenuto interno resta ancorato a coordinate native (0..843×0..603)
    //   → clipping. Iniettiamo viewBox=`0 0 W H` derivato da width/height.
    //   Pass 1: scansione iniziale; Pass 2: MutationObserver per inserzioni
    //   asincrone (contract render lazy / fm:navigated re-render).
    _fixGeoGebraSvgViewBox(document);
    _startGeoGebraSvgObserver();
};

function _fixGeoGebraSvgViewBox(root) {
    const svgs = root.querySelectorAll('.fm-geogebra-wrap svg');
    let fixed = 0;
    svgs.forEach(svg => {
        if (svg.hasAttribute('viewBox') || svg.hasAttribute('viewbox')) return;
        const w = svg.getAttribute('width');
        const h = svg.getAttribute('height');
        if (!w || !h) return;
        const wn = parseFloat(w), hn = parseFloat(h);
        if (!isFinite(wn) || !isFinite(hn) || wn <= 0 || hn <= 0) return;
        svg.setAttribute('viewBox', `0 0 ${wn} ${hn}`);
        svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
        fixed++;
    });
    if (fixed > 0 && typeof console !== 'undefined') {
        console.debug(`[fm-ggb] viewBox iniettato su ${fixed} SVG GeoGebra`);
    }
    return fixed;
}

// G22.S15.bis Fase 5 — MutationObserver: SVG GeoGebra può essere iniettato
// in modo asincrono (contract render lazy, fm:navigated, lazy-load images).
// onInit gira a DOMContentLoaded ma trova spesso solo placeholder. Watcher
// applica il fix non appena un nuovo .fm-geogebra-wrap appare nel DOM.
let _ggbObserver = null;
function _startGeoGebraSvgObserver() {
    if (_ggbObserver) return;
    if (typeof MutationObserver !== "function") return;
    _ggbObserver = new MutationObserver((mutations) => {
        for (const m of mutations) {
            for (const n of m.addedNodes) {
                if (n.nodeType !== 1) continue;
                if (n.matches?.('.fm-geogebra-wrap, .fm-geogebra-wrap svg')) {
                    _fixGeoGebraSvgViewBox(n.parentElement || document);
                } else if (n.querySelector?.('.fm-geogebra-wrap svg')) {
                    _fixGeoGebraSvgViewBox(n);
                }
            }
        }
    });
    _ggbObserver.observe(document.body, { childList: true, subtree: true });
}

// Auto-init (idempotente via dataset flag)
if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", onInit);
} else {
    onInit();
}
window.addEventListener("fm:navigated", onInit);

window.FM = window.FM || {};
window.FM.fixGeoGebraSvgViewBox = _fixGeoGebraSvgViewBox;
window.FM.bindCheckinHandlers = bindCheckinHandlers;
window.FM.restoreCheckinState = restoreCheckinState;
// G27.dsa.persist — esposto per dsa-marks.js: patcha contract item (mark
// + dsa_marks) sul server in modo silenzioso (silent retry su 409).
window.FM.patchContractItem = patchContractItem;
window.FM.populateOriginSelects = populateOriginSelects;
window.FM.applyTopicColorCycle = applyTopicColorCycle;
window.FM.populatePositionInputs = populatePositionInputs;
window.FM.refreshHeaderPageCitations = refreshHeaderPageCitations;
// G23.fix4 — Init centralizzato serializer field (load+save uniformi).
FieldSerializer.init({
    extractRawWithTikz: _extractRawWithTikz,
    buildBlocksFromTextarea: _buildBlocksFromTextarea,
    toHtml: _toHtml,
});
window.FM.FieldSerializer = FieldSerializer;
// Esposti SOLO per E2E test (vedi tests/e2e/list_button_html_render.spec.js):
window.FM.__toHtmlForTest = _toHtml;
window.FM.__buildBlocksFromTextareaForTest = _buildBlocksFromTextarea;
window.FM.__insertListSnippetForTest = insertListSnippet;
window.FM.__buildSectionForTest = buildSection;
window.FM.__extractRawWithTikzForTest = _extractRawWithTikz;
window.FM.__updateInlineFormatBtnStateForTest = _updateInlineFormatBtnState;
window.FM.__toggleInlineFormatForTest = _toggleInlineFormat;
window.FM.__openFindReplaceDialogForTest = openFindReplaceDialog;
window.FM.__wrapSnippetForTest = wrapSnippet;
window.FM.__UndoManagerForTest = UndoManager;
window.FM.__indentListItemForTest = _indentListItem;
window.FM.__outdentListItemForTest = _outdentListItem;
window.FM.__insertTabAtCaretForTest = _insertTabAtCaret;
window.FM.__columnAtCaretForTest = _columnAtCaret;
// Esposto per editor-draft-autosave: server save silenzioso (no toast, no chiusura editor).
window.FM.EditorServerAutosave = {
    saveItem:    _saveItemEditorInPlace,                       // item editor (panel inline su .fm-collection__item)
    saveGroup:   (problem) => _saveGroupEditInPlace(problem),  // group editor (titolo + intro)
};
