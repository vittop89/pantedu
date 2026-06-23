/**
 * G24.refactor5.step8b — Estratto da `features/checkin-handlers.js` (monolite
 * 7400+ LOC). Section builders standalone (4 funzioni, ~150 LOC) per editor
 * inline: metadata (difficulty/page/ex_num/badge color/category), meta input
 * generico, badge color field con preset + custom picker, radio section.
 *
 * Tutte pure DOM construction senza dipendenze runtime/file-state. I builders
 * complessi `buildSection` (raw textarea + preview MathJax + TikZ buttons),
 * `buildRmLayoutSection` (live RM tabella controls), `buildSingleTableCard`
 * restano nel monolite per via di molte cross-deps (rebuildRmTables,
 * _openTikzModalForBlock, _collapseTikzBlocks, etc).
 */

/** Phase 16 — metadata section: difficulty + page + ex_num + bg_color + category.
 *  Fonte/source è già nella `.origin` del `.checkIN` (non duplichiamo). Legge
 *  valori correnti dal `.fm-badge` data-* attributes o dalle classi del
 *  `.fm-collection__item`. */
export function buildMetadataSection(item) {
    const wrap = document.createElement("div");
    wrap.style.cssText = "margin-bottom:10px;padding:8px;background:#eef4ff;border:1px solid #b0c8e8;border-radius:4px;display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px";

    // Phase 16 — metadata da data-attributes SERVER-RENDERED del .fm-badge.
    // Il badge ora è raw LaTeX span (no .fm-badge-ex sub-element). ContractRenderer
    // emette: <span class="fm-badge" data-page="X" data-ex-num="Y" data-difficulty="N" data-bg-color="red" data-source="...">
    const badge = item.querySelector(".fm-badge");
    const diffCur = badge?.dataset?.difficulty
        || Array.from(item.classList).find((c) => /^diff\d$/.test(c))?.slice(4) || "0";
    wrap.appendChild(buildMetaInput("Difficoltà", "difficulty", "number", diffCur, { min: 0, max: 4, step: 1 }));

    const page = badge?.dataset?.page || "";
    const exNum = badge?.dataset?.exNum || "";
    wrap.appendChild(buildMetaInput("Pagina", "page", "text", page));
    wrap.appendChild(buildMetaInput("N. esercizio", "ex_num", "text", exNum));

    const bgCurrent = badge?.dataset?.bgColor || "gray";
    wrap.appendChild(buildBadgeColorField("Colore badge", "bg_color", bgCurrent));

    const cat = item.querySelector(".fm-titolo-quesito")?.textContent?.trim() || "";
    wrap.appendChild(buildMetaInput("Categoria", "category_label", "text", cat));

    return wrap;
}

/** Input label + input + opzionale attrs HTML (es. min/max/step per number). */
export function buildMetaInput(label, field, type, value, attrs = {}) {
    const w = document.createElement("label");
    w.style.cssText = "display:flex;flex-direction:column;gap:2px;font:12px/1.3 system-ui";
    const lbl = document.createElement("span");
    lbl.style.cssText = "font-weight:600;color:#333";
    lbl.textContent = label;
    const inp = document.createElement("input");
    inp.type = type;
    inp.value = value;
    inp.className = "fm-editor-meta";
    inp.dataset.field = field;
    inp.style.cssText = "padding:4px 6px;border:1px solid #ccc;border-radius:3px;font:13px/1 system-ui";
    Object.entries(attrs).forEach(([k, v]) => inp.setAttribute(k, String(v)));
    w.appendChild(lbl);
    w.appendChild(inp);
    return w;
}

/** Phase 16 — Campo colore badge con select preset (nomi xcolor compatibili
 *  con `\bbox[background: X,3pt]`) + color picker per custom hex. */
export function buildBadgeColorField(label, field, value) {
    const PRESETS = ["red", "green", "blue", "cyan", "orange", "yellow", "magenta", "purple", "gray", "black", "white"];
    const w = document.createElement("label");
    w.style.cssText = "display:flex;flex-direction:column;gap:2px;font:12px/1.3 system-ui";
    const lbl = document.createElement("span");
    lbl.style.cssText = "font-weight:600;color:#333";
    lbl.textContent = label;
    w.appendChild(lbl);

    const row = document.createElement("div");
    row.style.cssText = "display:flex;gap:4px;align-items:center";

    const sel = document.createElement("select");
    sel.className = "fm-editor-meta";
    sel.dataset.field = field;
    sel.style.cssText = "padding:4px 6px;border:1px solid #ccc;border-radius:3px;font:13px/1 system-ui;flex:1";
    for (const p of PRESETS) {
        const opt = document.createElement("option");
        opt.value = p;
        opt.textContent = p;
        // Swatch visivo inline: colore bg sull'option
        opt.style.cssText = `background:${p};color:${p === "white" || p === "yellow" ? "#000" : "#fff"}`;
        if (p === value) opt.selected = true;
        sel.appendChild(opt);
    }
    // "custom" option se value è un hex / valore non tra PRESETS
    if (!PRESETS.includes(value)) {
        const opt = document.createElement("option");
        opt.value = value;
        opt.textContent = `${value} (custom)`;
        opt.selected = true;
        sel.appendChild(opt);
    }

    // Color picker per custom hex
    const picker = document.createElement("input");
    picker.type = "color";
    picker.title = "Colore custom";
    picker.value = /^#[0-9a-f]{6}$/i.test(value) ? value : "#888888";
    picker.style.cssText = "width:32px;height:28px;padding:0;border:1px solid #ccc;border-radius:3px;cursor:pointer";
    picker.addEventListener("input", () => {
        // Aggiungi/aggiorna opzione custom nel select
        const hex = picker.value;
        let custom = Array.from(sel.options).find((o) => o.value === hex && o.textContent.includes("custom"));
        if (!custom) {
            custom = document.createElement("option");
            custom.textContent = `${hex} (custom)`;
            custom.value = hex;
            sel.appendChild(custom);
        }
        sel.value = hex;
    });

    row.appendChild(sel);
    row.appendChild(picker);
    w.appendChild(row);
    return w;
}

/** Radio section: label + N radio buttons mutually-exclusive con name random
 *  (evita collisione tra sezioni multiple sullo stesso documento). */
export function buildRadioSection(label, opts, selected) {
    const wrap = document.createElement("div");
    wrap.style.cssText = "margin-bottom:8px";
    const lbl = document.createElement("label");
    lbl.style.cssText = "display:block;font-weight:600;font-size:12px;margin-bottom:2px;color:#333";
    lbl.textContent = label;
    wrap.appendChild(lbl);
    const group = document.createElement("div");
    group.style.cssText = "display:flex;gap:12px";
    const name = `fm-ed-${  Math.random().toString(36).slice(2, 8)}`;
    opts.forEach((o) => {
        const id = `${name  }-${  o}`;
        const r = document.createElement("input");
        r.type = "radio"; r.name = name; r.value = o; r.id = id;
        r.className = "fm-editor-radio"; r.dataset.field = label.toLowerCase();
        if (o === selected) r.checked = true;
        const rl = document.createElement("label");
        rl.setAttribute("for", id); rl.textContent = o; rl.style.cssText = "cursor:pointer;font-weight:600;padding:0 6px";
        group.appendChild(r); group.appendChild(rl);
    });
    wrap.appendChild(group);
    return wrap;
}
