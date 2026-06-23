/**
 * EventHendler \u2014 estratto da functions-mod.js (Phase 9g, big module).
 * G26.phase6.3 \u2014 migrato a vanilla JS (no jQuery direct).
 * G26.phase7.1 \u2014 `.sortable()` + `.draggable()` (jQuery UI) sostituiti
 *                con SortableJS (vanilla, ESM, ~30KB).
 */
// G26.phase7.1 — sortable.esm.js vendor copy (PHP carica modules ES sorgente
// direttamente, no Vite bundler runtime → bare specifier "sortablejs" non
// risolve nel browser. Path relativo a vendor/ funziona).
import Sortable from "../../vendor/sortablejs/sortable.esm.js";
import { Endpoints } from "../core/endpoints.js";
import { Api } from "../core/api.js";
import { asElement, trigger } from "../core/dom-utils.js";

/** Encoder form-urlencoded identico al vecchio shim ajaxCompat (oggetti →
 *  JSON.stringify), per conversioni fedeli dei POST verso vanilla fetch. */
function _form(data) {
    const p = new URLSearchParams();
    for (const [k, v] of Object.entries(data)) {
        if (v != null) p.append(k, typeof v === "object" ? JSON.stringify(v) : String(v));
    }
    return p.toString();
}

/** Index del nodo tra i siblings (replica jQuery .index() no-arg). */
function siblingIndex(el) {
    if (!el || !el.parentElement) return -1;
    return Array.from(el.parentElement.children).indexOf(el);
}

/** Mappa nome eventi namespace handler refs per replica .off("ns"). */
const _namespacedHandlers = new Map();
function offNs(target, event, ns) {
    const key = `${event}.${ns}`;
    const targetMap = _namespacedHandlers.get(target);
    if (!targetMap) return;
    const fn = targetMap.get(key);
    if (fn) {
        target.removeEventListener(event, fn);
        targetMap.delete(key);
    }
}
function onNs(target, event, ns, handler) {
    offNs(target, event, ns);
    let targetMap = _namespacedHandlers.get(target);
    if (!targetMap) {
        targetMap = new Map();
        _namespacedHandlers.set(target, targetMap);
    }
    targetMap.set(`${event}.${ns}`, handler);
    target.addEventListener(event, handler);
}

/**
 * G19.4 \u2014 `normalizeVerTitle` era definito in `functions-mod.js` legacy
 * (non incluso nella build moderna) \u2192 ReferenceError quando il tooltip
 * "Ultima versione" si apre. Port locale identico al legacy:
 *   - lowercase + strip punteggiatura/parentesi/apici
 *   - solo `[a-z0-9_\-\u00e0\u00e8\u00e9\u00ec\u00f2\u00f9 ]` survive
 *   - spazi \u2192 `_`, collassa multipli, trim leading/trailing
 *   - prefisso `REC_` se verTitlePrefix == "VERIFICA DI RECUPERO:"
 */
