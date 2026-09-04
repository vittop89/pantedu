/**
 * ProseMirror / Tiptap custom nodes per Portable Text Risdoc
 * (Phase 22.3b + 22.3d NodeView interattivi).
 *
 * 3 nodi custom + NodeView con interactivity inline:
 *
 * • FieldRef     inline atom. Click sul chip emette CustomEvent
 *                `fm-pt-node-edit` → editor apre field-picker in modal
 *                edit-mode (sostituisce il node esistente).
 *
 * • CheckboxGroup block atom con NodeView full-editable:
 *                  - Click checkbox: toggle state → transaction setNodeMarkup
 *                  - Input label: blur/Enter commit → transaction
 *                  - Btn "×" per rimuovere item
 *                  - Btn "+" in fondo per aggiungere item
 *                  (Nessun modal necessario per casi semplici)
 *
 * • RawTex       block atom. Click sulla callout → CustomEvent
 *                `fm-pt-node-edit` → editor apre rawTex modal con content
 *                precompiled.
 *
 * Pattern event: il NodeView imperativo non ha riferimento diretto al
 * Lit component. Comunica via CustomEvent(bubbles:true, composed:true).
 * Il component ascolta sul shadow root → apre modal con {pos, attrs}.
 */

import { Node, Extension, mergeAttributes } from "@tiptap/core";
import { fetchSchemaOptions } from "../../../components/risdoc/_options-fetcher.js";
import { computeTableValues, offsetFormula } from "./formula-engine.js"; // ADR-031 formule tabelle

/**
 * Allineamento testo (stile Google Docs) — aggiunge l'attributo globale
 * `textAlign` ai paragrafi PT. Replica minimale di @tiptap/extension-text-align
 * (non installato) per non aggiungere dipendenze. Round-trip:
 *   PM paragraph.attrs.textAlign  ↔  PT block.textAlign  ↔  HTML style:text-align
 *   ↔  LaTeX (center/flushright/raggedright/justify) in PtToTex.php.
 */
export const TextAlign = Extension.create({
    name: "textAlign",
    addOptions() {
        return {
            types: ["paragraph"],
            alignments: ["left", "center", "right", "justify"],
            defaultAlignment: null,
        };
    },
    addGlobalAttributes() {
        return [{
            types: this.options.types,
            attributes: {
                textAlign: {
                    default: this.options.defaultAlignment,
                    parseHTML: (el) => {
                        const a = el.style.textAlign || el.getAttribute("data-text-align") || null;
                        return this.options.alignments.includes(a) ? a : this.options.defaultAlignment;
                    },
                    renderHTML: (attrs) =>
                        attrs.textAlign ? { style: `text-align:${attrs.textAlign}` } : {},
                },
            },
        }];
    },
    addCommands() {
        return {
            setTextAlign: (alignment) => ({ commands }) => {
                if (!this.options.alignments.includes(alignment)) return false;
                return this.options.types
                    .map((type) => commands.updateAttributes(type, { textAlign: alignment }))
                    .some(Boolean);
            },
            unsetTextAlign: () => ({ commands }) =>
                this.options.types
                    .map((type) => commands.resetAttributes(type, "textAlign"))
                    .some(Boolean),
        };
    },
});

/**
 * Variante lista (presets stile ➤♦●, A.1.a., 1)a)i)…) — attributo `listStyle`
 * su bulletList/orderedList. Mappato a data-fm-list-style (CSS preset, riuso di
 * checkin-handlers) in HTML e a label enumitem in LaTeX (PtToTex). Default "".
 */
/**
 * Rientri elenco via tastiera: Tab → annida (sinkListItem), Shift+Tab →
 * dis-annida (liftListItem), SOLO quando il cursore è in un listItem. Fuori da
 * una lista ritorna false (Tab fa il comportamento di default). Sostituisce i
 * pulsanti rientro (come negli editor esercizi). Priorità alta per vincere su
 * eventuali handler Tab di default.
 */
export const ListTabKeymap = Extension.create({
    name: "listTabKeymap",
    priority: 1000,
    addKeyboardShortcuts() {
        return {
            Tab: () => this.editor.isActive("listItem")
                ? this.editor.commands.sinkListItem("listItem") : false,
            "Shift-Tab": () => this.editor.isActive("listItem")
                ? this.editor.commands.liftListItem("listItem") : false,
        };
    },
});

export const ListStyle = Extension.create({
    name: "listStyle",
    addOptions() {
        return { types: ["bulletList", "orderedList"] };
    },
    addGlobalAttributes() {
        return [{
            types: this.options.types,
            attributes: {
                listStyle: {
                    default: "",
                    parseHTML: (el) => el.getAttribute("data-fm-list-style") || "",
                    renderHTML: (attrs) =>
                        attrs.listStyle ? { "data-fm-list-style": attrs.listStyle } : {},
                },
            },
        }];
    },
});

// 2026-05-27 — Unificazione (ADR-026 C2-full): attributi-CARRY globali così i
// metadati schema (fieldType/columnKeys/seedRef/fieldName) SOPRAVVIVONO al
// round-trip dell'editor (PM li scarterebbe se non dichiarati). addGlobalAttributes
// li aggiunge a tutti i tipi-campo in un colpo (+ data-* per copy/paste/SSR).
export const CarryAttributes = Extension.create({
    name: "carryAttributes",
    addGlobalAttributes() {
        const fieldNodes = [
            "checkboxGroup", "ptSelect", "ptTextField", "ptFormCheckbox", "ptTable",
            "ptGlossaryTable", "ptLinkListPdf", "ptSectionHeader", "paragraph",
        ];
        const strAttr = (dataName) => ({
            default: "",
            parseHTML: (el) => el.getAttribute(dataName) || "",
            renderHTML: (a) => {
                const key = dataName.replace(/^data-/, "").replace(/-([a-z])/g, (_, c) => c.toUpperCase());
                return a[key] ? { [dataName]: a[key] } : {};
            },
        });
        return [
            { types: fieldNodes, attributes: { fieldType: strAttr("data-field-type") } },
            { types: ["paragraph", "ptSectionHeader"], attributes: { fieldName: strAttr("data-field-name") } },
            {
                types: ["ptTable"],
                attributes: {
                    columnKeys: {
                        default: null,
                        parseHTML: (el) => { try { return JSON.parse(el.getAttribute("data-column-keys") || "null"); } catch { return null; } },
                        renderHTML: (a) => a.columnKeys ? { "data-column-keys": JSON.stringify(a.columnKeys) } : {},
                    },
                },
            },
            { types: ["ptGlossaryTable", "ptLinkListPdf"], attributes: { seedRef: strAttr("data-seed-ref") } },
            // checkboxGroup/ptTable NON dichiarano name → lo aggiungiamo via carry
            // (data-field-id) per identificare il campo nel reverse body_pt→schema.
            { types: ["checkboxGroup", "ptTable"], attributes: { name: strAttr("data-field-id") } },
            // checkboxGroup non ha options_source (a differenza di ptSelect) → carry.
            {
                types: ["checkboxGroup"],
                attributes: {
                    options_source: {
                        default: null,
                        parseHTML: (el) => { try { return JSON.parse(el.getAttribute("data-options-source") || "null"); } catch { return null; } },
                        renderHTML: (a) => a.options_source ? { "data-options-source": JSON.stringify(a.options_source) } : {},
                    },
                },
            },
            // ADR-030 — binding per-terna ("terna" = 🔗) su QUALSIASI componente
            // porta-valore inseribile dalla barra, così il flag esplicito (toggle
            // "dipende dalla classe") sopravvive al roundtrip PT↔PM.
            {
                types: ["checkboxGroup", "ptTable", "ptSelect", "ptTextField", "ptFormCheckbox", "rawTex"],
                attributes: {
                    binding: {
                        default: null,
                        parseHTML: (el) => el.getAttribute("data-binding") || null,
                        renderHTML: (a) => a.binding ? { "data-binding": String(a.binding) } : {},
                    },
                },
            },
        ];
    },
});

// ADR-030 — bottoncino compatto 🔗/📌. In un doc "Valori per classe" ogni campo
// è 🔗 (per classe) DI DEFAULT; 📌 (binding:"fixed") lo rende condiviso. Auto-🔗
// bloccato se il componente ha una sorgente cartella. Click → dispatch({binding}).
function buildBindingBtn(attrs, dispatch) {
    const autoFolder = !!(attrs && attrs.options_source && attrs.options_source.folder);
    const isLinked = autoFolder || !(attrs && attrs.binding === "fixed");
    const b = document.createElement("button");
    b.type = "button";
    b.className = "pt-binding-btn"; // stili in fm-risdoc-pt-editor.js (shadow), BEM
    b.textContent = isLinked ? "🔗" : "📌";
    b.title = autoFolder
        ? "Prende le opzioni da una cartella → valore sempre 🔗 per indirizzo/classe/materia."
        : (isLinked
            ? "🔗 Valore per classe (default): cambia per indirizzo/classe/materia. Clic per renderlo fisso/condiviso (📌)."
            : "📌 Valore fisso: uguale per tutte le classi. Clic per renderlo di nuovo per classe (🔗).");
    if (autoFolder) b.disabled = true;
    b.addEventListener("mousedown", (e) => e.stopPropagation());
    b.addEventListener("click", (e) => { e.stopPropagation(); dispatch({ binding: isLinked ? "fixed" : null }); });
    return b;
}

// ADR-031 — funzioni per l'autocompletamento inline (nomi canonici IT).
const FORMULA_FN_LIST = [
    "SOMMA", "MEDIA", "MEDIANA", "MIN", "MAX", "CONTA", "CONTA.SE", "SOMMA.SE", "PRODOTTO",
    "ARROTONDA", "ARROTONDA.PER.DIF", "ARROTONDA.PER.ECC", "INTERO",
    "RADQ", "POTENZA", "RESTO", "ABS", "SE", "SE.ERRORE", "E", "O", "NON",
    "PERCENTUALE", "TESTO",
];
// Cella (ri,ci) appena diventata formula digitando "=": auto-focus al re-render.
let _pendingFormulaFocus = null;

/**
 * ADR-031 — editor FORMULA inline (stile foglio di calcolo): la cella mostra il
 * RISULTATO; al focus mostra la formula con autocompletamento delle funzioni
 * mentre scrivi (↓/↑/Invio/Esc); al blur ricalcola. updateCell salva cell.formula.
 */
function buildFormulaCell(td, cell, ctx, updateCell) {
    const res = ctx.formulaResult;
    const isErr = !!(res && res.error);
    const inp = document.createElement("input");
    inp.type = "text";
    inp.className = "pt-fcell" + (isErr ? " pt-fcell--err" : "");
    inp.spellcheck = false;
    inp.value = res ? res.display : "";
    inp.placeholder = "=…";
    inp.title = `Formula: ${cell.formula}` + (isErr ? `  (${res.error})` : "");
    inp.dataset.formula = cell.formula || "=";

    let ac = null, acItems = [], acIdx = -1, picking = false, editing = false;
    const closeAc = () => { if (ac) { ac.remove(); ac = null; } acItems = []; acIdx = -1; };
    const currentWord = () => {
        const v = inp.value.slice(0, inp.selectionStart ?? inp.value.length);
        const m = /([A-Za-z.]+)$/.exec(v);
        return m ? m[1] : "";
    };
    const renderAc = () => {
        if (!ac) { ac = document.createElement("div"); ac.className = "pt-fcell-ac"; td.appendChild(ac); }
        ac.innerHTML = "";
        acItems.forEach((f, i) => {
            const it = document.createElement("div");
            it.className = "pt-fcell-ac__item" + (i === acIdx ? " active" : "");
            it.textContent = f;
            it.addEventListener("mousedown", (e) => { e.preventDefault(); picking = true; acIdx = i; acPick(); picking = false; });
            ac.appendChild(it);
        });
    };
    const updateAc = () => {
        const w = currentWord().toUpperCase();
        if (!w) { closeAc(); return; }
        acItems = FORMULA_FN_LIST.filter((f) => f.startsWith(w)).slice(0, 8);
        if (!acItems.length) { closeAc(); return; }
        acIdx = 0; renderAc();
    };
    const acMove = (d) => { if (!ac) return; acIdx = (acIdx + d + acItems.length) % acItems.length;
        [...ac.children].forEach((c, i) => c.classList.toggle("active", i === acIdx)); };
    const acPick = () => {
        const fn = acItems[acIdx]; if (!fn) { closeAc(); return; }
        const pos = inp.selectionStart ?? inp.value.length;
        const before = inp.value.slice(0, pos);
        const wm = /([A-Za-z.]+)$/.exec(before);
        const start = wm ? pos - wm[1].length : pos;
        const ins = fn + "(";
        inp.value = inp.value.slice(0, start) + ins + inp.value.slice(pos);
        const caret = start + ins.length;
        closeAc(); inp.focus();
        try { inp.setSelectionRange(caret, caret); } catch (_) {}
    };
    const commit = () => {
        // GUARD CRITICO: committa SOLO se l'input è in modifica (focus → mostra la
        // FORMULA). Quando è blurrato mostra il RISULTATO (es. "3"): senza questo
        // guard il save-flush (__ptCellCommit) rileggerebbe "3" e lo salverebbe
        // come formula "=3", sovrascrivendo "=SOMMA(...)" → formula persa al salvataggio.
        if (!editing) return;
        editing = false;
        let v = inp.value.trim();
        closeAc();
        if (v === "") { updateCell({ formula: null, text: "" }); return; } // svuotata → cella di testo
        if (v.charAt(0) !== "=") v = "=" + v;
        if (v !== (cell.formula || "")) { updateCell({ widget: null, formula: v }); return; }
        // Formula invariata → niente re-render: ripristino il RISULTATO (il focus
        // aveva messo la cella in "modo formula"). Senza questo restava la formula
        // visibile senza calcolo.
        inp.value = res ? res.display : "";
    };

    // Isolamento da ProseMirror: il NodeView tabella è un atom. Senza fermare
    // TUTTI gli eventi di input (beforeinput/keypress/keyup/paste…) PM, con il
    // nodo selezionato, interpreta la digitazione come "sostituisci il nodo" →
    // la sezione spariva e compariva il carattere. (Stesso pattern di
    // bindAtomInputSafety usato dalle altre celle.)
    const stop = (e) => e.stopPropagation();
    inp.addEventListener("mousedown", stop);
    inp.addEventListener("mouseup", stop);
    inp.addEventListener("click", stop);
    inp.addEventListener("keyup", stop);
    inp.addEventListener("keypress", stop);
    inp.addEventListener("beforeinput", stop);
    inp.addEventListener("copy", stop);
    inp.addEventListener("cut", stop);
    inp.addEventListener("paste", stop);
    inp.addEventListener("focus", () => {
        editing = true; // ora l'input mostra la FORMULA → commit potrà salvarla
        inp.value = inp.dataset.formula || "=";
        try { inp.setSelectionRange(inp.value.length, inp.value.length); } catch (_) {}
    });
    inp.addEventListener("input", (e) => { e.stopPropagation(); updateAc(); });
    inp.addEventListener("keydown", (e) => {
        e.stopPropagation(); e.stopImmediatePropagation();
        if (ac) {
            if (e.key === "ArrowDown") { e.preventDefault(); acMove(1); return; }
            if (e.key === "ArrowUp")   { e.preventDefault(); acMove(-1); return; }
            if (e.key === "Enter" || e.key === "Tab") { e.preventDefault(); acPick(); return; }
            if (e.key === "Escape")    { e.preventDefault(); closeAc(); return; }
        } else if (e.key === "Enter") { e.preventDefault(); inp.blur(); }
    });
    inp.addEventListener("blur", () => { setTimeout(() => { if (!picking) commit(); }, 120); });
    inp.__ptCellCommit = commit; // per il save-flush

    td.appendChild(inp);
    if (_pendingFormulaFocus && _pendingFormulaFocus === `${ctx.ri},${ctx.ci}`) {
        _pendingFormulaFocus = null;
        setTimeout(() => { inp.focus(); try { inp.setSelectionRange(inp.value.length, inp.value.length); } catch (_) {} updateAc(); }, 0);
    }
    return inp;
}

// ADR-031 — indice colonna 0-based → lettere A1 (0→A, 25→Z, 26→AA).
function colLetter(n) {
    let s = "";
    let x = n;
    do { s = String.fromCharCode(65 + (x % 26)) + s; x = Math.floor(x / 26) - 1; } while (x >= 0);
    return s;
}

// Phase 24.32 — fill <select> con <optgroup> raggruppando per opt.group.
// Items senza group vanno top-level, gli altri sotto optgroup label=group.
// Se NON ci sono group, fallback flat.
function fillSelectGrouped(select, opts, currentValue) {
    if (!Array.isArray(opts) || opts.length === 0) return;
    const hasGroups = opts.some((o) => o && typeof o === "object" && o.group);
    if (!hasGroups) {
        for (const o of opts) {
            const opt = document.createElement("option");
            opt.value = o?.value ?? "";
            opt.textContent = o?.label ?? o?.value ?? "";
            if ((o?.value ?? "") === (currentValue ?? "")) opt.selected = true;
            select.appendChild(opt);
        }
        return;
    }
    // Group preserving insertion order
    const groups = new Map();
    for (const o of opts) {
        const g = (o && typeof o === "object" && o.group) ? String(o.group) : "";
        if (!groups.has(g)) groups.set(g, []);
        groups.get(g).push(o);
    }
    for (const [groupName, items] of groups.entries()) {
        let target = select;
        if (groupName) {
            const og = document.createElement("optgroup");
            og.label = groupName;
            select.appendChild(og);
            target = og;
        }
        for (const o of items) {
            const opt = document.createElement("option");
            opt.value = o?.value ?? "";
            opt.textContent = o?.label ?? o?.value ?? "";
            if ((o?.value ?? "") === (currentValue ?? "")) opt.selected = true;
            target.appendChild(opt);
        }
    }
}

// Phase 24.19 — catalogo globale delle sorgenti options disponibili.
// Popolato lazy dal primo popover che lo richiede; cacheato per sessione.
let _optionsSourcesCatalog = null;
let _optionsSourcesPromise = null;
export function getOptionsSourcesCatalog() {
    if (_optionsSourcesCatalog) return Promise.resolve(_optionsSourcesCatalog);
    if (_optionsSourcesPromise) return _optionsSourcesPromise;
    _optionsSourcesPromise = fetch("/api/risdoc/options-sources", { credentials: "same-origin" })
        .then((r) => r.ok ? r.json() : { files: [], folders: [] })
        .then((j) => {
            _optionsSourcesCatalog = j;
            return j;
        })
        .catch(() => ({ files: [], folders: [] }));
    return _optionsSourcesPromise;
}

// ── Sorgenti options: label leggibili + builder <select> organizzato. ──────
// Centralizza la costruzione dei menu sorgente (prima duplicata e grezza tra
// il Gruppo di checkbox e le celle Tabella). Raggruppa per dataset (optgroup),
// distingue file singoli da cartelle state-based, ed evita di elencare i singoli
// file state-based (si usano via cartella, risolti da indirizzo/classe/materia).
const PT_DATASET_LABELS = {
    competenze_DM2007: "Competenze (DM 139/2007 — assi culturali)",
    competenze_PECUP: "Competenze PECUP (licei)",
    competenze_trasversali_cittadinanza: "Competenze trasversali di cittadinanza",
    obiettivi_disciplinari_LG2010: "Obiettivi disciplinari (Linee guida 2010)",
    obiettivi_disciplinari_dipartimento: "Obiettivi disciplinari (dipartimento)",
    obiettivi_disciplinari_dipartimento_minimi: "Obiettivi disciplinari minimi",
    programmi_svolti: "Programmi svolti",
};
const PT_MATERIA_LABELS = { mat: "matematica", fis: "fisica" };
function ptPrettyDatasetLabel(ds) {
    return PT_DATASET_LABELS[ds]
        || ds.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
}
function ptPrettyEntryLabel(entry) {
    const parts = entry.path.split("/");
    if (entry.kind === "folder") {
        const rest = parts.slice(1).join(" / ");
        return (rest ? `${rest} · ` : "") + "(per indirizzo/classe/materia)";
    }
    const base = parts[parts.length - 1].replace(/\.json$/i, "");
    const m = base.match(/^([A-Za-z]+)_(\d+)_([A-Za-z]+)$/); // IND_classe_materia
    if (m) {
        const cat = parts.length >= 4 && ["competenze", "abilita", "conoscenze"].includes(parts[1])
            ? `${parts[1]} · ` : "";
        return `${cat}${m[1]} · ${PT_MATERIA_LABELS[m[3].toLowerCase()] || m[3]} · classe ${m[2]}`;
    }
    return base === parts[0] ? "(generale)" : base.replace(/_/g, " ");
}
/** Catalogo → struttura DATI raggruppata per dataset (per la cascata):
 *  [{dataset, label, entries:[{kind, path, value:"kind:path", label}]}].
 *  Include SIA le cartelle state-based (opzione "Automatico", risolta dallo
 *  stato del documento) SIA i singoli file (scelta esplicita di un file). */
export function ptGroupedSources(cat) {
    const entries = [
        ...(cat.folders || []).map((f) => ({ ...f, kind: "folder" })),
        ...(cat.files || []).map((f) => ({ ...f, kind: "file" })),
    ];
    const groups = new Map();
    for (const e of entries) {
        const ds = e.path.split("/")[0] || e.path;
        if (!groups.has(ds)) groups.set(ds, []);
        groups.get(ds).push(e);
    }
    const folderSuffix = (path) => {
        const rest = path.split("/").slice(1).join("/");
        return rest ? ` (${rest})` : "";
    };
    return [...groups.keys()]
        .sort((a, b) => ptPrettyDatasetLabel(a).localeCompare(ptPrettyDatasetLabel(b)))
        .map((ds) => ({
            dataset: ds,
            label: ptPrettyDatasetLabel(ds),
            // cartelle ("Automatico") prima, poi i file specifici (per path).
            entries: groups.get(ds)
                .sort((a, b) => (a.kind === b.kind ? a.path.localeCompare(b.path) : (a.kind === "folder" ? -1 : 1)))
                .map((e) => ({
                    kind: e.kind,
                    path: e.path,
                    value: `${e.kind}:${e.path}`,
                    label: e.kind === "folder"
                        ? `📁 Automatico${folderSuffix(e.path)} — per indirizzo/classe/materia`
                        : `📄 ${ptPrettyEntryLabel(e)}`,
                })),
        }));
}

/** Costruisce DUE select a cascata in `container`:
 *    1) tipo di contenuto (dataset, leggibile)
 *    2) variante: «Automatico» (cartella state-based) oppure file specifico
 *  Chiama onPick({file}|{folder}|null) alla scelta. selClass per lo stile. */
function buildSourceCascade(container, { current = null, onPick, selClass = "" } = {}) {
    const dsSel = document.createElement("select");
    dsSel.className = selClass;
    dsSel.title = "Tipo di contenuto (competenze, obiettivi, programmi…)";
    dsSel.innerHTML = '<option value="">— tipo di contenuto —</option>';
    dsSel.addEventListener("mousedown", (e) => e.stopPropagation());
    const varSel = document.createElement("select");
    varSel.className = selClass;
    varSel.title = "Automatico (dal documento) o un file specifico";
    varSel.style.display = "none";
    varSel.addEventListener("mousedown", (e) => e.stopPropagation());
    container.append(dsSel, varSel);

    let grouped = [];
    const renderVariants = (g, prePath = null, preKind = null) => {
        varSel.innerHTML = "";
        if (g.entries.length > 1) {
            const ph = document.createElement("option");
            ph.value = ""; ph.textContent = "— scegli variante —";
            varSel.appendChild(ph);
        }
        g.entries.forEach((e) => {
            const o = document.createElement("option");
            o.value = e.value; o.dataset.kind = e.kind; o.dataset.path = e.path;
            o.textContent = e.label;
            if (prePath && e.path === prePath && e.kind === preKind) o.selected = true;
            varSel.appendChild(o);
        });
        varSel.style.display = "";
        if (g.entries.length === 1) {
            const e = g.entries[0];
            onPick?.(e.kind === "folder" ? { folder: e.path } : { file: e.path });
        }
    };

    getOptionsSourcesCatalog().then((cat) => {
        grouped = ptGroupedSources(cat);
        grouped.forEach((g) => {
            const o = document.createElement("option");
            o.value = g.dataset; o.textContent = g.label;
            dsSel.appendChild(o);
        });
        if (current && (current.file || current.folder)) {
            const path = current.file || current.folder;
            const kind = current.file ? "file" : "folder";
            const g = grouped.find((gg) => gg.entries.some((e) => e.path === path && e.kind === kind));
            if (g) { dsSel.value = g.dataset; renderVariants(g, path, kind); }
        }
    });

    dsSel.addEventListener("change", () => {
        const g = grouped.find((gg) => gg.dataset === dsSel.value);
        if (!g) { varSel.style.display = "none"; onPick?.(null); return; }
        renderVariants(g);
    });
    varSel.addEventListener("change", () => {
        const opt = varSel.selectedOptions[0];
        const kind = opt?.dataset.kind, path = opt?.dataset.path;
        onPick?.(path ? (kind === "folder" ? { folder: path } : { file: path }) : null);
    });
    return { dsSel, varSel };
}

