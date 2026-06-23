/**
 * G24.refactor5.step5 — Estratto da `features/checkin-handlers.js` (monolite
 * 8700+ LOC). Find & Replace dialog VS Code-like per editor inline.
 *
 * Features:
 *   - regex flag (.*)  case-sensitive (Aa)  whole-word (Ab)  in-selection (⊞)
 *   - prev/next navigation (F3 / Shift+F3 / Enter / Shift+Enter)
 *   - replace one / replace all (Ctrl+H / Ctrl+Alt+Enter)
 *   - highlight TUTTE le occorrenze via <mark class="fm-fr-hit">
 *
 * G24.faseE — Lazy-load via dynamic import. Chunk separato.
 */

import { setRangeAtOffsets } from "./caret-utils.js";

/** Toast fallback locale: usa FM.ToastManager se presente, no-op altrimenti.
 *  Permette al modulo di essere standalone senza dipendere dal monolite. */
function localToast(msg, kind = "warn") {
    const tm = (typeof window !== "undefined") ? window.FM?.ToastManager : null;
    if (tm?.show) {
        const map = { ok: ["success", "OK"], warn: ["warning", "Attenzione"], err: ["error", "Errore"], info: ["info", "Info"] };
        const [type, title] = map[kind] || ["info", "Info"];
        try { tm.show(type, title, String(msg)); } catch { /* ignore */ }
    }
}

/**
 * @param {Element|Object} panel
 * @param {Object} [opts] {initialQuery, initialReplace}
 */
