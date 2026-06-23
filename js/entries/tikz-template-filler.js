/**
 * G22.S15 / Phase 1 — Modal "Template Filler" per generare TikZ via form.
 *
 * Lazy-loaded da checkin-handlers al click del bottone "📋 Schema modulare".
 *
 * Pipeline:
 *   1. Apre modal con form pre-popolato (default o data esistente)
 *   2. User edita campi → live preview SVG (debounce 600ms)
 *   3. Save → emette TikZ string + JSON data → callback al chiamante
 *      che inserisce/aggiorna `<script type="text/tikz" data-template-id=... data-template-data=...>`
 *      nel textarea originale
 *
 * v1: solo template "schema-modulare". Estendibile registrando altri
 * template in `js/modules/editor/tikz-templates/`.
 */
import * as schemaModulare from "../modules/editor/tikz-templates/schema-modulare.js";
import { renderAll as tikzRenderAll } from "../modules/editor/tikz-render-client.js";

const TEMPLATES = {
    [schemaModulare.TEMPLATE_ID]: schemaModulare,
};

let _modalState = null;
let _styleInjected = false;

/** G22.S15.bis — confirm dark mode interno (sostituisce browser await window.FM.Dialog.confirm()). */
function confirmAsync({ title = "Conferma", message = "Sicuro?", confirmLabel = "OK", cancelLabel = "Annulla", danger = false } = {}) {
    return new Promise((resolve) => {
        document.getElementById("fm-tplf-confirm")?.remove();
        const dlg = document.createElement("div");
        dlg.id = "fm-tplf-confirm";
        dlg.style.cssText = "position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:100050;display:flex;align-items:center;justify-content:center;font:13px/1.4 system-ui";
        const confirmBg = danger ? "#c02a2a" : "#2a5ac7";
        const esc = (v) => String(v).replace(/[<>&"']/g, (c) => ({ "<":"&lt;",">":"&gt;","&":"&amp;",'"':"&quot;","'":"&#39;" }[c]));
        dlg.innerHTML = `
            <div style="background:#1e1e1e;color:#ddd;border:1px solid #444;border-radius:8px;box-shadow:0 12px 48px rgba(0,0,0,0.6);min-width:380px;max-width:90vw;overflow:hidden">
                <div style="padding:12px 16px;background:#2a2a2a;border-bottom:1px solid #444;font-weight:600">${esc(title)}</div>
                <div style="padding:18px 16px;color:#ccc;white-space:pre-wrap">${esc(message)}</div>
                <div style="padding:10px 12px;background:#252525;border-top:1px solid #444;display:flex;gap:8px;justify-content:flex-end">
                    <button data-act="cancel" style="padding:6px 14px;background:#3a3a3a;color:#ddd;border:1px solid #555;border-radius:4px;cursor:pointer">${esc(cancelLabel)}</button>
                    <button data-act="ok" style="padding:6px 14px;background:${confirmBg};color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:600">${esc(confirmLabel)}</button>
                </div>
            </div>`;
        document.body.appendChild(dlg);
        const done = (v) => { dlg.remove(); document.removeEventListener("keydown", kh); resolve(v); };
        const kh = (e) => { if (e.key === "Escape") done(false); else if (e.key === "Enter") done(true); };
        document.addEventListener("keydown", kh);
        dlg.addEventListener("click", (e) => {
            const a = e.target?.dataset?.act;
            if (a === "ok") done(true);
            else if (a === "cancel") done(false);
            else if (e.target === dlg) done(false);
        });
        dlg.querySelector('[data-act="ok"]').focus();
    });
}

function injectStyles() { /* ADR-023 Fase 2: CSS spostato in css/modules/ */ }

let _previewTimer = null;

async function refreshPreview(previewEl, source) {
    const status = previewEl.querySelector(".preview-status");
    if (status) status.textContent = "compiling…";
    const sandbox = document.createElement("div");
    const script = document.createElement("script");
    script.type = "text/tikz";
    script.textContent = source;
    sandbox.appendChild(script);
    try {
        const stats = await tikzRenderAll(sandbox, { defaultScope: "public" });
        const oldStatus = status?.outerHTML || '<div class="preview-status"></div>';
        previewEl.innerHTML = oldStatus;
        if (stats.errors.length > 0) {
            const err = document.createElement("div");
            err.className = "err";
            err.textContent = "Errore compile:\n\n" + stats.errors.map(e => e.error).join("\n\n");
            previewEl.appendChild(err);
            const ns = previewEl.querySelector(".preview-status"); if (ns) ns.textContent = "errore";
        } else {
            while (sandbox.firstChild) {
                if (sandbox.firstChild.nodeName !== "SCRIPT") previewEl.appendChild(sandbox.firstChild);
                else sandbox.removeChild(sandbox.firstChild);
            }
            const ns = previewEl.querySelector(".preview-status"); if (ns) ns.textContent = "ok";
        }
    } catch (e) {
        previewEl.innerHTML = '<div class="preview-status">errore</div><div class="err">Errore: ' + (e.message || e) + '</div>';
    }
}

function debouncePreview(previewEl, getSource, ms = 600) {
    clearTimeout(_previewTimer);
    _previewTimer = setTimeout(() => refreshPreview(previewEl, getSource()), ms);
}

// ─────────────────────────────────────────────────────────────────────
// Form rendering — schema-modulare specific
// ─────────────────────────────────────────────────────────────────────

function input(value, onChange, type = "text", style = "") {
    const el = document.createElement("input");
    el.type = type;
    el.value = value ?? "";
    if (style) el.style.cssText = style;
    el.addEventListener("input", () => onChange(type === "number" ? parseFloat(el.value) || 0 : el.value));
    return el;
}

function textarea(value, onChange, rows = 1) {
    const el = document.createElement("textarea");
    el.rows = rows;
    el.value = value ?? "";
    el.addEventListener("input", () => onChange(el.value));
    return el;
}

function button(label, onClick, className = "") {
    const el = document.createElement("button");
    el.type = "button";
    el.textContent = label;
    if (className) el.className = className;
    el.addEventListener("click", onClick);
    return el;
}

function el(tag, attrs = {}, children = []) {
    const e = document.createElement(tag);
    Object.entries(attrs).forEach(([k, v]) => {
        if (k === "class") e.className = v;
        else if (k === "style") e.style.cssText = v;
        else e.setAttribute(k, v);
    });
    children.forEach((c) => c && e.appendChild(typeof c === "string" ? document.createTextNode(c) : c));
    return e;
}

/** Crea un toggle "?" + body collassato di default. Ritorna l'array
 *  [iconElement, bodyElement] che il caller puo' inserire nel DOM. */
function tipToggle(html) {
    const icon = document.createElement("span");
    icon.className = "fm-tplf-tip-toggle";
    icon.textContent = "?";
    icon.title = "Click per aiuto";
    const body = document.createElement("div");
    body.className = "fm-tplf-tip-body";
    body.innerHTML = html;
    icon.addEventListener("click", (e) => {
        e.stopPropagation();
        body.classList.toggle("open");
    });
    return { icon, body };
}

/** Costruisce una sezione `<div class="fm-tplf-section">` con header
 *  cliccabile (h4 + ? icon) + tipBody collapsato + content. */
function sectionWithTip(title, tipHtml, ...content) {
    const sec = document.createElement("div");
    sec.className = "fm-tplf-section";
    const head = document.createElement("div");
    head.style.cssText = "display:flex;align-items:center;gap:0";
    const h4 = document.createElement("h4");
    h4.style.cssText = "margin:0";
    h4.textContent = title;
    head.appendChild(h4);
    const { icon, body } = tipToggle(tipHtml);
    head.appendChild(icon);
    sec.appendChild(head);
    sec.appendChild(body);
    content.forEach((c) => c && sec.appendChild(c));
    return sec;
}

function renderGlobalParams(data, onChange) {
    const g = data.globalParams;
    const fieldDef = (key, label, type = "text") => {
        const inp = input(g[key], (v) => { g[key] = v; onChange(); }, type);
        return [el("label", {}, [label]), inp];
    };
    const grid = el("div", { class: "fm-tplf-grid" }, [
        ...fieldDef("spacing", "Spacing schemi (X)", "number"),
        ...fieldDef("topTextY", "Y label sopra", "number"),
        ...fieldDef("bottomTextPadding", "Padding label sotto", "number"),
        ...fieldDef("highlightFill", "Colore evidenza fill"),
        ...fieldDef("highlightBorder", "Colore evidenza bordo"),
        ...fieldDef("highlightText", "Colore testo evidenza"),
        ...fieldDef("highlightRadius", "Raggio evidenza (es. 0.2cm)"),
        ...fieldDef("highlightBorderWidth", "Spessore bordo evidenza"),
    ]);
    return sectionWithTip(
        "Parametri globali",
        `Valori condivisi da TUTTI gli schemi (colori, spacing, raggi).
        <code>spacing</code> e' la distanza orizzontale tra schemi se metti
        <code>xShift = \\schemaSpacing</code> nel 2&deg;, <code>1.6*\\schemaSpacing</code> nel 3&deg;, ecc.`,
        grid,
    );
}

function renderSchemaForm(schema, schemaIdx, onChange) {
    // xShift, labels
    const headRow = el("div", { class: "fm-tplf-grid" }, [
        el("label", {}, ["xShift (espressione)"]),
        input(schema.xShift, (v) => { schema.xShift = v; onChange(); }, "text"),
        el("label", {}, ["Label sopra"]),
        input(schema.labelAbove, (v) => { schema.labelAbove = v; onChange(); }, "text"),
        el("label", {}, ["Label sotto"]),
        input(schema.labelBelow, (v) => { schema.labelBelow = v; onChange(); }, "text"),
    ]);

    // xValues table
    const xvTable = el("table", { class: "fm-tplf-table" }, [
        el("thead", {}, [el("tr", {}, [
            el("th", {}, ["#"]),
            el("th", {}, ["pos"]),
            el("th", {}, ["valore (LaTeX)"]),
            el("th", {}, [""]),
        ])]),
    ]);
    const xvBody = el("tbody", {});
    schema.xValues.forEach((xv, i) => {
        const tr = el("tr", {}, [
            el("td", { class: "row-num" }, [String(i + 1)]),
            el("td", {}, [input(xv.pos, (v) => { xv.pos = parseFloat(v) || 0; onChange(); }, "number")]),
            el("td", {}, [input(xv.value, (v) => { xv.value = v; onChange(); })]),
            el("td", { class: "actions" }, [button("🗑", () => { schema.xValues.splice(i, 1); onChange(true); })]),
        ]);
        xvBody.appendChild(tr);
    });
    xvTable.appendChild(xvBody);
    const xvAdd = button("+ Aggiungi colonna", () => {
        const last = schema.xValues[schema.xValues.length - 1];
        schema.xValues.push({ pos: (last?.pos || 0) + 1, value: "$0$" });
        // Adatta tutti i row.signs per matchare nuovo count = xValues.length + 1
        const expected = schema.xValues.length + 1;
        schema.rows.forEach(r => {
            while (r.signs.length < expected) r.signs.push("$+$");
            while (r.signs.length > expected) r.signs.pop();
        });
        onChange(true);
    }, "fm-tplf-add-btn");

    // rows table
    const expectedSigns = schema.xValues.length + 1;
    const rowsTable = el("table", { class: "fm-tplf-table" }, []);
    const rowsHead = el("thead", {}, [
        el("tr", {}, [
            el("th", {}, ["#"]),
            el("th", { style: "width:60px" }, ["y"]),
            el("th", { style: "width:160px" }, ["equazione"]),
            ...Array.from({ length: expectedSigns }, (_, k) => el("th", { style: "width:42px" }, [`s${k + 1}`])),
            el("th", { style: "width:140px" }, ["cerchi (idx/type)"]),
            el("th", { style: "width:80px" }, ["highlight"]),
            el("th", {}, [""]),
        ]),
    ]);
    rowsTable.appendChild(rowsHead);
    const rowsBody = el("tbody", {});
    schema.rows.forEach((row, i) => {
        // adatta signs se mismatch
        while (row.signs.length < expectedSigns) row.signs.push("$+$");
        while (row.signs.length > expectedSigns) row.signs.pop();
        const tr = el("tr", {}, [
            el("td", { class: "row-num" }, [String(i + 1)]),
            el("td", {}, [input(row.y, (v) => { row.y = parseFloat(v) || 0; onChange(); }, "number")]),
            el("td", {}, [input(row.equation, (v) => { row.equation = v; onChange(); })]),
            ...row.signs.map((s, k) =>
                el("td", {}, [input(s, (v) => { row.signs[k] = v; onChange(); })])
            ),
            el("td", {}, [
                input(formatCirclesShort(row.circles), (v) => { row.circles = parseCirclesShort(v); onChange(); }),
            ]),
            el("td", {}, [
                input((row.highlights || []).join(","), (v) => { row.highlights = parseIntList(v); onChange(); }),
            ]),
            el("td", { class: "actions" }, [button("🗑", () => { schema.rows.splice(i, 1); onChange(true); })]),
        ]);
        rowsBody.appendChild(tr);
    });
    rowsTable.appendChild(rowsBody);
    const rowsAdd = button("+ Aggiungi riga", () => {
        const last = schema.rows[schema.rows.length - 1];
        schema.rows.push({
            y: (last?.y || 0) + 1,
            equation: "",
            signs: Array(expectedSigns).fill("$+$"),
            circles: [],
            highlights: [],
        });
        onChange(true);
    }, "fm-tplf-add-btn");

    // soluzione
    const sol = schema.solution || {};
    const solGrid = el("div", { class: "fm-tplf-grid" }, [
        el("label", {}, ["Segni soluzione (idx/segno, separati da virgola)"]),
        input(formatSolSignsShort(sol.signs), (v) => { sol.signs = parseSolSignsShort(v); onChange(); }),
        el("label", {}, ["Cerchi soluzione (idx/type)"]),
        input(formatCirclesShort(sol.circles), (v) => { sol.circles = parseCirclesShort(v); onChange(); }),
        el("label", {}, ["Colonne evidenziate"]),
        input((sol.highlightIdx || []).join(","), (v) => { sol.highlightIdx = parseIntList(v); onChange(); }),
        el("label", {}, ["Testo soluzione"]),
        input(sol.text || "", (v) => { sol.text = v; onChange(); }),
    ]);
    schema.solution = sol;

    return el("div", {}, [
        sectionWithTip(
            `Schema #${schemaIdx + 1}`,
            `<b>xShift</b>: posizione X dello schema (espressione TeX).
            Il primo schema usa <code>0</code>; il secondo <code>\\schemaSpacing</code>;
            il terzo <code>1.6*\\schemaSpacing</code>; ecc.<br>
            <b>Label sopra/sotto</b>: testo LaTeX in posizione automatica
            rispetto al centro dello schema (es. <code>$\\text{se }a&lt;0$</code>).`,
            headRow,
        ),
        sectionWithTip(
            "Punti X (colonne)",
            `Ogni colonna sulla retta orizzontale e' un punto notevole (zero,
            discontinuita', vertice). <b>pos</b> e' la coordinata X (numero);
            <b>valore</b> l'etichetta LaTeX mostrata sopra (es. <code>$2a$</code>,
            <code>$-\\frac{a}{4}$</code>, <code>$0$</code>).
            Aggiungere/togliere colonne riadatta automaticamente i segni nelle righe
            (1 colonna = N+1 segni).`,
            xvTable, xvAdd,
        ),
        sectionWithTip(
            "Righe (segni)",
            `Ogni riga rappresenta un'espressione (numeratore, denominatore,
            quoziente, ecc.). <b>y</b> = ordinata verticale (0.5, 1.5, 2.5);
            <b>equazione</b> = label a sinistra (LaTeX); <b>s1..sN</b> = segni delle
            colonne (es. <code>$+$</code>, <code>$-$</code>, <code>$0$</code>).<br>
            <b>cerchi</b>: lista <code>idx/type</code> separati da virgola
            (es. <code>1/draw, 2/fill</code>) — disegna cerchio aperto/pieno
            sul punto X N. <b>highlight</b>: indici di celle da evidenziare con
            cerchio rosso, separati da virgola (es. <code>3</code> evidenzia il 3&deg;
            segno; <code>1, 3, 5</code> evidenzia il 1&deg;, 3&deg; e 5&deg;).<br>
            <i>Nota:</i> la linea tratteggiata viene posizionata automaticamente
            tra l'ultima e la penultima riga. Se metti UNA SOLA riga la dotted-line
            cade in alto (default 0.25): aggiungi almeno 2-3 righe per la posizione corretta.`,
            rowsTable, rowsAdd,
        ),
        renderSolutionSectionCollapsible(schema, sol, solGrid),
    ]);
}

/** Sezione "Riga soluzione" collapsibile + warning chiaro: spiega che e'
 *  un OVERLAY sull'ultima riga (NON una riga nuova) e che nel 90% dei
 *  casi NON serve. Aperta solo se ci sono dati. */
function renderSolutionSectionCollapsible(schema, sol, solGrid) {
    const hasData = (sol.signs?.length || sol.circles?.length || sol.highlightIdx?.length || (sol.text || "").trim());
    const sec = el("div", { class: "fm-tplf-section" }, []);
    const header = el("div", { style: "display:flex;align-items:center;gap:8px;cursor:pointer" });
    const arrow = el("span", { style: "color:#888;font-size:10px" }, [hasData ? "▼" : "▶"]);
    const title = el("h4", { style: "margin:0;flex:1" }, ["Overlay soluzione (avanzato — quasi mai necessario)"]);
    const badge = el("span", { style: "font-size:10px;padding:1px 6px;background:#c73a3a;color:#fff;border-radius:3px" }, ["RARO"]);
    header.appendChild(arrow); header.appendChild(title); header.appendChild(badge);
    sec.appendChild(header);

    const tipObj = tipToggle(`<b>Cosa fa</b>: aggiunge segni/cerchi <i>sovrapposti</i> alla
        <b>stessa altezza Y dell'ultima riga</b> definita sopra (NON crea una
        riga nuova). E' un residuo del template originale per quando rowSpecs
        contiene SOLO righe di calcolo intermedio (N&gt;0, D&gt;0) e si vuole
        disegnare il risultato come overlay.<br>
        <b>Quando serve davvero</b>: praticamente mai, se nella tabella
        <b>Righe</b> hai gia' messo la riga risultato (es. <code>N(x)/D(x)</code>).
        In quel caso, evidenzia direttamente le celle col campo
        <b>highlight</b> della riga (formato: <code>3</code> = colonna 3).<br>
        <b>Sintassi campi</b>:<br>
        <code>1/$+$, 2/$-$, 3/$+$</code> per i Segni (idx/segno).<br>
        <code>1/draw, 2/fill</code> per i Cerchi.<br>
        <code>3, 5</code> per evidenziare colonne (cerchio rosso).<br>
        <code>S = \\emptyset</code> testo opzionale al centro sotto.`);
    header.appendChild(tipObj.icon);
    const body = el("div", { style: hasData ? "display:block;margin-top:8px" : "display:none;margin-top:8px" }, [
        tipObj.body,
        solGrid,
    ]);
    sec.appendChild(body);

    header.addEventListener("click", () => {
        const open = body.style.display !== "none";
        body.style.display = open ? "none" : "block";
        arrow.textContent = open ? "▶" : "▼";
    });
    return sec;
}

// helper format/parse compact
function formatCirclesShort(circles) {
    return (circles || []).map(c => `${c.idx}/${c.type || "draw"}`).join(", ");
}
function parseCirclesShort(s) {
    return (s || "").split(",").map(t => t.trim()).filter(Boolean).map(t => {
        const [idx, type] = t.split("/").map(x => x.trim());
        return { idx: parseInt(idx, 10), type: type || "draw" };
    }).filter(c => !isNaN(c.idx));
}
function parseIntList(s) {
    return (s || "").split(",").map(t => t.trim()).filter(Boolean).map(t => parseInt(t, 10)).filter(n => !isNaN(n));
}
function formatSolSignsShort(signs) {
    if (!signs || !signs.length) return "";
    return signs.map((s, i) => `${i + 1}/${s}`).join(", ");
}
function parseSolSignsShort(s) {
    if (!s.trim()) return [];
    return (s || "").split(",").map(t => t.trim()).filter(Boolean).map(t => {
        const parts = t.split("/").map(x => x.trim());
        return parts.length >= 2 ? parts.slice(1).join("/") : parts[0];
    });
}

// ─────────────────────────────────────────────────────────────────────
// Modal main flow
// ─────────────────────────────────────────────────────────────────────

/** Apre la modal di template filling.
 *  @param {string} templateId  es. "schema-modulare"
 *  @param {object|null} initialData  data esistente da editare, o null per defaultData()
 *  @param {function} onSave  callback(tikzString, data) chiamata su Salva
 */
export function openTemplateFiller(templateId, initialData, onSave, opts = null) {
    if (_modalState) return;
    injectStyles();
    const tpl = TEMPLATES[templateId];
    if (!tpl) {
        alert(`Template "${templateId}" non trovato`);
        return;
    }

    const data = initialData ? structuredClone(initialData) : tpl.defaultData();
    if (data.id !== templateId) data.id = templateId;

    // G22.S15.bis — toolbar 4-bottoni quando opts.{onAdd,onSavePref,onReset}
    // sono passati (chiamato da 📋 della template DB row). Altrimenti backward-
    // compat: 1 bottone "Salva" che invoca onSave (legacy uso da 📋 nel quesito).
    const useActions = opts && (typeof opts.onAdd === "function"
        || typeof opts.onSavePref === "function" || typeof opts.onReset === "function");
    const headerTitle = (opts && opts.title) || `📋 ${tpl.TEMPLATE_LABEL}`;
    const overrideBadge = useActions && opts.isOverride
        ? '<span class="fm-tplf-override-badge" title="Stai vedendo il TUO override del default admin. Reset per ripristinare.">✱ MIO</span>'
        : '';
    const toolbarHtml = useActions ? `
                    ${overrideBadge}
                    <button data-act="codeview">Vedi codice TikZ</button>
                    <button data-act="add"     class="primary" title="Aggiungi nel quesito (cursor focus)">➕ Aggiungi</button>
                    <button data-act="savepref"           title="Salva come MIO predefinito (override del default admin)">💾 Salva predefinito</button>
                    <button data-act="reset"   class="danger" title="Reimposta al default admin (rimuove il mio override)">🔄 Reset</button>
                    <button data-act="cancel"             title="Chiudi">✕</button>`
        : `
                    <button data-act="codeview">Vedi codice TikZ</button>
                    <button data-act="cancel" class="danger">Annulla</button>
                    <button data-act="save" class="primary">Salva</button>`;

    const backdrop = document.createElement("div");
    backdrop.className = "fm-tplf-backdrop";
    backdrop.innerHTML = `
        <div class="fm-tplf">
            <div class="fm-tplf-header">
                <h3>${headerTitle}</h3>
                <div class="fm-tplf-toolbar">${toolbarHtml}</div>
            </div>
            <div class="fm-tplf-body">
                <div class="fm-tplf-form"></div>
                <div class="fm-tplf-preview">
                    <div class="preview-status">…</div>
                </div>
            </div>
        </div>`;
    document.body.appendChild(backdrop);
    const formEl = backdrop.querySelector(".fm-tplf-form");
    const previewEl = backdrop.querySelector(".fm-tplf-preview");

    function rerenderForm() {
        formEl.innerHTML = "";
        formEl.appendChild(renderGlobalParams(data, () => onChange()));

        // tabs schemas
        const tabsBar = el("div", { class: "fm-tplf-tabs" }, []);
        let activeTab = 0;
        const tabContent = el("div", {});
        function renderTabs() {
            tabsBar.innerHTML = "";
            data.schemas.forEach((_, i) => {
                const b = button(`Schema ${i + 1}`, () => { activeTab = i; renderActive(); });
                if (i === activeTab) b.className = "active";
                tabsBar.appendChild(b);
                if (data.schemas.length > 1) {
                    const del = button("✕", async () => {
                        if (await window.FM.Dialog.confirm(`Elimina schema ${i + 1}?`)) {
                            data.schemas.splice(i, 1);
                            activeTab = Math.max(0, activeTab - (i <= activeTab ? 1 : 0));
                            renderTabs();
                            renderActive();
                            onChange();
                        }
                    }, "del");
                    tabsBar.appendChild(del);
                }
            });
            const add = button("+ Schema", () => {
                data.schemas.push(tpl.defaultSchema(data.schemas.length));
                activeTab = data.schemas.length - 1;
                renderTabs();
                renderActive();
                onChange();
            }, "add");
            tabsBar.appendChild(add);
        }
        function renderActive() {
            tabContent.innerHTML = "";
            const s = data.schemas[activeTab];
            if (s) tabContent.appendChild(renderSchemaForm(s, activeTab, (full) => {
                if (full) { rerenderForm(); }
                onChange();
            }));
        }
        renderTabs();
        renderActive();
        formEl.appendChild(tabsBar);
        formEl.appendChild(tabContent);
    }

    function getCurrentTikz() {
        const errors = tpl.validate(data);
        if (errors.length) {
            return "% ERRORI VALIDAZIONE:\n" + errors.map(e => "% - " + e).join("\n");
        }
        return tpl.renderTikz(data);
    }

    function onChange() {
        debouncePreview(previewEl, getCurrentTikz, 600);
    }

    rerenderForm();
    refreshPreview(previewEl, getCurrentTikz());

    function close() {
        if (!_modalState) return;
        _modalState = null;
        backdrop.remove();
        clearTimeout(_previewTimer);
    }

    function validateOrAlert() {
        const errors = tpl.validate(data);
        if (errors.length) {
            alert("Errori di validazione:\n" + errors.join("\n"));
            return null;
        }
        return tpl.renderTikz(data);
    }

    backdrop.addEventListener("click", async (e) => {
        const act = e.target?.dataset?.act;
        if (!act) return;

        // Toolbar 4-bottoni (mode "actions")
        if (useActions) {
            if (act === "add") {
                const tikz = validateOrAlert(); if (tikz === null) return;
                try { const ok = await opts.onAdd(tikz, data); if (ok) close(); }
                catch (err) { console.error(err); }
            } else if (act === "savepref") {
                const tikz = validateOrAlert(); if (tikz === null) return;
                try { await opts.onSavePref(tikz, data); }
                catch (err) { console.error(err); }
            } else if (act === "reset") {
                if (typeof opts.onReset === "function") {
                    try { const ok = await opts.onReset(); if (ok) close(); }
                    catch (err) { console.error(err); }
                }
            } else if (act === "cancel") {
                close();
            } else if (act === "codeview") {
                const code = getCurrentTikz();
                const cv = el("div", { class: "fm-tplf-codeview" }, [code]);
                previewEl.appendChild(cv);
                cv.scrollIntoView({ behavior: "smooth" });
            }
            return;
        }

        // Backward-compat: 1 bottone Salva (chiamato da 📋 nel quesito)
        if (act === "save") {
            const tikz = validateOrAlert(); if (tikz === null) return;
            try { onSave && onSave(tikz, data); } catch (err) { console.error(err); }
            close();
        } else if (act === "cancel") {
            confirmAsync({ title: "Annulla", message: "Annullare le modifiche?", confirmLabel: "Sì, annulla", danger: true })
                .then((ok) => { if (ok) close(); });
        } else if (act === "codeview") {
            const code = getCurrentTikz();
            const cv = el("div", { class: "fm-tplf-codeview" }, [code]);
            previewEl.appendChild(cv);
            cv.scrollIntoView({ behavior: "smooth" });
        }
    });

    // ESC chiude (con conferma se modificato)
    document.addEventListener("keydown", function escH(e) {
        if (!_modalState) { document.removeEventListener("keydown", escH); return; }
        if (e.key !== "Escape") return;
        confirmAsync({ title: "Annulla", message: "Annullare le modifiche?", confirmLabel: "Sì, annulla", danger: true })
            .then((ok) => { if (ok) close(); });
    });

    _modalState = { close };
}

if (typeof window !== "undefined") {
    window.FM = window.FM || {};
    window.FM.openTemplateFiller = openTemplateFiller;
}
