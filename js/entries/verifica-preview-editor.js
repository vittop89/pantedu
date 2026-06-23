/**
 * Entry Vite dedicato per Verifica Preview Modal (Phase G21.1).
 *
 * Bundled separatamente da `bootstrap.js` per evitare di caricare
 * CodeMirror 6 (~200KB raw / ~30KB gz) sulle pagine che non aprono mai
 * il preview modal.
 *
 * Side-effect:
 *   - Espone `window.FM.VerificaPreview = { openPreview, closeModal, getState }`
 *     (Vite tree-shakes ES exports da entry chunks; window global è il
 *     canale robusto per consumer esterni al bundle.)
 *
 * Lazy load pattern (chiamato da js/modules/features/verifica-preview-modal.js):
 * ```js
 * const manifest = await fetch("/build/manifest.json").then(r => r.json());
 * const entry = manifest["js/entries/verifica-preview-editor.js"];
 * await import(`/build/${entry.file}`);
 * // window.FM.VerificaPreview.openPreview(docs) ora disponibile
 * ```
 */

import { EditorState, StateEffect, StateField } from "@codemirror/state";
import { EditorView, keymap, lineNumbers, highlightActiveLine, drawSelection, Decoration } from "@codemirror/view";
import { defaultKeymap, history, historyKeymap, indentWithTab } from "@codemirror/commands";
import { oneDark } from "@codemirror/theme-one-dark";
import { fetchCsrf, wafFetch } from "../modules/core/dom-utils.js";

// G21.2 v3 — Flash decoration via CodeMirror StateEffect/StateField.
// Sostituisce manipulation DOM (rotta da activeLine re-render) con il
// meccanismo nativo CM6 → garantisce persistence per la durata richiesta.
const flashEffect = StateEffect.define();

const flashField = StateField.define({
    create: () => Decoration.none,
    update(decos, tr) {
        decos = decos.map(tr.changes);
        for (const effect of tr.effects) {
            if (effect.is(flashEffect)) {
                if (effect.value == null) {
                    decos = Decoration.none;
                } else {
                    const lineFrom = effect.value;
                    decos = Decoration.set([
                        Decoration.line({ class: "fm-vp-flash" }).range(lineFrom),
                    ]);
                }
            }
        }
        return decos;
    },
    provide: (f) => EditorView.decorations.from(f),
});

// G21.1 — CDN: usiamo jsdelivr (già whitelisted in CSP connect-src del progetto).
// cdnjs è bloccato dal CSP corrente, quindi PDF.js viene da jsdelivr.
const PDFJS_VERSION = "4.7.76";
const PDFJS_SRC = `https://cdn.jsdelivr.net/npm/pdfjs-dist@${PDFJS_VERSION}/build/pdf.mjs`;
const PDFJS_WORKER = `https://cdn.jsdelivr.net/npm/pdfjs-dist@${PDFJS_VERSION}/build/pdf.worker.mjs`;
const PAKO_SRC = "https://cdn.jsdelivr.net/npm/pako@2.1.0/dist/pako.esm.mjs";

const MODAL_ID = "fm-vp-modal";

// ─────────────────────────── Utility ──────────────────────────────────

function ensureToast(kind, title, msg, ms = 4500) {
    if (window.FM?.ToastManager?.show) {
        window.FM.ToastManager.show(kind, title, msg, ms);
        return;
    }
    // Fallback — su /risdoc/view (shell unificata, no bootstrap) ToastManager
    // può non essere caricato: senza questo, "Salva TEX" e gli errori non
    // davano alcun feedback visibile. Usa la status bar del modal.
    console.warn(`[verifica-preview] ${title}: ${msg}`);
    try {
        const statusKind = (kind === "error" || kind === "warning") ? "error" : "info";
        setStatus(`${title}: ${msg}`, statusKind);
    } catch (_) { /* status bar non pronta */ }
}

// fetchCsrf importato da dom-utils (canonico, cache 60s).

function debounce(fn, ms) {
    let t = null;
    return (...args) => {
        clearTimeout(t);
        t = setTimeout(() => fn(...args), ms);
    };
}

function bytesToBlob(bytes, mime) {
    return new Blob([bytes], { type: mime });
}

function b64ToBytes(b64) {
    const bin = atob(b64);
    const bytes = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
    return bytes;
}

function bytesToB64(bytes) {
    // Robust per array grandi: chunk-by-chunk per evitare stack overflow.
    const CHUNK = 0x8000;
    let bin = "";
    for (let i = 0; i < bytes.length; i += CHUNK) {
        bin += String.fromCharCode.apply(null, bytes.subarray(i, i + CHUNK));
    }
    return btoa(bin);
}

// ─────────────────────────── PDF.js loader ────────────────────────────

let _pdfjsPromise = null;
async function loadPdfJs() {
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

let _pakoPromise = null;
async function loadPako() {
    if (_pakoPromise) return _pakoPromise;
    _pakoPromise = (async () => {
        const mod = await import(/* @vite-ignore */ PAKO_SRC);
        return mod.default || mod;
    })();
    return _pakoPromise;
}

// ─────────────────────────── SyncTeX parser ───────────────────────────

class SyncTexParser {
    constructor(text) {
        this.text = text;
        this.files = new Map();
        this.records = [];
        // Convenzione SyncTeX:
        //   - coordinate raw nei records sono in "synctex_units"
        //   - Unit:N riga dice "1 synctex_unit = N scaled points (sp)"
        //   - Default se assente: Unit=1 (synctex_unit = 1 sp)
        //   - Conversione a punti tipografici: pt = raw * Unit / 65536
        this.unit = 1;          // synctex_unit → sp ratio
        this.magnification = 1; // optional, da Magnification:N (in millesimi, /1000)
        this.parse();
    }

    parse() {
        const lines = this.text.split("\n");
        let currentPage = 0;
        const stack = [];
        // Fattore conversione raw → pt: (Unit * Magnification) / 65536
        // Calcolato dopo aver letto le header lines.
        const SP_PER_PT = 65536;

        for (const ln of lines) {
            if (!ln) continue;
            const c0 = ln[0];

            if (ln.startsWith("Input:")) {
                const m = ln.match(/^Input:(\d+):(.+)$/);
                if (m) this.files.set(m[1], m[2]);
                continue;
            }
            if (ln.startsWith("Unit:")) {
                this.unit = parseFloat(ln.slice(5)) || 1;
                continue;
            }
            if (ln.startsWith("Magnification:")) {
                const v = parseFloat(ln.slice(14));
                if (v > 0) this.magnification = v / 1000.0;
                continue;
            }
            if (c0 === "{") {
                currentPage = parseInt(ln.slice(1), 10) || 0;
                stack.push({ page: currentPage });
                continue;
            }
            if (c0 === "}") {
                stack.pop();
                continue;
            }

            const recMatch = ln.match(/^([xhvkg$])(\d+),(\d+):(-?\d+),(-?\d+)(?::(-?\d+),(-?\d+),(-?\d+))?/);
            if (recMatch && currentPage > 0) {
                const tag  = recMatch[2];
                const line = parseInt(recMatch[3], 10);
                // Conversione corretta sp → pt: raw * Unit / 65536 * mag
                const conv = this.unit * this.magnification / SP_PER_PT;
                const x    = parseInt(recMatch[4], 10) * conv;
                const y    = parseInt(recMatch[5], 10) * conv;
                const w    = recMatch[6] ? parseInt(recMatch[6], 10) * conv : 0;
                const h    = recMatch[7] ? parseInt(recMatch[7], 10) * conv : 0;
                const file = this.files.get(tag) || "doc.tex";
                this.records.push({
                    type: recMatch[1],
                    page: currentPage,
                    x, y, w, h,
                    file, line,
                });
            }
        }
    }

    findTexAtPdfPoint(page, xPt, yPt) {
        // G21.1 v3 — algoritmo robusto per documenti densi (tikz, math, ecc).
        //
        // Lezione learned: filtro `w > 0` esclude troppi record validi.
        // SyncTeX emette molti record 'h' zero-width che sono pin-points su
        // posizioni precise. Inoltre `v` (vertical box) records definiscono
        // confini di paragrafo utili.
        //
        // Strategia:
        //   1. Trova banda Y migliore: il y dei record più vicino a yPt
        //      (cluster di record y simili = "una riga visuale del PDF")
        //   2. Tra i record della banda (±LINE_HEIGHT_PT), prendi quello
        //      con X più vicino:
        //        - se record ha w>0: distance = max(0, dx_from_bbox)
        //        - se record è punto (w=0): distance = |xPt - r.x|
        //   3. Risultato: file + line del best
        //
        const LINE_HEIGHT_PT = 12;   // banda Y per "stessa riga visuale"
        const X_OUT_OF_BAND  = 100;  // se best dx > 100pt → scarta (no riga vicina)

        const onPage = this.records.filter(r => r.page === page);
        if (!onPage.length) return null;

        // Filter: tutti gli h-records (anche zero-width, sono utili) +
        // v-records con w>0 (paragraph boxes)
        const useful = onPage.filter(r =>
            (r.type === "h") || (r.type === "v" && r.w > 0)
        );
        const pool = useful.length ? useful : onPage;

        // Step 1: banda Y migliore = y del record più vicino in y
        let bandY = null;
        let bestDy = Infinity;
        for (const r of pool) {
            const dY = Math.abs(yPt - r.y);
            if (dY < bestDy) {
                bestDy = dY;
                bandY = r.y;
            }
        }
        if (bandY == null) return null;

        // Step 2: tutti i record entro ±LINE_HEIGHT_PT da bandY
        const banded = pool.filter(r => Math.abs(r.y - bandY) <= LINE_HEIGHT_PT);

        // Step 3 — gerarchia di preferenza dentro la banda Y:
        //   PRIORITÀ 1: record con bbox X che CONTIENE xPt (cliccato esatto)
        //   PRIORITÀ 2: record con w>0 (riga di testo) più vicino in X
        //   PRIORITÀ 3: record marker (w=0) più vicino in X
        const containing = [];   // bbox contiene xPt
        const lineRecs   = [];   // w > 0 ma fuori bbox
        const markers    = [];   // w == 0
        for (const r of banded) {
            if (r.w > 0) {
                if (xPt >= r.x && xPt <= r.x + r.w) {
                    containing.push({ r, dx: 0 });
                } else {
                    const dx = xPt < r.x ? (r.x - xPt) : (xPt - (r.x + r.w));
                    lineRecs.push({ r, dx });
                }
            } else {
                markers.push({ r, dx: Math.abs(xPt - r.x) });
            }
        }
        // Sort ognuno per dx, poi sceglie dal pool più prioritario non vuoto
        let chosen = null;
        if (containing.length) {
            // Tra i contenenti, prendi quello con bbox più stretto (più "specifico")
            containing.sort((a, b) => a.r.w - b.r.w);
            chosen = containing[0];
        } else if (lineRecs.length) {
            lineRecs.sort((a, b) => a.dx - b.dx);
            chosen = lineRecs[0];
        } else if (markers.length) {
            markers.sort((a, b) => a.dx - b.dx);
            chosen = markers[0];
        }
        const best = chosen?.r ?? null;
        const bestDx = chosen?.dx ?? Infinity;

        if (!best || bestDx > X_OUT_OF_BAND) {
            // Banda Y vuota o tutti troppo lontani in X → fallback nearest record
            let fallbackBest = null;
            let fallbackDist = Infinity;
            for (const r of onPage) {
                const dx = xPt - r.x;
                const dy = yPt - r.y;
                const d = dx * dx + dy * dy;
                if (d < fallbackDist) {
                    fallbackDist = d;
                    fallbackBest = r;
                }
            }
            if (!fallbackBest) return null;
            return {
                file: fallbackBest.file, line: fallbackBest.line,
                distance: Math.sqrt(fallbackDist),
                strategy: "fallback",
            };
        }

        const inBbox = (best.w > 0 && xPt >= best.x && xPt <= best.x + best.w);
        return {
            file: best.file, line: best.line,
            distance: Math.hypot(bestDx, bestDy),
            strategy: inBbox ? "bbox-contains" : "y-band",
        };
    }

    findPdfAtTexLine(line, file = null) {
        for (const r of this.records) {
            if (file && !r.file.endsWith(file) && r.file !== file) continue;
            if (r.line === line) return r;
        }
        let best = null;
        let bestDist = Infinity;
        for (const r of this.records) {
            if (file && !r.file.endsWith(file) && r.file !== file) continue;
            const d = Math.abs(r.line - line);
            if (d < bestDist) {
                bestDist = d;
                best = r;
            }
        }
        return best;
    }
}

// ─────────────────────────── State ────────────────────────────────────

const State = {
    modal: null,
    docs: [],
    activeIdx: 0,
    autoRebuild: false,
    engine: "pdflatex",
    cm: null,
    cmDirty: false,
    pdfJs: null,
    pdfDoc: null,
    pdfPage: 1,
    pdfScale: 1.4,    // G27.bugfix — zoom tracked in State, non solo dataset
    syncTex: null,
    syncTexDocId: null,
    cache: new Map(),
    debouncedRebuild: null,
    // G22.S10 — multi-file: stato per-doc dei buffer.
    //   files: Map<docId, Map<path, {text, dirty, originalText}>>
    //   activePath: Map<docId, string>  (path attualmente nell'editor per quel doc)
    files: new Map(),
    activePath: new Map(),
};

// ───────────────────────── Modal lifecycle ────────────────────────────

function buildModalShell() {
    const m = document.createElement("div");
    m.id = MODAL_ID;
    m.className = "fm-modal-backdrop fm-vp-backdrop";
    m.innerHTML = `
      <div class="fm-vp-modal" role="dialog" aria-modal="true" aria-labelledby="fm-vp-title">
        <header class="fm-vp-header">
          <h2 id="fm-vp-title" class="fm-vp-title">Anteprima verifica</h2>
          <div class="fm-vp-toolbar">
            <button type="button" class="fm-btn fm-btn--xs" data-act="rebuild" title="Ricompila (Ctrl+S)">▶ Ricompila</button>
            <label class="fm-vp-toggle" title="Auto: salva + ricompila dopo 2 sec di idle">
                <input type="checkbox" data-act="auto-rebuild"> Auto
            </label>
            <select class="fm-vp-engine" data-act="engine">
                <option value="pdflatex">pdflatex</option>
                <option value="xelatex">xelatex</option>
                <option value="lualatex">lualatex</option>
            </select>
            <button type="button" class="fm-btn fm-btn--xs" data-act="insert-geogebra" title="Inserisci grafico GeoGebra al cursor (genera \fmgeogebra{...})">
                <img src="/img/geogebra.svg" width="14" height="14" alt="" style="vertical-align:middle;margin-right:3px"> GeoGebra
            </button>
            <button type="button" class="fm-btn fm-btn--xs fm-vp-save-tex" data-act="save-tex" title="Salva TEX (no rebuild)">💾 Salva TEX</button>
            <button type="button" class="fm-btn fm-btn--xs" data-act="export-pdf" title="Scarica PDF">⤓ PDF</button>
            <span class="fm-vp-preview-only-badge" hidden style="padding:3px 8px;background:#5a3a1a;color:#ffd166;border:1px solid #ffd166;border-radius:3px;font:600 10px/1.2 system-ui;letter-spacing:0.4px"
                  title="Modalità preview: ricompilazione locale senza salvataggio nel DB. Per salvare apri da TEX/PDF.">👁 PREVIEW ONLY</span>
            <button type="button" class="fm-modal-close" data-act="close" aria-label="Chiudi">×</button>
          </div>
        </header>
        <nav class="fm-vp-tabs" role="tablist" aria-label="Varianti"></nav>
        <div class="fm-vp-status" data-status>
            <span data-status-info>Nessuna verifica caricata</span>
            <span data-status-warnings></span>
        </div>
        <main class="fm-vp-body">
          <aside class="fm-vp-filetree" data-filetree aria-label="File sorgenti TeX"></aside>
          <div class="fm-vp-resizer" data-resizer role="separator" aria-orientation="vertical"
               aria-label="Ridimensiona la barra dei file" tabindex="0"
               title="Trascina per ridimensionare (frecce ← → da tastiera)"></div>
          <div class="fm-vp-pane fm-vp-pane--editor" data-pane="editor">
            <div class="fm-vp-editor-breadcrumb" data-editor-breadcrumb>main.tex</div>
            <div class="fm-vp-editor-host" data-cm-host></div>
          </div>
          <div class="fm-vp-pane fm-vp-pane--pdf" data-pane="pdf">
            <div class="fm-vp-pdf-toolbar">
              <button type="button" class="fm-btn fm-btn--xxs" data-act="prev-page">◀</button>
              <span data-page-info>– / –</span>
              <button type="button" class="fm-btn fm-btn--xxs" data-act="next-page">▶</button>
              <button type="button" class="fm-btn fm-btn--xxs" data-act="zoom-out">−</button>
              <button type="button" class="fm-btn fm-btn--xxs" data-act="zoom-in">+</button>
              <span class="fm-vp-pdf-help" title="Tieni Ctrl (o Cmd su Mac) e clicca un punto del PDF per saltare alla riga corrispondente nel TeX">Ctrl+click PDF → TeX</span>
            </div>
            <div class="fm-vp-pdf-host" data-pdf-host></div>
          </div>
        </main>
        <footer class="fm-vp-footer">
          <details class="fm-vp-log">
            <summary>Log compilazione
              <button type="button" class="fm-log-btn" data-act="copy-log" title="Copia tutto il log">📋 Copia</button>
              <button type="button" class="fm-log-btn" data-act="clear-log" title="Pulisci log">🗑 Pulisci</button>
            </summary>
            <pre data-log></pre>
          </details>
        </footer>
      </div>`;
    return m;
}

async function closeModal() {
    if (!State.modal) return;
    // G22.S10 — controlla anche dirty across-buffers (utente potrebbe aver
    // modificato file diversi dal main visibile in editor).
    let anyDirty = State.cmDirty;
    if (!anyDirty) {
        for (const buffers of State.files.values()) {
            for (const buf of buffers.values()) if (buf.dirty) { anyDirty = true; break; }
            if (anyDirty) break;
        }
    }
    if (anyDirty) {
        const proceed = await window.FM.Dialog.confirm("Hai modifiche non salvate. Chiudere comunque?");
        if (!proceed) return;
    }
    if (State.cm) {
        State.cm.destroy();
        State.cm = null;
    }
    if (State.pdfDoc) {
        try { State.pdfDoc.destroy(); } catch {}
        State.pdfDoc = null;
    }
    State.modal.remove();
    State.modal = null;
    State.docs = [];
    State.cache.clear();
    State.files.clear();
    State.activePath.clear();
    State.cmDirty = false;
    document.removeEventListener("keydown", handleGlobalKey);
}

function handleGlobalKey(e) {
    if (!State.modal) return;
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "s") {
        e.preventDefault();
        rebuildActive(true);
        return;
    }
    if (e.key === "Escape") {
        e.preventDefault();
        closeModal();
    }
}

