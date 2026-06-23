/**
 * TableManager — estratto da functions-mod.js (Phase 9g, big module).
 * G26.phase6.2 — migrato a vanilla JS (no jQuery).
 *
 * Boundary: tutte le API pubbliche accettano Element O jQuery wrapper
 * (transition compat con caller legacy in ui-comp / event-handler).
 */
import { Endpoints } from "../core/endpoints.js";
import { asElement, asElementArray, outerHeight } from "../core/dom-utils.js";

/** POST form-urlencoded a Endpoints.update.table → testo. Sostituisce gli
 *  ajaxCompat POST del modulo (oggetti/array nei data → JSON.stringify). */
function _postTable(data) {
    const p = new URLSearchParams();
    for (const [k, v] of Object.entries(data || {})) {
        if (v != null) p.append(k, typeof v === "object" ? JSON.stringify(v) : String(v));
    }
    return fetch(Endpoints.update.table, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body: p.toString(),
    }).then(async (res) => {
        const t = await res.text();
        if (!res.ok) throw new Error(`HTTP ${res.status}: ${t.slice(0, 200)}`);
        return t;
    });
}

/** Replica jQuery .data() su Element: prima legge dataset, poi WeakMap fallback. */
const _dataStore = new WeakMap();
function elData(el, key, value) {
    if (!el) return undefined;
    if (arguments.length === 3) {
        let store = _dataStore.get(el);
        if (!store) {
            store = new Map();
            _dataStore.set(el, store);
        }
        store.set(key, value);
        // Reflect to dataset (kebab-case)
        try { el.dataset[key] = value; } catch (_) { /* non-string ok in WeakMap */ }
        return value;
    }
    // Read: priorità WeakMap (più recente), fallback dataset con coercion
    const store = _dataStore.get(el);
    if (store && store.has(key)) return store.get(key);
    const raw = el.dataset[key];
    if (raw === undefined) return undefined;
    // jQuery .data() coerces numerics + JSON; replica semplice
    if (raw === "true") return true;
    if (raw === "false") return false;
    if (raw === "null") return null;
    if (raw !== "" && !isNaN(raw)) return Number(raw);
    return raw;
}

/** Iterates over Element.dataset key/value pairs (replica jQuery $.each(table.data(), fn)). */
function eachDataAttr(el, fn) {
    if (!el || !el.dataset) return;
    for (const key in el.dataset) {
        fn(key, elData(el, key));
    }
}

/** Parse single HTML element from string. */
function htmlToElement(html) {
    const tmp = document.createElement("template");
    tmp.innerHTML = String(html).trim();
    return tmp.content.firstElementChild;
}

/** Parse multiple top-level elements from HTML (returns array). */
function htmlToElements(html) {
    const tmp = document.createElement("template");
    tmp.innerHTML = String(html).trim();
    return Array.from(tmp.content.children);
}


/** Index del nodo tra i siblings (replica jQuery .index() no-arg). */
function siblingIndex(el) {
    if (!el || !el.parentElement) return -1;
    return Array.from(el.parentElement.children).indexOf(el);
}

/** Index di un elemento in una lista di table siblings (replica .find("table").index(table)). */
function indexInCollection(collection, target) {
    const arr = asElementArray(collection);
    return arr.indexOf(target);
}

