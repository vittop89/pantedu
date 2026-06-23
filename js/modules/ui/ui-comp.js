/**
 * UIComp \u2014 estratto da functions-mod.js (Phase 9g, big module).
 * G26.phase6.7 \u2014 migrato a vanilla JS (no jQuery direct).
 *
 * God file: 3558 LOC, era 343 jQuery refs. Boundary pattern preserved:
 * tutte le API accettano sia Element che jQuery wrapper via asElement().
 */
import { Endpoints } from "../core/endpoints.js";
import { asElement, asElementArray, isVisible, trigger, outerHeight, outerWidth, fetchJson } from "../core/dom-utils.js";

/** POST form-urlencoded → JSON. Sostituisce gli ajaxCompat POST/json del modulo.
 *  data: oggetto piano (valori non-stringa → String/JSON.stringify, come lo shim). */
function _postJson(url, data) {
    const p = new URLSearchParams();
    for (const [k, v] of Object.entries(data || {})) {
        if (v != null) p.append(k, typeof v === "object" ? JSON.stringify(v) : String(v));
    }
    return fetchJson(url, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body: p.toString(),
    });
}

/** WeakMap-namespaced event delegation. */
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
    return store ? store.get(key) : undefined;
}

// ===========================================================================
// G26.phase8 — toolkit DOM vanilla (sostituisce il subset jQuery residuo).
// Funzioni pure: restituiscono Element/array reali, NIENTE wrapper chainable.
// ===========================================================================

/** Crea un Element da una stringa HTML (primo figlio). */
function elFromHTML(html) {
    const t = document.createElement("template");
    t.innerHTML = String(html).trim();
    return t.content.firstElementChild;
}
/** querySelectorAll → Array. root accetta Element/jQuery/selector/Document. */
function qsa(sel, root) {
    const r = root == null ? document : (asElement(root) || document);
    return Array.from(r.querySelectorAll(sel));
}
/** Nasconde (display:none) tutti i match di sel sotto root. */
function hideAll(sel, root) {
    qsa(sel, root).forEach((e) => { e.style.display = "none"; });
}
/** display helper: "" ripristina al default del foglio di stile. */
function setDisplay(node, val) {
    const e = asElement(node);
    if (e) e.style.display = val;
}
/** Fratelli di node che matchano sel (o tutti se sel omesso). */
function siblings(node, sel) {
    const e = asElement(node);
    if (!e || !e.parentNode) return [];
    return Array.from(e.parentNode.children).filter((c) => c !== e && (!sel || c.matches(sel)));
}
/** Fratelli precedenti (closest-first) che matchano sel. */
function prevAll(node, sel) {
    const out = [];
    let p = asElement(node);
    p = p && p.previousElementSibling;
    while (p) {
        if (!sel || p.matches(sel)) out.push(p);
        p = p.previousElementSibling;
    }
    return out;
}
/** Applica un oggetto di stili (chiavi camelCase o kebab-case). */
function setCss(node, styles) {
    const e = asElement(node);
    if (!e) return;
    for (const [k, v] of Object.entries(styles)) {
        const prop = k.includes("-") ? k : k.replace(/[A-Z]/g, (m) => "-" + m.toLowerCase());
        e.style.setProperty(prop, v == null ? "" : String(v));
    }
}
/** Delega evento a livello document, namespaced; handler riceve l'elemento
 *  matchato come `this` (replica $(document).off(ev.ns).on(ev.ns, sel, fn)). */
function delegate(event, ns, selector, handler) {
    onNs(document, event, ns, function (e) {
        const target = e.target;
        if (!target || typeof target.closest !== "function") return;
        const matched = target.closest(selector);
        if (matched && document.contains(matched)) handler.call(matched, e);
    });
}
/** Nodi di testo figli diretti con contenuto non vuoto. */
function nonEmptyTextNodes(el) {
    const e = asElement(el);
    if (!e) return [];
    return Array.from(e.childNodes).filter(
        (n) => n.nodeType === Node.TEXT_NODE && n.textContent.trim().length > 0,
    );
}
/** Fade di opacità via transition; risolve a fine animazione (replica .animate). */
function fadeTo(nodes, to, ms) {
    const list = Array.isArray(nodes) ? nodes : asElementArray(nodes);
    if (!list.length) return Promise.resolve();
    return new Promise((resolve) => {
        let pending = list.length;
        const done = () => { if (--pending <= 0) resolve(); };
        list.forEach((e) => {
            e.style.transition = `opacity ${ms}ms`;
            // force reflow così la transition parte dal valore corrente
            void e.offsetHeight;
            const onEnd = () => { e.style.transition = ""; e.removeEventListener("transitionend", onEnd); done(); };
            e.addEventListener("transitionend", onEnd);
            // fallback se transitionend non scatta (es. opacità già = to)
            setTimeout(onEnd, ms + 50);
            e.style.opacity = String(to);
        });
    });
}
/** GET JSON via fetch (replica $.getJSON). Ritorna Promise<data>. */
function getJSON(url) {
    return fetch(url, { credentials: "same-origin", headers: { Accept: "application/json" } })
        .then((r) => { if (!r.ok) throw new Error("HTTP " + r.status); return r.json(); });
}
/** GET testo via fetch (replica $.get per HTML). Ritorna Promise<string>. */
function getText(url) {
    return fetch(url, { credentials: "same-origin" })
        .then((r) => { if (!r.ok) throw new Error("HTTP " + r.status); return r.text(); });
}
/** Replica `.hide().removeClass("active")` su tutti i match. */
function closeAll(sel, root) {
    qsa(sel, root).forEach((e) => { e.style.display = "none"; e.classList.remove("active"); });
}
/** Replica `.removeClass("active")` su tutti i match. */
function deactivateAll(sel, root) {
    qsa(sel, root).forEach((e) => e.classList.remove("active"));
}
/** Replica jQuery `.next(sel)`: fratello successivo IFF matcha sel. */
function nextMatch(el, sel) {
    const e = asElement(el);
    const n = e && e.nextElementSibling;
    return n && (!sel || n.matches(sel)) ? n : null;
}
/** Primo figlio diretto <div> con testo; fallback al primo <div> (replica
 *  `.children("div").filter(testo).first()` con fallback). */
function firstNonEmptyDivChild(parent) {
    if (!parent) return null;
    const divs = Array.from(parent.children).filter((c) => c.tagName === "DIV");
    return divs.find((d) => d.textContent.trim().length > 0) || divs[0] || null;
}
/** Sposta i nodi di testo non vuoti (figli diretti) dentro host (replica
 *  `.contents().filter(textNode).each(append)` — move, non copy). */
function moveTextNodesInto(parent, host) {
    if (!parent || !host) return;
    nonEmptyTextNodes(parent).forEach((n) => host.appendChild(n));
}

