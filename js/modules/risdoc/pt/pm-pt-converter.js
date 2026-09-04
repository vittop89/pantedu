/**
 * Converter ProseMirror doc ↔ Portable Text (Phase 22.3).
 *
 * Tiptap/ProseMirror operano su un JSON doc format diverso da Portable Text.
 * Questo converter fa il bridge bidirezionale senza perdita, così:
 *   - Load editor: PT JSON  → PM doc  → Tiptap.setContent(pmDoc)
 *   - Save editor: Tiptap.getJSON() → PM doc → PT JSON
 *
 * Mapping:
 *   PT root array              ↔  PM {type:"doc", content:[...]}
 *   PT block (style normal)    ↔  PM {type:"paragraph", content:[...]}
 *   PT span (text, marks)      ↔  PM {type:"text", text, marks:[...]}
 *   PT fieldRef (name)         ↔  PM {type:"fieldRef", attrs:{name}}
 *   PT checkboxGroup (items)   ↔  PM {type:"checkboxGroup", attrs:{items}}
 *   PT rawTex (content)        ↔  PM {type:"rawTex", attrs:{content}}
 *
 * Mark names (PT Sanity convention → PM Tiptap default):
 *   strong  ↔  bold
 *   em      ↔  italic
 *   underline ↔ underline
 *   code    ↔  code
 *
 * Round-trip garantito per il subset POC: PT → PM → PT produce output
 * equivalente (a meno di normalizzazioni innocue: span vuoti droppati).
 */

/**
 * Portable Text AST → ProseMirror doc JSON.
 * @param {Array<Object>} pt
 * @returns {{type: "doc", content: Array<Object>}}
 */
export function ptToPmDoc(pt) {
    const blocks = Array.isArray(pt) ? pt : [];
    const content = [];
    let i = 0;
    while (i < blocks.length) {
        const b = blocks[i];
        // Liste: blocchi consecutivi con `listItem` formano una lista annidata
        // (ricostruita da PT flat → PM bulletList/orderedList gerarchico).
        if (b && b._type === "block" && b.listItem) {
            let j = i;
            while (j < blocks.length && blocks[j] && blocks[j]._type === "block" && blocks[j].listItem) j++;
            const node = buildListTree(blocks.slice(i, j));
            if (node) content.push(node);
            i = j;
            continue;
        }
        const pm = applyCarryToPm(blockToPm(b), b);
        if (pm) content.push(pm);
        i++;
    }
    return { type: "doc", content };
}

// 2026-05-27 — Unificazione: copia i metadati-CARRY (schema) PT↔PM così
// sopravvivono al round-trip dell'editor (vedi CarryAttributes in pm-schema).
function applyCarryToPm(pm, block) {
    if (!pm || !block) return pm;
    pm.attrs = pm.attrs || {};
    if (block.fieldType) pm.attrs.fieldType = String(block.fieldType);
    if (Array.isArray(block.columnKeys)) pm.attrs.columnKeys = block.columnKeys;
    if (block.seed_ref) pm.attrs.seedRef = String(block.seed_ref);
    if (block.fieldName) pm.attrs.fieldName = String(block.fieldName);
    // name + options_source: checkboxGroup/ptTable NON li dichiarano → li
    // portiamo via carry così sopravvivono (per identificare il campo in reverse).
    if (block.name && pm.attrs.name === undefined) pm.attrs.name = String(block.name);
    if (block.options_source && typeof block.options_source === "object" && pm.attrs.options_source === undefined) {
        pm.attrs.options_source = block.options_source;
    }
    // ADR-030 — binding per-terna ("terna" = 🔗 dipende da indirizzo/classe/materia).
    if (block.binding && pm.attrs.binding === undefined) pm.attrs.binding = String(block.binding);
    return pm;
}
function applyCarryToPt(pt, node) {
    if (!pt || !node || !node.attrs) return pt;
    const a = node.attrs;
    if (a.fieldType) pt.fieldType = String(a.fieldType);
    if (Array.isArray(a.columnKeys)) pt.columnKeys = a.columnKeys;
    if (a.seedRef) pt.seed_ref = String(a.seedRef);
    if (a.fieldName) pt.fieldName = String(a.fieldName);
    if (a.name && !pt.name) pt.name = String(a.name);
    if (a.options_source && typeof a.options_source === "object" && !pt.options_source) {
        pt.options_source = a.options_source;
    }
    if (a.binding && !pt.binding) pt.binding = String(a.binding);
    return pt;
}

