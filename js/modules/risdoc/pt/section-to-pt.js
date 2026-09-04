/**
 * Helper schema-section → PT AST (Phase 24.6).
 *
 * Converte una section del schema risdoc (con items/fields) in un array PT
 * AST pronto per essere caricato dentro <fm-risdoc-pt-editor>. Usato da
 * <fm-risdoc-pt-section> per rendere un'intera section come PT editor unico.
 *
 * Mapping type → PT block:
 *   nota-textarea     → block (da default PT o string legacy o empty)
 *   checkbox-group    → checkboxGroup (items da options + values selected)
 *   grade-selector    → select
 *   giudizio-item     → select
 *   info-field        → textField
 *   form-checkbox     → formCheckbox
 *   dynamic-table     → table
 *   text-section      → sectionHeader + ricorsivo items
 *   header            → sectionHeader con selectors
 *   static-content    → block plain text (html stripped)
 *
 * Values source: `fields[name]` del compilation (string, array, bool, PT AST).
 */

/**
 * @param {Object} section       Section schema (con items, title, description, type)
 * @param {Object} fields        Map name → value del compilation
 * @param {Object} dynamicOpts   Map fieldName → Array<{value,label,default?,group?}>
 *                               per items con options_source già fetchato
 * @returns {Array<Object>} PT AST blocks
 */
/**
 * ADR-026 — INVERSO di sectionSchemaToPt per il SAVE del percorso unico:
 * estrae i valori dei campi (`fields[name]`) dai nodi PT che portano `name`.
 * I blocchi display-only (sectionHeader, block, staticContent) non hanno valore
 * → ignorati (il resto del documento si rigenera dallo schema). Lossless sui
 * VALORI editabili (ciò che si salva nelle compilations).
 *
 * @param {Array<Object>} pt  PT AST (sezioni concatenate)
 * @returns {Object} fields  { nome: valore }
 */
export function ptToFields(pt) {
    const fields = {};
    const walk = (nodes) => {
        for (const n of (Array.isArray(nodes) ? nodes : [])) {
            if (!n || typeof n !== "object") continue;
            const name = typeof n.name === "string" && n.name ? n.name : null;
            switch (n._type) {
                case "select":
                case "textField":
                    if (name) fields[name] = typeof n.value === "string" ? n.value : "";
                    break;
                case "formCheckbox":
                    if (name) fields[name] = !!n.checked;
                    break;
                case "checkboxGroup":
                    if (name) {
                        const items = Array.isArray(n.items) ? n.items : [];
                        fields[name] = items
                            .filter((it) => it && it.state === "x")
                            .map((it) => (typeof it.value === "string" && it.value) ? it.value : String(it.label ?? ""));
                    }
                    break;
                case "table":
                    if (name) fields[name] = Array.isArray(n.rows) ? n.rows : [];
                    break;
                case "accordion":
                    for (const it of (Array.isArray(n.items) ? n.items : [])) walk(it?.body_pt);
                    break;
                default:
                    break;
            }
        }
    };
    walk(pt);
    return fields;
}

export function sectionSchemaToPt(section, fields = {}, dynamicOpts = {}) {
    const blocks = [];
    if (!section || typeof section !== "object") return blocks;

    // G22.S26 — Se la section pt_unified ha già un `default` PT array salvato
    // (es. dopo merge in admin-edit save), usalo come source-of-truth invece
    // di rigenerare da items[i].default. Senza questo l'admin che modifica
    // checkbox state via PT editor vedrebbe il rendering ricavato dai
    // default originali (sovrascritti dalla "vista unificata") + il default
    // salvato → doppio render + state non riflesso.
    if (section.pt_unified === true
        && Array.isArray(section.default)
        && section.default.length > 0
        && section.default.every((b) => b && typeof b === "object" && "_type" in b)) {
        if (typeof window !== "undefined" && window.FM_DEBUG) {
            console.log(`[section-to-pt] use section.default (${section.default.length} blocks) for "${section.title || section.name || "?"}"`);
        }
        return section.default;
    }

    // Section title as header (level 2 default). CARRY: se la section/text-section
    // ha un name, taggalo sull'header così il reverse ricostruisce il contenitore.
    if (section.title && section.type !== "header") {
        blocks.push({
            _type: "sectionHeader",
            title: String(section.title),
            level: 2,
            ...(section.name ? { fieldName: String(section.name), fieldType: String(section.type || "text-section") } : {}),
        });
    }
    if (section.description) {
        blocks.push({
            _type: "block", style: "normal",
            children: [{ _type: "span", text: String(section.description), marks: ["em"] }],
        });
    }

    // Se la section è essa stessa un field (type diverso da text-section/container),
    // processala come single item. Altrimenti itera su items.
    if (section.type && section.type !== "text-section" && !Array.isArray(section.items)) {
        blocks.push(...fieldToPt(section, fields, dynamicOpts));
        return blocks;
    }

    const items = Array.isArray(section.items) ? section.items : [];
    for (const item of items) {
        blocks.push(...fieldToPt(item, fields, dynamicOpts));
    }
    return blocks;
}