// ───────────────────────── Tabs varianti ──────────────────────────────

function renderTabs() {
    const nav = State.modal.querySelector(".fm-vp-tabs");
    nav.innerHTML = "";
    State.docs.forEach((d, i) => {
        const btn = document.createElement("button");
        btn.type = "button";
        btn.className = "fm-vp-tab" + (i === State.activeIdx ? " fm-vp-tab--active" : "");
        btn.dataset.idx = String(i);
        btn.textContent = d.variant || `#${d.id}`;
        btn.title = d.title ? `${d.title} (${d.variant || d.id})` : `Verifica ${d.id}`;
        btn.addEventListener("click", () => switchTo(i));
        nav.appendChild(btn);
    });
}

async function switchTo(idx) {
    if (idx < 0 || idx >= State.docs.length) return;
    if (State.cmDirty) {
        const proceed = await window.FM.Dialog.confirm("Modifiche non salvate sulla variante corrente. Continuare?");
        if (!proceed) return;
        State.cmDirty = false;
    }
    State.activeIdx = idx;
    renderTabs();
    await loadAndRenderActive();
}

async function loadAndRenderActive() {
    const doc = State.docs[State.activeIdx];
    if (!doc) return;
    setStatus(`Caricamento variante ${doc.variant || doc.id}...`);

    let cached = State.cache.get(doc.id);
    if (!cached) {
        // G22.S10 — multi-file: prima carica tutta la manifest, poi
        // mostra editor sul main.tex, compile in parallelo. Su adapter
        // senza supporto multi-file (template-file), fallback al flat.
        const adapter = getAdapter();
        let buffers = null;
        let texFlatFallback = "";
        try {
            if (adapter.supportsMultiFile && typeof adapter.fetchTexFiles === "function") {
                const files = await adapter.fetchTexFiles(doc);
                buffers = new Map();
                files.forEach(f => {
                    // G22.S15.bis Fase 5 — normalize CRLF→LF: CodeMirror
                    // ritorna sempre LF su getEditorContent. Se originalText
                    // contiene CRLF dal server, cur !== originalText anche
                    // senza modifiche → pallino dirty fantasma.
                    const normalized = (typeof f.content === 'string')
                        ? f.content.replace(/\r\n/g, '\n')
                        : f.content;
                    buffers.set(f.path, {
                        text: normalized,
                        originalText: normalized,
                        dirty: false,
                        missing: !!f.missing,
                        overrideStatus: f.overrideStatus || 'common',
                        binary: !!f.is_binary,
                        size: typeof f.size === 'number' ? f.size : 0,
                    });
                });
                if (!buffers.size) {
                    texFlatFallback = await fetchTex(doc);
                    buffers.set("main.tex", {
                        text: texFlatFallback,
                        originalText: texFlatFallback,
                        dirty: false,
                        missing: false,
                        overrideStatus: 'common',
                    });
                }
            } else {
                texFlatFallback = await fetchTex(doc);
                buffers = new Map([["main.tex", {
                    text: texFlatFallback,
                    originalText: texFlatFallback,
                    dirty: false,
                    missing: false,
                    overrideStatus: 'common',
                }]]);
            }
        } catch (e) {
            setStatus(`Errore caricamento TEX: ${e.message}`, "error");
            setEditorContent("");
            return;
        }
        State.files.set(doc.id, buffers);
        // Path attivo: main.tex se presente, altrimenti il primo.
        const initialPath = buffers.has("main.tex") ? "main.tex" : buffers.keys().next().value;
        State.activePath.set(doc.id, initialPath);

        cached = {
            tex: buffers.get(initialPath).text,
            pdfBytes: null,
            synctex: null,
            log: "",
            warnings: [],
            errors: [],
            engine: State.engine,
            duration_ms: 0,
        };
        State.cache.set(doc.id, cached);
        renderFileTree(doc.id);
        setEditorContent(buffers.get(initialPath).text);

        // Compile in parallelo (non blocca editor)
        try {
            const result = await compileDoc(doc, {
                engine: State.engine,
                activePath: initialPath,
            });
            Object.assign(cached, result);
            State.cache.set(doc.id, cached);
        } catch (e) {
            const detail = e.detail || {};
            cached.log = detail.log || detail.log_excerpt || e.message;
            cached.warnings = detail.warnings || [];
            cached.errors = detail.errors || [{ line: null, message: e.message }];
            updateLog(cached);
            setStatus(`✘ Compile fallito: ${e.message}. TEX modificabile sopra → Ricompila.`, "error");
            return;
        }
    } else {
        // Già in cache: ripristina buffer attivo nell'editor.
        renderFileTree(doc.id);
        const activePath = State.activePath.get(doc.id) || "main.tex";
        const buf = State.files.get(doc.id)?.get(activePath);
        if (buf) setEditorContent(buf.text);
    }

    await renderPdfInPane('[data-pdf-host]', cached.pdfBytes, 1);
    updateLog(cached);
    setStatus(`Engine: ${cached.engine || State.engine} · ${cached.duration_ms || "?"}ms · ${cached.pdfBytes?.length || 0} bytes`);
}

// G22.S10b — File tree sidebar con gruppi + legenda + override-status icone.
//
// Struttura:
//   📁 Elementi comuni  (texCommon/*)
//   📁 Modelli verifica (versioni/*)
//   📁 Griglie di valutazione (griglie/*)
//   📁 Altri (path che non matchano i prefissi noti)
//
// Per ogni file:
//   icona file: 📜 per .sty, 📄 per .tex, 📃 per altro
//   indicator override: 🟢 user · 🏫 institute · · common · ❌ missing
//   pallino dirty (●) se buffer modificato.
const TREE_GROUPS = [
    { key: 'texCommon/',  label: '📁 Elementi comuni' },
    { key: 'versioni/',   label: '📁 Modelli verifica' },
    { key: 'griglie/',    label: '📁 Griglie di valutazione' },
];
const STATUS_ICON = {
    'user':      { glyph: '🟢', tip: 'tua personalizzazione' },
    'institute': { glyph: '🏫', tip: 'dal tuo istituto' },
    'common':    { glyph: '○',  tip: 'usa modello comune' },
    'missing':   { glyph: '❌', tip: 'file mancante: ricreare salvando' },
};

function fileIcon(path) {
    if (path.endsWith('.sty')) return '📜';
    if (path.endsWith('.tex')) return '📄';
    if (path.endsWith('.bib')) return '📚';
    return '📃';
}

function groupFilesByPrefix(paths) {
    const groups = TREE_GROUPS.map(g => ({ ...g, paths: [], subgroups: null }));
    const others = { key: '__other__', label: '📁 Altri', paths: [], subgroups: null };
    for (const p of paths) {
        let matched = false;
        for (const g of groups) {
            if (p.startsWith(g.key)) {
                g.paths.push(p);
                matched = true;
                break;
            }
        }
        if (!matched) others.paths.push(p);
    }
    // Sort dentro ogni gruppo: main_NOR/SOL/DSA/DIS in ordine specifico, resto alfa.
    const variantOrder = { 'main_NOR.tex': 0, 'main_SOL.tex': 1, 'main_DSA.tex': 2, 'main_DIS.tex': 3 };
    for (const g of [...groups, others]) {
        g.paths.sort((a, b) => {
            const aBase = a.split('/').pop();
            const bBase = b.split('/').pop();
            const aOrd = variantOrder[aBase];
            const bOrd = variantOrder[bBase];
            if (aOrd != null && bOrd != null) return aOrd - bOrd;
            if (aOrd != null) return -1;
            if (bOrd != null) return 1;
            return a.localeCompare(b);
        });
    }

    // G22.S15.bis Fase 4 — Sub-grouping per "Modelli verifica" (versioni/):
    //   📁 main      → main_NOR.tex, main_SOL.tex, main_DSA.tex, main_DIS.tex
    //   📁 esercizi  → esercizi_NOR.tex, esercizi_SOL.tex, ecc.
    //   📁 altri     → ogni altro file dentro versioni/
    for (const g of groups) {
        if (g.key === 'versioni/' && g.paths.length > 0) {
            const mainPaths = [];
            const esercPaths = [];
            const otherPaths = [];
            for (const p of g.paths) {
                const base = p.split('/').pop();
                if (/^main_/.test(base)) mainPaths.push(p);
                else if (/^esercizi/.test(base)) esercPaths.push(p);
                else otherPaths.push(p);
            }
            const subs = [];
            if (mainPaths.length)  subs.push({ label: '📁 main',     paths: mainPaths });
            if (esercPaths.length) subs.push({ label: '📁 esercizi', paths: esercPaths });
            if (otherPaths.length) subs.push({ label: '📁 altri',    paths: otherPaths });
            if (subs.length > 1) g.subgroups = subs;  // mostra sub solo se >1
        }
    }

    const result = groups.filter(g => g.paths.length > 0);
    if (others.paths.length) result.push(others);
    return result;
}

/* G22.S15.bis Fase 4 — renderFileTree aggregato:
 * Mostra TUTTI i file di TUTTE le varianti (docs) nel filetree, raggruppati
 * per cartella semantica. Path variant-specific (`versioni/main_X.tex`,
 * `versioni/esercizi_X.tex`) sotto sub-cartelle `📁 main` / `📁 esercizi`,
 * con suffisso variante (es. "main_NOR.tex (A_NOR)"). File shared
 * (texCommon/, griglie/) mostrati una volta sola.
 *
 * Click su file di variante diversa → swicha activeIdx + path.
 *
 * Tab bar redundante con questa vista → nascosta. */
/**
 * Sidebar — sezione "Immagini" sotto il filetree TeX.
 * Fetch /overrides per il primo doc risdoc-template (o teacher-content forkato);
 * mostra grid di thumbnails (override + system) con path da usare in
 * \includegraphics{images/...}. Cliccando un thumb copia il path in clipboard.
 */
