/**
 * Phase 18 — Edit mode scoped per sidepage (DB-backed).
 *
 * Phase 25.A3 — file orchestratore. Implementazione split in moduli:
 *   - sidepage-modal-content.js   (modal UNICO CRUD + fork — ADR-024)
 *   - sidepage-inline-actions.js  (✏🗑👁📥 actions inline + Nuovo)
 *   - sidepage-edit-toggle.js     (toggle .js-edit-section per-sezione)
 *
 * Questo file resta come entry-point importato da bootstrap.js: registra
 * gli event listeners globali e ri-esporta l'API window.FM.
 */

import {
    bindSidebarEditButtons,
    injectEditToolbar,
    restoreEditState,
} from "./sidepage-edit-toggle.js";
import {
    addInlineItemActions,
    removeInlineItemActions,
    bindSectionAddButtons,
    typeForSidepageKey,
} from "./sidepage-inline-actions.js";
import { closeModal } from "./sidepage-modal-content.js";
import { byKey } from "./sidepage-registry.js";
import * as CatLabels from "./sidepage-category-labels.js";

// ─────── Reload trasversale guidato dalla CONFIG del panel ───────
// Phase 24.73 — ri-renderizza una sidepage usando il LOADER configurato nel
// registry (def.loader), non hardcodando risdoc/db. Usato sia alla chiusura
// edit (✓) sia dopo la rinomina di una categoria. Generalizza il dispatch a
// qualunque configurazione di panel: aggiungere un loader = un solo case qui.
function reloadSidepageByKey(key) {
    if (!key) return false;
    const def = byKey(key);
    const loader = def?.loader;
    if (loader === "risdoc") {
        return !!window.FM?.RisdocSidepage?.reload?.(key);
    }
    // default: loader "db"
    const type = typeForSidepageKey(key);
    if (type && typeof window.FM?.loadDbSidepageContent === "function") {
        window.FM.loadDbSidepageContent(key, type);
        return true;
    }
    return false;
}
window.FM = window.FM || {};
window.FM.reloadSidepageByKey = reloadSidepageByKey;

// ─────── Rinomina categoria PER-DOCENTE (trasversale) ───────
// Phase 24.73 — delega unica su .fm-db-head-label dentro un blocco categoria
// (data-section-kind="category"), valida per OGNI loader (risdoc/bes/verif/
// custom). Doppio-click sempre; click singolo solo in edit-mode (toggle ✎).
// Il nome è personale del docente (CatLabels, localStorage per-username).
function categoryHeadFrom(target) {
    const label = target.closest?.(".fm-db-head-label");
    if (!label) return null;
    const block = label.closest("ul.fm-db-block");
    if (!block || block.dataset.sectionKind !== "category") return null;
    const category = block.dataset.category || block.dataset.section;
    if (!category) return null;
    const sidepage = label.closest(".fm-sb-panel");
    const key = sidepage?.dataset?.sidepage || (sidepage?.id || "").replace(/^fm-sp-/, "");
    return { label, block, category, key };
}
async function promptRenameCategory(ctx) {
    const current = ctx.label.textContent;
    const next = await window.FM.Dialog.prompt(`Rinomina categoria "${current}" (solo per te):`, current);
    if (next == null) return;
    CatLabels.setLabel(ctx.category, next);
    // re-render col loader corretto così la nuova etichetta è autoritativa
    if (!reloadSidepageByKey(ctx.key)) {
        ctx.label.textContent = CatLabels.labelOf(ctx.category, current);
    }
}
document.body.addEventListener("dblclick", (e) => {
    const ctx = categoryHeadFrom(e.target);
    if (!ctx) return;
    if (ctx.block.dataset.editActive === "1") return; // in edit-mode: click singolo
    e.stopPropagation();
    promptRenameCategory(ctx);
});
document.body.addEventListener("click", (e) => {
    const ctx = categoryHeadFrom(e.target);
    if (!ctx || ctx.block.dataset.editActive !== "1") return; // solo in edit-mode
    e.stopPropagation();
    promptRenameCategory(ctx);
});