/** Ricostruisce una lista PM annidata da un run di blocchi PT con listItem+level. */
function buildListTree(run) {
    if (!run.length) return null;
    const base = Math.min(...run.map((b) => Number.isInteger(b.level) ? b.level : 1));
    return buildListLevel(run, { idx: 0 }, base);
}
function buildListLevel(run, cursor, level) {
    const first = run[cursor.idx];
    const type = first.listItem === "number" ? "orderedList" : "bulletList";
    const listStyle = typeof first.listStyle === "string" ? first.listStyle : "";
    const listNode = { type, content: [] };
    if (listStyle) listNode.attrs = { listStyle };
    while (cursor.idx < run.length) {
        const cur = run[cursor.idx];
        const curLevel = Number.isInteger(cur.level) ? cur.level : 1;
        if (curLevel < level) break;            // risali al chiamante
        if (curLevel > level) break;            // (gestito dal figlio); safety
        const li = { type: "listItem", content: [textBlockToPm(cur)] };
        cursor.idx++;
        // Figli più profondi → sotto-lista dentro questo <li>.
        if (cursor.idx < run.length) {
            const nextLevel = Number.isInteger(run[cursor.idx].level) ? run[cursor.idx].level : 1;
            if (nextLevel > level) {
                li.content.push(buildListLevel(run, cursor, level + 1));
            }
        }
        listNode.content.push(li);
    }
    return listNode;
}

function blockToPm(block) {
    if (!block || typeof block !== "object" || !block._type) return null;
    switch (block._type) {
        case "block":          return textBlockToPm(block);
        case "checkboxGroup":  return {
            type: "checkboxGroup",
            attrs: {
                items: Array.isArray(block.items) ? block.items : [],
                renderMode: block.renderMode || "all",
                columns: Math.max(1, Math.min(5, parseInt(block.columns, 10) || 1)),
            },
        };
        case "rawTex":         return {
            type: "rawTex",
            attrs: { content: typeof block.content === "string" ? block.content : "" },
        };
        case "table":          return {
            type: "ptTable",
            attrs: {
                columns: Array.isArray(block.columns) ? block.columns : [],
                rows:    Array.isArray(block.rows) ? block.rows : [],
                caption: typeof block.caption === "string" ? block.caption : "",
                headerNote: typeof block.headerNote === "string" ? block.headerNote : "",
                footerNote: typeof block.footerNote === "string" ? block.footerNote : "",
                widthMode: block.widthMode === "full" ? "full" : "auto",
                colWidths: Array.isArray(block.colWidths) ? block.colWidths : [],
            },
        };
        case "select": return {
            type: "ptSelect",
            attrs: {
                name: block.name || "",
                label: block.label || "",
                value: block.value || "",
                options: Array.isArray(block.options) ? block.options : [],
                options_source: (block.options_source && typeof block.options_source === "object") ? block.options_source : null,
            },
        };
        case "textField": return {
            type: "ptTextField",
            attrs: {
                name: block.name || "",
                label: block.label || "",
                value: block.value || "",
                kind: block.kind || "text",
                placeholder: block.placeholder || "",
            },
        };
        case "formCheckbox": return {
            type: "ptFormCheckbox",
            attrs: {
                name: block.name || "",
                label: block.label || "",
                checked: !!block.checked,
            },
        };
        case "sectionHeader": return {
            type: "ptSectionHeader",
            attrs: {
                title: block.title || "",
                level: Number.isInteger(block.level) ? block.level : 2,
                selectors: Array.isArray(block.selectors) ? block.selectors : [],
                boxed: !!block.boxed,
                excluded: !!block.excluded,
            },
        };
        // G23 page-doc — glossary table (lemmi/definizioni/fonti)
        case "glossaryTable": return {
            type: "ptGlossaryTable",
            attrs: {
                name: block.name || "",
                columns: Array.isArray(block.columns) && block.columns.length >= 2
                    ? block.columns
                    : ["N.", "Lemma", "Definizione", "Fonte"],
                entries: Array.isArray(block.entries) ? block.entries : [],
                sortable:   block.sortable   !== false,
                searchable: block.searchable !== false,
            },
        };
        // G23 page-doc — staticContent
        case "staticContent": return {
            type: "ptStaticContent",
            attrs: {
                title:  block.title  || "",
                level:  Number.isInteger(block.level) ? block.level : 2,
                format: block.format || "html",
                body:   typeof block.body === "string" ? block.body : "",
                items:  Array.isArray(block.items) ? block.items : [],
            },
        };
        // G23 page-doc — accordion
        case "accordion": return {
            type: "ptAccordion",
            attrs: {
                items: Array.isArray(block.items) ? block.items : [],
                allow_multiple: block.allow_multiple !== false,
            },
        };
        // G23 page-doc — link list PDF
        case "linkListPdf": return {
            type: "ptLinkListPdf",
            attrs: {
                title: block.title || "",
                items: Array.isArray(block.items) ? block.items : [],
            },
        };
        // G23 page-doc — citation norma
        case "citationNorma": return {
            type: "ptCitationNorma",
            attrs: {
                tipo:     block.tipo     || "DM",
                numero:   block.numero   || "",
                anno:     block.anno     != null ? block.anno : "",
                articolo: block.articolo || "",
                title:    block.title    || "",
                href:     block.href     || "",
                quote:    block.quote    || "",
            },
        };
        default:               return null;
    }
}