/**
 * Phase 24.10c — Helper per input interattivi dentro NodeView atom.
 *
 * Un atom node in ProseMirror è selezionabile come unità (`selectednode`).
 * Se l'utente preme Backspace/Delete/freccia con selection attiva sul
 * node, PM cancella l'intero node anche se il cursor testuale è dentro
 * un `<input>` interno al NodeView.
 *
 * Fix: stopPropagation su TUTTI gli eventi keyboard/mouse per evitare
 * che PM intercetti. `stopImmediatePropagation` su Backspace/Delete/
 * arrow per doppia sicurezza.
 */
function bindAtomInputSafety(input) {
    const stopKey = (e) => e.stopPropagation();
    const hardStop = (e) => { e.stopPropagation(); e.stopImmediatePropagation(); };
    input.addEventListener("keydown", (e) => {
        const dangerous = ["Backspace", "Delete", "ArrowLeft", "ArrowRight", "ArrowUp", "ArrowDown"];
        if (dangerous.includes(e.key)) hardStop(e);
        else e.stopPropagation();
        if (e.key === "Enter") { e.preventDefault(); input.blur(); }
    });
    input.addEventListener("keyup", stopKey);
    input.addEventListener("keypress", stopKey);
    input.addEventListener("beforeinput", stopKey);
    input.addEventListener("input", stopKey);
    input.addEventListener("mousedown", stopKey);
    input.addEventListener("mouseup", stopKey);
    input.addEventListener("click", stopKey);
    input.addEventListener("copy", stopKey);
    input.addEventListener("cut", stopKey);
    input.addEventListener("paste", stopKey);
}

/** Aggiunge un pulsante 🗑 (floating top-right) per ELIMINARE l'intero blocco/
 *  componente PT. Idempotente. Da richiamare dopo ogni `innerHTML = ""` del
 *  NodeView (il bottone è position:absolute → ordine DOM irrilevante). */
function attachBlockDeleteBtn(container, editor, getPos) {
    if (!container) return;
    // Il titolo-sezione (ptSectionHeader) ha già i suoi controlli + il 🗑 della
    // card: niente delete-blocco qui (eviterebbe sovrapposizioni).
    const here = typeof getPos === "function" ? getPos() : null;
    if (here != null) {
        const n0 = editor.state.doc.nodeAt(here);
        if (n0 && n0.type.name === "ptSectionHeader") return;
    }
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "pt-block-delete";
    btn.textContent = "🗑";
    btn.title = "Elimina questo componente";
    btn.setAttribute("contenteditable", "false");
    btn.addEventListener("mousedown", (e) => { e.stopPropagation(); e.preventDefault(); });
    btn.addEventListener("click", (e) => {
        e.stopPropagation();
        const pos = typeof getPos === "function" ? getPos() : null;
        if (pos == null) return;
        const node = editor.state.doc.nodeAt(pos);
        if (!node) return;
        editor.view.dispatch(editor.state.tr.delete(pos, pos + node.nodeSize));
    });
    container.appendChild(btn);
}

/** Emit helper: CustomEvent per comunicare "open edit modal" al component host. */
function emitNodeEdit(dom, detail) {
    dom.dispatchEvent(new CustomEvent("fm-pt-node-edit", {
        bubbles: true,
        composed: true,
        detail,
    }));
}

/** Inline atom: riferimento a campo compilazione. Click → modal edit. */
export const FieldRef = Node.create({
    name: "fieldRef",
    inline: true,
    group: "inline",
    atom: true,
    selectable: true,

    addAttributes() {
        return {
            name: {
                default: "",
                parseHTML: (el) => el.getAttribute("data-field") || "",
                renderHTML: (attrs) => ({ "data-field": attrs.name }),
            },
        };
    },

    parseHTML() {
        return [{ tag: 'span[data-pt-type="fieldRef"]' }];
    },

    renderHTML({ node, HTMLAttributes }) {
        return [
            "span",
            mergeAttributes(HTMLAttributes, {
                "data-pt-type": "fieldRef",
                "class": "pt-field-ref",
            }),
            `[${node.attrs.name}]`,
        ];
    },

    addNodeView() {
        return ({ node, getPos }) => {
            const dom = document.createElement("span");
            dom.className = "pt-field-ref";
            dom.setAttribute("data-pt-type", "fieldRef");
            dom.setAttribute("data-field", node.attrs.name || "");
            dom.textContent = `[${node.attrs.name || ""}]`;
            dom.title = "Click per modificare il campo";
            dom.addEventListener("click", (e) => {
                e.preventDefault();
                e.stopPropagation();
                emitNodeEdit(dom, {
                    type: "fieldRef",
                    pos: typeof getPos === "function" ? getPos() : null,
                    attrs: { ...node.attrs },
                });
            });
            return {
                dom,
                update: (updatedNode) => {
                    if (updatedNode.type.name !== "fieldRef") return false;
                    dom.setAttribute("data-field", updatedNode.attrs.name || "");
                    dom.textContent = `[${updatedNode.attrs.name || ""}]`;
                    return true;
                },
            };
        };
    },

    addCommands() {
        return {
            insertFieldRef:
                (name) =>
                ({ commands }) =>
                    commands.insertContent({
                        type: this.name,
                        attrs: { name: String(name || "") },
                    }),
        };
    },
});

/** Block atom: gruppo di checkbox fully editable in-place. */
export const CheckboxGroup = Node.create({
    name: "checkboxGroup",
    group: "block",
    atom: true,
    selectable: true,
    draggable: true,

    addAttributes() {
        return {
            items: {
                default: [],
                parseHTML: (el) => {
                    try {
                        const raw = el.getAttribute("data-items") || "[]";
                        const parsed = JSON.parse(raw);
                        return Array.isArray(parsed) ? parsed : [];
                    } catch {
                        return [];
                    }
                },
                renderHTML: (attrs) => ({
                    "data-items": JSON.stringify(attrs.items || []),
                }),
            },
            // Phase 24.19 — renderMode: "all" (default, mostra ☑/☐ per ogni item)
            //               o "checked-only" (solo items spuntati con bullet)
            renderMode: {
                default: "all",
                parseHTML: (el) => el.getAttribute("data-render-mode") || "all",
                renderHTML: (attrs) => ({
                    "data-render-mode": attrs.renderMode || "all",
                }),
            },
            // Impaginazione: numero di colonne (1–5) su cui distribuire le checkbox.
            columns: {
                default: 1,
                parseHTML: (el) => {
                    const n = parseInt(el.getAttribute("data-columns"), 10);
                    return Number.isFinite(n) ? Math.max(1, Math.min(5, n)) : 1;
                },
                renderHTML: (attrs) => ({
                    "data-columns": String(Math.max(1, Math.min(5, parseInt(attrs.columns, 10) || 1))),
                }),
            },
        };
    },

    parseHTML() {
        return [{ tag: 'div[data-pt-type="checkboxGroup"]' }];
    },

    renderHTML({ node, HTMLAttributes }) {
        // SSR fallback HTML (usato per copy/paste/ serialize). Il NodeView
        // produce la versione interactive in-editor.
        const items = Array.isArray(node.attrs.items) ? node.attrs.items : [];
        const rendered = items
            .map((it) => {
                const checked = it?.state === "x" ? " checked" : "";
                const label = escapeHtml(String(it?.label ?? ""));
                return `<label class="pt-checkbox-item"><input type="checkbox"${checked} disabled> ${label}</label>`;
            })
            .join("");
        return [
            "div",
            mergeAttributes(HTMLAttributes, {
                "data-pt-type": "checkboxGroup",
                "class": "pt-checkbox-group",
                "innerHTML": rendered,
            }),
        ];
    },

    addNodeView() {
        return ({ node, editor, getPos }) => {
            const dom = document.createElement("div");
            dom.className = "fm-pt-checkbox-group pt-editable";
            dom.setAttribute("data-pt-type", "checkboxGroup");

            const dispatchItems = (newItems) => {
                const pos = typeof getPos === "function" ? getPos() : null;
                if (pos == null) return;
                const tr = editor.view.state.tr.setNodeMarkup(pos, null, {
                    ...node.attrs,
                    items: newItems,
                });
                editor.view.dispatch(tr);
            };
            const dispatchAttr = (patch) => {
                const pos = typeof getPos === "function" ? getPos() : null;
                if (pos == null) return;
                const tr = editor.view.state.tr.setNodeMarkup(pos, null, {
                    ...node.attrs, ...patch,
                });
                editor.view.dispatch(tr);
            };

            const render = (items, renderMode, columns, optionsSource) => {
                const cols = Math.max(1, Math.min(5, parseInt(columns, 10) || 1));
                dom.innerHTML = "";
                attachBlockDeleteBtn(dom, editor, getPos);
                dom.setAttribute("data-items", JSON.stringify(items));
                dom.setAttribute("data-render-mode", renderMode || "all");
                dom.setAttribute("data-columns", String(cols));

                // Phase 24.19 — toggle render mode in TeX
                const modeBar = document.createElement("div");
                modeBar.className = "pt-checkbox-mode-bar";
                const modeLabel = document.createElement("span");
                modeLabel.className = "pt-checkbox-mode-label";
                modeLabel.textContent = "Rendering TeX:";
                modeLabel.title = "Come rendere il gruppo nella compilazione TeX";
                modeBar.appendChild(modeLabel);
                const mkModeBtn = (val, label, title) => {
                    const b = document.createElement("button");
                    b.type = "button";
                    b.className = `pt-checkbox-mode-btn${  renderMode === val ? " active" : ""}`;
                    b.textContent = label;
                    b.title = title;
                    b.addEventListener("mousedown", (e) => e.stopPropagation());
                    b.addEventListener("click", (e) => {
                        e.stopPropagation();
                        dispatchAttr({ renderMode: val });
                    });
                    return b;
                };
                modeBar.append(
                    mkModeBtn("all", "☐/☑ Tutti", "Mostra tutti gli items (spuntati e non) con checkbox TeX"),
                    mkModeBtn("checked-only", "• Solo spuntati", "Mostra solo gli items spuntati come bullet list"),
                    mkModeBtn("checked-inline", "↪ Solo spuntati inline", "Mostra solo gli items spuntati inline nel testo, separati da virgole (nessun bullet)"),
                );
                // Impaginazione: numero di colonne (1–5) via casella numerica
                // (a destra nella mode-bar). Il valore riflette la configurazione
                // corrente e si aggiorna in tempo reale al cambio.
                const colSpacer = document.createElement("span");
                colSpacer.className = "pt-checkbox-mode-spacer";
                const colLabel = document.createElement("span");
                colLabel.className = "pt-checkbox-mode-label";
                colLabel.textContent = "Colonne:";
                const colInput = document.createElement("input");
                colInput.type = "number";
                colInput.min = "1";
                colInput.max = "5";
                colInput.step = "1";
                colInput.value = String(cols);
                colInput.className = "pt-checkbox-cols-input";
                colInput.title = "Numero di colonne (1–5) su cui impaginare le checkbox";
                colInput.setAttribute("contenteditable", "false");
                colInput.addEventListener("mousedown", (e) => e.stopPropagation());
                const applyCols = () => {
                    let n = parseInt(colInput.value, 10);
                    if (!Number.isFinite(n)) n = 1;
                    n = Math.max(1, Math.min(5, n));
                    colInput.value = String(n);
                    if (n !== cols) dispatchAttr({ columns: n });
                };
                colInput.addEventListener("change", (e) => { e.stopPropagation(); applyCols(); });
                colInput.addEventListener("input", (e) => { e.stopPropagation(); });
                modeBar.append(colSpacer, colLabel, colInput);
                // ADR-030 — toggle 🔗/📌 (valore per classe) del gruppo.
                modeBar.appendChild(buildBindingBtn({ options_source: optionsSource, binding: node.attrs.binding }, dispatchAttr));
                dom.appendChild(modeBar);

                // ── Sorgente JSON + scelta gruppo (carica gli item da un file
                // JSON scegliendo UN gruppo o TUTTI con intestazioni). ──
                const srcBar = document.createElement("div");
                srcBar.className = "pt-checkbox-src-bar";
                const srcLabel = document.createElement("span");
                srcLabel.className = "pt-checkbox-mode-label";
                srcLabel.textContent = "📚 Da catalogo:";
                // Cascata: [tipo contenuto] → [Automatico / file specifico] → [gruppo].
                const cascadeWrap = document.createElement("span");
                cascadeWrap.className = "pt-checkbox-src-cascade";
                // Cascata sorgente: scegliendo un contenuto il gruppo viene
                // COLLEGATO (options_source) → si ri-idrata dallo stato del
                // documento E persiste al reload (la scelta non si perde); la
                // cascata pre-seleziona la sorgente corrente. (Prima materializzava
                // gli item ma lasciava la vecchia options_source → al reload re-
                // idratava da quella, perdendo la scelta.)
                buildSourceCascade(cascadeWrap, {
                    current: optionsSource || null,
                    selClass: "pt-checkbox-src-sel",
                    onPick: (src) => { if (src) dispatchAttr({ options_source: src }); },
                });
                srcBar.append(srcLabel, cascadeWrap);
                dom.appendChild(srcBar);

                const itemsWrap = document.createElement("div");
                itemsWrap.className = "pt-checkbox-items" + (cols >= 2 ? " pt-checkbox-items--multicol" : "");
                if (cols >= 2) itemsWrap.style.columnCount = String(cols);
                let lastGroup = null;
                items.forEach((it, i) => {
                    // Intestazione di gruppo (modalità "Tutti i gruppi").
                    if (it && it.group && it.group !== lastGroup) {
                        const gh = document.createElement("div");
                        gh.className = "pt-checkbox-group-head";
                        gh.textContent = it.group;
                        itemsWrap.appendChild(gh);
                        lastGroup = it.group;
                    }
                    const row = document.createElement("span");
                    row.className = "pt-checkbox-item pt-editable-item";

                    const cb = document.createElement("input");
                    cb.type = "checkbox";
                    cb.checked = it?.state === "x";
                    cb.title = "Toggle state";
                    cb.addEventListener("change", (e) => {
                        e.stopPropagation();
                        const next = items.map((x, j) =>
                            j === i ? { ...x, state: cb.checked ? "x" : "_" } : x,
                        );
                        dispatchItems(next);
                    });
                    cb.addEventListener("mousedown", (e) => e.stopPropagation());

                    // Phase 24.21 — textarea invece di input per permettere
                    // wrap multi-line delle label lunghe (era troncate con
                    // ellipsis). rows=1 + field-sizing:content = auto-height.
                    const labelInput = document.createElement("textarea");
                    labelInput.rows = 1;
                    labelInput.value = it?.label ?? "";
                    labelInput.className = "pt-checkbox-label-input";
                    labelInput.placeholder = "Etichetta…";
                    labelInput.title = it?.label ?? "";
                    bindAtomInputSafety(labelInput);
                    // Fallback auto-resize per browser senza field-sizing:content
                    const autoSize = () => {
                        labelInput.style.height = "auto";
                        labelInput.style.height = `${labelInput.scrollHeight  }px`;
                    };
                    labelInput.addEventListener("input", autoSize);
                    queueMicrotask(autoSize);
                    labelInput.addEventListener("blur", () => {
                        const val = labelInput.value;
                        if ((items[i]?.label ?? "") === val) return;
                        const next = items.map((x, j) =>
                            j === i ? { ...x, label: val } : x,
                        );
                        dispatchItems(next);
                    });

                    const removeBtn = document.createElement("button");
                    removeBtn.type = "button";
                    removeBtn.className = "pt-checkbox-remove";
                    removeBtn.textContent = "×";
                    removeBtn.title = "Rimuovi item";
                    removeBtn.addEventListener("click", (e) => {
                        e.stopPropagation();
                        const next = items.filter((_, j) => j !== i);
                        dispatchItems(next);
                    });
                    removeBtn.addEventListener("mousedown", (e) => e.stopPropagation());

                    row.append(cb, labelInput, removeBtn);
                    itemsWrap.appendChild(row);
                });
                dom.appendChild(itemsWrap);

                const addBtn = document.createElement("button");
                addBtn.type = "button";
                addBtn.className = "pt-checkbox-add";
                addBtn.textContent = "+ item";
                addBtn.title = "Aggiungi item";
                addBtn.addEventListener("click", (e) => {
                    e.stopPropagation();
                    dispatchItems([...items, { state: "_", label: "" }]);
                });
                addBtn.addEventListener("mousedown", (e) => e.stopPropagation());
                dom.appendChild(addBtn);
            };

            render(
                Array.isArray(node.attrs.items) ? node.attrs.items : [],
                node.attrs.renderMode || "all",
                Math.max(1, Math.min(5, parseInt(node.attrs.columns, 10) || 1)),
                node.attrs.options_source || null,
            );

            return {
                dom,
                ignoreMutation: () => true, // editing inputs non deve triggerare PM mutation parsing
                update: (updatedNode) => {
                    if (updatedNode.type.name !== "checkboxGroup") return false;
                    render(
                        Array.isArray(updatedNode.attrs.items) ? updatedNode.attrs.items : [],
                        updatedNode.attrs.renderMode || "all",
                        Math.max(1, Math.min(5, parseInt(updatedNode.attrs.columns, 10) || 1)),
                        updatedNode.attrs.options_source || null,
                    );
                    return true;
                },
            };
        };
    },

    addCommands() {
        return {
            insertCheckboxGroup:
                (items, renderMode, optionsSource) =>
                ({ commands }) =>
                    commands.insertContent({
                        type: this.name,
                        attrs: {
                            items: Array.isArray(items) ? items : [],
                            renderMode: renderMode || "all",
                            // Cartella "Automatico" → gruppo COLLEGATO (re-risolto
                            // dai selettori indirizzo/classe/materia del documento).
                            options_source: (optionsSource && typeof optionsSource === "object") ? optionsSource : null,
                        },
                    }),
        };
    },
});

/** Block atom: raw TeX. Click → modal edit con content precompiled. */
export const RawTex = Node.create({
    name: "rawTex",
    group: "block",
    atom: true,
    selectable: true,
    draggable: true,

    addAttributes() {
        return {
            content: {
                default: "",
                parseHTML: (el) => el.getAttribute("data-content") || "",
                renderHTML: (attrs) => ({ "data-content": attrs.content }),
            },
        };
    },

    parseHTML() {
        return [{ tag: 'div[data-pt-type="rawTex"]' }];
    },

    renderHTML({ node, HTMLAttributes }) {
        return [
            "div",
            mergeAttributes(HTMLAttributes, {
                "data-pt-type": "rawTex",
                "class": "pt-raw-tex",
                "aria-label": "Raw TeX",
            }),
            String(node.attrs.content ?? ""),
        ];
    },

    addNodeView() {
        return ({ node, getPos }) => {
            const dom = document.createElement("div");
            dom.className = "pt-raw-tex pt-editable";
            dom.setAttribute("data-pt-type", "rawTex");
            dom.title = "Click per modificare il TeX raw";

            const render = (content) => {
                dom.innerHTML = "";
                attachBlockDeleteBtn(dom, editor, getPos);
                dom.setAttribute("data-content", content);
                const prefix = document.createElement("span");
                prefix.className = "pt-raw-tex-prefix";
                prefix.textContent = "\\TeX ";
                const body = document.createElement("span");
                body.className = "pt-raw-tex-body";
                body.textContent = content || "(vuoto — click per editare)";
                dom.append(prefix, body);
            };

            dom.addEventListener("click", (e) => {
                e.preventDefault();
                e.stopPropagation();
                emitNodeEdit(dom, {
                    type: "rawTex",
                    pos: typeof getPos === "function" ? getPos() : null,
                    attrs: { ...node.attrs },
                });
            });

            render(String(node.attrs.content ?? ""));

            return {
                dom,
                update: (updatedNode) => {
                    if (updatedNode.type.name !== "rawTex") return false;
                    render(String(updatedNode.attrs.content ?? ""));
                    return true;
                },
            };
        };
    },

    addCommands() {
        return {
            insertRawTex:
                (content) =>
                ({ commands }) =>
                    commands.insertContent({
                        type: this.name,
                        attrs: { content: String(content || "") },
                    }),
        };
    },
});

/** Normalizza larghezze per-colonna in percentuali (somma 100). Ripartizione
 *  equa se non tutte le colonne hanno un valore valido (>0). Mirror di
 *  PtToHtml::normalizeColWidths / pt-to-html.js normalizeColWidths. */
function normalizeColWidthsPct(widths, colCount) {
    if (colCount <= 0) return [];
    const w = Array.isArray(widths) ? widths : [];
    const vals = [];
    let allValid = true;
    for (let i = 0; i < colCount; i++) {
        const v = Number.isFinite(+w[i]) ? +w[i] : 0;
        if (!(v > 0)) allValid = false;
        vals[i] = v > 0 ? v : 0;
    }
    const sum = vals.reduce((a, b) => a + b, 0);
    if (!allValid || sum <= 0) {
        return Array.from({ length: colCount }, () => Math.round((100 / colCount) * 100) / 100);
    }
    return vals.map((v) => Math.round((v / sum) * 100 * 100) / 100);
}

