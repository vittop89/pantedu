/**
 * Un elenco si interrompe e si riprende senza che le voci cambino posto
 * (24/9/2026).
 *
 * Due cose, tutte e due in `js/modules/editor/list-edit-utils.js`:
 *
 * 1. Uscire da una lista a metà. Prima le voci che seguivano quella spostata
 *    restavano dov'erano, e il testo si riordinava: Backspace a inizio di «b»
 *    in a, b, c dava a, c, b; Invio su una voce vuota a metà lista metteva il
 *    paragrafo in fondo a tutta la lista; Maiusc+Tab su una voce annidata la
 *    spostava dopo le sorelle. Misurato prima della correzione con queste
 *    stesse strutture: tre casi su quattro sbagliati, il quarto (uscita
 *    dall'ultima voce) giusto, e resta giusto qui sotto.
 *
 * 2. Il primo punto di una lista numerata, l'attributo `start`: la lista
 *    ripresa dopo un paragrafo partiva sempre da 1. o da a. Le funzioni pure
 *    che il menu «List ▸ Numerazione» usa sono provate qui; che il menu sia
 *    collegato nella pagina vera lo dice `tests/e2e/editor/barra-dell-editor.spec.js`.
 */
import { describe, test, expect, beforeEach } from "vitest";
import {
    outdentListItem, listStart, setListStart, numberAfter,
    innermostList, listDepth, previousOrderedList, parseListStart, listFits, itemCount,
} from "../../js/modules/editor/list-edit-utils.js";

/** Un campo come quello dell'editor, con dentro `html`. */
function campo(html) {
    const f = document.createElement("div");
    f.contentEditable = "true";
    f.innerHTML = html;
    document.body.appendChild(f);
    return f;
}

/** Il testo proprio di ogni voce e blocco, in ordine di lettura. */
function ordine(f) {
    const out = [];
    const walk = (n) => {
        for (const c of n.childNodes) {
            if (c.nodeType === Node.TEXT_NODE && c.textContent.trim()) out.push(c.textContent.trim());
            else if (c.nodeType === Node.ELEMENT_NODE) walk(c);
        }
    };
    walk(f);
    return out;
}

beforeEach(() => { document.body.innerHTML = ""; });

describe("uscire da una lista di primo livello", () => {
    test("Backspace a inizio della voce centrale: il testo resta in ordine e la lista si spezza", () => {
        const f = campo('<ol class="fm-dsa-li-list" data-dsa-section="question" data-fm-list-style="lower-alpha-roman"><li>a</li><li>b</li><li>c</li></ol>');
        outdentListItem(f.querySelectorAll("li")[1], f, false, true);

        expect(ordine(f)).toEqual(["a", "b", "c"]);
        const [prima, blocco, dopo] = f.children;
        expect(prima.tagName).toBe("OL");
        expect(blocco.outerHTML).toBe("<div>b</div>");
        expect(dopo.tagName, "le voci dopo restano in una lista").toBe("OL");
        // Stile e sezione passano alla seconda metà, che continua la numerazione.
        expect(dopo.getAttribute("data-fm-list-style")).toBe("lower-alpha-roman");
        expect(dopo.getAttribute("data-dsa-section")).toBe("question");
        expect(dopo.className).toBe("fm-dsa-li-list");
        expect(dopo.getAttribute("start")).toBe("2");
    });

    test("Invio su una voce vuota a metà: il paragrafo nuovo nasce lì, non in fondo", () => {
        const f = campo('<ol start="4"><li>a</li><li>b</li><li></li><li>c</li></ol>');
        outdentListItem(f.querySelectorAll("li")[2], f, true);

        expect(f.innerHTML).toBe('<ol start="4"><li>a</li><li>b</li></ol><div><br></div><ol start="6"><li>c</li></ol>');
        // Il caret è nel paragrafo nuovo.
        expect(window.getSelection().getRangeAt(0).startContainer).toBe(f.children[1]);
    });

    test("Invio sull'ultima voce vuota: come prima, il paragrafo va dopo la lista", () => {
        const f = campo("<ol><li>a</li><li>b</li><li></li></ol>");
        outdentListItem(f.querySelectorAll("li")[2], f, true);
        expect(f.innerHTML).toBe("<ol><li>a</li><li>b</li></ol><div><br></div>");
    });

    test("dalla prima voce: il blocco va prima della lista, che non si spezza", () => {
        const f = campo('<ol start="3"><li>a</li><li>b</li></ol>');
        outdentListItem(f.querySelector("li"), f, false, true);
        expect(f.innerHTML).toBe('<div>a</div><ol start="3"><li>b</li></ol>');
    });

    test("una lista puntata si spezza senza prendere un numero", () => {
        const f = campo("<ul><li>a</li><li>b</li><li>c</li></ul>");
        outdentListItem(f.querySelectorAll("li")[1], f);
        expect(f.innerHTML).toBe("<ul><li>a</li></ul><div>b</div><ul><li>c</li></ul>");
    });
});

