/**
 * G22.S15 — Modal CM6 per editing TikZ avanzato.
 *
 * Apertura on-demand da un textarea che contiene `<script type="text/tikz">`.
 * Bundle caricato lazy via dynamic import (~35 KB gz).
 *
 * Feature:
 *   - CodeMirror 6 con highlight LaTeX (stex legacy mode)
 *   - Folding custom su marker `% =====.....NOME.....` (apertura) e
 *     prossimo marker dello stesso tipo (chiusura) — pensato per i
 *     template modulari pantedu (vedi modelli_tikz).
 *   - Preview SVG live a destra, riusa `tikz-render-client` (memo cache).
 *   - Toolbar: salva, annulla, fold all / unfold all.
 *   - Salva → scrive il TikZ (eventualmente ri-wrappato in `<script>`)
 *     nel textarea originale + trigger preview inline.
 *
 * API:
 *   import { openTikzModal } from "/js/entries/tikz-editor-modal.js";
 *   openTikzModal(textareaEl);
 */
import { EditorState, Compartment } from "@codemirror/state";
import { EditorView, keymap, lineNumbers, highlightActiveLine, drawSelection } from "@codemirror/view";
import { defaultKeymap, history, historyKeymap, indentWithTab } from "@codemirror/commands";
import { searchKeymap, search } from "@codemirror/search";
import { foldGutter, foldKeymap, syntaxHighlighting, defaultHighlightStyle, StreamLanguage, foldService } from "@codemirror/language";
import { stex } from "@codemirror/legacy-modes/mode/stex";
import { oneDark } from "@codemirror/theme-one-dark";

import { normalizeTikz, sha256Hex } from "../modules/editor/tikz-render-client.js";
import { fetchCsrf } from "../modules/core/dom-utils.js";

// Stesso PDF.js della verifica-preview-editor → unificazione pipeline.
const PDFJS_VERSION = "4.7.76";
const PDFJS_SRC = `https://cdn.jsdelivr.net/npm/pdfjs-dist@${PDFJS_VERSION}/build/pdf.mjs`;
const PDFJS_WORKER = `https://cdn.jsdelivr.net/npm/pdfjs-dist@${PDFJS_VERSION}/build/pdf.worker.mjs`;
let _pdfjsPromise = null;
async function _loadPdfJs() {
    if (_pdfjsPromise) return _pdfjsPromise;
    _pdfjsPromise = (async () => {
        const mod = await import(/* @vite-ignore */ PDFJS_SRC);
        const pdfjs = mod.default || mod;
        if (pdfjs.GlobalWorkerOptions) {
            pdfjs.GlobalWorkerOptions.workerSrc = PDFJS_WORKER;
        }
        return pdfjs;
    })();
    return _pdfjsPromise;
}

/** Replica JS-side di TexAdhocCompileController::wrapTikzSource. Usato dal
 *  pulsante "Mostra TeX wrapped" per visualizzare ESATTAMENTE il source che
 *  arriva al server (e quindi a pdflatex) per la compilazione. */
function wrapTikzSourceForDisplay(src, border = "2pt") {
    const s = String(src || "").trim();
    if (/^\s*\\documentclass\b/m.test(s)) return s;

    const fontSetup =
        "\\usepackage[scaled]{helvet}\n" +
        "\\usepackage[T1]{fontenc}\n" +
        "\\renewcommand{\\familydefault}{\\sfdefault}\n";

    if (/\\begin\s*\{\s*document\s*\}/.test(s)) {
        const patched = s.replace(
            /(\\begin\s*\{\s*document\s*\})/,
            fontSetup + "$1",
        );
        return `\\documentclass[tikz,border=${border}]{standalone}\n${patched}\n`;
    }

    let body = s;
    if (!/\\begin\s*\{\s*tikzpicture\s*\}/.test(body)) {
        body = `\\begin{tikzpicture}\n${body}\n\\end{tikzpicture}`;
    }
    return (
        `\\documentclass[tikz,border=${border}]{standalone}\n` +
        "\\usepackage{tikz}\n" +
        "\\usepackage{amsmath,amssymb}\n" +
        fontSetup +
        "\\begin{document}\n" +
        body +
        "\n\\end{document}\n"
    );
}

/** Dialog modale (sopra il fm-tikz-modal) che mostra side-by-side il source
 *  originale e il TeX completo wrapped che arriva al server per pdflatex. */
