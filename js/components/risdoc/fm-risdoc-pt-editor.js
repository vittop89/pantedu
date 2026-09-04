/**
 * <fm-risdoc-pt-editor> — Rich editor Portable Text (Phase 22.3b/c).
 *
 * Wrapper Lit di Tiptap 3.x per l'editing di contenuti Portable Text del
 * dominio risdoc. Monta l'editor in shadow root, espone la property
 * `value` come PT AST e emette `pt-change` su ogni modifica.
 *
 * Attributes / properties:
 *   value      {Array|string}  PT AST (array) o stringa JSON. Property setter
 *                              accetta entrambi; attribute string viene parsato.
 *   fields     {Array<string>} nomi campi disponibili per field picker.
 *   readonly   {boolean}       disabilita editing.
 *
 * Events:
 *   pt-change  {detail: {value: Array}}  debounced 150ms.
 *
 * UX Phase 22.3c — eliminate alert/prompt, sostituite con modali inline
 * nel shadow root:
 *   - Field picker: dropdown con suggerimenti `fields` + input custom + validation
 *   - Checkbox group builder: lista editabile di items (+/-, toggle state,
 *     input label) + preset rapidi (Corretto/Adeguato/Poco corretto, ...)
 *   - Raw TeX editor: textarea + snippet preset cliccabili
 */

import { LitElement, html, css } from "https://cdn.jsdelivr.net/npm/lit@3/+esm";
import { Editor } from "@tiptap/core";
import StarterKit from "@tiptap/starter-kit";
// Phase 24.13 — Tiptap 3.x StarterKit include già Underline. Import
// separato causava "Duplicate extension names" warning; rimosso.

// Phase 22.7 — Source mode deps
import { EditorState } from "@codemirror/state";
import { EditorView, keymap, lineNumbers, highlightActiveLine } from "@codemirror/view";
import { defaultKeymap, history, historyKeymap } from "@codemirror/commands";
import { json as jsonLang, jsonParseLinter } from "@codemirror/lang-json";
import { linter, lintGutter } from "@codemirror/lint";
import { oneDark } from "@codemirror/theme-one-dark";

import {
    FieldRef, CheckboxGroup, RawTex, PtTable,
    PtSelect, PtTextField, PtFormCheckbox, PtSectionHeader,
    PtGlossaryTable, PtStaticContent, PtAccordion, PtLinkListPdf, PtCitationNorma,
    TextAlign, ListStyle, ListTabKeymap, CarryAttributes,
    getOptionsSourcesCatalog, ptGroupedSources,
} from "../../modules/risdoc/pt/pm-schema.js";
import { ptToPmDoc, pmDocToPt } from "../../modules/risdoc/pt/pm-pt-converter.js";
import { fetchSchemaOptions } from "./_options-fetcher.js";

/** Validator client-side leggero (shape check) — no dep su ajv. */
function validatePtShape(pt) {
    if (!Array.isArray(pt)) return { valid: false, error: "root must be array" };
    for (let i = 0; i < pt.length; i++) {
        const b = pt[i];
        if (!b || typeof b !== "object") return { valid: false, error: `block ${i}: not an object` };
        const t = b._type;
        if (t === "block") {
            if (b.children !== undefined && !Array.isArray(b.children)) return { valid: false, error: `block ${i}: children must be array` };
        } else if (t === "checkboxGroup") {
            if (!Array.isArray(b.items)) return { valid: false, error: `block ${i}: items must be array` };
        } else if (t === "rawTex") {
            if (typeof b.content !== "string") return { valid: false, error: `block ${i}: content must be string` };
        } else if (t === "table" || t === "select" || t === "textField" || t === "formCheckbox" || t === "sectionHeader") {
            // POC: existing block types validated at server. Client-side accept.
        } else if (t === "glossaryTable") {
            if (!Array.isArray(b.columns) || b.columns.length < 2) return { valid: false, error: `block ${i}: glossaryTable.columns must have ≥2 items` };
            if (!Array.isArray(b.entries)) return { valid: false, error: `block ${i}: glossaryTable.entries must be array` };
        } else if (t === "staticContent") {
            if (b.body !== undefined && typeof b.body !== "string") return { valid: false, error: `block ${i}: staticContent.body must be string` };
            if (b.items !== undefined && !Array.isArray(b.items))   return { valid: false, error: `block ${i}: staticContent.items must be array` };
        } else if (t === "accordion") {
            if (!Array.isArray(b.items)) return { valid: false, error: `block ${i}: accordion.items must be array` };
        } else if (t === "linkListPdf") {
            if (!Array.isArray(b.items)) return { valid: false, error: `block ${i}: linkListPdf.items must be array` };
        } else if (t === "citationNorma") {
            if (typeof b.tipo !== "string") return { valid: false, error: `block ${i}: citationNorma.tipo must be string` };
        } else {
            return { valid: false, error: `block ${i}: unknown _type "${t}"` };
        }
    }
    return { valid: true };
}

const CHECKBOX_PRESETS = [
    { label: "Comportamento classe", items: [
        { state: "x", label: "corretto" },
        { state: "_", label: "adeguato" },
        { state: "_", label: "poco corretto non" },
    ]},
    { label: "Sì / No", items: [
        { state: "_", label: "Sì" },
        { state: "_", label: "No" },
    ]},
    { label: "Alto / Medio / Basso", items: [
        { state: "_", label: "alto" },
        { state: "x", label: "medio" },
        { state: "_", label: "basso" },
    ]},
    { label: "Frequenza", items: [
        { state: "_", label: "sempre" },
        { state: "x", label: "spesso" },
        { state: "_", label: "a volte" },
        { state: "_", label: "mai" },
    ]},
];

const RAWTEX_SNIPPETS = [
    { label: "\\vspace{1em}", content: "\\vspace{1em}" },
    { label: "\\newpage", content: "\\newpage" },
    { label: "\\hline", content: "\\hline" },
    { label: "Equazione inline $...$", content: "$x^2 + y^2 = z^2$" },
    { label: "Equazione display", content: "\\begin{equation}\n    x^2 + y^2 = z^2\n\\end{equation}" },
    { label: "Itemize", content: "\\begin{itemize}\n    \\item primo\n    \\item secondo\n\\end{itemize}" },
];

export class FmRisdocPtEditor extends LitElement {
    static properties = {
        value:    { type: Object },
        fields:   { type: Array },
        readonly: { type: Boolean },
        compact:  { type: Boolean }, // Phase 24.10b — se true, no toolbar interna
        _ready:   { state: true },
        _modal:   { state: true },
        _mode:    { state: true }, // Phase 22.7: "rich" | "source"
        _sourceError: { state: true },
    };

