/**
 * Binding per-terna dei VALORI dei campi (ADR-030 — "un documento, valori per
 * indirizzo/classe/materia").
 *
 * Idea: UN solo documento (una struttura). I campi 🔗 "legati alla terna"
 * salvano i loro VALORI per combinazione indirizzo/classe/materia; i campi 📌
 * "fissi" restano nel body_pt come sempre.
 *
 * Un campo è 🔗 se:
 *   - ha `options_source.folder` (le OPZIONI già dipendono dalla terna → anche
 *     il valore lo fa: inferenza automatica), OPPURE
 *   - dichiara esplicitamente `binding === "terna"` (toggle nel popover, per
 *     QUALSIASI componente inseribile dalla barra: Campo, Sì/No, Select, …).
 *
 * I valori per-terna viaggiano DENTRO il body_pt in un blocco speciale non
 * renderizzato `{_type:"ternaStore", store:{…}}` → ereditano la cifratura
 * per-docente di body_pt (nessun campo in chiaro nuovo, nessuna migrazione DB).
 *
 * Attivo SOLO con `metadata.terna_scoped === true`: i documenti esistenti
 * (flag assente) si comportano IDENTICI a prima.
 *
 * Chiavi campo (stabili, persistite nel body_pt):
 *   - componente top-level → `name` (generato se assente; trasportato via
 *     data-field-id nel roundtrip PT↔PM).
 *   - cella tabella        → `${table.name}#${cell.cid}` (cid per cella; vive
 *     dentro `rows`, preservato verbatim).
 */

const TERNA_STORE_TYPE = "ternaStore";

/**
 * Registro accessor per tipo di nodo: come leggere/scrivere/azzerare il VALORE
 * (ciò che cambia per terna). Aggiungere un componente = aggiungere una riga.
 * Il resto degli attributi del nodo è STRUTTURA (condivisa fra le terne).
 */
const ACCESSORS = {
    select:        { read: (n) => (typeof n.value === "string" ? n.value : ""),  write: (n, v) => { n.value = typeof v === "string" ? v : ""; },  blank: (n) => { n.value = ""; } },
    textField:     { read: (n) => (typeof n.value === "string" ? n.value : ""),  write: (n, v) => { n.value = typeof v === "string" ? v : ""; },  blank: (n) => { n.value = ""; } },
    formCheckbox:  { read: (n) => !!n.checked,                                     write: (n, v) => { n.checked = !!v; },                            blank: (n) => { n.checked = false; } },
    // ADR-030 scalabilità — salva SOLO i value selezionati (non l'oggetto pieno
    // {state,label,value}): label/state sono ricostruibili (state="x" implicito,
    // label ri-risolta dalla sorgente al render). Riduce ~3-5× la dimensione dello
    // store per i gruppi competenze/obiettivi (label lunghe), cruciale col limite
    // ~2MB del body_pt cifrato su doc d'istituto multi-classe. Retro-compat: write
    // accetta anche oggetti già espansi (store vecchi).
    checkboxGroup: {
        read: (n) => (Array.isArray(n.items) ? n.items : [])
            .filter((it) => it && (it.state === "x" || it.checked))
            .map((it) => String(it.value ?? it.label ?? "")),
        write: (n, v) => {
            n.items = Array.isArray(v)
                ? v.map((x) => (typeof x === "string" ? { state: "x", value: x, label: x } : x))
                : [];
        },
        blank: (n) => { n.items = []; },
    },
    rawTex:        { read: (n) => (typeof n.content === "string" ? n.content : ""), write: (n, v) => { n.content = typeof v === "string" ? v : ""; }, blank: (n) => { n.content = ""; } },
};

/** Un nodo top-level porta-valore è 🔗 (per classe)?
 *  ADR-030 — in un documento terna_scoped (il motore gira SOLO per quei doc) OGNI
 *  campo-valore è per-classe DI DEFAULT; fa eccezione solo chi è marcato 📌 fisso
 *  (binding:"fixed"). Le etichette/struttura (block/sectionHeader/text-cell) non
 *  sono campi-valore → restano condivise. */
function blockIsLinked(n) {
    if (!n || typeof n !== "object" || !ACCESSORS[n._type]) return false;
    return n.binding !== "fixed";
}

/** Una cella tabella è 🔗 (per classe)?
 *  - cella con WIDGET (input/select/checkbox): per-classe DI DEFAULT (salvo 📌).
 *  - cella di solo TESTO: di solito è un'etichetta → CONDIVISA di default; diventa
 *    per-classe solo se marcata esplicitamente 🔗 (binding:"terna"), perché non si
 *    può distinguere automaticamente un'etichetta ("TOTALE") da un valore ("666"). */
function cellIsLinked(cell) {
    if (!cell || typeof cell !== "object" || cell.formula) return false; // formula = struttura calcolata
    if (cell.widget) return cell.widget.binding !== "fixed" && cell.binding !== "fixed";
    return cell.binding === "terna";
}

/** Il documento ha almeno un campo 🔗? */
export function hasLinkedFields(blocks) {
    let found = false;
    walkLinked(blocks, { block: () => { found = true; }, cell: () => { found = true; } });
    return found;
}

