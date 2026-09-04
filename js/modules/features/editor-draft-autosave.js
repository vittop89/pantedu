/**
 * Phase G19.14 — Auto-save draft per `.fm-editor-panel`.
 *
 *  Persiste in IndexedDB lo stato dei textarea/input dell'editor inline
 *  ogni 5 secondi. Su `beforeunload` se ci sono draft non salvati,
 *  mostra warning. Al riapertura del panel di un item con draft trovato,
 *  offre recovery via toast/confirm.
 *
 *  Storage: IndexedDB `fm-editor-drafts` / store `drafts` con record
 *  `{ key: itemId, fields, savedAt, panelTitle }`.
 *
 *  Lifecycle:
 *    - openItemEditor() → controlla recovery + bind input listener
 *    - input/change su .fm-editor-field|meta|correct|rmlayout → debounced save (5s)
 *    - saveItemEditor() → cancella draft (commit a server)
 *    - closeItemEditor() (cancel) → mantiene draft per recovery
 */

const DB_NAME = "fm-editor-drafts";
const DB_VER  = 1;
const STORE   = "drafts";
const SAVE_DEBOUNCE_MS = 5000;

let _dbPromise = null;

function openDb() {
    if (_dbPromise) return _dbPromise;
    _dbPromise = new Promise((resolve, reject) => {
        if (!("indexedDB" in window)) {
            reject(new Error("IndexedDB non supportato"));
            return;
        }
        const req = indexedDB.open(DB_NAME, DB_VER);
        req.onerror = () => reject(req.error);
        req.onsuccess = () => resolve(req.result);
        req.onupgradeneeded = (e) => {
            const db = e.target.result;
            if (!db.objectStoreNames.contains(STORE)) {
                db.createObjectStore(STORE, { keyPath: "key" });
            }
        };
    });
    return _dbPromise;
}

async function dbGet(key) {
    try {
        const db = await openDb();
        return new Promise((resolve) => {
            const tx = db.transaction(STORE, "readonly");
            const req = tx.objectStore(STORE).get(key);
            req.onsuccess = () => resolve(req.result || null);
            req.onerror = () => resolve(null);
        });
    } catch (_) { return null; }
}

async function dbPut(record) {
    try {
        const db = await openDb();
        return new Promise((resolve) => {
            const tx = db.transaction(STORE, "readwrite");
            const req = tx.objectStore(STORE).put(record);
            req.onsuccess = () => resolve(true);
            req.onerror = () => resolve(false);
        });
    } catch (_) { return false; }
}

async function dbDelete(key) {
    try {
        const db = await openDb();
        return new Promise((resolve) => {
            const tx = db.transaction(STORE, "readwrite");
            const req = tx.objectStore(STORE).delete(key);
            req.onsuccess = () => resolve(true);
            req.onerror = () => resolve(false);
        });
    } catch (_) { return false; }
}

/** Raccoglie i fields correnti dal panel (mirror di saveItemEditor
 *  in checkin-handlers ma senza il submit).
 *
 *  G22.S15 — se la textarea ha blocchi TikZ collassati a marker
 *  (`⟨🔍 TikZ #N⟩`), salva il VALORE ESPANSO (con `<script>` reali).
 *  Altrimenti il recover restituirebbe solo il marker plain text e
 *  perderebbe il TikZ vero. */
function collectFieldsFromPanel(panel) {
    if (!panel) return null;
    const fields = {};
    const expand = window.FM?.MultiTikzHelpers?.expandedValue;
    panel.querySelectorAll(".fm-editor-field").forEach((ta) => {
        fields[ta.dataset.field] = expand ? expand(ta) : ta.value;
    });
    panel.querySelectorAll(".fm-editor-radio:checked").forEach((r) => {
        fields[r.dataset.field] = r.value;
    });
    const meta = {};
    panel.querySelectorAll(".fm-editor-meta").forEach((inp) => {
        meta[inp.dataset.field] = inp.value;
    });
    if (Object.keys(meta).length) fields.metadata = meta;
    return fields;
}

/** Applica i campi salvati al panel (recovery).
 *  G22.S15.bis Fase 5 — i field salvati sono ESPANSI (HTML reale di TikZ/
 *  GeoGebra invece dei marker ⟨...⟩, vedi collectFieldsFromPanel). Al
 *  restore dobbiamo ri-collassare per mostrare i marker nel textarea +
 *  popolare i blocks per l'editor avanzato. */