/**
 * ADR-026 Step 5 — migra UNA compilation (schema + fields salvati) al body_pt
 * unificato (formato custom). Walk delle sezioni in ordine:
 *  - sezione SENZA id il cui contenuto è salvato sotto chiave sintetica
 *    "section_<indice>_<slug(title)>" (blob PT del vecchio motore) → splice del
 *    blob così com'è (è già il render della sezione → lossless, reopen-equal);
 *  - altrimenti sectionSchemaToPt(section, fields, dynamicOpts) (i valori
 *    salvati contengono già label+stati risolti, dynamicOpts opzionale).
 * Provato lossless sulle 60 compilation reali (0 orphan significativi).
 */
export function compilationToBodyPt(schema, fields = {}, dynamicOpts = {}) {
    const sections = Array.isArray(schema?.sections) ? schema.sections : [];
    const body = [];
    sections.forEach((s, i) => {
        if (!s.id && (s.title || s.name)) {
            const key = `section_${i}_${slugifySectionTitle(s.title || s.name)}`;
            const blob = fields[key];
            if (Array.isArray(blob) && blob.length
                && blob.every((b) => b && typeof b === "object" && "_type" in b)) {
                body.push(...blob);
                return;
            }
        }
        body.push(...sectionSchemaToPt(s, fields, dynamicOpts));
    });
    return body;
}

/** Slug della chiave sintetica sezione (deve combaciare col vecchio motore). */
function slugifySectionTitle(t) {
    return String(t || "").toLowerCase()
        .replace(/^\s*\d+(\.\d+)*\.?\s*/, "")          // toglie "1. " / "2.3 " iniziale
        .normalize("NFD").replace(/[̀-ͯ]/g, "")
        .replace(/[^a-z0-9]+/g, "_").replace(/^_+|_+$/g, "");
}

function fieldToPt(field, fields = {}, dynamicOpts = {}) {
    if (!field || typeof field !== "object") return [];
    const name = field.name || "";
    const value = name ? fields[name] : undefined;

    switch (field.type) {
        case "nota-textarea":       return notaTextareaToPt(field, value);
        case "checkbox-group":      return checkboxGroupToPt(field, value, dynamicOpts);
        case "grade-selector":
        case "giudizio-item":       return selectToPt(field, value, dynamicOpts);
        case "giudizio-group":      return giudizioGroupToPt(field, fields, dynamicOpts);
        case "info-field":          return textFieldToPt(field, value);
        case "form-checkbox":       return formCheckboxToPt(field, value);
        case "dynamic-table":       return dynamicTableToPt(field, value);
        case "header":              return headerToPt(field);
        case "text-section":        return sectionSchemaToPt(field, fields, dynamicOpts);
        case "static-content":      return staticContentToPt(field);
        // Unificazione (ADR-026 C2-full) — tipi modello → PT-type renderizzati,
        // così OGNI schema forka a un body_pt completo (no placeholder).
        case "glossary-table":      return glossaryTableToPt(field, value);
        case "link-list-pdf":       return linkListPdfToPt(field, value);
        case "privacy-block":       return privacyBlockToPt(field);
        case "signature-block":     return signatureBlockToPt(field);
        case "submit":
        case "reset":               return []; // pulsanti form: nessun blocco doc
        default:
            return [{
                _type: "block", style: "normal",
                children: [{
                    _type: "span",
                    text: `[${field.type || "?"}] ${field.title || field.label || field.name || ""}`,
                    marks: ["em"],
                }],
            }];
    }
}

