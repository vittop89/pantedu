/**
 * Sidepage registry — single source of truth (Phase 24.71).
 *
 * Centralizza il mapping fra:
 *   - sidepage key (DOM data-sidepage / button id)
 *   - panel id (#fm-sp-<key>)
 *   - loader (db-sidepage vs risdoc-sidepage)
 *   - content_type per teacher_content (mappa/lab/esercizio/verifica/bes/risdoc)
 *   - origin per risdoc_templates (risdoc/strcomp)
 *   - grouping (subject vs category)
 *   - capability flags (multi-instance fork, free custom doc)
 *
 * Eliminava 3 mapping duplicati:
 *   • db-sidepage.js  SIDEPAGE_TYPE_MAP   (4 keys)
 *   • section-edit-mode.js SIDEPAGE_TO_TYPE (6 keys)
 *   • risdoc-sidepage.js SIDEPAGE_SPEC     (2 keys)
 *
 * Aggiungere un nuovo sidepage richiede SOLO 1 entry qui + il button HTML
 * in views/partials/sidebar.php (zero modifiche ai 3 consumer JS).
 */

/**
 * @typedef {object} SidepageDef
 * @property {string} key            data-sidepage value (chiave logica)
 * @property {string} panel          DOM id del .fm-sb-panel (#fm-sp-<key>)
 * @property {"db"|"risdoc"} loader  modulo che popola il pannello
 * @property {string} type           teacher_content content_type (semantica primaria)
 * @property {"subject"|"category"} group raggruppamento item nella sidepage
 * @property {string=} origin        risdoc_templates.origin (solo loader=risdoc)
 * @property {string[]=} categories  categorie default rendered (solo loader=risdoc)
 * @property {boolean=} supportsFork true ↔ "+ Nuovo" apre modal multi-instance fork
 * @property {boolean=} customCategories true ↔ il docente può creare categorie
 *                       custom (✨ Nuova categoria), persistite per-utente in
 *                       localStorage. Implicito su loader=risdoc; per loader=db
 *                       si applica solo a sidepage che lo dichiarano (verif).
 */

/**
 * Base hardcoded (fallback offline / pre-idratazione). ADR-027 Step 4: il
 * runtime può essere idratato da GET /api/sidebar/config via hydrate(), che
 * sovrascrive/aggiunge le sezioni (incluse le custom create dall'admin).
 *
 * @type {Record<string, SidepageDef>}
 */
const BASE_SIDEPAGES = ({
    // Sidepage per-materia: l'asse primario è la materia (MAT/FIS/GEO/…).
    // Le "categorie" qui = materie del docente (no custom).
    mappe:  { key: "mappe",  panel: "fm-sp-mappe",  loader: "db",
              type: "mappa",     group: "subject", customCategories: false },
    lab:    { key: "lab",    panel: "fm-sp-lab",    loader: "db",
              type: "esercizio", group: "subject", customCategories: false },
    eser:   { key: "eser",   panel: "fm-sp-eser",   loader: "db",
              type: "esercizio", group: "subject", customCategories: false },
    // Verifiche: asse primario = categoria custom (Compito in classe, Recupero,
    // Orale, …). Materia diventa filtro secondario via #sel-mater. Phase 24.72.
    verif:  { key: "verif",  panel: "fm-sp-verif",  loader: "db",
              type: "verifica",  group: "category", customCategories: true,
              defaultCategory: "VERIFICHE" },
    // Sidepage per-categoria con fork multi-instance (template istituzionali).
    bes:    { key: "bes",    panel: "fm-sp-bes",    loader: "risdoc",
              type: "document",  group: "category", customCategories: true,
              origin: "strcomp", categories: ["bes", "altro"],
              supportsFork: true },
    risdoc: { key: "risdoc", panel: "fm-sp-risdoc", loader: "risdoc",
              type: "document",  group: "category", customCategories: true,
              origin: "risdoc",  categories: ["modelli", "risorse"],
              supportsFork: true },
});

// Runtime mutabile: parte dalla base, idratabile da hydrate(). I consumer
// leggono SEMPRE da qui (byKey/dbLoaderDefs/…) così le sezioni custom
// configurate da admin diventano funzionali senza modifiche ai loader.
let _runtime = { ...BASE_SIDEPAGES };

/**
 * Backward-compat: alcuni consumer leggevano l'oggetto SIDEPAGES. Restiamo
 * un proxy sul runtime corrente (sola lettura logica).
 * @type {Record<string, SidepageDef>}
 */
