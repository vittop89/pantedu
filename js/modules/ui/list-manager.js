/**
 * ListManager — estratto da functions-mod.js (Phase 9f).
 * G26.phase4.9 — migrato a vanilla JS (no jQuery).
 */
import { Endpoints } from "../core/endpoints.js";

/** Walk ancestors of `node` up to (excluding) `stop`, return those matching CSS selector. */
function ancestorsMatching(node, selector, stop) {
    const out = [];
    let cur = node && node.nodeType === Node.ELEMENT_NODE ? node.parentElement : (node && node.parentElement);
    while (cur && cur !== stop) {
        if (cur.matches?.(selector)) out.push(cur);
        cur = cur.parentElement;
    }
    return out; // ordered child→parent
}

/** Return the closest ancestor (including self if Element) matching selector. */
function closestEl(node, selector) {
    let el = node;
    if (el && el.nodeType !== Node.ELEMENT_NODE) el = el.parentElement;
    return el ? el.closest(selector) : null;
}

export const ListManager = {
    _preDropdownRange: null,

    _getTopLevelBlock: function (node, editorEl) {
        let current = node;
        while (current && current.parentNode !== editorEl) {
            current = current.parentNode;
        }
        return current && current !== editorEl ? current : null;
    },

    _fragmentToLiContent: function (fragment) {
        const blockTags = new Set(["DIV", "P", "H1", "H2", "H3", "H4", "H5", "H6", "LI", "BLOCKQUOTE", "PRE", "TR", "TD", "TH"]);
        const processNode = (node) => {
            if (node.nodeType === Node.TEXT_NODE) return node.textContent;
            if (node.nodeType === Node.ELEMENT_NODE) {
                if (node.tagName === "BR") return "<br>";
                let inner = "";
                node.childNodes.forEach((child) => { inner += processNode(child); });
                if (blockTags.has(node.tagName)) return `${inner}<br>`;
                return inner;
            }
            return "";
        };
        let result = "";
        fragment.childNodes.forEach((node) => { result += processNode(node); });
        return result.replace(/(<br>)+$/, "");
    },

    _blockToLiText: function (el) {
        if (el.nodeType === Node.TEXT_NODE) return el.textContent;
        const frag = document.createDocumentFragment();
        el.childNodes.forEach((child) => frag.appendChild(child.cloneNode(true)));
        return this._fragmentToLiContent(frag);
    },

    _convertListTo: function (newTag, styleType, type) {
        const selection = window.getSelection();
        if (selection.rangeCount > 0) {
            console.log("DEVO CONVERTIRE LA LISTA");
            const range = selection.getRangeAt(0);
            let container = range.startContainer;
            if (container.nodeType === Node.TEXT_NODE) {
                console.log("il conteiner è un nodo di testo");
                container = container.parentElement;
            }
            if (container.tagName === "OL" || container.tagName === "UL") {
                container = container.querySelector("li") || container;
            }

            const editorElement = closestEl(container, ".editor");
            if (!editorElement) {
                console.error("Editor non trovato per la conversione lista");
                return;
            }

            // parentsUntil(editor, "div, p").last() → ultimo (più vicino a editor) ancestor div/p
            const ancestors = ancestorsMatching(container, "div, p", editorElement);
            let paragraph = ancestors[ancestors.length - 1] || null;

            if (!paragraph) {
                const candidate = closestEl(container, "div, p");
                if (candidate && editorElement.contains(candidate)) paragraph = candidate;
            }

            const listItem = paragraph ? paragraph.querySelector("li") : null;
            console.log("container: ", container);

            if (listItem) {
                const oldList = listItem.parentElement;
                console.log("Vecchia lista:", oldList);
                const newList = document.createElement(newTag);
                // Move all <li> children from oldList → newList
                Array.from(oldList.children).forEach((child) => {
                    if (child.tagName === "LI") newList.appendChild(child);
                });
                console.log("Nuova lista:", newList);
                oldList.replaceWith(newList);
                this._setListStyle(newList, styleType, type);
            }
        }
        DomManager.restoreSelection();
        this.updateListTypeSelector();
    },

    changeListType: function (type, editorID) {
        const curetPos = DomManager.saveSelection();
        const editor = document.getElementById(editorID);
        if (!editor) {
            console.error(`Editor con ID ${editorID} non trovato.`);
            return;
        }
        const selection = window.getSelection();

        let activeRange = selection.rangeCount > 0 ? selection.getRangeAt(0) : null;
        if ((!activeRange || activeRange.collapsed) && this._preDropdownRange && !this._preDropdownRange.collapsed) {
            activeRange = this._preDropdownRange;
        }
        this._preDropdownRange = null;

        // ── Selezione multi-blocco ────────────────────────────────────────────
        if (activeRange && !activeRange.collapsed) {
            const startBlock = this._getTopLevelBlock(activeRange.startContainer, editor);
            const endBlock = this._getTopLevelBlock(activeRange.endContainer, editor);

            if (startBlock && endBlock) {
                const listTag = type === "insertUnorderedList" ? "UL" : "OL";

                const blocks = [];
                let node = startBlock;
                while (node) {
                    if (node.nodeType === Node.ELEMENT_NODE || node.nodeType === Node.TEXT_NODE) {
                        blocks.push(node);
                    }
                    if (node === endBlock) break;
                    node = node.nextSibling;
                }

                const lines = blocks.map((b) => this._blockToLiText(b)).filter((l) => l !== "");
                const liContent = lines.join("<br>");
                const insertBefore = endBlock.nextSibling;

                const list = document.createElement(listTag);
                const newLi = document.createElement("li");
                newLi.innerHTML = liContent;
                list.appendChild(newLi);
                this._setListStyle(list, this._getListStyleType(type), type);

                blocks.forEach((b) => b.parentNode && b.parentNode.removeChild(b));

                if (insertBefore) {
                    editor.insertBefore(list, insertBefore);
                } else {
                    editor.appendChild(list);
                }

                ListManager.onListItemCreated(newLi);

                const newRange = document.createRange();
                const firstChild = newLi.firstChild;
                if (firstChild) {
                    newRange.setStart(firstChild, 0);
                    newRange.collapse(true);
                    selection.removeAllRanges();
                    selection.addRange(newRange);
                }

                this.updateListTypeSelector();
                DomManager.restoreSelection();
                return;
            }
        }

        if (!activeRange) {
            this.updateListTypeSelector();
            DomManager.restoreSelection();
            return;
        }

        {
            const range = activeRange;
            let container = range.startContainer;
            if (container.nodeType === Node.TEXT_NODE) {
                container = container.parentElement;
            }
            if (container.tagName === "OL" || container.tagName === "UL") {
                container = container.querySelector("li") || container;
            }

            // Trova il paragrafo/div corretto contenente il cursore
            let paragraph = null;
            const candidate = closestEl(container, "div, p");
            if (candidate) {
                const isDirectChild = (editor.contains(candidate) && candidate.parentElement === editor);
                const ownerEditor = candidate.parentElement?.closest(`#${editorID}`);
                if (isDirectChild || ownerEditor) {
                    paragraph = candidate;
                }
            }

            if (!paragraph || paragraph === editor) {
                const ancestors = ancestorsMatching(container, "div, p", editor);
                paragraph = ancestors[ancestors.length - 1] || null;
            }

            if (!paragraph) {
                // Fallback: cerca tra parents (non parentsUntil) un elemento dentro l'editor
                let cur = container && container.nodeType === Node.ELEMENT_NODE ? container.parentElement : container?.parentElement;
                while (cur) {
                    if (cur.matches?.("div, p") && editor.contains(cur) && cur !== editor) {
                        paragraph = cur;
                        break;
                    }
                    cur = cur.parentElement;
                }
            }

            const listItem = paragraph ? paragraph.querySelector("li") : null;
            if (paragraph && !listItem) {
                const listTag = type === "insertUnorderedList" ? "UL" : "OL";
                const list = document.createElement(listTag);
                const newListItem = document.createElement("li");
                list.appendChild(newListItem);

                // Inserisci il contenuto direttamente nel li senza wrapper div
                const contents = Array.from(paragraph.childNodes);

                contents.forEach((node) => {
                    if (node.nodeType === Node.ELEMENT_NODE && node.tagName === "DIV") {
                        // Sposta contenuto del div direttamente nel li
                        Array.from(node.childNodes).forEach((child) => newListItem.appendChild(child));
                        newListItem.appendChild(document.createElement("br"));
                    } else {
                        newListItem.appendChild(node.cloneNode(true));
                    }
                });

                paragraph.replaceChildren();
                paragraph.appendChild(list);
                this._setListStyle(list, this._getListStyleType(type), type);
                list.id = "newList";

                ListManager.onListItemCreated(newListItem);

                const newRange = document.createRange();
                const sel = window.getSelection();
                const textNode = Array.from(newListItem.childNodes).find((n) => n.nodeType === Node.TEXT_NODE);
                if (textNode) {
                    newRange.setStart(textNode, curetPos);
                    newRange.collapse(true);
                    sel.removeAllRanges();
                    sel.addRange(newRange);
                    DomManager.saveSelection();
                }
            } else if (listItem) {
                console.log("listItem:", listItem);
                console.log("listItem.parent():", listItem.parentElement);
                const list = listItem.parentElement;
                if (list && (list.tagName === "OL" || list.tagName === "UL")) {
                    if (type === "insertUnorderedList") {
                        this._convertListTo("UL", "disc", type);
                    } else if (type === "insertOrderedList") {
                        this._convertListTo("OL", "decimal", type);
                    } else if (type === "insertOrderedList1") {
                        this._convertListTo("OL", "none", type);
                    } else if (type === "insertOrderedListA") {
                        this._convertListTo("OL", "upper-alpha", type);
                    } else if (type === "insertOrderedLista") {
                        this._convertListTo("OL", "lower-alpha", type);
                    }
                }
            }
        }
        this.updateListTypeSelector();
        DomManager.restoreSelection();
    },

    _setListStyle: function (list, styleType, type) {
        if (!list) return;
        list.style.listStyleType = styleType;
        list.classList.remove("fm-custom-counter", "custom-decimal", "custom-upper-alpha", "custom-lower-alpha");
        if (styleType === "none") list.classList.add("fm-custom-counter");
        else if (styleType === "decimal") list.classList.add("fm-custom-decimal");
        else if (styleType === "upper-alpha") list.classList.add("fm-custom-upper-alpha");
        else if (styleType === "lower-alpha") list.classList.add("fm-custom-lower-alpha");
        list.dataset.listType = type;
    },

    _getListStyleType: function (type) {
        switch (type) {
            case "insertOrderedList":  return "decimal";
            case "insertOrderedList1": return "none";
            case "insertOrderedListA": return "upper-alpha";
            case "insertOrderedLista": return "lower-alpha";
            default:                   return "disc";
        }
    },

    updateListTypeSelector: function () {
        const selection = window.getSelection();
        if (selection.rangeCount === 0) return;

        const range = selection.getRangeAt(0);
        let container = range.startContainer;
        if (container.nodeType === Node.TEXT_NODE) {
            container = container.parentElement;
        }

        const editorWrapper = closestEl(container, ".Editor_wrapper");
        if (!editorWrapper) return;

        const listTypeEl = editorWrapper.querySelector("#listType");
        if (!listTypeEl) return;

        const listItem = closestEl(container, "li");
        if (!listItem) {
            listTypeEl.value = "";
            return;
        }

        const list = listItem.parentElement;
        if (!list) return;

        if (list.tagName === "UL") {
            listTypeEl.value = "insertUnorderedList";
        } else if (list.tagName === "OL") {
            const listStyleType = getComputedStyle(list).listStyleType;
            switch (listStyleType) {
                case "decimal":     listTypeEl.value = "insertOrderedList"; break;
                case "none":        listTypeEl.value = "insertOrderedList1"; break;
                case "upper-alpha": listTypeEl.value = "insertOrderedListA"; break;
                case "lower-alpha": listTypeEl.value = "insertOrderedLista"; break;
                default:            listTypeEl.value = ""; break;
            }
        }
    },

    // ── DSA checkbox handling ────────────────────────────────────────────────
    _createDSACheckbox: function (listItem) {
        if (!listItem || !listItem.closest(".DraggableContainer_ver, .fm-draggable-container")) {
            return;
        }

        const existingCheckbox = listItem.querySelector(".dsa-checkbox");

        if (existingCheckbox) {
            const container = existingCheckbox.closest(".fm-dsa-checkbox-container");

            let hasAddTextDSA = false;
            let nextNode = container ? container.nextSibling : null;
            while (nextNode) {
                if (nextNode.nodeType === Node.ELEMENT_NODE) {
                    if (nextNode.matches("span.fm-add-text-dsa")) {
                        hasAddTextDSA = true;
                        if (nextNode.style.display === "none") {
                            console.log("   🔧 Rimuovo display: none dallo span");
                            nextNode.style.display = "";
                        }
                    }
                    break;
                }
                nextNode = nextNode.nextSibling;
            }

            existingCheckbox.checked = hasAddTextDSA;

            // Sostituisce listener "change" — equivalente $.off("change").on("change", fn)
            if (existingCheckbox._dsaListener) {
                existingCheckbox.removeEventListener("change", existingCheckbox._dsaListener);
            }
            existingCheckbox._dsaListener = function () {
                ListManager._handleDSACheckboxChange(this);
            };
            existingCheckbox.addEventListener("change", existingCheckbox._dsaListener);

            return;
        }

        // Crea nuovo checkbox
        const checkboxContainer = document.createElement("span");
        checkboxContainer.className = "fm-dsa-checkbox-container";
        const checkbox = document.createElement("input");
        checkbox.type = "checkbox";
        checkbox.className = "dsa-checkbox";
        checkboxContainer.appendChild(checkbox);

        // Cerca span.AddTextDSA esistente all'inizio del li (skip text nodes vuoti)
        let existingSpan = null;
        let firstChild = listItem.firstChild;
        while (firstChild) {
            if (firstChild.nodeType === Node.ELEMENT_NODE && firstChild.matches?.("span.AddTextDSA")) {
                existingSpan = firstChild;
                break;
            }
            if (firstChild.nodeType === Node.TEXT_NODE && firstChild.textContent.trim() === "") {
                firstChild = firstChild.nextSibling;
            } else {
                break;
            }
        }

        if (existingSpan) {
            console.log("✅ Trovato span AddTextDSA esistente, creando checkbox checked");
            checkbox.checked = true;
            existingSpan.before(checkboxContainer);
            existingSpan.style.display = "";
            console.log("✅ Span AddTextDSA dovrebbe essere visibile:", existingSpan);
        } else {
            listItem.insertBefore(checkboxContainer, listItem.firstChild);
        }

        checkbox._dsaListener = function () {
            ListManager._handleDSACheckboxChange(this);
        };
        checkbox.addEventListener("change", checkbox._dsaListener);
    },

    _handleDSACheckboxChange: function (checkbox) {
        const li = checkbox.closest("li");
        const container = checkbox.closest(".fm-dsa-checkbox-container");
        const isChecked = checkbox.checked;

        if (isChecked) {
            const nextEl = container ? container.nextElementSibling : null;
            if (!nextEl || !nextEl.matches("span.fm-add-text-dsa")) {
                const dsaSpan = document.createElement("span");
                dsaSpan.className = "fm-add-text-dsa";
                dsaSpan.textContent = "(*F*) ";
                container.after(dsaSpan);
            }
        } else {
            const nextEl = container ? container.nextElementSibling : null;
            if (nextEl && nextEl.matches("span.fm-add-text-dsa")) {
                nextEl.remove();
            }
        }

        ListManager._saveDSACheckboxToServer(li, isChecked);
    },

    /**
     * Salva la modifica checkbox DSA sul server.
     * @param {Element} li
     * @param {boolean} isChecked
     */
    _saveDSACheckboxToServer: function (li, isChecked) {
        if (!li || !li.closest(".DraggableContainer_ver, .fm-draggable-container")) {
            console.log("ℹ️ Non in verifica, salvataggio non necessario");
            return;
        }

        const problem = li.closest(".fm-groupcollex");
        const list = li.closest("ol, ul");

        if (!problem) {
            console.error("❌ _saveDSACheckboxToServer: problema non trovato");
            return;
        }
        if (!list) {
            console.error("❌ _saveDSACheckboxToServer: lista (ol/ul) non trovata");
            return;
        }

        const problemId = problem.id;
        // PathManager.extractPath accetta Element (unwrap interno via Xe).
        const path = PathManager.extractPath(problem);

        const allLists = Array.from(problem.querySelectorAll("ol, ul"));
        const olIndex = allLists.indexOf(list);
        const lis = Array.from(list.querySelectorAll("li"));
        const liIndex = lis.indexOf(li);

        console.log(`💾 Salvataggio checkbox DSA: problema=${problemId}, lista=${olIndex}, li=${liIndex}, checked=${isChecked}`);

        if (!path || !problemId) {
            console.error("❌ Path o problemID mancante");
            return;
        }

        const body = new URLSearchParams({
            type: "li-normal",
            filePath: path,
            problemID: problemId,
            olIndex: String(olIndex),
            liIndex: String(liIndex),
            listTag: (list.tagName || "").toLowerCase(),
            checked: isChecked ? "1" : "0",
        });

        fetch(Endpoints.update.dsa, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
            body: body.toString(),
            credentials: "same-origin",
        })
            .then((r) => r.json())
            .then((response) => {
                if (response.success) {
                    console.log(`✅ Checkbox DSA salvato: ${isChecked ? "checked" : "unchecked"}`);
                } else {
                    console.error(`❌ Errore server:`, response.error);
                }
            })
            .catch((err) => {
                console.error(`❌ Errore salvataggio checkbox DSA:`, err);
            });
    },

    addDSACheckboxesToExistingLists: function () {
        document.querySelectorAll(".DraggableContainer_ver li:not(.fm-li-inline), .fm-draggable-container li:not(.fm-li-inline)")
            .forEach((li) => ListManager._createDSACheckbox(li));
    },

    onListItemCreated: function (listItem) {
        ListManager._createDSACheckbox(listItem);
    },
};

window.FM = window.FM || {};
window.FM.ListManager = ListManager;
window.ListManager    = ListManager;