async function renderImagesSection(host) {
    if (!host) return;
    const firstDoc = State.docs?.[0];
    if (!firstDoc) return;
    // Risoluzione del MASTER template id:
    //  - mode "risdoc-template": doc.id È già il template id (es. 16)
    //  - mode "teacher-content": doc.id è l'id del teacher_content (fork); il
    //    master template id sta nel metadata model_template_id / template_seed_id
    //  - default: parse numerico dell'id (back-compat)
    let templateId = null;
    if (State.mode === "risdoc-template") {
        templateId = typeof firstDoc.id === "number" ? firstDoc.id
            : (parseInt(String(firstDoc.id || "0"), 10) || null);
    } else if (State.mode === "teacher-content") {
        const tcId = typeof firstDoc.id === "number" ? firstDoc.id
            : (parseInt(String(firstDoc.id || "0"), 10) || null);
        if (tcId) {
            try {
                const r = await wafFetch(`/api/teacher/content/${tcId}`, { credentials: "same-origin" });
                if (r.ok) {
                    const j = await r.json();
                    let meta = j.content?.metadata;
                    if (!meta && typeof j.content?.metadata_json === "string") {
                        try { meta = JSON.parse(j.content.metadata_json); } catch (_) {}
                    }
                    templateId = parseInt(meta?.model_template_id || meta?.template_seed_id || 0, 10) || null;
                }
            } catch (_) { /* fallthrough */ }
        }
    } else {
        templateId = typeof firstDoc.id === "number" ? firstDoc.id
            : (parseInt(String(firstDoc.id || "0"), 10) || null);
    }
    if (!templateId) { host.innerHTML = ""; return; }
    host.innerHTML = `<h4 class="fm-vp-filetree__group">🖼 Immagini</h4>
        <div class="fm-vp-images__loading" style="opacity:0.6;font-size:11px;padding:4px 10px">Caricamento…</div>`;
    let data = null;
    try {
        const r = await wafFetch(`/api/risdoc/templates/${templateId}/overrides`, { credentials: "same-origin" });
        if (r.ok) data = await r.json();
    } catch (_) {}
    if (!data || (!data.overrides?.length && !data.system_images?.length)) {
        host.innerHTML = ""; // niente sezione se nessuna immagine
        return;
    }
    const items = [
        ...(data.overrides || []).filter(o => o.kind === "image").map(o => ({ ...o, _kind: "override" })),
        ...(data.system_images || []).map(o => ({ ...o, _kind: "system" })),
    ];
    const baseUrl = `/api/risdoc/templates/${templateId}/file?kind=image&path=`;
    const rows = items.map((it) => {
        const tex = `\\includegraphics{${it.relative_path}}`;
        return `
            <button type="button" class="fm-vp-images__item fm-vp-images__item--${it._kind}"
                    data-tex="${escapeHtml(tex)}" data-path="${escapeHtml(it.relative_path)}"
                    title="Click → copia '${escapeHtml(tex)}' negli appunti">
                <img src="${baseUrl}${encodeURIComponent(it.relative_path)}" alt="${escapeHtml(it.relative_path)}" loading="lazy">
                <span class="fm-vp-images__path">${escapeHtml(it.relative_path)}</span>
                <span class="fm-vp-images__badge fm-vp-images__badge--${it._kind}">${it._kind === "system" ? "sistema" : "override"}</span>
            </button>
        `;
    }).join("");
    host.innerHTML = `
        <h4 class="fm-vp-filetree__group">🖼 Immagini (${items.length})</h4>
        <p class="fm-vp-images__hint">Click su un'immagine per copiare il path da incollare in <code>\\includegraphics{...}</code>.</p>
        <div class="fm-vp-images__grid">${rows}</div>
    `;
    host.querySelectorAll(".fm-vp-images__item").forEach((btn) => {
        btn.addEventListener("click", async () => {
            const tex = btn.dataset.tex || "";
            try {
                await navigator.clipboard.writeText(tex);
                btn.classList.add("fm-vp-images__item--copied");
                setTimeout(() => btn.classList.remove("fm-vp-images__item--copied"), 1200);
            } catch (_) { /* clipboard fail silent */ }
        });
    });
}

function renderFileTree(_legacyDocId) {
    if (!State.modal) return;
    const tree = State.modal.querySelector("[data-filetree]");
    if (!tree) return;
    if (!State.docs || !State.docs.length) {
        tree.innerHTML = '<div class="fm-vp-filetree__empty">Nessun file</div>';
        return;
    }

    // Variante e activeDoc correnti
    const activeDoc = State.docs[State.activeIdx];
    const activeDocId = activeDoc?.id;
    const activePath = State.activePath.get(activeDocId);

    // Raccolta entries: per ogni doc, prendi i suoi buffers
    /** @type {Array<{docId:number, variant:string, path:string, buf:any}>} */
    const allEntries = [];
    for (const doc of State.docs) {
        const buffers = State.files.get(doc.id);
        if (!buffers) continue;
        const variantStr = String(doc.variant || '');
        const variant = variantStr.match(/(SOL|NOR|DSA|DIS)$/)?.[1] || variantStr || '';
        for (const [path, buf] of buffers.entries()) {
            allEntries.push({ docId: doc.id, variant, path, buf });
        }
    }
    if (allEntries.length === 0) {
        tree.innerHTML = '<div class="fm-vp-filetree__empty">Nessun file</div>';
        return;
    }

    // Suddivisione semantica:
    //   versioni/main_*.tex  → "Modelli verifica > main"  (per-variante)
    //   versioni/esercizi*   → "Modelli verifica > esercizi" (per-variante)
    //   versioni/* altro     → "Modelli verifica > altri" (per-variante)
    //   texCommon/*          → "Elementi comuni" (shared, dedup)
    //   griglie/*            → "Griglie di valutazione" (shared)
    //   geogebra/*           → "Modelli verifica > geogebra" (per-variante)
    //   altro                → "Altri" (shared)
    const buckets = {
        main:    [],    // [{docId, variant, path, buf}]
        eserc:   [],
        ggb:     new Map(),    // path → entry (dedup binari shared tra varianti)
        verAlt:  new Map(),    // path → entry (dedup: tikz_preamble e fonti_KIND condivisi via batch-sync)
        common:  new Map(),    // path → entry (dedup)
        griglie: new Map(),
        other:   new Map(),
    };

    // G22.S15.bis Fase 5 — file binari (PDF/img) sotto versioni/geogebra/
    // sono shared tra varianti (Option C blob dedup): mostriamo UNA sola
    // entry per path. La variante "rappresentante" è preferibilmente la
    // attiva, altrimenti la prima incontrata.
    const isBinaryPath = (p) => /\.(pdf|png|jpe?g|gif|svg|webp)$/i.test(p);
    const setOrPreferActive = (map, e) => {
        const prev = map.get(e.path);
        if (!prev) { map.set(e.path, e); return; }
        if (e.docId === activeDocId) map.set(e.path, e);
    };

    for (const e of allEntries) {
        if (e.path.startsWith('versioni/')) {
            const base = e.path.split('/').pop();
            // Binari shared (geogebra/N.pdf, immagini): dedup per path.
            if (isBinaryPath(e.path)) {
                setOrPreferActive(buckets.ggb, e);
                continue;
            }
            if (/^main_/.test(base))           buckets.main.push(e);
            else if (/^esercizi/.test(base))   buckets.eserc.push(e);
            // G27.batch-sync — versioni/ \"altri\" (tikz_preamble.tex,
            // fonti_KIND.tex): dedup per path. Stesso path tra sibling-row
            // mantiene stesso content via propagation server-side, quindi
            // 1 entry visibile è sufficiente (preferisci variante attiva).
            else                                setOrPreferActive(buckets.verAlt, e);
        } else if (e.path.startsWith('geogebra/')) {
            // Bundle root /geogebra (no versioni/): dedup per path.
            setOrPreferActive(buckets.ggb, e);
        } else if (e.path.startsWith('texCommon/')) {
            // Solo prima occorrenza: file shared identico tra varianti
            if (!buckets.common.has(e.path)) buckets.common.set(e.path, e);
        } else if (e.path.startsWith('griglie/')) {
            if (!buckets.griglie.has(e.path)) buckets.griglie.set(e.path, e);
        } else {
            if (!buckets.other.has(e.path)) buckets.other.set(e.path, e);
        }
    }

    // Sort per variante in ordine SOL/NOR/DSA/DIS poi alfabetico
    const variantOrder = { 'SOL': 0, 'NOR': 1, 'DSA': 2, 'DIS': 3 };
    const sortByVariant = (a, b) => {
        const av = variantOrder[a.variant] ?? 99;
        const bv = variantOrder[b.variant] ?? 99;
        if (av !== bv) return av - bv;
        return a.path.localeCompare(b.path);
    };
    buckets.main.sort(sortByVariant);
    buckets.eserc.sort(sortByVariant);

    // Legenda — costruita dalla mappa STATUS_ICON (DRY: glifi sempre allineati
    // alle icone di stato delle righe) + variante attiva. Ogni voce è un item
    // con glifo + descrizione, così "comune" (○) non si confonde col separatore.
    const legendItems = [
        [STATUS_ICON.user.glyph, 'tua personalizzazione'],
        [STATUS_ICON.institute.glyph, 'dal tuo istituto'],
        [STATUS_ICON.common.glyph, 'modello comune'],
        [STATUS_ICON.missing.glyph, 'file mancante'],
        ['⭐', 'variante attiva'],
    ];
    const html = [];
    // <details> espandibile: compatta di default (una riga "Legenda"), all'apertura
    // mostra TUTTE le voci a colonna singola con wrap → niente clipping nella
    // sidebar stretta (prima era grid 2-col + nowrap → testo tagliato).
    html.push('<details class="fm-vp-filetree__legend">');
    html.push('<summary class="fm-vp-filetree__legend-title">Legenda</summary>');
    html.push('<ul class="fm-vp-filetree__legend-list">');
    for (const [glyph, desc] of legendItems) {
        html.push(`<li class="fm-vp-filetree__legend-item"><span class="fm-vp-filetree__legend-glyph" aria-hidden="true">${glyph}</span> ${escapeHtml(desc)}</li>`);
    }
    html.push('</ul>');
    html.push('</details>');

    // Renderer di una riga file: include eventuale suffisso variante
    const renderFileRow = (entry, level, showVariant = false) => {
        const { docId, variant, path, buf } = entry;
        const status = buf.missing ? 'missing' : (buf.overrideStatus || 'common');
        const statusInfo = STATUS_ICON[status] || STATUS_ICON.common;
        const baseName = path.split('/').pop();
        const indent = 10 + level * 14;
        const cls = ['fm-vp-filetree__item'];
        const isActive = (docId === activeDocId && path === activePath);
        const isActiveDoc = docId === activeDocId;
        if (isActive)        cls.push('fm-vp-filetree__item--active');
        if (buf.dirty)       cls.push('fm-vp-filetree__item--dirty');
        if (buf.missing)     cls.push('fm-vp-filetree__item--missing');
        const variantBadge = showVariant && variant
            ? ` <span style="opacity:0.6;font-size:10px;margin-left:4px">(${escapeHtml(variant)}${isActiveDoc ? ' ⭐' : ''})</span>`
            : '';
        html.push(`
            <div class="${cls.join(' ')}" data-doc-id="${docId}" data-path="${escapeHtml(path)}"
                 title="${escapeHtml(path)}${variant ? ' • variante ' + variant : ''}${buf.dirty ? ' • modifiche non salvate' : ''}"
                 style="padding-left:${indent}px">
                <span class="fm-vp-filetree__name">${fileIcon(path)} ${escapeHtml(baseName)}${variantBadge}</span>
                <span class="fm-vp-filetree__dot" aria-hidden="true">●</span>
                <span class="fm-vp-filetree__status" title="${escapeHtml(statusInfo.tip)}">${statusInfo.glyph}</span>
            </div>
        `);
    };

    // Modelli verifica (versioni/) — sub-grouped
    if (buckets.main.length || buckets.eserc.length || buckets.verAlt.size || buckets.ggb.size) {
        html.push(`<h4 class="fm-vp-filetree__group">📁 Modelli verifica</h4>`);
        if (buckets.main.length) {
            html.push(`<h5 class="fm-vp-filetree__subgroup" style="margin:6px 0 2px 14px;font-size:11px;color:#9bb">📁 main</h5>`);
            buckets.main.forEach(e => renderFileRow(e, 1, true));
        }
        if (buckets.eserc.length) {
            html.push(`<h5 class="fm-vp-filetree__subgroup" style="margin:6px 0 2px 14px;font-size:11px;color:#9bb">📁 esercizi</h5>`);
            buckets.eserc.forEach(e => renderFileRow(e, 1, true));
        }
        if (buckets.ggb.size) {
            html.push(`<h5 class="fm-vp-filetree__subgroup" style="margin:6px 0 2px 14px;font-size:11px;color:#9bb">📁 geogebra (PDF allegati, condivisi)</h5>`);
            // Binari deduplicati: una sola riga per path, no badge variante
            // (è shared tra tutte le varianti).
            [...buckets.ggb.values()].sort((a, b) => a.path.localeCompare(b.path))
                .forEach(e => renderFileRow(e, 1, false));
        }
        if (buckets.verAlt.size) {
            html.push(`<h5 class="fm-vp-filetree__subgroup" style="margin:6px 0 2px 14px;font-size:11px;color:#9bb">📁 altri</h5>`);
            // Dedup per path: 1 entry visibile (content allineato cross-variant via batch-sync).
            [...buckets.verAlt.values()].sort((a, b) => a.path.localeCompare(b.path))
                .forEach(e => renderFileRow(e, 1, false));
        }
    }

    // Griglie (shared)
    if (buckets.griglie.size) {
        html.push(`<h4 class="fm-vp-filetree__group">📁 Griglie di valutazione</h4>`);
        [...buckets.griglie.values()].sort((a, b) => a.path.localeCompare(b.path))
            .forEach(e => renderFileRow(e, 0, false));
    }

    // Elementi comuni (shared)
    if (buckets.common.size) {
        html.push(`<h4 class="fm-vp-filetree__group">📁 Elementi comuni</h4>`);
        [...buckets.common.values()].sort((a, b) => a.path.localeCompare(b.path))
            .forEach(e => renderFileRow(e, 0, false));
    }

    // Altri (shared)
    if (buckets.other.size) {
        html.push(`<h4 class="fm-vp-filetree__group">📁 Altri</h4>`);
        [...buckets.other.values()].sort((a, b) => a.path.localeCompare(b.path))
            .forEach(e => renderFileRow(e, 0, false));
    }

    // Sezione "🖼 Immagini" — placeholder che viene popolato async sotto
    // (mostra thumbnails delle immagini override + sistema del template per
    // ricordare i path corretti da usare in \includegraphics{...}).
    // Visibile solo per docs risdoc-template / teacher-content forkati.
    const firstRisdocDoc = State.docs.find(d => /^(template-|teacher-content-)/.test(String(d.id)) || d._isRisdocTemplate);
    html.push('<div class="fm-vp-filetree__images" data-filetree-images></div>');

    tree.innerHTML = html.join('');
    // Render async della sezione immagini (fetch /overrides per il primo doc
    // risdoc-template / teacher-content che incontriamo).
    renderImagesSection(tree.querySelector('[data-filetree-images]'));
    // Click → switchFile (può cambiare doc + path)
    // G22.S15.bis Fase 5 — doc.id può essere stringa (es. "teacher-templates"
    // in template-file mode) o numero (verifica DB id). NON forzare parseInt
    // o le stringhe diventano NaN e switchFile bail-out su State.files.get(NaN).
    tree.querySelectorAll('[data-path]').forEach(el => {
        el.addEventListener('click', () => {
            // ADR-026: doc.id può essere stato salvato come STRING (es. templateId
            // passato da risdoc-template mode = "16") o NUMBER (verifica DB id).
            // dataset.docId è sempre string → prova entrambe le chiavi per
            // matchare la chiave reale in State.files (era bug "click non switcha").
            const raw = el.dataset.docId;
            const asNum = parseInt(raw, 10);
            let targetDocId = raw;
            if (!State.files.has(raw) && String(asNum) === raw && State.files.has(asNum)) {
                targetDocId = asNum;
            }
            switchFile(targetDocId, el.dataset.path);
        });
    });
    // Breadcrumb
    const bc = State.modal.querySelector("[data-editor-breadcrumb]");
    if (bc) {
        const activeBuffers = State.files.get(activeDocId);
        const buf = activeBuffers?.get(activePath);
        const tag = buf?.missing ? ' ❌ MANCANTE' : '';
        const variant = activeDoc?.variant ? ` [${activeDoc.variant}]` : '';
        bc.textContent = (activePath || "—") + variant + tag;
    }

    // Tab bar — nascosta quando filetree mostra le varianti aggregato
    const tabsBar = State.modal.querySelector(".fm-vp-tabs");
    if (tabsBar) tabsBar.style.display = "none";
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({
        "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;",
    }[c]));
}