function notaTextareaToPt(field, value) {
    // CARRY (motore unico): tag i blocchi della nota con fieldName+fieldType così
    // il reverse body_pt→schema sa che quei blocchi sono UNA nota-textarea nominata
    // (i blocchi sono "piatti"; il marker delimita il campo). Renderer ignorano i
    // campi extra. Solo il PRIMO blocco porta il marker (apre il campo).
    const name = field.name ? String(field.name) : "";
    const tag = (blocks) => {
        if (!name || !Array.isArray(blocks) || blocks.length === 0) return blocks;
        return blocks.map((b, i) => i === 0 ? { ...b, fieldName: name, fieldType: "nota-textarea" } : b);
    };
    // PT AST valido → pass-through
    if (Array.isArray(value) && value.length > 0
        && value.every((b) => b && typeof b === "object" && "_type" in b)) {
        return tag(value);
    }
    // String legacy → single block
    if (typeof value === "string" && value.trim() !== "") {
        const paragraphs = value.split(/\n\s*\n/).filter(p => p.trim());
        return tag(paragraphs.map(p => ({
            _type: "block", style: "normal",
            children: [{ _type: "span", text: p.replace(/\n/g, " ").trim(), marks: [] }],
        })));
    }
    // Schema default
    if (Array.isArray(field.default) && field.default.length > 0) {
        return tag(field.default);
    }
    // Fallback: block vuoto con label come heading (porta comunque il marker).
    const heading = field.label || field.title;
    const out = [{
        _type: "block", style: "normal",
        children: heading
            ? [{ _type: "span", text: String(heading), marks: ["strong"] }]
            : [{ _type: "span", text: "", marks: [] }],
    }];
    return tag(out);
}

export function checkboxGroupToPt(field, value, dynamicOpts = {}) {
    // Phase 24.9 — se il field dichiara options_source e abbiamo options
    // già fetchate via _options-fetcher, usale. Key = JSON stringified del
    // options_source (robusto: non dipende da field.name presente).
    let options = Array.isArray(field.options) ? field.options : [];
    if (field.options_source) {
        const key = JSON.stringify(field.options_source);
        if (Array.isArray(dynamicOpts[key])) {
            options = dynamicOpts[key];
        }
    }
    const selected = Array.isArray(value)
        ? value.filter(v => typeof v === "string")
        : [];

    // PT AST già → pass-through
    if (Array.isArray(value) && value.length > 0
        && value.every((b) => b && typeof b === "object" && "_type" in b)) {
        return value;
    }

    const out = [];
    if (field.title) {
        out.push({
            _type: "sectionHeader",
            title: String(field.title),
            level: 3,
        });
    }

    // Group by `.group` preservando ordine
    const groups = new Map();
    for (const o of options) {
        const obj = typeof o === "object" ? o : { value: String(o), label: String(o) };
        const gName = obj.group || "";
        if (!groups.has(gName)) groups.set(gName, []);
        const key = obj.value ?? obj.label ?? "";
        const label = obj.label ?? obj.value ?? "";
        let state = "_";
        if (selected.length > 0) {
            state = selected.includes(key) || selected.includes(label) ? "x" : "_";
        } else if (obj.default) {
            state = "x";
        }
        // ADR-026: `value` (key) sull'item + `name` sul gruppo rendono il PT
        // INVERTIBILE (ptToFields) senza perdita. Campi extra ignorati dai renderer.
        groups.get(gName).push({ state, label: String(label), value: String(key) });
    }
    const fieldName = field.name ? String(field.name) : "";
    // CARRY (motore unico): fieldType + options_source sul nodo → reverse
    // body_pt→schema senza perdita. options_source materializzato a runtime.
    const carry = {
        ...(fieldName ? { name: fieldName } : {}),
        fieldType: "checkbox-group",
        ...(field.options_source ? { options_source: field.options_source } : {}),
    };
    let emitted = false;
    for (const [gName, items] of groups.entries()) {
        if (gName) {
            out.push({
                _type: "block", style: "normal",
                children: [{ _type: "span", text: gName, marks: ["strong"] }],
            });
        }
        out.push({ _type: "checkboxGroup", ...carry, items });
        emitted = true;
    }
    // Nessuna opzione (es. options_source non ancora fetchato): emetti comunque
    // il nodo (vuoto) per NON perdere la struttura del campo (name+options_source).
    if (!emitted) out.push({ _type: "checkboxGroup", ...carry, items: [] });
    return out;
}

