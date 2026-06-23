/**
 * LogoutWidgetManager \u2014 estratto da functions-mod.js (Phase 9d).
 */

export const LogoutWidgetManager = {
  _state: {
    isInitialized: false,
    isScriptLoaded: false,
    initAttempts: 0,
    maxAttempts: 15,
  },

  // Inizializza il sistema logout widget
  init: function () {
    if (this._state.isInitialized) {
      return;
    }

    try {
      this._loadScript()
        .then(() => {
          this._initializeWidget();
        })
        .catch((error) => {
          console.error("Errore caricamento script logout:", error);
        });
    } catch (error) {
      console.error("Errore inizializzazione LogoutWidget:", error);
    }
  },

  // Carica lo script del logout button
  _loadScript: function () {
    return new Promise((resolve, reject) => {
      if (this._state.isScriptLoaded || window.LogoutButton) {
        resolve();
        return;
      }

      const existingScript = document.querySelector('script[src="/log/logout/logout_button.js"]');
      if (existingScript) {
        setTimeout(() => {
          this._state.isScriptLoaded = true;
          resolve();
        }, 200);
        return;
      }

      const script = document.createElement("script");
      script.src = "/log/logout/logout_button.js";
      script.async = true;

      script.onload = () => {
        setTimeout(() => {
          this._state.isScriptLoaded = true;
          resolve();
        }, 200);
      };

      script.onerror = () => {
        reject(new Error("Impossibile caricare logout_button.js"));
      };

      document.head.appendChild(script);
    });
  },

  // Inizializza il widget
  _initializeWidget: function () {
    const scrollbarUpBar = document.querySelector(".fm-scrollbar-up-bar");

    if (scrollbarUpBar && window.LogoutButton) {
      const success = window.LogoutButton.init(".scrollbarUpBar", {
        checkInterval: 25000,
        showSection: true,
        showRole: true,
      });

      if (success) {
        this._state.isInitialized = true;
      }
    } else if (!scrollbarUpBar) {
      console.warn("LogoutWidgetManager: .scrollbarUpBar non trovata nel DOM");
    }
  },

  // Forza re-inizializzazione
  reinit: function () {
    console.log("🔄 Re-inizializzazione LogoutWidget...");
    this._state.isInitialized = false;
    this._state.initAttempts = 0;
    this._initializeWidget();
  },

  // Verifica stato
  getStatus: function () {
    return {
      isInitialized: this._state.isInitialized,
      isScriptLoaded: this._state.isScriptLoaded,
      initAttempts: this._state.initAttempts,
      logoutButtonAvailable: !!window.LogoutButton,
      containerExists: !!document.getElementById("logout-widget-container"),
    };
  },

  // Debug info
  debug: function () {
    const status = this.getStatus();
    console.log("🔍 LogoutWidget Status:", status);
    return status;
  },

  // Disabilita il logout widget (per debug/troubleshooting)
  disable: function () {
    console.log("🚫 Disabilitazione LogoutWidget...");
    this._state.isInitialized = false;
    this._state.isScriptLoaded = false;
    this._state.initAttempts = this._state.maxAttempts; // Impedisce nuovi tentativi

    // Rimuove il widget dal DOM se presente
    const logoutSection = document.getElementById("logout-section");
    if (logoutSection) {
      logoutSection.remove();
      console.log("🗑️ Widget rimosso dal DOM");
    }

    // Rimuove il script se presente
    const script = document.querySelector('script[src="/log/logout/logout_button.js"]');
    if (script) {
      script.remove();
      console.log("🗑️ Script rimosso");
    }

    this._state.isInitialized = false;
    this._state.isDisabled = false;
  },
};

window.FM = window.FM || {};
window.FM.LogoutWidgetManager = LogoutWidgetManager;
window.LogoutWidgetManager    = LogoutWidgetManager;
