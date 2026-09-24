/** Unit smoke tests — RmLayoutModel domain object + observers. */
import { describe, test, expect, vi } from "vitest";
import { RmLayoutModel, syncCellsShape } from "../../js/modules/editor/rm-layout-model.js";

describe("RmLayoutModel constructor", () => {
    test("default state senza initial", () => {
        const m = new RmLayoutModel();
        expect(m.orientation).toBe("horizontal");
        expect(m.tables).toHaveLength(1);
        expect(m.tables[0].rows).toBe(2);
        expect(m.tables[0].cols).toBe(2);
        expect(m.tables[0].typecell).toBe("|X|X|");
    });

    test("initial state custom", () => {
        const m = new RmLayoutModel({
            orientation: "vertical",
            tables: [{ rows: 3, cols: 3, typecell: "|V|X|X|" }],
        });
        expect(m.orientation).toBe("vertical");
        expect(m.tables).toHaveLength(1);
        expect(m.tables[0].rows).toBe(3);
        expect(m.tables[0].cols).toBe(3);
    });

    test("normalize derive colTypes da typecell", () => {
        const m = new RmLayoutModel({
            tables: [{ rows: 2, cols: 3, typecell: "|X|V|N|" }],
        });
        expect(m.tables[0].colTypes).toEqual(["X", "V", "N"]);
    });
});

describe("setOrientation", () => {
    test("cambia + emette change:orientation", () => {
        const m = new RmLayoutModel();
        const spy = vi.fn();
        m.on("change:orientation", spy);
        m.setOrientation("vertical");
        expect(m.orientation).toBe("vertical");
        expect(spy).toHaveBeenCalledWith("vertical", m);
    });

    test("no-op se valore uguale", () => {
        const m = new RmLayoutModel({ orientation: "horizontal" });
        const spy = vi.fn();
        m.on("change:orientation", spy);
        m.setOrientation("horizontal");
        expect(spy).not.toHaveBeenCalled();
    });

    test("emette composite change", () => {
        const m = new RmLayoutModel();
        const spy = vi.fn();
        m.on("change", spy);
        m.setOrientation("vertical");
        expect(spy).toHaveBeenCalledOnce();
    });
});

describe("setTableCount", () => {
    test("aggiunge tabelle clonando l'ultima", () => {
        const m = new RmLayoutModel();
        m.setTableCount(3);
        expect(m.tables).toHaveLength(3);
        // clonata da first → stessa shape
        expect(m.tables[2].rows).toBe(2);
    });

    test("rimuove tabelle (truncate)", () => {
        const m = new RmLayoutModel({
            tables: [{}, {}, {}, {}],
        });
        m.setTableCount(2);
        expect(m.tables).toHaveLength(2);
    });

    test("clamp [1, 10]", () => {
        const m = new RmLayoutModel();
        m.setTableCount(0);
        expect(m.tables).toHaveLength(1);
        m.setTableCount(100);
        expect(m.tables).toHaveLength(10);
    });
});

describe("setRows / setCols", () => {
    test("setRows + sync cells shape", () => {
        const m = new RmLayoutModel();
        m.setRows(0, 4);
        expect(m.tables[0].rows).toBe(4);
        expect(m.tables[0].cells).toHaveLength(4);
        expect(m.tables[0].cells[0]).toHaveLength(2); // cols=2
    });

    test("setCols + auto-adjust colTypes", () => {
        const m = new RmLayoutModel();
        m.setCols(0, 4);
        expect(m.tables[0].cols).toBe(4);
        expect(m.tables[0].colTypes).toEqual(["X", "X", "X", "X"]);
        expect(m.tables[0].typecell).toBe("|X|X|X|X|");
    });

    test("emit change:table con field", () => {
        const m = new RmLayoutModel();
        const spy = vi.fn();
        m.on("change:table", spy);
        m.setRows(0, 5);
        expect(spy).toHaveBeenCalledWith({ idx: 0, field: "rows" }, m);
    });
});

