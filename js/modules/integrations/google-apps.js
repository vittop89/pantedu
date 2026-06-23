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
  pendingAuthData: null,
  isEditMode: false,

  init: function () {
    AppState.init();
    DOMManager.init();
    CookieConsentManager.init();
    GoogleAppsScript.init();
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

  bindWindowEvents: function () {
    window.addEventListener(
      "message",
      (e) => {
        if (e.origin !== window.location.origin) return;

        // Gestione messaggi di autenticazione
        if ((typeof e.data === "object" && e.data.type === "auth_success") || (typeof e.data === "string" && e.data === "auth_success")) {
          // IMPOSTA IL FLAG DI AUTENTICAZIONE
          sessionStorage.setItem("sidebar_authenticated", "true");

          // Recupera i dati salvati per il caricamento della sidebar
          const authData = App.pendingAuthData;
          if (authData) {
            // FORZA l'apertura della sezione (ignora lo stato attuale)
            const btnEl = asElement(authData.btn || authData.$btn);
            if (btnEl) btnEl.style.borderStyle = "inset";
            sessionStorage.setItem(authData.bordStore, "inset");

            // Nascondi altre sezioni che potrebbero essere aperte
            Object.keys(Config.SIDEBAR_CONFIG).forEach((id) => {
              if (id !== authData.sidebarId) {
                const el = document.querySelector(id);
                if (el) el.style.display = "none";
              }
            });

            // Carica il contenuto della sezione
            App.loadSidebarContent(authData.sidebarId, authData.page);

            // Pulisci i dati pending
            App.pendingAuthData = null;
          } else {
            console.warn("⚠️ Nessun dato pendingAuthData trovato dopo autenticazione");
          }
        }
        // GESTIONE PRIVILEGI INSUFFICIENTI
        else if (typeof e.data === "object" && e.data.type === "auth_required") {
          // NON impostare il flag di autenticazione per la sezione
          // La sezione rimane chiusa e mostra il messaggio nell'iframe

          // Pulisci i dati pending dato che non possiamo aprire la sezione
          App.pendingAuthData = null;
        }
        // Gestione messaggi numerici esistenti
        else if (typeof e.data === "number") {
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
  handleSelectChange: function () {
    // Debounce per evitare chiamate multiple
    clearTimeout(this._selectChangeTimeout);
    this._selectChangeTimeout = setTimeout(() => {
      this._doHandleSelectChange();
    }, 50);
  },

  _doHandleSelectChange: function () {
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
      selectedIIS: selectedIIS,
      selectedCLS: selectedCLS,
      selectedMATER: selectedMATER || null,
    };

    let iframeSent = false;

    // 1. Prova con #myframe (pagine esercizi)
    let iframe = document.getElementById("myframe");
    if (iframe && iframe.contentWindow) {
      iframe.contentWindow.postMessage(message, "*");
      console.debug("[google-apps] postMessage iframe #myframe:", message);
      iframeSent = true;
    }

    // 2. Prova con #type_verAll (se esiste)
    if (!iframeSent) {
      iframe = document.getElementById("type_verAll");
      if (iframe && iframe.contentWindow) {
        iframe.contentWindow.postMessage(message, "*");
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
            dynIframe.contentWindow.postMessage(message, "*");
            iframeSent = true;
          }
        });
      }
    }

    if (!iframeSent) {
      console.log("ℹ️ Nessun iframe caricato al momento (skip postMessage)");
    }
  },
  setupSidebarButtons: function () {
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
        const config = Config.SIDEBAR_CONFIG[sidebarId];
        if (config && config.PasswordSidepage === true) {
          this.loadAuthenticationFirst(sidebarId, bordStore, page, newBtn);
        } else {
          DOMManager.toggleSidebarSection(newBtn, sidebarId, bordStore, page);
        }
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

  loadAuthenticationFirst: function (sidebarId, bordStore, page, btn) {
    const btnEl = asElement(btn);
    if (!btnEl) return;
    const currentBorderStyle = getComputedStyle(btnEl).borderStyle;
    const storedBorderStyle = sessionStorage.getItem(bordStore);

    if (currentBorderStyle === "inset" || storedBorderStyle === "inset") {
      btnEl.style.borderStyle = "outset";
      sessionStorage.setItem(bordStore, "outset");
      const sidebarEl = document.querySelector(sidebarId);
      if (sidebarEl) sidebarEl.style.display = "none";

      sessionStorage.removeItem("sidebar_authenticated");
      App.pendingAuthData = null;
      return;
    }

    if (sessionStorage.getItem("sidebar_authenticated") === "true") {
      btnEl.style.borderStyle = "inset";
      sessionStorage.setItem(bordStore, "inset");
      this.loadSidebarContent(sidebarId, page);
    } else {
      const authUrl = `/log/auth/AuthCode.php?sidebar_auth=1&section=${encodeURIComponent(sidebarId)}`;
      DOMManager.loadUrlInFrame(authUrl);

      App.pendingAuthData = {
        sidebarId,
        bordStore,
        page,
        btn: btnEl,
      };
    }
  },

  /**
   * Phase 18 — caricamento sidebar full-DB.
   * NON carica più il template legacy `/modello_pag_listSidebar.php`
   * (conteneva `.materia` hardcoded per MAT/GEO/FIS — ostacolo per
   * materie nuove). Svuota il container + emette l'evento
   * fm:sidebar-template-ready; db-sidepage.js crea container materia
   * e header dinamicamente dal DB.
   */
  loadSidebarContent: async function (id) {
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
    if (id === "#fm-sp-mappe" && App.isEditMode) {
      await this._addControlPanelClass();
    }
    document.dispatchEvent(new CustomEvent("fm:sidebar-template-ready", { detail: { id } }));
  },

  _applyMateriaFilter: function () {
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
  handleLinkrefClick: function (linkElement) {
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

  toggleEditMode: async function () {
    console.group(`[App] toggleEditMode - Stato attuale: ${this.isEditMode ? "Modifica Attiva" : "Modifica Disattiva"}`);

    // CORREZIONE: Non cambiare lo stato fino a quando non siamo sicuri che l'operazione sia riuscita
    const wasInEditMode = this.isEditMode;

    try {
      if (!this.isEditMode) {
        // Attivazione modalità editing
        this.isEditMode = true;

        await this._activateEditMode();

        console.log("✅ Modalità editing attivata (sidebar già ricaricata internamente)");
      } else {
        // Tentativo di salvataggio e disattivazione
        const saveSuccessful = await this._saveChangesAndDeactivate();

        // CORREZIONE: Solo se il salvataggio è riuscito, disattiva la modalità editing
        if (saveSuccessful !== false) {
          this.isEditMode = false;

          // Ricarica la sidebar per nascondere i link con display: "hide"
          await this._reloadCurrentSidebar();
        }
        // Se saveSuccessful è false, rimaniamo in modalità editing
      }
    } catch (error) {
      console.error("❌ ERRORE CRITICO in toggleEditMode:", error);
      // CORREZIONE: In caso di errore, ripristina lo stato precedente
      this.isEditMode = wasInEditMode;
      DOMManager.updateButtonState(this.isEditMode);
    }
    console.groupEnd();
  },

  _reloadCurrentSidebar: async function () {
    const sidebarIds = Object.keys(Config.SIDEBAR_CONFIG);
    let activeSidebarId = null;

    for (const sidebarId of sidebarIds) {
      const el = document.querySelector(sidebarId);
      if (el && isVisible(el) && getComputedStyle(el).display !== "none") {
        activeSidebarId = sidebarId;
        break;
      }
    }

    if (activeSidebarId) {
      console.log(`🔄Ricaricamento sidebar: ${activeSidebarId}`);
      await this.loadSidebarContent(activeSidebarId);
      console.log(`✅ Ricaricamento completato per: ${activeSidebarId}`);
    }
  },

  _activateEditMode: async function () {
    console.group("🚀 [App] _activateEditMode (v7.0 - Ordine corretto)");
    try {
      DOMManager.updateButtonState(true);

      // STEP 1: Verifica permessi e carica template
      // Path assoluti (Phase 13): URL relativo "./" rompe per /studio/...
      // e /eser/.../X.php SPA-wrapped. /Elementi_Riservati.html è
      // routato via LegacyController in admin section.
      // filesInVerifiche/getAllLinksFileStatus tollerano 400 (sezione
      // non valida o no DB scope) → fallback a array vuoti.
      const [hasPermission, inputTemplateHtml, fileStatusMap, verfilenames] = await Promise.all([
          Api.checkIfPageExists("/verifiche/security_page.html"),
          Api.fetchHtmlTemplate("/Elementi_Riservati.html"),
          this._getAllLinksFileStatus().catch(() => ({})),
          Api.filesInVerifiche().catch(() => []),
      ]);

      if (!hasPermission) {
        console.error("❌ Permesso negato.");
        this.isEditMode = false;
        DOMManager.updateButtonState(false);
        console.groupEnd();
        return;
      }

      const templateContainer = document.createElement("div");
      if (typeof inputTemplateHtml === "string") {
        templateContainer.innerHTML = inputTemplateHtml;
      } else if (inputTemplateHtml instanceof Node) {
        templateContainer.appendChild(inputTemplateHtml);
      }
      const inputWrapperTemplate = templateContainer.querySelector(".input-wrapper-linkref");
      const mainAddBtnTemplate = templateContainer.querySelector(".addArgBtn-main");

      if (!inputWrapperTemplate || !mainAddBtnTemplate) {
        console.error("❌ Template non valido.");
        console.groupEnd();
        return;
      }

      // STEP 2: Prima salva lo stato originale dei link ESISTENTI
      const linkElements = Array.from(document.querySelectorAll(".linkref"));
      AppState.saveOriginalState(linkElements);

      // STEP 3: Ricarica la sidebar per mostrare TUTTI gli elementi (inclusi quelli nascosti)
      console.log("🔄STEP 3: Ricaricamento sidebar per mostrare elementi nascosti...");
      await this._reloadCurrentSidebar();

      // STEP 4: Ora converte TUTTI i link visibili (inclusi quelli appena apparsi) in form di editing
      console.log("🔄STEP 4: Conversione di tutti i link in modalità editing...");

      const allLinkElements = Array.from(document.querySelectorAll(".linkref"));

      for (const element of allLinkElements) {
        const data = {
          numArg: (element.querySelector(".numArg")?.textContent || "").trim(),
          id: `${(element.querySelector(".numArg")?.textContent || "").trim()}_${(element.querySelector(".argomento")?.textContent || "").trim().replace(/\s+/g, "_")}`,
          argomento: (element.querySelector(".argomento")?.textContent || "").trim(),
          href: element.getAttribute("href"),
          display: element.getAttribute("data-display") || "show",
        };

        const hrefHideValue = element.getAttribute("data-href-hide") || "";
        data["href-hide"] = hrefHideValue;

        const category = this._getCategoryFromElement(element);
        const hasFile = fileStatusMap.has(data.href);

        console.log(`🔄Convertendo link: ${data.argomento} - display: ${data.display}`);
        DOMManager.switchToEditView(element, data, hasFile, inputWrapperTemplate.cloneNode(true), category, verfilenames);
      }

      const sections = document.querySelectorAll(".materia, .documenti");
      if (sections.length > 0) {
        sections.forEach((sec) => sec.appendChild(mainAddBtnTemplate.cloneNode(true)));
      }

      await this._addControlPanel(templateContainer);

      const mappeEl = document.getElementById("fm-sp-mappe");
      if (mappeEl && isVisible(mappeEl)) {
        await this._addControlPanelClass();
      }

      console.log("✅ Modalità editing attivata con ordine corretto");
    } catch (error) {
      console.error("❌ ERRORE CRITICO in _activateEditMode:", error);
      DOMManager.updateButtonState(false);
      this.isEditMode = false;
    } finally {
      console.groupEnd();
    }
  },

  _saveChangesAndDeactivate: async function () {
    console.group("🚀 [App] _saveChangesAndDeactivate (v3.0 Con controllo stato)");
    try {
      const allInputsData = DOMManager.collectAllInputData();
      console.log("📊Dati raccolti per validazione:", allInputsData);

      // CORREZIONE: Se la validazione fallisce, ritorna false per indicare fallimento
      if (!this._validateInputs(allInputsData)) {
        console.warn("⚠️ Validazione fallita. Rimango in modalità editing.");
        console.groupEnd();
        return false; // Indica che il salvataggio è fallito
      }

      console.log("✅ Validazione superata. Procedo con il salvataggio...");

      for (const inputData of allInputsData) {
        console.group(`➡️ Processando elemento ID: ${inputData.id}`);
        try {
          const { category, config } = this._getCategoryConfigFromElement(inputData.element);
          if (!config) {
            console.warn("⚠️ Configurazione non trovata. Salto.");
            console.groupEnd();
            continue;
          }

          const argomentoForPath = inputData.argomento.replace(/\s+/g, "_");
          const paths = config.pathPattern(config.dirName, inputData.numArg, category, argomentoForPath, AppState.optsel, AppState.folder);
          let finalHref = inputData.href;

          if (inputData.shouldSaveFile) {
            finalHref = paths.file_php;

            const newFilePaths = [finalHref];
            const oldIndex = AppState.Old.IdLinkref_array.indexOf(inputData.id);
            const oldHref = oldIndex > -1 ? AppState.Old.Href_array[oldIndex] : "";
            const oldFilePaths = [oldHref];

            // Se è un file di esercizi, verifica se la verifica esiste già
            if (finalHref.includes("/eser/")) {
              const verPath = new Utils.PathFileVerExtractor(finalHref).verPath();
              const oldVerPath = new Utils.PathFileVerExtractor(oldHref).verPath();

              // Controlla se il file di verifica esiste già
              try {
                const verExists = await Api.checkIfPageExists(verPath);
                console.log(`🔍 Verifica esistenza file verifica: ${verPath} - Esiste: ${verExists}`);

                if (!verExists) {
                  // Il file di verifica non esiste, lo aggiungiamo per crearlo
                  console.log("✅ File verifica non esiste, verrà creato");
                  newFilePaths.push(verPath);
                  oldFilePaths.push(oldVerPath);
                } else {
                  console.log("⚠️ File verifica esiste già, non verrà ricreato");
                }
              } catch (error) {
                console.error("❌ Errore verifica esistenza file verifica:", error);
                // In caso di errore, aggiungiamo comunque (comportamento precedente)
                newFilePaths.push(verPath);
                oldFilePaths.push(oldVerPath);
              }
            }

            // Phase 20 — templatesToFetch contiene riferimenti a endpoint
            // archiviati (pagEsercizi[_Ver]) + Api.createFile è già deprecato
            // Phase 18. Mantenuto solo per path legacy (risdoc / strcomp_bes).
            // Il ramo eser/lab/mappe/verifiche è NO-OP: createFile fallirà
            // prima di raggiungere il server (DEPRECATED_ENDPOINT).
            const templatesToFetch = newFilePaths.map((path) => {
              if (path.includes("/risdoc/")) {
                return "/risdoc/modello_pag_risdocToTeX.php";
              } else if (path.includes("/strcomp_bes_altro/")) {
                return "/strcomp_bes_altro/modello_pag_listSidebar-strcomp_bes_altro.php";
              } else {
                return config.templateURL; // fallback per altri casi
              }
            });
            const processedContents = await Promise.all(templatesToFetch.map((url, i) => Api.getProcessedHtmlContent(url, inputData.argomento, inputData.shouldProtectFile)));

            const oldArgomento = oldIndex > -1 ? AppState.Old.Argomento_array[oldIndex] : "";
            const cambia_titolo = oldArgomento !== inputData.argomento ? 1 : 0;

            // Crea array di stati protezione per ogni file basato sull'elemento corrente
            const shouldProtectFiles = newFilePaths.map(() => inputData.shouldProtectFile);

            const dataForServer = {
              NewFilePaths: newFilePaths,
              OldFilePaths: oldFilePaths,
              cambia_titolo: cambia_titolo,
              filedirMateria: category,
              nameArg: inputData.argomento,
              datas: processedContents,
              shouldProtectFiles: shouldProtectFiles, // Array di stati protezione per ogni file
            };

            console.log("📋¦ Dati pronti per essere inviati a create_File.php:", dataForServer);
            await Api.createFile(dataForServer);
          }

          await Api.saveExternalLink({
            folderPath: new Utils.PathFileVerExtractor(paths.file_php).getFolderPath(),
            file_externalLinks: paths.file_links,
            dataToSave: JSON.stringify({
              NumArg: inputData.numArg,
              argomento: inputData.argomento,
              id: inputData.id,
              href: finalHref,
              "href-hide": inputData.hrefHide || "", // Nuovo campo href-hide
              display: inputData.display || "show",
            }),
            id: inputData.id,
          });

          inputData.href = finalHref;
          DOMManager.switchToLinkView(inputData.element, inputData);
        } catch (error) {
          console.error(`❌ Errore durante il processamento dell'elemento ${inputData.id}:`, error);
        } finally {
          console.groupEnd();
        }
      }

      // CORREZIONE: Solo se arriviamo qui, tutto è andato bene
      console.log("✅ Tutti gli elementi processati con successo. Disattivazione modalità editing...");

      DOMManager.updateButtonState(false);
      AppState.clearOriginalState();
      document.querySelectorAll(".addArgBtn-main").forEach((el) => el.remove());
      document.querySelectorAll(".control-panel-class").forEach((el) => el.remove());
      document.body.classList.remove("fm-mappe-edit"); // ADR-023 Fase 2: era <style> iniettato

      console.groupEnd();
      return true; // Indica che il salvataggio è riuscito
    } catch (error) {
      console.error("❌ ERRORE CRITICO in _saveChangesAndDeactivate:", error);
      console.groupEnd();
      return false; // Indica che il salvataggio è fallito
    }
  },

  _getCategoryConfigFromElement: function (element) {
    const el = asElement(element);
    if (!el) return { category: null, config: null };
    for (const id in Config.SIDEBAR_CONFIG) {
      if (el.closest(id)) {
        const config = Config.SIDEBAR_CONFIG[id];
        for (let i = 0; i < config.IDcategories.length; i++) {
          if (el.closest(config.IDcategories[i])) {
            return { category: config.categories[i], config };
          }
        }
      }
    }
    return { category: null, config: null };
  },

  _validateInputs: function (inputsData) {
    console.group("🔍 [App] Esecuzione di _validateInputs");
    console.log("📋‹ Input data ricevuti:", inputsData);
    console.log("📋‹ Numero di elementi da validare:", inputsData.length);

    const seen = new Map();
    let isValid = true;

    for (const data of inputsData) {
      const { category } = this._getCategoryConfigFromElement(data.element);
      if (!category) {
        console.warn("Validazione saltata per elemento senza categoria.", data.element);
        continue;
      }

      console.log(`📂 Categoria: ${category}`);

      if (!seen.has(category)) {
        seen.set(category, { numArgs: new Set(), argomentos: new Set() });
      }
      const categoryData = seen.get(category);

      const numArg = data.numArg.trim();
      const argomento = data.argomento.trim();
      console.log(`🔢 NumArg: "${numArg}", 📝 Argomento: "${argomento}"`);

      // Controlla prima se i campi sono vuoti
      if (numArg === "") {
        this._showValidationBanner(data.element, "Num. mancante");
        console.error(`Validazione fallita: NumArg vuoto per l'elemento ID ${data.id}`);
        isValid = false;
      } else if (argomento === "") {
        this._showValidationBanner(data.element, "Argomento mancante");
        console.error(`Validazione fallita: Argomento vuoto per l'elemento ID ${data.id}`);
        isValid = false;
      }
      // Controllo duplicati con messaggi specifici come in script copy.js
      else if (categoryData.numArgs.has(numArg)) {
        this._showValidationBanner(data.element, "NumArg già presente");
        console.error(`Validazione fallita: NumArg duplicato '${numArg}' per l'elemento ID ${data.id}`);
        isValid = false;
      } else if (categoryData.argomentos.has(argomento)) {
        this._showValidationBanner(data.element, "Argomento già presente");
        console.error(`Validazione fallita: Argomento duplicato '${argomento}' per l'elemento ID ${data.id}`);
        isValid = false;
      }

      // Se l'elemento è valido, aggiungi i suoi dati per i controlli futuri
      if (numArg !== "") categoryData.numArgs.add(numArg);
      if (argomento !== "") categoryData.argomentos.add(argomento);
    }

    if (isValid) {
      console.log("✅ Validazione superata.");
    }

    console.groupEnd();
    return isValid;
  },

  _showValidationBanner: function (element, message) {
    const el = asElement(element);
    if (!el) return;
    const uniqueId = Utils.generateUUID();
    const banner = document.createElement("div");
    banner.className = "banner";
    banner.id = uniqueId;
    banner.textContent = message;
    el.appendChild(banner);

    setTimeout(() => fadeOutAndRemove(banner, 500), 2000);
  },

  // Phase 18 — rimossa: il file-status legacy era per il sidepage
  // filesystem. Sidepage ora DB-only via db-sidepage.js.
  _getAllLinksFileStatus: async function () { return new Set(); },

  /**
   * Determina la categoria dal contesto DOM dell'elemento
   */
  _getCategoryFromElement: function (element) {
    const el = asElement(element);
    if (!el) return null;

    const categoryContainer = el.closest("#MAT, #GEO, #FIS");
    if (categoryContainer) return categoryContainer.id;

    const dataCategoryEl = el.closest("[data-category]");
    const dataCategory = dataCategoryEl?.getAttribute("data-category");
    if (dataCategory) return dataCategory.toUpperCase();

    const parent = el.closest('.materia, .categoria, [class*="MAT"], [class*="GEO"], [class*="FIS"]');
    if (parent) {
      const classes = parent.getAttribute("class") || "";
      const categoryMatch = classes.match(/(MAT|GEO|FIS)/i);
      if (categoryMatch) return categoryMatch[1].toUpperCase();
    }

    if (el.closest("#fm-sp-mappe")) {
      const mappeContainer = document.getElementById("fm-sp-mappe");
      const allCategoryElements = mappeContainer
        ? mappeContainer.querySelectorAll("#MAT, #GEO, #FIS")
        : [];
      for (const categoryEl of allCategoryElements) {
        if (categoryEl.contains(el)) return categoryEl.id;
      }
    }

    console.warn(`⚠️ Impossibile determinare categoria dall'elemento DOM`, el);
    return null;
  },

  /**
   * Determina la categoria dal path href (mantienere per compatibilità con altri percorsi)
   */
  _getCategoryFromHref: function (href) {
    if (!href) return null;

    // Estrae la categoria dal path: /eser/ar/eser_ar2s/MAT/... -> MAT
    const pathParts = href.split("/");

    // Per i path come /eser/ar/eser_ar2s/MAT/file.php
    if (pathParts.length >= 5) {
      const possibleCategory = pathParts[4]; // MAT, FIS, GEO, etc.

      // Verifica se è una categoria valida controllando le configurazioni
      for (const config of Object.values(Config.SIDEBAR_CONFIG)) {
        if (config.categories && config.categories.includes(possibleCategory)) {
          return possibleCategory;
        }
      }
    }

    console.warn(`⚠️ Impossibile determinare categoria da href: ${href}`);
    return null;
  },

  /**
   * NUOVO: Raccoglie i dati dei link dai JSON già caricati durante il rendering
   */
  // Phase 19 — _collectLinkDataFromRenderedSidebar rimossa (dead code:
  // dependeva da /links/check-variation legacy + mai invocata post-Phase 18).

  // Phase 18 — sidepage ora DB-only: display status deriva da
  // visibility (published|draft|archived) della riga teacher_content.
  // Link esterni non presenti nel DB default a "show".
  _getDisplayStatusForLink: async function (_href, _category) { return "show"; },

  /**
   * Aggiunge il pannello di controllo mappe quando è presente .selwrapbtn-es
   */
  _addControlPanel: async function (templateContainer) {
    console.log("🎛️ Verifica presenza .selwrapbtn-es per pannello controllo...");
    const tmpl = asElement(templateContainer);

    const selwrapBtns = document.querySelectorAll(".selwrapbtn-es");
    if (selwrapBtns.length === 0) {
      console.log("ℹ️ .selwrapbtn-es non presente, skip pannello controllo");
      return;
    }

    console.log("✅ .selwrapbtn-es trovato, aggiunta pannello controllo mappe...");
    document.querySelectorAll(".control-panel").forEach((el) => el.remove());

    try {
      const controlPanel = tmpl?.querySelector(".control-panel");
      if (controlPanel) {
        controlPanel.style.display = "block";
        selwrapBtns[0].appendChild(controlPanel);
        console.log("🎛️ Pannello controllo mappe aggiunto dopo .selwrapbtn-es (da Elementi_Riservati.html)");
      } else {
        console.warn("⚠️ Pannello .control-panel non trovato in Elementi_Riservati.html");
      }
    } catch (error) {
      console.error("❌ Errore nell'aggiunta del pannello:", error);
    }
  },

  _addControlPanelClass: async function () {
    try {
      const existing = document.querySelectorAll(".control-panel-class");
      if (existing.length > 0) {
        existing.forEach((el) => { el.style.display = ""; });
        return;
      }

      const templateHtml = await Api.fetchHtmlTemplate("/Elementi_Riservati.html");
      const tmpl = document.createElement("div");
      tmpl.innerHTML = templateHtml;
      const controlPanelClass = tmpl.querySelector(".control-panel-class");

      if (controlPanelClass) {
        const mappeEl = document.getElementById("fm-sp-mappe");
        if (mappeEl) mappeEl.before(controlPanelClass);

        document.body.classList.add("fm-mappe-edit"); // ADR-023 Fase 2: CSS in _mappe-edit-mode.css
        controlPanelClass.style.display = "";
        console.log("🎛️ Control-panel-class aggiunto con successo a #fm-sp-mappe (modalità edit)");
      } else {
        console.warn("⚠️ .control-panel-class non trovato in Elementi_Riservati.html");
      }
    } catch (error) {
      console.error("❌ Errore nell'aggiunta del control-panel-class:", error);
    }
  },

  toggleControlPanel: function () {
    document.querySelectorAll(".control-panel-content").forEach((el) => el.classList.toggle("expanded"));
    document.querySelectorAll(".control-panel-toggle").forEach((el) => el.classList.toggle("expanded"));
  },
};

window.FM = window.FM || {};
window.FM.App = App;
window.App    = App;
