/**
 * Risdoc sidepage (Phase 21).
 *
 * Popola le sidepage category-grouped (loader=risdoc nel registry):
 *   - bes    (#fm-sp-bes)    → origin=strcomp, categorie [STRCOMP, ALTRO]
 *   - risdoc (#fm-sp-risdoc) → origin=risdoc,  categorie [MODELLI, RISORSE]
 *
 * Sorgenti merge:
 *   1. /api/risdoc/templates                  template istituzionali
 *   2. /api/risdoc/teacher/instances          istanze fork (multi-instance Phase 24.58)
 *   3. /api/teacher/content?type=...          documenti personali liberi (Phase 24.62)
 *
 * Filtro Phase 24.63: docenti non super_admin vedono SOLO le proprie
 * istanze + doc personali (i template istituzionali sono accessibili
 * via modal "+ Nuovo" → Fork). Super_admin vede tutto.
 *
 * Ogni item linka a /risdoc/view/{id} (template) /
 * /risdoc/view/{id}?instance=KEY (fork) / /studio/... (doc libero).
 */

import { byKey, risdocLoaderDefs } from "./sidepage-registry.js";
import * as CustomCats from "./sidepage-custom-categories.js";
import * as CatLabels from "./sidepage-category-labels.js";
// Phase 25.A1 — utilities centralizzate.
import { escHtml as escapeHtml, assertJson } from "../core/dom-utils.js";

// Backward-compat alias: alcuni consumer legacy usavano SIDEPAGE_SPEC con
// `panelId` invece di `panel`. Build dal registry mantenendo lo shape.
const SIDEPAGE_SPEC = Object.fromEntries(
    risdocLoaderDefs().map(d => [d.key, {
        panelId: d.panel, origin: d.origin, categories: d.categories,
    }])
);

// ADR-027 Step 4 — spec derivato dal def runtime (idratabile): le sezioni
// custom non sono nella mappa statica, quindi si costruisce al volo.
function specOf(def) {
    if (!def) return null;
    return SIDEPAGE_SPEC[def.key] || {
        panelId: def.panel, origin: def.origin, categories: def.categories,
    };
}

let bound = false;

function init() {
    if (bound) return;
    bound = true;

    document.body.addEventListener("click", (e) => {
        const btn = e.target.closest(".fm-sb-sec[data-sidepage]");
        if (!btn) return;
        const def = byKey(btn.dataset.sidepage);
        if (!def || def.loader !== "risdoc") return;
        const spec = specOf(def);
        if (spec) loadSidepage(def.key, spec);
    });

    // Ri-renderizza le sidepage risdoc visibili (riusato per idratazione +
    // cambio selettori).
    const reRenderVisible = () => {
        for (const def of risdocLoaderDefs()) {
            const sp = document.getElementById("fm-sp-" + def.key);
            if (sp && sp.offsetParent !== null) loadSidepage(def.key, specOf(def));
        }
    };

    // ADR-027 Step 4 — ri-popola le sidepage risdoc visibili dopo l'idratazione.
    document.addEventListener("fm:sidebar-config-hydrated", reRenderVisible);

    // Cambio selettori sidebar (indirizzo/classe/materia) → ri-renderizza le
    // sidepage risdoc visibili così i link (es. "Piano annuale") seguono la
    // combinazione scelta. Delegato (i select possono essere idratati dopo).
    document.addEventListener("change", (e) => {
        const id = e.target && e.target.id;
        if (id === "sel-iis" || id === "sel-cls" || id === "sel-mater") reRenderVisible();
    });
}

