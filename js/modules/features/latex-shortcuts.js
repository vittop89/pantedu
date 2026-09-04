/**
 * Phase 25 — Motore runtime "Scorciatoie LaTeX da tastiera".
 *
 * Porta web dello script AutoHotkey del docente: applica hotstring (espansioni
 * digitate) e hotkey (combinazioni Alt/Ctrl/Shift) in OGNI ambiente di scrittura
 * del sito (textarea, input testo, e i `<div contentEditable.fm-editor-field>`
 * con shim textarea-like → API `.value/.selectionStart/.setSelectionRange`).
 *
 * Le scorciatoie sono dati forkabili: riferimento super-admin + override docente
 * (vedi `LatexShortcutsService`/`LatexShortcutsController`). Caricate da
 * `/api/latex-shortcuts/effective` al primo focus su un campo scrivibile.
 *
 * Marker snippet:
 *   - `${SEL}` → testo selezionato (vuoto se nessuna selezione)
 *   - `${CUR}` → posizione finale del cursore
 * Regola cursore (parità AHK): senza selezione → cursore su `${SEL}` (primo
 * slot da riempire); con selezione → cursore su `${CUR}`.
 *
 * NB: le hotkey di login dell'AHK originale (con credenziali) sono ESCLUSE.
 */

import { escHtml } from "../core/dom-utils.js";

const EFFECTIVE_URL = "/api/latex-shortcuts/effective";

let _loaded = false;
let _loading = null;
let _groups = null;            // forma { groupKey: [items] }
let _hotstrings = [];          // [{trigger, snippet}] ordinati per trigger desc
let _hotkeys = new Map();      // canon(combo) → snippet
let _bound = false;
let _busy = false;             // guard ricorsione su input programmatico

// ───────────────────────────── caricamento dati ─────────────────────────────

async function load(force = false) {
    if (_loaded && !force) return;
    if (_loading && !force) return _loading;
    _loading = (async () => {
        try {
            const res = await fetch(EFFECTIVE_URL, { credentials: "same-origin" });
            const j = await res.json().catch(() => ({}));
            _groups = (j && j.groups && typeof j.groups === "object") ? j.groups : {};
        } catch {
            _groups = {};
        }
        _rebuildIndex();
        _loaded = true;
        _loading = null;
    })();
    return _loading;
}

function _rebuildIndex() {
    const hs = [];
    const hk = new Map();
    for (const groupKey of Object.keys(_groups || {})) {
        const items = _groups[groupKey];
        if (!Array.isArray(items)) continue;
        for (const it of items) {
            if (!it || it._disabled) continue;
            const snippet = String(it.snippet ?? "");
            if (!snippet) continue;
            if (it.type === "hotstring" && it.trigger) {
                hs.push({ trigger: String(it.trigger), snippet });
            } else if (it.type === "hotkey" && it.keys) {
                hk.set(canon(String(it.keys)), snippet);
            }
        }
    }
    // Trigger più lunghi prima → match greedy corretto (\llr prima di \lr).
    hs.sort((a, b) => b.trigger.length - a.trigger.length);
    _hotstrings = hs;
    _hotkeys = hk;
}

// ───────────────────────────── normalizzazione combo ─────────────────────────

/** Canonicalizza una combo "alt+shift+d" → "alt+shift+d" (ordine fisso
 *  ctrl,alt,shift,meta + key). Usato sia per le chiavi JSON sia per gli eventi. */
function canon(combo) {
    const parts = String(combo).toLowerCase().split("+").map((s) => s.trim()).filter(Boolean);
    const mods = new Set();
    let key = "";
    for (const p of parts) {
        if (p === "ctrl" || p === "control") mods.add("ctrl");
        else if (p === "alt" || p === "option") mods.add("alt");
        else if (p === "shift") mods.add("shift");
        else if (p === "meta" || p === "cmd" || p === "win") mods.add("meta");
        else key = p;
    }
    const order = ["ctrl", "alt", "shift", "meta"].filter((m) => mods.has(m));
    return [...order, key].join("+");
}

/** Da KeyboardEvent → carattere base indipendente da Shift/layout (via code). */
function codeToKey(e) {
    const c = e.code || "";
    let m;
    if ((m = /^Key([A-Z])$/.exec(c))) return m[1].toLowerCase();
    if ((m = /^Digit([0-9])$/.exec(c))) return m[1];
    if (c === "Backslash") return "\\";
    if (c === "Minus") return "-";
    if (c === "Equal") return "=";
    if (c === "Slash") return "/";
    if (c === "Period") return ".";
    if (c === "Comma") return ",";
    // fallback: tasto logico minuscolo (no modificatori spuri)
    const k = (e.key || "").toLowerCase();
    return k.length === 1 ? k : k;
}

