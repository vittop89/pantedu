/**
 * G24.faseC-buildSection — Factory per `buildSection`, il "fat" section
 * builder usato da quesito/soluzione/giustifica/titolo/intro/cella.
 *
 * Pre-fix: 250 LOC inline nel monolite con 9 dep cross-cutting.
 * Post-fix: factory `createBuildSectionView(deps)` accetta tutte le dep
 * via DI, mantiene il modulo standalone.
 *
 * Layout della section:
 *   <wrap>
 *     <row class="fm-editor-row">
 *       <taWrap>
 *         <taTabRow>LABEL UPPER ... [TikZ btns]</taTabRow>
 *         <ta contentEditable .fm-editor-field>      (60% default)
 *       </taWrap>
 *       <pvWrap>
 *         <pvTabRow>Preview [🔍 zoom button]</pvTabRow>
 *         <pv .fm-editor-preview>                    (40% default)
 *       </pvWrap>
 *     </row>
 *   </wrap>
 *
 * TikZ/GeoGebra inline blocks: pulsanti dinamici (🔍/📋/📐) renderizzati
 * sopra il ta tramite `_renderTikzButtons()` (esposto come
 * `ta._renderTikzButtons` per re-render esterno dopo template filler save).
 *
 * Dipendenze (DI factory):
 *   - makeEditableField    (factory shim contenteditable)
 *   - collapseTikzBlocks, collapseGeoGebraBlocks (parse blocks → markers)
 *   - expandedValue        (estrazione blocks da textarea per re-collapse)
 *   - bindPreview, updatePreview (preview MathJax/TikZ render)
 *   - enhanceTextarea      (keyboard shortcuts + auto-bracket)
 *   - undoManager          (UndoManager singleton)
 *   - attachListKeyHandlers (list indent/outdent + B/I/U hotkeys)
 *   - getBlockDialogs      (async () → block dialogs API; lazy-loaded)
 *                            { openTemplateFiller, openTikzModalForBlock,
 *                              openGeoGebraEditorForBlock }
 */