/** Phase 24.1 — Block atom: table con cells editabili inline + toolbar add/remove. */
export const PtTable = Node.create({
    name: "ptTable",
    group: "block",
    atom: true,
    selectable: true,
    draggable: true,

    addAttributes() {
        return {
            columns: {
                default: [],
                parseHTML: (el) => {
                    try {
                        const raw = el.getAttribute("data-columns") || "[]";
                        const p = JSON.parse(raw);
                        return Array.isArray(p) ? p : [];
                    } catch { return []; }
                },
                renderHTML: (attrs) => ({ "data-columns": JSON.stringify(attrs.columns || []) }),
            },
            rows: {
                default: [],
                parseHTML: (el) => {
                    try {
                        const raw = el.getAttribute("data-rows") || "[]";
                        const p = JSON.parse(raw);
                        return Array.isArray(p) ? p : [];
                    } catch { return []; }
                },
                renderHTML: (attrs) => ({ "data-rows": JSON.stringify(attrs.rows || []) }),
            },
            caption: { default: "" },
            // Phase 24.19 — header/footer text (nota titolo sopra e piè tabella)
            headerNote: {
                default: "",
                parseHTML: (el) => el.getAttribute("data-header-note") || "",
                renderHTML: (attrs) => ({ "data-header-note": attrs.headerNote || "" }),
            },
            footerNote: {
                default: "",
                parseHTML: (el) => el.getAttribute("data-footer-note") || "",
                renderHTML: (attrs) => ({ "data-footer-note": attrs.footerNote || "" }),
            },
            // Larghezza tabella: "auto" (adatta al contenuto, default) |
            // "full" (occupa tutta la larghezza utile della pagina, che
            // dipende dall'orientamento portrait/landscape lato TeX).
            widthMode: {
                default: "auto",
                parseHTML: (el) => el.getAttribute("data-width-mode") || "auto",
                renderHTML: (attrs) => ({ "data-width-mode": attrs.widthMode || "auto" }),
            },
            // Larghezze relative per-colonna in percentuale (somma ≈ 100).
            // Significative solo con widthMode="full"; vuoto → colonne uguali.
            colWidths: {
                default: [],
                parseHTML: (el) => {
                    try {
                        const p = JSON.parse(el.getAttribute("data-col-widths") || "[]");
                        return Array.isArray(p) ? p : [];
                    } catch { return []; }
                },
                renderHTML: (attrs) => ({ "data-col-widths": JSON.stringify(attrs.colWidths || []) }),
            },
        };
    },

    parseHTML() {
        return [{ tag: 'table[data-pt-type="ptTable"]' }];
    },

    renderHTML({ node, HTMLAttributes }) {
        const cols = Array.isArray(node.attrs.columns) ? node.attrs.columns : [];
        const rows = Array.isArray(node.attrs.rows) ? node.attrs.rows : [];
        const thead = `<thead><tr>${cols.map((c) => `<th>${escapeHtml(c)}</th>`).join("")}</tr></thead>`;
        const tbody = rows.map((row) => {
            if (!Array.isArray(row)) return "";
            const cells = Array.from({ length: cols.length }, (_, i) =>
                `<td>${escapeHtml(String(row[i] ?? ""))}</td>`
            ).join("");
            return `<tr>${cells}</tr>`;
        }).join("");
        return [
            "table",
            mergeAttributes(HTMLAttributes, {
                "data-pt-type": "ptTable",
                "class": "pt-table",
                "innerHTML": `${thead  }<tbody>${  tbody  }</tbody>`,
            }),
        ];
    },

    addNodeView() {
        return ({ node, editor, getPos }) => {
            const container = document.createElement("div");
            container.className = "pt-table-container pt-editable";
            container.setAttribute("data-pt-type", "ptTable");

            const dispatch = (newAttrs) => {
                const pos = typeof getPos === "function" ? getPos() : null;
                if (pos == null) return;
                // BUGFIX — leggi gli attrs CORRENTI dal doc (non `node.attrs`,
                // catturato alla creazione del NodeView e ormai stantio): un
                // dispatch parziale (es. solo widthMode dal toggle larghezza)
                // ripristinava le righe iniziali, cancellando le modifiche.
                const cur = editor.view.state.doc.nodeAt(pos);
                const base = (cur && cur.type.name === "ptTable") ? cur.attrs : node.attrs;
                const tr = editor.view.state.tr.setNodeMarkup(pos, null, {
                    ...base,
                    ...newAttrs,
                });
                editor.view.dispatch(tr);
            };

            // Attrs CORRENTI della tabella dal doc (no closure stantia): usato
            // dagli handler add/remove riga/colonna per partire dallo stato vero.
            const curAttrs = () => {
                const pos = typeof getPos === "function" ? getPos() : null;
                const n = pos == null ? null : editor.view.state.doc.nodeAt(pos);
                return (n && n.type.name === "ptTable") ? n.attrs : node.attrs;
            };
            // Commit dell'eventuale input cella col focus (l'edit pendente non si
            // perde quando si clicca un bottone di toolbar che ri-renderizza).
            const flushFocusedInput = () => {
                const el = container.querySelector("input:focus, textarea:focus, .pt-table-cell-rich:focus");
                if (el && typeof el.__ptCellCommit === "function") el.__ptCellCommit();
            };
            // Cella col cursore ADESSO (live), null se il focus non è in una cella.
            // {ri,ci} con ri=-1 per l'header. Letta PRIMA del flush (che può
            // ri-renderizzare e togliere il focus).
            const focusedCell = () => {
                const el = container.querySelector(
                    "td input:focus, td textarea:focus, td .pt-table-cell-rich:focus, th input:focus, th textarea:focus");
                const cellEl = el && el.closest("td, th");
                if (!cellEl || cellEl.dataset.ci === undefined) return null;
                return { ri: parseInt(cellEl.dataset.ri, 10), ci: parseInt(cellEl.dataset.ci, 10) };
            };

            const mkBtn = (label, title, handler) => {
                const b = document.createElement("button");
                b.type = "button";
                b.className = "pt-table-btn";
                b.textContent = label;
                b.title = title;
                // preventDefault su mousedown → il focus resta sulla cella attiva
                // (niente blur che distrugge il bottone prima del click). La cella
                // col cursore è catturata PRIMA del flush e passata all'handler.
                b.addEventListener("mousedown", (e) => { e.stopPropagation(); e.preventDefault(); });
                b.addEventListener("click", (e) => {
                    e.stopPropagation();
                    const fc = focusedCell();
                    flushFocusedInput();
                    handler(fc);
                });
                return b;
            };

            // Phase 24.18 — preserva stato popover-cell aperto tra re-render
            // della tabella. Dopo render(), se c'era un popover aperto su
            // (ri,ci), lo riapriamo nel nuovo td.
            container.__openCellPop = null; // {ri, ci} oppure null

            // ADR-031 — BARRA FORMULA (stile Excel): mostra/edita la formula
            // completa della cella selezionata (utile per le formule lunghe che
            // non entrano nella cella). Stato a livello NodeView (sopravvive ai
            // re-render); _fbInput/_fbRef sono ricreati ad ogni render.
            let _selCell = null;              // {ri, ci} cella selezionata o null
            let _fbInput = null, _fbRef = null;
            let _fillHandle = null;           // maniglia di riempimento (drag-to-fill)
            const _cellRaw = (ri, ci) => {
                const cur = curAttrs();
                const rows = Array.isArray(cur.rows) ? cur.rows : [];
                const cell = normalizeCell((rows[ri] || [])[ci]);
                if (cell.formula) return cell.formula;
                if (cell.widget) return cell.widget.value ?? "";
                return cell.text || "";
            };
            const _setSelected = (ri, ci) => {
                _selCell = (ri == null || ci == null || ri < 0) ? null : { ri, ci };
                if (_fbRef) _fbRef.textContent = _selCell ? (colLetter(_selCell.ci) + (_selCell.ri + 1)) : "";
                if (_fbInput) {
                    _fbInput.value = _selCell ? _cellRaw(_selCell.ri, _selCell.ci) : "";
                    _fbInput.disabled = !_selCell;
                }
                // Evidenzia la cella selezionata + sposta la maniglia di riempimento
                // nel suo angolo in basso a destra (stile Excel).
                container.querySelectorAll("td.pt-table-td.is-selected")
                    .forEach((td) => td.classList.remove("is-selected"));
                if (_fillHandle && _fillHandle.parentNode) _fillHandle.remove();
                if (_selCell && _fillHandle) {
                    const td = container.querySelector(
                        `td.pt-table-td[data-ri="${_selCell.ri}"][data-ci="${_selCell.ci}"]`);
                    if (td) { td.classList.add("is-selected"); td.appendChild(_fillHandle); }
                }
            };
            // Drag-to-fill: copia la cella sorgente nelle celle trascinate (riga o
            // colonna), adattando i riferimenti RELATIVI delle formule (offsetFormula).
            const _startFillDrag = (ev) => {
                ev.preventDefault(); ev.stopPropagation();
                if (!_selCell) return;
                const src = { ..._selCell };
                const cur = curAttrs();
                const rows0 = Array.isArray(cur.rows) ? cur.rows : [];
                const nrows = rows0.length;
                const ncols = Array.isArray(cur.columns) ? cur.columns.length : 0;
                let targets = [];
                const clearHi = () => container.querySelectorAll("td.pt-fill-target")
                    .forEach((td) => td.classList.remove("pt-fill-target"));
                const onMove = (e) => {
                    // L'editor è in shadow DOM: su un listener a livello document
                    // e.target è retargettato all'host → uso composedPath()[0] per
                    // avere l'elemento REALE sotto il puntatore.
                    const path = typeof e.composedPath === "function" ? e.composedPath() : null;
                    const node = (path && path[0]) || e.target;
                    const td = node?.closest?.("td.pt-table-td");
                    clearHi(); targets = [];
                    if (!td || td.dataset.ci === undefined) return;
                    const ri = parseInt(td.dataset.ri, 10), ci = parseInt(td.dataset.ci, 10);
                    const dR = ri - src.ri, dC = ci - src.ci;
                    if (dR === 0 && dC === 0) return;
                    const vert = Math.abs(dR) >= Math.abs(dC);
                    const step = vert ? (dR >= 0 ? 1 : -1) : (dC >= 0 ? 1 : -1);
                    const last = vert ? ri : ci;
                    for (let k = (vert ? src.ri : src.ci) + step; ; k += step) {
                        const r = vert ? k : src.ri, c = vert ? src.ci : k;
                        if (r < 0 || r >= nrows || c < 0 || c >= ncols) break;
                        targets.push({ r, c });
                        const tt = container.querySelector(`td.pt-table-td[data-ri="${r}"][data-ci="${c}"]`);
                        if (tt) tt.classList.add("pt-fill-target");
                        if (k === last) break;
                    }
                };
                const onUp = () => {
                    document.removeEventListener("mousemove", onMove, true);
                    document.removeEventListener("mouseup", onUp, true);
                    clearHi();
                    if (!targets.length) return;
                    const cur2 = curAttrs();
                    const rows = (Array.isArray(cur2.rows) ? cur2.rows : []).map(
                        (r) => Array.isArray(r) ? [...r] : r);
                    const srcCell = normalizeCell((rows[src.ri] || [])[src.ci]);
                    // Clona in PROFONDITÀ qualsiasi widget (select/textField/…): così
                    // options, options_source, value-array ecc. sono copiati e
                    // indipendenti tra le celle riempite (niente riferimenti condivisi).
                    const cloneWidget = (w) => {
                        if (!w || typeof w !== "object") return null;
                        try { return JSON.parse(JSON.stringify(w)); } catch (_) { return { ...w }; }
                    };
                    targets.forEach(({ r, c }) => {
                        const tgt = normalizeCell((rows[r] || [])[c]);
                        if (tgt.merged) return; // non riempire i placeholder di merge
                        const merged = {
                            ...tgt,                                  // mantieni struttura + cid (id unico) del target
                            text: srcCell.text,
                            widget: cloneWidget(srcCell.widget),     // select/input/oggetti annidati
                            bg: srcCell.bg, align: srcCell.align, valign: srcCell.valign,
                        };
                        // copia anche l'impostazione per-classe (binding), tenendo il cid del target
                        if (srcCell.binding) merged.binding = srcCell.binding; else delete merged.binding;
                        if (srcCell.formula) merged.formula = offsetFormula(srcCell.formula, r - src.ri, c - src.ci);
                        else delete merged.formula;
                        if (!Array.isArray(rows[r])) rows[r] = [];
                        rows[r][c] = compactTableCell(merged);
                    });
                    dispatch({ rows });
                };
                document.addEventListener("mousemove", onMove, true);
                document.addEventListener("mouseup", onUp, true);
            };
            const _applyFormulaBar = () => {
                if (!_selCell || !_fbInput) return;
                const { ri, ci } = _selCell;
                const cur = curAttrs();
                const rows = Array.isArray(cur.rows) ? cur.rows : [];
                if (ri < 0 || ri >= rows.length) return;
                const v = String(_fbInput.value).trim();
                const newRows = rows.map((r, ix) => {
                    if (ix !== ri) return r;
                    const copy = Array.isArray(r) ? [...r] : [];
                    const ex = normalizeCell(copy[ci]);
                    let patch;
                    if (v === "") patch = { formula: null, text: "", widget: ex.widget };       // svuota
                    else if (v.charAt(0) === "=") patch = { formula: v, widget: null, text: "" }; // formula
                    else if (ex.widget) patch = { widget: { ...ex.widget, value: v }, formula: null }; // valore widget
                    else patch = { formula: null, widget: null, text: v };                       // testo
                    copy[ci] = compactTableCell({ ...ex, ...patch });
                    return copy;
                });
                dispatch({ rows: newRows });
            };

            const render = (columns, rows, caption, headerNote, footerNote, widthMode, colWidths) => {
                const savedOpenPop = container.__openCellPop;
                const isFull = widthMode === "full";
                container.innerHTML = "";
                attachBlockDeleteBtn(container, editor, getPos);
                container.setAttribute("data-columns", JSON.stringify(columns));
                container.setAttribute("data-rows",    JSON.stringify(rows));
                container.setAttribute("data-width-mode", isFull ? "full" : "auto");

                // Phase 24.19 — Header note (titolo/sopra tabella)
                const headerInput = document.createElement("input");
                headerInput.type = "text";
                headerInput.className = "fm-pt-table-note pt-table-note-header";
                headerInput.value = headerNote || "";
                headerInput.placeholder = "Nota/titolo sopra la tabella (opzionale)";
                bindAtomInputSafety(headerInput);
                headerInput.addEventListener("blur", () => {
                    if ((headerNote ?? "") === headerInput.value) return;
                    dispatch({ headerNote: headerInput.value });
                });
                container.appendChild(headerInput);

                // ── Inserimento/rimozione righe e colonne RELATIVE alla cella col
                // cursore (focusedCell, letta al click). Inserimento: senza cursore
                // → append in fondo/destra. Eliminazione: richiede il cursore in una
                // cella, altrimenti banner bloccante.
                // Banner bloccante (no eliminazione senza cursore in una cella).
                const blockDelete = (msg) => {
                    if (window.FM?.Dialog?.alert) {
                        window.FM.Dialog.alert(msg, { title: "Eliminazione bloccata", kind: "warn" });
                    } else {
                        window.alert(msg);
                    }
                };
                // NB: leggono SEMPRE lo stato corrente (curAttrs), non la closure,
                // così un flush/commit appena fatto è già incluso.
                const insertRow = (at) => {
                    const a = curAttrs();
                    const cols = Array.isArray(a.columns) ? a.columns : [];
                    const rws = Array.isArray(a.rows) ? a.rows : [];
                    const idx = Math.max(0, Math.min(at, rws.length));
                    dispatch({ rows: [...rws.slice(0, idx), cols.map(() => ""), ...rws.slice(idx)] });
                };
                const removeRow = (at) => {
                    const rws = Array.isArray(curAttrs().rows) ? curAttrs().rows : [];
                    if (rws.length === 0) return;
                    const idx = at == null ? rws.length - 1 : Math.max(0, Math.min(at, rws.length - 1));
                    dispatch({ rows: rws.filter((_, i) => i !== idx) });
                };
                const insertCol = (at) => {
                    const a = curAttrs();
                    const cols = Array.isArray(a.columns) ? a.columns : [];
                    const rws = Array.isArray(a.rows) ? a.rows : [];
                    const cw = Array.isArray(a.colWidths) ? a.colWidths : [];
                    const idx = Math.max(0, Math.min(at, cols.length));
                    const patch = {
                        columns: [...cols.slice(0, idx), "", ...cols.slice(idx)],
                        rows: rws.map((row) => [...row.slice(0, idx), "", ...row.slice(idx)]),
                    };
                    if (cw.length) {
                        patch.colWidths = [...cw.slice(0, idx), Math.round(100 / (cols.length + 1)), ...cw.slice(idx)];
                    }
                    dispatch(patch);
                };
                const removeCol = (at) => {
                    const a = curAttrs();
                    const cols = Array.isArray(a.columns) ? a.columns : [];
                    const rws = Array.isArray(a.rows) ? a.rows : [];
                    const cw = Array.isArray(a.colWidths) ? a.colWidths : [];
                    if (cols.length <= 1) return;
                    const idx = at == null ? cols.length - 1 : Math.max(0, Math.min(at, cols.length - 1));
                    const patch = {
                        columns: cols.filter((_, i) => i !== idx),
                        rows: rws.map((row) => row.filter((_, i) => i !== idx)),
                    };
                    if (cw.length) patch.colWidths = cw.filter((_, i) => i !== idx);
                    dispatch(patch);
                };

                const toolbar = document.createElement("div");
                toolbar.className = "pt-table-toolbar";
                const rowGroup = document.createElement("span");
                rowGroup.className = "pt-table-toolbar__group";
                rowGroup.append(
                    Object.assign(document.createElement("span"), { className: "pt-table-toolbar__lbl", textContent: "Righe:" }),
                    mkBtn("↑ sopra", "Inserisci una riga SOPRA la cella col cursore (o in cima se nessuna)",
                        (fc) => insertRow(fc && fc.ri >= 0 ? fc.ri : 0)),
                    mkBtn("↓ sotto", "Inserisci una riga SOTTO la cella col cursore (o in fondo se nessuna)",
                        (fc) => insertRow(fc && fc.ri >= 0 ? fc.ri + 1 : curAttrs().rows.length)),
                    mkBtn("✕ riga", "Elimina la riga della cella col cursore (richiede il cursore in una cella)",
                        (fc) => {
                            if (!fc || fc.ri < 0) {
                                blockDelete("Per eliminare una riga, posiziona prima il cursore in una cella della riga da eliminare.");
                                return;
                            }
                            removeRow(fc.ri);
                        }),
                );
                const colGroup = document.createElement("span");
                colGroup.className = "pt-table-toolbar__group";
                colGroup.append(
                    Object.assign(document.createElement("span"), { className: "pt-table-toolbar__lbl", textContent: "Colonne:" }),
                    mkBtn("← sinistra", "Inserisci una colonna a SINISTRA della cella col cursore (o in testa)",
                        (fc) => insertCol(fc ? fc.ci : 0)),
                    mkBtn("→ destra", "Inserisci una colonna a DESTRA della cella col cursore (o in fondo)",
                        (fc) => insertCol(fc ? fc.ci + 1 : curAttrs().columns.length)),
                    mkBtn("✕ col", "Elimina la colonna della cella col cursore (richiede il cursore in una cella)",
                        (fc) => {
                            if (!fc) {
                                blockDelete("Per eliminare una colonna, posiziona prima il cursore in una cella della colonna da eliminare.");
                                return;
                            }
                            removeCol(fc.ci);
                        }),
                );
                toolbar.append(rowGroup, colGroup);
                container.appendChild(toolbar);

                // ── Larghezza tabella (BEM .fm-pt-tw) ─────────────────────────
                // Toggle Adatta-contenuto / Tutta-pagina + (se full) larghezze
                // per-colonna in %. La larghezza piena segue la max-width della
                // pagina (portrait/landscape lato TeX/anteprima).
                const twBar = document.createElement("div");
                twBar.className = "fm-pt-tw";
                const twLabel = document.createElement("span");
                twLabel.className = "fm-pt-tw__label";
                twLabel.textContent = "Larghezza:";
                twBar.appendChild(twLabel);
                const mkTwBtn = (mode, label, title) => {
                    const b = document.createElement("button");
                    b.type = "button";
                    b.className = `fm-pt-tw__btn${(isFull ? "full" : "auto") === mode ? " fm-pt-tw__btn--active" : ""}`;
                    b.textContent = label;
                    b.title = title;
                    b.addEventListener("mousedown", (e) => e.stopPropagation());
                    b.addEventListener("click", (e) => {
                        e.stopPropagation();
                        dispatch({ widthMode: mode === "full" ? "full" : "auto" });
                    });
                    return b;
                };
                twBar.append(
                    mkTwBtn("auto", "↔ Adatta contenuto", "La tabella si adatta al contenuto"),
                    mkTwBtn("full", "⬌ Tutta la pagina", "La tabella occupa tutta la larghezza della pagina"),
                );
                if (isFull && columns.length > 0) {
                    const pct = normalizeColWidthsPct(colWidths, columns.length);
                    const colsWrap = document.createElement("div");
                    colsWrap.className = "fm-pt-tw__cols";
                    columns.forEach((_, ci) => {
                        const field = document.createElement("label");
                        field.className = "fm-pt-tw__col";
                        field.title = `Larghezza colonna ${ci + 1} (%)`;
                        const num = document.createElement("input");
                        num.type = "number";
                        num.min = "1"; num.max = "100"; num.step = "1";
                        num.className = "fm-pt-tw__input";
                        num.value = String(Math.round(pct[ci]));
                        num.addEventListener("mousedown", (e) => e.stopPropagation());
                        const commit = () => {
                            const next = normalizeColWidthsPct(colWidths, columns.length).slice();
                            const v = Math.max(1, Math.min(100, parseInt(num.value, 10) || 0));
                            next[ci] = v;
                            dispatch({ colWidths: next });
                        };
                        num.addEventListener("change", commit);
                        field.append(num, Object.assign(document.createElement("span"), {
                            className: "fm-pt-tw__unit", textContent: "%",
                        }));
                        colsWrap.appendChild(field);
                    });
                    twBar.appendChild(colsWrap);
                }
                container.appendChild(twBar);

                // ── Barra formula (sopra la tabella) ──
                const fbar = document.createElement("div");
                fbar.className = "pt-formula-bar";
                const fbIcon = document.createElement("span");
                fbIcon.className = "pt-formula-bar__fx";
                fbIcon.textContent = "fx";
                fbIcon.title = "Barra della formula — clicca una cella per vederne/modificarne la formula";
                _fbRef = document.createElement("span");
                _fbRef.className = "pt-formula-bar__ref";
                _fbInput = document.createElement("input");
                _fbInput.type = "text";
                _fbInput.className = "pt-formula-bar__input";
                _fbInput.spellcheck = false;
                _fbInput.disabled = true;
                _fbInput.placeholder = "Seleziona una cella… (es. =SOMMA(A1:A3))";
                bindAtomInputSafety(_fbInput); // Invio → blur → applica
                _fbInput.addEventListener("blur", _applyFormulaBar);
                _fbInput.__ptCellCommit = _applyFormulaBar; // flush al salvataggio
                fbar.append(fbIcon, _fbRef, _fbInput);
                container.appendChild(fbar);

                const table = document.createElement("table");
                table.className = `fm-pt-table${isFull ? " fm-pt-table--full" : ""}`;
                if (isFull && columns.length > 0) {
                    const pct = normalizeColWidthsPct(colWidths, columns.length);
                    const cg = document.createElement("colgroup");
                    pct.forEach((w) => {
                        const col = document.createElement("col");
                        col.style.width = `${w}%`;
                        cg.appendChild(col);
                    });
                    table.appendChild(cg);
                }

                const thead = document.createElement("thead");
                const hrow = document.createElement("tr");
                columns.forEach((col, ci) => {
                    const th = document.createElement("th");
                    th.dataset.ri = "-1";   // header → ri=-1 (ops colonna ok dal th)
                    th.dataset.ci = String(ci);
                    const input = document.createElement("input");
                    input.type = "text";
                    input.value = col ?? "";
                    input.className = "pt-table-cell-input pt-table-cell-header";
                    input.placeholder = `Col ${ci + 1}`;
                    bindAtomInputSafety(input);
                    const commitCol = () => {
                        if ((columns[ci] ?? "") === input.value) return;
                        const next = [...columns];
                        next[ci] = input.value;
                        dispatch({ columns: next });
                    };
                    input.__ptCellCommit = commitCol;
                    input.addEventListener("blur", commitCol);
                    th.appendChild(input);
                    hrow.appendChild(th);
                });
                thead.appendChild(hrow);
                table.appendChild(thead);

                // ADR-031 — calcola TUTTE le celle formula della griglia (dipendenze
                // + cicli) PRIMA del render, così ogni cella formula mostra il
                // risultato. Ricalcolo ad ogni render (il NodeView ri-renderizza al
                // cambio di un valore → aggiornamento automatico).
                const fGrid = rows.map((row) => {
                    const out = [];
                    for (let c = 0; c < columns.length; c++) {
                        const cc = normalizeCell(row[c]);
                        if (cc.formula) out.push({ formula: cc.formula });
                        else out.push({ raw: cc.widget ? (cc.widget.value ?? "") : (cc.text || "") });
                    }
                    return out;
                });
                let fResults = [];
                try { fResults = computeTableValues(fGrid); } catch (_) { fResults = []; }

                const tbody = document.createElement("tbody");
                rows.forEach((row, ri) => {
                    const tr = document.createElement("tr");
                    // Una colonna per slot dell'array riga (ci = indice colonna). I
                    // placeholder `merged` (coperti da colspan/rowspan) si saltano;
                    // gli altri rendono un <td> con lo span. NB: si itera per
                    // indice-colonna, NON si avanza di colSpan — altrimenti le celle
                    // DOPO un colspan venivano perse (bug merge colonne). Slot
                    // mancanti (riga più corta) → cella vuota via normalizeCell.
                    for (let ci = 0; ci < columns.length; ci++) {
                        const cell = normalizeCell(row[ci]);
                        if (cell.merged) continue;
                        const td = document.createElement("td");
                        td.dataset.ri = String(ri);
                        td.dataset.ci = String(ci);
                        if (cell.colspan > 1) td.colSpan = Math.min(cell.colspan, columns.length - ci);
                        if (cell.rowspan > 1) td.rowSpan = cell.rowspan;
                        td.className = "pt-table-td";
                        if (cell.bg) td.style.backgroundColor = cell.bg; // Phase 24.32
                        if (cell.align) td.style.textAlign = cell.align;
                        if (cell.valign) td.style.verticalAlign = cell.valign;
                        // ADR-031 — badge riferimento A1 (es. "B2") per scrivere le formule.
                        const refBadge = document.createElement("span");
                        refBadge.className = "pt-table-cell-ref";
                        refBadge.textContent = colLetter(ci) + (ri + 1);
                        td.appendChild(refBadge);
                        buildCellUI(td, cell, { ri, ci, row, rows, columns, dispatch, formulaResult: fResults[ri]?.[ci] });
                        tr.appendChild(td);
                    }
                    tbody.appendChild(tr);
                });
                table.appendChild(tbody);

                if (caption) {
                    const cap = document.createElement("div");
                    cap.className = "pt-table-caption";
                    cap.textContent = caption;
                    container.appendChild(cap);
                }

                // Barra formula: al focus di una cella aggiorna riferimento+formula.
                table.addEventListener("focusin", (e) => {
                    const cellEl = e.target.closest("td, th");
                    if (cellEl && cellEl.dataset.ci !== undefined) {
                        _setSelected(parseInt(cellEl.dataset.ri, 10), parseInt(cellEl.dataset.ci, 10));
                    }
                });
                container.appendChild(table);
                // Maniglia di riempimento (ricreata ad ogni render; _setSelected la
                // sposta nell'angolo della cella selezionata).
                _fillHandle = document.createElement("div");
                _fillHandle.className = "pt-fill-handle";
                _fillHandle.title = "Trascina per copiare il contenuto nelle celle vicine (le formule adattano i riferimenti)";
                _fillHandle.addEventListener("mousedown", _startFillDrag);
                // Ripristina la barra + selezione persistita (re-render).
                _setSelected(_selCell ? _selCell.ri : null, _selCell ? _selCell.ci : null);

                // Phase 24.19 — Footer note (piè tabella)
                const footerInput = document.createElement("input");
                footerInput.type = "text";
                footerInput.className = "fm-pt-table-note pt-table-note-footer";
                footerInput.value = footerNote || "";
                footerInput.placeholder = "Nota sotto la tabella (opzionale)";
                bindAtomInputSafety(footerInput);
                footerInput.addEventListener("blur", () => {
                    if ((footerNote ?? "") === footerInput.value) return;
                    dispatch({ footerNote: footerInput.value });
                });
                container.appendChild(footerInput);

                // Phase 24.18 — se c'era un popover aperto, riaprilo
                if (savedOpenPop) {
                    const { ri, ci } = savedOpenPop;
                    const trs = table.querySelectorAll("tbody tr");
                    if (ri < trs.length) {
                        const tds = trs[ri].querySelectorAll("td");
                        if (ci < tds.length) {
                            const currentCell = normalizeCell(rows[ri]?.[ci] ?? "");
                            // Ricostruisci updateCell locale (stesso pattern di buildCellUI)
                            const reopenUpdateCell = (patch) => {
                                const newRows = rows.map((r, ix) => {
                                    if (ix !== ri) return r;
                                    const copy = [...r];
                                    const existing = normalizeCell(copy[ci]);
                                    const merged = { ...existing, ...patch };
                                    let compact;
                                    if (merged.widget === null && merged.colspan === 1
                                        && merged.rowspan === 1 && !merged.merged
                                        && !merged.bg && !merged.align && !merged.valign) {
                                        compact = merged.text || "";
                                    } else {
                                        compact = { text: merged.text, widget: merged.widget,
                                            colspan: merged.colspan, rowspan: merged.rowspan,
                                            merged: merged.merged };
                                        if (merged.bg) compact.bg = merged.bg;
                                        if (merged.align) compact.align = merged.align;
                                        if (merged.valign) compact.valign = merged.valign;
                                    }
                                    copy[ci] = compact;
                                    return copy;
                                });
                                dispatch({ rows: newRows });
                            };
                            openCellConfigPopover(tds[ci], currentCell, {
                                ri, ci, row: rows[ri] || [], rows, columns,
                                dispatch,
                                updateCell: reopenUpdateCell,
                                dispatchRows: (newRows) => dispatch({ rows: newRows }),
                                compactCell: (c) => c,
                                getCell: () => normalizeCell(rows[ri]?.[ci] ?? ""),
                            });
                        }
                    }
                }
            };

            const renderFromNode = (n) => render(
                Array.isArray(n.attrs.columns) ? n.attrs.columns : [],
                Array.isArray(n.attrs.rows)    ? n.attrs.rows    : [],
                typeof n.attrs.caption === "string" ? n.attrs.caption : "",
                typeof n.attrs.headerNote === "string" ? n.attrs.headerNote : "",
                typeof n.attrs.footerNote === "string" ? n.attrs.footerNote : "",
                n.attrs.widthMode === "full" ? "full" : "auto",
                Array.isArray(n.attrs.colWidths) ? n.attrs.colWidths : [],
            );
            renderFromNode(node);

            // Ri-risoluzione delle CELLE DINAMICHE (select/checkbox con
            // options_source folder-mode) al cambio dei selettori sidebar: il
            // render fetcha con window.FM.pt.currentState, ma senza questo non si
            // ri-fetchava MAI → le select tabella restavano stantie/vuote quando
            // il docente cambiava indirizzo/classe/materia (a differenza dei gruppi
            // checkbox, ri-risolti dalla sezione). Iscrizione solo se la tabella ha
            // celle folder-mode (file-mode = statico, non dipende dallo stato).
            const hasDynamicCells = (n) => {
                const rows = Array.isArray(n?.attrs?.rows) ? n.attrs.rows : [];
                return rows.some((row) => Array.isArray(row) && row.some((c) =>
                    c && typeof c === "object" && c.options_source && c.options_source.folder));
            };
            let _unsubState = null;
            if (window.FM?.pt?.onStateChange && hasDynamicCells(node)) {
                _unsubState = window.FM.pt.onStateChange(() => {
                    const n = { attrs: curAttrs() };
                    if (hasDynamicCells(n)) renderFromNode(n);
                });
            }

            return {
                dom: container,
                ignoreMutation: () => true,
                update: (updatedNode) => {
                    if (updatedNode.type.name !== "ptTable") return false;
                    renderFromNode(updatedNode);
                    return true;
                },
                destroy: () => { if (_unsubState) { try { _unsubState(); } catch (_) {} _unsubState = null; } },
            };
        };
    },

    addCommands() {
        return {
            insertPtTable:
                (columns, rows, caption, widthMode, colWidths) =>
                ({ commands }) =>
                    commands.insertContent({
                        type: this.name,
                        attrs: {
                            columns: Array.isArray(columns) ? columns : [],
                            rows:    Array.isArray(rows)    ? rows    : [],
                            caption: typeof caption === "string" ? caption : "",
                            widthMode: widthMode === "full" ? "full" : "auto",
                            colWidths: Array.isArray(colWidths) ? colWidths : [],
                        },
                    }),
        };
    },
});

