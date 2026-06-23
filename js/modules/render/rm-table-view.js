/**
 * G23 — RM Table View (single source of truth client-side).
 *
 * Modulo responsabile del rendering DOM delle tabelle RM (Risposta Multipla)
 * con markup IDENTICO a `app/Services/ContractRenderer::renderRmTable()` lato
 * server. Eliminata la divergenza `.rm-letter`/`.rm-pick-choice` legacy.
 *
 * Markup canonico (mirrored PHP/JS):
 *
 *   <table class="fm-rm-table"
 *          data-typecell="|X|V|"
 *          data-rows="2" data-cols="2"
 *          data-mixtr="0" data-mixcol="0"
 *          data-mpagew="1" data-width="">
 *     <tbody>
 *       <tr>
 *         <td class="rm-option" data-row="0" data-col="0">
 *           <div class="fm-wrap-check-cell" style="display:flex">
 *             {input dinamico via colTypeToInput}
 *             <label class="fm-collection"><div class="fm-cell-content">{blocks}</div></label>
 *           </div>
 *         </td>
 *         …
 *       </tr>
 *     </tbody>
 *   </table>
 *
 * Le LETTERE (a./b./c./d.) NON sono emesse nel DOM: vengono derivate da indice
 * `r*cols+c` solo nel TeX builder (Sanitizer.php) per `\textbf{a.}` label.
 */

/** Tipi colonna supportati (mirror `App\Services\Rendering\RmColumnTypes`). */
export const COL_TYPES = Object.freeze({
    X: { html: 'checkbox', tex: '\\square',           desc: 'Checkbox (multipla)' },
    V: { html: 'radio',    tex: '\\bigcirc',          desc: 'Radio (esclusiva)' },
    B: { html: 'button',   tex: '\\fbox{btn}',        desc: 'Button' },
    T: { html: 'text',     tex: '\\underline{\\ \\ \\ \\ }', desc: 'Text input' },
    N: { html: 'number',   tex: '\\boxed{\\#}',       desc: 'Number input' },
    F: { html: 'vf',       tex: '\\text{V}\\,\\square\\quad \\text{F}\\,\\square', desc: 'Vero/Falso' },
});

/** Normalizza tipo colonna (default X). */
export function normalizeColType(type) {
    const t = String(type || 'X').toUpperCase();
    return COL_TYPES[t] ? t : 'X';
}

/** Mappa tipo colonna → input HTML element (DOM Node, NOT string). */
export function colTypeToInput(type, opts = {}) {
    const t = normalizeColType(type);
    const checked = !!opts.checked;
    if (t === 'B') {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'fm-rm-btn';
        btn.textContent = opts.label || 'btn';
        return btn;
    }
    if (t === 'T') {
        const inp = document.createElement('input');
        inp.type = 'text';
        inp.className = 'fm-rm-text';
        inp.value = opts.value || '';
        return inp;
    }
    if (t === 'N') {
        const inp = document.createElement('input');
        inp.type = 'number';
        inp.className = 'fm-rm-num';
        if (opts.value != null) inp.value = String(opts.value);
        return inp;
    }
    if (t === 'F') {
        // Vero/Falso: span con casella V (checkbox interattiva = correct) + F visiva.
        const sp = document.createElement('span');
        sp.className = 'fm-rm-vf';
        const vLbl = document.createElement('label');
        vLbl.className = 'fm-rm-vf__opt fm-rm-vf__opt--v';
        vLbl.title = 'Vero';
        const inp = document.createElement('input');
        inp.type = 'checkbox';
        inp.className = 'checkbox fm-checkbox-rm fm-rm-vf__input' + (checked ? ' solchecked' : '');
        if (checked) inp.checked = true;
        const vTxt = document.createElement('span'); vTxt.className = 'fm-rm-vf__lbl'; vTxt.textContent = 'V';
        vLbl.append(inp, vTxt);
        const fOpt = document.createElement('span');
        fOpt.className = 'fm-rm-vf__opt fm-rm-vf__opt--f';
        fOpt.setAttribute('aria-hidden', 'true');
        const fBox = document.createElement('span'); fBox.className = 'fm-rm-vf__box';
        const fTxt = document.createElement('span'); fTxt.className = 'fm-rm-vf__lbl'; fTxt.textContent = 'F';
        fOpt.append(fBox, fTxt);
        sp.append(vLbl, fOpt);
        return sp;
    }
    // X / V → checkbox / radio
    const inp = document.createElement('input');
    inp.type = COL_TYPES[t].html;
    inp.className = 'checkbox fm-checkbox-rm';
    if (checked) inp.checked = true;
    return inp;
}

