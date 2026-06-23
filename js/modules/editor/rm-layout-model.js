/**
 * G24.faseA.1 — Domain model per layout RM (Risposta Multipla).
 *
 * Encapsula la state shape `{orientation, tables: [...]}` con mutator
 * typed + observer pub/sub. Sostituisce lo state object inline in
 * `buildRmLayoutSection` (mutato direttamente da N handler scattered)
 * con un'API esplicita: ogni `.set...()` notifica i listener registrati.
 *
 * Shape tabella:
 *   {
 *     rows: number,
 *     cols: number,
 *     typecell: string         // "|X|X|" derivato da colTypes
 *     colTypes: ['X'|'V'|'B'|'T'|'N', ...] (len = cols)
 *     cells: [[string, ...], ...] (rows × cols matrix)
 *     mixtr: boolean,
 *     mixcol: boolean,
 *     mpagew: boolean,
 *     specificWidth: string,   // px o ""
 *   }
 *
 * Eventi emessi:
 *   "change:orientation"  — orientation flip
 *   "change:tableCount"   — table add/remove
 *   "change:table"        — qualsiasi modifica dentro una tabella (rows/cols/typecell/cells/flags)
 *   "change"              — fired sempre (composto degli altri 3)
 *
 * Le view (RmLayoutView, buildSingleTableCard) sottoscrivono "change" per
 * triggerare `rebuildRmTables(model.toJSON())`. Le mutazioni direct dei
 * field (es. `model.tables[i].rows = 3`) non emettono evento: usare i
 * setter per coerenza.
 */

import { normalizeColType } from "../render/rm-table-view.js";

/** State shape default per tabella vuota. */
function makeDefaultTable() {
    return {
        rows: 2,
        cols: 2,
        typecell: "|X|X|",
        colTypes: ["X", "X"],
        cells: [["", ""], ["", ""]],
        mixtr: false,
        mixcol: false,
        mpagew: false,
        specificWidth: "",
    };
}

/** Sync `cells[rows][cols]` matrix con `rows`/`cols` correnti. Preserva
 *  content esistente, riempie con "" i nuovi slot. */
export function syncCellsShape(t) {
    const oldRows = (t.cells || []).length;
    const oldCols = (t.cells?.[0] || []).length;
    if (oldRows === t.rows && oldCols === t.cols) return;
    const next = [];
    for (let r = 0; r < t.rows; r++) {
        next.push([]);
        for (let c = 0; c < t.cols; c++) {
            next[r].push(t.cells?.[r]?.[c] || "");
        }
    }
    t.cells = next;
}

export class RmLayoutModel {
    /** @param {{orientation: string, tables: Array}} initial */
    constructor(initial = {}) {
        this.orientation = initial.orientation || "horizontal";
        this.tables = Array.isArray(initial.tables) && initial.tables.length
            ? initial.tables.map((t) => this._normalizeTable(t))
            : [makeDefaultTable()];
        /** @type {Map<string, Set<Function>>} */
        this._listeners = new Map();
        /** Reference opaca al DOM item (usata da rebuildRmTables). */
        this.item = initial.item || null;
    }

    _normalizeTable(raw) {
        // G24.fix-typecell-derive — Se typecell esplicitamente passato e
        // colTypes NO, deriva colTypes da typecell (preserva input utente).
        // Pre-fix: defaults colTypes pre-spread sovrascrivevano sempre il
        // typecell custom (regression rilevata da test).
        const typecellExplicit = typeof raw?.typecell === "string";
        const colTypesExplicit = Array.isArray(raw?.colTypes) && raw.colTypes.length > 0;
        const t = { ...makeDefaultTable(), ...raw };
        if (!colTypesExplicit && typecellExplicit) {
            // Deriva da typecell input
            const derived = (raw.typecell.match(/X|V|B|T|N|F/gi) || []).map((s) => s.toUpperCase());
            t.colTypes = derived.length ? derived : Array(t.cols).fill("X");
        } else if (!colTypesExplicit) {
            // Né colTypes né typecell esplicito: default su cols
            const derived = (t.typecell.match(/X|V|B|T|N|F/gi) || []).map((s) => s.toUpperCase());
            t.colTypes = derived.length ? derived : Array(t.cols).fill("X");
        }
        // Sync typecell con colTypes (single source of truth)
        t.typecell = `|${  t.colTypes.map(normalizeColType).join("|")  }|`;
        syncCellsShape(t);
        return t;
    }

    // ── Observer API ──────────────────────────────────────────────────

    /** Sottoscrivi evento. Ritorna unsubscribe function. */
    on(event, fn) {
        if (!this._listeners.has(event)) this._listeners.set(event, new Set());
        this._listeners.get(event).add(fn);
        return () => this._listeners.get(event)?.delete(fn);
    }

    _emit(event, payload) {
        this._listeners.get(event)?.forEach((fn) => {
            try { fn(payload, this); } catch (e) { console.error("[RmLayoutModel]", event, e); }
        });
        if (event !== "change") this._emit("change", { event, payload });
    }