/** Phase 24.2 — Block atom: select. NodeView con native <select> interattivo. */
export const PtSelect = Node.create({
    name: "ptSelect",
    group: "block",
    atom: true,
    selectable: true,
    draggable: true,

    addAttributes() {
        return {
            name: { default: "" },
            label: { default: "" },
            value: { default: "" },
            options: {
                default: [],
                parseHTML: (el) => {
                    try { return JSON.parse(el.getAttribute("data-options") || "[]"); }
                    catch { return []; }
                },
                renderHTML: (attrs) => ({ "data-options": JSON.stringify(attrs.options || []) }),
            },
            options_source: { default: null }, // Phase 24.13: {file}|{folder}|null
        };
    },

    parseHTML() { return [{ tag: 'div[data-pt-type="ptSelect"]' }]; },

    renderHTML({ node, HTMLAttributes }) {
        return ["div", mergeAttributes(HTMLAttributes, {
            "data-pt-type": "ptSelect",
            "class": "pt-select-container",
        }), `${node.attrs.label ?? ""}: ${node.attrs.value ?? ""}`];
    },

    addNodeView() {
        return ({ node, editor, getPos }) => {
            const dom = document.createElement("div");
            dom.className = "pt-select-container pt-editable";
            dom.setAttribute("data-pt-type", "ptSelect");

            const dispatch = (newAttrs) => {
                const pos = typeof getPos === "function" ? getPos() : null;
                if (pos == null) return;
                const tr = editor.view.state.tr.setNodeMarkup(pos, null, {
                    ...node.attrs, ...newAttrs,
                });
                editor.view.dispatch(tr);
            };

            // Phase 24.18 — cache fetched options per options_source
            // (key = JSON.stringify(options_source)). Evita re-fetch su
            // ogni render + permette al select di mostrare opzioni da JSON.
            let fetchedOpts = null;
            let fetchedKey = null;

            // Phase 24.17 — separato header (labelInput/select/editBtn)
            // dal popover. render() ricostruisce SOLO header, il popover
            // (se aperto) viene preservato + ri-renderizzato con nuovi attrs.
            const render = (attrs) => {
                const existingPop = dom.querySelector(".pt-select-popover");
                // Rimuovi tutti i children EXCEPT il popover
                [...dom.children].forEach((c) => {
                    if (!c.classList.contains("pt-select-popover")) c.remove();
                });
                attachBlockDeleteBtn(dom, editor, getPos);

                // Phase 24.18 — fetch runtime delle options se options_source
                // presente. Il parent state (indirizzo/classe/disciplina)
                // viene letto da window.FM.pt.currentState se disponibile.
                const srcKey = attrs.options_source
                    ? JSON.stringify(attrs.options_source) : null;
                if (srcKey && srcKey !== fetchedKey) {
                    fetchedKey = srcKey;
                    const state = window.FM?.pt?.currentState || {};
                    fetchSchemaOptions({ options_source: attrs.options_source }, state)
                        .then((opts) => {
                            if (fetchedKey !== srcKey) return; // stale
                            fetchedOpts = opts;
                            // Re-render con nuove options
                            render(attrs);
                        });
                } else if (!srcKey) {
                    fetchedOpts = null;
                    fetchedKey = null;
                }

                const labelInput = document.createElement("input");
                labelInput.type = "text";
                labelInput.className = "pt-inline-label-input";
                labelInput.placeholder = "Etichetta…";
                labelInput.value = attrs.label || "";
                bindAtomInputSafety(labelInput);
                labelInput.addEventListener("blur", () => {
                    if ((attrs.label ?? "") === labelInput.value) return;
                    dispatch({ label: labelInput.value });
                });

                const select = document.createElement("select");
                select.className = "pt-select";
                // Options priority: fetched (se source+caricato) > inline attrs
                const opts = (srcKey && Array.isArray(fetchedOpts) && fetchedOpts.length > 0)
                    ? fetchedOpts
                    : (Array.isArray(attrs.options) ? attrs.options : []);
                if (opts.length === 0 && srcKey) {
                    const opt = document.createElement("option");
                    opt.textContent = "(caricamento…)";
                    opt.disabled = true;
                    select.appendChild(opt);
                }
                // Phase 24.32 — group options by .group → <optgroup>
                fillSelectGrouped(select, opts, attrs.value);
                select.addEventListener("mousedown", (e) => e.stopPropagation());
                select.addEventListener("change", () => dispatch({ value: select.value }));

                const sep = document.createElement("span");
                sep.className = "pt-inline-sep";
                sep.textContent = ":";

                const editBtn = document.createElement("button");
                editBtn.type = "button";
                editBtn.className = `pt-select-edit-btn${  existingPop ? " active" : ""}`;
                editBtn.textContent = "⚙";
                editBtn.title = "Modifica le opzioni del menù";
                editBtn.addEventListener("mousedown", (e) => e.stopPropagation());
                editBtn.addEventListener("click", (e) => {
                    e.stopPropagation();
                    togglePopover(attrs);
                });

                // Insert header BEFORE existing popover (popover stays last child)
                if (existingPop) {
                    dom.insertBefore(labelInput, existingPop);
                    dom.insertBefore(sep, existingPop);
                    dom.insertBefore(select, existingPop);
                    dom.insertBefore(editBtn, existingPop);
                    // Ri-renderizza il contenuto del popover con nuovi attrs
                    renderPopoverContent(existingPop, attrs);
                } else {
                    dom.append(labelInput, sep, select, editBtn);
                }
            };

            /** Popola il popover con contenuto rigenerato da attrs. */
            const renderPopoverContent = (pop, attrs) => {
                pop.innerHTML = "";

                const title = document.createElement("div");
                title.className = "pt-select-popover-title";
                title.textContent = "Opzioni del menù";
                pop.appendChild(title);

                // Phase 24.19 — Help "etichetta" spiegazione
                const labelHelp = document.createElement("div");
                labelHelp.className = "pt-select-popover-help";
                labelHelp.style.marginBottom = "8px";
                labelHelp.innerHTML = '<strong>Etichetta</strong>: testo mostrato accanto al menù (es. "Indirizzo:", "Classe:"). Identifica il significato del valore selezionato sia a video sia in TeX.';
                pop.appendChild(labelHelp);

                // ── Section 1: Sorgente opzioni (inline / file JSON / folder)
                const srcWrap = document.createElement("div");
                srcWrap.className = "pt-select-popover-section";
                const srcLabel = document.createElement("div");
                srcLabel.className = "pt-select-popover-sublabel";
                srcLabel.textContent = "Sorgente opzioni";
                srcWrap.appendChild(srcLabel);

                const currentSrc = attrs.options_source;
                // Phase 24.20 — key-presence (non truthy) inference
                const srcMode = !currentSrc ? "inline"
                    : ("file" in currentSrc ? "file"
                        : ("folder" in currentSrc ? "folder" : "inline"));

                const modeRow = document.createElement("div");
                modeRow.className = "pt-select-popover-row";

                const mkRadio = (val, label, desc) => {
                    const btn = document.createElement("button");
                    btn.type = "button";
                    btn.className = `pt-select-src-btn${  srcMode === val ? " active" : ""}`;
                    btn.textContent = label;
                    btn.title = desc;
                    btn.addEventListener("mousedown", (e) => e.stopPropagation());
                    btn.addEventListener("click", (e) => {
                        e.stopPropagation();
                        if (val === "inline") dispatch({ options_source: null });
                        else if (val === "file") dispatch({ options_source: { file: currentSrc?.file || "competenze_DM2007/competenze_DM2007.json" } });
                        else if (val === "folder") dispatch({ options_source: { folder: currentSrc?.folder || "obiettivi_disciplinari_dipartimento/competenze" } });
                    });
                    return btn;
                };
                modeRow.append(
                    mkRadio("inline", "Inline", "Opzioni definite qui direttamente"),
                    mkRadio("file",   "📄 File JSON", "Popola da un file JSON specifico"),
                    mkRadio("folder", "📁 Folder (stato)", "Popola da file JSON che dipende da indirizzo/classe/disciplina selezionate nell'header"),
                );
                srcWrap.appendChild(modeRow);

                // Phase 24.19 — Dropdown con catalogo invece di free-text input.
                if (srcMode === "file" || srcMode === "folder") {
                    const selectPath = document.createElement("select");
                    selectPath.className = "pt-select-popover-input pt-select-src-path";
                    selectPath.addEventListener("mousedown", (e) => e.stopPropagation());
                    const loading = document.createElement("option");
                    loading.textContent = "(caricamento elenco…)";
                    loading.disabled = true;
                    selectPath.appendChild(loading);
                    getOptionsSourcesCatalog().then((cat) => {
                        selectPath.innerHTML = "";
                        const emptyOpt = document.createElement("option");
                        emptyOpt.value = "";
                        emptyOpt.textContent = srcMode === "file"
                            ? "— seleziona file JSON —"
                            : "— seleziona cartella —";
                        selectPath.appendChild(emptyOpt);
                        const list = srcMode === "file" ? cat.files : cat.folders;
                        for (const entry of (list || [])) {
                            const opt = document.createElement("option");
                            opt.value = entry.path;
                            opt.textContent = `${entry.label  }  [${  entry.path  }]`;
                            const current = srcMode === "file" ? currentSrc?.file : currentSrc?.folder;
                            if (entry.path === current) opt.selected = true;
                            selectPath.appendChild(opt);
                        }
                    });
                    selectPath.addEventListener("change", () => {
                        const v = selectPath.value.trim();
                        if (!v) return;
                        if (srcMode === "file")   dispatch({ options_source: { file: v } });
                        if (srcMode === "folder") dispatch({ options_source: { folder: v } });
                    });
                    const help = document.createElement("div");
                    help.className = "pt-select-popover-help";
                    help.textContent = srcMode === "file"
                        ? "Il file JSON viene letto direttamente (non dipende da classe/indirizzo)."
                        : "Folder: path base, il file effettivo è selezionato runtime in base a indirizzo / classe / disciplina.";
                    srcWrap.append(selectPath, help);
                }
                pop.appendChild(srcWrap);

                // ── ADR-030 — Valore 🔗 per classe / 📌 fisso ──
                {
                    const autoFolder = !!(currentSrc && currentSrc.folder);
                    // ADR-030 — per-classe di DEFAULT; 📌 (binding:"fixed") = condiviso.
                    const isLinked = autoFolder || attrs.binding !== "fixed";
                    const bWrap = document.createElement("div");
                    bWrap.className = "pt-select-popover-section";
                    const bLbl = document.createElement("div");
                    bLbl.className = "pt-select-popover-sublabel";
                    bLbl.textContent = "Valore";
                    bWrap.appendChild(bLbl);
                    const bRow = document.createElement("div");
                    bRow.className = "pt-select-popover-row";
                    const mkB = (val, label, title) => {
                        const b = document.createElement("button");
                        b.type = "button"; b.textContent = label; b.title = title;
                        const active = val === "terna" ? isLinked : !isLinked;
                        b.className = "pt-select-popover-mode-btn" + (active ? " active" : "");
                        if (autoFolder) b.disabled = true;
                        b.addEventListener("mousedown", (e) => e.stopPropagation());
                        b.addEventListener("click", (e) => { e.stopPropagation(); dispatch({ binding: val === "fixed" ? "fixed" : null }); });
                        return b;
                    };
                    bRow.append(
                        mkB("terna", "🔗 Per classe", "Il valore cambia per indirizzo/classe/materia (default)"),
                        mkB("fixed", "📌 Fisso", "Il valore è lo stesso (condiviso) per tutte le classi"),
                    );
                    const bHelp = document.createElement("div");
                    bHelp.className = "pt-select-popover-help";
                    bHelp.innerHTML = autoFolder
                        ? 'Prende le opzioni da una cartella → <strong>sempre 🔗 per classe</strong>.'
                        : 'Col documento in "Valori per classe" il valore è <strong>🔗 per classe</strong> di default; 📌 per condividerlo con tutte.';
                    bWrap.append(bRow, bHelp);
                    pop.appendChild(bWrap);
                }

                // ── Section 2: Lista options inline (sempre editabile, ma disabled
                //     visivamente se c'è options_source perché runtime-overridden).
                const listWrap = document.createElement("div");
                listWrap.className = "pt-select-popover-section";
                const listLabel = document.createElement("div");
                listLabel.className = "pt-select-popover-sublabel";
                listLabel.textContent = srcMode === "inline"
                    ? "Lista opzioni"
                    : "Lista fallback (usata se file non disponibile)";
                listWrap.appendChild(listLabel);

                const list = document.createElement("div");
                list.className = "pt-select-popover-list";

                const renderList = () => {
                    list.innerHTML = "";
                    const current = Array.isArray(attrs.options) ? [...attrs.options] : [];
                    if (current.length === 0) {
                        const empty = document.createElement("div");
                        empty.className = "pt-select-popover-empty";
                        empty.textContent = "(nessuna opzione — aggiungi con + sotto)";
                        list.appendChild(empty);
                    }
                    current.forEach((o, i) => {
                        const row = document.createElement("div");
                        row.className = "pt-select-popover-row";

                        const valIn = document.createElement("input");
                        valIn.type = "text";
                        valIn.value = o?.value ?? "";
                        valIn.placeholder = "valore";
                        valIn.className = "pt-select-popover-input";
                        bindAtomInputSafety(valIn);
                        valIn.addEventListener("blur", () => {
                            const next = [...current];
                            next[i] = { ...next[i], value: valIn.value };
                            dispatch({ options: next });
                        });

                        const labIn = document.createElement("input");
                        labIn.type = "text";
                        labIn.value = o?.label ?? "";
                        labIn.placeholder = "etichetta (mostrata)";
                        labIn.className = "pt-select-popover-input";
                        bindAtomInputSafety(labIn);
                        labIn.addEventListener("blur", () => {
                            const next = [...current];
                            next[i] = { ...next[i], label: labIn.value };
                            dispatch({ options: next });
                        });

                        const rm = document.createElement("button");
                        rm.type = "button";
                        rm.className = "pt-select-popover-rm";
                        rm.textContent = "×";
                        rm.title = "Rimuovi opzione";
                        rm.addEventListener("mousedown", (e) => e.stopPropagation());
                        rm.addEventListener("click", (e) => {
                            e.stopPropagation();
                            const next = current.filter((_, j) => j !== i);
                            dispatch({ options: next });
                        });

                        row.append(valIn, labIn, rm);
                        list.appendChild(row);
                    });
                };
                renderList();
                listWrap.appendChild(list);

                const addBtn = document.createElement("button");
                addBtn.type = "button";
                addBtn.className = "pt-select-popover-add";
                addBtn.textContent = "+ aggiungi opzione";
                addBtn.addEventListener("mousedown", (e) => e.stopPropagation());
                addBtn.addEventListener("click", (e) => {
                    e.stopPropagation();
                    const current = Array.isArray(attrs.options) ? [...attrs.options] : [];
                    current.push({ value: "", label: "" });
                    dispatch({ options: current });
                });
                listWrap.appendChild(addBtn);
                pop.appendChild(listWrap);

                const closeBtn = document.createElement("button");
                closeBtn.type = "button";
                closeBtn.className = "pt-select-popover-close";
                closeBtn.textContent = "Chiudi";
                closeBtn.addEventListener("mousedown", (e) => e.stopPropagation());
                closeBtn.addEventListener("click", (e) => {
                    e.stopPropagation();
                    pop.remove();
                    // Ri-render header per aggiornare editBtn .active state
                    const pos = typeof getPos === "function" ? getPos() : null;
                    if (pos != null) {
                        const fresh = editor.view.state.doc.nodeAt(pos);
                        if (fresh) render(fresh.attrs);
                    }
                });
                pop.appendChild(closeBtn);
            };

            /** Toggle popover: crea se assente, chiude se presente. */
            const togglePopover = (attrs) => {
                const existing = dom.querySelector(".pt-select-popover");
                if (existing) { existing.remove(); render(attrs); return; }
                const pop = document.createElement("div");
                pop.className = "pt-select-popover";
                // Listeners una volta sola (persistono tra re-render content)
                pop.addEventListener("mousedown", (e) => e.stopPropagation());
                pop.addEventListener("click", (e) => e.stopPropagation());
                renderPopoverContent(pop, attrs);
                dom.appendChild(pop);
                render(attrs);
            };

            render(node.attrs);

            return {
                dom,
                ignoreMutation: () => true,
                update: (updatedNode) => {
                    if (updatedNode.type.name !== "ptSelect") return false;
                    render(updatedNode.attrs);
                    return true;
                },
            };
        };
    },

    addCommands() {
        return {
            insertPtSelect:
                (label, value, options, name, optionsSource) =>
                ({ commands }) =>
                    commands.insertContent({
                        type: this.name,
                        attrs: {
                            name: name || "",
                            label: label || "",
                            value: value || "",
                            options: Array.isArray(options) ? options : [],
                            options_source: optionsSource || null,
                        },
                    }),
        };
    },
});

