/**
 * Phase PDF-Import — tabella di revisione (render + edit inline + selezione).
 *
 * SICUREZZA: il contenuto estratto è dato NON FIDATO. Tutto il testo è
 * renderizzato via textContent (mai innerHTML) → niente XSS dal PDF/LLM.
 * DOMPurify non è una dipendenza del progetto: l'approccio textContent è
 * l'isolamento corretto.
 */

const TYPES = ["Collect", "VF", "RM_VF", "RM"];
const COLORS = ["", "red", "blue", "green", "orange"];

export class ReviewTable {
    /**
     * @param {HTMLElement} tbody
     * @param {object} handlers { onEdit(rowId,field,value), onSelectionChange(ids), onShowPage(pageNum) }
     */
    constructor(tbody, handlers) {
        this.tbody = tbody;
        this.handlers = handlers || {};
        this.rows = [];
        this.selected = new Set();
        // Opzioni per il select "Contenitore" = gruppi delle verifiche correlate.
        this.containerOptions = [];
        // Verifiche correlate [{content_id, title}] per l'opzione "nuovo gruppo".
        this.verifiche = [];
        // Codici origine (fonti) del docente per il select "Origine".
        this.originOptions = [];
    }

    /** Imposta le opzioni del select Origine (codici fonte del docente). */
    setOriginOptions(codes) {
        this.originOptions = Array.isArray(codes) ? codes.filter((c) => typeof c === "string" && c) : [];
        this.render();
    }

    /** Imposta opzioni Contenitore (gruppi verifica) + elenco verifiche. */
    setContainerOptions(opts, verifiche) {
        this.containerOptions = Array.isArray(opts) ? opts.filter(Boolean) : [];
        if (Array.isArray(verifiche)) this.verifiche = verifiche.filter(Boolean);
        this._rehydrateTargets();
        this.render();
    }

    /** Titolo del gruppo creato al volo per un dato tipo. */
    static newGroupTitle(type) {
        return `Esercizi importati (${type})`;
    }

    setRows(rows) {
        this.rows = Array.isArray(rows) ? rows : [];
        // Pulisci selezione di righe scomparse.
        const ids = new Set(this.rows.map((r) => r.id));
        for (const s of [...this.selected]) if (!ids.has(s)) this.selected.delete(s);
        this._rehydrateTargets();
        this.render();
    }

    /**
     * `target_content_id` è SOLO client (non persistito server-side): dopo un
     * reload/poll le righe conservano la stringa `container` ma perdono il
     * content_id → all'insert verrebbero saltate. Lo ricaviamo dalle opzioni
     * contenitore quando combaciano per titolo (+tipo).
     */
    _rehydrateTargets() {
        const opts = this.containerOptions;
        if (!Array.isArray(opts) || opts.length === 0) return;
        for (const r of this.rows) {
            if (r.target_content_id || !r.container) continue;
            const m = opts.find((c) => c.group === r.container && (!c.type || !r.type || c.type === r.type));
            if (m) r.target_content_id = m.content_id;
        }
    }

    selectedRows() {
        return this.rows.filter((r) => this.selected.has(r.id));
    }

    rowById(id) {
        return this.rows.find((r) => r.id === id) || null;
    }

    selectedIds() {
        return [...this.selected];
    }

    clearSelection() {
        this.selected.clear();
        this.render();
        this._emitSelection();
    }

    render() {
        const frag = document.createDocumentFragment();
        for (const row of this.rows) frag.appendChild(this._renderRow(row));
        this.tbody.replaceChildren(frag);
    }