// Phase 24.76 — quando le etichette categoria sono idratate dal DB (e diverse
// da quanto già mostrato, es. primo accesso da nuovo dispositivo), ricarica i
// pannelli categoria APERTI così applicano i nomi personalizzati del docente.
document.addEventListener("fm:category-labels-hydrated", () => {
    const keys = new Set();
    document.querySelectorAll('.fm-sb-panel ul.fm-db-block[data-section-kind="category"]').forEach((ul) => {
        const key = ul.closest(".fm-sb-panel")?.dataset?.sidepage;
        if (key) keys.add(key);
    });
    keys.forEach((k) => reloadSidepageByKey(k));
});

// ─────── Hooks ───────

window.addEventListener("fm:edit-section-toggled", (e) => {
    const { active, sidepage } = e.detail || {};
    if (!sidepage) return;
    if (active) {
        injectEditToolbar(sidepage);
        return;
    }
    // 2026-06-10 — Chiusura edit (click ✓): NON ricarichiamo più il pannello.
    // La visibilità di .fm-item-actions / .fm-section-add è gata da CSS su
    // [data-edit-active] (già messo a "0" dal toggle), e le operazioni fatte
    // durante l'edit (✎ edit, 🗑 delete, ➕ create) aggiornano già il DOM in
    // modo chirurgico (o con refresh proprio nei loader category/risdoc).
    // Prima `reloadSidepageByKey` ri-fetchava e ri-renderizzava l'INTERO
    // fm-sb-panel a OGNI ✓ → l'intero pannello spariva e ricompariva (flash /
    // "pagina bianca"). Non rimuoviamo nemmeno le inline actions a mano: le
    // nasconde il CSS, così le chip ruolo D/C/R read-only restano visibili.
    const key = sidepage.dataset.sidepage
             || (sidepage.id || "").replace(/^fm-sp-/, "");
    if (!key) return;
    // Mantiene fresca la cache per la PROSSIMA apertura del pannello (dati
    // autoritativi dal server alla riapertura) senza ricaricare adesso.
    window.FM?.clearTeacherContentCache?.();
    // Il pannello Verifiche ha un sotto-blocco "VERIFICHE SALVATE" con loader
    // dedicato (verifica-documents-sidepage.js): lo lasciamo aggiornare via il
    // suo evento, senza toccare il resto del pannello.
    window.dispatchEvent(new CustomEvent("fm:verifica-saved", {
        detail: { sidepageKey: key },
    }));
});

/**
 * Phase 24.38 — ascolta sia db-sidepage che risdoc-sidepage events
 * (risdoc/strcomp emettono fm:risdoc-sidepage-rendered, formato differente).
 */
const onSidepageRendered = (e) => {
    const detail = e.detail || {};
    const sidepage = detail.sidepage;
    if (!sidepage) return;
    const type = detail.type
              || typeForSidepageKey(sidepage.dataset.sidepage)
              || typeForSidepageKey(detail.sidepageKey)
              || null;
    // Phase 24.47 — toggle è per-section. Inline actions e + Nuovo bindings
    // sono SEMPRE installati al render; visibilità è gated da CSS.
    removeInlineItemActions(sidepage);
    if (type) {
        addInlineItemActions(sidepage, type);
        bindSectionAddButtons(sidepage, type);
    }
    // Ripristina l'edit mode delle sezioni attive: i blocchi ri-renderizzati
    // nascono senza data-edit-active, altrimenti l'edit mode si chiuderebbe da
    // sola dopo un save/delete item (deve chiudersi SOLO dal toggle ✓).
    restoreEditState(sidepage);
};
document.addEventListener("fm:db-sidepage-rendered", onSidepageRendered);
document.addEventListener("fm:risdoc-sidepage-rendered", onSidepageRendered);

window.addEventListener("fm:navigated", bindSidebarEditButtons);
document.addEventListener("DOMContentLoaded", bindSidebarEditButtons);
document.addEventListener("fm:db-sidepage-rendered", bindSidebarEditButtons);
document.addEventListener("fm:risdoc-sidepage-rendered", bindSidebarEditButtons);
document.addEventListener("keydown", (e) => { if (e.key === "Escape") closeModal(); });

window.FM = window.FM || {};
window.FM.bindSidebarEditButtons = bindSidebarEditButtons;