/**
 * ADR-026 Step 5 — DE-HYDRATION al salvataggio: i campi `options_source`
 * vengono espansi al render (hydration) in N nodi raggruppati (block-label +
 * checkboxGroup per gruppo), tutti con lo STESSO carry options_source. Salvarli
 * così gonfia il body_pt (es. competenze_DM2007 → ~600 nodi) e rischia il limite
 * 2MB. Questa funzione COLLASSA ogni run di nodi con lo stesso options_source in
 * UN solo checkboxGroup compatto con SOLO le voci selezionate (state "x"): le
 * opzioni complete si ri-fetchano al render. Preserva tutto il resto (edit utente,
 * checkbox statici senza options_source) intatto.
 */
export function dehydrateDynamicOptions(blocks) {
    if (!Array.isArray(blocks)) return blocks;
    const osKey = (n) => (n && n._type === "checkboxGroup" && n.options_source)
        ? JSON.stringify(n.options_source) : null;
    // block-label di gruppo = block con SOLO span in grassetto (emesso da
    // checkboxGroupToPt come header del gruppo). paragrafo vuoto = filler Tiptap.
    const isBoldLabel = (n) => n && n._type === "block" && Array.isArray(n.children)
        && n.children.length > 0
        && n.children.every((c) => c && c._type === "span" && (c.marks || []).includes("strong"));
    const isEmptyPara = (n) => n && n._type === "block"
        && (!Array.isArray(n.children) || n.children.every((c) => c && c._type === "span" && !String(c.text || "").trim()));

    const byKey = new Map(); // key → { firstIdx, lastIdx, name, options_source, selected:[] }
    for (let j = 0; j < blocks.length; j++) {
        const k = osKey(blocks[j]);
        if (!k) continue;
        let e = byKey.get(k);
        if (!e) { e = { firstIdx: j, lastIdx: j, name: blocks[j].name || null, options_source: blocks[j].options_source, selected: [] }; byKey.set(k, e); }
        e.lastIdx = j;
        if (!e.name && blocks[j].name) e.name = blocks[j].name;
        // Preserva impaginazione (renderMode + columns) del gruppo: settati dalla
        // mode-bar del NodeView, andrebbero persi al collasso → al re-render il
        // gruppo tornava a 1 colonna / "Tutti" finché non si ricaricava la pagina.
        if (!e.renderMode && blocks[j].renderMode) e.renderMode = blocks[j].renderMode;
        if (e.columns == null && blocks[j].columns != null) e.columns = blocks[j].columns;
        for (const it of (blocks[j].items || [])) if (it && (it.state === "x" || it.checked)) e.selected.push(it);
    }
    // Espande firstIdx all'indietro per assorbire la label-block del PRIMO
    // gruppo (es. block("Asse dei Linguaggi") che precede il primo cg). Senza
    // questo restava fuori dallo span e sopravviveva al dehydrate → growth.
    // emitIdx = posizione DOVE emettere il compact (estesa firstIdx, prima del
    // primo label). firstCgIdx = vero indice del primo cg (per il vecchio check).
    for (const e of byKey.values()) {
        e.cgFirstIdx = e.firstIdx; // preserva per riferimento
        let i = e.firstIdx - 1;
        while (i >= 0 && (isBoldLabel(blocks[i]) || isEmptyPara(blocks[i]))) i--;
        e.firstIdx = i + 1; // expanded, può coincidere con cgFirstIdx se nessun label precedente
    }
    // Rimozione SPAN-based (idempotente): nell'intervallo [first..last] di ogni
    // chiave rimuovi i suoi checkboxGroup + i block-label di gruppo + i paragrafi
    // vuoti (filler). Così la re-hydration ri-genera label puliti senza duplicare.
    const remove = new Set();
    // Mappa indice → chiave os, per il primo indice rimosso di ogni chiave
    // emettere il compact (anche se quel nodo è una label, non un cg).
    const emitAt = new Map(); // emitFirstIdx → key
    for (const [k, e] of byKey.entries()) {
        emitAt.set(e.firstIdx, k);
        for (let j = e.firstIdx; j <= e.lastIdx; j++) {
            const n = blocks[j];
            if (osKey(n) || isBoldLabel(n) || isEmptyPara(n)) remove.add(j);
        }
    }
    // Rebuild: tieni i nodi non-rimossi; al PRIMO indice di ogni chiave emetti
    // UN checkboxGroup compatto (options_source + SOLO selezioni → ri-fetch al render).
    const out = [];
    for (let j = 0; j < blocks.length; j++) {
        if (!remove.has(j)) { out.push(blocks[j]); continue; }
        const emitKey = emitAt.get(j);
        if (emitKey) {
            const e = byKey.get(emitKey);
            out.push({
                _type: "checkboxGroup",
                ...(e.name ? { name: e.name } : {}),
                fieldType: "checkbox-group",
                options_source: e.options_source,
                ...(e.renderMode ? { renderMode: e.renderMode } : {}),
                ...(e.columns != null ? { columns: e.columns } : {}),
                items: e.selected,
            });
        }
    }
    return out;
}

