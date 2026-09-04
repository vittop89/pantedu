/**
 * G24.faseC-final — Sync scroll del preview a quello del textarea.
 *
 * Approccio combinato:
 *   1. Quando il textarea scrolla (auto-scroll su typing o scroll manuale),
 *      la preview segue proporzionalmente: ratio = ta.scrollTop / scrollable.
 *      Vantaggio: il textarea sa dove visualizzare il cursore (auto-scroll
 *      del browser), preview lo replica.
 *   2. Caret-based ratio come fallback (per casi dove ta non scrolla, es.
 *      tutto dentro viewport).
 *   3. Edge-snap: se caret a fine testo → preview a fine; se a inizio → top.
 *
 * Pure DOM math, no deps.
 */
export function syncPreviewScroll(ta, pv) {
    if (!ta || !pv) return;
    const taScrollable = ta.scrollHeight - ta.clientHeight;
    const pvScrollable = pv.scrollHeight - pv.clientHeight;
    if (pvScrollable <= 0) return;
    const caret = ta.selectionStart || 0;
    const total = (ta.value || "").length;
    // Edge cases: caret molto vicino a inizio/fine → snap.
    if (total > 0) {
        const caretRatio = caret / total;
        if (caretRatio >= 0.95) { pv.scrollTop = pvScrollable; return; }
        if (caretRatio <= 0.05) { pv.scrollTop = 0; return; }
    }
    // Primary: ratio basato su scroll del textarea (segue il cursore visivo).
    let ratio;
    if (taScrollable > 5) {
        ratio = ta.scrollTop / taScrollable;
    } else if (total > 0) {
        // Textarea tutto visibile: usa caret position
        ratio = caret / total;
    } else {
        return;
    }
    pv.scrollTop = Math.max(0, Math.min(1, ratio)) * pvScrollable;
}
