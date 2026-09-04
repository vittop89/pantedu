/**
 * GoogleAppsScript — estratto da script.js (Phase 9j). legacy block.
 * G26.phase4.4 — migrato a vanilla JS (no jQuery).
 * Sprint de-shim: richieste via _gasRequest() (fetch + AbortController),
 * niente più dipendenza da core/ajax-compat.js. _gasRequest ritorna una
 * Promise e rigetta con un oggetto {status, statusText, responseText} per i
 * fail handler che ispezionano jqXHR.status/responseText.
 */

/**
 * Richiesta verso il webhook GAS. Replica le opzioni usate qui:
 * type/data/contentType/dataType(json)/timeout/beforeSend/dataFilter.
 * @returns {Promise<any>} testo o JSON parsato; reject = {status,statusText,responseText}.
 */
async function _gasRequest({ url, type = "GET", data = null, contentType, dataType, timeout = 0, beforeSend, dataFilter } = {}) {
  const method = String(type).toUpperCase();
  const headers = {};
  let body = null;
  if (method !== "GET" && method !== "HEAD") {
    if (typeof data === "string") {
      body = data;
      headers["Content-Type"] = contentType || "application/x-www-form-urlencoded; charset=UTF-8";
    } else if (data instanceof FormData || data instanceof URLSearchParams) {
      body = data; // il browser imposta il Content-Type (incl. boundary)
    } else if (data && typeof data === "object") {
      const p = new URLSearchParams();
      for (const [k, v] of Object.entries(data)) {
        if (v != null) p.append(k, typeof v === "object" ? JSON.stringify(v) : String(v));
      }
      body = p.toString();
      headers["Content-Type"] = "application/x-www-form-urlencoded; charset=UTF-8";
    }
  }
  if (typeof beforeSend === "function") { try { beforeSend(); } catch (_) { /* ignore */ } }
  const controller = new AbortController();
  const tid = timeout > 0 ? setTimeout(() => controller.abort(), timeout) : null;
  let res;
  try {
    res = await fetch(url, { method, headers, body, credentials: "same-origin", signal: controller.signal });
  } catch (err) {
    if (tid) clearTimeout(tid);
    const isAbort = err?.name === "AbortError";
    throw { status: 0, statusText: isAbort ? "timeout" : "error", responseText: "", message: err?.message || "" };
  }
  if (tid) clearTimeout(tid);
  let text = await res.text();
  if (typeof dataFilter === "function") { try { text = dataFilter(text, dataType); } catch (_) { /* keep raw */ } }
  if (!res.ok) throw { status: res.status, statusText: res.statusText, responseText: text };
  if (dataType === "json" && typeof text === "string") {
    try { return JSON.parse(text); }
    // statusText "parsererror" come il vecchio shim: alcuni fail handler lo testano.
    catch { throw { status: res.status, statusText: "parsererror", responseText: text, message: "Invalid JSON" }; }
  }
  return text;
}