// Salva il testo CM corrente nel buffer del path attivo, poi swappa al nuovo.
// G22.S15.bis Fase 4 — supporta cross-variant switch: se il `docId` target
// differisce dall'attivo, prima cambiamo variante (`State.activeIdx`).
function switchFile(targetDocId, newPath) {
    const buffers = State.files.get(targetDocId);
    if (!buffers || !buffers.has(newPath)) return;

    // Salva il buffer del path attivo CORRENTE (può essere su altro doc)
    const currentDoc = State.docs[State.activeIdx];
    const currentDocId = currentDoc?.id;
    const oldPath = currentDocId !== undefined ? State.activePath.get(currentDocId) : null;
    if (currentDocId !== undefined && oldPath) {
        const oldBuffers = State.files.get(currentDocId);
        const oldBuf = oldBuffers?.get(oldPath);
        // G22.S15.bis Fase 5 — non salvare il contenuto del CodeMirror nel
        // buffer se il vecchio file era binario: il CM mostra un placeholder
        // testuale (es. "📄 File binario...") che non è mai modifica utente.
        if (oldBuf && !oldBuf.binary) {
            const cur = getEditorContent();
            if (cur !== oldBuf.text) {
                oldBuf.text = cur;
                oldBuf.dirty = (cur !== oldBuf.originalText);
            }
        }
    }

    // Cross-variant: se il target è in un doc diverso, cambia activeIdx
    let crossVariant = false;
    if (currentDocId !== targetDocId) {
        const newIdx = State.docs.findIndex(d => d.id === targetDocId);
        if (newIdx >= 0) {
            State.activeIdx = newIdx;
            crossVariant = true;
        }
    }

    // No-op se stesso file e stessa variante
    if (!crossVariant && oldPath === newPath) return;

    State.activePath.set(targetDocId, newPath);
    const newBuf = buffers.get(newPath);
    // G22.S15.bis Fase 4 — file binari (PDF, immagini) non si renderizzano
    // in CodeMirror. Mostra placeholder con info dimensione + suggerimento.
    const isBinary = /\.(pdf|png|jpe?g|gif|svg|webp)$/i.test(newPath)
                  || (newBuf.text === "" && newBuf.originalText === "");
    if (isBinary && newPath.endsWith('.pdf')) {
        const ext = newPath.split('.').pop().toLowerCase();
        const placeholder = `% ╔══════════════════════════════════════════════════════════════════╗
% ║  📄 File binario: ${newPath}
% ║  Tipo: ${ext.toUpperCase()}
% ║
% ║  Questo è un file PDF allegato al bundle (es. grafico GeoGebra
% ║  convertito in PDF vettoriale via rsvg-convert sul VPS).
% ║
% ║  pdflatex lo include nel documento finale tramite il comando:
% ║    \\includegraphics{geogebra/N}
% ║
% ║  → Per VEDERE l'immagine: clicca un file .tex e ricompila.
% ║  → Il PDF è solo un asset allegato, non editabile.
% ║
% ║  Per eliminarlo: cancella il \\includegraphics dal .tex padre
% ║  e rifa "Salva TEX". Il PDF orfano viene eliminato al rebuild.
% ╚══════════════════════════════════════════════════════════════════╝`;
        setEditorContent(placeholder);
        State.cmDirty = anyBufferDirty(targetDocId);
    } else {
        setEditorContent(newBuf.text);
        State.cmDirty = anyBufferDirty(targetDocId);
    }

    // Se variante cambiata, ricarica il PDF della nuova variant
    if (crossVariant) {
        const cached = State.cache.get(targetDocId);
        if (cached?.pdfBytes && typeof renderPdfInPane === 'function') {
            try { renderPdfInPane('[data-pdf-host]', cached.pdfBytes, 1); } catch (_) {}
        }
    }

    renderFileTree(targetDocId);

    // G22.S15.bis Fase 5 — auto-compile in template-file mode al cambio di
    // file (evita di dover cliccare "Ricompila" manualmente per vedere
    // l'anteprima del file selezionato). Skip per binari + skip se
    // crossVariant (già gestito sopra con il PDF cached).
    if (State.mode === 'template-file' && !isBinary && !crossVariant) {
        // Debounced via debouncedRebuild se attivo, altrimenti rebuild diretto
        if (typeof rebuildActive === 'function') {
            try { rebuildActive(false); } catch (_) {}
        }
    }
}

function anyBufferDirty(docId) {
    const buffers = State.files.get(docId);
    if (!buffers) return false;
    for (const buf of buffers.values()) if (buf.dirty) return true;
    return false;
}

// Sincronizza il buffer attivo con il testo corrente dell'editor (chiamato
// prima di save/compile per riflettere modifiche non ancora committate dal
// listener onChange).
function syncActiveBuffer(docId) {
    const buffers = State.files.get(docId);
    if (!buffers) return;
    const activePath = State.activePath.get(docId);
    if (!activePath) return;
    const buf = buffers.get(activePath);
    if (!buf) return;
    // G22.S15.bis Fase 5 — buffer binari: non sincronizzare il placeholder.
    if (buf.binary) return;
    const cur = getEditorContent();
    buf.text = cur;
    buf.dirty = (cur !== buf.originalText);
}

// ───────────────────────── Backend wrappers ───────────────────────────

// G21.4 — Adapters: il modal supporta 2 modi:
//   - "verifica" (default): usa /api/verifica/{id}/* (TEX/PDF/synctex su DB)
//   - "template-file": usa /api/teacher/verifica/files/* (file template editor)
// L'adapter viene scelto in base a State.mode passato a openPreview.

const verificaAdapter = {
    async fetchTex(doc) {
        const r = await wafFetch(`/api/verifica/${doc.id}/tex`, { credentials: "same-origin" });
        if (!r.ok) throw new Error(`fetchTex HTTP ${r.status}`);
        return await r.text();
    },
    async compile(doc, { engine, passes = 2, texOverride = null, saveTex = false } = {}) {
        const csrf = await fetchCsrf();
        const body = { engine, passes };
        if (texOverride != null) body.tex_override = texOverride;
        if (saveTex) body.save_tex = true;
        const r = await wafFetch(`/api/verifica/${doc.id}/compile?with_artifacts=1`, {
            method: "POST", credentials: "same-origin",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
            body: JSON.stringify(body),
        });
        const data = await r.json();
        if (!r.ok || !data.ok) {
            const err = new Error(data.error || `compile HTTP ${r.status}`);
            err.detail = data;
            throw err;
        }
        // G22.S16 — formatted_files: dict {path: content_string} dal VPS via
        // latexindent. Il caller (rebuildActive) sovrascrive i buffer in
        // editor con queste versioni riformattate.
        return {
            pdfBytes: data.pdf_b64 ? b64ToBytes(data.pdf_b64) : null,
            synctex:  data.synctex_gz_b64 ? b64ToBytes(data.synctex_gz_b64) : null,
            log:      data.log || "",
            warnings: data.warnings || [],
            errors:   data.errors || [],
            engine:   data.compile?.engine || engine,
            duration_ms: data.compile?.duration_ms || 0,
            formattedFiles: (data.formatted_files && typeof data.formatted_files === "object")
                ? data.formatted_files : {},
        };
    },
    async saveTex(doc, texContent) {
        const csrf = await fetchCsrf();
        const r = await wafFetch(`/api/verifica/${doc.id}/tex`, {
            method: "POST", credentials: "same-origin",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
            body: JSON.stringify({ tex: texContent }),
        });
        const data = await r.json();
        if (!r.ok || !data.ok) throw new Error(data.error || `saveTex HTTP ${r.status}`);
        return data;
    },
    // G22.S10 — multi-file: GET manifest + POST batch.
    async fetchTexFiles(doc) {
        const r = await wafFetch(`/api/verifica/${doc.id}/tex-files`, { credentials: "same-origin" });
        const j = await r.json();
        if (!r.ok || !j.ok) throw new Error(j.error || `fetchTexFiles HTTP ${r.status}`);
        // Schema: [{path, content, size, missing?, overrideStatus?}]
        return Array.isArray(j.files) ? j.files : [];
    },
    async saveTexFiles(doc, files) {
        const csrf = await fetchCsrf();
        const r = await wafFetch(`/api/verifica/${doc.id}/tex-files`, {
            method: "POST", credentials: "same-origin",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
            body: JSON.stringify({ files }),
        });
        const data = await r.json();
        if (!r.ok || !data.ok) throw new Error(data.error || `saveTexFiles HTTP ${r.status}`);
        return data;
    },
    supportsSyncTex: true,
    supportsMultiFile: true,
    backendUrlFor: (doc) => `/api/verifica/${doc.id}/synctex/edit`,
};

const templateFileAdapter = {
    // G22.S10c — single-file fetch (legacy callsite "Apri preview" del template editor).
    async fetchTex(doc) {
        const url = `/api/teacher/verifica/files/read?path=${encodeURIComponent(doc.path)}`;
        const r = await wafFetch(url, { credentials: "same-origin" });
        const j = await r.json();
        if (!r.ok || !j.ok) throw new Error(j.error || `fetchTex HTTP ${r.status}`);
        return j.content || "";
    },
    // G22.S10c — multi-file fetch: list + parallel read per file.
    // Doc è virtuale ('teacher-templates'): tutti i file vivono sotto un unico
    // "doc" fittizio nello State del modal.
    async fetchTexFiles(doc) {
        const instParam = doc.institute ? `?institute=${encodeURIComponent(doc.institute)}` : '';
        const r = await wafFetch(`/api/teacher/verifica/files${instParam}`, { credentials: "same-origin" });
        const j = await r.json();
        if (!r.ok || !j.ok) throw new Error(j.error || `fetchTexFiles HTTP ${r.status}`);
        const list = Array.isArray(j.files) ? j.files : [];
        // Parallel /read — limita batch a 8 concorrenti per non sovraccaricare PHP-FPM.
        const PARALLEL = 8;
        const out = new Array(list.length);
        let cursor = 0;
        const worker = async () => {
            while (cursor < list.length) {
                const i = cursor++;
                const f = list[i];
                try {
                    const url = `/api/teacher/verifica/files/read?path=${encodeURIComponent(f.path)}` + (instParam ? '&' + instParam.slice(1) : '');
                    const rr = await wafFetch(url, { credentials: "same-origin" });
                    const jj = await rr.json();
                    out[i] = {
                        path: f.path,
                        content: (rr.ok && jj.ok) ? (jj.content || "") : "",
                        missing: f.source === 'missing' || (!rr.ok || !jj.ok),
                        // Mappa source backend → overrideStatus frontend.
                        overrideStatus:
                            f.source === 'teacher'   ? 'user'
                          : f.source === 'institute' ? 'institute'
                          : f.source === 'default'   ? 'common'
                          : 'missing',
                    };
                } catch (e) {
                    out[i] = { path: f.path, content: "", missing: true, overrideStatus: 'missing' };
                }
            }
        };
        await Promise.all(Array.from({ length: Math.min(PARALLEL, list.length) }, worker));
        return out.filter(Boolean);
    },
    // G22.S10c — save batch parallel (1 /write per file modificato).
    // Backend templateFileAdapter.write crea/sovrascrive il file SOLO nel
    // teacher-scope (cascade gestita lato read), quindi salvare un file che
    // era 'default' lo trasforma in 'user'. La UI ricarica la tree dopo save.
    async saveTexFiles(doc, files) {
        const csrf = await fetchCsrf();
        const PARALLEL = 6;
        let cursor = 0;
        const errors = [];
        const worker = async () => {
            while (cursor < files.length) {
                const i = cursor++;
                const f = files[i];
                try {
                    const r = await wafFetch(`/api/teacher/verifica/files/write`, {
                        method: "POST", credentials: "same-origin",
                        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
                        body: JSON.stringify({ path: f.path, content: f.content, _csrf: csrf }),
                    });
                    const data = await r.json();
                    if (!r.ok || !data.ok) errors.push(`${f.path}: ${data.error || r.status}`);
                } catch (e) {
                    errors.push(`${f.path}: ${e.message}`);
                }
            }
        };
        await Promise.all(Array.from({ length: Math.min(PARALLEL, files.length) }, worker));
        if (errors.length) throw new Error(`saveTexFiles: ${errors.length} errori — ${errors.slice(0, 3).join('; ')}`);
        return { ok: true, saved: files.length };
    },
    async compile(doc, { texOverride = null, activePath = null } = {}) {
        // G22.S10c — multi-file: usa activePath dal modal State; fallback a doc.path
        // per chiamate legacy single-file.
        const targetPath = activePath || doc.path;
        if (!targetPath) {
            const e = new Error("compile: nessun file selezionato");
            e.detail = {};
            throw e;
        }
        const csrf = await fetchCsrf();
        const r = await wafFetch(`/api/teacher/verifica/files/preview-pdf`, {
            method: "POST", credentials: "same-origin",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
            body: JSON.stringify({
                path: targetPath,
                content: texOverride,
                _csrf: csrf,
            }),
        });
        if (!r.ok) {
            const j = await r.json().catch(() => ({}));
            const err = new Error(j.error || `compile HTTP ${r.status}`);
            err.detail = { ...j, log: j.log_excerpt || "" };
            throw err;
        }
        const blob = await r.blob();
        const buf = new Uint8Array(await blob.arrayBuffer());
        return {
            pdfBytes: buf,
            synctex: null,
            log: "",
            warnings: [],
            errors: [],
            engine: "pdflatex",
            duration_ms: parseInt(r.headers.get("X-Compile-Duration-Ms") || "0", 10),
        };
    },
    async saveTex(doc, texContent) {
        const csrf = await fetchCsrf();
        const r = await wafFetch(`/api/teacher/verifica/files/write`, {
            method: "POST", credentials: "same-origin",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
            body: JSON.stringify({ path: doc.path, content: texContent, _csrf: csrf }),
        });
        const data = await r.json();
        if (!r.ok || !data.ok) throw new Error(data.error || `saveTex HTTP ${r.status}`);
        return data;
    },
    supportsSyncTex: false,
    supportsMultiFile: true,
    backendUrlFor: () => null,
};

