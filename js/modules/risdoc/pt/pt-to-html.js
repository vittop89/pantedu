/**
 * Portable Text → HTML walker (Phase 22.1 POC).
 *
 * Specchio lato client del renderer PHP `App\Services\Risdoc\Pt\PtToTex`.
 * Stesso AST, output differente: HTML per live preview nell'editor.
 *
 * Scope POC — token supportati:
 *   Block:   block (style=normal), checkboxGroup, rawTex
 *   Inline:  span (marks strong/em/underline/code), fieldRef
 *
 * Output HTML è stringa (non DOM). Caller può:
 *   - inserirlo in un container via `el.innerHTML = ptToHtml(pt)`
 *   - servirlo via SSR (Node.js) per preview statico
 *
 * Zero dipendenze: funzione pura, testabile in isolation.
 */
import { computeTableValues, isFormula } from "./formula-engine.js"; // ADR-031 formule

/**
 * @param {Array<Object>} blocks  Portable Text root array
 * @returns {string} HTML string (blocchi concatenati, no wrapper)
 */
export function ptToHtml(blocks) {
    if (!Array.isArray(blocks)) return "";
    const segs = [];
    // Esclusione sezione (👁 in output off): salta header + contenuto fino al
    // prossimo sectionHeader con level <= a quello escluso (level-aware).
    let excludeUntil = null;
    for (let i = 0; i < blocks.length; i++) {
        const block = blocks[i];
        if (!block || typeof block !== "object" || !block._type) continue;
        if (block._type === "sectionHeader") {
            const hlvl = parseInt(block.level, 10) || 2;
            if (excludeUntil !== null && hlvl <= excludeUntil) excludeUntil = null;
            if (excludeUntil === null && block.excluded) { excludeUntil = hlvl; continue; }
        }
        if (excludeUntil !== null) continue;
        // Liste: run di blocchi listItem → ul/ol annidate (blocco a sé).
        if (block._type === "block" && block.listItem) {
            const run = [];
            while (i < blocks.length && blocks[i]?._type === "block" && blocks[i].listItem) {
                run.push(blocks[i]);
                i++;
            }
            i--;
            segs.push({ kind: "block", html: renderListRun(run) });
            continue;
        }
        // Blocco di testo: tieni il contenuto INLINE (non ancora in <p>) così un
        // campo inline adiacente (checkbox "solo spuntati inline") può fondersi
        // nella stessa frase invece di restare isolato a capoverso.
        if (block._type === "block") {
            const children = Array.isArray(block.children) ? block.children : [];
            const inner = children.map(renderInline).filter(Boolean).join("");
            if (!inner) continue;
            const align = ["center", "right", "justify", "left"].includes(block.textAlign) ? block.textAlign : null;
            segs.push({ kind: "para", html: inner, align });
            continue;
        }
        // checkboxGroup "solo spuntati inline" → segmento INLINE (flusso frase).
        if (block._type === "checkboxGroup" && block.renderMode === "checked-inline") {
            const html = renderCheckboxGroup(block);
            if (html) segs.push({ kind: "inline", html });
            continue;
        }
        // Altri blocchi (tabella, header, checkbox "tutti"/"solo spuntati", …) → blocco a sé.
        const rendered = renderBlock(block);
        if (rendered) segs.push({ kind: "block", html: rendered });
    }

    // Assemblaggio: un segmento INLINE fonde i paragrafi PRIMA e DOPO in un unico
    // <p> (flusso naturale della frase). Due paragrafi consecutivi (senza inline
    // in mezzo) restano separati. I blocchi veri (tabelle, header…) chiudono il
    // paragrafo corrente.
    const out = [];
    let buf = null; // { html, lastKind: "para"|"inline", align }
    const flushBuf = () => {
        if (buf && buf.html) {
            const st = buf.align ? ` style="text-align:${buf.align}"` : "";
            out.push(`<p${st}>${buf.html}</p>`);
        }
        buf = null;
    };
    for (const s of segs) {
        if (s.kind === "block") { flushBuf(); out.push(s.html); continue; }
        if (!buf) { buf = { html: s.html, lastKind: s.kind, align: s.kind === "para" ? s.align : null }; continue; }
        if (s.kind === "para" && buf.lastKind === "para") {
            flushBuf(); // due paragrafi normali consecutivi → separati
            buf = { html: s.html, lastKind: "para", align: s.align };
            continue;
        }
        buf.html += " " + s.html; // merge (corrente o precedente è inline)
        buf.lastKind = s.kind;
    }
    flushBuf();
    return out.join("\n");
}

