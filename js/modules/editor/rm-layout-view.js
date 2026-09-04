/**
 * G24.faseC — RM layout view: state-driven UI per RmLayoutModel.
 *
 * Pre-fix: buildRmLayoutSection + buildSingleTableCard nel monolite
 * (~370 LOC) mutavano `state` object inline e chiamavano rebuildRmTables
 * manualmente.
 *
 * Post-fix: questo modulo prende un `RmLayoutModel` (Fase A.1) come input
 * e renderizza i controlli. Tutti gli event handler chiamano `model.set*()`
 * che emette change → view re-render automatico. rebuildRmTables è
 * sottoscritto al model una sola volta in buildRmLayoutSection.
 *
 * Pattern factory: il modulo riceve dependencies via factory function
 * `createRmLayoutView(deps)` invece di hard-coded import del monolite —
 * mantiene il modulo standalone (no circular dep).
 */

import { RmLayoutModel } from "./rm-layout-model.js";
import { normalizeColType, COL_TYPES } from "../render/rm-table-view.js";

/** Helper: input numerico con marker `.fm-editor-rmlayout` opzionale per
 *  capture rmLayout.{name}. Internal a questo modulo. */
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

/**
 * Factory: ritorna le 2 view functions (section + card) bound alle deps.
 *
 * @param {object} deps
 * @param {Function} deps.createEditableField  — factory editor field
 * @param {Function} deps.rebuildRmTables      — render RM tables DOM
 * @param {Function} deps.extractCellContent   — extract cell raw content
 * @param {Function} [deps.updateCellPopupPreview]
 * @param {Function} [deps.hideCellPopupPreview]
 * @returns {{ buildRmLayoutSection, buildSingleTableCard }}
 */