async function loadSidepage(sidepageKey, spec) {
    const sidepage = document.getElementById(spec.panelId);
    if (!sidepage) return;

    // Token anti-race (coerente con db-sidepage pattern)
    const token = (sidepage.__fmRisdocToken || 0) + 1;
    sidepage.__fmRisdocToken = token;

    preserveEditBtn(sidepage);
    cleanContainers(sidepage);
    // Phase 24.48 — pulsante "+ Nuova categoria" in cima al sidepage panel
    injectNewCategoryButton(sidepage, spec);

    // Phase 25 — i template istituzionali sono SEMPRE filtrati dal display
    // (vedi sotto), quindi non serve più distinguere super_admin qui.
    let rows = [];
    // WS4 — guest (sidebar pubblica): NIENTE chiamate auth-only (templates/istanze
    // istituzionali) che darebbero 401/redirect a /login; il guest vede solo i
    // documenti published del super-admin via endpoint pubblico (sotto).
    const _isGuest = !!document.querySelector('nav.sidebar[data-fm-guest="1"]');
    if (!_isGuest) {
        try {
            const qs = new URLSearchParams({ origin: spec.origin });
            const url = `/api/risdoc/templates?${qs}`;
            const res = await fetch(url, { credentials: "same-origin", headers: { Accept: "application/json" } });
            if (sidepage.__fmRisdocToken !== token) return;
            const j = await assertJson(res, url);
            rows = j.templates || [];
        } catch (e) {
            if (sidepage.__fmRisdocToken !== token) return;
            renderError(sidepage, e);
            return;
        }
    }

    // Phase 24.58 — Merge ISTANZE del docente (multi-instance fork)
    // sotto il template istituzionale corrispondente.
    const instancesByTemplate = {};
    if (!_isGuest) try {
        const url = `/api/risdoc/teacher/instances`;
        const res = await fetch(url, { credentials: "same-origin", headers: { Accept: "application/json" } });
        if (res.ok) {
            const j = await assertJson(res, url);
            for (const i of (j.instances || [])) {
                if (!i.instance_key || i.instance_key === "") continue;
                const tid = parseInt(i.template_id, 10);
                (instancesByTemplate[tid] ??= []).push(i);
            }
        }
    } catch (_) { /* silent */ }
    const instanceRows = [];
    for (const r of rows) {
        const tid = parseInt(r.id, 10);
        const insts = instancesByTemplate[tid] || [];
        for (const inst of insts) {
            // Phase 24.67 — parse numarg da instance_label se ha pattern
            // "<num> <text>" (es. "1.0 Piano annuale 3A"). Altrimenti
            // eredita num_arg dal template padre.
            const fullLabel = inst.instance_label || inst.instance_key;
            const m = String(fullLabel).match(/^(\S+)\s+(.+)$/);
            const useNumarg = (m && /^[\d.]+[a-zA-Z]?$|^\d+[A-Z]?$/.test(m[1])) ? m[1] : r.num_arg;
            const useLabel = (m && useNumarg === m[1]) ? m[2] : fullLabel;
            instanceRows.push({
                id:           `inst_${tid}_${inst.instance_key}`,
                templateId:   tid,
                instanceKey:  inst.instance_key,
                origin:       r.origin,
                category:     r.category,
                num_arg:      useNumarg,
                argomento:    useLabel,
                discipline:   r.discipline || null,
                isInstance:   true,
                lastUpdated:  inst.last_updated || null,
            });
        }
    }
    rows.push(...instanceRows);

    // Phase 24.62 — Merge teacher_content body_pt (documenti personali
    // liberi non derivati da template istituzionale). Categoria da
    // metadata.category. Coexistono con istanze e template istituzionali.
    try {
        // ADR-027 — carica il teacher_content per SEZIONE (section_id), non per
        // content_type: così compaiono i documenti creati con QUALSIASI "Modello
        // documento" (formato derivato), non solo quelli con il tipo del pannello.
        const qs = new URLSearchParams({ section: sidepageKey, with_metadata: "1" });
        // Sicurezza visibilità: SOLO docenti/admin (marker server-side
        // .js-edit-section) usano /api/teacher/content (vede anche i draft
        // propri). Gli STUDENTI usano l'endpoint pubblico /api/study/content.json,
        // filtrato server-side a visibility=published + scope della sezione →
        // un documento NON pubblicato non compare mai come voce cliccabile nella
        // sidebar dello studente. (Prima: /api/teacher/content → 403 role:teacher,
        // quindi gli studenti non vedevano nemmeno i documenti pubblicati.)
        const canEdit = !!sidepage.querySelector(".js-edit-section");
        const url = canEdit
            ? `/api/teacher/content?${qs}`
            : (_isGuest ? `/api/public/study/content.json?${qs}` : `/api/study/content.json?${qs}`);
        const res = await fetch(url, { credentials: "same-origin", headers: { Accept: "application/json" } });
        if (res.ok) {
            const tj = await assertJson(res, url);
            const tRowsRaw = tj.rows || tj.content || [];
            // G22.S25 — escludi archived (cestino logico, recover da dashboard).
            const tRows = tRowsRaw.filter(t => (t.visibility || "") !== "archived");
            for (const t of tRows) {
                let meta = {};
                if (typeof t.metadata_json === "string" && t.metadata_json !== "") {
                    try { meta = JSON.parse(t.metadata_json) || {}; } catch (_) {}
                } else if (t.metadata && typeof t.metadata === "object") {
                    meta = t.metadata;
                }
                // Phase 25 fix — il default per categoria vuota DEVE coincidere con
                // una delle `spec.categories` (minuscole, es. "risorse"/"altro");
                // prima era "RISORSE"/"STRCOMP" (MAIUSCOLO) → bucket mai iterato dal
                // render → il documento spariva dal panel (e da ogni lista).
                const _defCat = (spec.categories && spec.categories.length)
                    ? spec.categories[spec.categories.length - 1]
                    : (spec.origin === "strcomp" ? "altro" : "risorse");
                // doc_roles (chip D/C/R) — dal metadata del documento (array) o
                // dalla stringa già esposta dal server. Stessa semantica del db
                // loader (itemLiHtml): serve a far renderizzare la chip
                // .fm-item-role da addInlineItemActions.
                const _docRoles = Array.isArray(meta.doc_roles)
                    ? meta.doc_roles.map(x => String(x || "").toUpperCase()).filter(x => "DCR".includes(x)).join("")
                    : (typeof t.doc_roles === "string" ? t.doc_roles.toUpperCase().replace(/[^DCR]/g, "") : "");
                rows.push({
                    id:        `tc_${  t.id}`,
                    teacherContentId: t.id,
                    origin:    spec.origin,
                    category:  String(meta.category || _defCat),
                    num_arg:   String(t.topic || ""),
                    argomento: String(t.title || ""),
                    discipline: t.subject_code || null,
                    // Codici PROPRI del documento: l'href deve puntare alla
                    // combinazione del doc, NON ai selettori sidebar correnti
                    // (altrimenti il link apre solo quando i selettori combaciano).
                    docInd:    t.indirizzo || "",
                    docCls:    t.classe || "",
                    docSubj:   t.subject_code || "",
                    doc_roles: _docRoles,
                    isTeacherContent: true,
                    // ADR-030 — doc "un solo documento, valori per terna": il link
                    // deve aprire SEMPRE questa riga (?ids) seguendo i selettori
                    // correnti come LENTE, non vincolato alla terna della riga.
                    ternaScoped: meta.terna_scoped === true,
                });
            }
        }
    } catch (_) { /* silent */ }

    // Phase 25 — i template ISTITUZIONALI non compaiono MAI nel sidepage
    // docente, neppure per super_admin: si gestiscono esclusivamente da
    // /admin/templates (pagina dedicata). Il sidepage "Risorse docente" mostra
    // SOLO i contenuti del docente (istanze fork + documenti liberi); i modelli
    // istituzionali restano accessibili via modal "+ Nuovo" → Fork.
    // (Prima super_admin li vedeva inline qui — confusione tra catalogo
    // istituzionale e risorse personali, vedi richiesta utente.)
    rows = rows.filter(r => r.isInstance || r.isTeacherContent);

    // Raggruppa per category
    const byCat = {};
    for (const r of rows) {
        (byCat[r.category] ??= []).push(r);
    }

    // Phase 24.48 — categorie custom create dall'utente, filtrate per scope
    // (indirizzo/classe/disciplina correnti). Categorie default restano sempre.
    const customCats = customCategoriesForCurrent();
    const allCats = [...spec.categories];
    for (const c of customCats) {
        if (!allCats.includes(c.key)) allCats.push(c.key);
    }

    for (const cat of allCats) {
        if (sidepage.__fmRisdocToken !== token) return;
        renderCategory(sidepage, spec, cat, byCat[cat] || []);
    }

    // Phase 25 fix — safety net: documenti con una categoria NON tra le default
    // né tra le custom configurate (es. categoria digitata a mano, valore legacy,
    // o case mismatch) non devono MAI sparire dal panel. Rendi i bucket residui.
    for (const cat of Object.keys(byCat)) {
        if (allCats.includes(cat)) continue;
        if (sidepage.__fmRisdocToken !== token) return;
        renderCategory(sidepage, spec, cat, byCat[cat]);
    }

    document.dispatchEvent(new CustomEvent("fm:risdoc-sidepage-rendered", {
        detail: { sidepage, sidepageKey, origin: spec.origin, rows },
    }));
}

