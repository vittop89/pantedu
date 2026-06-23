/**
 * Phase 25 — Gestione categorie dedicata (/area-docente/categorie).
 *
 * Sostituisce la gestione inline nel sidepage. Opera sulle categorie delle
 * sezioni document:
 *   - Risorse docente (bucket origin "risdoc", default modelli/risorse)
 *   - BES/DSA         (bucket origin "strcomp", default bes/altro)
 *
 * Categorie:
 *   - default  → bloccate (non rinominabili/eliminabili, sono la struttura base)
 *   - custom   → CustomCats (localStorage, key MAIUSCOLO + scope opzionale)
 *   - residue  → categorie presenti nei documenti ma non default né custom
 *                (es. duplicati storici): rinominabili (label) ed eliminabili
 *
 * Operazioni sicure: crea (custom), rinomina (label via CatLabels), elimina
 * (categoria + suoi documenti, con avviso). Niente re-categorizzazione automatica
 * (il dual-write del body_pt la renderebbe rischiosa) — per i duplicati: elimina
 * e ricrea nella categoria corretta.
 */

import * as CustomCats from "./sidepage-custom-categories.js";
import * as CatLabels from "./sidepage-category-labels.js";
import { esc, fetchJson, fetchCsrf } from "../core/dom-utils.js";

const SECTIONS = [
    { key: "risdoc", origin: "risdoc",  title: "📁 Risorse docente", defaults: ["modelli", "risorse"] },
    { key: "bes",    origin: "strcomp", title: "🧩 BES/DSA — Recuperi", defaults: ["bes", "altro"] },
];

const DEFAULT_LABELS = {
    modelli: "Modelli", risorse: "Risorse", bes: "BES", altro: "Altro",
};

const getCsrf = fetchCsrf; // centralizzato (meta-tag-first + fetch fallback in dom-utils)

function toast(kind, title, msg) {
    if (window.FM?.ToastManager?.show) window.FM.ToastManager.show(kind, title, msg, 3000);
    else console[kind === "error" ? "error" : "info"](`[cat] ${title}: ${msg}`);
}

function labelOf(key, isDefault) {
    const override = CatLabels.labelOf ? CatLabels.labelOf(key, null) : null;
    if (override) return override;
    if (isDefault && DEFAULT_LABELS[key]) return DEFAULT_LABELS[key];
    return key;
}

/** Carica i documenti di una sezione e conta per categoria. */
async function loadDocsByCategory(section) {
    const url = `/api/teacher/content?section=${encodeURIComponent(section.key)}&with_metadata=1`;
    const byCat = {}; // catKey -> [{id, title}]
    try {
        const j = await fetchJson(url).catch(() => ({}));
        const rows = (j.rows || j.content || []).filter(t => (t.visibility || "") !== "archived");
        for (const t of rows) {
            let meta = {};
            if (typeof t.metadata_json === "string" && t.metadata_json) { try { meta = JSON.parse(t.metadata_json) || {}; } catch {} }
            else if (t.metadata && typeof t.metadata === "object") meta = t.metadata;
            const cat = String(meta.category || (section.origin === "strcomp" ? "bes" : "risorse"));
            (byCat[cat] ??= []).push({ id: t.id, title: t.title || "" });
        }
    } catch { /* noop */ }
    return byCat;
}

/** Config sezioni (cache): per leggere lockDefaultCategories deciso dall'admin. */
let _sectionCfg = null;
async function loadSectionConfig() {
    if (_sectionCfg) return _sectionCfg;
    _sectionCfg = {};
    try {
        const j = await (await fetch("/api/sidebar/config", { credentials: "same-origin" })).json();
        for (const s of (j.sections || [])) _sectionCfg[s.key] = s;
    } catch { /* default: bloccate */ }
    return _sectionCfg;
}

