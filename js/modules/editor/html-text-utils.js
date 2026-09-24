/**
 * G24.refactor5.step1 — Estratto da `features/checkin-handlers.js` (monolite
 * 9100+ LOC). Utility puramente sintattiche per escape HTML/TeX e check
 * inline. Nessuna dipendenza DOM/runtime: import-only.
 *
 * L'escape HTML non vive più qui (23/9/2026, revisione architetturale
 * A-21): `escHtml` ed `escapeHtml` sono quella di core/dom-utils.js, e
 * `escHtmlStrict` la usa cambiando solo la forma dell'apostrofo. Prima qui ce
 * n'erano tre, e `escHtml(0)` dava una stringa vuota mentre quella di
 * dom-utils dava «0».
 */

import { escHtml } from "../core/dom-utils.js";

/** L'escape di riferimento (core/dom-utils.js), con i due nomi storici. */
export { escHtml };
export const escapeHtml = escHtml;

/** Come escHtml, con l'apostrofo `&#039;` di htmlspecialchars in PHP
 *  (compatibilità ContractRenderer). */
export function escHtmlStrict(s) {
    return escHtml(s).replace(/&#39;/g, "&#039;");
}

/** Alias per attribute escape (stessa policy di escHtmlStrict). */
export const escAttr = escHtmlStrict;

/** Replace newline → <br> (preserva text content escapato). */
export function nl2br(s) {
    return String(s).replace(/\n/g, "<br>");
}

/** Detect inline HTML markup nel testo (per fast-path rendering). */
export function containsInlineHtml(s) {
    return /<(b|strong|i|em|u|s|sub|sup|a|span)\b[^>]*>/i.test(s);
}

/** TeX-safe escape per JS string injectata in source TeX (cells, badges, ecc).
 *  Maps caratteri TeX-speciali a equivalenti escape sequence. */
export function escTexJs(s) {
    return String(s).replace(/[\\{}_$%&#~^]/g, (c) => {
        const map = { "\\": "\\textbackslash{}", "&": "\\&", "#": "\\#", "$": "\\$",
                      "%": "\\%", "_": "\\_", "{": "\\{", "}": "\\}",
                      "~": "\\textasciitilde{}", "^": "\\textasciicircum{}" };
        return map[c] || c;
    });
}