function textBlockToPm(block) {
    const children = Array.isArray(block.children) ? block.children : [];
    const content = children.flatMap(inlineToPm).filter(Boolean);
    // Allineamento paragrafo (textAlign) → attrs PM. left/null = default (omesso).
    const attrs = (block.textAlign && block.textAlign !== "left")
        ? { textAlign: block.textAlign } : null;
    const node = { type: "paragraph" };
    if (content.length) node.content = content;
    if (attrs) node.attrs = attrs;
    return node;
}

function inlineToPm(child) {
    if (!child || typeof child !== "object") return [];
    if (child._type === "span") {
        const text = typeof child.text === "string" ? child.text : "";
        if (!text) return []; // skip span vuoti
        const marks = (Array.isArray(child.marks) ? child.marks : [])
            .map(ptMarkToPm).filter(Boolean);
        return [{
            type: "text",
            text,
            ...(marks.length ? { marks } : {}),
        }];
    }
    if (child._type === "fieldRef") {
        const name = typeof child.name === "string" ? child.name : "";
        if (!name) return [];
        return [{ type: "fieldRef", attrs: { name } }];
    }
    return [];
}

function ptMarkToPm(mark) {
    switch (mark) {
        case "strong":    return { type: "bold" };
        case "em":        return { type: "italic" };
        case "underline": return { type: "underline" };
        case "code":      return { type: "code" };
        default:          return null; // mark sconosciuto → scartato
    }
}

/**
 * ProseMirror doc JSON → Portable Text AST.
 * @param {{type: string, content?: Array<Object>}} pmDoc
 * @returns {Array<Object>}
 */
// Motore UNICO (ADR-026 Step 3): ogni nodo-campo del body_pt deve avere name +
// fieldType per essere uno schema-field valido (così il fork modello↔custom è
// simmetrico e le risposte del docente mappano sul campo). I campi inseriti dalla
// toolbar nascono senza name/fieldType → li auto-assegniamo al save. Stabile:
// dopo save→reopen il name è nel body_pt → ptToPmDoc lo rimette → preservato.
const FIELD_DEFAULT_TYPE = {
    select: "select",
    checkboxGroup: "checkbox-group",
    textField: "text-field",
    formCheckbox: "form-checkbox",
    table: "dynamic-table",
    glossaryTable: "glossary-table",
    linkListPdf: "link-list-pdf",
};
function genFieldName() {
    const r = (typeof crypto !== "undefined" && crypto.getRandomValues)
        ? crypto.getRandomValues(new Uint32Array(1))[0].toString(16)
        : Math.floor(Math.random() * 0xffffffff).toString(16);
    return "f_" + r.padStart(8, "0");
}
function ensureFieldCarry(block) {
    if (!block || typeof block !== "object") return block;
    const def = FIELD_DEFAULT_TYPE[block._type];
    if (!def) return block; // non è un campo schema-rilevante
    if (!block.fieldType) block.fieldType = def;
    if (!block.name) block.name = genFieldName();
    return block;
}