/** Costruisce la lista categorie {key, kind, label, docs} per una sezione. */
async function buildCategories(section) {
    const cfg = await loadSectionConfig();
    // L'admin decide (in /admin/sidebar-config) se le predefinite sono bloccate.
    // Default: bloccate (true) se la config non è disponibile.
    const lockedDefaults = cfg[section.key]?.lockDefaultCategories !== false;
    const byCat = await loadDocsByCategory(section);
    const customs = CustomCats.loadAll ? CustomCats.loadAll() : {};
    const seen = new Set();
    const out = [];

    // default
    for (const key of section.defaults) {
        seen.add(key);
        out.push({ key, kind: "default", locked: lockedDefaults, label: labelOf(key, true), docs: byCat[key] || [] });
    }
    // custom (bucket = origin)
    for (const [key, cfg] of Object.entries(customs)) {
        if (cfg?.bucket && cfg.bucket !== section.origin) continue;
        if (seen.has(key)) continue;
        seen.add(key);
        const scope = [cfg.ind, cfg.cls, cfg.subj].filter(Boolean).join("/") || "globale";
        out.push({ key, kind: "custom", label: cfg.label || key, scope, docs: byCat[key] || [] });
    }
    // residue (presenti nei doc ma non default/custom — duplicati storici)
    for (const key of Object.keys(byCat)) {
        if (seen.has(key)) continue;
        seen.add(key);
        out.push({ key, kind: "residue", label: labelOf(key, false), docs: byCat[key] || [] });
    }
    return out;
}

async function deleteDoc(id) {
    const fd = new URLSearchParams({ _csrf: await getCsrf() });
    await fetch(`/api/teacher/content/${id}/delete`, {
        method: "POST", credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: fd.toString(),
    });
}

/** Ri-categorizza un documento (server JSON_SET, sicuro su body_pt). */
async function recategorizeDoc(id, category, sectionKey) {
    const fd = new URLSearchParams({ _csrf: await getCsrf(), category });
    if (sectionKey) fd.set("section_key", sectionKey);
    const j = await fetchJson(`/api/teacher/content/${id}/recategorize`, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: fd.toString(),
    });
    if (j?.ok === false) throw new Error(j?.error || "richiesta non riuscita");
}

function removeSourceCat(cat) {
    if (cat.kind === "custom" && CustomCats.remove) CustomCats.remove(cat.key);
}

/** Categoria vuota → conferma + rimuovi. Con documenti → popup migra/elimina. */
async function deleteCategory(section, cat, allCache) {
    const n = cat.docs.length;
    if (n === 0) {
        const ok = window.FM?.Dialog?.confirm
            ? await window.FM.Dialog.confirm(`Eliminare la categoria «${cat.label}»? È vuota.`)
            : confirm(`Eliminare la categoria «${cat.label}»? È vuota.`);
        if (!ok) return false;
        removeSourceCat(cat);
        toast("success", "Categoria eliminata", `«${cat.label}»`);
        return true;
    }
    // Con contenuti → popup dedicato.
    return openDeleteModal(section, cat, allCache);
}

/** Popup: elenca i documenti della categoria, propone migrazione verso un'altra
 *  categoria (stessa o altra sezione) OPPURE eliminazione definitiva. */