/** Run di blocchi-lista PT → ul/ol annidate (preview client). */
function renderListRun(run) {
    let out = "";
    const stack = [];
    let open = 0;
    for (const b of run) {
        const level = Math.max(1, Number.isInteger(b.level) ? b.level : 1);
        const tag = b.listItem === "number" ? "ol" : "ul";
        const style = typeof b.listStyle === "string" ? b.listStyle : "";
        while (open < level) {
            const attr = style ? ` data-fm-list-style="${style}"` : "";
            out += `<${tag} class="fm-pt-list"${attr}>`;
            stack.push(tag);
            open++;
        }
        while (open > level) { out += `</${stack.pop()}>`; open--; }
        const inner = (Array.isArray(b.children) ? b.children : [])
            .map(renderInline).filter(Boolean).join("");
        out += `<li>${inner}</li>`;
    }
    while (open > 0) { out += `</${stack.pop()}>`; open--; }
    return out;
}

function renderBlock(block) {
    switch (block._type) {
        case "block":          return renderTextBlock(block);
        case "checkboxGroup":  return renderCheckboxGroup(block);
        case "rawTex":         return renderRawTex(block);
        case "table":          return renderTable(block);
        case "select":         return renderSelect(block);
        case "textField":      return renderTextField(block);
        case "formCheckbox":   return renderFormCheckbox(block);
        case "sectionHeader":  return renderSectionHeader(block);
        // G23 page-doc block types
        case "glossaryTable":  return renderGlossaryTable(block);
        case "staticContent":  return renderStaticContent(block);
        case "accordion":      return renderAccordion(block);
        case "linkListPdf":    return renderLinkListPdf(block);
        case "citationNorma":  return renderCitationNorma(block);
        default:               return ""; // _type sconosciuto → skip
    }
}

function renderTextBlock(block) {
    const children = Array.isArray(block.children) ? block.children : [];
    const inner = children.map(renderInline).filter(Boolean).join("");
    if (!inner) return "";
    // Allineamento (Google Docs): textAlign → style text-align.
    const align = ["center", "right", "justify", "left"].includes(block.textAlign) ? block.textAlign : null;
    const style = align ? ` style="text-align:${align}"` : "";
    return `<p${style}>${inner}</p>`;
}

function renderInline(child) {
    if (!child || typeof child !== "object" || !child._type) return "";
    switch (child._type) {
        case "span":     return renderSpan(child);
        case "fieldRef": return renderFieldRef(child);
        default:         return "";
    }
}

function renderSpan(span) {
    const text  = typeof span.text === "string" ? span.text : "";
    const marks = Array.isArray(span.marks) ? span.marks : [];
    let html = escapeHtml(text);
    if (marks.length === 0) return html;
    // marks[0] = più esterno, marks[last] = più interno. Applica da innermost.
    for (let i = marks.length - 1; i >= 0; i--) {
        html = applyMark(marks[i], html);
    }
    return html;
}

function applyMark(mark, inner) {
    switch (mark) {
        case "strong":    return `<strong>${inner}</strong>`;
        case "em":        return `<em>${inner}</em>`;
        case "underline": return `<u>${inner}</u>`;
        case "code":      return `<code>${inner}</code>`;
        default:          return inner; // mark sconosciuto → pass-through
    }
}

function renderFieldRef(node) {
    const name = typeof node.name === "string" ? node.name : "";
    if (!name) return "";
    const esc = escapeHtml(name);
    return `<span class="pt-field-ref" data-field="${esc}">[${esc}]</span>`;
}