function selectToPt(field, value, dynamicOpts = {}) {
    // Phase 24.11b — select può avere options_source come checkbox-group
    let options = Array.isArray(field.options) ? field.options : [];
    if (field.options_source) {
        const key = JSON.stringify(field.options_source);
        if (Array.isArray(dynamicOpts[key]) && dynamicOpts[key].length > 0) {
            options = dynamicOpts[key];
        }
    }
    return [{
        _type: "select",
        ...(field.name ? { name: String(field.name) } : {}),
        ...(field.type ? { fieldType: String(field.type) } : {}),       // CARRY: grade-selector/giudizio-item/select
        ...(field.options_source ? { options_source: field.options_source } : {}),
        label: String(field.title || field.label || field.name || ""),
        value: typeof value === "string" ? value : "",
        options: options.map((o) => {
            if (typeof o === "object") {
                return { value: String(o.value ?? ""), label: String(o.label ?? o.value ?? "") };
            }
            return { value: String(o), label: String(o) };
        }),
    }];
}

function giudizioGroupToPt(field, fields, dynamicOpts = {}) {
    const items = Array.isArray(field.items) ? field.items : [];
    const out = [];
    if (field.title) {
        out.push({ _type: "sectionHeader", title: String(field.title), level: 3 });
    }
    for (const it of items) {
        out.push(...selectToPt(it, fields[it.name], dynamicOpts));
    }
    return out;
}

function textFieldToPt(field, value) {
    return [{
        _type: "textField",
        ...(field.name ? { name: String(field.name) } : {}),
        ...(field.type ? { fieldType: String(field.type) } : {}),       // CARRY: info-field
        label: String(field.title || field.label || field.name || ""),
        value: typeof value === "string" ? value : "",
        ...(field.kind ? { kind: field.kind } : {}),
        ...(field.placeholder ? { placeholder: String(field.placeholder) } : {}),
    }];
}

function formCheckboxToPt(field, value) {
    return [{
        _type: "formCheckbox",
        ...(field.name ? { name: String(field.name) } : {}),
        ...(field.type ? { fieldType: String(field.type) } : {}),       // CARRY: form-checkbox
        label: String(field.title || field.label || ""),
        checked: value === true || value === "true" || value === 1,
    }];
}

function dynamicTableToPt(field, value) {
    const cols = Array.isArray(field.columns)
        ? field.columns.map(c => typeof c === "object" ? String(c.label ?? c.value ?? "") : String(c))
        : [];

    // Fallback se schema ha rows (labels fixed) invece di columns
    if (cols.length === 0 && Array.isArray(field.rows)) {
        return [{
            _type: "table",
            ...(field.name ? { name: String(field.name) } : {}),
            fieldType: "dynamic-table",
            columns: ["Voce", "Valore"],
            rows: field.rows.map(r => [String(r.label || ""), typeof value?.[r.label || r.field] === "string" ? value[r.label || r.field] : ""]),
        }];
    }

    // Value: array di array o array di object → rows
    let rows = [];
    if (Array.isArray(value)) {
        rows = value.map(r => {
            if (Array.isArray(r)) return r.map(String);
            if (r && typeof r === "object") {
                return cols.map((c, i) => {
                    const field_c = field.columns?.[i];
                    const key = typeof field_c === "object" ? (field_c.field ?? field_c.value ?? field_c.label) : c;
                    return String(r[key] ?? "");
                });
            }
            return [];
        });
    }

    // CARRY: columnKeys = le chiavi-campo per colonna (per ricostruire le righe
    // come oggetti keyed in reverse, vedi pm-pt). fieldType per il reverse type.
    const columnKeys = Array.isArray(field.columns)
        ? field.columns.map((c, i) => typeof c === "object" ? String(c.field ?? c.value ?? c.label ?? i) : String(c))
        : [];
    return [{
        _type: "table",
        ...(field.name ? { name: String(field.name) } : {}),  // ADR-026: invertibile
        fieldType: "dynamic-table",
        ...(columnKeys.length ? { columnKeys } : {}),
        columns: cols.length > 0 ? cols : ["col"],
        rows,
        ...(field.title ? { caption: String(field.title) } : {}),
    }];
}

