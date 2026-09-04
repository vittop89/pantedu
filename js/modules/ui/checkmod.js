/**
 * CheckmodManager — estratto da functions-mod.js:17958 (Phase 9a).
 * G26.phase5.3 — migrato a vanilla JS (no jQuery).
 *
 * Inserisce checkbox "Giustifica" e "Soluzioni" nei .fm-collapsible
 * delle verifiche che non ne hanno già.
 */
import { asElement } from "../core/dom-utils.js";

const GIUST_HTML = `
          <div class="fm-wrapcheckgiust">
            <input type="checkbox" class="checkbox checkgiust" checked>
            <label class="labgiust">Giustifica</label>
          </div>
        `;

const SOL_HTML = `
          <div class="fm-wrapchecksol">
            <input type="checkbox" class="checkbox checksol" checked>
            <label class="labsol">Soluzioni</label>
          </div>
        `;

export const CheckmodManager = {
    /**
     * @param {Element|object|null} container - root in cui cercare .fm-collapsible (default = document)
     * @param {Element|object|null} editEser - elemento .editEser opzionale da clonare in ogni checkmod
     */
    insertCheckmodInCollapsibles(container, editEser) {
        // Phase 25.Q.12 — skip injection se utente non ha edit scope
        // (student/guest). Defense-in-depth: HTML non emesso server-side
        // da ContractRenderer.renderCheckmod, e qui anche client-side
        // gating per partial reload / SPA navigation.
        if (document.body?.dataset?.fmCanEdit === "0") return;
        const containerEl = asElement(container);
        const editEserEl = asElement(editEser);

        const root = containerEl || document;
        const collapsibles = root.querySelectorAll(".fm-collapsible");

        collapsibles.forEach((collapsible) => {
            const existing = collapsible.querySelector(".fm-checkmod");

            if (existing) {
                if (editEserEl) {
                    if (!existing.querySelector(".fm-edit-eser")) {
                        existing.appendChild(editEserEl.cloneNode(true));
                    }
                }
                return;
            }

            const wrapGiust = collapsible.querySelector(".fm-wrapcheckgiust");
            const wrapSol = collapsible.querySelector(".fm-wrapchecksol");

            const checkmod = document.createElement("div");
            checkmod.className = "fm-checkmod";

            if (wrapGiust) {
                checkmod.appendChild(wrapGiust);
            } else {
                checkmod.insertAdjacentHTML("beforeend", GIUST_HTML);
            }

            if (wrapSol) {
                checkmod.appendChild(wrapSol);
            } else {
                checkmod.insertAdjacentHTML("beforeend", SOL_HTML);
            }

            if (editEserEl) checkmod.appendChild(editEserEl.cloneNode(true));
            collapsible.appendChild(checkmod);
        });
    },
};

window.FM = window.FM || {};
window.FM.CheckmodManager = CheckmodManager;
window.CheckmodManager    = CheckmodManager;
