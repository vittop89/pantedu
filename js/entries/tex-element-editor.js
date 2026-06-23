/**
 * G22.S15.bis — Editor unificato per elementi tex (TikZ codice grezzo
 * o LaTeX math) con CM6 + preview live.
 *
 * Aperto da fm-tex-group header (➕ nuovo) o item row (✏️ edit). Lazy
 * import da checkin-handlers via /build/manifest.json.
 *
 * Layout:
 *   ┌────────────────────────────────────────────────────────────────┐
 *   │ [TikZ|LaTeX]  Nome: [______]  Gruppo: [select|new]  [Annulla]  │
 *   ├──────────────────────────────────┬─────────────────────────────┤
 *   │ CodeMirror 6 (stex)              │ Preview SVG / MathJax       │
 *   │                                   │                             │
 *   └──────────────────────────────────┴─────────────────────────────┘
 *                                                       [Salva]
 *
 * Preview:
 *   - type=tikz  → tikz-render-client (server pdflatex+dvisvgm)
 *   - type=latex → MathJax typeset (client)
 *
 * API:
 *   import { openTexElementEditor } from "/js/entries/tex-element-editor.js";
 *   openTexElementEditor({
 *     mode: "new"|"edit",
 *     groupKey: "gruppo-…", initialType, initialLabel, initialCode,
 *     existingGroups: ["gruppo-foo", …],
 *     onSave: async ({type,label,code,groupName,newGroup}) => {ok,error},
 *     onCancel: () => void,
 *   });
 */
import { EditorState, Compartment } from "@codemirror/state";
import { EditorView, keymap, lineNumbers, highlightActiveLine, drawSelection } from "@codemirror/view";
import { defaultKeymap, history, historyKeymap, indentWithTab } from "@codemirror/commands";
import { searchKeymap, search } from "@codemirror/search";
import { foldGutter, foldKeymap, syntaxHighlighting, defaultHighlightStyle, StreamLanguage } from "@codemirror/language";
import { stex } from "@codemirror/legacy-modes/mode/stex";
import { oneDark } from "@codemirror/theme-one-dark";

import { renderAll as tikzRenderAll } from "../modules/editor/tikz-render-client.js";
import { escAttr } from "../modules/core/dom-utils.js";

let _modalState = null;
let _styleInjected = false;

function injectStyles() { /* ADR-023 Fase 2: CSS spostato in css/modules/ */ }