function eventCombo(e) {
    const mods = [];
    if (e.ctrlKey) mods.push("ctrl");
    if (e.altKey) mods.push("alt");
    if (e.shiftKey) mods.push("shift");
    if (e.metaKey) mods.push("meta");
    return [...mods, codeToKey(e)].join("+");
}

// ───────────────────────────── espansione snippet ───────────────────────────

/** Espande lo snippet sostituendo ${SEL}/${CUR}; ritorna {text, cursor}. */
function expand(snippet, sel) {
    const SEL = "${SEL}";
    const CUR = "${CUR}";
    const hasSel = !!sel;
    // Anchor del cursore: senza selezione preferisci ${SEL} (primo slot),
    // con selezione preferisci ${CUR}.
    let anchor = null;
    if (!hasSel && snippet.includes(SEL)) anchor = "SEL";
    else if (snippet.includes(CUR)) anchor = "CUR";
    else if (snippet.includes(SEL)) anchor = "SEL";

    let out = "";
    let cursor = null;
    let i = 0;
    while (i < snippet.length) {
        if (snippet.startsWith(SEL, i)) {
            if (anchor === "SEL" && cursor === null) cursor = out.length;
            out += (sel || "");
            i += SEL.length;
        } else if (snippet.startsWith(CUR, i)) {
            if (anchor === "CUR" && cursor === null) cursor = out.length;
            i += CUR.length;
        } else {
            out += snippet[i];
            i += 1;
        }
    }
    if (cursor === null) cursor = out.length;
    return { text: out, cursor };
}

// ───────────────────────────── target scrivibili ────────────────────────────

function editableTarget(el) {
    if (!el || !el.tagName) return null;
    // Esclusione: campi che CONFIGURANO le scorciatoie (editor/popup) — altrimenti
    // digitare una sigla mentre la definisci la espanderebbe (ricorsione).
    if (el.closest?.("[data-no-shortcuts]")) return null;
    const tag = el.tagName;
    if (tag === "TEXTAREA") return el;
    if (tag === "INPUT") {
        const t = (el.type || "text").toLowerCase();
        if (t === "text" || t === "search" || t === "") return el;
        return null; // number/date/etc: no LaTeX
    }
    if (el.isContentEditable) return el;
    return null;
}

/** Inserisce `text` rimpiazzando il range [rStart,rEnd] e posiziona il cursore
 *  a rStart+cursorOffset. Unificato textarea/input + contentEditable (shim). */
function replaceRange(el, rStart, rEnd, text, cursorOffset) {
    _busy = true;
    try {
        if (el.isContentEditable) {
            el.focus();
            try { el.setSelectionRange(rStart, rEnd); } catch { /* shim best-effort */ }
            // insertText rimpiazza la selezione + emette input (lo shim si aggiorna).
            document.execCommand("insertText", false, text);
            try { el.setSelectionRange(rStart + cursorOffset, rStart + cursorOffset); } catch { /* noop */ }
        } else {
            const v = el.value;
            el.value = v.slice(0, rStart) + text + v.slice(rEnd);
            const pos = rStart + cursorOffset;
            try { el.setSelectionRange(pos, pos); } catch { /* noop */ }
        }
        el.dispatchEvent(new Event("input", { bubbles: true }));
    } finally {
        _busy = false;
    }
}

// ───────────────────────────── handlers ─────────────────────────────────────

function onKeyDown(e) {
    if (!_loaded || _hotkeys.size === 0) return;
    // Solo combo con almeno un modificatore "potente" (evita di rubare la
    // digitazione normale e lo Shift+lettera).
    if (!e.altKey && !e.ctrlKey && !e.metaKey) return;
    const el = editableTarget(e.target);
    if (!el) return;
    const snippet = _hotkeys.get(eventCombo(e));
    if (!snippet) return;
    e.preventDefault();
    const start = el.selectionStart ?? 0;
    const end = el.selectionEnd ?? start;
    const sel = (el.value || "").slice(start, end);
    const { text, cursor } = expand(snippet, sel);
    replaceRange(el, start, end, text, cursor);
}

function onInput(e) {
    if (_busy || !_loaded || _hotstrings.length === 0) return;
    // Solo inserimenti di un singolo carattere (parità AHK; evita paste).
    if (e.inputType && e.inputType !== "insertText") return;
    if (e.data && e.data.length > 1) return;
    const el = editableTarget(e.target);
    if (!el) return;
    const caret = el.selectionStart;
    if (typeof caret !== "number") return;
    const before = (el.value || "").slice(0, caret);
    if (!before) return;
    for (const { trigger, snippet } of _hotstrings) {
        if (before.endsWith(trigger)) {
            const rStart = caret - trigger.length;
            const { text, cursor } = expand(snippet, "");
            replaceRange(el, rStart, caret, text, cursor);
            return;
        }
    }
}