/** Phase 24.3 — Block atom: textField. NodeView con native <input>. */
export const PtTextField = Node.create({
    name: "ptTextField",
    group: "block",
    atom: true,
    selectable: true,
    draggable: true,

    addAttributes() {
        return {
            name: { default: "" },
            label: { default: "" },
            value: { default: "" },
            kind: { default: "text" },
            placeholder: { default: "" },
        };
    },

    parseHTML() { return [{ tag: 'div[data-pt-type="ptTextField"]' }]; },

    renderHTML({ node, HTMLAttributes }) {
        return ["div", mergeAttributes(HTMLAttributes, {
            "data-pt-type": "ptTextField",
            "class": "pt-textfield-container",
        }), `${node.attrs.label ?? ""}: ${node.attrs.value ?? ""}`];
    },

    addNodeView() {
        return ({ node, editor, getPos }) => {
            const dom = document.createElement("div");
            dom.className = "pt-textfield-container pt-editable";
            dom.setAttribute("data-pt-type", "ptTextField");

            const dispatch = (newAttrs) => {
                const pos = typeof getPos === "function" ? getPos() : null;
                if (pos == null) return;
                const tr = editor.view.state.tr.setNodeMarkup(pos, null, { ...node.attrs, ...newAttrs });
                editor.view.dispatch(tr);
            };

            const render = (attrs) => {
                dom.innerHTML = "";
                attachBlockDeleteBtn(dom, editor, getPos);
                const labelInput = document.createElement("input");
                labelInput.type = "text";
                labelInput.className = "pt-inline-label-input";
                labelInput.placeholder = "Etichetta…";
                labelInput.title = 'Etichetta: testo mostrato prima del valore (es. "Cognome:", "Classe:"). Identifica il significato del campo sia a video sia in TeX.';
                labelInput.value = attrs.label || "";
                bindAtomInputSafety(labelInput);
                labelInput.addEventListener("blur", () => {
                    if ((attrs.label ?? "") === labelInput.value) return;
                    dispatch({ label: labelInput.value });
                });

                const valInput = document.createElement("input");
                valInput.type = ["text","number","date"].includes(attrs.kind) ? attrs.kind : "text";
                valInput.className = "pt-text-field";
                valInput.placeholder = attrs.placeholder || "";
                valInput.value = attrs.value || "";
                bindAtomInputSafety(valInput);
                valInput.addEventListener("blur", () => {
                    if ((attrs.value ?? "") === valInput.value) return;
                    dispatch({ value: valInput.value });
                });

                const sep = document.createElement("span");
                sep.className = "pt-inline-sep";
                sep.textContent = ":";

                dom.append(labelInput, sep, valInput, buildBindingBtn(attrs, dispatch));
            };

            render(node.attrs);

            return {
                dom,
                ignoreMutation: () => true,
                update: (updatedNode) => {
                    if (updatedNode.type.name !== "ptTextField") return false;
                    render(updatedNode.attrs);
                    return true;
                },
            };
        };
    },

    addCommands() {
        return {
            insertPtTextField:
                (label, value, kind, name, placeholder) =>
                ({ commands }) =>
                    commands.insertContent({
                        type: this.name,
                        attrs: {
                            name: name || "",
                            label: label || "",
                            value: value || "",
                            kind: kind || "text",
                            placeholder: placeholder || "",
                        },
                    }),
        };
    },
});

/** Phase 24.4 — Block atom: formCheckbox (single on/off). */
export const PtFormCheckbox = Node.create({
    name: "ptFormCheckbox",
    group: "block",
    atom: true,
    selectable: true,
    draggable: true,

    addAttributes() {
        return {
            name: { default: "" },
            label: { default: "" },
            checked: { default: false },
        };
    },

    parseHTML() { return [{ tag: 'label[data-pt-type="ptFormCheckbox"]' }]; },

    renderHTML({ node, HTMLAttributes }) {
        return ["label", mergeAttributes(HTMLAttributes, {
            "data-pt-type": "ptFormCheckbox",
            "class": "pt-form-checkbox",
        }), node.attrs.label ?? ""];
    },

    addNodeView() {
        return ({ node, editor, getPos }) => {
            const dom = document.createElement("div");
            dom.className = "pt-form-checkbox-container pt-editable";
            dom.setAttribute("data-pt-type", "ptFormCheckbox");

            const dispatch = (newAttrs) => {
                const pos = typeof getPos === "function" ? getPos() : null;
                if (pos == null) return;
                const tr = editor.view.state.tr.setNodeMarkup(pos, null, { ...node.attrs, ...newAttrs });
                editor.view.dispatch(tr);
            };

            const render = (attrs) => {
                dom.innerHTML = "";
                attachBlockDeleteBtn(dom, editor, getPos);
                const cb = document.createElement("input");
                cb.type = "checkbox";
                cb.checked = !!attrs.checked;
                cb.addEventListener("mousedown", (e) => e.stopPropagation());
                cb.addEventListener("change", () => dispatch({ checked: cb.checked }));

                const labelInput = document.createElement("input");
                labelInput.type = "text";
                labelInput.className = "pt-inline-label-input";
                labelInput.value = attrs.label || "";
                labelInput.placeholder = "Etichetta…";
                bindAtomInputSafety(labelInput);
                labelInput.addEventListener("blur", () => {
                    if ((attrs.label ?? "") === labelInput.value) return;
                    dispatch({ label: labelInput.value });
                });

                dom.append(cb, labelInput, buildBindingBtn(attrs, dispatch));
            };

            render(node.attrs);

            return {
                dom,
                ignoreMutation: () => true,
                update: (updatedNode) => {
                    if (updatedNode.type.name !== "ptFormCheckbox") return false;
                    render(updatedNode.attrs);
                    return true;
                },
            };
        };
    },

    addCommands() {
        return {
            insertPtFormCheckbox:
                (label, checked, name) =>
                ({ commands }) =>
                    commands.insertContent({
                        type: this.name,
                        attrs: {
                            name: name || "",
                            label: label || "",
                            checked: !!checked,
                        },
                    }),
        };
    },
});

/** Phase 24.5 — Block atom: sectionHeader. NodeView h1-h4 + selectors chip. */
/** Spunta/despunta TUTTE le checkbox dei componenti (checkboxGroup, ptFormCheckbox)
 *  nell'intervallo di una sezione: dall'header (pos) fino al prossimo sectionHeader
 *  con level <= a quello dato. Toggle: se sono già tutte spuntate → despunta. */
function toggleAllCheckboxesInSection(editor, getPos, level) {
    const pos = typeof getPos === "function" ? getPos() : null;
    if (pos == null) return;
    const doc = editor.state.doc;
    const myNode = doc.nodeAt(pos);
    const myLevel = (myNode && myNode.attrs && myNode.attrs.level) || level || 2;
    const start = pos + (myNode ? myNode.nodeSize : 1);
    let end = doc.content.size;
    let found = false;
    doc.nodesBetween(start, doc.content.size, (n, p) => {
        if (found) return false;
        if (n.type.name === "ptSectionHeader" && ((n.attrs && n.attrs.level) || 2) <= myLevel) {
            end = p; found = true; return false;
        }
        return true;
    });
    if (end <= start) return;
    // Scan: tutte già spuntate?
    let total = 0, checked = 0;
    doc.nodesBetween(start, end, (n) => {
        if (n.type.name === "checkboxGroup") {
            (n.attrs.items || []).forEach((it) => { total++; if (it && it.state === "x") checked++; });
        } else if (n.type.name === "ptFormCheckbox") {
            total++; if (n.attrs.checked) checked++;
        }
        return true;
    });
    if (total === 0) return;
    const setChecked = checked < total; // non tutte spuntate → spunta tutte; altrimenti despunta
    const tr = editor.state.tr;
    doc.nodesBetween(start, end, (n, p) => {
        if (n.type.name === "checkboxGroup") {
            const items = (n.attrs.items || []).map((it) => ({ ...it, state: setChecked ? "x" : "_" }));
            tr.setNodeMarkup(p, null, { ...n.attrs, items });
        } else if (n.type.name === "ptFormCheckbox") {
            tr.setNodeMarkup(p, null, { ...n.attrs, checked: setChecked });
        }
        return true;
    });
    if (tr.docChanged) editor.view.dispatch(tr);
}

export const PtSectionHeader = Node.create({
    name: "ptSectionHeader",
    group: "block",
    atom: true,
    selectable: true,
    draggable: true,

    addAttributes() {
        return {
            title: { default: "" },
            level: { default: 2 },
            selectors: { default: [] },
            // Phase 24.32 — boxed: se true, render TeX wrappa contenuto
            // successivo (fino al prossimo header same/shallower level) in
            // \begin{sectionbox}{title}...\end{sectionbox}.
            boxed: {
                default: false,
                parseHTML: (el) => el.getAttribute("data-boxed") === "1",
                renderHTML: (attrs) => ({ "data-boxed": attrs.boxed ? "1" : "0" }),
            },
            // Esclusa dall'output: questa sezione (header + contenuto fino al
            // prossimo header di livello <=) NON compare in Anteprima/TeX, ma
            // resta salvata (riattivabile). Toggle dal checkbox 👁 in editor.
            excluded: {
                default: false,
                parseHTML: (el) => el.getAttribute("data-excluded") === "1",
                renderHTML: (attrs) => ({ "data-excluded": attrs.excluded ? "1" : "0" }),
            },
        };
    },

    parseHTML() { return [{ tag: '[data-pt-type="ptSectionHeader"]' }]; },

    renderHTML({ node, HTMLAttributes }) {
        const level = Math.max(1, Math.min(4, Number.isInteger(node.attrs.level) ? node.attrs.level : 2));
        return [`h${level}`, mergeAttributes(HTMLAttributes, {
            "data-pt-type": "ptSectionHeader",
            "class": "pt-section-header",
        }), node.attrs.title ?? ""];
    },

    addNodeView() {
        return ({ node, editor, getPos }) => {
            const dom = document.createElement("div");
            dom.className = "pt-section-header-container pt-editable";
            dom.setAttribute("data-pt-type", "ptSectionHeader");

            const dispatch = (newAttrs) => {
                const pos = typeof getPos === "function" ? getPos() : null;
                if (pos == null) return;
                const tr = editor.view.state.tr.setNodeMarkup(pos, null, { ...node.attrs, ...newAttrs });
                editor.view.dispatch(tr);
            };

            const render = (attrs) => {
                dom.innerHTML = "";
                attachBlockDeleteBtn(dom, editor, getPos);
                const level = Math.max(1, Math.min(4, attrs.level || 2));
                const hRow = document.createElement("div");
                hRow.className = `pt-section-header level-${level}`;

                const titleInput = document.createElement("input");
                titleInput.type = "text";
                titleInput.className = "pt-section-title-input";
                titleInput.value = attrs.title || "";
                titleInput.placeholder = "Titolo sezione…";
                bindAtomInputSafety(titleInput);
                titleInput.addEventListener("blur", () => {
                    if ((attrs.title ?? "") === titleInput.value) return;
                    dispatch({ title: titleInput.value });
                });

                const levelSel = document.createElement("select");
                levelSel.className = "pt-section-level-sel";
                levelSel.title = "Livello heading";
                [1, 2, 3, 4].forEach((l) => {
                    const opt = document.createElement("option");
                    opt.value = String(l); opt.textContent = `H${l}`;
                    if (l === level) opt.selected = true;
                    levelSel.appendChild(opt);
                });
                levelSel.addEventListener("mousedown", (e) => e.stopPropagation());
                levelSel.addEventListener("change", () => dispatch({ level: parseInt(levelSel.value, 10) }));

                // Phase 24.32 — boxed toggle (sectionbox wrap)
                const boxedLabel = document.createElement("label");
                boxedLabel.className = "pt-section-boxed-toggle";
                boxedLabel.title = "Avvolge il contenuto successivo (fino al prossimo header dello stesso livello) in \\begin{sectionbox}{titolo}...\\end{sectionbox}";
                const boxedCb = document.createElement("input");
                boxedCb.type = "checkbox";
                boxedCb.checked = !!attrs.boxed;
                boxedCb.addEventListener("mousedown", (e) => e.stopPropagation());
                boxedCb.addEventListener("change", () => dispatch({ boxed: boxedCb.checked }));
                const boxedTxt = document.createElement("span");
                boxedTxt.textContent = "📦 Box";
                boxedLabel.append(boxedCb, boxedTxt);

                // Checkbox 👁 "in output": spuntata = la sezione (header + contenuto
                // fino al prossimo header di livello <=) compare in Anteprima/TeX.
                // Togliendo la spunta la sezione viene ESCLUSA (ma resta salvata).
                const outLabel = document.createElement("label");
                outLabel.className = "pt-section-output-toggle" + (attrs.excluded ? " is-excluded" : "");
                outLabel.title = "Spuntata = questa sezione compare in Anteprima e TeX. Togli la spunta per ESCLUDERLA (resta salvata, riattivabile).";
                const outCb = document.createElement("input");
                outCb.type = "checkbox";
                outCb.checked = !attrs.excluded;
                outCb.addEventListener("mousedown", (e) => e.stopPropagation());
                outCb.addEventListener("change", () => dispatch({ excluded: !outCb.checked }));
                const outTxt = document.createElement("span");
                outTxt.textContent = "👁 In output";
                outLabel.append(outCb, outTxt);

                // Bottone "☑ tutte": spunta/despunta TUTTE le checkbox dei
                // componenti (Gruppo di checkbox, Sì/No) dentro questa sezione
                // (dall'header fino al prossimo header di livello <=).
                const checkAllBtn = document.createElement("button");
                checkAllBtn.type = "button";
                checkAllBtn.className = "pt-section-checkall-btn";
                checkAllBtn.textContent = "☑ tutte";
                checkAllBtn.title = "Spunta (o despunta, se già tutte spuntate) tutte le checkbox dei componenti in questa sezione";
                checkAllBtn.addEventListener("mousedown", (e) => e.stopPropagation());
                checkAllBtn.addEventListener("click", (e) => {
                    e.stopPropagation();
                    toggleAllCheckboxesInSection(editor, getPos, attrs.level || 2);
                });
                hRow.append(titleInput, levelSel, boxedLabel, outLabel, checkAllBtn);
                if (attrs.excluded) dom.classList.add("pt-section-header-container--excluded");
                else dom.classList.remove("pt-section-header-container--excluded");
                dom.appendChild(hRow);

                const sel = Array.isArray(attrs.selectors) ? attrs.selectors : [];
                if (sel.length) {
                    const selRow = document.createElement("div");
                    selRow.className = "fm-pt-section-selectors";
                    sel.forEach((name) => {
                        const chip = document.createElement("span");
                        chip.className = "pt-field-ref";
                        chip.textContent = `[${name}]`;
                        selRow.appendChild(chip);
                    });
                    dom.appendChild(selRow);
                }
            };

            render(node.attrs);

            return {
                dom,
                ignoreMutation: () => true,
                update: (updatedNode) => {
                    if (updatedNode.type.name !== "ptSectionHeader") return false;
                    render(updatedNode.attrs);
                    return true;
                },
            };
        };
    },

    addCommands() {
        return {
            insertPtSectionHeader:
                (title, level, selectors) =>
                ({ commands, editor }) => {
                    // Posizione d'inserimento (atom block) prima dell'insert.
                    const insertPos = editor.state.selection.from;
                    const ok = commands.insertContent({
                        type: this.name,
                        attrs: {
                            title: title || "",
                            level: Number.isInteger(level) ? level : 2,
                            selectors: Array.isArray(selectors) ? selectors : [],
                        },
                    });
                    // Phase 25 (rework editor custom) — porta il focus dentro
                    // l'<input> titolo della sezione appena inserita: il section
                    // header è un nodo atom con NodeView, l'insert NON ci sposta
                    // dentro da solo (era il bug "il cursore non resta nell'input").
                    requestAnimationFrame(() => {
                        let dom = null;
                        try { dom = editor.view.nodeDOM(insertPos); } catch (_) { /* pos non risolvibile */ }
                        let input = dom?.querySelector?.(".pt-section-title-input");
                        if (!input) {
                            // Fallback: l'ultima sezione (quella nuova, appena appesa).
                            const all = editor.view.dom.querySelectorAll(".pt-section-title-input");
                            input = all[all.length - 1] || null;
                        }
                        if (input) { input.focus(); input.select?.(); }
                    });
                    return ok;
                },
        };
    },
});

/**
 * G23 page-doc — Block atom: tabella glossario lemmi/definizioni con
 * colonne fisse, entries inline editabili. NodeView replica UX PtTable
 * (toolbar add/remove + cell editors) ma struttura entries object-shaped
 * (n/lemma/definizione/fonte) anziché matrix string.
 *
 * PT AST shape:
 *   { _type: "glossaryTable", columns: [...], entries: [{n,lemma,...}], sortable, searchable }
 *
 * Render runtime aggiunge sort headers + search input (gestiti server-side
 * con script vanilla ~1KB, no jQuery).
 */
export const PtGlossaryTable = Node.create({
    name: "ptGlossaryTable",
    group: "block",
    atom: true,
    selectable: true,
    draggable: true,

    addAttributes() {
        return {
            name: { default: "" },
            columns: {
                default: ["N.", "Lemma", "Definizione", "Fonte"],
                parseHTML: (el) => {
                    try {
                        const raw = el.getAttribute("data-columns") || "[]";
                        const p = JSON.parse(raw);
                        return Array.isArray(p) && p.length >= 2 ? p : ["N.", "Lemma", "Definizione", "Fonte"];
                    } catch { return ["N.", "Lemma", "Definizione", "Fonte"]; }
                },
                renderHTML: (attrs) => ({ "data-columns": JSON.stringify(attrs.columns || []) }),
            },
            entries: {
                default: [],
                parseHTML: (el) => {
                    try {
                        const raw = el.getAttribute("data-entries") || "[]";
                        const p = JSON.parse(raw);
                        return Array.isArray(p) ? p : [];
                    } catch { return []; }
                },
                renderHTML: (attrs) => ({ "data-entries": JSON.stringify(attrs.entries || []) }),
            },
            sortable:   { default: true },
            searchable: { default: true },
        };
    },

    parseHTML() { return [{ tag: 'div[data-pt-type="ptGlossaryTable"]' }]; },

    renderHTML({ node, HTMLAttributes }) {
        const cols = Array.isArray(node.attrs.columns) ? node.attrs.columns : [];
        const entries = Array.isArray(node.attrs.entries) ? node.attrs.entries : [];
        const thead = `<thead><tr>${cols.map((c) => `<th>${escapeHtml(String(c))}</th>`).join("")}</tr></thead>`;
        const tbody = entries.map((e) => {
            const cells = cols.map((c) => {
                const key = headerToKey(c);
                return `<td>${escapeHtml(String(e?.[key] ?? ""))}</td>`;
            }).join("");
            return `<tr>${cells}</tr>`;
        }).join("");
        return [
            "div",
            mergeAttributes(HTMLAttributes, {
                "data-pt-type": "ptGlossaryTable",
                "class": "pt-glossary-table",
                "innerHTML": `<table>${thead}<tbody>${tbody}</tbody></table>`,
            }),
        ];
    },

    addNodeView() {
        return ({ node, editor, getPos }) => {
            const container = document.createElement("div");
            container.className = "pt-glossary-table pt-editable";
            container.setAttribute("data-pt-type", "ptGlossaryTable");

            const dispatch = (newAttrs) => {
                const pos = typeof getPos === "function" ? getPos() : null;
                if (pos == null) return;
                const tr = editor.view.state.tr.setNodeMarkup(pos, null, { ...node.attrs, ...newAttrs });
                editor.view.dispatch(tr);
            };

            const mkBtn = (label, title, handler, cls = "pt-glossary-btn") => {
                const b = document.createElement("button");
                b.type = "button";
                b.className = cls;
                b.textContent = label;
                b.title = title;
                b.addEventListener("click", (e) => { e.stopPropagation(); handler(); });
                b.addEventListener("mousedown", (e) => e.stopPropagation());
                return b;
            };

            const render = (cols, entries) => {
                container.innerHTML = "";
                attachBlockDeleteBtn(container, editor, getPos);
                container.setAttribute("data-columns", JSON.stringify(cols));
                container.setAttribute("data-entries", JSON.stringify(entries));

                const toolbar = document.createElement("div");
                toolbar.className = "pt-glossary-toolbar";
                toolbar.append(
                    mkBtn("+ riga", "Aggiungi voce glossario", () => {
                        const newEntry = {};
                        cols.forEach((c) => { newEntry[headerToKey(c)] = ""; });
                        if (cols.some((c) => headerToKey(c) === "n")) {
                            newEntry.n = entries.length + 1;
                        }
                        dispatch({ entries: [...entries, newEntry] });
                    }),
                    mkBtn("− riga", "Rimuovi ultima voce", () => {
                        if (entries.length === 0) return;
                        dispatch({ entries: entries.slice(0, -1) });
                    }),
                );
                container.appendChild(toolbar);

                const table = document.createElement("table");
                table.className = "pt-glossary-table-edit";

                // Header
                const thead = document.createElement("thead");
                const hrow = document.createElement("tr");
                cols.forEach((col, ci) => {
                    const th = document.createElement("th");
                    const input = document.createElement("input");
                    input.type = "text";
                    input.value = col;
                    input.className = "pt-glossary-cell-input pt-glossary-cell-header";
                    input.placeholder = `Col ${ci + 1}`;
                    bindAtomInputSafety(input);
                    input.addEventListener("blur", () => {
                        if (cols[ci] === input.value) return;
                        const next = [...cols];
                        next[ci] = input.value;
                        // Re-map entries keys
                        const oldKey = headerToKey(cols[ci]);
                        const newKey = headerToKey(input.value);
                        const newEntries = entries.map((e) => {
                            if (oldKey === newKey) return e;
                            const r = { ...e };
                            r[newKey] = r[oldKey];
                            delete r[oldKey];
                            return r;
                        });
                        dispatch({ columns: next, entries: newEntries });
                    });
                    th.appendChild(input);
                    hrow.appendChild(th);
                });
                thead.appendChild(hrow);
                table.appendChild(thead);

                // Body
                const tbody = document.createElement("tbody");
                entries.forEach((entry, ri) => {
                    const tr = document.createElement("tr");
                    cols.forEach((col) => {
                        const td = document.createElement("td");
                        const key = headerToKey(col);
                        const isNumeric = key === "n";
                        const input = isNumeric
                            ? document.createElement("input")
                            : document.createElement("textarea");
                        if (isNumeric) {
                            input.type = "number";
                            input.min = "1";
                            input.value = entry?.[key] ?? (ri + 1);
                        } else {
                            input.rows = 1;
                            input.value = entry?.[key] ?? "";
                            input.style.resize = "none";
                            input.style.overflow = "hidden";
                            input.style.fieldSizing = "content";
                        }
                        input.className = `pt-glossary-cell-input pt-glossary-cell-${key}`;
                        input.placeholder = String(col);
                        bindAtomInputSafety(input);
                        input.addEventListener("blur", () => {
                            const newVal = isNumeric
                                ? (parseInt(input.value, 10) || (ri + 1))
                                : input.value;
                            if ((entries[ri]?.[key] ?? "") === newVal) return;
                            const next = entries.map((e, j) =>
                                j === ri ? { ...e, [key]: newVal } : e,
                            );
                            dispatch({ entries: next });
                        });
                        td.appendChild(input);
                        tr.appendChild(td);
                    });
                    tbody.appendChild(tr);
                });
                table.appendChild(tbody);
                container.appendChild(table);

                const meta = document.createElement("div");
                meta.className = "pt-glossary-meta";
                meta.textContent = `${entries.length} voci · ${cols.length} colonne · sortable+searchable a runtime`;
                container.appendChild(meta);
            };

            render(
                Array.isArray(node.attrs.columns) ? node.attrs.columns : ["N.", "Lemma", "Definizione", "Fonte"],
                Array.isArray(node.attrs.entries) ? node.attrs.entries : [],
            );

            return {
                dom: container,
                ignoreMutation: () => true,
                update: (updatedNode) => {
                    if (updatedNode.type.name !== "ptGlossaryTable") return false;
                    render(
                        Array.isArray(updatedNode.attrs.columns) ? updatedNode.attrs.columns : [],
                        Array.isArray(updatedNode.attrs.entries) ? updatedNode.attrs.entries : [],
                    );
                    return true;
                },
            };
        };
    },

    addCommands() {
        return {
            insertPtGlossaryTable:
                (columns, entries, name) =>
                ({ commands }) =>
                    commands.insertContent({
                        type: this.name,
                        attrs: {
                            name: name || "",
                            columns: Array.isArray(columns) && columns.length >= 2
                                ? columns
                                : ["N.", "Lemma", "Definizione", "Fonte"],
                            entries: Array.isArray(entries) ? entries : [],
                            sortable: true,
                            searchable: true,
                        },
                    }),
        };
    },
});

/**
 * G23 page-doc — converte un'intestazione colonna in chiave entry.
 * "N." → "n", "Lemma" → "lemma", "Definizione" → "definizione", "Fonte" → "fonte".
 * Generico: lowercase, rimuove punti, accentate ASCII-fy approssimato, spaces → _.
 */
function headerToKey(header) {
    return String(header || "")
        .toLowerCase()
        .replace(/\./g, "")
        .replace(/[àáâä]/g, "a")
        .replace(/[èéêë]/g, "e")
        .replace(/[ìíîï]/g, "i")
        .replace(/[òóôö]/g, "o")
        .replace(/[ùúûü]/g, "u")
        .replace(/\s+/g, "_")
        .replace(/[^a-z0-9_]/g, "")
        .replace(/^_+|_+$/g, "");
}