function renderCheckboxGroup(block) {
    const items = Array.isArray(block.items) ? block.items : [];
    if (items.length === 0) return "";
    const mode = typeof block.renderMode === "string" ? block.renderMode : "all";
    const nCols = Math.max(1, Math.min(5, parseInt(block.columns, 10) || 1));
    // Impaginazione N colonne (column-count via style inline). La stringa chiude
    // l'attributo class e apre style; il `">` del template chiude lo style.
    const cols2 = nCols >= 2 ? ` fm-pt-cb--multicol" style="column-count:${nCols}` : "";
    if (mode === "checked-only" || mode === "checked-inline") {
        const checked = items
            .filter((it) => it && typeof it === "object" && it.state === "x")
            .map((it) => escapeHtml(typeof it.label === "string" ? it.label : ""))
            .filter(Boolean);
        if (checked.length === 0) return "";
        if (mode === "checked-inline") {
            return `<span class="fm-pt-cb-inline">${checked.join(", ")}</span>`;
        }
        const lis = checked.map((l) => `<li>${l}</li>`).join("");
        return `<ul class="fm-pt-cb-list${cols2}">${lis}</ul>`;
    }
    // mode "all" — colonna (un item per riga) con marker ☑/☐ + intestazioni di
    // gruppo se gli item hanno `group`. Markup IDENTICO a PtToHtml.php.
    const rows = [];
    let lastGroup = null;
    items.forEach((it) => {
        if (!it || typeof it !== "object") return;
        const g = typeof it.group === "string" ? it.group : "";
        if (g && g !== lastGroup) {
            rows.push(`<div class="fm-pt-cb-group-head">${escapeHtml(g)}</div>`);
            lastGroup = g;
        }
        const sym = it.state === "x" ? "☑" : "☐";
        const label = escapeHtml(typeof it.label === "string" ? it.label : "");
        rows.push(`<label class="fm-pt-cb-item"><span class="fm-pt-cb-state">${sym}</span> ${label}</label>`);
    });
    return `<div class="fm-pt-checkbox-group${cols2}">${rows.join(" ")}</div>`;
}

function renderRawTex(block) {
    const content = typeof block.content === "string" ? block.content : "";
    // HTML: mostriamo come callout read-only (il contenuto TeX non è eseguito).
    return `<div class="pt-raw-tex" aria-label="Raw TeX">${escapeHtml(content)}</div>`;
}

/** Phase 24.2 — select: `<label>: <select><option>...</option></select>`. */
function renderSelect(block) {
    const label = typeof block.label === "string" ? block.label : "";
    const value = typeof block.value === "string" ? block.value : "";
    const options = Array.isArray(block.options) ? block.options : [];
    const opts = options.map((o) => {
        const v = escapeHtml(String(o?.value ?? ""));
        const l = escapeHtml(String(o?.label ?? o?.value ?? ""));
        const sel = (o?.value === value) ? " selected" : "";
        return `<option value="${v}"${sel}>${l}</option>`;
    }).join("");
    const sel = `<select class="pt-select" data-pt-name="${escapeHtml(block.name || "")}">${opts}</select>`;
    return label
        ? `<label class="pt-inline-label">${escapeHtml(label)}: ${sel}</label>`
        : sel;
}

/** Phase 24.3 — textField: `<label>: <input type="X">`. */
function renderTextField(block) {
    const label = typeof block.label === "string" ? block.label : "";
    const value = typeof block.value === "string" ? block.value : "";
    const kind = ["text", "number", "date"].includes(block.kind) ? block.kind : "text";
    const placeholder = typeof block.placeholder === "string" ? block.placeholder : "";
    const inp = `<input type="${kind}" class="pt-text-field" `
        + `value="${escapeHtml(value)}" `
        + `placeholder="${escapeHtml(placeholder)}" `
        + `data-pt-name="${escapeHtml(block.name || "")}">`;
    return label
        ? `<label class="pt-inline-label">${escapeHtml(label)}: ${inp}</label>`
        : inp;
}

/** Phase 24.4 — formCheckbox: checkbox singolo con label. */
function renderFormCheckbox(block) {
    const label = typeof block.label === "string" ? block.label : "";
    const checked = !!block.checked;
    return `<label class="pt-form-checkbox">`
        + `<input type="checkbox"${checked ? " checked" : ""} disabled> `
        + `${escapeHtml(label)}`
        + `</label>`;
}

