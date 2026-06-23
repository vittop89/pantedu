/**
 * LatexRender \u2014 estratto da functions-mod.js (Phase 9f).
 *
 * G22.S15 \u2014 `manipulateTikzScript` e' stato ri-cablato per usare la nuova
 * pipeline server-side via VPS (`tikz-render-client.js`). La vecchia
 * logica filesystem (svg_classe/, svg/) e' rimossa; il nuovo modulo
 * gestisce cache (hash sha256) + compile on-demand.
 *
 * G22.S15.bis \u2014 TikZJax deprecato: nessun fallback WASM client-side.
 * In caso di VPS irraggiungibile, l'utente vede blocco errore inline.
 */

import { renderAll as tikzRenderAll } from "./tikz-render-client.js";
import { asElement } from "../core/dom-utils.js";

/**
 * Regex pre-compilate degli ambienti LaTeX vietati in \pbox/\parbox.
 * Compilare a livello modulo evita la creazione di oggetti RegExp ad ogni chiamata
 * di validateContent (fix efficienza: evita GC pressure ad ogni keystroke).
 */
const _FORBIDDEN_ENV_REGEXES = [
  "align\\*", "align", "equation\\*", "equation",
  "gather\\*", "gather", "multline\\*", "multline",
  "alignat\\*", "alignat",
].map((env) => ({
  name: env.replace(/\\\\/g, "\\").replace(/\\\*/g, "*"),
  regex: new RegExp("\\\\begin\\{" + env + "\\}", "i"),
}));

/** Verifica se editor è valido (Element o jQuery wrapper non-empty). */
function isValidEditor(editor) {
    if (!editor) return false;
    if (editor.nodeType === 1) return true;
    if (typeof editor.length === "number") return editor.length > 0;
    return false;
}

// Map debole per associare observer ad un Element latex-viewer
const _imageObservers = new WeakMap();