    static styles = css`
        /* Phase 23 — tutti i colors via CSS custom properties definite in
         * css/risdoc-tokens.css (body-level, fluiscono attraverso shadow DOM).
         * body.fm-dark override automatico. Fallback inline per standalone demo.
         */
        :host {
            display: block;
            font-family: system-ui, -apple-system, sans-serif;
            border: 1px solid var(--fm-risdoc-editor-border, #ccc);
            border-radius: 4px;
            background: var(--fm-risdoc-editor-bg, #fff);
            color: var(--fm-risdoc-editor-fg, #1e293b);
            position: relative;
        }
        .toolbar {
            display: flex; gap: 4px;
            padding: 6px 8px;
            border-bottom: 1px solid var(--fm-risdoc-toolbar-border, #e5e5e5);
            background: var(--fm-risdoc-toolbar-bg, #fafafa);
            flex-wrap: wrap;
        }
        .toolbar button {
            padding: 4px 10px;
            font-size: 13px;
            background: var(--fm-risdoc-btn-bg, #fff);
            border: 1px solid var(--fm-risdoc-btn-border, #ddd);
            border-radius: 3px;
            cursor: pointer;
            color: var(--fm-risdoc-btn-fg, #333);
        }
        .toolbar button:hover { background: var(--fm-risdoc-btn-hover, #f0f0f0); }
        .toolbar button.is-active {
            background: var(--fm-risdoc-btn-active-bg, #2a5ac7);
            color: var(--fm-risdoc-btn-active-fg, #fff);
            border-color: var(--fm-risdoc-btn-active-bg, #2a5ac7);
        }
        .toolbar .sep { width: 1px; background: var(--fm-risdoc-btn-border, #ddd); margin: 0 4px; align-self: stretch; }

        .editor {
            padding: 12px;
            /* Content-fit: floor piccolo (cliccabile) ma l'altezza segue il
               contenuto. Il vecchio floor fisso 180-200px faceva "saltare" la
               card a 180px appena si scriveva una riga in una sottosezione. */
            min-height: 2.6em;
            line-height: 1.5;
            font-size: 14px;
            color: var(--fm-risdoc-editor-fg, #1e293b);
        }
        .editor:focus-within { outline: 2px solid var(--fm-risdoc-border-focus, #2a5ac7); outline-offset: -2px; }
        /* Phase 24.38 — ProseMirror richiede white-space: pre-wrap per gestire
           correttamente spazi multipli + line breaks (warning console eliminato). */
        .editor .ProseMirror {
            outline: none;
            min-height: 1.6em;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        /* BUG1 — "container" section (solo sectionHeader, nessun blocco di
           contenuto: i figli/sottosezioni sono card sibling separate): non
           riservare i ~180-200px del min-height, altrimenti resta un box vuoto
           alto sotto l'H2/H1 prima della prossima sottosezione.
           NB: ProseMirror inserisce SEMPRE un <p> finale vuoto dopo l'header
           atom (trailing break) → il vecchio selettore :only-child non
           matchava mai. Il container viene rilevato in JS dal value PT
           (_isContainerSection) e riflesso come attributo host. */
        :host([data-container-empty]) .editor {
            min-height: 0;
            padding-top: 4px;
            padding-bottom: 4px;
        }
        :host([data-container-empty]) .editor .ProseMirror {
            min-height: 0;
        }
        /* Sezione con SOTTOSEZIONI: il contenuto vero è nelle card figlie → il
           suo editor non riserva i ~180px (niente box vuoto alto sotto l'header/
           sottotitolo). Resta comunque cliccabile/editabile. */
        :host([has-subsections]) .editor { min-height: 0; padding-bottom: 6px; }
        :host([has-subsections]) .editor .ProseMirror { min-height: 1.6em; }
        .editor p { margin: 0 0 0.8em; }
        .editor p:last-child { margin-bottom: 0; }
        .editor strong { font-weight: 700; }
        .editor em { font-style: italic; }
        .editor u { text-decoration: underline; }
        .editor code {
            background: var(--fm-risdoc-code-inline-bg, #f4f4f4); padding: 1px 4px;
            border-radius: 3px; font-family: 'SF Mono', Consolas, monospace;
            font-size: 0.9em;
        }

        /* ── ELENCHI (shadow DOM: serve CSS esplicito, le UA-default non bastano
              per le varianti). Default + 13 varianti data-fm-list-style, marker
              per livello via ::marker (coerente con la resa LaTeX/HTML). ── */
        .editor ul, .editor ol { padding-left: 1.8em; margin: .5em 0; }
        .editor li { margin: .15em 0; }
        .editor li > p { margin: 0; }
        .editor ul { list-style-type: disc; }
        .editor ul ul { list-style-type: circle; }
        .editor ul ul ul { list-style-type: square; }
        .editor ol { list-style-type: decimal; }
        .editor ol ol { list-style-type: lower-alpha; }
        .editor ol ol ol { list-style-type: lower-roman; }
        /* bullet: arrow ➤♦● */
        .editor ul[data-fm-list-style="arrow-bullet"] { list-style: none; }
        .editor ul[data-fm-list-style="arrow-bullet"] > li::marker { content: "➤  "; }
        .editor ul[data-fm-list-style="arrow-bullet"] ul > li::marker { content: "♦  "; }
        .editor ul[data-fm-list-style="arrow-bullet"] ul ul > li::marker { content: "●  "; }
        /* bullet: star ★○■ */
        .editor ul[data-fm-list-style="star-circle"] { list-style: none; }
        .editor ul[data-fm-list-style="star-circle"] > li::marker { content: "★  "; }
        .editor ul[data-fm-list-style="star-circle"] ul > li::marker { content: "○  "; }
        .editor ul[data-fm-list-style="star-circle"] ul ul > li::marker { content: "■  "; }
        /* ordered con suffisso . (list-style-type per livello) */
        .editor ol[data-fm-list-style="alpha-decimal"] { list-style-type: upper-alpha; }
        .editor ol[data-fm-list-style="alpha-decimal"] ol { list-style-type: decimal; }
        .editor ol[data-fm-list-style="alpha-decimal"] ol ol { list-style-type: lower-alpha; }
        .editor ol[data-fm-list-style="lower-alpha-roman"] { list-style-type: lower-alpha; }
        .editor ol[data-fm-list-style="lower-alpha-roman"] ol { list-style-type: lower-roman; }
        .editor ol[data-fm-list-style="lower-alpha-roman"] ol ol { list-style-type: decimal; }
        .editor ol[data-fm-list-style="roman-alpha"] { list-style-type: upper-roman; }
        .editor ol[data-fm-list-style="roman-alpha"] ol { list-style-type: upper-alpha; }
        .editor ol[data-fm-list-style="roman-alpha"] ol ol { list-style-type: decimal; }
        .editor ol[data-fm-list-style="decimal-zero"] { list-style-type: decimal-leading-zero; }
        .editor ol[data-fm-list-style="decimal-zero"] ol { list-style-type: lower-alpha; }
        .editor ol[data-fm-list-style="decimal-zero"] ol ol { list-style-type: lower-roman; }
        /* ordered con suffisso ) (::marker + counter) */
        .editor ol[data-fm-list-style="paren"], .editor ol[data-fm-list-style="alpha-paren"],
        .editor ol[data-fm-list-style="lower-alpha-paren"], .editor ol[data-fm-list-style="roman-paren"],
        .editor ol[data-fm-list-style="decimal-zero-paren"] { list-style: none; }
        .editor ol[data-fm-list-style="paren"] > li::marker { content: counter(list-item, decimal) ")  "; }
        .editor ol[data-fm-list-style="paren"] ol > li::marker { content: counter(list-item, lower-alpha) ")  "; }
        .editor ol[data-fm-list-style="paren"] ol ol > li::marker { content: counter(list-item, lower-roman) ")  "; }
        .editor ol[data-fm-list-style="alpha-paren"] > li::marker { content: counter(list-item, upper-alpha) ")  "; }
        .editor ol[data-fm-list-style="alpha-paren"] ol > li::marker { content: counter(list-item, decimal) ")  "; }
        .editor ol[data-fm-list-style="alpha-paren"] ol ol > li::marker { content: counter(list-item, lower-alpha) ")  "; }
        .editor ol[data-fm-list-style="lower-alpha-paren"] > li::marker { content: counter(list-item, lower-alpha) ")  "; }
        .editor ol[data-fm-list-style="lower-alpha-paren"] ol > li::marker { content: counter(list-item, lower-roman) ")  "; }
        .editor ol[data-fm-list-style="lower-alpha-paren"] ol ol > li::marker { content: counter(list-item, decimal) ")  "; }
        .editor ol[data-fm-list-style="roman-paren"] > li::marker { content: counter(list-item, upper-roman) ")  "; }
        .editor ol[data-fm-list-style="roman-paren"] ol > li::marker { content: counter(list-item, upper-alpha) ")  "; }
        .editor ol[data-fm-list-style="roman-paren"] ol ol > li::marker { content: counter(list-item, decimal) ")  "; }
        .editor ol[data-fm-list-style="decimal-zero-paren"] > li::marker { content: counter(list-item, decimal-leading-zero) ")  "; }

        .pt-field-ref {
            display: inline-block;
            background: var(--fm-pt-field-bg, #e0eaff);
            color: var(--fm-pt-field-fg, #1e40af);
            padding: 1px 6px;
            border-radius: 3px;
            font-weight: 500;
            margin: 0 1px;
            cursor: default;
        }
        .pt-checkbox-group {
            margin: 0.6em 0;
            padding: 6px 10px;
            background: var(--fm-pt-checkbox-bg, #f8f9fa);
            border-left: 3px solid var(--fm-pt-checkbox-accent, #10b981);
            border-radius: 3px;
            display: grid;
            /* Phase 24.20 — auto-fit colonne responsive. Label lunghe non più
               troncate (min-width=fr permette growth fino a 100% container). */
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 4px 10px;
            align-items: start;
        }
        .pt-checkbox-group .pt-checkbox-mode-bar {
            grid-column: 1 / -1;
        }
        .pt-checkbox-item {
            display: inline-flex;
            align-items: flex-start; /* checkbox a inizio frase, non centrato */
            gap: 4px;
            font-size: 14px;
            min-width: 0; /* permit flex/grid shrinking */
        }
        /* Phase 22.3d — NodeView editabile inline */
        .pt-checkbox-group.pt-editable .pt-editable-item {
            display: flex;
            align-items: flex-start;
            gap: 6px;
            padding: 2px 4px;
            border-radius: 3px;
            transition: background 0.12s;
            min-width: 0;
        }
        .pt-checkbox-group.pt-editable .pt-editable-item input[type="checkbox"] {
            margin-top: 3px;
            flex: 0 0 auto;
        }
        .pt-checkbox-group.pt-editable .pt-editable-item:hover { background: rgba(16,185,129,0.08); }
        .pt-checkbox-label-input {
            font: inherit;
            font-size: 14px;
            padding: 2px 6px;
            border: 1px solid transparent;
            background: transparent;
            color: inherit;
            border-radius: 3px;
            /* Phase 24.21 — textarea con wrap multi-line. field-sizing:content
               auto-resize height in base al contenuto (Chrome 123+). */
            width: 100%;
            min-width: 0;
            resize: none;
            overflow: hidden;
            white-space: pre-wrap;
            word-wrap: break-word;
            word-break: break-word;
            line-height: 1.35;
            font-family: inherit;
            field-sizing: content;
        }
        .pt-checkbox-label-input:hover {
            border-color: var(--fm-risdoc-border-subtle, #d1d5db);
            background: var(--fm-risdoc-btn-bg, #fff);
        }
        .pt-checkbox-label-input:focus {
            border-color: var(--fm-risdoc-border-focus, #2a5ac7);
            background: var(--fm-risdoc-btn-bg, #fff);
            outline: none;
        }
        .pt-checkbox-remove {
            opacity: 0;
            width: 18px; height: 18px;
            padding: 0;
            font-size: 14px;
            line-height: 1;
            border: 0;
            border-radius: 3px;
            background: var(--fm-risdoc-btn-danger-bg, #fee2e2);
            color: var(--fm-risdoc-btn-danger-fg, #b91c1c);
            cursor: pointer;
            transition: opacity 0.12s;
        }
        .pt-editable-item:hover .pt-checkbox-remove { opacity: 1; }
        .pt-checkbox-remove:hover { filter: brightness(1.1); }
        .pt-checkbox-add {
            padding: 2px 10px;
            font-size: 13px;
            border: 1px dashed var(--fm-pt-checkbox-accent, #a7f3d0);
            background: transparent;
            color: var(--fm-pt-checkbox-accent, #047857);
            border-radius: 12px;
            cursor: pointer;
        }
        .pt-checkbox-add:hover {
            background: var(--fm-pt-checkbox-bg, #ecfdf5);
            border-style: solid;
        }

        .pt-raw-tex {
            margin: 0.6em 0;
            padding: 8px 12px;
            background: var(--fm-pt-rawtex-bg, #fef3c7);
            border-left: 3px solid var(--fm-pt-rawtex-accent, #d97706);
            border-radius: 3px;
            font-family: 'SF Mono', Consolas, monospace;
            font-size: 13px;
            color: var(--fm-pt-rawtex-fg, #78350f);
            white-space: pre-wrap;
            word-break: break-all;
        }
        .pt-raw-tex.pt-editable { cursor: pointer; transition: background 0.12s; }
        .pt-raw-tex.pt-editable:hover { filter: brightness(0.95); }
        .pt-raw-tex-prefix {
            font-weight: 700;
            margin-right: 6px;
            user-select: none;
        }
        .pt-raw-tex-body { opacity: 0.95; }

        /* Phase 24.1 — Table block */
        .pt-table-container {
            margin: 0.8em 0;
            padding: 6px;
            background: var(--fm-risdoc-panel-bg, #f8f9fa);
            border: 1px solid var(--fm-risdoc-border-subtle, #e2e8f0);
            border-radius: 4px;
        }
        .pt-table-toolbar {
            display: flex;
            gap: 8px;
            margin-bottom: 6px;
            flex-wrap: wrap;
            align-items: center;
        }
        /* Gruppi Righe/Colonne con etichetta: inserimento direzionale. */
        .pt-table-toolbar__group {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 6px;
            border: 1px solid var(--fm-risdoc-border-subtle, #e2e8f0);
            border-radius: 4px;
        }
        .pt-table-toolbar__lbl {
            font-size: 11px;
            font-weight: 600;
            color: var(--fm-risdoc-text-muted, #64748b);
        }
        .pt-table-btn {
            padding: 2px 10px;
            font-size: 12px;
            background: var(--fm-risdoc-btn-bg, #fff);
            color: var(--fm-risdoc-btn-fg, #334155);
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1);
            border-radius: 3px;
            cursor: pointer;
        }
        .pt-table-btn:hover { background: var(--fm-risdoc-btn-hover, #f1f5f9); }
        /* Evidenzia la cella attiva (cursore) → riferimento per gli inserimenti. */
        :is(.pt-table, .fm-pt-table) td:focus-within,
        :is(.pt-table, .fm-pt-table) th:focus-within {
            outline: 2px solid var(--fm-risdoc-border-focus, #2a5ac7);
            outline-offset: -2px;
        }
        /* Tabella: selettore :is() copre sia il NodeView interattivo
           (.fm-pt-table) sia il fallback SSR (.pt-table). Tutti i colori via
           token → dark-mode automatico (prima .fm-pt-table non era stilata e
           restava su bianco/nero di default in dark). */
        :is(.pt-table, .fm-pt-table) {
            width: 100%;
            border-collapse: collapse;
            background: var(--fm-risdoc-card-bg, #fff);
            color: var(--fm-risdoc-text, inherit);
            font-size: 13px;
        }
        /* widthMode="full": layout fisso → le percentuali del <colgroup>
           determinano le proporzioni delle colonne (feedback visivo in editor). */
        .fm-pt-table--full { table-layout: fixed; }
        .fm-pt-table--full th, .fm-pt-table--full td { overflow-wrap: anywhere; }
        :is(.pt-table, .fm-pt-table) th, :is(.pt-table, .fm-pt-table) td {
            border: 1px solid var(--fm-risdoc-border, #cbd5e1);
            padding: 4px;
            vertical-align: top;
        }
        :is(.pt-table, .fm-pt-table) th {
            background: var(--fm-risdoc-th-bg, #e0e0e0);
            color: var(--fm-risdoc-text, #1e293b);
            font-weight: 600;
        }
        .pt-table-cell-input {
            width: 100%;
            padding: 2px 6px;
            font: inherit;
            font-size: 13px;
            background: transparent;
            color: inherit;
            border: 1px solid transparent;
            border-radius: 2px;
            box-sizing: border-box;
        }
        .pt-table-cell-input.pt-table-cell-header { font-weight: 600; }
        .pt-table-cell-input:hover {
            border-color: var(--fm-risdoc-border-subtle, #d1d5db);
            background: var(--fm-risdoc-editor-bg, #fff);
        }
        .pt-table-cell-input:focus,
        .pt-table-cell-rich:focus {
            border-color: var(--fm-risdoc-border-focus, #2a5ac7);
            background: var(--fm-risdoc-editor-bg, #fff);
            outline: none;
        }
        /* Cella testo RICH (contenteditable): mostra B/I/U/code formattati. */
        .pt-table-cell-rich {
            min-height: 1.5em;
            cursor: text;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .pt-table-cell-rich:hover {
            border-color: var(--fm-risdoc-border-subtle, #d1d5db);
        }
        .pt-table-cell-rich strong, .pt-table-cell-rich b { font-weight: 700; }
        .pt-table-cell-rich em, .pt-table-cell-rich i { font-style: italic; }
        .pt-table-cell-rich u { text-decoration: underline; }
        .pt-table-cell-rich code {
            font-family: ui-monospace, Menlo, Consolas, monospace;
            font-size: 0.92em;
            background: var(--fm-risdoc-bg-field, #f1f5f9);
            padding: 0 3px;
            border-radius: 3px;
        }
        /* Cella tipo "checkbox": lista di checkbox (scelta multipla). */
        .pt-table-cell-checkboxes { display: flex; flex-direction: column; gap: 2px; padding: 2px 4px; }
        /* align-items:flex-start → il checkbox sta a INIZIO frase (cima del
           label multi-riga), non centrato verticalmente. */
        .pt-table-cell-check { display: flex; align-items: flex-start; gap: 4px; font-size: 13px; cursor: pointer; }
        .pt-table-cell-check input { margin: 2px 0 0; flex: 0 0 auto; accent-color: var(--fm-risdoc-accent, #2a5ac7); }
        .pt-table-cell-check-empty { font-size: 12px; font-style: italic; color: var(--fm-risdoc-text-muted, #64748b); }
        .pt-table-cell-check-group {
            font-weight: 600; font-size: 12px; margin-top: 4px;
            color: var(--fm-risdoc-text, #1e293b);
            border-bottom: 1px solid var(--fm-risdoc-border, #e2e8f0);
        }
        .pt-table-caption {
            font-size: 12px;
            color: var(--fm-risdoc-text-muted, #64748b);
            font-style: italic;
            margin-top: 4px;
            text-align: center;
        }

        /* ── Controlli larghezza tabella (.fm-pt-tw, BEM, dark-aware) ──
           Usati sia nel modal "Inserisci tabella" sia nella toolbar del
           NodeView. Tutti i colori via token → dark automatico. */
        .fm-pt-tw {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
            margin: 6px 0;
            font-size: 12px;
        }
        .fm-pt-tw__label {
            color: var(--fm-risdoc-text-muted, #64748b);
            font-weight: 600;
        }
        .fm-pt-tw__btn {
            padding: 3px 10px;
            font-size: 12px;
            cursor: pointer;
            background: var(--fm-risdoc-btn-bg, #fff);
            color: var(--fm-risdoc-btn-fg, #334155);
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1);
            border-radius: 4px;
        }
        .fm-pt-tw__btn:hover { background: var(--fm-risdoc-btn-hover, #f1f5f9); }
        /* .fm-pt-tw prefix → specificità (0,2,0) > .modal button (0,1,1),
           così l'accento attivo vince anche dentro il modal. */
        .fm-pt-tw .fm-pt-tw__btn--active,
        .fm-pt-tw .fm-pt-tw__btn--active:hover {
            background: var(--fm-risdoc-accent, #2a5ac7);
            color: #fff;
            border-color: var(--fm-risdoc-accent, #2a5ac7);
        }
        .fm-pt-tw__cols {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            width: 100%;
            margin-top: 2px;
        }
        .fm-pt-tw__col {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            color: var(--fm-risdoc-text, #1e293b);
        }
        .fm-pt-tw__col-name {
            max-width: 90px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: var(--fm-risdoc-text-muted, #64748b);
        }
        .fm-pt-tw__input {
            width: 48px;
            padding: 2px 4px;
            font-size: 12px;
            background: var(--fm-risdoc-editor-bg, #fff);
            color: var(--fm-risdoc-text, #1e293b);
            border: 1px solid var(--fm-risdoc-border-subtle, #d1d5db);
            border-radius: 3px;
        }
        .fm-pt-tw__unit { color: var(--fm-risdoc-text-muted, #64748b); }
        .fm-pt-tw__hint {
            font-size: 11px;
            color: var(--fm-risdoc-text-muted, #64748b);
            margin: 4px 0 0;
        }

        /* Phase 24.11 — Table cells tipate + merge UI */
        .pt-table-td {
            position: relative;
        }
        /* Corsia riservata a destra (~22px) per il pulsante ingranaggio: i
           controlli cella (input width 100%, select, frecce numero, .pt-fcell)
           si restringono nel content-box e non finiscono MAI sotto l'ingranaggio.
           Soluzione generale per ogni tipo di cella, senza layout-shift.
           Selettore con specificita' (0,2,1) per battere la regola td (0,1,1)
           che impone padding 4px. */
        :is(.pt-table, .fm-pt-table) td.pt-table-td {
            padding: 4px 24px 4px 4px;
        }
        /* Drag-to-fill stile Excel: cella selezionata + maniglia + celle target */
        .pt-table-td.is-selected {
            box-shadow: inset 0 0 0 2px var(--fm-risdoc-border-focus, #2a5ac7);
        }
        .pt-fill-handle {
            position: absolute; bottom: -3px; right: -3px;
            width: 9px; height: 9px;
            background: var(--fm-risdoc-border-focus, #2a5ac7);
            border: 1px solid var(--fm-risdoc-card-bg, #fff);
            border-radius: 1px;
            cursor: crosshair;
            z-index: 6;
        }
        .pt-table-td.pt-fill-target {
            box-shadow: inset 0 0 0 1px var(--fm-risdoc-border-focus, #2a5ac7);
            background: var(--fm-risdoc-formula-bg, rgba(59,130,246,.08));
        }
        .pt-table-cell-select {
            width: 100%;
            padding: 3px 4px;
            font-size: 13px;
            background: var(--fm-risdoc-btn-bg, #fff);
            color: var(--fm-risdoc-btn-fg, inherit);
            border: 1px solid transparent;
            border-radius: 2px;
        }
        .pt-table-cell-select:hover { border-color: var(--fm-risdoc-border-subtle, #d1d5db); }
        .pt-table-cell-select:focus { border-color: var(--fm-risdoc-border-focus, #2a5ac7); outline: none; }
        .pt-table-cell-cfg {
            position: absolute;
            top: 2px;
            right: 2px;
            width: 18px;
            height: 18px;
            padding: 0;
            font-size: 11px;
            line-height: 1;
            background: var(--fm-risdoc-card-bg, #fff);
            color: var(--fm-risdoc-text-muted, #64748b);
            border: 1px solid var(--fm-risdoc-border-subtle, #e5e5e5);
            border-radius: 3px;
            cursor: pointer;
            opacity: 0;
            transition: opacity 0.15s;
        }
        .pt-table-td:hover .pt-table-cell-cfg { opacity: 1; }
        .pt-table-cell-cfg:hover { background: var(--fm-risdoc-btn-hover, #f1f5f9); color: var(--fm-risdoc-text, #1e293b); }
        .pt-table-cell-pop {
            /* position:fixed → il popover esce dall'overflow:hidden della card
               sezione (.fm-section-wrap), che altrimenti lo taglia sul bordo
               sinistro per le celle a sinistra. top/left calcolati in JS
               (clampPop) dal rect del bottone ⚙ in coord. viewport. */
            position: fixed;
            z-index: 1000;
            padding: 8px 10px;
            min-width: 240px;
            max-width: 340px;            /* ADR-031 — niente popover larghissimi */
            box-sizing: border-box;
            background: var(--fm-risdoc-modal-bg, #fff);
            color: var(--fm-risdoc-text, #1e293b);
            border: 1px solid var(--fm-risdoc-modal-border, #e5e5e5);
            border-radius: 6px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        }
        .pt-table-cell-pop-help { white-space: normal; word-break: break-word; }
        .pt-table-cell-pop-help code {
            background: rgba(100,116,139,.12); padding: 0 3px; border-radius: 3px;
            font-size: 11px; white-space: nowrap;
        }
        /* ADR-031 — riga formula: input largo + selettore funzioni compatto */
        .pt-formula-row { display: flex; gap: 6px; align-items: stretch; }
        .pt-formula-input { flex: 1 1 auto; min-width: 0; font-family: ui-monospace, monospace; }
        .pt-formula-fnsel { flex: 0 0 auto; max-width: 46%; }
        .pt-table-cell-pop-section { margin-bottom: 8px; }
        .pt-table-cell-pop-h {
            font-size: 11px;
            font-weight: 600;
            color: var(--fm-risdoc-text-muted, #64748b);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        .pt-table-cell-pop-row {
            display: flex;
            gap: 4px;
            align-items: center;
            flex-wrap: wrap;
        }
        .pt-table-cell-pop-row button {
            padding: 3px 10px;
            font-size: 12px;
            background: var(--fm-risdoc-btn-bg, #fff);
            color: var(--fm-risdoc-btn-fg, inherit);
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1);
            border-radius: 3px;
            cursor: pointer;
        }
        .pt-table-cell-pop-row button:hover:not([disabled]) { background: var(--fm-risdoc-btn-hover, #f1f5f9); }
        .pt-table-cell-pop-row button[disabled] { opacity: 0.4; cursor: not-allowed; }
        .pt-table-cell-pop-type.active {
            background: var(--fm-risdoc-btn-active-bg, #2a5ac7);
            color: var(--fm-risdoc-btn-active-fg, #fff);
            border-color: var(--fm-risdoc-btn-active-bg, #2a5ac7);
        }
        .pt-table-cell-pop-val {
            padding: 3px 8px;
            font-size: 11px;
            font-family: monospace;
            background: var(--fm-risdoc-elevated-bg, #f8fafc);
            border: 1px solid var(--fm-risdoc-border-subtle, #e2e8f0);
            border-radius: 3px;
        }
        .pt-table-cell-pop-close {
            margin-top: 4px;
            padding: 4px 10px;
            font-size: 12px;
            background: var(--fm-risdoc-btn-bg, #fff);
            color: var(--fm-risdoc-btn-fg, inherit);
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1);
            border-radius: 3px;
            cursor: pointer;
        }
        /* Phase 24.18 — table cell popover: sezione options + help */
        .pt-table-cell-pop-help {
            margin-top: 4px;
            font-size: 10px;
            color: var(--fm-risdoc-text-muted, #64748b);
            line-height: 1.4;
        }
        .pt-table-cell-pop-list { margin: 4px 0; max-height: 160px; overflow-y: auto; }
        .pt-table-cell-pop-list .pt-table-cell-pop-row { margin-bottom: 3px; }
        .pt-table-cell-pop-input {
            padding: 3px 6px;
            font-size: 12px;
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1);
            border-radius: 3px;
            background: var(--fm-risdoc-bg-field, #fff);
            color: var(--fm-risdoc-text, inherit);
            min-width: 80px;
            flex: 1;
        }
        .pt-table-cell-pop-rm {
            padding: 2px 6px;
            font-size: 12px;
            background: transparent;
            color: var(--fm-risdoc-error-fg, #b91c1c);
            border: 1px solid var(--fm-risdoc-error-border, #fca5a5);
            border-radius: 3px;
            cursor: pointer;
            line-height: 1;
        }
        .pt-table-cell-pop-rm:hover { background: var(--fm-risdoc-error-bg, #fee2e2); }
        .pt-table-cell-pop-add {
            padding: 3px 10px;
            font-size: 11px;
            background: var(--fm-risdoc-btn-bg, #fff);
            color: var(--fm-risdoc-btn-fg, inherit);
            border: 1px dashed var(--fm-risdoc-btn-border, #cbd5e1);
            border-radius: 3px;
            cursor: pointer;
            width: 100%;
            margin-top: 2px;
        }
        .pt-table-cell-pop-add:hover { background: var(--fm-risdoc-btn-hover, #f1f5f9); }
        .pt-table-cell-pop-empty {
            font-size: 11px;
            color: var(--fm-risdoc-text-muted, #94a3b8);
            font-style: italic;
            padding: 4px 0;
        }
        /* Phase 24.19 — table header/footer notes */
        .pt-table-note {
            display: block;
            width: 100%;
            padding: 4px 6px;
            font-size: 12px;
            background: var(--fm-risdoc-bg-field, #f8f9fa);
            border: 1px dashed var(--fm-risdoc-btn-border, #cbd5e1);
            border-radius: 3px;
            color: var(--fm-risdoc-text, inherit);
            font-style: italic;
        }
        .pt-table-note-header { margin-bottom: 4px; }
        .pt-table-note-footer { margin-top: 4px; }
        .pt-table-note:focus { outline: 2px solid var(--fm-risdoc-accent, #2a5ac7); background: var(--fm-risdoc-card-bg, #fff); }

        /* ── Glossary table editor (dark-aware, allineato a .pt-table-*) ── */
        .pt-glossary-table.pt-editable {
            margin: 0.8em 0;
            padding: 6px;
            background: var(--fm-risdoc-panel-bg, #f8f9fa);
            border: 1px solid var(--fm-risdoc-border-subtle, #e2e8f0);
            border-radius: 4px;
        }
        .pt-glossary-toolbar { display: flex; gap: 4px; margin-bottom: 6px; flex-wrap: wrap; }
        .pt-glossary-btn {
            padding: 2px 10px; font-size: 12px;
            background: var(--fm-risdoc-btn-bg, #fff);
            color: var(--fm-risdoc-btn-fg, #334155);
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1);
            border-radius: 3px; cursor: pointer;
        }
        .pt-glossary-btn:hover { background: var(--fm-risdoc-btn-hover, #f1f5f9); }
        .pt-glossary-table-edit {
            width: 100%; border-collapse: collapse;
            background: var(--fm-risdoc-card-bg, #fff);
            color: var(--fm-risdoc-text, inherit); font-size: 13px;
        }
        .pt-glossary-table-edit th, .pt-glossary-table-edit td {
            border: 1px solid var(--fm-risdoc-border, #cbd5e1);
            padding: 2px; vertical-align: top;
        }
        .pt-glossary-table-edit th { background: var(--fm-risdoc-th-bg, #e0e0e0); }
        .pt-glossary-cell-input {
            width: 100%; padding: 3px 6px; font: inherit; font-size: 13px;
            background: transparent; color: inherit;
            border: 1px solid transparent; border-radius: 2px;
            box-sizing: border-box; resize: vertical;
        }
        .pt-glossary-cell-input.pt-glossary-cell-header { font-weight: 600; }
        .pt-glossary-cell-input:hover { border-color: var(--fm-risdoc-border-subtle, #d1d5db); }
        .pt-glossary-cell-input:focus {
            border-color: var(--fm-risdoc-border-focus, #2a5ac7);
            background: var(--fm-risdoc-bg-field, #fff); outline: none;
        }

        /* ── Static-content editor (dark-aware) ── */
        .pt-static-content-editor.pt-editable {
            margin: 0.8em 0; padding: 6px;
            background: var(--fm-risdoc-panel-bg, #f8f9fa);
            border: 1px solid var(--fm-risdoc-border-subtle, #e2e8f0);
            border-radius: 4px;
        }
        .pt-static-content__hdr { display: flex; gap: 6px; margin-bottom: 6px; align-items: center; }
        .pt-static-content__title-input {
            flex: 1; padding: 4px 8px; font: inherit; font-weight: 600;
            background: var(--fm-risdoc-bg-field, #fff); color: var(--fm-risdoc-text, inherit);
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1); border-radius: 3px;
            box-sizing: border-box;
        }
        .pt-static-content__level-sel {
            padding: 4px 6px; font: inherit;
            background: var(--fm-risdoc-btn-bg, #fff); color: var(--fm-risdoc-btn-fg, #334155);
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1); border-radius: 3px;
        }
        .pt-static-content__body-input {
            width: 100%; padding: 6px 8px; font: inherit; font-size: 13px; line-height: 1.5;
            background: var(--fm-risdoc-bg-field, #fff); color: var(--fm-risdoc-text, inherit);
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1); border-radius: 3px;
            box-sizing: border-box; resize: vertical;
        }
        .pt-static-content__body-input:focus,
        .pt-static-content__title-input:focus {
            border-color: var(--fm-risdoc-border-focus, #2a5ac7); outline: none;
        }
        .pt-static-content__meta {
            margin-top: 4px; font-size: 11px; color: var(--fm-risdoc-text-muted, #64748b);
        }

        /* ── Accordion editor (dark-aware) ── */
        .pt-accordion-editor.pt-editable {
            margin: 0.8em 0; padding: 6px;
            background: var(--fm-risdoc-panel-bg, #f8f9fa);
            border: 1px solid var(--fm-risdoc-border-subtle, #e2e8f0);
            border-radius: 4px;
        }
        .pt-accordion-toolbar { display: flex; gap: 8px; align-items: center; margin-bottom: 6px; flex-wrap: wrap; }
        .pt-accordion-btn {
            padding: 2px 10px; font-size: 12px;
            background: var(--fm-risdoc-btn-bg, #fff); color: var(--fm-risdoc-btn-fg, #334155);
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1); border-radius: 3px; cursor: pointer;
        }
        .pt-accordion-btn:hover { background: var(--fm-risdoc-btn-hover, #f1f5f9); }
        .pt-accordion-toggle { font-size: 12px; color: var(--fm-risdoc-text-muted, #64748b); display: inline-flex; gap: 4px; align-items: center; }
        .pt-accordion-master { font-weight: 600; color: var(--fm-risdoc-accent, #2a5ac7); }
        .pt-accordion-item-excl { cursor: pointer; flex: 0 0 auto; }
        .pt-accordion-item-edit {
            border: 1px solid var(--fm-risdoc-border, #cbd5e1); border-radius: 4px;
            margin-bottom: 6px; background: var(--fm-risdoc-card-bg, #fff);
        }
        /* Voce esclusa: attenuata + barrata nel titolo (esclusa da Anteprima/TeX). */
        .pt-accordion-item-edit--excluded { opacity: 0.5; }
        .pt-accordion-item-edit--excluded .pt-accordion-item-title { text-decoration: line-through; }
        .pt-accordion-item-summary {
            display: flex; gap: 6px; align-items: center; padding: 6px;
            background: var(--fm-risdoc-section-head-bg, #f1f5f9); cursor: pointer;
            border-radius: 4px 4px 0 0;
        }
        .pt-accordion-item-title {
            flex: 1; padding: 4px 8px; font: inherit; font-weight: 600;
            background: var(--fm-risdoc-bg-field, #fff); color: var(--fm-risdoc-text, inherit);
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1); border-radius: 3px; box-sizing: border-box;
        }
        .pt-accordion-item-rm {
            padding: 2px 8px; font-size: 14px; line-height: 1;
            background: var(--fm-risdoc-btn-danger-bg, #fee2e2); color: var(--fm-risdoc-btn-danger-fg, #b91c1c);
            border: 1px solid var(--fm-risdoc-error-border, #fecaca); border-radius: 3px; cursor: pointer;
        }
        .pt-accordion-item-body { padding: 6px; }
        .pt-accordion-item-body-input {
            width: 100%; padding: 6px 8px; font: inherit; font-size: 13px; line-height: 1.5;
            background: var(--fm-risdoc-bg-field, #fff); color: var(--fm-risdoc-text, inherit);
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1); border-radius: 3px;
            box-sizing: border-box; resize: vertical;
        }
        .pt-accordion-item-body-input:focus,
        .pt-accordion-item-title:focus {
            border-color: var(--fm-risdoc-border-focus, #2a5ac7); outline: none;
        }
        /* Phase 24.19 — checkbox renderMode toggle bar */
        .pt-checkbox-mode-bar {
            display: flex;
            gap: 6px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 6px;
            /* padding-destro extra: lascia spazio al 🗑 (.pt-block-delete,
               absolute top-right 22px) così l'ultimo bottone "In linea" non ci
               finisce sotto/accavallato. */
            padding: 4px 30px 4px 6px;
            background: var(--fm-risdoc-bg-field, #f8f9fa);
            border-radius: 3px;
            font-size: 11px;
        }
        .pt-checkbox-mode-label {
            color: var(--fm-risdoc-text-muted, #64748b);
            font-weight: 600;
        }
        /* spinge i controlli "Colonne" a destra nella mode-bar. */
        .pt-checkbox-mode-spacer { flex: 1 1 12px; min-width: 8px; }
        /* "1 colonna": forza UNA voce per riga (deprecato il flow inline
           precedente in cui le voci corte si affiancavano). */
        .pt-checkbox-items { display: flex; flex-direction: column; gap: 2px; align-items: stretch; }
        .pt-checkbox-items > .pt-checkbox-item { display: flex; width: 100%; }
        /* N colonne (2–5): column-count su display:block, impostato inline da JS
           (style.columnCount); voci block intere, niente spezzature. */
        .pt-checkbox-items--multicol { display: block; column-gap: 28px; }
        .pt-checkbox-items--multicol > .pt-checkbox-item { display: flex; width: auto; }
        .pt-checkbox-items--multicol > .pt-checkbox-item,
        .pt-checkbox-items--multicol > .pt-checkbox-group-head {
            break-inside: avoid; -webkit-column-break-inside: avoid;
        }
        .pt-checkbox-items--multicol > .pt-checkbox-group-head { break-after: avoid; }
        /* Casella numerica "Colonne" (1–5) nella mode-bar. */
        .pt-checkbox-cols-input {
            width: 46px;
            padding: 2px 4px;
            font-size: 11px;
            text-align: center;
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1);
            border-radius: 3px;
            background: var(--fm-risdoc-btn-bg, #fff);
            color: var(--fm-risdoc-btn-fg, inherit);
        }
        /* Barra sorgente JSON + scelta gruppo del Gruppo di checkbox. */
        .pt-checkbox-src-bar {
            display: flex;
            gap: 6px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 6px;
            padding: 4px 6px;
            background: var(--fm-risdoc-bg-field, #f8f9fa);
            border-radius: 3px;
            font-size: 11px;
        }
        .pt-checkbox-src-sel {
            font-size: 11px;
            padding: 2px 4px;
            max-width: 280px;
            background: var(--fm-risdoc-editor-bg, #fff);
            color: var(--fm-risdoc-text, #1e293b);
            border: 1px solid var(--fm-risdoc-border-subtle, #cbd5e1);
            border-radius: 3px;
        }
        .pt-checkbox-src-cascade { display: inline-flex; gap: 6px; flex-wrap: wrap; align-items: center; }
        /* Cascata sorgente nel popover cella + modal Gruppo. */
        .pt-table-cell-pop-cascade { display: flex; flex-direction: column; gap: 5px; }
        .pt-table-cell-pop-cascade select { width: 100%; }
        .cbm-cascade { display: flex; flex-direction: column; gap: 6px; }
        .cbm-cascade select { width: 100%; padding: 5px 8px; border-radius: 6px;
            border: 1px solid var(--fm-risdoc-border-subtle, #cbd5e1);
            background: var(--fm-risdoc-editor-bg, #fff); color: var(--fm-risdoc-text, #1e293b); }
        .cbm-hint { display: block; font-size: 12px; line-height: 1.4; padding: 6px 8px;
            border-radius: 6px; background: var(--fm-risdoc-warn-bg, #fef3c7); color: var(--fm-risdoc-warn-fg, #92400e); }
        .cbm-hint--ok { background: var(--fm-risdoc-info-bg, #dbeafe); color: var(--fm-risdoc-info-fg, #1e40af); }
        /* Intestazione di gruppo nella lista di checkbox (modalità Tutti). */
        .pt-checkbox-group-head {
            width: 100%;
            margin: 6px 0 2px;
            font-weight: 700;
            font-size: 12px;
            color: var(--fm-risdoc-accent, #2a5ac7);
        }
        .pt-checkbox-mode-btn {
            padding: 2px 8px;
            font-size: 11px;
            line-height: 1.5;
            white-space: nowrap;
            background: var(--fm-risdoc-btn-bg, #fff);
            color: var(--fm-risdoc-btn-fg, inherit);
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1);
            border-radius: 3px;
            cursor: pointer;
        }
        /* ADR-030 — bottoncino 🔗/📌 "valore per classe" inline (checkboxGroup
           mode-bar, textField, formCheckbox). BEM, niente stili inline. */
        .pt-binding-btn {
            border: none;
            background: transparent;
            cursor: pointer;
            font-size: 0.85em;
            opacity: .55;
            padding: 0 3px;
            line-height: 1;
        }
        .pt-binding-btn:hover:not([disabled]) { opacity: 1; }
        .pt-binding-btn[disabled] { cursor: default; opacity: .8; }
        /* ADR-031 — celle formula + riferimenti A1 */
        .pt-table-td { position: relative; }
        .pt-table-cell-ref {
            position: absolute; top: 0; left: 1px;
            font-size: 8px; line-height: 1; color: var(--fm-risdoc-text-muted, #94a3b8);
            opacity: .55; pointer-events: none; user-select: none; font-weight: 600;
            letter-spacing: .2px;
        }
        .pt-table-cell-formula {
            display: inline-block; min-width: 2ch; padding: 1px 4px;
            background: var(--fm-risdoc-formula-bg, rgba(59,130,246,.08));
            border-radius: 3px; font-variant-numeric: tabular-nums;
            color: var(--fm-risdoc-formula-fg, #1d4ed8); font-weight: 600;
        }
        .pt-table-cell-formula--err {
            background: rgba(239,68,68,.12); color: var(--fm-risdoc-error-fg, #b91c1c); font-weight: 600;
        }
        /* ADR-031 — editor formula inline (stile foglio di calcolo) + autocomplete */
        .pt-fcell {
            width: 100%; box-sizing: border-box; border: none; outline: none;
            padding: 2px 4px; background: var(--fm-risdoc-formula-bg, rgba(59,130,246,.08));
            color: var(--fm-risdoc-formula-fg, #1d4ed8); font-weight: 600;
            font-variant-numeric: tabular-nums; border-radius: 3px;
        }
        .pt-fcell:focus {
            background: var(--fm-risdoc-input-bg, #fff); color: var(--fm-risdoc-text, #1e293b);
            font-family: ui-monospace, monospace; font-weight: 500;
            box-shadow: inset 0 0 0 1px var(--fm-risdoc-accent, #3b82f6);
        }
        .pt-fcell--err { background: rgba(239,68,68,.12); color: var(--fm-risdoc-error-fg, #b91c1c); }
        .pt-fcell-ac {
            position: absolute; z-index: 1200; left: 4px; top: 100%;
            min-width: 150px; max-height: 200px; overflow-y: auto;
            background: var(--fm-risdoc-modal-bg, #fff);
            border: 1px solid var(--fm-risdoc-modal-border, #cbd5e1);
            border-radius: 5px; box-shadow: 0 6px 18px rgba(0,0,0,.18);
            font-family: ui-monospace, monospace; font-size: 12px;
        }
        .pt-fcell-ac__item { padding: 3px 9px; cursor: pointer; white-space: nowrap; color: var(--fm-risdoc-text, #1e293b); }
        .pt-fcell-ac__item.active, .pt-fcell-ac__item:hover {
            background: var(--fm-risdoc-accent, #3b82f6); color: #fff;
        }
        /* ADR-031 — barra formula (stile Excel) sopra la tabella */
        .pt-formula-bar {
            display: flex; align-items: center; gap: 6px;
            margin: 4px 0 2px; padding: 2px 6px;
            background: var(--fm-risdoc-elevated-bg, #f8fafc);
            border: 1px solid var(--fm-risdoc-border-subtle, #e5e5e5);
            border-radius: 5px;
        }
        .pt-formula-bar__fx {
            font-style: italic; font-weight: 700; font-family: Georgia, "Times New Roman", serif;
            color: var(--fm-risdoc-text-muted, #64748b);
            padding: 0 6px 0 2px; border-right: 1px solid var(--fm-risdoc-border-subtle, #e5e5e5);
            user-select: none;
        }
        .pt-formula-bar__ref {
            min-width: 36px; text-align: center; font-weight: 700; font-size: 12px;
            font-family: ui-monospace, monospace; color: var(--fm-risdoc-accent, #005A8D);
            user-select: none;
        }
        .pt-formula-bar__input {
            flex: 1 1 auto; min-width: 0; border: none; outline: none; background: transparent;
            font-family: ui-monospace, monospace; font-size: 13px;
            color: var(--fm-risdoc-text, #1e293b); padding: 4px;
        }
        .pt-formula-bar__input:focus {
            background: var(--fm-risdoc-input-bg, #fff); border-radius: 3px;
            box-shadow: inset 0 0 0 1px var(--fm-risdoc-accent, #3b82f6);
        }
        .pt-formula-bar__input:disabled { color: var(--fm-risdoc-text-muted, #94a3b8); cursor: default; }
        .pt-formula-bar__input::placeholder { color: var(--fm-risdoc-text-muted, #94a3b8); font-style: italic; }
        /* Phase 24.32 — boxed toggle nel sectionHeader */
        .pt-section-boxed-toggle {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-size: 11px;
            color: var(--fm-risdoc-text-muted, #64748b);
            cursor: pointer;
            padding: 2px 6px;
            border-radius: 3px;
            background: var(--fm-risdoc-bg-field, #f1f5f9);
        }
        .pt-section-boxed-toggle input[type="checkbox"] {
            margin: 0; accent-color: var(--fm-risdoc-accent, #2a5ac7);
        }
        .pt-section-boxed-toggle:hover { background: var(--fm-pt-checkbox-bg, #e2e8f0); }
        /* Toggle 👁 "in output" (incluso/escluso da Anteprima e TeX). */
        .pt-section-output-toggle {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-size: 11px;
            color: var(--fm-risdoc-text-muted, #64748b);
            cursor: pointer;
            padding: 2px 6px;
            border-radius: 3px;
            background: var(--fm-risdoc-bg-field, #f1f5f9);
        }
        .pt-section-output-toggle input[type="checkbox"] { margin: 0; accent-color: var(--fm-risdoc-accent, #2a5ac7); }
        .pt-section-output-toggle:hover { background: var(--fm-pt-checkbox-bg, #e2e8f0); }
        .pt-section-output-toggle.is-excluded {
            background: var(--fm-risdoc-error-bg, #fee2e2);
            color: var(--fm-risdoc-error-fg, #b91c1c);
        }
        /* Bottone "☑ tutte" — spunta tutte le checkbox dei componenti in sezione. */
        .pt-section-checkall-btn {
            font-size: 11px;
            padding: 2px 8px;
            cursor: pointer;
            border-radius: 3px;
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1);
            background: var(--fm-risdoc-btn-bg, #fff);
            color: var(--fm-risdoc-btn-fg, #334155);
        }
        .pt-section-checkall-btn:hover { background: var(--fm-risdoc-btn-hover, #f1f5f9); }
        /* 🗑 elimina-blocco su ogni componente PT (floating top-right). */
        .pt-editable { position: relative; }
        .pt-block-delete {
            position: absolute;
            top: 2px;
            right: 2px;
            z-index: 6;
            width: 22px;
            height: 22px;
            padding: 0;
            font-size: 12px;
            line-height: 1;
            cursor: pointer;
            border-radius: 4px;
            border: 1px solid var(--fm-risdoc-error-border, #fecaca);
            background: var(--fm-risdoc-btn-bg, rgba(255,255,255,.9));
            color: var(--fm-risdoc-error-fg, #b91c1c);
            opacity: 0.4;
            transition: opacity .15s ease, background .15s ease;
        }
        .pt-editable:hover > .pt-block-delete { opacity: 1; }
        .pt-block-delete:hover { background: var(--fm-risdoc-error-bg, #fee2e2); }
        /* Sezione esclusa: attenua tutto il contenuto sotto l'header escluso. */
        .pt-section-header-container--excluded { opacity: 0.55; }
        .pt-section-header-container--excluded .pt-section-title-input { text-decoration: line-through; }
        .pt-checkbox-mode-btn.active {
            background: var(--fm-risdoc-btn-active-bg, #2a5ac7);
            color: var(--fm-risdoc-btn-active-fg, #fff);
            border-color: var(--fm-risdoc-btn-active-bg, #2a5ac7);
        }

        /* Phase 24.2-4 — Inline widgets (select, textField, formCheckbox) */
        .pt-select-container, .pt-textfield-container, .pt-form-checkbox-container {
            display: flex;
            align-items: center;
            gap: 6px;
            margin: 0.4em 0;
            padding: 4px 8px;
            background: var(--fm-risdoc-bg-field, #f8f9fa);
            border-left: 3px solid var(--fm-risdoc-accent, #005A8D);
            border-radius: 3px;
        }
        .pt-inline-label-input {
            font-weight: 600;
            font-size: 14px;
            padding: 2px 6px;
            background: transparent;
            color: inherit;
            border: 1px solid transparent;
            border-radius: 3px;
            min-width: 100px;
            max-width: 280px;
        }
        .pt-inline-label-input:hover {
            border-color: var(--fm-risdoc-border-subtle, #d1d5db);
            background: var(--fm-risdoc-editor-bg, #fff);
        }
        .pt-inline-label-input:focus {
            border-color: var(--fm-risdoc-border-focus, #2a5ac7);
            background: var(--fm-risdoc-editor-bg, #fff);
            outline: none;
        }
        .pt-inline-sep { color: var(--fm-risdoc-text-muted, #64748b); user-select: none; }

        .pt-select {
            padding: 3px 8px;
            font-size: 14px;
            background: var(--fm-risdoc-btn-bg, #fff);
            color: var(--fm-risdoc-btn-fg, inherit);
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1);
            border-radius: 3px;
        }
        .pt-text-field {
            padding: 3px 8px;
            font-size: 14px;
            background: var(--fm-risdoc-btn-bg, #fff);
            color: var(--fm-risdoc-btn-fg, inherit);
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1);
            border-radius: 3px;
            min-width: 120px;
        }
        .pt-form-checkbox-container { border-left-color: var(--fm-pt-checkbox-accent, #10b981); }
        .pt-form-checkbox-container input[type="checkbox"] { flex-shrink: 0; }

        /* Phase 24.10c — Select options popover */
        .pt-select-container { position: relative; }
        .pt-select-edit-btn {
            padding: 2px 8px;
            background: var(--fm-risdoc-btn-bg, #fff);
            color: var(--fm-risdoc-btn-fg, inherit);
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1);
            border-radius: 3px;
            cursor: pointer;
            font-size: 13px;
        }
        .pt-select-edit-btn:hover { background: var(--fm-risdoc-btn-hover, #f1f5f9); }
        .pt-select-popover {
            position: absolute;
            top: 100%;
            left: 0;
            z-index: 20;
            margin-top: 4px;
            padding: 10px 12px;
            min-width: 360px;
            background: var(--fm-risdoc-modal-bg, #fff);
            color: var(--fm-risdoc-text, #1e293b);
            border: 1px solid var(--fm-risdoc-modal-border, #e5e5e5);
            border-radius: 6px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        }
        .pt-select-popover-title {
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 13px;
            color: var(--fm-risdoc-text-strong, #1e293b);
        }
        .pt-select-popover-list {
            display: flex;
            flex-direction: column;
            gap: 4px;
            margin-bottom: 8px;
            max-height: 240px;
            overflow-y: auto;
        }
        .pt-select-popover-row {
            display: flex;
            gap: 4px;
            align-items: center;
        }
        .pt-select-popover-input {
            flex: 1;
            padding: 4px 6px;
            font-size: 13px;
            background: var(--fm-risdoc-editor-bg, #fff);
            color: var(--fm-risdoc-text, #1e293b);
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1);
            border-radius: 3px;
        }
        .pt-select-popover-rm {
            padding: 2px 6px;
            font-size: 14px;
            background: var(--fm-risdoc-error-bg, #fee2e2);
            color: var(--fm-risdoc-error-fg, #b91c1c);
            border: 1px solid var(--fm-risdoc-error-border, #fca5a5);
            border-radius: 3px;
            cursor: pointer;
            width: 28px;
        }
        .pt-select-popover-add, .pt-select-popover-close {
            padding: 4px 12px;
            font-size: 12px;
            background: var(--fm-risdoc-btn-bg, #fff);
            color: var(--fm-risdoc-btn-fg, inherit);
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1);
            border-radius: 3px;
            cursor: pointer;
            margin-right: 6px;
        }
        .pt-select-popover-add:hover, .pt-select-popover-close:hover {
            background: var(--fm-risdoc-btn-hover, #f1f5f9);
        }
        .pt-select-popover-add {
            border-style: dashed;
            border-color: var(--fm-pt-field-border, #93c5fd);
            color: var(--fm-pt-field-fg, #1e40af);
        }
        /* Phase 24.13 — popover sections + source mode switcher */
        .pt-select-popover-section { margin-bottom: 12px; }
        .pt-select-popover-sublabel {
            font-size: 11px;
            font-weight: 600;
            color: var(--fm-risdoc-text-muted, #64748b);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        .pt-select-src-btn {
            padding: 4px 10px;
            font-size: 12px;
            background: var(--fm-risdoc-btn-bg, #fff);
            color: var(--fm-risdoc-btn-fg, inherit);
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1);
            border-radius: 3px;
            cursor: pointer;
        }
        .pt-select-src-btn:hover { background: var(--fm-risdoc-btn-hover, #f1f5f9); }
        .pt-select-src-btn.active {
            background: var(--fm-risdoc-btn-active-bg, #2a5ac7);
            color: var(--fm-risdoc-btn-active-fg, #fff);
            border-color: var(--fm-risdoc-btn-active-bg, #2a5ac7);
        }
        .pt-select-src-path {
            width: 100%;
            margin-top: 6px;
        }
        .pt-select-popover-help {
            font-size: 11px;
            color: var(--fm-risdoc-text-muted, #64748b);
            margin-top: 6px;
            line-height: 1.5;
        }
        .pt-select-popover-help code {
            background: var(--fm-risdoc-code-inline-bg, #f1f5f9);
            color: var(--fm-risdoc-text, inherit);
            padding: 1px 4px;
            border-radius: 2px;
            font-size: 10px;
        }
        .pt-select-popover-empty {
            font-size: 12px;
            color: var(--fm-risdoc-text-muted, #64748b);
            font-style: italic;
            padding: 4px 8px;
            text-align: center;
        }

        /* Phase 24.5 — Section header */
        .pt-section-header-container {
            margin: 1em 0 0.5em;
            padding: 6px 10px;
            background: var(--fm-risdoc-section-head-bg, rgb(219, 228, 240));
            border-bottom: 2px solid var(--fm-risdoc-section-head-border, #8db070);
        }
        .pt-section-header {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .pt-section-title-input {
            flex: 1;
            font-weight: 700;
            font-size: 1.1em;
            padding: 4px 8px;
            background: transparent;
            color: var(--fm-risdoc-accent, #005A8D);
            border: 1px solid transparent;
            border-radius: 3px;
        }
        .pt-section-title-input:hover {
            border-color: var(--fm-risdoc-border-subtle, #d1d5db);
            background: var(--fm-risdoc-editor-bg, #fff);
        }
        .pt-section-title-input:focus {
            border-color: var(--fm-risdoc-border-focus, #2a5ac7);
            background: var(--fm-risdoc-editor-bg, #fff);
            outline: none;
        }
        .pt-section-header.level-1 .pt-section-title-input { font-size: 1.4em; }
        .pt-section-header.level-2 .pt-section-title-input { font-size: 1.2em; }
        .pt-section-header.level-3 .pt-section-title-input { font-size: 1.05em; }
        .pt-section-header.level-4 .pt-section-title-input { font-size: 1em; }
        .pt-section-level-sel {
            padding: 3px 6px;
            font-size: 11px;
            background: var(--fm-risdoc-btn-bg, #fff);
            color: var(--fm-risdoc-btn-fg, inherit);
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1);
            border-radius: 3px;
        }
        .pt-section-selectors {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            margin-top: 4px;
            padding-left: 8px;
        }

        /* Phase 22.7 — Source mode */
        .source-wrap {
            padding: 0;
            background: var(--fm-risdoc-code-bg, #1e293b);
            color: var(--fm-risdoc-code-fg, #e2e8f0);
        }
        .source-mount {
            min-height: 380px;
            max-height: 60vh;
            overflow: auto;
        }
        .source-error {
            padding: 8px 12px;
            background: var(--fm-risdoc-error-border, #7f1d1d);
            color: var(--fm-risdoc-error-bg, #fef2f2);
            font-size: 12px;
            font-family: 'SF Mono', Consolas, monospace;
            border-top: 1px solid var(--fm-risdoc-error-fg, #b91c1c);
        }
        .source-ok {
            padding: 6px 12px;
            background: var(--fm-risdoc-success-fg, #064e3b);
            color: var(--fm-risdoc-success-bg, #a7f3d0);
            font-size: 12px;
            font-family: 'SF Mono', Consolas, monospace;
            border-top: 1px solid var(--fm-risdoc-success-fg, #047857);
        }
        .mode-toggle {
            margin-left: auto;
            padding: 3px 12px;
            background: var(--fm-pt-field-bg, #eef2ff);
            border: 1px solid var(--fm-pt-field-border, #a5b4fc);
            color: var(--fm-pt-field-fg, #3730a3);
            font-weight: 600;
            font-size: 12px;
            border-radius: 12px;
            cursor: pointer;
        }
        .mode-toggle:hover { filter: brightness(0.95); }
        .mode-toggle.is-source {
            background: var(--fm-risdoc-code-bg, #1e293b);
            color: var(--fm-risdoc-code-fg, #e2e8f0);
            border-color: var(--fm-risdoc-border, #475569);
        }

        /* ── Modal ── */
        .modal-backdrop {
            /* position:fixed (non absolute): il modal si centra nel VIEWPORT e
               NON viene tagliato dall'overflow:hidden della card sezione quando
               questa è piccola/stretta (prima si vedeva solo l'intestazione). */
            position: fixed;
            inset: 0;
            background: var(--fm-risdoc-overlay-bg, rgba(15, 23, 42, 0.6));
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1em;
        }
        .modal {
            background: var(--fm-risdoc-modal-bg, #fff);
            color: var(--fm-risdoc-text, #1e293b);
            border-radius: 6px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.35);
            width: 100%;
            max-width: 520px;
            max-height: 90%;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .modal-head {
            padding: 10px 16px;
            border-bottom: 1px solid var(--fm-risdoc-modal-border, #e5e5e5);
            background: var(--fm-risdoc-modal-head-bg, #f8fafc);
            font-size: 15px;
            font-weight: 600;
            color: var(--fm-risdoc-text-strong, #1e293b);
        }
        .modal-body {
            padding: 14px 16px;
            overflow-y: auto;
            flex: 1;
        }
        .modal-foot {
            padding: 10px 16px;
            border-top: 1px solid var(--fm-risdoc-modal-border, #e5e5e5);
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            background: var(--fm-risdoc-modal-head-bg, #f8fafc);
        }
        .modal button {
            padding: 6px 14px;
            font-size: 13px;
            border-radius: 3px;
            cursor: pointer;
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1);
            background: var(--fm-risdoc-btn-bg, #fff);
            color: var(--fm-risdoc-btn-fg, #334155);
        }
        .modal button.primary {
            background: var(--fm-risdoc-btn-active-bg, #2a5ac7);
            color: var(--fm-risdoc-btn-active-fg, #fff);
            border-color: var(--fm-risdoc-btn-active-bg, #2a5ac7);
        }
        .modal button.primary:hover { filter: brightness(1.1); }
        .modal button:hover { background: var(--fm-risdoc-btn-hover, #f1f5f9); }
        .modal button[disabled] { opacity: 0.5; cursor: not-allowed; }
        .modal label {
            display: block;
            font-size: 13px;
            color: var(--fm-risdoc-text-muted, #475569);
            margin-bottom: 4px;
        }
        .modal input[type="text"], .modal textarea {
            width: 100%;
            padding: 6px 8px;
            font-size: 14px;
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1);
            background: var(--fm-risdoc-editor-bg, #fff);
            color: var(--fm-risdoc-text, #1e293b);
            border-radius: 3px;
            font-family: inherit;
            box-sizing: border-box;
        }
        .modal textarea {
            font-family: 'SF Mono', Consolas, monospace;
            font-size: 13px;
            resize: vertical;
            min-height: 100px;
        }
        .modal .validation-error {
            color: var(--fm-risdoc-error-fg, #b91c1c);
            font-size: 12px;
            margin-top: 4px;
        }
        .modal section { margin-bottom: 14px; }
        .modal section:last-child { margin-bottom: 0; }
        .modal h4 {
            font-size: 12px;
            color: var(--fm-risdoc-text-muted, #64748b);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 6px;
            font-weight: 600;
        }

        .chip-list { display: flex; flex-wrap: wrap; gap: 6px; }
        .chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            background: var(--fm-pt-field-bg, #e0eaff);
            color: var(--fm-pt-field-fg, #1e40af);
            border: 1px solid var(--fm-pt-field-border, #93c5fd);
            border-radius: 14px;
            font-size: 13px;
            cursor: pointer;
            transition: filter 0.15s;
        }
        .chip:hover { filter: brightness(0.95); }

        .preset-list { display: flex; flex-direction: column; gap: 4px; }
        .preset {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 6px 10px;
            background: var(--fm-risdoc-elevated-bg, #f8fafc);
            border: 1px solid var(--fm-risdoc-border-subtle, #e2e8f0);
            color: var(--fm-risdoc-text, #1e293b);
            border-radius: 3px;
            font-size: 13px;
            cursor: pointer;
        }
        .preset:hover {
            background: var(--fm-pt-field-bg, #eff6ff);
            border-color: var(--fm-pt-field-border, #93c5fd);
        }
        .preset-preview {
            font-size: 11px;
            color: var(--fm-risdoc-text-muted, #64748b);
            font-family: monospace;
        }

        .item-list { display: flex; flex-direction: column; gap: 6px; }
        .item-row {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px;
            background: var(--fm-risdoc-elevated-bg, #f8fafc);
            border: 1px solid var(--fm-risdoc-border-subtle, #e2e8f0);
            border-radius: 3px;
        }
        .item-row input[type="text"] { flex: 1; }
        .item-row .state-toggle {
            padding: 4px 8px;
            background: var(--fm-risdoc-btn-bg, #fff);
            color: var(--fm-risdoc-btn-fg, #334155);
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1);
            border-radius: 3px;
            cursor: pointer;
            font-size: 13px;
            min-width: 60px;
        }
        .item-row .state-toggle.checked {
            background: var(--fm-risdoc-success-bg, #d1fae5);
            border-color: var(--fm-pt-checkbox-accent, #10b981);
            color: var(--fm-risdoc-success-fg, #065f46);
        }
        .item-row .remove-btn {
            padding: 4px 8px;
            background: var(--fm-risdoc-btn-bg, #fff);
            border: 1px solid var(--fm-risdoc-error-border, #fca5a5);
            border-radius: 3px;
            color: var(--fm-risdoc-error-fg, #b91c1c);
            cursor: pointer;
        }
        .item-row .remove-btn:hover { background: var(--fm-risdoc-error-bg, #fef2f2); }
        .add-btn {
            padding: 6px;
            background: var(--fm-risdoc-elevated-bg, #f8fafc);
            border: 1px dashed var(--fm-risdoc-btn-border, #cbd5e1);
            border-radius: 3px;
            color: var(--fm-risdoc-text-muted, #475569);
            cursor: pointer;
            font-size: 13px;
            text-align: center;
        }
        .add-btn:hover {
            background: var(--fm-pt-field-bg, #eff6ff);
            border-color: var(--fm-pt-field-border, #93c5fd);
            color: var(--fm-pt-field-fg, #1e40af);
        }
    `;