/** Phase 24.5 — sectionHeader: h1-h4 via level + selectors placeholders. */
function renderSectionHeader(block) {
    const title = typeof block.title === "string" ? block.title : "";
    const level = Number.isInteger(block.level) ? Math.max(1, Math.min(4, block.level)) : 2;
    const tag = `h${level}`;
    const selectors = Array.isArray(block.selectors) ? block.selectors : [];
    const suffix = selectors.length
        ? ` <span class="pt-selectors">${
            selectors.map((n) => `<span class="pt-field-ref" data-field="${escapeHtml(n)}">[${escapeHtml(n)}]</span>`).join(" ")
          }</span>`
        : "";
    return `<${tag} class="fm-pt-section-header">${escapeHtml(title)}${suffix}</${tag}>`;
}

/** Phase 24.1/11 — table: render HTML <table> con thead + tbody.
 *  Cells possono essere string (legacy) o object {text, widget, colspan, rowspan, merged}.
 *  Merged cells sono skippate (sono coperte da colspan/rowspan di cell precedenti). */
function renderTable(block) {
    const columns = Array.isArray(block.columns) ? block.columns : [];
    const rows    = Array.isArray(block.rows) ? block.rows : [];
    if (columns.length === 0) return "";

    const thead = `<thead><tr>${
        columns.map((c) => `<th>${escapeHtml(String(c ?? ""))}</th>`).join("")
    }</tr></thead>`;

    // ADR-031 — calcola le formule (riferimenti = indice di colonna nell'array
    // riga, come l'editor). fResults[ri][ci] = {display, error, …}.
    const fgrid = rows.map((row) => {
        const arr = Array.isArray(row) ? row : [];
        const out = [];
        for (let c = 0; c < columns.length; c++) {
            const cell = arr[c];
            if (cell && typeof cell === "object" && cell.formula && isFormula(cell.formula)) {
                out.push({ formula: cell.formula });
            } else {
                let raw = "";
                if (typeof cell === "string") raw = cell;
                else if (cell && typeof cell === "object") {
                    const wv = cell.widget ? cell.widget.value : undefined;
                    raw = (wv != null && typeof wv !== "object") ? String(wv) : (cell.text || "");
                }
                out.push({ raw });
            }
        }
        return out;
    });
    let fResults = [];
    try { fResults = computeTableValues(fgrid); } catch (_) { fResults = []; }

    const tbody = rows.map((row, ri) => {
        if (!Array.isArray(row)) return "";
        const cells = [];
        // Clamp a columns.length come l'editor e l'SSR: celle in eccesso nei dati
        // (colonne fantasma) NON vengono renderizzate; righe più corte → td vuoti.
        for (let ci = 0; ci < columns.length; ci++) {
            const c = row[ci];
            if (c == null) { cells.push("<td></td>"); continue; }
            if (typeof c === "string") {
                cells.push(`<td>${renderInlineCellText(c)}</td>`);
                continue;
            }
            if (typeof c === "object") {
                if (c.merged) continue; // coperta da colspan/rowspan precedente
                const colspan = Math.max(1, parseInt(c.colspan, 10) || 1);
                const rowspan = Math.max(1, parseInt(c.rowspan, 10) || 1);
                const attrs = [];
                if (colspan > 1) attrs.push(`colspan="${colspan}"`);
                if (rowspan > 1) attrs.push(`rowspan="${rowspan}"`);
                const css = [];
                if (typeof c.bg === "string" && /^#[0-9a-fA-F]{6}$/.test(c.bg)) css.push(`background-color:${c.bg}`);
                if (["left", "center", "right"].includes(c.align)) css.push(`text-align:${c.align}`);
                if (["top", "middle", "bottom"].includes(c.valign)) css.push(`vertical-align:${c.valign}`);
                if (css.length) attrs.push(`style="${css.join(";")}"`);
                let content;
                if (c.formula && isFormula(c.formula)) {
                    const fr = fResults[ri] && fResults[ri][ci];
                    const disp = fr ? String(fr.display ?? "") : "";
                    const errCls = (fr && fr.error) ? " fm-pt-formula--err" : "";
                    content = `<span class="fm-pt-formula${errCls}">${escapeHtml(disp)}</span>`;
                } else {
                    content = renderTableCellContent(c);
                }
                cells.push(`<td${attrs.length ? ` ${  attrs.join(" ")}` : ""}>${content}</td>`);
            }
        }
        return `<tr>${cells.join("")}</tr>`;
    }).filter(Boolean).join("");

    const caption = typeof block.caption === "string" && block.caption
        ? `<caption>${escapeHtml(block.caption)}</caption>`
        : "";

    // Larghezza: "full" → tutta la pagina + <colgroup> percentuali per-colonna.
    const full = block.widthMode === "full";
    const cls = `fm-pt-table${full ? " fm-pt-table--full" : ""}`;
    let colgroup = "";
    if (full) {
        const widths = normalizeColWidths(
            Array.isArray(block.colWidths) ? block.colWidths : [], columns.length,
        );
        colgroup = `<colgroup>${
            widths.map((w) => `<col style="width:${w}%">`).join("")
        }</colgroup>`;
    }

    return `<table class="${cls}">${caption}${colgroup}${thead}<tbody>${tbody}</tbody></table>`;
}

/** Mirror di PtToHtml::normalizeColWidths — percentuali sommanti a 100,
 *  ripartizione equa se non tutte le colonne hanno un valore valido (>0). */
function normalizeColWidths(widths, colCount) {
    if (colCount <= 0) return [];
    const vals = [];
    let allValid = true;
    for (let i = 0; i < colCount; i++) {
        const v = Number.isFinite(+widths[i]) ? +widths[i] : 0;
        if (!(v > 0)) allValid = false;
        vals[i] = v > 0 ? v : 0;
    }
    const sum = vals.reduce((a, b) => a + b, 0);
    if (!allValid || sum <= 0) {
        return Array.from({ length: colCount }, () => Math.round((100 / colCount) * 100) / 100);
    }
    return vals.map((v) => Math.round((v / sum) * 100 * 100) / 100);
}

function renderTableCellContent(c) {
    const w = c.widget;
    if (w && typeof w === "object" && w._type) {
        if (w._type === "checkbox") {
            const options = Array.isArray(w.options) ? w.options : [];
            const checked = Array.isArray(w.value) ? w.value.map(String) : (w.value ? [String(w.value)] : []);
            const mode = typeof w.renderMode === "string" ? w.renderMode : "all";
            const isChk = (o) => checked.includes(String(o?.value ?? o?.label ?? ""));
            if (options.length === 0) {
                return checked.length ? `<span class="fm-pt-cell-checks">${escapeHtml(checked.join(", "))}</span>` : "";
            }
            // "solo spuntati inline" → solo le voci spuntate, a flusso (nella frase).
            if (mode === "checked-inline") {
                const lbls = options.filter(isChk).map((o) => escapeHtml(String(o?.label ?? o?.value ?? "")));
                return `<span class="fm-pt-cb-inline">${lbls.join(", ")}</span>`;
            }
            // "tutti" / "solo spuntati" → INCOLONNATO (un item per riga), opz. N colonne.
            const onlyChecked = mode === "checked-only";
            const nCols = Math.max(1, Math.min(5, parseInt(w.columns, 10) || 1));
            const colStyle = nCols >= 2 ? ` style="column-count:${nCols}"` : "";
            let lastGroup = null;
            const rows = [];
            options.forEach((o) => {
                if (onlyChecked && !isChk(o)) return;
                const lbl = escapeHtml(String(o?.label ?? o?.value ?? ""));
                const grp = o && o.group ? String(o.group) : "";
                if (grp && grp !== lastGroup) {
                    rows.push(`<div class="fm-pt-cb-group-head">${escapeHtml(grp)}</div>`);
                    lastGroup = grp;
                }
                const sym = onlyChecked ? "•" : (isChk(o) ? "☑" : "☐");
                rows.push(`<label class="fm-pt-cb-item"><span class="fm-pt-cb-state">${sym}</span> ${lbl}</label>`);
            });
            return `<div class="fm-pt-checkbox-group"${colStyle}>${rows.join("")}</div>`;
        }
        if (w._type === "select") {
            const value = String(w.value ?? "");
            const options = Array.isArray(w.options) ? w.options : [];
            const opts = options.map((o) => {
                const v = escapeHtml(String(o?.value ?? ""));
                const l = escapeHtml(String(o?.label ?? o?.value ?? ""));
                const sel = o?.value === value ? " selected" : "";
                return `<option value="${v}"${sel}>${l}</option>`;
            }).join("");
            return `<select class="pt-select">${opts}</select>`;
        }
        if (w._type === "textField") {
            const kind = ["text", "number", "date"].includes(w.kind) ? w.kind : "text";
            const value = String(w.value ?? "");
            const placeholder = typeof w.placeholder === "string" ? w.placeholder : "";
            return `<input type="${kind}" class="pt-text-field" value="${escapeHtml(value)}" placeholder="${escapeHtml(placeholder)}">`;
        }
    }
    return renderInlineCellText(String(c.text ?? ""));
}

/** Mirror di PtToHtml::renderInlineText — escapa tutto, poi ripristina SOLO i
 *  4 tag inline consentiti (strong/em/u/code) prodotti dai formattatori. */
function renderInlineCellText(text) {
    return escapeHtml(String(text ?? ""))
        .replace(/&lt;(\/?)(strong|em|u|code)&gt;/g, "<$1$2>");
}

/**
 * G23 page-doc — glossaryTable: tabella lemmi/definizioni con sort+search.
 * Output HTML semantico (caption, th[scope]) + container per runtime script.
 */
function renderGlossaryTable(block) {
    const columns = Array.isArray(block.columns) ? block.columns : [];
    const entries = Array.isArray(block.entries) ? block.entries : [];
    if (columns.length === 0) return "";
    const name = typeof block.name === "string" ? block.name : "";
    const searchable = block.searchable !== false;

    const search = searchable
        ? `<input type="search" class="pt-glossary-search" placeholder="Cerca…" aria-label="Cerca nel glossario">`
        : "";

    const thead = `<thead><tr>${
        columns.map((c) => {
            const esc = escapeHtml(String(c));
            return `<th scope="col" class="pt-glossary-th" data-col-key="${escapeHtml(headerToKeyClient(c))}">${esc}</th>`;
        }).join("")
    }</tr></thead>`;

    const tbody = entries.map((e) => {
        const cells = columns.map((c) => {
            const key = headerToKeyClient(c);
            return `<td>${escapeHtml(String(e?.[key] ?? ""))}</td>`;
        }).join("");
        return `<tr>${cells}</tr>`;
    }).join("");

    const nameAttr = name ? ` data-name="${escapeHtml(name)}"` : "";
    return `<div class="pt-glossary-table"${nameAttr}>`
        + search
        + `<table><caption class="pt-glossary-caption">Glossario (${entries.length} voci)</caption>`
        + thead
        + `<tbody>${tbody}</tbody>`
        + `</table>`
        + `</div>`;
}

function headerToKeyClient(header) {
    return String(header || "")
        .toLowerCase()
        .replace(/\./g, "")
        .replace(/[àáâä]/g, "a").replace(/[èéêë]/g, "e")
        .replace(/[ìíîï]/g, "i").replace(/[òóôö]/g, "o")
        .replace(/[ùúûü]/g, "u")
        .replace(/\s+/g, "_")
        .replace(/[^a-z0-9_]/g, "")
        .replace(/^_+|_+$/g, "");
}

/** G23 page-doc — staticContent: HTML sanitizzato (sanitize lato server). */
function renderStaticContent(block) {
    const level = Number.isInteger(block.level) ? Math.max(2, Math.min(4, block.level)) : 2;
    const title = typeof block.title === "string" ? block.title : "";
    const body  = typeof block.body  === "string" ? block.body  : "";
    const items = Array.isArray(block.items) ? block.items : [];
    const heading = title
        ? `<h${level}>${escapeHtml(title)}</h${level}>`
        : "";
    const bodyHtml = body ? `<div class="pt-static-content__body">${body}</div>` : "";
    const nested = items.map(renderStaticContent).filter(Boolean).join("");
    return `<section class="pt-static-content" data-level="${level}">`
        + heading + bodyHtml + nested
        + `</section>`;
}

/** G23 page-doc — accordion via <details>/<summary> native (zero-JS). */
function renderAccordion(block) {
    const items = Array.isArray(block.items) ? block.items : [];
    if (items.length === 0) return "";
    const allowMultiple = block.allow_multiple !== false;
    const itemsHtml = items.map((it) => {
        if (!it || typeof it !== "object") return "";
        if (it.excluded) return ""; // item escluso (checkbox) → omesso
        const title = escapeHtml(typeof it.title === "string" ? it.title : "");
        const open  = it.default_open ? " open" : "";
        const bodyPt = Array.isArray(it.body_pt) ? it.body_pt : [];
        const body = bodyPt.map(renderBlock).filter(Boolean).join("");
        return `<details class="pt-accordion__item"${open}>`
             + `<summary>${title}</summary>`
             + `<div class="pt-accordion__body">${body}</div>`
             + `</details>`;
    }).filter(Boolean).join("");
    if (!itemsHtml) return ""; // tutti esclusi
    return `<div class="pt-accordion" data-multiple="${allowMultiple ? "true" : "false"}">${itemsHtml}</div>`;
}

/** G23 page-doc — linkListPdf: lista link normativi gerarchici. */
function renderLinkListPdf(block) {
    const title = typeof block.title === "string" ? block.title : "";
    const items = Array.isArray(block.items) ? block.items : [];
    if (items.length === 0) return "";
    const titleHtml = title ? `<h3 class="pt-link-list-pdf__title">${escapeHtml(title)}</h3>` : "";
    const itemsHtml = items.map(renderLinkListItem).filter(Boolean).join("");
    return `<section class="pt-link-list-pdf">${titleHtml}<ul class="pt-link-list-pdf__list">${itemsHtml}</ul></section>`;
}

function renderLinkListItem(item) {
    if (!item || typeof item !== "object") return "";
    const label = typeof item.label === "string" ? item.label : "";
    const href  = typeof item.href  === "string" ? item.href  : "";
    if (!label || !href) return "";
    const external = !!item.external || /^https?:\/\//i.test(href);
    const targetAttr = external ? ` target="_blank" rel="noopener noreferrer"` : "";
    const iconHtml = external ? ` <span class="pt-link-list-pdf__icon" aria-hidden="true">↗</span>` : "";
    const desc = typeof item.description === "string" && item.description
        ? `<p class="pt-link-list-pdf__desc">${escapeHtml(item.description)}</p>`
        : "";
    const subs = Array.isArray(item.sub_items) ? item.sub_items : [];
    const subsHtml = subs.length
        ? `<ul class="pt-link-list-pdf__sublist">${subs.map(renderLinkListItem).filter(Boolean).join("")}</ul>`
        : "";
    return `<li>`
        + `<a href="${escapeHtml(href)}" class="pt-link-list-pdf__link"${targetAttr}>${escapeHtml(label)}${iconHtml}</a>`
        + desc + subsHtml
        + `</li>`;
}

/** G23 page-doc — citationNorma: blocco citazione strutturato. */
function renderCitationNorma(block) {
    const tipo = typeof block.tipo === "string" ? block.tipo : "altro";
    const numero = typeof block.numero === "string" ? block.numero : "";
    const anno = block.anno != null ? String(block.anno) : "";
    const articolo = typeof block.articolo === "string" ? block.articolo : "";
    const title = typeof block.title === "string" ? block.title : "";
    const href = typeof block.href === "string" ? block.href : "";
    const quote = typeof block.quote === "string" ? block.quote : "";

    const headLabel = [tipo, numero, anno].filter(Boolean).join(" ").replace(/\s+/, " ").trim() || tipo;
    const articoloHtml = articolo
        ? ` <span class="pt-citation-norma__articolo">${escapeHtml(articolo)}</span>`
        : "";
    const quoteHtml = quote
        ? `<blockquote class="pt-citation-norma__quote">${escapeHtml(quote)}</blockquote>`
        : "";
    const titleEsc = escapeHtml(title || headLabel);
    const titleHtml = href
        ? `<p class="pt-citation-norma__title"><a href="${escapeHtml(href)}" target="_blank" rel="noopener noreferrer">${titleEsc}</a></p>`
        : (title ? `<p class="pt-citation-norma__title">${titleEsc}</p>` : "");

    return `<aside class="pt-citation-norma" data-tipo="${escapeHtml(tipo)}">`
        + `<div class="pt-citation-norma__header">`
        + `<strong>${escapeHtml(headLabel)}</strong>${articoloHtml}`
        + `</div>`
        + quoteHtml + titleHtml
        + `</aside>`;
}

// G22.S15.bis Fase 5+ — delegate canonical (plus null-safety).
function escapeHtml(s) {
    return window.FM?.DomUtils?.escHtml
        ? window.FM.DomUtils.escHtml(s)
        : String(s ?? "").replace(/[&<>"']/g, (c) =>
            ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
}
