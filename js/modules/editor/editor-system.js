/**
 * EditorSystem \u2014 estratto da functions-mod.js (Phase 9g, big module).
 * G26.phase6.6 \u2014 migrato a vanilla JS (no jQuery direct).
 *
 * Boundary pattern: tutte le API accettano sia Element che jQuery wrapper
 * via asElement() helper. Le funzioni interne lavorano con Element.
 */
import { Endpoints } from "../core/endpoints.js";
import { asElement, isVisible, trigger } from "../core/dom-utils.js";

/**
 * Sanitizza HTML proveniente dal server prima di inserirlo nell'editor.
 * DOMPurify non è una dipendenza del progetto; usiamo l'approccio strutturale:
 *  1. <template> per il parsing (i browser NON eseguono <script> nel template).
 *  2. Rimozione di <script> e <style> che non appartengono all'editor.
 *  3. Stripping degli attributi handler inline (on*).
 * Questo non è equivalente a DOMPurify ma neutralizza i vettori più comuni
 * (stored XSS via backup server-side). Se in futuro DOMPurify viene aggiunto
 * come dipendenza, sostituire questa funzione con DOMPurify.sanitize(html, cfg).
 */
function sanitizeEditorHtml(html) {
    const tmpl = document.createElement("template");
    tmpl.innerHTML = String(html || "");
    const frag = tmpl.content;
    // Rimuovi tag eseguibili
    frag.querySelectorAll("script, style, iframe, object, embed, form").forEach((el) => el.remove());
    // Rimuovi attributi handler inline (onclick, onerror, onload, ecc.)
    frag.querySelectorAll("*").forEach((el) => {
        for (const attr of Array.from(el.attributes)) {
            if (/^on\w+/i.test(attr.name)) el.removeAttribute(attr.name);
        }
        // Rimuovi href/src con javascript:
        for (const attrName of ["href", "src", "action", "formaction"]) {
            const val = el.getAttribute(attrName);
            if (val && /^\s*javascript:/i.test(val)) el.removeAttribute(attrName);
        }
    });
    // Riconverti il fragment in stringa tramite container temporaneo
    const tmp = document.createElement("div");
    tmp.appendChild(frag.cloneNode(true));
    return tmp.innerHTML;
}

/** WeakMap per replica jQuery .data() su Element. */
const _elData = new WeakMap();
function elData(el, key, value) {
    if (!el) return undefined;
    if (arguments.length === 3) {
        let store = _elData.get(el);
        if (!store) {
            store = new Map();
            _elData.set(el, store);
        }
        store.set(key, value);
        return value;
    }
    const store = _elData.get(el);
    return store ? store.get(key) : el.dataset[key];
}

/**
 * Registro handler con namespace per removeEventListener selettivo.
 * Usiamo Map (non WeakMap) perché i target includono `document` (non collectable).
 * Per target DOM rimossi: offNs() pulisce la entry; se il target viene GC-ato
 * prima di offNs(), la Map trattiene solo la entry (non il nodo: il nodo è già GC).
 * Rischio leak accettabile: i wrapper editor sono a lunga vita. Se in futuro
 * i wrapper diventassero short-lived, migrare a WeakMap<target, Map<key, fn>>.
 */
const _nsHandlers = new Map();
function offNs(target, event, ns) {
    const key = `${event}.${ns}`;
    const targetMap = _nsHandlers.get(target);
    if (!targetMap) return;
    const fn = targetMap.get(key);
    if (fn) {
        target.removeEventListener(event, fn);
        targetMap.delete(key);
    }
    // Rimuovi la Map vuota per evitare accumulo di entry orfane
    if (targetMap.size === 0) {
        _nsHandlers.delete(target);
    }
}
function onNs(target, event, ns, handler) {
    offNs(target, event, ns);
    let targetMap = _nsHandlers.get(target);
    if (!targetMap) {
        targetMap = new Map();
        _nsHandlers.set(target, targetMap);
    }
    targetMap.set(`${event}.${ns}`, handler);
    target.addEventListener(event, handler);
}