function _showWrappedTexDialog(backdrop, original, wrapped) {
    // Rimuovi eventuale dialog precedente
    backdrop.querySelectorAll(".fm-tikz-wrapped-dlg").forEach(el => el.remove());

    const dlg = document.createElement("div");
    dlg.className = "fm-tikz-wrapped-dlg";
    dlg.innerHTML = `
        <div class="fm-tikz-wrapped-dlg__inner">
            <div class="fm-tikz-wrapped-dlg__header">
                <h4>TeX inviato al server per la compilazione</h4>
                <button type="button" class="fm-log-btn" data-wdlg="copy">📋 Copia tutto</button>
                <button type="button" class="fm-log-btn fm-tikz-wrapped-dlg__close">✕ Chiudi</button>
            </div>
            <div class="fm-tikz-wrapped-dlg__cols">
                <div class="fm-tikz-wrapped-dlg__col">
                    <div class="fm-tikz-wrapped-dlg__label">Source utente (textarea editor) — <em>${original.length} chars</em></div>
                    <pre class="fm-tikz-wrapped-dlg__pre"></pre>
                </div>
                <div class="fm-tikz-wrapped-dlg__col">
                    <div class="fm-tikz-wrapped-dlg__label">TeX wrapped (= ciò che riceve pdflatex sul VPS) — <em>${wrapped.length} chars</em></div>
                    <pre class="fm-tikz-wrapped-dlg__pre fm-tikz-wrapped-dlg__pre--highlight"></pre>
                </div>
            </div>
            <div class="fm-tikz-wrapped-dlg__hint">
                Il wrap aggiunge <code>\\documentclass[tikz,border=2pt]{standalone}</code> + font helvet sans-serif (= identico a verifica.sty).
                Stesso wrap usato da <code>POST /api/tex/compile-adhoc-pdf</code>.
            </div>
        </div>
    `;
    // Inserisci testo nei <pre> via textContent (no XSS)
    const pres = dlg.querySelectorAll(".fm-tikz-wrapped-dlg__pre");
    pres[0].textContent = original;
    pres[1].textContent = wrapped;
    backdrop.appendChild(dlg);

    dlg.addEventListener("click", (e) => {
        if (e.target.classList.contains("fm-tikz-wrapped-dlg__close")) {
            dlg.remove();
            return;
        }
        if (e.target.dataset.wdlg === "copy") {
            navigator.clipboard.writeText(wrapped).catch(() => {});
            const orig = e.target.textContent;
            e.target.textContent = "✓ Copiato";
            setTimeout(() => { e.target.textContent = orig; }, 1500);
        }
    });
    // ESC chiude
    const escHandler = (e) => {
        if (e.key === "Escape" && dlg.parentNode) {
            dlg.remove();
            document.removeEventListener("keydown", escHandler);
        }
    };
    document.addEventListener("keydown", escHandler);
}

// CSRF centralizzato (meta-tag-first + cache 60s in dom-utils).
const _getCsrfToken = fetchCsrf;

/** Debug logger per il modal preview. Inserisce ogni evento in
 *  `.fm-tikz-modal-debuglog` (creato lazily). Console mirror in
 *  parallelo con prefisso `[fm-tikz-modal]`. */
