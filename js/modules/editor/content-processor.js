/**
 * ContentProcessor \u2014 estratto da functions-mod.js (Phase 9f).
 *
 * G22.S15 \u2014 la preview live in edit-mode ora delega il rendering
 * TikZ al modulo `tikz-render-client.js` (server-side via VPS), che
 * sostituisce gli `<script type="text/tikz">` inseriti in `.latex-viewer`
 * con SVG inline. Niente piu' attesa di TikZJax + tikzjax-load-finished.
 *
 * G22.S15.bis \u2014 TikZJax DEPRECATO: il listener `tikzjax-load-finished`
 * e ogni riferimento a `window.TikZJax` / `window.tikzjax` sono stati
 * rimossi. Errori di compile \u2192 blocco rosso inline (vedi
 * tikz-render-client.renderAll).
 */
import { renderAll as tikzRenderAll } from "./tikz-render-client.js";
import { asElement, outerHeight } from "../core/dom-utils.js";

/** WeakMap per replica jQuery .data() su DOM Element (key→Map). */
const _elementData = new WeakMap();
function elData(el, key, value) {
  if (!el) return undefined;
  let store = _elementData.get(el);
  if (arguments.length === 3) {
    if (!store) {
      store = new Map();
      _elementData.set(el, store);
    }
    store.set(key, value);
    return value;
  }
  return store ? store.get(key) : undefined;
}

