/**
 * G24.refactor5.step9 — Estratto da `features/checkin-handlers.js` (monolite
 * 8300+ LOC). Popup preview flottante al focus su RM cell textarea.
 *
 * Uso `position:fixed` (viewport-relative, no scrollX/Y). Auto-position:
 * preferisce SOPRA il textarea, fallback DESTRA, fallback SOTTO.
 *
 * Styling gestito dal CSS `.fm-cell-popup-preview` (dark mode override).
 *
 * Dipendenze:
 *   - MathJax (`window.MathJax`): typeset LaTeX inline
 *   - tikz-render-client: render TikZ scripts in popup
 *
 * Disattivabile via `localStorage["fmv.popupPreview"] = "0"`.
 */

import { renderAll as tikzRenderAll } from "./tikz-render-client.js";

const POPUP_ID = "fm-cell-popup-preview";
const POP_W = 420;
const POP_H = 260;
const GAP   = 8;

/** Mostra popup preview accanto al textarea (auto-position).
 *  No-op se utente ha disattivato via localStorage. */
export function showCellPopupPreview(ta) {
    if (typeof localStorage !== "undefined" && localStorage.getItem("fmv.popupPreview") === "0") return;
    let popup = document.getElementById(POPUP_ID);
    if (!popup) {
        popup = document.createElement("div");
        popup.id = POPUP_ID;
        popup.className = "fm-cell-popup-preview";
        document.body.appendChild(popup);
    }
    positionCellPopup(popup, ta);
    renderCellPopup(popup, ta.value);
    popup.hidden = false;
}

/** Nascondi popup (HTML5 hidden attribute, non rimuove dal DOM per riutilizzo). */
export function hideCellPopupPreview() {
    const popup = document.getElementById(POPUP_ID);
    if (popup) popup.hidden = true;
}

/** Riaggiorna posizione + contenuto se popup visibile. No-op altrimenti. */
export function updateCellPopupPreview(ta) {
    const popup = document.getElementById(POPUP_ID);
    if (!popup || popup.hidden) return;
    positionCellPopup(popup, ta);
    renderCellPopup(popup, ta.value);
}

/** Auto-position rispetto al `ta`:
 *  - Preferisci SOPRA se `rect.top > POP_H + GAP`
 *  - Altrimenti DESTRA se c'è larghezza
 *  - Altrimenti SOTTO (fallback)
 *  Clamp finale: min 8px da bordo viewport. */
export function positionCellPopup(popup, ta) {
    // position:fixed → uso viewport coords direttamente (no scrollX/scrollY).
    const rect = ta.getBoundingClientRect();
    let top, left;
    // Preferisci SOPRA il textarea se c'è spazio
    if (rect.top > POP_H + GAP) {
        top  = rect.top - POP_H - GAP;
        left = Math.min(rect.left, window.innerWidth - POP_W - 8);
    } else if (rect.right + POP_W + GAP < window.innerWidth) {
        // A destra
        top  = Math.max(8, Math.min(rect.top, window.innerHeight - POP_H - 8));
        left = rect.right + GAP;
    } else {
        // Sotto (fallback)
        top  = rect.bottom + GAP;
        left = Math.min(rect.left, window.innerWidth - POP_W - 8);
    }
    popup.style.top = `${Math.max(8, top)}px`;
    popup.style.left = `${Math.max(8, left)}px`;
}

/** Inietta `value` nel popup, typeset MathJax, render TikZ scripts.
 *  - Empty value → placeholder italic.
 *  - MathJax mancante: skip silent.
 *  - TikZ errori: catch silent (logged in tikzRenderAll). */
export async function renderCellPopup(popup, value) {
    popup.innerHTML = value || `<em class="fm-cell-popup-empty">preview vuota</em>`;
    if (typeof window !== "undefined" && window.MathJax?.typesetPromise) {
        try { await window.MathJax.typesetPromise([popup]); } catch (_) { /* ignore */ }
    }
    // TikZ scripts inline
    const scripts = popup.querySelectorAll('script[type="text/tikz"]');
    if (scripts.length) {
        try {
            await tikzRenderAll(popup, { defaultScope: "public" });
        } catch (_) { /* ignore */ }
    }
}