let _previewTimer = null;
async function refreshPreview(previewEl, source, type) {
    const status = previewEl.querySelector(".preview-status");
    if (status) status.textContent = "rendering…";
    // Prepare fresh container preserving status node
    const oldStatus = status?.outerHTML || '<div class="preview-status"></div>';
    previewEl.innerHTML = oldStatus;
    const newStatus = previewEl.querySelector(".preview-status");

    if (!source.trim()) {
        if (newStatus) newStatus.textContent = "vuoto";
        return;
    }

    try {
        if (type === "tikz") {
            // Sandbox script + tikzRenderAll
            const sandbox = document.createElement("div");
            const script = document.createElement("script");
            script.type = "text/tikz";
            script.textContent = source;
            sandbox.appendChild(script);
            const stats = await tikzRenderAll(sandbox, { defaultScope: "public" });
            if (stats.errors.length > 0) {
                const err = document.createElement("div");
                err.className = "err";
                err.textContent = "Errore compile:\n\n" + stats.errors.map(e => e.error).join("\n\n");
                previewEl.appendChild(err);
                if (newStatus) newStatus.textContent = "errore";
            } else {
                while (sandbox.firstChild) {
                    if (sandbox.firstChild.nodeName !== "SCRIPT") previewEl.appendChild(sandbox.firstChild);
                    else sandbox.removeChild(sandbox.firstChild);
                }
                if (newStatus) newStatus.textContent = "ok";
            }
        } else {
            // LaTeX math → MathJax. Wrap in \(...\) se non già delimitato.
            const wrapped = /\\\(|\\\[|\\begin\{(equation|align|gather|displaymath|math)\}/.test(source)
                ? source : `\\(${source}\\)`;
            const div = document.createElement("div");
            div.style.cssText = "padding:8px;font:14px/1.6 'Latin Modern Roman', Cambria, serif";
            div.textContent = wrapped;
            previewEl.appendChild(div);
            if (window.MathJax?.typesetPromise) {
                await window.MathJax.typesetPromise([div]);
                if (newStatus) newStatus.textContent = "ok";
            } else if (newStatus) {
                newStatus.textContent = "MathJax non caricato";
            }
        }
    } catch (e) {
        const err = document.createElement("div");
        err.className = "err";
        err.textContent = "Errore: " + (e.message || e);
        previewEl.appendChild(err);
        if (newStatus) newStatus.textContent = "errore";
    }
}

function debouncePreview(previewEl, getSource, getType, ms = 500) {
    clearTimeout(_previewTimer);
    _previewTimer = setTimeout(() => refreshPreview(previewEl, getSource(), getType()), ms);
}

export function openTexElementEditor(opts) {
    if (_modalState) return;
    injectStyles();

    const {
        mode = "new",            // "new" | "edit" | "insert"
        groupKey = "",
        initialType = "tikz",
        initialLabel = "",
        initialCode = "",
        existingGroups = [],
        title: customTitle = "",
        saveLabel: customSaveLabel = "",
        extraToolbar = [],       // [{label, title, onClick: (api) => void, primary?}]
        // G22.S15.bis — toolbar 4-bottoni per mode "insert" (chiamato da 🔍).
        // Quando definite, sostituiscono i bottoni Annulla/Salva di default.
        actions = null,          // {onAdd, onSavePref, onReset, isOverride?, canAdd?}
        onSave, onCancel,
    } = opts || {};

    // mode "insert" = inserimento nel quesito (no label, no group, no type
    // switch — solo CM6 + preview). Usato dal bottone 🔍 "Inserisci come
    // codice TikZ" della template DB row.
    const showMeta = mode !== "insert";
    const useActions = mode === "insert" && actions && typeof actions === "object";

    const headerTitle = customTitle
        || (mode === "edit"   ? "Modifica elemento"
        : mode === "insert" ? "Inserisci codice"
                            : "Nuovo elemento");
    const saveLabel = customSaveLabel
        || (mode === "insert" ? "Inserisci (Ctrl+S)" : "Salva (Ctrl+S)");

    // Group select markup: opzione "(nuovo gruppo)" → mostra input; altrimenti pre-selezionato.
    let groupSelectHtml = "";
    if (showMeta) {
        groupSelectHtml = mode === "new"
            ? `<select class="fm-tee-group">
                <option value="__new__">+ Nuovo gruppo</option>
                ${existingGroups.map((g) => `<option value="${escAttr(g)}" ${g === groupKey ? "selected" : ""}>${escAttr(g.replace(/^gruppo-/, ""))}</option>`).join("")}
            </select>
            <input class="fm-tee-newgroup" placeholder="Nome nuovo gruppo" autocomplete="off">`
            : `<span class="fm-tee-readonly" style="color:#aaa;font-size:12px">${escAttr(groupKey.replace(/^gruppo-/, ""))}</span>`;
    }

    const metaBlock = showMeta ? `
                <div class="fm-tee-typeswitch" role="tablist" aria-label="Tipo elemento">
                    <button data-type="tikz"  class="${initialType === "tikz" ? "active" : ""}">TikZ</button>
                    <button data-type="latex" class="${initialType === "latex" ? "active" : ""}">LaTeX math</button>
                </div>
                <div class="fm-tee-field">
                    <label>Nome</label>
                    <input class="fm-tee-label" value="${escAttr(initialLabel)}" autocomplete="off" placeholder="es. Schema studio segno">
                </div>
                <div class="fm-tee-field">
                    <label>Gruppo</label>
                    ${groupSelectHtml}
                </div>` : "";

    const extraBtnsHtml = (extraToolbar || [])
        .map((b, i) => `<button data-act="extra-${i}" title="${escAttr(b.title || "")}" ${b.primary ? 'class="primary"' : ''}>${escAttr(b.label)}</button>`)
        .join("");

    // G22.S15.bis — toolbar 4-bottoni (mode "insert" + actions presenti):
    //   ➕ Aggiungi al quesito · 💾 Salva mio predefinito · 🔄 Reset · ✕ Chiudi.
    const overrideBadge = useActions && actions.isOverride
        ? '<span class="fm-tee-override-badge" title="Stai vedendo il TUO override del default admin. Reset per ripristinare.">✱ MIO</span>'
        : '';
    const toolbarHtml = useActions ? `
                    ${overrideBadge}
                    <button data-act="add"      class="primary" title="Aggiungi nel quesito (cursor focus)">➕ Aggiungi</button>
                    <button data-act="savepref"            title="Salva questa versione come MIO predefinito (override del default admin)">💾 Salva predefinito</button>
                    <button data-act="reset"    class="danger" title="Reimposta al default admin (rimuove il mio override)">🔄 Reset</button>
                    <button data-act="cancel"              title="Chiudi">✕</button>`
        : `
                    ${extraBtnsHtml}
                    <button data-act="cancel" class="danger">Annulla</button>
                    <button data-act="save" class="primary">${escAttr(saveLabel)}</button>`;

    const backdrop = document.createElement("div");
    backdrop.className = "fm-tee-backdrop";
    backdrop.innerHTML = `
        <div class="fm-tee-modal" role="dialog" aria-label="${escAttr(headerTitle)}">
            <div class="fm-tee-header">
                <h3>${escAttr(headerTitle)}</h3>
                ${metaBlock}
                <div class="fm-tee-spacer"></div>
                <div class="fm-tee-toolbar">${toolbarHtml}</div>
            </div>
            <div class="fm-tee-body">
                <div class="fm-tee-editor"></div>
                <div class="fm-tee-preview">
                    <div class="preview-status">…</div>
                </div>
            </div>
        </div>`;
    document.body.appendChild(backdrop);

    const editorEl = backdrop.querySelector(".fm-tee-editor");
    const previewEl = backdrop.querySelector(".fm-tee-preview");
    const labelInput = backdrop.querySelector(".fm-tee-label");
    const groupSel = backdrop.querySelector(".fm-tee-group");
    const newGroupInput = backdrop.querySelector(".fm-tee-newgroup");
    const typeBtns = backdrop.querySelectorAll(".fm-tee-typeswitch button");

    let currentType = initialType;
    const getType = () => currentType;

    // New-group input visibility (mode=new): mostra solo se select === "__new__"
    if (groupSel && newGroupInput) {
        const syncNewGroup = () => {
            newGroupInput.style.display = groupSel.value === "__new__" ? "" : "none";
            if (groupSel.value === "__new__") newGroupInput.focus();
        };
        groupSel.addEventListener("change", syncNewGroup);
        syncNewGroup();
    }

    // CM6 setup
    const cm = new EditorView({
        parent: editorEl,
        state: EditorState.create({
            doc: initialCode,
            extensions: [
                lineNumbers(),
                history(),
                drawSelection(),
                highlightActiveLine(),
                foldGutter(),
                EditorView.lineWrapping,
                StreamLanguage.define(stex),
                syntaxHighlighting(defaultHighlightStyle, { fallback: true }),
                search({ top: true }),
                keymap.of([
                    ...defaultKeymap, ...historyKeymap, ...searchKeymap, ...foldKeymap,
                    indentWithTab,
                    { key: "Mod-s", run: () => { saveAndClose(); return true; } },
                ]),
                oneDark,
                EditorView.updateListener.of((u) => {
                    if (u.docChanged) {
                        debouncePreview(previewEl, () => cm.state.doc.toString(), getType, 450);
                    }
                }),
            ],
        }),
    });

    // Type switch
    typeBtns.forEach((b) => b.addEventListener("click", () => {
        typeBtns.forEach((x) => x.classList.remove("active"));
        b.classList.add("active");
        currentType = b.dataset.type;
        refreshPreview(previewEl, cm.state.doc.toString(), currentType);
    }));

    // Initial preview
    refreshPreview(previewEl, initialCode, currentType);

    function close() {
        if (!_modalState) return;
        _modalState = null;
        cm.destroy();
        backdrop.remove();
        clearTimeout(_previewTimer);
    }
    function cancelAndClose() { close(); if (typeof onCancel === "function") onCancel(); }

    async function saveAndClose() {
        const code = cm.state.doc.toString();
        if (!code.trim()) { cm.focus(); previewEl.querySelector(".preview-status").textContent = "codice vuoto"; return; }

        // mode "insert": no label/group/type validazione, solo codice
        if (mode === "insert") {
            if (typeof onSave !== "function") { close(); return; }
            const sb = backdrop.querySelector('[data-act="save"]');
            sb.disabled = true; sb.textContent = "…";
            try {
                const r = await onSave({ type: currentType, code });
                if (!r || r.ok !== false) close();
                else { sb.disabled = false; sb.textContent = saveLabel; }
            } catch (_) { sb.disabled = false; sb.textContent = saveLabel; }
            return;
        }

        const label = labelInput.value.trim();
        if (!label) { labelInput.focus(); previewEl.querySelector(".preview-status").textContent = "manca nome"; return; }

        let groupName = "", newGroup = "";
        if (mode === "new") {
            if (groupSel.value === "__new__") {
                newGroup = (newGroupInput?.value || "").trim();
                if (!newGroup) { newGroupInput.focus(); return; }
            } else {
                groupName = groupSel.value;
            }
        } else {
            groupName = groupKey;
        }

        if (typeof onSave !== "function") { close(); return; }
        const saveBtn = backdrop.querySelector('[data-act="save"]');
        saveBtn.disabled = true; saveBtn.textContent = "Salvataggio…";
        try {
            const r = await onSave({ type: currentType, label, code, groupName, newGroup });
            if (r && r.ok === true) close();
            else { saveBtn.disabled = false; saveBtn.textContent = "Salva (Ctrl+S)"; }
        } catch (e) {
            saveBtn.disabled = false; saveBtn.textContent = "Salva (Ctrl+S)";
        }
    }

    // API esposta agli extra-toolbar callback: get/set doc + close.
    const editorApi = {
        getCode: () => cm.state.doc.toString(),
        setCode: (newCode) => {
            cm.dispatch({ changes: { from: 0, to: cm.state.doc.length, insert: newCode || "" } });
            refreshPreview(previewEl, newCode || "", currentType);
        },
        getType: () => currentType,
        close,
    };

    backdrop.addEventListener("click", (e) => {
        const a = e.target?.dataset?.act;
        if (!a) return;
        if (a === "save") saveAndClose();
        else if (a === "cancel") cancelAndClose();
        else if (a === "add" && useActions && typeof actions.onAdd === "function") {
            actions.onAdd(editorApi);
        } else if (a === "savepref" && useActions && typeof actions.onSavePref === "function") {
            actions.onSavePref(editorApi);
        } else if (a === "reset" && useActions && typeof actions.onReset === "function") {
            actions.onReset(editorApi);
        } else if (a.startsWith("extra-")) {
            const idx = Number(a.slice(6));
            const item = (extraToolbar || [])[idx];
            if (item && typeof item.onClick === "function") item.onClick(editorApi);
        }
    });
    backdrop.addEventListener("mousedown", (e) => { if (e.target === backdrop) cancelAndClose(); });
    document.addEventListener("keydown", function escHandler(e) {
        if (!_modalState) { document.removeEventListener("keydown", escHandler); return; }
        if (e.key === "Escape") cancelAndClose();
    });

    cm.focus();
    _modalState = { close, cm, api: editorApi };
}

if (typeof window !== "undefined") {
    window.FM = window.FM || {};
    window.FM.openTexElementEditor = openTexElementEditor;
}
