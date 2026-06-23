/**
 * DB-backed sidepage content (Phase 18 — unified DB source).
 *
 * Loader per le sidepage subject-grouped (mappe/lab/eser/verif). Le entry
 * sono definite in sidepage-registry.js (loader: "db"). bes/risdoc usano
 * risdoc-sidepage.js (per-teacher overrides + multi-instance fork).
 *
 * Link generati: /studio/{type}/{ind}/{cls}/{subj}/{topic}
 */

import { byKey, dbLoaderDefs } from "./sidepage-registry.js";
import * as CustomCats from "./sidepage-custom-categories.js";
import * as CatLabels from "./sidepage-category-labels.js";
// Phase 25.A1 — utilities centralizzate (esc/escAttr/parseMeta/readSelect).
import { esc, escAttr, parseMeta, readSelect, assertJson } from "../core/dom-utils.js";
import { codesFor } from "../core/curriculum-codes.js";

// Backward-compat: alcuni consumer leggevano window.FM.DB_SIDEPAGE_TYPE_MAP.
// Build dal registry per evitare drift.
const SIDEPAGE_TYPE_MAP = Object.fromEntries(
    dbLoaderDefs().map(d => [d.key, d.type])
);

// Phase 25.A4 — ETag client cache per /api/teacher/content?type=…
// Riduce payload + work server-side: il server espone ETag header (Phase 17),
// noi inviamo If-None-Match e su 304 riusiamo il body cached.
// Invalidazione: su mutate (create/update/delete) → clearContentCache().
const __etagCache = new Map();  // key: queryString → { etag, body }

function clearContentCache() {
    __etagCache.clear();
}
window.FM = window.FM || {};
window.FM.clearTeacherContentCache = clearContentCache;

let bound = false;