/** Mappa tipo colonna → LaTeX symbol (per parità con server-side TeX). */
export function colTypeToTex(type) {
    return COL_TYPES[normalizeColType(type)].tex;
}

/**
 * Riallinea `t.cells` a matrice `t.rows × t.cols`, preserva contenuti esistenti.
 * Estratta da legacy `syncCellsShape` in checkin-handlers.js.
 */
export function syncCellsShape(t) {
    if (!Array.isArray(t.cells)) t.cells = [];
    while (t.cells.length < t.rows) t.cells.push([]);
    t.cells.length = t.rows;
    for (let r = 0; r < t.rows; r++) {
        if (!Array.isArray(t.cells[r])) t.cells[r] = [];
        while (t.cells[r].length < t.cols) t.cells[r].push('');
        t.cells[r].length = t.cols;
    }
}

/** Helper: unwrap all elements matching selector, preserving children. */
function _unwrapAll(root, selector) {
    root.querySelectorAll(selector).forEach(el => {
        const parent = el.parentNode;
        if (!parent) return;
        while (el.firstChild) parent.insertBefore(el.firstChild, el);
        el.remove();
    });
}

/**
 * Estrae il content raw di una cella RM, neutrale al markup.
 * Supporta TUTTI i markup storici:
 *   1. Server moderno: `<td><div.wrapCheckCell><input><label.fm-collection><div.cellContent>{content}</div></label></div></td>`
 *   2. Client legacy : `<td><span.rm-letter><label.rm-pick-choice><input></label> {content}</td>`
 *   3. Plain        : `<td>{content}</td>` (contract antichi senza wrapping)
 *
 * Pulisce inoltre il markup DSA emesso dal renderer:
 *   - `.fm-dsa-li-num` (marker testuale "a.", "b.") → rimosso
 *   - `.fm-dsa-li-buttons` (UI F/GF) → rimosso
 *   - `.fm-dsa-li-content` → unwrap (sposta children al li)
 *   - `.fm-text[data-raw]` / `.fm-latex[data-raw]` → unwrap al data-raw (preserva
 *     LaTeX source vs HTML escaped)
 *
 * Marca le OL/UL root con `data-dsa-section="options"` cosi' al re-save il
 * parser sa che e' content di una cella (no F/GF buttons).
 *
 * Strategy: clone + strip elementi decorativi, poi estrai innerHTML.
 */
export function extractCellContent(td) {
    if (!td) return '';
    const c = td.cloneNode(true);

    // 1. Strip markup decorativo legacy client (.rm-letter etc) + input
    c.querySelectorAll('.rm-letter, .rm-vf-choice, .rm-pick-choice, .rm-marker').forEach(x => x.remove());
    c.querySelectorAll('.fm-wrap-check-cell input, .fm-wrap-check-cell button.fm-rm-btn').forEach(x => x.remove());

    // 2. Unwrap struttura wrapCheckCell server (label.fm-collection > div.cellContent
    //    > div.wrapCheckCell) → preserva solo content interno.
    _unwrapAll(c, 'label.fm-collection');
    _unwrapAll(c, '.cellContent');
    _unwrapAll(c, '.wrapCheckCell');

    // 3. Strip DSA list UI (server-emitted): F/GF buttons + marker spans, ma
    //    PRESERVA struttura nested OL.
    c.querySelectorAll('.fm-dsa-li-buttons, .fm-dsa-li-num').forEach(x => x.remove());
    //    Unwrap .fm-dsa-li-content (sposta inner content al li parent).
    _unwrapAll(c, '.fm-dsa-li-content');

    // 4. fm-text / fm-latex → sostituisci con data-raw (preserva LaTeX source).
    c.querySelectorAll('.fm-text[data-raw], .fm-latex[data-raw]').forEach(span => {
        const raw = span.getAttribute('data-raw') || '';
        const isLatex = span.classList.contains('fm-latex');
        if (isLatex) {
            // Preserva span fm-latex (LaTeX needs delimiters); ma riscrivi inner
            const tmp = document.createElement('span');
            tmp.className = 'fm-latex';
            tmp.setAttribute('data-raw', raw);
            tmp.innerHTML = raw;
            span.replaceWith(tmp);
        } else {
            // Sostituisci con text node con raw value
            const tmp = document.createElement('template');
            tmp.innerHTML = raw;
            const frag = tmp.content;
            const parent = span.parentNode;
            if (parent) {
                while (frag.firstChild) parent.insertBefore(frag.firstChild, span);
                span.remove();
            }
        }
    });

    // 5. Marca OL/UL root come "options" per round-trip section (evita F/GF
    //    al re-render). Solo top-level (non sub-list).
    Array.from(c.children).forEach(child => {
        if (/^(OL|UL)$/i.test(child.tagName)) {
            child.setAttribute('data-dsa-section', 'options');
            // Sub-list inner → 'sub' (nessun marker, native browser)
            child.querySelectorAll('ol, ul').forEach(sub => {
                sub.setAttribute('data-dsa-section', 'sub');
            });
        }
    });
    // Rimuovi attr DSA state residui sui li
    c.querySelectorAll('[data-fm-dsa-state]').forEach(li => li.removeAttribute('data-fm-dsa-state'));

    // 6. Estrai content preservando struttura
    if (c.querySelector('ol, ul, br, b, strong, i, em, u, s, sub, sup, .fm-latex, svg')) {
        return (c.innerHTML || '').replace(/^\s*[a-z]\.\s*/i, '').trim();
    }
    return (c.textContent || '').replace(/^\s*[a-z]\.\s*/i, '').trim();
}