// G22.S11 — Adapter per modal su risdoc template view (toolbar bottone TEX/PDF).
// Multi-file mode: 4 file (main.tex + body + 2 texCommon). Compile via VPS
// tex-compile invocando POST /api/risdoc/templates/{id}/compile-pdf.
const risdocAdapter = {
    async fetchTexFiles(doc) {
        const csrf = await fetchCsrf();
        const fd = new FormData();
        fd.append("form_state", JSON.stringify(doc.formState || {}));
        fd.append("_csrf", csrf);
        const r = await wafFetch(`/api/risdoc/templates/${doc.id}/tex-files`, {
            method: "POST", credentials: "same-origin",
            headers: { "X-CSRF-Token": csrf },
            body: fd,
        });
        const j = await r.json();
        if (!r.ok || !j.ok) throw new Error(j.error || `fetchTexFiles HTTP ${r.status}`);
        return Array.isArray(j.files) ? j.files : [];
    },
    async saveTexFiles(doc, files) {
        const csrf = await fetchCsrf();
        const r = await wafFetch(`/api/risdoc/templates/${doc.id}/tex-files/save`, {
            method: "POST", credentials: "same-origin",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
            body: JSON.stringify({ files }),
        });
        const j = await r.json();
        if (!r.ok || !j.ok) throw new Error(j.error || `saveTexFiles HTTP ${r.status}`);
        return j;
    },
    async compile(doc, { texOverride = null, activePath = null } = {}) {
        // Multi-file: invia tutti i buffer dirty al backend per compile bundle.
        // Il backend ricostruisce il pacchetto da formState + override client.
        const csrf = await fetchCsrf();
        // Recupera tutti i buffer dal modal State (passati come files override).
        const buffers = State.files.get(doc.id);
        const filesPayload = [];
        if (buffers) {
            for (const [path, buf] of buffers.entries()) {
                filesPayload.push({ path, content: buf.text });
            }
        }
        const r = await wafFetch(`/api/risdoc/templates/${doc.id}/compile-pdf`, {
            method: "POST", credentials: "same-origin",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
            body: JSON.stringify({
                form_state: doc.formState || {},
                files: filesPayload,
            }),
        });
        if (!r.ok) {
            const j = await r.json().catch(() => ({}));
            const err = new Error(j.error || `compile HTTP ${r.status}`);
            err.detail = { ...j, log: j.log_excerpt || "" };
            throw err;
        }
        const blob = await r.blob();
        const buf = new Uint8Array(await blob.arrayBuffer());
        return {
            pdfBytes: buf,
            synctex: null,
            log: "",
            warnings: [],
            errors: [],
            engine: "pdflatex",
            duration_ms: parseInt(r.headers.get("X-Compile-Duration-Ms") || "0", 10),
        };
    },
    async fetchTex() { throw new Error("risdocAdapter: fetchTex non supportato (multi-file mode)"); },
    async saveTex()  { throw new Error("risdocAdapter: saveTex non supportato (usa saveTexFiles)"); },
    supportsSyncTex: false,
    supportsMultiFile: true,
    backendUrlFor: () => null,
};

// G22.S13 — Adapter per editor 3 file texCommon condivisi (tab "Modelli risdoc"
// in /area-docente/templates). NO compile (i 3 file da soli non producono PDF
// significativo: sono fragment di preambolo/header). Solo edit + save.
const risdocTemplatesAdapter = {
    async fetchTexFiles(doc) {
        const instParam = doc.institute ? `?institute=${encodeURIComponent(doc.institute)}` : '';
        const r = await wafFetch(`/api/teacher/risdoc/templates/files${instParam}`, { credentials: "same-origin" });
        const j = await r.json();
        if (!r.ok || !j.ok) throw new Error(j.error || `fetchTexFiles HTTP ${r.status}`);
        return Array.isArray(j.files) ? j.files : [];
    },
    async saveTexFiles(doc, files) {
        const csrf = await fetchCsrf();
        const r = await wafFetch(`/api/teacher/risdoc/templates/files/save`, {
            method: "POST", credentials: "same-origin",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
            body: JSON.stringify({ files }),
        });
        const j = await r.json();
        if (!r.ok || !j.ok) throw new Error(j.error || `saveTexFiles HTTP ${r.status}`);
        return j;
    },
    async compile(doc, { texOverride = null, activePath = null } = {}) {
        // G22.S15.bis Fase 5 — usa l'endpoint preview-pdf dedicato (wrappa i
        // frammenti .sty/header in documento sintetico via buildPreviewTex).
        const targetPath = activePath || doc.path;
        if (!targetPath) {
            const err = new Error("compile: nessun file selezionato");
            err.detail = {};
            throw err;
        }
        const csrf = await fetchCsrf();
        const r = await wafFetch(`/api/teacher/risdoc/templates/files/preview-pdf`, {
            method: "POST", credentials: "same-origin",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
            body: JSON.stringify({
                path: targetPath,
                content: texOverride,
                _csrf: csrf,
            }),
        });
        if (!r.ok) {
            const j = await r.json().catch(() => ({}));
            const err = new Error(j.error || `compile HTTP ${r.status}`);
            err.detail = { ...j, log: j.log_excerpt || "" };
            throw err;
        }
        const blob = await r.blob();
        const buf = new Uint8Array(await blob.arrayBuffer());
        return {
            pdfBytes: buf,
            synctex: null,
            log: "",
            warnings: [],
            errors: [],
            engine: "pdflatex",
            duration_ms: parseInt(r.headers.get("X-Compile-Duration-Ms") || "0", 10),
        };
    },
    async fetchTex() { throw new Error("fetchTex non supportato (multi-file mode)"); },
    async saveTex() { throw new Error("saveTex non supportato (usa saveTexFiles)"); },
    supportsSyncTex: false,
    supportsMultiFile: true,
    backendUrlFor: () => null,
};

// ADR-024 — Adapter documento CUSTOM (teacher_content body_pt). Multi-file +
// compile come risdoc, ma endpoint /api/teacher/content/{id}/*. Il corpo
// (documento.tex) è generato da body_pt server-side (read-only nel modal);
// gli edit a main.tex/risdoc.sty/intestazione sono effimeri (override compile).
const teacherContentAdapter = {
    async fetchTexFiles(doc) {
        const csrf = await fetchCsrf();
        const r = await wafFetch(`/api/teacher/content/${doc.id}/tex-files`, {
            method: "POST", credentials: "same-origin",
            headers: { "X-CSRF-Token": csrf },
            body: new URLSearchParams({ _csrf: csrf }).toString(),
        });
        const j = await r.json();
        if (!r.ok || !j.ok) throw new Error(j.error || `fetchTexFiles HTTP ${r.status}`);
        return Array.isArray(j.files) ? j.files : [];
    },
    async saveTexFiles(doc, files) {
        const csrf = await fetchCsrf();
        const r = await wafFetch(`/api/teacher/content/${doc.id}/tex-files/save`, {
            method: "POST", credentials: "same-origin",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
            body: JSON.stringify({ files }),
        });
        const j = await r.json();
        if (!r.ok || !j.ok) throw new Error(j.error || `saveTexFiles HTTP ${r.status}`);
        return j;
    },
    async compile(doc) {
        const csrf = await fetchCsrf();
        const buffers = State.files.get(doc.id);
        const filesPayload = [];
        if (buffers) {
            for (const [path, buf] of buffers.entries()) {
                filesPayload.push({ path, content: buf.text });
            }
        }
        const r = await wafFetch(`/api/teacher/content/${doc.id}/compile-pdf`, {
            method: "POST", credentials: "same-origin",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
            body: JSON.stringify({ files: filesPayload }),
        });
        if (!r.ok) {
            const j = await r.json().catch(() => ({}));
            const err = new Error(j.error || `compile HTTP ${r.status}`);
            err.detail = { ...j, log: j.log_excerpt || "" };
            throw err;
        }
        const blob = await r.blob();
        const buf = new Uint8Array(await blob.arrayBuffer());
        return {
            pdfBytes: buf, synctex: null, log: "", warnings: [], errors: [],
            engine: "pdflatex",
            duration_ms: parseInt(r.headers.get("X-Compile-Duration-Ms") || "0", 10),
        };
    },
    async fetchTex() { throw new Error("teacherContentAdapter: usa fetchTexFiles (multi-file)"); },
    async saveTex()  { throw new Error("teacherContentAdapter: usa saveTexFiles (multi-file)"); },
    supportsSyncTex: false,
    supportsMultiFile: true,
    backendUrlFor: () => null,
};

function getAdapter() {
    if (State.mode === "template-file")     return templateFileAdapter;
    if (State.mode === "risdoc-template")   return risdocAdapter;
    if (State.mode === "risdoc-templates")  return risdocTemplatesAdapter;
    if (State.mode === "teacher-content")   return teacherContentAdapter;
    return verificaAdapter;
}

async function fetchTex(doc) { return getAdapter().fetchTex(doc); }
async function compileDoc(doc, opts = {}) {
    return getAdapter().compile(doc, { engine: State.engine, ...opts });
}
async function saveTexOnly(doc, texContent) { return getAdapter().saveTex(doc, texContent); }

// G22.S10 — Raccoglie i buffer da salvare per il batch /tex-files.
// Strategia: salva sempre l'intera lista di file (non solo i dirty), così
// il backend ricostruisce manifest e tex_sha256 in modo deterministico.
// Riuso blob lato service evita IO churn per i file invariati.
function collectFilesForSave(docId) {
    const buffers = State.files.get(docId);
    if (!buffers) return [];
    const out = [];
    for (const [path, buf] of buffers.entries()) {
        out.push({ path, content: buf.text });
    }
    return out;
}

// ───────────────────────── Editor (CodeMirror 6) ──────────────────────

// Larghezza sidebar file ridimensionabile (drag handle + frecce tastiera),
// persistita in localStorage. Pilota la prima traccia della grid .fm-vp-body
// via la custom property --fm-vp-sidebar-w (vedi _area-docente.css).
const SIDEBAR_W_KEY = "fm.vp.sidebarWidth";
const SIDEBAR_W_MIN = 140;
const SIDEBAR_W_MAX = 520;
function clampSidebarW(px) {
    return Math.max(SIDEBAR_W_MIN, Math.min(SIDEBAR_W_MAX, Math.round(px)));
}
function applySidebarWidth(px) {
    const w = clampSidebarW(px);
    State.modal?.style.setProperty("--fm-vp-sidebar-w", w + "px");
    const handle = State.modal?.querySelector("[data-resizer]");
    if (handle) handle.setAttribute("aria-valuenow", String(w));
    try { localStorage.setItem(SIDEBAR_W_KEY, String(w)); } catch (_) {}
    return w;
}
function setupSidebarResizer() {
    const handle = State.modal?.querySelector("[data-resizer]");
    const aside = State.modal?.querySelector("[data-filetree]");
    if (!handle || !aside) return;
    handle.setAttribute("aria-valuemin", String(SIDEBAR_W_MIN));
    handle.setAttribute("aria-valuemax", String(SIDEBAR_W_MAX));
    // Larghezza iniziale: localStorage o default 200.
    let saved = 200;
    try { const v = parseInt(localStorage.getItem(SIDEBAR_W_KEY) || "", 10); if (Number.isFinite(v)) saved = v; } catch (_) {}
    applySidebarWidth(saved);

    let dragging = false;
    const onMove = (e) => {
        if (!dragging) return;
        const left = aside.getBoundingClientRect().left;
        applySidebarWidth((e.clientX ?? 0) - left);
        e.preventDefault();
    };
    const onUp = () => {
        if (!dragging) return;
        dragging = false;
        document.body.style.userSelect = "";
        document.body.style.cursor = "";
        window.removeEventListener("pointermove", onMove);
        window.removeEventListener("pointerup", onUp);
    };
    handle.addEventListener("pointerdown", (e) => {
        dragging = true;
        document.body.style.userSelect = "none";
        document.body.style.cursor = "col-resize";
        window.addEventListener("pointermove", onMove);
        window.addEventListener("pointerup", onUp);
        e.preventDefault();
    });
    // Tastiera (WCAG 2.2): ← → regolano la larghezza di 16px.
    handle.addEventListener("keydown", (e) => {
        const cur = aside.getBoundingClientRect().width;
        if (e.key === "ArrowLeft")  { applySidebarWidth(cur - 16); e.preventDefault(); }
        else if (e.key === "ArrowRight") { applySidebarWidth(cur + 16); e.preventDefault(); }
    });
    // Doppio click → reset al default.
    handle.addEventListener("dblclick", () => applySidebarWidth(200));
}