export const LatexRender = {
  _state: {
    isConsoleMonitored: false,
    capturedMessages: [],
    isCapturingTikZ: false,
    onConsoleSilenceCallback: null,
    lastLogActivityTimerId: null,
    fallbackTimerId: null,
    originalConsoleLog: console.log.bind(console),
    tikzCheck: 0,
    svgCheck: -1,
    TikzRender: 0,
    isConsoleProcessing: false,
    SILENCE_DURATION_AFTER_LAST_LOG_MS: 7000,
  },

  /**
   * Valida che il contenuto destinato a comandi LaTeX ristretti non contenga ambienti non compatibili
   * @param {string} content - Contenuto da inserire nel comando LaTeX
   * @param {string} latexCommand - Comando LaTeX che ha restrizioni (default: "pbox")
   * @returns {boolean} - true se valido, false se contiene codice vietato
   */
  validateContent: function (content, latexCommand = "pbox") {
    if (!content) return true;

    // Usa le regex pre-compilate a livello modulo (evita allocazioni ad ogni chiamata)
    for (const { name: envName, regex } of _FORBIDDEN_ENV_REGEXES) {
      if (regex.test(content)) {
        if (typeof ToastManager !== "undefined") {
          ToastManager.show(
            "error",
            "Errore LaTeX",
            `L'ambiente \\begin{${envName}} non può essere usato in \\${latexCommand}. Usa $...$ o \\(...\\) per le formule matematiche.`,
            8000,
          );
        } else {
          alert(`Errore LaTeX:\n\nL'ambiente \\begin{${envName}} non può essere usato in \\${latexCommand}.\n\nUsa invece $...$ o \\(...\\) per le formule matematiche.`);
        }
        console.error(`Ambiente vietato in ${latexCommand}: \\begin{${envName}}`);
        return false;
      }
    }

    return true;
  },

  generateTexPreview: function (editor, InTikz) {
    if (!isValidEditor(editor)) {
      this._state.originalConsoleLog("Errore: Editor in LatexRender.generateTexPreview non valido o non definito.");
      return;
    }

    // if (InTikz === 0 && TikzRender === 1) { // isConsoleProcessing andrebbe gestito a livello di LatexRender.generateTexPreview
    //     this._state.originalConsoleLog("Attesa: elaborazione console già in corso...");
    //     return;
    // }
    // isConsoleProcessing = true; // Flag per evitare esecuzioni sovrapposte di LatexRender.generateTexPreview

    this._setupConsoleMonitoring(); // Assicura che la console sia monitorata
    this._clearCapturedTikzErrors(); // Pulisci i messaggi da esecuzioni precedenti

    ContentProcessor.getContent(editor, 1, InTikz); // Esegui l'azione che genera i log (errori TikZ)
    // this._state.originalConsoleLog('DEBUG: tikzCheck:', tikzCheck, 'svgCheck:', svgCheck);
    if (InTikz === 0) {
      this._waitForConsoleLogToFinish(() => {
        // this._state.originalConsoleLog("DEBUG: Cascata di messaggi sulla console terminata.");
        const tikzErrorsContent = this._getCapturedTikzErrors();

        if (Array.isArray(tikzErrorsContent) && tikzErrorsContent.length > 0) {
          this._state.originalConsoleLog("Messaggi di errore TikZ intercettati:", tikzErrorsContent);
          // Ora usa tikzErrorsContent per aggiornare l'interfaccia utente
          // La tua logica originale chiamava observeAndReplaceInvalidImage qui.
          this._displayTikzErrors(editor, tikzErrorsContent);
          // svgCheck = 0; // Imposta svgCheck dopo aver gestito gli errori
        } else {
          // this._state.originalConsoleLog("Nessun messaggio di errore TikZ intercettato.");
          // Nessun errore TikZ, l'immagine dovrebbe essere valida
        }
        this._state.isConsoleProcessing = false; // Fine elaborazione
        if (this._state.svgCheck === 1) {
          document.querySelectorAll(".fm-tikz-error-highlight").forEach((el) => el.remove());
        }
        InTikz = 0;
      });
    }
  },
  createWindowPreview: function (editor, titlePreview) {
    const editorEl = asElement(editor);
    if (!editorEl) return;
    const wrapper = editorEl.closest(".fm-editor-wrapper");
    if (!wrapper) return;

    let latexDiv = wrapper.querySelector(".fm-latex-preview-container");
    if (!latexDiv) {
      latexDiv = document.createElement("div");
      latexDiv.className = "fm-latex-preview-container";
      latexDiv.innerHTML = `
                    <div class="fm-latex-preview-title"></div>
                    <div class="fm-latex-viewer"></div>
            `;
      wrapper.appendChild(latexDiv);
    }
    const titleEl = latexDiv.querySelector(".fm-latex-preview-title");
    if (titleEl) titleEl.textContent = titlePreview;
    const viewerEl = latexDiv.querySelector(".fm-latex-viewer");
    if (viewerEl) viewerEl.replaceChildren();
  },
  MathJaxRender: function (latexViewer) {
    if (window.MathJax) {
      const viewerEl = asElement(latexViewer);
      if (viewerEl) {
        UIComp.safeTypeset([viewerEl]).then((success) => {
          if (!success) {
            console.warn("⚠️ MathJax rendering non completato nel latex-viewer");
          }
        });
      } else {
        console.error("Visualizzatore non trovato");
      }
    } else {
      console.error("MathJax non caricato");
    }
  },

  // Cache per evitare toast duplicati
  _lastMissingSvgToast: null,

  /**
   * G22.S15 — sostituisce ogni <script type="text/tikz"> con SVG inline
   * compilato lato server (VPS pdflatex+dvisvgm), con cache sha256.
   *
   * Nessuna piu' lookup filesystem in svg_classe/ — la cache vive in
   * storage/cache/tikz/{public|teacher_<id>}/<prefix>/<hash>.{svg|bin}.
   *
   * G22.S15.bis — TikZJax deprecato, niente fallback client-side WASM.
   * Errori → blocco rosso inline (vedi tikz-render-client.renderAll).
   */
  manipulateTikzScript: function () {
    return tikzRenderAll(document, {
      defaultScope: "public",
    }).then((stats) => {
      if (stats.errors.length > 0) {
        console.warn("[tikz] errori render:", stats.errors);
        if (typeof ToastManager !== "undefined") {
          ToastManager.show(
            "error",
            "TikZ render",
            `${stats.errors.length} render falliti`,
            5000,
          );
        }
      }
      console.log("[tikz] render complete", stats);
      return stats;
    }).catch((err) => {
      console.error("[tikz] renderAll fatal error:", err);
    });
  },

  /**
   * Installa il proxy console.log per la durata di UNA finestra di cattura TikZ.
   * Il proxy viene rimosso (console.log ripristinato) non appena la cattura termina
   * o scade il timeout, evitando JSON.stringify su OGNI log per tutta la sessione.
   *
   * Comportamento: i messaggi che arrivano durante la finestra di cattura
   * vengono accumulati in _state.capturedMessages; gli altri passano all'originale
   * senza elaborazione aggiuntiva.
   */
  _setupConsoleMonitoring: function () {
    if (this._state.isConsoleMonitored) {
      return;
    }
    this._state.isConsoleMonitored = true;
    const self = this;
    const realConsoleLog = this._state.originalConsoleLog;

    const startMessage0 = "LaTeX2e <2020-02-02> patch level 2";
    const startMessage  = "! Emergency stop.";
    const endMessage    = "Could not find file input.dvi";

    const proxyLog = function (...args) {
      // Passa sempre all'originale
      realConsoleLog.apply(console, args);

      // Aggiornamento timer silenzio (per _waitForConsoleLogToFinish)
      if (self._state.onConsoleSilenceCallback) {
        clearTimeout(self._state.lastLogActivityTimerId);
        self._state.lastLogActivityTimerId = setTimeout(() => {
          if (self._state.onConsoleSilenceCallback) {
            const cb = self._state.onConsoleSilenceCallback;
            self._state.onConsoleSilenceCallback = null;
            clearTimeout(self._state.fallbackTimerId);
            cb();
          }
        }, self._state.SILENCE_DURATION_AFTER_LAST_LOG_MS);
      }

      // Costruisci la stringa del messaggio SOLO durante la finestra di interesse
      // (quando isCapturingTikZ è attivo o cerchiamo i marcatori di inizio)
      const needsInspection = self._state.isCapturingTikZ ||
        (args.length > 0 && typeof args[0] === "string" && (
          args[0].includes(startMessage0) ||
          args[0].includes(startMessage) ||
          args[0].includes(endMessage)
        ));

      if (!needsInspection) return;

      // Serializza solo se necessario
      const messageParts = args.map((arg) => {
        if (typeof arg === "string") return arg;
        if (arg instanceof Error) return arg.message + (arg.stack ? "\n" + arg.stack : "");
        try { return JSON.stringify(arg); } catch (_) { return "[Unstringifiable Object]"; }
      });
      const messageString = messageParts.join(" ");

      if (messageString.includes(startMessage0)) {
        self._state.TikzRender = 1;
      }

      if (messageString.includes(startMessage) && !self._state.isCapturingTikZ) {
        self._state.isCapturingTikZ = true;
        self._state.capturedMessages = [];
      }

      if (self._state.isCapturingTikZ) {
        self._state.capturedMessages.push(
          args.length === 1 && typeof args[0] === "string" ? args[0] : messageString,
        );
      }

      if (messageString.includes(endMessage) && self._state.isCapturingTikZ) {
        self._state.isCapturingTikZ = false;
        self._state.TikzRender = 0;
        // Ripristina console.log originale: la finestra di cattura è terminata
        console.log = realConsoleLog;
        self._state.isConsoleMonitored = false;
      }
    };

    console.log = proxyLog;
  },
  _waitForConsoleLogToFinish: function (callback, overallTimeoutMs = 5000) {
    this._setupConsoleMonitoring(); // Assicura che la nostra console.log sia attiva
    this._state.onConsoleSilenceCallback = callback; // Imposta il callback da chiamare

    // Pulisci qualsiasi timer precedente per evitare conflitti
    clearTimeout(this._state.lastLogActivityTimerId);
    clearTimeout(this._state.fallbackTimerId);

    // Imposta il timer di fallback: se non ci sono log per 'overallTimeoutMs',
    // il callback viene chiamato comunque.
    // Questo timeout inizia dal momento in cui LatexRender._waitForConsoleLogToFinish è chiamata.
    const self = this;
    this._state.fallbackTimerId = setTimeout(() => {
      if (self._state.onConsoleSilenceCallback) {
        // this._state.originalConsoleLog("DEBUG: Timeout di fallback per LatexRender._waitForConsoleLogToFinish.");
        const cb = self._state.onConsoleSilenceCallback;
        self._state.onConsoleSilenceCallback = null;
        clearTimeout(self._state.lastLogActivityTimerId); // Annulla il timer basato sull'attività, perché il fallback si è attivato
        cb();
      }
    }, overallTimeoutMs);
  },
  _getCapturedTikzErrors: function () {
    if (this._state.capturedMessages.length > 0) {
      const lastMessageArray = this._state.capturedMessages[this._state.capturedMessages.length - 1];
      const lastMessageString = Array.isArray(lastMessageArray) ? lastMessageArray.join(" ") : String(lastMessageArray);
      if (lastMessageString.includes("Could not find file input.dvi")) {
        return this._state.capturedMessages.slice(0, -1);
      }
    }
    return this._state.capturedMessages.slice();
  },
  _clearCapturedTikzErrors: function () {
    this._state.capturedMessages = [];
  },

  // removeHighlights: function (targetElement) {
  //     $(targetElement).find('span.fm-tikz-error-highlight').each(function () {
  //         $(this).replaceWith(this.childNodes);
  //     });
  //     if (targetElement.normalize) {
  //         targetElement.normalize();
  //     }
  // },
  _displayTikzErrors: function (editor, errors) {
    const editorEl = asElement(editor);
    if (!editorEl) return;
    const wrapper = editorEl.closest(".fm-editor-wrapper");
    const latexViewer = wrapper ? wrapper.querySelector(".fm-latex-viewer") : null;
    const imgsNotFound = latexViewer ? latexViewer.querySelectorAll('img[src="//invalid.site/img-not-found.png"]') : [];

    if (imgsNotFound.length) {
      this._state.originalConsoleLog("DEBUG: Immagine non valida trovata, la sostituisco con il blocco di errori TikZ.");
      const errText = errors.map((e) => (Array.isArray(e) ? e.join(" ") : e)).join("\n");
      imgsNotFound.forEach((img) => {
        const replacementDiv = LatexRender._buildErrorBlock(errText);
        img.replaceWith(replacementDiv);
      });
    } else if (errors.length > 0) {
      this._state.originalConsoleLog("Errori TikZ presenti ma nessuna immagine placeholder '//invalid.site/img-not-found.png' trovata. Errori:", errors);
    }

    this._observeForInvalidImageAndDisplayErrors(editorEl, errors);
  },

  /** Costruisce il blocco di errore TikZ stilizzato. */
  _buildErrorBlock: function (errText) {
    const div = document.createElement("div");
    div.className = "fm-tikz-error-messages-block";
    Object.assign(div.style, {
      border: "1px solid red",
      color: "red",
      padding: "10px",
      margin: "10px 0",
      whiteSpace: "pre-wrap",
      fontFamily: "monospace",
      fontSize: "0.9em",
      maxHeight: "250px",
      overflowY: "auto",
    });
    div.textContent = errText;
    return div;
  },
  _observeForInvalidImageAndDisplayErrors: function (editor, errors) {
    const editorEl = asElement(editor);
    if (!editorEl) {
      this._state.originalConsoleLog("Errore: Editor non valido o non definito per l'observer.");
      return;
    }
    const wrapper = editorEl.closest(".fm-editor-wrapper");
    const latexViewer = wrapper ? wrapper.querySelector(".fm-latex-viewer") : null;
    if (!latexViewer) {
      this._state.originalConsoleLog("Errore: latex-viewer non trovato per l'osservazione DOM.");
      return;
    }

    // Disconnetti eventuali observer precedenti per questo latex-viewer (evita duplicazioni)
    const existingObserver = _imageObservers.get(latexViewer);
    if (existingObserver) existingObserver.disconnect();

    const errText = errors.map((e) => (Array.isArray(e) ? e.join(" ") : e)).join("\n");

    const observer = new MutationObserver((mutationsList, obs) => {
      const stillInvalidImages = latexViewer.querySelectorAll('img[src="//invalid.site/img-not-found.png"]');
      if (stillInvalidImages.length) {
        this._state.originalConsoleLog("DEBUG: Immagine non valida rilevata dall'observer DOM, la sostituisco.");
        obs.disconnect();
        _imageObservers.delete(latexViewer);

        stillInvalidImages.forEach((img) => {
          img.replaceWith(LatexRender._buildErrorBlock(errText));
        });
      }
    });

    observer.observe(latexViewer, { childList: true, subtree: true });
    _imageObservers.set(latexViewer, observer);

    // Opzionale: disconnetti l'observer dopo un timeout per sicurezza
    // setTimeout(() => {
    //     if ($latexViewer.data('imageObserver') === observer) { // Disconnetti solo se è ancora questo observer
    //         observer.disconnect();
    //         $latexViewer.removeData('imageObserver');
    //         this._state.originalConsoleLog("DEBUG: Observer DOM disconnesso per timeout di sicurezza.");
    //     }
    // }, 15000); // Es. 15 secondi
  },
};

window.FM = window.FM || {};
window.FM.LatexRender = LatexRender;
window.LatexRender    = LatexRender;