export const UIComp = {
  _beforeUnloadHandlerAttached: false,
  _mathJaxQueue: Promise.resolve(),
  _elementiRiservatiLoading: false,
  _elementiRiservatiCallbacks: [],
  safeTypeset: function (targets = null) {
    const runTypeset = async () => {
      if (typeof MathJax === "undefined" || typeof MathJax.typesetPromise !== "function") {
        return false;
      }

      const getConnectedTargets = () => {
        if (!Array.isArray(targets)) return null;
        return targets.filter((el) => el && el.isConnected);
      };

      const performTypeset = async (currentTargets) => {
        // Se targets è stato passato esplicitamente come array ma è vuoto dopo il filtro,
        // NON fare fallback al typeset globale (evita di compilare editor aperti in altri collex-item)
        if (Array.isArray(targets) && (!currentTargets || currentTargets.length === 0)) {
          console.log("ℹ️ MathJax typeset: nessun target connesso, skip (no fallback globale)");
          return;
        }

        // Evita clear globale: è costoso e rallenta il rendering generale
        if (currentTargets && currentTargets.length > 0 && typeof MathJax.typesetClear === "function") {
          try {
            MathJax.typesetClear(currentTargets);
          } catch (clearErr) {
            console.warn("⚠️ MathJax.typesetClear warning:", clearErr);
          }
        }

        if (currentTargets && currentTargets.length > 0) {
          await MathJax.typesetPromise(currentTargets);
        } else {
          await MathJax.typesetPromise();
        }
      };

      await new Promise((resolve) => setTimeout(resolve, 0));

      try {
        await performTypeset(getConnectedTargets());
        return true;
      } catch (err) {
        const message = String(err?.message || "");
        const isTransientDomError = /removeChild|parentNode|null/i.test(message);

        if (!isTransientDomError) {
          console.warn("⚠️ MathJax typeset non-transient error:", err);
          return false;
        }

        await new Promise((resolve) => setTimeout(resolve, 70));

        try {
          await performTypeset(getConnectedTargets());
          console.log("✅ MathJax typeset riuscito al retry");
          return true;
        } catch (retryErr) {
          console.warn("⚠️ MathJax typeset fallito anche al retry:", retryErr);
          return false;
        }
      }
    };

    UIComp._mathJaxQueue = UIComp._mathJaxQueue.then(runTypeset).catch((queueErr) => {
      console.warn("⚠️ Queue MathJax error:", queueErr);
      return false;
    });

    return UIComp._mathJaxQueue;
  },
  safeTypesetBatch: async function (targets = [], chunkSize = 18) {
    if (!Array.isArray(targets) || targets.length === 0) {
      return UIComp.safeTypeset();
    }

    const connectedTargets = targets.filter((el) => el && el.isConnected);
    if (connectedTargets.length === 0) {
      return true;
    }

    if (connectedTargets.length <= chunkSize) {
      return UIComp.safeTypeset(connectedTargets);
    }

    let allOk = true;
    for (let i = 0; i < connectedTargets.length; i += chunkSize) {
      const chunk = connectedTargets.slice(i, i + chunkSize);
      const ok = await UIComp.safeTypeset(chunk);
      if (!ok) {
        allOk = false;
      }
      await new Promise((resolve) => setTimeout(resolve, 0));
    }

    return allOk;
  },
  // updateListTypeSelector: function (url, elementTikzSelector) {
  //     $.get(url, function (data) {
  //         const tempDiv = $('<div>').html(data); // Crea un div temporaneo e inserisci il contenuto HTML
  //         const elements = tempDiv.find('.element-tex'); // Trova gli elementi all'interno del div temporaneo

  //         elements.each(function () {
  //             let content = '';
  //             const scriptElement = $(this).find('script').get(0);
  //             const latexElement = $(this).find('.latex').get(0);

  //             if (scriptElement) {
  //                 content = scriptElement.textContent.split('\n').map(line => {
  //                     return `<div>${line.replace(/ /g, '&nbsp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')}</div>`;
  //                 }).join('');
  //             } else if (latexElement) {
  //                 content = latexElement.textContent.split('\n').map(line => {
  //                     return `${line.replace(/ /g, '&nbsp;')}<br>`;
  //                     // return `${line}<br>`;
  //                 }).join('');
  //             }

  //             const labelTikz = $(this).find('.label_tikz').text();
  //             const labelLatex = $(this).find('.label_latex').text();
  //             const label = labelTikz || labelLatex; // Usa il label disponibile

  //             const option = $('<option></option>').text(label).val(content);
  //             elementTikzSelector.append(option);
  //         });
  //     }).fail(function () {
  //         console.error('Errore nel caricamento degli elementi dalla pagina:', url);
  //     });
  // }
  // updateListTracciaSelector: function (url, container) {
  //     $.get(url, function (data) {
  //         const tempDiv = $('<div>').html(data);
  //         tempDiv.find('.element-traccia').each(function () {
  //             let content = '';
  //             const scriptElement = $(this).find('script[type="text/tikz"]').get(0);
  //             const latexElement = $(this).find('.latex').get(0);

  //             if (scriptElement) {
  //                 content = scriptElement.textContent.split('\n').map(line => {
  //                     return `<div>${line.replace(/ /g, '&nbsp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')}</div>`;
  //                 }).join('');
  //             } else if (latexElement) {
  //                 content = latexElement.textContent.split('\n').map((line, idx) => {
  //                     return idx === 0
  //                         ? line.replace(/ /g, '&nbsp;')
  //                         : line.replace(/ /g, '&nbsp;') + '<br>';
  //                     // return `${line}<br>`;
  //                 }).join('');
  //             }
  //             const labelLatex = $(this).find('.label_latex').text();
  //             const label = labelLatex || "Traccia";
  //             const $btn = $('<button class="fm-traccia-element-btn"></button>')
  //                 .text(label)
  //                 .attr('data-content', content)
  //             $btn.on('click', function (e) {
  //                 e.stopPropagation();
  //                 const editorId = EditorSystem.getFocusedEditorId();
  //                 if (editorId) {
  //                     EditorSystem._insertElement($(this).attr('data-content'), editorId);
  //                     $('.fm-element-traccia-groups').hide();
  //                 }
  //             });
  //             container.append($btn);
  //         });
  //     });
  // },
  // updateListTypeSelector: function (url, container) {
  //     $.get(url, function (data) {
  //         const tempDiv = $('<div>').html(data);
  //         const groups = {};
  //         tempDiv.find('.element-tex').each(function () {
  //             const group = $(this).closest('.tex-group').data('group') || 'Altro';
  //             if (!groups[group]) groups[group] = [];
  //             groups[group].push(this);
  //         });
  //         for (const group in groups) {
  //             const $groupDiv = $('<div class="tex-group" data-group="' + group + '"></div>');
  //             $groupDiv.append('<button class="fm-group-btn">' + group.replace('gruppo-', '').replace('-', ' ') + '</button>');
  //             const $optionsDiv = $('<div class="fm-group-options"></div>');
  //             groups[group].forEach(el => {
  //                 let content = '';
  //                 const scriptElement = $(el).find('script[type="text/tikz"]').get(0);
  //                 const latexElement = $(el).find('.latex').get(0);

  //                 if (scriptElement) {
  //                     content = scriptElement.textContent.split('\n').map(line => {
  //                         return `<div>${line.replace(/ /g, '&nbsp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')}</div>`;
  //                     }).join('');
  //                 } else if (latexElement) {
  //                     content = latexElement.textContent.split('\n').map((line, idx) => {
  //                         return idx === 0
  //                             ? line.replace(/ /g, '&nbsp;')
  //                             : line.replace(/ /g, '&nbsp;') + '<br>';
  //                         // return `${line}<br>`;
  //                     }).join('');
  //                 }

  //                 const labelTikz = $(el).find('.label_tikz').text();
  //                 const labelLatex = $(el).find('.label_latex').text();
  //                 const label = labelTikz || labelLatex;

  //                 const $btn = $('<button class="fm-tikz-element-btn"></button>')
  //                     .text(label)
  //                     .attr('data-content', content)

  //                 $btn.on('click', function (e) {
  //                     e.stopPropagation();
  //                     const editorId = EditorSystem.getFocusedEditorId();
  //                     if (editorId) {
  //                         EditorSystem._insertElement($(this).attr('data-content'), editorId);
  //                         $('.fm-group-options').hide();
  //                         $('.fm-element-tikz-groups').hide();
  //                     }
  //                 });

  //                 $optionsDiv.append($btn);
  //             });
  //             $groupDiv.append($optionsDiv);
  //             container.append($groupDiv);
  //         }

  //         // Mostra/nasconde i sottogruppi al click
  //         $(document).off('click.tikzGroup').on('click.tikzGroup', '.group-btn', function (event) {
  //             event.stopPropagation();
  //             $('.fm-group-options').hide();
  //             $(this).siblings('.fm-group-options').show();
  //         });
  //         $(document).off('click.hideTikzGroup').on('click.hideTikzGroup', function () {
  //             $('.fm-group-options').hide();
  //         });
  //     });
  // },
  _getElementContent: function (element) {
    const root = asElement(element);
    const scriptElement = root ? root.querySelector('script[type="text/tikz"]') : null;
    const latexElement = root ? root.querySelector(".latex") : null;

    if (scriptElement) {
      return scriptElement.textContent
        .split("\n")
        .map((line, idx) => {
          return idx === 0 ? line.replace(/ /g, "&nbsp;").replace(/</g, "&lt;").replace(/>/g, "&gt;") : `<div>${line.replace(/ /g, "&nbsp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")}</div>`;
        })
        .join("");
    } else if (latexElement) {
      return latexElement.textContent
        .split("\n")
        .map((line, idx) => {
          return idx === 0 ? line.replace(/ /g, "&nbsp;") : line.replace(/ /g, "&nbsp;") + "<br>";
        })
        .join("");
    }
    return "";
  },

  // Metodo ausiliario per creare un bottone
  _createButton: function (label, content, className, hideSelector, lazyLoad) {
    const btn = document.createElement("button");
    btn.className = className;
    btn.textContent = label;

    if (lazyLoad) {
      // Memorizza i parametri per il caricamento lazy
      btn.setAttribute("data-tikz-group", lazyLoad.group);
      btn.setAttribute("data-tikz-index", lazyLoad.index);
    } else {
      // Caricamento immediato (vecchio metodo)
      btn.setAttribute("data-content", content);
    }

    btn.addEventListener("click", function (e) {
      e.stopPropagation();
      const editorId = EditorSystem.getFocusedEditorId();
      if (!editorId) {
        console.warn("Nessun editor attivo");
        return;
      }

      if (lazyLoad) {
        // Recupera il contenuto dalla cache JSON
        const group = this.getAttribute("data-tikz-group");
        const index = parseInt(this.getAttribute("data-tikz-index"));

        console.log("Caricamento TikZ dal JSON:", { group, index });

        if (!window.tikzContentCache) {
          console.error("Cache JSON TikZ non trovata!");
          alert("Errore: cache dei modelli TikZ non disponibile");
          return;
        }

        if (!window.tikzContentCache[group]) {
          console.error("Gruppo non trovato nella cache JSON:", group);
          alert("Errore: gruppo TikZ non trovato");
          return;
        }

        if (index >= window.tikzContentCache[group].length) {
          console.error("Indice fuori range:", index, "su", window.tikzContentCache[group].length);
          alert("Errore: elemento TikZ non trovato");
          return;
        }

        const tikzCode = window.tikzContentCache[group][index].content;

        console.log("Caricamento contenuto TikZ (", tikzCode.split("\n").length, "righe)");

        const lines = tikzCode.split("\n");

        // Usa sempre l'inserimento progressivo per evitare blocchi
        console.log("Inserimento progressivo per tutti i contenuti");
        EditorSystem._insertLargeContent(lines, editorId);

        hideAll(hideSelector);
      } else {
        // Inserimento immediato (vecchio metodo)
        EditorSystem._insertElement(this.getAttribute("data-content"), editorId);
        hideAll(hideSelector);
      }
    });
    return btn;
  },

  updateListTracciaSelector: function (url, container) {
    // Se il JSON è già in cache, usalo direttamente senza ricaricarlo
    if (window.tracciaContentCache && Object.keys(window.tracciaContentCache).length > 0) {
      // console.log("📦 Uso cache Traccia già caricata (" + Object.keys(window.tracciaContentCache).length + " gruppi)");
      UIComp._buildTracciaUI(window.tracciaContentCache, container);
      return;
    }

    // Altrimenti carica il JSON
    UIComp.preloadTracciaJSON(url, function () {
      UIComp._buildTracciaUI(window.tracciaContentCache, container);
    });
  },

  preloadTracciaJSON: function (url, callback) {
    // Se già caricato, chiama subito il callback
    if (window.tracciaContentCache && Object.keys(window.tracciaContentCache).length > 0) {
      console.log("📦 Cache Traccia già disponibile");
      if (callback) callback();
      return;
    }

    console.log("🔄 Precaricamento JSON Traccia...");

    // Usa lo stesso sistema di TikZ: prima verifica/rigenera il JSON, poi caricalo
    const jsonUrl = url.replace(".php", "_traccia.json");

    console.log("Caricamento Traccia JSON da:", jsonUrl);

    getJSON(jsonUrl)
      .then((tracciaData) => {
        console.log("✅ JSON Traccia caricato!", Object.keys(tracciaData).length, "gruppi");
        // Salva i dati in memoria globale per accesso rapido
        window.tracciaContentCache = tracciaData;
        if (callback) callback();
      })
      .catch(() => {
        console.error("❌ Errore nel caricamento di", jsonUrl);
        alert("Errore nel caricamento dei modelli Traccia. Assicurati che il file JSON sia stato generato.");
      });
  },

  _buildTracciaUI: function (tracciaData, container) {
    const containerEl = asElement(container);
    // Crea i gruppi e pulsanti (stessa logica di TikZ)
    for (const group in tracciaData) {
      const groupDiv = document.createElement("div");
      groupDiv.className = "fm-traccia-group";
      groupDiv.setAttribute("data-group", group);
      const groupBtn = document.createElement("button");
      groupBtn.className = "fm-group-btn";
      groupBtn.textContent = group.replace("gruppo-", "").replace(/-/g, " ");
      groupDiv.appendChild(groupBtn);
      const optionsDiv = document.createElement("div");
      optionsDiv.className = "fm-group-options";

      tracciaData[group].forEach((item, index) => {
        // Crea pulsanti manualmente con data-group e data-index (non data-tikz-*)
        const btn = document.createElement("button");
        btn.className = "traccia-element-btn";
        btn.textContent = item.label;
        btn.setAttribute("data-group", group);
        btn.setAttribute("data-index", index);
        optionsDiv.appendChild(btn);
      });

      groupDiv.appendChild(optionsDiv);
      if (containerEl) containerEl.appendChild(groupDiv);
    }

    // Mostra/nasconde i sottogruppi al click
    delegate("click", "tracciaGroup", ".traccia-group .group-btn", function (event) {
      event.stopPropagation();
      qsa(".fm-traccia-group .fm-group-options").forEach((e) => { e.style.display = "none"; e.classList.remove("active"); });
      qsa(".fm-traccia-group .fm-group-btn").forEach((e) => e.classList.remove("active"));

      const options = siblings(this, ".fm-group-options")[0];
      if (options) { options.style.display = ""; options.classList.add("active"); }
      this.classList.add("active");

      // Calcola dimensioni dinamiche del container
      const containerNode = this.closest(".fm-element-traccia-groups");
      if (containerNode && options) {
        // Aspetta che il browser calcoli le dimensioni dopo show()
        setTimeout(() => {
          const tracciaGroup = this.closest(".fm-traccia-group");
          const totalWidth = outerWidth(tracciaGroup, true) + outerWidth(options, true);

          // Trova l'altezza massima tra tutti i traccia-group
          let maxHeight = 0;
          qsa(".fm-traccia-group", containerNode).forEach((g) => {
            const h = outerHeight(g, true);
            if (h > maxHeight) maxHeight = h;
          });

          // Aggiungi l'altezza delle options attive se è maggiore
          const optionsHeight = outerHeight(options, true);
          if (optionsHeight > maxHeight) maxHeight = optionsHeight;

          setCss(containerNode, { width: totalWidth + "px", height: maxHeight + "px", "max-height": "none" });
        }, 0);
      }
    });

    // Click sui pulsanti degli elementi
    delegate("click", "tracciaElement", ".traccia-element-btn", function (event) {
      event.stopPropagation();

      const group = this.getAttribute("data-group");
      const index = this.getAttribute("data-index");

      if (group && index !== null && window.tracciaContentCache[group]) {
        const item = window.tracciaContentCache[group][index];
        const content = item.content;
        const editorId = EditorSystem.getFocusedEditorId();

        if (editorId && content) {
          // Inserisci il contenuto nell'editor
          if (content.length > 5000) {
            const lines = content.split("\n");
            EditorSystem._insertLargeContent(lines, editorId);
          } else if (content.includes("<")) {
            EditorSystem._insertElement(content, editorId);
          } else {
            EditorSystem._insertPlainText(content, editorId);
          }

          hideAll(".fm-element-traccia-groups");
        } else {
          console.error("Editor non focalizzato o contenuto mancante");
        }
      }
    });
  },

  updateTikzElementGroups: function (url, container) {
    // Se il JSON è già in cache, usalo direttamente senza ricaricarlo
    if (window.tikzContentCache && Object.keys(window.tikzContentCache).length > 0) {
      // console.log("📦 Uso cache TikZ già caricata (" + Object.keys(window.tikzContentCache).length + " gruppi)");
      UIComp._buildTikzUI(window.tikzContentCache, container);
      return;
    }

    // Altrimenti carica il JSON
    UIComp.preloadTikzJSON(url, function () {
      UIComp._buildTikzUI(window.tikzContentCache, container);
    });
  },

  preloadTikzJSON: function (url, callback) {
    // Se già caricato, chiama subito il callback
    if (window.tikzContentCache && Object.keys(window.tikzContentCache).length > 0) {
      console.log("📦 Cache TikZ già disponibile");
      if (callback) callback();
      return;
    }

    const loadJson = () => {
      // Poi carica il JSON (anche se il check fallisce, prova comunque)
      const jsonUrl = url.replace(".php", "_elements.json");
      getJSON(jsonUrl)
        .then((tikzData) => {
          console.log("✅ JSON TikZ caricato!", Object.keys(tikzData).length, "gruppi");
          // Salva i dati in memoria globale per accesso rapido
          window.tikzContentCache = tikzData;
          if (callback) callback();
        })
        .catch(() => {
          console.error("❌ Errore nel caricamento di", jsonUrl);
          alert("Errore nel caricamento dei modelli TikZ. Assicurati che il file JSON sia stato generato.");
        });
    };
    getJSON(Endpoints.tikz.ensureJson)
      .then((checkResult) => {
        if (checkResult.regenerated) {
          console.log("🔃 JSON RIGENERATO:", checkResult.reason, "- Gruppi:", checkResult.groups);
        }
      })
      .catch((error) => {
        console.error("❌ Errore nel check JSON:", error);
      })
      .finally(loadJson);
  },

  /**
   * Precarica il file Elementi_Riservati.html in cache globale
   * Riduce drasticamente le richieste HTTP ripetute
   * @param {Function} callback - Funzione da chiamare con tempDiv cached
   */
  preloadElementiRiservati: function (callback) {
    const CACHE_VERSION = "v3_body_parsed_cachebust"; // Versione cache per forzare reload

    // Se già in cache E versione corretta, usa subito (ma in modo asincrono)
    if (window.elementiRiservatiCache && window.elementiRiservatiCacheVersion === CACHE_VERSION) {
      // Chiama il callback in modo asincrono per non bloccare il return della Promise
      setTimeout(function () {
        if (typeof callback === "function") callback(window.elementiRiservatiCache);
      }, 0);
      return;
    }

    // Se un caricamento è già in corso, accoda il callback e evita richieste duplicate
    if (UIComp._elementiRiservatiLoading) {
      if (typeof callback === "function") {
        UIComp._elementiRiservatiCallbacks.push(callback);
      }
      return;
    }

    UIComp._elementiRiservatiLoading = true;
    if (typeof callback === "function") {
      UIComp._elementiRiservatiCallbacks.push(callback);
    }

    // Cache-bust stabile per versione: permette cache HTTP tra richieste della stessa versione
    const cacheBuster = "?v=" + encodeURIComponent(CACHE_VERSION);
    const flush = (value) => {
      const queuedCallbacks = UIComp._elementiRiservatiCallbacks.slice();
      UIComp._elementiRiservatiCallbacks = [];
      UIComp._elementiRiservatiLoading = false;
      queuedCallbacks.forEach(function (cb) {
        try {
          cb(value);
        } catch (err) {
          console.error("❌ Errore callback preloadElementiRiservati:", err);
        }
      });
    };
    getText("/Elementi_Riservati.html" + cacheBuster)
      .then((data) => {
        // Parsa il documento HTML completo e estrai solo il body (Element DOM).
        const parser = new DOMParser();
        const doc = parser.parseFromString(data, "text/html");
        window.elementiRiservatiCache = doc.body;
        window.elementiRiservatiCacheVersion = CACHE_VERSION;
        flush(window.elementiRiservatiCache);
      })
      .catch(() => {
        console.error("❌ Errore caricamento Elementi_Riservati.html");
        flush(null);
      });
  },

  _buildTikzUI: function (tikzData, container) {
    const containerEl = asElement(container);
    // Crea un contenitore flex per i tre pulsanti di azione
    const actionButtonsWrapper = document.createElement("div");
    actionButtonsWrapper.className = "fm-tex-action-buttons-wrapper";

    const makeActionGroup = (groupClass, btnClass, label, form) => {
      const div = document.createElement("div");
      div.className = "tex-group " + groupClass;
      const b = document.createElement("button");
      b.className = "fm-group-btn " + btnClass;
      b.textContent = label;
      div.appendChild(b);
      if (form) div.appendChild(form);
      return div;
    };

    // Pulsanti azione: crea / elimina / modifica elementi
    actionButtonsWrapper.appendChild(makeActionGroup("fm-add-new-element-group", "fm-add-new-element-btn", "➕", UIComp._createNewElementForm(tikzData)));
    actionButtonsWrapper.appendChild(makeActionGroup("fm-delete-element-group", "fm-delete-element-btn", "🗑️", UIComp._createDeleteElementForm(tikzData)));
    actionButtonsWrapper.appendChild(makeActionGroup("fm-edit-element-group", "fm-edit-element-btn", "✏️", UIComp._createEditElementForm(tikzData)));

    // Aggiungi il wrapper al container
    if (containerEl) containerEl.appendChild(actionButtonsWrapper);

    // Crea i gruppi e pulsanti
    for (const group in tikzData) {
      const groupDiv = document.createElement("div");
      groupDiv.className = "tex-group";
      groupDiv.setAttribute("data-group", group);
      const groupBtn = document.createElement("button");
      groupBtn.className = "fm-group-btn";
      groupBtn.textContent = group.replace("gruppo-", "").replace(/-/g, " ");
      groupDiv.appendChild(groupBtn);
      const optionsDiv = document.createElement("div");
      optionsDiv.className = "fm-group-options";

      tikzData[group].forEach((item, index) => {
        const btn = UIComp._createButton(item.label, null, "tikz-element-btn", ".group-options, .elementTikzGroups", { group: group, index: index });
        optionsDiv.appendChild(btn);
      });

      groupDiv.appendChild(optionsDiv);
      if (containerEl) containerEl.appendChild(groupDiv);
    }

    // Mostra/nasconde i sottogruppi al click
    delegate("click", "tikzGroup", ".group-btn:not(.add-new-element-btn, .delete-element-btn, .edit-element-btn)", function (event) {
      event.stopPropagation();

      // Chiudi i form di aggiunta / eliminazione / modifica se aperti
      closeAll(".fm-new-element-form");
      deactivateAll(".fm-add-new-element-btn");
      closeAll(".fm-delete-element-form");
      deactivateAll(".fm-delete-element-btn");
      closeAll(".fm-edit-element-form");
      deactivateAll(".fm-edit-element-btn");

      // Chiudi tutti gli altri group-options
      qsa(".fm-group-options")
        .filter((e) => !e.matches(".fm-new-element-form, .fm-delete-element-form, .fm-edit-element-form"))
        .forEach((e) => { e.style.display = "none"; e.classList.remove("active"); });
      qsa(".tex-group .fm-group-btn")
        .filter((e) => !e.matches(".fm-add-new-element-btn, .fm-delete-element-btn"))
        .forEach((e) => e.classList.remove("active"));

      const options = siblings(this, ".fm-group-options")[0];
      if (options) { options.style.display = ""; options.classList.add("active"); }
      this.classList.add("active");

      // Calcola dimensioni dinamiche del container
      const containerNode = this.closest(".fm-element-tikz-groups");
      if (containerNode && options) {
        // Aspetta che il browser calcoli le dimensioni dopo show()
        setTimeout(() => {
          // Calcola larghezza: max tra tutti i tex-group + larghezza options attive
          let maxGroupWidth = 0;
          qsa(".tex-group", containerNode).forEach((g) => {
            const w = outerWidth(g, true);
            if (w > maxGroupWidth) maxGroupWidth = w;
          });
          const totalWidth = maxGroupWidth + outerWidth(options, true);

          // Calcola altezza minima: somma di tutti i tex-group
          let minHeight = 0;
          qsa(".tex-group", containerNode).forEach((g) => { minHeight += outerHeight(g, true); });

          // Altezza: somma altezze dei tex-group PRECEDENTI + altezza group-options attive
          const clickedGroup = this.closest(".tex-group");
          let heightBefore = 0;
          prevAll(clickedGroup, ".tex-group").forEach((g) => { heightBefore += outerHeight(g, true); });

          const optionsHeight = outerHeight(options, true);
          const totalHeight = Math.max(heightBefore + optionsHeight, minHeight);

          setCss(containerNode, { width: totalWidth + "px", height: totalHeight + "px", "max-height": "none" });
        }, 0);
      }
    });
    // Chiusura globale al click "fuori". L'originale jQuery (.on("click.hideTikzGroup"))
    // si affidava a stopPropagation() dei handler specifici (delegati → eseguiti
    // prima del direct-bound in jQuery) per non chiudere quando si clicca un
    // controllo. In vanilla replichiamo con guard: bail se il click è dentro un
    // controllo/form della palette (sia classi fm- sia legacy non-prefissate).
    onNs(document, "click", "hideTikzGroup", function (e) {
      const SKIP =
        ".group-btn, .fm-group-btn, .fm-group-options, .traccia-element-btn, .tikz-element-btn," +
        ".new-element-form, .fm-new-element-form, .delete-element-form, .fm-delete-element-form," +
        ".edit-element-form, .fm-edit-element-form, .add-new-element-btn, .fm-add-new-element-btn," +
        ".delete-element-btn, .fm-delete-element-btn, .edit-element-btn, .fm-edit-element-btn";
      if (e.target && typeof e.target.closest === "function" && e.target.closest(SKIP)) return;
      closeAll(".fm-group-options");
      deactivateAll(".tex-group .fm-group-btn");
      deactivateAll(".fm-traccia-group .fm-group-btn");
      deactivateAll(".fm-traccia-group .fm-group-options");
      closeAll(".fm-new-element-form");
      deactivateAll(".fm-add-new-element-btn");
      closeAll(".fm-delete-element-form");
      deactivateAll(".fm-delete-element-btn");
      closeAll(".fm-edit-element-form");
      deactivateAll(".fm-edit-element-btn");
    });

    // Handler per il pulsante di aggiunta nuovo elemento
    UIComp._initNewElementFormHandlers();
  },

  _createNewElementForm: function (tikzData) {
    // Crea l'elenco dei gruppi esistenti per il select
    let groupOptions = '<option value="">-- Seleziona gruppo esistente --</option>';
    for (const group in tikzData) {
      const displayName = group.replace("gruppo-", "").replace(/-/g, " ");
      groupOptions += '<option value="' + group + '">' + displayName + "</option>";
    }

    const formHtml = `
      <div class="fm-group-options fm-new-element-form" style="display:none;">
        <div class="fm-form-section">
          <label>Nuovo Gruppo:</label>
          <input type="text" class="newGroupName" placeholder="Nome nuovo gruppo" />
        </div>
        <div class="fm-form-section">
          <label>O scegli gruppo esistente:</label>
          <select class="existingGroupSelect">
            ${groupOptions}
          </select>
        </div>
        <div class="fm-form-section">
          <label>Tipo di elemento:</label>
          <div class="fm-checkbox-group">
            <label><input type="radio" name="elementType" value="tikz" checked /> TikZ</label>
            <label><input type="radio" name="elementType" value="latex" /> LaTeX</label>
          </div>
        </div>
        <div class="fm-form-section">
          <label>Nome elemento:</label>
          <input type="text" class="elementLabel" placeholder="Inserisci nome elemento" />
        </div>
        <div class="fm-form-section">
          <label>Codice:</label>
          <textarea class="elementCode" placeholder="Inserisci codice LaTeX o TikZ" rows="10"></textarea>
        </div>
        <div class="form-actions">
          <button class="fm-save-new-element-btn">💾 Salva</button>
          <button class="fm-cancel-new-element-btn">❌ Annulla</button>
        </div>
      </div>
    `;

    return elFromHTML(formHtml);
  },

  _createDeleteElementForm: function (tikzData) {
    // Crea l'elenco dei gruppi per il select
    let groupOptions = '<option value="">-- Seleziona gruppo --</option>';
    for (const group in tikzData) {
      const displayName = group.replace("gruppo-", "").replace(/-/g, " ");
      groupOptions += '<option value="' + group + '">' + displayName + "</option>";
    }

    const formHtml = `
      <div class="fm-group-options fm-delete-element-form" style="display:none;">
        <div class="fm-form-section">
          <label>Seleziona Gruppo:</label>
          <select id="deleteGroupSelect">
            ${groupOptions}
          </select>
        </div>
        <div class="fm-form-section" id="deleteElementSection" style="display:none;">
          <label>Seleziona Elemento:</label>
          <select id="deleteElementSelect">
            <option value="">-- Seleziona elemento --</option>
          </select>
        </div>
        <div class="form-actions">
          <button class="fm-delete-single-element-btn" style="display:none;">🗑️ Elimina Elemento</button>
          <button class="fm-delete-whole-group-btn" style="display:none;">🗑️ Elimina Gruppo</button>
          <button class="fm-cancel-delete-element-btn">❌ Annulla</button>
        </div>
      </div>
    `;

    return elFromHTML(formHtml);
  },

  _createEditElementForm: function (tikzData) {
    // Crea l'elenco dei gruppi per il select
    let groupOptions = '<option value="">-- Seleziona gruppo --</option>';
    for (const group in tikzData) {
      const displayName = group.replace("gruppo-", "").replace(/-/g, " ");
      groupOptions += '<option value="' + group + '">' + displayName + "</option>";
    }

    const formHtml = `
      <div class="fm-group-options fm-edit-element-form" style="display:none;">
        <div class="fm-form-section">
          <label>Seleziona Gruppo:</label>
          <select class="editGroupSelect">
            ${groupOptions}
          </select>
        </div>
        <div class="fm-form-section editElementSection" style="display:none;">
          <label>Seleziona Elemento:</label>
          <select class="editElementSelect">
            <option value="">-- Seleziona elemento --</option>
          </select>
        </div>
        <div class="editFormFields" style="display:none;">
          <div class="fm-form-section">
            <label>Nuovo Nome Gruppo (lascia vuoto per mantenere):</label>
            <input type="text" class="editGroupName" placeholder="Nuovo nome per il gruppo" />
          </div>
          <div class="fm-form-section">
            <label>O sposta in gruppo esistente:</label>
            <select class="editMoveToGroup">
              <option value="">-- Mantieni gruppo corrente --</option>
              ${groupOptions}
            </select>
          </div>
          <div class="fm-form-section">
            <label>Tipo di elemento:</label>
            <div class="fm-checkbox-group">
              <label><input type="radio" name="editElementType" value="tikz" checked /> TikZ</label>
              <label><input type="radio" name="editElementType" value="latex" /> LaTeX</label>
            </div>
          </div>
          <div class="fm-form-section">
            <label>Nome elemento:</label>
            <input type="text" class="editElementLabel" placeholder="Nome elemento" />
          </div>
          <div class="fm-form-section">
            <label>Codice:</label>
            <textarea class="editElementCode" placeholder="Codice LaTeX o TikZ" rows="10"></textarea>
          </div>
        </div>
        <div class="form-actions">
          <button class="fm-save-edit-element-btn" style="display:none;">✏️ Modifica</button>
          <button class="fm-cancel-edit-element-btn">❌ Annulla</button>
        </div>
      </div>
    `;

    return elFromHTML(formHtml);
  },

  _initNewElementFormHandlers: function () {
    const normalizeGroupName = function (rawGroupName) {
      if (!rawGroupName) return "";
      let normalized = String(rawGroupName).trim().replace(/\s+/g, " ").toLowerCase().replace(/ /g, "-");
      if (!normalized.startsWith("gruppo-")) {
        normalized = "gruppo-" + normalized;
      }
      return normalized;
    };

    const hasDuplicateLabelInGroup = function (groupKey, elementLabel) {
      if (!groupKey || !elementLabel || !window.tikzContentCache || !window.tikzContentCache[groupKey]) {
        return false;
      }

      const normalizedLabel = String(elementLabel).trim().toLowerCase();
      return window.tikzContentCache[groupKey].some(
        (item) =>
          String(item.label || "")
            .trim()
            .toLowerCase() === normalizedLabel,
      );
    };

    // Impedisci che i click all'interno del form chiudano il menu
    delegate("click", "formStopPropagation", ".new-element-form", function (e) {
      e.stopPropagation();
    });

    // Handler per aprire/chiudere il form
    delegate("click", "addNewElement", ".add-new-element-btn", function (e) {
      e.stopPropagation();
      e.preventDefault();

      console.log("Pulsante Aggiungi Elemento cliccato");

      // Chiudi il form di eliminazione se è aperto
      closeAll(".fm-delete-element-form");
      deactivateAll(".fm-delete-element-btn");

      // Chiudi tutti gli altri group-options
      qsa(".fm-group-options")
        .filter((el) => !el.matches(".fm-new-element-form, .fm-delete-element-form"))
        .forEach((el) => { el.style.display = "none"; el.classList.remove("active"); });
      qsa(".tex-group .fm-group-btn")
        .filter((el) => !el.matches(".fm-add-new-element-btn, .fm-delete-element-btn"))
        .forEach((el) => el.classList.remove("active"));

      // Toggle del form
      const form = siblings(this, ".fm-new-element-form")[0];
      console.log("Form trovato:", form ? 1 : 0);
      if (!form) return;

      if (isVisible(form)) {
        form.style.display = "none";
        form.classList.remove("active");
        this.classList.remove("active");
      } else {
        form.style.display = "";
        form.classList.add("active");
        this.classList.add("active");

        // Calcola dimensioni dinamiche del container per il form
        const containerNode = this.closest(".fm-element-tikz-groups");
        if (containerNode) {
          setTimeout(() => {
            // Calcola larghezza: max tra tutti i tex-group + larghezza form
            let maxGroupWidth = 0;
            qsa(".tex-group", containerNode).forEach((g) => {
              const w = outerWidth(g, true);
              if (w > maxGroupWidth) maxGroupWidth = w;
            });
            const totalWidth = maxGroupWidth + outerWidth(form, true);

            // Calcola altezza minima: somma di tutti i tex-group
            let minHeight = 0;
            qsa(".tex-group", containerNode).forEach((g) => { minHeight += outerHeight(g, true); });

            // Altezza totale: max tra minHeight e altezza form
            const totalHeight = Math.max(minHeight, outerHeight(form, true));

            setCss(containerNode, { width: totalWidth + "px", height: totalHeight + "px", "max-height": "none" });
          }, 0);
        }
      }
    });

    // Handler per il pulsante Salva
    delegate("click", "saveNewElement", ".save-new-element-btn", function (e) {
        e.preventDefault();
        e.stopPropagation();

        // Trova il form relativo a questo pulsante
        const form = this.closest(".fm-new-element-form");
        const fval = (sel) => (form.querySelector(sel)?.value ?? "");

        const groupName = fval(".newGroupName").trim();
        const existingGroup = fval(".existingGroupSelect");
        const elementType = form.querySelector('input[name="elementType"]:checked')?.value;
        const label = fval(".elementLabel").trim();
        const code = fval(".elementCode").trim();

        // Debug: verifica valori letti dal form
        console.log("📋 Valori form:", {
          groupName: groupName,
          existingGroup: existingGroup,
          elementType: elementType,
          label: label,
          codeLength: code.length,
        });

        // Validazione - deve esserci ALMENO uno dei due (nuovo gruppo o gruppo esistente)
        // Se c'è un gruppo esistente selezionato, va bene anche se newGroupName è vuoto
        if (!existingGroup && !groupName) {
          console.error("⚠️ Validazione fallita: nessun gruppo specificato");
          if (typeof ToastManager !== "undefined") {
            ToastManager.show("warning", "Attenzione", "Inserisci un nome per il nuovo gruppo o seleziona un gruppo esistente", 4500);
          } else {
            alert("Inserisci un nome per il nuovo gruppo o seleziona un gruppo esistente");
          }
          return;
        }

        if (!label) {
          if (typeof ToastManager !== "undefined") {
            ToastManager.show("warning", "Attenzione", "Inserisci una label per l'elemento", 4500);
          } else {
            alert("Inserisci una label per l'elemento");
          }
          return;
        }

        if (!code) {
          if (typeof ToastManager !== "undefined") {
            ToastManager.show("warning", "Attenzione", "Inserisci il codice LaTeX o TikZ", 4500);
          } else {
            alert("Inserisci il codice LaTeX o TikZ");
          }
          return;
        }

        const targetGroup = existingGroup || normalizeGroupName(groupName);
        if (hasDuplicateLabelInGroup(targetGroup, label)) {
          if (typeof ToastManager !== "undefined") {
            ToastManager.show("warning", "Attenzione", "Esiste già un elemento con lo stesso nome nel gruppo selezionato. Scegli un nome diverso.", 4500);
          } else {
            alert("Esiste già un elemento con lo stesso nome nel gruppo selezionato. Scegli un nome diverso.");
          }
          return;
        }

        // Invia i dati al server
        _postJson(Endpoints.tikz.saveNewElement, {
          groupName: groupName,
          existingGroup: existingGroup,
          elementType: elementType,
          label: label,
          code: code,
        })
          .then(function (response) {
            console.log("📥 Risposta server:", response);

            if (response.success) {
              if (response.debug) {
                console.log("🔍 Debug info:", response.debug);
              }

              if (typeof ToastManager !== "undefined") {
                ToastManager.showSuccess("Elemento salvato con successo nel gruppo: " + response.group, 4500);
              } else {
                alert("Elemento salvato con successo nel gruppo: " + response.group);
              }

              // Resetta il form specifico
              form.querySelectorAll(".newGroupName, .existingGroupSelect, .elementLabel, .elementCode").forEach((f) => { f.value = ""; });

              // Chiudi il form
              form.style.display = "none";
              form.classList.remove("active");
              siblings(form, ".fm-add-new-element-btn").forEach((b) => b.classList.remove("active"));

              // Ricarica la cache e aggiorna l'UI
              window.tikzContentCache = null;

              const container = document.querySelector(".fm-element-tikz-groups");
              if (container) container.replaceChildren();

              // Forza il ricaricamento del JSON dal server con cache-busting
              const jsonUrl = "/modelli_tikz_elements.json?cachebust=" + Date.now();
              getJSON(jsonUrl)
                .then((tikzData) => {
                  console.log("✅ JSON TikZ ricaricato dopo salvataggio!", Object.keys(tikzData).length, "gruppi");
                  window.tikzContentCache = tikzData;
                  UIComp._buildTikzUI(tikzData, container);
                })
                .catch((error) => {
                  console.error("❌ Errore nel ricaricamento JSON:", error);
                  if (typeof ToastManager !== "undefined") {
                    ToastManager.show("warning", "Attenzione", "Elemento salvato ma errore nel ricaricamento. Ricarica la pagina.", 4500);
                  } else {
                    alert("Elemento salvato ma errore nel ricaricamento. Ricarica la pagina.");
                  }
                });
            } else {
              console.error("❌ Errore dal server:", response);
              if (response.debug) {
                console.log("🔍 Debug info:", response.debug);
              }
              if (typeof ToastManager !== "undefined") {
                ToastManager.showError(response.error || "Impossibile salvare l'elemento");
              } else {
                alert(response.error || "Impossibile salvare l'elemento");
              }
            }
          })
          .catch(function (xhr) {
            const status = xhr?.code, error = xhr?.message;
            if (typeof ToastManager !== "undefined") {
              ToastManager.showError("Errore nella richiesta: " + error);
            } else {
              alert("Errore nella richiesta: " + error);
            }
            console.error("Errore AJAX:", xhr, status, error);
          });
      });

    // Handler per il pulsante Annulla
    delegate("click", "cancelNewElement", ".cancel-new-element-btn", function (e) {
      e.preventDefault();
      e.stopPropagation();

      // Trova il form relativo a questo pulsante
      const form = this.closest(".fm-new-element-form");

      // Resetta il form specifico
      form.querySelectorAll(".newGroupName, .existingGroupSelect, .elementLabel, .elementCode").forEach((f) => { f.value = ""; });

      // Chiudi il form
      form.style.display = "none";
      form.classList.remove("active");
      siblings(form, ".fm-add-new-element-btn").forEach((b) => b.classList.remove("active"));
    });

    // Inizializza gli handler per il form di eliminazione
    UIComp._initDeleteElementFormHandlers();
  },

  _initDeleteElementFormHandlers: function () {
    const showDeleteToast = function (type, message, duration = 4500) {
      if (typeof ToastManager !== "undefined") {
        const title = type === "error" ? "Errore" : type === "warning" ? "Attenzione" : "Successo";
        ToastManager.show(type, title, message, duration);
      } else {
        alert(message);
      }
    };

    // Impedisci che i click all'interno del form chiudano il menu
    delegate("click", "deleteFormStopPropagation", ".delete-element-form", function (e) {
      e.stopPropagation();
    });

    // Handler per aprire/chiudere il form di eliminazione
    delegate("click", "deleteElement", ".delete-element-btn", function (e) {
      e.stopPropagation();
      e.preventDefault();

      console.log("Pulsante Elimina Elemento cliccato");

      // Chiudi il form di aggiunta se è aperto
      closeAll(".fm-new-element-form");
      deactivateAll(".fm-add-new-element-btn");

      // Chiudi tutti gli altri group-options
      qsa(".fm-group-options")
        .filter((el) => !el.matches(".fm-new-element-form, .fm-delete-element-form"))
        .forEach((el) => { el.style.display = "none"; el.classList.remove("active"); });
      qsa(".tex-group .fm-group-btn")
        .filter((el) => !el.matches(".fm-add-new-element-btn, .fm-delete-element-btn"))
        .forEach((el) => el.classList.remove("active"));

      // Toggle del form
      const form = siblings(this, ".fm-delete-element-form")[0];
      const btn = this;
      if (!form) return;

      // Usa la classe active per determinare lo stato invece di :visible
      if (btn.classList.contains("active")) {
        form.style.display = "none";
        form.classList.remove("active");
        btn.classList.remove("active");
      } else {
        form.style.display = "";
        form.classList.add("active");
        btn.classList.add("active");

        // Calcola dimensioni dinamiche del container per il form
        const containerNode = btn.closest(".fm-element-tikz-groups");
        if (containerNode) {
          setTimeout(() => {
            let maxGroupWidth = 0;
            qsa(".tex-group", containerNode).forEach((g) => {
              const w = outerWidth(g, true);
              if (w > maxGroupWidth) maxGroupWidth = w;
            });
            const totalWidth = maxGroupWidth + outerWidth(form, true);

            let minHeight = 0;
            qsa(".tex-group", containerNode).forEach((g) => { minHeight += outerHeight(g, true); });

            const totalHeight = Math.max(minHeight, outerHeight(form, true));

            setCss(containerNode, { width: totalWidth + "px", height: totalHeight + "px", "max-height": "none" });
          }, 0);
        }
      }
    });

    // Handler per il cambio gruppo nel select
    delegate("change", "deleteGroupSelect", "#deleteGroupSelect", function (e) {
      const selectedGroup = this.value;

      if (!selectedGroup) {
        hideAll("#deleteElementSection");
        hideAll(".fm-delete-single-element-btn");
        hideAll(".fm-delete-whole-group-btn");
        return;
      }

      // Mostra il pulsante per eliminare l'intero gruppo
      qsa(".fm-delete-whole-group-btn").forEach((b) => { b.style.display = ""; });

      // Carica gli elementi del gruppo selezionato
      if (window.tikzContentCache && window.tikzContentCache[selectedGroup]) {
        const elements = window.tikzContentCache[selectedGroup];
        let elementOptions = '<option value="">-- Seleziona elemento --</option>';

        elements.forEach((item) => {
          elementOptions += '<option value="' + item.label + '">' + item.label + "</option>";
        });

        const sel = document.querySelector("#deleteElementSelect");
        if (sel) sel.innerHTML = elementOptions;
        setDisplay("#deleteElementSection", "");
      }
    });

    // Handler per il cambio elemento nel select
    delegate("change", "deleteElementSelect", "#deleteElementSelect", function (e) {
      const selectedElement = this.value;
      if (selectedElement) {
        qsa(".fm-delete-single-element-btn").forEach((b) => { b.style.display = ""; });
      } else {
        hideAll(".fm-delete-single-element-btn");
      }
    });

    // Helper condiviso: reset + chiusura form delete + reload JSON.
    const resetDeleteFormAndReload = (logLabel) => {
      const setVal = (sel) => { const e = document.querySelector(sel); if (e) e.value = ""; };
      setVal("#deleteGroupSelect");
      setVal("#deleteElementSelect");
      hideAll("#deleteElementSection");
      hideAll(".fm-delete-single-element-btn");
      hideAll(".fm-delete-whole-group-btn");
      closeAll(".fm-delete-element-form");
      deactivateAll(".fm-delete-element-btn");

      window.tikzContentCache = null;
      const container = document.querySelector(".fm-element-tikz-groups");
      if (container) container.replaceChildren();

      getJSON("/modelli_tikz_elements.json?t=" + Date.now())
        .then((tikzData) => {
          console.log("✅ JSON ricaricato dopo " + logLabel + ":", Object.keys(tikzData).length, "gruppi");
          window.tikzContentCache = tikzData;
          UIComp._buildTikzUI(tikzData, container);
        })
        .catch(() => {
          console.error("❌ Errore nel ricaricamento JSON");
          // Fallback: usa il metodo normale
          UIComp.updateTikzElementGroups(Endpoints.templates.modelliTikz, container);
        });
    };

    // Handler per eliminare un singolo elemento
    delegate("click", "deleteSingleElement", ".delete-single-element-btn", async function (e) {
      e.preventDefault();
      e.stopPropagation();

      const groupName = document.querySelector("#deleteGroupSelect")?.value;
      const elementLabel = document.querySelector("#deleteElementSelect")?.value;

      if (!groupName || !elementLabel) {
        showDeleteToast("warning", "Seleziona un gruppo e un elemento");
        return;
      }

      if (!await window.FM.Dialog.confirm("Sei sicuro di voler eliminare l'elemento \"" + elementLabel + '"?')) {
        return;
      }

      _postJson(Endpoints.tikz.deleteElement, { groupName: groupName, elementLabel: elementLabel, deleteWholeGroup: false })
        .then(function (response) {
          if (response.success) {
            showDeleteToast("success", "Elemento eliminato con successo!");
            resetDeleteFormAndReload("eliminazione");
          } else {
            showDeleteToast("error", response.error || "Impossibile eliminare l'elemento");
          }
        })
        .catch(function (xhr) {
          const status = xhr?.code, error = xhr?.message;
          showDeleteToast("error", "Errore nella richiesta: " + error);
          console.error("Errore AJAX:", xhr, status, error);
        });
    });

    // Handler per eliminare l'intero gruppo
    delegate("click", "deleteWholeGroup", ".delete-whole-group-btn", async function (e) {
      e.preventDefault();
      e.stopPropagation();

      const groupName = document.querySelector("#deleteGroupSelect")?.value;

      if (!groupName) {
        showDeleteToast("warning", "Seleziona un gruppo");
        return;
      }

      const displayName = groupName.replace("gruppo-", "").replace(/-/g, " ");
      if (!await window.FM.Dialog.confirm("Sei sicuro di voler eliminare l'intero gruppo \"" + displayName + '" e tutti i suoi elementi?')) {
        return;
      }

      _postJson(Endpoints.tikz.deleteElement, { groupName: groupName, deleteWholeGroup: true })
        .then(function (response) {
          if (response.success) {
            showDeleteToast("success", "Gruppo eliminato con successo!");
            resetDeleteFormAndReload("eliminazione gruppo");
          } else {
            showDeleteToast("error", response.error || "Impossibile eliminare il gruppo");
          }
        })
        .catch(function (xhr) {
          const status = xhr?.code, error = xhr?.message;
          showDeleteToast("error", "Errore nella richiesta: " + error);
          console.error("Errore AJAX:", xhr, status, error);
        });
    });

    // Handler per il pulsante Annulla
    delegate("click", "cancelDeleteElement", ".cancel-delete-element-btn", function (e) {
      e.preventDefault();
      e.stopPropagation();

      const setVal = (sel) => { const el = document.querySelector(sel); if (el) el.value = ""; };
      setVal("#deleteGroupSelect");
      setVal("#deleteElementSelect");
      hideAll("#deleteElementSection");
      hideAll(".fm-delete-single-element-btn");
      hideAll(".fm-delete-whole-group-btn");
      closeAll(".fm-delete-element-form");
      deactivateAll(".fm-delete-element-btn");
    });

    // ========== HANDLER PER MODIFICA ELEMENTO ==========

    // Impedisci che i click all'interno del form chiudano il menu
    delegate("click", "editFormStopPropagation", ".edit-element-form", function (e) {
      e.stopPropagation();
    });

    // Handler per aprire/chiudere il form di modifica
    delegate("click", "editElement", ".edit-element-btn", function (e) {
      e.stopPropagation();
      e.preventDefault();

      // Chiudi il form di aggiunta se è aperto
      closeAll(".fm-new-element-form");
      deactivateAll(".fm-add-new-element-btn");

      // Chiudi il form di eliminazione se è aperto
      closeAll(".fm-delete-element-form");
      deactivateAll(".fm-delete-element-btn");

      // Chiudi tutti gli altri group-options
      qsa(".fm-group-options")
        .filter((el) => !el.matches(".fm-edit-element-form"))
        .forEach((el) => { el.style.display = "none"; el.classList.remove("active"); });
      qsa(".tex-group .fm-group-btn")
        .filter((el) => !el.matches(".fm-edit-element-btn"))
        .forEach((el) => el.classList.remove("active"));

      // Toggle del form
      const form = siblings(this, ".fm-edit-element-form")[0];
      const btn = this;
      if (!form) return;

      if (btn.classList.contains("active")) {
        form.style.display = "none";
        form.classList.remove("active");
        btn.classList.remove("active");
      } else {
        form.style.display = "";
        form.classList.add("active");
        btn.classList.add("active");

        // Calcola dimensioni dinamiche del container
        const containerNode = btn.closest(".fm-element-tikz-groups");
        if (containerNode) {
          setTimeout(() => {
            let maxGroupWidth = 0;
            qsa(".tex-group", containerNode).forEach((g) => {
              const w = outerWidth(g, true);
              if (w > maxGroupWidth) maxGroupWidth = w;
            });
            const totalWidth = maxGroupWidth + outerWidth(form, true);

            let minHeight = 0;
            qsa(".tex-group", containerNode).forEach((g) => { minHeight += outerHeight(g, true); });

            const totalHeight = Math.max(minHeight, outerHeight(form, true));

            setCss(containerNode, { width: totalWidth + "px", height: totalHeight + "px", "max-height": "none" });
          }, 0);
        }
      }
    });

    // Handler per il cambio gruppo nel select di modifica
    delegate("change", "editGroupSelect", ".editGroupSelect", function (e) {
        const selectedGroup = this.value;
        const form = this.closest(".fm-edit-element-form");

        if (!selectedGroup) {
          hideAll(".editElementSection", form);
          hideAll(".editFormFields", form);
          hideAll(".fm-save-edit-element-btn", form);
          return;
        }

        // Carica gli elementi del gruppo selezionato dal JSON
        getJSON("/modelli_tikz_elements.json?t=" + Date.now())
          .then(function (data) {
            const elements = data[selectedGroup];
            if (!elements || elements.length === 0) {
              alert("Nessun elemento trovato in questo gruppo");
              return;
            }

            // Popola il select degli elementi
            const elementSelect = form.querySelector(".editElementSelect");
            if (elementSelect) {
              elementSelect.replaceChildren();
              elementSelect.insertAdjacentHTML("beforeend", '<option value="">-- Seleziona elemento --</option>');
              elements.forEach(function (element, index) {
                elementSelect.insertAdjacentHTML("beforeend", '<option value="' + index + '">' + element.label + "</option>");
              });
            }

            // Mostra la sezione di selezione elemento
            qsa(".editElementSection", form).forEach((el) => { el.style.display = ""; });
          })
          .catch(function () {
            alert("Errore nel caricamento degli elementi");
          });
      });

    // Handler per il cambio elemento nel select
    delegate("change", "editElementSelect", ".editElementSelect", function (e) {
        const elementIndex = this.value;
        const form = this.closest(".fm-edit-element-form");
        const selectedGroup = form.querySelector(".editGroupSelect")?.value;

        if (!elementIndex || elementIndex === "") {
          hideAll(".editFormFields", form);
          hideAll(".fm-save-edit-element-btn", form);
          return;
        }

        // Carica i dati dell'elemento selezionato
        getJSON("/modelli_tikz_elements.json?t=" + Date.now())
          .then(function (data) {
            const element = data[selectedGroup][parseInt(elementIndex)];

            if (!element) {
              alert("Elemento non trovato");
              return;
            }

            // Popola i campi del form con i dati esistenti
            const labelInput = form.querySelector(".editElementLabel");
            const codeInput = form.querySelector(".editElementCode");
            if (labelInput) labelInput.value = element.label;
            if (codeInput) codeInput.value = element.content;

            // Imposta il tipo (tikz o latex) dal JSON
            const editFormFields = form.querySelector(".editFormFields");
            if (editFormFields) {
              // Prima deseleziona entrambi, poi seleziona quello corretto
              editFormFields.querySelectorAll('input[name="editElementType"]').forEach((r) => { r.checked = false; });
              const wanted = element.type === "tikz" ? "tikz" : "latex";
              const radio = editFormFields.querySelector('input[name="editElementType"][value="' + wanted + '"]');
              if (radio) radio.checked = true;
            }

            // Mostra i campi di modifica e il pulsante salva
            qsa(".editFormFields", form).forEach((el) => { el.style.display = ""; });
            qsa(".fm-save-edit-element-btn", form).forEach((el) => { el.style.display = ""; });
          })
          .catch(function () {
            alert("Errore nel caricamento dei dati dell'elemento");
          });
      });

    // Handler per il pulsante Salva Modifiche
    delegate("click", "saveEditElement", ".save-edit-element-btn", function (e) {
        e.preventDefault();
        e.stopPropagation();

        console.log("🔔 Click su pulsante Modifica rilevato!");

        const form = this.closest(".fm-edit-element-form");
        const wrapper = this.closest(".fm-editor-wrapper");
        const fval = (sel) => (form.querySelector(sel)?.value ?? "");

        const groupName = fval(".editGroupSelect");
        const elementIndex = fval(".editElementSelect");
        const newGroupName = fval(".editGroupName").trim();
        const moveToGroup = fval(".editMoveToGroup");
        const elementType = form.querySelector('input[name="editElementType"]:checked')?.value;
        const label = fval(".editElementLabel").trim();
        const code = fval(".editElementCode").trim();

        console.log("📝 Dati modifica:", {
          groupName,
          elementIndex,
          newGroupName: newGroupName || "(vuoto)",
          moveToGroup: moveToGroup || "(vuoto)",
          elementType,
          label: label || "(vuoto)",
          codeLength: code.length,
        });

        if (!groupName || !elementIndex) {
          if (typeof ToastManager !== "undefined") {
            ToastManager.show("warning", "Attenzione", "Seleziona gruppo e elemento", 4500);
          } else {
            alert("Seleziona gruppo e elemento");
          }
          return;
        }

        if (!label) {
          if (typeof ToastManager !== "undefined") {
            ToastManager.show("warning", "Attenzione", "Il nome elemento è obbligatorio", 4500);
          } else {
            alert("Il nome elemento è obbligatorio");
          }
          return;
        }

        if (!code) {
          if (typeof ToastManager !== "undefined") {
            ToastManager.show("warning", "Attenzione", "Il codice è obbligatorio", 4500);
          } else {
            alert("Il codice è obbligatorio");
          }
          return;
        }

        console.log("✅ Validazione OK, invio richiesta AJAX...");

        // Invia la richiesta di modifica al server
        console.log("📤 Invio richiesta AJAX a /edit_tikz_element.php");
        console.log("📦 Dati inviati:", {
          groupName, elementIndex, newGroupName, moveToGroup, elementType, label, codeLength: code.length,
        });
        const _editCtrl = new AbortController();
        const _editTo = setTimeout(() => _editCtrl.abort(), 30000);
        const _editBody = new URLSearchParams();
        for (const [k, v] of Object.entries({ groupName, elementIndex, newGroupName, moveToGroup, elementType, label, code })) {
          if (v != null) _editBody.append(k, typeof v === "object" ? JSON.stringify(v) : String(v));
        }
        const ajaxRequest = fetch(Endpoints.tikz.editElement, {
          method: "POST", credentials: "same-origin",
          headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
          body: _editBody.toString(), signal: _editCtrl.signal,
        }).then(async (res) => {
          clearTimeout(_editTo);
          const t = await res.text();
          if (!res.ok) throw { status: res.status, statusText: res.statusText, responseText: t, message: res.statusText };
          try { return JSON.parse(t); }
          catch { throw { status: res.status, statusText: "parsererror", responseText: t, message: "Invalid JSON" }; }
        }, (err) => {
          clearTimeout(_editTo);
          const isAbort = err?.name === "AbortError";
          throw { status: 0, statusText: isAbort ? "timeout" : "error", responseText: "", message: err?.message || "" };
        });

        ajaxRequest.then(function (response) {
          console.log("✅ Risposta ricevuta:", response);
          if (response.success) {
            // Mostra il gruppo finale (potrebbe essere stato rinominato o l'elemento spostato)
            const finalGroup = response.group || groupName;
            const wasRenamed = !!response.renamed;
            const wasMoved = !!response.moved;

            let successMessage = "✅ Elemento modificato con successo";
            if (wasRenamed) {
              successMessage += '\n📝 Gruppo rinominato: "' + response.originalGroup.replace(/-/g, " ").replace("gruppo ", "") + '" → "' + finalGroup.replace(/-/g, " ").replace("gruppo ", "") + '"';
            } else if (wasMoved) {
              successMessage += '\n📦 Elemento spostato nel gruppo: "' + finalGroup.replace(/-/g, " ").replace("gruppo ", "") + '"';
            } else {
              successMessage += '\n📁 Gruppo: "' + finalGroup.replace(/-/g, " ").replace("gruppo ", "") + '"';
            }

            if (typeof ToastManager !== "undefined") {
              ToastManager.showSuccess(successMessage, 4500);
            } else {
              alert(successMessage);
            }

            // Resetta il form
            [".editGroupSelect", ".editElementSelect", ".editGroupName", ".editMoveToGroup", ".editElementLabel", ".editElementCode"]
              .forEach((sel) => { const el = form.querySelector(sel); if (el) el.value = ""; });
            hideAll(".editElementSection", form);
            hideAll(".editFormFields", form);
            hideAll(".fm-save-edit-element-btn", form);

            // Chiudi il form
            if (wrapper) {
              closeAll(".fm-edit-element-form", wrapper);
              deactivateAll(".fm-edit-element-btn", wrapper);
            }

            // Ricarica la cache e aggiorna l'UI
            window.tikzContentCache = null;

            const container = document.querySelector(".fm-element-tikz-groups");
            if (container) container.replaceChildren();

            UIComp.updateTikzElementGroups(Endpoints.templates.modelliTikz, container);
          } else {
            console.error("❌ Errore dal server:", response);
            if (typeof ToastManager !== "undefined") {
              ToastManager.showError(response.error || "Modifica fallita");
            } else {
              alert(response.error || "Modifica fallita");
            }
          }
        }).catch(() => { /* errori gestiti dal .catch dedicato sotto */ });

        ajaxRequest.catch(function (xhr) {
          const status = xhr?.statusText, error = xhr?.message;
          console.error("❌ Errore AJAX:", { status, error, xhr });
          console.error("❌ Response text:", xhr.responseText);
          if (typeof ToastManager !== "undefined") {
            ToastManager.showError("Errore di comunicazione con il server: " + error);
          } else {
            alert("Errore di comunicazione con il server: " + error);
          }
        });
      });

    // Handler per il pulsante Annulla modifica
    delegate("click", "cancelEditElement", ".cancel-edit-element-btn", function (e) {
      e.preventDefault();
      e.stopPropagation();

      const form = this.closest(".fm-edit-element-form");
      const wrapper = this.closest(".fm-editor-wrapper");

      // Resetta il form
      [".editGroupSelect", ".editElementSelect", ".editElementLabel", ".editElementCode"]
        .forEach((sel) => { const el = form.querySelector(sel); if (el) el.value = ""; });
      hideAll(".editElementSection", form);
      hideAll(".editFormFields", form);
      hideAll(".fm-save-edit-element-btn", form);

      // Chiudi il form
      if (wrapper) {
        closeAll(".fm-edit-element-form", wrapper);
        deactivateAll(".fm-edit-element-btn", wrapper);
      }
    });
  },

  _helpMessManager: function () {
    qsa("[id^=type_ver] .fm-help-circle").forEach((e) => e.remove());
    // G20.7 — Scope per-circle: il vecchio impl usava $(".fm-help-message")
    // globale → click su una help-circle apriva TUTTI i .help-message
    // della pagina. Ora il toggle e' scoped al .help-message DIRETTAMENTE
    // contenuto in this. Position: sotto al circle (top:100% + offset)
    // per evitare clipping fuori dal topbar (era top: -100px → cut).
    onNs(document, "click", "fmHelp", function (e) {
      const clicked = e.target.closest(".fm-help-circle");
      // chiudi tutti i message aperti se click fuori da una help-circle
      // o se click ri-su quella attiva (toggle).
      const allOpen = qsa(".fm-help-message[data-fm-help-open='1']");
      if (!clicked) {
        allOpen.forEach((m) => { m.style.display = "none"; m.removeAttribute("data-fm-help-open"); });
        return;
      }
      const msg = clicked.querySelector(".fm-help-message");
      if (!msg) return;
      const isOpen = msg.getAttribute("data-fm-help-open") === "1";
      // chiudi sempre gli altri (mutex tra help-circles)
      allOpen.filter((m) => m !== msg).forEach((m) => { m.style.display = "none"; m.removeAttribute("data-fm-help-open"); });
      if (isOpen) {
        msg.style.display = "none";
        msg.removeAttribute("data-fm-help-open");
      } else {
        msg.style.display = "block";
        msg.style.left = "0";
        msg.style.top = "calc(100% + 4px)";
        msg.setAttribute("data-fm-help-open", "1");
      }
      e.stopPropagation();
    });
  },

  _dsaTooltipManager: function () {
    // Tooltip DSA posizionato in basso a destra - gestito solo tramite CSS :hover
    // Nessuna logica JavaScript necessaria per il posizionamento fisso
  },

  _caricaDivRiservati: function (callback) {
    const self = this;

    UIComp.preloadElementiRiservati(function (tempDivArg) {
      const cacheEl = asElement(tempDivArg);
      if (!cacheEl) {
        console.error("❌ Errore nel caricamento del file Elementi_Riservati.html");
        if (typeof callback === "function") callback(null);
        return;
      }

      const tempDiv = cacheEl.cloneNode(true); // Clone per evitare modifiche alla cache
      const editEser = tempDiv.querySelector(".fm-edit-eser");
      const checkIN = tempDiv.querySelector(".fm-check-in");
      const moveBtn = tempDiv.querySelector(".fm-move-btn");

      // 📦 Usa CheckmodManager per inserire .checkmod nei .fm-collapsible
      if (typeof CheckmodManager !== "undefined") {
        CheckmodManager.insertCheckmodInCollapsibles(null, editEser);
      } else {
        console.error("❌ CheckmodManager non disponibile!");
      }

      // Accedi a AppState dalla finestra parent (pagina principale)
      const parentAppState = window.parent.AppState;
      const moreArgValue = parentAppState ? parentAppState.moreArg : 0;

      // Carica normalmente i checkIN
      const collexItems = qsa(".fm-collection__item");
      collexItems.forEach((item) => {
        if (!item.querySelector(".fm-check-in") && checkIN) {
          item.insertBefore(checkIN.cloneNode(true), item.firstChild);
        }
      });

      // Popola subito le select .origin (memo-fetch dedup TTL).
      window.FM.memoFetchJson("/api/teacher/origins.json").then((origins) => {
        collexItems.forEach((item) => {
          const select = item.querySelector(".origin");
          if (select && select.querySelectorAll("option").length === 0) {
            const options = '<option value="origine">origine</option>' + origins.map((value) => `<option value="${value}">${value}</option>`).join("");
            select.insertAdjacentHTML("beforeend", options);

            let selectedClass = null;
            let found = false;
            (item.getAttribute("class") || "").split(" ").forEach(function (cls) {
              if (cls !== "origine" && origins.includes(cls)) {
                selectedClass = cls;
                found = true;
              }
            });

            select.value = found ? selectedClass : "origine";
          }
        });
      }).catch((err) => {
        console.warn("⚠️ Errore caricamento /api/teacher/origins.json in _caricaDivRiservati:", err);
      });

      // Phase 20 — appendi moveBtn SOLO ai .fm-groupcollex che non ne hanno già uno
      // (ContractRenderer emette `.moveBtn` dentro `.checkmod` server-side
      // su /studio/...). Senza guard, legacy pagine /eser/*.php ottenevano
      // un doppione quando _caricaDivRiservati girava post-render.
      qsa(".fm-groupcollex").forEach((p) => {
        if (!p.querySelector(".fm-move-btn") && moveBtn) {
          p.appendChild(moveBtn.cloneNode(true));
        }
      });

      if (typeof callback === "function") callback(tempDiv);
    });
  },
  _caricaCheckboxABin: function () {
    return new Promise((resolve) => {
      UIComp.preloadElementiRiservati(function (tempDivArg) {
        const cacheEl = asElement(tempDivArg);
        if (!cacheEl) {
          resolve();
          return;
        }
        const tempDiv = cacheEl.cloneNode(true);
        const checkboxABin = tempDiv.querySelector(".fm-a-bin");
        const infoVer = tempDiv.querySelector("#scrollbarInfo");
        const pt = tempDiv.querySelector(".fm-input-wrapper-pt");
        // (append checkboxABin/pt storicamente disattivati)
        // Evita duplicazioni di #scrollbarInfo / #infoVer tra attivazioni successive
        qsa("#scrollbarInfo").forEach((e) => e.remove());
        const headerPage = document.querySelector("#header_page");
        const typeVerAll = document.querySelector("#type_verAll");
        if (infoVer) {
          if (headerPage) {
            headerPage.after(infoVer);
          } else if (typeVerAll) {
            typeVerAll.insertBefore(infoVer, typeVerAll.firstChild);
          } else {
            document.body.insertBefore(infoVer, document.body.firstChild);
          }
        }

        // 👁️ Gestione visibilità span.AddTextDSA
        // Le span con checkbox individuale sono gestite dal loro checkbox
        // Helper function per verificare se il testo è tutto MAIUSCOLO
        function isUpperCaseText(text) {
          // Rimuovi asterischi, spazi, parentesi
          const cleanText = text.replace(/[\*\s\(\)]/g, "");
          // Estrai solo le lettere
          const letters = cleanText.replace(/[^a-zA-ZÀ-ÿ]/g, "");
          // Se non ci sono lettere, considera come maiuscolo (es: solo numeri/simboli)
          if (letters.length === 0) return true;
          // Controlla se tutte le lettere sono maiuscole
          return letters === letters.toUpperCase();
        }

        // Le span senza checkbox sono gestite dal checkbox #DSA globale
        qsa(".fm-add-text-dsa").forEach((span) => {
          const spanText = span.textContent.trim();

          // Gli span con classe .has-checkbox sono sempre visibili (hanno checkbox F/GF nel dsa-wrapper)
          if (span.classList.contains("has-checkbox")) {
            span.style.display = "";
            return;
          }

          const prev = span.previousElementSibling;
          const checkboxContainer = prev && prev.matches(".fm-dsa-checkbox-container") ? prev : null;
          if (checkboxContainer) {
            const checkbox = checkboxContainer.querySelector(".dsa-checkbox");
            // Se ha checkbox individuale, mostra/nascondi in base al suo stato
            span.style.display = checkbox && checkbox.checked ? "" : "none";
          } else {
            // Distingui tra testo MAIUSCOLO e minuscolo
            if (isUpperCaseText(spanText)) {
              // Testo MAIUSCOLO: nascosto di default, gestito da #DSA
              const isDSAChecked = !!document.querySelector("#DSA")?.checked;
              span.style.display = isDSAChecked ? "" : "none";
            } else {
              // Testo minuscolo: SEMPRE visibile
              span.style.display = "";
            }
          }
        });

        // Evita binding multipli ad ogni attivazione
        const dsaEl = document.querySelector("#DSA");
        if (dsaEl) {
          onNs(dsaEl, "change", "uiCompDsaGlobal", function () {
            const isDSAChecked = this.checked;

            // Gestisci solo gli span SENZA checkbox individuale E senza classe .has-checkbox
            qsa(".fm-add-text-dsa")
              .filter((span) => !span.matches(".has-checkbox"))
              .forEach((span) => {
                const spanText = span.textContent.trim();

                // Salta gli span che hanno un checkbox individuale (dsa-checkbox-container)
                const prev = span.previousElementSibling;
                if (prev && prev.matches(".fm-dsa-checkbox-container")) {
                  return; // Gestito dal checkbox individuale
                }

                // Distingui tra testo MAIUSCOLO e minuscolo
                if (isUpperCaseText(spanText)) {
                  // Testo MAIUSCOLO: gestito da #DSA
                  span.style.display = isDSAChecked ? "" : "none";
                } else {
                  // Testo minuscolo: SEMPRE visibile (ignora #DSA)
                  span.style.display = "";
                }
              });
          });
        }

        // VALORI DI DEFAULT - NON SOVRASCRIVERE se già popolati da PrintInfoManager
        const setDefault = (sel, val) => { const f = document.querySelector(sel); if (f && !f.value) f.value = val; };
        setDefault("#anno", "2025");
        setDefault("#verTime", "55 min");
        setDefault("#istituto", "di Esempio");
        setDefault("#verTitle", "");
        setDefault("#verTitlePrefix", "VERIFICA:");

        // NON impostare classe e addressSchool qui - gestiti da PrintInfoManager.loadPrintInfo()

        // G19.18 — Re-enable tooltip "Informazioni salvate" sul campo
        // #versione (l'utente lo trova utile per il version-progression
        // check legacy: se una verifica con stesso titolo esiste, suggerisce
        // la prossima versione disponibile).
        if (typeof EventHendler !== "undefined" && EventHendler.initializeVersioneTooltip) {
          setTimeout(function () {
            EventHendler.initializeVersioneTooltip();
          }, 100);
        }

        // Inizializza il toggle dello ScrollbarInfo DOPO che è stato caricato nel DOM
        setTimeout(function () {
          if (typeof UIComp !== "undefined" && UIComp.initScrollbarInfoToggle) {
            UIComp.initScrollbarInfoToggle();
          } else {
            console.error("❌ UIComp.initScrollbarInfoToggle non disponibile");
          }
        }, 150);
        resolve();
      });
    });
  },
  _applicaStiliColore: function (collexItem, colorName) {
    const textColor = colorName === "white" ? "black" : "white";
    const root = asElement(collexItem);
    if (!root) return;

    // Applica stili al selettore colore
    qsa(".fm-color-select", root).forEach((e) => setCss(e, { "background-color": colorName, color: textColor }));

    // Applica stili agli elementi di input (escluso .input-quesito che usa il colore schiarito)
    qsa(".fm-check-in, textarea:not(.input-quesito), .pt, .fm-labcheck-in, .origin", root).forEach((e) =>
      setCss(e, { "background-color": colorName, color: textColor }),
    );

    // Applica colore schiarito al titolo quesito e input-quesito
    const lightenedColor = utilities.lightenColor(colorName, 0.5);
    qsa(".fm-titolo-quesito, .input-quesito", root).forEach((e) => setCss(e, { "background-color": lightenedColor, color: "black" }));
  },
  verificaETitoliQuesito: function () {
    qsa(".fm-titolo-quesito").forEach((titolo) => {
      const backgroundColor = getComputedStyle(titolo).backgroundColor;

      // Controlla se il background è bianco o trasparente
      const isWhiteBackground = backgroundColor === "rgb(255, 255, 255)" || backgroundColor === "rgba(255, 255, 255, 1)" || backgroundColor === "white" || backgroundColor === "rgba(0, 0, 0, 0)" || backgroundColor === "transparent";

      // Imposta il colore del testo in base al background
      titolo.style.color = isWhiteBackground ? "black" : "white";
    });
  },
  _caricaColori: function (tempDiv) {
    const self = this;
    // Prima applica i colori letti dal DOM
    qsa(".fm-collection__item").forEach((collexItem) => {
      const checkIN = collexItem.querySelector(".fm-check-in");
      const titolo = collexItem.querySelector(".fm-titolo-quesito");
      const backgroundColor = titolo ? getComputedStyle(titolo).backgroundColor : "";
      const colorName = utilities.getColorName(backgroundColor);
      const colorSelect = checkIN ? checkIN.querySelector(".fm-color-select") : null;
      self._applicaStiliColore(collexItem, colorName);
      isColorSelectScriptChange = true;
      if (colorSelect) {
        colorSelect.value = colorName;
        trigger(colorSelect, "change");
      }
      isColorSelectScriptChange = false;
      if (checkIN) checkIN.style.backgroundColor = backgroundColor;
    });
    // Poi forza il ciclo colori topic in base ai cambi di testo .fm-titolo-quesito
    self._enforceTopicColorCycle();
  },

  /**
   * Assegna ID univoci ai .fm-collection__item che non ne hanno.
   * Viene chiamato dopo il caricamento degli esercizi (BtnInOut).
   * Se ci sono collex-item senza id, li genera e li salva sul server.
   * Formato ID: cx_<timestamp-base36>_<indice>
   *
   * @param {string} containerSelector - Selettore del container caricato (es. "#type_verAll_add0")
   *                                     Se null, controlla l'intero documento.
   */
  _ensureCollexItemIds: function (containerSelector) {
    const scope = containerSelector ? document.querySelector(containerSelector) : document;
    if (!scope) return;

    // Raggruppa per filePath per inviare una richiesta per file
    const fileGroups = {};

    qsa(".fm-collection__item", scope).forEach((item, index) => {
      if (!item.getAttribute("id")) {
        // Genera un ID univoco: cx_<timestamp-base36>_<indice-globale>
        const uniqueId = "cx_" + Date.now().toString(36) + "_" + Math.random().toString(36).substr(2, 4) + "_" + index;
        item.setAttribute("id", uniqueId);

        // Determina il filePath di questo item
        const filePath = PathManager.extractPath(item);
        // Calcola l'indice relativo al fm-draggable-container (come in change-color)
        const relativeIndex = PathManager.globalTOrelativeIndex(".fm-collection__item", item, "[class*=fm-draggable-container]");

        if (relativeIndex !== -1 && filePath) {
          if (!fileGroups[filePath]) {
            fileGroups[filePath] = [];
          }
          fileGroups[filePath].push({
            index: relativeIndex,
            id: uniqueId,
          });
        }

        console.log(`🆔 Assegnato ID "${uniqueId}" a collex-item (relIndex: ${relativeIndex}, file: ${filePath})`);
      }
    });

    // Invia le richieste di salvataggio per ogni file
    Object.keys(fileGroups).forEach(function (filePath) {
      const ids = fileGroups[filePath];
      console.log(`💾 Salvataggio ${ids.length} ID per file: ${filePath}`);

      _postJson(Endpoints.exercises.ensureCollexIds, {
        filePath: filePath,
        ids: JSON.stringify(ids),
      })
        .then(function (response) {
          if (response.success) {
            console.log(`✅ ID salvati per ${filePath}: ${response.assigned} assegnati su ${response.total} totali`);
          } else {
            console.error(`❌ Errore salvataggio ID per ${filePath}:`, response.error);
          }
        })
        .catch(function (jqXHR) {
          console.error(`❌ Errore AJAX salvataggio ID per ${filePath}:`, jqXHR?.code, jqXHR?.message);
        });
    });

    const totalMissing = Object.values(fileGroups).reduce((sum, arr) => sum + arr.length, 0);
    if (totalMissing === 0) {
    } else {
      console.log(`🆔 Totale ID assegnati e salvati: ${totalMissing}`);
    }
  },

  /**
   * Forza il ciclo colori topic: white → green → blue → red → purple → orange
   * Il ciclo si resetta per ogni .fm-groupcollex: ogni .fm-groupcollex parte da white.
   * All'interno di un .fm-groupcollex, ogni cambio di testo in .fm-titolo-quesito
   * avanza al colore successivo. Esercizi con lo stesso topic mantengono lo stesso colore.
   */
  _enforceTopicColorCycle: function () {
    const TOPIC_COLOR_CYCLE = ["white", "green", "blue", "red", "purple", "orange"];
    const self = this;

    qsa(".fm-groupcollex").forEach((groupEl) => {
      let currentCycleIndex = 0;
      let lastTopicText = null;

      qsa(".fm-collection__item", groupEl).forEach((item) => {
        const titolo = item.querySelector(".fm-titolo-quesito");
        if (!titolo) return;

        // Estrai il testo puro del topic (ignora input/textarea interni)
        let topicText = "";
        const textarea = titolo.querySelector("textarea.input-quesito");
        if (textarea) {
          topicText = textarea.value || "";
        } else {
          topicText = titolo.textContent || "";
        }
        topicText = topicText.trim();

        // Se il testo è vuoto, non cambiare colore — mantieni il corrente
        if (!topicText) return;

        // Se il testo topic è cambiato rispetto al precedente, avanza nel ciclo
        if (lastTopicText !== null && topicText !== lastTopicText) {
          currentCycleIndex++;
        }
        lastTopicText = topicText;

        const colorName = TOPIC_COLOR_CYCLE[currentCycleIndex % TOPIC_COLOR_CYCLE.length];

        // Applica il colore al titolo_quesito
        titolo.style.backgroundColor = colorName;

        // Aggiorna colorSelect se presente
        const checkIN = item.querySelector(".fm-check-in");
        const colorSelect = checkIN ? checkIN.querySelector(".fm-color-select") : null;
        if (colorSelect) {
          isColorSelectScriptChange = true;
          colorSelect.value = colorName;
          trigger(colorSelect, "change");
          isColorSelectScriptChange = false;
        }

        // Applica stili colore a tutto il collex-item
        self._applicaStiliColore(item, colorName);
      });
    });
  },

  _CaricaSel_EserOr: function (tempDiv) {
    const self = this;
    const cacheEl = asElement(tempDiv);
    let selectorEser = cacheEl ? cacheEl.querySelector(".fm-selector-eser") : null; // Clona solo la parte necessaria
    // G19.22+fix-flicker — idempotenza: chiamate parallele durante boot
    // (DOMContentLoaded + fm:navigated + 2-3 init secondari) creavano race
    // su ajax origins.json + remove/append → flicker visibile.
    // Lock previene 2nd entry mentre 1st ajax pending.
    if (UIComp.__caricaSelEserOrPending) return;
    UIComp.__caricaSelEserOrPending = true;
    // G19.22+fix-flicker FAST PATH — se `.fm-selector-eser` esiste già nel
    // topbar slot O #scrollbarInfo (post _caricaCheckboxABin), NON clonare/
    // rimuovere/relocare: popola solo il dropdown via ajax + bind handlers.
    // Evita REMOVE+ADD flicker dell'elemento.
    const existingInSlot = document.querySelector("#fm-topbar [data-fm-eser-slot] .fm-selector-eser, #scrollbarInfo .fm-selector-eser, #infoVer .fm-selector-eser");
    if (existingInSlot) {
        window.FM.memoFetchJson("/api/teacher/origins.json").then((origins) => {
            let html = "";
            (origins || []).forEach(function (value) {
                html += `<label><input type="checkbox" class="fm-option-checkbox"><a href="#" data-value="${value}">${value}<i class="fas fa-edit edit-btn"></i><i class="fas fa-times fm-remove-btn"></i></a></label>`;
            });
            const existingDropdown = nextMatch(existingInSlot.querySelector(".fm-dropdown-gen"), ".fm-dropdown-content-gen");
            if (existingDropdown) existingDropdown.innerHTML = html;
            if (typeof self._helpMessManager === "function") self._helpMessManager();
            if (typeof self._dsaTooltipManager === "function") self._dsaTooltipManager();
            UIComp.__caricaSelEserOrPending = false;
        }).catch(() => {
            UIComp.__caricaSelEserOrPending = false;
        });
        return;
    }
    // Phase 16 — origins per-teacher: deriva dalla sources.json personale.
    // Fallback al vecchio file statico se l'API non risponde (retro-compat).
    window.FM.memoFetchJson("/api/teacher/origins.json").then((origins) => {
      let html = "";
      let htmlFilter = "";
      origins.forEach(function (value) {
        html += `<label>
                    <input type="checkbox" class="fm-option-checkbox">
                        <a href="#" data-value="${value}">${value}<i class="fas fa-edit edit-btn"></i><i class="fas fa-times fm-remove-btn"></i></a>
                    </label>`;
        htmlFilter += `<a href="#" data-value="${value}">${value}</a>`;
      });

      // NON popolare qui, ma dopo l'inserimento nel DOM
      // selectorEser.find(".fm-dropdown-content-gen").html(html);
      // console.log('✅ dropdown-content_gen popolato in selectorEser');

      // Inserisci selector-eser dentro #infoVer invece che prima dei container
      // Rimuovi prima eventuali selector-eser esistenti in #infoVer
      qsa("#infoVer .fm-selector-eser").forEach((e) => e.remove());
      // G20.6 — RESCUE: topbar-modern.js relocateVerTitle puo' aver
      // spostato #verTitlePrefix + #verTitle (e G20.7: anche #versione)
      // dentro `.selector-eser` del topbar. Se la rimuoviamo a freddo
      // perdiamo gli input user-state. Li riportiamo prima del .remove():
      // - #verTitlePrefix + #verTitle -> #wrapInfoVer
      // - #versione -> #wrapInfoSchool (sua dimora originale)
      // Poi `setTimeout(activate)` sotto richiama relocateVerTitle che
      // li sposta nuovamente nel topbar.
      const wrapInfoVer = document.querySelector("#wrapInfoVer");
      qsa("#fm-topbar [data-fm-eser-slot] .fm-selector-eser #verTitlePrefix, " +
          "#fm-topbar [data-fm-eser-slot] .fm-selector-eser #verTitle")
        .forEach((e) => { if (wrapInfoVer) wrapInfoVer.appendChild(e); });
      const wrapInfoSchool = document.querySelector("#wrapInfoSchool");
      qsa("#fm-topbar [data-fm-eser-slot] .fm-selector-eser #versione")
        .forEach((e) => { if (wrapInfoSchool) wrapInfoSchool.appendChild(e); });
      // G19.22 — il topbar (`#fm-topbar [data-fm-eser-slot]`) puo' contenere
      // una `.selector-eser` rilocata da topbar-modern.js. Va rimossa anche
      // qui per evitare duplicate id (#fm-create-exercise-btn,
      // #savePrintInfoBtn, #loadPrintInfoBtn) → strict mode violations.
      qsa("#fm-topbar [data-fm-eser-slot] .fm-selector-eser").forEach((e) => e.remove());

      // Clona SENZA popolare il dropdown.
      // G19.6 — il deep clone include GIÀ `.scelte-verifica-wrapper` come
      // last-child del template; l'esplicito append separato creava un doppione.
      const cloneVer = selectorEser ? selectorEser.cloneNode(true) : null;

      // Inserisci dentro #infoVer
      const infoVerEl = document.querySelector("#infoVer");
      if (infoVerEl && cloneVer) infoVerEl.appendChild(cloneVer);

      // G19.22 — trigger relocate nel topbar slot dopo l'append.
      setTimeout(() => {
          try { window.FM?.TopbarModern?.activate?.(); } catch (_) {}
      }, 0);

      // POPOLA IL DROPDOWN DOPO l'inserimento nel DOM
      // dropdown-content_gen è ora SIBLING di .dropdown_gen, non child
      const targetDropdown = nextMatch(document.querySelector("#infoVer .fm-selector-eser .fm-dropdown-gen"), ".fm-dropdown-content-gen");

      if (targetDropdown) {
        targetDropdown.innerHTML = html;
      } else if (window.FM?.DEBUG_DROPDOWN) {
        // G22.S15 — silenziato: pagine senza .selector-eser (es. editor) non
        // hanno il target dropdown e non e' un errore. Per debug attivare
        // window.FM.DEBUG_DROPDOWN = true in console.
        console.debug("[ui-comp] target dropdown non trovato (no .selector-eser sulla pagina)");
      }

      // Mostra il filtro origini
      const selOrigin = document.querySelector("#sel-origin");
      if (selOrigin) {
        selOrigin.style.display = "";
        const dc = selOrigin.querySelector(".fm-dropdown-content");
        if (dc) dc.insertAdjacentHTML("beforeend", htmlFilter);
      }
      if (cacheEl) cacheEl.remove();
      self._checkOriginCheckboxes();

      qsa(".fm-collection__item").forEach((item) => {
        const options = `<option value="origine">origine</option>` + origins.map((value) => `<option value="${value}">${value}</option>`).join("");
        const select = item.querySelector(".origin");
        if (!select) return;
        select.insertAdjacentHTML("beforeend", options);

        // Seleziona il valore corretto: cerca una classe che corrisponde a un value (escluso 'origine')
        let selectedClass = null;
        let found = false;
        (item.getAttribute("class") || "").split(" ").forEach(function (cls) {
          if (cls !== "origine" && origins.includes(cls)) {
            selectedClass = cls;
            found = true;
          }
        });

        select.value = found ? selectedClass : "origine";
      });
      self._helpMessManager();
      self._dsaTooltipManager();
      // G19.22+fix-flicker — release lock dopo settle ajax + DOM ops.
      UIComp.__caricaSelEserOrPending = false;
    }).catch(function () {
      // Release anche su fail per non bloccare future chiamate.
      UIComp.__caricaSelEserOrPending = false;
    });
  },
  _checkOriginCheckboxes: function () {
    // Recupera i valori correnti selezionati
    const values = DataManager.currentPageValues || [];
    // Per ogni checkbox nella dropdown, controlla se il valore è presente e spunta
    qsa(".fm-dropdown-content-gen .fm-option-checkbox").forEach((cb) => {
      const a = nextMatch(cb, "a");
      const value = a ? a.getAttribute("data-value") : undefined;
      cb.checked = values.includes(String(value));
    });
  },
  _caricaElemRiservati: async function (path, dynamicId, index, lastIndex) {
    // console.log('dinamicId in caricaElementi: ', dynamicId);
    // console.log('path in caricaElementi: ', path);
    const self = this;

    // Sostituisce jQuery `.load(path, cb)`: GET → innerHTML → callback.
    const container = document.querySelector(dynamicId);
    if (!container) return;
    let _loadStatus = "success";
    let _loadXhr = { status: 0, statusText: "" };
    try {
      const responseText = await getText(path);
      container.innerHTML = responseText;
    } catch (err) {
      _loadStatus = "error";
      _loadXhr = { status: 0, statusText: String((err && err.message) || err) };
    }
    await (async function () {
      if (_loadStatus === "error") {
        console.error(`❌ Errore caricamento ${path}:`, _loadXhr.status, _loadXhr.statusText);
        return;
      }

      // G22.S15.bis — TikZJax deprecato; conversione a `text/tikz-cached`
      // mantenuta per compatibilità con eventuali consumer legacy che
      // distinguevano tikz "live" vs "cached", ma non strettamente necessaria
      // (tikz-render-client cerca solo `text/tikz`).
      qsa('script[type="text/tikz"][id^="tikz_"]', container).forEach((s) => {
        s.removeAttribute("data-show-console");
        s.setAttribute("type", "text/tikz-cached");
      });

      // Aspetta che il DOM sia completamente caricato
      await new Promise((resolve) => setTimeout(resolve, 100));

      const problemsInContainer = qsa(".fm-groupcollex", container);

      // Nascondi i contenitori padre fino a setup completo degli elementi riservati
      qsa(".DraggableContainer_ver, .fm-draggable-container", container).forEach((e) => setCss(e, { display: "none", opacity: 0 }));

      problemsInContainer.forEach((problem) => {
        problem.style.display = "block";
        ContentProcessor.processmathjaxElements(problem, 0);
      });

      // ⚡ Non bloccare il caricamento degli elementi riservati con task pesanti
      // MathJax e TikZ verranno avviati in background dopo l'iniezione UI

      EventHendler.UploadDynamicId(dynamicId, index);
      // console.log('index: ', index,'\n lastIndex: ', lastIndex);

      // Carica i div riservati per ogni problema caricato

      // Carica i checkbox DSA per i li-inline in tutti i casi
      await new Promise((resolve) => {
        UIComp.preloadElementiRiservati(function (tempDivArg) {
          const cacheEl = asElement(tempDivArg);
          if (!cacheEl) {
            resolve();
            return;
          }

          const dsaWrapperTemplate = cacheEl.querySelector(".dsa-wrapper-container");

          // Logica condivisa F/GF: ricalcola marker host, sposta i text-node,
          // pulisce span esistenti e (se checked) inserisce il marker.
          const applyDsaMarker = (checkbox, dsaWrapper, uniqueId, marker, otherId) => {
            const isChecked = checkbox.checked;
            const currentLi = checkbox.closest(".fm-li-inline");
            const currentCollex = currentLi ? currentLi.querySelector(".fm-collection") : null;
            if (!currentCollex) return;

            let markerHost = firstNonEmptyDivChild(currentCollex);
            if (!markerHost) {
              markerHost = document.createElement("div");
              currentCollex.insertBefore(markerHost, currentCollex.firstChild);
            }

            // Sposta il testo figlio diretto di .fm-collection nel marker host
            moveTextNodesInto(currentCollex, markerHost);

            // Rimuovi TUTTI gli span AddTextDSA come figli diretti
            Array.from(markerHost.children)
              .filter((c) => c.matches("span.fm-add-text-dsa"))
              .forEach((c) => c.remove());

            if (isChecked) {
              markerHost.insertAdjacentHTML("afterbegin", `<span class="fm-add-text-dsa has-checkbox" style="display: inline;">(*${marker}*) </span>`);
              const other = dsaWrapper.querySelector("#" + otherId);
              if (other) other.checked = false;
            }

            UIComp._saveDSAChanges(currentLi, isChecked ? marker : "");
          };

          if (dsaWrapperTemplate) {
            const collexItems = qsa(".fm-collection__item", container);

            collexItems.forEach((collexItem, itemIndex) => {
              // .fm-li-inline può essere sia <li> che <div>, è fratello di collex-item
              const liInlineElements = qsa(".fm-li-inline", collexItem);

              liInlineElements.forEach((li, liIndex) => {
                // Il .fm-collection sta DENTRO li.fm-li-inline, non fuori!
                const firstCollex = li.querySelector(".fm-collection");
                if (!firstCollex) {
                  console.warn(`⚠️ Nessun .fm-collection trovato dentro li-inline ${liIndex}`);
                  return;
                }

                const uniqueId = `${dynamicId.replace("#", "")}-item${itemIndex}-li${liIndex}`;
                const idF = `dsa-checkboxF-${uniqueId}`;
                const idGF = `dsa-checkboxGF-${uniqueId}`;

                // Clona il template e aggiorna gli ID
                const dsaWrapper = dsaWrapperTemplate.cloneNode(true);
                const checks = dsaWrapper.querySelectorAll(".dsa-checkbox");
                const labels = dsaWrapper.querySelectorAll("label");
                if (checks[0]) checks[0].setAttribute("id", idF);
                if (labels[0]) labels[0].setAttribute("for", idF);
                if (checks[1]) checks[1].setAttribute("id", idGF);
                if (labels[1]) labels[1].setAttribute("for", idGF);

                // Trova un host valido per il marker DSA dentro .fm-collection
                const markerHost = firstNonEmptyDivChild(firstCollex);

                // Se il testo è figlio diretto di .fm-collection, spostalo nel marker host
                if (markerHost) {
                  moveTextNodesInto(firstCollex, markerHost);

                  // Verifica se esiste già uno span AddTextDSA nell'host del marker
                  const existingSpan = Array.from(markerHost.children).find((c) => c.matches("span.fm-add-text-dsa"));
                  if (existingSpan) {
                    const markerText = existingSpan.textContent.trim();
                    if (markerText === "(*F*)" || markerText.includes("*F*")) {
                      const cb = dsaWrapper.querySelector("#" + idF);
                      if (cb) cb.checked = true;
                      existingSpan.style.display = "";
                    } else if (markerText === "(*GF*)" || markerText.includes("*GF*")) {
                      const cb = dsaWrapper.querySelector("#" + idGF);
                      if (cb) cb.checked = true;
                      existingSpan.style.display = "";
                    }
                  }
                }

                // Inserisci come primo figlio del li-inline
                li.insertBefore(dsaWrapper, li.firstChild);

                // Event handler per checkbox F / GF
                const cbF = dsaWrapper.querySelector("#" + idF);
                if (cbF) cbF.addEventListener("change", function () { applyDsaMarker(this, dsaWrapper, uniqueId, "F", idGF); });
                const cbGF = dsaWrapper.querySelector("#" + idGF);
                if (cbGF) cbGF.addEventListener("change", function () { applyDsaMarker(this, dsaWrapper, uniqueId, "GF", idF); });
              });
            });
          } else {
            console.warn(`⚠️ Template DSA non trovato in Elementi_Riservati.html`);
          }

          resolve();
        });
      });

      // Per il primo problema, carica tutto normalmente
      if (index === 0) {
        self._caricaDivRiservati(function (tempDiv) {
          // NON chiamare più _CaricaSel_EserOr qui - verrà chiamato DOPO _caricaCheckboxABin
          // self._CaricaSel_EserOr(tempDiv); // ❌ RIMOSSO - chiamato dopo
          self._caricaColori();
          // self._helpMessManager();
        });
      } else {
        // Per gli argomenti successivi, carica solo i checkIN
        await new Promise((resolve) => {
          UIComp.preloadElementiRiservati(function (tempDivArg) {
            const cacheEl2 = asElement(tempDivArg);
            if (!cacheEl2) {
              resolve();
              return;
            }
            const tempDiv = cacheEl2.cloneNode(true);
            const editEser = tempDiv.querySelector(".fm-edit-eser"); // 🔧 Prendi solo il primo editEser
            const checkIN = tempDiv.querySelector(".fm-check-in");
            const moveBtn = tempDiv.querySelector(".fm-move-btn");

            // 🔧 USA CheckmodManager invece di wrappare manualmente
            if (typeof CheckmodManager !== "undefined" && typeof CheckmodManager.insertCheckmodInCollapsibles === "function") {
              if (editEser) {
                CheckmodManager.insertCheckmodInCollapsibles(container, editEser);
              } else {
                console.warn(`  ⚠️ editEser non trovato, uso CheckmodManager senza editEser`);
                CheckmodManager.insertCheckmodInCollapsibles(container);
              }
            } else {
              console.error(`  ❌ CheckmodManager non disponibile!`);
              // Fallback al metodo manuale
              qsa(".fm-collapsible", container).forEach((collapsible) => {
                const wrapcheckgiust = collapsible.querySelector(".fm-wrapcheckgiust");
                const wrapchecksol = collapsible.querySelector(".fm-wrapchecksol");

                if (wrapcheckgiust || wrapchecksol) {
                  const checkmod = document.createElement("div");
                  checkmod.className = "fm-checkmod";
                  if (wrapcheckgiust) checkmod.appendChild(wrapcheckgiust);
                  if (wrapchecksol) checkmod.appendChild(wrapchecksol);
                  if (editEser) checkmod.appendChild(editEser.cloneNode(true));
                  collapsible.appendChild(checkmod);
                }
              });
            }

            qsa(".fm-collection__item", container).forEach((item) => {
              if (!item.querySelector(".fm-check-in") && checkIN) {
                item.insertBefore(checkIN.cloneNode(true), item.firstChild);
              }
            });
            // Phase 20 — guard: no doppione con server-rendered .moveBtn.
            qsa(".fm-groupcollex", container).forEach((p) => {
              if (!p.querySelector(".fm-move-btn") && moveBtn) {
                p.appendChild(moveBtn.cloneNode(true));
              }
            });

            resolve();
          });
        });
      }

      if (index == lastIndex) {
        // Aspetta un momento per assicurarsi che tutti i checkIN siano stati caricati
        await new Promise((resolve) => setTimeout(resolve, 200));

        EventHendler.initializeDraggableSortable();

        self.caricaModHeaderBtn();
        self.CheckSolSel();
        self.InsertCheckPos();
        // self.ColorDotDifF();
        self.caricaSol_VF();
        self.caricaGiust();

        // Inizializzazione VF/punti gestita centralmente da StateManager.loadCompleteState()

        // 🆔 Assegna ID univoci ai collex-item che non ne hanno
        self._ensureCollexItemIds(null);
      }

      // Avvia MathJax in background DOPO aver inserito gli elementi riservati,
      // poi idrata TikZ solo dopo il completamento MathJax per evitare contention.
      setTimeout(() => {
        const updatedProblems = qsa(".fm-groupcollex", container);

        // Limita i target ai blocchi testuali matematici (evita scanning pesante dell'intero problem)
        const mathTargets = [];
        updatedProblems.forEach((problemEl) => {
          const scopedTargets = problemEl.querySelectorAll(".fm-collection, .collexTab, .fm-sol, .fm-giustsol, .fm-testo, .fm-titolo-quesito, .fm-latex-viewer, .latex");
          if (scopedTargets.length > 0) {
            scopedTargets.forEach((el) => mathTargets.push(el));
          } else {
            mathTargets.push(problemEl);
          }
        });

        UIComp.safeTypesetBatch(mathTargets, 14).then(() => {
          if (index === lastIndex) {
            const runTikzHydration = () => {
              LatexRender.manipulateTikzScript().catch((err) => console.warn("⚠️ Errore idratazione TikZ in background:", err));
            };

            if (typeof window.requestIdleCallback === "function") {
              window.requestIdleCallback(() => runTikzHydration(), { timeout: 1200 });
            } else {
              setTimeout(runTikzHydration, 180);
            }
          }
        });
      }, 0);
    })();
  },
  /**
   * Salva le modifiche DSA (span AddTextDSA) sul server
   * @param {Element} liArg - L'elemento .fm-li-inline modificato
   * @param {string} marker - 'F', 'GF', o '' (rimuovi)
   */
  _saveDSAChanges: function (liArg, marker) {
    const li = asElement(liArg);
    if (!li) {
      console.error("❌ _saveDSAChanges: li-inline non trovato");
      return;
    }

    const problem = li.closest(".fm-groupcollex");
    const collexItem = li.closest(".fm-collection__item");

    if (!problem || !collexItem) {
      console.error("❌ _saveDSAChanges: problema o collex-item non trovato");
      return;
    }

    const problemId = problem.getAttribute("id");
    const path = PathManager.extractPath(problem);

    // Trova l'indice del collex-item DENTRO il problema (non globale!)
    const collexItemIndex = qsa(".fm-collection__item", problem).indexOf(collexItem);

    console.log(`💾 Salvataggio DSA: problema=${problemId}, marker=${marker}, collexItem=${collexItemIndex}`);

    if (!path || !problemId) {
      console.error("❌ Path o problemID mancante");
      return;
    }

    _postJson(Endpoints.update.dsa, {
      type: "fm-li-inline",
      filePath: path,
      problemID: problemId,
      marker: marker,
      collexItemIndex: collexItemIndex,
    })
      .then(function (response) {
        if (response.success) {
          console.log(`✅ DSA salvato: ${marker ? "(*" + marker + "*)" : "rimosso"}`);
        } else {
          console.error(`❌ Errore server:`, response.error);
        }
      })
      .catch(function (xhr) {
        console.error(`❌ Errore salvataggio DSA:`, xhr?.message);
        console.error(`   Response:`, xhr?.responseText);
      });
  },

  _rimuoviElementiRiservati: async function () {
    const reservedElements = qsa(".selection, .fm-edit-eser, .fm-edit-quesito, .fm-move-btn, .fm-check-in, .fm-a-bin, .fm-input-wrapper-pt, #modHeaderBtn, .fm-checkmod");

    if (reservedElements.length > 0) {
      // Fade-out (sostituisce jQuery .animate({opacity:0},1000) + .promise("fx")).
      await fadeTo(reservedElements, 0, 1000);
    }

    qsa(".selection").forEach((e) => e.remove());
    qsa(".fm-collapsible").forEach((e) => { e.style.width = "100%"; });
    qsa(".fm-edit-eser").forEach((e) => e.remove());
    qsa(".fm-edit-quesito").forEach((e) => e.remove());
    qsa(".fm-move-btn").forEach((e) => e.remove());
    qsa(".fm-selector-eser").forEach((e) => e.replaceChildren());
    qsa("#modHeaderBtn").forEach((e) => e.remove());
    // .fm-check-in: rimossi (non solo nascosti)
    qsa(".fm-check-in").forEach((e) => e.remove());
    qsa(".fm-a-bin").forEach((e) => e.remove());
    qsa(".fm-input-wrapper-pt").forEach((e) => e.remove());
  },
  /** Phase 20 — toggle visibilità `.giustifica` nel .fm-testo del .fm-groupcollex
   *  via checkbox .checkgiust. Usa attributo [hidden] + regola CSS
   *  `.giustifica[hidden] { display: none }`. Default .checkgiust
   *  checked → hidden=false → visible. */
  caricaGiust: function () {
    delegate("change", "fmGiust", ".checkgiust", function () {
      const show = this.checked;
      const problem = this.closest(".fm-groupcollex");
      if (problem) qsa(".fm-testo .fm-giustifica", problem).forEach((g) => { g.hidden = !show; });
    });
    qsa(".checkgiust").forEach((c) => trigger(c, "change"));
  },
  caricaSol_VF: function () {
    const self = this;
    delegate("change", "checksol", ".checksol", function () {
      const problemEl = this.closest(".fm-groupcollex");
      const sol = problemEl ? qsa(".fm-sol", problemEl) : [];
      const solchecked = problemEl ? qsa(".solchecked", problemEl) : [];
      const giustsol = problemEl ? qsa(".fm-giustsol", problemEl) : [];
      const Sol_V = problemEl ? qsa(".V", problemEl) : [];
      const Sol_F = problemEl ? qsa(".F", problemEl) : [];
      if (this.checked) {
        solchecked.forEach((c) => { c.checked = true; });

        // Mostra prima gli elementi, altrimenti outerHeight() può risultare 0
        giustsol.forEach((e) => { e.style.display = ""; });
        sol.forEach((e) => { e.style.display = ""; });

        if (Sol_F.length || Sol_V.length) {
          giustsol.forEach((g) => {
            const elementVF = g.previousElementSibling;
            if (elementVF && elementVF.classList.contains("F")) {
              elementVF.textContent = "FALSO";
            } else if (elementVF && elementVF.classList.contains("V")) {
              elementVF.textContent = "VERO";
            }

            const vfHeight = outerHeight(g) || g.scrollHeight || "auto";
            if (elementVF) {
              setCss(elementVF, {
                width: "70px",
                height: typeof vfHeight === "number" ? vfHeight + "px" : vfHeight,
                "align-content": "center",
                "text-align": "center",
                "margin-right": "10px",
              });
            }

            setCss(g, { "border-left": "1px solid", "padding-left": "10px" });
          });
        }
      } else {
        solchecked.forEach((c) => { c.checked = false; });
        giustsol.forEach((e) => { e.style.display = "none"; });
        sol.forEach((e) => { e.style.display = "none"; });
      }
      const collapsible = this.closest(".fm-collapsible");
      if (collapsible && collapsible.classList.contains("active")) {
        self.SetHeightProblem(this);
      }
    });
    qsa(".fm-wrapsol-vf").forEach((e) => { e.style.display = "flex"; });
    qsa(".checksol").forEach((c) => trigger(c, "change"));
  },
  caricaModHeaderBtn: function () {
    UIComp.preloadElementiRiservati(function (tempDivArg) {
      const cacheEl = asElement(tempDivArg);
      if (!cacheEl) return;
      const tempDiv = cacheEl.cloneNode(true);
      const editheader = tempDiv.querySelector("#modHeaderBtn");
      const headerPage = document.querySelector("#header_page");
      if (headerPage && editheader) headerPage.insertBefore(editheader, headerPage.firstChild);
    });
  },
  InsertCheckPos: function () {
    qsa(".fm-check-in").forEach((e) => { e.style.display = "inline-flex"; });
    const total = qsa(".fm-pos-check-es").length; // Numero totale di placeholder
    let count = 0; // Contatore per i placeholder popolati

    const finalizeReservedSetup = function () {
      // Phase 15 — WidthCheck era global legacy; resolve sicuro via window
      const _WidthCheck = (typeof window !== "undefined" && window.WidthCheck)
        || parseInt(getComputedStyle(document.documentElement).getPropertyValue("--widthSelection"))
        || 60;
      const targetWidthCheck = Number(_WidthCheck) || 60;
      const collapsibles = qsa(".fm-collapsible");

      qsa(".fm-groupcollex").forEach((e) => setCss(e, { width: "calc(100% - 5px)" }));
      collapsibles.forEach((e) => setCss(e, { width: `calc(100% - ${targetWidthCheck + 28}px)` }));
      qsa(".selection").forEach((e) => setCss(e, { visibility: "visible", width: targetWidthCheck + "px", opacity: 1 }));
      collapsibles.forEach((e) => { e.style.opacity = "1"; });
      qsa(".fm-check-in, .fm-move-btn, .fm-a-bin, .fm-input-wrapper-pt, .fm-checkmod").forEach((e) => { e.style.opacity = "1"; });

      // Mostra i contenitori padre solo quando tutto è pronto (fade-in)
      const containers = qsa(".DraggableContainer_ver, .fm-draggable-container");
      containers.forEach((c) => { c.style.display = "block"; c.style.opacity = "0"; });
      fadeTo(containers, 1, 2000);

      // Popola i campi move-position con i numeri corretti
      if (typeof EventHendler !== "undefined" && typeof EventHendler.updateMovePositions === "function") {
        qsa(".fm-groupcollex").forEach((p) => {
          EventHendler.updateMovePositions(p);
        });
      }

      // Inizializza i gestori per defPositionImp
      if (typeof EventHendler !== "undefined" && typeof EventHendler.initializeDefPositionImpHandlers === "function") {
        EventHendler.initializeDefPositionImpHandlers();
      }

      // Inizializza i controlli punti VF quando .selection è sicuramente presente
      if (typeof utilities !== "undefined" && typeof utilities.initializeVFProblems === "function") {
        utilities.initializeVFProblems();
      }

      // Inizializza/riaggancia i checkbox DSA nelle liste già presenti (anche fuori edit mode)
      if (typeof ListManager !== "undefined" && typeof ListManager.addDSACheckboxesToExistingLists === "function") {
        ListManager.addDSACheckboxesToExistingLists();
      }
    };

    UIComp.preloadElementiRiservati(function (tempDivArg) {
      const cacheEl = asElement(tempDivArg);
      if (!cacheEl) return;
      const tempDiv = cacheEl.cloneNode(true);
      const posCheckContent = tempDiv.querySelector(".selection"); // Trova il contenuto di #PosCheck

      if (posCheckContent && total > 0) {
        qsa(".fm-pos-check-es").forEach((slot) => {
          qsa(".selection", slot).forEach((e) => e.remove());
          slot.appendChild(posCheckContent.cloneNode(true));
          count++; // Incrementa il contatore ogni volta che un placeholder viene popolato
          if (count === total) {
            finalizeReservedSetup();
          }
        });
      } else if (posCheckContent) {
        // Fallback: ricrea i placeholder mancanti e inserisci .selection
        qsa(".fm-groupcollex").forEach((problem) => {
          let slot = problem.querySelector(".fm-pos-check-es");
          const collapsible = problem.querySelector(".fm-collapsible");

          if (!slot) {
            slot = document.createElement("div");
            slot.className = "fm-pos-check-es";
            if (collapsible) {
              collapsible.before(slot);
            } else {
              problem.insertBefore(slot, problem.firstChild);
            }
          }

          qsa(".selection", slot).forEach((e) => e.remove());
          slot.appendChild(posCheckContent.cloneNode(true));
        });

        finalizeReservedSetup();
      } else {
        finalizeReservedSetup();
      }
    });
  },
  CheckSolSel: function () {
    let uniqueIdCounter = 1;
    qsa(".checksol").forEach((c) => { c.checked = true; });
    qsa(".checkbox").forEach((checkbox) => {
      const uniqueId = "uniqueId" + uniqueIdCounter;
      const label = nextMatch(checkbox, "label");
      if (label) {
        label.setAttribute("for", uniqueId);
        label.setAttribute("onclick", "event.stopPropagation();");
      }
      checkbox.setAttribute("id", uniqueId);
      checkbox.setAttribute("onclick", "event.stopPropagation();");
      uniqueIdCounter++;
    });
  },
  // ColorDotDifF: function () {
  //     $('.fm-collapsible span').remove();
  //     $('.fm-groupcollex').add($('.fm-groupcollex').find('*')).each(function () {
  //         let classes = $(this).attr('class');
  //         if (classes) {
  //             let match = classes.match(/\bdiff(\d+)\b/);
  //             if (match) {
  //                 let num = match[1];
  //                 let specialChars = Array(parseInt(num) + 1).join('●');
  //                 let color1 = colorsProblem[num - 1];
  //                 let color2 = colorsExercise[num - 1]; // Sostituisci con il colore desiderato
  //                 if ($(this).is('.fm-groupcollex')) {
  //                     $(this).find('.fm-collapsible').prepend('<span style="color:' + color1 + ';font-size: 25px;position: relative; top:-1px;">' + specialChars + " </span>");
  //                 } else {
  //                     $(this).append('<span style="color:' + color2 + ';font-size: 25px;position: relative; top:-1px; margin-left:8px;"> ' + specialChars + " </span>");
  //                 }
  //             }
  //         }
  //     });
  // },
  // ShowHideSol: function (elements, action, text, replacement, specialAction, isSolVF) {
  //     let result = null;
  //     elements.each(function () {
  //         if (action === 'show') {
  //             $(this).show();
  //             if (text) $(this).prepend(text);
  //         } else if (action === 'hide') {
  //             if (isSolVF) {
  //                 if (text && replacement !== undefined) {
  //                     $(this).text(function (_, txt) {
  //                         return txt.replace(text, replacement);
  //                     });
  //                 }
  //             }
  //             else {
  //                 $(this).hide();
  //             }
  //         }
  //         if (specialAction && typeof specialAction === "function") {
  //             result = specialAction($(this));
  //         }
  //     });
  //     return result;
  // },
  /**
   * Configura tutti gli elementi riservati per un problema
   * Replica la logica di BtnInOut/_caricaElemRiservati e del nuovo esercizio
   * @param {jQuery} $problem - L'elemento .fm-groupcollex da configurare
   */
  setupProblemElements: function ($problem) {
    const problem = asElement($problem);
    if (!problem) return;
    console.log("🎯 setupProblemElements chiamato per problema:", problem.getAttribute("id"));
    console.log("📊 Stato PRIMA:", {
      checkmod: qsa(".fm-checkmod", problem).length,
      editEser: qsa(".fm-edit-eser", problem).length,
      selection: qsa(".selection", problem).length,
      checkIN: qsa(".fm-check-in", problem).length,
      moveBtn: qsa(".fm-move-btn", problem).length,
    });

    UIComp.preloadElementiRiservati(function (tempDivArg) {
      const cacheEl = asElement(tempDivArg);
      if (!cacheEl) {
        console.error("❌ Errore caricamento /Elementi_Riservati.html");
        return;
      }

      try {
        const tempDiv = cacheEl.cloneNode(true);
        const editEser = tempDiv.querySelector(".fm-edit-eser"); // 🔧 Prendi il primo editEser
        const checkIN = tempDiv.querySelector(".fm-check-in");
        const moveBtn = tempDiv.querySelector(".fm-move-btn");
        const selection = tempDiv.querySelector(".selection");

        // 1. Aggiungi .selection a .PosCheckEs (come in nuovo esercizio e BtnInOut)
        const posCheckEs = qsa(".fm-pos-check-es", problem);
        if (posCheckEs.length && selection) {
          posCheckEs.forEach((slot) => {
            qsa(".selection", slot).forEach((e) => e.remove());
            slot.appendChild(selection.cloneNode(true));
          });

          // Mostra .selection e ridimensiona collapsible (come in InsertCheckPos)
          const WidthCheck = typeof window.WidthCheck !== "undefined" ? window.WidthCheck : parseInt(getComputedStyle(document.documentElement).getPropertyValue("--widthSelection")) || 60;

          qsa(".selection", problem).forEach((e) => setCss(e, { width: WidthCheck + "px", visibility: "visible", opacity: 1 }));
          setCss(problem, { width: "calc(100% - 5px)" });
          qsa(".fm-collapsible", problem).forEach((e) => setCss(e, { width: `calc(100% - ${Number(WidthCheck) + 28}px)` }));

          console.log("  ✅ .selection aggiunto e dimensionato (width:", WidthCheck + "px)");
        }

        // 2. Gestisci .checkmod nel .fm-collapsible usando CheckmodManager
        if (typeof CheckmodManager !== "undefined" && typeof CheckmodManager.insertCheckmodInCollapsibles === "function") {
          if (editEser) {
            CheckmodManager.insertCheckmodInCollapsibles(problem, editEser);
            console.log("  ✅ CheckmodManager chiamato con editEser");
          } else {
            console.warn("  ⚠️ editEser non trovato in Elementi_Riservati.html");
            CheckmodManager.insertCheckmodInCollapsibles(problem);
          }
        } else {
          console.error("  ❌ CheckmodManager non disponibile!");
        }

        // 3. Aggiungi checkIN ai collex-item (come in tutti i casi)
        qsa(".fm-collection__item", problem).forEach((item) => {
          if (!item.querySelector(".fm-check-in") && checkIN) {
            item.insertBefore(checkIN.cloneNode(true), item.firstChild);
          }
        });
        console.log("  ✅ .checkIN aggiunti ai collex-item");

        // 3b. Aggiorna i numeri di posizione nei move-position
        if (typeof EventHendler !== "undefined" && typeof EventHendler.updateMovePositions === "function") {
          EventHendler.updateMovePositions(problem);
          console.log("  ✅ move-position aggiornati");
        }

        // 4. Aggiungi moveBtn al problema (come in nuovo esercizio)
        if (!problem.querySelector(".fm-move-btn") && moveBtn) {
          problem.appendChild(moveBtn.cloneNode(true));
          console.log("  ✅ .moveBtn aggiunto al problema");
        }

        // 5. Chiama CheckSolSel per i checkbox (come in nuovo esercizio)
        if (typeof UIComp !== "undefined" && typeof UIComp.CheckSolSel === "function") {
          UIComp.CheckSolSel();
        }

        // 6. Popola le origin per i collex-item (come in nuovo esercizio)
        window.FM.memoFetchJson("/api/teacher/origins.json").then((origins) => {
          qsa(".fm-collection__item", problem).forEach((item) => {
            const select = item.querySelector(".origin");

            if (select && select.querySelectorAll("option").length === 0) {
              const options = '<option value="origine">origine</option>' + origins.map((value) => `<option value="${value}">${value}</option>`).join("");
              select.insertAdjacentHTML("beforeend", options);

              // Seleziona il valore corretto basandosi sulle classi
              let selectedClass = null;
              let found = false;

              (item.getAttribute("class") || "").split(" ").forEach(function (cls) {
                if (cls !== "origine" && origins.includes(cls)) {
                  selectedClass = cls;
                  found = true;
                }
              });

              select.value = found ? selectedClass : "origine";
            }
          });
          console.log("  ✅ origins popolate");

          // Aggiorna l'altezza del problema DOPO aver aggiunto tutti gli elementi
          if (typeof DataManager !== "undefined" && typeof DataManager.SetHeightProblem === "function") {
            DataManager.SetHeightProblem(problem);
            console.log("  ✅ altezza problema aggiornata");
          }
        }).catch((err) => {
          console.warn("⚠️ Errore caricamento /api/teacher/origins.json:", err);
        });

        console.log("✅ setupProblemElements completato per:", problem.getAttribute("id"));

        // Aggiorna altezza dopo che tutto è stato caricato
        if (typeof UIComp !== "undefined" && typeof UIComp.SetHeightProblem === "function") {
          UIComp.SetHeightProblem(problem);
        }
      } catch (error) {
        console.error("❌ Errore in setupProblemElements:", error);
        console.error("Stack:", error.stack);
      }
    });
  },

  SetHeightProblem: function (element) {
    //setTimewout per dare il tempo di caricare il contenuto
    setTimeout(() => {
      const problem = asElement(element)?.closest(".fm-groupcollex");
      const content = problem ? problem.querySelector(".content") : null;
      const scrollhide = problem ? problem.querySelector(".fm-scrollbarhide") : null;
      const collapsible = problem ? problem.querySelector(".fm-collapsible") : null;

      // Verifica che l'altezza sia definita
      if (content && scrollhide) {
        // Assicurati che il collapsible sia aperto
        const isOpen = collapsible && collapsible.classList.contains("active");

        if (!isOpen) {
          // Se chiuso, aprilo temporaneamente per calcolare l'altezza
          if (collapsible) collapsible.classList.add("active");
          content.style.maxHeight = "none"; // Rimuovi temporaneamente il limite
        }

        // Forza il browser a ricalcolare il layout
        void scrollhide.offsetHeight;

        // Calcola l'altezza reale di scrollbarhide (il contenuto interno)
        const scrollhideHeight = scrollhide.scrollHeight;
        const contentScrollHeight = content.scrollHeight;

        // Usa il valore massimo tra scrollhide e content per garantire che tutto sia visibile
        const newHeight = Math.max(scrollhideHeight, contentScrollHeight);

        // Imposta maxHeight di content
        content.style.maxHeight = newHeight + "px";
        scrollhide.style.overflowX = "hidden";
      } else {
        console.error("Elementi content o scrollbarhide non trovati.");
      }
    }, 300);
    // const content = $(element).closest('.fm-groupcollex').find('.content');
    // const scrollhide = $(element).closest('.fm-groupcollex').find('.fm-scrollbarhide');
    // content.css('maxHeight', content.prop('scrollHeight') + "px");
    // scrollhide.css('overflow-x', 'hidden');
  },
  printVisitedLinks: function () {
    // console.log("Visited Links in sel-copy:");
    visitedLinks.forEach(function (link, index) {
      // console.log("link aggiunto numero " + index + ": " + link);
    });
  },
  BtnInOut: async function (btnid, indirizzo) {
    const btnEl = asElement(btnid);
    const initialBorderStyle = btnEl ? getComputedStyle(btnEl).borderStyle : "";
    const actionMode = initialBorderStyle === "inset" ? "deactivate" : "activate";

    if (actionMode === "activate") {
      qsa(".DraggableContainer_ver, .fm-draggable-container").forEach((e) => setCss(e, { display: "none", opacity: 0 }));
    }

    // Precarica il JSON TikZ all'attivazione di verifica-mode (Phase 21: auto-on su admin-access)
    UIComp.preloadTikzJSON(Endpoints.templates.modelliTikz);

    // Se visitedLinks è vuoto, inserisci il link della pagina corrente in visitedLinksVER
    if (visitedLinks.length === 0) {
      visitedLinks.push(window.location.href);
      console.log("✅ Aggiunto link corrente:", window.location.href);
    }

    visitedLinks.forEach(function (el) {
      if (typeof el === "string" && el.includes("risdoc")) {
        outOfVer = 1;
      }
      let extractor = new PathFileVerExtractor(el);
      let verifiedPath = extractor.verPath();
      visitedLinksVER.push(verifiedPath);
    });

    // Carica subito infoVer PRIMA dei problemi, poi applica i valori da JSON/sessione
    await this._caricaCheckboxABin();
    await new Promise((resolve) => {
      UIComp.preloadElementiRiservati((tempDiv) => {
        if (tempDiv) {
          this._CaricaSel_EserOr(tempDiv);
        }
        resolve();
      });
    });

    if (typeof StateManager !== "undefined" && typeof StateManager.loadInfoInputsOnly === "function") {
      StateManager.loadInfoInputsOnly();
    }

    if (typeof PrintInfoManager !== "undefined" && typeof PrintInfoManager.loadPrintInfo === "function") {
      PrintInfoManager.loadPrintInfo();
    }

    for (let index = 0; index < visitedLinksVER.length; index++) {
      await this._processLink(btnid, index, actionMode);
    }

    console.log("✅ BtnInOut completato!");
    visitedLinksVER = [];
  },
  _processLink: async function (btnid, index, actionMode = null) {
    let lastIndex = visitedLinksVER.length - 1;
    let path = visitedLinksVER[index];
    let dynamicId = "#type_verAll_add" + index;
    const btnEl = asElement(btnid);
    let bordType = btnEl ? getComputedStyle(btnEl).borderStyle : "";
    let outOfVer = 0;
    const effectiveMode = actionMode || (bordType === "inset" ? "deactivate" : "activate");

    if (effectiveMode === "activate") {
      if (PlusArgisChecked == 1) {
        qsa(".fm-pagestyle").forEach((e) => e.remove());
      }

      document.querySelector("#btnP")?.click();

      if (!isPageLoaded) {
        let pageExists = await PathManager.checkIfPageExists(path);

        let extractor = new PathFileVerExtractor(path);
        let argomento = extractor.word;

        if (pageExists === 0 && outOfVer == 0) {
          console.log("❌ Pagina verifiche non trovata");
          throw new Error("pagina verifiche non trovata");
        }
        if (pageExists === 1 || outOfVer == 1) {
          await this._caricaElemRiservati(path, dynamicId, index, lastIndex);
          isPageLoaded = true;

          DataManager.removedElements.forEach(function (element) {
            // G26.phase8 — DataManager.removedElements contiene vanilla DOM Nodes.
            console.log("Elemento rimosso:", element);
            console.log("id dinamico:", dynamicId);
            const dyn = document.querySelector(dynamicId);
            let previousElement = null;
            for (const sib of prevAll(dyn)) {
              const f = sib.querySelector(".fm-draggable-container");
              if (f) { previousElement = f; break; }
            }
            console.log("Elemento precedente:", previousElement);
            if (element && previousElement) {
              previousElement.appendChild(element);
              element.style.display = "";
            }
          });
          DataManager.clearRemovedElements();

          // Ripristina anche i collex-item rimossi
          DataManager.restoreRemovedCollexItems();
        }
      } else {
        await this._caricaElemRiservati(path, dynamicId, index, lastIndex);
        qsa(".checksol").forEach((c) => trigger(c, "change"));
      }
      if (index === lastIndex) {
        setCss(btnEl, {
          background: "linear-gradient(135deg, #ff0000, #cc0000)",
          color: "white",
          "border-style": "inset",
          "border-color": "#990000",
        });
      }
      if (btnEl) btnEl.innerHTML = '<strong style="letter-spacing: 2px;">ATTIVATA</strong>';

      // MathJax già renderizzato per-container in _caricaElemRiservati

      // Carica lo stato completo dopo che tutti i problemi sono stati caricati
      if (index === lastIndex && typeof StateManager !== "undefined") {
        setTimeout(() => {
          // Verifica che ci siano effettivamente problemi caricati
          const problemCount = qsa(".fm-groupcollex").length;
          const checkboxCount = qsa(".checkboxA, .checkboxB, .fm-checkbox-ain, .fm-checkbox-bin").length;
          const schoolElements = qsa("#wrapInfoSchool input").length;
          const verElements = qsa('#wrapInfoVer input, #wrapInfoVer input[type="checkbox"]').length;

          if (problemCount > 0 || schoolElements > 0 || verElements > 0) {
            StateManager.loadCompleteState();

            // ⚠️ IMPORTANTE: Carica PrintInfo DOPO StateManager per sovrascrivere i valori salvati
            setTimeout(() => {
              if (typeof PrintInfoManager !== "undefined") {
                PrintInfoManager.loadPrintInfo();
              }
            }, 100);

            // Inizializza i controlli di movimento dopo il caricamento dello stato
            if (typeof EventHendler !== "undefined" && EventHendler.initializeMoveControls) {
              EventHendler.initializeMoveControls();
            }

            // Inizializza l'autocomplete per verTitle
            if (typeof EventHendler !== "undefined" && EventHendler.initializeVerTitleAutocomplete) {
              EventHendler.initializeVerTitleAutocomplete();
            }

            // Inizializza il tooltip per versione
            if (typeof EventHendler !== "undefined" && EventHendler.initializeVersioneTooltip) {
              EventHendler.initializeVersioneTooltip();
            }

            // Riordina i problemi in base ai valori defPositionImp caricati dallo stato
            setTimeout(() => {
              if (typeof EventHendler !== "undefined" && EventHendler.reorderAllContainers) {
                EventHendler.reorderAllContainers();
              }
            }, 200);
          } else {
            console.log("❌ Nessun elemento trovato, skip caricamento stato");
            // 🔧 DEBUG: Verifica cosa c'è effettivamente nel DOM
            console.log("🔍 Contenuto di #type_verAll:", document.querySelector("#type_verAll")?.children.length, "elementi");
            console.log(
              "🔍 Contenuto completo body:",
              Array.from(document.body.children).map((el) => el.getAttribute("id") || el.getAttribute("class") || el.tagName),
            );
          }
        }, 160);
      }

      if (!UIComp._beforeUnloadHandlerAttached) {
        window.addEventListener("beforeunload", function (event) {
          // Mostra un messaggio di conferma
          event.preventDefault(); // Necessario per alcuni browser
          event.returnValue = ""; // Richiesto per mostrare il prompt di conferma
        });
        UIComp._beforeUnloadHandlerAttached = true;
      }
    } else if (effectiveMode === "deactivate") {
      // Salva lo stato prima di rimuovere gli elementi
      if (typeof StateManager !== "undefined") {
        StateManager.saveCompleteState();
        console.log("Stato completo salvato prima della rimozione elementi");
      }

      setCss(btnEl, {
        background: "linear-gradient(135deg, #ffff33, #f5f500)",
        color: "black",
        "border-style": "outset",
        "border-color": "#c4c400",
      });
      if (btnEl) btnEl.innerHTML = "<strong>A T T I V A</strong>";

      // Ripristina i collex-item rimossi prima di rimuovere gli elementi riservati
      DataManager.restoreRemovedCollexItems();

      await this._rimuoviElementiRiservati();
    }
  },

  /**
   * Toggle visibilità UpBar
   * @param {boolean} animate - Se usare animazione
   */
  toggleUpbar: function (animate = true) {
    const upbarToggle = document.querySelector("#upbar-toggle");
    const upbarSlider = qsa(".fm-upbar-slider");
    const upbar = qsa(".fm-upbar");

    const isChecked = !!(upbarToggle && upbarToggle.checked);

    if (isChecked) {
      // Mostra upbar
      sessionStorage.setItem("upbarToggleState", "visible");
      upbar.forEach((e) => e.classList.remove("upbar-hidden"));

      upbarSlider.forEach((e) => { e.textContent = "▼"; e.style.backgroundColor = "#7877db"; });
    } else {
      // Nascondi upbar - usa verde più scuro
      sessionStorage.setItem("upbarToggleState", "hidden");
      upbar.forEach((e) => e.classList.add("upbar-hidden"));

      upbarSlider.forEach((e) => { e.textContent = "▲"; e.style.backgroundColor = "#525199"; });

      console.log("[UIComp] UpBar nascosta");
    }
  },

  /**
   * Inizializza il toggle dell'UpBar
   * Ripristina lo stato salvato in sessionStorage
   */
  initUpbarToggle: function () {
    const savedState = sessionStorage.getItem("upbarToggleState");
    const upbarToggle = document.querySelector("#upbar-toggle");

    if (upbarToggle) upbarToggle.checked = savedState !== "hidden";
    this.toggleUpbar(false);

    // Event listener per il toggle
    if (upbarToggle) {
      onNs(upbarToggle, "change", "upbarToggle", () => {
        this.toggleUpbar(true);
      });
    }
  },

  /**
   * Toggle visibilità ScrollbarInfo
   * @param {boolean} animate - Se usare animazione
   */
  toggleScrollbarInfo: function (animate = true) {
    const scrollbarInfoToggle = document.querySelector("#scrollbarInfo-toggle");
    const scrollbarInfoSlider = qsa(".fm-scrollbar-info-slider");
    const scrollbarInfo = document.querySelector("#scrollbarInfo");
    const infoVer = document.querySelector("#infoVer");
    const toggleContainer = document.querySelector("#scrollbarInfo-toggle-container");
    const setOpacity = (el, v) => { if (el) el.style.opacity = String(v); };

    const isChecked = !!(scrollbarInfoToggle && scrollbarInfoToggle.checked);

    if (isChecked) {
      // Mostra scrollbarInfo con animazione fade
      sessionStorage.setItem("scrollbarInfoToggleState", "visible");

      if (animate) {
        // Fade out del toggle nella posizione corrente (alto)
        setOpacity(toggleContainer, 0);

        setTimeout(() => {
          // Cambia stato (rimuove classe hidden)
          if (scrollbarInfo) scrollbarInfo.classList.remove("scrollbarInfo-hidden");

          // Fade in di infoVer e toggle nella nuova posizione
          setOpacity(infoVer, 0);
          setTimeout(() => {
            if (infoVer) infoVer.style.transition = "opacity 0.3s ease";
            setOpacity(infoVer, 1);
            setOpacity(toggleContainer, 1);
          }, 50);
        }, 300);
      } else {
        if (scrollbarInfo) scrollbarInfo.classList.remove("scrollbarInfo-hidden");
        setOpacity(infoVer, 1);
      }

      scrollbarInfoSlider.forEach((e) => { e.textContent = "▼"; e.style.backgroundColor = "#096663"; });

      console.log("[UIComp] ScrollbarInfo mostrato");
    } else {
      // Nascondi scrollbarInfo con animazione fade
      sessionStorage.setItem("scrollbarInfoToggleState", "hidden");

      if (animate) {
        // Fade out di infoVer e toggle nella posizione corrente
        if (infoVer) infoVer.style.transition = "opacity 0.3s ease";
        setOpacity(infoVer, 0);
        setOpacity(toggleContainer, 0);

        setTimeout(() => {
          // Cambia stato e posizione
          if (scrollbarInfo) scrollbarInfo.classList.add("scrollbarInfo-hidden");

          // Fade in del toggle nella nuova posizione (alto)
          setTimeout(() => {
            setOpacity(toggleContainer, 1);
          }, 50);
        }, 300);
      } else {
        if (scrollbarInfo) scrollbarInfo.classList.add("scrollbarInfo-hidden");
      }

      scrollbarInfoSlider.forEach((e) => { e.textContent = "▲"; e.style.backgroundColor = "#044441"; });

      console.log("[UIComp] ScrollbarInfo nascosto");
    }
  },

  /**
   * Inizializza il toggle dello ScrollbarInfo
   * Ripristina lo stato salvato in sessionStorage
   */
  initScrollbarInfoToggle: function () {
    const savedState = sessionStorage.getItem("scrollbarInfoToggleState");
    const scrollbarInfoToggle = document.querySelector("#scrollbarInfo-toggle");
    const scrollbarInfo = document.querySelector("#scrollbarInfo");

    if (savedState === "hidden") {
      // Se era nascosto, nascondi
      if (scrollbarInfoToggle) scrollbarInfoToggle.checked = false;
      this.toggleScrollbarInfo(false);
    } else {
      // Se era visibile o è il primo caricamento, assicurati che sia visibile
      if (scrollbarInfoToggle) scrollbarInfoToggle.checked = true;
      if (scrollbarInfo) scrollbarInfo.classList.remove("scrollbarInfo-hidden");
      qsa(".fm-scrollbar-info-slider").forEach((e) => { e.textContent = "▼"; e.style.backgroundColor = "#096663"; });
    }

    // Controlla lo stato della sidebar dal parent per posizionare il toggle sotto upbar-toggle
    try {
      const parentIOBarState = window.parent !== window ? window.parent.sessionStorage.getItem("ioBarState") : null;
      if (parentIOBarState === "closed") {
        const container = document.querySelector("#scrollbarInfo-toggle-container");
        if (container) setCss(container, { top: "73px", left: "0px" });
      }
    } catch (e) {
      console.warn("⚠️ Impossibile leggere stato sidebar dal parent:", e.message);
    }

    // Event listener per il toggle
    if (scrollbarInfoToggle) {
      onNs(scrollbarInfoToggle, "change", "scrollbarInfoToggle", () => {
        this.toggleScrollbarInfo(true);
      });
    }
  },
};

window.FM = window.FM || {};
window.FM.UIComp = UIComp;
window.UIComp    = UIComp;