describe("far salire una voce annidata", () => {
    test("Maiusc+Tab: le sorelle che seguivano diventano sue figlie, nessuna cambia posto", () => {
        const f = campo('<ol><li>a<ol class="fm-dsa-li-list"><li>x</li><li>y</li></ol></li><li>b</li></ol>');
        outdentListItem(f.querySelector("ol ol li"), f);

        expect(ordine(f)).toEqual(["a", "x", "y", "b"]);
        expect(f.innerHTML).toBe('<ol><li>a</li><li>x<ol class="fm-dsa-li-list"><li>y</li></ol></li><li>b</li></ol>');
    });

    test("le sorelle si accodano alle figlie che la voce aveva già", () => {
        const f = campo("<ol><li>a<ol><li>x<ol><li>x1</li></ol></li><li>y</li></ol></li></ol>");
        outdentListItem(f.querySelector("ol ol > li"), f);
        expect(f.innerHTML).toBe("<ol><li>a</li><li>x<ol><li>x1</li><li>y</li></ol></li></ol>");
    });

    test("Invio su voce annidata vuota con sorelle dopo: una voce nuova sale, e le sorelle con lei", () => {
        const f = campo('<ol><li>a<ol><li>x</li><li id="vuota"></li><li>y</li></ol></li></ol>');
        outdentListItem(f.querySelector("#vuota"), f, true);
        expect(f.innerHTML).toBe("<ol><li>a<ol><li>x</li></ol></li><li><ol><li>y</li></ol></li></ol>");
    });
});

describe("il primo punto di una lista", () => {
    test("start si legge, e 1 toglie l'attributo invece di scriverlo", () => {
        const ol = document.createElement("ol");
        expect(listStart(ol)).toBe(1);
        setListStart(ol, 3);
        expect(ol.getAttribute("start")).toBe("3");
        expect(listStart(ol)).toBe(3);
        setListStart(ol, 1);
        expect(ol.hasAttribute("start")).toBe(false);
    });

    test("il numero dopo l'ultimo punto conta solo le voci della lista, non quelle annidate", () => {
        const f = campo('<ol start="2"><li>b<ol><li>i</li><li>ii</li></ol></li><li>c</li></ol>');
        expect(numberAfter(f.firstChild)).toBe(4);
    });

    test("la lista da continuare è l'ultima numerata prima, allo stesso livello", () => {
        const f = campo(
            '<ol id="uno"><li>a</li></ol><div>testo</div>'
            + '<ol id="due"><li>a<ol id="dentro"><li>x</li></ol></li></ol>'
            + '<ul id="puntata"><li>p</li></ul><div id="qui">riprendo</div>'
            + '<ol id="dopo"><li>z</li></ol>',
        );
        const qui = f.querySelector("#qui").firstChild;
        expect(previousOrderedList(f, qui, 0).id).toBe("due");
        expect(previousOrderedList(f, f.querySelector("#due"), 0).id).toBe("uno");
        expect(previousOrderedList(f, f.querySelector("#uno"), 0)).toBe(null);
        // Una lista non continua quella che la contiene.
        expect(previousOrderedList(f, f.querySelector("#dentro"), 1)).toBe(null);
        expect(listDepth(f.querySelector("#dentro"), f)).toBe(1);
        expect(innermostList(f, f.querySelector("#dentro li").firstChild).id).toBe("dentro");
        expect(innermostList(f, qui)).toBe(null);
    });
});

describe("il segno che scrive il docente diventa il primo numero", () => {
    test.each([
        // [scritto, segno del livello, atteso]
        ["c", "la.", 3], ["C", "UA)", 3], ["c)", "la)", 3], ["3", "la.", 3], ["z", "la.", 26],
        ["iv", "lr.", 4], ["IV.", "UR.", 4], ["xii", "lr)", 12], ["4", "UR.", 4],
        ["7", "D.", 7], ["07", "0D.", 7], [" 12 ", "D)", 12],
    ])("«%s» con %s → %i", (scritto, segno, atteso) => {
        expect(parseListStart(scritto, segno)).toBe(atteso);
    });

    test.each([
        // Una lettera in un elenco numerato, un romano sbagliato, zero, troppo.
        ["c", "D."], ["iiii", "lr."], ["vx", "lr."], ["0", "la."], ["27", "la."],
        ["aa", "la."], ["1000", "D."], ["", "D."], ["-2", "D."], ["2.5", "D."],
        // Una lettera che non è un numero romano, in un elenco romano.
        ["b", "lr."],
    ])("«%s» con %s non vale", (scritto, segno) => {
        expect(parseListStart(scritto, segno)).toBe(null);
    });
});

describe("un elenco a lettere non va oltre la z", () => {
    test("conta l'ultimo punto, non il primo", () => {
        expect(listFits("la.", 25, 2), "y, z").toBe(true);
        expect(listFits("UA)", 25, 3), "y, z e oltre").toBe(false);
        expect(listFits("la.", 26, 1), "la z da sola").toBe(true);
        expect(listFits("la.", 26, 0), "una lista vuota conta come un punto").toBe(true);
        expect(listFits("la.", 27, 0)).toBe(false);
    });

    test("numeri e romani non hanno quel limite", () => {
        expect(listFits("D.", 900, 200)).toBe(true);
        expect(listFits("lr)", 30, 10)).toBe(true);
        expect(listFits("0D.", 99, 5)).toBe(true);
    });

    test("i punti sono le voci della lista, non quelle annidate", () => {
        const f = campo("<ol><li>a<ol><li>x</li><li>y</li></ol></li><li>b</li></ol>");
        expect(itemCount(f.firstChild)).toBe(2);
    });
});