function preserveEditBtn(sidepage) {
    // Se il btn .js-edit-section è nested dentro un host precedente, ri-parentalo a direct child
    const eb = sidepage.querySelector(".js-edit-section");
    if (eb && eb.parentElement !== sidepage) sidepage.appendChild(eb);
}

// Phase 24.48 — Custom categories: estratto in sidepage-custom-categories.js
// (Phase 24.72) per essere condiviso con db-sidepage. Le funzioni locali sotto
// sono wrapper retro-compatibili per l'API pubblica window.FM.RisdocSidepage.

function loadCustomCategories() {
    return CustomCats.loadAll();
}

function saveCustomCategories(all) {
    CustomCats.saveAll(all);
}

function customCategoriesForCurrent(filterOrigin) {
    const ind  = document.getElementById("sel-iis")?.value || "";
    const cls  = document.getElementById("sel-cls")?.value || "";
    const subj = document.getElementById("sel-mater")?.value || "";
    // Backward-compat: accetta categorie con `origin` (vecchio schema) O
    // `bucket` (nuovo schema). Filtro applicato manualmente per gestire
    // entrambi durante il periodo di migrazione.
    const all = CustomCats.loadAll();
    const out = [];
    for (const [key, cfg] of Object.entries(all)) {
        if (!cfg || typeof cfg !== "object") continue;
        const cfgBucket = cfg.bucket || cfg.origin;
        if (filterOrigin && cfgBucket && cfgBucket !== filterOrigin) continue;
        if (cfg.ind  && cfg.ind  !== ind)  continue;
        if (cfg.cls  && cfg.cls  !== cls)  continue;
        if (cfg.subj && cfg.subj !== subj) continue;
        out.push({ key, label: cfg.label || key, ...cfg });
    }
    return out;
}