/**
 * Render un singolo `<table class="fm-rm-table">` da uno state table.
 *
 * @param {object} t state della tabella:
 *   { rows, cols, typecell, colTypes[], cells[r][c], mixtr, mixcol, mpagew, specificWidth }
 * @param {object} opts:
 *   - cellRenderer: (content, ctx) => string|Node  custom renderer per cell content
 *   - correctMask: boolean[][]  marcato corretta cella [r][c]
 *   - placeholder: string  testo se cella vuota (default '')
 * @returns {HTMLTableElement}
 */
export function renderRmTable(t, opts = {}) {
    syncCellsShape(t);
    if (!t.colTypes || t.colTypes.length !== t.cols) {
        const derived = String(t.typecell || '').toUpperCase().match(/[XVBTNF]/g) || [];
        t.colTypes = Array.from({ length: t.cols }, (_, c) => derived[c] || 'X');
    }
    t.typecell = `|${  t.colTypes.join('|')  }|`;

    const cellRenderer = opts.cellRenderer || ((content) => {
        const div = document.createElement('div');
        div.className = 'fm-cell-content';
        div.innerHTML = content || (opts.placeholder || '');
        return div;
    });
    const correctMask = opts.correctMask || [];

    const table = document.createElement('table');
    table.className = 'fm-rm-table';
    table.dataset.typecell = t.typecell;
    table.dataset.rows = String(t.rows);
    table.dataset.cols = String(t.cols);
    table.dataset.mixtr = t.mixtr ? '1' : '0';
    table.dataset.mixcol = t.mixcol ? '1' : '0';
    table.dataset.mpagew = t.mpagew ? '1' : '0';
    if (t.specificWidth) table.dataset.width = String(t.specificWidth);
    const widthStyle = t.mpagew ? '100%' : (t.specificWidth ? `${t.specificWidth}px` : 'auto');
    table.style.cssText = `border-collapse:collapse;width:${widthStyle}`;

    const tbody = document.createElement('tbody');
    for (let r = 0; r < t.rows; r++) {
        const tr = document.createElement('tr');
        for (let c = 0; c < t.cols; c++) {
            const td = document.createElement('td');
            td.className = 'rm-option';
            td.dataset.row = String(r);
            td.dataset.col = String(c);
            td.style.cssText = 'border:1px solid #888;padding:6px;vertical-align:top';

            const colType = normalizeColType(t.colTypes[c]);
            const checked = !!(correctMask[r] && correctMask[r][c]);
            if (checked) td.classList.add('rm-correct');

            const wrap = document.createElement('div');
            wrap.className = 'fm-wrap-check-cell';
            wrap.style.cssText = 'display:flex;gap:6px;align-items:flex-start';

            // Phase 24.78 — value N/T (soluzione docente) preservato via valueMask
            // attraverso i rebuild live dell'editor (analogo a correctMask).
            const valueMask = opts.valueMask || [];
            const cellVal = (valueMask[r] && valueMask[r][c] != null) ? valueMask[r][c] : '';
            const input = colTypeToInput(colType, { checked, value: cellVal });
            wrap.appendChild(input);

            const label = document.createElement('label');
            label.className = 'fm-collection';
            label.style.cssText = 'flex:1;cursor:pointer';

            const content = (t.cells[r] && t.cells[r][c]) || '';
            const rendered = cellRenderer(content, { r, c, colType, checked });
            if (rendered instanceof Node) {
                label.appendChild(rendered);
            } else {
                const div = document.createElement('div');
                div.className = 'fm-cell-content';
                div.innerHTML = String(rendered || '');
                label.appendChild(div);
            }

            wrap.appendChild(label);
            td.appendChild(wrap);
            tr.appendChild(td);
        }
        tbody.appendChild(tr);
    }
    table.appendChild(tbody);
    return table;
}