function openDeleteModal(section, cat, allCache) {
    return new Promise((resolve) => {
        // Costruisci le opzioni di destinazione: tutte le categorie di tutte le
        // sezioni, esclusa quella di origine.
        const opts = [];
        for (const s of SECTIONS) {
            for (const c of (allCache?.[s.key] || [])) {
                if (s.key === section.key && c.key === cat.key) continue;
                opts.push(`<option value="${esc(s.key)}::${esc(c.key)}">${esc(s.title.replace(/^[^ ]+ /, ""))} › ${esc(c.label)}</option>`);
            }
        }
        const docList = cat.docs.map(d => `<li>${esc(d.title || "(senza titolo)")}</li>`).join("");

        const overlay = document.createElement("div");
        overlay.className = "fm-cat-modal";
        overlay.innerHTML = `
            <div class="fm-cat-modal__panel" role="dialog" aria-modal="true">
                <h3 class="fm-cat-modal__title">Eliminare la categoria «${esc(cat.label)}»?</h3>
                <p class="fm-cat-modal__lead">Contiene <strong>${cat.docs.length}</strong>
                    ${cat.docs.length === 1 ? "documento" : "documenti"}:</p>
                <ul class="fm-cat-modal__docs">${docList}</ul>
                <div class="fm-cat-modal__migrate">
                    <label>Sposta i documenti in:
                        <select class="fm-cat-modal__target">${opts.join("")}</select>
                    </label>
                    <button type="button" class="fm-btn fm-btn--primary fm-btn--sm fm-cat-modal__do-migrate">↪ Migra qui</button>
                </div>
                <hr class="fm-cat-modal__sep">
                <div class="fm-cat-modal__danger">
                    <p class="fm-muted fm-text-xs">In alternativa puoi eliminare definitivamente i documenti
                        (azione <strong>non annullabile</strong>).</p>
                    <button type="button" class="fm-btn fm-btn--danger fm-btn--sm fm-cat-modal__do-delete">🗑 Elimina definitivamente</button>
                </div>
                <div class="fm-cat-modal__foot">
                    <button type="button" class="fm-btn fm-btn--ghost fm-btn--sm fm-cat-modal__cancel">Annulla</button>
                </div>
            </div>`;
        document.body.appendChild(overlay);
        const close = (result) => { overlay.remove(); resolve(result); };

        overlay.addEventListener("click", (e) => { if (e.target === overlay) close(false); });
        overlay.querySelector(".fm-cat-modal__cancel").addEventListener("click", () => close(false));

        overlay.querySelector(".fm-cat-modal__do-migrate").addEventListener("click", async (e) => {
            const sel = overlay.querySelector(".fm-cat-modal__target");
            const val = sel?.value || "";
            if (!val) { toast("error", "Nessuna destinazione", "Scegli una categoria."); return; }
            const [destSection, destCat] = val.split("::");
            e.target.disabled = true; e.target.textContent = "Migrazione…";
            try {
                for (const d of cat.docs) {
                    await recategorizeDoc(d.id, destCat, destSection !== section.key ? destSection : "");
                }
                removeSourceCat(cat);
                window.FM?.clearTeacherContentCache?.();
                toast("success", "Documenti migrati", `${cat.docs.length} → ${destCat}`);
                close(true);
            } catch (err) { toast("error", "Errore migrazione", err.message); e.target.disabled = false; e.target.textContent = "↪ Migra qui"; }
        });

        overlay.querySelector(".fm-cat-modal__do-delete").addEventListener("click", async (e) => {
            const ok = window.FM?.Dialog?.confirm
                ? await window.FM.Dialog.confirm(`Eliminare DEFINITIVAMENTE «${cat.label}» e i suoi ${cat.docs.length} documenti? Azione non annullabile.`)
                : confirm(`Eliminare definitivamente «${cat.label}» e i suoi documenti?`);
            if (!ok) return;
            e.target.disabled = true; e.target.textContent = "Eliminazione…";
            try {
                for (const d of cat.docs) await deleteDoc(d.id);
                removeSourceCat(cat);
                window.FM?.clearTeacherContentCache?.();
                toast("success", "Eliminati", `«${cat.label}» + ${cat.docs.length} documenti`);
                close(true);
            } catch (err) { toast("error", "Errore", err.message); e.target.disabled = false; e.target.textContent = "🗑 Elimina definitivamente"; }
        });
    });
}

async function renameCategory(cat) {
    const Dialog = window.FM?.Dialog;
    const cur = cat.label;
    const next = Dialog?.prompt
        ? await Dialog.prompt(`Nuova etichetta per «${cur}»:`, cur)
        : window.prompt(`Nuova etichetta per «${cur}»:`, cur);
    if (next == null) return false;
    const label = String(next).trim();
    if (!label || label === cur) return false;
    CatLabels.setLabel(cat.key, label);
    toast("success", "Rinominata", `«${cur}» → «${label}»`);
    return true;
}

async function createCategory(section, container) {
    const keyInput = container.querySelector(`.fm-cat-new__key[data-section="${section.key}"]`);
    const labelInput = container.querySelector(`.fm-cat-new__label[data-section="${section.key}"]`);
    const key = (keyInput?.value || "").trim();
    const label = (labelInput?.value || "").trim();
    if (!key) { toast("error", "Manca la chiave", "Inserisci una chiave (es. RECUPERO)."); return false; }
    try {
        // Scope "any" (globale): visibile in ogni indirizzo/classe/materia.
        const created = CustomCats.create({ key, label: label || key, bucket: section.origin, scope: "any" });
        toast("success", "Categoria creata", `«${label || created}»`);
        if (keyInput) keyInput.value = "";
        if (labelInput) labelInput.value = "";
        return true;
    } catch (e) {
        const m = { invalid_key: "Chiave non valida." }[e.message] || `Errore: ${e.message}`;
        toast("error", "Errore", m);
        return false;
    }
}