function _escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({
        "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;",
    }[c]));
}
function _findModalRoot(el) {
    return el?.closest(".fm-tikz-modal") || document.querySelector(".fm-tikz-modal") || document.body;
}
function _ensureDebugLog(previewEl) {
    // Footer-level log (shared, single instance per modal). Mai dentro previewEl.
    const root = _findModalRoot(previewEl);
    let log = root.querySelector(".fm-tikz-modal-footer .fm-tikz-modal-debuglog");
    if (!log) {
        // Crea il footer se mancante (modal aperto da legacy senza footer markup)
        let footer = root.querySelector(".fm-tikz-modal-footer");
        if (!footer) {
            footer = document.createElement("footer");
            footer.className = "fm-tikz-modal-footer";
            footer.innerHTML = `
                <details class="fm-tikz-modal-debuglog-wrap" open>
                    <summary>Log compilazione TikZ <span class="ts-badge"></span></summary>
                    <div class="fm-tikz-modal-debuglog"></div>
                </details>`;
            root.appendChild(footer);
        }
        log = root.querySelector(".fm-tikz-modal-debuglog");
    }
    return log;
}
function _dbg(previewEl, level, msg) {
    const log = _ensureDebugLog(previewEl);
    const ts = new Date().toISOString().substring(11, 23);
    const line = document.createElement("div");
    line.innerHTML = `<span class="ts">${ts}</span> <span class="lvl-${level}">${msg}</span>`;
    log.appendChild(line);
    log.scrollTop = log.scrollHeight;
    // Mirror console
    const fn = level === "err" ? "error" : level === "warn" ? "warn" : "log";
    console[fn]("[fm-tikz-modal]", msg);
}
/** Dump esteso (multi-line content) con <details> collassabile + copy button. */
function _dump(previewEl, label, content, level = "src") {
    const log = _ensureDebugLog(previewEl);
    const safeLabel = _escapeHtml(label);
    const safeId = "dump-" + Math.random().toString(36).substring(2, 9);
    const details = document.createElement("details");
    details.innerHTML = `<summary class="lvl-${level}">${safeLabel} <span style="color:#888">(${content.length} chars)</span></summary>` +
        `<button class="copy-btn" data-copy="${safeId}">Copy</button>` +
        `<pre class="dump" id="${safeId}">${_escapeHtml(content)}</pre>`;
    log.appendChild(details);
    details.querySelector(".copy-btn").addEventListener("click", (e) => {
        e.preventDefault();
        navigator.clipboard.writeText(content).catch(() => {});
        e.target.textContent = "Copied!";
        setTimeout(() => { e.target.textContent = "Copy"; }, 1500);
    });
    log.scrollTop = log.scrollHeight;
}

// Pattern del marker "regione" usato dai template modulari pantedu.
// Esempi:
//   % ==================================================
//   % .....CONTROLLI GLOBALI.....
//
//   % ================= FIGURA 1 =================
//
// La regola: una riga con `% ` poi `=` ripetuti (≥3) e/o `.....` segna
// l'inizio di una sezione. La regione termina alla prossima riga
// dello stesso tipo (fold to next marker).
const FOLD_MARKER_RE = /^[ \t]*%[ \t]*([=]{3,}|\.{4,}|=+\s*\.{3,}|=+\s+[A-Z][^\n]*)/;

/** Custom fold service: ritorna il range da `from` (fine della linea
 *  marker) al carattere prima della prossima linea marker (o EOF). */
const panteduFoldService = foldService.of((state, lineStart, lineEnd) => {
    const docText = state.doc.toString();
    const line = docText.slice(lineStart, lineEnd);
    if (!FOLD_MARKER_RE.test(line)) return null;

    // Cerca la prossima riga marker
    let pos = lineEnd + 1;
    while (pos < docText.length) {
        const nextLineEnd = docText.indexOf("\n", pos);
        const ne = nextLineEnd === -1 ? docText.length : nextLineEnd;
        const nextLine = docText.slice(pos, ne);
        if (FOLD_MARKER_RE.test(nextLine)) {
            // Fold da fine di lineEnd (incluso il \n) fino a inizio di pos
            return { from: lineEnd, to: pos - 1 };
        }
        if (nextLineEnd === -1) break;
        pos = nextLineEnd + 1;
    }
    // No closer found → fold fino a fine documento
    return { from: lineEnd, to: docText.length };
});

let _modalState = null;
let _styleInjected = false;

function injectStyles() { /* ADR-023 Fase 2: CSS spostato in css/modules/ */ }

/** Estrae il sorgente TikZ dal value del textarea.
 *  - Se contiene `<script type="text/tikz">...</script>`, ritorna il body.
 *  - Altrimenti ritorna il value as-is.
 *  Ritorna anche un setter che ricostruisce il value dal nuovo body
 *  preservando lo wrapper se presente. */