/**
 * Visita i campi 🔗 del body_pt: nodi top-level porta-valore, celle tabella,
 * accordion ricorsivo. Per ciascuno chiama handlers.block(node) /
 * handlers.cell(cell, table). Gli handler possono mutare i nodi.
 */
function walkLinked(blocks, handlers) {
    if (!Array.isArray(blocks)) return;
    for (const n of blocks) {
        if (!n || typeof n !== "object") continue;
        if (n._type === "table" && Array.isArray(n.rows)) {
            for (const row of n.rows) {
                if (!Array.isArray(row)) continue;
                for (const cell of row) if (cellIsLinked(cell)) handlers.cell?.(cell, n);
            }
            continue;
        }
        if (n._type === "accordion" && Array.isArray(n.items)) {
            for (const it of n.items) walkLinked(it?.body_pt, handlers);
            continue;
        }
        if (blockIsLinked(n)) handlers.block?.(n);
    }
}

let _seq = 0;
function genId(prefix) {
    _seq += 1;
    let rnd = "";
    try { rnd = Math.random().toString(36).slice(2, 9); } catch (_) { rnd = String(_seq); }
    return `${prefix}_${rnd}${_seq.toString(36)}`;
}

/** Assegna id stabili (name ai nodi 🔗, name+cid alle tabelle/celle 🔗) dove
 *  mancano. Idempotente. */
export function ensureBindingIds(blocks) {
    walkLinked(blocks, {
        block: (n) => { if (!n.name) n.name = genId("fld"); },
        cell: (cell, table) => {
            if (!table.name) table.name = genId("tbl");
            if (!cell.cid) cell.cid = genId("c");
        },
    });
    return blocks;
}

function blockKey(n) { return n.name || null; }
function cellKey(cell, table) {
    if (!table.name || !cell.cid) return null;
    return `${table.name}#${cell.cid}`;
}
function readCellValue(cell) {
    if (!cell.widget) return typeof cell.text === "string" ? cell.text : ""; // cella di testo 🔗
    const w = cell.widget;
    if (Array.isArray(w.value)) return w.value.slice();
    return typeof w.value === "string" ? w.value : (w.value ?? "");
}
function blankCellValue(cell) {
    if (!cell.widget) { cell.text = ""; return; }
    cell.widget.value = Array.isArray(cell.widget.value) ? [] : "";
}
function writeCellValue(cell, v) {
    if (!cell.widget) { cell.text = typeof v === "string" ? v : ""; return; }
    cell.widget.value = (v === undefined || v === null) ? "" : v;
}

/**
 * SAVE — estrae i valori 🔗 della terna corrente in `store[ternaKey]` e li
 * AZZERA nei nodi (così il body_pt salvato è la sola struttura condivisa).
 * Muta `blocks` e `store`. @returns store aggiornato.
 */
export function extractTernaValues(blocks, ternaKey, store) {
    if (!ternaKey) return store || {};
    ensureBindingIds(blocks);
    const out = store && typeof store === "object" ? store : {};
    const delta = {};
    walkLinked(blocks, {
        block: (n) => {
            const k = blockKey(n);
            const acc = ACCESSORS[n._type];
            if (!k || !acc) return;
            delta[k] = acc.read(n);
            acc.blank(n);
        },
        cell: (cell, table) => {
            const k = cellKey(cell, table);
            if (!k) return;
            delta[k] = readCellValue(cell);
            blankCellValue(cell);
        },
    });
    out[ternaKey] = delta;
    return out;
}

/**
 * LOAD — sovrappone i valori salvati di `store[ternaKey]` ai campi 🔗. I campi
 * senza valore salvato per quella terna restano vuoti. Muta `blocks`.
 */
export function applyTernaValues(blocks, store, ternaKey) {
    if (!Array.isArray(blocks)) return blocks;
    ensureBindingIds(blocks);
    const t = (store && ternaKey && store[ternaKey]) ? store[ternaKey] : {};
    walkLinked(blocks, {
        block: (n) => {
            const k = blockKey(n);
            const acc = ACCESSORS[n._type];
            if (!k || !acc) return;
            acc.write(n, t[k]);
        },
        cell: (cell, table) => {
            const k = cellKey(cell, table);
            if (!k) return;
            writeCellValue(cell, t[k]);
        },
    });
    return blocks;
}

/** Separa il blocco ternaStore dal body_pt. @returns {{blocks, store}} */
export function splitTernaStore(blocks) {
    if (!Array.isArray(blocks)) return { blocks: [], store: {} };
    let store = {};
    const clean = [];
    for (const n of blocks) {
        if (n && typeof n === "object" && n._type === TERNA_STORE_TYPE) {
            if (n.store && typeof n.store === "object") store = n.store;
            continue;
        }
        clean.push(n);
    }
    return { blocks: clean, store };
}

/** Aggiunge in coda il blocco ternaStore al body_pt da salvare (no duplicati). */
export function attachTernaStore(blocks, store) {
    const clean = splitTernaStore(blocks).blocks;
    clean.push({ _type: TERNA_STORE_TYPE, store: store && typeof store === "object" ? store : {} });
    return clean;
}

/** Rimuove ogni blocco ternaStore (render/anteprima/export non devono vederlo). */
export function stripTernaStore(blocks) {
    return splitTernaStore(blocks).blocks;
}
