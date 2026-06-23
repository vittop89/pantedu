/**
 * CookieConsentManager — estratto da script.js:257 (Phase 9d).
 * Gestione consenso cookies (functional toggle, modal banner).
 * Selectors: prefix `fm-modal` / `fm-cookie` (vedi views/partials/modals.php).
 *
 * G26.phase4 — Migrato a vanilla JS (no più jQuery).
 * fadeIn/Out (jQuery animations) → CSS transitions con opacity.
 */

import { fetchCsrf } from "./dom-utils.js";

// Helper: fade animation senza jQuery. Usa opacity transition + display
// toggle. Idempotente se chiamato su un elemento già visible/hidden.
function fadeIn(el, duration = 200) {
    if (!el) return;
    el.hidden = false;                       // attr [hidden] off (CSS .fm-modal:not([hidden]) matches)
    el.classList.remove("fm-d-none");        // utility .fm-d-none (layer utilities) vince sul layer components — toglierla
    el.style.transition = `opacity ${duration}ms`;
    el.style.opacity = "0";
    el.style.display = "";  // restore default display
    // force reflow
    void el.offsetWidth;
    el.style.opacity = "1";
}
function fadeOut(el, duration = 200) {
    if (!el) return;
    el.style.transition = `opacity ${duration}ms`;
    el.style.opacity = "0";
    setTimeout(() => {
        if (el.style.opacity === "0") {
            el.style.display = "none";
            el.hidden = true;                // [hidden] on per coerenza CSS
            el.classList.add("fm-d-none");   // re-apply utility (idempotente: doppia difesa)
        }
    }, duration);
}