function applyFieldsToPanel(panel, fields) {
    if (!panel || !fields) return;
    const collapseTikz = window.FM?.MultiTikzHelpers?.collapseTikzBlocks;
    const collapseGgb  = window.FM?.MultiTikzHelpers?.collapseGeoGebraBlocks;
    panel.querySelectorAll(".fm-editor-field").forEach((ta) => {
        if (fields[ta.dataset.field] !== undefined) {
            let v = fields[ta.dataset.field];
            // Re-collapse TikZ + GeoGebra → marker, popola block list.
            if (typeof collapseTikz === "function") {
                const r = collapseTikz(v);
                v = r.collapsed;
                ta._tikzBlocks = r.blocks;
            }
            if (typeof collapseGgb === "function") {
                const r = collapseGgb(v);
                v = r.collapsed;
                ta._geogebraBlocks = r.blocks;
            }
            ta.value = v;
            // Re-render bottoni "🔍 Modifica TikZ" / "📐 Modifica GeoGebra"
            if (typeof ta._renderTikzButtons === "function") ta._renderTikzButtons();
            ta.dispatchEvent(new Event("input", { bubbles: true }));
        }
    });
    panel.querySelectorAll(".fm-editor-meta").forEach((inp) => {
        if (fields.metadata?.[inp.dataset.field] !== undefined) {
            inp.value = fields.metadata[inp.dataset.field];
            inp.dispatchEvent(new Event("input", { bubbles: true }));
        }
    });
    panel.querySelectorAll(".fm-editor-radio").forEach((r) => {
        if (fields[r.dataset.field] !== undefined && r.value === fields[r.dataset.field]) {
            r.checked = true;
        }
    });
}

const _saveTimers = new Map(); // key → timer id
const _activeBindings = new Map(); // key → config { watchEl, statusContainer, saveFn, getFields }

/** Generic schedule save: usa la config registrata per `key` (target-agnostic).
 *  Supporta target item (editor inline) e group (problem header/intro). */
function scheduleSave(key) {
    const cfg = _activeBindings.get(key);
    if (!cfg) return;
    const { watchEl, statusContainer, saveFn, getFields } = cfg;
    const committed = _committedRecently.get(key);
    if (committed && (Date.now() - committed) < 5_000) return;
    if (watchEl && !watchEl.isConnected) return;
    if (_saveTimers.has(key)) clearTimeout(_saveTimers.get(key));
    const t = setTimeout(async () => {
        const c2 = _committedRecently.get(key);
        if (c2 && (Date.now() - c2) < 5_000) return;
        if (watchEl && !watchEl.isConnected) return;
        // IDB backup (opzionale)
        if (typeof getFields === "function") {
            const fields = getFields();
            if (fields && Object.keys(fields).length > 0) {
                await dbPut({ key, fields, savedAt: Date.now(), url: location.pathname });
            }
        }
        // Server save silenzioso
        try {
            const ok = await saveFn();
            _updateStatusBadge(statusContainer, ok ? "saved" : "local-only");
        } catch (_) {
            _updateStatusBadge(statusContainer, "local-only");
        }
    }, SAVE_DEBOUNCE_MS);
    _saveTimers.set(key, t);
}

/** Status badge Google Docs-style. Container-agnostic.
 *  Stati: "saving" (in volo), "saved" (server OK), "local-only" (solo IDB). */
const STATUS_STATES = {
    saving:       { text: "Salvataggio…", color: "#fff", bg: "#9ca3af" },
    saved:        { text: "✓ Salvato",    color: "#fff", bg: "#10b981" },
    "local-only": { text: "⚠ Solo locale", color: "#fff", bg: "#f59e0b" },
};

/** Container globale per il badge autosave: sempre in topbar (priority chain):
 *  1. `.fm-topbar__zone--eser` (zone esercizi del topbar)
 *  2. `#fm-topbar` (root topbar)
 *  3. `.fm-editor-toolbar` (toolbar globale editor)
 *  4. statusContainer locale fornito (fallback)
 *
 *  Un solo badge per sessione: l'ultimo target attivo update wins (stile Google
 *  Docs). Evita duplicazioni se ho più editor aperti simultaneamente. */
function _resolveStatusContainer(localFallback) {
    return document.querySelector(".fm-topbar__zone--eser")
        || document.getElementById("fm-topbar")
        || document.querySelector(".fm-editor-toolbar")
        || localFallback
        || document.body;
}