    constructor() {
        super();
        this.value = [];
        this.fields = [];
        this.readonly = false;
        this.compact = false;
        this._ready = false;
        this._modal = null;
        this._mode = "rich"; // Phase 22.7
        this._sourceError = "";
        this._editor = null;
        this._emitTimer = null;
        this._cm = null; // CodeMirror view
    }

    firstUpdated() {
        const mount = this.renderRoot.querySelector(".editor");
        const initial = this._normalizeValue(this.value);
        this._editor = new Editor({
            element: mount,
            extensions: [
                StarterKit.configure({
                    heading: false, blockquote: false, codeBlock: false,
                    horizontalRule: false,
                    // Liste ABILITATE (elenchi puntati/numerati + annidamento).
                    // NB: niente opzioni custom (keepMarks non è valida su
                    // bulletList/orderedList in StarterKit → rompeva l'estensione).
                }),
                TextAlign.configure({ types: ["paragraph"] }),
                ListStyle,
                ListTabKeymap,
                CarryAttributes,
                FieldRef,
                CheckboxGroup,
                RawTex,
                PtTable,
                PtSelect,
                PtTextField,
                PtFormCheckbox,
                PtSectionHeader,
                PtGlossaryTable,
                PtStaticContent,
                PtAccordion,
                PtLinkListPdf,
                PtCitationNorma,
            ],
            content: ptToPmDoc(initial),
            editable: !this.readonly,
            editorProps: {
                // Fix crash "Applying a mismatched transaction": il drop di un
                // NodeView atom (componenti PT, draggable) tra/within gli
                // editor-sezione multipli rompe ProseMirror. Blocchiamo il drop
                // di contenuto atom (il riordino componenti via DnD non è
                // affidabile); il drag-drop di solo TESTO resta consentito.
                handleDrop: (view, event, slice) => {
                    let hasAtom = false;
                    try { slice.content.descendants((n) => { if (n.isAtom) hasAtom = true; }); } catch (_) { hasAtom = true; }
                    if (hasAtom) { event.preventDefault(); return true; }
                    return false;
                },
            },
            onUpdate: () => this._scheduleEmit(),
            onSelectionUpdate: () => this.requestUpdate(),
            onFocus: () => {
                // Phase 24.10b — registra questo editor come "focused"
                // per la toolbar globale condivisa.
                if (this._blurTimer) { clearTimeout(this._blurTimer); this._blurTimer = null; }
                window.FM?.pt?.setFocused(this);
            },
            onBlur: () => {
                // Phase 25.E12 — quando l'editor perde focus, sgancia con
                // delay ~180ms per permettere ai click su button toolbar di
                // registrarsi (i button hanno mousedown.preventDefault per
                // preservare la selezione, ma il blur scatta lo stesso).
                // Se nel frattempo torna focus su un altro editor o questo,
                // il timer viene cancellato.
                if (this._blurTimer) clearTimeout(this._blurTimer);
                this._blurTimer = setTimeout(() => {
                    if (window.FM?.pt?.currentEditor === this) {
                        window.FM?.pt?.setFocused(null);
                    }
                    this._blurTimer = null;
                }, 180);
            },
        });
        this._ready = true;
        // Phase 22.3d — listener per NodeView edit events (click su chip
        // fieldRef / callout rawTex → apre modal in edit-mode).
        this.renderRoot.addEventListener("fm-pt-node-edit", (e) => {
            const { type, pos, attrs } = e.detail || {};
            if (type === "fieldRef") {
                this._modal = { type: "fieldRef", name: attrs?.name || "", error: "", _editPos: pos };
            } else if (type === "rawTex") {
                this._modal = { type: "rawTex", content: attrs?.content || "", _editPos: pos };
            }
            // checkboxGroup: edit inline via NodeView, no modal.
        });
        // Emit iniziale così il consumer riceve lo stato (per live preview)
        queueMicrotask(() => this._emit());
        this._reflectContainer(this._normalizeValue(this.value));
    }