function injectNewCategoryButton(sidepage, _spec) {
    // ADR-028 (2026-06-02) — bottone "🗂️ Gestisci categorie" rimosso: era troppo
    // pesante visivamente. Il link a /area-docente/categorie è ora nel popover
    // della ⓘ del button sezione (section-scope-hint.js). Manteniamo la pulizia
    // di un eventuale host legacy per idempotenza.
    sidepage.querySelector(".fm-newcat-host")?.remove();
}

function deleteCustomCategory(key) {
    CustomCats.remove(key);
}

/** Phase 25 — banner che, al doppio-click su una categoria, rimanda alla pagina
 *  dedicata di gestione (crea/rinomina/elimina). Sostituisce i pulsanti inline
 *  e la rinomina-on-dblclick. Idempotente per sidepage. */
function showCategoryHintBanner(sidepage, label) {
    const ex = sidepage.querySelector(":scope > .fm-cat-hint-banner");
    if (ex) ex.remove();
    const b = document.createElement("div");
    b.className = "fm-cat-hint-banner";
    b.innerHTML = `<span>Per <strong>creare / rinominare / eliminare</strong> la categoria `
        + `«${escapeHtml(label || "")}» usa la pagina dedicata.</span>`
        + `<span><a href="/area-docente/categorie">Gestisci categorie →</a> `
        + `<button type="button" class="fm-cat-hint-banner__close" aria-label="Chiudi">✕</button></span>`;
    b.querySelector(".fm-cat-hint-banner__close")?.addEventListener("click", () => b.remove());
    // Inserisci in cima al panel.
    sidepage.insertBefore(b, sidepage.firstChild);
}

function cleanContainers(sidepage) {
    sidepage.querySelectorAll(".fm-risdoc-cat, .fm-subj-host, ul.fm-db-block, .fm-newcat-host").forEach(n => n.remove());
}

