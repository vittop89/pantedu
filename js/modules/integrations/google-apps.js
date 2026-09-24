/**
 * App — estratto da script.js (Phase 9j). Google Apps Script integration.
 * G26.phase6.5 — migrato a vanilla JS (no jQuery).
 */
import { Endpoints } from "../core/endpoints.js";
import { asElement, isVisible } from "../core/dom-utils.js";

/** Fade-out + remove (replica jQuery .fadeOut(500, cb)). */
function fadeOutAndRemove(el, duration = 500) {
    if (!el) return;
    el.style.transition = `opacity ${duration}ms`;
    el.style.opacity = "0";
    setTimeout(() => el.remove(), duration);
}

/** Fade-in + delay + fade-out animation chain (replica jQuery sequence). */
function flashMessageAfter(targetEl, message, className = "presenceMessage") {
    if (!targetEl) return;
    const span = document.createElement("span");
    span.className = className;
    span.textContent = message;
    span.style.opacity = "0";
    span.style.transition = "opacity 400ms";
    targetEl.after(span);
    // Force reflow then fade in
    void span.offsetHeight;
    span.style.opacity = "1";
    setTimeout(() => {
        span.style.opacity = "0";
        setTimeout(() => span.remove(), 400);
    }, 1000 + 400);
}

export const App = {
  init () {
    AppState.init();
    DOMManager.init();
    this.bindWindowEvents();
    DOMManager.updateSelectsFromState();
    this.setupSidebarButtons();

    // Phase 15 helper inline: confronta linkref con location corrente
    // considerando il redirect /eser/{ind}/eser_{ind}{cls}/{SUBJ}/{num}_.php
    // → /studio/esercizio/{ind}/{cls}/{subj}/{num}
    function isSameOrModernCurrent(linkref) {
      try {
        const u = new URL(linkref, location.href);
        if (u.pathname === location.pathname && u.search === location.search) return true;
        const m = u.pathname.match(/^\/eser\/([a-z]+)\/eser_([a-z]+)(\d+[a-z]?)\/([A-Z]+)\/([\d.]+)_\4-.+?-\2\3\.php$/i);
        if (m) {
          const expected = `/studio/esercizio/${m[1]}/${m[3]}/${m[4]}/${m[5]}`;
          if (location.pathname === expected) return true;
        }
        return false;
      } catch (_) { return false; }
    }

    if (AppState.linkref) {
      // Phase 15 — evita re-navigate loop: se la pagina corrente è già
      // linkref (o una sua versione modern post-redirect), skip.
      //
      // G27.bugfix — restore di linkref deve avvenire SOLO se l'utente sta
      // arrivando dalla root "/" (caso bookmark/login redirect alla home).
      // Per qualsiasi altra URL esplicita (es. /area-docente/fonti, /admin/*,
      // /teacher/dashboard) la URL del browser è la fonte di verità: l'utente
      // ha cliccato un link / fatto F5 / aperto un bookmark e vuole RESTARE
      // su quella pagina. Senza questa guard, F5 su una pagina non-exercise
      // mentre linkref punta all'ultimo esercizio rimanda l'utente lì
      // (DOMManager.loadUrlInFrame → fmRouter.navigate → swap content +
      // pushState verso linkref → URL bar mostra /studio/esercizio/...).
      const isRootEntry = location.pathname === "/" || location.pathname === "/index.php";
      if (isRootEntry && !isSameOrModernCurrent(AppState.linkref)) {
        DOMManager.loadUrlInFrame(AppState.linkref);
      }
    }
  },

  bindWindowEvents () {
    window.addEventListener(
      "message",
      (e) => {
        if (e.origin !== window.location.origin) return;

        // 2026-09-05 — i messaggi auth_success/auth_required arrivavano
        // dall'iframe di /log/auth/AuthCode.php (gate legacy delle sezioni
        // con PasswordSidepage, spento ovunque dalla Phase 25.B1): via con
        // l'entry point. L'accesso alle sezioni lo decide il server.
        // Gestione messaggi numerici esistenti
        if (typeof e.data === "number") {
          AppState.moreArg = e.data;
        }
        // Gestione stringhe esistenti
        else if (typeof e.data === "string") {
          AppState.addVisitedLink(e.data);
        }
      },
      false,
    );
  },
  handleSelectChange () {
    // Debounce per evitare chiamate multiple
    clearTimeout(this._selectChangeTimeout);
    this._selectChangeTimeout = setTimeout(() => {
      this._doHandleSelectChange();
    }, 50);
  },

  _doHandleSelectChange () {
    // CORREZIONE: Aggiorna AppState prima di configurare i pulsanti
    AppState.updateFromSelects();

    // Richiama setupSidebarButtons per riconfigurare dopo il cambio select
    this.setupSidebarButtons();

    // Se la sidebar è chiusa ma ha contenuto, aggiorna anche quello nascosto
    const savedIOBarState = sessionStorage.getItem("ioBarState");
    if (savedIOBarState === "closed" && DOMManager.detachedChildren) {
      // I pulsanti sono già stati riconfigurati sopra
    }

    // 🔔 Notifica l'iframe del cambio selettori per aggiornare PrintInfo
    const selectedIIS = document.querySelector("select#sel-iis")?.value;
    const selectedCLS = document.querySelector("select#sel-cls")?.value;
    const selectedMATER = document.querySelector("select#sel-mater")?.value;

    // Non inviare messaggi se i valori non sono ancora definiti
    if (!selectedIIS || !selectedCLS) {
      console.log("ℹ️ Select non ancora inizializzati (skip postMessage)");
      return;
    }

    const message = {
      type: "sidebar-selector-changed",
      selectedIIS,
      selectedCLS,
      selectedMATER: selectedMATER || null,
    };

    let iframeSent = false;

    // 1. Prova con #myframe (pagine esercizi)
    let iframe = document.getElementById("myframe");
    if (iframe && iframe.contentWindow) {
      iframe.contentWindow.postMessage(message, window.location.origin);
      console.debug("[google-apps] postMessage iframe #myframe:", message);
      iframeSent = true;
    }

    // 2. Prova con #type_verAll (se esiste)
    if (!iframeSent) {
      iframe = document.getElementById("type_verAll");
      if (iframe && iframe.contentWindow) {
        iframe.contentWindow.postMessage(message, window.location.origin);
        console.debug("[google-apps] postMessage iframe #type_verAll:", message);
        iframeSent = true;
      }
    }

    // 3. Fallback: cerca iframe dinamici (type_verAll_add0, type_verAll_add1, etc.)
    if (!iframeSent) {
      const dynamicIframes = document.querySelectorAll('iframe[id^="type_verAll"]');
      if (dynamicIframes.length > 0) {
        dynamicIframes.forEach((dynIframe) => {
          if (dynIframe.contentWindow) {
            dynIframe.contentWindow.postMessage(message, window.location.origin);
            iframeSent = true;
          }
        });
      }
    }

    if (!iframeSent) {
      console.log("ℹ️ Nessun iframe caricato al momento (skip postMessage)");
    }
  },
  setupSidebarButtons () {
    document.querySelectorAll(".fm-sb-sec").forEach((btn) => {
      const sidepageKey = btn.getAttribute("data-sidepage");
      if (!sidepageKey) return;

      const sidebarId = Object.keys(Config.SIDEBAR_CONFIG).find(
        (key) => Config.SIDEBAR_CONFIG[key].sidepage === sidepageKey,
      );
      const dirName = Config.SIDEBAR_CONFIG[sidebarId]?.dirName;

      if (!sidebarId || !dirName) return;

      const bordStore = `bordSt_${sidepageKey}`;
      const page = `/${dirName}/${AppState.folder}/${dirName}_${AppState.optsel}.html`;

      // Sostituisce listener via cloneNode (replica .off("click.sidebarBtn"))
      const newBtn = btn.cloneNode(true);
      btn.replaceWith(newBtn);
      newBtn.addEventListener("click", () => {
        DOMManager.toggleSidebarSection(newBtn, sidebarId, bordStore, page);
      });

      const savedIOBarState = sessionStorage.getItem("ioBarState");
      const sessionBord = sessionStorage.getItem(bordStore);

      if (savedIOBarState !== "closed") {
        if (sessionBord === "inset") {
          newBtn.style.borderStyle = "inset";
          this.loadSidebarContent(sidebarId, page);
        } else if (sessionBord === "outset") {
          newBtn.style.borderStyle = "outset";
          const sidebarEl = document.querySelector(sidebarId);
          if (sidebarEl) sidebarEl.style.display = "none";
        }
      } else if (sessionBord === "inset") {
        newBtn.style.borderStyle = "inset";
      } else if (sessionBord === "outset") {
        newBtn.style.borderStyle = "outset";
      }
    });
  },

  /**
   * Phase 18 — caricamento sidebar full-DB.
   * NON carica più il template legacy `/modello_pag_listSidebar.php`
   * (conteneva `.materia` hardcoded per MAT/GEO/FIS — ostacolo per
   * materie nuove). Svuota il container + emette l'evento
   * fm:sidebar-template-ready; db-sidepage.js crea container materia
   * e header dinamicamente dal DB.
   */
  async loadSidebarContent (id) {
    const config = Config.SIDEBAR_CONFIG[id];
    if (!config) return;
    const el = document.querySelector(id);
    if (!el) return;
    // Preserva .js-edit-section + .fm-edit-toolbar; rimuovi altri figli
    Array.from(el.children).forEach((child) => {
      if (!child.matches(".js-edit-section, .fm-edit-toolbar")) child.remove();
    });
    // .fm-sb-panel ha display:none di default in CSS → forza block (replica .show())
    el.style.display = "block";
    document.dispatchEvent(new CustomEvent("fm:sidebar-template-ready", { detail: { id } }));
  },

  _applyMateriaFilter () {
    const show = (sel) => document.querySelectorAll(sel).forEach((e) => { e.style.display = ""; });
    const hide = (sel) => document.querySelectorAll(sel).forEach((e) => { e.style.display = "none"; });
    if (AppState.mater === "M") {
      hide("#FIS, #GEO");
      show("#MAT");
    } else if (AppState.mater === "G") {
      hide("#MAT, #FIS");
      show("#GEO");
    } else if (AppState.mater === "F") {
      show("#FIS");
      hide("#MAT, #GEO");
    } else {
      show("#MAT, #GEO, #FIS");
    }
  },
  handleLinkrefClick (linkElement) {
    const linkEl = asElement(linkElement);
    if (!linkEl) return;
    const url = linkEl.getAttribute("href");
    if (AppState.moreArg === 1) {
      // dom-manager.elements.iframe ora è Element (non jq wrapper)
      const iframeDocument = DOMManager.elements.iframe?.contentDocument;
      const mainRoot = document.getElementById("fm-content");
      const hasContainer = !!(iframeDocument?.querySelector(".fm-draggable-container")
                           || mainRoot?.querySelector(".fm-draggable-container, .fm-contract-wrap"));
      if (!hasContainer) AppState.visitedLinks = [];

      if (DOMManager.isUrlAlreadyInFrame(url) || DOMManager.isUrlPending(url)) {
        flashMessageAfter(linkEl, "già presente");
        return;
      }
      AppState.addVisitedLink(url);
      DOMManager.appendContentToFrame(url);
    } else {
      sessionStorage.setItem("linkref", url);
      AppState.resetVisitedLinks(url);
      Utils.sendLoginRedirectPath(url);
      DOMManager.loadUrlInFrame(url);
    }
  },

};

window.FM = window.FM || {};
window.FM.App = App;
window.App    = App;