    /**
     * BUG1 — una sezione "container" ha SOLO l'header (le sottosezioni sono
     * card sibling separate, vedi fm-risdoc-pt-section). Per non riservare il
     * min-height (box vuoto alto) riflettiamo l'attributo host data-container-empty.
     * Rilevato dal value PT perché ProseMirror aggiunge sempre un <p> finale
     * vuoto (trailing break) dopo l'header atom → impossibile via CSS :only-child.
     */
    _isContainerSection(pt) {
        if (!Array.isArray(pt) || pt.length === 0) return false;
        let header = 0;
        for (const b of pt) {
            if (!b || typeof b !== "object") return false;
            if (b._type === "sectionHeader") { header++; continue; }
            // qualsiasi altro blocco con contenuto reale → non è un container
            if (b._type === "block") {
                const empty = !Array.isArray(b.children)
                    || b.children.every((c) => !((c && typeof c.text === "string" ? c.text : "")).trim());
                if (empty) continue; // paragrafo vuoto: ignora
            }
            return false;
        }
        return header > 0;
    }

    _reflectContainer(pt) {
        this.toggleAttribute("data-container-empty", this._isContainerSection(pt));
    }

    updated(changed) {
        if (!this._editor) return;
        if (changed.has("value") && !this._suppressSetContent) {
            const pt = this._normalizeValue(this.value);
            this._editor.commands.setContent(ptToPmDoc(pt), { emitUpdate: false });
            this._reflectContainer(pt);
            queueMicrotask(() => this._emit());
        }
        this._suppressSetContent = false;
        if (changed.has("readonly")) {
            this._editor.setEditable(!this.readonly);
        }
    }

