/**
 * G24.refactor5.step10 — Estratto da `features/checkin-handlers.js` (monolite
 * 7300+ LOC). Helper isolati del sotto-sistema TeX dropdown:
 *   makeSectionLabel       — separatore visuale label uppercase (dropdown UI)
 *   confirmDialog          — popup conferma custom (sostituisce await window.FM.Dialog.confirm())
 *   _extractTemplateData   — decode base64 __FM_TPL_DATA__ marker
 *   _findFocusedTextarea   — utility per ottenere il textarea focus dal panel
 *   _insertIntoQuesito     — inserisce TikZ block/code nel textarea quesito
 *
 * Sotto-sistema TeX dropdown completo (buildTexDropdown, loadTexGroups,
 * _openTexElementEditor, _openTexInsertEditor, _openNewOrImportDialog,
 * _openFillerForTemplateRow, _openGeoGebraEditorForBlock, etc) resta
 * nel monolite: ~900 LOC interconnessi con fetch/CM6/modali dynamic
 * import. Bundle unico, non scomponibile in step "safe" senza riarch.
 *
 * Dipendenze: html-text-utils (escapeHtml per confirmDialog).
 */

import { escapeHtml } from "./html-text-utils.js";

/** Label visuale uppercase + separator border-top per dropdown sections. */
export function makeSectionLabel(text) {
    // G22.S15 — dark mode: separatori scuri + testo grigio.
    const lbl = document.createElement("div");
    lbl.style.cssText = "padding:8px 12px 4px;font:600 10px/1 system-ui;color:#888;text-transform:uppercase;letter-spacing:0.5px;border-top:1px solid #333;margin-top:4px";
    lbl.textContent = text;
    return lbl;
}

/** Popup di conferma personalizzato (no browser alert).
 *  Ritorna Promise<boolean> — true se utente conferma, false altrimenti
 *  (Escape, click fuori, Annulla). */
export function confirmDialog({ title = "Conferma", message = "Sicuro?", confirmLabel = "OK", cancelLabel = "Annulla", danger = false } = {}) {
    return new Promise((resolve) => {
        document.getElementById("fm-confirm-dialog")?.remove();
        const dlg = document.createElement("div");
        dlg.id = "fm-confirm-dialog";
        dlg.style.cssText = "position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:100050;display:flex;align-items:center;justify-content:center;font:13px/1.4 system-ui";
        const confirmBg = danger ? "#c02a2a" : "#2a5ac7";
        dlg.innerHTML = `
            <div style="background:#1e1e1e;color:#ddd;border:1px solid #444;border-radius:8px;box-shadow:0 12px 48px rgba(0,0,0,0.6);min-width:380px;max-width:90vw;padding:0;overflow:hidden">
                <div style="padding:12px 16px;background:#2a2a2a;border-bottom:1px solid #444;font-weight:600">${escapeHtml(title)}</div>
                <div style="padding:18px 16px;white-space:pre-wrap;color:#ccc">${escapeHtml(message)}</div>
                <div style="padding:10px 12px;background:#252525;border-top:1px solid #444;display:flex;gap:8px;justify-content:flex-end">
                    <button data-act="cancel" style="padding:6px 14px;background:#3a3a3a;color:#ddd;border:1px solid #555;border-radius:4px;cursor:pointer">${escapeHtml(cancelLabel)}</button>
                    <button data-act="ok" style="padding:6px 14px;background:${confirmBg};color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:600">${escapeHtml(confirmLabel)}</button>
                </div>
            </div>`;
        document.body.appendChild(dlg);

        const done = (val) => { dlg.remove(); document.removeEventListener("keydown", esc); resolve(val); };
        const esc = (e) => { if (e.key === "Escape") done(false); else if (e.key === "Enter") done(true); };
        document.addEventListener("keydown", esc);
        dlg.addEventListener("click", (e) => {
            const a = e.target?.dataset?.act;
            if (a === "ok") done(true);
            else if (a === "cancel") done(false);
            else if (e.target === dlg) done(false);
        });
        dlg.querySelector('[data-act="ok"]').focus();
    });
}

const FM_TPL_DATA_RE = /^%\s*__FM_TPL_DATA__:([A-Za-z0-9+/=]+)\s*$/m;

