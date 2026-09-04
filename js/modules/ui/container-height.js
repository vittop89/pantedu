/**
 * ContainerHeightManager — estratto da functions-mod.js (Phase 9e).
 * G26.phase5.3 — migrato a vanilla JS (no jQuery).
 *
 * Le funzioni accettano sia Element che jQuery wrapper (transition compat
 * con caller legacy).
 */
import { asElement, isVisible } from "../core/dom-utils.js";

/** outerHeight(true): height + padding (incluso da boundingRect) + margin top/bottom. */
function outerHeightWithMargin(el) {
    if (!el) return 0;
    const rect = el.getBoundingClientRect();
    const cs = getComputedStyle(el);
    const mt = parseFloat(cs.marginTop) || 0;
    const mb = parseFloat(cs.marginBottom) || 0;
    return rect.height + mt + mb;
}

export const ContainerHeightManager = {
    _activeObservers: new Map(),

    updateHeight: function (wrapper, elementTikzGroups, elementTracciaGroups, elementBackupList, forceUpdate = false) {
        const wrapperEl = asElement(wrapper);
        if (!wrapperEl) return;

        const container = wrapperEl.closest(".fm-sol, .fm-collection, .fm-testo, .fm-giustsol");
        if (!container) return;

        const tikzEl = asElement(elementTikzGroups);
        const tracciaEl = asElement(elementTracciaGroups);
        const backupEl = asElement(elementBackupList);

        let visibleMenu = null;
        let menuName = "";

        if (tikzEl && isVisible(tikzEl)) {
            visibleMenu = tikzEl;
            menuName = "TikZ";
        } else if (tracciaEl && isVisible(tracciaEl)) {
            visibleMenu = tracciaEl;
            menuName = "Traccia";
        } else if (backupEl && isVisible(backupEl)) {
            visibleMenu = backupEl;
            menuName = "Backup";
        }

        if (!visibleMenu) return;

        setTimeout(() => {
            // Forza un reflow
            void visibleMenu.offsetHeight;

            const allBtns = Array.from(visibleMenu.querySelectorAll(".fm-group-btn"));
            const btns = allBtns.filter(isVisible);
            const activeBtn = btns.find((b) => b.classList.contains("active")) || null;
            const activeOptions = Array.from(visibleMenu.querySelectorAll(".fm-group-options.active"))
                .find(isVisible) || null;

            // Somma altezze pulsanti fino all'active (escluso)
            let sumToActive = 0;
            let foundActive = false;
            for (const btn of btns) {
                if (activeBtn && btn === activeBtn) {
                    foundActive = true;
                    break;
                }
                sumToActive += outerHeightWithMargin(btn);
            }
            if (activeOptions && foundActive) {
                sumToActive += outerHeightWithMargin(activeOptions);
            }

            // Somma altezze di tutti i pulsanti
            let sumAllBtns = 0;
            for (const btn of btns) {
                sumAllBtns += outerHeightWithMargin(btn);
            }

            let totalMenuHeight = Math.max(sumToActive, sumAllBtns);
            if (totalMenuHeight === 0) {
                totalMenuHeight = outerHeightWithMargin(visibleMenu);
            }

            visibleMenu.style.height = `${totalMenuHeight}px`;

            console.log("📦 Calcolo altezza menu:", {
                sumToActive, sumAllBtns, totalMenuHeight, menuName,
            });

            console.log(`🔍 Debug ${menuName} Menu:`, {
                visible: isVisible(visibleMenu),
                height: totalMenuHeight,
                containerFound: !!container,
                forceUpdate,
            });

            if (totalMenuHeight > 0) {
                const cs = getComputedStyle(container);
                const currentMinHeight = parseInt(cs.minHeight) || 0;
                const currentHeight = container.getBoundingClientRect().height;
                const newHeight = totalMenuHeight + 50;

                console.log("📐 Altezze:", {
                    current: currentHeight,
                    currentMinHeight,
                    menu: totalMenuHeight,
                    new: newHeight,
                    willUpdate: forceUpdate || newHeight > currentMinHeight,
                });

                if (forceUpdate || newHeight > currentMinHeight) {
                    container.style.minHeight = `${newHeight}px`;
                    console.log("✅ Container aggiornato a:", `${newHeight}px`);
                }
            }
        }, 100);
    },

    resetHeight: function (wrapper) {
        const wrapperEl = asElement(wrapper);
        if (!wrapperEl) return;
        const container = wrapperEl.closest(".fm-sol, .fm-collection, .fm-testo, .fm-giustsol");
        if (container) {
            console.log("🔄 Rimuovo min-height dal contenitore");
            container.style.minHeight = "";
        }
    },

    startMonitoringGroupOptions: function (wrapper, elementTikzGroups, elementTracciaGroups, elementBackupList) {
        const wrapperEl = asElement(wrapper);
        if (!wrapperEl) return;

        const wrapperId = wrapperEl.id || wrapperEl.dataset.wrapperId || `wrapper-${Date.now()}`;
        if (!wrapperEl.id && !wrapperEl.dataset.wrapperId) {
            wrapperEl.dataset.wrapperId = wrapperId;
        }

        this.stopMonitoringGroupOptions(wrapperId);

        const self = this;
        let lastHeight = 0;

        const checkGroupOptionsHeight = () => {
            const activeGroupOptions = Array.from(wrapperEl.querySelectorAll(".fm-group-options.active"))
                .find(isVisible);

            if (activeGroupOptions) {
                const currentHeight = outerHeightWithMargin(activeGroupOptions);

                if (currentHeight !== lastHeight && currentHeight > 0) {
                    console.log("📊 Cambio altezza group-options:", {
                        oldHeight: lastHeight,
                        newHeight: currentHeight,
                    });

                    lastHeight = currentHeight;
                    self.updateHeight(wrapperEl, elementTikzGroups, elementTracciaGroups, elementBackupList, true);
                }
            }
        };

        const observerConfig = {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ["class", "style"],
        };

        const observer = new MutationObserver((mutations) => {
            let shouldCheck = false;

            mutations.forEach((mutation) => {
                const target = mutation.target;
                if (target.classList && (target.classList.contains("fm-group-options") || target.closest(".fm-group-options") || (mutation.type === "attributes" && target.tagName === "SELECT"))) {
                    shouldCheck = true;
                }
            });

            if (shouldCheck) {
                setTimeout(() => checkGroupOptionsHeight(), 150);
            }
        });

        const tikzEl = asElement(elementTikzGroups);
        const tracciaEl = asElement(elementTracciaGroups);
        if (tikzEl) observer.observe(tikzEl, observerConfig);
        if (tracciaEl) observer.observe(tracciaEl, observerConfig);

        // Event delegation: change su <select> dentro .group-options
        const changeHandler = (e) => {
            if (e.target.tagName === "SELECT" && e.target.closest(".fm-group-options")) {
                setTimeout(() => checkGroupOptionsHeight(), 150);
            }
        };
        wrapperEl.addEventListener("change", changeHandler);

        this._activeObservers.set(wrapperId, {
            observer,
            wrapperEl,
            changeHandler,
        });

        console.log("🎯 Monitoring group-options per wrapper:", wrapperId);
    },

    stopMonitoringGroupOptions: function (wrapperId) {
        if (this._activeObservers.has(wrapperId)) {
            const { observer, wrapperEl, changeHandler } = this._activeObservers.get(wrapperId);
            observer.disconnect();
            if (wrapperEl && changeHandler) wrapperEl.removeEventListener("change", changeHandler);
            this._activeObservers.delete(wrapperId);
            console.log("🛑 Stopped monitoring group-options per wrapper:", wrapperId);
        }
    },
};

window.FM = window.FM || {};
window.FM.ContainerHeightManager = ContainerHeightManager;
window.ContainerHeightManager    = ContainerHeightManager;