    connectedCallback() {
        super.connectedCallback();
        // Reparent durante il riordino sezioni (↑/↓) = detach+reattach SINCRONI:
        // il disconnect ha SCHEDULATO la distruzione di ProseMirror → qui la
        // annulliamo così editor e contenuto sopravvivono. Senza questo,
        // firstUpdated è one-shot e la card restava VUOTA dopo lo spostamento
        // (ProseMirror distrutto, mai ricreato → 506→438 item persi nel repro).
        if (this._destroyTimer) { clearTimeout(this._destroyTimer); this._destroyTimer = null; }
    }

    disconnectedCallback() {
        super.disconnectedCallback();
        if (this._emitTimer) clearTimeout(this._emitTimer);
        if (this._blurTimer) { clearTimeout(this._blurTimer); this._blurTimer = null; }
        // Se l'editor disconnesso era il currentEditor, sgancia.
        if (window.FM?.pt?.currentEditor === this) window.FM?.pt?.setFocused(null);
        // Distruzione DIFFERITA di ProseMirror/CodeMirror: un reparent (riordino
        // card) ri-connette nello STESSO task → connectedCallback annulla questo
        // timer e l'editor sopravvive. Se la card è davvero rimossa (delete),
        // il timer scatta e libera le risorse (niente leak).
        if (this._destroyTimer) clearTimeout(this._destroyTimer);
        this._destroyTimer = setTimeout(() => {
            this._destroyTimer = null;
            if (this._editor) { this._editor.destroy(); this._editor = null; }
            if (this._cm) { this._cm.destroy(); this._cm = null; }
        }, 0);
    }