function renderCategory(sidepage, spec, category, rows) {
    // WS4 — guest: href verso la vista pubblica read-only (vedi sotto).
    const _isGuest = !!document.querySelector('nav.sidebar[data-fm-guest="1"]');
    const host = document.createElement("div");
    host.className = "fm-risdoc-cat";
    host.dataset.category = category;

    const ul = document.createElement("ul");
    ul.className = "fm-db-block";
    ul.dataset.type = "risdoc-template";
    ul.dataset.origin = spec.origin;
    ul.dataset.category = category;

    const head = document.createElement("li");
    head.className = "fm-db-head";
    const label = document.createElement("span");
    label.className = "fm-db-head-label";
    label.textContent = labelOfCategory(category);
    // Phase 25 — la rinomina/gestione è nella pagina dedicata. Il doppio-click
    // qui NON rinomina più: mostra un banner che rimanda a /area-docente/categorie
    // (e ferma la delega di section-edit-mode che aprirebbe il prompt di rinomina).
    label.title = "Doppio-click: gestisci le categorie nella pagina dedicata";
    label.addEventListener("dblclick", (e) => {
        e.preventDefault();
        e.stopPropagation();
        showCategoryHintBanner(sidepage, labelOfCategory(category));
    }, true);
    head.appendChild(label);

    // Edit btn (clonato, CSS globale positioning lo gestisce)
    const origEdit = sidepage.querySelector(":scope > .js-edit-section");
    if (origEdit) {
        const clone = origEdit.cloneNode(true);
        clone.removeAttribute("data-fm-edit-bound");
        clone.dataset.risdocCategory = category;
        head.appendChild(clone);
    }
    // Phase 24.43 + 24.47 — "+ Nuovo" per-categoria uniformato a
    // .fm-section-add (stesso pattern di db-sidepage). Backward-compat:
    // mantiene anche .fm-cat-add per E2E test esistenti.
    const addBtn = document.createElement("button");
    addBtn.type = "button";
    addBtn.className = "fm-btn fm-btn--xs fm-section-add fm-cat-add";
    addBtn.dataset.fmPreCategory = category;
    addBtn.dataset.fmType = spec.origin === "strcomp" ? "bes" : "risdoc";
    addBtn.title = `Crea contenuto in ${labelOfCategory(category)}`;
    addBtn.textContent = "➕";
    head.appendChild(addBtn);

    // Phase 25 — la gestione categorie (crea/rinomina/elimina) è stata spostata
    // nella pagina dedicata /area-docente/categorie. Qui niente più pulsanti
    // inline: il doppio-click sull'etichetta mostra un banner che rimanda lì
    // (vedi bindCategoryHint). Il "+" resta per creare CONTENUTO.
    head.dataset.fmCatLabel = labelOfCategory(category);

    // Phase 24.47 — uniformazione: ogni .fm-db-block ha data-section/data-section-kind
    ul.dataset.section = category;
    ul.dataset.sectionKind = "category";
    ul.appendChild(head);

    if (!rows.length) {
        const empty = document.createElement("li");
        empty.className = "fm-muted";
        empty.style.fontSize = "11px";
        empty.textContent = "Nessun template visibile";
        ul.appendChild(empty);
    } else {
        // Phase 24.58 — sort che mantiene istanze raggruppate sotto al template:
        // ordina per (num_arg, isInstance, instanceKey).
        rows.sort((a, b) => {
            const na = parseFloat(a.num_arg), nb = parseFloat(b.num_arg);
            const numCmp = (!isNaN(na) && !isNaN(nb))
                ? na - nb
                : String(a.num_arg).localeCompare(String(b.num_arg));
            if (numCmp !== 0) return numCmp;
            // Stesso num_arg: template prima delle sue istanze.
            const ai = a.isInstance ? 1 : 0;
            const bi = b.isInstance ? 1 : 0;
            if (ai !== bi) return ai - bi;
            return String(a.instanceKey || "").localeCompare(String(b.instanceKey || ""));
        });
        for (const r of rows) {
            const li = document.createElement("li");
            if (r.isInstance) {
                li.dataset.templateId = String(r.templateId);
                li.dataset.instanceKey = r.instanceKey;
                li.dataset.userCreated = "1";
                li.classList.add("fm-risdoc-instance");
                li.title = `Istanza personale di "${humanizeArgomento(r.argomento)}" (forkata dal template istituzionale)`;
            } else if (r.isTeacherContent) {
                // Phase 24.62 — documenti personali liberi (no template fork)
                li.dataset.contentId = String(r.teacherContentId);
                // chip ruolo D/C/R: addInlineItemActions la renderizza da qui.
                if (r.doc_roles) li.dataset.docRoles = r.doc_roles;
                li.dataset.userCreated = "1";
                li.classList.add("fm-risdoc-custom");
                li.title = "Documento personale (libero, non derivato da template)";
            } else {
                li.dataset.templateId = String(r.id);
            }
            li.dataset.origin = r.origin;
            li.dataset.category = r.category;
            const num = document.createElement("span");
            num.className = "fm-numarg";
            // Phase 24.67 — istanze e teacher_content mostrano il loro
            // num_arg (parsato da instance_label / topic), fallback icona.
            if (r.isInstance) {
                num.textContent = r.num_arg || "↳";
            } else if (r.isTeacherContent) {
                num.textContent = r.num_arg || "📄";
            } else {
                num.textContent = r.num_arg;
            }
            const a = document.createElement("a");
            if (r.isInstance) {
                a.href = `/risdoc/view/${r.templateId}?instance=${encodeURIComponent(r.instanceKey)}`;
            } else if (r.isTeacherContent && _isGuest && r.teacherContentId) {
                // WS4 — guest: vista pubblica read-only del singolo documento
                // (full-page, niente linkref/SPA che richiederebbe auth).
                a.href = `/public/studio/${r.teacherContentId}`;
            } else if (r.isTeacherContent) {
                // Il link segue i SELETTORI correnti (indirizzo/classe/materia):
                // cambiando i selettori e ricliccando si apre il documento della
                // nuova combinazione. La sidepage si ri-renderizza al cambio
                // selettori (vedi init) così l'href resta sempre aggiornato.
                const ind = document.getElementById("sel-iis")?.value || r.docInd || window.FM?.Curriculum?.firstCode("indirizzi") || "";
                const cls = document.getElementById("sel-cls")?.value || r.docCls || window.FM?.Curriculum?.firstCode("classi") || "";
                const subj = document.getElementById("sel-mater")?.value || r.docSubj || window.FM?.Curriculum?.firstCode("materie") || "";
                const topic = encodeURIComponent(r.num_arg || r.argomento || "");
                a.href = `/studio/${r.origin === "strcomp" ? "bes" : "risdoc"}/${ind}/${cls}/${subj}/${topic}`;
                // ADR-030 — doc terna_scoped: aggancia ?ids così il server apre
                // SEMPRE questa riga (relax filtro terna) e l'indirizzo/classe/
                // materia dell'URL fa solo da LENTE per i valori dei campi 🔗.
                if (r.ternaScoped && r.teacherContentId) {
                    a.href += `?ids=${encodeURIComponent(r.teacherContentId)}`;
                }
                // class="linkref" → navigazione in-frame (SPA, DOMManager
                // bindLinkrefClick), come gli item del subject/category loader.
                // Senza, il click su un teacher_content faceva un full-page load
                // ("Sync interrotta dal cambio pagina"). I link /risdoc/view/…
                // (template/istanze) restano pagine standalone (no linkref).
                a.classList.add("linkref");
            } else {
                a.href = `/risdoc/view/${r.id}`;
            }
            a.textContent = humanizeArgomento(r.argomento);
            if (!r.isInstance && !r.isTeacherContent && r.discipline) a.textContent += ` (${r.discipline})`;
            li.append(num, " ", a);
            ul.appendChild(li);
        }
    }

    host.appendChild(ul);
    sidepage.appendChild(host);
}