function setupEditor() {
    const host = State.modal.querySelector("[data-cm-host]");
    if (!host) return;
    const onUpdate = EditorView.updateListener.of((u) => {
        if (u.docChanged) {
            // G22.S15.bis Fase 5 — skip dirty propagation se docChanged è
            // stato emesso da un setEditorContent programmatico (load file).
            if (State._suppressDirty) return;
            State.cmDirty = true;
            updateStatusDirty();
            // G22.S10 — propaga dirty al buffer corrente + ridisegna pallino tree.
            const doc = State.docs[State.activeIdx];
            if (doc) {
                const buffers = State.files.get(doc.id);
                const activePath = State.activePath.get(doc.id);
                if (buffers && activePath) {
                    const buf = buffers.get(activePath);
                    // G22.S15.bis Fase 5 — buffer binari: il placeholder non
                    // è modifica utente, ignora docChanged emessi da setEditorContent.
                    if (buf && !buf.binary) {
                        const cur = u.state.doc.toString();
                        buf.text = cur;
                        const wasDirty = buf.dirty;
                        buf.dirty = (cur !== buf.originalText);
                        if (wasDirty !== buf.dirty) renderFileTree(doc.id);
                    }
                }
            }
            if (State.autoRebuild && State.debouncedRebuild) {
                State.debouncedRebuild();
            }
        }
    });
    State.cm = new EditorView({
        state: EditorState.create({
            doc: "",
            extensions: [
                lineNumbers(),
                highlightActiveLine(),
                drawSelection(),
                history(),
                keymap.of([
                    // G27.bugfix — Ctrl+S (Mod-s) intercept: il browser
                    // aprirebbe "Save Page" come default. Qui invece chiama
                    // rebuildActive(true) → salva i buffer dirty + ricompila
                    // → PDF aggiornato con le modifiche correnti dell'editor.
                    // preventDefault implicito (run ritorna true).
                    {
                        key: "Mod-s",
                        run: () => { rebuildActive(true).catch(() => {}); return true; },
                        preventDefault: true,
                    },
                    ...defaultKeymap,
                    ...historyKeymap,
                    indentWithTab,
                ]),
                onUpdate,
                oneDark,
                EditorView.lineWrapping,
                flashField,  // G21.2 — line decoration per SyncTeX flash
            ],
        }),
        parent: host,
    });
}

function setEditorContent(text) {
    if (!State.cm) return;
    // G22.S15.bis Fase 5 — flag suppress dirty: dispatch programmatico
    // (load di un nuovo file) NON deve marcare il buffer dirty. CodeMirror
    // normalizza i line ending (CRLF→LF) e altre piccolezze, quindi cur !==
    // originalText anche senza edit utente → pallino dirty fantasma.
    State._suppressDirty = true;
    State.cm.dispatch({
        changes: { from: 0, to: State.cm.state.doc.length, insert: text || "" },
    });
    State._suppressDirty = false;
    State.cmDirty = false;
    updateStatusDirty();
}

function getEditorContent() {
    return State.cm ? State.cm.state.doc.toString() : "";
}

function moveCursorToLine(line) {
    if (!State.cm) return;
    const doc = State.cm.state.doc;
    const safe = Math.max(1, Math.min(line, doc.lines));
    const pos = doc.line(safe).from;

    // G21.2 v3 — usa Decoration nativa CodeMirror invece di DOM manipulation:
    // l'effect imposta una line-decoration .fm-vp-flash su quella riga, gestita
    // dal rendering interno di CM6 (sopravvive a re-render activeLine).
    State.cm.dispatch({
        selection: { anchor: pos, head: pos },
        scrollIntoView: true,
        effects: [flashEffect.of(pos)],
    });
    State.cm.focus();
    State._flashSeq = (State._flashSeq || 0) + 1;
    const myToken = State._flashSeq;
    console.debug("[FLASH] dispatch decoration", { line: safe, pos, token: myToken });

    // Auto-clear dopo 1 secondo (sostituisce decoration con none)
    setTimeout(() => {
        if (myToken === State._flashSeq) {
            State.cm?.dispatch({ effects: [flashEffect.of(null)] });
            console.debug("[FLASH] cleared", { token: myToken });
        }
    }, 1000);
    return;
    // G21.2 v3 — highlight 1s con DEBUG dettagliato + animationend listener
    State._flashSeq = (State._flashSeq || 0) + 1;
    const flashToken = String(State._flashSeq);
    const t0 = performance.now();
    console.debug("[FLASH] start", { token: flashToken, line: safe, t: 0 });

    requestAnimationFrame(() => requestAnimationFrame(() => {
        const dt_raf = (performance.now() - t0).toFixed(1);
        const lines = State.cm.dom.querySelectorAll(".cm-content .cm-line");
        let target = null;
        let strategy = "indexed";
        if (lines.length >= safe) {
            target = lines[safe - 1];
        } else {
            target = State.cm.dom.querySelector(".cm-content .cm-activeLine");
            strategy = "activeLine-fallback";
        }
        console.debug("[FLASH] rAF target", {
            token: flashToken,
            dt: `${dt_raf}ms`,
            domLines: lines.length,
            requested: safe,
            strategy,
            targetFound: !!target,
            targetText: target?.textContent?.slice(0, 50),
        });
        if (!target) {
            console.warn("[FLASH] NO TARGET — abort", { token: flashToken });
            return;
        }

        // Cleanup flash precedenti
        const prevFlashed = State.cm.dom.querySelectorAll(".cm-line[data-fm-flash]");
        prevFlashed.forEach(el => {
            el.style.animation = "";
            el.style.outline = "";
            el.style.outlineOffset = "";
            el.style.backgroundColor = "";
            el.removeAttribute("data-fm-flash");
        });
        if (prevFlashed.length) {
            console.debug("[FLASH] cleanup prev", { count: prevFlashed.length });
        }

        // Animation listeners per tracciare ciclo vita
        const onStart = () => console.debug("[FLASH] animationstart", {
            token: flashToken, t: (performance.now() - t0).toFixed(1) + "ms",
        });
        const onEnd = () => console.debug("[FLASH] animationend", {
            token: flashToken, t: (performance.now() - t0).toFixed(1) + "ms",
        });
        target.addEventListener("animationstart", onStart, { once: true });
        target.addEventListener("animationend", onEnd, { once: true });

        // Reset animation state per restart pulito
        target.style.animation = "none";
         
        void target.offsetHeight;

        // Apply nuovo flash
        target.setAttribute("data-fm-flash", flashToken);
        target.style.animation = "fm-vp-flash-1s 1s ease-out forwards";
        target.style.outline = "2px solid #ff6f00";
        target.style.outlineOffset = "1px";
        console.debug("[FLASH] applied", {
            token: flashToken,
            computedAnimation: getComputedStyle(target).animationName,
            inlineAnimation: target.style.animation,
        });

        // MutationObserver: traccia se CodeMirror sostituisce il DOM
        const obs = new MutationObserver((muts) => {
            for (const m of muts) {
                if (m.type === "attributes" && m.attributeName === "data-fm-flash") {
                    console.debug("[FLASH] data-fm-flash mutated", {
                        token: flashToken,
                        newValue: target.getAttribute("data-fm-flash"),
                        t: (performance.now() - t0).toFixed(1) + "ms",
                    });
                }
                if (m.type === "childList" && Array.from(m.removedNodes).includes(target)) {
                    console.warn("[FLASH] TARGET REMOVED FROM DOM!", {
                        token: flashToken,
                        t: (performance.now() - t0).toFixed(1) + "ms",
                    });
                }
            }
        });
        obs.observe(target, { attributes: true, attributeFilter: ["data-fm-flash", "class", "style"] });
        const parent = target.parentElement;
        if (parent) obs.observe(parent, { childList: true });

        setTimeout(() => {
            obs.disconnect();
            const currentToken = target.getAttribute("data-fm-flash");
            const stillInDom = document.contains(target);
            console.debug("[FLASH] timeout fired", {
                token: flashToken,
                currentToken,
                tokenMatches: currentToken === flashToken,
                stillInDom,
                t: (performance.now() - t0).toFixed(1) + "ms",
            });
            if (currentToken === flashToken) {
                target.style.animation = "";
                target.style.outline = "";
                target.style.outlineOffset = "";
                target.style.backgroundColor = "";
                target.removeAttribute("data-fm-flash");
            }
        }, 1200);
    }));
}

// ───────────────────────── PDF.js renderer ────────────────────────────

async function renderPdfInPane(hostSelector, pdfBytes, pageNum = 1) {
    const host = State.modal.querySelector(hostSelector);
    if (!host || !pdfBytes) return;
    const main = hostSelector === "[data-pdf-host]";
    const pdfjs = await loadPdfJs();

    // G27.bugfix3 — SERIALIZZAZIONE render sul pane principale. Zoom/page-change
    // possono ri-chiamare renderPdfInPane mentre un render è ancora in volo:
    // senza serializzazione due render concorrenti si distruggono il doc a
    // vicenda → "RenderingCancelledException: Rendering cancelled" e lo zoom
    // "non funziona". Token di generazione: solo l'ULTIMA chiamata completa;
    // le precedenti escono pulite. Il render-task precedente viene annullato.
    let myGen = 0;
    if (main) {
        myGen = (State.pdfRenderGen || 0) + 1;
        State.pdfRenderGen = myGen;
        if (State.pdfRenderTask) { try { State.pdfRenderTask.cancel(); } catch {} State.pdfRenderTask = null; }
        if (State.pdfDoc) { try { State.pdfDoc.destroy(); } catch {} State.pdfDoc = null; }
    }
    const superseded = () => main && State.pdfRenderGen !== myGen;

    // PDF.js trasferisce l'ArrayBuffer al Worker (zero-copy) → detach.
    // Cloniamo per consentire render multipli/ri-render dopo zoom/page change.
    const cloned = new Uint8Array(pdfBytes);
    const loadingTask = pdfjs.getDocument({ data: cloned });
    const pdfDoc = await loadingTask.promise;
    if (superseded()) { try { pdfDoc.destroy(); } catch {} return; }
    if (main) State.pdfDoc = pdfDoc;

    host.innerHTML = "";
    const total = pdfDoc.numPages;
    const safePage = Math.max(1, Math.min(pageNum, total));
    const page = await pdfDoc.getPage(safePage);
    if (superseded()) return;
    // G27.bugfix — preferisci State.pdfScale (sopravvive a re-render) sopra
    // host.dataset.scale (effimero); fallback "1.4" su parse fail.
    const dsScale = parseFloat(host.dataset.scale || "");
    let scale = (main && Number.isFinite(State.pdfScale))
        ? State.pdfScale
        : (Number.isFinite(dsScale) ? dsScale : 1.4);
    if (!Number.isFinite(scale) || scale <= 0) scale = 1.4;
    const viewport = page.getViewport({ scale });

    const canvas = document.createElement("canvas");
    canvas.width = viewport.width;
    canvas.height = viewport.height;
    canvas.dataset.page = String(safePage);
    canvas.dataset.scale = String(scale);
    canvas.className = "fm-vp-pdf-canvas";
    host.appendChild(canvas);

    const ctx = canvas.getContext("2d");
    const renderTask = page.render({ canvasContext: ctx, viewport });
    if (main) State.pdfRenderTask = renderTask;
    try {
        await renderTask.promise;
    } catch (e) {
        // Render superseduto da uno successivo (zoom/page rapido): non è un errore.
        if (e && e.name === "RenderingCancelledException") return;
        throw e;
    } finally {
        if (main && State.pdfRenderTask === renderTask) State.pdfRenderTask = null;
    }
    if (superseded()) return;

    const indicator = State.modal.querySelector("[data-page-info]");
    if (indicator) indicator.textContent = `${safePage} / ${total}`;
    if (main) State.pdfPage = safePage;

    if (main) {
        canvas.addEventListener("click", async (ev) => {
            await handlePdfClick(ev, canvas, viewport, safePage);
        });
    }
}

async function changePage(delta) {
    if (!State.pdfDoc) return;
    const newPage = State.pdfPage + delta;
    if (newPage < 1 || newPage > State.pdfDoc.numPages) return;
    const cached = State.cache.get(State.docs[State.activeIdx].id);
    await renderPdfInPane('[data-pdf-host]', cached.pdfBytes, newPage);
}

async function changeZoom(delta) {
    // G27.bugfix — zoom tracked in State (era solo dataset.scale che a volte
    // si perdeva tra re-render). Anche guard difensivi su PDF/cache mancanti.
    console.debug("[zoom] changeZoom called", { delta, current: State.pdfScale });
    const host = State.modal?.querySelector("[data-pdf-host]");
    if (!host) { console.warn("[zoom] host non trovato"); return; }
    const cur = Number.isFinite(State.pdfScale) ? State.pdfScale : 1.4;
    const next = Math.max(0.5, Math.min(3.0, cur + delta));
    if (next === cur) { console.debug("[zoom] clamp, no change"); return; }
    State.pdfScale = next;
    host.dataset.scale = String(next);
    const doc = State.docs[State.activeIdx];
    if (!doc) { console.warn("[zoom] no active doc"); return; }
    const cached = State.cache.get(doc.id);
    if (!cached?.pdfBytes) { console.warn("[zoom] no cached pdfBytes for", doc.id); return; }
    try {
        await renderPdfInPane('[data-pdf-host]', cached.pdfBytes, State.pdfPage || 1);
        console.debug("[zoom] OK new scale", next);
    } catch (e) {
        console.error("[zoom] render failed", e);
    }
}

// ───────────────────────── SyncTeX click ──────────────────────────────