    /** Phase 22.7 — toggle mode rich/source. */
    async _toggleMode() {
        if (this._mode === "rich") {
            // Rich → Source: serialize PT corrente, init CodeMirror
            const pt = this.getPt();
            this._sourceError = "";
            this._mode = "source";
            await this.updateComplete;
            this._initCodeMirror(JSON.stringify(pt, null, 2));
        } else {
            // Source → Rich: parse + validate + setContent
            const raw = this._cm?.state.doc.toString() ?? "[]";
            let parsed;
            try { parsed = JSON.parse(raw); }
            catch (e) {
                this._sourceError = `JSON parse error: ${e.message}`;
                return; // stay in source mode
            }
            const vr = validatePtShape(parsed);
            if (!vr.valid) {
                this._sourceError = `PT shape error: ${vr.error}`;
                return;
            }
            this._sourceError = "";
            // Destroy CM; switch to rich; re-populate editor
            if (this._cm) { this._cm.destroy(); this._cm = null; }
            this._mode = "rich";
            await this.updateComplete;
            if (this._editor) {
                this._editor.commands.setContent(ptToPmDoc(parsed), { emitUpdate: false });
                queueMicrotask(() => this._emit());
            }
        }
    }

    _initCodeMirror(initialText) {
        const mount = this.renderRoot.querySelector(".source-mount");
        if (!mount) return;
        const onUpdate = EditorView.updateListener.of((u) => {
            if (!u.docChanged) return;
            // Live validation: parse JSON + shape check → update _sourceError
            const txt = u.state.doc.toString();
            if (!txt.trim()) { this._sourceError = ""; return; }
            try {
                const parsed = JSON.parse(txt);
                const vr = validatePtShape(parsed);
                this._sourceError = vr.valid ? "" : vr.error;
            } catch (e) {
                this._sourceError = e.message;
            }
            this.requestUpdate();
        });
        // Phase 23 — tema CodeMirror segue body.fm-dark dinamico. Il cambio
        // mode richiede ri-init dell'editor (rare, side effect accettabile).
        const isDark = document.body.classList.contains("fm-dark");
        const state = EditorState.create({
            doc: initialText,
            extensions: [
                lineNumbers(),
                highlightActiveLine(),
                history(),
                jsonLang(),
                linter(jsonParseLinter()),
                lintGutter(),
                keymap.of([...defaultKeymap, ...historyKeymap]),
                onUpdate,
                ...(isDark ? [oneDark] : []),
                EditorView.theme({
                    "&": { fontSize: "13px", height: "380px" },
                    ".cm-scroller": { fontFamily: "'SF Mono', Consolas, monospace" },
                }),
            ],
        });
        this._cm = new EditorView({ state, parent: mount });
    }

    _normalizeValue(v) {
        if (Array.isArray(v)) return v;
        if (typeof v === "string") {
            try { const parsed = JSON.parse(v); return Array.isArray(parsed) ? parsed : []; }
            catch { return []; }
        }
        return [];
    }

    _scheduleEmit() {
        if (this._emitTimer) clearTimeout(this._emitTimer);
        this._emitTimer = setTimeout(() => {
            this._emitTimer = null;
            this._emit();
        }, 150);
    }

    _emit() {
        if (!this._editor) return;
        const pt = pmDocToPt(this._editor.getJSON());
        this._suppressSetContent = true;
        this.value = pt;
        this._reflectContainer(pt);
        this.dispatchEvent(new CustomEvent("pt-change", {
            detail: { value: pt },
            bubbles: true,
            composed: true,
        }));
    }

    getPt() {
        if (!this._editor) return this._normalizeValue(this.value);
        return pmDocToPt(this._editor.getJSON());
    }

    // ── toolbar handlers ──

    _toggle(cmd) {
        if (!this._editor) return;
        this._editor.chain().focus()[cmd]().run();
    }

    _isActive(mark) {
        return this._editor?.isActive(mark) ? "is-active" : "";
    }

    _openFieldModal()    { this._modal = { type: "fieldRef", name: this.fields?.[0] || "", error: "" }; }
    _openCheckboxModal() {
        this._modal = { type: "checkboxGroup", items: [{ state: "_", label: "" }], _srcGroups: null, _dataset: "", _src: null, _loadedOpts: null, _optGroups: null };
        getOptionsSourcesCatalog().then((cat) => {
            if (this._modal?.type === "checkboxGroup") { this._modal._srcGroups = ptGroupedSources(cat); this.requestUpdate(); }
        });
    }
    _openRawTexModal()   { this._modal = { type: "rawTex", content: "" }; }
    _openTableModal()    {
        this._modal = {
            type: "ptTable",
            columns: ["Colonna 1", "Colonna 2", "Colonna 3"],
            rows: [
                ["", "", ""],
                ["", "", ""],
            ],
            caption: "",
            widthMode: "auto",     // "auto" (adatta) | "full" (tutta la pagina)
            colWidths: [],         // % per-colonna (solo full); vuoto → uguali
        };
    }

    _closeModal() { this._modal = null; }

    _commitFieldRef(name) {
        const pos = this._modal?._editPos;
        if (this._editor) {
            if (typeof pos === "number") {
                // Edit-mode: sostituisci attrs del node esistente
                const tr = this._editor.state.tr.setNodeMarkup(pos, null, { name });
                this._editor.view.dispatch(tr);
                this._editor.view.focus();
            } else {
                this._editor.chain().focus().insertFieldRef(name).run();
            }
        }
        this._closeModal();
    }

    _commitCheckboxGroup(items, optionsSource = null) {
        // checkboxGroup è sempre insert (non edit-mode: l'inline NodeView
        // gestisce modifiche locali). Se futuro servirà "replace all" → pos-aware.
        // optionsSource (cartella) → gruppo collegato/dinamico.
        this._editor?.chain().focus().insertCheckboxGroup(items, "all", optionsSource).run();
        this._closeModal();
    }

    _commitRawTex(content) {
        const pos = this._modal?._editPos;
        if (this._editor) {
            if (typeof pos === "number") {
                const tr = this._editor.state.tr.setNodeMarkup(pos, null, { content });
                this._editor.view.dispatch(tr);
                this._editor.view.focus();
            } else {
                this._editor.chain().focus().insertRawTex(content).run();
            }
        }
        this._closeModal();
    }

    _commitTable(columns, rows, caption, widthMode, colWidths) {
        this._editor?.chain().focus()
            .insertPtTable(columns, rows, caption, widthMode, colWidths).run();
        this._closeModal();
    }

    /**
     * Phase 24.10 — inserisce un block atomico alla posizione corrente
     * del cursor (selection attiva). Preservata grazie a preventDefault
     * su mousedown del toolbar (vedi render). Se editor non focus →
     * focus porta cursor a fine doc (fallback accettabile).
     */
    _insertAtCursor(type, args) {
        if (!this._editor) return;
        const chain = this._editor.chain().focus();
        const fn = {
            ptSectionHeader: "insertPtSectionHeader",
            ptTextField:     "insertPtTextField",
            ptSelect:        "insertPtSelect",
            ptFormCheckbox:  "insertPtFormCheckbox",
            ptGlossaryTable: "insertPtGlossaryTable",
            ptStaticContent: "insertPtStaticContent",
            ptAccordion:     "insertPtAccordion",
            ptLinkListPdf:   "insertPtLinkListPdf",
            ptCitationNorma: "insertPtCitationNorma",
        }[type];
        if (!fn || typeof chain[fn] !== "function") return;
        chain[fn](...args).run();
    }

    // ─────────────────────────────────────────────────────────────
    // Phase 24.10b — Public API per toolbar globale sticky
    // (fm-risdoc-pt-toolbar delega i comandi via window.FM.pt.currentEditor)
    // ─────────────────────────────────────────────────────────────

    /** Focus il Tiptap editor (ripristina cursor se disponibile). */
    focusEditor() { this._editor?.chain().focus().run(); }