describe("setColType", () => {
    test("set + typecell update", () => {
        const m = new RmLayoutModel();
        m.setColType(0, 1, "V");
        expect(m.tables[0].colTypes[1]).toBe("V");
        expect(m.tables[0].typecell).toBe("|X|V|");
    });

    test("normalize input (es. lower → upper, fallback X)", () => {
        const m = new RmLayoutModel();
        m.setColType(0, 0, "v");
        expect(m.tables[0].colTypes[0]).toBe("V");
        m.setColType(0, 0, "INVALID");
        expect(m.tables[0].colTypes[0]).toBe("X");
    });
});

describe("setCell (no event)", () => {
    test("mutate state ma NO change event (live cell typing)", () => {
        const m = new RmLayoutModel();
        const spy = vi.fn();
        m.on("change", spy);
        m.setCell(0, 0, 0, "hello");
        expect(m.tables[0].cells[0][0]).toBe("hello");
        expect(spy).not.toHaveBeenCalled();
    });
});

describe("setFlag", () => {
    test("setFlag mixtr/mixcol/mpagew", () => {
        const m = new RmLayoutModel();
        m.setFlag(0, "mixtr", true);
        expect(m.tables[0].mixtr).toBe(true);
    });

    test("mpagew=true reset specificWidth", () => {
        const m = new RmLayoutModel({
            tables: [{ rows: 2, cols: 2, specificWidth: "300", mpagew: false }],
        });
        m.setFlag(0, "mpagew", true);
        expect(m.tables[0].specificWidth).toBe("");
    });

    test("flag non-valido ignored", () => {
        const m = new RmLayoutModel();
        const spy = vi.fn();
        m.on("change:table", spy);
        m.setFlag(0, "invalidFlag", true);
        expect(spy).not.toHaveBeenCalled();
    });
});

describe("setSpecificWidth", () => {
    test("set + reset mpagew when value", () => {
        const m = new RmLayoutModel({
            tables: [{ rows: 2, cols: 2, mpagew: true }],
        });
        m.setSpecificWidth(0, "500");
        expect(m.tables[0].specificWidth).toBe("500");
        expect(m.tables[0].mpagew).toBe(false);
    });
});

describe("toJSON snapshot", () => {
    test("clone deep, mutating cloned cells non altera model", () => {
        const m = new RmLayoutModel();
        m.setCell(0, 0, 0, "a");
        const snap = m.toJSON();
        snap.tables[0].cells[0][0] = "b";
        expect(m.tables[0].cells[0][0]).toBe("a");
    });
});

describe("observer unsubscribe", () => {
    test("unsubscribe function rimuove listener", () => {
        const m = new RmLayoutModel();
        const spy = vi.fn();
        const off = m.on("change:orientation", spy);
        m.setOrientation("vertical");
        expect(spy).toHaveBeenCalledTimes(1);
        off();
        m.setOrientation("horizontal");
        expect(spy).toHaveBeenCalledTimes(1); // ancora 1, non chiamato dopo off
    });
});

describe("syncCellsShape (helper)", () => {
    test("expand rows preserva content esistente", () => {
        const t = { rows: 3, cols: 2, cells: [["a", "b"], ["c", "d"]] };
        syncCellsShape(t);
        expect(t.cells).toHaveLength(3);
        expect(t.cells[0]).toEqual(["a", "b"]);
        expect(t.cells[2]).toEqual(["", ""]);
    });

    test("shrink rows tronca", () => {
        const t = { rows: 1, cols: 2, cells: [["a", "b"], ["c", "d"]] };
        syncCellsShape(t);
        expect(t.cells).toHaveLength(1);
    });

    test("no-op se shape già match", () => {
        const t = { rows: 2, cols: 2, cells: [["a", "b"], ["c", "d"]] };
        const before = t.cells;
        syncCellsShape(t);
        expect(t.cells).toBe(before); // same reference
    });
});