/** Decode base64 JSON payload `% __FM_TPL_DATA__:...` marker (template filler
 *  state). Doppio fallback decode: con/senza decodeURIComponent escape. */
export function extractTemplateData(content) {
    const m = (content || "").match(FM_TPL_DATA_RE);
    if (!m) return null;
    try { return JSON.parse(decodeURIComponent(escape(atob(m[1])))); }
    catch (_) {
        try { return JSON.parse(atob(m[1])); } catch (__) { return null; }
    }
}

/** Helper: trova il textarea editor in focus (o fallback al primo
 *  `.fm-editor-field`). Ritorna null se panel non disponibile o no focus. */
export function findFocusedTextarea(getPanel) {
    const panel = typeof getPanel === "function" ? getPanel() : getPanel;
    if (!panel) return null;
    return panel._focusedTextarea || panel.querySelector?.(".fm-editor-field") || null;
}

/** G27.tikz.extract — Estrae solo i NOMI di \usepackage e \usetikzlibrary
 *  dal codice template per popolare data-tex-packages / data-tikz-libraries
 *  (metadata extra channel letto dal Sanitizer server-side per hoist). NON
 *  modifica il body: il body resta INTATTO con tutto il preamble originale.
 *
 *  Razionale: il body deve restare standalone-compilabile perche' viene
 *  usato dal preview render endpoint (TikzRenderService) che compila il
 *  TikZ ISOLATO via VPS pdflatex. Strippare \usepackage/\newcommand dal
 *  body avrebbe rotto il preview: macro/package undefined in standalone.
 *  La duplicazione metadata (attrs + body) e' innocua: il Sanitizer
 *  server-side dedup-hoist tutto correttamente per il full-doc compile. */
function extractTikzMetadata(code) {
    const packages  = new Set();
    const libraries = new Set();
    let m;
    const reUse = /\\usepackage(?:\[[^\]]*\])?\{([^}]+)\}/g;
    while ((m = reUse.exec(code))) {
        m[1].split(",").forEach(n => { const t = n.trim(); if (t) packages.add(t); });
    }
    const reLib = /\\usetikzlibrary\{([^}]+)\}/g;
    while ((m = reLib.exec(code))) {
        m[1].split(",").forEach(n => { const t = n.trim(); if (t) libraries.add(t); });
    }
    return {
        packages:  [...packages].join(","),
        libraries: [...libraries].join(","),
    };
}

/** Inserisce un blocco TikZ (o testo LaTeX) nel textarea quesito usando il
 *  multi-tikz markers system. Usato dalle azioni "➕ Aggiungi" sia di CM6
 *  insert sia di filler.
 *  G27.tikz.extract — al momento dell'inserimento popola data-tex-packages
 *  / data-tikz-libraries READ-ONLY (metadata channel). Il body resta
 *  intatto (necessario per il preview render standalone). */
export function insertIntoQuesito(ta, type, code) {
    if (!ta) return false;
    ta._tikzBlocks = ta._tikzBlocks || [];
    if (type === "tikz") {
        const meta = extractTikzMetadata(code);
        const dataPkg = meta.packages
            ? ` data-tex-packages="${meta.packages.replace(/"/g, "&quot;")}"`
            : "";
        const dataLibs = meta.libraries
            ? ` data-tikz-libraries="${meta.libraries.replace(/"/g, "&quot;")}"`
            : "";
        ta._tikzBlocks.push({
            tagOpen:  `<script type="text/tikz" data-show-console="true"${dataPkg}${dataLibs}>`,
            body:     `\n${  code  }\n`,
            tagClose: '</' + 'script>',
        });
        const newMarker = `⟨🔍 TikZ #${ta._tikzBlocks.length}⟩`;
        ta.value = (ta.value.trim() ? `${ta.value  }\n` : "") + newMarker;
    } else {
        ta.value = (ta.value.trim() ? `${ta.value  }\n` : "") + code;
    }
    if (typeof ta._renderTikzButtons === "function") ta._renderTikzButtons();
    ta._lastRenderedValue = undefined;
    ta.dispatchEvent(new Event("input", { bubbles: true }));
    return true;
}