async function handlePdfClick(ev, canvas, viewport, page) {
    if (!ev.ctrlKey && !ev.metaKey) return;
    const doc = State.docs[State.activeIdx];
    const cached = State.cache.get(doc.id);
    if (!cached?.synctex) {
        ensureToast("info", "SyncTeX", "Mappatura SyncTeX non disponibile per questa variante.");
        return;
    }

    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;
    const pxX = (ev.clientX - rect.left) * scaleX;
    const pxY = (ev.clientY - rect.top) * scaleY;

    // PDF.js convertToPdfPoint: spazio PDF (origine BASSO-SINISTRA).
    // synctex CLI vuole coordinate TeX (origine ALTO-SINISTRA): flip Y.
    const [pdfX, pdfY] = viewport.convertToPdfPoint(pxX, pxY);
    const pageHeightPt = viewport.viewBox ? viewport.viewBox[3] : (canvas.height / viewport.scale);
    const xPt = pdfX;
    const yPt = pageHeightPt - pdfY;

    console.debug("[SyncTeX] click", {
        canvas_px: { x: pxX, y: pxY },
        pdf_pt:    { x: pdfX, y: pdfY },
        tex_pt:    { x: xPt, y: yPt },
        page_h:    pageHeightPt,
        page,
    });

    // G21.2 — usa binario nativo `synctex edit` via backend (precisione
    // VSCode-grade). Sostituisce il parser JS custom impreciso.
    // G21.4 — supporta solo modalità "verifica" (template files no synctex).
    const adapter = getAdapter();
    if (!adapter.supportsSyncTex) {
        ensureToast("info", "SyncTeX", "Sync non disponibile in modalità template.");
        return;
    }
    let hit = null;
    try {
        const csrf = await fetchCsrf();
        const synctexB64 = bytesToB64(cached.synctex);
        const r = await wafFetch(adapter.backendUrlFor(doc), {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
            body: JSON.stringify({
                synctex_gz_b64: synctexB64,
                page, x: xPt, y: yPt,
            }),
        });
        const data = await r.json();
        console.debug("[SyncTeX] CLI response", data);
        if (data.ok && data.line) {
            hit = { file: data.file, line: data.line, column: data.column };
        }
    } catch (e) {
        console.warn("[SyncTeX] CLI call failed, fallback parser:", e.message);
    }

    // Fallback: parser JS custom se backend irraggiungibile
    if (!hit) {
        let parser = State.syncTex;
        if (!parser || State.syncTexDocId !== doc.id) {
            try {
                const pako = await loadPako();
                const inflated = pako.ungzip(cached.synctex, { to: "string" });
                parser = new SyncTexParser(inflated);
                State.syncTex = parser;
                State.syncTexDocId = doc.id;
            } catch (e) {
                ensureToast("error", "SyncTeX", `Fallback parser fallito: ${e.message}`);
                return;
            }
        }
        hit = parser.findTexAtPdfPoint(page, xPt, yPt);
        if (hit) hit.strategy = (hit.strategy || "?") + " (fallback)";
    }

    if (!hit) {
        ensureToast("info", "SyncTeX", "Nessuna corrispondenza TeX per questo punto.");
        return;
    }
    moveCursorToLine(hit.line);
    const fileName = (hit.file || "").split("/").pop() || hit.file;
    setStatus(`SyncTeX → riga ${hit.line}${hit.column > 0 ? `:${hit.column}` : ""} (${fileName})`);
}

// ───────────────────────── Status / warnings UI ───────────────────────

function setStatus(msg, kind = "info") {
    const el = State.modal?.querySelector("[data-status-info]");
    if (el) {
        el.textContent = msg;
        el.dataset.kind = kind;
    }
}

function updateStatusDirty() {
    const t = State.modal?.querySelector(".fm-vp-title");
    if (!t || !State.docs[State.activeIdx]) return;
    const doc = State.docs[State.activeIdx];
    const base = `Anteprima — ${doc.title || `verifica ${doc.id}`}`;
    t.textContent = State.cmDirty ? `${base} •` : base;
}

function updateLog(cached) {
    const pre = State.modal?.querySelector("[data-log]");
    if (pre) pre.textContent = cached.log || "(nessun log disponibile)";

    const wrn = State.modal?.querySelector("[data-status-warnings]");
    if (wrn) {
        const we = (cached.warnings?.length || 0);
        const ee = (cached.errors?.length || 0);
        wrn.innerHTML = "";
        if (ee > 0) {
            const sp = document.createElement("span");
            sp.className = "fm-vp-badge fm-vp-badge--error";
            sp.textContent = `❌ ${ee} errori`;
            wrn.appendChild(sp);
        }
        if (we > 0) {
            const sp = document.createElement("span");
            sp.className = "fm-vp-badge fm-vp-badge--warn";
            sp.textContent = `⚠ ${we} warning`;
            wrn.appendChild(sp);
        }
    }
}

// ───────────────────── G22.S15.bis Fase 4 — GeoGebra ─────────────────

/** Lazy-load del modal GeoGebra editor + inserisce `\fmgeogebra{base64}{label}`
 *  alla posizione corrente del cursor nel CodeMirror del file attivo. Il
 *  pre-process server-side (GeoGebraTexPreProcessor) lo trasformerà al
 *  compile in `\includegraphics{geogebra/N}` + salvataggio PDF nel bundle. */
async function insertGeogebraAtCursor() {
    if (!window.FM?.openGeoGebraEditor) {
        try {
            const cacheBust = `?t=${Date.now()}`;
            const res = await fetch(`/build/manifest.json${cacheBust}`, { credentials: "same-origin", cache: "no-store" });
            if (!res.ok) throw new Error(`manifest HTTP ${res.status} — npm run build`);
            const manifest = await res.json();
            const entry = manifest["js/entries/geogebra-editor.js"];
            if (!entry) throw new Error("entry geogebra-editor assente");
            await import(/* @vite-ignore */ `/build/${entry.file}`);
            if (!window.FM?.openGeoGebraEditor) throw new Error("bundle non popola FM.openGeoGebraEditor");
        } catch (e) {
            setStatus("Errore caricamento GeoGebra: " + e.message);
            return;
        }
    }
    window.FM.openGeoGebraEditor({
        initialGgbBase64: null,
        initialLabel: "",
        onAdd: async ({ svg, label, width }) => {
            if (!svg) { setStatus("Errore: SVG vuoto"); return false; }
            const cm = State.cm;
            if (!cm || typeof cm.dispatch !== "function") {
                setStatus("CodeMirror non disponibile");
                return false;
            }
            const doc = State.docs[State.activeIdx];
            if (!doc) { setStatus("Nessun documento attivo"); return false; }

            // ADR-026 — fuori da "verifica" (risdoc-template, teacher-content,
            // template-file, risdoc-templates) l'endpoint /api/verifica/{id}/
            // geogebra-attach NON esiste (id non è una verifica). Inseriamo il
            // marker `\fmgeogebra[opt]{base64-svg}{label}` direttamente nel
            // buffer attivo: il preprocessor server-side (GeoGebraTexPreProcessor,
            // ora attivo anche nei compile risdoc/teacher) lo converte in
            // \includegraphics + PDF nel bundle al Ricompila. Il base64 vive nel
            // buffer (incluso nel payload `files` del compile) → preview corretta.
            if (State.mode !== "verifica") {
                let svgB64m;
                try { svgB64m = btoa(unescape(encodeURIComponent(svg))); }
                catch (_) { setStatus("Errore encoding SVG"); return false; }
                const w = (width || "").trim();
                let optArg = "";
                if (w && w !== "100%" && w !== "\\linewidth") {
                    if (/^(\d+(?:\.\d+)?)%$/.test(w)) optArg = `[width=${parseFloat(w) / 100}\\linewidth]`;
                    else optArg = `[width=${w.replace(/[\[\]]/g, "")}]`;
                }
                const safeLabel = String(label || "").replace(/[{}]/g, "");
                const macro = `\\fmgeogebra${optArg}{${svgB64m}}{${safeLabel}}`;
                const sel = cm.state.selection.main;
                cm.dispatch({
                    changes: { from: sel.from, to: sel.to, insert: macro },
                    selection: { anchor: sel.from + macro.length },
                });
                cm.focus();
                syncActiveBuffer(doc.id);
                State.cmDirty = true;
                updateStatusDirty();
                renderFileTree(doc.id);
                setStatus("GeoGebra inserito (\\fmgeogebra) — premi ▶ Ricompila per vedere il grafico.");
                return true;
            }

            // G22.S15.bis Fase 4 — file-based pipeline (solo verifica):
            //   1. POST /api/verifica/{id}/geogebra-attach con SVG b64
            //   2. Backend → SVG→PDF→bundle update → ritorna {path: "geogebra/N"}
            //   3. Inserisce \includegraphics[width=...]{geogebra/N} nel CM6
            // Differenza vs marker \fmgeogebra: il base64 non sta nel TeX
            // (CodeMirror leggero), il PDF è già nel bundle (no preprocess).
            setStatus("Conversione SVG → PDF…");
            let svgB64;
            try { svgB64 = btoa(unescape(encodeURIComponent(svg))); }
            catch (_) { setStatus("Errore encoding SVG"); return false; }

            try {
                const csrfToken = await fetchCsrfTokenSafe();
                const r = await wafFetch(`/api/verifica/${doc.id}/geogebra-attach`, {
                    method: "POST",
                    credentials: "same-origin",
                    headers: {
                        "Content-Type": "application/json",
                        "X-CSRF-Token": csrfToken,
                        "Accept": "application/json",
                    },
                    body: JSON.stringify({ svg_b64: svgB64, label: label || "" }),
                });
                const json = await r.json().catch(() => null);
                if (!r.ok || !(json?.success === true || json?.ok === true)) {
                    const err = json?.error || `HTTP ${r.status}`;
                    setStatus(`Errore attach: ${err}`);
                    return false;
                }

                // Width → option dell'\includegraphics (percentuali → \linewidth)
                const w = (width || "").trim();
                let imgOpts = "width=\\linewidth,keepaspectratio";
                if (w && w !== "100%" && w !== "\\linewidth") {
                    if (/^(\d+(?:\.\d+)?)%$/.test(w)) {
                        const pct = parseFloat(w) / 100;
                        imgOpts = `width=${pct}\\linewidth,keepaspectratio`;
                    } else {
                        imgOpts = `width=${w.replace(/[\[\]]/g, "")},keepaspectratio`;
                    }
                }
                const path = String(json.path || "");
                const safeLabel = String(label || "").replace(/[{}\\]/g, "");
                // Inserisce \includegraphics + commento opzionale con label
                const macro = safeLabel
                    ? `\\includegraphics[${imgOpts}]{${path}}% ${safeLabel}`
                    : `\\includegraphics[${imgOpts}]{${path}}`;
                const sel = cm.state.selection.main;
                cm.dispatch({
                    changes: { from: sel.from, to: sel.to, insert: macro },
                    selection: { anchor: sel.from + macro.length },
                });
                cm.focus();
                setStatus(`Salvato ${path}.pdf nel bundle (${(json.pdf_size || 0)} byte) — ricompila per vedere`);
                // G27 — inserisci SUBITO il binario nei buffer dal risultato
                // dell'attach (path + pdf_size noti). Il re-fetch del manifest
                // qui sotto può essere STANTIO (l'update appena scritto non è
                // ancora visibile) → il filetree NON mostrava geogebra/N.pdf
                // anche se \includegraphics era già nel tex. Questo è
                // deterministico e non dipende dal timing del re-fetch.
                try {
                    // path reale nel manifest (es. versioni/geogebra/N.pdf) dal
                    // server; fallback a path+.pdf per compatibilità.
                    const binPath = String(json.manifest_path || `${path}.pdf`);
                    const buffers0 = State.files.get(doc.id) || new Map();
                    if (!buffers0.has(binPath)) {
                        buffers0.set(binPath, {
                            text: '', originalText: '', dirty: false, missing: false,
                            overrideStatus: 'common', binary: true,
                            size: typeof json.pdf_size === 'number' ? json.pdf_size : 0,
                        });
                        State.files.set(doc.id, buffers0);
                        renderFileTree(doc.id);
                    }
                } catch (_) {}
                // Ricarica buffers dal server (best-effort: sincronizza eventuali
                // altri file; il binario è già stato aggiunto sopra).
                try {
                    const adapter = getAdapter();
                    if (adapter?.supportsMultiFile && typeof adapter.fetchTexFiles === "function") {
                        const files = await adapter.fetchTexFiles(doc);
                        const buffers = State.files.get(doc.id) || new Map();
                        files.forEach(f => {
                            if (!buffers.has(f.path)) {
                                const normalized = (typeof f.content === 'string')
                                    ? f.content.replace(/\r\n/g, '\n')
                                    : f.content;
                                buffers.set(f.path, {
                                    text: normalized, originalText: normalized,
                                    dirty: false, missing: !!f.missing,
                                    overrideStatus: f.overrideStatus || 'common',
                                    binary: !!f.is_binary,
                                    size: typeof f.size === 'number' ? f.size : 0,
                                });
                            }
                        });
                        State.files.set(doc.id, buffers);
                        renderFileTree(doc.id);
                    }
                } catch (_) {}
                return true;
            } catch (e) {
                setStatus("Errore attach: " + e.message);
                return false;
            }
        },
        onCancel: () => {},
    });
}

/** Helper: ottiene CSRF token. Usa il `fetchCsrf` importato da dom-utils
 *  (GET /auth/csrf, cache 60s) = stesso path usato dalle altre scritture.
 *  BUGFIX G27: prima usava `window.FM.fetchCsrf` (INESISTENTE — il canonico è
 *  window.FM.DomUtils.fetchCsrf) → ripiegava sul meta tag stale/assente →
 *  token vuoto → `csrf_invalid` sulla geogebra-attach. */
async function fetchCsrfTokenSafe() {
    try {
        const t = await fetchCsrf();
        if (t) return t;
    } catch (_) {}
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta?.content || "";
}

// ───────────────────────── Rebuild ───────────────────────────────────