function renderError(sidepage, err) {
    const div = document.createElement("div");
    div.className = "fm-risdoc-cat";
    div.innerHTML = `<div class="fm-error" style="padding:8px;font-size:11px;color:#c02a2a">
        Errore caricamento template: ${escapeHtml(err.message || String(err))}
    </div>`;
    sidepage.appendChild(div);
}

// Phase 24.41 — Default labels (override per-teacher via localStorage)
const DEFAULT_LABELS = {
    STRCOMP: "STRUMENTI COMPENSATIVI",
    ALTRO:   "ALTRO",
    MODELLI: "MODELLI",
    RISORSE: "RISORSE",
};
// Phase 24.69 — chiave per-utente (la rinomina dblclick è una scelta
// personale del docente, non condivisa con altri sullo stesso browser
// o cross-istanza). Fallback alla chiave legacy globale per backward-compat.
// Phase 24.73 — gli override etichetta vivono nello store CONDIVISO
// (sidepage-category-labels.js), trasversale a tutti i loader. Questi sono
// wrapper retro-compatibili per l'API pubblica window.FM.RisdocSidepage.*
function loadLabelOverrides() {
    return CatLabels.getOverrides();
}

function saveLabelOverride(category, newLabel) {
    CatLabels.setLabel(category, newLabel);
}