export function openFindReplaceDialog(panel, opts = {}) {
    const ta = panel?._focusedTextarea || panel?.querySelector?.(".fm-editor-field");
    if (!ta) { localToast("Nessun campo in focus", "warn"); return; }

    document.getElementById("fm-findreplace-dialog")?.remove();
    const dlg = document.createElement("div");
    dlg.id = "fm-findreplace-dialog";
    dlg.className = "fm-fr-dialog";
    dlg.innerHTML = `
      <div class="fm-fr-header">
        <span class="fm-fr-title">🔍 Trova e sostituisci</span>
        <button class="fm-fr-close" type="button" title="Chiudi (Esc)">×</button>
      </div>
      <div class="fm-fr-body">
        <div class="fm-fr-row">
          <input class="fm-fr-find" type="text" placeholder="Trova" autocomplete="off">
          <button class="fm-fr-opt" data-opt="case" type="button" title="Distingui maiuscole/minuscole (Alt+C)">Aa</button>
          <button class="fm-fr-opt" data-opt="word" type="button" title="Solo parole intere (Alt+W)">Ab</button>
          <button class="fm-fr-opt" data-opt="regex" type="button" title="Espressione regolare (Alt+R)">.*</button>
          <button class="fm-fr-opt" data-opt="insel" type="button" title="Cerca solo nella selezione (Alt+L)">⊞</button>
        </div>
        <div class="fm-fr-row">
          <input class="fm-fr-replace" type="text" placeholder="Sostituisci" autocomplete="off">
          <button class="fm-fr-btn fm-fr-prev" type="button" title="Match precedente (Shift+Enter / Shift+F3)">↑</button>
          <button class="fm-fr-btn fm-fr-next" type="button" title="Match successivo (Enter / F3)">↓</button>
          <button class="fm-fr-btn fm-fr-replace-one" type="button" title="Sostituisci (Ctrl+H)">⤷</button>
          <button class="fm-fr-btn fm-fr-replace-all" type="button" title="Sostituisci tutto (Ctrl+Alt+Enter)">⇶</button>
        </div>
        <div class="fm-fr-status"></div>
      </div>`;
    document.body.appendChild(dlg);

    // State
    const state = {
        flags: { case: false, word: false, regex: false, insel: false },
        matches: [],
        currentIdx: -1,
        selRangeText: "",
    };

    // Capture selection PRIMA di focusare il dialog (Selection si perde)
    state.selRangeText = opts.initialQuery
        || (ta.tagName === "TEXTAREA"
            ? ta.value.slice(ta.selectionStart || 0, ta.selectionEnd || 0)
            : (window.getSelection()?.toString() || ""));

    const findInp = dlg.querySelector(".fm-fr-find");
    const replaceInp = dlg.querySelector(".fm-fr-replace");
    const status = dlg.querySelector(".fm-fr-status");
    findInp.value = state.selRangeText.includes("\n") ? "" : state.selRangeText;
    replaceInp.value = opts.initialReplace || "";

    // Helpers
    const getText = () => ta.tagName === "TEXTAREA" ? ta.value : ta.textContent;
    const setText = (newText) => {
        if (ta.tagName === "TEXTAREA") {
            ta.value = newText;
        } else {
            // Per contenteditable: re-set textContent perderebbe formattazione.
            // Strategia: operare su Range via document.execCommand("insertText") O
            // sostituire SOLO la occorrenza targettata via range.
            // Per ora: textContent (rimuove tag) — caveat noto.
            ta.textContent = newText;
        }
        ta.dispatchEvent(new Event("input", { bubbles: true }));
    };

    const compileRegex = () => {
        const q = findInp.value;
        if (!q) return null;
        let pattern = state.flags.regex ? q : q.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
        if (state.flags.word) pattern = `\\b${  pattern  }\\b`;
        const flags = `g${  state.flags.case ? "" : "i"}`;
        try { return new RegExp(pattern, flags); } catch { return null; }
    };

    const computeMatches = () => {
        state.matches = [];
        state.currentIdx = -1;
        const re = compileRegex();
        if (!re) { renderStatus(); return; }
        const text = getText();
        let m;
        while ((m = re.exec(text)) !== null) {
            if (m.index === re.lastIndex) re.lastIndex++; // avoid zero-length loop
            state.matches.push({ start: m.index, end: m.index + m[0].length, text: m[0] });
        }
        if (state.matches.length) state.currentIdx = 0;
        renderStatus();
        highlightInField();
    };

    const renderStatus = () => {
        if (state.matches.length === 0) {
            status.textContent = findInp.value ? "Nessun risultato" : "";
            status.className = `fm-fr-status${  findInp.value ? " fm-fr-no-result" : ""}`;
        } else {
            status.textContent = `${state.currentIdx + 1} di ${state.matches.length}`;
            status.className = "fm-fr-status";
        }
    };

    // Highlight wrapping: per TEXTAREA mostriamo solo count.
    // Per contenteditable: evidenziamo TUTTI i match via <mark class="fm-fr-hit">
    // (giallo background) + scroll to current.
    const highlightInField = () => {
        if (state.currentIdx < 0) {
            clearFindHighlights(ta);
            return;
        }
        const m = state.matches[state.currentIdx];
        if (ta.tagName === "TEXTAREA") {
            ta.focus();
            ta.setSelectionRange(m.start, m.end);
            return;
        }
        // contenteditable: wrap tutti i match in <mark>, current = active class
        applyFindHighlights(ta, state.matches, state.currentIdx);
    };

    const next = () => {
        if (!state.matches.length) return;
        state.currentIdx = (state.currentIdx + 1) % state.matches.length;
        renderStatus(); highlightInField();
    };
    const prev = () => {
        if (!state.matches.length) return;
        state.currentIdx = (state.currentIdx - 1 + state.matches.length) % state.matches.length;
        renderStatus(); highlightInField();
    };
    const replaceOne = () => {
        if (state.currentIdx < 0 || !state.matches.length) return;
        const m = state.matches[state.currentIdx];
        const text = getText();
        const newText = text.slice(0, m.start) + replaceInp.value + text.slice(m.end);
        setText(newText);
        computeMatches();
    };
    const replaceAll = () => {
        const re = compileRegex();
        if (!re) return;
        const text = getText();
        const newText = text.replace(re, replaceInp.value);
        const count = state.matches.length;
        setText(newText);
        status.textContent = `Sostituiti ${count}`;
        state.matches = [];
        state.currentIdx = -1;
    };

    // Wire UI
    dlg.querySelector(".fm-fr-close").addEventListener("click", () => {
        clearFindHighlights(ta);
        dlg.remove();
    });
    findInp.addEventListener("input", computeMatches);
    replaceInp.addEventListener("input", () => {/* nothing yet */});
    dlg.querySelector(".fm-fr-prev").addEventListener("click", prev);
    dlg.querySelector(".fm-fr-next").addEventListener("click", next);
    dlg.querySelector(".fm-fr-replace-one").addEventListener("click", replaceOne);
    dlg.querySelector(".fm-fr-replace-all").addEventListener("click", replaceAll);
    dlg.querySelectorAll(".fm-fr-opt").forEach((btn) => {
        btn.addEventListener("click", () => {
            const k = btn.dataset.opt;
            state.flags[k] = !state.flags[k];
            btn.classList.toggle("fm-fr-active", state.flags[k]);
            computeMatches();
        });
    });

    dlg.addEventListener("keydown", (e) => {
        if (e.key === "Escape") { dlg.remove(); return; }
        if (e.key === "Enter" && e.target === findInp) {
            e.preventDefault();
            if (e.shiftKey) prev(); else next();
            return;
        }
        if (e.key === "Enter" && e.target === replaceInp) {
            e.preventDefault();
            if (e.ctrlKey && e.altKey) replaceAll();
            else replaceOne();
            return;
        }
        // Toggle flags via Alt+key
        if (e.altKey) {
            const map = { c: "case", w: "word", r: "regex", l: "insel" };
            const k = map[e.key.toLowerCase()];
            if (k) {
                e.preventDefault();
                const btn = dlg.querySelector(`.fm-fr-opt[data-opt="${k}"]`);
                btn?.click();
            }
        }
    });

    findInp.focus();
    if (findInp.value) {
        findInp.select();
        computeMatches();
    }
}