function init() {
    if (bound) return;
    bound = true;

    // Click delegato sui button sezione `.fm-sb-sec[data-sidepage]`.
    document.body.addEventListener("click", (e) => {
        const btn = e.target.closest(".fm-sb-sec[data-sidepage]");
        if (!btn) return;
        const def = byKey(btn.dataset.sidepage);
        if (!def || def.loader !== "db") return;
        loadDbContent(def.key, def.type);
    });

    // Re-load quando cambiano i select.
    document.body.addEventListener("change", (e) => {
        const t = e.target;
        if (!t.matches?.("#sel-iis, #sel-cls, #sel-mater")) return;
        for (const def of dbLoaderDefs()) {
            const sidepage = sidepageOf(def.key);
            if (sidepage && (isVisible(sidepage) || sidepage.querySelector("ul.fm-db-block"))) {
                loadDbContent(def.key, def.type);
            }
        }
    });

    // Phase 18 — auto-popolo al ready + quando google-apps carica il
    // template sidepage (fm:sidebar-template-ready emesso dopo il .load()
    // di loadSidebarContent). Copre il caso: reload pagina con sidepage
    // già aperta → il template legacy torna a mostrare `.materia` vuoto
    // finché db-sidepage non popola.
    // Phase 20 — guard: skippa se la sidepage è GIÀ popolata con
    // <ul class="fm-db-block"> (evita ri-fetch + ri-render inutile su
    // ogni fm:navigated quando l'utente naviga tra i content).
    const popolaTutte = () => {
        for (const def of dbLoaderDefs()) {
            const sidepage = sidepageOf(def.key);
            if (!sidepage || !isVisible(sidepage)) continue;
            if (sidepage.querySelector("ul.fm-db-block")) continue;
            loadDbContent(def.key, def.type);
        }
    };

    document.addEventListener("fm:sidebar-template-ready", (e) => {
        // detail.id = "#fm-sp-<key>" (da google-apps.loadSidebarContent).
        const rawId = (e.detail && e.detail.id) || "";
        const key = rawId.replace(/^#fm-sp-/, "");
        const def = byKey(key);
        if (!def || def.loader !== "db") return popolaTutte();
        loadDbContent(def.key, def.type);
    });

    // Primo passaggio: se le sidepage sono già nel DOM (es. page reload
    // con sidepage.open persistita), popola ora.
    setTimeout(popolaTutte, 300);

    // ADR-027 Step 4 — dopo l'idratazione del registry (config sidebar),
    // ri-popola: una sezione custom già aperta al load diventa funzionale.
    document.addEventListener("fm:sidebar-config-hydrated", popolaTutte);
}

function isVisible(el) {
    if (!el) return false;
    const style = getComputedStyle(el);
    if (style.display === "none" || style.visibility === "hidden") return false;
    const rect = el.getBoundingClientRect();
    return rect.width > 0 && rect.height > 0;
}

async function loadDbContent(sidepageKey, type) {
    const def = byKey(sidepageKey);
    // Phase 24.72 — dispatch a category-grouped (verif) o subject-grouped (default).
    if (def?.group === "category") {
        return loadDbContentByCategory(def);
    }
    return loadDbContentBySubject(sidepageKey, type);
}

async function loadDbContentBySubject(sidepageKey, type) {
    const sidepage = sidepageOf(sidepageKey);
    if (!sidepage) return;
    const ind = readSelect("sel-iis");
    const cls = readSelect("sel-cls");
    if (!ind || !cls) {
        sidepage.innerHTML = "";
        return;
    }

    // Phase 18 — token per evitare race condition.
    const token = (sidepage.__fmToken || 0) + 1;
    sidepage.__fmToken = token;

    const allSubjs = collectActiveSubjects();
    const selMater = readSelect("sel-mater");
    // Perf 2026-05-24 — fetch SOLO la materia selezionata in sel-mater.
    // Prima fetchavamo TUTTE le materie attive (es. 11 GET) per poi
    // nasconderle con applySubjectFilter → spreco N-1 round-trip.
    // Se selMater è "All"/vuoto: fetch tutte (caso "Tutte le materie").
    // applySubjectFilter resta valido (mostra/nasconde host esistenti).
    const subjs = (selMater && selMater !== "All" && allSubjs.includes(selMater))
        ? [selMater]
        : allSubjs;

    // Phase 18 — preserva il .js-edit-section btn (iniettato server-side),
    // elimina solo container materia precedenti (fm-subj-host / #M / #G /
    // #F / #MAT / #FIS / ...) + errori / headers residui.
    cleanSidepageKeepingEditBtn(sidepage);

    // ADR-027 — sezioni multi-tipo (allowedTypes>1): carica per `section`
    // (tutti i content_type ancorati alla sezione). Mono-tipo → per `type`
    // (i 6 default restano invariati).
    const _def = byKey(sidepageKey);
    const _multiType = !!(_def?.allowedTypes && _def.allowedTypes.length > 1);

    await Promise.all(subjs.map(async (subj) => {
        if (sidepage.__fmToken !== token) return;
        try {
            const qs  = new URLSearchParams(_multiType
                ? { section: sidepageKey, ind, cls, subject: subj, limit: 500 }
                : { type, ind, cls, subject: subj, limit: 500 });
            const _pubSb = !!document.querySelector('nav.sidebar[data-fm-guest="1"]');
            const url = `${_pubSb ? "/api/public/study/content.json" : "/api/study/content.json"}?${qs}`;
            const res = await fetch(url, { credentials: "same-origin", headers: { Accept: "application/json" } });
            if (sidepage.__fmToken !== token) return;
            // G22.S25 — assertJson rileva HTML/redirect login → throw
            // FetchJsonError i18n italiano (catch a fondo loop).
            const j   = await assertJson(res, url);
            // G22.S25 — Escludi i contenuti archiviati dalla sidepage:
            // recupero via dashboard → Panoramica → Ricerca → ☑ archiviati.
            const rows = (j.rows || []).filter(r => (r.visibility || "") !== "archived");
            rows.sort((a, b) => {
                const na = parseFloat(a.topic), nb = parseFloat(b.topic);
                if (!isNaN(na) && !isNaN(nb)) return na - nb;
                return String(a.topic).localeCompare(String(b.topic));
            });
            const items = rows.length
                ? rows.map(r => itemLiHtml(type, ind, cls, subj, r)).join("")
                : EMPTY_ITEM_HTML;
            if (sidepage.__fmToken !== token) return;
            renderIntoSidepage(sidepage, type, subj, items);
            // Phase 18 — applica filter SUBITO dopo ogni render per
            // evitare il flickering (senza aspettare la fine di Promise.all).
            applyFilterToHost(sidepage, subj, selMater);
        } catch (e) {
            if (sidepage.__fmToken !== token) return;
            renderIntoSidepage(sidepage, type, subj,
                `<li class="fm-error">Errore ${subj}: ${esc(e.message || e)}</li>`);
            applyFilterToHost(sidepage, subj, selMater);
        }
    }));

    if (sidepage.__fmToken !== token) return;
    applySubjectFilter(sidepage, selMater);

    // Phase 18 — signal che il render DB è completo. section-edit-mode
    // ascolta e ri-attacca le inline actions (✎🗑👁) se la sidepage è
    // in edit mode attivo.
    document.dispatchEvent(new CustomEvent("fm:db-sidepage-rendered", {
        detail: { sidepage, type, sidepageKey },
    }));
}

// ─────── Category-grouped loader (Phase 24.72 — verif) ───────
//
// Per le sidepage con `def.group === "category"` (verif): l'asse primario
// non è la materia ma la categoria scelta dal docente (Compito in classe,
// Recupero, Orale, …). Materia diventa filtro item-level via #sel-mater.
//
// Pattern allineato a risdoc-sidepage.renderCategory ma senza template
// istituzionali / fork: solo teacher_content per quel type.
async function loadDbContentByCategory(def) {
    const sidepage = sidepageOf(def.key);
    if (!sidepage) return;

    const token = (sidepage.__fmToken || 0) + 1;
    sidepage.__fmToken = token;

    const ind = readSelect("sel-iis");
    const cls = readSelect("sel-cls");
    const selMater = readSelect("sel-mater");

    // Phase 24.72 — preserva il btn .js-edit-section (auth marker) anche se
    // ind/cls mancano: senza di esso `bindSidebarEditButtons` non riconosce
    // più il pannello come editabile dopo la selezione.
    cleanSidepageKeepingEditBtn(sidepage);
    injectNewCategoryButton(sidepage, def);

    if (!ind || !cls) return;  // niente fetch finché non c'è scope

    let rows = [];
    try {
        // ADR-027 — multi-tipo → per `section`; mono-tipo → per `type`.
        const _multiType = !!(def.allowedTypes && def.allowedTypes.length > 1);
        const qs = new URLSearchParams(_multiType
            ? { section: def.key, indirizzo: ind, classe: cls, with_metadata: "1", limit: 500 }
            : { type: def.type, indirizzo: ind, classe: cls, with_metadata: "1", limit: 500 });
        const qsKey = qs.toString();
        // Sicurezza visibilità: SOLO i docenti/admin (marker server-side
        // .js-edit-section) usano /api/teacher/content (vede anche i draft
        // propri). Gli STUDENTI usano l'endpoint pubblico /api/study/content.json
        // che filtra server-side a visibility=published + scope della sezione:
        // così un documento non pubblicato NON compare mai come voce cliccabile
        // nella sidebar dello studente. (Prima: /api/teacher/content → 403
        // role:teacher → "Errore caricamento" per gli studenti.)
        const canEdit = !!sidepage.querySelector(".js-edit-section");
        const _pubSb = !!document.querySelector('nav.sidebar[data-fm-guest="1"]');
        const endpoint = canEdit
            ? `/api/teacher/content?${qsKey}`
            : `${_pubSb ? "/api/public/study/content.json" : "/api/study/content.json"}?${qsKey}`;
        const cacheKey = (canEdit ? "t:" : "s:") + qsKey;
        // Phase 25.A4 — ETag conditional GET. Il server ritorna 304 se la
        // signature (max updated_at + count) non è cambiata: niente body.
        const headers = { "Accept": "application/json" };
        const cached = __etagCache.get(cacheKey);
        if (cached?.etag) headers["If-None-Match"] = cached.etag;
        const res = await fetch(endpoint, {
            credentials: "same-origin",
            headers,
        });
        if (sidepage.__fmToken !== token) return;
        if (res.status === 304 && cached) {
            rows = cached.body.rows || [];
        } else {
            // G22.S25 — assertJson per gestire session-expired/HTML response.
            const j = await assertJson(res, endpoint);
            rows = j.rows || [];
            const newEtag = res.headers.get("ETag");
            if (res.ok && newEtag) __etagCache.set(cacheKey, { etag: newEtag, body: j });
        }
    } catch (e) {
        if (sidepage.__fmToken !== token) return;
        sidepage.appendChild(Object.assign(document.createElement("div"), {
            className: "fm-error",
            textContent: `Errore caricamento ${def.type}: ${e.message || e}`,
        }));
        return;
    }

    // G22.S25 — Escludi gli archived dalla sidepage: i contenuti archiviati
    // sono in "cestino logico" e devono essere recuperati dalla dashboard
    // (Panoramica → Ricerca → ☑ archiviati → ↩ Ripristina).
    const visibleRows = rows.filter(r => (r.visibility || "") !== "archived");
    // Filtro materia (item-level) se #sel-mater non è "All"/vuoto.
    const filtered = (selMater && selMater !== "All")
        ? visibleRows.filter(r => (r.subject_code || "") === selMater)
        : visibleRows;

    // Group by metadata.category (default = def.defaultCategory, fallback "ALTRO").
    const fallbackCat = def.defaultCategory || "ALTRO";
    const byCat = {};
    for (const r of filtered) {
        const meta = parseMeta(r);
        const cat = String(meta.category || "").trim().toUpperCase() || fallbackCat;
        (byCat[cat] ??= []).push(r);
    }

    // Ordina categorie: defaultCategory prima, poi custom (alfabetico).
    const customCats = CustomCats.listForBucket({
        bucket: def.type, ind, cls, subj: selMater || "",
    });
    const allCats = new Set([fallbackCat, ...customCats.map(c => c.key), ...Object.keys(byCat)]);
    const sorted = [...allCats].sort((a, b) => {
        if (a === fallbackCat) return -1;
        if (b === fallbackCat) return 1;
        return a.localeCompare(b);
    });

    for (const cat of sorted) {
        if (sidepage.__fmToken !== token) return;
        renderCategoryGroup(sidepage, def, cat, byCat[cat] || [], { ind, cls });
    }

    document.dispatchEvent(new CustomEvent("fm:db-sidepage-rendered", {
        detail: { sidepage, type: def.type, sidepageKey: def.key, group: "category" },
    }));
}

function injectNewCategoryButton(sidepage, def) {
    sidepage.querySelectorAll(".fm-newcat-host").forEach(n => n.remove());
    if (!def.customCategories) return;
    // Solo se il docente ha permesso edit (la presenza del btn .js-edit-section
    // implica auth). Per gli studenti rendiamo solo le categorie esistenti.
    if (!sidepage.querySelector(".js-edit-section")) return;
    const host = document.createElement("div");
    host.className = "fm-newcat-host";
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "fm-btn fm-btn--xs fm-newcat-btn";
    btn.textContent = "✨ Nuova categoria";
    btn.title = "Crea una nuova categoria con scope opzionale";
    btn.addEventListener("click", async (e) => {
        e.preventDefault();
        const ind  = readSelect("sel-iis");
        const cls  = readSelect("sel-cls");
        const subj = readSelect("sel-mater");
        const created = await CustomCats.promptCreate({ bucket: def.type, ind, cls, subj });
        if (created) loadDbContentByCategory(def);
    });
    host.appendChild(btn);
    sidepage.appendChild(host);
}

function categoryLabel(def, key) {
    // Phase 24.73 — override etichetta PER-DOCENTE trasversale (store condiviso),
    // valido anche per le categorie db (verif): rende la rinomina cross-loader.
    const overrides = CatLabels.getOverrides();
    if (overrides[key]) return overrides[key];
    if (def.defaultCategory && key === def.defaultCategory) {
        return key; // es. "VERIFICHE"
    }
    const customs = CustomCats.loadAll();
    return customs[key]?.label || key;
}

function renderCategoryGroup(sidepage, def, category, rows, ctx) {
    const host = document.createElement("div");
    host.className = "fm-risdoc-cat";  // riusa lo stile della category-block.
    host.dataset.category = category;

    const ul = document.createElement("ul");
    ul.className = "fm-db-block";
    ul.dataset.type = def.type;
    ul.dataset.category = category;
    ul.dataset.section = category;
    ul.dataset.sectionKind = "category";

    const head = document.createElement("li");
    head.className = "fm-db-head";
    const label = document.createElement("span");
    label.className = "fm-db-head-label";
    label.textContent = categoryLabel(def, category);
    head.appendChild(label);

    // Edit btn clonato dal marker server-side (preserva auth detection).
    const origEdit = sidepage.querySelector(":scope > .js-edit-section");
    if (origEdit) {
        const clone = origEdit.cloneNode(true);
        clone.removeAttribute("data-fm-edit-bound");
        head.appendChild(clone);
    }
    // "+ Nuovo" per-categoria: pre-seleziona category nel modal teacher_content.
    if (origEdit) {
        const addBtn = document.createElement("button");
        addBtn.type = "button";
        addBtn.className = "fm-btn fm-btn--xs fm-section-add";
        addBtn.dataset.fmType = def.type;
        addBtn.dataset.fmPreCategory = category;
        addBtn.title = `Crea ${def.type} in ${categoryLabel(def, category)}`;
        addBtn.textContent = "➕";
        head.appendChild(addBtn);
    }
    ul.appendChild(head);

    if (!rows.length) {
        const empty = document.createElement("li");
        empty.className = "fm-muted";
        empty.style.fontSize = "11px";
        empty.textContent = "Nessun contenuto";
        ul.appendChild(empty);
    } else {
        rows.sort((a, b) => {
            const na = parseFloat(a.topic), nb = parseFloat(b.topic);
            if (!isNaN(na) && !isNaN(nb)) return na - nb;
            return String(a.topic).localeCompare(String(b.topic));
        });
        // Builder UNICO itemLiHtml (con class="linkref" → SPA nav, parità di
        // data-attr con il subject loader). showSubject: la materia varia per
        // riga nel category-grouped, quindi suffissa "· SUBJ" al titolo.
        for (const r of rows) {
            const li = liFromHtml(itemLiHtml(def.type, ctx.ind, ctx.cls, r.subject_code || "", r, { showSubject: true }));
            if (li) ul.appendChild(li);
        }
    }

    host.appendChild(ul);
    sidepage.appendChild(host);
}

// Phase 25.A1 — parseMeta importata da core/dom-utils.js

/**
 * Phase 18 — rimuove container materia + header residui dalla sidepage,
 * preservando il btn server-side `.js-edit-section` (ATTIVA/SALVA) e
 * qualsiasi toolbar già montata.
 */
/** Phase 20 — markup del btn edit, iniettato INLINE dentro .fm-db-head.
 *  Il btn originale server-side in .fm-sb-panel viene rimosso per evitare
 *  duplicati. bindSidebarEditButtons (idempotent) attacca il click
 *  handler sul nuovo btn via fm:db-sidepage-rendered event. */
function editBtnHtml(section) {
    return `<button class="fm-btn fm-btn--xs js-edit-section" `
         + `type="button" title="Modifica sezione" aria-label="Modifica sezione" `
         + `data-section="${esc(section)}" `
         + `data-action="toggle-edit-section"><strong>✎</strong></button>`;
}

function cleanSidepageKeepingEditBtn(sidepage) {
    // Phase 20 — se il btn .js-edit-section è nested dentro un ul che
    // sta per essere rimosso (spostato in .fm-db-head da render precedente),
    // ri-parentalo come figlio diretto di .fm-sb-panel PRIMA del cleanup.
    const editBtn = sidepage.querySelector(".js-edit-section");
    if (editBtn && editBtn.parentElement !== sidepage) {
        sidepage.appendChild(editBtn);
    }
    // `.materia` resta: e' l'header legacy delle sezioni materie iniettate
    // dai template PHP (MATEMATICA/GEOGRAFIA/FISICA) — zona protetta.
    // Phase 24.72 — include anche .fm-risdoc-cat / .fm-newcat-host per il
    // category-grouped path (verif).
    const toRemove = sidepage.querySelectorAll(
        ".fm-subj-host, #M, #G, #F, #MAT, #FIS, #GEO, .materia, ul.fm-db-block, "
        + ".fm-edit-toolbar-ghost, .fm-risdoc-cat, .fm-newcat-host"
    );
    toRemove.forEach((n) => n.remove());
}

/**
 * Phase 18 — applica display:none al container di una materia se non
 * matcha selMater corrente (o mostra se selMater vuoto / "All").
 */
function applyFilterToHost(sidepage, subj, selMater) {
    const host = findSubjectHost(sidepage, subj);
    if (!host) return;
    const show = !selMater || selMater === "All" || selMater === subj;
    host.style.display = show ? "" : "none";
}

/**
 * Phase 18 — legge le materie attive da #sel-mater options.
 * Fallback DINAMICO al catalogo curriculum (codesFor("materie")) se il select
 * non è presente o è vuoto — niente preset hardcoded ["MAT","FIS","GEO"]
 * (le materie sono per-istituto).
 */
function collectActiveSubjects() {
    const sel = document.getElementById("sel-mater");
    if (!sel) return codesFor("materie");
    const opts = Array.from(sel.options || [])
        .filter(o => !o.disabled && o.value && o.value !== "All")
        .map(o => o.value);
    // G22.S20 v2.C2 — dedup: dropdown può avere 2 entries per stessa materia
    // (es. legacy globale + institute-specific). Without dedup, Promise.all
    // dei fetch render 2 volte la stessa lista esercizi sotto la stessa label.
    const unique = Array.from(new Set(opts));
    return unique.length ? unique : codesFor("materie");
}

/**
 * Phase 18 — filtra i container materia in base a sel-mater.
 * Se mater="All" o vuoto → mostra tutti. Altrimenti mostra solo il
 * container corrispondente (tramite findSubjectHost).
 */
function applySubjectFilter(sidepage, selMater) {
    const hosts = sidepage.querySelectorAll(".fm-subj-host, #M, #G, #F, #MAT, #FIS, #GEO, #CHI, #STO, #ITA, #ING");
    const show = !selMater || selMater === "All";
    hosts.forEach(h => {
        const block = h.querySelector("ul.fm-db-block");
        if (!block) { h.style.display = "none"; return; }
        const subjBlock = block.dataset.subj || "";
        h.style.display = (show || subjBlock === selMater) ? "" : "none";
    });
}

function removeBlock(sidepage) {
    sidepage.querySelectorAll(".fm-db-block").forEach((b) => b.remove());
    delete sidepage.__fmLastBlockHtml;
    delete sidepage.__fmLastBlockType;
    delete sidepage.__fmLastSubj;
}

/**
 * Phase 18 — popola il container materia (#M/#G/#F — prima lettera di
 * subject_code) del sidepage con <li> dai rows DB.
 *
 * Strategia:
 *  - Trova container `#<LETTER>` (M/G/F o prima lettera di subj).
 *    Se manca, prepend al sidepage.
 *  - Rimuove `.materia` ridondante (header legacy "MATEMATICA" etc.)
 *    quando popoliamo noi: il btn sidebar già identifica la sezione.
 *  - Riempie o crea `<ul class="fm-db-block">` dentro il container.
 */
// ─────── Markup item condiviso (usato dal render full + update chirurgico) ───────

const EMPTY_ITEM_HTML = '<li class="fm-muted" style="font-size:11px">Nessun contenuto</li>';

/**
 * Markup di una singola <li> teacher_content. Sorgente UNICA del template così
 * il render full (loadDbContentBySubject) e gli update chirurgici (insert/update
 * post create/edit) producono DOM identico — niente divergenza.
 *
 * Phase 20 — href con ?ids=<id> (server carica solo quel content). class=linkref
 * → navigation in iframe senza reload sidebar (DOMManager bindLinkrefClick).
 */
function itemLiHtml(type, ind, cls, subj, r, opts = {}) {
    const slug = encodeURIComponent(r.topic || "");
    // ADR-027 — una sezione può contenere content_type diversi dal tipo del
    // pannello (es. un "document" creato col modello Personalizzabile dal ➕
    // della sezione Esercizi). L'href deve usare il TIPO REALE della riga
    // (content_type), non quello del pannello, altrimenti un document veniva
    // linkato come /studio/esercizio/. Fallback al tipo pannello se assente.
    const itemType = r.content_type || r.type || type;
    // WS4 — guest (sidebar pubblica): link alla vista pubblica read-only del
    // singolo contenuto (full-page nav, niente linkref/SPA che richiederebbe auth).
    const _pubGuest = !!document.querySelector('nav.sidebar[data-fm-guest="1"]');
    const href = _pubGuest
        ? `/public/studio/${r.id}`
        : `/studio/${itemType}/${escAttr(ind)}/${escAttr(cls)}/${escAttr(subj)}/${slug}?ids=${r.id}`;
    const num  = r.topic ? `<span class="fm-numarg">${esc(r.topic)}</span> ` : "";
    const hasPt = r.has_body_pt ? "1" : "0";
    // doc_roles ∈ "" | "D" | "C" | "R" | "DC" | "DCR" | … (server expone
    // metadata.doc_roles dal modal creazione "Personalizzabile"). Solo se
    // presente ed include almeno una lettera valida.
    const roles = (typeof r.doc_roles === "string" ? r.doc_roles.toUpperCase() : "")
        .replace(/[^DCR]/g, "");
    const rolesAttr = roles ? ` data-doc-roles="${roles}"` : "";
    // opts.showSubject — loader category-grouped (verif/document): la materia
    // varia per riga, quindi la mostriamo come suffisso "· SUBJ" nel titolo.
    const subjSuffix = (opts.showSubject && subj) ? ` · ${esc(subj)}` : "";
    // class="linkref" SEMPRE: la navigazione in-frame (SPA, DOMManager
    // bindLinkrefClick) dipende da questa classe. Senza, il click sull'<a>
    // fa una navigazione full-page (reload → "Sync interrotta dal cambio
    // pagina"). Builder UNICO per subject- e category-grouped: stessa markup,
    // stessa navigazione, niente divergenza.
    // Guest → full-page nav alla vista pubblica (no linkref/SPA). Altrimenti SPA in-frame.
    const aCls = _pubGuest ? "" : ' class="linkref"';
    return `<li data-content-id="${r.id}" data-has-body-pt="${hasPt}"${rolesAttr}>${num}<a${aCls} href="${href}">${esc(r.title || r.topic)}${subjSuffix}</a></li>`;
}

// Comparator topic: numerico se entrambi parsabili (es. "2.1" < "2.10"? no:
// parseFloat → 2.1 vs 2.10=2.1 → fallback locale), altrimenti localeCompare.
// Allineato all'ordinamento del render full (loadDbContentBySubject).
function compareTopics(a, b) {
    const na = parseFloat(a), nb = parseFloat(b);
    if (!isNaN(na) && !isNaN(nb) && na !== nb) return na - nb;
    return String(a).localeCompare(String(b));
}

function topicOfLi(li) {
    return li.querySelector(".fm-numarg")?.textContent?.trim() || "";
}

// Ri-ordina in-place le <li data-content-id> dentro un ul.fm-db-block (la
// fm-db-head resta primo figlio: non è in lista, gli appendChild la scavalcano).
function sortBlockItems(ul) {
    const items = [...ul.querySelectorAll("li[data-content-id]")];
    items.sort((a, b) => compareTopics(topicOfLi(a), topicOfLi(b)));
    items.forEach((li) => ul.appendChild(li));
}

function liFromHtml(html) {
    const tmp = document.createElement("template");
    tmp.innerHTML = html.trim();
    return tmp.content.firstElementChild;
}

function blockForSubject(sidepage, subj) {
    return sidepage.querySelector(`ul.fm-db-block[data-subj="${CSS.escape(subj || "")}"]`);
}

// Notifica i listener (section-edit-mode: re-attach inline actions ✎🗑 + restore
// edit state; sidepage-highlight: re-applica highlight). NIENTE refetch: è solo
// re-wiring sui <li> già presenti → nessun flicker.
function notifyRendered(sidepage, type, sidepageKey) {
    document.dispatchEvent(new CustomEvent("fm:db-sidepage-rendered", {
        detail: { sidepage, type, sidepageKey },
    }));
}

/**
 * Insert chirurgico di un nuovo item (post create) SENZA reload del pannello.
 * Ritorna false se il block materia non è renderizzato → il caller fa fallback
 * a refreshSidepage (es. materia filtrata/non visibile).
 */
function surgicalInsertItem({ sidepageKey, type, subj, row }) {
    const sidepage = sidepageOf(sidepageKey);
    if (!sidepage) return false;
    const ul = blockForSubject(sidepage, subj);
    if (!ul) return false;
    ul.querySelectorAll("li.fm-muted").forEach((n) => n.remove()); // "Nessun contenuto"
    const li = liFromHtml(itemLiHtml(type, readSelect("sel-iis"), readSelect("sel-cls"), subj, row));
    if (!li) return false;
    ul.appendChild(li);
    sortBlockItems(ul);
    notifyRendered(sidepage, type, sidepageKey);
    return true;
}

/**
 * Insert chirurgico per il loader CATEGORY-grouped (verif/document): inserisce
 * il nuovo item nel blocco della categoria invece che in quello materia. Ritorna
 * false (→ fallback refresh) se il pannello/blocco categoria non è renderizzato
 * o se il filtro materia attivo escluderebbe la riga (parità con loadDbContentByCategory).
 */
function surgicalInsertCategoryItem({ sidepageKey, type, category, subj, row }) {
    const sidepage = sidepageOf(sidepageKey);
    if (!sidepage) return false;
    const cat = String(category || "").trim();
    if (!cat) return false;
    // Rispetta il filtro materia attivo: se #sel-mater è una materia specifica e
    // la riga è di un'altra materia, un refresh la nasconderebbe → fallback.
    const selMater = readSelect("sel-mater");
    if (selMater && selMater !== "All" && subj && subj !== selMater) return false;
    const ul = sidepage.querySelector(
        `ul.fm-db-block[data-section-kind="category"][data-category="${CSS.escape(cat)}"]`,
    );
    if (!ul) return false;
    ul.querySelectorAll("li.fm-muted").forEach((n) => n.remove()); // "Nessun contenuto"
    const li = liFromHtml(itemLiHtml(type, readSelect("sel-iis"), readSelect("sel-cls"), subj, row, { showSubject: true }));
    if (!li) return false;
    ul.appendChild(li);
    sortBlockItems(ul);
    notifyRendered(sidepage, type, sidepageKey);
    return true;
}

/**
 * Update chirurgico in-place (post edit). Sostituisce la <li> esistente con
 * markup fresco e ri-ordina (il topic può essere cambiato). Ritorna false se
 * la <li> non esiste nel DOM → caller fa fallback.
 */
function surgicalUpdateItem({ sidepageKey, type, row }) {
    const sidepage = sidepageOf(sidepageKey);
    if (!sidepage) return false;
    const old = sidepage.querySelector(`li[data-content-id="${row.id}"]`);
    if (!old) return false;
    const ul = old.closest("ul.fm-db-block");
    // Category-grouped (verif/document): la materia varia per riga e non è sul
    // blocco (data-subj assente) → va presa dalla row e mostrata come suffisso
    // "· SUBJ" (showSubject). Subject-grouped: subj dal blocco materia.
    const isCategory = ul?.dataset?.sectionKind === "category";
    const subj = isCategory ? (row.subject_code || "") : (ul?.dataset?.subj || "");
    // Guard: in category senza materia nota produrremmo un href rotto
    // (/studio/type/ind/cls//slug) → meglio il fallback refresh del caller.
    if (isCategory && !subj) return false;
    const opts = isCategory ? { showSubject: true } : {};
    const fresh = liFromHtml(itemLiHtml(type, readSelect("sel-iis"), readSelect("sel-cls"), subj, row, opts));
    if (!fresh) return false;
    old.replaceWith(fresh);
    if (ul) sortBlockItems(ul);
    notifyRendered(sidepage, type, sidepageKey);
    return true;
}

/**
 * Remove chirurgico (post delete). Toglie la <li> e ripristina il placeholder
 * "Nessun contenuto" se il block resta vuoto. Niente reload → niente flicker.
 */
function surgicalRemoveItem({ sidepageKey, id }) {
    const sidepage = sidepageOf(sidepageKey);
    const li = sidepage?.querySelector(`li[data-content-id="${id}"]`);
    if (!li) return false;
    const ul = li.closest("ul.fm-db-block");
    li.remove();
    if (ul && ul.querySelectorAll("li[data-content-id]").length === 0) {
        ul.appendChild(liFromHtml(EMPTY_ITEM_HTML));
    }
    return true;
}

function renderIntoSidepage(sidepage, type, subj, itemsHtml) {
    const host = findSubjectHost(sidepage, subj);

    // Phase 18 — label materia:
    //   1. dal template legacy .materia se ancora presente
    //   2. da #sel-mater option[value=subj] .textContent (user-defined)
    //   3. mapping statico subjFullName (fallback)
    const legacyMateria = host.querySelector(".materia");
    const selOpt = document.querySelector(`#sel-mater option[value="${CSS.escape(subj)}"]`);
    const materiaLabel = (legacyMateria?.textContent || "").trim()
                      || (selOpt?.textContent || "").trim()
                      || subjFullName(subj);
    host.querySelectorAll(".materia").forEach((m) => m.remove());

    let ul = host.querySelector("ul.fm-db-block");
    if (!ul) {
        const legacyUl = host.querySelector("ul");
        ul = document.createElement("ul");
        ul.className = "fm-db-block";
        ul.dataset.type = type;
        ul.dataset.subj = subj || "";
        if (legacyUl) legacyUl.replaceWith(ul);
        else host.appendChild(ul);
    }
    // Phase 21 — btn edit iniettato in OGNI .fm-db-head (accanto al label
    // materia), uno per host visibile. L'originale server-side direct-child
    // di .fm-sb-panel resta come marker di auth (hasEditPermission) e viene
    // nascosto via CSS (.fm-sb-panel:has(.fm-db-head .js-edit-section) > .js-edit-section).
    // Vantaggi rispetto a Phase 20:
    //   - no race (tutti gli host, in parallelo, ricevono il btn)
    //   - no vanishing (filtro materia nasconde solo gli host filtrati:
    //     gli altri btn restano visibili accanto ai loro titoli)
    //   - auth detection robusta (originale preservato come marker)
    const section = sidepage.dataset.sidepage || sidepage.id || "";
    const hasEditPermission = !!sidepage.querySelector(".js-edit-section");
    // Phase 24.47 — uniformazione: ogni .fm-db-block ha data-section/data-section-kind
    // così il toggle .js-edit-section opera per-sezione.
    ul.dataset.section = subj || "";
    ul.dataset.sectionKind = "subject";
    const editInHead = (materiaLabel && hasEditPermission) ? editBtnHtml(section) : "";
    // Phase 24.47 — "+ Nuovo" per-materia uniformato a .fm-section-add
    // (stesso pattern di risdoc-sidepage). Visibilità via CSS quando
    // .fm-db-block[data-edit-active="1"]. data-fm-subj fa override
    // sul global #sel-mater nel createContent.
    const addInHead = (materiaLabel && hasEditPermission)
        ? `<button type="button" class="fm-btn fm-btn--xs fm-section-add"`
          + ` data-fm-type="${esc(type)}" data-fm-subj="${esc(subj || "")}"`
          + ` title="Crea ${esc(type)} in ${esc(materiaLabel)}">➕</button>`
        : "";
    const headHtml = materiaLabel
        ? `<li class="fm-db-head">`
          + `<span class="fm-db-head-label">${esc(materiaLabel)}</span>${
           editInHead
           }${addInHead
           }</li>`
        : "";
    ul.innerHTML = headHtml + itemsHtml;

    // Phase 18 — MutationObserver rimosso: il template legacy non viene
    // piu caricato (google-apps.loadSidebarContent non fa .load()) quindi
    // non serve piu ri-iniettare il block dopo un wipe.
    // Causava duplicazione: cleanSidepageKeepingEditBtn rimuoveva ul,
    // observer ri-iniettava stale HTML, poi render nuovo appendeva un
    // secondo host/ul.
}

function sidepageOf(key) {
    return document.getElementById(`fm-sp-${key}`);
}

// Phase 25.A1 — readSelect importata da core/dom-utils.js

/**
 * Phase 18 — trova il container dove inserire il block DB.
 * Strategia (in ordine):
 *   1. #<SUBJ_CODE> (es. #MAT / #FIS / #CHI / #STO) — template moderno
 *   2. #<LETTER> (es. #M / #G / #F) — template legacy (prima lettera)
 *   3. Se subj ha container custom via dataset es. [data-subj="CHI"]
 *   4. Fallback: root sidepage (block fuori dal template → OK ma
 *      senza tint materia).
 *
 * Se nessun container trovato, CREA un div host dedicato per preservare
 * l'accent styling + il titolo materia (fondamentale per materie nuove
 * non previste dal template legacy come CHI/STO).
 */
function findSubjectHost(sidepage, subj) {
    const code   = (subj || "").trim().toUpperCase();
    if (!code) return sidepage;
    // 1. full code (#MAT, #CHI, ...)
    const full = sidepage.querySelector(`#${  CSS.escape(code)}`);
    if (full) return full;
    // 2. first letter (#M, #G, #F) — template legacy
    const letter = code.charAt(0);
    const legacy = sidepage.querySelector(`#${  CSS.escape(letter)}`);
    if (legacy) return legacy;
    // 3. data-subj attribute
    const byAttr = sidepage.querySelector(`[data-subj="${code}"]`);
    if (byAttr) return byAttr;
    // 4. crea host dedicato per materie nuove (CHI, STO, ITA, ...)
    let dyn = sidepage.querySelector(`[data-fm-subj-host="${code}"]`);
    if (!dyn) {
        dyn = document.createElement("div");
        dyn.dataset.fmSubjHost = code;
        dyn.className = "fm-subj-host";
        sidepage.appendChild(dyn);
    }
    return dyn;
}

function subjFullName(subj) {
    const s = (subj || "").toUpperCase();
    return ({
        MAT: "MATEMATICA", FIS: "FISICA", GEO: "GEOMETRIA",
        CHI: "CHIMICA", STO: "STORIA", ART: "ARTE",
        ITA: "ITALIANO", ING: "INGLESE", SCI: "SCIENZE",
    }[s]) || s;
}

function labelOf(type) {
    return ({ mappa: "Mappe", esercizio: "Esercizi", verifica: "Verifiche", document: "Documenti" }[type]) || type;
}
function pluralLabel(type) {
    return ({ mappa: "mappe", esercizio: "esercizi", verifica: "verifiche", document: "documenti" }[type]) || type;
}
// Phase 25.A1 — esc/escAttr importate da core/dom-utils.js

window.addEventListener("fm:navigated", init);
if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
} else {
    queueMicrotask(init);
}

window.FM = window.FM || {};
window.FM.initDbSidepage = init;
window.FM.loadDbSidepageContent = loadDbContent;
window.FM.DB_SIDEPAGE_TYPE_MAP = SIDEPAGE_TYPE_MAP;
// Update chirurgici (no reload pannello → no flicker). Ritornano false se non
// applicabili (block non renderizzato / li assente) → il caller fa fallback a
// refreshSidepage. Usati da sidepage-modal-content (create/edit) e
// sidepage-inline-actions (delete).
window.FM.dbSidepageInsertItem = surgicalInsertItem;
window.FM.dbSidepageInsertCategoryItem = surgicalInsertCategoryItem;
window.FM.dbSidepageUpdateItem = surgicalUpdateItem;
window.FM.dbSidepageRemoveItem = surgicalRemoveItem;