export const TableManager = {
    _state: {
        checkboxUpdates: [],
        batchingTimeout: null,
        // Mappa per handler delegation namespaced (replica jQuery .off(".grc"))
        _gestioneRigaColonnaHandlers: new WeakMap(),
    },

    /**
     * Processa il contenuto di una singola cella per l'output LaTeX.
     */
    _processCellContent: function (cell, checksol, typeCell) {
        const cellEl = asElement(cell);
        if (!cellEl) return "";

        // Clona la cella per processare l'HTML senza modificare l'originale
        const cellClone = cellEl.cloneNode(true);

        let htmlContent = cellClone.innerHTML;
        if (htmlContent) {
            htmlContent = htmlContent
                .replace(/<\/div>/g, "\\\\\n")
                .replace(/<br[^>]*>/g, "\\\\\n")
                .replace(/<div[^>]*>/g, "");
            cellClone.innerHTML = htmlContent;
        }

        let text = (cellClone.textContent || "").trim();
        text = text.replace(/\\\\\s*\\\\/g, "\\\\");
        text = text.replace(/\\\\\s*$/g, "");

        const checkboxRM = cellEl.querySelector(".fm-checkbox-rm");

        if (checkboxRM) {
            return this._processCheckboxCell(cellEl, checkboxRM, text, checksol);
        }
        return this._processRegularCell(text, typeCell);
    },

    _processCheckboxCell: function (cellEl, checkboxRM, text, checksol) {
        const checksolEl = asElement(checksol);
        const isSolutionMode = checksolEl ? checksolEl.checked === true : false;
        const isSolutionChecked = checkboxRM.classList.contains("solchecked");
        const isTikzContent = text.includes("\\begin{tikzpicture}");

        let checkboxType = "\\checkbox";
        if (isSolutionMode && isSolutionChecked) {
            checkboxType = "\\xcheckbox";
        }

        const wrapCheckCell = cellEl.querySelector(".fm-wrap-check-cell");
        let position = "left";
        if (wrapCheckCell) {
            const classes = wrapCheckCell.className || "";
            if (classes.includes("pos-top")) position = "top";
            else if (classes.includes("pos-bottom")) position = "bottom";
            else if (classes.includes("pos-right")) position = "right";
        }

        if (isTikzContent) {
            if (position === "top") return `${checkboxType}\\newline ${text}`;
            if (position === "bottom") return `${text}\\newline ${checkboxType}`;
            if (position === "right") return `${text}\\, ${checkboxType}`;
            return `${checkboxType}\\, ${text}`;
        }

        const wrapperStart = "\\adjustbox{padding=0.2cm}{%\n\\begin{minipage}{\\dimexpr\\linewidth-1cm}\n";
        const wrapperEnd = "\\end{minipage}%\n}";

        if (position === "top") return `${wrapperStart}\\centering ${checkboxType}\\\\ ${text}${wrapperEnd}`;
        if (position === "bottom") return `${wrapperStart}\\centering ${text}\\\\ ${checkboxType}${wrapperEnd}`;
        if (position === "right") return `${wrapperStart}\\raggedleft ${text}\\, ${checkboxType}${wrapperEnd}`;
        return `${wrapperStart}${checkboxType}\\, ${text}${wrapperEnd}`;
    },

    _processRegularCell: function (text, typeCell) {
        if (text.includes("\\begin{tikzpicture}")) return text;
        if (typeCell.includes("X")) {
            return `\\adjustbox{padding=0.2cm}{%\n\\begin{minipage}{\\dimexpr\\linewidth-1cm}\n${text}\\end{minipage}%\n}`;
        }
        if (typeCell.includes("C")) {
            return `\\adjustbox{padding=0.2cm}{%\n\\begin{minipage}{\\dimexpr\\linewidth-1cm}\n\\centering ${text}\\end{minipage}%\n}`;
        }
        return text;
    },

    _processTableRows: function (table, elements, checksol, typeCell) {
        const tableEl = asElement(table);
        if (!tableEl) return "";

        let latexCode = "";
        const rows = Array.from(tableEl.querySelectorAll("tr"));
        const elementsArr = asElementArray(elements);

        if (!elementsArr.some((e) => e.classList.contains("fm-block20"))) {
            utilities.shuffleArray(rows);
        }

        rows.forEach((row) => {
            const cellsInRow = Array.from(row.querySelectorAll("td, th"));
            const cellTexts = cellsInRow.map((c) => this._processCellContent(c, checksol, typeCell));
            latexCode += `${cellTexts.join(" & ")} \\\\\n\\hline\n`;
        });

        return latexCode;
    },

    _processTableColumn: function (table, elements, checksol, typeCell, NColumn) {
        const tableEl = asElement(table);
        if (!tableEl) return "";

        let latexCode = "";
        const rows = Array.from(tableEl.querySelectorAll("tr"));
        const elementsArr = asElementArray(elements);
        const columnIndices = Array.from({ length: NColumn }, (_, i) => i);

        if (!elementsArr.some((e) => e.classList.contains("fm-block20"))) {
            utilities.shuffleArray(columnIndices);
        }

        rows.forEach((row) => {
            const allCellsInRow = Array.from(row.querySelectorAll("td, th"));
            const reorderedCells = columnIndices.map((colIndex) => allCellsInRow[colIndex]).filter(Boolean);
            const cellTexts = reorderedCells.map((c) => this._processCellContent(c, checksol, typeCell));
            latexCode += `${cellTexts.join(" & ")} \\\\\n\\hline\n`;
        });

        return latexCode;
    },

    _processTableMixed: function (table, elements, checksol, typeCell) {
        const tableEl = asElement(table);
        if (!tableEl) return "";

        let latexCode = "";
        const rows = Array.from(tableEl.querySelectorAll("tr"));
        const elementsArr = asElementArray(elements);

        if (!elementsArr.some((e) => e.classList.contains("fm-block20"))) {
            utilities.shuffleArray(rows);
        }

        rows.forEach((row) => {
            const cellsInRow = Array.from(row.querySelectorAll("td, th"));
            if (!elementsArr.some((e) => e.classList.contains("fm-block20"))) {
                utilities.shuffleArray(cellsInRow);
            }
            const cellTexts = cellsInRow.map((c) => this._processCellContent(c, checksol, typeCell));
            latexCode += `${cellTexts.join(" & ")} \\\\\n\\hline\n`;
        });

        return latexCode;
    },

    _processTableNoMix: function (table, elements, checksol, typeCell) {
        const tableEl = asElement(table);
        if (!tableEl) return "";

        let latexCode = "";
        const rows = Array.from(tableEl.querySelectorAll("tr"));

        rows.forEach((row) => {
            const cellsInRow = Array.from(row.querySelectorAll("td, th"));
            const cellTexts = cellsInRow.map((c) => this._processCellContent(c, checksol, typeCell));
            latexCode += `${cellTexts.join(" & ")} \\\\\n\\hline\n`;
        });

        return latexCode;
    },

    _processTable: function (table, tableIndex, tableArray, elements, checksol) {
        const tableEl = asElement(table);
        if (!tableEl) return "";

        const minipagew = elData(tableEl, "mpagew");
        const mixtr = elData(tableEl, "mixtr");
        const typeCell = elData(tableEl, "typecell");
        const NColumn = (typeCell.match(/[a-zA-Z]/g) || []).length;

        let latexCode = "";

        if (tableArray.length > 1) {
            if (tableIndex === 0) latexCode += "\\\\\n";
            latexCode += `\n\n\\begin{minipage}{${minipagew}\\textwidth}`;
        }

        latexCode += `\n{\\renewcommand{\\arraystretch}{1.5}%\n\\begin{tabularx}{\\textwidth}{${typeCell}}\n\\hline\n`;

        const mixcol = elData(tableEl, "mixcol") || 0;

        if (mixtr === 1 && mixcol === 0) {
            latexCode += this._processTableRows(tableEl, elements, checksol, typeCell);
        } else if (mixtr === 0 && mixcol === 1) {
            latexCode += this._processTableColumn(tableEl, elements, checksol, typeCell, NColumn);
        } else if (mixtr === 1 && mixcol === 1) {
            latexCode += this._processTableMixed(tableEl, elements, checksol, typeCell);
        } else {
            latexCode += this._processTableNoMix(tableEl, elements, checksol, typeCell);
        }

        latexCode += "\\end{tabularx}\n}\n";

        if (tableArray.length > 1) {
            latexCode += "\\end{minipage}";
        }

        if (tableIndex !== tableArray.length - 1) {
            const tabelle = tableEl.closest(".fm-tabelle");
            const align = tabelle ? (tabelle.getAttribute("data-align") || "hfill") : "hfill";
            latexCode += align === "vfill" ? "\\\\\n" : "\\hfill";
        } else {
            latexCode += this._processGiustSol(tableEl, checksol);
        }

        return latexCode;
    },

    _processGiustSol: function (table, checksol) {
        const checksolEl = asElement(checksol);
        if (!checksolEl || !checksolEl.checked) return "";

        const giustsolElement = table.parentElement?.nextElementSibling;
        if (giustsolElement && giustsolElement.classList.contains("fm-giustsol")) {
            const giustsolClone = giustsolElement.cloneNode(true);

            let childHtml = giustsolClone.innerHTML;
            childHtml = childHtml
                .replace(/<div[^>]*>/g, "")
                .replace(/<\/div[^>]*>/g, "\n")
                .replace(/<\/p[^>]*>/g, "\n")
                .replace(/<br[^>]*>/g, "\n");
            giustsolClone.innerHTML = childHtml;

            const processedContent = utilitiesPrint.Print_itemize_olul(giustsolClone.tagName, giustsolClone, 0, 0, 0);
            return `\n${processedContent}\n`;
        }

        return "";
    },

    printTabelle: function (elements, checksol, _chekgiust) {
        const elementsArr = Array.from(elements);
        if (!elementsArr.some((e) => e.classList?.contains("block20"))) {
            utilities.shuffleArray(elementsArr);
        }

        let latexCodetab = "";

        elementsArr.forEach((element) => {
            const tables = element.querySelectorAll("table");
            tables.forEach((table, tableIndex, tableArray) => {
                latexCodetab += this._processTable(table, tableIndex, Array.from(tableArray), elementsArr, checksol);
            });
        });

        return latexCodetab;
    },

    /**
     * Event delegation namespaced sulla table (replica jQuery .on("click.grc", selector, fn)).
     * Replica .off(".grc") rimuovendo tutti gli handler registrati per questa table.
     */
    gestioneRigaColonna: function (table, numTab) {
        const tableEl = asElement(table);
        if (!tableEl) return;

        const parent = tableEl.closest(".fm-groupcollex");
        const path = PathManager.extractPath(parent);
        const self = this;

        // Rimuovi handler precedenti (replica .off(".grc"))
        const prevHandlers = this._state._gestioneRigaColonnaHandlers.get(tableEl);
        if (prevHandlers) {
            prevHandlers.forEach((h) => tableEl.removeEventListener("click", h));
        }
        const newHandlers = [];

        // Helper: trova table siblings nel fm-draggable-container
        const getTableIndexInContainer = () => {
            const container = tableEl.closest('[class^="fm-draggable-container"]');
            if (!container) return -1;
            return indexInCollection(container.querySelectorAll("table"), tableEl);
        };

        // delColX handler
        const onClickDelegated = (e) => {
            const target = e.target.closest?.(".delColX, .AddColL, .AddColR, .AddRowD, .AddRowU, .delRowX");
            if (!target || !tableEl.contains(target)) return;

            // delColX
            if (target.classList.contains("delColX")) {
                const totalCols = tableEl.querySelectorAll("tr:first-child td").length;
                if (totalCols <= 1) {
                    if (typeof ToastManager !== "undefined") {
                        ToastManager.show("warning", "Attenzione", "Non è possibile eliminare l'ultima colonna della tabella", 4000);
                    }
                    return;
                }
                const td = target.closest("td");
                const colIndex = td ? siblingIndex(td) : -1;
                const tableIndex = getTableIndexInContainer();

                tableEl.querySelectorAll("tr").forEach((tr) => {
                    const tds = tr.querySelectorAll("td");
                    if (tds[colIndex]) tds[colIndex].remove();
                });

                const problem = tableEl.closest(".fm-groupcollex");
                const typeCellContainer = problem ? problem.querySelector(`#numTabOption${numTab} .fm-typecell-container`) : null;
                if (typeCellContainer) {
                    const inputs = typeCellContainer.querySelectorAll(".fm-typecell-input");
                    if (inputs[colIndex]) inputs[colIndex].remove();

                    const newTypeCellArray = Array.from(typeCellContainer.querySelectorAll(".fm-typecell-input")).map((i) => i.value);
                    const newTypeCell = `|${newTypeCellArray.join("|")}|`;
                    tableEl.setAttribute("data-typecell", newTypeCell);

                    _postTable({
                        action: "deleteColumn",
                        tableIndex,
                        colIndex,
                        url: path,
                        typecell: newTypeCell,
                    })
                        .then((response) => {
                            self.updateIds();
                            console.log("Colonna eliminata con successo:", response);
                        })
                        .catch((error) => console.error("Errore durante l'eliminazione della colonna:", error));
                }
                return;
            }

            // AddColL / AddColR
            if (target.classList.contains("AddColL") || target.classList.contains("AddColR")) {
                const td = target.closest("td");
                const colIndex = td ? siblingIndex(td) : -1;
                const tableIndex = getTableIndexInContainer();
                const isLeft = target.classList.contains("AddColL");

                tableEl.querySelectorAll("tr").forEach((tr) => {
                    const tds = tr.querySelectorAll("td");
                    const referenceCell = tds[colIndex];
                    if (!referenceCell) return;
                    const newCell = referenceCell.cloneNode(true);
                    if (isLeft) referenceCell.before(newCell);
                    else referenceCell.after(newCell);
                });

                const problem = tableEl.closest(".fm-groupcollex");
                const typeCellContainer = problem ? problem.querySelector(`#numTabOption${numTab} .fm-typecell-container`) : null;
                if (typeCellContainer) {
                    const currentInputs = typeCellContainer.querySelectorAll(".fm-typecell-input");
                    const currentTypeCellArray = Array.from(currentInputs).map((i) => i.value);
                    const newValue = currentTypeCellArray[colIndex] || "X";

                    const newInput = htmlToElement(`<input type="text" class="fm-typecell-input" value="${newValue}"
                                       style="min-width: 80px; margin: 2px;"
                                       placeholder="es: X, c, >{\\arraybackslash}m{1cm}" />`);

                    const refInput = currentInputs[colIndex];
                    if (refInput && newInput) {
                        if (isLeft) refInput.before(newInput);
                        else refInput.after(newInput);
                    }

                    const newTypeCellArray = Array.from(typeCellContainer.querySelectorAll(".fm-typecell-input")).map((i) => i.value);
                    const newTypeCell = `|${newTypeCellArray.join("|")}|`;
                    tableEl.setAttribute("data-typecell", newTypeCell);

                    _postTable({
                        action: isLeft ? "addColumnLeft" : "addColumnRight",
                        tableIndex,
                        colIndex,
                        url: path,
                        typecell: newTypeCell,
                    })
                        .then((response) => {
                            self.updateIds();
                            console.log(`Colonna aggiunta ${isLeft ? "a sinistra" : "a destra"} con successo:`, response);
                        })
                        .catch((error) => console.error(`Errore durante l'aggiunta della colonna ${isLeft ? "a sinistra" : "a destra"}:`, error));
                }
                return;
            }

            // AddRowD / AddRowU
            if (target.classList.contains("AddRowD") || target.classList.contains("AddRowU")) {
                const tr = target.closest("tr");
                const rowIndex = tr ? siblingIndex(tr) : -1;
                const tableIndex = getTableIndexInContainer();
                const isAddBelow = target.classList.contains("AddRowD");

                const rows = tableEl.querySelectorAll("tr");
                const refRow = rows[rowIndex];
                if (!refRow) return;
                const newRow = refRow.cloneNode(true);
                if (isAddBelow) refRow.after(newRow);
                else refRow.before(newRow);

                _postTable({
                    action: isAddBelow ? "addRowBelow" : "addRowAbove",
                    tableIndex,
                    rowIndex,
                    url: path,
                })
                    .then((response) => {
                        self.updateIds();
                        console.log(`Riga aggiunta ${isAddBelow ? "sotto" : "sopra"} con successo:`, response);
                    })
                    .catch((error) => console.error(`Errore durante l'aggiunta della riga ${isAddBelow ? "sotto" : "sopra"}:`, error));
                return;
            }

            // delRowX
            if (target.classList.contains("delRowX")) {
                const totalRows = tableEl.querySelectorAll("tr").length;
                if (totalRows <= 2) {
                    if (typeof ToastManager !== "undefined") {
                        ToastManager.show("warning", "Attenzione", "Non è possibile eliminare l'ultima riga della tabella", 4000);
                    }
                    return;
                }
                const tr = target.closest("tr");
                const rowIndex = tr ? siblingIndex(tr) : -1;
                const tableIndex = getTableIndexInContainer();

                _postTable({
                    action: "deleteRow",
                    tableIndex,
                    rowIndex,
                    url: path,
                })
                    .then((response) => {
                        console.log("Riga eliminata con successo:", response);
                        const rows = tableEl.querySelectorAll("tr");
                        if (rows[rowIndex]) rows[rowIndex].remove();
                    })
                    .catch((error) => console.error("Errore durante l'eliminazione della riga:", error));
                return;
            }
        };
        tableEl.addEventListener("click", onClickDelegated);
        newHandlers.push(onClickDelegated);

        this._state._gestioneRigaColonnaHandlers.set(tableEl, newHandlers);
    },

    adjustRowActionHeight: function (element) {
        const el = asElement(element);
        if (!el) return;
        const trAnc = el.closest(".tr");
        const lastTd = trAnc ? trAnc.querySelector("td:last-child") : null;
        const rowActionTd = el.querySelector("td.row-actions");
        if (lastTd && rowActionTd) {
            const height = outerHeight(lastTd, true);
            rowActionTd.style.height = `${height}px`;
        }
    },

    checkAndMarkCheckboxes: function (table) {
        const tableEl = asElement(table);
        if (!tableEl) return;

        const rowCheckboxCount = {};
        const colCheckboxCount = {};
        const totalRows = tableEl.querySelectorAll("tr").length;
        const firstRow = tableEl.querySelector("tr");
        const totalColumns = firstRow ? firstRow.querySelectorAll("td").length : 0;

        for (let colIndex = 0; colIndex < totalColumns; colIndex++) {
            colCheckboxCount[colIndex] = 0;
        }

        tableEl.querySelectorAll("td").forEach((cell) => {
            const rowEl = cell.closest("tr");
            const rowIndex = rowEl ? siblingIndex(rowEl) : -1;
            const colIndex = siblingIndex(cell);

            const checkboxRMs = cell.querySelectorAll(".fm-checkbox-rm");
            if (checkboxRMs.length > 0) {
                const insertCheckbox = cell.querySelector(".fm-insertcheckbox-cell");
                if (insertCheckbox) insertCheckbox.checked = true;
            }

            if (!rowCheckboxCount[rowIndex]) rowCheckboxCount[rowIndex] = 0;

            if (checkboxRMs.length > 0) {
                rowCheckboxCount[rowIndex]++;
                colCheckboxCount[colIndex]++;
            }
        });

        const allTrs = tableEl.querySelectorAll("tr");
        for (const rowIndex in rowCheckboxCount) {
            const allCellsHaveCheckboxRM = rowCheckboxCount[rowIndex] === totalColumns;
            const tr = allTrs[parseInt(rowIndex)];
            if (!tr) continue;
            const insertCheckboxRow = tr.querySelector(".fm-insertcheckbox-row");
            if (insertCheckboxRow) insertCheckboxRow.checked = allCellsHaveCheckboxRM;
        }

        const firstRowTds = firstRow ? firstRow.querySelectorAll("td") : [];
        for (const colIndex in colCheckboxCount) {
            const allCellsInColumnHaveCheckboxRM = colCheckboxCount[colIndex] === totalRows - 1;
            const td = firstRowTds[parseInt(colIndex)];
            if (!td) continue;
            const insertCheckboxCol = td.querySelector(".fm-insertcheckbox-col");
            if (insertCheckboxCol) insertCheckboxCol.checked = allCellsInColumnHaveCheckboxRM;
        }
    },

    updateCheckboxState: function (cell, isChecked) {
        const cellEl = asElement(cell);
        if (!cellEl) return;

        if (isChecked) {
            if (!cellEl.querySelector(".fm-checkbox-rm")) {
                let existingContent = cellEl.innerHTML;
                if (existingContent && existingContent.includes('<input type="checkbox" class="fm-insertcheckbox-cell">')) {
                    existingContent = existingContent.replace('<input type="checkbox" class="fm-insertcheckbox-cell">', '<input type="checkbox" class="fm-insertcheckbox-cell" checked>');
                }
                cellEl.innerHTML = `
        <div class="fm-wrap-check-cell" style="display: flex;">
            <div class="fm-checkbox-wrapper">
                <button class="fm-btn-check-pos fm-btn-check-top" title="Sposta checkbox sopra">↑</button>
                <button class="fm-btn-check-pos fm-btn-check-left" title="Sposta checkbox a sinistra">←</button>
                <input type="checkbox" class="checkbox fm-checkbox-rm" onclick="event.stopPropagation();">
                <button class="fm-btn-check-pos fm-btn-check-right" title="Sposta checkbox a destra">→</button>
                <button class="fm-btn-check-pos fm-btn-check-bottom" title="Sposta checkbox sotto">↓</button>
            </div>
            <div class="fm-cell-content">${existingContent}</div>
        </div>
    `;
                this._setupCheckboxPositionButtons(cellEl);

                cellEl.querySelectorAll(".editor").forEach((editor) => {
                    if (editor.id) EditorSystem.inizializzaEditor(editor.id);
                });
            }
        } else if (cellEl.querySelector(".fm-checkbox-rm")) {
            const cellContentEl = cellEl.querySelector(".fm-wrap-check-cell .fm-cell-content");
            let contentWithoutCheckbox = cellContentEl ? cellContentEl.innerHTML : "";

            const hasInsertCheckbox = contentWithoutCheckbox && contentWithoutCheckbox.includes("insertcheckbox-Cell");
            if (hasInsertCheckbox) {
                contentWithoutCheckbox = contentWithoutCheckbox.replace('<input type="checkbox" class="fm-insertcheckbox-cell" checked>', '<input type="checkbox" class="fm-insertcheckbox-cell">');
                contentWithoutCheckbox = contentWithoutCheckbox.replace('<input type="checkbox" class="fm-insertcheckbox-cell" checked="">', '<input type="checkbox" class="fm-insertcheckbox-cell">');
            }

            cellEl.innerHTML = contentWithoutCheckbox;

            cellEl.querySelectorAll(".editor").forEach((editor) => {
                if (editor.id) EditorSystem.inizializzaEditor(editor.id);
            });
        }
    },

    _setupCheckboxPositionButtons: function (cell) {
        const cellEl = asElement(cell);
        if (!cellEl) return;
        const self = this;

        const bindPos = (selector, position) => {
            cellEl.querySelectorAll(selector).forEach((btn) => {
                // Replica .off("click") clonando il nodo per rimuovere tutti i listener
                const clone = btn.cloneNode(true);
                btn.replaceWith(clone);
                clone.addEventListener("click", (e) => {
                    e.stopPropagation();
                    self._changeCheckboxPosition(cellEl, position);
                });
            });
        };

        bindPos(".btnCheckTop", "top");
        bindPos(".btnCheckBottom", "bottom");
        bindPos(".btnCheckLeft", "left");
        bindPos(".btnCheckRight", "right");
    },

    _changeCheckboxPosition: function (cell, position) {
        const cellEl = asElement(cell);
        if (!cellEl) return;
        const wrapCheckCell = cellEl.querySelector(".fm-wrap-check-cell");
        if (!wrapCheckCell) return;

        wrapCheckCell.classList.remove("fm-pos-top", "pos-bottom", "pos-left", "pos-right");
        wrapCheckCell.classList.add(`pos-${position}`);

        switch (position) {
            case "top":
                Object.assign(wrapCheckCell.style, { flexDirection: "column", alignItems: "center" });
                break;
            case "bottom":
                Object.assign(wrapCheckCell.style, { flexDirection: "column-reverse", alignItems: "center" });
                break;
            case "left":
                Object.assign(wrapCheckCell.style, { flexDirection: "row", alignItems: "center" });
                break;
            case "right":
                Object.assign(wrapCheckCell.style, { flexDirection: "row-reverse", alignItems: "center" });
                break;
        }

        this._saveCheckboxPosition(cellEl, position);
    },

    _saveCheckboxPosition: function (cell, position) {
        const cellEl = asElement(cell);
        if (!cellEl) return;
        const tableEl = cellEl.closest("table");
        if (!tableEl) return;
        const parent = tableEl.closest(".fm-groupcollex");
        const path = PathManager.extractPath(parent);
        const container = tableEl.closest('[class^="fm-draggable-container"]');
        const tableIndex = container ? indexInCollection(container.querySelectorAll("table"), tableEl) : -1;
        const tr = cellEl.closest("tr");
        const rowIndex = tr ? siblingIndex(tr) : -1;
        const colIndex = siblingIndex(cellEl);

        _postTable({
            action: "updateCheckboxPosition",
            tableIndex,
            rowIndex,
            colIndex,
            position,
            url: path,
        })
            .then((response) => console.log("Posizione checkbox aggiornata con successo:", response))
            .catch((error) => console.error("Errore durante l'aggiornamento della posizione checkbox:", error));
    },

    handleInputChange: function (inputElement) {
        const inputEl = asElement(inputElement);
        if (!inputEl) return;
        const modTable = inputEl.closest(".fm-input-mod-table");
        const modTableIdMatch = modTable ? modTable.id.match(/\d+/) : null;
        const modTableIndex = modTableIdMatch ? parseInt(modTableIdMatch[0]) : null;
        const tableEl = document.getElementById(`numTab${modTableIndex}`);
        if (!tableEl) return;

        const key = inputEl.classList.contains("fm-typecell-input") ? "typecell" : "mpagew";
        let newValue;

        if (key === "typecell") {
            const typeCellContainer = inputEl.closest(".fm-typecell-container");
            const inputs = typeCellContainer ? typeCellContainer.querySelectorAll(".fm-typecell-input") : [];
            const newTypeCellArray = Array.from(inputs).map((i) => i.value);
            newValue = `|${newTypeCellArray.join("|")}|`;
        } else {
            newValue = inputEl.value;
        }

        elData(tableEl, key, newValue);
        tableEl.setAttribute(`data-${key}`, newValue);

        console.log(`Aggiornamento ${key} a ${newValue} per la tabella ${modTableIndex}`);
        this.ajaxUpdateTableAttribute(tableEl, key, newValue);
    },

    handleCheckboxChange: function (checkboxElement) {
        const checkboxEl = asElement(checkboxElement);
        if (!checkboxEl) return;
        const modTable = checkboxEl.closest(".fm-input-mod-table");
        const modTableIdMatch = modTable ? modTable.id.match(/\d+/) : null;
        const modTableIndex = modTableIdMatch ? parseInt(modTableIdMatch[0]) : null;
        const tableEl = document.getElementById(`numTab${modTableIndex}`);
        if (!tableEl) return;

        let key;
        if (checkboxEl.closest(".fm-input-mixtr")) key = "mixtr";
        else if (checkboxEl.closest(".fm-input-mixcol")) key = "mixcol";
        else {
            console.error("Tipo di checkbox non riconosciuto");
            return;
        }

        const newValue = checkboxEl.checked ? 1 : 0;
        elData(tableEl, key, newValue);
        tableEl.setAttribute(`data-${key}`, newValue);

        console.log(`Aggiornamento ${key} a ${newValue} per la tabella ${modTableIndex}`);
        this.ajaxUpdateTableAttribute(tableEl, key, newValue);
    },

    ajaxUpdateTableAttribute: function (table, key, newValue) {
        const tableEl = asElement(table);
        if (!tableEl) return;
        const parent = tableEl.closest(".fm-groupcollex");
        const path = PathManager.extractPath(parent);
        const container = tableEl.closest('[class^="fm-draggable-container"]');
        const tableIndex = container ? indexInCollection(container.querySelectorAll("table"), tableEl) : -1;

        _postTable({
            action: "updateTableAttribute",
            tableIndex,
            url: path,
            key,
            value: newValue,
        })
            .then((response) => console.log(`${key} aggiornato con successo sul server:`, response))
            .catch((error) => console.error(`Errore durante l'aggiornamento di ${key} sul server:`, error));
    },

    batchSaveCheckboxRMState: function (filePath, user) {
        console.log("DEBUG: batchSaveCheckboxRMState chiamato con:", filePath, user);
        console.log("DEBUG: checkboxUpdates array:", this._state.checkboxUpdates);
        if (this._state.checkboxUpdates.length === 0) return;

        const self = this;

        _postTable({
            action: "updateMultipleCheckboxes",
            updates: self._state.checkboxUpdates,
            url: filePath,
            user,
        })
            .then((response) => console.log("Stato dei checkbox aggiornato con successo:", response))
            .catch((error) => console.error("Errore durante l'aggiornamento dei checkbox:", error))
            .finally(() => {
                self._state.checkboxUpdates = [];
            });
    },

    addCheckboxUpdate: function (update) {
        console.log("DEBUG: addCheckboxUpdate chiamato con:", update);
        if (!this._state.checkboxUpdates) this._state.checkboxUpdates = [];
        this._state.checkboxUpdates.push(update);
        console.log("DEBUG: Array checkboxUpdates aggiornato:", this._state.checkboxUpdates);
    },

    debounceBatchSave: function (filePath, user) {
        clearTimeout(this._state.batchingTimeout);
        this._state.batchingTimeout = setTimeout(() => {
            this.batchSaveCheckboxRMState(filePath, user);
        }, 300);
    },

    updateIds: function () {
        const editors = document.querySelectorAll(".editor, .myTextarea");
        let newId = 1;
        editors.forEach((el, index) => {
            const currentId = el.id;
            if (index % 2 === 0) newId++;
            const newIdStr = currentId.replace(/\d+$/, newId);
            el.id = newIdStr;
            if (el.classList.contains("editor")) {
                EditorSystem.inizializzaEditor(el.id);
            }
        });

        document.querySelectorAll(".fm-input-mod-table").forEach((el, index) => {
            const currentId = el.id;
            el.id = currentId.replace(/\d+$/, index);
        });

        document.querySelectorAll("table").forEach((el, index) => {
            const tableId = el.id;
            if (tableId) {
                el.id = tableId.replace(/\d+$/, index);
            }
        });

        document.querySelectorAll(".fm-num-table").forEach((el, index) => {
            el.textContent = (el.textContent || "").replace(/\d+/, index + 1);
        });

        document.querySelectorAll(".fm-num-table-t").forEach((el, index) => {
            el.textContent = (el.textContent || "").replace(/\d+/, index + 1);
        });
    },

    initializeTableEditor: function (element, parent, index) {
        const self = this;
        const parentEl = asElement(parent);
        if (!parentEl) return Promise.resolve();

        const domTabs = parentEl.querySelectorAll(".fm-tabelle");
        const domTab = domTabs[index];
        if (!domTab) return Promise.resolve();

        return new Promise((resolve) => {
            UIComp.preloadElementiRiservati((tempDivArg) => {
                const tempEl = asElement(tempDivArg);
                if (!tempEl) {
                    console.error("Errore nel caricamento del file Elementi_Riservati.html");
                    resolve();
                    return;
                }
                const tempClone = tempEl.cloneNode(true);

                const btnC = tempClone.querySelector(".fm-add-del-col");
                const btnR1 = tempClone.querySelector(".Add-Row");
                const btnR2 = tempClone.querySelector(".fm-check-del-row");

                domTab.querySelectorAll("table").forEach((table) => {
                    self._setupTableForEditing(table, btnC, btnR1, btnR2);
                });

                resolve();
            });
        });
    },

    _setupTableForEditing: function (table, btnC, btnR1, btnR2) {
        const tableEl = asElement(table);
        if (!tableEl) return;
        tableEl.style.display = "flex";

        this._addColumnControls(tableEl, btnC);
        this._addRowControls(tableEl, btnR1, btnR2);
        this._setupTableIdentifiers(tableEl);
        this._createTableEditInterface(tableEl);
        this._finalizeTableSetup(tableEl);
    },

    _addColumnControls: function (table, btnC) {
        const tableEl = asElement(table);
        if (!tableEl || !btnC) return;
        const newRow = document.createElement("tr");
        newRow.className = "fm-row-actions-header";
        newRow.style.verticalAlign = "bottom";

        const firstRow = tableEl.querySelector("tr");
        const firstRowTds = firstRow ? firstRow.querySelectorAll("td") : [];
        firstRowTds.forEach(() => {
            const columnActionsCell = document.createElement("td");
            columnActionsCell.className = "fm-column-actions";
            columnActionsCell.appendChild(btnC.cloneNode(true));
            newRow.appendChild(columnActionsCell);
        });
        tableEl.insertBefore(newRow, tableEl.firstChild);
    },

    _addRowControls: function (table, btnR1, btnR2) {
        const tableEl = asElement(table);
        if (!tableEl || !btnR1 || !btnR2) return;
        const self = this;
        const allTrs = tableEl.querySelectorAll("tr");
        const rows = Array.from(allTrs).slice(1); // .not(":first")

        rows.forEach((row) => {
            const rowActionsCell = document.createElement("td");
            rowActionsCell.className = "row-actions";
            rowActionsCell.appendChild(btnR1.cloneNode(true));
            rowActionsCell.appendChild(btnR2.cloneNode(true));
            row.appendChild(rowActionsCell);
            self.adjustRowActionHeight(row);
        });
    },

    _setupTableIdentifiers: function (table) {
        const tableEl = asElement(table);
        if (!tableEl) return { numTab: -1, numTabName: 0 };
        const container = tableEl.closest('[class^="fm-draggable-container"]');
        const numTab = container ? indexInCollection(container.querySelectorAll("table"), tableEl) : -1;
        tableEl.id = `numTab${numTab}`;
        const numTabName = numTab + 1;

        const tabelle = tableEl.closest(".fm-tabelle");
        if (tabelle) {
            tabelle.querySelectorAll(".fm-num-table-t").forEach((el) => el.remove());
        }
        const tbody = tableEl.querySelector("tbody");
        if (tbody) {
            const label = document.createElement("div");
            label.className = "fm-num-table-t";
            label.textContent = `TABELLA ${numTabName}`;
            tbody.before(label);
        }

        return { numTab, numTabName };
    },

    _createTableEditInterface: function (table) {
        const tableEl = asElement(table);
        if (!tableEl) return;
        const { numTab, numTabName } = this._setupTableIdentifiers(tableEl);
        const tabelle = tableEl.closest(".fm-tabelle");
        if (!tabelle) return;
        const allTables = tabelle.querySelectorAll("table");
        const totalTables = allTables.length;

        const tableIndexInTabelle = indexInCollection(allTables, tableEl);
        if (tableIndexInTabelle === 0 && totalTables > 1) {
            const alignSwitch = this._createAlignSwitch(tabelle);
            if (alignSwitch) tabelle.before(alignSwitch);
        }

        const inputContainer = document.createElement("div");
        inputContainer.className = "fm-input-mod-table";
        inputContainer.id = `numTabOption${numTab}`;
        const numTableDiv = document.createElement("div");
        numTableDiv.className = "fm-num-table";
        numTableDiv.textContent = `TABELLA ${numTabName}`;
        inputContainer.appendChild(numTableDiv);

        eachDataAttr(tableEl, (key, value) => {
            const inputGroup = this._createInputGroup(key, value, tableEl);
            if (inputGroup) inputContainer.appendChild(inputGroup);
        });

        tabelle.before(inputContainer);
    },

    _createAlignSwitch: function (tabelle) {
        const tabelleEl = asElement(tabelle);
        if (!tabelleEl) return null;
        const currentAlign = tabelleEl.getAttribute("data-align") || "hfill";
        const isHfill = currentAlign !== "vfill";
        const problemEl = tabelleEl.closest(".fm-groupcollex");
        const path = PathManager.extractPath(problemEl);
        const container = tabelleEl.closest('[class^="fm-draggable-container"]');
        const tabelleIndex = container ? indexInCollection(container.querySelectorAll(".fm-tabelle"), tabelleEl) : -1;

        const switchEl = htmlToElement(`
      <div class="fm-tabelle-align-switch">
        <span>Tabelle:</span>
        <div class="fm-align-toggle">
          <button class="fm-align-btn hfill-btn${isHfill ? " active" : ""}" data-align="hfill">⇔ affiancate</button>
          <button class="fm-align-btn vfill-btn${!isHfill ? " active" : ""}" data-align="vfill">⇕ impilate</button>
        </div>
      </div>
    `);
        if (!switchEl) return null;

        switchEl.querySelectorAll(".fm-align-btn").forEach((btn) => {
            btn.addEventListener("click", () => {
                const align = btn.getAttribute("data-align");
                switchEl.querySelectorAll(".fm-align-btn").forEach((b) => b.classList.remove("active"));
                btn.classList.add("active");
                tabelleEl.setAttribute("data-align", align);

                _postTable({
                    action: "updateTabelleAlign",
                    tabelleIndex,
                    url: path,
                    align,
                })
                    .then((response) => console.log("data-align aggiornato sul server:", response))
                    .catch((error) => console.error("Errore aggiornamento data-align:", error));
            });
        });

        return switchEl;
    },

    _createInputGroup: function (key, value, table) {
        if (key === "mixtr" || key === "mixcol") {
            return this._createCheckboxInput(key, value);
        }
        if (key === "typecell") {
            return this._createTypeCellInput(key, value, table);
        }
        return this._createTextInput(key, value, table);
    },

    _createCheckboxInput: function (key, value) {
        return htmlToElement(`
        <div class="input-${key}">
            <label>${key}</label>
            <input type="checkbox" ${value === 1 ? "checked" : ""} />
        </div>
    `);
    },

    _createTypeCellInput: function (key, value, table) {
        const cleanValue = String(value).replace(/^\|+|\|+$/g, "");
        const typeCellArray = cleanValue ? cleanValue.split("|") : ["X"];
        const typeCellContainer = document.createElement("div");
        typeCellContainer.className = "fm-typecell-container";

        const datalistId = `typecell-suggestions-${Date.now()}`;
        const datalist = htmlToElement(`
      <datalist id="${datalistId}">
        <option value="X">X - Colonna auto-espandibile (tabularx)</option>
        <option value="c">c - Centrato</option>
        <option value="l">l - Allineato a sinistra</option>
        <option value="r">r - Allineato a destra</option>
        <option value="p{2cm}">p{2cm} - Paragrafo con larghezza fissa</option>
        <option value="m{2cm}">m{2cm} - Verticalmente centrato</option>
        <option value="b{2cm}">b{2cm} - Allineato in basso</option>
        <option value=">{\\arraybackslash}m{1cm}">>{\\arraybackslash}m{1cm} - Centrato con backslash</option>
        <option value=">{\\centering\\arraybackslash}m{2cm}">>{\\centering\\arraybackslash}m{2cm} - Centrato esplicitamente</option>
        <option value=">{\\raggedright\\arraybackslash}m{2cm}">>{\\raggedright\\arraybackslash}m{2cm} - A sinistra</option>
        <option value=">{\\raggedleft\\arraybackslash}m{2cm}">>{\\raggedleft\\arraybackslash}m{2cm} - A destra</option>
      </datalist>
    `);
        if (datalist) typeCellContainer.appendChild(datalist);

        typeCellArray.forEach((cellSpec, index) => {
            const cellWrapper = document.createElement("div");
            cellWrapper.style.cssText = "display: inline-flex; align-items: center; margin: 2px;";

            const cellInput = document.createElement("input");
            cellInput.type = "text";
            cellInput.className = "fm-typecell-input";
            cellInput.value = cellSpec;
            cellInput.setAttribute("list", datalistId);
            cellInput.placeholder = "es: X, c, l, r, p{2cm}";
            cellInput.dataset.index = String(index);
            cellInput.style.cssText = "min-width: 120px; margin-right: 4px;";
            cellInput.title = "Digita per vedere i suggerimenti: X, c, l, r, p{}, m{}, >{}";

            const removeButton = document.createElement("button");
            removeButton.type = "button";
            removeButton.style.cssText = "padding: 2px 4px; font-size: 12px;";
            removeButton.textContent = "×";

            cellInput.addEventListener("input", () => this._updateTypeCellAttribute(table, key, typeCellContainer));
            removeButton.addEventListener("click", () => {
                if (typeCellContainer.querySelectorAll(".fm-typecell-input").length > 1) {
                    cellWrapper.remove();
                    this._updateTypeCellAttribute(table, key, typeCellContainer);
                }
            });

            cellWrapper.appendChild(cellInput);
            cellWrapper.appendChild(removeButton);
            typeCellContainer.appendChild(cellWrapper);
        });

        const addButton = document.createElement("button");
        addButton.type = "button";
        addButton.style.cssText = "margin: 2px; padding: 4px 8px; background: #4CAF50; color: white; border: none; border-radius: 3px;";
        addButton.textContent = "+ Colonna";
        addButton.addEventListener("click", () => {
            const newWrapper = document.createElement("div");
            newWrapper.style.cssText = "display: inline-flex; align-items: center; margin: 2px;";

            const newInput = document.createElement("input");
            newInput.type = "text";
            newInput.className = "fm-typecell-input";
            newInput.value = "X";
            newInput.setAttribute("list", datalistId);
            newInput.placeholder = "es: X, c, l, r, p{2cm}";
            newInput.style.cssText = "min-width: 120px; margin-right: 4px;";
            newInput.title = "Digita per vedere i suggerimenti: X, c, l, r, p{}, m{}, >{}";

            const newRemoveButton = document.createElement("button");
            newRemoveButton.type = "button";
            newRemoveButton.style.cssText = "padding: 2px 4px; font-size: 12px;";
            newRemoveButton.textContent = "×";

            newInput.addEventListener("input", () => this._updateTypeCellAttribute(table, key, typeCellContainer));
            newRemoveButton.addEventListener("click", () => {
                if (typeCellContainer.querySelectorAll(".fm-typecell-input").length > 1) {
                    newWrapper.remove();
                    this._updateTypeCellAttribute(table, key, typeCellContainer);
                }
            });

            newWrapper.appendChild(newInput);
            newWrapper.appendChild(newRemoveButton);
            addButton.before(newWrapper);
            this._updateTypeCellAttribute(table, key, typeCellContainer);
        });

        typeCellContainer.appendChild(addButton);

        const wrapper = document.createElement("div");
        wrapper.className = `input-${key}`;
        const label = document.createElement("label");
        label.textContent = key;
        wrapper.appendChild(label);
        wrapper.appendChild(typeCellContainer);
        return wrapper;
    },

    _createTextInput: function (key, value, table) {
        const wrapper = document.createElement("div");
        wrapper.className = `input-${key}`;
        wrapper.innerHTML = `
            <label>${key}</label>
            <input type="text" value="${value}" />
        `;

        const input = wrapper.querySelector("input");
        if (input) {
            input.addEventListener("input", (e) => {
                this._updateTableAttribute(table, key, e.target.value);
            });
        }

        return wrapper;
    },

    _updateTypeCellAttribute: function (table, key, container) {
        const containerEl = asElement(container);
        if (!containerEl) return;
        const inputs = containerEl.querySelectorAll(".fm-typecell-input");
        const newTypeCellArray = Array.from(inputs).map((i) => i.value);
        const newTypeCell = `|${newTypeCellArray.join("|")}|`;
        this._updateTableAttribute(table, key, newTypeCell);
    },

    _updateTableAttribute: function (table, key, newValue) {
        const tableEl = asElement(table);
        if (!tableEl) return;
        elData(tableEl, key, newValue);
        tableEl.setAttribute(`data-${key}`, newValue);
        console.log(`Attributo data-${key} aggiornato a:`, newValue);
    },

    _finalizeTableSetup: function (table) {
        const tableEl = asElement(table);
        if (!tableEl) return;
        const firstRow = tableEl.querySelector("tr");
        if (firstRow) firstRow.querySelectorAll("td").forEach((td) => { td.style.border = "none"; });
        tableEl.querySelectorAll("tr td:last-child").forEach((td) => { td.style.border = "none"; });

        this.checkAndMarkCheckboxes(tableEl);

        const self = this;
        tableEl.querySelectorAll("td").forEach((cell) => {
            const hasCheckboxRM = cell.querySelector(".fm-checkbox-rm") !== null;
            if (hasCheckboxRM) {
                const hasButtons = cell.querySelector(".fm-btn-check-top") !== null;
                if (!hasButtons) {
                    self._rebuildCheckboxStructure(cell);
                } else {
                    self._setupCheckboxPositionButtons(cell);
                }
            }
        });

        const container = tableEl.closest('[class^="fm-draggable-container"]');
        const numTab = container ? indexInCollection(container.querySelectorAll("table"), tableEl) : -1;
        this.gestioneRigaColonna(tableEl, numTab);
    },

    _rebuildCheckboxStructure: function (cell) {
        const cellEl = asElement(cell);
        if (!cellEl) return;
        const checkboxRM = cellEl.querySelector(".fm-checkbox-rm");
        if (!checkboxRM) return;

        const isCheckboxRMChecked = checkboxRM.checked === true;
        const wrapCheckCell = cellEl.querySelector(".fm-wrap-check-cell");
        let positionClass = "";
        let wrapStyle = "display: flex;";
        if (wrapCheckCell) {
            const classes = wrapCheckCell.className || "";
            const posMatch = classes.match(/pos-(top|bottom|left|right)/);
            if (posMatch) positionClass = posMatch[0];
            const savedStyle = wrapCheckCell.getAttribute("style");
            if (savedStyle && savedStyle.trim() !== "") wrapStyle = savedStyle;
        }

        if (wrapCheckCell) {
            const wrapClone = wrapCheckCell.cloneNode(true);
            wrapClone.querySelectorAll(".fm-checkbox-rm").forEach((el) => el.remove());
            wrapClone.querySelectorAll(".fm-checkbox-wrapper").forEach((el) => el.remove());

            let contentHtml = "";
            const cellContentEl = wrapClone.querySelector(".fm-cell-content");
            if (cellContentEl) {
                contentHtml = cellContentEl.innerHTML;
            } else {
                contentHtml = (wrapClone.innerHTML || "").trim();
            }
            if (!contentHtml || contentHtml.trim() === "") contentHtml = "&nbsp;";

            const wrapClass = positionClass ? `wrapCheckCell ${positionClass}` : "wrapCheckCell";
            const newWrapHtml = `
                <div class="${wrapClass}" style="${wrapStyle}">
                    <div class="fm-checkbox-wrapper">
                        <button class="fm-btn-check-pos fm-btn-check-top" title="Sposta checkbox sopra">↑</button>
                        <button class="fm-btn-check-pos fm-btn-check-left" title="Sposta checkbox a sinistra">←</button>
                        <input type="checkbox" class="checkbox fm-checkbox-rm" ${isCheckboxRMChecked ? "checked" : ""} onclick="event.stopPropagation();">
                        <button class="fm-btn-check-pos fm-btn-check-right" title="Sposta checkbox a destra">→</button>
                        <button class="fm-btn-check-pos fm-btn-check-bottom" title="Sposta checkbox sotto">↓</button>
                    </div>
                    <div class="fm-cell-content">${contentHtml}</div>
                </div>
            `;
            const newWrap = htmlToElement(newWrapHtml);
            if (newWrap) wrapCheckCell.replaceWith(newWrap);

            const insertCheckboxInToolbar = cellEl.querySelector(".toolbar .fm-insertcheckbox-cell");
            if (insertCheckboxInToolbar) insertCheckboxInToolbar.checked = true;
        } else {
            const cellClone = cellEl.cloneNode(true);
            cellClone.querySelectorAll(".fm-checkbox-rm").forEach((el) => el.remove());
            let contentHtml = (cellClone.innerHTML || "").trim();

            console.log("DEBUG rebuild CASO 2: Contenuto recuperato:", contentHtml);
            if (!contentHtml || contentHtml === "") contentHtml = "&nbsp;";

            const newCellHtml = `
                <div class="fm-wrap-check-cell" style="display: flex;">
                    <div class="fm-checkbox-wrapper">
                        <button class="fm-btn-check-pos fm-btn-check-top" title="Sposta checkbox sopra">↑</button>
                        <button class="fm-btn-check-pos fm-btn-check-left" title="Sposta checkbox a sinistra">←</button>
                        <input type="checkbox" class="checkbox fm-checkbox-rm" ${isCheckboxRMChecked ? "checked" : ""} onclick="event.stopPropagation();">
                        <button class="fm-btn-check-pos fm-btn-check-right" title="Sposta checkbox a destra">→</button>
                        <button class="fm-btn-check-pos fm-btn-check-bottom" title="Sposta checkbox sotto">↓</button>
                    </div>
                    <div class="fm-cell-content">${contentHtml}</div>
                </div>
            `;
            cellEl.innerHTML = newCellHtml;

            const insertCheckboxInToolbar = cellEl.querySelector(".toolbar .fm-insertcheckbox-cell");
            if (insertCheckboxInToolbar) insertCheckboxInToolbar.checked = true;
        }

        cellEl.querySelectorAll(".editor").forEach((editor) => {
            if (editor.id) EditorSystem.inizializzaEditor(editor.id);
        });

        this._setupCheckboxPositionButtons(cellEl);
    },
};

window.FM = window.FM || {};
window.FM.TableManager = TableManager;
window.TableManager    = TableManager;