export const EditorSystem = {
  _state: {
    focusedEditorId: null,
  },
  _undoStack: new Map(), // Mappa editorId -> array di stati
  _redoStack: new Map(),
  _inputTimers: new Map(), // Timer per debounce del salvataggio durante input
  _isPerformingUndoRedo: false, // Flag per evitare di salvare durante undo/redo

  /**
   * Salva lo stato corrente dell'editor nello stack di undo
   * @param {string} editorId - ID dell'editor
   */
  _saveUndoState (editorId) {
    const editor = document.getElementById(editorId);
    if (!editor) return;

    if (!this._undoStack.has(editorId)) {
      this._undoStack.set(editorId, []);
    }
    if (!this._redoStack.has(editorId)) {
      this._redoStack.set(editorId, []);
    }

    const stack = this._undoStack.get(editorId);
    const selection = window.getSelection();

    // Pre-filtro veloce ad ogni keystroke:
    // 1. Confronta textContent.length (lettura O(1), non serializza il DOM).
    //    Se la lunghezza è cambiata il contenuto è sicuramente diverso → procedi a salvare.
    //    Se è uguale → serializza innerHTML per confronto esatto solo in quel caso.
    // Riduce la pressione sul main thread su dispositivi lenti (3G rurale).
    const currentTextLen = editor.textContent.length;
    if (stack.length > 0) {
      const lastState = stack[stack.length - 1];
      // Ottimizzazione: se la lunghezza del testo è uguale potrebbe esserlo anche l'HTML;
      // serializza innerHTML solo in quel caso. Se la lunghezza è diversa (o _textLen non
      // presente su stati salvati prima di questo fix), saltiamo il confronto innerHTML.
      const lenKnown = lastState._textLen !== undefined;
      const sameLen = lenKnown && lastState._textLen === currentTextLen;
      if (sameLen && lastState.html === editor.innerHTML) {
        return; // Stato identico, non salvare
      }
      if (!lenKnown && lastState.html === editor.innerHTML) {
        return; // Fallback per stati senza _textLen (compatibilità)
      }
    }

    // Salva HTML e posizione cursore
    const currentHtmlForUndo = editor.innerHTML;
    const state = {
      html: currentHtmlForUndo,
      _textLen: currentTextLen,
      cursorOffset: selection.rangeCount > 0 ? selection.getRangeAt(0).startOffset : 0,
      cursorNode: selection.rangeCount > 0 ? DomManager.getNodePath(selection.getRangeAt(0).startContainer) : null,
    };

    stack.push(state);

    // Limita stack a 50 stati
    if (stack.length > 50) {
      stack.shift();
    }

    // Pulisci redo stack quando viene salvato un nuovo stato
    this._redoStack.set(editorId, []);
  },

  /**
   * Esegue undo nell'editor
   * @param {string} editorId - ID dell'editor
   */
  _performUndo (editorId) {
    const editor = document.getElementById(editorId);
    if (!editor) {
      return false;
    }

    const undoStack = this._undoStack.get(editorId);
    const redoStack = this._redoStack.get(editorId);

    if (!undoStack || undoStack.length === 0) {
      return false;
    }

    // Prendi l'ultimo stato dallo stack
    const lastState = undoStack[undoStack.length - 1];

    // Ottieni lo stato CORRENTE dell'editor
    const selection = window.getSelection();
    const currentHtml = editor.innerHTML;
    // Salva cursore corrente come fallback per stati senza cursorNode
    const preUndoCursorNode = selection.rangeCount > 0 ? DomManager.getNodePath(selection.getRangeAt(0).startContainer) : null;
    const preUndoCursorOffset = selection.rangeCount > 0 ? selection.getRangeAt(0).startOffset : 0;

    let stateToRestore;

    // Se lo stato corrente è uguale all'ultimo nello stack, significa che è già stato salvato
    // Quindi fai pop di quello e prendi il precedente
    if (lastState && lastState.html === currentHtml) {
      const currentStateInStack = undoStack.pop(); // Rimuovi lo stato corrente (già salvato)
      if (undoStack.length === 0) {
        undoStack.push(currentStateInStack); // Rimetti lo stato nello stack
        return false;
      }
      // Controlla se stiamo per rimuovere l'ultimo stato (quello iniziale)
      if (undoStack.length === 1) {
        stateToRestore = undoStack[0]; // Non fare pop, solo leggi
        redoStack.push(currentStateInStack); // Salva quello corrente nel redo
      } else {
        // Prendi lo stato precedente e salvalo nel redo
        stateToRestore = undoStack.pop();
        redoStack.push(currentStateInStack); // Salva quello corrente (che abbiamo rimosso) nel redo
      }
    } else {
      // Lo stato corrente non è nello stack, salvalo nel redo
      const currentState = {
        html: currentHtml,
        cursorOffset: selection.rangeCount > 0 ? selection.getRangeAt(0).startOffset : 0,
        cursorNode: selection.rangeCount > 0 ? DomManager.getNodePath(selection.getRangeAt(0).startContainer) : null,
      };
      redoStack.push(currentState);

      // Controlla se stiamo per rimuovere l'ultimo stato (quello iniziale)
      if (undoStack.length === 1) {
        stateToRestore = undoStack[0]; // Non fare pop, solo leggi
      } else {
        stateToRestore = undoStack.pop();
      }
    }

    // Ripristina lo stato
    editor.innerHTML = stateToRestore.html;

    // Ripristina il cursore alla posizione salvata con quello stato
    const _restoreCursor = (cursorNode, cursorOffset, fallbackEl) => {
      const sel = window.getSelection();
      sel.removeAllRanges();
      const range = document.createRange();
      if (cursorNode) {
        try {
          const node = DomManager.getNodeFromPath(cursorNode);
          // Verifica che il nodo sia dentro l'editor (non in toolbar o altrove)
          if (node && fallbackEl.contains(node)) {
            const offset = Math.min(cursorOffset, node.nodeType === Node.TEXT_NODE ? node.length : node.childNodes.length);
            range.setStart(node, offset);
            range.collapse(true);
            sel.addRange(range);
            return;
          }
        } catch (e) {
          console.warn("Impossibile ripristinare cursore dopo undo:", e);
        }
      }
      // Fallback: cursore all'inizio dell'editor
      range.setStart(fallbackEl, 0);
      range.collapse(true);
      sel.addRange(range);
    };
    // Usa la posizione salvata nello stato; se null (stato iniziale), usa quella pre-undo
    const cursorNode = stateToRestore.cursorNode ?? preUndoCursorNode;
    const cursorOffset = stateToRestore.cursorNode != null ? stateToRestore.cursorOffset : preUndoCursorOffset;
    _restoreCursor(cursorNode, cursorOffset, editor);

    return true;
  },

  /**
   * Esegue redo nell'editor
   * @param {string} editorId - ID dell'editor
   */
  _performRedo (editorId) {
    const editor = document.getElementById(editorId);
    if (!editor) return false;

    const undoStack = this._undoStack.get(editorId);
    const redoStack = this._redoStack.get(editorId);

    if (!redoStack || redoStack.length === 0) return false;

    // Salva lo stato CORRENTE dell'editor nell'undo stack (per poterci tornare con undo)
    const selection = window.getSelection();
    const currentState = {
      html: editor.innerHTML,
      cursorOffset: selection.rangeCount > 0 ? selection.getRangeAt(0).startOffset : 0,
      cursorNode: selection.rangeCount > 0 ? DomManager.getNodePath(selection.getRangeAt(0).startContainer) : null,
    };
    undoStack.push(currentState);

    // Prendi lo stato successivo dal redo stack
    const nextState = redoStack.pop();
    editor.innerHTML = nextState.html;

    // Ripristina il cursore alla posizione salvata con lo stato redo
    if (nextState.cursorNode) {
      try {
        const node = DomManager.getNodeFromPath(nextState.cursorNode);
        if (node && editor.contains(node)) {
          const range = document.createRange();
          const offset = Math.min(nextState.cursorOffset, node.nodeType === Node.TEXT_NODE ? node.length : node.childNodes.length);
          range.setStart(node, offset);
          range.collapse(true);
          const sel = window.getSelection();
          sel.removeAllRanges();
          sel.addRange(range);
        }
      } catch (e) {
        console.warn("Impossibile ripristinare cursore dopo redo:", e);
      }
    }

    return true;
  },

  getFocusedEditorId () {
    return this._state.focusedEditorId;
  },
  setFocusedEditorId (id) {
    this._state.focusedEditorId = id;
  },
  genEditorID (element) {
    const el = asElement(element);
    if (!el) {
      console.error("genEditorID: element non valido", element);
      return null;
    }
    const elementId = el.id;
    if (!elementId) {
      console.error("genEditorID: element senza ID", el);
      return null;
    }
    const editor = document.getElementById(`Editor${elementId}`);
    if (!editor) {
      console.warn("genEditorID: editor non trovato per id", elementId, "element:", el);
    }
    return editor;
  },

  /**
   * Genera un ID editor basato sulla posizione relativa nel DOM
   * @param {jQuery} $element - Elemento da cui partire per cercare il contenitore
   * @returns {string} ID nel formato "Editor[num]_[type]" (es: "Editor3_collex")
   */
  generatePositionalEditorId (element) {
    const el = asElement(element);
    if (!el) return `Editor${Date.now()}`;

    const containerTypes = [
      { selector: ".fm-collection", type: "fm-collection" },
      { selector: ".giustifica", type: "giustifica" },
      { selector: ".fm-testo", type: "fm-testo" },
      { selector: ".fm-giustsol", type: "fm-giustsol" },
      { selector: ".fm-sol", type: "fm-sol" },
    ];

    let containerType = null;
    let container = null;

    for (const config of containerTypes) {
      container = el.closest(config.selector);
      if (container) {
        containerType = config.type;
        break;
      }
    }

    if (!containerType) return `Editor${Date.now()}`;

    // Conteggio posizionale su document intenzionale: serve il numero globale del container nella pagina
    const allContainers = Array.from(document.querySelectorAll(containerTypes.find((c) => c.type === containerType).selector));
    const position = allContainers.indexOf(container) + 1;

    return `Editor${position}_${containerType}`;
  },

  /**
   * Estrae il tipo di contenitore dall'ID dell'editor
   * @param {string} editorId - ID dell'editor (es: "Editor3_collex")
   * @returns {string} Tipo di contenitore (es: "fm-collection") o vuoto se non trovato
   */
  getEditorType (editorId) {
    const match = editorId.match(/_([a-z]+)$/);
    return match ? match[1] : "";
  },

  /**
   * Mappa il tipo di editor al nome leggibile per il backup
   * @param {string} editorType - Tipo editor (es: "fm-collection", "fm-testo")
   * @returns {string} Nome leggibile (es: "esercizio", "traccia")
   */
  getEditorTypeDisplayName (editorType) {
    const typeMap = {
      collex: "esercizio",
      testo: "traccia",
      giustifica: "testo giustifica",
      giustsol: "giustifica",
      sol: "soluzione",
    };
    return typeMap[editorType] || editorType;
  },

  /**
   * Evidenzia i commenti LaTeX (testo dopo % non preceduto da \) con span verde
   * SOLO all'interno di blocchi tikzpicture
   * @param {jQuery} $editor - Elemento editor jQuery
   */
  highlightLatexComments (editor) {
    const editorEl = asElement(editor);
    if (!editorEl) return;

    // Verifica se siamo in un ambiente tikzpicture
    const editorContent = editorEl.textContent;
    if (!editorContent.includes("\\begin{tikzpicture}") || !editorContent.includes("\\end{tikzpicture}")) {
      return; // Non è un ambiente tikzpicture, esci
    }

    // Salva la posizione del cursore prima di modificare il contenuto
    const selection = window.getSelection();
    let cursorInfo = null;

    if (selection.rangeCount > 0) {
      const range = selection.getRangeAt(0);
      cursorInfo = {
        node: range.startContainer,
        offset: range.startOffset,
      };
    }

    // Calcola i range tra \usepackage e \end{document}
    const latexRanges = [];

    // Cerca \usepackage e \end{document}
    const usepackageRegex = /\\usepackage/g;
    const endDocRegex = /\\end\{document\}/g;

    // Trova TUTTE le coppie \usepackage ... \end{document}
    const usepackageMatches = [];
    const endDocMatches = [];

    let match;
    while ((match = usepackageRegex.exec(editorContent)) !== null) {
      usepackageMatches.push(match.index);
    }

    while ((match = endDocRegex.exec(editorContent)) !== null) {
      endDocMatches.push(match.index + match[0].length);
    }

    // Accoppia ogni \usepackage con il \end{document} successivo
    usepackageMatches.forEach((startPos) => {
      const endPos = endDocMatches.find((end) => end > startPos);
      if (endPos !== undefined) {
        latexRanges.push({ start: startPos, end: endPos });
      }
    });

    if (latexRanges.length === 0) return;

    // Funzione per verificare se una posizione è dentro il range LaTeX
    function isInsideLatex(position) {
      return latexRanges.some((range) => position >= range.start && position <= range.end);
    }

    // Ottieni tutti i nodi di testo nell'editor
    const walker = document.createTreeWalker(editorEl, NodeFilter.SHOW_TEXT, null, false);

    const nodesToProcess = [];
    let node;
    let currentPosition = 0;

    while ((node = walker.nextNode())) {
      // Salta nodi già all'interno di span.latex-comment
      if (node.parentElement?.classList.contains("latex-comment")) {
        currentPosition += node.textContent.length;
        continue;
      }

      const nodeStart = currentPosition;
      const nodeEnd = currentPosition + node.textContent.length;

      // Verifica se il nodo è dentro il range LaTeX (tra \usepackage e \end{document})
      if (isInsideLatex(nodeStart) || isInsideLatex(nodeEnd)) {
        nodesToProcess.push(node);
      }

      currentPosition = nodeEnd;
    }

    let cursorRestoreNode = null;
    let cursorRestoreOffset = 0;

    // Processa ogni nodo di testo
    nodesToProcess.forEach((textNode) => {
      const text = textNode.textContent;
      if (!text.includes("%")) return;

      // Cerca commenti LaTeX: % non preceduto da \
      const parts = [];
      let lastIndex = 0;

      for (let i = 0; i < text.length; i++) {
        if (text[i] === "%") {
          // Controlla se % è preceduto da \
          if (i > 0 && text[i - 1] === "\\") {
            continue; // Skip \% (escaped percent)
          }

          // Trova la fine della riga (newline o fine stringa)
          let endOfLine = text.indexOf("\n", i);
          if (endOfLine === -1) endOfLine = text.length;

          // Aggiungi il testo prima del commento
          if (i > lastIndex) {
            parts.push({
              type: "text",
              content: text.substring(lastIndex, i),
            });
          }

          // Aggiungi il commento (incluso il %)
          parts.push({
            type: "comment",
            content: text.substring(i, endOfLine),
          });

          lastIndex = endOfLine;
          i = endOfLine - 1; // -1 perché il loop farà i++
        }
      }

      // Aggiungi il testo rimanente
      if (lastIndex < text.length) {
        parts.push({
          type: "text",
          content: text.substring(lastIndex),
        });
      }

      // Se ci sono commenti, sostituisci il nodo
      if (parts.some((p) => p.type === "comment")) {
        const fragment = document.createDocumentFragment();
        let charCount = 0;

        parts.forEach((part, partIndex) => {
          if (part.type === "text") {
            const newTextNode = document.createTextNode(part.content);
            fragment.appendChild(newTextNode);

            // Se il cursore era in questo nodo di testo, calcola la nuova posizione
            if (cursorInfo && textNode === cursorInfo.node) {
              if (cursorInfo.offset >= charCount && cursorInfo.offset <= charCount + part.content.length) {
                cursorRestoreNode = newTextNode;
                cursorRestoreOffset = cursorInfo.offset - charCount;
              }
            }
            charCount += part.content.length;
          } else {
            const span = document.createElement("span");
            span.className = "latex-comment";
            span.style.backgroundColor = "#90EE90"; // Verde chiaro
            span.style.color = "#006400"; // Verde scuro
            span.textContent = part.content;
            fragment.appendChild(span);

            // Se il cursore era subito dopo il % (inizio del commento)
            // mettilo dentro lo span, non prima
            if (cursorInfo && textNode === cursorInfo.node) {
              if (cursorInfo.offset >= charCount && cursorInfo.offset <= charCount + part.content.length) {
                // Il cursore è dentro il commento
                if (span.firstChild) {
                  cursorRestoreNode = span.firstChild;
                  cursorRestoreOffset = cursorInfo.offset - charCount;
                } else {
                  // Fallback: posiziona dopo il testo precedente
                  const prevTextNodes = Array.from(fragment.childNodes).filter((n) => n.nodeType === Node.TEXT_NODE);
                  if (prevTextNodes.length > 0) {
                    const lastTextNode = prevTextNodes[prevTextNodes.length - 1];
                    cursorRestoreNode = lastTextNode;
                    cursorRestoreOffset = lastTextNode.length;
                  }
                }
              }
            }
            charCount += part.content.length;
          }
        });

        // Sostituisci il nodo originale con il fragment
        const parent = textNode.parentNode;
        if (parent) {
          parent.replaceChild(fragment, textNode);
        }
      }
    });

    // Ripristina il cursore dopo tutte le modifiche
    if (cursorRestoreNode && cursorRestoreNode.parentNode) {
      try {
        const newRange = document.createRange();
        const maxOffset = cursorRestoreNode.nodeType === Node.TEXT_NODE ? cursorRestoreNode.length : cursorRestoreNode.childNodes.length || 0;
        newRange.setStart(cursorRestoreNode, Math.min(cursorRestoreOffset, maxOffset));
        newRange.collapse(true);
        selection.removeAllRanges();
        selection.addRange(newRange);
      } catch (e) {
        console.warn("Impossibile ripristinare il cursore:", e);
      }
    }
  },

  inizializzaEditor (editorId) {
    const editorEl = document.getElementById(editorId);
    if (!editorEl) return;
    const wrapper = editorEl.closest(".fm-editor-wrapper");
    if (!wrapper) return;
    const listType = wrapper.querySelector("#listType");

    if (listType) {
      listType.removeAttribute("onchange");
      // Replace listType element via cloneNode to clear all listeners (replica .off)
      const newListType = listType.cloneNode(true);
      listType.replaceWith(newListType);
      let _ltBeforeOpen = "";
      newListType.addEventListener("mousedown", function () {
        _ltBeforeOpen = this.value;
        const self = this;
        setTimeout(() => { self.value = ""; }, 0);
      });
      newListType.addEventListener("change", function () {
        const val = this.value || _ltBeforeOpen;
        if (val) ListManager.changeListType(val, editorId);
      });
    }

    // Riattacca i click handler sui pulsanti della toolbar
    const elementTikzSelector = wrapper.querySelector(".fm-element-tikz-selector");
    const elementTikzGroups = elementTikzSelector?.querySelector(".fm-element-tikz-groups");
    const elementTracciaSelector = wrapper.querySelector(".fm-element-traccia-selector");
    const elementTracciaGroups = elementTracciaSelector?.querySelector(".fm-element-traccia-groups");

    // Cloneo per rimuovere listeners precedenti (replica .off("click"))
    const cloneAndBind = (selector, handler) => {
      const oldEl = wrapper.querySelector(selector);
      if (!oldEl) return null;
      const newEl = oldEl.cloneNode(true);
      oldEl.replaceWith(newEl);
      newEl.addEventListener("click", handler);
      return newEl;
    };

    const tikzTitle = cloneAndBind(".elementTikzTitle", function (e) {
      e.stopPropagation();
      if (elementTracciaGroups) elementTracciaGroups.style.display = "none";
      wrapper.querySelectorAll(".fm-element-backup-list").forEach((el) => { el.style.display = "none"; });
      wrapper.querySelectorAll(".fm-element-traccia-title, .fm-element-backup-title").forEach((el) => el.classList.remove("active"));
      document.querySelectorAll(".fm-traccia-group .fm-group-options").forEach((el) => {
        el.style.display = "none";
        el.classList.remove("active");
      });
      document.querySelectorAll(".fm-traccia-group .fm-group-btn").forEach((el) => el.classList.remove("active"));

      if (elementTikzGroups) {
        const wasVisible = isVisible(elementTikzGroups);
        elementTikzGroups.style.display = wasVisible ? "none" : "";

        if (!wasVisible) {
          this.classList.add("active");
          ContainerHeightManager.updateHeight(wrapper, elementTikzGroups, elementTracciaGroups, wrapper.querySelector(".fm-element-backup-list"));
          ContainerHeightManager.startMonitoringGroupOptions(wrapper, elementTikzGroups, elementTracciaGroups, wrapper.querySelector(".fm-element-backup-list"));
        } else {
          this.classList.remove("active");
          document.querySelectorAll(".fm-group-options").forEach((el) => {
            el.style.display = "none";
            el.classList.remove("active");
          });
          document.querySelectorAll(".tex-group .fm-group-btn").forEach((el) => el.classList.remove("active"));
          ContainerHeightManager.resetHeight(wrapper);
          const wrapperId = wrapper.id || elData(wrapper, "wrapper-id");
          if (wrapperId) ContainerHeightManager.stopMonitoringGroupOptions(wrapperId);
        }
      }

      const editorElInner = wrapper.querySelector(".editor");
      if (editorElInner) EditorSystem._setHeightEditor(editorElInner);
    });

    if (elementTikzGroups) {
      onNs(elementTikzGroups, "click", "tikzHeight", (e) => {
        if (e.target.closest(".fm-group-btn, .tex-element-btn")) {
          ContainerHeightManager.updateHeight(wrapper, elementTikzGroups, elementTracciaGroups, wrapper.querySelector(".fm-element-backup-list"));
        }
      });
    }
    if (elementTracciaGroups) {
      onNs(elementTracciaGroups, "click", "tracciaHeight", (e) => {
        if (e.target.closest(".fm-group-btn, .fm-traccia-element-btn")) {
          ContainerHeightManager.updateHeight(wrapper, elementTikzGroups, elementTracciaGroups, wrapper.querySelector(".fm-element-backup-list"));
        }
      });
    }

    cloneAndBind(".elementTracciaTitle", function (e) {
      e.stopPropagation();
      if (elementTikzGroups) elementTikzGroups.style.display = "none";
      wrapper.querySelectorAll(".fm-element-backup-list").forEach((el) => { el.style.display = "none"; });
      wrapper.querySelectorAll(".fm-element-tikz-title, .fm-element-backup-title").forEach((el) => el.classList.remove("active"));
      document.querySelectorAll(".fm-group-options").forEach((el) => {
        el.style.display = "none";
        el.classList.remove("active");
      });
      document.querySelectorAll(".tex-group .fm-group-btn").forEach((el) => el.classList.remove("active"));

      if (elementTracciaGroups) {
        const wasVisible = isVisible(elementTracciaGroups);
        elementTracciaGroups.style.display = wasVisible ? "none" : "";

        if (!wasVisible) {
          this.classList.add("active");
          ContainerHeightManager.updateHeight(wrapper, elementTikzGroups, elementTracciaGroups, wrapper.querySelector(".fm-element-backup-list"));
          ContainerHeightManager.startMonitoringGroupOptions(wrapper, elementTikzGroups, elementTracciaGroups, wrapper.querySelector(".fm-element-backup-list"));
        } else {
          this.classList.remove("active");
          document.querySelectorAll(".fm-traccia-group .fm-group-options").forEach((el) => {
            el.style.display = "none";
            el.classList.remove("active");
          });
          document.querySelectorAll(".fm-traccia-group .fm-group-btn").forEach((el) => el.classList.remove("active"));
          ContainerHeightManager.resetHeight(wrapper);
          const wrapperId = wrapper.id || elData(wrapper, "wrapper-id");
          if (wrapperId) ContainerHeightManager.stopMonitoringGroupOptions(wrapperId);
        }
      }
      const editorElInner = wrapper.querySelector(".editor");
      if (editorElInner) EditorSystem._setHeightEditor(editorElInner);
    });

    const self = this;

    // Evento per impedire al cursore di entrare nel container della checkbox DSA.
    // Delegation via WeakMap-namespaced handler per pulire on re-init.
    const dsaCursorHandler = (event) => {
      // Solo se l'evento target è dentro l'editor di questo editorId
      if (!event.target.closest(`#${editorId}`)) return;
      const isNavigatingBack = event.type === "keyup" && (event.key === "ArrowLeft" || event.key === "ArrowUp");

      setTimeout(() => {
        const selection = window.getSelection();
        if (selection.rangeCount === 0) return;
        const range = selection.getRangeAt(0);
        const node = range.startContainer;

        // Closest .dsa-checkbox-container (su node o suo parent)
        const checkboxContainer = (node.nodeType === Node.ELEMENT_NODE ? node : node.parentElement)?.closest(".fm-dsa-checkbox-container");
        const parentIsCheckboxContainer = node.parentElement?.matches?.(".dsa-checkbox-container") ? node.parentElement : null;

        if (checkboxContainer || parentIsCheckboxContainer) {
          const container = checkboxContainer || parentIsCheckboxContainer;
          const newRange = document.createRange();

          if (isNavigatingBack) {
            const li = container.closest("li");
            let prevLi = li ? li.previousElementSibling : null;
            while (prevLi && prevLi.tagName !== "LI") prevLi = prevLi.previousElementSibling;

            if (prevLi) {
              const lastNode = prevLi.lastChild || prevLi;
              const offset = lastNode.nodeType === Node.TEXT_NODE ? lastNode.length : lastNode.childNodes.length;
              newRange.setStart(lastNode, offset);
            } else if (container.previousSibling) {
              const prev = container.previousSibling;
              if (prev.nodeType === Node.TEXT_NODE) newRange.setStart(prev, prev.length);
              else newRange.setStartAfter(prev);
            } else {
              newRange.setStartBefore(container);
            }
          } else if (container.nextSibling) {
            if (container.nextSibling.nodeType === Node.TEXT_NODE) {
              newRange.setStart(container.nextSibling, 0);
            } else {
              newRange.setStartBefore(container.nextSibling);
            }
          } else {
            newRange.setStartAfter(container);
          }

          newRange.collapse(true);
          selection.removeAllRanges();
          selection.addRange(newRange);
        }

        // Verifica se il cursore è dentro l'input checkbox stesso
        if (node.nodeType === Node.ELEMENT_NODE && node.classList?.contains("dsa-checkbox")) {
          const checkbox = node;
          const dsaContainer = checkbox.parentNode;
          const newRange = document.createRange();

          if (isNavigatingBack) {
            const li = dsaContainer.closest?.("li");
            let prevLi = li ? li.previousElementSibling : null;
            while (prevLi && prevLi.tagName !== "LI") prevLi = prevLi.previousElementSibling;

            if (prevLi) {
              const lastNode = prevLi.lastChild || prevLi;
              const offset = lastNode.nodeType === Node.TEXT_NODE ? lastNode.length : lastNode.childNodes.length;
              newRange.setStart(lastNode, offset);
            } else if (dsaContainer.previousSibling) {
              const prev = dsaContainer.previousSibling;
              newRange.setStart(prev, prev.nodeType === Node.TEXT_NODE ? prev.length : prev.childNodes.length);
            } else {
              newRange.setStartBefore(dsaContainer);
            }
          } else if (dsaContainer.nextSibling) {
            if (dsaContainer.nextSibling.nodeType === Node.TEXT_NODE) {
              newRange.setStart(dsaContainer.nextSibling, 0);
            } else {
              newRange.setStartBefore(dsaContainer.nextSibling);
            }
          } else {
            newRange.setStartAfter(dsaContainer);
          }

          newRange.collapse(true);
          selection.removeAllRanges();
          selection.addRange(newRange);
        }
      }, 0);
    };
    onNs(document, "mouseup", `editorInit_${editorId}`, dsaCursorHandler);
    onNs(document, "keyup", `editorInit_${editorId}`, dsaCursorHandler);
    onNs(document, "click", `editorInit_${editorId}`, dsaCursorHandler);

    onNs(document, "keydown", `editorInit_${editorId}`, (event) => {
      if (!event.target.closest(`#${editorId}`)) return;
      // Gestisci Ctrl+V (Paste) - Salva stato prima di incollare
      if ((event.ctrlKey || event.metaKey) && event.key === "v" && !event.shiftKey) {
        self._saveUndoState(editorId);
      }

      // Gestisci Ctrl+Shift+V (Paste without formatting) - Salva stato prima di incollare
      if ((event.ctrlKey || event.metaKey) && event.shiftKey && event.key === "V") {
        self._saveUndoState(editorId);
      }

      // Gestisci Ctrl+X (Cut) - Salva stato prima di tagliare
      if ((event.ctrlKey || event.metaKey) && event.key === "x") {
        self._saveUndoState(editorId);
      }

      // Gestisci Ctrl+Z (Undo) e Ctrl+Y (Redo)
      if (event.ctrlKey && event.key === "z" && !event.shiftKey) {
        event.preventDefault();

        // Cancella il timer di debounce pendente
        if (self._inputTimers.has(editorId)) {
          clearTimeout(self._inputTimers.get(editorId));
          self._inputTimers.delete(editorId);
        }

        // Esegui l'undo (che gestirà internamente il salvataggio dello stato corrente)
        self._isPerformingUndoRedo = true;
        const result = self._performUndo(editorId);
        if (result) {
          { const editorInner = document.getElementById(editorId); if (editorInner) trigger(editorInner, "input"); }
          // Disattiva il flag DOPO aver triggerato l'input
          setTimeout(() => {
            self._isPerformingUndoRedo = false;
          }, 0);
        } else {
          self._isPerformingUndoRedo = false;
        }
        return;
      }

      if (event.ctrlKey && (event.key === "y" || (event.key === "z" && event.shiftKey))) {
        event.preventDefault();

        // Cancella il timer di debounce pendente
        if (self._inputTimers.has(editorId)) {
          clearTimeout(self._inputTimers.get(editorId));
          self._inputTimers.delete(editorId);
        }

        // Esegui il redo (che gestirà internamente il salvataggio dello stato corrente)
        self._isPerformingUndoRedo = true;
        if (self._performRedo(editorId)) {
          { const editorInner = document.getElementById(editorId); if (editorInner) trigger(editorInner, "input"); }
          // Disattiva il flag DOPO aver triggerato l'input
          setTimeout(() => {
            self._isPerformingUndoRedo = false;
          }, 0);
        } else {
          self._isPerformingUndoRedo = false;
        }
        return;
      }

      if (event.key === "Tab") {
        event.preventDefault();
        const selection = window.getSelection();
        const range = selection.getRangeAt(0);
        const tabNode = document.createTextNode("\u00A0\u00A0\u00A0\u00A0"); // 4 non-breaking spaces for a tab
        range.insertNode(tabNode);
        range.setStartAfter(tabNode);
        range.setEndAfter(tabNode);
        selection.removeAllRanges();
        selection.addRange(range);
      }

      // Gestisci Delete e Backspace per <li> vuoti
      if (event.key === "Delete" || event.key === "Backspace") {
        const selection = window.getSelection();
        if (selection.rangeCount > 0) {
          const range = selection.getRangeAt(0);
          const container = range.startContainer;
          const containerEl2 = container.nodeType === Node.ELEMENT_NODE ? container : container.parentElement;
          let closestLi = containerEl2 ? containerEl2.closest("li") : null;
          if (closestLi && closestLi.classList.contains("fm-li-inline")) closestLi = null;

          if (closestLi) {
            const isAtStart = range.startOffset === 0 && (
              container.nodeType === Node.TEXT_NODE
                ? container === closestLi.firstChild || container.parentNode === closestLi
                : container === closestLi
            );
            const isAtEnd = range.startOffset === (container.nodeType === Node.TEXT_NODE ? container.length : container.childNodes.length) && (
              container.nodeType === Node.TEXT_NODE
                ? container === closestLi.lastChild || container.parentNode === closestLi
                : container === closestLi
            );

            if ((event.key === "Backspace" && isAtStart) || (event.key === "Delete" && isAtEnd)) {
              // prev/next <li> sibling
              let prevLi = closestLi.previousElementSibling;
              while (prevLi && prevLi.tagName !== "LI") prevLi = prevLi.previousElementSibling;
              let nextLi = closestLi.nextElementSibling;
              while (nextLi && nextLi.tagName !== "LI") nextLi = nextLi.nextElementSibling;

              const currentText = (closestLi.textContent || "").trim();
              if (currentText === "" || closestLi.innerHTML.trim() === "<br>") {
                event.preventDefault();
                self._saveUndoState(editorId);

                if (event.key === "Backspace" && prevLi) {
                  closestLi.remove();
                  const newRange = document.createRange();
                  const lastNode = prevLi.lastChild || prevLi;
                  const off = lastNode.nodeType === Node.TEXT_NODE ? lastNode.length : lastNode.childNodes.length;
                  newRange.setStart(lastNode, off);
                  newRange.collapse(true);
                  selection.removeAllRanges();
                  selection.addRange(newRange);
                } else if (event.key === "Delete" && nextLi) {
                  closestLi.remove();
                  const newRange = document.createRange();
                  const firstNode = nextLi.firstChild || nextLi;
                  newRange.setStart(firstNode, 0);
                  newRange.collapse(true);
                  selection.removeAllRanges();
                  selection.addRange(newRange);
                } else {
                  closestLi.remove();
                }
                return;
              }

              // Backspace all'inizio di un <li> non vuoto: unisci con il precedente
              if (event.key === "Backspace" && prevLi) {
                event.preventDefault();
                self._saveUndoState(editorId);

                if (prevLi.lastChild?.nodeName === "BR") prevLi.lastChild.remove();

                const prevLastNode = prevLi.lastChild || prevLi;
                const cursorOffset = prevLastNode.nodeType === Node.TEXT_NODE ? prevLastNode.length : prevLastNode.childNodes.length;

                // Sposta i childNodes del closestLi nel prevLi (skip leading BR)
                const childNodes = Array.from(closestLi.childNodes);
                childNodes.forEach((cn) => {
                  if (!(cn.nodeType === Node.ELEMENT_NODE && cn.tagName === "BR" && cn === closestLi.firstChild)) {
                    prevLi.appendChild(cn);
                  }
                });

                closestLi.remove();

                const newRange = document.createRange();
                newRange.setStart(prevLastNode, cursorOffset);
                newRange.collapse(true);
                selection.removeAllRanges();
                selection.addRange(newRange);
              }

              // Delete alla fine di un <li> non vuoto: unisci con il successivo
              if (event.key === "Delete" && nextLi) {
                event.preventDefault();
                self._saveUndoState(editorId);

                const currentLastNode = closestLi.lastChild || closestLi;
                const cursorOffset = currentLastNode.nodeType === Node.TEXT_NODE ? currentLastNode.length : currentLastNode.childNodes.length;

                if (closestLi.lastChild?.nodeName === "BR") closestLi.lastChild.remove();
                if (nextLi.firstChild?.nodeName === "BR") nextLi.firstChild.remove();

                Array.from(nextLi.childNodes).forEach((cn) => closestLi.appendChild(cn));
                nextLi.remove();

                const newRange = document.createRange();
                const nodeForCursor = currentLastNode.parentNode ? currentLastNode : closestLi.lastChild || closestLi;
                const off = nodeForCursor.nodeType === Node.TEXT_NODE ? cursorOffset : Math.min(cursorOffset, nodeForCursor.childNodes.length);
                newRange.setStart(nodeForCursor, off);
                newRange.collapse(true);
                selection.removeAllRanges();
                selection.addRange(newRange);
              }
            }
          }
        }
      }

      // Gestisci Invio
      if (event.key === "Enter" && !event.shiftKey) {
        const selection = window.getSelection();
        if (selection.rangeCount > 0) {
          const range = selection.getRangeAt(0);
          const container = range.startContainer;
          const containerEl3 = container.nodeType === Node.ELEMENT_NODE ? container : container.parentElement;

          // Verifica se siamo dentro uno span .latex-comment
          const commentSpan = containerEl3 ? containerEl3.closest(".latex-comment") : null;
          if (commentSpan) {
            event.preventDefault();

            const br = document.createElement("br");
            const textNode = document.createTextNode("");
            commentSpan.after(br, textNode);

            const newRange = document.createRange();
            newRange.setStart(textNode, 0);
            newRange.collapse(true);
            selection.removeAllRanges();
            selection.addRange(newRange);
            return;
          }

          // Verifica se siamo dentro un <li>
          let closestLi2 = containerEl3 ? containerEl3.closest("li") : null;
          if (closestLi2 && closestLi2.classList.contains("fm-li-inline")) closestLi2 = null;
          if (closestLi2) {
            event.preventDefault();
            self._saveUndoState(editorId);

            const liText = (closestLi2.textContent || "").trim();
            const liHtml = closestLi2.innerHTML.trim();

            if (liText === "" || liHtml === "<br>" || liHtml === "") {
              const list = closestLi2.parentElement;
              const listIsValid = list && (list.tagName === "UL" || list.tagName === "OL");

              closestLi2.remove();

              const newDiv = document.createElement("div");
              newDiv.innerHTML = "<br>";

              if (listIsValid) list.after(newDiv);

              const newRange = document.createRange();
              newRange.setStart(newDiv, 0);
              newRange.collapse(true);
              selection.removeAllRanges();
              selection.addRange(newRange);

              return;
            }

            const newLi = document.createElement("li");

            const afterRange = range.cloneRange();
            afterRange.selectNodeContents(closestLi2);
            afterRange.setStart(range.endContainer, range.endOffset);

            const afterContent = afterRange.extractContents();

            if (afterContent.textContent.trim() === "") {
              newLi.appendChild(document.createElement("br"));
            } else {
              newLi.appendChild(afterContent);
            }

            // Inserisci il nuovo <li> dopo quello corrente
            closestLi2.after(newLi);

            // Se il <li> corrente è diventato vuoto, aggiungi un <br>
            if (closestLi2.textContent.trim() === "") {
              closestLi2.appendChild(document.createElement("br"));
            }

            // Aggiungi checkbox DSA se siamo in una verifica
            ListManager.onListItemCreated(newLi);

            // Posiziona il cursore all'inizio del nuovo <li>
            const newRange = document.createRange();
            const firstNode = newLi.firstChild || newLi;
            const offset = firstNode.nodeType === Node.TEXT_NODE ? 0 : 0;

            try {
              if (firstNode === newLi) {
                // Se newLi non ha figli, inserisci un nodo di testo vuoto
                const textNode = document.createTextNode("");
                newLi.insertBefore(textNode, newLi.firstChild);
                newRange.setStart(textNode, 0);
              } else {
                newRange.setStart(firstNode, offset);
              }
              newRange.collapse(true);
              selection.removeAllRanges();
              selection.addRange(newRange);
            } catch (e) {
              console.warn("Impossibile posizionare cursore:", e);
              // Fallback: focus sull'editor
              newLi.focus();
            }
          }
        }
      }
    });
    if (!EditorSystem._selectionChangeRegistered) {
      EditorSystem._selectionChangeRegistered = true;
      let updateListTypeSelectorTimeout;
      onNs(document, "selectionchange", "editorInit", () => {
        const selection = window.getSelection();
        if (selection.rangeCount > 0) {
          const r = selection.getRangeAt(0);
          const container = r.startContainer;
          const containerEl4 = container.nodeType === Node.ELEMENT_NODE ? container : container.parentElement;
          const editorParent = containerEl4 ? containerEl4.closest(".editor, .myTextarea") : null;
          if (editorParent) {
            if (!selection.isCollapsed) {
              ListManager._preDropdownRange = r.cloneRange();
            }
            clearTimeout(updateListTypeSelectorTimeout);
            updateListTypeSelectorTimeout = setTimeout(() => {
              ListManager.updateListTypeSelector();
            }, 250);
          }
        }
      });
    }

    const editorElForWrap = document.getElementById(editorId);
    if (editorElForWrap) {
      DomManager.wrapDirectTextNodesInDivs(editorElForWrap);
      DomManager.unwrapDivs(editorElForWrap);
    }
  },
  /**
   * @deprecated - NO-OP: eventi input/focus/blur gestiti tramite event delegation globale.
   * Vedi EditorSystem._initEditorEventDelegation()
   */
  _bindEditorCoreEvents (editorId) {
    // NO-OP: la delegation globale su $(document) gestisce tutti gli .editor
  },
  /**
   * Controlla se il cursore si trova in un ambiente TikZ (\usepackage...\end{document})
   */
  _checkCursorPositionInTikz (editorRef) {
    const editor = asElement(editorRef);
    if (!editor) {
      console.error("Editor non trovato");
      return 0;
    }

    const selection = window.getSelection();
    if (!selection.rangeCount) {
      return 0;
    }

    const range = selection.getRangeAt(0);
    const cursorNode = range.startContainer;
    const cursorOffset = range.startOffset;
    const content = editor.textContent;

    let cursorPosition = 0;
    let currentNode = cursorNode;
    while (currentNode) {
      if (currentNode === editor) break;
      if (currentNode.previousSibling) {
        currentNode = currentNode.previousSibling;
        cursorPosition += (currentNode.textContent || "").length;
      } else {
        currentNode = currentNode.parentNode;
      }
    }
    cursorPosition += cursorOffset;

    const leftContent = content.slice(0, cursorPosition).trim();
    const matches = leftContent.match(/\\usepackage|\\end{document}/g);

    if (!matches || matches.length === 0) {
      return 0;
    }

    return matches[matches.length - 1] === "\\usepackage" ? 1 : 0;
  },
  /**
   * Carica il contenuto di un backup nell'editor corrente
   * @param {string} filename - Nome del file di backup
   * @param {string} editorId - ID dell'editor in cui caricare il backup
   */
  loadBackup (filename, editorId) {
    // NB: lo shim ajaxCompat NON inviava i `data` nelle GET → comportamento
    // wire preservato (filename non aggiunto alla query string).
    fetch(Endpoints.editor.loadRevision, { credentials: "same-origin" })
      .then(async (res) => {
        const t = await res.text().catch(() => "");
        if (!res.ok) throw { status: res.status, statusText: res.statusText, responseText: t };
        try { return JSON.parse(t); } catch { throw { status: res.status, responseText: t, message: "Invalid JSON" }; }
      })
      .then((response) => {
        if (response.success) {
          const editor = document.getElementById(editorId);
          if (editor) {
            // [SICUREZZA] Sanitizza il contenuto del backup prima di inserirlo:
            // response.content è server-controlled e potrebbe contenere XSS.
            editor.innerHTML = sanitizeEditorHtml(response.content);
            console.log("Backup caricato:", filename);
            trigger(editor, "input");
            ToastManager.show("success", "Successo", "Backup caricato con successo!", 3000);
          } else {
            ToastManager.show("error", "Errore", "Editor non trovato.", 4000);
          }
        } else {
          ToastManager.show("error", "Errore", response.error || "Impossibile caricare il backup", 4000);
        }
      })
      .catch((error) => {
        console.error("Errore nel caricamento del backup:", error);
        ToastManager.show("error", "Errore", `Errore nel caricamento del backup: ${error.message || error}`, 4000);
      });
  },
  loadEditor (id, content, nameEditor) {
    content = ContentProcessor.getRawTikzContent(content, id);

    // 🔧 Unescape dei tag HTML di formattazione che potrebbero essere stati salvati come entità
    content = content.replace(/&lt;(\/?)(u|b|i|strong|em|span)([^&]*)&gt;/gi, "<$1$2$3>");

    const self = this;
    const idEl = document.getElementById(id);
    if (!idEl) return;

    // Replica jQuery .load("/path .selector", cb): fetch + parse + replace
    fetch("/Elementi_Riservati.html", { credentials: "same-origin" })
      .then((r) => r.text())
      .then((html) => {
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, "text/html");
        const wrapperTemplate = doc.querySelector(".fm-editor-wrapper");
        if (!wrapperTemplate) {
          console.error("[loadEditor] Editor_wrapper non trovato in Elementi_Riservati.html");
          return;
        }
        idEl.innerHTML = "";
        idEl.appendChild(document.importNode(wrapperTemplate, true));
        // Callback originale — `this` = wrapper (idEl che contiene .Editor_wrapper)
        loadEditorCallback.call(idEl);
      })
      .catch((err) => console.error("[loadEditor] fetch error:", err));

    function loadEditorCallback() {
      const wrapper = this;
      const elementTikzSelector = wrapper.querySelector(".fm-element-tikz-selector");
      const elementTikzGroups = elementTikzSelector?.querySelector(".fm-element-tikz-groups");
      const elementTracciaSelector = wrapper.querySelector(".fm-element-traccia-selector");
      const elementTracciaGroups = elementTracciaSelector?.querySelector(".fm-element-traccia-groups");

      UIComp.updateListTracciaSelector(Endpoints.templates.modelliTikz, elementTracciaGroups);

      const tracciaTitle = wrapper.querySelector(".fm-element-traccia-title");
      if (tracciaTitle && elementTracciaGroups) {
        tracciaTitle.addEventListener("click", function (e) {
          e.stopPropagation();
          const wasVisible = isVisible(elementTracciaGroups);
          elementTracciaGroups.style.display = wasVisible ? "none" : "";
          if (!wasVisible) {
            this.classList.add("active");
            ContainerHeightManager.updateHeight(wrapper, elementTikzGroups, elementTracciaGroups, wrapper.querySelector(".fm-element-backup-list"));
          } else {
            this.classList.remove("active");
            ContainerHeightManager.resetHeight(wrapper);
          }
        });
      }
      onNs(document, "click", "tracciaHide", () => {
        if (elementTracciaGroups && isVisible(elementTracciaGroups)) {
          ContainerHeightManager.resetHeight(elementTracciaGroups.closest(".fm-editor-wrapper"));
        }
        if (elementTracciaGroups) elementTracciaGroups.style.display = "none";
      });

      UIComp.updateTikzElementGroups(Endpoints.templates.modelliTikz, elementTikzGroups);

      const tikzTitle = wrapper.querySelector(".fm-element-tikz-title");
      if (tikzTitle && elementTikzGroups) {
        tikzTitle.addEventListener("click", function (e) {
          e.stopPropagation();
          const wasVisible = isVisible(elementTikzGroups);
          elementTikzGroups.style.display = wasVisible ? "none" : "";
          if (!wasVisible) {
            this.classList.add("active");
            ContainerHeightManager.updateHeight(wrapper, elementTikzGroups, elementTracciaGroups, wrapper.querySelector(".fm-element-backup-list"));
          } else {
            this.classList.remove("active");
            ContainerHeightManager.resetHeight(wrapper);
          }
        });
      }

      const linkbar = wrapper.querySelector(".fm-linkbar");
      if (linkbar) {
        linkbar.addEventListener("click", function () {
          this.classList.toggle("active");
        });
      }

      onNs(document, "click", "tikzHide", () => {
        if (elementTikzGroups && isVisible(elementTikzGroups)) {
          ContainerHeightManager.resetHeight(elementTikzGroups.closest(".fm-editor-wrapper"));
        }
        if (elementTikzGroups) elementTikzGroups.style.display = "none";
      });

      // Gestione pulsante Salva Backup Manuale (💾)
      const saveBackupBtn = wrapper.querySelector(".fm-save-backup-btn");
      if (saveBackupBtn) {
        saveBackupBtn.addEventListener("click", (e) => {
          e.stopPropagation();

          const editorElInner = document.getElementById(editorId);
          if (!editorElInner) {
            alert("Editor non trovato");
            return;
          }

          const content = ContentProcessor.getHtmlContent(editorElInner);
          const titleElement = document.querySelector(".fm-titolo");
          const titlepage = titleElement ? `${titleElement.textContent.trim()}.html` : "backup.html";

          fetch(Endpoints.editor.saveSnapshot, {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
            body: new URLSearchParams({ filename: editorId, content, titlepage }).toString(),
          })
            .then(async (res) => {
              const t = await res.text().catch(() => "");
              if (!res.ok) throw { status: res.status, statusText: res.statusText, responseText: t };
              try { return JSON.parse(t); } catch { throw { status: res.status, responseText: t, message: "Invalid JSON" }; }
            })
            .then((response) => {
              if (response.success) {
                if (typeof ToastManager !== "undefined") {
                  ToastManager.show("success", "Successo", `${response.message} - File: ${response.filename}`, 4000);
                } else {
                  alert(`✅ ${response.message}\nFile: ${response.filename}`);
                }
                console.log("💾 Backup manuale salvato:", response.filename);
              } else {
                alert(`❌ Errore: ${response.message}`);
              }
            })
            .catch((error) => {
              console.error("Errore nel salvataggio del backup manuale:", error);
              alert("❌ Errore nel salvataggio del backup manuale");
            });
        });
      }

      // Gestione pulsante Backup Editor (B_E)
      const elementBackupList = wrapper.querySelector(".fm-element-backup-list");
      const elementBackupTitle = wrapper.querySelector(".fm-element-backup-title");
      if (elementBackupTitle && elementBackupList) {
        elementBackupTitle.addEventListener("click", function (e) {
          e.stopPropagation();
          const currentWrapper = this.closest(".fm-editor-wrapper");

          if (isVisible(elementBackupList)) {
            elementBackupList.style.display = "none";
            this.classList.remove("active");
            ContainerHeightManager.resetHeight(currentWrapper);
            return;
          }

          if (elementTikzGroups) elementTikzGroups.style.display = "none";
          if (elementTracciaGroups) elementTracciaGroups.style.display = "none";
          currentWrapper.querySelectorAll(".fm-element-tikz-title, .fm-element-traccia-title").forEach((el) => el.classList.remove("active"));
          document.querySelectorAll(".fm-group-options, .fm-traccia-group .fm-group-options").forEach((el) => {
            el.style.display = "none";
            el.classList.remove("active");
          });
          document.querySelectorAll(".tex-group .fm-group-btn, .fm-traccia-group .fm-group-btn").forEach((el) => el.classList.remove("active"));

          this.classList.add("active");

          let argomento = "";
          const titleElement = document.querySelector(".fm-titolo");
          if (titleElement) argomento = titleElement.textContent.trim();
          if (!argomento) argomento = sessionStorage.getItem("selectedARG") || "";

          console.log("🔍 Debug Backup - argomento:", argomento);
          console.log("🔍 Debug Backup - editorId:", editorId);

          if (!argomento) {
            alert("Argomento non trovato. Impossibile caricare i backup.");
            return;
          }

          const titleBtn = this;
          // NB: ajaxCompat non inviava i `data` nelle GET → query preservata senza argomento.
          fetch(Endpoints.editor.listRevisions, { credentials: "same-origin" })
            .then(async (res) => {
              const t = await res.text().catch(() => "");
              if (!res.ok) throw { status: res.status, statusText: res.statusText, responseText: t };
              try { return JSON.parse(t); } catch { throw { status: res.status, responseText: t, message: "Invalid JSON" }; }
            })
            .then((response) => {
              console.log("📦 Risposta server backup:", response);

              if (response.success && response.backups.length > 0) {
                elementBackupList.replaceChildren();
                const currentEditorNum = editorId.match(/\d+/)[0];
                const currentEditorBackups = [];
                const otherBackups = [];

                response.backups.forEach((backup) => {
                  const backupNum = backup.editorId.match(/\d+/)[0];
                  if (backupNum === currentEditorNum) currentEditorBackups.push(backup);
                  else otherBackups.push(backup);
                });

                otherBackups.sort((a, b) => b.mtime - a.mtime);

                const createBackupButton = (backup, isCurrent) => {
                  const button = document.createElement("button");
                  button.classList.add("fm-backup-element-btn");
                  button.setAttribute("data-filename", backup.filename);
                  button.setAttribute("data-editor-id", backup.editorId);
                  // [SICUREZZA] Nodi DOM espliciti invece di innerHTML con metadata server-controlled
                  const labelNode = document.createTextNode(`${backup.editorId} - ${backup.tipo}`);
                  const br = document.createElement("br");
                  const small = document.createElement("small");
                  small.textContent = backup.date;
                  button.appendChild(labelNode);
                  button.appendChild(br);
                  button.appendChild(small);
                  if (isCurrent) button.classList.add("fm-current-editor");
                  button.addEventListener("click", function () {
                    const filename = this.getAttribute("data-filename");
                    EditorSystem.loadBackup(filename, editorId);
                    elementBackupList.style.display = "none";
                  });
                  return button;
                };

                currentEditorBackups.forEach((backup) => {
                  elementBackupList.appendChild(createBackupButton(backup, true));
                });

                if (currentEditorBackups.length > 0 && otherBackups.length > 0) {
                  elementBackupList.insertAdjacentHTML("beforeend", '<hr style="margin: 8px 0; border: 1px solid #ccc;">');
                }

                otherBackups.forEach((backup) => {
                  elementBackupList.appendChild(createBackupButton(backup, false));
                });

                elementBackupList.style.display = "";
                titleBtn.classList.add("active");

                ContainerHeightManager.updateHeight(wrapper, elementTikzGroups, elementTracciaGroups, elementBackupList);
              } else {
                alert("Nessun backup trovato per questo argomento.");
              }
            })
            .catch(() => alert("Errore nel caricamento dei backup."));
        });
      }

      onNs(document, "click", "backupBtnHeight", (e) => {
        if (e.target.closest(".fm-backup-element-btn")) {
          ContainerHeightManager.updateHeight(wrapper, elementTikzGroups, elementTracciaGroups, elementBackupList);
        }
      });

      onNs(document, "click", "backupHide", () => {
        if (elementBackupList && isVisible(elementBackupList)) {
          ContainerHeightManager.resetHeight(elementBackupList.closest(".fm-editor-wrapper"));
        }
        if (elementBackupList) elementBackupList.style.display = "none";
      });

      // NUOVO: Genera ID posizionale invece che sequenziale
      const idEl2 = document.getElementById(id);
      const editorId = self.generatePositionalEditorId(idEl2);

      // Aggiorna anche l'ID dell'elemento .myTextarea originale per consistenza
      if (idEl2) idEl2.id = editorId.replace("Editor", "");

      const editorInner = wrapper.querySelector(".editor");
      if (editorInner) {
        editorInner.id = editorId;
        editorInner.innerHTML = content;
      }

      const typeWrapper = wrapper.querySelector(".fm-type-wrapper");
      if (typeWrapper) typeWrapper.textContent = nameEditor;

      if (editorInner) {
        if (nameEditor === "SOLUZIONE" || nameEditor === "GIUSTIFICA") {
          editorInner.style.minWidth = "266px";
        } else if (nameEditor === "INTESTAZIONE") {
          editorInner.style.minWidth = "289px";
        } else if (nameEditor === "QUESITO") {
          editorInner.style.minWidth = "249px";
        } else if (nameEditor === "TESTO") {
          editorInner.style.minWidth = "234px";
        } else if (nameEditor === "RICHIESTA GIUSTIFICA") {
          editorInner.style.minWidth = "342px";
        }
      }

      self.highlightLatexComments(editorInner);
      self.inizializzaEditor(editorId);
    }
  },
  // focusEditor: function () {
  //     $('.editor').focus();
  // },
  _ensureEditorNotEmpty (editor) {
    const editorEl = asElement(editor);
    if (!editorEl) return;

    const childNodes = editorEl.childNodes;
    const isEmpty =
      childNodes.length === 0 ||
      editorEl.innerHTML.trim() === "" ||
      editorEl.innerHTML === "<br>" ||
      (childNodes.length === 1 && childNodes[0].nodeType === Node.TEXT_NODE) ||
      (!editorEl.querySelector("ul, ol") && (editorEl.textContent || "").trim() === "");

    if (isEmpty) {
      const div = document.createElement("div");
      div.innerHTML = "<br>";
      if (childNodes.length === 1 && childNodes[0].nodeType === Node.TEXT_NODE) {
        const textNode = childNodes[0].textContent;
        div.innerHTML = `${textNode}<br>`;
      }
      editorEl.innerHTML = "";
      editorEl.appendChild(div);

      const selection = window.getSelection();
      if (selection.rangeCount > 0) {
        const range = selection.getRangeAt(0);
        const commonAncestor = range.commonAncestorContainer;
        if (editorEl.contains(commonAncestor)) {
          const newRange = document.createRange();
          newRange.setStart(div, 0);
          newRange.setEnd(div, 0);
          selection.removeAllRanges();
          selection.addRange(newRange);
        }
      }
    }
  },
  _setHeightEditor (editor) {
    const editorEl = asElement(editor);
    if (!editorEl) {
      console.error("Editor non trovato");
      return;
    }

    const editorWrapper = editorEl.closest(".fm-editor-wrapper");
    const latexViewer = editorWrapper ? editorWrapper.querySelector(".fm-latex-viewer") : null;

    if (latexViewer && latexViewer.querySelector(".RenderMessage")) return;

    clearTimeout(this._latexViewerHeightTimeout);
    this._latexViewerHeightTimeout = setTimeout(() => {
      const latexViewerHeight = latexViewer ? latexViewer.getBoundingClientRect().height : 0;
      const scrollTop = editorEl.scrollTop;
      const currentEditorHeight = parseInt(getComputedStyle(editorEl).height) || 0;
      const savedHeight = elData(editorEl, "savedLatexViewerHeight") || 0;

      const closestContent = editorEl.closest(".content");
      if (closestContent) closestContent.style.maxHeight = "none";

      editorEl.style.height = "auto";
      const scrollHeight = editorEl.scrollHeight;
      const newHeight = scrollHeight + 20;

      if (latexViewerHeight === 0 && savedHeight > 0) {
        editorEl.style.height = `${savedHeight}px`;
        editorEl.style.overflowY = "auto";
        editorEl.scrollTop = scrollTop;
        return;
      }

      if (latexViewerHeight === 0) {
        if (currentEditorHeight > 0) {
          editorEl.style.height = `${currentEditorHeight}px`;
          editorEl.style.overflowY = "auto";
        } else {
          editorEl.style.maxHeight = "none";
          editorEl.style.height = `${newHeight}px`;
          editorEl.style.overflowY = "hidden";
        }
        editorEl.scrollTop = scrollTop;
        return;
      }

      // latexViewerHeight > 0 — pulisci savedHeight
      const store = _elData.get(editorEl);
      if (store) store.delete("savedLatexViewerHeight");

      if (currentEditorHeight !== latexViewerHeight) {
        editorEl.style.height = `${latexViewerHeight}px`;
        editorEl.style.overflowY = "auto";
        editorEl.scrollTop = scrollTop;
      }
    }, 50);
  },

  _minimizeEditor () {
    document.querySelectorAll(".editor").forEach((el) => { el.style.height = "20px"; });
  },

  _formatBorderType_wrapper (editor, show) {
    const editorEl = asElement(editor);
    if (!editorEl) return;
    const editorWrapper = editorEl.closest(".fm-editor-wrapper");
    const typeWrapper = editorWrapper ? editorWrapper.querySelector(".fm-type-wrapper") : null;
    if (!typeWrapper) return;
    if (show === 1) {
      Object.assign(typeWrapper.style, {
        borderTop: "2px solid #101010",
        borderRight: "2px solid #101010",
        borderLeft: "2px solid #101010",
        borderRadius: "5px 5px 0 0",
      });
    } else {
      Object.assign(typeWrapper.style, {
        borderTop: "inset 1px",
        borderRight: "inset 1.5px",
        borderLeft: "inset 1px",
        borderRadius: "0",
      });
    }
  },
  async execCmd (command, value = null) {
    if (command === "createLink") {
      const editor = document.activeElement; // Salva l'editor attivo PRIMA del prompt
      const url = await window.FM.Dialog.prompt("Inserisci URL:", "http://");
      if (url) {
        const selection = window.getSelection();
        let linkText = "";
        if (selection.rangeCount && !selection.isCollapsed) {
          linkText = selection.toString();
        } else {
          linkText = await window.FM.Dialog.prompt("Testo del link:", url);
        }
        if (linkText) {
          const range = selection.rangeCount ? selection.getRangeAt(0) : null;
          const anchor = document.createElement("a");
          anchor.href = url;
          anchor.textContent = linkText;
          anchor.target = "_blank";
          if (range) {
            range.deleteContents();
            range.insertNode(anchor);
          } else {
            // Usa l'editor salvato prima del prompt
            if (editor && editor.classList.contains("editor")) {
              editor.appendChild(anchor);
            }
          }
        }
      }
    }
  },
  insertSOLSpan () {
    const focusedEditorId = this.getFocusedEditorId();
    let editor;

    if (focusedEditorId) {
      editor = document.getElementById(focusedEditorId);
    }

    if (!editor) {
      editor = document.activeElement;
    }

    if (!editor || !editor.classList.contains("editor")) {
      console.error("Nessun editor attivo. Focused ID:", focusedEditorId);
      return;
    }

    const selection = window.getSelection();
    const range = selection.rangeCount > 0 ? selection.getRangeAt(0) : null;

    if (range && editor.contains(range.commonAncestorContainer)) {
      // Verifica se c'è del testo selezionato
      const selectedText = selection.toString().trim();

      const span = document.createElement("span");
      span.className = "fm-solution";

      if (selectedText) {
        // Se c'è testo selezionato, taglialo e usalo come contenuto dello span
        // Estrai il contenuto selezionato (con formattazione HTML se presente)
        const fragment = range.cloneContents();

        // Cancella il contenuto selezionato dall'editor (taglia)
        range.deleteContents();

        // Trasferisci il contenuto nel nuovo span
        while (fragment.firstChild) {
          span.appendChild(fragment.firstChild);
        }
      } else {
        // Se non c'è testo selezionato, usa il testo di default
        span.textContent = "(SOL)";
        range.deleteContents();
      }

      range.insertNode(span);

      // Posiziona il cursore dopo lo span
      range.setStartAfter(span);
      range.collapse(true);
      selection.removeAllRanges();
      selection.addRange(range);
    } else {
      // Se non c'è una selezione valida, inserisci alla fine
      const span = document.createElement("span");
      span.className = "fm-solution";
      span.textContent = "(SOL)";
      editor.appendChild(span);

      // Posiziona il cursore dopo lo span
      const range = document.createRange();
      const selection = window.getSelection();
      range.setStartAfter(span);
      range.collapse(true);
      selection.removeAllRanges();
      selection.addRange(range);
    }

    // Mantieni il focus sull'editor
    editor.focus();
  },
  // Find/Replace System
  _findReplaceState: {
    editorStates: new Map(), // Mappa editorId/editor -> {searchTerm, matches, currentIndex}
    currentEditorId: null,
    isVisible: false,
  },

  _getEditorKey (editor) {
    // Usa l'ID se disponibile, altrimenti usa l'oggetto editor stesso come chiave
    return editor.id || editor;
  },

  _getCurrentEditorState () {
    const editor = this._getActiveEditor();
    if (!editor) return null;

    const key = this._getEditorKey(editor);
    if (!this._findReplaceState.editorStates.has(key)) {
      this._findReplaceState.editorStates.set(key, {
        searchTerm: "",
        currentMatches: [],
        currentIndex: -1,
      });
    }
    return this._findReplaceState.editorStates.get(key);
  },

  _onEditorFocus (editor) {
    const key = this._getEditorKey(editor);
    const state = this._getCurrentEditorState();

    if (!state) return;

    // Aggiorna il campo findInput con il termine di ricerca di questo editor
    const panel = document.querySelector(".fm-find-replace-panel");
    if (panel && this._findReplaceState.isVisible) {
      const findInput = panel.querySelector(".fm-find-input");
      if (findInput) {
        findInput.value = state.searchTerm || "";
      }
      this._updateCounter();
    }
  },

  toggleFindReplace () {
    const panel = document.querySelector(".fm-find-replace-panel");
    if (!panel) return;

    // Prima apertura: inizializza i listener sul pannello
    if (!this._findReplaceListenersInit) {
      this.initFindReplaceListeners();
      this._findReplaceListenersInit = true;
    }

    this._findReplaceState.isVisible = !this._findReplaceState.isVisible;
    panel.style.display = this._findReplaceState.isVisible ? "block" : "none";

    if (this._findReplaceState.isVisible) {
      // Quando apri il pannello, carica lo stato dell'editor corrente
      const state = this._getCurrentEditorState();
      const findInput = panel.querySelector(".fm-find-input");
      if (findInput && state) {
        findInput.value = state.searchTerm || "";
      }
      if (findInput) findInput.focus();
    } else {
      // Pulisci le evidenziazioni di TUTTI gli editor quando chiudi il pannello
      this._clearAllHighlights();
    }
  },

  _clearHighlights () {
    const editor = this._getActiveEditor();
    if (!editor) return;

    const highlights = editor.querySelectorAll(".fm-find-highlight, .fm-find-highlight-active");
    highlights.forEach((span) => {
      const parent = span.parentNode;
      const text = document.createTextNode(span.textContent);
      parent.replaceChild(text, span);
      parent.normalize();
    });

    const state = this._getCurrentEditorState();
    if (state) {
      state.currentMatches = [];
      state.currentIndex = -1;
    }
    this._updateCounter();
  },

  _clearAllHighlights () {
    // Pulisci le evidenziazioni in TUTTI gli editor
    const allEditors = document.querySelectorAll(".editor");
    allEditors.forEach((editor) => {
      const highlights = editor.querySelectorAll(".fm-find-highlight, .fm-find-highlight-active");
      highlights.forEach((span) => {
        const parent = span.parentNode;
        const text = document.createTextNode(span.textContent);
        parent.replaceChild(text, span);
        parent.normalize();
      });
    });

    // Pulisci tutti gli stati degli editor
    this._findReplaceState.editorStates.clear();
    this._updateCounter();
  },

  _updateCounter () {
    const counter = document.querySelector(".fm-find-replace-counter");
    if (!counter) return;

    const state = this._getCurrentEditorState();
    if (!state || state.currentMatches.length === 0) {
      counter.textContent = "";
    } else {
      counter.textContent = `${state.currentIndex + 1} di ${state.currentMatches.length}`;
    }
  },

  _getSearchOptions () {
    const panel = document.querySelector(".fm-find-replace-panel");
    if (!panel) return { matchCase: false, wholeWord: false, useRegex: false };

    return {
      matchCase: panel.querySelector(".matchCaseCheckbox")?.checked || false,
      wholeWord: panel.querySelector(".wholeWordCheckbox")?.checked || false,
      useRegex: panel.querySelector(".useRegexCheckbox")?.checked || false,
    };
  },

  _performSearch (searchTerm) {
    this._clearHighlights();

    if (!searchTerm) return;

    const editor = this._getActiveEditor();
    if (!editor) return;

    const options = this._getSearchOptions();
    let pattern;

    try {
      if (options.useRegex) {
        pattern = new RegExp(searchTerm, options.matchCase ? "g" : "gi");
      } else {
        let escapedTerm = searchTerm.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
        if (options.wholeWord) {
          escapedTerm = `\\b${escapedTerm}\\b`;
        }
        pattern = new RegExp(escapedTerm, options.matchCase ? "g" : "gi");
      }
    } catch (e) {
      console.error("Errore nella regex:", e);
      return;
    }

    this._highlightMatches(editor, pattern);

    const state = this._getCurrentEditorState();
    if (state) {
      state.searchTerm = searchTerm;
      if (state.currentMatches.length > 0) {
        state.currentIndex = 0;
        this._scrollToMatch(0);
      }
    }

    this._updateCounter();
  },

  _highlightMatches (editor, pattern) {
    const walker = document.createTreeWalker(editor, NodeFilter.SHOW_TEXT, null, false);

    const nodesToReplace = [];
    let node;

    while ((node = walker.nextNode())) {
      const matches = [...node.textContent.matchAll(pattern)];
      if (matches.length > 0) {
        nodesToReplace.push({ node, matches });
      }
    }

    nodesToReplace.forEach(({ node, matches }) => {
      const fragment = document.createDocumentFragment();
      let lastIndex = 0;

      matches.forEach((match) => {
        const matchIndex = match.index;
        const matchText = match[0];

        if (matchIndex > lastIndex) {
          fragment.appendChild(document.createTextNode(node.textContent.substring(lastIndex, matchIndex)));
        }

        const span = document.createElement("span");
        span.className = "fm-find-highlight";
        span.textContent = matchText;
        fragment.appendChild(span);

        const state = this._getCurrentEditorState();
        if (state) {
          state.currentMatches.push(span);
        }

        lastIndex = matchIndex + matchText.length;
      });

      if (lastIndex < node.textContent.length) {
        fragment.appendChild(document.createTextNode(node.textContent.substring(lastIndex)));
      }

      node.parentNode.replaceChild(fragment, node);
    });
  },

  _scrollToMatch (index) {
    const state = this._getCurrentEditorState();
    if (!state || index < 0 || index >= state.currentMatches.length) return;

    // Rimuovi classe attiva da tutti
    state.currentMatches.forEach((match) => {
      match.classList.remove("fm-find-highlight-active");
      match.classList.add("fm-find-highlight");
    });

    // Aggiungi classe attiva al match corrente
    const currentMatch = state.currentMatches[index];
    currentMatch.classList.remove("fm-find-highlight");
    currentMatch.classList.add("fm-find-highlight-active");

    // Scrolla fino al match
    currentMatch.scrollIntoView({ behavior: "smooth", block: "center" });

    this._updateCounter();
  },

  findNext () {
    const panel = document.querySelector(".fm-find-replace-panel");
    if (!panel) return;

    const state = this._getCurrentEditorState();
    if (!state) return;

    const searchTerm = panel.querySelector(".fm-find-input")?.value;

    if (searchTerm !== state.searchTerm) {
      this._performSearch(searchTerm);
      return;
    }

    if (state.currentMatches.length === 0) return;

    state.currentIndex = (state.currentIndex + 1) % state.currentMatches.length;
    this._scrollToMatch(state.currentIndex);
  },

  findPrevious () {
    const panel = document.querySelector(".fm-find-replace-panel");
    if (!panel) return;

    const state = this._getCurrentEditorState();
    if (!state) return;

    const searchTerm = panel.querySelector(".fm-find-input")?.value;

    if (searchTerm !== state.searchTerm) {
      this._performSearch(searchTerm);
      return;
    }

    if (state.currentMatches.length === 0) return;

    state.currentIndex = (state.currentIndex - 1 + state.currentMatches.length) % state.currentMatches.length;
    this._scrollToMatch(state.currentIndex);
  },

  replace () {
    const panel = document.querySelector(".fm-find-replace-panel");
    if (!panel) return;

    const state = this._getCurrentEditorState();
    if (!state) return;

    const replaceText = panel.querySelector(".fm-replace-input")?.value || "";

    if (state.currentIndex < 0 || state.currentIndex >= state.currentMatches.length) {
      return;
    }

    const currentMatch = state.currentMatches[state.currentIndex];
    const textNode = document.createTextNode(replaceText);
    currentMatch.parentNode.replaceChild(textNode, currentMatch);

    // Rimuovi il match dall'array
    state.currentMatches.splice(state.currentIndex, 1);

    // Passa al prossimo match
    if (state.currentMatches.length > 0) {
      if (state.currentIndex >= state.currentMatches.length) {
        state.currentIndex = 0;
      }
      this._scrollToMatch(state.currentIndex);
    } else {
      state.currentIndex = -1;
      this._updateCounter();
    }
  },

  replaceAll () {
    const panel = document.querySelector(".fm-find-replace-panel");
    if (!panel) return;

    const state = this._getCurrentEditorState();
    if (!state) return;

    const replaceText = panel.querySelector(".fm-replace-input")?.value || "";

    if (state.currentMatches.length === 0) return;

    // Sostituisci tutti i match
    state.currentMatches.forEach((match) => {
      const textNode = document.createTextNode(replaceText);
      match.parentNode.replaceChild(textNode, match);
    });

    state.currentMatches = [];
    state.currentIndex = -1;
    this._updateCounter();
  },

  _getActiveEditor () {
    const focusedEditorId = this.getFocusedEditorId();
    let editor;

    if (focusedEditorId) {
      editor = document.getElementById(focusedEditorId);
    }

    if (!editor) {
      editor = document.activeElement;
    }

    if (!editor || !editor.classList.contains("editor")) {
      // Prendi il primo editor disponibile
      editor = document.querySelector(".editor");
    }

    return editor;
  },

  initFindReplaceListeners () {
    const panel = document.querySelector(".fm-find-replace-panel");
    if (!panel) return;

    const findInput = panel.querySelector(".fm-find-input");
    const replaceInput = panel.querySelector(".fm-replace-input");
    const checkboxes = panel.querySelectorAll(".matchCaseCheckbox, .wholeWordCheckbox, .useRegexCheckbox");

    // Cerca mentre l'utente digita (con debounce)
    let searchTimeout;
    if (findInput) {
      findInput.addEventListener("input", (e) => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
          this._performSearch(e.target.value);
        }, 300);
      });

      // Cerca al premere Invio
      findInput.addEventListener("keydown", (e) => {
        if (e.key === "Enter") {
          e.preventDefault();
          if (e.shiftKey) {
            this.findPrevious();
          } else {
            this.findNext();
          }
        } else if (e.key === "Escape") {
          this.toggleFindReplace();
        }
      });
    }

    // Sostituisci al premere Invio nel campo replace
    if (replaceInput) {
      replaceInput.addEventListener("keydown", (e) => {
        if (e.key === "Enter") {
          e.preventDefault();
          if (e.ctrlKey || e.metaKey) {
            this.replaceAll();
          } else {
            this.replace();
          }
        } else if (e.key === "Escape") {
          this.toggleFindReplace();
        }
      });
    }

    // Riesegui ricerca quando cambiano le opzioni
    checkboxes.forEach((checkbox) => {
      checkbox.addEventListener("change", () => {
        if (findInput && findInput.value) {
          this._performSearch(findInput.value);
        }
      });
    });
  },

  insertDSASpan () {
    const focusedEditorId = this.getFocusedEditorId();
    let editor;

    if (focusedEditorId) {
      editor = document.getElementById(focusedEditorId);
    }

    if (!editor) {
      editor = document.activeElement;
    }

    if (!editor || !editor.classList.contains("editor")) {
      console.error("Nessun editor attivo. Focused ID:", focusedEditorId);
      return;
    }

    const selection = window.getSelection();
    const range = selection.rangeCount > 0 ? selection.getRangeAt(0) : null;

    if (range && editor.contains(range.commonAncestorContainer)) {
      const span = document.createElement("span");
      span.className = "fm-add-text-dsa";
      span.textContent = "(*DSA*)";

      range.deleteContents();
      range.insertNode(span);

      // Posiziona il cursore dopo lo span
      range.setStartAfter(span);
      range.collapse(true);
      selection.removeAllRanges();
      selection.addRange(range);
    } else {
      // Se non c'è una selezione valida, inserisci alla fine
      const span = document.createElement("span");
      span.className = "fm-add-text-dsa";
      span.textContent = "(*DSA*)";
      editor.appendChild(span);

      // Posiziona il cursore dopo lo span
      const range = document.createRange();
      const selection = window.getSelection();
      range.setStartAfter(span);
      range.collapse(true);
      selection.removeAllRanges();
      selection.addRange(range);
    }

    // Mantieni il focus sull'editor
    editor.focus();
  },
  _insertElement (elementHtml, editorID) {
    // Pulisci l'HTML rimuovendo &nbsp; e <br> all'inizio e alla fine
    const cleanedHtml = elementHtml
      // Rimuovi &nbsp; e <br> all'inizio
      .replace(/^(\s*(&nbsp;|\s|<br\s*\/?>)*)+/gi, "")
      // Rimuovi &nbsp; e <br> alla fine
      .replace(/(\s*(&nbsp;|\s|<br\s*\/?>)*)+$/gi, "")
      // Rimuovi eventuali spazi multipli consecutivi
      // .replace(/(&nbsp;){2,}/g, '&nbsp;')
      // Rimuovi <br> multipli consecutivi
      .replace(/(<br\s*\/?>){2,}/gi, "<br>");

    console.log(`Inserimento elemento: ${cleanedHtml}, Editor ID: ${editorID}`);
    const editor = document.getElementById(editorID);
    if (editor) {
      const selection = window.getSelection();
      const range = selection.rangeCount > 0 ? selection.getRangeAt(0) : null;

      if (range && editor.contains(range.commonAncestorContainer)) {
        range.deleteContents();
        const element = document.createElement("div");
        element.innerHTML = cleanedHtml;
        const frag = document.createDocumentFragment();
        let node;
        let lastNode = null;
        while ((node = element.firstChild)) {
          lastNode = frag.appendChild(node);
        }
        range.insertNode(frag);
        if (lastNode) {
          range.setStartAfter(lastNode);
          range.collapse(true);
          selection.removeAllRanges();
          selection.addRange(range);
        }

        // Evidenzia i commenti LaTeX dopo l'inserimento
        this.highlightLatexComments(editor);
      } else {
        console.error("La selezione non è all'interno dell'editor specificato.");
      }
    } else {
      console.error(`Editor con ID ${editorID} non trovato.`);
    }
  },

  _insertPlainText (plainText, editorID) {
    console.log(`Inserimento testo puro (${plainText.length} caratteri) in Editor ID: ${editorID}`);
    const editor = document.getElementById(editorID);
    if (editor) {
      const selection = window.getSelection();
      const range = selection.rangeCount > 0 ? selection.getRangeAt(0) : null;

      if (range && editor.contains(range.commonAncestorContainer)) {
        range.deleteContents();

        // Dividi in righe e crea un fragment con testo formattato + <br>
        const lines = plainText.split("\n");
        const frag = document.createDocumentFragment();

        lines.forEach((line, idx) => {
          // Preserva gli spazi e escape HTML
          const formattedLine = line
            .replace(/ /g, "\u00A0") // Converti spazi in non-breaking spaces
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;");

          // Crea un nodo div per contenere la riga formattata
          const div = document.createElement("div");
          div.innerHTML = formattedLine;
          frag.appendChild(div);

          // Aggiungi <br> tranne per l'ultima riga
          //   if (idx < lines.length - 1) {
          //     const br = document.createElement("br");
          //     frag.appendChild(br);
          //   }
        });

        range.insertNode(frag);

        // Posiziona il cursore alla fine
        range.collapse(false);
        selection.removeAllRanges();
        selection.addRange(range);

        // Evidenzia i commenti LaTeX dopo l'inserimento
        this.highlightLatexComments(editor);

        console.log("Testo inserito con successo con formattazione e rientri");
      } else {
        console.error("La selezione non è all'interno dell'editor specificato.");
      }
    } else {
      console.error(`Editor con ID ${editorID} non trovato.`);
    }
  },

  _insertLargeContent (lines, editorID) {
    console.log(`Inserimento contenuto grande (${lines.length} righe) in modo asincrono`);
    const editor = document.getElementById(editorID);
    if (!editor) {
      console.error(`Editor con ID ${editorID} non trovato.`);
      return;
    }

    const selection = window.getSelection();
    const range = selection.rangeCount > 0 ? selection.getRangeAt(0) : null;

    if (!range || !editor.contains(range.commonAncestorContainer)) {
      console.error("La selezione non è all'interno dell'editor specificato.");
      return;
    }

    range.deleteContents();

    // Crea un container temporaneo
    const container = document.createElement("div");
    container.style.display = "inline";
    range.insertNode(container);

    // Inserisci le righe a pezzi di 20 alla volta
    const chunkSize = 20;
    let currentIndex = 0;

    function insertChunk() {
      const endIndex = Math.min(currentIndex + chunkSize, lines.length);
      const frag = document.createDocumentFragment();

      for (let i = currentIndex; i < endIndex; i++) {
        const line = lines[i];
        const escaped = line.replace(/ /g, "\u00A0").replace(/</g, "&lt;").replace(/>/g, "&gt;");

        // if (i === 0 && currentIndex === 0) {
        //   // Prima riga senza div
        //   const span = document.createElement("span");
        //   span.innerHTML = escaped || "\u00A0"; // Se vuota, inserisci uno spazio
        //   frag.appendChild(span);
        // } else {
        const div = document.createElement("div");
        // Usa innerHTML per preservare gli &nbsp; e inserisci almeno uno spazio per righe vuote
        div.innerHTML = escaped || "\u00A0";
        frag.appendChild(div);
        // }
      }

      container.appendChild(frag);
      currentIndex = endIndex;

      if (currentIndex < lines.length) {
        // Continua con il prossimo chunk
        setTimeout(insertChunk, 0);
      } else {
        // Finito - sostituisci il container con il suo contenuto
        while (container.firstChild) {
          container.parentNode.insertBefore(container.firstChild, container);
        }
        container.parentNode.removeChild(container);

        // Evidenzia i commenti LaTeX dopo l'inserimento completo
        EditorSystem.highlightLatexComments(editor);

        console.log("Inserimento completato");
      }
    }

    insertChunk();
  },

  // Apre il pannello Find/Replace e pre-popola con il testo selezionato (se presente)
  _openFindReplace (selectedText) {
    if (!this._findReplaceState.isVisible) {
      this.toggleFindReplace();
    }
    const panel = document.querySelector(".fm-find-replace-panel");
    if (!panel) return;
    const findInput = panel.querySelector(".fm-find-input");
    if (!findInput) return;
    if (selectedText) {
      findInput.value = selectedText;
      this._performSearch(selectedText);
    }
    findInput.focus();
  },

  /**
   * Inizializza event delegation globale per input/focus/blur su TUTTI gli .editor.
   * Usa $(document).on(event, ".editor", handler) così qualsiasi .editor aggiunto
   * dinamicamente (clone righe/colonne, AJAX, ecc.) funziona senza rebind manuale.
   * Chiamare UNA SOLA VOLTA all'avvio.
   */
  _initEditorEventDelegation () {
    if (this._editorDelegationInitialized) return;
    this._editorDelegationInitialized = true;
    const self = this;

    // ── SELECTIONCHANGE: aggiorna cursore nel top-of-stack se HTML non è cambiato ──
    document.addEventListener("selectionchange", () => {
      if (self._isPerformingUndoRedo) return;
      const editorId = self._state.focusedEditorId;
      if (!editorId) return;
      const stack = self._undoStack.get(editorId);
      if (!stack || stack.length === 0) return;
      const editor = document.getElementById(editorId);
      if (!editor) return;
      const topState = stack[stack.length - 1];
      if (topState.html !== editor.innerHTML) return; // HTML cambiato, non aggiornare
      const sel = window.getSelection();
      if (sel.rangeCount === 0) return;
      const anchor = sel.getRangeAt(0).startContainer;
      if (!editor.contains(anchor)) return; // cursore fuori dall'editor
      topState.cursorNode = DomManager.getNodePath(anchor);
      topState.cursorOffset = sel.getRangeAt(0).startOffset;
    });

    // ── INPUT ──────────────────────────────────────────────
    onNs(document, "input", "editorCore", (e) => {
      const editorEl = e.target.closest?.(".editor");
      if (!editorEl) return;
      const currentEditorId = editorEl.id;

      if (!self._isPerformingUndoRedo && currentEditorId) {
        if (self._inputTimers.has(currentEditorId)) {
          clearTimeout(self._inputTimers.get(currentEditorId));
        }
        const timer = setTimeout(() => {
          self._saveUndoState(currentEditorId);
          self._inputTimers.delete(currentEditorId);
        }, 100);
        self._inputTimers.set(currentEditorId, timer);
      }

      const InTikz = self._checkCursorPositionInTikz(editorEl);
      self._ensureEditorNotEmpty(editorEl);
      self.highlightLatexComments(editorEl);

      // Debounce per-editor del generateTexPreview (elData WeakMap-based)
      const prevTimeout = elData(editorEl, "_previewTimeout");
      if (prevTimeout) clearTimeout(prevTimeout);
      elData(
        editorEl,
        "_previewTimeout",
        setTimeout(() => {
          LatexRender.generateTexPreview(editorEl, InTikz);
        }, 300),
      );

      TableManager.adjustRowActionHeight(editorEl);
    });

    // ── FOCUS ─────────────────────────────────────────────
    // focus non bubbla nativamente → uso focusin (bubbla)
    onNs(document, "focusin", "editorCore", (e) => {
      const editorEl = e.target.closest?.(".editor");
      if (!editorEl) return;
      const currentEditorId = editorEl.id;

      self.setFocusedEditorId(currentEditorId);
      self._formatBorderType_wrapper(editorEl, 1);
      self._ensureEditorNotEmpty(editorEl);

      if (self._findReplaceState && self._findReplaceState.isVisible) {
        self._onEditorFocus(editorEl);
      }

      if (currentEditorId) {
        const undoStack = self._undoStack.get(currentEditorId);
        if (!undoStack || undoStack.length === 0) {
          self._saveUndoState(currentEditorId);
        }
      }

      DataManager.startBackupInterval();
    });

    // ── KEYDOWN (Ctrl+F → apri Find/Replace) ──────────────
    onNs(document, "keydown", "editorCore", (e) => {
      if (!e.target.closest?.(".editor")) return;
      if ((e.ctrlKey || e.metaKey) && e.key === "f") {
        e.preventDefault();
        self._openFindReplace(window.getSelection().toString());
      }
    });

    // ── BLUR ──────────────────────────────────────────────
    // blur non bubbla nativamente → uso focusout (bubbla)
    onNs(document, "focusout", "editorCore", (e) => {
      const editorEl = e.target.closest?.(".editor");
      if (!editorEl) return;
      const currentEditorId = editorEl.id;

      if (self._inputTimers.has(currentEditorId)) {
        clearTimeout(self._inputTimers.get(currentEditorId));
        self._inputTimers.delete(currentEditorId);
      }
      self._saveUndoState(currentEditorId);

      self._formatBorderType_wrapper(editorEl, 0);
      LatexRender.generateTexPreview(editorEl, 0);
      self._setHeightEditor(editorEl);

      DomManager.saveSelection();
      DataManager.stopBackupInterval();
    });

    // ── DOUBLE-CLICK su .latex-viewer → posiziona cursore nel .editor ──
    // Click su formula (mjx-container)  → N-esimo \( nell'editor
    // Click su testo normale            → N-esima occorrenza della parola
    //                                     (saltando il contenuto dentro \(...\))
    (function initLatexViewerSync() {
      // Raccoglie i (textNode, offset, char) del contenuto interno dell'N-esimo \(...\)
      function collectNthMathContent(editorEl, nth) {
        const walker = document.createTreeWalker(editorEl, NodeFilter.SHOW_TEXT, null, false);
        const all = [];
        let node;
        while ((node = walker.nextNode())) {
          const text = node.nodeValue || "";
          for (let i = 0; i < text.length; i++) {
            all.push({ node, offset: i, char: text[i] });
          }
        }
        let count = 0;
        for (let i = 0; i < all.length - 1; i++) {
          if (all[i].char === "\\" && all[i + 1].char === "(") {
            if (count === nth) {
              const openIdx = i + 2;
              for (let j = openIdx; j < all.length - 1; j++) {
                if (all[j].char === "\\" && all[j + 1].char === ")") {
                  return { content: all.slice(openIdx, j), openMarker: all[i] };
                }
              }
              return { content: all.slice(openIdx), openMarker: all[i] };
            }
            count++;
          }
        }
        return null;
      }

      // Comandi strutturali: NON sono leaf; i loro argomenti sono espressioni
      // che verranno tokenizzate normalmente (es. \frac{a}{b} → a, b).
      const STRUCTURAL_CMDS = new Set([
        "frac","dfrac","tfrac","cfrac","binom","dbinom","tbinom",
        "sqrt","vec","hat","bar","tilde","dot","ddot","breve","check","acute","grave","widehat","widetilde",
        "overline","underline","overbrace","underbrace","overrightarrow","overleftarrow","overleftrightarrow",
        "mathbf","mathrm","mathit","mathsf","mathtt","mathbb","mathcal","mathfrak","boldsymbol","bm",
        "left","right","middle","bigl","bigr","Bigl","Bigr","biggl","biggr","Biggl","Biggr","big","Big","bigg","Bigg",
        "displaystyle","textstyle","scriptstyle","scriptscriptstyle",
        "phantom","hphantom","vphantom","mathstrut","smash",
        "limits","nolimits",
        "stackrel","overset","underset",
        "cancel","bcancel","xcancel","cancelto",
        "not",
        "nonumber","notag",
      ]);

      // Comandi il cui intero argomento `{...}` è reso come UNA sola leaf (mtext).
      const TEXT_ARG_CMDS = new Set([
        "text","textbf","textit","textrm","textsf","texttt","textnormal","textup","textsc",
        "mbox","hbox",
        "operatorname","operatornamewithlimits",
        "mathop",
      ]);

      // Comandi i cui argomenti devono essere SCARTATI (non tokenizzati): { da saltare, ... }
      // La lista (`skip: N`) indica quanti argomenti obbligatori `{...}` consumare.
      // `greedy: true` consuma tutti i `{...}` / `[...]` successivi (per \begin / \end).
      // `passthrough: N` salta N argomenti, poi lascia il resto alla tokenizzazione.
      const ENV_CMDS = new Set(["begin", "end"]);
      const CMDS_SKIP_ARGS = {
        color: 1,
        label: 1,
        tag: 1,
        rule: 2,
        hspace: 1,
        vspace: 1,
        kern: 1,
        mkern: 1,
      };
      // Consuma N brace-args poi riprende la tokenizzazione normale (utile per color-box).
      const CMDS_PASSTHROUGH_AFTER = {
        colorbox: 1,
        textcolor: 1,
        fcolorbox: 2,
      };

      function tokenizeLatexAtoms(src) {
        const atoms = [];
        const n = src.length;
        let i = 0;

        function skipWs(from) {
          let j = from;
          while (j < n && (src[j] === " " || src[j] === "\t" || src[j] === "\n" || src[j] === "\r")) j++;
          return j;
        }

        function skipBalancedBraces(from) {
          if (from >= n || src[from] !== "{") return from;
          let depth = 1;
          let j = from + 1;
          while (j < n && depth > 0) {
            const ch = src[j];
            if (ch === "\\" && j + 1 < n) { j += 2; continue; }
            if (ch === "{") depth++;
            else if (ch === "}") depth--;
            j++;
          }
          return j;
        }

        function skipBalancedBrackets(from) {
          if (from >= n || src[from] !== "[") return from;
          let depth = 1;
          let j = from + 1;
          while (j < n && depth > 0) {
            const ch = src[j];
            if (ch === "\\" && j + 1 < n) { j += 2; continue; }
            if (ch === "[") depth++;
            else if (ch === "]") depth--;
            j++;
          }
          return j;
        }

        function consumeNArgs(from, count) {
          let j = skipWs(from);
          let remaining = count;
          while (remaining > 0 && j < n) {
            if (src[j] === "[") { j = skipBalancedBrackets(j); j = skipWs(j); continue; }
            if (src[j] !== "{") break;
            j = skipBalancedBraces(j);
            j = skipWs(j);
            remaining--;
          }
          return j;
        }

        function consumeAllBraceArgs(from) {
          let j = skipWs(from);
          while (j < n && (src[j] === "{" || src[j] === "[")) {
            j = src[j] === "{" ? skipBalancedBraces(j) : skipBalancedBrackets(j);
            j = skipWs(j);
          }
          return j;
        }

        while (i < n) {
          const c = src[i];
          if (c === " " || c === "\t" || c === "\n" || c === "\r") { i++; continue; }
          if (c === "{" || c === "}" || c === "^" || c === "_" || c === "&") { i++; continue; }
          if (c === "$") { i++; continue; } // toggle di modalità, non è una leaf
          if (c === "\\") {
            // Lettera → nome comando
            if (i + 1 < n && /[a-zA-Z]/.test(src[i + 1])) {
              let j = i + 1;
              while (j < n && /[a-zA-Z]/.test(src[j])) j++;
              const name = src.substring(i + 1, j);
              const cmdStart = i;
              i = j;
              if (ENV_CMDS.has(name)) {
                // \begin{env}[opts]{colspec} ecc. → consuma TUTTI i gruppi {...}/[...] successivi
                i = consumeAllBraceArgs(i);
                continue;
              }
              if (Object.prototype.hasOwnProperty.call(CMDS_SKIP_ARGS, name)) {
                i = consumeNArgs(i, CMDS_SKIP_ARGS[name]);
                continue;
              }
              if (Object.prototype.hasOwnProperty.call(CMDS_PASSTHROUGH_AFTER, name)) {
                // es. \colorbox{color}{content}: salta i primi N arg, lascia il resto
                i = consumeNArgs(i, CMDS_PASSTHROUGH_AFTER[name]);
                continue;
              }
              if (TEXT_ARG_CMDS.has(name)) {
                // 1 atomo = intero {arg}
                let k = skipWs(i);
                // salta eventuale optional [..] (raro per \text)
                if (k < n && src[k] === "[") { k = skipBalancedBrackets(k); k = skipWs(k); }
                if (k < n && src[k] === "{") {
                  const braceStart = k;
                  const braceEnd = skipBalancedBraces(k);
                  atoms.push({ start: braceStart, end: braceEnd });
                  i = braceEnd;
                } else {
                  atoms.push({ start: cmdStart, end: j });
                }
                continue;
              }
              if (STRUCTURAL_CMDS.has(name)) {
                // Nessun atomo: gli argomenti verranno tokenizzati dal main loop.
                continue;
              }
              // Comando leaf (\alpha, \sum, \sin, \pi, \int, \infty, \cup, \le, ...)
              atoms.push({ start: cmdStart, end: j });
              continue;
            }
            // \\ → line break (array/cases), non è leaf; salta anche \\[dim]
            if (i + 1 < n && src[i + 1] === "\\") {
              i += 2;
              let k = skipWs(i);
              if (k < n && src[k] === "*") k++;
              k = skipWs(k);
              if (k < n && src[k] === "[") k = skipBalancedBrackets(k);
              i = k;
              continue;
            }
            // Spaziature invisibili: \, \! \; \: \<spazio>
            if (i + 1 < n) {
              const nxt = src[i + 1];
              if (nxt === "," || nxt === "!" || nxt === ";" || nxt === ":" || nxt === " ") {
                i += 2;
                continue;
              }
              // Carattere escaped (\$, \%, \#, \{, \}, ...) → 1 atomo
              atoms.push({ start: i, end: i + 2 });
              i += 2;
              continue;
            }
            i++;
            continue;
          }
          // Carattere visibile singolo
          atoms.push({ start: i, end: i + 1 });
          i++;
        }
        return atoms;
      }

      // Selettore delle "leaf" MathJax per output CHTML e SVG
      const MJX_LEAF_SELECTOR =
        'mjx-mi, mjx-mn, mjx-mo, mjx-mtext,' +
        ' [data-mml-node="mi"], [data-mml-node="mn"], [data-mml-node="mo"], [data-mml-node="mtext"]';

      // Mapping nome-comando LaTeX → simbolo Unicode reso da MathJax.
      const LATEX_TO_SYMBOL = {
        alpha:"α",beta:"β",gamma:"\(\gamma\)",delta:"δ",epsilon:"ϵ",varepsilon:"ε",
        zeta:"ζ",eta:"η",theta:"θ",vartheta:"ϑ",iota:"ι",kappa:"κ",
        lambda:"λ",mu:"μ",nu:"ν",xi:"ξ",omicron:"ο",pi:"π",varpi:"ϖ",
        rho:"ρ",varrho:"ϱ",sigma:"σ",varsigma:"ς",tau:"τ",
        upsilon:"υ",phi:"ϕ",varphi:"φ",chi:"χ",psi:"ψ",omega:"ω",
        Gamma:"Γ",Delta:"Δ",Theta:"Θ",Lambda:"Λ",Xi:"Ξ",Pi:"Π",
        Sigma:"Σ",Upsilon:"Υ",Phi:"Φ",Psi:"Ψ",Omega:"Ω",
        times:"×",div:"÷",cdot:"⋅",ast:"∗",star:"⋆",circ:"∘",bullet:"∙",
        pm:"±",mp:"∓",oplus:"⊕",ominus:"⊖",otimes:"⊗",oslash:"⊘",odot:"⊙",
        le:"≤",leq:"≤",ge:"≥",geq:"≥",neq:"≠",ne:"≠",approx:"≈",
        equiv:"≡",sim:"∼",simeq:"≃",cong:"≅",propto:"∝",
        ll:"≪",gg:"≫",prec:"≺",succ:"≻",preceq:"⪯",succeq:"⪰",
        cup:"∪",cap:"∩",sqcup:"⊔",sqcap:"⊓",uplus:"⊎",
        subset:"⊂",subseteq:"⊆",supset:"⊃",supseteq:"⊇",
        in:"∈",notin:"∉",ni:"∋",
        to:"→",rightarrow:"→",Rightarrow:"⇒",leftarrow:"←",Leftarrow:"⇐",
        leftrightarrow:"↔",Leftrightarrow:"⇔",mapsto:"↦",longmapsto:"⟼",
        uparrow:"↑",downarrow:"↓",Uparrow:"⇑",Downarrow:"⇓",
        forall:"∀",exists:"∃",nexists:"∄",neg:"¬",lnot:"¬",
        land:"∧",lor:"∨",wedge:"∧",vee:"∨",top:"⊤",bot:"⊥",
        infty:"∞",partial:"∂",nabla:"∇",
        sum:"∑",prod:"∏",coprod:"∐",int:"∫",iint:"∬",iiint:"∭",oint:"∮",
        bigcup:"⋃",bigcap:"⋂",bigoplus:"⨁",bigotimes:"⨂",
        aleph:"ℵ",beth:"ℶ",Re:"ℜ",Im:"ℑ",hbar:"ℏ",ell:"ℓ",wp:"℘",
        emptyset:"∅",varnothing:"∅",
        dots:"…",ldots:"…",cdots:"⋯",vdots:"⋮",ddots:"⋱",
        prime:"′",backslash:"\\",
        lfloor:"⌊",rfloor:"⌋",lceil:"⌈",rceil:"⌉",langle:"⟨",rangle:"⟩",
        quad:" ",qquad:"  ",
      };

      // Funzioni operatore che MathJax rende col proprio nome testuale.
      const FUNCTION_NAMES = new Set([
        "sin","cos","tan","cot","sec","csc",
        "arcsin","arccos","arctan","arccot",
        "sinh","cosh","tanh","coth",
        "log","ln","lg","exp",
        "lim","liminf","limsup","sup","inf","max","min",
        "det","dim","ker","arg","deg","gcd","hom","Pr","mod",
      ]);

      // Testo reso atteso per un atomo del sorgente LaTeX.
      function atomRenderedText(atom, src) {
        const raw = src.substring(atom.start, atom.end);
        if (raw.length >= 2 && raw.charAt(0) === "{" && raw.charAt(raw.length - 1) === "}") {
          return raw.substring(1, raw.length - 1).replace(/\s+/g, " ").trim();
        }
        if (raw.charAt(0) === "\\") {
          const name = raw.substring(1);
          if (/^[a-zA-Z]+$/.test(name)) {
            if (Object.prototype.hasOwnProperty.call(LATEX_TO_SYMBOL, name)) return LATEX_TO_SYMBOL[name];
            if (FUNCTION_NAMES.has(name)) return name;
            return name;
          }
          return name;
        }
        return raw;
      }

      // Normalizza stringhe per confronto (minus Unicode, spazi, ecc.).
      function normText(s) {
        return String(s == null ? "" : s)
          .replace(/[\u2212\u2010\u2011\u2012\u2013\u2014]/g, "-")
          .replace(/\u00A0/g, " ")
          .replace(/\s+/g, " ")
          .trim();
      }

      // Trova la leaf cliccata: ancestor del target, o quella più vicina al puntatore
      function findClickedLeaf(mjxContainer, target, clientX, clientY) {
        let el = target;
        while (el && el !== mjxContainer) {
          if (el.matches && el.matches(MJX_LEAF_SELECTOR)) return el;
          el = el.parentElement;
        }
        const leaves = mjxContainer.querySelectorAll(MJX_LEAF_SELECTOR);
        if (!leaves.length) return null;
        let best = null;
        let bestDist = Infinity;
        for (let k = 0; k < leaves.length; k++) {
          const r = leaves[k].getBoundingClientRect();
          if (!r.width && !r.height) continue;
          const dx = Math.max(r.left - clientX, 0, clientX - r.right);
          const dy = Math.max(r.top - clientY, 0, clientY - r.bottom);
          const d = dx * dx + dy * dy;
          if (d < bestDist) { bestDist = d; best = leaves[k]; }
        }
        return best;
      }

      // Cursore nella N-esima formula \(...\): identifica la leaf cliccata e
      // cerca l'occorrenza corrispondente nel sorgente usando il matching
      // per testo reso (più robusto del matching per indice DOM↔atomo).
      function findMathRangeInEditor(editorEl, nth, mjxContainer, clientX, clientY, target) {
        const data = collectNthMathContent(editorEl, nth);
        if (!data) return null;
        const content = data.content;
        if (!content.length) {
          const r = document.createRange();
          r.setStart(data.openMarker.node, data.openMarker.offset);
          r.setEnd(data.openMarker.node, data.openMarker.offset);
          return r;
        }

        const src = content.map((c) => { return c.char; }).join("");
        const atoms = tokenizeLatexAtoms(src);
        if (!atoms.length) return null;

        // Normalizza il target se è un text node
        let targetEl = target;
        if (targetEl && targetEl.nodeType === Node.TEXT_NODE) targetEl = targetEl.parentElement;

        const leaf = findClickedLeaf(mjxContainer, targetEl, clientX, clientY);
        const allLeaves = mjxContainer.querySelectorAll(MJX_LEAF_SELECTOR);

        let charIdx = -1;

        if (leaf) {
          const leafText = normText(leaf.textContent);
          const leafIdx = Array.prototype.indexOf.call(allLeaves, leaf);

          // Conta quante leaf precedenti hanno lo stesso testo reso
          let prevSameText = 0;
          for (let k = 0; k < leafIdx; k++) {
            if (normText(allLeaves[k].textContent) === leafText) prevSameText++;
          }

          // Trova la (prevSameText+1)-esima occorrenza dello stesso testo negli atomi
          let seen = 0;
          for (let k = 0; k < atoms.length; k++) {
            const at = normText(atomRenderedText(atoms[k], src));
            if (at === leafText) {
              if (seen === prevSameText) { charIdx = atoms[k].start; break; }
              seen++;
            }
          }

          // Fallback 1: match per indice (atoms[leafIdx])
          if (charIdx < 0 && leafIdx >= 0 && leafIdx < atoms.length) {
            charIdx = atoms[leafIdx].start;
          }
        }

        // Fallback 2: ratio orizzontale sul container
        if (charIdx < 0) {
          const rect = mjxContainer.getBoundingClientRect();
          const ratio = rect.width > 0
            ? Math.max(0, Math.min(1, (clientX - rect.left) / rect.width))
            : 0;
          const atomIdx = Math.min(atoms.length - 1, Math.floor(ratio * atoms.length));
          charIdx = atoms[atomIdx].start;
        }

        const targetChar = content[Math.max(0, Math.min(charIdx, content.length - 1))];
        const range = document.createRange();
        range.setStart(targetChar.node, targetChar.offset);
        range.setEnd(targetChar.node, targetChar.offset);
        return range;
      }

      function findNthTextRangeInEditor(editorEl, word, nth) {
        if (!word) return null;
        const walker = document.createTreeWalker(editorEl, NodeFilter.SHOW_TEXT, null, false);
        let count = 0;
        let inMath = false;
        let node;
        while ((node = walker.nextNode())) {
          const text = node.nodeValue || "";
          let i = 0;
          while (i < text.length) {
            if (!inMath) {
              const nextOpen = text.indexOf("\\(", i);
              const segEnd = nextOpen === -1 ? text.length : nextOpen;
              let segFrom = i;
              while (segFrom < segEnd) {
                const pos = text.indexOf(word, segFrom);
                if (pos === -1 || pos >= segEnd) break;
                if (count === nth) {
                  const r = document.createRange();
                  r.setStart(node, pos);
                  r.setEnd(node, pos + word.length);
                  return r;
                }
                count++;
                segFrom = pos + word.length;
              }
              if (nextOpen === -1) { i = text.length; }
              else { i = nextOpen + 2; inMath = true; }
            } else {
              const nextClose = text.indexOf("\\)", i);
              if (nextClose === -1) { i = text.length; }
              else { i = nextClose + 2; inMath = false; }
            }
          }
        }
        return null;
      }

      function countWordBeforeInViewer(viewerEl, word, anchorNode, anchorOffset) {
        if (!word) return 0;
        const walker = document.createTreeWalker(
          viewerEl,
          NodeFilter.SHOW_TEXT,
          {
            acceptNode (n) {
              if (n.parentElement && n.parentElement.closest("mjx-container, svg[data-tikz-script-id]")) {
                return NodeFilter.FILTER_REJECT;
              }
              return NodeFilter.FILTER_ACCEPT;
            },
          },
          false,
        );
        let prefix = "";
        let reached = false;
        let node;
        while ((node = walker.nextNode())) {
          if (node === anchorNode) {
            prefix += (node.nodeValue || "").slice(0, anchorOffset);
            reached = true;
            break;
          }
          prefix += node.nodeValue || "";
        }
        if (!reached && anchorNode && anchorNode.nodeType === Node.ELEMENT_NODE) {
          // Fallback: accumula fino a che l'element contiene il walker node
          // (nel caso il click sia su un elemento, non su un text node)
        }
        let count = 0;
        let from = 0;
        while (true) {
          const p = prefix.indexOf(word, from);
          if (p === -1) break;
          count++;
          from = p + word.length;
        }
        return count;
      }

      onNs(document, "dblclick", "latexViewerSync", (e) => {
        const viewerEl = e.target.closest?.(".latex-viewer");
        if (!viewerEl) return;
        const wrapperEl = viewerEl.closest(".fm-editor-wrapper");
        const editorEl = wrapperEl ? wrapperEl.querySelector(".editor") : null;
        if (!editorEl) return;

        const target = e.target;
        const mjxContainer = target && target.closest ? target.closest("mjx-container") : null;

        let range = null;
        if (mjxContainer && viewerEl.contains(mjxContainer)) {
          const allMjx = viewerEl.querySelectorAll("mjx-container");
          const idx = Array.prototype.indexOf.call(allMjx, mjxContainer);
          if (idx >= 0) {
            range = findMathRangeInEditor(editorEl, idx, mjxContainer, e.clientX, e.clientY, target);
          }
        } else {
          const sel = window.getSelection();
          const word = sel && sel.toString ? sel.toString().trim() : "";
          if (word && sel.rangeCount > 0) {
            const r0 = sel.getRangeAt(0);
            const occBefore = countWordBeforeInViewer(viewerEl, word, r0.startContainer, r0.startOffset);
            range = findNthTextRangeInEditor(editorEl, word, occBefore);
          }
        }

        if (!range) return;

        editorEl.focus();
        const newSel = window.getSelection();
        newSel.removeAllRanges();
        newSel.addRange(range);

        const parentEl = range.startContainer.nodeType === Node.ELEMENT_NODE
          ? range.startContainer
          : range.startContainer.parentElement;
        if (parentEl && parentEl.scrollIntoView) {
          const editorRect = editorEl.getBoundingClientRect();
          const targetRect = parentEl.getBoundingClientRect();
          if (targetRect.top < editorRect.top || targetRect.bottom > editorRect.bottom) {
            parentEl.scrollIntoView({ behavior: "smooth", block: "center" });
          }
        }

        e.preventDefault();
        e.stopPropagation();
      });
    })();

    console.log("✅ EditorSystem: event delegation globale per .editor inizializzata");
  },
};

window.FM = window.FM || {};
window.FM.EditorSystem = EditorSystem;
window.EditorSystem    = EditorSystem;
