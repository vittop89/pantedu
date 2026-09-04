/**
 * Phase 25.A3 — Estratto da section-edit-mode.js (1003 LOC → 4 moduli).
 *
 * Toggle edit mode per-sezione (Phase 24.47): .js-edit-section dentro
 * .fm-db-head → toggle SUL <ul.fm-db-block> (sezione singola: materia
 * per db-sidepage, categoria per risdoc-sidepage).
 *
 * Esposto:
 *   - bindSidebarEditButtons() — bind di tutti i toggle button presenti
 *   - injectEditToolbar(sidepage) — inietta + Nuovo + inline actions
 *   - removeEditToolbar(sidepage) — pulisce inline actions
 *
 * Side-effect (al load del modulo): registra event listeners globali
 * per fm:edit-section-toggled, fm:db-sidepage-rendered,
 * fm:risdoc-sidepage-rendered, DOMContentLoaded, fm:navigated.
 */

import {
    bindSectionAddButtons,
    addInlineItemActions,
    removeInlineItemActions,
    typeForSidepageKey,
} from "./sidepage-inline-actions.js";

// Phase 20 — label icona-only (no "Modifica"/"Chiudi" testuali).
const ACTIVE_LABEL  = "✓";
const PASSIVE_LABEL = "✎";

// Bug-fix edit-mode persistence — refreshSidepage() (save/delete item) ri-renderizza
// l'intero pannello: i nuovi <ul.fm-db-block> nascono SENZA data-edit-active, quindi
// l'edit mode sembrava chiudersi da sola. Persistiamo le sezioni attive per
// sidepage+section e le ripristiniamo a ogni render (vedi restoreEditState).
// Chiave: sidepageKey → Set(sectionKey). L'edit mode va chiusa SOLO dal toggle ✓.
const activeEditSections = new Map();

function sidepageKey(sidepage) {
    return sidepage?.dataset?.sidepage || sidepage?.id || "";
}

function blockSectionKey(block, sidepage) {
    return block.dataset.section
        || block.dataset.category
        || block.dataset.subj
        || sidepageKey(sidepage);
}

/**
 * Ripristina lo stato edit-active sui <ul.fm-db-block> dopo un re-render.
 * Re-applica data-edit-active="1" + lo stato visivo del toggle (✓ / btn-esactive)
 * per ogni sezione che era attiva, così CSS riespone inline actions e "+ Nuovo".
 */
export function restoreEditState(sidepage) {
    if (!sidepage) return;
    const set = activeEditSections.get(sidepageKey(sidepage));
    if (!set || set.size === 0) return;
    sidepage.querySelectorAll("ul.fm-db-block").forEach((block) => {
        if (!set.has(blockSectionKey(block, sidepage))) return;
        block.dataset.editActive = "1";
        const btn = block.querySelector(".js-edit-section");
        if (!btn) return;
        btn.classList.add("btn-esactive");
        const strong = btn.querySelector("strong");
        if (strong) strong.textContent = ACTIVE_LABEL;
    });
}

export function bindSidebarEditButtons() {
    document.querySelectorAll(".fm-sb-panel .js-edit-section").forEach((btn) => {
        if (btn.dataset.fmEditBound === "1") return;
        btn.dataset.fmEditBound = "1";
        btn.addEventListener("click", (e) => {
            e.preventDefault();
            e.stopPropagation();
            const sidepage = btn.closest(".fm-sb-panel");
            if (!sidepage) return;
            // Phase 24.47 — target per-sezione: <ul.fm-db-block> ancestor.
            const block = btn.closest("ul.fm-db-block");
            if (!block) return;

            const active  = block.dataset.editActive !== "1";
            const section = blockSectionKey(block, sidepage);
            block.dataset.editActive = active ? "1" : "0";
            btn.classList.toggle("btn-esactive", active);
            const strong = btn.querySelector("strong");
            if (strong) strong.textContent = active ? ACTIVE_LABEL : PASSIVE_LABEL;

            // Persisti lo stato così sopravvive ai refreshSidepage() di save/delete item.
            const key = sidepageKey(sidepage);
            let set = activeEditSections.get(key);
            if (!set) { set = new Set(); activeEditSections.set(key, set); }
            if (active) set.add(section);
            else        set.delete(section);

            window.dispatchEvent(new CustomEvent("fm:edit-section-toggled", {
                detail: { section, active, sidepage, block },
            }));
        });
    });
}

export function injectEditToolbar(sidepage) {
    const key = sidepage.dataset.sidepage || sidepage.id;
    const type = typeForSidepageKey(key);
    if (!type) return;
    bindSectionAddButtons(sidepage, type);
    addInlineItemActions(sidepage, type);
}

export function removeEditToolbar(sidepage) {
    removeInlineItemActions(sidepage);
}