    /** Toggle mark: "bold"/"italic"/"underline"/"code". */
    toggleMark(name) {
        // G23 Sprint 10b — input-aware B/I/U/code: se il focus è in un
        // textarea/input interno al NodeView (es. staticContent body HTML,
        // cella glossario/tabella), applica wrap tag HTML sulla selezione
        // dell'input invece del ProseMirror mark (che opererebbe sul doc,
        // non sull'input). Così i 3 formatting funzionano "in ogni zona".
        const active = this.renderRoot?.activeElement;
        // Cella tabella RICH (contenteditable) → formattazione live visibile.
        if (active && active.isContentEditable
            && active.classList?.contains("pt-table-cell-rich")
            && this.renderRoot.contains(active)) {
            this._applyInlineToContentEditable(active, name);
            return;
        }
        if (active && (active.tagName === "TEXTAREA" || active.tagName === "INPUT")
            && this.renderRoot.contains(active)) {
            this._applyInlineToInput(active, name);
            return;
        }
        const cmd = {
            bold: "toggleBold", italic: "toggleItalic",
            underline: "toggleUnderline", code: "toggleCode",
        }[name];
        if (cmd) this._toggle(cmd);
    }

    /**
     * Formattazione inline su un contenteditable (cella tabella rich): B/I/U via
     * execCommand (toggle, output normalizzato a strong/em/u al commit), code via
     * wrap manuale del range (no execCommand per code).
     */
    _applyInlineToContentEditable(el, name) {
        el.focus();
        try { document.execCommand("styleWithCSS", false, false); } catch { /* noop */ }
        const cmd = { bold: "bold", italic: "italic", underline: "underline" }[name];
        if (cmd) {
            try { document.execCommand(cmd); } catch { /* noop */ }
        } else if (name === "code") {
            this._wrapSelectionTag(el, "code");
        }
        // commit: marca dirty (l'effettivo salvataggio avviene al blur).
        el.dispatchEvent(new Event("input", { bubbles: true }));
    }

    /** Wrap della selezione corrente in <tag> (per `code`, senza execCommand).
     *  Shadow-aware: usa la selezione dello shadowRoot se disponibile. */
    _wrapSelectionTag(el, tag) {
        const sel = (this.renderRoot.getSelection && this.renderRoot.getSelection())
            || document.getSelection();
        if (!sel || sel.rangeCount === 0) return;
        const range = sel.getRangeAt(0);
        if (range.collapsed) return;
        try {
            const wrapper = document.createElement(tag);
            wrapper.appendChild(range.extractContents());
            range.insertNode(wrapper);
            sel.removeAllRanges();
            const r2 = document.createRange();
            r2.selectNodeContents(wrapper);
            sel.addRange(r2);
        } catch { /* selezione che attraversa più nodi → ignora */ }
    }

    /** Allineamento paragrafo (left/center/right/justify) — stile Google Docs. */
    setAlign(align) {
        if (!this._editor) return;
        this._editor.chain().focus().setTextAlign(align).run();
    }

    /** True se il paragrafo corrente ha l'allineamento `align`. */
    isActiveAlign(align) {
        return !!this._editor?.isActive({ textAlign: align });
    }

    /**
     * Inserisce/commuta una lista (elenco) con variante. `kind` ∈
     * ul|ul-arrow|ul-star|ol|ol-Alpha|ol-alpha|ol-Roman|ol-zero|ol-paren|
     * ol-Alpha-paren|ol-alpha-paren|ol-Roman-paren|ol-zero-paren.
     * Il preset listStyle è lo stesso di checkin-handlers (CSS) e mappato a
     * label enumitem in PtToTex.php.
     */
    setList(kind) {
        if (!this._editor) return;
        const PRESET = {
            "ul": "", "ul-arrow": "arrow-bullet", "ul-star": "star-circle",
            "ol": "", "ol-Alpha": "alpha-decimal", "ol-alpha": "lower-alpha-roman",
            "ol-Roman": "roman-alpha", "ol-zero": "decimal-zero",
            "ol-paren": "paren", "ol-Alpha-paren": "alpha-paren",
            "ol-alpha-paren": "lower-alpha-paren", "ol-Roman-paren": "roman-paren",
            "ol-zero-paren": "decimal-zero-paren",
        };
        const isOrdered = kind.startsWith("ol");
        const listStyle = PRESET[kind] ?? "";
        const nodeType = isOrdered ? "orderedList" : "bulletList";
        const toggle = isOrdered ? "toggleOrderedList" : "toggleBulletList";
        // Catena ATOMICA: toggle lista + set variante nella stessa transazione
        // (separare in due chain perdeva l'attr perché la selezione si spostava).
        this._editor.chain().focus()[toggle]().updateAttributes(nodeType, { listStyle }).run();
    }

    /** Rientro/sporgenza item lista (annidamento). */
    listIndent()  { this._editor?.chain().focus().sinkListItem("listItem").run(); }
    listOutdent() { this._editor?.chain().focus().liftListItem("listItem").run(); }

    /**
     * G23 Sprint 10b — wrap selezione di un <input>/<textarea> con tag HTML
     * inline (strong/em/u/code). Usato per B/I/U/code quando il cursore è
     * dentro un input NodeView. Dispatch input+change per commit del valore.
     */
    _applyInlineToInput(el, name) {
        const tag = { bold: "strong", italic: "em", underline: "u", code: "code" }[name];
        if (!tag) return;
        const start = el.selectionStart ?? 0;
        const end = el.selectionEnd ?? 0;
        const val = el.value || "";
        const sel = val.slice(start, end);
        const open = `<${tag}>`;
        const close = `</${tag}>`;
        let next, caretStart, caretEnd;
        if (sel) {
            // Toggle: se la selezione è già wrappata esattamente, unwrap.
            if (sel.startsWith(open) && sel.endsWith(close)) {
                const inner = sel.slice(open.length, sel.length - close.length);
                next = val.slice(0, start) + inner + val.slice(end);
                caretStart = start; caretEnd = start + inner.length;
            } else {
                next = val.slice(0, start) + open + sel + close + val.slice(end);
                caretStart = start + open.length;
                caretEnd = caretStart + sel.length;
            }
        } else {
            // Nessuna selezione: inserisce coppia tag vuota + cursore in mezzo.
            next = val.slice(0, start) + open + close + val.slice(end);
            caretStart = caretEnd = start + open.length;
        }
        el.value = next;
        // Commit: i NodeView ascoltano 'blur', ma dispatchiamo anche 'input'
        // per auto-resize textarea + eventuali live listener.
        el.dispatchEvent(new Event("input", { bubbles: true }));
        el.focus();
        el.setSelectionRange(caretStart, caretEnd);
    }

    isActiveMark(name) { return !!this._editor?.isActive(name); }

    /** Apri modal: "fieldRef"|"checkboxGroup"|"rawTex"|"ptTable". */
    openInsertModal(type) {
        if (type === "fieldRef")          this._openFieldModal();
        else if (type === "checkboxGroup") this._openCheckboxModal();
        else if (type === "rawTex")       this._openRawTexModal();
        else if (type === "ptTable")      this._openTableModal();
    }

    /** Quick-insert block: "ptSectionHeader"/"ptTextField"/"ptSelect"/"ptFormCheckbox". */
    insertQuick(type, args) { this._insertAtCursor(type, args); }

    /** Toggle Rich↔Source mode. */
    toggleEditorMode() { this._toggleMode(); }

    isSourceMode() { return this._mode === "source"; }

    // ── render ──

    render() {
        const isSource = this._mode === "source";
        // Phase 24.10 — preventDefault su mousedown dei bottoni toolbar
        // preserva la selection/cursor dell'editor (altrimenti il click
        // sposta focus al button e insertContent finisce a fine documento).
        const preserveSel = (e) => e.preventDefault();
        // Phase 24.10b — compact mode: nasconde toolbar interna (usata da
        // fm-risdoc-pt-section quando template shell ha toolbar globale).
        if (this.compact) {
            return html`
                <div class="editor" spellcheck="false"
                     style=${isSource ? "display:none" : ""}></div>
                ${isSource ? html`
                    <div class="source-wrap">
                        <div class="source-mount"></div>
                        ${this._sourceError
                            ? html`<div class="source-error">⚠ ${this._sourceError}</div>`
                            : html`<div class="source-ok">✓ JSON valido — usa toolbar globale "▶ Applica" per tornare a Rich</div>`}
                    </div>
                ` : ""}
                ${this._modal ? this._renderModal() : ""}
            `;
        }
        return html`
            <div class="toolbar" role="toolbar" aria-label="Formattazione"
                 @mousedown=${preserveSel}>
                ${!isSource ? html`
                    <button type="button" class="${this._isActive("bold")}"
                            @click=${() => this._toggle("toggleBold")}
                            title="Grassetto (Ctrl+B)"><strong>B</strong></button>
                    <button type="button" class="${this._isActive("italic")}"
                            @click=${() => this._toggle("toggleItalic")}
                            title="Corsivo (Ctrl+I)"><em>I</em></button>
                    <button type="button" class="${this._isActive("underline")}"
                            @click=${() => this._toggle("toggleUnderline")}
                            title="Sottolineato (Ctrl+U)"><u>U</u></button>
                    <button type="button" class="${this._isActive("code")}"
                            @click=${() => this._toggle("toggleCode")}
                            title="Codice inline (Ctrl+E)"><code>&lt;&gt;</code></button>
                    <div class="sep"></div>
                    <button type="button" @click=${() => this._openFieldModal()}
                            title="Inserisci riferimento a un campo del docente (es. classe, sezione, disciplina). Renderizzato come chip blu; in TeX diventa [field-nome]">📝 Campo</button>
                    <button type="button" @click=${() => this._openCheckboxModal()}
                            title="Inserisci gruppo di checkbox (scelta multipla con opzioni modificabili)">☑ Gruppo</button>
                    <button type="button" @click=${() => this._openRawTexModal()}
                            title="Inserisci codice LaTeX grezzo (escape hatch: formule, \\vspace, \\newpage, comandi custom)">\\TeX</button>
                    <button type="button" @click=${() => this._openTableModal()}
                            title="Inserisci tabella editabile con header, righe e colonne">📋 Tabella</button>
                    <button type="button"
                            @click=${() => this._insertAtCursor("ptSectionHeader", ["Nuova sezione", 2, []])}
                            title="Inserisci intestazione di sezione (H1–H4) con selettori field">§ Sezione</button>
                    <button type="button"
                            @click=${() => this._insertAtCursor("ptTextField", ["Etichetta", "", "text"])}
                            title="Inserisci input di testo/numero/data con etichetta (es. 'Anno scolastico: ____')">✎ Testo</button>
                    <button type="button"
                            @click=${() => this._insertAtCursor("ptSelect", ["Etichetta", "", []])}
                            title="Inserisci menù a tendina. Poi clicca ⚙ per aggiungere opzioni inline o collegare un file JSON (competenze/abilità/conoscenze, ecc.)">⬇ Select</button>
                    <button type="button"
                            @click=${() => this._insertAtCursor("ptFormCheckbox", ["Affermazione", false])}
                            title="Inserisci singolo checkbox sì/no (in TeX: \\xcheckbox se spuntato, \\checkbox altrimenti)">☐ Sì/No</button>
                ` : ""}
                <button type="button"
                        class="mode-toggle ${isSource ? "is-source" : ""}"
                        @click=${() => this._toggleMode()}
                        title=${isSource ? "Applica le modifiche e torna alla modalità Rich" : "Passa alla modalità Source: edita l'AST Portable Text come JSON (per utenti avanzati)"}>
                    ${isSource ? "▶ Applica" : "{ } Source"}
                </button>
            </div>
            <div class="editor" spellcheck="false"
                 style=${isSource ? "display:none" : ""}></div>
            ${isSource ? html`
                <div class="source-wrap">
                    <div class="source-mount"></div>
                    ${this._sourceError
                        ? html`<div class="source-error">⚠ ${this._sourceError}</div>`
                        : html`<div class="source-ok">✓ JSON valido — click "▶ Applica" per tornare a Rich</div>`}
                </div>
            ` : ""}
            ${this._modal ? this._renderModal() : ""}
        `;
    }

    _renderModal() {
        switch (this._modal.type) {
            case "fieldRef":      return this._renderFieldModal();
            case "checkboxGroup": return this._renderCheckboxModal();
            case "rawTex":        return this._renderRawTexModal();
            case "ptTable":       return this._renderTableModal();
            default: return "";
        }
    }

    _renderFieldModal() {
        const m = this._modal;
        const validate = (name) => {
            if (!name) return "Nome obbligatorio.";
            if (!/^[a-zA-Z_][a-zA-Z0-9_]*$/.test(name)) return "Solo lettere, numeri, underscore. Deve iniziare con lettera o _.";
            return "";
        };
        const onInput = (e) => {
            m.name = e.target.value;
            m.error = validate(m.name);
            this.requestUpdate();
        };
        const submit = () => {
            const err = validate(m.name);
            if (err) { m.error = err; this.requestUpdate(); return; }
            this._commitFieldRef(m.name);
        };
        const onKeydown = (e) => {
            if (e.key === "Escape") this._closeModal();
            if (e.key === "Enter") submit();
        };
        return html`
            <div class="modal-backdrop" @click=${(e) => e.target === e.currentTarget && this._closeModal()} @keydown=${onKeydown}>
                <div class="modal" role="dialog" aria-label="Inserisci campo">
                    <div class="modal-head">📝 ${typeof m._editPos === "number" ? "Modifica" : "Inserisci"} riferimento a campo</div>
                    <div class="modal-body">
                        ${this.fields?.length ? html`
                            <section>
                                <h4>Campi disponibili</h4>
                                <div class="chip-list">
                                    ${this.fields.map((f) => html`
                                        <button type="button" class="chip" @click=${() => this._commitFieldRef(f)}>
                                            📝 ${f}
                                        </button>
                                    `)}
                                </div>
                            </section>
                        ` : ""}
                        <section>
                            <label for="fm-pt-field-name">Nome campo (snake_case)</label>
                            <input id="fm-pt-field-name" type="text"
                                   .value=${m.name}
                                   @input=${onInput}
                                   @keydown=${onKeydown}
                                   placeholder="es. anno_scolastico, nome_docente"
                                   autofocus>
                            ${m.error ? html`<div class="validation-error">${m.error}</div>` : ""}
                        </section>
                    </div>
                    <div class="modal-foot">
                        <button type="button" @click=${() => this._closeModal()}>Annulla</button>
                        <button type="button" class="primary" @click=${submit}
                                ?disabled=${!!validate(m.name)}>Inserisci</button>
                    </div>
                </div>
            </div>
        `;
    }