export function pmDocToPt(pmDoc) {
    if (!pmDoc || typeof pmDoc !== "object" || pmDoc.type !== "doc") return [];
    const content = Array.isArray(pmDoc.content) ? pmDoc.content : [];
    // flatMap: bulletList/orderedList si espandono in N blocchi listItem (flat).
    return content.flatMap((node) => {
        if (node?.type === "bulletList" || node?.type === "orderedList") {
            return pmListToPt(node, 1);
        }
        const b = ensureFieldCarry(applyCarryToPt(pmBlockToPt(node), node));
        return b ? [b] : [];
    });
}

/** Appiattisce una lista PM annidata → array di blocchi PT (listItem+level+listStyle). */
function pmListToPt(node, level) {
    const type = node.type === "orderedList" ? "number" : "bullet";
    const listStyle = typeof node.attrs?.listStyle === "string" ? node.attrs.listStyle : "";
    const out = [];
    for (const li of (Array.isArray(node.content) ? node.content : [])) {
        if (li?.type !== "listItem") continue;
        for (const child of (Array.isArray(li.content) ? li.content : [])) {
            if (child?.type === "paragraph") {
                const blk = pmParagraphToPt(child);
                blk.listItem = type;
                blk.level = level;
                if (listStyle) blk.listStyle = listStyle;
                out.push(blk);
            } else if (child?.type === "bulletList" || child?.type === "orderedList") {
                out.push(...pmListToPt(child, level + 1));
            }
        }
    }
    return out;
}

function pmBlockToPt(node) {
    if (!node || typeof node !== "object" || !node.type) return null;
    switch (node.type) {
        case "paragraph":      return pmParagraphToPt(node);
        case "checkboxGroup":  {
            const pt = {
                _type: "checkboxGroup",
                items: Array.isArray(node.attrs?.items) ? node.attrs.items : [],
            };
            if (node.attrs?.renderMode && node.attrs.renderMode !== "all") {
                pt.renderMode = node.attrs.renderMode;
            }
            const cgCols = Math.max(1, Math.min(5, parseInt(node.attrs?.columns, 10) || 1));
            if (cgCols >= 2) {
                pt.columns = cgCols;
            }
            return pt;
        }
        case "rawTex":         return {
            _type: "rawTex",
            content: typeof node.attrs?.content === "string" ? node.attrs.content : "",
        };
        case "ptTable":        {
            const pt = {
                _type: "table",
                columns: Array.isArray(node.attrs?.columns) ? node.attrs.columns : [],
                rows:    Array.isArray(node.attrs?.rows) ? node.attrs.rows : [],
            };
            if (typeof node.attrs?.caption === "string" && node.attrs.caption) {
                pt.caption = node.attrs.caption;
            }
            if (typeof node.attrs?.headerNote === "string" && node.attrs.headerNote) {
                pt.headerNote = node.attrs.headerNote;
            }
            if (typeof node.attrs?.footerNote === "string" && node.attrs.footerNote) {
                pt.footerNote = node.attrs.footerNote;
            }
            if (node.attrs?.widthMode === "full") {
                pt.widthMode = "full";
            }
            if (Array.isArray(node.attrs?.colWidths) && node.attrs.colWidths.length) {
                pt.colWidths = node.attrs.colWidths;
            }
            return pt;
        }
        case "ptSelect": {
            const pt = { _type: "select" };
            if (node.attrs?.name)  pt.name  = node.attrs.name;
            if (node.attrs?.label) pt.label = node.attrs.label;
            if (node.attrs?.value) pt.value = node.attrs.value;
            pt.options = Array.isArray(node.attrs?.options) ? node.attrs.options : [];
            if (node.attrs?.options_source && typeof node.attrs.options_source === "object") {
                pt.options_source = node.attrs.options_source;
            }
            return pt;
        }
        case "ptTextField": {
            const pt = { _type: "textField" };
            if (node.attrs?.name)  pt.name  = node.attrs.name;
            if (node.attrs?.label) pt.label = node.attrs.label;
            if (node.attrs?.value) pt.value = node.attrs.value;
            if (node.attrs?.kind && node.attrs.kind !== "text") pt.kind = node.attrs.kind;
            if (node.attrs?.placeholder) pt.placeholder = node.attrs.placeholder;
            return pt;
        }
        case "ptFormCheckbox": {
            const pt = {
                _type: "formCheckbox",
                label: node.attrs?.label || "",
                checked: !!node.attrs?.checked,
            };
            if (node.attrs?.name) pt.name = node.attrs.name;
            return pt;
        }
        case "ptSectionHeader": {
            const pt = {
                _type: "sectionHeader",
                title: node.attrs?.title || "",
            };
            if (Number.isInteger(node.attrs?.level)) pt.level = node.attrs.level;
            if (Array.isArray(node.attrs?.selectors) && node.attrs.selectors.length) {
                pt.selectors = node.attrs.selectors;
            }
            if (node.attrs?.boxed) pt.boxed = true;
            if (node.attrs?.excluded) pt.excluded = true;
            return pt;
        }
        // G23 page-doc — glossary table
        case "ptGlossaryTable": {
            const pt = {
                _type: "glossaryTable",
                columns: Array.isArray(node.attrs?.columns) && node.attrs.columns.length >= 2
                    ? node.attrs.columns
                    : ["N.", "Lemma", "Definizione", "Fonte"],
                entries: Array.isArray(node.attrs?.entries) ? node.attrs.entries : [],
            };
            if (node.attrs?.name) pt.name = node.attrs.name;
            if (node.attrs?.sortable === false)   pt.sortable   = false;
            if (node.attrs?.searchable === false) pt.searchable = false;
            return pt;
        }
        // G23 page-doc — staticContent
        case "ptStaticContent": {
            const pt = { _type: "staticContent" };
            if (node.attrs?.title) pt.title = node.attrs.title;
            if (Number.isInteger(node.attrs?.level) && node.attrs.level !== 2) pt.level = node.attrs.level;
            if (node.attrs?.format && node.attrs.format !== "html") pt.format = node.attrs.format;
            if (node.attrs?.body)  pt.body  = node.attrs.body;
            if (Array.isArray(node.attrs?.items) && node.attrs.items.length) pt.items = node.attrs.items;
            return pt;
        }
        // G23 page-doc — accordion
        case "ptAccordion": {
            const pt = {
                _type: "accordion",
                items: Array.isArray(node.attrs?.items) ? node.attrs.items : [],
            };
            if (node.attrs?.allow_multiple === false) pt.allow_multiple = false;
            return pt;
        }
        // G23 page-doc — link list PDF
        case "ptLinkListPdf": {
            const pt = {
                _type: "linkListPdf",
                items: Array.isArray(node.attrs?.items) ? node.attrs.items : [],
            };
            if (node.attrs?.title) pt.title = node.attrs.title;
            return pt;
        }
        // G23 page-doc — citation norma
        case "ptCitationNorma": {
            const pt = { _type: "citationNorma", tipo: node.attrs?.tipo || "DM" };
            if (node.attrs?.numero)   pt.numero   = node.attrs.numero;
            if (node.attrs?.anno)     pt.anno     = node.attrs.anno;
            if (node.attrs?.articolo) pt.articolo = node.attrs.articolo;
            if (node.attrs?.title)    pt.title    = node.attrs.title;
            if (node.attrs?.href)     pt.href     = node.attrs.href;
            if (node.attrs?.quote)    pt.quote    = node.attrs.quote;
            return pt;
        }
        default:               return null;
    }
}