function _updateStatusBadge(localContainer, state) {
    const container = _resolveStatusContainer(localContainer);
    if (!container) return;
    // Cerca badge esistente DENTRO il container globale (singleton di sessione)
    let badge = container.querySelector(":scope > .fm-autosave-status")
              || container.querySelector(".fm-autosave-status");
    if (!badge) {
        badge = document.createElement("span");
        badge.className = "fm-autosave-status";
        badge.style.cssText = "display:inline-flex;align-items:center;padding:3px 10px;margin:0 6px;font:11px/1.2 system-ui;border:1px solid transparent;border-radius:10px;opacity:0.85;transition:opacity 0.3s, color 0.2s, background 0.2s;vertical-align:middle";
        container.appendChild(badge);
    }
    const s = STATUS_STATES[state] || STATUS_STATES.saved;
    badge.textContent = s.text;
    badge.style.color = s.color;
    badge.style.background = s.bg;
    badge.style.opacity = "1";
    if (state === "saved") {
        clearTimeout(badge._fadeTimer);
        badge._fadeTimer = setTimeout(() => { badge.style.opacity = "0.4"; }, 2000);
    }
}

/** Generic bind: registra una config di autosave su un watchEl.
 *  Config:
 *    - key: identificatore univoco (es. "item-<id>" o "group-<ref>")
 *    - watchEl: elemento su cui ascoltare input/change (capture phase)
 *    - statusContainer: elemento dove mountare il badge
 *    - saveFn: async () => bool (server save, true=ok false=fallito)
 *    - getFields: () => object (opzionale, per IDB backup)
 *
 *  Idempotente: re-bind sullo stesso watchEl aggiorna la config in-place.
 */
function bindAutosaveOn(config) {
    if (!config || !config.key || !config.watchEl) return;
    const { key, watchEl } = config;
    _activeBindings.set(key, config);
    if (watchEl.dataset.fmDraftBound === "1") return; // listener già attached
    watchEl.dataset.fmDraftBound = "1";
    watchEl.dataset.fmDraftKey = key;
    const handler = () => {
        _updateStatusBadge(config.statusContainer, "saving");
        scheduleSave(key);
    };
    watchEl.addEventListener("input", handler, true);
    watchEl.addEventListener("change", handler, true);
}

/** Unbind: rimuove la config attiva (al close editor). */
function unbindAutosaveKey(key) {
    _activeBindings.delete(key);
    if (_saveTimers.has(key)) {
        clearTimeout(_saveTimers.get(key));
        _saveTimers.delete(key);
    }
}

/** Legacy wrapper per backward compat: bind autosave per editor item.
 *  Risolve item + statusContainer dal panel structure. */
function bindAutosave(panel, itemId) {
    if (!panel || !itemId) return;
    bindAutosaveOn({
        key: `item-${itemId}`,
        watchEl: panel,
        statusContainer: panel.querySelector(".fm-editor-panel-header > div:first-child") || panel,
        saveFn: async () => {
            const item = panel.closest(".fm-collection__item");
            if (item && window.FM?.EditorServerAutosave?.saveItem) {
                return await window.FM.EditorServerAutosave.saveItem(item, panel);
            }
            return false;
        },
        getFields: () => collectFieldsFromPanel(panel),
    });
}