function renderSection(section, cats, lockCustom) {
    const rows = cats.map((cat) => {
        const badge = cat.kind === "default"
            ? '<span class="fm-cat-badge fm-cat-badge--default">predefinita</span>'
            : cat.kind === "residue"
                ? '<span class="fm-cat-badge fm-cat-badge--residue">duplicato/storica</span>'
                : `<span class="fm-cat-badge fm-cat-badge--custom">custom · ${esc(cat.scope || "globale")}</span>`;
        let actions;
        if (cat.kind === "default") {
            // Predefinita: niente elimina (è strutturale). Rinomina solo se l'admin
            // ha SBLOCCATO le predefinite per questa sezione (lockDefaultCategories=false).
            actions = cat.locked
                ? '<span class="fm-muted fm-text-xs">🔒 bloccata (admin)</span>'
                : `<button type="button" class="fm-btn fm-btn--ghost fm-btn--xs fm-cat-act" data-act="rename" data-key="${esc(cat.key)}">✎ Rinomina</button>`;
        } else {
            actions = `<button type="button" class="fm-btn fm-btn--ghost fm-btn--xs fm-cat-act" data-act="rename" data-key="${esc(cat.key)}">✎ Rinomina</button>
               <button type="button" class="fm-btn fm-btn--danger fm-btn--xs fm-cat-act" data-act="delete" data-key="${esc(cat.key)}">🗑 Elimina</button>`;
        }
        return `<tr data-key="${esc(cat.key)}">
            <td><strong>${esc(cat.label)}</strong> <code class="fm-cat-key">${esc(cat.key)}</code></td>
            <td>${badge}</td>
            <td class="fm-cat-count">${cat.docs.length}</td>
            <td class="fm-cat-actions">${actions}</td>
        </tr>`;
    }).join("");

    return `<section class="fm-cat-section" data-section="${section.key}">
        <h2 class="fm-cat-section__title">${esc(section.title)}</h2>
        <table class="fm-cat-table">
            <thead><tr><th>Categoria</th><th>Tipo</th><th>Contenuti</th><th>Azioni</th></tr></thead>
            <tbody>${rows}</tbody>
        </table>
        ${lockCustom
            ? '<p class="fm-muted fm-text-xs">🚫 La creazione di nuove categorie è disabilitata dall\'amministratore per questa sezione.</p>'
            : `<div class="fm-cat-new">
            <input type="text" class="fm-cat-new__key" data-section="${section.key}" placeholder="CHIAVE (es. RECUPERO)" maxlength="24">
            <input type="text" class="fm-cat-new__label" data-section="${section.key}" placeholder="Etichetta (opz.)">
            <button type="button" class="fm-btn fm-btn--primary fm-btn--sm fm-cat-create" data-section="${section.key}">➕ Crea categoria</button>
        </div>`}
    </section>`;
}

async function render(container) {
    container.innerHTML = '<p class="fm-muted fm-text-center fm-p-5">Caricamento categorie…</p>';
    const sectionsHtml = [];
    const cache = {};
    const cfg = await loadSectionConfig();
    for (const section of SECTIONS) {
        const cats = await buildCategories(section);
        cache[section.key] = cats;
        const lockCustom = cfg[section.key]?.lockCustomCategories === true;
        sectionsHtml.push(renderSection(section, cats, lockCustom));
    }
    container.innerHTML = sectionsHtml.join("");
    container._catCache = cache;
}

let _bound = false;
function mount(container) {
    if (!container) return;
    render(container);
    if (_bound) return;
    _bound = true;
    container.addEventListener("click", async (e) => {
        const createBtn = e.target.closest(".fm-cat-create");
        if (createBtn) {
            const section = SECTIONS.find(s => s.key === createBtn.dataset.section);
            if (await createCategory(section, container)) render(container);
            return;
        }
        const act = e.target.closest(".fm-cat-act");
        if (!act) return;
        const sectionEl = act.closest(".fm-cat-section");
        const section = SECTIONS.find(s => s.key === sectionEl?.dataset.section);
        const cats = container._catCache?.[section?.key] || [];
        const cat = cats.find(c => c.key === act.dataset.key);
        if (!section || !cat) return;
        if (act.dataset.act === "rename") { if (await renameCategory(cat)) render(container); }
        else if (act.dataset.act === "delete") { if (await deleteCategory(section, cat, container._catCache)) render(container); }
    });
}

window.FM = window.FM || {};
window.FM.CategoryManager = { mount };

export const CategoryManager = window.FM.CategoryManager;