export function createRmLayoutView(deps) {
    const {
        createEditableField,
        rebuildRmTables,
        extractCellContent,
        updateCellPopupPreview = () => {},
        hideCellPopupPreview = () => {},
    } = deps;

    /** Build sezione layout RM completa per un .fm-collection__item.
     *  @param {Element} firstTable — primo `.fm-rm-table` del item (legacy arg)
     *  @param {number}  totalCells — totale celle (legacy arg)
     *  @param {Element} item       — `.fm-collection__item` parent
     *  @returns {HTMLElement} wrap div con _fmRmLayoutModel annotato */
    function buildRmLayoutSection(firstTable, totalCells, item) {
        const tableEls = item?.querySelectorAll(".fm-rm-table") || (firstTable ? [firstTable] : []);
        const model = RmLayoutModel.fromDom(tableEls, extractCellContent, item);

        const triggerRebuild = () => rebuildRmTables(model.toJSON());
        model.on("change:orientation", triggerRebuild);
        model.on("change:tableCount", () => { renderTablesSection(); triggerRebuild(); });
        model.on("change:table", triggerRebuild);

        const wrap = document.createElement("div");
        wrap.className = "fm-rm-layout-section";
        wrap.style.cssText = "margin:10px 0;padding:10px;background:#eef4ff;border:1px solid #b0c8e8;border-radius:4px";

        const title = document.createElement("div");
        title.style.cssText = "font:600 13px/1.4 system-ui;color:#2a5ac7;margin-bottom:8px";
        title.textContent = "Layout tabelle RM";
        wrap.appendChild(title);

        // === Global controls (Tabelle + Orientamento) ===
        const globalRow = document.createElement("div");
        globalRow.style.cssText = "display:flex;gap:12px;align-items:flex-end;margin-bottom:10px;padding:6px;background:rgba(255,255,255,0.4);border-radius:3px;flex-wrap:wrap";

        const countWrap = document.createElement("label");
        countWrap.style.cssText = "display:flex;flex-direction:column;gap:2px;font:12px/1.3 system-ui";
        const countLbl = document.createElement("span");
        countLbl.style.cssText = "font-weight:600;color:#333";
        countLbl.textContent = "Numero tabelle";
        const countInp = document.createElement("input");
        countInp.type = "number"; countInp.min = "1"; countInp.max = "10";
        countInp.value = String(model.tables.length);
        countInp.className = "fm-editor-rmlayout";
        countInp.dataset.field = "table_count";
        countInp.style.cssText = "padding:4px 6px;border:1px solid #ccc;border-radius:3px;font:13px/1 system-ui;width:80px";
        countInp.addEventListener("change", () => model.setTableCount(countInp.value));
        countWrap.appendChild(countLbl);
        countWrap.appendChild(countInp);
        globalRow.appendChild(countWrap);

        const orientWrap = document.createElement("label");
        orientWrap.style.cssText = "display:flex;flex-direction:column;gap:2px;font:12px/1.3 system-ui;flex:1;min-width:180px";
        const orientLbl = document.createElement("span");
        orientLbl.style.cssText = "font-weight:600;color:#333";
        orientLbl.textContent = "Orientamento tabelle";
        const orientSel = document.createElement("select");
        orientSel.className = "fm-editor-rmlayout";
        orientSel.dataset.field = "orientation";
        orientSel.style.cssText = "padding:4px 6px;border:1px solid #ccc;border-radius:3px;font:13px/1 system-ui";
        [["horizontal", "Orizzontale (affiancate)"], ["vertical", "Verticale (stacked)"]].forEach(([v, lbl]) => {
            const opt = document.createElement("option");
            opt.value = v; opt.textContent = lbl;
            if (v === model.orientation) opt.selected = true;
            orientSel.appendChild(opt);
        });
        orientSel.addEventListener("change", () => model.setOrientation(orientSel.value));
        orientWrap.appendChild(orientLbl);
        orientWrap.appendChild(orientSel);
        globalRow.appendChild(orientWrap);

        wrap.appendChild(globalRow);

        // === Per-table sections ===
        const tablesContainer = document.createElement("div");
        tablesContainer.className = "fm-rm-tables-container";
        tablesContainer.style.cssText = "display:flex;flex-direction:column;gap:6px";
        wrap.appendChild(tablesContainer);

        function renderTablesSection() {
            tablesContainer.innerHTML = "";
            model.tables.forEach((_, idx) => {
                tablesContainer.appendChild(buildSingleTableCard(model, idx));
            });
        }
        renderTablesSection();

        wrap._fmRmLayoutModel = model;
        return wrap;
    }

    /** Card per singola tabella RM (rows/cols/typecell/cells/flags/width). */
    function buildSingleTableCard(model, idx) {
        const t = model._table(idx);

        const card = document.createElement("div");
        card.style.cssText = "padding:10px;background:rgba(255,255,255,0.6);border:1px solid #d0dcf0;border-radius:4px";

        const h = document.createElement("h4");
        h.style.cssText = "margin:0 0 8px;font:600 12px/1.3 system-ui;color:#2a5ac7";
        h.textContent = `Tabella ${idx + 1}`;
        card.appendChild(h);

        // === Dimensioni (Righe + Colonne) ===
        const dimGrid = document.createElement("div");
        dimGrid.style.cssText = "display:flex;gap:8px;margin-bottom:8px";
        dimGrid.appendChild(makeNumInput("Righe", "rows", t.rows, 1, 12, (v) => {
            model.setRows(idx, v);
            renderCellGrid();
        }, { rmLayoutField: `rows_${idx}`, tableIdx: idx }));
        dimGrid.appendChild(makeNumInput("Colonne", "cols", t.cols, 1, 6, (v) => {
            model.setCols(idx, v);
            renderColTypesRow();
            renderCellGrid();
        }, { rmLayoutField: `cols_${idx}`, tableIdx: idx }));
        card.appendChild(dimGrid);

        // === Tipo colonne ===
        const colTypesWrap = document.createElement("div");
        colTypesWrap.style.cssText = "margin-bottom:8px;padding:6px;background:rgba(255,255,255,0.4);border-radius:3px";
        const cttLbl = document.createElement("div");
        cttLbl.style.cssText = "font:600 11px/1.2 system-ui;color:#333;margin-bottom:4px";
        cttLbl.textContent = "Tipo per colonna (X=checkbox, V=radio, B=button, T=text, N=number)";
        colTypesWrap.appendChild(cttLbl);
        const colTypesRow = document.createElement("div");
        colTypesRow.style.cssText = "display:flex;gap:6px";
        colTypesWrap.appendChild(colTypesRow);

        const typecellHidden = document.createElement("input");
        typecellHidden.type = "hidden";
        typecellHidden.classList.add("fm-editor-rmlayout");
        typecellHidden.dataset.field = `typecell_${idx}`;
        typecellHidden.dataset.tableIdx = String(idx);
        typecellHidden.value = t.typecell || "|X|X|";
        colTypesWrap.appendChild(typecellHidden);

        const TYPECELL_HINTS = Object.entries(COL_TYPES).map(([v, info]) => ({
            v,
            label: `${v} ${info.html === "checkbox" ? "Checkbox" : info.html === "radio" ? "Radio" : info.html === "button" ? "Button" : info.html === "text" ? "Text" : info.html === "vf" ? "Vero/Falso" : "Number"}`,
            tex:   info.tex,
            desc:  info.desc,
        }));

        function renderColTypesRow() {
            colTypesRow.innerHTML = "";
            for (let c = 0; c < t.cols; c++) {
                const colWrap = document.createElement("div");
                colWrap.style.cssText = "flex:1;display:flex;flex-direction:column;gap:2px";
                const sel = document.createElement("select");
                sel.dataset.col = String(c);
                sel.style.cssText = "padding:3px 6px;border:1px solid #ccc;border-radius:3px;font:12px/1 system-ui;text-align:center";
                const currentType = normalizeColType(t.colTypes[c]);
                TYPECELL_HINTS.forEach(({ v, label, desc }) => {
                    const opt = document.createElement("option");
                    opt.value = v;
                    opt.textContent = `col ${c + 1}: ${label}`;
                    opt.title = desc;
                    if (v === currentType) opt.selected = true;
                    sel.appendChild(opt);
                });
                const hint = TYPECELL_HINTS.find(h => h.v === currentType) || TYPECELL_HINTS[0];
                sel.title = `LaTeX: ${hint.tex}`;
                const latexHint = document.createElement("span");
                latexHint.style.cssText = "font:10px/1 Consolas,monospace;color:#888;text-align:center";
                latexHint.textContent = hint.tex;
                sel.addEventListener("change", () => {
                    model.setColType(idx, c, sel.value);
                    typecellHidden.value = t.typecell;
                    const h = TYPECELL_HINTS.find(x => x.v === sel.value) || TYPECELL_HINTS[0];
                    sel.title = `LaTeX: ${h.tex}`;
                    latexHint.textContent = h.tex;
                });
                colWrap.appendChild(sel);
                colWrap.appendChild(latexHint);
                colTypesRow.appendChild(colWrap);
            }
        }
        renderColTypesRow();
        card.appendChild(colTypesWrap);

        // === Griglia editor celle ===
        const cellsSection = document.createElement("div");
        cellsSection.style.cssText = "margin-bottom:8px";

        const cellsHeader = document.createElement("div");
        cellsHeader.style.cssText = "display:flex;justify-content:space-between;align-items:center;margin-bottom:4px";
        const csLbl = document.createElement("div");
        csLbl.style.cssText = "font:600 11px/1.2 system-ui;color:#333";
        csLbl.textContent = "Contenuto celle (click per editare)";
        cellsHeader.appendChild(csLbl);

        const popupWrap = document.createElement("label");
        popupWrap.style.cssText = "font:11px/1.2 system-ui;color:#666;cursor:pointer;display:inline-flex;align-items:center;gap:4px";
        const popupCb = document.createElement("input");
        popupCb.type = "checkbox";
        popupCb.checked = localStorage.getItem("fmv.popupPreview") !== "0";
        popupCb.addEventListener("change", () => {
            localStorage.setItem("fmv.popupPreview", popupCb.checked ? "1" : "0");
            if (!popupCb.checked) hideCellPopupPreview();
        });
        popupWrap.appendChild(popupCb);
        popupWrap.appendChild(document.createTextNode("Popup preview al focus"));
        cellsHeader.appendChild(popupWrap);
        cellsSection.appendChild(cellsHeader);

        const cellsGrid = document.createElement("div");
        cellsSection.appendChild(cellsGrid);

        function renderCellGrid() {
            cellsGrid.innerHTML = "";
            cellsGrid.style.cssText = `display:grid;grid-template-columns:repeat(${t.cols},1fr);gap:4px`;
            for (let r = 0; r < t.rows; r++) {
                for (let c = 0; c < t.cols; c++) {
                    const letter = String.fromCharCode(97 + (r * t.cols + c));
                    const cellBox = document.createElement("div");
                    cellBox.style.cssText = "display:flex;flex-direction:column;gap:3px";
                    const ta = createEditableField({
                        field:     `rm-cell-r${r}c${c}`,
                        value:     t.cells[r]?.[c] || "",
                        placeholder: `cella ${letter} (r${r + 1}c${c + 1})`,
                        dataset:   { row: r, col: c },
                        style:     "padding:5px 6px;border:1px solid #ccc;border-radius:3px;font:12px/1.3 Consolas,monospace;min-height:38px;width:100%;box-sizing:border-box;overflow:auto;white-space:pre-wrap;word-wrap:break-word",
                        onInput:   (field) => {
                            model.setCell(idx, r, c, field.value);
                            updateCellPopupPreview(field);
                            rebuildRmTables(model.toJSON());
                        },
                        popupPreview: true,
                    });

                    cellBox.appendChild(ta);
                    cellsGrid.appendChild(cellBox);
                }
            }
        }
        renderCellGrid();
        card.appendChild(cellsSection);

        // === Flags row ===
        const flags = document.createElement("div");
        flags.style.cssText = "display:flex;gap:12px;flex-wrap:wrap;align-items:center;font:12px/1.3 system-ui";
        [["mixtr", "Mix righe"], ["mixcol", "Mix colonne"]].forEach(([field, lbl]) => {
            const label = document.createElement("label");
            label.style.cssText = "display:inline-flex;align-items:center;gap:4px;cursor:pointer";
            const cb = document.createElement("input");
            cb.type = "checkbox"; cb.checked = !!t[field];
            cb.classList.add("fm-editor-rmlayout");
            cb.dataset.field = `${field}_${idx}`;
            cb.dataset.tableIdx = String(idx);
            cb.addEventListener("change", () => model.setFlag(idx, field, cb.checked));
            const span = document.createElement("span"); span.textContent = lbl;
            label.appendChild(cb); label.appendChild(span);
            flags.appendChild(label);
        });

        // === Larghezza TABELLA ===
        const widthGroup = document.createElement("div");
        widthGroup.style.cssText = "display:inline-flex;align-items:center;gap:8px;padding:3px 8px;background:rgba(255,255,255,0.5);border-radius:3px";
        const wgLbl = document.createElement("span");
        wgLbl.style.cssText = "font-weight:600";
        wgLbl.textContent = "Larghezza tabella:";
        widthGroup.appendChild(wgLbl);

        const mpLabel = document.createElement("label");
        mpLabel.style.cssText = "display:inline-flex;align-items:center;gap:4px;cursor:pointer";
        const mpCb = document.createElement("input");
        mpCb.type = "checkbox"; mpCb.checked = !!t.mpagew;
        mpCb.classList.add("fm-editor-rmlayout");
        mpCb.dataset.field = `mpagew_${idx}`;
        mpCb.dataset.tableIdx = String(idx);
        mpCb.addEventListener("change", () => {
            model.setFlag(idx, "mpagew", mpCb.checked);
            if (mpCb.checked) swInp.value = "";
            swInp.disabled = mpCb.checked;
        });
        const mpSpan = document.createElement("span"); mpSpan.textContent = "piena";
        mpLabel.appendChild(mpCb); mpLabel.appendChild(mpSpan);
        widthGroup.appendChild(mpLabel);

        const swInp = document.createElement("input");
        swInp.type = "number"; swInp.min = "0"; swInp.step = "10";
        swInp.value = t.specificWidth || "";
        swInp.placeholder = "px specifica";
        swInp.disabled = !!t.mpagew;
        swInp.classList.add("fm-editor-rmlayout");
        swInp.dataset.field = `specificWidth_${idx}`;
        swInp.dataset.tableIdx = String(idx);
        swInp.style.cssText = "width:90px;padding:3px 5px;border:1px solid #ccc;border-radius:3px;font:12px/1 system-ui";
        swInp.addEventListener("change", () => {
            model.setSpecificWidth(idx, swInp.value);
            if (swInp.value) mpCb.checked = false;
        });
        widthGroup.appendChild(swInp);
        flags.appendChild(widthGroup);

        card.appendChild(flags);
        return card;
    }

    return { buildRmLayoutSection, buildSingleTableCard };
}