/**
 * Wrappa ogni match in <mark class="fm-fr-hit"> dentro il contenteditable.
 * Il match "currentIdx" riceve classe extra `fm-fr-hit-active`.
 *
 * Strategy: serialize field a "plain text + offset map", apply mark.js-style
 * wrapping con TreeWalker, ricostruisce il DOM con tag preservati.
 *
 * Per evitare distruzione struttura HTML: usiamo Range API. Ogni match ha
 * (start, end) come offset textContent. Creiamo Range corrispondenti e
 * surroundContents con <mark>.
 *
 * NB: Range.surroundContents() fallisce se il range attraversa boundaries
 * (es. inizio in text node A, fine in text node B). In quel caso skippiamo
 * il match (raro: solo per match cross-element).
 */
export function applyFindHighlights(field, matches, activeIdx) {
    clearFindHighlights(field);
    // Devo applicare matches DALL'ULTIMO al PRIMO per non invalidare offset
    // dopo ogni surroundContents.
    const sorted = matches.map((m, i) => ({ ...m, idx: i }))
        .sort((a, b) => b.start - a.start);
    for (const m of sorted) {
        try {
            const range = document.createRange();
            setRangeAtOffsets(field, m.start, m.end, range);
            const mark = document.createElement("mark");
            mark.className = `fm-fr-hit${  m.idx === activeIdx ? " fm-fr-hit-active" : ""}`;
            range.surroundContents(mark);
        } catch (e) {
            // Range cross-boundary: skip
        }
    }
    // Scroll active in view
    const active = field.querySelector("mark.fm-fr-hit-active");
    active?.scrollIntoView({ block: "nearest" });
}

/** Unwrap tutti i `<mark class="fm-fr-hit">` dal field + normalize text nodes
 *  adiacenti (cleanup post-replace o close dialog). */
export function clearFindHighlights(field) {
    field.querySelectorAll("mark.fm-fr-hit").forEach((m) => {
        // unwrap: sposta children prima del mark, rimuovi mark
        const parent = m.parentNode;
        while (m.firstChild) parent.insertBefore(m.firstChild, m);
        m.remove();
    });
    // Merge text nodes adiacenti rimasti (normalize)
    field.normalize();
}