export function createBuildSectionView(deps) {
    const {
        makeEditableField,
        collapseTikzBlocks, collapseGeoGebraBlocks,
        expandedValue,
        bindPreview, updatePreview,
        enhanceTextarea,
        undoManager,
        attachListKeyHandlers,
        getBlockDialogs,  // async () → { openTemplateFiller, openTikzModalForBlock, openGeoGebraEditorForBlock }
    } = deps;

    function buildSection(label, initialValue, fieldKey = null) {
        // G23.fix17 — `fieldKey` opzionale separa display label dal data-field.
        const wrap = document.createElement("div");
        wrap.style.cssText = "margin-bottom:8px";

        const row = document.createElement("div");
        row.className = "fm-editor-row";
        row.style.cssText = "display:flex;gap:6px;align-items:stretch";

        // Textarea (raw source)
        const taWrap = document.createElement("div");
        taWrap.style.cssText = "flex:1 1 60%;min-width:0;display:flex;flex-direction:column";
        const taTabRow = document.createElement("div");
        taTabRow.className = "fm-editor-tabrow";
        taTabRow.style.cssText = "display:flex;align-items:flex-end;gap:4px;flex-wrap:wrap";
        const taTab = document.createElement("div");
        taTab.className = "fm-editor-tab";
        taTab.textContent = label.toUpperCase();
        taTab.style.cssText = "display:inline-block;padding:2px 8px;background:#eee;border:1px solid #ccc;border-bottom:none;font:11px/1 system-ui;color:#555";
        taTabRow.appendChild(taTab);
        // Container per bottoni dinamici (uno per ogni blocco TikZ/GeoGebra).
        const tikzBtnsContainer = document.createElement("span");
        tikzBtnsContainer.style.cssText = "display:flex;gap:4px;margin-left:auto;flex-wrap:wrap";
        taTabRow.appendChild(tikzBtnsContainer);

        // contentEditable div con shim textarea-like
        const ta = makeEditableField();
        ta.className = "fm-editor-field";
        ta.dataset.field = fieldKey || label.toLowerCase();
        ta.style.cssText = "width:100%;font:13px/1.4 Consolas,monospace;padding:6px;border:1px solid #ccc;border-radius:0 3px 3px 3px;box-sizing:border-box;flex:1 1 auto;min-height:80px;overflow:auto;white-space:pre-wrap;word-wrap:break-word";

        // Step 1: estrai blocchi TikZ + GeoGebra → marker
        const tikzCol = collapseTikzBlocks(initialValue);
        const ggbCol  = collapseGeoGebraBlocks(tikzCol.collapsed);
        ta.value = ggbCol.collapsed;
        ta._tikzBlocks = tikzCol.blocks;
        ta._geogebraBlocks = ggbCol.blocks;

        // Step 2: rendering bottoni dinamici (esposto come `ta._renderTikzButtons`)
        function _renderTikzButtons() {
            tikzBtnsContainer.innerHTML = "";
            const tikzList = ta._tikzBlocks || [];
            const ggbList  = ta._geogebraBlocks || [];
            tikzList.forEach((blk, i) => {
                const tplMatch = blk?.tagOpen?.match(/data-template-id=["']([^"']+)["']/i);
                const isTemplate = !!tplMatch;
                const btn = document.createElement("button");
                btn.type = "button";
                const labelN = tikzList.length === 1 ? "" : ` #${i + 1}`;
                if (isTemplate) {
                    btn.textContent = `📋 Modifica schema${labelN}`;
                    btn.title = "Edita questo schema via form/tabella";
                    btn.style.cssText = "padding:2px 8px;background:#34a853;color:#fff;border:none;border-radius:3px 3px 0 0;font:11px/1 system-ui;cursor:pointer";
                    btn.addEventListener("click", async () => {
                        const d = await getBlockDialogs();
                        d.openTemplateFiller(ta, tplMatch[1], i);
                    });
                } else {
                    btn.textContent = `🔍 Modifica TikZ${labelN}`;
                    btn.title = "Apri editor avanzato (CodeMirror + folding + preview)";
                    btn.style.cssText = "padding:2px 8px;background:#2a5ac7;color:#fff;border:none;border-radius:3px 3px 0 0;font:11px/1 system-ui;cursor:pointer";
                    btn.addEventListener("click", async () => {
                        const d = await getBlockDialogs();
                        d.openTikzModalForBlock(ta, i);
                    });
                }
                tikzBtnsContainer.appendChild(btn);
            });
            ggbList.forEach((blk, i) => {
                const btn = document.createElement("button");
                btn.type = "button";
                const labelN = ggbList.length === 1 ? "" : ` #${i + 1}`;
                const lblTxt = blk?.label ? ` "${blk.label}"` : "";
                btn.textContent = `📐 Modifica GeoGebra${labelN}`;
                btn.title = `Riapri il grafico${lblTxt} nell'editor GeoGebra (round-trip stato .ggb)`;
                btn.style.cssText = "padding:2px 8px;background:#9c27b0;color:#fff;border:none;border-radius:3px 3px 0 0;font:11px/1 system-ui;cursor:pointer";
                btn.addEventListener("click", async () => {
                    const d = await getBlockDialogs();
                    d.openGeoGebraEditorForBlock(ta, i);
                });
                tikzBtnsContainer.appendChild(btn);
            });
        }
        _renderTikzButtons();
        ta._renderTikzButtons = _renderTikzButtons;

        // Listener: utente digita <script type="text/tikz"> direttamente
        ta.addEventListener("input", () => {
            if (!/<script\s+type=["']text\/tikz["']/i.test(ta.value)) return;
            const expanded = expandedValue(ta);
            const fresh = collapseTikzBlocks(expanded);
            ta._tikzBlocks = fresh.blocks;
            if (fresh.collapsed !== ta.value) {
                const before = ta.selectionStart;
                ta.value = fresh.collapsed;
                ta.setSelectionRange(before, before);
                _renderTikzButtons();
            }
        });

        // Sync markers ↔ blocks su input/Ctrl+Z
        ta.addEventListener("input", () => {
            const text = ta.value;
            const tikzList = ta._tikzBlocks || [];
            const ggbList  = ta._geogebraBlocks || [];
            if (!tikzList.length && !ggbList.length) return;

            const tikzNums = [];
            const ggbNums  = [];
            const reAll = /⟨🔍 TikZ #(\d+)⟩|⟨📐 GeoGebra #(\d+)⟩/g;
            let m;
            while ((m = reAll.exec(text)) !== null) {
                if (m[1] !== undefined) tikzNums.push(parseInt(m[1], 10));
                else if (m[2] !== undefined) ggbNums.push(parseInt(m[2], 10));
            }

            const newTikz = tikzNums.map(n => tikzList[n - 1]).filter(Boolean);
            const newGgb  = ggbNums.map(n => ggbList[n - 1]).filter(Boolean);

            const tikzChanged = newTikz.length !== tikzList.length;
            const ggbChanged  = newGgb.length  !== ggbList.length;
            const tikzReordered = !tikzChanged && tikzNums.some((n, i) => n !== i + 1);
            const ggbReordered  = !ggbChanged  && ggbNums.some((n, i)  => n !== i + 1);

            if (!tikzChanged && !ggbChanged && !tikzReordered && !ggbReordered) return;

            ta._tikzBlocks = newTikz;
            ta._geogebraBlocks = newGgb;

            if (tikzChanged || ggbChanged || tikzReordered || ggbReordered) {
                let tikzCounter = 0, ggbCounter = 0;
                const newText = text
                    .replace(/⟨🔍 TikZ #\d+⟩/g, () => `⟨🔍 TikZ #${++tikzCounter}⟩`)
                    .replace(/⟨📐 GeoGebra #\d+⟩/g, () => `⟨📐 GeoGebra #${++ggbCounter}⟩`);
                if (newText !== text) {
                    const sel = ta.selectionStart;
                    ta.value = newText;
                    ta.setSelectionRange(Math.min(sel, newText.length), Math.min(sel, newText.length));
                }
            }
            _renderTikzButtons();
            ta._lastRenderedValue = undefined;
        });

        taWrap.appendChild(taTabRow);
        taWrap.appendChild(ta);

        // Preview (MathJax rendered)
        const pvWrap = document.createElement("div");
        pvWrap.style.cssText = "flex:1 1 40%;min-width:0;display:flex;flex-direction:column";
        const pvTabRow = document.createElement("div");
        pvTabRow.style.cssText = "display:flex;align-items:flex-end;gap:4px";
        const pvTab = document.createElement("div");
        pvTab.textContent = "Preview";
        pvTab.style.cssText = "display:inline-block;padding:2px 8px;background:#e0f0ff;border:1px solid #88b0d8;border-bottom:none;font:11px/1 system-ui;color:#2a5ac7";
        pvTabRow.appendChild(pvTab);
        // G22.S15.bis Fase 5 — Pulsante zoom A4 (3 modes cycle).
        const zoomBtn = document.createElement("button");
        zoomBtn.type = "button";
        zoomBtn.textContent = "🔍 Normale";
        zoomBtn.title = "Cicla larghezza preview: Normale → A4 → Largo. Simula la larghezza del foglio A4 stampato.";
        zoomBtn.style.cssText = "padding:2px 8px;background:#fff;border:1px solid #88b0d8;border-bottom:none;font:11px/1 system-ui;color:#2a5ac7;cursor:pointer;border-radius:3px 3px 0 0";
        pvTabRow.appendChild(zoomBtn);
        const ZOOM_MODES = [
            { key: "normal", label: "🔍 Normale", taFlex: "1 1 60%", pvFlex: "1 1 40%", pvMaxW: "" },
            { key: "a4",     label: "📄 A4",      taFlex: "1 1 30%", pvFlex: "0 0 720px", pvMaxW: "720px" },
            { key: "wide",   label: "📐 Largo",   taFlex: "1 1 20%", pvFlex: "1 1 80%", pvMaxW: "" },
        ];
        let zoomIdx = 0;
        zoomBtn.addEventListener("click", () => {
            zoomIdx = (zoomIdx + 1) % ZOOM_MODES.length;
            const m = ZOOM_MODES[zoomIdx];
            zoomBtn.textContent = m.label;
            taWrap.style.flex = m.taFlex;
            pvWrap.style.flex = m.pvFlex;
            pvWrap.style.maxWidth = m.pvMaxW;
            pvWrap.dataset.zoomMode = m.key;
            queueMicrotask(() => updatePreview(ta, pv));
        });
        const pv = document.createElement("div");
        pv.className = "fm-editor-preview";
        pv.style.cssText = "padding:8px;background:#fff;border:1px solid #88b0d8;border-radius:0 3px 3px 3px;min-height:60px;overflow:auto;font-size:13px;white-space:pre-wrap;word-wrap:break-word;flex:1 1 auto";
        pvWrap.appendChild(pvTabRow);
        pvWrap.appendChild(pv);

        row.appendChild(taWrap);
        row.appendChild(pvWrap);
        wrap.appendChild(row);

        // Wiring: preview update + rich editing + undo + list keys + first render
        bindPreview(ta, pv);
        enhanceTextarea(ta);
        undoManager.attach(ta);
        attachListKeyHandlers(ta);
        queueMicrotask(() => updatePreview(ta, pv));
        return wrap;
    }

    return { buildSection };
}