/**
 * G23 page-doc — Block atom: staticContent HTML sanitizzato.
 * Body sanitizzato client-side via window.FM.PtSanitizer (defense-in-depth);
 * server-side authoritative via HtmlSanitizer::forPageDoc().
 */
export const PtStaticContent = Node.create({
    name: "ptStaticContent",
    group: "block",
    atom: true,
    selectable: true,
    draggable: true,

    addAttributes() {
        return {
            title:  { default: "" },
            level:  { default: 2 },
            format: { default: "html" },
            body:   { default: "" },
            items:  { default: [] },
        };
    },

    parseHTML() { return [{ tag: 'section[data-pt-type="ptStaticContent"]' }]; },

    renderHTML({ node, HTMLAttributes }) {
        const level = Math.max(2, Math.min(4, node.attrs.level || 2));
        return ["section", mergeAttributes(HTMLAttributes, {
            "data-pt-type": "ptStaticContent",
            "class": "pt-static-content",
            "data-level": String(level),
        }), node.attrs.title || ""];
    },

    addNodeView() {
        return ({ node, editor, getPos }) => {
            const dom = document.createElement("div");
            dom.className = "pt-static-content-editor pt-editable";
            dom.setAttribute("data-pt-type", "ptStaticContent");

            const dispatch = (newAttrs) => {
                const pos = typeof getPos === "function" ? getPos() : null;
                if (pos == null) return;
                const tr = editor.view.state.tr.setNodeMarkup(pos, null, { ...node.attrs, ...newAttrs });
                editor.view.dispatch(tr);
            };

            const render = (attrs) => {
                dom.innerHTML = "";
                attachBlockDeleteBtn(dom, editor, getPos);
                const header = document.createElement("div");
                header.className = "pt-static-content__hdr";

                const titleInput = document.createElement("input");
                titleInput.type = "text";
                titleInput.value = attrs.title || "";
                titleInput.className = "pt-static-content__title-input";
                titleInput.placeholder = "Titolo sezione (opzionale)…";
                bindAtomInputSafety(titleInput);
                titleInput.addEventListener("blur", () => {
                    if ((attrs.title ?? "") === titleInput.value) return;
                    dispatch({ title: titleInput.value });
                });

                const levelSel = document.createElement("select");
                levelSel.className = "pt-static-content__level-sel";
                levelSel.title = "Livello heading";
                [2, 3, 4].forEach((l) => {
                    const opt = document.createElement("option");
                    opt.value = String(l); opt.textContent = `H${l}`;
                    if (l === (attrs.level || 2)) opt.selected = true;
                    levelSel.appendChild(opt);
                });
                levelSel.addEventListener("mousedown", (e) => e.stopPropagation());
                levelSel.addEventListener("change", () => dispatch({ level: parseInt(levelSel.value, 10) }));

                header.append(titleInput, levelSel);
                dom.appendChild(header);

                const bodyInput = document.createElement("textarea");
                bodyInput.className = "pt-static-content__body-input";
                bodyInput.placeholder = "Contenuto HTML (h2-h4, p, ul/ol, blockquote, a, strong/em). Es:\n<p>Testo...</p><ul><li>Punto 1</li></ul>";
                bodyInput.rows = 6;
                bodyInput.value = attrs.body || "";
                bindAtomInputSafety(bodyInput);
                bodyInput.addEventListener("blur", () => {
                    let cleaned = bodyInput.value;
                    try {
                        if (window.FM?.PtSanitizer?.sanitizeForPageDoc) {
                            cleaned = window.FM.PtSanitizer.sanitizeForPageDoc(bodyInput.value);
                        }
                    } catch (_) { /* server authoritative — graceful */ }
                    if ((attrs.body ?? "") === cleaned) return;
                    dispatch({ body: cleaned });
                });
                dom.appendChild(bodyInput);

                const meta = document.createElement("div");
                meta.className = "pt-static-content__meta";
                const nestedCount = Array.isArray(attrs.items) ? attrs.items.length : 0;
                meta.textContent = `H${attrs.level || 2} · ${attrs.format || "html"} · ${(attrs.body || "").length} char · ${nestedCount} sub-sezioni`;
                dom.appendChild(meta);
            };

            render(node.attrs);

            return {
                dom,
                ignoreMutation: () => true,
                update: (updatedNode) => {
                    if (updatedNode.type.name !== "ptStaticContent") return false;
                    render(updatedNode.attrs);
                    return true;
                },
            };
        };
    },

    addCommands() {
        return {
            insertPtStaticContent:
                (title, body, level) =>
                ({ commands }) =>
                    commands.insertContent({
                        type: this.name,
                        attrs: {
                            title: title || "",
                            level: Number.isInteger(level) ? level : 2,
                            format: "html",
                            body: body || "",
                            items: [],
                        },
                    }),
        };
    },
});

/**
 * G23 page-doc — Block atom: accordion via <details>/<summary> native.
 */
export const PtAccordion = Node.create({
    name: "ptAccordion",
    group: "block",
    atom: true,
    selectable: true,
    draggable: true,

    addAttributes() {
        return {
            items: { default: [] },
            allow_multiple: { default: true },
        };
    },

    parseHTML() { return [{ tag: 'div[data-pt-type="ptAccordion"]' }]; },

    renderHTML({ HTMLAttributes }) {
        return ["div", mergeAttributes(HTMLAttributes, {
            "data-pt-type": "ptAccordion",
            "class": "pt-accordion",
        })];
    },

    addNodeView() {
        return ({ node, editor, getPos }) => {
            const dom = document.createElement("div");
            dom.className = "pt-accordion-editor pt-editable";
            dom.setAttribute("data-pt-type", "ptAccordion");

            const dispatch = (newAttrs) => {
                const pos = typeof getPos === "function" ? getPos() : null;
                if (pos == null) return;
                const tr = editor.view.state.tr.setNodeMarkup(pos, null, { ...node.attrs, ...newAttrs });
                editor.view.dispatch(tr);
            };

            const render = (items, allowMultiple) => {
                dom.innerHTML = "";
                attachBlockDeleteBtn(dom, editor, getPos);
                const toolbar = document.createElement("div");
                toolbar.className = "pt-accordion-toolbar";

                const addBtn = document.createElement("button");
                addBtn.type = "button";
                addBtn.className = "pt-accordion-btn";
                addBtn.textContent = "+ voce";
                addBtn.title = "Aggiungi una voce a comparsa (apribile/chiudibile)";
                addBtn.addEventListener("mousedown", (e) => e.stopPropagation());
                addBtn.addEventListener("click", (e) => {
                    e.stopPropagation();
                    dispatch({ items: [...items, { title: "Nuova voce", body_pt: [{ _type: "block", style: "normal", children: [{ _type: "span", text: "", marks: [] }] }], default_open: false }] });
                });
                toolbar.appendChild(addBtn);

                const multipleLabel = document.createElement("label");
                multipleLabel.className = "pt-accordion-toggle";
                const multipleCb = document.createElement("input");
                multipleCb.type = "checkbox";
                multipleCb.checked = !!allowMultiple;
                multipleCb.title = "Se attivo, il lettore può tenere aperte più voci contemporaneamente";
                multipleCb.addEventListener("mousedown", (e) => e.stopPropagation());
                multipleCb.addEventListener("change", () => dispatch({ allow_multiple: multipleCb.checked }));
                multipleLabel.append(multipleCb, document.createTextNode(" più voci aperte insieme"));
                toolbar.appendChild(multipleLabel);

                // Master checkbox: spunta/toglie a TUTTE le voci (escludi/includi
                // tutte). Indeterminate quando alcune sì e altre no.
                const allExcl = items.length > 0 && items.every((x) => x && x.excluded);
                const someExcl = items.some((x) => x && x.excluded);
                const masterLabel = document.createElement("label");
                masterLabel.className = "pt-accordion-toggle pt-accordion-master";
                const masterCb = document.createElement("input");
                masterCb.type = "checkbox";
                masterCb.checked = allExcl;
                masterCb.indeterminate = someExcl && !allExcl;
                masterCb.title = "Escludi/includi TUTTE le voci in un colpo";
                masterCb.addEventListener("mousedown", (e) => e.stopPropagation());
                masterCb.addEventListener("change", () => {
                    dispatch({ items: items.map((x) => ({ ...x, excluded: masterCb.checked })) });
                });
                masterLabel.append(masterCb, document.createTextNode(" escludi tutte"));
                toolbar.appendChild(masterLabel);
                dom.appendChild(toolbar);

                items.forEach((it, i) => {
                    const row = document.createElement("details");
                    row.className = "pt-accordion-item-edit";
                    // Voce esclusa (checkbox) → collassata + attenuata + omessa
                    // da Anteprima/TeX.
                    if (it.excluded) row.classList.add("pt-accordion-item-edit--excluded");
                    if (it.default_open && !it.excluded) row.open = true;

                    const summary = document.createElement("summary");
                    summary.className = "pt-accordion-item-summary";

                    // Checkbox "escludi questa voce".
                    const exclCb = document.createElement("input");
                    exclCb.type = "checkbox";
                    exclCb.checked = !!it.excluded;
                    exclCb.className = "pt-accordion-item-excl";
                    exclCb.title = "Spunta per ESCLUDERE questa voce da Anteprima e TeX (la collassa)";
                    exclCb.addEventListener("mousedown", (e) => e.stopPropagation());
                    exclCb.addEventListener("click", (e) => e.stopPropagation());
                    exclCb.addEventListener("change", () => {
                        dispatch({ items: items.map((x, j) => j === i ? { ...x, excluded: exclCb.checked } : x) });
                    });
                    summary.appendChild(exclCb);

                    const titleInput = document.createElement("input");
                    titleInput.type = "text";
                    titleInput.value = it.title || "";
                    titleInput.className = "pt-accordion-item-title";
                    titleInput.placeholder = `Titolo voce ${i + 1}`;
                    bindAtomInputSafety(titleInput);
                    titleInput.addEventListener("blur", () => {
                        if ((items[i]?.title ?? "") === titleInput.value) return;
                        const next = items.map((x, j) => j === i ? { ...x, title: titleInput.value } : x);
                        dispatch({ items: next });
                    });
                    const rm = document.createElement("button");
                    rm.type = "button";
                    rm.textContent = "×";
                    rm.className = "pt-accordion-item-rm";
                    rm.title = "Rimuovi questa voce";
                    rm.addEventListener("mousedown", (e) => e.stopPropagation());
                    rm.addEventListener("click", (e) => {
                        e.stopPropagation();
                        dispatch({ items: items.filter((_, j) => j !== i) });
                    });
                    summary.append(titleInput, rm);
                    row.appendChild(summary);

                    const body = document.createElement("div");
                    body.className = "pt-accordion-item-body";
                    const bodyInput = document.createElement("textarea");
                    bodyInput.className = "pt-accordion-item-body-input";
                    bodyInput.placeholder = "Contenuto della voce (testo)";
                    bodyInput.rows = 4;
                    bodyInput.value = (Array.isArray(it.body_pt) && it.body_pt[0]?.children?.[0]?.text) || "";
                    bindAtomInputSafety(bodyInput);
                    bodyInput.addEventListener("blur", () => {
                        const next = items.map((x, j) =>
                            j === i ? { ...x, body_pt: [{ _type: "block", style: "normal", children: [{ _type: "span", text: bodyInput.value, marks: [] }] }] } : x,
                        );
                        dispatch({ items: next });
                    });
                    body.appendChild(bodyInput);
                    row.appendChild(body);

                    dom.appendChild(row);
                });
            };

            render(
                Array.isArray(node.attrs.items) ? node.attrs.items : [],
                node.attrs.allow_multiple !== false,
            );

            return {
                dom,
                ignoreMutation: () => true,
                update: (updatedNode) => {
                    if (updatedNode.type.name !== "ptAccordion") return false;
                    render(
                        Array.isArray(updatedNode.attrs.items) ? updatedNode.attrs.items : [],
                        updatedNode.attrs.allow_multiple !== false,
                    );
                    return true;
                },
            };
        };
    },

    addCommands() {
        return {
            insertPtAccordion:
                (items, allowMultiple) =>
                ({ commands }) =>
                    commands.insertContent({
                        type: this.name,
                        attrs: {
                            items: Array.isArray(items) ? items : [],
                            allow_multiple: allowMultiple !== false,
                        },
                    }),
        };
    },
});

/**
 * G23 page-doc — Block atom: lista link normativi (PDF/URL) gerarchici.
 */
export const PtLinkListPdf = Node.create({
    name: "ptLinkListPdf",
    group: "block",
    atom: true,
    selectable: true,
    draggable: true,

    addAttributes() {
        return {
            title: { default: "" },
            items: { default: [] },
        };
    },

    parseHTML() { return [{ tag: 'div[data-pt-type="ptLinkListPdf"]' }]; },

    renderHTML({ HTMLAttributes }) {
        return ["div", mergeAttributes(HTMLAttributes, {
            "data-pt-type": "ptLinkListPdf",
            "class": "pt-link-list-pdf",
        })];
    },

    addNodeView() {
        return ({ node, editor, getPos }) => {
            const dom = document.createElement("div");
            dom.className = "pt-link-list-pdf-editor pt-editable";
            dom.setAttribute("data-pt-type", "ptLinkListPdf");

            const dispatch = (newAttrs) => {
                const pos = typeof getPos === "function" ? getPos() : null;
                if (pos == null) return;
                const tr = editor.view.state.tr.setNodeMarkup(pos, null, { ...node.attrs, ...newAttrs });
                editor.view.dispatch(tr);
            };

            const render = (attrs) => {
                dom.innerHTML = "";
                attachBlockDeleteBtn(dom, editor, getPos);

                const titleInput = document.createElement("input");
                titleInput.type = "text";
                titleInput.className = "pt-link-list-pdf__title-input";
                titleInput.placeholder = "Titolo gruppo link (es. Scuola dell'infanzia e primo ciclo)";
                titleInput.value = attrs.title || "";
                bindAtomInputSafety(titleInput);
                titleInput.addEventListener("blur", () => {
                    if ((attrs.title ?? "") === titleInput.value) return;
                    dispatch({ title: titleInput.value });
                });
                dom.appendChild(titleInput);

                const items = Array.isArray(attrs.items) ? attrs.items : [];
                items.forEach((it, i) => {
                    const row = document.createElement("div");
                    row.className = "pt-link-list-pdf__item-edit";

                    const labelIn = document.createElement("input");
                    labelIn.type = "text";
                    labelIn.value = it.label || "";
                    labelIn.placeholder = "Label";
                    labelIn.className = "pt-link-list-pdf__field";
                    bindAtomInputSafety(labelIn);
                    labelIn.addEventListener("blur", () => {
                        const next = items.map((x, j) => j === i ? { ...x, label: labelIn.value } : x);
                        dispatch({ items: next });
                    });

                    const hrefIn = document.createElement("input");
                    hrefIn.type = "text";
                    hrefIn.value = it.href || "";
                    hrefIn.placeholder = "URL / path";
                    hrefIn.className = "pt-link-list-pdf__field";
                    bindAtomInputSafety(hrefIn);
                    hrefIn.addEventListener("blur", () => {
                        const next = items.map((x, j) => j === i ? {
                            ...x,
                            href: hrefIn.value,
                            external: /^https?:\/\//i.test(hrefIn.value),
                        } : x);
                        dispatch({ items: next });
                    });

                    const descIn = document.createElement("input");
                    descIn.type = "text";
                    descIn.value = it.description || "";
                    descIn.placeholder = "Descrizione (opzionale)";
                    descIn.className = "pt-link-list-pdf__field";
                    bindAtomInputSafety(descIn);
                    descIn.addEventListener("blur", () => {
                        const next = items.map((x, j) => j === i ? { ...x, description: descIn.value } : x);
                        dispatch({ items: next });
                    });

                    const rm = document.createElement("button");
                    rm.type = "button";
                    rm.textContent = "×";
                    rm.className = "pt-link-list-pdf__rm";
                    rm.title = "Rimuovi link";
                    rm.addEventListener("mousedown", (e) => e.stopPropagation());
                    rm.addEventListener("click", (e) => {
                        e.stopPropagation();
                        dispatch({ items: items.filter((_, j) => j !== i) });
                    });

                    row.append(labelIn, hrefIn, descIn, rm);
                    dom.appendChild(row);
                });

                const addBtn = document.createElement("button");
                addBtn.type = "button";
                addBtn.className = "pt-link-list-pdf__add";
                addBtn.textContent = "+ link";
                addBtn.addEventListener("mousedown", (e) => e.stopPropagation());
                addBtn.addEventListener("click", (e) => {
                    e.stopPropagation();
                    dispatch({ items: [...items, { label: "", href: "", external: false }] });
                });
                dom.appendChild(addBtn);
            };

            render(node.attrs);

            return {
                dom,
                ignoreMutation: () => true,
                update: (updatedNode) => {
                    if (updatedNode.type.name !== "ptLinkListPdf") return false;
                    render(updatedNode.attrs);
                    return true;
                },
            };
        };
    },

    addCommands() {
        return {
            insertPtLinkListPdf:
                (title, items) =>
                ({ commands }) =>
                    commands.insertContent({
                        type: this.name,
                        attrs: {
                            title: title || "",
                            items: Array.isArray(items) ? items : [],
                        },
                    }),
        };
    },
});

/**
 * G23 page-doc — Block atom: citazione legge/decreto strutturata.
 */
export const PtCitationNorma = Node.create({
    name: "ptCitationNorma",
    group: "block",
    atom: true,
    selectable: true,
    draggable: true,

    addAttributes() {
        return {
            tipo:     { default: "DM" },
            numero:   { default: "" },
            anno:     { default: "" },
            articolo: { default: "" },
            title:    { default: "" },
            href:     { default: "" },
            quote:    { default: "" },
        };
    },

    parseHTML() { return [{ tag: 'aside[data-pt-type="ptCitationNorma"]' }]; },

    renderHTML({ HTMLAttributes }) {
        return ["aside", mergeAttributes(HTMLAttributes, {
            "data-pt-type": "ptCitationNorma",
            "class": "pt-citation-norma",
        })];
    },

    addNodeView() {
        return ({ node, editor, getPos }) => {
            const dom = document.createElement("div");
            dom.className = "pt-citation-norma-editor pt-editable";
            dom.setAttribute("data-pt-type", "ptCitationNorma");

            const dispatch = (newAttrs) => {
                const pos = typeof getPos === "function" ? getPos() : null;
                if (pos == null) return;
                const tr = editor.view.state.tr.setNodeMarkup(pos, null, { ...node.attrs, ...newAttrs });
                editor.view.dispatch(tr);
            };

            const render = (attrs) => {
                dom.innerHTML = "";
                attachBlockDeleteBtn(dom, editor, getPos);

                const row1 = document.createElement("div");
                row1.className = "pt-citation-norma__row";

                const tipoSel = document.createElement("select");
                tipoSel.className = "pt-citation-norma__tipo";
                ["L", "DL", "DLgs", "DPR", "DM", "DI", "CM", "DDG", "OM", "Racc", "COM", "altro"].forEach((t) => {
                    const opt = document.createElement("option");
                    opt.value = t; opt.textContent = t;
                    if (t === (attrs.tipo || "DM")) opt.selected = true;
                    tipoSel.appendChild(opt);
                });
                tipoSel.addEventListener("mousedown", (e) => e.stopPropagation());
                tipoSel.addEventListener("change", () => dispatch({ tipo: tipoSel.value }));

                const numeroIn = document.createElement("input");
                numeroIn.type = "text";
                numeroIn.placeholder = "n. (es. 5669)";
                numeroIn.value = attrs.numero || "";
                numeroIn.className = "pt-citation-norma__numero";
                bindAtomInputSafety(numeroIn);
                numeroIn.addEventListener("blur", () => {
                    if ((attrs.numero ?? "") === numeroIn.value) return;
                    dispatch({ numero: numeroIn.value });
                });

                const annoIn = document.createElement("input");
                annoIn.type = "text";
                annoIn.placeholder = "anno (es. 2011)";
                annoIn.value = attrs.anno != null ? String(attrs.anno) : "";
                annoIn.className = "pt-citation-norma__anno";
                bindAtomInputSafety(annoIn);
                annoIn.addEventListener("blur", () => {
                    if (String(attrs.anno ?? "") === annoIn.value) return;
                    dispatch({ anno: annoIn.value });
                });

                const articoloIn = document.createElement("input");
                articoloIn.type = "text";
                articoloIn.placeholder = "art./comma (opz.)";
                articoloIn.value = attrs.articolo || "";
                articoloIn.className = "pt-citation-norma__articolo";
                bindAtomInputSafety(articoloIn);
                articoloIn.addEventListener("blur", () => {
                    if ((attrs.articolo ?? "") === articoloIn.value) return;
                    dispatch({ articolo: articoloIn.value });
                });

                row1.append(tipoSel, numeroIn, annoIn, articoloIn);
                dom.appendChild(row1);

                const titleIn = document.createElement("input");
                titleIn.type = "text";
                titleIn.placeholder = "Titolo leggibile (opzionale)";
                titleIn.value = attrs.title || "";
                titleIn.className = "pt-citation-norma__title-input";
                bindAtomInputSafety(titleIn);
                titleIn.addEventListener("blur", () => {
                    if ((attrs.title ?? "") === titleIn.value) return;
                    dispatch({ title: titleIn.value });
                });
                dom.appendChild(titleIn);

                const hrefIn = document.createElement("input");
                hrefIn.type = "text";
                hrefIn.placeholder = "URL/path al PDF ufficiale (opzionale)";
                hrefIn.value = attrs.href || "";
                hrefIn.className = "pt-citation-norma__href";
                bindAtomInputSafety(hrefIn);
                hrefIn.addEventListener("blur", () => {
                    if ((attrs.href ?? "") === hrefIn.value) return;
                    dispatch({ href: hrefIn.value });
                });
                dom.appendChild(hrefIn);

                const quoteIn = document.createElement("textarea");
                quoteIn.placeholder = "Citazione testuale (opzionale, max 500 char)";
                quoteIn.value = attrs.quote || "";
                quoteIn.rows = 3;
                quoteIn.maxLength = 500;
                quoteIn.className = "pt-citation-norma__quote-input";
                bindAtomInputSafety(quoteIn);
                quoteIn.addEventListener("blur", () => {
                    if ((attrs.quote ?? "") === quoteIn.value) return;
                    dispatch({ quote: quoteIn.value });
                });
                dom.appendChild(quoteIn);

                const meta = document.createElement("div");
                meta.className = "pt-citation-norma__meta";
                const head = [attrs.tipo, attrs.numero, attrs.anno].filter(Boolean).join(" ").trim() || attrs.tipo || "DM";
                meta.textContent = `Anteprima: ${head}${attrs.articolo ? " · " + attrs.articolo : ""}`;
                dom.appendChild(meta);
            };

            render(node.attrs);

            return {
                dom,
                ignoreMutation: () => true,
                update: (updatedNode) => {
                    if (updatedNode.type.name !== "ptCitationNorma") return false;
                    render(updatedNode.attrs);
                    return true;
                },
            };
        };
    },

    addCommands() {
        return {
            insertPtCitationNorma:
                (tipo, numero, anno, articolo, title, href, quote) =>
                ({ commands }) =>
                    commands.insertContent({
                        type: this.name,
                        attrs: {
                            tipo: tipo || "DM",
                            numero: numero || "",
                            anno: anno || "",
                            articolo: articolo || "",
                            title: title || "",
                            href: href || "",
                            quote: quote || "",
                        },
                    }),
        };
    },
});

