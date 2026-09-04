/**
 * Shared options fetcher per schemi risdoc con `options_source` (Phase 24.9).
 *
 * Fetcha la lista options remota per un field/section che dichiara
 * `options_source: {file}` (path statico assoluto) o `options_source: {folder}`
 * (path templato su state {indirizzo}/{disciplina}/{indirizzo}_{classe}_{disciplina}.json).
 *
 * Condivisa tra fm-risdoc-checkbox-group (legacy) e fm-risdoc-pt-section
 * (PT unificata) per evitare duplicazione + cache globale per URL.
 */

const _cache = new Map(); // url → Promise<options[]>

/** Combinazione indirizzo/classe/disciplina dall'URL della pagina studio
 *  (/studio/risdoc/{ind}/{cls}/{mat}/…). Fonte autorevole per un documento
 *  SALVATO quando lo state passato è incompleto. Vuoto fuori dalla pagina studio
 *  (modelli/onepath) → nessun effetto, si usa lo state esplicito.
 *  ADR-030 — UNICA implementazione del parsing URL studio (riusata da
 *  fm-pt-document._urlStateCombo / _lensTernaKey): evita la triplicazione del
 *  regex. */
export function studioUrlCombo() {
    try {
        const m = (window.location?.pathname || "").match(/\/studio\/risdoc\/([^/]+)\/([^/]+)\/([^/]+)/);
        if (!m) return {};
        const dec = (s) => { try { return decodeURIComponent(s); } catch (_) { return s; } };
        return { indirizzo: dec(m[1]), classe: dec(m[2]), disciplina: dec(m[3]) };
    } catch (_) { return {}; }
}

/** Chiave terna "ind/cls/mat" dall'URL studio, o null se incompleta. */
export function studioTernaKey() {
    const c = studioUrlCombo();
    return (c.indirizzo && c.classe && c.disciplina)
        ? `${c.indirizzo}/${c.classe}/${c.disciplina}` : null;
}

// Alias interno storico (usato sotto in fetchSchemaOptions).
const stateFromStudioUrl = studioUrlCombo;

// ── Feedback centralizzato degli ERRORI di caricamento dinamico ─────────────
// Solo veri errori HTTP/rete (la catch del fetch sotto scatta su !r.ok o reject),
// NON i risultati vuoti (200 + []). Debounce + dedupe: più sorgenti fallite nello
// stesso giro = 1 solo avviso nel pannello .fm-drive-sync-head (via sync-panel).
let _errTimer = null;
let _errN = 0;
function reportDynamicLoadError() {
    _errN++;
    if (_errTimer) clearTimeout(_errTimer);
    _errTimer = setTimeout(async () => {
        const n = _errN; _errN = 0; _errTimer = null;
        try {
            const { notify } = await import("../../modules/ui/sync-panel.js");
            notify("⚠ Contenuto dinamico", "warn",
                `Impossibile caricare ${n > 1 ? n + " sorgenti collegate" : "una sorgente collegata"} (rete/server). Le voci dinamiche restano vuote finché il caricamento non riesce — riprova o ricarica la pagina.`,
                7000);
        } catch (_) { /* sync-panel non disponibile: errore già in console */ }
    }, 500);
}

/**
 * @param {Object} itemOrSection  deve avere `.options_source`
 * @param {Object} state          { classe, sezione, indirizzo, disciplina }
 * @returns {Promise<Array<{value, label, default?, group?}>>}
 *          Array vuoto se URL non costruibile o fetch fallisce.
 */
export function fetchSchemaOptions(itemOrSection, state = {}) {
    const src = itemOrSection?.options_source;
    if (!src) return Promise.resolve([]);

    let url;
    if (typeof src === "object" && src.file) {
        url = `/risdoc/${src.file}`;
    } else {
        // ADR-025 (B) — risolutore dinamico: override istituto → globale (DB)
        // → fallback file statico. Passa i codici curriculum CANONICI diretti
        // (nessuna mappa hardcoded); il server costruisce il path file.
        const folder = typeof src === "string" ? src : src.folder;
        // Combinazione AUTOREVOLE del documento: lo state esplicito ha priorità,
        // ma se manca (window.FM.pt.currentState arriva vuoto/azzerato da race tra
        // più fm-pt-document) ripieghiamo sull'URL della pagina studio
        // /studio/risdoc/{ind}/{cls}/{mat}/… così le select folder-mode
        // (conoscenze/competenze/abilità) non restano vuote.
        const urlCombo = stateFromStudioUrl();
        const ind = state.indirizzo  || urlCombo.indirizzo;
        const cls = state.classe     || urlCombo.classe;
        const mat = state.disciplina || urlCombo.disciplina;
        if (!folder || !ind || !cls || !mat) return Promise.resolve([]);
        url = `/api/risdoc/curriculum-options?dataset=${encodeURIComponent(folder)}`
            + `&indirizzo=${encodeURIComponent(ind)}&classe=${encodeURIComponent(cls)}`
            + `&materia=${encodeURIComponent(mat)}`;
    }

    if (_cache.has(url)) return _cache.get(url);

    const p = fetch(url, { credentials: "same-origin" })
        .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
        .then((data) => parseOptionsJson(data))
        .catch((e) => {
            console.warn("[options-fetcher] fetch fail", url, e.message);
            reportDynamicLoadError();
            return [];
        });
    _cache.set(url, p);
    return p;
}

function parseOptionsJson(data) {
    if (!Array.isArray(data)) return [];
    const out = [];
    for (const node of data) {
        if (node?.contenuti && Array.isArray(node.contenuti)) {
            for (const item of node.contenuti) {
                out.push({
                    value: item.label,
                    label: item.label,
                    default: !!item.checked,
                    group: node.titolo,
                });
            }
        } else if (node?.label) {
            out.push({
                value: node.label,
                label: node.label,
                default: !!node.checked,
            });
        }
    }
    return out;
}

/** Walk ricorsivo section → trova TUTTI gli items con options_source. */
export function collectOptionsSourceItems(section, acc = []) {
    if (!section || typeof section !== "object") return acc;
    if (section.options_source && section.type !== "text-section") {
        acc.push(section);
    }
    if (Array.isArray(section.items)) {
        for (const item of section.items) collectOptionsSourceItems(item, acc);
    }
    return acc;
}