function onFocusIn(e) {
    if (_loaded) return;
    if (editableTarget(e.target)) load();
}

// ───────────────────────────── popup cheat-sheet ────────────────────────────

const GROUP_TITLES = {
    "espansioni": "Espansioni (digita)",
    "strutture": "Strutture (digita)",
    "tasti-simboli": "Simboli (tasti)",
    "tasti-operazioni": "Operazioni (tasti)",
    "tasti-parentesi": "Parentesi & ambienti (tasti)",
    "tasti-colori": "Colori & evidenziatori (tasti)",
    "tasti-passaggi": "Passaggi numerati (tasti)",
};

function comboPretty(keys) {
    return String(keys).split("+").map((p) => {
        const t = p.trim();
        if (t === "ctrl") return "Ctrl";
        if (t === "alt") return "Alt";
        if (t === "shift") return "Shift";
        if (t === "meta") return "Meta";
        return t.length === 1 ? t.toUpperCase() : t;
    }).join("+");
}

async function openPopup() {
    await load();
    closePopup();
    const overlay = document.createElement("div");
    overlay.id = "fm-shortcuts-popup";
    overlay.className = "fm-shortcuts-popup";
    overlay.setAttribute("role", "dialog");
    overlay.setAttribute("aria-modal", "true");
    overlay.setAttribute("aria-label", "Scorciatoie LaTeX da tastiera");

    let html = '<div class="fm-shortcuts-popup__panel">';
    html += '<div class="fm-shortcuts-popup__head">'
         + '<h2 class="fm-shortcuts-popup__title">⌨️ Scorciatoie LaTeX</h2>'
         + '<button type="button" class="fm-shortcuts-popup__close" aria-label="Chiudi">✕</button>'
         + "</div>";
    html += '<div class="fm-shortcuts-popup__body">';
    const groups = _groups || {};
    for (const gk of Object.keys(groups)) {
        const items = groups[gk];
        if (!Array.isArray(items) || !items.length) continue;
        html += `<section class="fm-shortcuts-popup__group"><h3>${escHtml(GROUP_TITLES[gk] || gk)}</h3><ul>`;
        for (const it of items) {
            const trig = it.type === "hotkey"
                ? `<kbd>${escHtml(comboPretty(it.keys || ""))}</kbd>`
                : `<code>${escHtml(it.trigger || "")}</code>`;
            const dis = it._disabled ? ' style="opacity:.45;text-decoration:line-through"' : "";
            const mine = it._override ? ' <span class="fm-shortcuts-popup__mine" title="Personalizzata">✱</span>' : "";
            html += `<li${dis}>${trig} <span class="fm-shortcuts-popup__lbl">${escHtml(it.label || "")}${mine}</span>`
                 + `<span class="fm-shortcuts-popup__desc">${escHtml(it.desc || "")}</span></li>`;
        }
        html += "</ul></section>";
    }
    html += "</div>";
    html += '<div class="fm-shortcuts-popup__foot">'
         + '<a class="fm-shortcuts-popup__edit" href="/area-docente/templates?tab=scorciatoie">Personalizza le mie scorciatoie →</a>'
         + "</div>";
    html += "</div>";
    overlay.innerHTML = html;
    document.body.appendChild(overlay);

    overlay.addEventListener("click", (ev) => {
        if (ev.target === overlay || ev.target.closest(".fm-shortcuts-popup__close")) closePopup();
    });
    document.addEventListener("keydown", _escClose, { once: false });
}

function _escClose(ev) {
    if (ev.key === "Escape") closePopup();
}

function closePopup() {
    const ex = document.getElementById("fm-shortcuts-popup");
    if (ex) ex.remove();
    document.removeEventListener("keydown", _escClose);
}

// ───────────────────────────── init ─────────────────────────────────────────

function init() {
    if (_bound) return;
    _bound = true;
    // Delegazione globale: copre ogni ambiente di scrittura presente e futuro.
    document.addEventListener("keydown", onKeyDown, true);
    document.addEventListener("input", onInput, true);
    document.addEventListener("focusin", onFocusIn, true);
    // Pulsante popup nella sidebar (sel-wrapper-actions).
    document.addEventListener("click", (e) => {
        if (e.target.closest?.("#fm-shortcuts-btn")) {
            e.preventDefault();
            openPopup();
        }
    });
}

init();

window.FM = window.FM || {};
window.FM.LatexShortcuts = {
    reload: () => load(true),
    open: openPopup,
    load,
};

export const LatexShortcuts = window.FM.LatexShortcuts;