    _renderRow(row) {
        const tr = document.createElement("tr");
        if (this.selected.has(row.id)) tr.classList.add("fm-pdfimport__row--selected");
        tr.dataset.rowId = row.id;
        // Click sulla riga (non su controlli) → anteprima renderizzata in basso.
        tr.addEventListener("click", (e) => {
            if (e.target.closest("input,select,button,[contenteditable]")) return;
            this.tbody.querySelectorAll(".fm-pdfimport__row--active")
                .forEach((x) => x.classList.remove("fm-pdfimport__row--active"));
            tr.classList.add("fm-pdfimport__row--active");
            this.handlers.onPreview?.(row);
        });

        // checkbox
        const tdSel = document.createElement("td");
        const cb = document.createElement("input");
        cb.type = "checkbox";
        cb.checked = this.selected.has(row.id);
        cb.setAttribute("aria-label", "Seleziona riga");
        cb.addEventListener("change", () => {
            if (cb.checked) this.selected.add(row.id);
            else this.selected.delete(row.id);
            tr.classList.toggle("fm-pdfimport__row--selected", cb.checked);
            this._emitSelection();
        });
        tdSel.appendChild(cb);
        tr.appendChild(tdSel);

        // page: MOSTRA la pagina STAMPATA estratta dall'immagine (row.page),
        // ma la navigazione apre la pagina-PDF per indice (row.source_page).
        const tdPage = document.createElement("td");
        const pageBtn = document.createElement("button");
        pageBtn.type = "button";
        pageBtn.className = "fm-pdfimport__btn fm-pdfimport__btn--sm fm-pdfimport__btn--ghost";
        pageBtn.textContent = String(row.page || row.source_page || "");
        pageBtn.title = "Apri la pagina nel visualizzatore";
        pageBtn.addEventListener("click", () => this.handlers.onShowPage?.(parseInt(row.source_page ?? "1", 10) || 1));
        tdPage.appendChild(pageBtn);
        tr.appendChild(tdPage);

        // number (editable text, stretto)
        tr.appendChild(this._editableCell(row, "number", "fm-pdfimport__cell--num"));
        // type (select stretto)
        tr.appendChild(this._selectCell(row, "type", TYPES));
        // color (swatch cycle)
        tr.appendChild(this._colorCell(row));
        // difficulty (select minimo 0-4)
        tr.appendChild(this._selectCell(row, "difficulty", ["0", "1", "2", "3", "4"], true, "fm-pdfimport__select--num"));
        // topic (editable)
        tr.appendChild(this._editableCell(row, "topic"));
        // origine = select coi codici fonte del docente
        tr.appendChild(this._originCell(row));
        // container = select coi nomi dei gruppi del documento sorgente
        tr.appendChild(this._containerCell(row));
        // flags
        const tdFlags = document.createElement("td");
        for (const f of row.flags || []) {
            const chip = document.createElement("span");
            chip.className = "fm-pdfimport__flag";
            chip.textContent = this._flagLabel(f);
            tdFlags.appendChild(chip);
        }
        tr.appendChild(tdFlags);

        return tr;
    }

    _editableCell(row, field, extraClass = "") {
        const td = document.createElement("td");
        const div = document.createElement("div");
        div.className = "fm-pdfimport__cell--edit" + (extraClass ? " " + extraClass : "");
        div.contentEditable = "true";
        div.spellcheck = false;
        div.textContent = String(row[field] ?? "");
        div.addEventListener("blur", () => {
            const val = div.textContent.trim();
            if (val !== String(row[field] ?? "")) {
                row[field] = val;
                this.handlers.onEdit?.(row.id, field, val);
            }
        });
        td.appendChild(div);
        return td;
    }

    _selectCell(row, field, options, numeric = false, extraClass = "") {
        const td = document.createElement("td");
        const sel = document.createElement("select");
        sel.className = "fm-pdfimport__select fm-pdfimport__select--sm" + (extraClass ? " " + extraClass : "");
        for (const opt of options) {
            const o = document.createElement("option");
            o.value = opt;
            o.textContent = opt === "" ? "—" : opt;
            if (String(row[field] ?? "") === String(opt)) o.selected = true;
            sel.appendChild(o);
        }
        sel.addEventListener("change", () => {
            const val = numeric ? parseInt(sel.value, 10) : sel.value;
            row[field] = val;
            this.handlers.onEdit?.(row.id, field, val);
            if (field === "type") {
                // Il contenitore scelto potrebbe non essere più coerente col tipo:
                // azzera e ridisegna così il select Contenitore si rifiltra.
                if (row.target_content_id) {
                    row.container = "";
                    row.target_content_id = null;
                    this.handlers.onEdit?.(row.id, "container", "");
                }
                this.render();
            }
        });
        td.appendChild(sel);
        return td;
    }

    _originCell(row) {
        const td = document.createElement("td");
        const sel = document.createElement("select");
        sel.className = "fm-pdfimport__select fm-pdfimport__select--wide";
        const cur = String(row.origin ?? "");
        const opts = [""];
        for (const c of this.originOptions) if (!opts.includes(c)) opts.push(c);
        if (cur && !opts.includes(cur)) opts.push(cur);
        for (const o of opts) {
            const opt = document.createElement("option");
            opt.value = o;
            opt.textContent = o === "" ? "— fonte —" : o;
            if (cur === o) opt.selected = true;
            sel.appendChild(opt);
        }
        sel.addEventListener("change", () => {
            row.origin = sel.value;
            this.handlers.onEdit?.(row.id, "origin", sel.value);
        });
        td.appendChild(sel);
        return td;
    }