    _renderCheckboxModal() {
        const m = this._modal;
        const update = () => this.requestUpdate();
        const addItem = () => { m.items.push({ state: "_", label: "" }); update(); };
        const removeItem = (i) => { m.items.splice(i, 1); update(); };
        const toggleState = (i) => { m.items[i].state = m.items[i].state === "x" ? "_" : "x"; update(); };
        const setLabel = (i, v) => { m.items[i].label = v; update(); };
        const loadPreset = (preset) => { m.items = JSON.parse(JSON.stringify(preset.items)); update(); };
        // ── Da catalogo (cascata tipo → Automatico/file → gruppo) ──
        const onVariant = async (val) => {
            const i = (val || "").indexOf(":");
            const kind = i > 0 ? val.slice(0, i) : "", path = i > 0 ? val.slice(i + 1) : "";
            m._loadedOpts = null; m._optGroups = null;
            if (!path) { m._src = null; update(); return; }
            m._src = kind === "folder" ? { folder: path } : { file: path };
            update();
            try { m._loadedOpts = await fetchSchemaOptions({ options_source: m._src }, window.FM?.pt?.currentState || {}); }
            catch { m._loadedOpts = []; }
            m._optGroups = [...new Set((m._loadedOpts || []).map((o) => o.group).filter(Boolean))];
            update();
        };
        const onDataset = (ds) => {
            m._dataset = ds; m._src = null; m._loadedOpts = null; m._optGroups = null;
            const g = (m._srcGroups || []).find((x) => x.dataset === ds);
            if (g && g.entries.length === 1) onVariant(g.entries[0].value); else update();
        };
        const applyGroup = (g) => {
            const chosen = g === "__all__" ? (m._loadedOpts || []) : (m._loadedOpts || []).filter((o) => o.group === g);
            m.items = chosen.map((o) => {
                const it = { state: o.default ? "x" : "_", label: String(o.label ?? "") };
                if (g === "__all__" && o.group) it.group = o.group;
                return it;
            });
            update();
        };
        const datasetGroup = (m._srcGroups || []).find((x) => x.dataset === m._dataset);
        // "Automatico" (cartella) → gruppo COLLEGATO/dinamico: si può inserire
        // anche senza voci ora (si popola coi selettori del documento).
        const dynamicFolder = !!(m._src && m._src.folder);
        const cleanItems = m.items
            .map((it) => ({ state: it.state, label: it.label.trim(), ...(it.group ? { group: it.group } : {}) }))
            .filter((it) => it.label);
        const canSubmit = (cleanItems.length > 0 && m.items.every((it) => it.label.trim())) || dynamicFolder;
        const submit = () => {
            if (!canSubmit) return;
            if (dynamicFolder) {
                // framework dinamico da options_source; salviamo solo le selezioni.
                this._commitCheckboxGroup(cleanItems.filter((it) => it.state === "x"), { folder: m._src.folder });
            } else {
                this._commitCheckboxGroup(cleanItems);
            }
        };
        const onKeydown = (e) => { if (e.key === "Escape") this._closeModal(); };
        return html`
            <div class="modal-backdrop" @click=${(e) => e.target === e.currentTarget && this._closeModal()} @keydown=${onKeydown}>
                <div class="modal" role="dialog" aria-label="Gruppo checkbox">
                    <div class="modal-head">☑ Gruppo di checkbox</div>
                    <div class="modal-body">
                        <section>
                            <h4>Preset rapidi</h4>
                            <div class="preset-list">
                                ${CHECKBOX_PRESETS.map((p) => html`
                                    <div class="preset" @click=${() => loadPreset(p)}>
                                        <span>${p.label}</span>
                                        <span class="preset-preview">${p.items.map((it) => it.label).join(" / ")}</span>
                                    </div>
                                `)}
                            </div>
                        </section>
                        <section>
                            <h4>Da catalogo (competenze, obiettivi, programmi…)</h4>
                            ${m._srcGroups ? html`
                                <div class="cbm-cascade">
                                    <select @change=${(e) => onDataset(e.target.value)}>
                                        <option value="">— tipo di contenuto —</option>
                                        ${m._srcGroups.map((g) => html`<option value=${g.dataset} ?selected=${m._dataset === g.dataset}>${g.label}</option>`)}
                                    </select>
                                    ${datasetGroup && datasetGroup.entries.length > 1 ? html`
                                        <select @change=${(e) => onVariant(e.target.value)}>
                                            <option value="">— Automatico o file specifico —</option>
                                            ${datasetGroup.entries.map((e) => html`<option value=${e.value}>${e.label}</option>`)}
                                        </select>
                                    ` : ""}
                                    ${(m._src && m._src.folder) ? html`
                                        <span class="cbm-hint cbm-hint--ok">🔁 Verrà inserito <strong>collegato</strong>: il contenuto si aggiorna con indirizzo/classe/materia del documento (e al cambio dei selettori). Inserisci, poi spunta le voci nell'editor.</span>
                                    ` : (m._loadedOpts && m._loadedOpts.length) ? html`
                                        <select @change=${(e) => e.target.value && applyGroup(e.target.value)}>
                                            <option value="">— scegli gruppo —</option>
                                            <option value="__all__">Tutti i gruppi (con intestazioni)</option>
                                            ${(m._optGroups || []).map((g) => html`<option value=${g}>${g}</option>`)}
                                        </select>
                                    ` : (m._loadedOpts ? html`<span class="cbm-hint">⚠️ Nessun contenuto in questo file. Scegli un altro file.</span>`
                                        : (m._src ? html`<span class="fm-muted">caricamento…</span>` : ""))}
                                </div>
                            ` : html`<span class="fm-muted">caricamento sorgenti…</span>`}
                        </section>
                        <section>
                            <h4>Items (${m.items.length})</h4>
                            <div class="item-list">
                                ${m.items.map((it, i) => html`
                                    <div class="item-row">
                                        <button type="button"
                                                class="state-toggle ${it.state === "x" ? "checked" : ""}"
                                                @click=${() => toggleState(i)}
                                                title="Toggle checked/unchecked">
                                            ${it.state === "x" ? "☑ sì" : "☐ no"}
                                        </button>
                                        <input type="text"
                                               .value=${it.label}
                                               @input=${(e) => setLabel(i, e.target.value)}
                                               placeholder="Etichetta item...">
                                        <button type="button" class="fm-remove-btn"
                                                @click=${() => removeItem(i)}
                                                title="Rimuovi item">🗑</button>
                                    </div>
                                `)}
                                <button type="button" class="add-btn" @click=${addItem}>
                                    + Aggiungi item
                                </button>
                            </div>
                        </section>
                    </div>
                    <div class="modal-foot">
                        <button type="button" @click=${() => this._closeModal()}>Annulla</button>
                        <button type="button" class="primary" @click=${submit}
                                ?disabled=${!canSubmit}>${dynamicFolder ? "Inserisci collegato" : `Inserisci (${cleanItems.length})`}</button>
                    </div>
                </div>
            </div>
        `;
    }

    _renderTableModal() {
        const m = this._modal;
        const update = () => this.requestUpdate();
        const setCol = (i, v) => { m.columns[i] = v; update(); };
        const addCol = () => { m.columns.push("Nuova"); m.rows.forEach(r => r.push("")); update(); };
        const rmCol = () => {
            if (m.columns.length <= 1) return;
            m.columns.pop(); m.rows.forEach(r => r.pop()); update();
        };
        const addRow = () => { m.rows.push(m.columns.map(() => "")); update(); };
        const rmRow = () => { if (m.rows.length > 0) { m.rows.pop(); update(); } };
        const setWidthMode = (mode) => {
            m.widthMode = mode === "full" ? "full" : "auto";
            // Inizializza colWidths equa alla prima attivazione di "full".
            if (m.widthMode === "full" && (!Array.isArray(m.colWidths) || m.colWidths.length !== m.columns.length)) {
                const eq = Math.round(100 / m.columns.length);
                m.colWidths = m.columns.map(() => eq);
            }
            update();
        };
        const setColWidth = (i, v) => {
            const eq = Math.round(100 / m.columns.length);
            if (!Array.isArray(m.colWidths) || m.colWidths.length !== m.columns.length) {
                m.colWidths = m.columns.map(() => eq);
            }
            m.colWidths[i] = Math.max(1, Math.min(100, parseInt(v, 10) || 0));
            update();
        };
        const canSubmit = m.columns.every((c) => c.trim());
        const submit = () => {
            if (!canSubmit) return;
            this._commitTable(
                m.columns.map(c => c.trim()),
                m.rows,
                (m.caption || "").trim(),
                m.widthMode === "full" ? "full" : "auto",
                m.widthMode === "full" && Array.isArray(m.colWidths) ? m.colWidths : [],
            );
        };
        const onKeydown = (e) => { if (e.key === "Escape") this._closeModal(); };
        return html`
            <div class="modal-backdrop" @click=${(e) => e.target === e.currentTarget && this._closeModal()} @keydown=${onKeydown}>
                <div class="modal" role="dialog" aria-label="Inserisci tabella">
                    <div class="modal-head">📋 Inserisci tabella</div>
                    <div class="modal-body">
                        <section>
                            <h4>Dimensioni (${m.columns.length} col × ${m.rows.length} righe)</h4>
                            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                <button type="button" @click=${addCol}>+ colonna</button>
                                <button type="button" @click=${rmCol}>− colonna</button>
                                <button type="button" @click=${addRow}>+ riga</button>
                                <button type="button" @click=${rmRow}>− riga</button>
                            </div>
                        </section>
                        <section>
                            <h4>Header colonne</h4>
                            <div style="display:flex;flex-direction:column;gap:6px;">
                                ${m.columns.map((col, i) => html`
                                    <input type="text"
                                           .value=${col}
                                           placeholder=${`Col ${i + 1}`}
                                           @input=${(e) => setCol(i, e.target.value)}>
                                `)}
                            </div>
                        </section>
                        <section>
                            <h4>Larghezza</h4>
                            <div class="fm-pt-tw" role="radiogroup" aria-label="Larghezza tabella">
                                <button type="button"
                                        class="fm-pt-tw__btn ${m.widthMode !== "full" ? "fm-pt-tw__btn--active" : ""}"
                                        role="radio" aria-checked=${m.widthMode !== "full"}
                                        @click=${() => setWidthMode("auto")}
                                        title="La tabella si adatta al contenuto">↔ Adatta contenuto</button>
                                <button type="button"
                                        class="fm-pt-tw__btn ${m.widthMode === "full" ? "fm-pt-tw__btn--active" : ""}"
                                        role="radio" aria-checked=${m.widthMode === "full"}
                                        @click=${() => setWidthMode("full")}
                                        title="La tabella occupa tutta la larghezza della pagina (rispetta orientamento)">⬌ Tutta la pagina</button>
                            </div>
                            ${m.widthMode === "full" ? html`
                                <div class="fm-pt-tw__cols">
                                    ${m.columns.map((col, i) => html`
                                        <label class="fm-pt-tw__col" title=${`Larghezza ${col || `Col ${i + 1}`} (%)`}>
                                            <span class="fm-pt-tw__col-name">${col || `Col ${i + 1}`}</span>
                                            <input type="number" min="1" max="100" step="1"
                                                   class="fm-pt-tw__input"
                                                   .value=${String((m.colWidths && m.colWidths[i]) ?? Math.round(100 / m.columns.length))}
                                                   @change=${(e) => setColWidth(i, e.target.value)}>
                                            <span class="fm-pt-tw__unit">%</span>
                                        </label>
                                    `)}
                                </div>
                                <p class="fm-pt-tw__hint">Le percentuali vengono normalizzate (la somma diventa 100%).</p>
                            ` : ""}
                        </section>
                        <section>
                            <label for="fm-pt-table-caption">Didascalia (opzionale)</label>
                            <input id="fm-pt-table-caption" type="text"
                                   .value=${m.caption || ""}
                                   @input=${(e) => { m.caption = e.target.value; }}
                                   placeholder="Es. Tab. 1 — Studenti">
                        </section>
                    </div>
                    <div class="modal-foot">
                        <button type="button" @click=${() => this._closeModal()}>Annulla</button>
                        <button type="button" class="primary" @click=${submit}
                                ?disabled=${!canSubmit}>Inserisci</button>
                    </div>
                </div>
            </div>
        `;
    }

    _renderRawTexModal() {
        const m = this._modal;
        const update = () => this.requestUpdate();
        const setContent = (v) => { m.content = v; update(); };
        const loadSnippet = (s) => { m.content = s.content; update(); };
        const submit = () => {
            if (!m.content.trim()) return;
            this._commitRawTex(m.content);
        };
        const onKeydown = (e) => {
            if (e.key === "Escape") this._closeModal();
            if (e.key === "Enter" && (e.ctrlKey || e.metaKey)) submit();
        };
        return html`
            <div class="modal-backdrop" @click=${(e) => e.target === e.currentTarget && this._closeModal()} @keydown=${onKeydown}>
                <div class="modal" role="dialog" aria-label="Raw TeX">
                    <div class="modal-head">\\TeX ${typeof m._editPos === "number" ? "Modifica" : "Inserisci"} TeX raw</div>
                    <div class="modal-body">
                        <section>
                            <h4>Snippet pronti (clicca per caricare)</h4>
                            <div class="preset-list">
                                ${RAWTEX_SNIPPETS.map((s) => html`
                                    <div class="preset" @click=${() => loadSnippet(s)}>
                                        <span>${s.label}</span>
                                        <span class="preset-preview">${s.content.slice(0, 40).replace(/\n/g, " ⏎ ")}${s.content.length > 40 ? "…" : ""}</span>
                                    </div>
                                `)}
                            </div>
                        </section>
                        <section>
                            <label for="fm-pt-rawtex">Contenuto LaTeX (iniettato as-is in export)</label>
                            <textarea id="fm-pt-rawtex"
                                      .value=${m.content}
                                      @input=${(e) => setContent(e.target.value)}
                                      @keydown=${onKeydown}
                                      placeholder="\\vspace{1em}"
                                      rows="6"
                                      autofocus></textarea>
                        </section>
                    </div>
                    <div class="modal-foot">
                        <button type="button" @click=${() => this._closeModal()}>Annulla</button>
                        <button type="button" class="primary" @click=${submit}
                                ?disabled=${!m.content.trim()}>Inserisci (Ctrl+Enter)</button>
                    </div>
                </div>
            </div>
        `;
    }
}

if (!customElements.get("fm-risdoc-pt-editor")) {
    customElements.define("fm-risdoc-pt-editor", FmRisdocPtEditor);
}
// Phase 24.34 — alias generico cross-domain. Stesso codice del risdoc
// editor; serve a creare/editare contenuti PT AST per esercizi/lab/verifica
// /bes via il modal section-edit.
if (!customElements.get("fm-pt-editor")) {
    customElements.define("fm-pt-editor", class extends FmRisdocPtEditor {});
}