// G22.S15.bis Fase 5+ — delegate canonical (semantica identica).
function escapeHtml(s) {
    return window.FM?.DomUtils?.escHtml
        ? window.FM.DomUtils.escHtml(s)
        : String(s ?? "").replace(/[&<>"']/g, (c) =>
            ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
}

/**
 * Phase 24.11 — Normalizza cell (string legacy o object) a forma uniforme
 * `{text, widget, colspan, rowspan, merged}`.
 */
function normalizeCell(c) {
    const base = { text: "", widget: null, colspan: 1, rowspan: 1, merged: false, bg: "", align: "", valign: "" };
    if (c == null) return { ...base };
    if (typeof c === "string") return { ...base, text: c };
    if (typeof c === "object") {
        const H = ["left", "center", "right"];
        const V = ["top", "middle", "bottom"];
        return {
            text: typeof c.text === "string" ? c.text : "",
            widget: (c.widget && typeof c.widget === "object" && c.widget._type) ? c.widget : null,
            colspan: Math.max(1, parseInt(c.colspan, 10) || 1),
            rowspan: Math.max(1, parseInt(c.rowspan, 10) || 1),
            merged: !!c.merged,
            bg: typeof c.bg === "string" ? c.bg : "", // Phase 24.32
            align:  H.includes(c.align)  ? c.align  : "", // allineamento orizzontale
            valign: V.includes(c.valign) ? c.valign : "", // allineamento verticale
            // ADR-030 — id/binding per-cella per il binding per-terna (stabili).
            ...(typeof c.cid === "string" && c.cid ? { cid: c.cid } : {}),
            ...(c.binding ? { binding: String(c.binding) } : {}),
            // ADR-031 — formula della cella (stile Excel), se presente.
            ...(typeof c.formula === "string" && c.formula ? { formula: c.formula } : {}),
        };
    }
    return { ...base, text: String(c) };
}

/** Compatta una cella PT preservando TUTTE le chiavi significative (merge attrs,
 *  ADR-030 cid/binding, ADR-031 formula). Sorgente unica: usata da buildCellUI
 *  (updateCell) e dalla barra formula. Se tutto default → stringa nuda. */
function compactTableCell(c) {
    if (c.widget === null && c.colspan === 1 && c.rowspan === 1 && !c.merged
        && !c.bg && !c.align && !c.valign && !c.cid && !c.binding && !c.formula) return c.text || "";
    const out = { text: c.text, widget: c.widget, colspan: c.colspan, rowspan: c.rowspan, merged: c.merged };
    if (c.bg) out.bg = c.bg;
    if (c.align) out.align = c.align;
    if (c.valign) out.valign = c.valign;
    if (c.cid) out.cid = String(c.cid);
    if (c.binding) out.binding = String(c.binding);
    if (c.formula) out.formula = String(c.formula);
    return out;
}

/** Tag inline consentiti nelle celle (formattatori B/I/U/code). Mappa anche le
 *  varianti prodotte da execCommand (b/i) verso la forma canonica. */
const CELL_TAGS = { STRONG: "strong", B: "strong", EM: "em", I: "em", U: "u", CODE: "code" };

/** cell.text (markup grezzo con i 4 tag) → HTML sicuro per il contenteditable:
 *  escapa <>&, poi ripristina SOLO i 4 tag (come PtToHtml::renderInlineText). */
function cellTextToHtml(text) {
    const esc = String(text ?? "")
        .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
    return esc.replace(/&lt;(\/?)(strong|em|u|code)&gt;/g, "<$1$2>");
}

/** innerHTML del contenteditable → cell.text canonico: tieni solo i 4 tag
 *  (b→strong, i→em), testo grezzo (textContent), niente attributi → no XSS. */
function cellHtmlToText(html) {
    const tmp = document.createElement("div");
    tmp.innerHTML = String(html ?? "");
    const walk = (node) => {
        let out = "";
        node.childNodes.forEach((n) => {
            if (n.nodeType === 3) {
                out += n.textContent; // testo grezzo (decodificato)
            } else if (n.nodeType === 1) {
                const tag = CELL_TAGS[n.tagName];
                const inner = walk(n);
                if (tag) out += `<${tag}>${inner}</${tag}>`;
                else if (n.tagName === "BR") out += " ";
                else out += inner; // tag non consentito → tieni solo il contenuto
            }
        });
        return out;
    };
    // normalizza tag adiacenti uguali e vuoti
    return walk(tmp).replace(/<(strong|em|u|code)>\s*<\/\1>/g, "").trim();
}

/**
 * Phase 24.11 — Mount UI di una cell tabella nel NodeView:
 *   - Widget dinamico: text contenteditable rich / select / textField
 *   - Hover → piccolo gear (⚙) che apre popover config (type switch, options, merge)
 *   - Blur commit via dispatch con newRows array
 */
function buildCellUI(td, cell, ctx) {
    const { ri, ci, rows, columns, dispatch } = ctx;

    // Compatta una cella preservando merge attrs + cid/binding (ADR-030) +
    // formula (ADR-031). Sorgente unica condivisa con la barra formula.
    const compactCell = (c) => compactTableCell(c);

    const updateCell = (patch) => {
        const newRows = rows.map((r, ix) => {
            if (ix !== ri) return r;
            const copy = [...r];
            const existing = normalizeCell(copy[ci]);
            copy[ci] = compactCell({ ...existing, ...patch });
            return copy;
        });
        dispatch({ rows: newRows });
    };
    // Expose full dispatch per merge operations che toccano più cells
    ctx.dispatchRows = (newRows) => dispatch({ rows: newRows });
    ctx.compactCell = compactCell;

    // Render widget principale in base a tipo
    const w = cell.widget;
    // Una cella "select" SENZA opzioni né options_source è una select vuota
    // inutile (mostra solo "—"): la trattiamo come cella di TESTO normale. Risolve
    // la "tabella UDA" (4. PERCORSO DIDATTICO), che aveva celle select vuote, →
    // tabella come tutte le altre, senza dover ri-sincronizzare modello/fork.
    // Una cella "select" è "usabile" se ha opzioni inline OPPURE una sorgente
    // (file statico o folder-mode dinamico per combinazione). Una select senza
    // né opzioni né sorgente è inutile (solo "—") → resa come TESTO.
    const _selUsable = w && w._type === "select"
        && ((Array.isArray(w.options) && w.options.length > 0)
            || (w.options_source && (w.options_source.file || w.options_source.folder)));
    if (cell.formula) {
        // ADR-031 — cella FORMULA: editor inline (mostra risultato, al focus la
        // formula con autocompletamento). Errori (#DIV/0!, #CIRC!…) evidenziati.
        buildFormulaCell(td, cell, ctx, updateCell);
    } else if (_selUsable) {
        const sel = document.createElement("select");
        sel.className = "pt-table-cell-select";
        // Opzione "solo titoli": il menu mostra SOLO i titoli (i .group distinti
        // del dataset) invece dei sottotitoli. Default ("all") = titoli (optgroup)
        // + sottotitoli (comportamento storico).
        const applyOptMode = (list) => {
            if (w.opt_mode !== "titles" || !Array.isArray(list)) return list || [];
            const seen = new Set(); const out = [];
            for (const o of list) {
                const g = o && o.group;
                if (g && !seen.has(g)) { seen.add(g); out.push({ label: g, value: g }); }
            }
            return out.length ? out : list;
        };
        const fillOpts = (optsList) => {
            const current = sel.value;
            sel.innerHTML = "";
            const blank = document.createElement("option");
            blank.value = ""; blank.textContent = "—";
            if (!w.value) blank.selected = true;
            sel.appendChild(blank);
            // Phase 24.32 — group via <optgroup> se .group presente
            fillSelectGrouped(sel, applyOptMode(optsList || []), current || w.value);
        };
        // Phase 24.21 — fetch options_source runtime per table cell select.
        // Se source presente, mostra loading, fetch, popola. Cache via URL.
        if (w.options_source && (w.options_source.file || w.options_source.folder)) {
            const loading = document.createElement("option");
            loading.textContent = "(caricamento…)";
            loading.disabled = true;
            sel.appendChild(loading);
            const state = window.FM?.pt?.currentState || {};
            fetchSchemaOptions({ options_source: w.options_source }, state)
                .then((fetched) => {
                    // Priority: fetched > inline fallback (w.options)
                    fillOpts(fetched.length > 0 ? fetched : w.options);
                })
                .catch(() => fillOpts(w.options));
        } else {
            fillOpts(w.options);
        }
        sel.addEventListener("mousedown", (e) => e.stopPropagation());
        sel.addEventListener("change", () => updateCell({ widget: { ...w, value: sel.value } }));
        td.appendChild(sel);
    } else if (w && w._type === "checkbox") {
        // Cella checkbox: lista di checkbox (scelta multipla). value = array dei
        // valori spuntati. Opzioni inline o da JSON/cartella (come select).
        const wrap = document.createElement("div");
        wrap.className = "pt-table-cell-checkboxes";
        const fillChecks = (optsList) => {
            wrap.innerHTML = "";
            const checked = Array.isArray(w.value) ? w.value : (w.value ? [w.value] : []);
            let lastGroup = null;
            (optsList || []).forEach((o) => {
                const val = String(o?.value ?? o?.label ?? "");
                const lbl = String(o?.label ?? o?.value ?? "");
                if (val === "" && lbl === "") return;
                // Intestazione di gruppo (modalità "Tutti i gruppi") — rispetta
                // la struttura del JSON invece di appiattire le voci.
                const grp = o && o.group ? String(o.group) : "";
                if (grp && grp !== lastGroup) {
                    const gh = document.createElement("div");
                    gh.className = "pt-table-cell-check-group";
                    gh.textContent = grp;
                    wrap.appendChild(gh);
                    lastGroup = grp;
                }
                const label = document.createElement("label");
                label.className = "pt-table-cell-check";
                const cb = document.createElement("input");
                cb.type = "checkbox";
                cb.checked = checked.includes(val);
                cb.addEventListener("mousedown", (e) => e.stopPropagation());
                cb.addEventListener("change", () => {
                    const cur = Array.isArray(w.value) ? [...w.value] : [];
                    const idx = cur.indexOf(val);
                    if (cb.checked && idx < 0) cur.push(val);
                    else if (!cb.checked && idx >= 0) cur.splice(idx, 1);
                    updateCell({ widget: { ...w, value: cur } });
                });
                label.append(cb, document.createTextNode(` ${lbl}`));
                wrap.appendChild(label);
            });
            if (!wrap.children.length) {
                const empty = document.createElement("span");
                empty.className = "pt-table-cell-check-empty";
                empty.textContent = "(nessuna opzione — configura col ⚙)";
                wrap.appendChild(empty);
            }
        };
        if (w.options_source && (w.options_source.file || w.options_source.folder)) {
            const loading = document.createElement("span");
            loading.textContent = "(caricamento…)";
            wrap.appendChild(loading);
            const state = window.FM?.pt?.currentState || {};
            fetchSchemaOptions({ options_source: w.options_source }, state)
                .then((fetched) => fillChecks(fetched.length > 0 ? fetched : w.options))
                .catch(() => fillChecks(w.options));
        } else {
            fillChecks(w.options);
        }
        td.appendChild(wrap);
    } else if (w && w._type === "textField") {
        const inp = document.createElement("input");
        inp.type = ["text","number","date"].includes(w.kind) ? w.kind : "text";
        inp.value = w.value || "";
        inp.placeholder = w.placeholder || "";
        inp.className = "pt-table-cell-input";
        // Marker per il save flush: ri/ci/columnIdx + commit closure salvati
        // sull'elemento. _save in fm-pt-document forza il commit per gli
        // input "dirty" (value !== attribute corrente) → no focus loss perché
        // l'utente è già uscito cliccando Salva.
        inp.__ptCellCommit = () => updateCell({ widget: { ...w, value: inp.value } });
        bindAtomInputSafety(inp);
        inp.addEventListener("blur", () => inp.__ptCellCommit());
        // ADR-031 — digitando "=" come primo carattere la cella diventa FORMULA.
        inp.addEventListener("input", () => {
            if (inp.value.charAt(0) === "=") {
                _pendingFormulaFocus = `${ctx.ri},${ctx.ci}`;
                updateCell({ widget: null, formula: inp.value });
            }
        });
        td.appendChild(inp);
    } else {
        // Text plain (default) — contenteditable RICH: mostra strong/em/u/code
        // formattati mentre si scrive (i formattatori B/I/U/code della toolbar
        // li applicano via execCommand). Commit = innerHTML → cell.text canonico.
        const ed = document.createElement("div");
        ed.className = "pt-table-cell-input pt-table-cell-rich";
        ed.contentEditable = "true";
        ed.setAttribute("role", "textbox");
        ed.innerHTML = cellTextToHtml(cell.text || "");
        ed.__ptCellRich = true; // marker per la toolbar (formattazione rich)
        ed.__ptCellCommit = () => {
            const next = cellHtmlToText(ed.innerHTML);
            if ((cell.text || "") !== next) updateCell({ text: next });
        };
        bindAtomInputSafety(ed);
        ed.addEventListener("blur", () => ed.__ptCellCommit());
        // ADR-031 — digitando "=" come primo carattere la cella diventa FORMULA
        // (stile foglio di calcolo). Converte e passa all'editor formula inline.
        ed.addEventListener("input", () => {
            const txt = cellHtmlToText(ed.innerHTML);
            if (txt.charAt(0) === "=") {
                _pendingFormulaFocus = `${ctx.ri},${ctx.ci}`;
                updateCell({ widget: null, text: "", formula: txt });
            }
        });
        td.appendChild(ed);
    }

    // Allineamento orizzontale del testo dentro l'input/select/rich della cella.
    if (cell.align) {
        const field = td.querySelector("input, select, textarea, .pt-table-cell-rich");
        if (field) field.style.textAlign = cell.align;
    }

    // Config button (⚙) — sempre visibile per cell
    const cog = document.createElement("button");
    cog.type = "button";
    cog.className = "pt-table-cell-cfg";
    cog.title = "Configura cella: tipo (testo/select/input) + merge";
    cog.textContent = "⚙";
    cog.addEventListener("mousedown", (e) => e.stopPropagation());
    cog.addEventListener("click", (e) => {
        e.stopPropagation();
        openCellConfigPopover(td, cell, {
            ri, ci, columns, rows, updateCell,
            dispatchRows: ctx.dispatchRows,
            compactCell: ctx.compactCell,
            getCell: () => {
                const container = td.closest(".pt-table-container");
                try {
                    const latestRows = JSON.parse(container?.getAttribute("data-rows") || "[]");
                    return normalizeCell(latestRows[ri]?.[ci] ?? "");
                } catch { return cell; }
            },
        });
    });
    td.appendChild(cog);
}

function openCellConfigPopover(td, cell, ctx) {
    const container = td.closest?.(".pt-table-container");
    // Rimuovi popover esistente se presente (toggle)
    const existing = td.querySelector(".pt-table-cell-pop");
    if (existing) {
        existing.remove();
        if (container) container.__openCellPop = null;
        return;
    }

    const pop = document.createElement("div");
    pop.className = "pt-table-cell-pop";
    pop.addEventListener("mousedown", (e) => e.stopPropagation());
    pop.addEventListener("click", (e) => e.stopPropagation());
    td.appendChild(pop);
    if (container) container.__openCellPop = { ri: ctx.ri, ci: ctx.ci };

    // Phase 24.22 — Posiziona il popover (position:fixed) ancorandolo al ⚙ in
    // coordinate viewport. fixed esce dall'overflow:hidden della card sezione
    // (prima il popover era tagliato a sinistra per le celle a sinistra).
    // Apre sotto l'ancora, allineato al bordo destro; clamp dentro il viewport.
    const clampPop = () => {
        if (!pop.isConnected) return;
        const anchorEl = td.querySelector(".pt-table-cell-cfg") || td;
        const a = anchorEl.getBoundingClientRect();
        const vw = window.innerWidth;
        const vh = window.innerHeight;
        const MARGIN = 8;
        // Reset misure per leggere le dimensioni naturali del popover
        pop.style.maxHeight = "";
        pop.style.overflowY = "";
        pop.style.left = "0px";
        pop.style.top = "0px";
        const pw = pop.offsetWidth;
        const ph = pop.offsetHeight;
        // Orizzontale: allinea il bordo destro del popover al ⚙, poi clamp.
        let left = a.right - pw;
        if (left + pw > vw - MARGIN) left = vw - MARGIN - pw;
        if (left < MARGIN) left = MARGIN;
        // Verticale: sotto l'ancora; se sfora, prova sopra, altrimenti scroll.
        let top = a.bottom + 2;
        if (top + ph > vh - MARGIN) {
            const above = a.top - 2 - ph;
            if (above >= MARGIN) {
                top = above;
            } else {
                top = MARGIN;
                pop.style.maxHeight = `${vh - 2 * MARGIN}px`;
                pop.style.overflowY = "auto";
            }
        }
        pop.style.left = `${left}px`;
        pop.style.top = `${top}px`;
    };
    // Expose per re-clamp dopo renderPopContent
    pop.__clamp = clampPop;
    const reposition = () => clampPop();
    // resize + scroll: il popover è position:fixed → riposiziona dal ⚙ per
    // restare ancorato alla cella mentre si scrolla/ridimensiona.
    window.addEventListener("resize", reposition);
    window.addEventListener("scroll", reposition, true);

    const closePop = () => {
        if (!pop.isConnected) return;
        pop.remove();
        if (container) container.__openCellPop = null;
    };
    // Click fuori dal popover → chiudi. Capture + composedPath per attraversare
    // lo shadow DOM; ignora il ⚙ (gestito dal suo toggle). Deferred per non
    // intercettare il mousedown di apertura corrente.
    const onOutside = (e) => {
        const path = e.composedPath ? e.composedPath() : [];
        if (path.includes(pop)) return;
        const cogEl = td.querySelector(".pt-table-cell-cfg");
        if (cogEl && path.includes(cogEl)) return;
        closePop();
    };
    const armOutside = () => document.addEventListener("mousedown", onOutside, true);
    setTimeout(armOutside, 0);

    // Cleanup listener quando popover rimosso
    const mo = new MutationObserver(() => {
        if (!pop.isConnected) {
            window.removeEventListener("resize", reposition);
            window.removeEventListener("scroll", reposition, true);
            document.removeEventListener("mousedown", onOutside, true);
            mo.disconnect();
        }
    });
    if (pop.parentNode) mo.observe(pop.parentNode, { childList: true });

    // Phase 24.18 — renderPopContent: ricostruisce popover body con cell aggiornata.
    // Chiamato dopo mutations (merge / updateCell) per NON chiudere popover.
    const renderPopContent = (currentCell) => {
        pop.innerHTML = "";
        // Phase 24.22 — re-clamp dopo rebuild content (size può cambiare)
        requestAnimationFrame(() => pop.__clamp?.());
        const section = (heading, body, helpText) => {
            const w = document.createElement("div");
            w.className = "pt-table-cell-pop-section";
            const h = document.createElement("div");
            h.className = "pt-table-cell-pop-h";
            h.textContent = heading;
            w.append(h, body);
            if (helpText) {
                const help = document.createElement("div");
                help.className = "pt-table-cell-pop-help";
                help.innerHTML = helpText;
                w.appendChild(help);
            }
            return w;
        };

        // ── Tipo ──
        const typeRow = document.createElement("div");
        typeRow.className = "pt-table-cell-pop-row";
        const types = [
            ["text", "Testo", "Solo visualizzazione (non editabile dal docente)"],
            ["select", "Select", "Menù a tendina con opzioni predefinite (modificabili qui)"],
            ["checkbox", "Checkbox", "Lista di checkbox (scelta multipla) con opzioni predefinite (modificabili qui)"],
            ["textField", "Input", "Campo editabile per il docente (testo / numero / data)"],
            ["formula", "∑ Formula", "Calcolo automatico stile Excel (es. =SOMMA(B2:B5))"],
        ];
        const currentType = currentCell.formula ? "formula" : (currentCell.widget?._type ?? "text");
        for (const [t, label, title] of types) {
            const btn = document.createElement("button");
            btn.type = "button";
            btn.textContent = label;
            btn.title = title;
            btn.className = `pt-table-cell-pop-type${  t === currentType ? " active" : ""}`;
            btn.addEventListener("mousedown", (e) => e.stopPropagation());
            btn.addEventListener("click", (e) => {
                e.stopPropagation();
                // Phase 24.19 — options vuote di default (era "opzione1/Opzione 1").
                // User aggiunge via +add. Placeholder "valore"/"etichetta".
                // ADR-031 — i tipi non-formula azzerano formula; "formula" azzera widget.
                if (t === "text") ctx.updateCell({ widget: null, formula: null });
                else if (t === "select") ctx.updateCell({ widget: { _type: "select", value: "", options: [], options_source: null }, formula: null });
                else if (t === "checkbox") ctx.updateCell({ widget: { _type: "checkbox", value: [], options: [], options_source: null }, formula: null });
                else if (t === "textField") ctx.updateCell({ widget: { _type: "textField", kind: "text", value: "", placeholder: "" }, formula: null });
                else if (t === "formula") ctx.updateCell({ widget: null, formula: currentCell.formula || "=" });
            });
            typeRow.appendChild(btn);
        }
        pop.appendChild(section(
            "Tipo cella",
            typeRow,
            'Testo = sola lettura. Select = menù. Checkbox = scelta multipla. Input = editabile. ∑ Formula = calcolo automatico.'
        ));

        // ── ADR-031 — Editor della FORMULA (quando tipo = formula) ──
        if (currentType === "formula") {
            const box = document.createElement("div");
            box.className = "pt-formula-box";

            const fIn = document.createElement("input");
            fIn.type = "text";
            fIn.className = "pt-table-cell-pop-input pt-formula-input";
            fIn.value = currentCell.formula || "=";
            fIn.placeholder = "=SOMMA(B2:B5)";
            fIn.spellcheck = false;
            fIn.addEventListener("mousedown", (e) => e.stopPropagation());
            fIn.addEventListener("keydown", (e) => e.stopPropagation());
            const commit = () => {
                let v = fIn.value.trim();
                if (v && v.charAt(0) !== "=") v = "=" + v;
                if (v !== (currentCell.formula || "")) ctx.updateCell({ widget: null, formula: v || "=" });
            };
            fIn.addEventListener("blur", commit);
            fIn.addEventListener("change", commit);
            // memorizza la posizione del cursore per inserire la funzione lì
            const saveCaret = () => { fIn._caret = fIn.selectionStart; };
            ["keyup", "click", "select", "input"].forEach((ev) => fIn.addEventListener(ev, saveCaret));

            // Selettore funzioni: scegli → inserisce NOME() col cursore tra le parentesi.
            const FN_GROUPS = [
                ["Somma e conteggio", ["SOMMA", "MEDIA", "MEDIANA", "MIN", "MAX", "CONTA", "CONTA.SE", "SOMMA.SE", "PRODOTTO"]],
                ["Arrotondamenti", ["ARROTONDA", "ARROTONDA.PER.DIF", "ARROTONDA.PER.ECC", "INTERO"]],
                ["Matematica", ["RADQ", "POTENZA", "RESTO", "ABS"]],
                ["Condizioni / logica", ["SE", "SE.ERRORE", "E", "O", "NON"]],
            ];
            const fnSel = document.createElement("select");
            fnSel.className = "pt-table-cell-pop-input pt-formula-fnsel";
            fnSel.title = "Inserisci una funzione nella formula";
            const ph = document.createElement("option");
            ph.value = ""; ph.textContent = "ƒ Inserisci funzione…"; ph.selected = true;
            fnSel.appendChild(ph);
            for (const [grp, fns] of FN_GROUPS) {
                const og = document.createElement("optgroup");
                og.label = grp;
                for (const fn of fns) {
                    const o = document.createElement("option");
                    o.value = fn; o.textContent = fn;
                    og.appendChild(o);
                }
                fnSel.appendChild(og);
            }
            fnSel.addEventListener("mousedown", (e) => e.stopPropagation());
            fnSel.addEventListener("change", (e) => {
                e.stopPropagation();
                const fn = fnSel.value;
                fnSel.selectedIndex = 0;
                if (!fn) return;
                let val = fIn.value || "=";
                if (val.charAt(0) !== "=") val = "=" + val;
                const caret = Number.isInteger(fIn._caret) ? fIn._caret : val.length;
                const ins = fn + "()";
                fIn.value = val.slice(0, caret) + ins + val.slice(caret);
                const inside = caret + fn.length + 1; // tra le parentesi
                fIn.focus();
                try { fIn.setSelectionRange(inside, inside); } catch (_) {}
                fIn._caret = inside;
                commit();
            });

            const row = document.createElement("div");
            row.className = "pt-formula-row";
            row.append(fIn, fnSel);
            box.appendChild(row);

            pop.appendChild(section("Formula", box,
                'Riferimenti <strong>A1</strong>, <strong>B2</strong>, range <strong>A1:B3</strong> (colonne A,B,C… righe 1,2,3…, '
                + 'visibili nei bordi delle celle). Es. <code>=B2/B3*100</code>. '
                + 'Uguale per tutte le classi, calcola sui valori della classe corrente.'));
        }

        // ── ADR-030 — Valore 🔗 per classe / 📌 fisso (tutte le celle tranne formula) ──
        if (currentType !== "formula") {
            const cw = currentCell.widget || {};
            const isWidget = !!currentCell.widget;
            const autoFolder = !!(cw.options_source && cw.options_source.folder);
            // Celle con widget: per-classe di DEFAULT (salvo 📌). Celle di testo:
            // condivise di default (di solito etichette), per-classe solo se 🔗.
            const isLinked = autoFolder
                || (isWidget ? (cw.binding !== "fixed" && currentCell.binding !== "fixed")
                             : (currentCell.binding === "terna"));
            const bRow = document.createElement("div");
            bRow.className = "pt-table-cell-pop-row";
            const mkB = (val, label, title) => {
                const b = document.createElement("button");
                b.type = "button"; b.textContent = label; b.title = title;
                const active = val === "terna" ? isLinked : !isLinked;
                b.className = "pt-table-cell-pop-type" + (active ? " active" : "");
                if (autoFolder) b.disabled = true; // cartella = sempre 🔗
                b.addEventListener("mousedown", (e) => e.stopPropagation());
                b.addEventListener("click", (e) => { e.stopPropagation(); ctx.updateCell({ binding: val === "terna" ? "terna" : "fixed" }); });
                return b;
            };
            bRow.append(
                mkB("terna", "🔗 Per classe", "Il valore di questa cella cambia per indirizzo/classe/materia"),
                mkB("fixed", "📌 Fisso", "Il valore è lo stesso (condiviso) per tutte le classi"),
            );
            pop.appendChild(section("Valore", bRow,
                autoFolder
                    ? 'Prende le opzioni da una cartella → è <strong>sempre 🔗 per classe</strong>.'
                    : (isWidget
                        ? 'Col documento in "Valori per classe" un campo editabile è <strong>🔗 per classe</strong> di default; 📌 per condividerlo con tutte.'
                        : 'Una cella di testo è <strong>condivisa</strong> di default (è spesso un\'etichetta). Se invece è un <em>valore</em> da compilare per classe, scegli <strong>🔗</strong>.')));
        }

        // ── Config opzioni per Select / Checkbox (stessa struttura) ──
        if (currentType === "select" || currentType === "checkbox") {
            pop.appendChild(buildSelectOptionsSection(currentCell, ctx, renderPopContent, getFreshCell));
        }
        // ── Rendering del gruppo checkbox di cella (come il gruppo standalone) ──
        if (currentType === "checkbox") {
            const cw = currentCell.widget || {};
            const cmode = cw.renderMode || "all";
            const ccols = Math.max(1, Math.min(5, parseInt(cw.columns, 10) || 1));
            const setCW = (patch) => {
                const fresh = (getFreshCell().widget) || cw;
                ctx.updateCell({ widget: { ...fresh, ...patch } });
            };
            const mrow = document.createElement("div");
            mrow.className = "pt-table-cell-pop-row";
            const mkM = (val, label, title) => {
                const b = document.createElement("button");
                b.type = "button"; b.textContent = label; b.title = title;
                b.className = "pt-table-cell-pop-type" + (cmode === val ? " active" : "");
                b.addEventListener("mousedown", (e) => e.stopPropagation());
                b.addEventListener("click", (e) => { e.stopPropagation(); setCW({ renderMode: val }); });
                return b;
            };
            mrow.append(
                mkM("all", "☑/☐ Tutti", "Tutte le voci con la casella, incolonnate"),
                mkM("checked-only", "• Solo spuntati", "Solo le voci spuntate, in elenco"),
                mkM("checked-inline", "↪ Solo spuntati inline", "Solo le voci spuntate, nel flusso del testo"),
            );
            const colLbl = document.createElement("label");
            colLbl.className = "pt-table-cell-pop-val";
            colLbl.append(" Colonne: ");
            const colIn = document.createElement("input");
            colIn.type = "number"; colIn.min = "1"; colIn.max = "5"; colIn.value = String(ccols);
            colIn.style.width = "3.5em";
            bindAtomInputSafety(colIn);
            colIn.addEventListener("change", () => setCW({ columns: Math.max(1, Math.min(5, parseInt(colIn.value, 10) || 1)) }));
            colLbl.appendChild(colIn);
            const wrapM = document.createElement("div");
            wrapM.append(mrow, colLbl);
            pop.appendChild(section("Rendering (gruppo checkbox)", wrapM,
                'Come compaiono le voci: <strong>Tutti</strong> (☑/☐ incolonnati), <strong>Solo spuntati</strong> (elenco), <strong>Solo spuntati inline</strong> (nel testo).'));
        }
        if (currentType === "textField") {
            pop.appendChild(buildTextFieldKindSection(currentCell, ctx, renderPopContent, getFreshCell));
        }

        // ── Merge ──
        const colsLeft = ctx.columns.length - ctx.ci;
        const rowsLeft = ctx.rows.length - ctx.ri;
        // NB: il contenitore si chiama `mergeBar` (NON `mergeRow`): il nome
        // `mergeRow` è la FUNZIONE module-level che esegue il merge verticale;
        // una const locale omonima la oscurava → "+ row"/"− row" chiamavano un
        // <div> (TypeError) e il merge di riga non funzionava.
        const mergeBar = document.createElement("div");
        mergeBar.className = "pt-table-cell-pop-row";

        const colspanInfo = document.createElement("span");
        colspanInfo.className = "pt-table-cell-pop-val";
        colspanInfo.textContent = `cols: ${currentCell.colspan}`;

        const colPlus = document.createElement("button");
        colPlus.type = "button"; colPlus.textContent = "+ col";
        colPlus.disabled = currentCell.colspan >= colsLeft;
        colPlus.addEventListener("mousedown", (e) => e.stopPropagation());
        colPlus.addEventListener("click", (e) => {
            e.stopPropagation();
            mergeCol(ctx, +1);
        });

        const colMinus = document.createElement("button");
        colMinus.type = "button"; colMinus.textContent = "− col";
        colMinus.disabled = currentCell.colspan <= 1;
        colMinus.addEventListener("mousedown", (e) => e.stopPropagation());
        colMinus.addEventListener("click", (e) => {
            e.stopPropagation();
            mergeCol(ctx, -1);
        });

        const rowspanInfo = document.createElement("span");
        rowspanInfo.className = "pt-table-cell-pop-val";
        rowspanInfo.textContent = `rows: ${currentCell.rowspan}`;

        const rowPlus = document.createElement("button");
        rowPlus.type = "button"; rowPlus.textContent = "+ row";
        rowPlus.disabled = currentCell.rowspan >= rowsLeft;
        rowPlus.addEventListener("mousedown", (e) => e.stopPropagation());
        rowPlus.addEventListener("click", (e) => {
            e.stopPropagation();
            mergeRow(ctx, +1);
        });

        const rowMinus = document.createElement("button");
        rowMinus.type = "button"; rowMinus.textContent = "− row";
        rowMinus.disabled = currentCell.rowspan <= 1;
        rowMinus.addEventListener("mousedown", (e) => e.stopPropagation());
        rowMinus.addEventListener("click", (e) => {
            e.stopPropagation();
            mergeRow(ctx, -1);
        });

        mergeBar.append(colspanInfo, colMinus, colPlus, rowspanInfo, rowMinus, rowPlus);
        pop.appendChild(section("Unisci celle (merge)", mergeBar));

        // ── Allineamento cella (orizzontale + verticale) ──
        // Utile soprattutto sulle celle unite (merge): centra il contenuto in
        // orizzontale (colspan) o verticale (rowspan). updateCell({align|valign}).
        const alignRow = document.createElement("div");
        alignRow.className = "pt-table-cell-pop-row";
        const mkAlignBtn = (key, val, label, title) => {
            const b = document.createElement("button");
            b.type = "button";
            b.textContent = label;
            b.title = title;
            b.className = "pt-table-cell-pop-type" + (currentCell[key] === val ? " active" : "");
            b.addEventListener("mousedown", (e) => e.stopPropagation());
            b.addEventListener("click", (e) => {
                e.stopPropagation();
                // toggle: ri-cliccando lo stesso → torna a default ("").
                ctx.updateCell({ [key]: currentCell[key] === val ? "" : val });
            });
            return b;
        };
        alignRow.append(
            mkAlignBtn("align", "left", "⌫", "Allinea a sinistra"),
            mkAlignBtn("align", "center", "↔", "Centra in orizzontale"),
            mkAlignBtn("align", "right", "⌦", "Allinea a destra"),
            Object.assign(document.createElement("span"), { className: "pt-table-cell-pop-val", textContent: "│" }),
            mkAlignBtn("valign", "top", "⤒", "Allinea in alto"),
            mkAlignBtn("valign", "middle", "↕", "Centra in verticale"),
            mkAlignBtn("valign", "bottom", "⤓", "Allinea in basso"),
        );
        pop.appendChild(section("Allineamento", alignRow));

        // Phase 24.32 — color picker per la cella (cellcolor TeX)
        const colorRow = document.createElement("div");
        colorRow.className = "pt-table-cell-pop-row";
        const cellColor = document.createElement("input");
        cellColor.type = "color";
        cellColor.value = currentCell.bg || "#ffffff";
        cellColor.title = "Colore di sfondo della cella (LaTeX \\cellcolor)";
        cellColor.addEventListener("mousedown", (e) => e.stopPropagation());
        cellColor.addEventListener("input", () => {
            ctx.updateCell({ bg: cellColor.value });
        });
        const cellColorReset = document.createElement("button");
        cellColorReset.type = "button";
        cellColorReset.textContent = "✕ Reset";
        cellColorReset.title = "Rimuovi colore di sfondo";
        cellColorReset.addEventListener("mousedown", (e) => e.stopPropagation());
        cellColorReset.addEventListener("click", (e) => {
            e.stopPropagation();
            ctx.updateCell({ bg: "" });
        });
        const cellColorVal = document.createElement("code");
        cellColorVal.textContent = currentCell.bg || "(nessuno)";
        cellColorVal.style.fontSize = "10px";
        colorRow.append(cellColor, cellColorVal, cellColorReset);
        pop.appendChild(section("Colore sfondo cella", colorRow));

        // Close
        const closeBtn = document.createElement("button");
        closeBtn.type = "button";
        closeBtn.className = "pt-table-cell-pop-close";
        closeBtn.textContent = "Chiudi";
        closeBtn.addEventListener("mousedown", (e) => e.stopPropagation());
        closeBtn.addEventListener("click", (e) => {
            e.stopPropagation();
            pop.remove();
            if (container) container.__openCellPop = null;
        });
        pop.appendChild(closeBtn);
    };

    // Fresh cell getter: rilegge la cell corrente dal contesto tabella
    // (dopo updateCell il cell object originale è obsoleto).
    const getFreshCell = () => {
        if (typeof ctx.getCell === "function") return ctx.getCell();
        return cell; // fallback
    };

    renderPopContent(cell);
    // Phase 24.22 — clamp iniziale (attesa layout)
    requestAnimationFrame(clampPop);
}

/** Sezione popover: configurazione options inline per cella tipo select. */
function buildSelectOptionsSection(currentCell, ctx, renderPopContent, getFreshCell) {
    const w = document.createElement("div");
    w.className = "pt-table-cell-pop-section";

    // Phase 24.19 — Sorgente opzioni (inline / file / folder) come ptSelect popover
    const srcH = document.createElement("div");
    srcH.className = "pt-table-cell-pop-h";
    srcH.textContent = "Sorgente opzioni";
    w.appendChild(srcH);

    const currentSrc = currentCell.widget?.options_source;
    // Phase 24.20 — inference basata su presenza chiave (non truthy) così
    // {file: ""} (dopo click JSON button, prima di select path) rileva "file".
    const srcMode = !currentSrc ? "inline"
        : ("file" in currentSrc ? "file"
            : ("folder" in currentSrc ? "folder" : "inline"));

    // Sorgente: Inline (opzioni qui) oppure "Da catalogo" (cascata: tipo di
    // contenuto → Automatico-per-stato / file specifico). Niente più split
    // JSON/Cartella confuso: la cartella è l'opzione "Automatico" nella cascata.
    const isCatalog = srcMode === "file" || srcMode === "folder";
    const widgetBase = () => currentCell.widget || { _type: "select", value: "", options: [] };
    const srcRow = document.createElement("div");
    srcRow.className = "pt-table-cell-pop-row";
    const mkSrc = (active, label, title, onClick) => {
        const btn = document.createElement("button");
        btn.type = "button";
        btn.textContent = label;
        btn.title = title;
        btn.className = "pt-table-cell-pop-type" + (active ? " active" : "");
        btn.addEventListener("mousedown", (e) => e.stopPropagation());
        btn.addEventListener("click", (e) => { e.stopPropagation(); onClick(); });
        return btn;
    };
    srcRow.append(
        mkSrc(!isCatalog, "Inline", "Opzioni definite qui", () => {
            ctx.updateCell({ widget: { ...widgetBase(), options_source: null } });
        }),
        mkSrc(isCatalog, "📚 Da catalogo", "Carica da un contenuto curricolare (file o automatico per stato)", () => {
            ctx.updateCell({ widget: { ...widgetBase(), options_source: { file: currentSrc?.file || currentSrc?.folder || "" } } });
        }),
    );
    w.appendChild(srcRow);

    if (isCatalog) {
        const cascadeWrap = document.createElement("div");
        cascadeWrap.className = "pt-table-cell-pop-cascade";
        buildSourceCascade(cascadeWrap, {
            current: currentSrc,
            selClass: "pt-table-cell-pop-input",
            onPick: (src) => ctx.updateCell({ widget: { ...widgetBase(), options_source: src } }),
        });
        w.appendChild(cascadeWrap);

        // Cella CHECKBOX: importa un gruppo (o tutti) dalla sorgente scelta →
        // materializza in `options` (con group), azzerando la source. Evita
        // l'appiattimento e funziona sia con file specifico sia con "Automatico".
        const resolvedSrc = currentSrc?.file ? { file: currentSrc.file }
            : (currentSrc?.folder ? { folder: currentSrc.folder } : null);
        if (currentCell.widget?._type === "checkbox" && resolvedSrc) {
            const gh = document.createElement("div");
            gh.className = "pt-table-cell-pop-h";
            gh.textContent = "Importa gruppo";
            gh.style.marginTop = "6px";
            w.appendChild(gh);
            const gSel = document.createElement("select");
            gSel.className = "pt-table-cell-pop-input";
            gSel.style.width = "100%";
            gSel.title = "Scegli un gruppo del JSON, oppure tutti (con intestazioni)";
            gSel.innerHTML = '<option value="">(caricamento…)</option>';
            gSel.addEventListener("mousedown", (e) => e.stopPropagation());
            let loadedG = [];
            fetchSchemaOptions({ options_source: resolvedSrc }, window.FM?.pt?.currentState || {})
                .then((opts) => {
                    loadedG = Array.isArray(opts) ? opts : [];
                    const groups = [...new Set(loadedG.map((o) => o.group).filter(Boolean))];
                    gSel.innerHTML = "";
                    const empty = document.createElement("option"); empty.value = ""; empty.textContent = "— importa… —"; gSel.appendChild(empty);
                    const all = document.createElement("option"); all.value = "__all__"; all.textContent = "Tutti i gruppi (con intestazioni)"; gSel.appendChild(all);
                    groups.forEach((g) => { const o = document.createElement("option"); o.value = g; o.textContent = g; gSel.appendChild(o); });
                })
                .catch(() => { gSel.innerHTML = '<option value="">(errore caricamento)</option>'; });
            gSel.addEventListener("change", () => {
                const g = gSel.value;
                if (!g) return;
                const chosen = g === "__all__" ? loadedG : loadedG.filter((o) => o.group === g);
                const opts = chosen.map((o) => {
                    const x = { value: String(o.value ?? o.label ?? ""), label: String(o.label ?? o.value ?? "") };
                    if (g === "__all__" && o.group) x.group = String(o.group);
                    return x;
                });
                const checked = chosen.filter((o) => o.default).map((o) => String(o.value ?? o.label ?? ""));
                ctx.updateCell({ widget: { ...currentCell.widget, options: opts, options_source: null, value: checked } });
            });
            w.appendChild(gSel);
        }

        // Cella SELECT: scegli COSA pescare dalla sorgente — solo i titoli
        // (i gruppi/`titolo` del dataset) oppure titoli + sottotitoli (tutte le
        // voci, default). Imposta `opt_mode` sul widget; lo applica `applyOptMode`
        // in buildCellUI al momento del render del <select>.
        if (currentCell.widget?._type === "select") {
            const omH = document.createElement("div");
            omH.className = "pt-table-cell-pop-h";
            omH.textContent = "Inserisci";
            omH.style.marginTop = "6px";
            w.appendChild(omH);

            const omRow = document.createElement("div");
            omRow.className = "pt-table-cell-pop-row";
            const curMode = currentCell.widget?.opt_mode === "titles" ? "titles" : "all";
            const mkOm = (val, label, title) => {
                const btn = document.createElement("button");
                btn.type = "button";
                btn.textContent = label;
                btn.title = title;
                btn.className = "pt-table-cell-pop-type" + (curMode === val ? " active" : "");
                btn.addEventListener("mousedown", (e) => e.stopPropagation());
                btn.addEventListener("click", (e) => {
                    e.stopPropagation();
                    ctx.updateCell({ widget: { ...widgetBase(), opt_mode: val } });
                });
                return btn;
            };
            omRow.append(
                mkOm("all", "Titoli + sottotitoli", "Mostra tutte le voci del dataset (titoli come gruppi + sottovoci)"),
                mkOm("titles", "Solo titoli", "Mostra solo i titoli (i gruppi/le intestazioni del dataset)"),
            );
            w.appendChild(omRow);
        }
    }

    const h = document.createElement("div");
    h.className = "pt-table-cell-pop-h";
    h.textContent = srcMode === "inline" ? "Opzioni del menù" : "Lista fallback (se file non disponibile)";
    h.style.marginTop = "6px";
    w.appendChild(h);

    const options = Array.isArray(currentCell.widget?.options) ? currentCell.widget.options : [];
    const list = document.createElement("div");
    list.className = "pt-table-cell-pop-list";

    if (options.length === 0) {
        const empty = document.createElement("div");
        empty.className = "pt-table-cell-pop-empty";
        empty.textContent = "(nessuna — aggiungi con + sotto)";
        list.appendChild(empty);
    }
    options.forEach((o, i) => {
        const row = document.createElement("div");
        row.className = "pt-table-cell-pop-row";

        const valIn = document.createElement("input");
        valIn.type = "text";
        valIn.value = o?.value ?? "";
        valIn.placeholder = "valore";
        valIn.className = "pt-table-cell-pop-input";
        bindAtomInputSafety(valIn);
        valIn.addEventListener("blur", () => {
            const next = [...options];
            next[i] = { ...next[i], value: valIn.value };
            ctx.updateCell({ widget: { ...currentCell.widget, options: next } });
        });

        const labIn = document.createElement("input");
        labIn.type = "text";
        labIn.value = o?.label ?? "";
        labIn.placeholder = "etichetta";
        labIn.className = "pt-table-cell-pop-input";
        bindAtomInputSafety(labIn);
        labIn.addEventListener("blur", () => {
            const next = [...options];
            next[i] = { ...next[i], label: labIn.value };
            ctx.updateCell({ widget: { ...currentCell.widget, options: next } });
        });

        const rm = document.createElement("button");
        rm.type = "button";
        rm.textContent = "×";
        rm.className = "pt-table-cell-pop-rm";
        rm.title = "Rimuovi";
        rm.addEventListener("mousedown", (e) => e.stopPropagation());
        rm.addEventListener("click", (e) => {
            e.stopPropagation();
            const next = options.filter((_, j) => j !== i);
            ctx.updateCell({ widget: { ...currentCell.widget, options: next } });
        });

        row.append(valIn, labIn, rm);
        list.appendChild(row);
    });
    w.appendChild(list);

    const addBtn = document.createElement("button");
    addBtn.type = "button";
    addBtn.className = "pt-table-cell-pop-add";
    addBtn.textContent = "+ aggiungi opzione";
    addBtn.addEventListener("mousedown", (e) => e.stopPropagation());
    addBtn.addEventListener("click", (e) => {
        e.stopPropagation();
        const next = [...options, { value: "", label: "" }];
        ctx.updateCell({ widget: { ...currentCell.widget, options: next } });
    });
    w.appendChild(addBtn);

    return w;
}

/** Sezione popover: kind per cella tipo textField (text/number/date). */
function buildTextFieldKindSection(currentCell, ctx, renderPopContent, getFreshCell) {
    const w = document.createElement("div");
    w.className = "pt-table-cell-pop-section";

    const h = document.createElement("div");
    h.className = "pt-table-cell-pop-h";
    h.textContent = "Tipo input";
    w.appendChild(h);

    const row = document.createElement("div");
    row.className = "pt-table-cell-pop-row";

    const kinds = [["text","fm-testo"],["number","numero"],["date","data"]];
    const currentKind = currentCell.widget?.kind || "text";
    for (const [k, label] of kinds) {
        const btn = document.createElement("button");
        btn.type = "button";
        btn.textContent = label;
        btn.className = `pt-table-cell-pop-type${  k === currentKind ? " active" : ""}`;
        btn.addEventListener("mousedown", (e) => e.stopPropagation());
        btn.addEventListener("click", (e) => {
            e.stopPropagation();
            ctx.updateCell({ widget: { ...currentCell.widget, kind: k } });
        });
        row.appendChild(btn);
    }
    w.appendChild(row);

    // Placeholder input
    const phLabel = document.createElement("div");
    phLabel.className = "pt-table-cell-pop-h";
    phLabel.textContent = "Placeholder";
    phLabel.style.marginTop = "6px";
    w.appendChild(phLabel);

    const phIn = document.createElement("input");
    phIn.type = "text";
    phIn.value = currentCell.widget?.placeholder || "";
    phIn.placeholder = "(opzionale)";
    phIn.className = "pt-table-cell-pop-input";
    bindAtomInputSafety(phIn);
    phIn.addEventListener("blur", () => {
        ctx.updateCell({ widget: { ...currentCell.widget, placeholder: phIn.value } });
    });
    w.appendChild(phIn);

    return w;
}

function mergeCol(ctx, delta) {
    const { ri, ci, rows, columns, dispatchRows, compactCell } = ctx;
    const current = normalizeCell(rows[ri][ci]);
    const maxCols = columns.length - ci;
    const newColspan = Math.max(1, Math.min(maxCols, current.colspan + delta));
    if (newColspan === current.colspan) return;
    const newRows = rows.map((r) => [...r]);
    const row = newRows[ri];
    row[ci] = compactCell({ ...current, colspan: newColspan });
    // Gestisci cell merged laterali: range [ci+1, ci+newColspan)
    for (let k = 1; k < newColspan; k++) {
        const idx = ci + k;
        if (idx >= row.length) continue;
        const existing = normalizeCell(row[idx]);
        row[idx] = compactCell({ ...existing, merged: true });
    }
    // Smerge le cell oltre il nuovo bound
    for (let k = newColspan; k <= current.colspan; k++) {
        const idx = ci + k;
        if (idx >= row.length) continue;
        const existing = normalizeCell(row[idx]);
        row[idx] = compactCell({ ...existing, merged: false });
    }
    dispatchRows(newRows);
}

function mergeRow(ctx, delta) {
    const { ri, ci, rows, compactCell, dispatchRows } = ctx;
    const current = normalizeCell(rows[ri][ci]);
    const maxRows = rows.length - ri;
    const newRowspan = Math.max(1, Math.min(maxRows, current.rowspan + delta));
    if (newRowspan === current.rowspan) return;
    const newRows = rows.map((r) => [...r]);
    newRows[ri][ci] = compactCell({ ...current, rowspan: newRowspan });
    // Marca merged le cell (ri+k, ci) per k in [1, newRowspan)
    for (let k = 1; k < newRowspan; k++) {
        const targetRow = newRows[ri + k];
        if (!targetRow || ci >= targetRow.length) continue;
        const existing = normalizeCell(targetRow[ci]);
        targetRow[ci] = compactCell({ ...existing, merged: true });
    }
    // Smerge le cell oltre il nuovo bound
    for (let k = newRowspan; k <= current.rowspan; k++) {
        const targetRow = newRows[ri + k];
        if (!targetRow || ci >= targetRow.length) continue;
        const existing = normalizeCell(targetRow[ci]);
        targetRow[ci] = compactCell({ ...existing, merged: false });
    }
    dispatchRows(newRows);
}