    _containerCell(row) {
        // containerOptions = [{content_id, group}] dalle verifiche correlate.
        const td = document.createElement("td");
        const sel = document.createElement("select");
        sel.className = "fm-pdfimport__select fm-pdfimport__select--wide";

        const mk = (value, label, selected) => {
            const o = document.createElement("option");
            o.value = value; o.textContent = label;
            if (selected) o.selected = true;
            sel.appendChild(o);
        };
        mk("", "— scegli —", !row.target_content_id);

        // Mostra solo i contenitori COERENTI col tipo della riga (RM→solo RM, ecc).
        const wantType = String(row.type ?? "");
        const opts = this.containerOptions.filter((c) => !wantType || !c.type || c.type === wantType);

        // Disambigua le label se lo stesso nome gruppo è in più verifiche.
        const counts = {};
        for (const c of opts) counts[c.group] = (counts[c.group] || 0) + 1;
        for (const c of opts) {
            const val = c.content_id + "::" + c.group;
            const label = counts[c.group] > 1 ? `${c.group} (#${c.content_id})` : c.group;
            const sel0 = String(row.target_content_id ?? "") === String(c.content_id)
                && String(row.container ?? "") === c.group;
            mk(val, label, sel0);
        }

        // Opzione "➕ Nuovo gruppo {TIPO}" per ogni verifica: crea al volo un
        // gruppo del tipo della riga (utile se non ci sono contenitori compatibili).
        if (wantType) {
            const newTitle = ReviewTable.newGroupTitle(wantType);
            for (const v of this.verifiche) {
                const label = this.verifiche.length > 1
                    ? `➕ Nuovo gruppo ${wantType} — ${v.title}`
                    : `➕ Nuovo gruppo ${wantType}`;
                const sel0 = String(row.target_content_id ?? "") === String(v.content_id)
                    && String(row.container ?? "") === newTitle;
                mk(`NEW::${v.content_id}::${wantType}`, label, sel0);
            }
        }

        sel.addEventListener("change", () => {
            const v = sel.value;
            if (!v) {
                row.container = ""; row.target_content_id = null;
            } else if (v.startsWith("NEW::")) {
                const parts = v.split("::"); // ["NEW", content_id, type]
                row.target_content_id = parts[1];
                row.container = ReviewTable.newGroupTitle(parts[2]);
            } else {
                const i = v.indexOf("::");
                row.target_content_id = v.slice(0, i);
                row.container = v.slice(i + 2);
            }
            this.handlers.onEdit?.(row.id, "container", row.container);
        });
        td.appendChild(sel);
        return td;
    }

    _colorCell(row) {
        const td = document.createElement("td");
        const sw = document.createElement("button");
        sw.type = "button";
        const apply = () => {
            const c = row.badge_color || "";
            sw.className = "fm-pdfimport__swatch " + (c ? `fm-pdfimport__swatch--${c}` : "fm-pdfimport__swatch--empty");
            sw.title = c || "nessun colore";
            sw.setAttribute("aria-label", `Colore: ${c || "nessuno"}`);
        };
        apply();
        sw.addEventListener("click", () => {
            const idx = COLORS.indexOf(row.badge_color || "");
            row.badge_color = COLORS[(idx + 1) % COLORS.length];
            apply();
            this.handlers.onEdit?.(row.id, "badge_color", row.badge_color);
        });
        td.appendChild(sw);
        return td;
    }

    _previewText(row) {
        const p = row.payload || {};
        let t = String(p.question || "");
        if (p.shared_instruction) t = `[${p.shared_instruction}] ${t}`;
        return t.length > 220 ? t.slice(0, 220) + "…" : t;
    }

    _flagLabel(f) {
        const map = {
            missing_text: "no testo",
            few_options: "poche opzioni",
            correct_answer_unknown: "risposta?",
            vf_answers_unknown: "V/F?",
            has_figure: "figura",
        };
        return map[f] || f;
    }

    _emitSelection() {
        this.handlers.onSelectionChange?.(this.selectedIds());
    }
}