function pmParagraphToPt(node) {
    const children = Array.isArray(node.content) ? node.content : [];
    const ptChildren = children.flatMap(pmInlineToPt).filter(Boolean);
    const pt = {
        _type: "block",
        style: "normal",
        children: ptChildren,
    };
    // Allineamento (Google Docs): persisti solo se non-default (left/justify
    // sono il default tipografico LaTeX → omessi per PT pulito; center/right sì).
    const ta = node.attrs?.textAlign;
    if (ta === "center" || ta === "right" || ta === "justify") pt.textAlign = ta;
    return pt;
}

function pmInlineToPt(node) {
    if (!node || typeof node !== "object" || !node.type) return [];
    if (node.type === "text") {
        const text = typeof node.text === "string" ? node.text : "";
        if (!text) return [];
        const marks = (Array.isArray(node.marks) ? node.marks : [])
            .map(pmMarkToPt).filter(Boolean);
        return [{
            _type: "span",
            text,
            marks,
        }];
    }
    if (node.type === "fieldRef") {
        const name = typeof node.attrs?.name === "string" ? node.attrs.name : "";
        if (!name) return [];
        return [{ _type: "fieldRef", name }];
    }
    return [];
}

function pmMarkToPt(mark) {
    if (!mark || typeof mark !== "object" || !mark.type) return null;
    switch (mark.type) {
        case "bold":      return "strong";
        case "italic":    return "em";
        case "underline": return "underline";
        case "code":      return "code";
        default:          return null;
    }
}