export const ContentProcessor = {
  getRawContent (editorSelector) {
    const editor = asElement(editorSelector);
    if (!editor) {
      console.error("Editor non trovato");
      return "";
    }
    return editor.textContent;
  },
  getHtmlContent (editorSelector) {
    const editor = asElement(editorSelector);
    if (!editor) {
      console.error("Editor non trovato");
      return "";
    }
    return editor.innerHTML;
  },
  getRawTikzContent (content, editorId) {
    const tempDiv = document.createElement("div");
    tempDiv.innerHTML = content;

    const tikzScripts = tempDiv.querySelectorAll('script[type="text/tikz"]');
    const tikzScriptIds = [];
    tikzScripts.forEach((script) => {
      if (script.id) tikzScriptIds.push(script.id);
    });

    if (!window._tikzScriptIdsByEditor) {
      window._tikzScriptIdsByEditor = {};
    }
    if (tikzScriptIds.length > 0 && editorId) {
      window._tikzScriptIdsByEditor[editorId] = tikzScriptIds;
    }

    // Unwrap: sostituisci ogni <script> con il suo contenuto (testo)
    tempDiv.querySelectorAll('script[type="text/tikz"]').forEach((script) => {
      const scriptContent = (script.innerHTML || "").trim();
      script.replaceWith(scriptContent);
    });

    return tempDiv.innerHTML;
  },
  getContent (editor, show, InTikz) {
    const editorEl = asElement(editor);
    const htmlContent = this.getHtmlContent(editorEl);

    // Estrai l'ID numerico dall'editor per cercare nella mappa
    let editorId = null;
    const fullId = editorEl?.id; // Es: "Editor1", "Editor2"
    if (fullId && fullId.startsWith("Editor")) {
      editorId = fullId.replace("Editor", ""); // Estrae "1", "2"
    }

    const tikzRegex = /\\usepackage([\s\S]*?)\\end{document}/g;
    const LatexRegex = /\\\(([\n\s\S]*?)\\\)/g;

    let modifiedContent = htmlContent;
    // console.log('htmlContent: ', htmlContent);

    // Cerca SEMPRE gli ID esistenti per riutilizzarli
    // PRIORITÀ:
    // 1. Mappa per editor specifico (mantiene ID originali anche senza preview)
    // 2. Cache globale (solo per editor che hanno fatto preview recente)
    // 3. Fallback: cerca nell'HTML
    let existingTikzScripts = [];

    // PRIORITÀ 1: Usa la mappa per editor specifico (per mantenere ID originali)
    if (editorId && window._tikzScriptIdsByEditor && window._tikzScriptIdsByEditor[editorId]) {
      existingTikzScripts = window._tikzScriptIdsByEditor[editorId];
    }
    // PRIORITÀ 2: Se non c'è nella mappa E stiamo salvando, usa cache globale
    else if (show === 0 && window._tikzScriptIds && window._tikzScriptIds.length > 0) {
      existingTikzScripts = window._tikzScriptIds;
    }
    // PRIORITÀ 3: Fallback - cerca nell'HTML
    else {
      // Fallback: cerca script esistenti nell'HTML del singolo editor
      const tempParser = document.createElement("div");
      tempParser.innerHTML = htmlContent;
      const existingScripts = tempParser.querySelectorAll('script[type="text/tikz"]');
      existingScripts.forEach((script) => {
        if (script.id) {
          existingTikzScripts.push(script.id);
        }
      });
    }

    let scriptIndex = 0; // Indice per riutilizzare gli ID esistenti
    const usedIds = new Set(); // Traccia gli ID già assegnati per evitare duplicati

    let match = "";
    if (show === 0) {
      console.log("show0");
      //   while ((match = tikzRegex.exec(htmlContent)) !== null) {
      modifiedContent = modifiedContent.replace(tikzRegex, (match) => {
        const s = document.createElement("script");

        // Determina quale indice usare:
        // - contatore globale SOLO se usiamo cache globale
        // - scriptIndex locale per mappa editor specifica
        let uniqueId;
        const useGlobalCache = show === 0 && window._tikzScriptIds && window._tikzScriptIds.length > 0;
        const useEditorMap = editorId && window._tikzScriptIdsByEditor && window._tikzScriptIdsByEditor[editorId];

        if (useEditorMap) {
          // PRIORITÀ 1: Usa mappa editor con indice locale
          let candidate = existingTikzScripts[scriptIndex];
          // Se l'ID dalla cache è già stato usato o non esiste, genera uno nuovo
          if (!candidate || usedIds.has(candidate)) {
            candidate = `tikz_${  Date.now()  }_${  Math.random().toString(36).substr(2, 9)}`;
          }
          usedIds.add(candidate);
          uniqueId = candidate;
          console.log(`📌 Editor ${editorId} usa ID mappa [${scriptIndex}]: ${uniqueId}`);
          scriptIndex++;
        } else if (useGlobalCache) {
          // PRIORITÀ 2: Usa cache globale con contatore globale
          if (typeof window._tikzGlobalIndex === "undefined") {
            window._tikzGlobalIndex = 0;
          }
          let candidate = existingTikzScripts[window._tikzGlobalIndex];
          // Se l'ID dalla cache è già stato usato o non esiste, genera uno nuovo
          if (!candidate || usedIds.has(candidate)) {
            candidate = `tikz_${  Date.now()  }_${  Math.random().toString(36).substr(2, 9)}`;
          }
          usedIds.add(candidate);
          uniqueId = candidate;
          console.log(`📌 Editor usa ID cache globale [${window._tikzGlobalIndex}]: ${uniqueId}`);
          window._tikzGlobalIndex++;
        } else {
          // FALLBACK: genera nuovo ID
          uniqueId = `tikz_${  Date.now()  }_${  Math.random().toString(36).substr(2, 9)}`;
          usedIds.add(uniqueId);
          console.log(`🆕 Editor genera nuovo ID: ${uniqueId}`);
        }
        s.setAttribute("id", uniqueId);
        s.setAttribute("type", "text/tikz");

        // G22.S15.bis — TikZJax deprecato, `data-show-console` non piu' usato
        // dalla pipeline VPS. Manteniamo solo per gli script con ID nuovo
        // (debug visivo storico in eventuali consumer legacy).
        if (!uniqueId.startsWith("tikz_")) {
          s.setAttribute("data-show-console", "true");
        }

        s.setAttribute("data-tex-packages", "custom-package");
        s.setAttribute("data-tex-packages", '{"pgfplots":""}');
        s.setAttribute("data-tex-packages", '{"amsmath":""}');
        s.setAttribute("data-tikz-libraries", "arrows.meta,calc");
        // s.textContent = s.textContent.replace(/>/g, '&gt;');
        // s.textContent = s.textContent.replace(/</g, '&lt;');

        // let content = match[0]
        console.log("match[0]: ", match[0]);
        // 1. PRIMA proteggi gli span.solution con placeholder
        const content = match
          .replace(/<span\s+class=["']solution["'][^>]*>/gi, "___SOLUTION_OPEN___")
          .replace(/<\/span>/gi, (closeTag, offset, string) => {
            // Conta quanti ___SOLUTION_OPEN___ ci sono prima di questo </span>
            const beforeThis = string.substring(0, offset);
            const openCount = (beforeThis.match(/___SOLUTION_OPEN___/g) || []).length;
            const closeCount = (beforeThis.match(/___SOLUTION_CLOSE___/g) || []).length;

            // Se ci sono più aperture che chiusure, questo è un </span> di .solution
            if (openCount > closeCount) {
              return "___SOLUTION_CLOSE___";
            }
            return ""; // Altrimenti rimuovi questo </span>
          })
          .replace(/<div[^>]*>/g, "")
          .replace(/<\/div[^>]*>/g, "<br>")
          .replace(/<br\s*\/?\s*>/gi, "<br>")
          //   .replace(/<\/div[^>]*>/g, ' ')
          .replace(/<\/?p[^>]*>/g, "")
          .replace(/<\/?span[^>]*>/g, "") // Rimuovi tutti gli altri span rimasti
          //   .replace(/&nbsp;/g, ' ')
          //   .replace(/\u00A0/g, "&nbsp;")
          //   .replace(/\u2007/g, "&nbsp;")
          //   .replace(/\u202F/g, "&nbsp;")
          // NON convertire spazi in &nbsp; - causava problemi di formattazione
          .replace(/&amp;/g, "&")
          .replace(/&gt;/g, ">")
          .replace(/&lt;/g, "<")
          .replace(/\r\n?/g, "\n")
          .replace(/<br>\s*\n+/gi, "<br>")
          .replace(/\n+\s*<br>/gi, "<br>")
          .replace(/\n/g, "<br>")
          .replace(/\u00A0/g, " ")
          .replace(/[ \t]+<br>/g, "<br>")
          .replace(/(<br>\s*){3,}/gi, "<br><br>")
          .trim() // Rimuovi spazi iniziali/finali
          // 2. Ripristina gli span.solution DOPO tutte le trasformazioni
          .replace(/___SOLUTION_OPEN___/g, '<span class="fm-solution">')
          .replace(/___SOLUTION_CLOSE___/g, "</span>");
        // s.textContent = s.textContent.replace(/&gt;/g, '>');
        // s.textContent = s.textContent.replace(/&lt;/g, '<');
        // console.log("Content Tikz pulito: ", content);
        s.textContent = content;
        return s.outerHTML;
      });
      //   }
      // while ((match = LatexRegex.exec(htmlContent)) !== null) {
      modifiedContent = modifiedContent.replace(LatexRegex, (match, latexContent) => {
        // console.log('match[0]: ', match[0]);
        // s.textContent = s.textContent.replace(/>/g, '&gt;');
        // s.textContent = s.textContent.replace(/</g, '&lt;');
        // console.log('match[0]: ', match[0]);
        // let content = match[0].replace(/<div[^>]*>/g, '')

        // 1. Proteggi gli span.solution con placeholder
        const content = latexContent
          .replace(/<span\s+class=["']solution["'][^>]*>/gi, "___SOLUTION_OPEN___")
          .replace(/<\/span>/gi, (closeTag, offset, string) => {
            // Conta quanti ___SOLUTION_OPEN___ ci sono prima di questo </span>
            const beforeThis = string.substring(0, offset);
            const openCount = (beforeThis.match(/___SOLUTION_OPEN___/g) || []).length;
            const closeCount = (beforeThis.match(/___SOLUTION_CLOSE___/g) || []).length;

            // Se ci sono più aperture che chiusure, questo è un </span> di .solution
            if (openCount > closeCount) {
              return "___SOLUTION_CLOSE___";
            }
            return ""; // Altrimenti rimuovi questo </span>
          })
          // NON rimuovere i <div>, solo gli spazi di indentazione prima di essi
          .replace(/\s+(<\/?div[^>]*>)/g, "$1") // Rimuovi spazi prima dei tag div
          .replace(/<\/?p[^>]*>/g, "")
          .replace(/<\/?span[^>]*>/g, "") // Rimuovi tutti gli altri span rimasti
          // .replace(/&nbsp;/g, ' ')
          //   .replace(/\u00A0/g, "&nbsp;")
          //   .replace(/\u2007/g, "&nbsp;")
          //   .replace(/\u202F/g, "&nbsp;")
          .replace(/ {2,}/g, " ") // Normalizza spazi multipli consecutivi (non di indentazione)
          .replace(/ /g, "&nbsp;")
          .replace(/&amp;/g, "&")
          // 2. Ripristina gli span.solution
          .replace(/___SOLUTION_OPEN___/g, '<span class="fm-solution">')
          .replace(/___SOLUTION_CLOSE___/g, "</span>");
        return `\\(${content}\\)`;
      });
      // }
    } else if (show === 1) {
      if (InTikz === 0) {
        // while ((match = tikzRegex.exec(htmlContent)) !== null) {
        //     const s = document.createElement('script');
        //     s.setAttribute('type', 'text/tikz');
        //     s.setAttribute('data-show-console', 'true');
        //     s.setAttribute('data-tex-packages', 'custom-package');
        //     s.setAttribute('data-tex-packages', '{"pgfplots":""}');
        //     s.setAttribute('data-tex-packages', '{"amsmath":""}');
        //     s.setAttribute('data-tikz-libraries', 'arrows.meta,calc');
        //     s.getAttribute('data-tex-packages').replace(/&quot;/g, '"');
        //     s.textContent = match[0].replace(/<br[^>]*>/g, '').replace(/<\/?p[^>]*>/g, '').replace(/<\/?div[^>]*>/g, '');
        //     s.textContent = match[0].replace(/<\/?b[^>]*>/g, '').replace(/<\/?u[^>]*>/g, '').replace(/<\/?i[^>]*>/g, '');
        //     s.textContent = match[0].replace(/<\/?span[^>]*>/g, '');
        //     s.textContent = s.textContent.replace(/<\/?b[^>]*>/g, '').replace(/<\/?u[^>]*>/g, '').replace(/<\/?i[^>]*>/g, '');
        //     s.textContent = s.textContent.replace(/<\/?p[^>]*>/g, '').replace(/<\/?div[^>]*>/g, '');
        //     s.textContent = s.textContent.replace(/&nbsp;/g, ' '); // Rimuove &nbsp;
        //     s.textContent = s.textContent.replace(/&gt;/g, '>');
        //     s.textContent = s.textContent.replace(/&lt;/g, '<');
        //     s.textContent = s.textContent.replace(/&amp;/g, '&')

        // Reset dell'indice per il blocco preview
        scriptIndex = 0;

        while ((match = tikzRegex.exec(htmlContent)) !== null) {
          const s = document.createElement("script");

          // Riutilizza l'ID esistente o genera uno nuovo
          const uniqueId = existingTikzScripts[scriptIndex] || `tikz_${  Date.now()  }_${  Math.random().toString(36).substr(2, 9)}`;
          scriptIndex++;
          s.setAttribute("id", uniqueId);
          s.setAttribute("type", "text/tikz");
          s.setAttribute("data-show-console", "true");
          s.setAttribute("data-tex-packages", "custom-package");
          s.setAttribute("data-tex-packages", '{"pgfplots":""}');
          s.setAttribute("data-tex-packages", '{"amsmath":""}');
          s.setAttribute("data-tikz-libraries", "arrows.meta,calc");

          // console.log("match[0]2: ", match[0]);

          const content = match[0]
            .replace(/<span\s+class=["']latex-comment["'][^>]*>.*?<\/span>/gi, "") // Rimuovi span latex-comment completi
            .replace(/<br[^>]*>/g, "")
            .replace(/<\/?p[^>]*>/g, "")
            .replace(/<\/?div[^>]*>/g, "")
            .replace(/<\/?b[^>]*>/g, "")
            .replace(/<\/?u[^>]*>/g, "")
            .replace(/<\/?i[^>]*>/g, "")
            .replace(/<\/?span[^>]*>/g, "")
            .replace(/&nbsp;/g, " ")
            .replace(/&gt;/g, ">")
            .replace(/&lt;/g, "<")
            .replace(/&amp;/g, "&");

          // Rimuovi i commenti LaTeX (% non preceduto da \ fino alla fine della riga)
          //   content = content.replace(/(?<!\\)%.*?(?=\n|\\end\{tikzpicture\}|$)/g, "");

          // s.textContent = s.textContent.replace(/&quot;/g, '"');
          // s.textContent = s.textContent.replace(/\s+/g, '').replace(/<\/?p[^>]*>/g, '');
          s.textContent = content;
          // Convert the script element to a string
          const scriptString = s.outerHTML;
          // console.log('scriptString: ', scriptString);

          // Replace the matched content with the script element string
          modifiedContent = modifiedContent.replace(match[0], scriptString);
          tikzCheck = 1;
          document.querySelectorAll(".RenderMessage").forEach((el) => el.remove());
        }
      }
      while ((match = LatexRegex.exec(htmlContent)) !== null) {
        const s = document.createElement("span");
        s.textContent = match[0]
          .replace(/<br[^>]*>/g, "")
          .replace(/<\/?p[^>]*>/g, "")
          .replace(/<\/?div[^>]*>/g, "");
        s.textContent = match[0]
          .replace(/<\/?b[^>]*>/g, "")
          .replace(/<\/?u[^>]*>/g, "")
          .replace(/<\/?i[^>]*>/g, "");
        s.textContent = match[0].replace(/<\/?span[^>]*>/g, "");
        s.textContent = s.textContent
          .replace(/<\/?b[^>]*>/g, "")
          .replace(/<\/?u[^>]*>/g, "")
          .replace(/<\/?i[^>]*>/g, "");
        s.textContent = s.textContent.replace(/<\/?p[^>]*>/g, "").replace(/<\/?div[^>]*>/g, "");
        s.textContent = s.textContent.replace(/&nbsp;/g, " "); // Rimuove &nbsp;
        s.textContent = s.textContent.replace(/&gt;/g, ">");
        s.textContent = s.textContent.replace(/&lt;/g, "<");
        s.textContent = s.textContent.replace(/&amp;/g, "&");
        s.textContent = s.textContent.replace(/hline/g, "hline ");
        s.textContent = s.textContent.replace(/hdashline/g, "hdashline ");
        const scriptString = s.outerHTML;
        // console.log('scriptString: ', scriptString);

        // Replace the matched content with the script element string
        modifiedContent = modifiedContent.replace(match[0], scriptString);
      }

      // Crea il preview container solo se non esiste già
      const editorWrapper = editorEl ? editorEl.closest(".fm-editor-wrapper") : null;
      if (editorWrapper && !editorWrapper.querySelector(".fm-latex-preview-container")) {
        LatexRender.createWindowPreview(editorEl, "Preview");
      }

      // Estrai gli script TikZ da modifiedContent per snapshot ordine.
      // (Pre-G22.S15 serviva a mappare SVG generati da TikZJax → ID;
      // ora tikz-render-client li sostituisce inline con SVG che gia'
      // include data-tikz-* attrs, ma manteniamo lo snapshot per moduli
      // che reagiscono a `_tikzScriptIdsByEditor`.)
      const tempDiv = document.createElement("div");
      tempDiv.innerHTML = modifiedContent;
      const tikzScripts = tempDiv.querySelectorAll('script[type="text/tikz"]');
      const tikzScriptsArray = []; // Array ordinato degli script

      tikzScripts.forEach((script) => {
        if (script.id) {
          tikzScriptsArray.push({
            id: script.id,
            content: script.textContent,
            element: script,
          });
        }
      });

      // Salva l'altezza corrente del latex-viewer PRIMA di sostituire il contenuto
      const latexViewerBeforeUpdate = editorWrapper ? editorWrapper.querySelector(".fm-latex-viewer") : null;
      const previewContainer = editorWrapper ? editorWrapper.querySelector(".fm-latex-preview-container") : null;
      const savedHeight = latexViewerBeforeUpdate ? outerHeight(latexViewerBeforeUpdate, true) : 0;

      // Applica l'altezza al CONTAINER invece che al latex-viewer
      // per evitare che venga persa quando chiamiamo innerHTML
      if (savedHeight > 0 && previewContainer) {
        Object.assign(previewContainer.style, {
          height: `${savedHeight}px`,
          minHeight: `${savedHeight}px`,
          overflow: "hidden",
        });
      }

      const _latexViewerEl = editorWrapper ? editorWrapper.querySelector(".fm-latex-viewer") : null;
      if (_latexViewerEl) _latexViewerEl.innerHTML = modifiedContent;

      // G22.S15 — render server-side dei <script type="text/tikz"> appena
      // inseriti in .latex-viewer (sostituiti con SVG inline via VPS).
      if (_latexViewerEl) {
        tikzRenderAll(_latexViewerEl, {
          defaultScope: "public",
        }).catch((err) => {
          console.error("[tikz-preview] renderAll error:", err);
        });
      }

      // Memorizza l'altezza nell'editor per usarla in _adjustEditorHeight
      if (savedHeight > 0 && editorEl) {
        elData(editorEl, "savedLatexViewerHeight", savedHeight);
      }

      // Dopo il rendering, rimuovi le restrizioni di altezza per permettere al contenuto di espandersi
      setTimeout(() => {
        const containerAfterUpdate = editorEl?.closest(".fm-editor-wrapper")?.querySelector(".fm-latex-preview-container");
        if (containerAfterUpdate) {
          Object.assign(containerAfterUpdate.style, {
            height: "auto",
            minHeight: "0",
            overflow: "visible",
          });
        }

        // Forza un controllo dell'altezza dell'editor dopo che il contenuto si è espanso
        setTimeout(() => {
          EditorSystem._setHeightEditor(editor);
        }, 50);
      }, 100);

      // G22.S15.bis — Listener legacy `tikzjax-load-finished` rimosso.
      // tikz-render-client setta gia' data-tikz-hash + data-tikz-source
      // sull'SVG iniettato; gli ID degli script sono memorizzati in
      // `_tikzScriptIdsByEditor` per round-trip preview→edit.
      // console.log('modifiedContent: ', modifiedContent);
      // $('.fm-latex-viewer').html(modifiedContent);
    }
    const latexViewer = editorEl?.closest(".fm-editor-wrapper")?.querySelector(".fm-latex-viewer") || null;
    if (latexViewer) {
      LatexRender.MathJaxRender(latexViewer);

      const existingHeightObserver = elData(latexViewer, "heightObserver");
      if (existingHeightObserver) {
        existingHeightObserver.disconnect();
      }

      let observerTimeout;
      let lastHeight = elData(editorEl, "savedLatexViewerHeight") || 0;

      const heightObserver = new MutationObserver(() => {
        clearTimeout(observerTimeout);
        observerTimeout = setTimeout(() => {
          const currentHeight = outerHeight(latexViewer, true);
          const currentEditorHeight = parseInt(getComputedStyle(editorEl).height) || 0;

          if (Math.abs(currentHeight - lastHeight) > 1) {
            lastHeight = currentHeight;
            EditorSystem._setHeightEditor(editor);
          } else if (Math.abs(currentHeight - currentEditorHeight) > 1) {
            lastHeight = currentHeight;
            EditorSystem._setHeightEditor(editor);
          }
        }, 100);
      });

      heightObserver.observe(latexViewer, {
        childList: true,
        subtree: true,
        attributes: true,
        characterData: true,
      });

      elData(latexViewer, "heightObserver", heightObserver);
    }
    if (InTikz !== 0 && latexViewer) {
      let startDiv = null;
      let endDiv = null;

      const allDivs = Array.from(latexViewer.querySelectorAll("div"));
      // Trova l'elemento con errore specifico
      const errorElements = allDivs.filter((d) => d.textContent.includes("Unknown environment 'document'"));

      if (errorElements.length === 0) {
        // Trova il primo <div> contenente \usepackage
        for (const d of allDivs) {
          if (d.textContent.includes("\\usepackage") && !startDiv) {
            startDiv = d;
            break;
          }
        }

        // Trova il primo <div> contenente \end{document} dopo startDiv
        if (startDiv) {
          let foundStart = false;
          for (const d of allDivs) {
            if (d === startDiv) {
              foundStart = true;
              continue;
            }
            if (foundStart && d.textContent.includes("\\end{document}")) {
              endDiv = d;
              break;
            }
          }
        }

        // Se trovi sia startDiv che endDiv, sostituisci tutti i div compresi tra loro
        if (startDiv && endDiv) {
          // Replica nextUntil(endDiv).add(startDiv).add(endDiv): startDiv + tutti i sibling
          // successivi fino a endDiv compreso. La sequenza è ordinata top-down.
          const divsToReplace = [startDiv];
          let cur = startDiv.nextElementSibling;
          while (cur && cur !== endDiv) {
            divsToReplace.push(cur);
            cur = cur.nextElementSibling;
          }
          if (endDiv) divsToReplace.push(endDiv);

          divsToReplace.forEach((d, index) => {
            if (index === 0) {
              d.replaceWith(ContentProcessor._buildRenderMessageDiv());
            } else {
              d.remove();
            }
          });
        }
      } else {
        // Sostituisci ogni errorElement con il RenderMessage
        errorElements.forEach((d) => d.replaceWith(ContentProcessor._buildRenderMessageDiv()));
      }
    }

    // 🧹 Strip dei <div> vuoti e senza classi in testa e in coda (solo in save mode)
    if (show === 0) {
      modifiedContent = ContentProcessor._trimEmptyDivs(modifiedContent);
    }

    return modifiedContent;
  },

  /** Costruisce il blocco RenderMessage stilizzato (sostituisce $('<div class="RenderMessage">').css().html() jq). */
  _buildRenderMessageDiv () {
    const div = document.createElement("div");
    div.className = "RenderMessage";
    Object.assign(div.style, {
      border: "2px solid blue",
      color: "blue",
      fontWeight: "bold",
      padding: "10px",
      margin: "10px 0",
      textAlign: "center",
    });
    div.innerHTML = "Per rendering Tikz cliccare fuori dall'editor<br>o scrivere fuori da \\usepackage...\\end{document}";
    return div;
  },

  /**
   * Rimuove i <div> vuoti e senza classi dall'inizio e dalla fine del contenuto HTML.
   * Un div è considerato "vuoto" se non ha classi e il suo contenuto è solo
   * whitespace, <br>, &nbsp; o combinazioni di questi.
   * @param {string} html - Stringa HTML da pulire
   * @returns {string} HTML con div vuoti iniziali/finali rimossi
   */
  _trimEmptyDivs (html) {
    if (!html || typeof html !== "string") return html;

    const container = document.createElement("div");
    container.innerHTML = html;

    function isEmptyDiv(node) {
      if (node.nodeType !== 1 || node.tagName !== "DIV") return false;
      // Se ha classi, non è "vuoto" per i nostri scopi
      if (node.className && node.className.trim() !== "") return false;
      // Controlla se il contenuto è solo whitespace, <br>, &nbsp;
      const inner = node.innerHTML
        .replace(/<br\s*\/?>/gi, "")
        .replace(/&nbsp;/gi, "")
        .trim();
      return inner === "";
    }

    // Rimuovi dall'inizio
    while (container.firstChild && isEmptyDiv(container.firstChild)) {
      container.removeChild(container.firstChild);
    }

    // Rimuovi dalla fine
    while (container.lastChild && isEmptyDiv(container.lastChild)) {
      container.removeChild(container.lastChild);
    }

    return container.innerHTML;
  },

  /**
   * Pulisce il contenuto di .giustifica rimuovendo tutti i tag tranne <br>.
   * I <div> vengono convertiti in <br> (essendo blocchi, agiscono come a capo).
   * @param {string} html - Contenuto HTML grezzo dall'editor
   * @returns {string} Solo testo con <br> per gli a capo
   */
  _cleanGiustifica (html) {
    if (!html || typeof html !== "string") return html;

    const container = document.createElement("div");
    container.innerHTML = html;

    let result = "";
    container.childNodes.forEach((node, idx) => {
      if (node.nodeType === Node.TEXT_NODE) {
        result += node.textContent;
      } else if (node.nodeType === Node.ELEMENT_NODE) {
        if (node.tagName === "BR") {
          result += "<br>";
        } else {
          // Per div e altri blocchi: prendi il testo interno, aggiungi <br> tra blocchi
          const inner = node.textContent;
          if (inner.trim() !== "") {
            if (result !== "" && !result.endsWith("<br>")) {
              result += "<br>";
            }
            result += inner;
          }
        }
      }
    });

    // Rimuovi <br> iniziali e finali superflui
    result = result.replace(/^(<br>\s*)+/, "").replace(/(<br>\s*)+$/, "");
    return result.trim();
  },

  replaceNumbox (el, checkboxSol) {
    // checkboxSol accetta Element (HTMLInputElement) o jQuery wrapper
    const checkboxEl = asElement(checkboxSol);
    const isChecked = checkboxEl ? checkboxEl.checked === true : false;
    let colorDefinition = "";
    // \overset{\color{red}\huge \bullet\circ\circ\circ}{\underset{\text{P-}106}{\bbox[border: 1px solid white; background: blue,3pt]{{\mathmakebox[cm][c]{\textcolor{white}{\large 17}}}}}}
    // const regex = new RegExp(
    //   [
    //     // Blocca l'array con tre righe
    //     String.raw`\\begin\{array\}\{\|c\|\}[\n\s\S]*?\\hline[\n\s\S]*?`,
    //     String.raw`\\small\{[^\}]*\}\s*\\\\\[-5pt\][\n\s\S]*?`,
    //     String.raw`\\tiny\{[^\}]*\}\s*\\\\\[-5pt\][\n\s\S]*?`,
    //     String.raw`\\tiny\{[^\}]*\}[\n\s\S]*?\\hline[\n\s\S]*?\\end\{array\}\\quad[\n\s\S]*?`,
    //     // Blocca l'overset con bbox
    //     String.raw`\\overset[\n\s\S]*?\\color\s*\{red\}[\n\s\S]*?\\huge[\n\s\S]*?([^}]*)\}\{`,
    //     String.raw`[\n\s\S]*?\\underset\{[\n\s\S]*?\\text\{P-[^}]*\}([^}]*)[\n\s\S]*?\}\{`,
    //     String.raw`[\n\s\S]*?\\bbox\[border:\s*1px\s*solid\s*white;\s*background:\s*([^,]*),\s*([^p]*)pt\][\n\s\S]*?`,
    //     String.raw`\\mathmakebox\[cm\]\[c\][\n\s\S]*?\\textcolor\{white\}\{([^}]*)[\n\s\S]*?\}[\n\s\S]*?\}[\n\s\S]*?\}[\n\s\S]*?\\quad`,
    //   ].join(""),
    //   "g"
    // );
    // el = el.replace(/\\begin\{array\}\{\|c\|\}[\n\s\S]*?\\hline[\n\s\S]*?\\small\{\\text\{([^\}]*)\}\}[\n\s\S]*?\\\\\[-5pt\][\n\s\S]*?\\tiny\{\\text\{([^\}]*)\}[\n\s\S]*?\\\\\[-5pt\][\n\s\S]*?\\tiny\{\\text\{([^\}]*)\}\}[\n\s\S]*?\\\\\[-5pt\][\n\s\S]*?\\hline[\n\s\S]*?\\end\{array\}\\quad[\n\s]*\\overset[\n\s\S]*?\\color\s*\{red\}[\n\s\S]*?\\huge[\n\s\S]*?([^}]*)\}\{[\n\s\S]*?\\underset\{[\n\s\S]*?\\text\{([^}]*)\}([^}]*)[\n\s\S]*?\}\{[\n\s\S]*?\\bbox\[border:\s*1px\s*solid\s*white;\s*background:\s*([^,]*),\s*([^p]*)pt\][\n\s\S]*?\\mathmakebox\[cm\]\[c\][\n\s\S]*?\\textcolor\{white\}\{([^}]*)[\n\s\S]*?\}[\n\s\S]*?\}[\n\s\S]*?([^}]*)\}[\n\s\S]*?\\quad/g, function (match, fonte, volume, autore, diff, ispag, pag, bcolor, padding, num, restContent) {
    // Costruisci la regex in modo modulare per leggibilità
    const regexParts = {
      // Blocco array con fonte/volume/autore
      arrayBlock:
        String.raw`\\begin\{array\}\{\|c\|\}[\n\s\S]*?\\hline[\n\s\S]*?` +
        String.raw`\\small\{\\text\{([^\}]*)\}\}[\n\s\S]*?\\\\\[-5pt\][\n\s\S]*?` + // (1) fonte
        String.raw`\\tiny\{\\text\{([^\}]*)\}[\n\s\S]*?\\\\\[-5pt\][\n\s\S]*?` + // (2) volume
        String.raw`\\tiny\{\\text\{([^\}]*)\}\}[\n\s\S]*?\\\\\[-5pt\][\n\s\S]*?` + // (3) autore
        String.raw`\\hline[\n\s\S]*?\\end\{array\}\\quad`,

      // Blocco overset con difficoltà
      oversetBlock: String.raw`[\n\s]*\\overset[\n\s\S]*?\\color\s*\{red\}[\n\s\S]*?` + String.raw`\\huge[\n\s\S]*?([^}]*)\}\{`, // (4) diff

      // Blocco underset con pagina
      undersetBlock: String.raw`[\n\s\S]*?\\underset\{[\n\s\S]*?` + String.raw`\\text\{([^}]*)\}([^}]*)[\n\s\S]*?\}\{`, // (5) ispag, (6) pag

      // Blocco bbox con colore e numero
      bboxBlock:
        String.raw`[\n\s\S]*?\\bbox\[border:\s*1px\s*solid\s*white;\s*background:\s*([^,]*),\s*([^p]*)pt\]` + // (7) bcolor, (8) padding
        String.raw`[\n\s\S]*?\\mathmakebox\[cm\]\[c\][\n\s\S]*?` +
        String.raw`\\textcolor\{white\}\{([^}]*)[\n\s\S]*?\}[\n\s\S]*?\}`, // (9) num

      // Contenuto restante
      restBlock: String.raw`[\n\s\S]*?([^}]*)\}[\n\s\S]*?\\quad`, // (10) restContent
    };

    // Combina tutte le parti
    const fullRegex = new RegExp(regexParts.arrayBlock + regexParts.oversetBlock + regexParts.undersetBlock + regexParts.bboxBlock + regexParts.restBlock, "g");

    el = el.replace(fullRegex, (match, fonte, volume, autore, diff, ispag, pag, bcolor, padding, num, restContent) => {
      // el = el.replace(/(\\begin\{array\}\{\|c\|\}[\n\s\S]*?\\hline[\n\s\S]*?\\small\{[^\}]*\}\}[n\s\S]*?\\\\\[-5pt\][\n\s\S]*?\\tiny\{[^\}]*\}[\n\s\S]*?\\\\\[-5pt\][\n\s\S]*?\\tiny\{[^\}]*\}\}[\n\s\S]*?\\\\\[-5pt\][\n\s\S]*?\\hline[\n\s\S]*?\\end\{array\}\\quad)?[\n\s]*\\overset[\n\s\S]*?\\color\s*\{red\}[\n\s\S]*?\\huge[\n\s\S]*?([^}]*)\}\{[\n\s\S]*?\\underset\{[\n\s\S]*?\\text\{([^}]*)\}([^}]*)[\n\s\S]*?\}\{[\n\s\S]*?\\bbox\[border:\s*1px\s*solid\s*white;\s*background:\s*([^,]*),\s*([^p]*)pt\][\n\s\S]*?\\mathmakebox\[cm\]\[c\][\n\s\S]*?\\textcolor\{white\}\{([^}]*)[\n\s\S]*?\}[\n\s\S]*?\}[\n\s\S]*?\}[\n\s\S]*?\\quad/g, function (match, fonte, diff, ispag, pag, bcolor, padding, num) {
      let mycolor = bcolor;
      if (bcolor.includes("#")) {
        bcolor = bcolor.replace("#", "");
        colorDefinition = `\\definecolor{mycolor}{HTML}{${bcolor}}\n`;
        mycolor = "mycolor";
      }
      if (isChecked) {
        // ✅ Wrappa in $$ per display math mode
        if (num.includes("\\text")) {
          return `\\begin\{array\}\{\|c\|\}\n\\hline\n\\text\{\\small\{${fonte}\}\}\\\\\[-6pt\]\n\\text\{\\tiny\{${volume}\}\}\\\\\[-6pt\]\n\\text\{\\tiny\{${autore}\}\}\\\\\n\\hline\n\\end\{array\}\\quad\n\\overset\{\\color\{red\}${diff}\}\{\\underset\{\\color\{black\}\\text\{\\tiny\{${ispag}${pag}\}\}\}\{\\fcolorbox\{white\}\{${mycolor}\}\{\\phantom\{\\rule\{1pt\}\{1pt\}\}\\textcolor\{white\}\{${num}\}\}\\phantom\{\\rule\{1pt\}\{1pt\}\}\}\}\}\\quad${restContent}`;
        } else {
          return `\\begin\{array\}\{\|c\|\}\n\\hline\n\\text\{\\small\{${fonte}\}\}\\\\\[-6pt\]\n\\text\{\\tiny\{${volume}\}\}\\\\\[-6pt\]\n\\text\{\\tiny\{${autore}\}\}\\\\\n\\hline\n\\end\{array\}\\quad\n\\overset\{\\color\{red\}${diff}\}\{\\underset\{\\color\{black\}\\text\{\\tiny\{${ispag}${pag}\}\}\}\{\\fcolorbox\{white\}\{${mycolor}\}\{\\phantom\{\\rule\{1pt\}\{1pt\}\}\\textcolor\{white\}\{${num}\}\\phantom\{\\rule\{1pt\}\{1pt\}\}\}\}\}\\quad${restContent}`;
        }
        // if (num.includes("\\text")) {
        //   return `$${fonte}\\overset\{\\color\{red\}${diff}\}\{\\underset\{\\color\{black\}\\text\{\\tiny\{${ispag}${pag}\}\}\}\{\\fcolorbox\{white\}\{${mycolor}\}\{\\phantom\{\\rule\{1pt\}\{1pt\}\}\\textcolor\{white\}\{${num}\}\}\\phantom\{\\rule\{1pt\}\{1pt\}\}\}\}\}\\quad$`;
        // } else {
        //   return `$${fonte}\\overset\{\\color\{red\}${diff}\}\{\\underset\{\\color\{black\}\\text\{\\tiny\{${ispag}${pag}\}\}\}\{\\fcolorbox\{white\}\{${mycolor}\}\{\\phantom\{\\rule\{1pt\}\{1pt\}\}\\textcolor\{white\}\{${num}\}\\phantom\{\\rule\{1pt\}\{1pt\}\}\}\}\}\\quad$`;
        // }
      } else {
        return "";
      }
    });
    if (!checkboxSol.is(":checked")) {
      el = el.replace(/\\begin\{array\}\{\|c\|\}\\hline\\small\{\\text\{([^}]*)\}\}\\([^i]*)iny\{\\text\{([^}]*)\}\}\\([^i]*)iny\{\\text\{([^}]*)\}\}\\([^h]*)hline\\end\{array\}/g, "");
    }
    return colorDefinition + el;
  },
  processmathjaxElements (element, latexContent) {
    const elementEl = asElement(element);
    if (!elementEl) return;

    // Prima di processare, rimuovi gli script TikZ che hanno già SVG cached
    // per evitare che vengano riprocessati quando manipoliamo innerHTML
    elementEl.querySelectorAll('script[type="text/tikz"][id^="tikz_"]').forEach((script) => {
      const scriptId = script.id;
      if (elementEl.querySelector(`svg[data-tikz-script-id="${scriptId}"]`)) {
        script.remove();
      }
    });

    elementEl.querySelectorAll(".fm-collection, .fm-sol, .fm-testo, .fm-giustsol").forEach((wrap) => {
      let htmlContent = wrap.innerHTML;
      htmlContent = htmlContent.replace(/\\\(([\n\s\S]*?)\\\)/g, (match, p1) => {
        // console.log('p1 before:', p1);
        if (latexContent == 1) {
          const cleaned = p1
            .replace(/\r?\n/g, "") // rimuove newline fisici del sorgente PHP (non da tag)
            .replace(/<div[^>]*>/g, "")
            .replace(/<\/div[^>]*>/g, "\n")
            .replace(/<br[^>]*>/g, "\n")
            .replace(/<\/?p[^>]*>/g, "")
            .replace(/<\/?span[^>]*>/g, "")
            .replace(/&nbsp;/g, " ")
            .replace(/&amp;/g, "&");
          //   .replace(/\\\\\s*\n/g, "\\\\\n")
          //   .replace(/&gt;/g, ">")
          //   .replace(/&lt;/g, "<");
          return `\\(${  cleaned  }\\)`;
        } else {
          const cleaned = p1
            //   .replace(/<\/div>/g, "<br>")
            //   .replace(/<div>/g, "")  // in questo caso bisognerevve tracciare il </div> di chiusura successivo (sopratutto se cade dopo il \) e farlo diventare <br>
            //   .replace(/<\/?p[^>]*>/g, "")
            //   .replace(/&gt;/g, ">")
            //   .replace(/&lt;/g, "<")
            //   .replace(/&amp;/g, "&")
            //   .replace(/&nbsp;/g, " ");
            // ✅ PRIMA preserva \\ seguiti da tag HTML convertendoli in \\ + spazio
            .replace(/\\\\(\s*)<br[^>]*>/gi, "\\\\ ")
            .replace(/\\\\(\s*)<\/div[^>]*>/gi, "\\\\ ")
            .replace(/\\\\(\s*)<div[^>]*>/gi, "\\\\ ")
            // 🔧 Preserva i comandi LaTeX come \[-5pt] che potrebbero essere spezzati da </div>
            .replace(/<\/div[^>]*>(\s*)/gi, " ") // Sostituisci </div> con spazio
            .replace(/<div[^>]*>(\s*)/gi, " ") // Sostituisci <div> con spazio
            // POI rimuovi i tag HTML normalmente
            .replace(/<br[^>]*>/g, " ") // <br> diventa spazio invece di essere rimosso
            .replace(/<\/?p[^>]*>/g, " ")
            .replace(/<\/?b[^>]*>/g, "")
            .replace(/<\/?u[^>]*>/g, "")
            .replace(/<\/?i[^>]*>/g, "")
            // ✅ Converti esplicitamente i backslash wrappati da span in \\ prima dello strip span
            .replace(/<span[^>]*>\s*(?:\\|&#92;|&bsol;)\s*<\/span>/gi, "\\\\")
            .replace(/<\/?span[^>]*>/g, "")
            // ✅ IMPORTANTE: Decodifica HTML entities DOPO aver rimosso i tag
            .replace(/&nbsp;/g, " ")
            .replace(/&amp;/g, "&")
            .replace(/&percnt;/g, "%") // Aggiunto per gestire \% encodato come &percnt;
            .replace(/&quot;/g, '"')
            .replace(/&#39;/g, "'")
            .replace(/&apos;/g, "'")
            //   .replace(/&gt;/g, ">")
            //   .replace(/&lt;/g, "<")
            // 🔧 FIX: Normalizza spazi multipli in uno solo
            .replace(/\s+/g, " ")
            // 🔧 FIX: Rimuovi spazi prima di comandi LaTeX critici
            .replace(/\s+\\/g, " \\") // " \command" → " \command"
            // 🔧 FIX: Aggiungi \\ prima di \[ se manca (per array line break)
            .replace(/([^\\])\s*\\\[/g, "$1 \\\\[") // "} \[-5pt]" → "} \\[-5pt]"
            // 🔧 FIX: Aggiungi spazio dopo \hline solo se non c'è già
            .replace(/\\hline(?!\s)/g, "\\hline ")
            .replace(/\\hdashline(?!\s)/g, "\\hdashline ")
            // 🔧 FIX: Pulisci spazi prima e dopo il contenuto
            .trim();
          // console.log("p1 after:", cleaned);
          return `\\(${  cleaned  }\\)`;
        }
      });
      wrap.innerHTML = htmlContent;
    });
  },
  /**
   * Pulisce il codice TikZ da tag HTML ed entità
   * @param {string} tikzContent - Contenuto TikZ grezzo (può contenere HTML)
   * @param {boolean} extractPreamble - Se true, estrae e salva i preamboli personalizzati
   * @returns {string} Codice TikZ pulito (solo rimozione HTML, formattazione gestita da _formatLatexContent)
   */
  cleanTikzContent (tikzContent, extractPreamble = false) {
    if (!tikzContent || typeof tikzContent !== "string") {
      return "";
    }

    // 🧹 RIMUOVI COMPLETAMENTE gli span latex-comment (span + contenuto)
    // Prima rimuovi gli span con classe latex-comment incluso il loro contenuto
    let cleanedContent = tikzContent.replace(/<span\s+class=["']latex-comment["'][^>]*>.*?<\/span>/gi, "");

    // 🔑 PRIMA converti entità HTML per proteggere < e > nelle formule
    cleanedContent = cleanedContent
      .replace(/&nbsp;/g, " ")
      .replace(/&nbsp;&nbsp;/g, "  ")
      .replace(/&nbsp;&nbsp;&nbsp;/g, "   ")
      .replace(/\u00A0/g, " ")
      .replace(/&gt;/g, ">")
      .replace(/&lt;/g, "<");

    // 🧹 PULIZIA HTML con regex più specifiche (solo tag completi con >)
    // NON matcha < o > nelle formule matematiche come 0<b<1
    cleanedContent = cleanedContent
      .replace(/<\/p>/gi, "\n")
      .replace(/<\/div>/gi, "\n")
      .replace(/<br\s*\/?>/gi, "\n")
      .replace(/<p(\s+[^>]*)?>/gi, "")
      .replace(/<\/?b>/gi, "")
      .replace(/<\/?u>/gi, "")
      .replace(/<\/?i>/gi, "")
      .replace(/<\/?span(\s+[^>]*)?>/gi, "")
      .replace(/<div(\s+[^>]*)?>/gi, "");

    // 🎯 Estrai SOLO definizioni personalizzate (esclude pacchetti standard già nell'intestazione)
    if (extractPreamble) {
      // Cerca il blocco tra inizio e \begin{document}
      const preambleMatch = cleanedContent.match(/^[\s\S]*?(?=\\begin\{document\})/i);
      if (preambleMatch) {
        const fullPreamble = preambleMatch[0];

        // 🚫 Escludi TUTTI i comandi usepackage e usetikzlibrary (già presenti in intestaLAteX.txt)
        const isPackageOrLibrary = (line) => {
          const trimmed = line.trim();
          return trimmed.startsWith("\\usepackage") || trimmed.startsWith("\\usetikzlibrary");
        };

        // 🎯 Permetti solo comandi/preamboli realmente utili (evita righe distruttive come \documentclass)
        const isAllowedSingleLine = (line) => {
          const trimmed = line.trim();
          if (!trimmed) return false;
          if (trimmed.startsWith("%")) return true;

          return trimmed.startsWith("\\pgfplotsset") || trimmed.startsWith("\\pgfdeclarelayer") || trimmed.startsWith("\\pgfsetlayers") || trimmed.startsWith("\\newif") || trimmed.startsWith("\\ShowPointDots") || trimmed.startsWith("\\pagestyle") || trimmed.startsWith("\\Set") || trimmed.startsWith("\\def\\");
        };

        // ✅ Estrai BLOCCHI completi di definizioni personalizzate
        const customBlocks = [];
        const lines = fullPreamble.split("\n");

        let i = 0;
        while (i < lines.length) {
          const line = lines[i];
          const trimmedLine = line.trim();

          // Ignora linee vuote
          if (!trimmedLine) {
            i++;
            continue;
          }

          // 🚫 Escludi usepackage/usetikzlibrary
          if (isPackageOrLibrary(trimmedLine)) {
            i++;
            continue;
          }

          // 🎯 Identifica inizio blocco
          const isBlockStart = trimmedLine.startsWith("\\def") || trimmedLine.startsWith("\\gdef") || trimmedLine.startsWith("\\edef") || trimmedLine.startsWith("\\newcommand") || trimmedLine.startsWith("\\newcount") || trimmedLine.startsWith("\\newdimen") || trimmedLine.startsWith("\\tikzset") || trimmedLine.startsWith("\\makeatletter");

          if (isBlockStart) {
            // Caso speciale: \makeatletter ... \makeatother
            if (trimmedLine.startsWith("\\makeatletter")) {
              const blockLines = [line];
              i++;
              // Leggi fino a \makeatother
              while (i < lines.length) {
                blockLines.push(lines[i]);
                if (lines[i].trim().startsWith("\\makeatother")) {
                  i++;
                  break;
                }
                i++;
              }
              customBlocks.push(blockLines.join("\n"));
              continue;
            }

            // Altri blocchi: traccia le graffe
            const blockLines = [line];
            let braceCount = (line.match(/\{/g) || []).length - (line.match(/\}/g) || []).length;
            i++;

            // Continua finché non bilancia le graffe
            while (i < lines.length && braceCount > 0) {
              const nextLine = lines[i];
              blockLines.push(nextLine);
              braceCount += (nextLine.match(/\{/g) || []).length - (nextLine.match(/\}/g) || []).length;
              i++;
            }

            customBlocks.push(blockLines.join("\n"));
          } else if (isAllowedSingleLine(trimmedLine)) {
            // Solo righe singole whitelisted
            customBlocks.push(line);
            i++;
          } else {
            // Scarta tutto ciò che non è una definizione/preambolo custom
            i++;
          }
        }

        // Salva solo se ci sono blocchi custom
        if (customBlocks.length > 0) {
          // Salva in Map (ogni problema ha il suo array di blocchi)
          if (!window.CustomTikzPreambles) {
            window.CustomTikzPreambles = new Map();
          }

          // Usa un ID incrementale come chiave per permettere accumulo da più problemi
          const problemId = window.CustomTikzPreambles.size;
          window.CustomTikzPreambles.set(problemId, customBlocks);
          console.log("📦 Blocchi TikZ personalizzati estratti:", customBlocks.length, "blocchi da problema", problemId);
        }
      }
    }

    // 🎯 Estrai solo il blocco tikzpicture (rimuove eventuali preamboli)
    const tikzStart = cleanedContent.indexOf("\\begin{tikzpicture}");
    const tikzEnd = cleanedContent.indexOf("\\end{tikzpicture}") + "\\end{tikzpicture}".length;

    if (tikzStart !== -1 && tikzEnd !== -1) {
      cleanedContent = cleanedContent.substring(tikzStart, tikzEnd);
    } else {
      // Se non trova tikzpicture, restituisci comunque il contenuto pulito da HTML
      console.warn("⚠️ Blocco \\begin{tikzpicture}...\\end{tikzpicture} non trovato");
    }

    return cleanedContent;
  },

  /**
   * Genera un hash numerico da una stringa (per deduplicate contenuti)
   * @param {string} str - Stringa da hashare
   * @returns {number} Hash numerico
   */
  _hashCode (str) {
    let hash = 0;
    for (let i = 0; i < str.length; i++) {
      const char = str.charCodeAt(i);
      hash = (hash << 5) - hash + char;
      hash = hash & hash; // Convert to 32bit integer
    }
    return hash;
  },

  createTikzCode (element, extractPreambles = false) {
    // 🔑 Processa TUTTI gli script TikZ, non solo quelli con data-show-console
    // Questo è necessario per pulire il codice quando viene caricato dal server per la stampa
    const tikzScripts = element.querySelectorAll('script[type="text/tikz"]');

    tikzScripts.forEach((tikzScript) => {
      // Skip se lo script ha un ID che inizia con tikz_ E ha già SVG cached nel DOM
      const scriptId = tikzScript.getAttribute("id");
      if (scriptId && scriptId.startsWith("tikz_")) {
        // Verifica se esiste già un SVG cached (solo per editor, non per stampa)
        const hasSvgCached = element.querySelector(`svg[data-tikz-script-id="${  scriptId  }"]`);
        if (hasSvgCached) {
          console.log("⏭️ Skip createTikzCode per script con SVG cached:", scriptId);
          return; // Skip questo script solo se ha SVG
        }
      }

      // Usa la funzione di pulizia centralizzata per rimuovere HTML dal TikZ
      const scriptContent = this.cleanTikzContent(tikzScript.innerHTML, extractPreambles);
      tikzScript.innerHTML = scriptContent;
    });
  },

  // Il giro su disco del vecchio editor TikZ è stato tolto il 2026-09-20.
  //
  // C'erano qui una quindicina di metodi — `saveAllSvgsInContainer`,
  // `saveSvgToServer`, `compileTikzToSvgOnServer`, `loadSvgFromServer`,
  // `duplicateAllSvgsInElement` e i loro aiutanti — che serializzavano
  // l'SVG dal DOM, lo spedivano a `POST /files/save-image` e alla
  // riapertura lo rileggevano dal disco. Ci si entrava solo da
  // `svg[data-tikz-script-id]`, e quell'attributo lo scriveva soltanto
  // quel codice: nessun chiamante fuori da questo file, nessuna richiesta
  // in produzione, e i file prodotti non li serviva nessuna rotta.
  //
  // Il disegno in servizio è `editor/tikz-render-client.js`: manda il
  // sorgente a `/tikz/render`, marca l'SVG con `data-tikz-hash`,
  // `data-tikz-source`, `data-tikz-srckey`, `data-tikz-tagopen` e
  // `data-tikz-body`, e la cache sta dietro quella rotta e
  // `/tikz/save-svg` (`features/checkin-handlers.js`).
  //
  // Che non torni: `tests/js-unit/giro-morto-del-vecchio-editor.test.js`
  // cerca in tutto `js/` chi scrive `data-tikz-script-id`.
};

window.FM = window.FM || {};
window.FM.ContentProcessor = ContentProcessor;
window.ContentProcessor    = ContentProcessor;