export const GoogleAppsScript = {
  // Intervallo corrente di polling (per poterlo fermare)
  currentProgressInterval: null,

  // Configurazione del webhook
  config: {
    webhookUrl: "/scriptGoogle_sync/upload-webhook.php",
    securityToken: "[DRIVE_SYNC_TOKEN]",
    timeout: 300000, // 5 minuti per operazioni di sincronizzazione classe completa
    resultContainers: {
      "sync-class": "#result-class",
      "clearMaps-class": "#result-class",
      sync: "#result-system",
      clearMaps: "#result-system",
      status: "#result-system",
      backup: "#result-system",
      reset: "#result-system",
    },
  },

  // Messaggi di successo per ogni azione
  successMessages: {
    "sync-class": "Sincronizzazione mappe della classe completata",
    "clearMaps-class": "Pulizia mappe della classe completata",
  },

  /**
   * Inizializza il modulo Google Apps Script
   */
  init: function () {
    this._bindEvents();
  },

  /**
   * Associa gli eventi del modulo
   */
  _bindEvents: function () {
    // Espone la funzione executeAction globalmente per compatibilità con HTML onclick
    window.executeAction = (action) => this.executeAction(action);
    window.stopSync = () => this.stopSync();
    window.closeControlPanel = () => {
      document.querySelectorAll(".btn-stop-sync").forEach((b) => b.classList.remove("visible"));
      document.querySelectorAll(".btn-close-panel").forEach((b) => b.classList.remove("visible"));
      { const _r = document.getElementById("result-class"); if (_r) { _r.style.display = "none"; _r.replaceChildren(); } }
      document.querySelectorAll(".btn-syncG-class:not(.btn-stop-sync):not(.btn-close-panel)").forEach((b) => { b.disabled = false; });
    };
  },

  /**
   * Ferma la sincronizzazione in corso
   */
  stopSync: function () {
    if (this.currentProgressInterval) {
      clearInterval(this.currentProgressInterval);
      this.currentProgressInterval = null;
    }
    this._addProgressMessage("sync-class", "🛑 Sincronizzazione fermata dall'utente.", "warning");
    document.querySelectorAll(".btn-stop-sync").forEach((b) => b.classList.remove("visible"));
    document.querySelectorAll(".btn-syncG-class:not(.btn-stop-sync):not(.btn-close-panel)").forEach((b) => { b.disabled = false; });
  },

  /**
   * Esegue un'azione specifica del control panel
   * @param {string} action - L'azione da eseguire ('sync-class', 'clearMaps-class')
   */
  executeAction: function (action) {
    console.log(`🎛️ [GoogleAppsScript] Azione richiesta: ${action}`);

    if (!this._validateAction(action)) {
      console.error(`❌ [GoogleAppsScript] Azione non valida: ${action}`);
      return;
    }

    // Log dei parametri della classe corrente
    const classContext = this._getClassContext();
    console.log(`📊[GoogleAppsScript] Contesto classe per ${action}:`, classContext);

    this._showLoading(action);

    const requestData = this._prepareRequestData(action);
    console.log(`📋¤ [GoogleAppsScript] Invio richiesta a webhook:`, requestData);

    this._sendWebhookRequest(requestData, action);
  },

  /**
   * Valida se l'azione è supportata
   * @param {string} action
   * @returns {boolean}
   */
  _validateAction: function (action) {
    const validActions = ["sync-class", "clearMaps-class"];
    return validActions.includes(action);
  },

  /**
   * Ottiene il container dei risultati appropriato per l'azione
   * @param {string} action
   * @returns {jQuery}
   */
  _getResultContainer: function (action) {
    const containerId = this.config.resultContainers[action] || "#result-class";
    return document.querySelector(containerId);
  },

  /**
   * Mostra l'indicatore di caricamento
   */
  _showLoading: function (action) {
    const resultDiv = this._getResultContainer(action);
    if (resultDiv) {
      // ✅ Rimuovi display: none e mostra il container
      resultDiv.style.display = "";

      const classContext = this._getClassContext();
      const debugInfo = `🕐 ${new Date().toLocaleTimeString()} | Classe: ${classContext.selectedIIS.toUpperCase()}${classContext.selectedCLS.toUpperCase()}`;

      resultDiv.innerHTML = (`
                <div class="loading">⏳ Elaborazione in corso...</div>
                <div class="debug-info" style="font-size: 11px; color: #666; margin-top: 5px;">
                    <div class="debug-timestamp">${debugInfo}</div>
                    <div class="debug-params">Azione: ${action} | Token: ✓</div>
                </div>
                <div class="progress-messages" id="progress-messages-${action}" style="margin-top: 10px; font-family: monospace; font-size: 12px;">
                    <!-- Qui verranno inseriti i messaggi di progresso -->
                </div>
            `);
    }
  },

  /**
   * Aggiunge un messaggio di progresso al container dei risultati
   * @param {string} action - L'azione in corso
   * @param {string} message - Il messaggio da mostrare
   * @param {string} type - Tipo di messaggio ('info', 'success', 'warning', 'error')
   */
  _addProgressMessage: function (action, message, type = "info") {
    const progressContainer = document.getElementById(`progress-messages-${action}`);
    if (progressContainer) {
      const timestamp = new Date().toLocaleTimeString();
      const icon =
        {
          info: "📄",
          success: "✅",
          warning: "⚠️",
          error: "❌",
        }[type] || "ℹ️";

      const messageHtml = `
                <div class="progress-message progress-${type}" style="margin: 2px 0; color: ${type === "error" ? "#d32f2f" : type === "success" ? "#388e3c" : "#666"};">
                    <span style="color: #999;">[${timestamp}]</span> ${icon} ${message}
                </div>
            `;

      progressContainer.insertAdjacentHTML("beforeend", messageHtml);

      // Auto-scroll verso il basso
      const resultDiv = this._getResultContainer(action);
      if (resultDiv) {
        resultDiv.scrollTop = resultDiv.scrollHeight;
      }
    }
  },

  /**
   * Pulisce i messaggi di progresso
   * @param {string} action - L'azione per cui pulire i messaggi
   */
  _clearProgressMessages: function (action) {
    const progressContainer = document.getElementById(`progress-messages-${action}`);
    if (progressContainer) {
      progressContainer.replaceChildren();
    }
  },

  /**
   * Avvia una simulazione di messaggi di progresso durante l'attesa
   * SOSTITUITA da polling reale dei messaggi dal webhook
   * @param {string} action - L'azione in corso
   * @returns {number} ID dell'intervallo per poterlo fermare
   */
  _startProgressSimulation: function (action) {
    // Invece di simulare, facciamo polling dei messaggi reali dal webhook
    return this._startProgressPolling(action);
  },

  /**
   * Avvia il polling dei messaggi di progresso reali dal webhook
   * @param {string} action - L'azione in corso
   * @returns {number} ID dell'intervallo per poterlo fermare
   */
  _startProgressPolling: function (action) {
    let lastMessageTimestamp = new Date().toISOString();

    return setInterval(() => {
      // Fai una richiesta AJAX per recuperare i nuovi messaggi di progresso
      _gasRequest({
        url: this.config.webhookUrl,
        type: "POST",
        data: {
          action: "get-progress-messages",
          token: this.config.securityToken,
          since: lastMessageTimestamp,
          timestamp: new Date().toISOString(),
        },
        timeout: 5000, // Timeout breve per il polling
      })
        .then((response) => {
          if (response.success && response.messages && response.messages.length > 0) {
            // Mostra i nuovi messaggi ricevuti
            response.messages.forEach((msg) => {
              this._addProgressMessage(action, msg.message, msg.type || "info");
              lastMessageTimestamp = msg.timestamp;
            });
          }
        })
        .catch((xhr) => {
          // Errore silenzioso per il polling - non interrompere l'operazione
          console.warn(`⚠️ [ProgressPolling] Errore recupero messaggi: ${xhr?.statusText || xhr?.message || xhr}`);
        });
    }, 3000); // Ogni 3 secondi controlla nuovi messaggi
  },

  /**
   * Prepara i dati per la richiesta webhook
   * @param {string} action
   * @returns {object}
   */
  _prepareRequestData: function (action) {
    // Raccogli i parametri di contesto della classe corrente
    const classContext = this._getClassContext();

    // Mappa l'azione a quella supportata dal webhook
    const webhookAction = this._mapToWebhookAction(action);

    // Prepara i dati base
    const requestData = {
      action: webhookAction, // ✅ Usa l'azione mappata
      token: this.config.securityToken,
      timestamp: new Date().toISOString(),
      // Parametri specifici della classe per la sincronizzazione
      classParams: classContext,
    };

    // Se è clearMaps-class, usa il nuovo formato di parametri
    if (webhookAction === "clearMaps-class") {
      // ✅ Usa il formato che si aspetta il nuovo modulo DriveArubaSync
      requestData.selectedIIS = classContext.selectedIIS || window.FM?.Curriculum?.firstCode("indirizzi") || "";
      requestData.selectedCLS = classContext.selectedCLS || window.FM?.Curriculum?.firstCode("classi") || "";
      requestData.selectedMATER = classContext.selectedMATER || window.FM?.Curriculum?.firstCode("materie") || "";
      requestData.optsel = classContext.optsel || "ar3s";
      requestData.folder = classContext.folder || "ART";
      requestData.mater = classContext.mater || "M";
    }
    // Se è sync-class, aggiungi anche i parametri al livello principale
    else if (webhookAction === "sync-class" || action === "sync-class") {
      // ✅ Aggiungi parametri per sync-class
      requestData.selectedIIS = classContext.selectedIIS || window.FM?.Curriculum?.firstCode("indirizzi") || "";
      requestData.selectedCLS = classContext.selectedCLS || window.FM?.Curriculum?.firstCode("classi") || "";
      requestData.selectedMATER = classContext.selectedMATER || window.FM?.Curriculum?.firstCode("materie") || "";
      requestData.optsel = classContext.optsel || "ar3s";
      requestData.folder = classContext.folder || "ART";
      requestData.mater = classContext.mater || "M";
    }
    // Se è una operazione specifica per classe legacy, aggiungi parametri necessari
    else if (webhookAction === "sync_class_specific" || webhookAction === "clear_class_specific") {
      // ✅ CORREZIONE: Usa i nomi parametri che si aspetta il webhook
      requestData.selectedIIS = classContext.selectedIIS || window.FM?.Curriculum?.firstCode("indirizzi") || ""; // default dinamico
      requestData.selectedCLS = classContext.selectedCLS || window.FM?.Curriculum?.firstCode("classi") || ""; // default dinamico
      requestData.selectedMATER = classContext.selectedMATER || "All"; // Aggiungi la materia selezionata

      // Per sync_class_specific, aggiungi dati di update (se disponibili)
      if (webhookAction === "sync_class_specific") {
        requestData.mappeUpdates = []; // Placeholder - qui andranno i dati delle mappe da aggiornare
      }
    }

    console.log(`📊[GoogleAppsScript] Azione originale: "${action}" → Webhook: "${webhookAction}"`);
    console.log(`📊[GoogleAppsScript] Contesto classe preparato:`, classContext);
    console.log(`📊[GoogleAppsScript] Parametri classe specifici:`, {
      selectedIIS: requestData.selectedIIS,
      selectedCLS: requestData.selectedCLS,
      selectedMATER: requestData.selectedMATER,
    });

    return requestData;
  },

  /**
   * Raccoglie il contesto della classe corrente dall'AppState
   * @returns {object}
   */
  _getClassContext: function () {
    // Prima leggi i valori effettivi dai select
    const currentSelectors = {
      selectedIIS: document.getElementById("sel-iis")?.value,
      selectedCLS: document.getElementById("sel-cls")?.value,
      selectedMATER: document.getElementById("sel-mater")?.value,
    };

    // Assicurati che AppState sia aggiornato dai select
    AppState.updateFromSelects();

    // ✅ VERIFICA COERENZA: Usa i valori effettivi dai select come riferimento
    const expectedOptsel = currentSelectors.selectedIIS + currentSelectors.selectedCLS;
    const expectedFolder = currentSelectors.selectedIIS + (currentSelectors.selectedCLS.includes("b") ? "_b" : "");

    if (AppState.optsel !== expectedOptsel) {
      console.warn(`🔧 [GoogleAppsScript] Correzione optsel: "${AppState.optsel}" → "${expectedOptsel}"`);
      AppState.optsel = expectedOptsel;
      sessionStorage.setItem("selectedMAP", AppState.optsel);
    }

    if (AppState.folder !== expectedFolder) {
      console.warn(`🔧 [GoogleAppsScript] Correzione folder: "${AppState.folder}" → "${expectedFolder}"`);
      AppState.folder = expectedFolder;
      sessionStorage.setItem("selectedFold", AppState.folder);
    }

    const context = {
      selectedIIS: AppState.selectedIIS,
      selectedCLS: AppState.selectedCLS,
      selectedMATER: AppState.selectedMATER,
      optsel: AppState.optsel,
      folder: AppState.folder,
      mater: AppState.mater,
    };

    console.log(`📊[GoogleAppsScript] Contesto da AppState:`, context);

    // Validazione e fallback per parametri essenziali
    if (!context.selectedIIS || !context.selectedCLS || !context.selectedMATER) {
      console.warn("⚠️ [GoogleAppsScript] Parametri classe mancanti, usando valori default");
      console.warn("⚠️ [GoogleAppsScript] Valori AppState prima della correzione:", {
        selectedIIS: context.selectedIIS,
        selectedCLS: context.selectedCLS,
        selectedMATER: context.selectedMATER,
      });

      // Forza i valori default se mancanti
      context.selectedIIS = context.selectedIIS || currentSelectors.selectedIIS || window.FM?.Curriculum?.firstCode("indirizzi") || "";
      context.selectedCLS = context.selectedCLS || currentSelectors.selectedCLS || window.FM?.Curriculum?.firstCode("classi") || "";
      context.selectedMATER = context.selectedMATER || currentSelectors.selectedMATER || window.FM?.Curriculum?.firstCode("materie") || "";

      // Ricalcola optsel e folder se necessario
      if (!context.optsel) {
        context.optsel = context.selectedIIS + context.selectedCLS;
      }
      if (!context.folder) {
        context.folder = context.selectedIIS + (context.selectedCLS.includes("b") ? "_b" : "");
      }
      if (!context.mater) {
        context.mater = context.selectedMATER.substring(0, 1);
      }

      console.log("🔧 [GoogleAppsScript] Valori corretti applicati:", context);
    }

    return context;
  },

  /**
   * Invia la richiesta al webhook
   * @param {object} requestData
   * @param {string} action
   */
  _sendWebhookRequest: function (requestData, action) {
    console.log(`📋¤ [GoogleAppsScript] Dati da inviare (prima di JSON.stringify):`, requestData);

    // GESTIONE SPECIALE PER AZIONI SYNC: chiamata diretta a Google Apps Script
    if (action === "sync-class" || action.includes("sync")) {
      console.log(`🔄[GoogleAppsScript] Rilevata azione di sincronizzazione: ${action}`);
      this._handleSyncAction(requestData, action);
      return;
    }

    // Timeout specifico per operazioni di sincronizzazione classe (più lunghe)
    const isLongOperation = action.includes("sync-class") || action.includes("sync_class");
    const timeoutValue = isLongOperation ? 300000 : this.config.timeout; // 5 minuti per sync-class, normale per altri

    console.log(`⏱️ [GoogleAppsScript] Timeout impostato: ${timeoutValue / 1000}s per azione ${action}`);

    _gasRequest({
      url: this.config.webhookUrl,
      type: "POST",
      data: JSON.stringify(requestData),
      contentType: "application/json; charset=utf-8",
      dataType: "json",
      timeout: timeoutValue,
      beforeSend: () => {
        console.log(`🚀 [GoogleAppsScript] Invio richiesta JSON per azione: ${action}`);
        console.log(`📋¡ [GoogleAppsScript] JSON inviato:`, JSON.stringify(requestData, null, 2));
      },
    })
      .then((response) => this._handleSuccess(response, action))
      .catch((jqXHR) => {
        const textStatus = jqXHR?.statusText, errorThrown = jqXHR?.message;
        // Gestione specifica per "Unknown action"
        if (jqXHR.status === 400 && jqXHR.responseText.includes("Unknown action")) {
          console.warn(`⚠️ [GoogleAppsScript] Azione "${action}" non riconosciuta dal webhook`);
          console.warn(`🔍 [GoogleAppsScript] Risposta webhook:`, jqXHR.responseText);

          // Prova a mappare l'azione a una supportata dal webhook
          const mappedAction = this._mapToWebhookAction(action);
          if (mappedAction && mappedAction !== action) {
            console.log(`🔄[GoogleAppsScript] Tentativo con azione mappata: "${mappedAction}"`);
            const newRequestData = { ...requestData, action: mappedAction };
            this._sendRetryRequest(newRequestData, action);
            return;
          }

          // Se non c'è mapping, mostra errore specifico
          this._handleUnknownActionError(action, jqXHR.responseText);
        }
        // Se il JSON fallisce, prova con form-data
        else if (jqXHR.status === 400 && jqXHR.responseText.includes("Invalid JSON")) {
          console.warn(`⚠️ [GoogleAppsScript] JSON respinto, tentativo con form-data...`);
          this._sendAsFormData(requestData, action);
        } else {
          this._handleError(jqXHR, textStatus, errorThrown, action);
        }
      });
  },

  /**
   * Mappa le azioni del frontend a quelle supportate dal webhook
   * @param {string} action
   * @returns {string}
   */
  _mapToWebhookAction: function (action) {
    const actionMap = {
      "sync-class": "sync_class_specific", // ✅ Nuova azione per classe specifica
      "clearMaps-class": "clearMaps-class", // ✅ Mappatura diretta al nuovo modulo
      sync: "sync_mappe", // Per tutte le classi (sistema globale)
      clearMaps: "clear_mappe", // Per tutte le classi (sistema globale)
      status: "upload_file", // Fallback
      backup: "upload_file", // Fallback
    };

    return actionMap[action] || action;
  },

  /**
   * Invia una richiesta di retry con azione mappata
   * @param {object} requestData
   * @param {string} originalAction
   */
  _sendRetryRequest: function (requestData, originalAction) {
    _gasRequest({
      url: this.config.webhookUrl,
      type: "POST",
      data: JSON.stringify(requestData),
      contentType: "application/json; charset=utf-8",
      dataType: "json",
      timeout: this.config.timeout,
      beforeSend: () => {
        console.log(`🔄[GoogleAppsScript] Retry con azione mappata: ${requestData.action}`);
      },
    })
      .then((response) => this._handleSuccess(response, originalAction))
      .catch((jqXHR) => {
        console.error(`❌ [GoogleAppsScript] Anche il retry è fallito`);
        this._handleError(jqXHR, jqXHR?.statusText, jqXHR?.message, originalAction);
      });
  },

  /**
   * Gestisce le azioni di sincronizzazione chiamando direttamente Google Apps Script
   * @param {object} requestData
   * @param {string} action
   */
  _handleSyncAction: function (requestData, action) {
    console.log(`🎯 [GoogleAppsScript] Gestione azione sync: ${action}`);
    console.log(`📊[GoogleAppsScript] Parametri sync:`, requestData);

    const resultDiv = this._getResultContainer(action);
    const classContext = this._getClassContext();

    // Mostra immediatamente il messaggio di avvio con container per messaggi di progresso
    resultDiv.innerHTML = (`
            <div class="loading sync-loading">
                <div class="sync-icon">🔄</div>
                <strong>Sincronizzazione Drawio in corso...</strong>
                <div class="sync-details">
                    <div>📂 Scansione cartelle Google Drive per classe ${classContext.optsel}</div>
                    <div>🎯 Materia selezionata: ${classContext.selectedMATER}</div>
                    <div>⏱️ Operazione avviata: ${new Date().toLocaleTimeString()}</div>
                </div>
                <div class="progress-messages" id="progress-messages-${action}" style="margin-top: 10px; max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 8px; background: #f9f9f9; font-family: monospace; font-size: 12px;">
                    <!-- Messaggi di progresso verranno aggiunti qui -->
                </div>
            </div>
            <div class="debug-info" style="font-size: 11px; color: #666; margin-top: 10px;">
                <div>🔧 Chiamata diretta a Google Apps Script...</div>
                <div>📋¡ URL: ${this._getGoogleAppsScriptUrl()}</div>
            </div>
        `);

    // Aggiungi messaggio iniziale di progresso
    this._addProgressMessage(action, "🚀 Avvio sincronizzazione Google Apps Script...", "info");

    // Avvia polling messaggi di progresso, mostra Stop e Chiudi
    this.currentProgressInterval = this._startProgressSimulation(action);
    document.querySelectorAll(".btn-stop-sync").forEach((b) => b.classList.add("visible"));
    document.querySelectorAll(".btn-close-panel").forEach((b) => b.classList.add("visible"));
    document.querySelectorAll(".btn-syncG-class:not(.btn-stop-sync):not(.btn-close-panel)").forEach((b) => { b.disabled = true; });

    // Chiama Google Apps Script
    this._callGoogleAppsScript(requestData, action)
      .then((response) => {
        clearInterval(this.currentProgressInterval); // Ferma il polling
        this.currentProgressInterval = null;
        document.querySelectorAll(".btn-stop-sync").forEach((b) => b.classList.remove("visible"));
        document.querySelectorAll(".btn-syncG-class:not(.btn-stop-sync):not(.btn-close-panel)").forEach((b) => { b.disabled = false; });
        console.log(`✅ [GoogleAppsScript] Sincronizzazione completata:`, response);
        this._handleSyncSuccess(response, action);
      })
      .catch((error) => {
        clearInterval(this.currentProgressInterval); // Ferma il polling
        this.currentProgressInterval = null;
        document.querySelectorAll(".btn-stop-sync").forEach((b) => b.classList.remove("visible"));
        document.querySelectorAll(".btn-syncG-class:not(.btn-stop-sync):not(.btn-close-panel)").forEach((b) => { b.disabled = false; });
        console.error(`❌ [GoogleAppsScript] Errore sincronizzazione:`, error);
        this._handleSyncError(error, action);
      });
  },

  /**
   * Ottiene l'URL di Google Apps Script per la sincronizzazione
   * Ora usa il proxy sicuro tramite GAS_Client
   * @returns {string}
   */
  _getGoogleAppsScriptUrl: function () {
    // Usa sempre il proxy sicuro (nessuna credenziale esposta)
    return "/scriptGoogle_sync/gas-config-proxy.php";
  },

  /**
   * Chiama Google Apps Script per eseguire la sincronizzazione
   * @param {object} requestData
   * @param {string} action
   * @returns {Promise}
   */
  _callGoogleAppsScript: function (requestData, action) {
    return new Promise((resolve, reject) => {
      console.log(`📋ž [GoogleAppsScript] Chiamata REALE a Google Apps Script...`);

      // Raccogli il contesto della classe attuale per assicurare che i parametri siano presenti
      const classContext = this._getClassContext();

      // Prepara i dati per la chiamata reale
      const syncRequestData = {
        action: "sync-class-execute", // Azione speciale per esecuzione reale
        token: requestData.token,
        timestamp: new Date().toISOString(),
        executeSync: true, // Flag per indicare esecuzione reale
        // Assicurati che i parametri essenziali siano presenti
        selectedIIS: requestData.selectedIIS || classContext.selectedIIS || window.FM?.Curriculum?.firstCode("indirizzi") || "",
        selectedCLS: requestData.selectedCLS || classContext.selectedCLS || window.FM?.Curriculum?.firstCode("classi") || "",
        selectedMATER: requestData.selectedMATER || classContext.selectedMATER || window.FM?.Curriculum?.firstCode("materie") || "",
        optsel: requestData.optsel || classContext.optsel || "ar3s",
        folder: requestData.folder || classContext.folder || "ART",
        classParams: requestData.classParams || classContext,
      };

      console.log(`📋¡ [GoogleAppsScript] Dati per chiamata reale:`, syncRequestData);

      // Chiama il webhook con parametri per l'esecuzione reale
      _gasRequest({
        url: this.config.webhookUrl,
        type: "POST",
        data: JSON.stringify(syncRequestData),
        contentType: "application/json; charset=utf-8",
        dataType: "json",
        timeout: 300000, // 5 minuti per operazioni lunghe
        // Filtro personalizzato per gestire risposte miste HTML+JSON
        dataFilter: function (data, type) {
          if (type === "json" && typeof data === "string") {
            // Se la risposta contiene warning HTML seguiti da JSON, estrai solo il JSON
            const jsonStart = data.indexOf("{");
            const jsonEnd = data.lastIndexOf("}") + 1;

            if (jsonStart !== -1 && jsonEnd > jsonStart && data.includes("<b>Warning</b>")) {
              console.warn(`⚠️ [GoogleAppsScript] Rilevati warning PHP nella risposta, estrazione JSON...`);
              return data.substring(jsonStart, jsonEnd);
            }
          }
          return data;
        },
        beforeSend: () => {
          console.log(`🚀 [GoogleAppsScript] Invio richiesta sync reale...`);
        },
      })
        .then((response) => {
          console.log(`✅ [GoogleAppsScript] Risposta sync reale ricevuta:`, response);

          // Verifica se la risposta è un oggetto valido e non una stringa HTML
          if (typeof response === "string" && response.includes("<b>Warning</b>")) {
            console.error(`❌ [GoogleAppsScript] Risposta HTML ricevuta invece di JSON:`, response);
            reject(new Error("Il server ha restituito HTML invece di JSON. Verificare la configurazione del webhook PHP."));
            return;
          }

          if (response && response.success) {
            // Estrai i dati da gasResponse se disponibile (webhook wrapping)
            const gasData = response.gasResponse || response;

            resolve({
              success: true,
              action: action,
              message: response.message || `Sincronizzazione drawio completata per classe ${requestData.optsel}`,
              filesGenerated: gasData.filesGenerated || [],
              totalFiles: gasData.totalFiles || 0,
              jsonFilesCreated: gasData.jsonFilesCreated || 0,
              timestamp: response.timestamp || new Date().toISOString(),
              method: "GoogleAppsScript-Real",
              details: response.details,
            });
          } else {
            reject(new Error(response.error || "Errore sconosciuto nella sincronizzazione"));
          }
        })
        .catch((jqXHR) => {
          const textStatus = jqXHR?.statusText, errorThrown = jqXHR?.message;
          console.error(`❌ [GoogleAppsScript] Errore chiamata sync reale:`, {
            status: jqXHR.status,
            statusText: jqXHR.statusText,
            textStatus: textStatus,
            errorThrown: errorThrown,
            responseText: jqXHR.responseText,
          });

          let errorMessage;
          let jsonResponse = null;

          // Tenta di estrarre JSON da una risposta mista HTML+JSON
          if (jqXHR.responseText && textStatus === "parsererror") {
            try {
              // Cerca l'inizio del JSON nella risposta (dopo i warning HTML)
              const jsonStart = jqXHR.responseText.indexOf("{");
              const jsonEnd = jqXHR.responseText.lastIndexOf("}") + 1;

              if (jsonStart !== -1 && jsonEnd > jsonStart) {
                const jsonString = jqXHR.responseText.substring(jsonStart, jsonEnd);
                jsonResponse = JSON.parse(jsonString);
                console.log(`🔧 [GoogleAppsScript] JSON estratto dalla risposta mista:`, jsonResponse);

                // Se abbiamo estratto il JSON con successo, usa il messaggio di errore dal JSON
                if (jsonResponse && jsonResponse.error) {
                  errorMessage = jsonResponse.error;
                }
              }
            } catch (parseError) {
              console.warn(`⚠️ [GoogleAppsScript] Impossibile estrarre JSON dalla risposta:`, parseError);
            }
          }

          // Se non siamo riusciti a estrarre un messaggio dal JSON, usa la logica precedente
          if (!errorMessage) {
            if (jqXHR.responseText && jqXHR.responseText.includes("<b>Warning</b>")) {
              if (jqXHR.responseText.includes("file_get_contents") && jqXHR.responseText.includes("Failed to open stream")) {
                errorMessage = "Errore di connessione a Google Apps Script. Verificare l'URL dello script e i permessi.";
              } else if (jqXHR.responseText.includes("HTTP request failed")) {
                errorMessage = "Richiesta HTTP a Google Apps Script fallita. Verificare la connessione internet e l'URL dello script.";
              } else {
                errorMessage = "Errore PHP nel webhook. Controllare i log del server.";
              }
            } else if (textStatus === "parsererror") {
              errorMessage = "Risposta del server non valida (JSON malformato). Controllare la configurazione del webhook.";
            } else {
              errorMessage = jqXHR.responseText ? `Errore server: ${jqXHR.responseText}` : `Errore di connessione: ${errorThrown || textStatus}`;
            }
          }

          reject(new Error(errorMessage));
        });
    });
  },

  /**
   * Gestisce il successo della sincronizzazione
   * @param {object} response
   * @param {string} action
   */
  _handleSyncSuccess: function (response, action) {
    const resultDiv = this._getResultContainer(action);
    const classContext = this._getClassContext();

    // Conserva i messaggi di progresso esistenti
    const existingProgressMessages = document.getElementById(`progress-messages-${action}`)?.innerHTML || "";

    // DEBUG: Log completo della risposta
    console.log("🔍 [DEBUG] Response completo:", response);
    console.log("🔍 [DEBUG] response.gasResponse:", response.gasResponse);
    console.log("🔍 [DEBUG] response.filesGenerated:", response.filesGenerated);

    // Estrai i dati da gasResponse se disponibile (webhook wrapping)
    const gasData = response.gasResponse || response;
    console.log("🔍 [DEBUG] gasData estratto:", gasData);
    console.log("🔍 [DEBUG] gasData.filesGenerated:", gasData.filesGenerated);
    console.log("🔍 [DEBUG] gasData.totalFiles:", gasData.totalFiles);

    const filesInfo = gasData.filesGenerated || [];
    const totalDrawioFiles = gasData.totalFiles || 0;
    const jsonFilesCreated = gasData.jsonFilesCreated || filesInfo.length;
    const completedTime = new Date().toLocaleTimeString();

    console.log("🔍 [DEBUG] filesInfo finale:", filesInfo);
    console.log("🔍 [DEBUG] totalDrawioFiles finale:", totalDrawioFiles);

    // DEBUG: Mostra il contenuto di ogni file
    filesInfo.forEach((file, index) => {
      console.log(`🔍 [DEBUG] File[${index}]:`, file);
      console.log(`🔍 [DEBUG] File[${index}].fileName:`, file.fileName);
      console.log(`🔍 [DEBUG] File[${index}].drawioCount:`, file.drawioCount);
      console.log(`🔍 [DEBUG] File[${index}].subject:`, file.subject);
    });

    // Aggiungi messaggio finale di completamento
    this._addProgressMessage(action, `🎉 Sincronizzazione completata con successo!`, "success");

    resultDiv.innerHTML = (`
            <div class="success sync-success">
                <div class="sync-icon">✅</div>
                <strong>Sincronizzazione Drawio Completata!</strong>
                <div class="sync-results">
                    <div>📊Materie sincronizzate: <strong>${filesInfo.length}</strong></div>
                    <div>📄 File drawio totali: <strong>${totalDrawioFiles}</strong></div>
                    <div>📝 File JSON creati: <strong>${jsonFilesCreated}</strong></div>
                    <div>⏰ Completata: ${completedTime}</div>
                </div>
                <div class="files-list" style="margin-top: 10px;">
                    <strong>Dettagli per materia:</strong>
                    ${filesInfo
                      .map(
                        (file) => `
                        <div class="file-item" style="margin: 5px 0; padding: 8px; background: #f8f9fa; border-left: 3px solid #28a745;">
                            <div style="font-weight: bold; margin-bottom: 3px;">
                                📊${file.materiaName || file.materia}
                            </div>
                            <div style="font-size: 12px; color: #666;">
                                📄 ${file.drawioFiles} file drawio trovati
                            </div>
                            <div style="font-size: 11px; color: #888; margin-top: 2px;">
                                📋‹ ${file.drawioLinksFile}
                            </div>
                            <div style="font-size: 11px; color: #888;">
                                📋‹ ${file.mappeLinksFile}
                            </div>
                        </div>
                    `,
                      )
                      .join("")}
                </div>
                <div class="progress-log" style="margin-top: 15px;">
                    <strong>Log operazioni:</strong>
                    <div class="progress-messages" id="progress-messages-${action}" style="margin-top: 5px; max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 8px; background: #f9f9f9; font-family: monospace; font-size: 11px;">
                        ${existingProgressMessages}
                    </div>
                </div>
            </div>
            <div class="debug-info" style="font-size: 11px; color: #28a745; margin-top: 10px;">
                <div>✅ Metodo: ${response.method || "GoogleAppsScript"}</div>
                <div>🎯 Classe: ${classContext.optsel} | Materia: ${classContext.selectedMATER}</div>
                <div>🕐 Timestamp: ${response.timestamp}</div>
            </div>
        `);

    // Auto-hide dopo 30 secondi (più tempo per leggere i risultati)
    this._autoHideResult(30000, action);
  },

  /**
   * Gestisce gli errori della sincronizzazione
   * @param {Error} error
   * @param {string} action
   */
  _handleSyncError: function (error, action) {
    const resultDiv = this._getResultContainer(action);
    const classContext = this._getClassContext();

    resultDiv.innerHTML = (`
            <div class="error sync-error">
                <div class="sync-icon">❌</div>
                <strong>Errore Sincronizzazione Drawio</strong>
                <div class="error-details">
                    <div>📄 Messaggio: ${error.message || "Errore sconosciuto"}</div>
                    <div>🎯 Classe: ${classContext.optsel}</div>
                    <div>⏰ Errore: ${new Date().toLocaleTimeString()}</div>
                </div>
            </div>
            <div class="debug-info" style="font-size: 11px; color: #dc3545; margin-top: 10px;">
                <div>❌ Chiamata Google Apps Script fallita</div>
                <div>🔧 Verifica che Google Apps Script sia configurato correttamente</div>
                <div>💡¡ Suggerimento: Controlla i log di Google Apps Script</div>
            </div>
        `);

    // Auto-hide dopo 20 secondi
    this._autoHideResult(20000, action);
  },

  /**
   * Gestisce l'errore di azione sconosciuta
   * @param {string} action
   * @param {string} responseText
   */
  _handleUnknownActionError: function (action, responseText) {
    const resultDiv = this._getResultContainer(action);
    if (resultDiv) {
      const classContext = this._getClassContext();
      const classInfo = `Classe: ${classContext.selectedIIS.toUpperCase()}${classContext.selectedCLS.toUpperCase()}`;

      const debugTimestamp = `❌ ${new Date().toLocaleTimeString()}`;
      const debugParams = `Azione "${action}" non supportata dal webhook`;

      resultDiv.innerHTML = (`
                <div class="error">
                    ❌ Azione non supportata: "${action}"<br>
                    <small>${classInfo}</small><br>
                    <small>Risposta server: ${responseText}</small>
                </div>
                <div class="debug-info" style="font-size: 11px; color: #dc3545; margin-top: 5px; border-top: 1px solid #f8d7da; padding-top: 5px;">
                    <div class="debug-timestamp">${debugTimestamp}</div>
                    <div class="debug-params">${debugParams}</div>
                    <div style="margin-top: 3px;">💡¡ Suggerimento: Verifica le azioni supportate dal webhook</div>
                </div>
            `);
    }

    // Auto-hide dopo 15 secondi per permettere di leggere il suggerimento
    this._autoHideResult(15000, action);
  },

  /**
   * Fallback: invia i dati come application/x-www-form-urlencoded
   * @param {object} requestData
   * @param {string} action
   */
  _sendAsFormData: function (requestData, action) {
    // Converti l'oggetto in form-data, appiattendo classParams
    const formData = {
      action: requestData.action,
      token: requestData.token,
      timestamp: requestData.timestamp,
      // Appiattisci classParams
      selectedIIS: requestData.classParams.selectedIIS,
      selectedCLS: requestData.classParams.selectedCLS,
      selectedMATER: requestData.classParams.selectedMATER,
      optsel: requestData.classParams.optsel,
      folder: requestData.classParams.folder,
      mater: requestData.classParams.mater,
    };

    console.log(`📋¤ [GoogleAppsScript] Tentativo form-data:`, formData);

    _gasRequest({
      url: this.config.webhookUrl,
      type: "POST",
      data: formData,
      timeout: this.config.timeout,
      beforeSend: () => {
        console.log(`🔄[GoogleAppsScript] Invio richiesta form-data per azione: ${action}`);
      },
    })
      .then((response) => this._handleSuccess(response, action))
      .catch((jqXHR) => this._handleError(jqXHR, jqXHR?.statusText, jqXHR?.message, action));
  },

  /**
   * Gestisce la risposta di successo
   * @param {*} response
   * @param {string} action
   */
  _handleSuccess: function (response, action) {
    try {
      let jsonResponse;

      // Se la risposta è già un oggetto, usala direttamente
      if (typeof response === "object" && response !== null) {
        jsonResponse = response;
      }
      // Se è una stringa, prova a parsare come JSON
      else if (typeof response === "string") {
        // Se la stringa sembra JSON, prova a parsarla
        if (response.trim().startsWith("{") || response.trim().startsWith("[")) {
          jsonResponse = JSON.parse(response);
        } else {
          // Se non è JSON, trattala come messaggio di testo
          throw new Error("Risposta testuale");
        }
      }

      // Controlla se l'operazione è riuscita
      if (jsonResponse && jsonResponse.success) {
        this._showSuccessMessage(action);
      } else if (jsonResponse && jsonResponse.error) {
        throw new Error(jsonResponse.error);
      } else {
        // Se non c'è un chiaro indicatore di successo, considera come successo
        this._showSuccessMessage(action, JSON.stringify(jsonResponse));
      }
    } catch (parseError) {
      // Se non è JSON valido, tratta come messaggio di testo di successo
      this._showSuccessMessage(action, response);
    }

    // Auto-hide dopo 8 secondi
    this._autoHideResult(8000, action);
  },

  /**
   * Gestisce gli errori della richiesta
   * @param {object} jqXHR
   * @param {string} textStatus
   * @param {string} errorThrown
   * @param {string} action
   */
  _handleError: function (jqXHR, textStatus, errorThrown, action) {
    console.error(`❌ [GoogleAppsScript] Errore nella richiesta:`, {
      status: jqXHR.status,
      statusText: jqXHR.statusText,
      responseText: jqXHR.responseText,
      textStatus: textStatus,
      errorThrown: errorThrown,
    });

    const errorMessage = this._getErrorMessage(jqXHR, textStatus);
    this._showErrorMessage(errorMessage, action);

    // Auto-hide dopo 12 secondi (più tempo per errori)
    this._autoHideResult(12000, action);
  },

  /**
   * Determina il messaggio di errore appropriato
   * @param {object} jqXHR
   * @param {string} textStatus
   * @returns {string}
   */
  _getErrorMessage: function (jqXHR, textStatus) {
    if (textStatus === "timeout") {
      return "Timeout - L'operazione potrebbe richiedere più tempo";
    } else if (jqXHR.status === 403) {
      return "Accesso negato - Verifica le credenziali";
    } else if (jqXHR.status === 404) {
      return "Webhook non trovato";
    } else if (jqXHR.status >= 500) {
      return "Errore del server";
    } else if (jqXHR.responseText) {
      return jqXHR.responseText;
    }
    return "Errore di connessione";
  },

  /**
   * Mostra un messaggio di successo
   * @param {string} action
   * @param {string} additionalInfo
   */
  _showSuccessMessage: function (action, additionalInfo = "") {
    const resultDiv = this._getResultContainer(action);
    if (resultDiv) {
      const baseMessage = this.successMessages[action] || "Operazione completata";
      const classContext = this._getClassContext();

      // Crea un messaggio dettagliato con i parametri della classe
      const classInfo = `Classe: ${classContext.selectedIIS.toUpperCase()}${classContext.selectedCLS.toUpperCase()}`;
      let materiaInfo = "";

      // Messaggio specifico per la materia
      if (classContext.selectedMATER === "All") {
        materiaInfo = " - Tutte le materie (MAT, GEO, FIS)";
      } else {
        const materiaNames = {
          Mat: "Matematica (MAT)",
          Geo: "Geometria (GEO)",
          Fis: "Fisica (FIS)",
        };
        materiaInfo = ` - ${materiaNames[classContext.selectedMATER] || classContext.selectedMATER}`;
      }

      let fullMessage = `✅ ${baseMessage}<br><small>${classInfo}${materiaInfo}</small>`;

      if (additionalInfo) {
        fullMessage += `<br><small>Risposta server: ${additionalInfo}</small>`;
      }

      const debugTimestamp = `✅ ${new Date().toLocaleTimeString()}`;
      const debugParams = `URL: ${this.config.webhookUrl} | Status: SUCCESS | Action: ${action}`;

      resultDiv.innerHTML = (`
                <div class="success">${fullMessage}</div>
                <div class="debug-info" style="font-size: 11px; color: #28a745; margin-top: 5px; border-top: 1px solid #d4edda; padding-top: 5px;">
                    <div class="debug-timestamp">${debugTimestamp}</div>
                    <div class="debug-params">${debugParams}</div>
                </div>
            `);
    }
  },

  /**
   * Mostra un messaggio di errore
   * @param {string} errorMessage
   * @param {string} action
   */
  _showErrorMessage: function (errorMessage, action) {
    const resultDiv = this._getResultContainer(action);
    if (resultDiv) {
      const classContext = this._getClassContext();
      const classInfo = `Classe: ${classContext.selectedIIS.toUpperCase()}${classContext.selectedCLS.toUpperCase()}`;

      const debugTimestamp = `❌ ${new Date().toLocaleTimeString()}`;
      const debugParams = `URL: ${this.config.webhookUrl} | Status: ERROR | Action: ${action}`;

      resultDiv.innerHTML = (`
                <div class="error">❌ ${errorMessage}<br><small>${classInfo}</small></div>
                <div class="debug-info" style="font-size: 11px; color: #dc3545; margin-top: 5px; border-top: 1px solid #f8d7da; padding-top: 5px;">
                    <div class="debug-timestamp">${debugTimestamp}</div>
                    <div class="debug-params">${debugParams}</div>
                </div>
            `);
    }
  },

  /**
   * Nasconde automaticamente il risultato dopo un delay
   * @param {number} delay
   * @param {string} action
   */
  _autoHideResult: function (delay, action) {
    setTimeout(() => {
      const resultDiv = this._getResultContainer(action);
      if (resultDiv) {
        resultDiv.style.transition = "opacity 500ms"; resultDiv.style.opacity = "0"; setTimeout(() => { resultDiv.style.display = "none"; resultDiv.style.opacity = ""; }, 500);
      }
    }, delay);
  },

  /**
   * Metodi di utilità per uso esterno
   */
  syncMaps: function () {
    this.executeAction("sync-class");
  },

  clearMaps: function () {
    this.executeAction("clearMaps-class");
  },
};

window.FM = window.FM || {};
window.FM.GoogleAppsScript = GoogleAppsScript;
window.GoogleAppsScript    = GoogleAppsScript;