async function rebuildActive(saveTex = false) {
    const doc = State.docs[State.activeIdx];
    if (!doc) return;
    // G27 — guard anti doppio-compile: con Auto attivo, un singolo edit poteva
    // innescare DUE compile (input→debouncedRebuild + cambio-file→rebuildActive).
    // Se un compile è già in volo per lo stesso stato, salta il secondo (la
    // versione più recente è già in compilazione; debounce/Ricompila coprono il
    // resto). Evita lavoro doppio sul microservizio tex.
    if (State.compileInFlight) return;
    State.compileInFlight = true;
    setStatus("Compilazione in corso...");
    try {
        // G22.S10 — multi-file: se ci sono buffer dirty e l'utente preme
        // "Salva+Compila" (Ctrl+S), salva PRIMA tutti i buffer modificati
        // tramite POST /tex-files batch, poi ricompila usando la manifest
        // server-side (no texOverride: il server riassembla flat dalla
        // manifest aggiornata). Per "Compila" puro (saveTex=false) la
        // ricompilazione resta su texOverride (flat dell'editor).
        syncActiveBuffer(doc.id);
        const adapter = getAdapter();
        let tex = getEditorContent();
        const activePath = State.activePath.get(doc.id) || null;
        if (saveTex && adapter.supportsMultiFile) {
            const filesToSave = collectFilesForSave(doc.id);
            if (filesToSave.length) {
                // G22.S10c — in template-file mode salva SOLO il file attivo
                // (ogni file è indipendente, no implicit batch). In verifica
                // mode salva tutti (manifest atomic via singolo POST batch).
                const filtered = State.mode === 'template-file'
                    ? filesToSave.filter(f => f.path === activePath)
                    : filesToSave;
                if (filtered.length) {
                    const saveResult = await adapter.saveTexFiles(doc, filtered);
                    const buffers = State.files.get(doc.id);
                    if (buffers) {
                        for (const f of filtered) {
                            const buf = buffers.get(f.path);
                            if (buf) {
                                buf.originalText = f.content;
                                buf.dirty = false;
                                // Salvataggio in template-file mode trasforma
                                // un file da common/institute → user override.
                                if (State.mode === 'template-file') buf.overrideStatus = 'user';
                            }
                        }
                        renderFileTree(doc.id);
                    }
                    // G27.batch-sync — toast quando il server propaga gli edit
                    // alle sibling-row del batch (stesso path = stesso content).
                    const synced = Number(saveResult?.synced_siblings || 0);
                    if (synced > 0 && window.FM?.SyncPanel?.notify) {
                        window.FM.SyncPanel.notify(
                            `Modifiche propagate a ${synced} altra varianti del batch`,
                            { type: 'info', timeout: 4000 }
                        );
                    }
                }
            }
        }
        // G22.S15.bis Fase 4 — fix Ricompila in multi-file mode:
        //   - Multi-file (verifica): MAI passare texOverride. Il server
        //     compila dalla manifest on-disk via /compile-bundle (path
        //     `../texCommon/X` risolti correttamente). Edit non salvati
        //     non si vedono nel PDF — l'utente deve "Salva+Compila".
        //   - Single-file legacy o template-file mode: usa texOverride
        //     (il bundle non è disponibile o non rilevante).
        const isMultiFileBundle = adapter.supportsMultiFile && State.mode !== 'template-file';
        const result = await compileDoc(doc, {
            engine: State.engine,
            texOverride: isMultiFileBundle ? null : tex,
            saveTex: saveTex && !adapter.supportsMultiFile,
            activePath,
        });
        // Aggiorna tex cached con il main.tex corrente (per back-compat con flat).
        result.tex = tex;
        State.cache.set(doc.id, result);
        if (State.syncTexDocId === doc.id) {
            State.syncTex = null;
            State.syncTexDocId = null;
        }
        if (saveTex) State.cmDirty = false;

        // G22.S16 — applica formatted_files ai buffer (latexindent server-side).
        // Sovrascrive il content dei file modificati da latexindent, mantenendo
        // il dirty flag del buffer attivo (l'utente ha appena salvato, ma
        // potrebbe voler iterare). Se il buffer attivo è stato riformattato,
        // aggiorna anche l'editor visibile.
        let formattedCount = 0;
        if (result.formattedFiles && Object.keys(result.formattedFiles).length > 0) {
            const buffers = State.files.get(doc.id);
            if (buffers) {
                for (const [path, formattedText] of Object.entries(result.formattedFiles)) {
                    const buf = buffers.get(path);
                    if (!buf) continue;
                    if (buf.text !== formattedText) {
                        buf.text = formattedText;
                        // Reset dirty flag: la versione formattata è ora la
                        // baseline, originalText viene aggiornato post-save.
                        if (saveTex || State.mode === 'verifica') {
                            buf.originalText = formattedText;
                            buf.dirty = false;
                        }
                        formattedCount++;
                        // Se è il buffer attivo nell'editor → swap content.
                        if (path === activePath) {
                            setEditorContent(formattedText);
                        }
                    }
                }
                if (formattedCount > 0) renderFileTree(doc.id);
            }
        }

        updateStatusDirty();
        await renderPdfInPane('[data-pdf-host]', result.pdfBytes, State.pdfPage);
        updateLog(result);
        const fmtTag = formattedCount > 0 ? ` · ${formattedCount} file formattati (latexindent)` : '';
        setStatus(`✔ Build OK · ${result.engine} · ${result.duration_ms}ms · ${result.pdfBytes.length} bytes${saveTex ? " · TEX salvato" : ""}${fmtTag}`);
    } catch (e) {
        const detail = e.detail || {};
        const log = detail.log || detail.log_excerpt || e.message;
        State.cache.set(doc.id, {
            tex: getEditorContent(),
            pdfBytes: State.cache.get(doc.id)?.pdfBytes || null,
            synctex: State.cache.get(doc.id)?.synctex || null,
            log,
            warnings: detail.warnings || [],
            errors: detail.errors || [],
            engine: State.engine,
            duration_ms: detail.duration_ms || 0,
        });
        updateLog(State.cache.get(doc.id));
        setStatus(`✘ Errore: ${e.message}`, "error");
    } finally {
        State.compileInFlight = false;
    }
}

// ───────────────────────── Public API ─────────────────────────────────

export async function openPreview(docs, opts = {}) {
    if (!Array.isArray(docs) || docs.length === 0) {
        ensureToast("error", "Anteprima", "Nessuna verifica da mostrare.");
        return;
    }
    if (State.modal) closeModal();

    State.docs = docs;
    State.activeIdx = Math.min(opts.openIdx || 0, docs.length - 1);
    State.autoRebuild = !!opts.autoRebuild;
    State.engine = opts.engine || "pdflatex";
    State.cache = new Map();
    State.files = new Map();
    State.activePath = new Map();
    State.cmDirty = false;
    // G22.S15.bis Fase 4 — previewOnly: nasconde "Salva TEX", abilita solo
    // ricompila in-memory (no persistenza DB). Tipico per bottone "Anteprima"
    // che apre verifica per ispezione + ricompila locale senza commit.
    State.previewOnly = !!opts.previewOnly;
    // G21.4 — mode determina quale adapter usare ("verifica" | "template-file")
    State.mode = opts.mode || "verifica";

    State.modal = buildModalShell();
    document.body.appendChild(State.modal);

    // G22.S15.bis Fase 4 — previewOnly UI: nascondi "Salva TEX", mostra
    // badge informativo, modifica titolo modal.
    if (State.previewOnly) {
        const saveBtn = State.modal.querySelector(".fm-vp-save-tex");
        if (saveBtn) saveBtn.hidden = true;
        const badge = State.modal.querySelector(".fm-vp-preview-only-badge");
        if (badge) badge.hidden = false;
        const title = State.modal.querySelector(".fm-vp-title");
        if (title) title.textContent = "Anteprima verifica (preview, no save)";
        ensureToast("info", "Anteprima",
            "Modalità preview: modifiche locali NON salvate. Per salvare il TeX usa TEX/PDF.", 5000);
    }

    document.addEventListener("keydown", handleGlobalKey);

    State.modal.addEventListener("click", async (e) => {
        const a = e.target.closest("[data-act]");
        if (!a) return;
        const act = a.dataset.act;
        if (act === "close") return closeModal();
        // G27.bugfix — "Ricompila" ora salva+compila (era solo compile, che
        // in modalità multi-file usava la manifest on-disk e poi sovrascriveva
        // i buffer editor con formattedFiles → modifiche perse). Il caso
        // "compila vecchia versione senza salvare" non ha utilità pratica
        // e creava confusione. Per save-only resta il pulsante "Salva TEX".
        if (act === "rebuild") return rebuildActive(true);
        if (act === "copy-log") {
            e.preventDefault();
            e.stopPropagation();
            const pre = State.modal.querySelector(".fm-vp-log pre[data-log]");
            if (pre) {
                navigator.clipboard.writeText(pre.textContent || "").catch(() => {});
                a.textContent = "✓ Copiato";
                setTimeout(() => { a.textContent = "📋 Copia"; }, 1500);
            }
            return;
        }
        if (act === "clear-log") {
            e.preventDefault();
            e.stopPropagation();
            const pre = State.modal.querySelector(".fm-vp-log pre[data-log]");
            if (pre) pre.textContent = "";
            return;
        }
        if (act === "insert-geogebra") return insertGeogebraAtCursor();
        if (act === "save-tex" && State.previewOnly) {
            ensureToast("warning", "Anteprima",
                "Modalità preview: il TeX non viene salvato. Apri da TEX/PDF per salvare.", 4000);
            return;
        }
        if (act === "save-tex") {
            try {
                const doc = State.docs[State.activeIdx];
                const adapter = getAdapter();
                syncActiveBuffer(doc.id);
                if (adapter.supportsMultiFile && State.files.has(doc.id)) {
                    const activePath = State.activePath.get(doc.id);
                    const all = collectFilesForSave(doc.id);
                    // template-file: salva solo il file attivo. verifica: tutti.
                    const files = State.mode === 'template-file'
                        ? all.filter(f => f.path === activePath)
                        : all;
                    if (files.length) {
                        await adapter.saveTexFiles(doc, files);
                        const buffers = State.files.get(doc.id);
                        if (buffers) {
                            for (const f of files) {
                                const buf = buffers.get(f.path);
                                if (buf) {
                                    buf.originalText = f.content;
                                    buf.dirty = false;
                                    if (State.mode === 'template-file') buf.overrideStatus = 'user';
                                }
                            }
                            renderFileTree(doc.id);
                        }
                    }
                } else {
                    await saveTexOnly(doc, getEditorContent());
                }
                State.cmDirty = false;
                updateStatusDirty();
                ensureToast("success", "TEX", "Salvato (senza ricompilare).");
            } catch (err) {
                ensureToast("error", "TEX", `Errore: ${err.message}`);
            }
            return;
        }
        if (act === "export-pdf") {
            const cached = State.cache.get(State.docs[State.activeIdx].id);
            if (!cached?.pdfBytes) return;
            const blob = bytesToBlob(cached.pdfBytes, "application/pdf");
            const url = URL.createObjectURL(blob);
            const a2 = document.createElement("a");
            a2.href = url;
            a2.download = `verifica_${State.docs[State.activeIdx].id}.pdf`;
            a2.click();
            URL.revokeObjectURL(url);
            return;
        }
        if (act === "prev-page") return changePage(-1);
        if (act === "next-page") return changePage(1);
        if (act === "zoom-out") return changeZoom(-0.2);
        if (act === "zoom-in")  return changeZoom(0.2);
    });

    State.modal.addEventListener("change", async (e) => {
        const a = e.target.closest("[data-act]");
        if (!a) return;
        const act = a.dataset.act;
        if (act === "auto-rebuild") {
            State.autoRebuild = a.checked;
            // G27.bugfix — Auto = save+compile (era compile-only, che in
            // multi-file mode ricompilava la versione disco e sovrascriveva
            // l'editor con formattedFiles, perdendo le modifiche utente).
            if (State.autoRebuild && !State.debouncedRebuild) {
                State.debouncedRebuild = debounce(() => rebuildActive(true), 2000);
            }
            return;
        }
        if (act === "engine") {
            State.engine = a.value;
            await rebuildActive(false);
            return;
        }
    });

    State.modal.addEventListener("click", (e) => {
        if (e.target === State.modal) closeModal();
    });

    setupEditor();
    setupSidebarResizer();

    // G27.bugfix3 — zoom gestito SOLO dal click delegato su State.modal
    // (act "zoom-in"/"zoom-out" → changeZoom). Rimosso il workaround dei
    // listener diretti duplicati: il "delegato a volte non triggera" era in
    // realtà il render PDF cancellato (RenderingCancelledException), ora risolto
    // dalla serializzazione in renderPdfInPane. Un solo handler = centralizzato.

    // G27.bugfix — debounced rebuild = save+compile (vedi commento sopra).
    State.debouncedRebuild = debounce(() => rebuildActive(true), 2000);

    renderTabs();
    await loadAndRenderActive();

    // G22.S15.bis Fase 4 — preload buffers di TUTTE le varianti in background.
    // Senza questo step, il filetree aggregato mostrerebbe solo i file della
    // variante attiva (SOL) → user vede solo main_SOL/esercizi_SOL invece di
    // tutte le varianti (NOR/DSA/DIS).
    if (State.docs.length > 1) {
        Promise.all(State.docs.map(async (doc, idx) => {
            if (idx === State.activeIdx) return;  // attivo già caricato
            if (State.files.has(doc.id)) return;   // già in cache
            try {
                const adapter = getAdapter();
                if (!adapter?.supportsMultiFile || typeof adapter.fetchTexFiles !== "function") return;
                const files = await adapter.fetchTexFiles(doc);
                const buffers = new Map();
                files.forEach(f => {
                    const raw = f.content || "";
                    const normalized = (typeof raw === 'string')
                        ? raw.replace(/\r\n/g, '\n')
                        : raw;
                    buffers.set(f.path, {
                        text: normalized,
                        originalText: normalized,
                        dirty: false,
                        missing: !!f.missing,
                        overrideStatus: f.overrideStatus || 'common',
                        binary: !!f.is_binary,
                        size: typeof f.size === 'number' ? f.size : 0,
                    });
                });
                State.files.set(doc.id, buffers);
                if (!State.activePath.has(doc.id) && buffers.size) {
                    const initialPath = buffers.has("main.tex") ? "main.tex"
                        : (buffers.has(`versioni/main_${(doc.variant || '').match(/(SOL|NOR|DSA|DIS)$/)?.[1] || 'NOR'}.tex`)
                            ? `versioni/main_${(doc.variant || '').match(/(SOL|NOR|DSA|DIS)$/)?.[1] || 'NOR'}.tex`
                            : buffers.keys().next().value);
                    State.activePath.set(doc.id, initialPath);
                }
            } catch (_) { /* skip silently */ }
        })).then(() => {
            // Re-render filetree per mostrare le varianti aggiuntive
            const activeDocId = State.docs[State.activeIdx]?.id;
            if (activeDocId !== undefined) renderFileTree(activeDocId);
        });
    }
}

export function closePreview() {
    closeModal();
}

// Esponi su window (canale per consumer fuori bundle)
window.FM = window.FM || {};
window.FM.VerificaPreview = {
    openPreview,
    closeModal,
    getState: () => State,
};