function headerToPt(field) {
    return [{
        _type: "sectionHeader",
        title: String(field.title || ""),
        level: 1,
        ...(Array.isArray(field.selectors) && field.selectors.length
            ? { selectors: field.selectors.map(String) }
            : {}),
    }];
}

function staticContentToPt(field) {
    const html = typeof field.html === "string" ? field.html : "";
    // Strip HTML tags per semplicità (POC); futuro: HTML → PT parser
    const text = html.replace(/<[^>]+>/g, "").trim();
    const name = field.name ? String(field.name) : "";
    // CARRY: emetti SEMPRE un blocco (anche vuoto) con marker fieldName+fieldType
    // se il campo ha un name → la struttura non si perde nel reverse.
    const marker = name ? { fieldName: name, fieldType: "static-content" } : {};
    if (!text && !name) return [];
    return [{
        _type: "block", style: "normal", ...marker,
        children: [{ _type: "span", text, marks: [] }],
    }];
}

// ── Unificazione (ADR-026 C2-full) — tipi modello → PT-type renderizzati ──
// Direzione FORWARD (fork → body_pt). entries/items del seed (seed_ref) vengono
// materializzati al fork (server/fetch); qui produciamo la STRUTTURA corretta.

function glossaryTableToPt(field, value) {
    if (Array.isArray(value) && value.length && value.every((b) => b && "_type" in b)) return value;
    const columns = Array.isArray(field.columns) && field.columns.length
        ? field.columns.map(String) : ["N.", "Lemma", "Definizione", "Fonte"];
    const entries = Array.isArray(value) ? value : (Array.isArray(field.entries) ? field.entries : []);
    return [{
        _type: "glossaryTable",
        ...(field.name ? { name: String(field.name) } : {}),
        fieldType: "glossary-table",
        ...(field.seed_ref ? { seed_ref: String(field.seed_ref) } : {}),
        columns, entries,
    }];
}

function linkListPdfToPt(field, value) {
    if (Array.isArray(value) && value.length && value.every((b) => b && "_type" in b)) return value;
    const items = Array.isArray(value) ? value : (Array.isArray(field.items) ? field.items : []);
    const title = String(field.title || field.label || "");
    // items vuoti → ptToHtml non renderebbe nulla: emetti comunque il nodo
    // linkListPdf (vuoto) col carry così la struttura del campo NON si perde.
    if (items.length === 0) {
        return [{
            _type: "linkListPdf", title, items: [],
            ...(field.name ? { name: String(field.name) } : {}),
            fieldType: "link-list-pdf",
            ...(field.seed_ref ? { seed_ref: String(field.seed_ref) } : {}),
        }];
    }
    return [{
        _type: "linkListPdf", title, items,
        ...(field.name ? { name: String(field.name) } : {}),
        fieldType: "link-list-pdf",
        ...(field.seed_ref ? { seed_ref: String(field.seed_ref) } : {}),
    }];
}

function privacyBlockToPt(field) {
    // Informativa privacy istituzionale (body_ref = frammento server-side).
    // Nel body_pt editabile: heading + paragrafo segnaposto (il testo
    // istituzionale viene iniettato a render/compile). CARRY su primo blocco.
    const name = field.name ? String(field.name) : "";
    const marker = name ? { fieldName: name, fieldType: "privacy-block",
        ...(field.body_ref ? { seed_ref: String(field.body_ref) } : {}) } : {};
    const out = [];
    if (field.title) {
        out.push({ _type: "sectionHeader", title: String(field.title), level: 3, ...marker });
    } else {
        out.push({ _type: "block", style: "normal", ...marker,
            children: [{ _type: "span", text: "Informativa sul trattamento dei dati (Art. 13 GDPR).", marks: ["em"] }] });
        return out;
    }
    out.push({
        _type: "block", style: "normal",
        children: [{ _type: "span", text: "Informativa sul trattamento dei dati (Art. 13 GDPR).", marks: ["em"] }],
    });
    return out;
}

function signatureBlockToPt(field) {
    const label = String(field.label || field.title || "Firma");
    const name = field.name ? String(field.name) : "";
    return [{
        _type: "block", style: "normal",
        ...(name ? { fieldName: name, fieldType: "signature-block" } : {}),
        children: [{ _type: "span", text: `${label}: _______________________________`, marks: [] }],
    }];
}
