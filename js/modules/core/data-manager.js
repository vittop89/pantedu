/**
 * DataManager \u2014 estratto da functions-mod.js (Phase 9d).
 * G26.phase4 \u2014 Migrato a vanilla JS (no pi\u00f9 jQuery).
 *
 * NB: lo state `removedElements` e `removedCollexItems` ora contiene
 * elementi DOM vanilla (Node, non jQuery). Callers che li riusano in
 * codice ancora jQuery devono fare wrap con `$(element)` esplicito.
 * Vedi ui-comp.js per esempio.
 */
import { Endpoints } from "./endpoints.js";
import { Api } from "./api.js";

export const DataManager = {
  _state: {
    removedElements: [],
    removedCollexItems: [],
    detachedElemColl: {},
    currentPageValues: [],
    idsModified: false,
  },

  // Getter pubblici per compatibilità
  get removedElements() {
    return this._state.removedElements;
  },
  get detachedElemColl() {
    return this._state.detachedElemColl;
  },
  get currentPageValues() {
    return this._state.currentPageValues;
  },
  get idsModified() {
    return this._state.idsModified;
  },

  // Setter pubblici
  set idsModified(value) {
    this._state.idsModified = value;
  },

  // Metodi per gestire removedElements
  clearRemovedElements: function () {
    this._state.removedElements = [];
  },

  // Metodi per gestire removedCollexItems
  restoreRemovedCollexItems: function () {
    this._state.removedCollexItems.forEach((item) => {
      const problem = document.getElementById(item.problemId);
      if (!problem) return;
      const collexList = problem.querySelector(".fm-collexercise");
      if (!collexList) return;
      // Inserisci il collex-item nella posizione originale.
      // item.element è vanilla DOM Node (cloned by _filterProblemsByValues).
      const existingItems = collexList.querySelectorAll(":scope > .fm-collection__item");
      if (item.originalIndex >= existingItems.length) {
        collexList.appendChild(item.element);
      } else {
        existingItems[item.originalIndex].before(item.element);
      }
      console.log("✅ Collex-item ripristinato in", item.problemId, "posizione", item.originalIndex);
    });

    this._state.removedCollexItems = [];
  },

  clearRemovedCollexItems: function () {
    this._state.removedCollexItems = [];
  },
  // Metodi per gestire detachedElemColl
  setDetachedElement: function (key, value) {
    this._state.detachedElemColl[key] = value;
  },
  getDetachedElement: function (key) {
    return this._state.detachedElemColl[key];
  },

  _filterProblemsByValues: async function (currentPageValues) {
    // G26 — vanilla iteration sui .fm-groupcollex.
    document.querySelectorAll(".fm-groupcollex").forEach((problem) => {
      const problemId = problem.id || "";
      const isVisible = currentPageValues.some((value) => problemId.includes(value));

      if (!isVisible) {
        // Salva l'elemento rimosso (clone vanilla, preserva structure ma non
        // event handlers nativi — sufficiente per repristino re-render).
        DataManager._state.removedElements.push(problem.cloneNode(/*deep=*/true));
        problem.remove();
        return;
      }

      // Il problem è visibile, ora rimuovi i .fm-collection__item che non corrispondono.
      problem.querySelectorAll(".fm-collection__item").forEach((collexItem) => {
        const classes = collexItem.className || "";
        const hasMatchingValue = currentPageValues.some((value) => {
          const regex = new RegExp(`\\b${value}\\b`);
          return regex.test(classes);
        });

        if (!hasMatchingValue) {
          // Salva il collex-item con riferimento al problem parent prima di rimuoverlo.
          // originalIndex = posizione fra i sibling .fm-collection__item del parent.
          const parentProblem = collexItem.closest(".fm-groupcollex");
          const siblings = parentProblem
            ? [...parentProblem.querySelectorAll(".fm-collection__item")]
            : [];
          const originalIndex = siblings.indexOf(collexItem);
          DataManager._state.removedCollexItems.push({
            element: collexItem.cloneNode(/*deep=*/true),
            problemId: parentProblem?.id || "",
            originalIndex,
          });
          collexItem.remove();
        }
      });

      // Mostra il problem (rimuove display:none se presente).
      problem.style.display = "";
    });
  },
  saveEditorBackup: function () {
    // G26 — vanilla: editor is HTMLElement; content is computed by
    // ContentProcessor (still legacy jQuery internally, OK).
    const editorId = (typeof EditorSystem !== "undefined"
        && typeof EditorSystem.getFocusedEditorId === "function")
        ? EditorSystem.getFocusedEditorId() : null;
    if (!editorId) return;
    const editor = document.getElementById(editorId);
    if (!editor) return;
    // ContentProcessor.getHtmlContent accetta Element (unwrap interno via Xe).
    const content = (typeof ContentProcessor !== "undefined")
        ? ContentProcessor.getHtmlContent(editor)
        : editor.innerHTML;
    const filename = editorId;
    const titoloEl = document.querySelector(".fm-titolo");
    const titlepage = `${(titoloEl?.textContent || "").trim()}.html`;

    // POST x-www-form-urlencoded via fetch (drop-in $.ajax POST replacement).
    const body = new URLSearchParams({ filename, content, titlepage });
    fetch(Endpoints.editor.saveRevision, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
      body: body.toString(),
      credentials: "same-origin",
    })
      .then((res) => res.text())
      .then((response) => console.log("Backup salvato con successo:", response))
      .catch((error) => console.error("Errore nel salvataggio del backup:", error));
  },
  startBackupInterval: function () {
    if (window.backupInterval) {
      clearInterval(window.backupInterval);
    }
    window.backupInterval = setInterval(this.saveEditorBackup, 5000);
  },
  stopBackupInterval: function () {
    if (window.backupInterval) {
      clearInterval(window.backupInterval);
      window.backupInterval = null;
    }
  },
  ContrPagModelli: function (visitedLinks) {
    const currentUrl = window.location.href;
    const baseUrl = window.location.origin;
    const link = document.querySelector("#modelli a");
    const linkUrl = link?.getAttribute("href") || null;
    if (linkUrl && currentUrl === baseUrl + linkUrl) {
      visitedLinks.push(linkUrl);
      return 1;
    }
    return 0;
  },
  /** Persiste la selezione degli origin-filter per la pagina corrente nel
   *  registry per-docente /api/teacher/checked-origins.json. Read-modify-
   *  write: mantiene le entry di altre pagine. */
  saveCheckedValues: async function () {
    // G26 — vanilla: trova option-checkbox checked + leggi data-value su <a> sibling.
    const checkedValues = [];
    document.querySelectorAll(".fm-option-checkbox:checked").forEach((cb) => {
      const nextA = cb.nextElementSibling?.tagName === "A" ? cb.nextElementSibling : null;
      const origin = nextA?.dataset?.value;
      if (origin) checkedValues.push(origin);
    });
    const pageName = window.location.pathname.split("/").pop();
    if (!pageName) return;
    try {
      const current = await Api.getJson("/api/teacher/checked-origins.json");
      const registry = (current && typeof current === "object") ? current : {};
      if (checkedValues.length > 0) {
        registry[pageName] = checkedValues;
      } else {
        delete registry[pageName];
      }
      const resp = await Api.putJson("/api/teacher/checked-origins.json", registry);
      console.log("✅ checked-origins salvati:", pageName, checkedValues, resp);
    } catch (e) {
      console.error("❌ Errore salvataggio checked-origins:", e);
    }
  },
  /** Carica la selezione origin-filter per la pagina corrente dal registry
   *  per-docente e applica il filtro tramite `_filterProblemsByValues`. */
  loadCheckedValues: async function () {
    const pageName = window.location.pathname.split("/").pop();
    if (!pageName) return;
    try {
      const registry = await Api.getJson("/api/teacher/checked-origins.json");
      const checkedValues = Array.isArray(registry?.[pageName]) ? registry[pageName] : [];
      this._state.currentPageValues = checkedValues;
      if (checkedValues.length > 0) {
        const currentPageValues = checkedValues.map((v) => v.trim());
        this._filterProblemsByValues(currentPageValues).catch((err) => {
          console.error("Errore durante il filtro dei problemi:", err);
        });
      }
    } catch (e) {
      console.error("❌ Errore caricamento checked-origins:", e);
    }
  },
  /** Muta il registry sources per-docente (add/edit/remove code).
   *  Sostituisce il legacy update-origins.php che scriveva su un file
   *  globale: ora lavora su /api/teacher/sources.json (scoped teacher). */
  updateOptionOnServer: async function (oldValue, newValue, action) {
    try {
      const body = await Api.getJson("/api/teacher/sources.json");
      const sources = (body && body.sources) ? body.sources : {};

      if (action === "add") {
        if (!newValue || sources[newValue]) return;
        sources[newValue] = { code: newValue };
      } else if (action === "remove") {
        if (!oldValue) return;
        delete sources[oldValue];
      } else if (action === "edit") {
        if (!oldValue || oldValue === newValue) return;
        if (!sources[oldValue]) return;
        const prev = sources[oldValue];
        delete sources[oldValue];
        sources[newValue] = { ...prev, code: newValue };
      } else {
        console.warn("updateOptionOnServer: action sconosciuta", action);
        return;
      }

      const resp = await Api.putJson("/api/teacher/sources.json", { sources });
      console.log("✅ sources.json aggiornato:", action, { oldValue, newValue, count: resp.count });
    } catch (e) {
      console.error("❌ Errore update sources.json:", e);
    }
  },
};

window.FM = window.FM || {};
window.FM.DataManager = DataManager;
window.DataManager    = DataManager;