function normalizeVerTitle(verTitleValue, verTitlePrefix) {
    if (!verTitleValue) return "";
    const normalized = verTitleValue
        .toLowerCase()
        .replace(/[,.\(\)\u00b0'"]/g, "")
        .replace(/[^a-z0-9_\-\u00e0\u00e8\u00e9\u00ec\u00f2\u00f9 ]/g, "")
        .trim()
        .replace(/\s+/g, "_")
        .replace(/_+/g, "_")
        .replace(/^_|_$/g, "");
    if (verTitlePrefix === "VERIFICA DI RECUPERO:") {
        return "REC_" + normalized;
    }
    return normalized;
}
// Bridge window per consumer legacy (alcuni script leggono direttamente
// `window.normalizeVerTitle`, replica del comportamento functions-mod.js).
if (typeof window !== "undefined") window.normalizeVerTitle = normalizeVerTitle;

export const EventHendler = {
  handleInsElement: function (element, checkboxClass, elementSelector, sez, newElement) {
    const el = asElement(element);
    if (!el) return;
    const optionpath = PathManager.getLink(el, PlusArgisChecked);
    let path = "";
    extractor = new PathFileVerExtractor(optionpath);
    if (DataManager.ContrPagModelli(visitedLinks) === 0) {
      path = el.closest("[id*=type_]") ? extractor.verPath(optionpath) : optionpath;
    } else {
      path = optionpath;
    }
    fetch(path, { credentials: "same-origin" })
      .then((res) => res.text())
      .then((data) => {
        const parser = new DOMParser();
        const docHTML = parser.parseFromString(data, "text/html");
        const texts_document = docHTML.querySelectorAll(checkboxClass);

        // Calcola l'indice relativo all'elemento corrente tra elementi della stessa classe
        const sameClassEls = document.querySelectorAll(`.checkbox${sez}in`);
        const checkboxIndex = Array.from(sameClassEls).indexOf(el);

        const text = texts_document[checkboxIndex];
        const contenutoCheckbox = text ? text.textContent : "";

        if (el.checked) {
          const contentEl = el.closest(".content");
          const elementTab = contentEl ? contentEl.querySelector(elementSelector) : null;
          if (elementTab) {
            const newHtml = newElement(checkboxIndex, `${sez} - ${contenutoCheckbox}`);
            elementTab.insertAdjacentHTML("beforeend", newHtml);
          }

          const problem = el.closest(".fm-groupcollex");
          const hasVF = problem && problem.querySelector(".VF");
          const firstCheckgiust = problem ? problem.querySelector(".checkgiust") : null;
          if (hasVF && firstCheckgiust?.checked === true) {
            const allRows = problem ? problem.querySelectorAll("table tr") : [];
            const lastRow = allRows[allRows.length - 1];
            if (lastRow && !(lastRow.nextElementSibling?.classList.contains("extraRow"))) {
              const extraRows = '<tr class="extraRow" style="height:15px; border: 1px dashed #000;"><td style="border: none"></td><td style="border: none"></td><td style="border: none"></td></tr><tr class="extraRow" style="height:15px; border: 1px dashed #000;"><td style="border: none"></td><td style="border: none"></td><td style="border: none"></td></tr>';
              lastRow.insertAdjacentHTML("afterend", extraRows);
            }
          }
          UIComp.SetHeightProblem(el);
        } else {
          const problem = el.closest(".fm-groupcollex");
          const righeTab = problem ? problem.querySelectorAll("table tr") : [];
          righeTab.forEach((tr) => {
            if (tr.textContent === `${sez} - ${contenutoCheckbox}`) {
              // Rimuovi fino a 2 sibling .extraRow successivi
              let nextEl = tr.nextElementSibling;
              let extraRemoved = 0;
              while (nextEl && nextEl.classList.contains("extraRow") && extraRemoved < 2) {
                const toRemove = nextEl;
                nextEl = nextEl.nextElementSibling;
                toRemove.remove();
                extraRemoved++;
              }
              tr.remove();
            }
          });
        }
      })
      .catch((error) => console.error("Errore:", error));
  },
  initializeDraggableSortable: function () {
    // G26.phase7.1 — SortableJS replacement per jQuery UI .sortable()/.draggable().
    // Tutti i container .fm-draggable-container / .DraggableContainer_ver diventano
    // mutually-connected (group: "fmProblems") cosicché .fm-groupcollex possa essere
    // trascinato tra contenitori (replica connectToSortable jQuery UI).
    document.querySelectorAll(".fm-draggable-container, .DraggableContainer_ver").forEach((containerEl) => {
      // Idempotente: distrugge la Sortable instance precedente se esiste
      if (containerEl._fmSortable) {
        try { containerEl._fmSortable.destroy(); } catch (_) { /* noop */ }
      }

      containerEl._fmSortable = Sortable.create(containerEl, {
        group: "fmProblems",
        handle: ".moveBtn",
        ghostClass: "sortable-placeholder",
        animation: 150,
        onStart: (evt) => {
          console.log("Sorting started for:", evt.item);
        },
        onEnd: (evt) => {
          console.log("Sorting stopped for:", evt.item);
          // sortedIDs replica jQuery UI sortable("toArray") — array di
          // id degli elementi figli diretti di evt.to (il container destinazione).
          const sortedIDs = Array.from(evt.to.children)
            .map((c) => c.id)
            .filter(Boolean);
          console.log("Sorted IDs: ", sortedIDs);

          const problemEl = evt.to.querySelector(".fm-groupcollex");
          const path = PathManager.extractPath(problemEl);
          console.log("path: ", path);

          fetch(Endpoints.update.file, {
            method: "POST", credentials: "same-origin",
            headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
            body: _form({ filePath: path, sortedIDs }),
          })
            .then(async (res) => { if (!res.ok) throw new Error(`HTTP ${res.status}`); return res.text(); })
            .then((response) => console.log("Order updated successfully on the server:", response))
            .catch((error) => console.error("Error updating order on the server:", error));
        },
      });
    });

    // jQuery UI .draggable({connectToSortable, helper:"clone"}) era usato
    // per drag esterno → drop in sortable. Con SortableJS, lo replichiamo
    // facendo dei .fm-groupcollex clonabili tramite pull:"clone" su un gruppo
    // dedicato. NB: in pratica nei use-case attuali, i .fm-groupcollex sono già
    // dentro un .fm-draggable-container e il drag funziona via il primo
    // Sortable.create. Questo blocco copre soltanto problemi orfani fuori
    // da un container (caso raro, ma replica semantica legacy).
    document.querySelectorAll(".fm-groupcollex").forEach((problemEl) => {
      // Skip se è dentro un .fm-draggable-container (già gestito sopra)
      if (problemEl.closest(".fm-draggable-container, .DraggableContainer_ver")) return;
      // Nessuna draggable instance se non c'è parent appropriato:
      // SortableJS richiede un container; usiamo parentElement.
      const parent = problemEl.parentElement;
      if (!parent || parent._fmSortable) return;
      parent._fmSortable = Sortable.create(parent, {
        group: { name: "fmProblems", pull: "clone" },
        handle: ".moveBtn",
        animation: 150,
        onStart: (evt) => {
          evt.item.style.opacity = "0.5";
          console.log("Dragging started for:", evt.item);
        },
        onEnd: (evt) => {
          evt.item.style.opacity = "";
          evt.item.style.width = "";
          evt.item.style.height = "";
          console.log("Dragging stopped for:", evt.item);
        },
      });
    });
  },
  checkRM_sol: function () {
    onNs(document, "change", "checkRM_sol", (e) => {
      const checkbox = e.target;
      if (!checkbox || !checkbox.matches?.(".checkboxRM")) return;

      const isChecked = checkbox.checked === true;
      checkbox.classList.toggle("solchecked", isChecked);

      const cell = checkbox.closest("td");
      const row = checkbox.closest("tr");
      const table = checkbox.closest("table");
      const problem = checkbox.closest(".fm-groupcollex");

      // rowIndex: ha precedente .row-actions-header? usa siblingIndex, altrimenti +1
      let hasHeaderBefore = false;
      let prev = row?.previousElementSibling;
      while (prev) {
        if (prev.classList.contains("fm-row-actions-header")) { hasHeaderBefore = true; break; }
        prev = prev.previousElementSibling;
      }
      const rowIndex = hasHeaderBefore ? siblingIndex(row) : siblingIndex(row) + 1;
      const colIndex = siblingIndex(cell);

      const container = problem ? problem.closest(".fm-draggable-container, .DraggableContainer_ver") : null;
      const tablesInContainer = container ? Array.from(container.querySelectorAll("table")) : [];
      const tableIndex = tablesInContainer.indexOf(table);

      const path = PathManager.extractPath(problem);

      const update = {
        tableIndex,
        rowIndex,
        colIndex,
        isChecked,
        classInscheckbox: false,
      };

      TableManager.addCheckboxUpdate(update);
      TableManager.debounceBatchSave(path, "no-user");
    });
  },
  UploadDynamicId: function (dynamicId, index) {
    const root = asElement(dynamicId);
    if (!root) return;
    const self = this;
    root.querySelectorAll("[id]").forEach((node) => {
      // 🔑 NON modificare gli ID all'interno degli SVG (clipPath, linearGradient, ecc.)
      const isInsideSvg = node.closest("svg") !== null;
      if (!isInsideSvg && !node.id.includes("_add")) {
        node.id = `${node.id}_add${index}`;
      }
      LatexRender.manipulateTikzScript();
      self.checkRM_sol();
    });
  },
  debounceOriginTimeouts: {},

  /** Batch update dell'origin di più .fm-collection__item. Risolve contractId +
   *  itemRef per ogni elemento tramite `.fm-contract-wrap[data-id]` e
   *  `.fm-collection__item[data-id]`, muta classi DOM immediate per feedback visivo,
   *  persiste via PATCH /api/teacher/content/{id}/quesito/{itemRef}/patch
   *  (con If-Match per optimistic locking). Debounce 300ms per batch id. */
  aggiornaMultipleOrigineCollex: function ($collexItems, selectedValue) {
    const batchId = "batch_" + Date.now();
    if (this.debounceOriginTimeouts[batchId]) {
      clearTimeout(this.debounceOriginTimeouts[batchId]);
    }

    this.debounceOriginTimeouts[batchId] = setTimeout(async () => {
      console.log("Batch aggiornamento origini avviato. Elementi:", $collexItems.length);

      let origins = [];
      try {
        origins = await window.FM.memoFetchJson("/api/teacher/origins.json");
      } catch (e) {
        console.warn("⚠️ Impossibile caricare origins:", e);
      }

      const updates = [];
      $collexItems.forEach(($collexItem) => {
        const el = asElement($collexItem);
        if (!el) return;
        const wrap = el.closest(".fm-contract-wrap");
        if (!wrap) {
          console.warn("⚠️ collex-item senza fm-contract-wrap, skip:", el);
          return;
        }
        const contractId = wrap.dataset.id;
        if (!/^\d+$/.test(contractId || "")) return;
        const itemRef = el.dataset.id || "";
        if (!itemRef) {
          console.warn("⚠️ collex-item senza data-id, skip:", el);
          return;
        }

        // Feedback visivo immediato sulle classi CSS
        origins.forEach((origin) => el.classList.remove(origin));
        el.classList.remove("origine");
        el.classList.add(selectedValue);
        const originSelect = el.querySelector(".origin");
        if (originSelect) originSelect.value = selectedValue;

        updates.push({
          contractId,
          itemRef,
          version: parseInt(wrap.dataset.version || "0", 10) || 0,
        });
      });

      if (updates.length === 0) {
        delete EventHendler.debounceOriginTimeouts[batchId];
        return;
      }

      // Persist parallelo. Raggruppa per contractId per ridurre conflict:
      // ogni PATCH bumpa la version del contract, quindi chiamate seriali
      // sullo stesso contract.
      const byContract = updates.reduce((acc, u) => {
        (acc[u.contractId] ||= []).push(u);
        return acc;
      }, {});

      const results = await Promise.allSettled(
        Object.entries(byContract).map(async ([cid, arr]) => {
          let version = arr[0].version;
          for (const u of arr) {
            const url = `/api/teacher/content/${cid}/quesito/${encodeURIComponent(u.itemRef)}/patch`;
            try {
              const resp = await Api.postJson(url + (version ? `?_version=${version}` : ""),
                { origin: selectedValue, _version: version });
              if (typeof resp.version === "number") {
                version = resp.version;
                const w = document.querySelector(`.fm-contract-wrap[data-id="${cid}"]`);
                if (w) w.dataset.version = String(version);
              }
            } catch (e) {
              console.error(`❌ patch origin fallita contract=${cid} item=${u.itemRef}:`, e);
              throw e;
            }
          }
        })
      );

      const ko = results.filter((r) => r.status === "rejected").length;
      if (ko > 0) {
        console.warn(`Batch origine: ${ko}/${results.length} contract(s) falliti`);
      } else {
        console.log(`✅ Batch origine completato: ${updates.length} item(s) su ${results.length} contract(s)`);
      }

      delete EventHendler.debounceOriginTimeouts[batchId];
    }, 300);
  },

  /**
   * Aggiorna i colori di più collex-item (solo DOM, senza salvataggio server).
   * I colori sono gestiti automaticamente da _enforceTopicColorCycle.
   */
  aggiornaMultipleColorCollex: function ($collexItems, selectedColor) {
    console.log("Aggiornamento colori DOM-only. Elementi da aggiornare:", $collexItems.length);

    $collexItems.forEach(function ($collexItem, itemIndex) {
      // Aggiorna solo gli stili nel DOM per feedback visivo
      UIComp._applicaStiliColore($collexItem, selectedColor);
    });

    console.log("Aggiornamento colori DOM completato (nessun salvataggio server)");
  },

  // aggiornaOrigineCollex: function ($collexItem, selectedValue) {
  //     const itemId = $collexItem.attr('id') || $collexItem.index();
  //     const index = PathManager.globalTOrelativeIndex('.fm-collection__item', $collexItem.get(0), '[class*=fm-draggable-container]');
  //     const filePath = PathManager.extractPath($collexItem);

  //     if (this.debounceOriginTimeouts[itemId]) {
  //         clearTimeout(this.debounceOriginTimeouts[itemId]);
  //     }
  //     this.debounceOriginTimeouts[itemId] = setTimeout(function () {
  //         $.getJSON('/origins/origins.json', function (origins) {
  //             origins.forEach(function (origin) {
  //                 $collexItem.removeClass(origin);
  //             });
  //             $collexItem.removeClass('origine');
  //             $collexItem.addClass(selectedValue);
  //             $.ajax({
  //                 url: '/origins/change-origin_quesito.php',
  //                 method: 'POST',
  //                 data: {
  //                     index: index,
  //                     selectedOrigin: selectedValue,
  //                     filePath: filePath
  //                 },
  //                 success: function (response) {
  //                     $collexItem.find('.origin').val(selectedValue);
  //                     console.log('Classe aggiornata su .fm-collection__item:', response);
  //                 },
  //                 error: function (jqXHR, textStatus, errorThrown) {
  //                     console.error('Errore nell\'aggiornamento della classe:', textStatus, errorThrown);
  //                 }
  //             });
  //         });
  //         delete EventHendler.debounceOriginTimeouts[itemId];
  //     }, 300);
  // },

  /**
   * Aggiorna i numeri di posizione per tutti i .fm-collection__item all'interno di un .fm-groupcollex
   * @param {jQuery} $problem - L'elemento .fm-groupcollex contenitore
   */
  updateMovePositions: function (problem) {
    const problemEl = asElement(problem);
    if (!problemEl) return;
    problemEl.querySelectorAll(".fm-collection__item").forEach((item, index) => {
      const position = index + 1; // Posizione 1-based
      const movePos = item.querySelector(".fm-move-position");
      if (movePos) movePos.value = position;

      // Assegna un data-original-index se non esiste già
      if (!item.getAttribute("data-original-index")) {
        item.setAttribute("data-original-index", String(index));
      }
    });
  },

  moveCollexItemUp: function (collexItem) {
    const collexEl = asElement(collexItem);
    if (!collexEl) return;
    const problemEl = collexEl.closest(".fm-groupcollex");
    this.normalizeOriginalIndices(problemEl);

    let prev = collexEl.previousElementSibling;
    while (prev && !prev.classList.contains("fm-collection__item")) prev = prev.previousElementSibling;
    if (prev) {
      prev.before(collexEl);
      this.updateMovePositions(problemEl);
      this.savePositionChange(problemEl);
    }
  },

  moveCollexItemDown: function (collexItem) {
    const collexEl = asElement(collexItem);
    if (!collexEl) return;
    const problemEl = collexEl.closest(".fm-groupcollex");
    this.normalizeOriginalIndices(problemEl);

    let next = collexEl.nextElementSibling;
    while (next && !next.classList.contains("fm-collection__item")) next = next.nextElementSibling;
    if (next) {
      next.after(collexEl);
      this.updateMovePositions(problemEl);
      this.savePositionChange(problemEl);
    }
  },

  moveCollexItemToPosition: function (collexItem, targetPosition) {
    const collexEl = asElement(collexItem);
    if (!collexEl) return;
    const problemEl = collexEl.closest(".fm-groupcollex");
    this.normalizeOriginalIndices(problemEl);

    const allItems = problemEl ? Array.from(problemEl.querySelectorAll(".fm-collection__item")) : [];
    const totalItems = allItems.length;

    if (targetPosition < 1 || targetPosition > totalItems) {
      const currentPosition = allItems.indexOf(collexEl) + 1;
      const movePos = collexEl.querySelector(".fm-move-position");
      if (movePos) movePos.value = currentPosition;
      return;
    }

    const currentPosition = allItems.indexOf(collexEl) + 1;
    if (currentPosition === targetPosition) return;

    if (targetPosition === 1) {
      allItems[0]?.before(collexEl);
    } else if (targetPosition === totalItems) {
      allItems[allItems.length - 1]?.after(collexEl);
    } else {
      const targetItem = allItems[targetPosition - 1];
      if (currentPosition < targetPosition) {
        targetItem?.after(collexEl);
      } else {
        targetItem?.before(collexEl);
      }
    }

    this.updateMovePositions(problemEl);
    this.savePositionChange(problemEl);
  },

  /**
   * Normalizza gli indici data-original-index se ci sono duplicati o valori mancanti
   * @param {jQuery} $problem - L'elemento .fm-groupcollex contenitore
   * @returns {boolean} - True se è stata eseguita una normalizzazione
   */
  normalizeOriginalIndices: function (problem) {
    const problemEl = asElement(problem);
    if (!problemEl) return false;
    const indices = [];
    const itemsWithoutIndex = [];

    problemEl.querySelectorAll(".fm-collection__item").forEach((item, currentIndex) => {
      const originalIndex = item.getAttribute("data-original-index");

      if (originalIndex !== undefined && originalIndex !== null && originalIndex !== "") {
        indices.push({ value: parseInt(originalIndex), position: currentIndex });
      } else {
        itemsWithoutIndex.push({ item, position: currentIndex });
      }
    });

    const indexValues = indices.map((i) => i.value);
    const hasDuplicates = indexValues.length !== new Set(indexValues).size;
    const hasMissingIndices = itemsWithoutIndex.length > 0;

    if (hasDuplicates || hasMissingIndices) {
      console.warn("⚠️ Rilevati duplicati o indici mancanti in data-original-index");
      console.log("📋 Indici attuali:", indexValues);
      console.log("❌ Items senza indice:", itemsWithoutIndex.length);

      problemEl.querySelectorAll(".fm-collection__item").forEach((item, newIndex) => {
        item.setAttribute("data-original-index", String(newIndex));
        console.log(`🔧 Normalizzato: posizione ${newIndex} → data-original-index="${newIndex}"`);
      });

      console.log("✅ Normalizzazione completata");
      return true;
    }

    return false;
  },

  /**
   * Salva il nuovo ordine degli elementi sul server con debounce
   * @param {jQuery} $problem - L'elemento .fm-groupcollex contenitore
   */
  savePositionChange: function (problem) {
    const problemEl = asElement(problem);
    if (!problemEl) return;

    if (!this._moveDebounceTimeouts) this._moveDebounceTimeouts = {};

    const path = PathManager.extractPath(problemEl);
    const problemID = problemEl.id;
    const debounceKey = problemID || path;
    const self = this;

    console.log("💾 savePositionChange chiamato:", { path, problemID, debounceKey });

    if (this._moveDebounceTimeouts[debounceKey]) {
      clearTimeout(this._moveDebounceTimeouts[debounceKey]);
      console.log("⏱️ Timeout precedente CANCELLATO per", debounceKey);
    }

    this._moveDebounceTimeouts[debounceKey] = setTimeout(() => {
      console.log("⏰ Timeout SCATTATO per", debounceKey, "- raccolgo indici attuali");

      const sortedIndices = [];
      problemEl.querySelectorAll(".fm-collection__item").forEach((item, currentIndex) => {
        const originalIndex = item.getAttribute("data-original-index");
        if (originalIndex !== undefined && originalIndex !== null && originalIndex !== "") {
          sortedIndices.push(parseInt(originalIndex));
          console.log(`  Posizione ${currentIndex}: data-original-index = ${originalIndex}`);
        } else {
          console.warn(`⚠️ Collex-item in posizione ${currentIndex} senza data-original-index, uso ${currentIndex}`);
          sortedIndices.push(currentIndex);
        }
      });

      if (sortedIndices.length === 0) {
        console.warn("⚠️ sortedIndices vuoto, skip salvataggio");
        delete self._moveDebounceTimeouts[debounceKey];
        return;
      }

      console.log("📊 Invio richiesta AJAX con:", {
        filePath: path, problemID, sortedIndices, action: "reorder_collex",
      });

      fetch(Endpoints.update.file, {
        method: "POST", credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body: _form({ filePath: path, problemID, sortedIndices: JSON.stringify(sortedIndices), action: "reorder_collex" }),
      })
        .then(async (res) => { if (!res.ok) throw new Error(`HTTP ${res.status}`); return res.text(); })
        .then((response) => {
          console.log("✅ Ordine salvato con successo:", response);
          problemEl.querySelectorAll(".fm-collection__item").forEach((item, newIndex) => {
            item.setAttribute("data-original-index", String(newIndex));
            console.log(`🔄 Aggiornato data-original-index=${newIndex} nel DOM`);
          });
        })
        .catch((error) => console.error("❌ Errore nel salvataggio ordine:", error));

      delete self._moveDebounceTimeouts[debounceKey];
    }, 500);

    console.log("⏱️ Nuovo timeout IMPOSTATO per", debounceKey);
  },

  /**
   * Inizializza gli event handler per i controlli di movimento
   */
  initializeMoveControls: function () {
    const self = this;

    document.querySelectorAll(".fm-groupcollex").forEach((problem) => {
      self.updateMovePositions(problem);
    });

    onNs(document, "click", "moveUp", (e) => {
      const target = e.target.closest?.(".move-up-btn");
      if (!target) return;
      e.preventDefault();
      const collexItem = target.closest(".fm-collection__item");
      self.moveCollexItemUp(collexItem);
    });

    onNs(document, "click", "moveDown", (e) => {
      const target = e.target.closest?.(".move-down-btn");
      if (!target) return;
      e.preventDefault();
      const collexItem = target.closest(".fm-collection__item");
      self.moveCollexItemDown(collexItem);
    });

    const handleMovePos = (e) => {
      const target = e.target;
      if (!target?.matches?.(".move-position")) return;
      const targetPosition = parseInt(target.value);
      if (!isNaN(targetPosition) && targetPosition > 0) {
        const collexItem = target.closest(".fm-collection__item");
        self.moveCollexItemToPosition(collexItem, targetPosition);
      }
    };
    onNs(document, "change", "movePos", handleMovePos);
    onNs(document, "blur", "movePos", handleMovePos);

    onNs(document, "keypress", "movePos", (e) => {
      const target = e.target;
      if (!target?.matches?.(".move-position")) return;
      if (e.which === 13 || e.keyCode === 13) {
        e.preventDefault();
        trigger(target, "change");
        target.blur();
      }
    });
  },

  /**
   * Riordina i problemi in un singolo DraggableContainer_ver in base ai valori defPositionImp
   * @param {jQuery} $container - Il contenitore DraggableContainer_ver
   * @param {boolean} withMathJax - Se eseguire il re-rendering di MathJax (default: true)
   */
  reorderSingleContainer: function (container, withMathJax = true) {
    const containerEl = asElement(container);
    if (!containerEl) {
      console.warn("⚠️ Container non valido per il riordinamento");
      return;
    }

    const parentEl = containerEl.parentElement;
    const _parentId = parentEl?.id || parentEl?.className || "contenitore senza id";

    // Ottieni tutti i problemi nel contenitore (solo top-level diretti)
    const problems = Array.from(containerEl.querySelectorAll(".fm-groupcollex"));

    // Ordina i problemi in base al valore di defPositionImp
    problems.sort((a, b) => {
      const valA = (a.querySelector(".fm-def-position-imp")?.value || "").trim();
      const valB = (b.querySelector(".fm-def-position-imp")?.value || "").trim();
      if (valA !== "" && valB !== "") return parseInt(valA) - parseInt(valB);
      if (valA === "") return 1;
      if (valB === "") return -1;
      return 0;
    });

    // Riappendi i problemi ordinati al contenitore (appendChild sposta)
    problems.forEach((p) => containerEl.appendChild(p));

    // Re-processa MathJax dopo riordinamento se richiesto
    // if (withMathJax) {
    //   $problems.each(function() {
    //     if (typeof ContentProcessor !== 'undefined') {
    //       ContentProcessor.processmathjaxElements($(this), 0);
    //     }
    //   });
    //   if (typeof MathJax !== 'undefined' && MathJax.typesetPromise) {
    //     MathJax.typesetPromise().then(() => {
    //       console.log('✅ MathJax ri-renderizzato dopo riordinamento');
    //     });
    //   }
    // }
  },

  /**
   * Riordina tutti i DraggableContainer_ver nella pagina
   * Utile dopo il caricamento dello stato da sessionStorage
   */
  reorderAllContainers: function () {
    let reorderedCount = 0;
    document.querySelectorAll(".DraggableContainer_ver").forEach((container) => {
      const problems = container.querySelectorAll(".fm-groupcollex");
      const defPosInputs = Array.from(problems).flatMap((p) =>
        Array.from(p.querySelectorAll(".fm-def-position-imp")),
      );
      const hasDefPosition = defPosInputs.some((inp) => (inp.value || "").trim() !== "");

      if (hasDefPosition) {
        EventHendler.reorderSingleContainer(container, false);
        reorderedCount++;
      }
    });

    // Esegui MathJax una sola volta alla fine per tutti i container
    // if (reorderedCount > 0 && typeof MathJax !== 'undefined' && MathJax.typesetPromise) {
    //   MathJax.typesetPromise().then(() => {
    //     console.log('✅ MathJax ri-renderizzato dopo riordinamento iniziale');
    //   });
    // }
  },

  /**
   * Inizializza la gestione degli input defPositionImp per il riordinamento dei problemi
   * Permette solo numeri e riordina i problemi nel DraggableContainer_ver
   */
  initializeDefPositionImpHandlers: function () {
    const self = this;

    onNs(document, "input", "defPosInput", (event) => {
      const target = event.target;
      if (!target?.matches?.(".defPositionImp")) return;
      target.value = target.value.replace(/[^0-9]/g, "");
    });

    onNs(document, "blur", "defPosBlur", (event) => {
      const target = event.target;
      if (!target?.matches?.(".defPositionImp")) return;
      const problemEl = target.closest(".fm-groupcollex");
      const containerEl = problemEl ? problemEl.closest(".DraggableContainer_ver") : null;
      if (containerEl) {
        const inputVal = (target.value || "").trim();
        console.log("🔍 defPositionImp blur, valore:", inputVal, "problem:", problemEl?.id);
        self.reorderSingleContainer(containerEl);
      }
    });

    onNs(document, "keydown", "defPosKey", (event) => {
      const target = event.target;
      if (!target?.matches?.(".defPositionImp")) return;
      if (event.keyCode === 13) {
        event.preventDefault();
        const problemEl = target.closest(".fm-groupcollex");
        const containerEl = problemEl ? problemEl.closest(".DraggableContainer_ver") : null;
        if (containerEl) {
          const inputVal = (target.value || "").trim();
          console.log("🔍 defPositionImp Enter, valore:", inputVal, "problem:", problemEl?.id);
          self.reorderSingleContainer(containerEl);
        }
        target.blur();
      }
    });
  },

  // Inizializza l'autocomplete per il campo verTitle
  initializeVerTitleAutocomplete() {
    const verTitle = document.getElementById("verTitle");
    if (!verTitle) return;

    // ADR-023 Fase 2: CSS in css/modules/_vertitle-autocomplete.css (@layer).
    if (!document.getElementById("verTitle-suggestions")) {
      verTitle.insertAdjacentHTML("afterend", '<ul id="verTitle-suggestions" class="autocomplete-dropdown"></ul>');
    }

    const suggestions = document.getElementById("verTitle-suggestions");
    let currentFolders = [];

    function fetchFolders() {
      const selectedIIS = sessionStorage.getItem("selectedIIS");
      const selectedCLS = sessionStorage.getItem("selectedCLS");
      const selectedMATER = sessionStorage.getItem("selectedMATER");
      const optsel = selectedIIS + selectedCLS;

      const classParams = { selectedIIS, selectedCLS, selectedMATER, optsel };

      // NB: lo shim ajaxCompat NON inviava i `data` nelle GET → comportamento
      // wire preservato (istituto/classe/materia non aggiunti alla query).
      fetch(Endpoints.verifiche.listFolders, { credentials: "same-origin" })
        .then((res) => { if (!res.ok) throw new Error(`HTTP ${res.status}`); return res.json(); })
        .then((response) => {
          if (response.folders && Array.isArray(response.folders)) {
            currentFolders = response.folders;
          } else if (Array.isArray(response)) {
            currentFolders = response;
          } else {
            currentFolders = [];
          }
        })
        .catch(() => { currentFolders = []; });
    }

    function showSuggestions(input) {
      const cleanedInput = input.replace(/^verifica:\s*/i, "").toLowerCase();
      suggestions.replaceChildren();

      if (!cleanedInput || currentFolders.length === 0) {
        suggestions.style.display = "none";
        return;
      }

      const normalizedInput = cleanedInput
        .replace(/[,\.\(\)°'"]/g, "")
        .replace(/[^a-z0-9àèéìòù\s_]/g, "")
        .trim()
        .replace(/[\s_]+/g, "_");

      const filtered = currentFolders.filter((folder) => {
        const normalizedFolder = folder
          .toLowerCase()
          .replace(/[^a-z0-9àèéìòù_]/g, "")
          .replace(/_+/g, "_");
        return normalizedFolder.includes(normalizedInput) || normalizedInput.includes(normalizedFolder);
      });

      if (filtered.length === 0) {
        suggestions.style.display = "none";
        return;
      }

      filtered.forEach((folder) => {
        const li = document.createElement("li");
        li.textContent = folder;
        li.addEventListener("click", () => {
          let folderWithSpaces = folder.replace(/_/g, " ");
          const verTitlePrefixEl = document.getElementById("verTitlePrefix");
          if (folderWithSpaces.startsWith("REC ")) {
            if (verTitlePrefixEl) verTitlePrefixEl.value = "VERIFICA DI RECUPERO:";
            folderWithSpaces = folderWithSpaces.substring(4);
          } else if (verTitlePrefixEl) {
            verTitlePrefixEl.value = "VERIFICA:";
          }
          verTitle.value = folderWithSpaces;
          suggestions.style.display = "none";
          trigger(verTitle, "change");
        });
        suggestions.appendChild(li);
      });

      suggestions.style.display = "";
    }

    onNs(verTitle, "input", "autocomplete", () => showSuggestions(verTitle.value));
    onNs(verTitle, "focus", "autocomplete", () => {
      fetchFolders();
      if (verTitle.value) showSuggestions(verTitle.value);
    });

    onNs(document, "click", "verTitleAutocomplete", (e) => {
      const insideVerTitle = e.target.closest("#verTitle, #verTitle-suggestions");
      if (!insideVerTitle) suggestions.style.display = "none";
    });

    onNs(verTitle, "keydown", "autocomplete", (e) => {
      const items = Array.from(suggestions.querySelectorAll("li"));
      const selected = items.find((li) => li.classList.contains("selected"));

      if (e.which === 40 || e.keyCode === 40) {
        e.preventDefault();
        if (!selected) {
          items[0]?.classList.add("selected");
        } else {
          selected.classList.remove("selected");
          const idx = items.indexOf(selected);
          const nextEl = items[idx + 1] || items[0];
          nextEl?.classList.add("selected");
        }
      } else if (e.which === 38 || e.keyCode === 38) {
        e.preventDefault();
        if (!selected) {
          items[items.length - 1]?.classList.add("selected");
        } else {
          selected.classList.remove("selected");
          const idx = items.indexOf(selected);
          const prevEl = items[idx - 1] || items[items.length - 1];
          prevEl?.classList.add("selected");
        }
      } else if (e.which === 13 || e.keyCode === 13) {
        if (selected) {
          e.preventDefault();
          selected.click();
        }
      } else if (e.which === 27 || e.keyCode === 27) {
        suggestions.style.display = "none";
      }
    });

    // Evidenzia l'item al passaggio del mouse (mouseover bubbla, mouseenter no)
    onNs(document, "mouseover", "verTitleHover", (e) => {
      const li = e.target.closest("#verTitle-suggestions li");
      if (!li) return;
      li.parentElement.querySelectorAll("li").forEach((s) => s.classList.remove("selected"));
      li.classList.add("selected");
    });
  },

  // Inizializza il tooltip per il campo versione
  initializeVersioneTooltip() {
    const versione = document.getElementById("versione");
    if (!versione) return;

    if (!document.getElementById("versione-tooltip")) {
      document.body.insertAdjacentHTML("beforeend", `
                <div id="versione-tooltip">
                    <div class="fm-ultima-versione">
                        <span class="loading">Caricamento ultima versione…</span>
                    </div>
                    <h4>Clicca un esempio per inserire (auto-incrementa numero):</h4>
                    <div class="fm-versione-cat">Verifica classica</div>
                    <div class="fm-esempio" data-version-prefix="v" data-version-suffix="">
                        <strong>v<span class="version-number">1</span></strong> — versione N (sola)
                    </div>
                    <div class="fm-esempio" data-version-prefix="v" data-version-suffix="_p1">
                        <strong>v<span class="version-number">1</span>_p1</strong> — versione N parte 1
                    </div>
                    <div class="fm-esempio" data-version-prefix="v" data-version-suffix="_p2">
                        <strong>v<span class="version-number">1</span>_p2</strong> — versione N parte 2
                    </div>
                    <div class="fm-versione-cat">Verifica di recupero</div>
                    <div class="fm-esempio" data-version-prefix="r" data-version-suffix="">
                        <strong>r<span class="version-number">1</span></strong> — versione N (sola)
                    </div>
                    <div class="fm-esempio" data-version-prefix="r" data-version-suffix="_p1">
                        <strong>r<span class="version-number">1</span>_p1</strong> — versione N parte 1
                    </div>
                    <div class="fm-esempio" data-version-prefix="r" data-version-suffix="_p2">
                        <strong>r<span class="version-number">1</span>_p2</strong> — versione N parte 2
                    </div>
                    <div class="fm-versione-cat">Verifica di prova</div>
                    <div class="fm-esempio" data-version-prefix="canc" data-version-suffix="">
                        <strong>canc</strong> — versione da cancellare
                    </div>
                </div>
            `);
    }

    const tooltip = document.getElementById("versione-tooltip");
    let tooltipShouldStayOpen = false;
    let currentMaxByPrefix = { v: 0, r: 0 };

    onNs(tooltip, "click", "versioneEsempio", (e) => {
      const item = e.target.closest(".fm-esempio[data-version-prefix]");
      if (!item) return;
      e.preventDefault();
      e.stopPropagation();
      const prefix = item.dataset.versionPrefix;
      const suffix = item.dataset.versionSuffix || "";
      let versionString;
      if (prefix === "canc") {
        versionString = "canc";
      } else {
        const nextN = (currentMaxByPrefix[prefix] || 0) + 1;
        versionString = `${prefix}${nextN}${suffix}`;
      }
      versione.value = versionString;
      trigger(versione, "change");
      tooltipShouldStayOpen = false;
      tooltip.style.display = "none";
      document.getElementById("infoVer")?.classList.remove("fm-tooltip-expanded");
      versione.focus();
    });

    function updateVersionNumbers(maxByPrefix) {
      currentMaxByPrefix = { v: maxByPrefix.v || 0, r: maxByPrefix.r || 0 };
      const vNum = tooltip.querySelector('.fm-esempio[data-version-prefix="v"] .version-number');
      const rNum = tooltip.querySelector('.fm-esempio[data-version-prefix="r"] .version-number');
      if (vNum) vNum.textContent = String(currentMaxByPrefix.v + 1);
      if (rNum) rNum.textContent = String(currentMaxByPrefix.r + 1);
    }

    function fetchUltimaVersione() {
      const selectedMATER = sessionStorage.getItem("selectedMATER")
        || document.getElementById("sel-mater")?.value || window.FM?.Curriculum?.firstCode("materie") || "";
      const verTitleValue = document.getElementById("verTitle")?.value || "";
      const verTitlePrefix = document.getElementById("verTitlePrefix")?.value || "";
      const verTitleNormalized = (typeof normalizeVerTitle === "function")
        ? normalizeVerTitle(verTitleValue, verTitlePrefix)
        : verTitleValue.trim();

      const ultimaEl = tooltip.querySelector(".fm-ultima-versione");

      if (!verTitleNormalized) {
        if (ultimaEl) ultimaEl.innerHTML = '<span class="loading">Inserisci prima il titolo verifica</span>';
        updateVersionNumbers({ v: 0, r: 0 });
        return;
      }
      const baseTitle = verTitleNormalized.toLowerCase();

      fetch(`/api/verifica/list?materia=${encodeURIComponent(selectedMATER)}`, { credentials: "same-origin" })
        .then((res) => { if (!res.ok) throw new Error(`HTTP ${res.status}`); return res.json(); })
        .then((response) => {
          const items = response?.items || [];
          const stripVariant = (t) => String(t || "")
            .replace(/\s*[—-]\s*[AB]_(SOL|NOR|DSA|DIS)\s*$/u, "").trim();
          const matching = items.filter((it) =>
            stripVariant(it.title).toLowerCase() === baseTitle);
          const labels = new Set();
          matching.forEach((it) => { if (it.version_label) labels.add(it.version_label); });

          const maxByPrefix = { v: 0, r: 0 };
          labels.forEach((lbl) => {
            const m = lbl.match(/^([vr])(\d+)/i);
            if (m) {
              const p = m[1].toLowerCase();
              const n = parseInt(m[2], 10);
              if (n > (maxByPrefix[p] || 0)) maxByPrefix[p] = n;
            }
          });
          if (!ultimaEl) return;
          if (labels.size === 0) {
            ultimaEl.innerHTML = `Nessuna versione esistente per "<strong>${verTitleNormalized}</strong>". Prossime: <strong style="color:#4CAF50">v1 / r1</strong>`;
          } else {
            ultimaEl.innerHTML = `Versioni esistenti per "<strong>${verTitleNormalized}</strong>": ${[...labels].join(", ")}<br>Prossime: <strong style="color:#4CAF50">v${maxByPrefix.v + 1}</strong> · <strong style="color:#4CAF50">r${maxByPrefix.r + 1}</strong>`;
          }
          updateVersionNumbers(maxByPrefix);
        })
        .catch(() => {
          if (ultimaEl) ultimaEl.innerHTML = '<span class="loading">Errore nel recupero versioni</span>';
        });
    }

    const isTooltipVisible = () => getComputedStyle(tooltip).display !== "none";
    const isTooltipHovered = () => tooltip.matches(":hover");

    onNs(versione, "click", "tooltip", (e) => {
      e.stopPropagation();
      if (isTooltipVisible()) return;

      const rect = versione.getBoundingClientRect();
      tooltipShouldStayOpen = true;
      tooltip.style.top = `${rect.bottom + 8}px`;
      tooltip.style.left = `${rect.left}px`;
      tooltip.style.display = "";

      document.getElementById("infoVer")?.classList.add("fm-tooltip-expanded");
      fetchUltimaVersione();
    });

    onNs(versione, "blur", "tooltip", () => {
      setTimeout(() => {
        if (!tooltipShouldStayOpen && !isTooltipHovered()) {
          tooltip.style.display = "none";
          document.getElementById("infoVer")?.classList.remove("fm-tooltip-expanded");
        }
        tooltipShouldStayOpen = false;
      }, 150);
    });

    onNs(tooltip, "mouseenter", "tooltip", () => {
      tooltipShouldStayOpen = true;
      document.getElementById("infoVer")?.classList.add("fm-tooltip-expanded");
    });

    onNs(tooltip, "mouseleave", "tooltip", () => {
      tooltipShouldStayOpen = false;
      setTimeout(() => {
        if (document.activeElement !== versione && !tooltipShouldStayOpen) {
          tooltip.style.display = "none";
          document.getElementById("infoVer")?.classList.remove("fm-tooltip-expanded");
        }
      }, 200);
    });

    onNs(tooltip, "click", "tooltipPrevent", (e) => {
      e.stopPropagation();
      tooltipShouldStayOpen = true;
    });

    onNs(document, "click", "versioneTooltip", (e) => {
      if (!e.target.closest("#versione, #versione-tooltip")) {
        tooltipShouldStayOpen = false;
        tooltip.style.display = "none";
        document.getElementById("infoVer")?.classList.remove("fm-tooltip-expanded");
      }
    });

    const verTitleEl = document.getElementById("verTitle");
    if (verTitleEl) {
      onNs(verTitleEl, "change", "versione", () => {
        if (isTooltipVisible()) fetchUltimaVersione();
      });
    }
  },
};

window.FM = window.FM || {};
window.FM.EventHendler = EventHendler;
window.EventHendler    = EventHendler;