export const SIDEPAGES = new Proxy({}, {
    get: (_t, prop) => _runtime[prop],
    has: (_t, prop) => prop in _runtime,
    ownKeys: () => Reflect.ownKeys(_runtime),
    getOwnPropertyDescriptor: (_t, prop) => prop in _runtime
        ? { enumerable: true, configurable: true, value: _runtime[prop] }
        : undefined,
});

/**
 * ADR-027 Step 4 — idrata il runtime con le sezioni dal server
 * (GET /api/sidebar/config). Sovrascrive le key fornite e aggiunge le custom.
 * Normalizza loader 'mixed' → 'risdoc' (category). Robusto a payload parziali.
 *
 * @param {Array<object>} list  sezioni server-shaped (key, loader, type, group, …)
 */
export function hydrate(list) {
    if (!Array.isArray(list) || !list.length) return;
    const next = { ...BASE_SIDEPAGES };
    for (const s of list) {
        const key = String(s.key || s.section_key || "").trim();
        if (!key) continue;
        const loaderRaw = String(s.loader || s.loader_kind || "db");
        const loader = loaderRaw === "db" ? "db" : "risdoc"; // mixed→risdoc
        next[key] = {
            key,
            panel: String(s.panel || ("fm-sp-" + key)),
            loader,
            type: String(s.type || s.default_content_type || ""),
            allowedTypes: Array.isArray(s.allowedTypes) ? s.allowedTypes
                        : (Array.isArray(s.allowed_content_types) ? s.allowed_content_types : undefined),
            group: (s.group || s.group_mode) === "subject" ? "subject" : "category",
            customCategories: !!(s.customCategories ?? s.custom_categories),
            allowTemplateFork: !!(s.allowTemplateFork ?? s.allow_template_fork),
            templateOrigin: s.templateOrigin || s.template_origin || undefined,
            templateGroups: Array.isArray(s.templateGroups) ? s.templateGroups
                          : (Array.isArray(s.template_groups) ? s.template_groups : undefined),
            origin: s.origin || undefined,
            categories: Array.isArray(s.categories) ? s.categories
                      : (Array.isArray(s.default_categories) ? s.default_categories : undefined),
            supportsFork: !!(s.supportsFork ?? s.supports_fork),
            defaultCategory: s.defaultCategory || undefined,
        };
    }
    _runtime = next;
    if (typeof document !== "undefined") {
        document.dispatchEvent(new CustomEvent("fm:sidebar-config-hydrated"));
    }
}

/** @returns {SidepageDef|null} */
export function byKey(key) {
    return _runtime[key] || null;
}

/** @returns {SidepageDef|null} */
export function byType(type) {
    for (const def of Object.values(_runtime)) {
        if (def.type === type) return def;
    }
    return null;
}

/** @returns {SidepageDef|null} */
export function byPanelId(panelId) {
    for (const def of Object.values(_runtime)) {
        if (def.panel === panelId) return def;
    }
    return null;
}

/**
 * Risolve il sidepage def partendo dall'elemento DOM (.fm-sb-panel).
 * @returns {SidepageDef|null}
 */
export function fromPanelEl(panel) {
    if (!panel) return null;
    return byKey(panel.dataset?.sidepage) || byPanelId(panel.id);
}

/**
 * @param {string} key
 * @returns {boolean} true ↔ "+ Nuovo" apre modal multi-instance fork
 */
export function supportsFork(key) {
    return !!SIDEPAGES[key]?.supportsFork;
}

// ADR-027 Step 4 — ricomputano dal runtime (idratabile), non più costanti
// frozen: così le sezioni custom rientrano nei loop di auto-popolamento.

/** @returns {SidepageDef[]} */
export function dbLoaderDefs() {
    return Object.values(_runtime).filter(d => d.loader === "db");
}

/** @returns {SidepageDef[]} */
export function risdocLoaderDefs() {
    return Object.values(_runtime).filter(d => d.loader === "risdoc");
}

// Espone su window.FM per consumer non-module (debug / E2E).
if (typeof window !== "undefined") {
    window.FM = window.FM || {};
    window.FM.SidepageRegistry = {
        SIDEPAGES, byKey, byType, byPanelId, fromPanelEl,
        supportsFork, dbLoaderDefs, risdocLoaderDefs, hydrate,
    };
}
