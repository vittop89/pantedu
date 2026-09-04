/**
 * G23.fix4 — Single source of truth per LOAD/SAVE di TUTTI i field di editor
 * (quesito, giustificazione, soluzione, intro gruppo, celle RM, titolo).
 *
 * Risolve l'inconsistenza dove `intro` (gruppo "fm-testo") veniva caricato via
 * `textContent` (strippa HTML/nested OL) e salvato come stringa raw —
 * causando perdita strutturale nested list ad ogni round-trip.
 *
 * Tutti i field rich (block-content) devono usare:
 *   LOAD:  loadFieldHtml(el)           → string HTML pulita (per contenteditable)
 *   SAVE:  captureFieldBlocks(ta)      → array di blocks (contract schema)
 *
 * Field plain (title only):
 *   LOAD:  loadFieldText(el)           → plain text string
 *   SAVE:  captureFieldText(ta)        → plain text string
 *
 * Inietta le 4 dipendenze in `init({ ... })` per disaccoppiare dal modulo
 * monolitico checkin-handlers.js. Tutte già esistenti e testate.
 */

let _deps = null;

/** Inietta le primitive dal modulo principale (chiamato una volta al boot). */
export function init(deps) {
    _deps = deps;
}

/** Estrae HTML "edit-ready" da un container `.fm-collection/.fm-sol/.fm-giustsol/.fm-testo`.
 *  Preserva: nested OL/UL, fm-text/fm-latex (replaced con data-raw), TikZ,
 *  GeoGebra, inline format (b/i/u). Strippa: .fm-dsa-li-num, .fm-dsa-li-content
 *  wrapper, .fm-dsa-li-buttons, .fm-sol-label, .giustifica.
 *
 *  G23.fix16 — `.giustifica` strappato (revert fix13). La giustifica è ora
 *  un FIELD SEPARATO nell'editor gruppo (sezione dedicata), non inline nel
 *  testo. Schema contract: `group.giustifica` string.
 *
 *  Mirror di `_extractRawWithoutLabel`/_extractRawWithTikz`.
 */
export function loadFieldHtml(el, opts = {}) {
    if (!el) return "";
    const clone = el.cloneNode(true);
    // Rimuovi label visivi che non sono parte del source.
    clone.querySelectorAll(".fm-sol-label, .fm-giustifica").forEach((s) => s.remove());
    if (!_deps?.extractRawWithTikz) {
        return clone.innerHTML.trim();
    }
    return _deps.extractRawWithTikz(clone);
}

/** G23.fix16 — Estrai SOLO il testo della giustifica (span.giustifica) da
 *  un container `.fm-testo > div`. Usato dal group editor per popolare il
 *  field dedicato. Ritorna stringa pura, no markup. */
export function loadGiustificaText(el) {
    if (!el) return "";
    const giust = el.querySelector(".fm-giustifica");
    return giust ? (giust.textContent || "").trim() : "";
}

/** Plain text loader (per titoli, label brevi senza markup richiesto). */
export function loadFieldText(el) {
    if (!el) return "";
    const clone = el.cloneNode(true);
    clone.querySelectorAll(".fm-sol-label, .fm-giustifica").forEach((s) => s.remove());
    return (clone.textContent || "").trim();
}

/** Cattura un field contenteditable come array di blocks (contract schema).
 *  Wrap delegato a `_buildBlocksFromTextarea` esistente (parser autoritativo
 *  per markers TikZ/GeoGebra + nested OL + inline format). */
export function captureFieldBlocks(ta) {
    if (!ta) return [];
    if (!_deps?.buildBlocksFromTextarea) {
        // Fallback: ritorna 1 text block
        return [{ type: "text", content: (ta.value || "").trim() }];
    }
    return _deps.buildBlocksFromTextarea(ta);
}

/** Cattura field come plain text (titoli). */
export function captureFieldText(ta) {
    if (!ta) return "";
    // Per contenteditable, preferiamo textContent (no HTML)
    if (typeof ta.value === "string") {
        // Strip tag HTML residui (per safety)
        const tmp = document.createElement("div");
        tmp.innerHTML = ta.value || "";
        return (tmp.textContent || "").trim();
    }
    return "";
}

/** Render array di blocks → HTML string (per applyEditsToDom dopo save).
 *  Delega a `_toHtml` esistente. */
export function blocksToHtml(blocks) {
    if (typeof blocks === "string") return blocks; // legacy compat
    if (!_deps?.toHtml) return Array.isArray(blocks) ? "" : String(blocks || "");
    return _deps.toHtml(blocks);
}