export const CookieConsentManager = {
    elements: {},

    init: function () {
        this.elements = {
            modal:             document.getElementById("fm-cookie-modal"),
            overlay:           document.getElementById("fm-modal-overlay"),
            warningMessage:    document.getElementById("cookie-warning-message"),
            iframeWarning:     document.getElementById("iframe-specific-warning"),
            functionalSwitch:  document.getElementById("fm-cookie-functional"),
            analyticsSwitch:   document.getElementById("fm-cookie-analytics"),
            advertisingSwitch: document.getElementById("fm-cookie-advertising"),
        };
        this._bindEvents();
        this._bindModalEvents();

        const currentConsent = this.getConsent();
        if (currentConsent) {
            this.applyConsent(currentConsent);
            this._updateSwitches(currentConsent);
        } else {
            this._updateSwitches(null);
            this._openModal();
        }
    },

    _bindEvents: function () {
        const overlay = this.elements.overlay;
        if (overlay) overlay.addEventListener("click", () => this._closeModal());

        // Selector multi via querySelectorAll
        document.querySelectorAll(".fm-modal-close, #close-cookie-modal-btn")
            .forEach((el) => el.addEventListener("click", () => this._closeModal()));

        const managePref = document.getElementById("manage-cookie-preferences");
        if (managePref) {
            managePref.addEventListener("click", (e) => {
                e.preventDefault();
                this._updateSwitches(this.getConsent());
                this._openModal();
            });
        }

        const acceptAll = document.getElementById("accept-all-cookies-modal");
        if (acceptAll) acceptAll.addEventListener("click", () =>
            this.saveConsent({ functional: true, analytics: true, advertising: true }));
        const rejectAll = document.getElementById("reject-all-cookies-modal");
        if (rejectAll) rejectAll.addEventListener("click", () =>
            this.saveConsent({ functional: false, analytics: false, advertising: false }));
        const confirmBtn = document.getElementById("confirm-choices-cookies-modal");
        if (confirmBtn) confirmBtn.addEventListener("click", () => {
            this.saveConsent({
                functional:  !!this.elements.functionalSwitch?.checked,
                analytics:   !!this.elements.analyticsSwitch?.checked,
                advertising: !!this.elements.advertisingSwitch?.checked,
            });
        });

        const resetBtn = document.getElementById("reset-consent-test");
        if (resetBtn) resetBtn.addEventListener("click", () => {
            localStorage.removeItem(Config.COOKIE_CONSENT_KEY);
            alert("Consenso resettato. Ricarica la pagina.");
            window.location.reload();
        });
    },

    _bindModalEvents: function () {
        // Gestione modali generici
        const modalOverlay = document.getElementById("fm-modal-overlay");

        // --- Funzioni Generali per i Modali ---
        const openModal = (modalElement) => {
            if (!modalElement) return;
            fadeIn(modalOverlay);
            fadeIn(modalElement);
            document.body.style.overflow = "hidden"; // Blocca lo scroll della pagina
        };

        const closeModal = (modalElement) => {
            if (!modalElement) return;
            fadeOut(modalElement);
            fadeOut(modalOverlay);
            document.body.style.overflow = "auto"; // Ripristina lo scroll
        };

        // Chiudi modale cliccando sull'overlay
        if (modalOverlay) {
            modalOverlay.addEventListener("click", () => {
                document.querySelectorAll(".fm-modal").forEach((m) => {
                    if (m.offsetParent !== null) closeModal(m); // visible check
                });
            });
        }

        // Chiudi modale cliccando sui bottoni "Chiudi" generici (event delegation
        // per gestire bottoni aggiunti dinamicamente).
        document.querySelectorAll(".fm-modal-close").forEach((btn) => {
            btn.addEventListener("click", function () {
                const targetModalId = this.dataset.targetModal
                    || this.closest(".fm-modal")?.id;
                if (targetModalId) {
                    closeModal(document.getElementById(targetModalId));
                }
            });
        });

        // Gestione modale License
        const licenseLink = document.getElementById("fm-license-section");
        if (licenseLink) {
            licenseLink.addEventListener("click", (e) => {
                e.preventDefault();
                const licenseModal = document.getElementById("fm-license-modal");
                if (licenseModal) openModal(licenseModal);
                else console.error("Elemento modale #fm-license-modal non trovato.");
            });
        }

        // Gestione modale Author
        const authorBtn = document.getElementById("open-author-modal");
        if (authorBtn) {
            authorBtn.addEventListener("click", (e) => {
                e.preventDefault();
                const authorModal = document.getElementById("fm-author-modal");
                if (authorModal) openModal(authorModal);
                else console.error("Elemento modale #fm-author-modal non trovato.");
            });
        }
    },

    _openModal: function () {
        fadeIn(this.elements.overlay);
        fadeIn(this.elements.modal);
        document.body.style.overflow = "hidden";
    },

    _closeModal: function () {
        fadeOut(this.elements.modal);
        fadeOut(this.elements.overlay);
        document.body.style.overflow = "auto";
        // Phase 15 — se chiuso senza aver salvato consent esplicito, salva
        // default "necessary-only" così il modal non si riapre a ogni navigazione.
        if (!this.getConsent()) {
            const defaults = {
                functional: false, analytics: false, advertising: false,
                timestamp: new Date().toISOString(),
                closed_default: true,
            };
            localStorage.setItem(Config.COOKIE_CONSENT_KEY, JSON.stringify(defaults));
            this.applyConsent(defaults);
        }
    },

    getConsent: function () {
        const consent = localStorage.getItem(Config.COOKIE_CONSENT_KEY);
        return consent ? JSON.parse(consent) : null;
    },

    saveConsent: function (consentChoices) {
        const consentData = { ...consentChoices, timestamp: new Date().toISOString() };
        localStorage.setItem(Config.COOKIE_CONSENT_KEY, JSON.stringify(consentData));
        this.applyConsent(consentData);
        this._closeModal();

        // Phase 25.C11 — sync backend per utenti autenticati.
        this._syncBackendIfAuthenticated(consentChoices).catch(() => {
            /* silent: sync ottimistico, no UX impact */
        });
    },

    /**
     * Phase 25.C11 — sync con /me/consents API se l'utente è loggato.
     */
    _syncBackendIfAuthenticated: async function (choices) {
        if (!window.FM?.user?.username) return;

        const csrf = await fetchCsrf();
        if (!csrf) return;

        const mapping = {
            analytics: "analytics",
            advertising: "marketing",
        };

        for (const [cookieTier, consentType] of Object.entries(mapping)) {
            const granted = !!choices[cookieTier];
            const endpoint = granted ? "/me/consents/grant" : "/me/consents/revoke";
            try {
                await fetch(endpoint, {
                    method: "POST",
                    credentials: "same-origin",
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: new URLSearchParams({ _csrf: csrf, type: consentType }).toString(),
                });
            } catch (_) {
                // Best-effort: continue su altri consent types
            }
        }
    },

    applyConsent: function (consent) {
        const warningMessage = this.elements.warningMessage;
        const iframeWarning = this.elements.iframeWarning;
        if (warningMessage) warningMessage.style.display = "none";
        const iconDangerHtml = '<div class="icon danger"></div>';

        const myframe = document.getElementById("myframe");
        const frames = document.querySelectorAll(".frame");

        if (!consent || !consent.functional) {
            if (iframeWarning) {
                iframeWarning.innerHTML = `${iconDangerHtml}IL CONTENUTO INTERATTIVO È DISABILITATO. È RICHIESTO IL CONSENSO AI COOKIE FUNZIONALI.`;
                iframeWarning.style.display = "";
            }
            if (myframe) {
                myframe.setAttribute("src", "about:blank");
                myframe.style.display = "none";
            }
            frames.forEach((f) => { f.style.height = "100%"; });
        } else {
            if (iframeWarning) iframeWarning.style.display = "none";
            if (myframe) myframe.style.display = "";
            frames.forEach((f) => { f.style.height = "auto"; });
        }
    },

    _updateSwitches: function (consent) {
        const fSwitch = this.elements.functionalSwitch;
        const aSwitch = this.elements.analyticsSwitch;
        const advSwitch = this.elements.advertisingSwitch;
        if (fSwitch) fSwitch.checked = consent ? consent.functional : true;
        if (aSwitch) aSwitch.checked = consent ? consent.analytics : true;
        if (advSwitch) advSwitch.checked = consent ? consent.advertising : false;
    },

    isFunctionalAllowed: function () {
        const consent = this.getConsent();
        return consent && consent.functional;
    },
};

window.FM = window.FM || {};
window.FM.CookieConsentManager = CookieConsentManager;
window.CookieConsentManager    = CookieConsentManager;