/**
 * Render multipli `.fm-rm-table` raggruppati in `.fm-rm-tables-wrap` (parità
 * con stato editor `state.tables[]` multi-tabella).
 *
 * @param {object} state { tables: [...], orientation: 'horizontal'|'vertical' }
 * @param {object} opts (vedi renderRmTable)
 * @returns {HTMLDivElement}
 */
export function renderRmTablesWrap(state, opts = {}) {
    const wrap = document.createElement('div');
    wrap.className = 'fm-rm-tables-wrap';
    wrap.dataset.orientation = state.orientation || 'horizontal';
    wrap.style.cssText = state.orientation === 'vertical'
        ? 'display:flex;flex-direction:column;gap:12px;margin-top:8px'
        : 'display:flex;gap:12px;flex-wrap:wrap;margin-top:8px';

    (state.tables || []).forEach((t, idx) => {
        const tableOpts = { ...opts };
        // correctMask per-table se passato come array di matrici
        if (Array.isArray(opts.correctMasks)) {
            tableOpts.correctMask = opts.correctMasks[idx] || [];
        }
        // Phase 24.78 — valueMask per-table (value N/T) analogo a correctMask
        if (Array.isArray(opts.valueMasks)) {
            tableOpts.valueMask = opts.valueMasks[idx] || [];
        }
        wrap.appendChild(renderRmTable(t, tableOpts));
    });
    return wrap;
}

/**
 * Costruisce state RM dal `options[]` array contract + `rmLayout`.
 * Usato da applyRmTableEdits (post-save in-place update).
 *
 * @param {Array} options [{ letter, correct, content: blocks[] | string }, ...]
 * @param {object} rmLayout { rows, cols, typecell, mixtr, mixcol, mpagew, specificWidth, orientation, table_count }
 * @param {function} blocksToHtml fn(blocks[]) → string HTML (iniettato per disaccoppiare dal renderer)
 * @returns {object} state { orientation, tables: [...] }
 */
export function stateFromContract(options, rmLayout, blocksToHtml) {
    const layout = rmLayout || {};
    const cols = Math.max(1, parseInt(layout.cols, 10) || 2);
    // Default rows = ceil(count/cols) per backward-compat contract senza dimensioni
    const optCount = (options || []).length;
    const rows = Math.max(1, parseInt(layout.rows, 10) || Math.max(1, Math.ceil(optCount / cols)) || 2);
    const typecell = String(layout.typecell || `|${  'X|'.repeat(cols)}`);
    const colTypes = (typecell.toUpperCase().match(/[XVBTNF]/g) || []).slice(0, cols);
    while (colTypes.length < cols) colTypes.push('X');

    const cells = [];
    const correctMask = [];
    const valueMask = [];
    for (let r = 0; r < rows; r++) {
        cells.push([]);
        correctMask.push([]);
        valueMask.push([]);
        for (let c = 0; c < cols; c++) {
            const idx = r * cols + c;
            const op = (options || [])[idx] || {};
            const content = op.content;
            let html = '';
            if (Array.isArray(content) && typeof blocksToHtml === 'function') {
                html = blocksToHtml(content);
            } else if (typeof content === 'string') {
                html = content;
            }
            cells[r].push(html);
            correctMask[r].push(!!op.correct);
            // Phase 24.78 — value N/T (soluzione docente) dal contract → valueMask,
            // cosi' il re-render post-save (uscita edit mode) ripopola gli input.
            valueMask[r].push(op.value != null ? String(op.value) : '');
        }
    }

    return {
        orientation: layout.orientation || 'horizontal',
        tables: [{
            rows, cols, typecell, colTypes, cells,
            mixtr:  !!layout.mixtr,
            mixcol: !!layout.mixcol,
            mpagew: layout.mpagew !== false && layout.mpagew !== '0',
            specificWidth: layout.specificWidth || layout.width || '',
        }],
        _correctMasks: [correctMask],
        _valueMasks: [valueMask],
    };
}