function labelOfCategory(c) {
    const overrides = CatLabels.getOverrides();
    if (overrides[c]) return overrides[c];
    if (DEFAULT_LABELS[c]) return DEFAULT_LABELS[c];
    // Phase 24.48 — fallback su custom categories label
    const customs = loadCustomCategories();
    if (customs[c]?.label) return customs[c].label;
    return c;
}

window.FM = window.FM || {};
window.FM.RisdocSidepage = window.FM.RisdocSidepage || {};
Object.assign(window.FM.RisdocSidepage, {
    labelOfCategory,
    saveLabelOverride,
    loadLabelOverrides,
    // Phase 24.48 — custom categories API
    loadCustomCategories,
    saveCustomCategories,
    customCategoriesForCurrent,
    deleteCustomCategory,
    /**
     * Phase 24.43 — Categorie effettivamente presenti nelle sidepage (DOM scan).
     * Ritorna [{key, label}] in ordine di rendering. La label riflette
     * eventuali override per-teacher applicati via dblclick.
     * Backward-compatible: il consumer legacy che fa `.includes("MODELLI")`
     * o `o.value` continua a funzionare leggendo `.key` esplicitamente.
     */
    listCurrentCategories(origin) {
        const sp = document.getElementById(origin === "strcomp" ? "fm-sp-bes" : "fm-sp-risdoc");
        if (!sp) return [];
        const out = [];
        const seen = new Set();
        sp.querySelectorAll("ul.fm-db-block[data-category]").forEach((ul) => {
            const key = ul.dataset.category;
            if (!key || seen.has(key)) return;
            seen.add(key);
            const label = ul.querySelector(".fm-db-head-label")?.textContent?.trim() || key;
            out.push({ key, label });
        });
        return out;
    },
});

function humanizeArgomento(s) {
    return String(s ?? "").replace(/_/g, " ");
}

// Phase 25.A1 — escapeHtml importata da core/dom-utils.js (alias di escHtml).

// Auto-popolamento quando google-apps.loadSidebarContent emette template-ready
document.addEventListener("fm:sidebar-template-ready", (e) => {
    const rawId = (e.detail?.id || "");
    const key = rawId.replace(/^#fm-sp-/, "");
    const def = byKey(key);
    if (def && def.loader === "risdoc") loadSidepage(def.key, specOf(def));
});

if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
else queueMicrotask(init);
window.addEventListener("fm:navigated", init);

window.FM = window.FM || {};
// Phase 24.41 — merge invece di sovrascrivere: Object.assign sopra
// installa labelOfCategory/saveLabelOverride/loadLabelOverrides/listCurrentCategories.
window.FM.RisdocSidepage = window.FM.RisdocSidepage || {};
Object.assign(window.FM.RisdocSidepage, {
    init,
    loadSidepage,
    // Phase 24.73 — reload by key (costruisce lo spec dal registry). Usato da
    // section-edit-mode alla chiusura edit (✓) per ri-renderizzare col loader
    // RISDOC corretto invece di loadDbSidepageContent (che svuotava il panel
    // perché bes/risdoc hanno type="document" nel registry).
    reload(key) {
        const def = byKey(key);
        if (!def || def.loader !== "risdoc") return false;
        const spec = specOf(def);
        if (!spec) return false;
        loadSidepage(def.key, spec);
        return true;
    },
});