function extractTikz(textarea) {
    const value = textarea.value || "";
    const m = value.match(/(<script\s+type=["']text\/tikz["'][^>]*>)([\s\S]*?)(<\/script>)/i);
    if (m) {
        return {
            body: m[2].replace(/^\n/, ""),  // rimuovi LF leading per editing pulito
            wrap: (newBody) => textarea.value.replace(m[0], m[1] + "\n" + newBody.replace(/^\n+/, "") + "\n" + m[3]),
        };
    }
    // Niente wrapper: prendiamo il value intero come body
    return {
        body: value,
        wrap: (newBody) => newBody,
    };
}

let _previewTimer = null;
async function refreshPreview(previewEl, source) {
    const status = previewEl.querySelector(".preview-status");
    if (status) status.textContent = "compiling…";

    // Aggiorna timestamp nel summary del log
    const root = _findModalRoot(previewEl);
    const tsBadge = root.querySelector(".fm-tikz-modal-debuglog-wrap .ts-badge");
    if (tsBadge) tsBadge.textContent = `· ultima @ ${new Date().toLocaleTimeString()}`;

    // Separator visuale tra render successivi nel log condiviso
    const logEl = _ensureDebugLog(previewEl);
    if (logEl.children.length > 0) {
        const sep = document.createElement("div");
        sep.style.cssText = "border-top:1px dashed #444;margin:6px 0;color:#666;font-size:10px;";
        sep.textContent = "─── nuovo render ───";
        logEl.appendChild(sep);
    }

    _dbg(previewEl, "info", `→ preview start, source.length=${source.length}`);
    _dump(previewEl, "TikZ source completo (input)", source, "src");

    // Calcola hash (info, non usato direttamente — il VPS ricomputa per cache key)
    try {
        const norm = normalizeTikz(source);
        const hash = await sha256Hex(norm);
        _dbg(previewEl, "hash", `hash(sha256) = ${hash}`);
        if (norm !== source) {
            _dump(previewEl, "TikZ normalized", norm, "src");
        }
    } catch (e) {
        _dbg(previewEl, "warn", `hash compute failed: ${e.message}`);
    }

    try {
        const t0 = performance.now();
        // Compile via NUOVO endpoint ad-hoc PDF (unificato con verifica preview)
        const csrf = await _getCsrfToken();
        const resp = await fetch("/api/tex/compile-adhoc-pdf", {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": csrf,
                "Accept": "application/pdf, application/json",
            },
            body: JSON.stringify({ tex: source, border: "2pt" }),
        });
        const dur = Math.round(performance.now() - t0);
        const ctype = resp.headers.get("content-type") || "";
        const xMode = resp.headers.get("x-compile-mode") || "";
        const xVpsDur = resp.headers.get("x-compile-duration-ms") || "";

        _dbg(previewEl, resp.ok ? "ok" : "err",
            `POST /api/tex/compile-adhoc-pdf → ${resp.status} ${ctype} mode=${xMode} vps_dur=${xVpsDur}ms total=${dur}ms`);

        // Conserva solo lo status, svuota il resto del preview
        const oldStatus = status?.outerHTML || '<div class="preview-status"></div>';
        previewEl.innerHTML = oldStatus;
        const newStatus = previewEl.querySelector(".preview-status");

        if (!resp.ok) {
            // Errore compile: leggi log da JSON
            let errBody = null;
            try { errBody = await resp.json(); } catch { /* */ }
            const log = (errBody?.log || errBody?.message || `HTTP ${resp.status}`).toString();
            const err = document.createElement("div");
            err.className = "err";
            err.textContent = "Errore compile:\n\n" + log.substring(0, 8000);
            previewEl.appendChild(err);
            if (newStatus) newStatus.textContent = `errore ${resp.status}`;
            _dump(previewEl, `pdflatex log (errore HTTP ${resp.status})`, log, "err");
            return;
        }

        const pdfBytes = new Uint8Array(await resp.arrayBuffer());
        _dbg(previewEl, "ok", `PDF received: ${pdfBytes.length} bytes`);

        // Render con PDF.js (stesso che usa la verifica preview)
        const pdfjs = await _loadPdfJs();
        const loadingTask = pdfjs.getDocument({ data: pdfBytes });
        const pdfDoc = await loadingTask.promise;
        _dbg(previewEl, "ok", `PDF loaded: ${pdfDoc.numPages} page(s)`);

        const page = await pdfDoc.getPage(1);
        // Scale dinamica: usa altezza disponibile del preview pane
        const baseScale = 2.0;
        const viewport = page.getViewport({ scale: baseScale });
        const canvas = document.createElement("canvas");
        canvas.width = viewport.width;
        canvas.height = viewport.height;
        canvas.className = "fm-tikz-modal-pdf-canvas";
        canvas.style.cssText = "max-width:100%;max-height:100%;display:block;margin:0 auto;";
        previewEl.appendChild(canvas);
        await page.render({ canvasContext: canvas.getContext("2d"), viewport }).promise;
        _dbg(previewEl, "ok",
            `canvas rendered ${canvas.width}x${canvas.height}px (scale=${baseScale}, viewport ${Math.round(viewport.width)}x${Math.round(viewport.height)}pt)`);
        if (newStatus) newStatus.textContent = "ok";
    } catch (e) {
        const oldStatus = status?.outerHTML || '<div class="preview-status">errore</div>';
        previewEl.innerHTML = oldStatus;
        const err = document.createElement("div");
        err.className = "err";
        err.textContent = "Errore: " + (e.message || e);
        previewEl.appendChild(err);
        _dbg(previewEl, "err", `exception: ${e.message || e}`);
    }
}

function debouncePreview(previewEl, getSource, ms = 500) {
    clearTimeout(_previewTimer);
    _previewTimer = setTimeout(() => refreshPreview(previewEl, getSource()), ms);
}

/** Apre la modal. `textarea` e' l'elemento sorgente del quesito; al
 *  salvataggio il suo `.value` viene aggiornato e dispatchato `input`
 *  così la pipeline updatePreview reagisce. */
export function openTikzModal(textarea) {
    if (_modalState) return;
    injectStyles();

    const { body: initialBody, wrap } = extractTikz(textarea);

    // Build DOM
    const backdrop = document.createElement("div");
    backdrop.className = "fm-tikz-modal-backdrop";
    backdrop.innerHTML = `
        <div class="fm-tikz-modal" role="dialog" aria-label="Editor TikZ avanzato">
            <div class="fm-tikz-modal-header">
                <h3>Editor TikZ avanzato</h3>
                <div class="fm-tikz-modal-toolbar">
                    <button data-act="fold-all" title="Comprimi tutte le sezioni">▶ Fold all</button>
                    <button data-act="unfold-all" title="Espandi tutte le sezioni">▼ Unfold all</button>
                    <button data-act="show-wrapped" title="Mostra il TeX completo (con \documentclass, font setup, etc) inviato al server per la compilazione">🔍 Mostra TeX wrapped</button>
                    <button data-act="cancel" class="danger">Annulla</button>
                    <button data-act="save" class="primary">Salva (Ctrl+S)</button>
                </div>
            </div>
            <div class="fm-tikz-modal-body">
                <div class="fm-tikz-modal-editor"></div>
                <div class="fm-tikz-modal-preview">
                    <div class="preview-status">…</div>
                </div>
            </div>
            <footer class="fm-tikz-modal-footer">
                <details class="fm-tikz-modal-debuglog-wrap" open>
                    <summary>Log compilazione TikZ
                        <span class="ts-badge"></span>
                        <button type="button" class="fm-log-btn" data-act="copy-log" title="Copia tutto il log inclusi i dump">📋 Copia</button>
                        <button type="button" class="fm-log-btn" data-act="clear-log" title="Pulisci log">🗑 Pulisci</button>
                    </summary>
                    <div class="fm-tikz-modal-debuglog"></div>
                </details>
            </footer>
        </div>`;
    document.body.appendChild(backdrop);

    const editorEl = backdrop.querySelector(".fm-tikz-modal-editor");
    const previewEl = backdrop.querySelector(".fm-tikz-modal-preview");

    // CM6 setup
    const themeCompartment = new Compartment();
    const cm = new EditorView({
        parent: editorEl,
        state: EditorState.create({
            doc: initialBody,
            extensions: [
                lineNumbers(),
                history(),
                drawSelection(),
                highlightActiveLine(),
                foldGutter(),
                panteduFoldService,
                EditorView.lineWrapping,
                StreamLanguage.define(stex),
                syntaxHighlighting(defaultHighlightStyle, { fallback: true }),
                search({ top: true }),
                keymap.of([
                    ...defaultKeymap,
                    ...historyKeymap,
                    ...searchKeymap,
                    ...foldKeymap,
                    indentWithTab,
                    {
                        key: "Mod-s",
                        run: () => { saveAndClose(); return true; },
                    },
                ]),
                themeCompartment.of(oneDark),
                EditorView.updateListener.of((u) => {
                    if (u.docChanged) {
                        debouncePreview(previewEl, () => cm.state.doc.toString(), 500);
                    }
                }),
            ],
        }),
    });

    // Initial preview
    refreshPreview(previewEl, initialBody);

    function close() {
        if (!_modalState) return;
        _modalState = null;
        cm.destroy();
        backdrop.remove();
        clearTimeout(_previewTimer);
    }

    function saveAndClose() {
        const newBody = cm.state.doc.toString();
        textarea.value = wrap(newBody);
        // Invalida lastRenderedValue (anti-flicker short-circuit) per forzare il re-render inline.
        if ("_lastRenderedValue" in textarea) textarea._lastRenderedValue = undefined;
        textarea.dispatchEvent(new Event("input", { bubbles: true }));
        close();
    }

    backdrop.addEventListener("click", (e) => {
        const btn = e.target?.closest?.("[data-act]") || e.target;
        const act = btn?.dataset?.act;
        if (act === "save") saveAndClose();
        else if (act === "cancel") close();
        else if (act === "fold-all") foldAllRegions(cm);
        else if (act === "unfold-all") unfoldAllRegions(cm);
        else if (act === "show-wrapped") {
            e.preventDefault();
            const currentSrc = cm.state.doc.toString();
            const wrapped = wrapTikzSourceForDisplay(currentSrc, "2pt");
            _showWrappedTexDialog(backdrop, currentSrc, wrapped);
        }
        else if (act === "copy-log") {
            e.preventDefault();
            e.stopPropagation();
            // Copia tutto il log: testo top-level + content di ogni <details>/<pre>
            const log = backdrop.querySelector(".fm-tikz-modal-debuglog");
            if (!log) return;
            const lines = [];
            for (const child of log.children) {
                if (child.tagName === "DETAILS") {
                    const summary = child.querySelector("summary")?.textContent?.trim() || "";
                    const dump = child.querySelector("pre.dump")?.textContent || "";
                    lines.push(`==== ${summary} ====`);
                    lines.push(dump);
                    lines.push("");
                } else {
                    lines.push((child.textContent || "").trim());
                }
            }
            const fullText = lines.join("\n");
            navigator.clipboard.writeText(fullText).catch(() => {});
            const orig = btn.textContent;
            btn.textContent = "✓ Copiato";
            setTimeout(() => { btn.textContent = orig; }, 1500);
        }
        else if (act === "clear-log") {
            e.preventDefault();
            e.stopPropagation();
            const log = backdrop.querySelector(".fm-tikz-modal-debuglog");
            if (log) log.innerHTML = "";
            const tsBadge = backdrop.querySelector(".fm-tikz-modal-debuglog-wrap .ts-badge");
            if (tsBadge) tsBadge.textContent = "";
        }
    });

    // ESC = annulla, click su backdrop nudo = annulla
    document.addEventListener("keydown", function escHandler(e) {
        if (!_modalState) { document.removeEventListener("keydown", escHandler); return; }
        if (e.key === "Escape") close();
    });
    backdrop.addEventListener("mousedown", (e) => {
        if (e.target === backdrop) close();
    });

    cm.focus();
    _modalState = { close, cm, textarea };
}

import { foldEffect, unfoldAll, foldedRanges } from "@codemirror/language";

function foldAllRegions(cm) {
    const docText = cm.state.doc.toString();
    const effects = [];
    let lineStart = 0;
    while (lineStart <= docText.length) {
        const lineEnd = docText.indexOf("\n", lineStart);
        const le = lineEnd === -1 ? docText.length : lineEnd;
        const line = docText.slice(lineStart, le);
        if (FOLD_MARKER_RE.test(line)) {
            // find next marker
            let pos = le + 1;
            let foundClose = -1;
            while (pos < docText.length) {
                const ne = docText.indexOf("\n", pos);
                const nlEnd = ne === -1 ? docText.length : ne;
                const nl = docText.slice(pos, nlEnd);
                if (FOLD_MARKER_RE.test(nl)) { foundClose = pos - 1; break; }
                if (ne === -1) break;
                pos = ne + 1;
            }
            const to = foundClose >= 0 ? foundClose : docText.length;
            if (to > le) effects.push(foldEffect.of({ from: le, to }));
        }
        if (lineEnd === -1) break;
        lineStart = lineEnd + 1;
    }
    if (effects.length) cm.dispatch({ effects });
}

function unfoldAllRegions(cm) {
    cm.dispatch({ effects: unfoldAll.of(null) });
}

// Espone su window per uso non-module
if (typeof window !== "undefined") {
    window.FM = window.FM || {};
    window.FM.openTikzModal = openTikzModal;
}
