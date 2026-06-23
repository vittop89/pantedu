/**
 * G24.refactor5.step1 — Estratto da `features/checkin-handlers.js` (monolite
 * 9100+ LOC). Utility puramente sintattiche per escape HTML/TeX e check
 * inline. Nessuna dipendenza DOM/runtime: import-only.
 *
 * Esisteva replica di funzioni quasi identiche (`_escHtml`, `escapeHtml`,
 * `escHtml`) in punti diversi del file — mantengo i 3 nomi differenti per
 * preservare le semantiche call-site originali (alcuni gestiscono `null`,
 * altri no). Consolidamento in unico esportato è step successivo (richiede
 * verifica call-site by call-site).
 */

/** HTML escape "scuola": amp/lt/gt/quote/apostrofo. Coerce a stringa. */
export function escHtml(s) {
    return String(s || "")
        .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
}

/** Variante con DomUtils fallback (legacy). Usa `s ?? ""` per null-safe. */
export function escapeHtml(s) {
    return (typeof window !== "undefined" && window.FM?.DomUtils?.escHtml)
        ? window.FM.DomUtils.escHtml(s)
        : String(s ?? "").replace(/[&<>"']/g, (c) =>
            ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
}

/** Variante con `&#039;` invece di `&#39;` (compatibilità ContractRenderer). */
export function escHtmlStrict(s) {
    return String(s)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
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
