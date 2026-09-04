/**
 * VerGenerationOverlay — estratto da functions-mod.js (Phase 9e).
 * G26.phase4.5 — migrato a vanilla JS (no jQuery).
 */

/* ADR-023 Fase 2: CSS spostato in css/modules/_ver-generation-overlay.css */

export const VerGenerationOverlay = {
    _aborted: false,
    _overlayElement: null,
    _logContainer: null,
    _progressBar: null,
    _suppressNotifications: false,
    _currentStep: 0,
    _totalSteps: 0,

    show: function (totalSteps) {
        this._aborted = false;
        this._currentStep = 0;
        this._totalSteps = totalSteps || 8;
        this._suppressNotifications = true;

        // Rimuovi overlay precedente se presente
        document.getElementById("ver-generation-overlay")?.remove();

        const overlayHtml = `
      <div id="ver-generation-overlay">
        <div id="ver-generation-panel">
          <div class="vgo-header">
            <h3 class="vgo-title">🔄 Generazione Verifiche</h3>
            <div id="ver-gen-subtitle" class="vgo-subtitle">Preparazione...</div>
          </div>
          <div class="vgo-progress-section">
            <div class="vgo-progress-track">
              <div id="ver-gen-progress-bar" class="vgo-progress-fill"></div>
            </div>
            <div id="ver-gen-progress-text" class="vgo-progress-text">0 / ${this._totalSteps} operazioni</div>
          </div>
          <div id="ver-gen-log" class="vgo-log"></div>
          <div class="vgo-footer">
            <button id="ver-gen-abort-btn" class="vgo-abort-btn">⏹ Interrompi Processo</button>
          </div>
        </div>
      </div>
    `;

        // ADR-023 Fase 2: CSS in css/modules/_ver-generation-overlay.css (@layer).
        document.body.insertAdjacentHTML("beforeend", overlayHtml);
        this._overlayElement = document.getElementById("ver-generation-overlay");
        this._logContainer = document.getElementById("ver-gen-log");
        this._progressBar = document.getElementById("ver-gen-progress-bar");

        // Bind abort button
        const self = this;
        const abortBtn = document.getElementById("ver-gen-abort-btn");
        if (abortBtn) {
            abortBtn.addEventListener("click", function () {
                self._aborted = true;
                self.addLog("Processo interrotto dall'utente", "warning");
                const sub = document.getElementById("ver-gen-subtitle");
                if (sub) sub.textContent = "Interruzione in corso... i file già generati rimarranno salvati.";
                this.disabled = true;
                this.textContent = "⏳ Interruzione...";
            });
        }

        // Blocca scroll del body
        document.body.style.overflow = "hidden";

        // Previeni click-through (gli eventi non bubblano oltre l'overlay)
        ["click", "mousedown", "mouseup"].forEach((ev) => {
            this._overlayElement?.addEventListener(ev, (e) => e.stopPropagation());
        });
    },

    hide: function (delay) {
        const self = this;
        const d = delay !== undefined ? delay : 0;
        setTimeout(function () {
            if (self._overlayElement) {
                self._overlayElement.style.opacity = "0";
                setTimeout(function () {
                    self._overlayElement?.remove();
                    self._overlayElement = null;
                    self._logContainer = null;
                    self._progressBar = null;
                    document.body.style.overflow = "";

                    self._suppressNotifications = false;
                }, 350);
            }
        }, d);
    },

    updateProgress: function (step, message) {
        this._currentStep = step;
        const pct = Math.round((step / this._totalSteps) * 100);

        if (this._progressBar) {
            this._progressBar.style.width = `${pct}%`;
        }
        const progText = document.getElementById("ver-gen-progress-text");
        if (progText) progText.textContent = `${step} / ${this._totalSteps} operazioni (${pct}%)`;
        const sub = document.getElementById("ver-gen-subtitle");
        if (sub) sub.textContent = message;
    },

    addLog: function (message, type) {
        if (!this._logContainer) return;
        type = type || "info";

        const icons = { info: "ℹ️", success: "✅", warning: "⚠️", error: "❌" };
        const time = new Date().toLocaleTimeString("it-IT", {
            hour: "2-digit", minute: "2-digit", second: "2-digit",
        });

        const lineHtml = `<div class="vgo-log-entry vgo-log-${type}"><span class="vgo-log-time">[${time}]</span> ${icons[type] || ""} ${message}</div>`;
        this._logContainer.insertAdjacentHTML("beforeend", lineHtml);
        this._logContainer.scrollTop = this._logContainer.scrollHeight;
    },

    isAborted: function () {
        return this._aborted;
    },

    markCompleted: function (fileCount) {
        const msg = fileCount
            ? `Completato! ${fileCount} file generati con successo.`
            : "Processo completato!";
        const sub = document.getElementById("ver-gen-subtitle");
        if (sub) sub.textContent = msg;
        this.updateProgress(this._totalSteps, msg);

        // Ferma animazione shimmer sulla barra
        if (this._progressBar) {
            this._progressBar.style.animation = "none";
            this._progressBar.style.background = "#a6e3a1";
        }

        this._rewireAbortBtn("✅ Chiudi", "vgo-complete-btn");
    },

    markAborted: function () {
        const sub = document.getElementById("ver-gen-subtitle");
        if (sub) sub.textContent = "Processo interrotto. I file già generati sono stati salvati.";
        if (this._progressBar) {
            this._progressBar.style.animation = "none";
            this._progressBar.style.background = "#fab387";
        }
        this._rewireAbortBtn("✖ Chiudi", "vgo-aborted-btn");
    },

    markError: function (errorMsg) {
        const sub = document.getElementById("ver-gen-subtitle");
        if (sub) sub.textContent = `Errore: ${errorMsg}`;
        if (this._progressBar) {
            this._progressBar.style.animation = "none";
            this._progressBar.style.background = "#f38ba8";
        }
        this._rewireAbortBtn("✖ Chiudi", "vgo-aborted-btn");
    },

    /** Sostituisce il click handler del bottone abort con un close-handler.
     *  G26 — rimpiazza il bottone con un clone per "rimuovere" tutti i
     *  listener precedenti (equivale a $.off("click")). */
    _rewireAbortBtn: function (label, extraClass) {
        const oldBtn = document.getElementById("ver-gen-abort-btn");
        if (!oldBtn) return;
        const newBtn = oldBtn.cloneNode(false); // shallow clone (no listeners)
        newBtn.textContent = label;
        if (extraClass) newBtn.classList.add(extraClass);
        newBtn.disabled = false;
        newBtn.addEventListener("click", () => VerGenerationOverlay.hide());
        oldBtn.replaceWith(newBtn);
    },
};

window.FM = window.FM || {};
window.FM.VerGenerationOverlay = VerGenerationOverlay;
window.VerGenerationOverlay    = VerGenerationOverlay;
