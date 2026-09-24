/** Unit smoke tests — HTML/TeX escape utilities (pure functions). */
import { describe, test, expect } from "vitest";
import {
    escHtml, escapeHtml, escHtmlStrict, escAttr, nl2br,
    containsInlineHtml, escTexJs,
} from "../../js/modules/editor/html-text-utils.js";

describe("escHtml", () => {
    test("escape standard chars", () => {
        expect(escHtml(`<a href="x">b&c</a>`)).toBe("&lt;a href=&quot;x&quot;&gt;b&amp;c&lt;/a&gt;");
    });
    test("apostrofo &#39;", () => {
        expect(escHtml("it's")).toBe("it&#39;s");
    });
    test("null safe", () => {
        expect(escHtml(null)).toBe("");
        expect(escHtml(undefined)).toBe("");
    });
});

describe("escHtmlStrict", () => {
    test("apostrofo &#039; (ContractRenderer compat)", () => {
        expect(escHtmlStrict("it's")).toBe("it&#039;s");
    });
});

describe("escapeHtml (with DomUtils fallback)", () => {
    test("standard escape via fallback", () => {
        expect(escapeHtml("<x>")).toBe("&lt;x&gt;");
    });
    test("null-safe via ??", () => {
        expect(escapeHtml(null)).toBe("");
    });
});

describe("escAttr (alias escHtmlStrict)", () => {
    test("identical to escHtmlStrict", () => {
        expect(escAttr(`"'<>`)).toBe(escHtmlStrict(`"'<>`));
    });
});

describe("nl2br", () => {
    test("newline → <br>", () => {
        expect(nl2br("a\nb\nc")).toBe("a<br>b<br>c");
    });
    test("no newline = passthrough", () => {
        expect(nl2br("plain")).toBe("plain");
    });
});

describe("containsInlineHtml", () => {
    test("detect b/i/u/etc", () => {
        expect(containsInlineHtml("<b>x</b>")).toBe(true);
        expect(containsInlineHtml("<em>y</em>")).toBe(true);
        expect(containsInlineHtml("<span class=x>z</span>")).toBe(true);
    });
    test("no match plain text", () => {
        expect(containsInlineHtml("plain text")).toBe(false);
    });
    test("no match block tags", () => {
        expect(containsInlineHtml("<div>x</div>")).toBe(false);
        expect(containsInlineHtml("<p>x</p>")).toBe(false);
    });
});

describe("escTexJs", () => {
    test("escape TeX special chars", () => {
        expect(escTexJs("&")).toBe("\\&");
        expect(escTexJs("#")).toBe("\\#");
        expect(escTexJs("$")).toBe("\\$");
        expect(escTexJs("%")).toBe("\\%");
        expect(escTexJs("_")).toBe("\\_");
        expect(escTexJs("{")).toBe("\\{");
        expect(escTexJs("}")).toBe("\\}");
    });
    test("escape special multichar", () => {
        expect(escTexJs("\\")).toBe("\\textbackslash{}");
        expect(escTexJs("~")).toBe("\\textasciitilde{}");
        expect(escTexJs("^")).toBe("\\textasciicircum{}");
    });
    test("passthrough chars normali", () => {
        expect(escTexJs("hello world 123")).toBe("hello world 123");
    });
    test("combined string", () => {
        expect(escTexJs("a&b_c")).toBe("a\\&b\\_c");
    });
});