    // ── Orientation ───────────────────────────────────────────────────

    setOrientation(v) {
        if (this.orientation === v) return;
        this.orientation = v;
        this._emit("change:orientation", v);
    }

    // ── Table count ───────────────────────────────────────────────────

    setTableCount(n) {
        const clamped = Math.max(1, Math.min(10, Number(n) || 1));
        if (clamped === this.tables.length) return;
        while (this.tables.length < clamped) {
            // Clona ultima tabella (preset utente)
            const last = this.tables[this.tables.length - 1];
            this.tables.push(this._normalizeTable({ ...last, cells: last.cells.map((r) => [...r]) }));
        }
        if (this.tables.length > clamped) {
            this.tables.length = clamped;
        }
        this._emit("change:tableCount", clamped);
    }

    // ── Per-table mutators ────────────────────────────────────────────

    _table(idx) {
        const t = this.tables[idx];
        if (!t) throw new Error(`RmLayoutModel: table idx=${idx} out of range`);
        return t;
    }

    setRows(idx, rows) {
        const t = this._table(idx);
        const v = Math.max(1, Math.min(12, Number(rows) || 1));
        if (t.rows === v) return;
        t.rows = v;
        syncCellsShape(t);
        this._emit("change:table", { idx, field: "rows" });
    }

    setCols(idx, cols) {
        const t = this._table(idx);
        const v = Math.max(1, Math.min(6, Number(cols) || 1));
        if (t.cols === v) return;
        t.cols = v;
        // Auto-adjust colTypes length
        while (t.colTypes.length < v) t.colTypes.push("X");
        t.colTypes.length = v;
        t.typecell = `|${  t.colTypes.join("|")  }|`;
        syncCellsShape(t);
        this._emit("change:table", { idx, field: "cols" });
    }

    setColType(idx, col, type) {
        const t = this._table(idx);
        const v = normalizeColType(type);
        if (t.colTypes[col] === v) return;
        t.colTypes[col] = v;
        t.typecell = `|${  t.colTypes.join("|")  }|`;
        this._emit("change:table", { idx, field: "colType", col });
    }

    setCell(idx, row, col, value) {
        const t = this._table(idx);
        if (!t.cells[row]) t.cells[row] = [];
        if (t.cells[row][col] === value) return;
        t.cells[row][col] = value;
        // Cell content change NON emette evento di rebuild — la view ne ha
        // già il valore corrente nei textarea. Solo per stato consistente.
    }

    setFlag(idx, flag, value) {
        const t = this._table(idx);
        if (!["mixtr", "mixcol", "mpagew"].includes(flag)) return;
        if (t[flag] === value) return;
        t[flag] = value;
        // Disabilita specificWidth quando mpagew attivo (constraint)
        if (flag === "mpagew" && value) t.specificWidth = "";
        this._emit("change:table", { idx, field: flag });
    }

    setSpecificWidth(idx, px) {
        const t = this._table(idx);
        if (t.specificWidth === px) return;
        t.specificWidth = px;
        // Constraint: width specifica disabilita mpagew
        if (px) t.mpagew = false;
        this._emit("change:table", { idx, field: "specificWidth" });
    }

    // ── Serialization ─────────────────────────────────────────────────

    /** Ritorna snapshot pulito per rebuildRmTables / save server. */
    toJSON() {
        return {
            orientation: this.orientation,
            tables: this.tables.map((t) => ({ ...t, cells: t.cells.map((r) => [...r]) })),
            item: this.item,
        };
    }

    /** Costruisce model da DOM .fm-rm-table esistenti (first-open editor).
     *  @param {Element[]} tableEls — array di `.fm-rm-table` DOM
     *  @param {Function} extractCellContent — helper per estrarre raw cell content
     *  @param {Element} item — ref al .fm-collection__item per future rebuild */
    static fromDom(tableEls, extractCellContent, item) {
        const tables = Array.from(tableEls).map((tbl) => {
            const typecell = tbl.dataset?.typecell || "|X|X|";
            const colTypes = (typecell.match(/X|V|B|T|N|F/gi) || []).map((s) => s.toUpperCase());
            const cols = Math.max(1, colTypes.length);
            const tds = Array.from(tbl.querySelectorAll("td"));
            const rows = Math.max(1, Math.ceil(tds.length / cols));
            const cells = [];
            for (let r = 0; r < rows; r++) {
                cells.push([]);
                for (let c = 0; c < cols; c++) {
                    const td = tds[r * cols + c];
                    cells[r].push(td ? extractCellContent(td) : "");
                }
            }
            return {
                rows, cols, typecell, colTypes, cells,
                mixtr:  tbl.dataset?.mixtr  === "1",
                mixcol: tbl.dataset?.mixcol === "1",
                mpagew: tbl.dataset?.mpagew === "1",
                specificWidth: tbl.dataset?.width || "",
            };
        });
        return new RmLayoutModel({
            orientation: "horizontal",
            tables: tables.length ? tables : [makeDefaultTable()],
            item,
        });
    }
}