/** Recovery: se esiste un draft per l'item appena aperto, chiede recovery. */
async function offerRecovery(panel, itemId) {
    if (!panel || !itemId) return;
    const existing = await dbGet(itemId);
    if (!existing?.fields) return;
    // Skip se il draft è > 7 giorni (probabilmente stale)
    const ageMs = Date.now() - (existing.savedAt || 0);
    if (ageMs > 7 * 24 * 60 * 60 * 1000) {
        await dbDelete(itemId);
        return;
    }
    // G22.S15 — draft pre-fix contengono solo marker `⟨🔍 TikZ #N⟩` senza
    // i blocchi `<script>` espansi. Restorarli produce uno stato rotto:
    // marker plain text + zero TikZ. Drop con notifica.
    const hasOrphanMarker = Object.values(existing.fields).some((v) =>
        typeof v === "string"
        && /⟨🔍 TikZ #\d+⟩/.test(v)
        && !/<script\s+type=["']text\/tikz["']/i.test(v)
    );
    if (hasOrphanMarker) {
        await dbDelete(itemId);
        if (window.FM?.ToastManager?.show) {
            window.FM.ToastManager.show(
                "warning",
                "Draft scartato",
                "Draft pre-aggiornamento contenente marker TikZ orfani: rimosso (compatibilita').",
                5000,
            );
        }
        return;
    }
    const ageDesc = ageMs < 60_000
        ? "pochi secondi fa"
        : ageMs < 3600_000
        ? `${Math.round(ageMs / 60_000)} minuti fa`
        : ageMs < 86400_000
        ? `${Math.round(ageMs / 3600_000)} ore fa`
        : `${Math.round(ageMs / 86400_000)} giorni fa`;
    const ok = await window.FM.Dialog.confirm(
        `Recovery: trovato draft locale per questo quesito (salvato ${ageDesc}).\n\n` +
        `Caricare le modifiche non salvate?\n\n` +
        `[OK] = ripristina draft\n[Annulla] = ignora draft (sarà rimosso)`
    );
    if (ok) {
        applyFieldsToPanel(panel, existing.fields);
    } else {
        await dbDelete(itemId);
    }
}

/** G22.S15.bis Fase 5 — cleanup di un draft per itemId:
 *  1. Cancella eventuale timer di autosave pending (debounce in volo)
 *  2. Rimuove l'entry dalla map _saveTimers
 *  3. Marca l'id come "committato" → scheduleSave ignorerà future input
 *     (max 5s) per evitare race con typeset MathJax che dispatcha input.
 *  4. dbDelete del draft IndexedDB.
 *
 *  Senza questo, l'evento input dispatchato post-save (es. dal MathJax
 *  re-typeset, dal cleanup del CodeMirror, dalla rimozione del panel)
 *  poteva ri-schedulare uno scheduleSave che dopo dbDelete ricreava
 *  il draft → al re-open: recovery offerto su un draft "fantasma". */
const _committedRecently = new Map(); // key → timestamp

async function _cleanupDraftFor(key) {
    // `key` può essere itemId legacy (no prefix) o key esplicita (item-X / group-X)
    const candidates = key.includes("-") ? [key] : [key, `item-${key}`, `group-${key}`];
    for (const k of candidates) {
        if (_saveTimers.has(k)) {
            clearTimeout(_saveTimers.get(k));
            _saveTimers.delete(k);
        }
        _committedRecently.set(k, Date.now());
        _activeBindings.delete(k);
        try { await dbDelete(k); } catch (_) {}
    }
    // GC: rimuovi entry vecchie (>10s) per evitare leak della map
    const cutoff = Date.now() - 10_000;
    for (const [k, t] of _committedRecently.entries()) {
        if (t < cutoff) _committedRecently.delete(k);
    }
}

/** API pubblica generica + legacy item.
 *  - `bindOn(config)`: API moderna per qualsiasi target (item, group, …)
 *  - `bind(panel, itemId)`: legacy wrapper item editor
 *  - `recover(panel, itemId)`: dialog di recovery dal draft IDB
 *  - `commit(key)`: cleanup post-save server (rimuove timer + IDB)
 *  - `drop(key)`: cleanup su cancel (alias di commit)
 *  - `setStatus(container, state)`: aggiorna badge "saving/saved/local-only"
 *  - `unbind(key)`: rimuove binding senza commit (e.g. editor close)
 */
/** G23.fix12 — Cancella SOLO il timer pendente per `key` senza affettare
 *  IDB draft o commit recently. Da chiamare PRIMA di un save manuale (es.
 *  `_flushAutosaveAndClose`) per evitare race condition: timer scaduto
 *  durante save manuale → 2 PATCH in parallelo → version conflict.
 *
 *  Differenza vs `commit/drop`: NON cancella IDB draft (preservato per
 *  recovery se save manuale fallisce) e NON setta committedRecently (cosi'
 *  eventuali edit successivi al save manuale schedulano nuovo save). */
function cancelScheduledSave(key) {
    const candidates = key.includes("-") ? [key] : [key, `item-${key}`, `group-${key}`];
    for (const k of candidates) {
        if (_saveTimers.has(k)) {
            clearTimeout(_saveTimers.get(k));
            _saveTimers.delete(k);
        }
    }
}

window.FM = window.FM || {};
window.FM.EditorDraft = {
    bindOn:    (config) => bindAutosaveOn(config),
    bind:      (panel, itemId) => bindAutosave(panel, itemId),
    recover:   (panel, itemId) => offerRecovery(panel, itemId),
    commit:    (key) => _cleanupDraftFor(key),
    drop:      (key) => _cleanupDraftFor(key),
    unbind:    (key) => unbindAutosaveKey(key),
    // G23.fix12 — Cancella timer pendente (no commit, no IDB delete).
    cancelScheduled: (key) => cancelScheduledSave(key),
    setStatus: (container, state) => _updateStatusBadge(container, state),
};

/** beforeunload warning se ci sono draft attivi non salvati. */
window.addEventListener("beforeunload", (e) => {
    const hasDirty = document.querySelector(".fm-editor-panel[data-fm-draft-bound='1']");
    if (hasDirty) {
        e.preventDefault();
        e.returnValue = "Hai modifiche non salvate nell'editor. Vuoi davvero uscire?";
        return e.returnValue;
    }
});
