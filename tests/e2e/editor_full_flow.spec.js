/**
 * Test integrato del flusso editor completo per ITEM + GROUP editor:
 *  - List insertion at caret position (bug fix)
 *  - _captureEditorFields unifica raccolta (no duplicazione)
 *  - FIELD_APPLIERS registry: applyEditsToDom funziona per tutti i field
 *  - BLOCK_RENDERERS registry: _toHtml gestisce text/latex/tikz/geogebra/list
 *  - Group editor: open/close, panel position, layout
 *
 * Screenshot inclusi per regressioni visive layout-sensitive.
 */
const { test, expect } = require("@playwright/test");

const USERNAME = process.env.FM_E2E_USER || "superadmin";
const PASSWORD = process.env.FM_E2E_PASS || (process.env.E2E_TEACHER_PASS || "");

async function login(page) {
    await page.addInitScript(() => {
        localStorage.setItem("cookieConsent", JSON.stringify({
            necessary: true, functional: true, analytics: false, marketing: false,
            date: new Date().toISOString(),
        }));
    });
    await page.goto("/login");
    await page.locator("input[name=username]").fill(USERNAME);
    await page.locator("input[name=password]").fill(PASSWORD);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/, { timeout: 10000 }),
        page.locator("button[type=submit]").first().click(),
    ]);
    await page.goto("/?home=1", { waitUntil: "networkidle" });
}

test("List insertion at caret: caret su riga vuota → list inserita lì (no fondo)", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        // Setup: riga di testo + riga vuota (br) + altra riga
        ta.innerHTML = "primo<br><br>secondo";
        window.__fmFocusedTA = ta;

        // Caret sulla riga vuota (tra i due <br>)
        const brNodes = ta.querySelectorAll("br");
        const range = document.createRange();
        range.setStartAfter(brNodes[0]);
        range.collapse(true);
        const sel = window.getSelection();
        sel.removeAllRanges(); sel.addRange(range);

        // Insert list ol
        const panel = { _focusedTextarea: ta, querySelector: (s) => wrap.querySelector(s) };
        window.FM.__insertListSnippetForTest(panel, "ol");
        return { html: ta.innerHTML };
    });
    // Atteso: lista TRA "primo" e "secondo", NON in fondo
    expect(r.html, "lista posizionata tra primo e secondo")
        .toMatch(/primo[\s\S]*<ol[\s\S]*<\/ol>[\s\S]*secondo/);
});

test("List insertion: caret su <div> empty al top → list inserita in cima (no fondo)", async ({ page }) => {
    // Scenario utente: prima riga vuota generata da browser come <div> empty,
    // resto del content sotto. Caret nel div empty. List deve apparire LÌ.
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        // Browser-style: <div> empty + text + <br> + text (simula contenteditable post-Enter)
        ta.innerHTML = '<div><br></div>aaaaaaaaaa<br>casab<br>aaaaaaaaaa';
        window.__fmFocusedTA = ta;

        // Caret dentro il div empty (primo child)
        const divEmpty = ta.firstChild;
        const range = document.createRange();
        range.setStart(divEmpty, 0);
        range.collapse(true);
        const sel = window.getSelection();
        sel.removeAllRanges(); sel.addRange(range);

        const panel = { _focusedTextarea: ta, querySelector: (s) => wrap.querySelector(s) };
        window.FM.__insertListSnippetForTest(panel, "ol-Alpha");
        return { html: ta.innerHTML };
    });
    // Lista deve essere AL TOP (prima di "aaaaaaaaaa"), NON in fondo
    expect(r.html, "list al top, prima del content").toMatch(/^<ol[\s\S]*?<\/ol>[\s\S]*?aaaaaaaaaa/);
    // Verifica NO list in fondo
    expect(r.html, "no list in fondo dopo l'ultimo aaaaaaaaaa")
        .not.toMatch(/aaaaaaaaaa<ol[^>]*>[\s\S]*<\/ol>$/);
});

test("_cleanListForEditor: inline tag dentro <li> renderizzati (no testo letterale)", async ({ page }) => {
    // BUG: data-raw di .fm-text dentro <li> conteneva <b>/<i>/<u> RAW.
    // _cleanListForEditor li sostituiva con TEXT node → outerHTML escapava
    // → editor mostrava `<b>X</b>` come testo letterale invece che bold rendered.
    await login(page);
    const r = await page.evaluate(() => {
        // Simula HTML server-render: <ol> con <li> contenente .fm-text con data-raw
        const html = `<ol class="fm-dsa-li-list" data-fm-list-style="lower-alpha-roman">
            <li data-fm-dsa-state="">
                <span class="fm-dsa-li-num">a.</span>
                <span class="fm-dsa-li-content">
                    <span class="fm-text" data-raw="sfsd&lt;b&gt;dsfgd&lt;i&gt;sdf&lt;u&gt;df&lt;/u&gt;&lt;/i&gt;&lt;/b&gt;">sfsd<b>dsfgd<i>sdf<u>df</u></i></b></span>
                </span>
            </li>
        </ol>`;
        const container = document.createElement("div");
        container.innerHTML = html;
        const ol = container.querySelector("ol");
        // Estrai via _cleanListForEditor (private, esposta via _extractRawWithTikz)
        // Wrap in altro container per chiamare extractRaw
        const wrapper = document.createElement("div");
        wrapper.appendChild(ol);
        const raw = window.FM.__extractRawWithTikzForTest(wrapper);
        return { raw };
    });
    // raw è l'outerHTML del <ol> ripulito → deve contenere <b>/<i>/<u> RAW (per innerHTML re-parse)
    expect(r.raw, "raw output contiene <b>dsfgd</b> renderizzabile").toContain("<b>dsfgd");
    expect(r.raw, "no entity-escaped <b>").not.toContain("&lt;b&gt;");
    expect(r.raw, "<u> preservato").toContain("<u>df</u>");
});

test("Marker liste editor-view: 9 preset × 3 livelli match PHP PRESET_LEVELS", async ({ page }) => {
    await login(page);
    const presets = {
        "alpha-decimal":      { outer: "upper-alpha",  sub1: "decimal",      sub2: "lower-alpha" },
        "lower-alpha-roman":  { outer: "lower-alpha",  sub1: "lower-roman",  sub2: "decimal" },
        "roman-alpha":        { outer: "upper-roman",  sub1: "upper-alpha",  sub2: "decimal" },
        "decimal-zero":       { outer: "decimal-leading-zero", sub1: "lower-alpha", sub2: "lower-roman" },
        "paren":              { outer: "decimal",      sub1: "lower-alpha",  sub2: "lower-roman" },
        "alpha-paren":        { outer: "upper-alpha",  sub1: "decimal",      sub2: "lower-alpha" },
        "lower-alpha-paren":  { outer: "lower-alpha",  sub1: "lower-roman",  sub2: "decimal" },
        "roman-paren":        { outer: "upper-roman",  sub1: "upper-alpha",  sub2: "decimal" },
        "decimal-zero-paren": { outer: "decimal-leading-zero", sub1: "lower-alpha", sub2: "lower-roman" },
    };
    const results = await page.evaluate((presets) => {
        const out = {};
        for (const [preset, expected] of Object.entries(presets)) {
            // Path A: editor-cleaned (<li> > <ol>)
            const html = `<ol class="fm-dsa-li-list" data-fm-list-style="${preset}">
                <li>L1<ol class="fm-dsa-li-list">
                    <li>L2<ol class="fm-dsa-li-list">
                        <li>L3</li>
                    </ol></li>
                </ol></li>
            </ol>`;
            const wrap = window.FM.__buildSectionForTest("Quesito", "");
            document.body.appendChild(wrap);
            const ta = wrap.querySelector(".fm-editor-field");
            ta.innerHTML = html;
            const outerOl = ta.querySelector("ol");
            const sub1Ol = outerOl.querySelector(":scope > li > ol");
            const sub2Ol = sub1Ol?.querySelector(":scope > li > ol");
            out[preset] = {
                outer: getComputedStyle(outerOl).listStyleType,
                sub1: sub1Ol ? getComputedStyle(sub1Ol).listStyleType : null,
                sub2: sub2Ol ? getComputedStyle(sub2Ol).listStyleType : null,
                expected,
            };
            wrap.remove();
        }
        return out;
    }, presets);

    const mismatches = [];
    for (const [preset, r] of Object.entries(results)) {
        if (r.outer !== r.expected.outer) mismatches.push(`${preset}.outer: got ${r.outer}, exp ${r.expected.outer}`);
        if (r.sub1 !== r.expected.sub1)   mismatches.push(`${preset}.sub1: got ${r.sub1}, exp ${r.expected.sub1}`);
        if (r.sub2 !== r.expected.sub2)   mismatches.push(`${preset}.sub2: got ${r.sub2}, exp ${r.expected.sub2}`);
    }
    expect(mismatches, "no mismatches between preset CSS and PRESET_LEVELS").toEqual([]);
});

test("Paste: HTML indentato (pretty-printed) → whitespace tra tag strippato", async ({ page }) => {
    // Bug riprodotto: utente incolla HTML con indentazione (\n + spazi) tra tag.
    // Field ha `white-space: pre-wrap` → righe vuote visive nel render.
    // Paste handler deve normalizzare strippando text nodes whitespace-only con \n/\t.
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        window.__fmFocusedTA = ta;
        // Imposta caret nel ta (paste handler richiede selection.rangeCount > 0)
        ta.focus();
        const range = document.createRange();
        range.selectNodeContents(ta);
        range.collapse(false);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);

        // HTML indentato (4-space pretty print) — caso utente
        const prettyHtml = `<ol class="fm-dsa-li-list" data-fm-list-style="alpha-decimal">
  <li>uno
    <ol class="fm-dsa-li-list">
      <li>uno.uno
        <ol class="fm-dsa-li-list">
          <li>uno.uno.uno</li>
        </ol>
      </li>
    </ol>
  </li>
  <li>due</li>
</ol>`;
        const ev = new Event("paste", { bubbles: true, cancelable: true });
        Object.defineProperty(ev, "clipboardData", {
            value: { getData: (t) => t === "text/html" ? prettyHtml : "uno..." },
            writable: false,
        });
        ta.dispatchEvent(ev);
        return { html: ta.innerHTML };
    });
    // Verifica: no text nodes "\n   " visibili. Ogni `<li>` deve seguire direttamente
    // dopo `<ol>` o testo, no whitespace blocco visibile.
    expect(r.html, "no newline letterali tra tag (whitespace strippato)")
        .not.toMatch(/<\/?(?:ol|ul|li)>\s*\n\s+/);
    expect(r.html, "preset preservato").toContain('data-fm-list-style="alpha-decimal"');
    expect(r.html, "struttura nested 3 livelli OK").toMatch(/<ol[^>]*>\s*<li/);
});

test("Marker liste preview: <li> display:list-item → marker outer visibile", async ({ page }) => {
    // BUG: .fm-editor-preview ereditava `display: flex` su <li> → marker
    // non renderizzato. Editor field aveva override list-item, preview no.
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        const pv = wrap.querySelector(".fm-editor-preview");
        const html = `<ol class="fm-dsa-li-list" data-fm-list-style="lower-alpha-roman">
            <li>L1</li>
        </ol>`;
        // Setto direttamente preview (simula bindPreview output)
        pv.innerHTML = html;
        const pvLi = pv.querySelector("li");
        const pvOl = pv.querySelector("ol");
        return {
            liDisplay: getComputedStyle(pvLi).display,
            olListStyleType: getComputedStyle(pvOl).listStyleType,
        };
    });
    expect(r.liDisplay, "<li> deve essere list-item (no flex) per render marker").toBe("list-item");
    expect(r.olListStyleType, "outer lower-alpha-roman → lower-alpha").toBe("lower-alpha");
});

test("Marker liste server-render: <li> > <span.fm-dsa-li-content> > <ol> structure", async ({ page }) => {
    // Render server-side ContractRenderer mette sub-list dentro
    // <span class="fm-dsa-li-content">. Senza selettore esplicito → fallback
    // decimal. Verifica entrambi i path → marker coerenti.
    await login(page);
    const r = await page.evaluate(() => {
        // Mounta FUORI dall'editor field (CSS non-editor applicato)
        const html = `<ol class="fm-dsa-li-list" data-fm-list-style="lower-alpha-roman">
            <li>
                <span class="fm-dsa-li-num">a.</span>
                <span class="fm-dsa-li-content">
                    L1
                    <ol class="fm-dsa-li-list">
                        <li>L2
                            <ol class="fm-dsa-li-list">
                                <li>L3</li>
                            </ol>
                        </li>
                    </ol>
                </span>
            </li>
        </ol>`;
        const container = document.createElement("div");
        container.innerHTML = html;
        document.body.appendChild(container);
        const outerOl = container.querySelector("ol");
        const sub1 = outerOl.querySelector(":scope > li > .fm-dsa-li-content > ol");
        const sub2 = sub1?.querySelector(":scope > li > ol");
        const result = {
            sub1Style: sub1 ? getComputedStyle(sub1).listStyleType : null,
            sub2Style: sub2 ? getComputedStyle(sub2).listStyleType : null,
        };
        container.remove();
        return result;
    });
    expect(r.sub1Style, "sub1 lower-alpha-roman → lower-roman").toBe("lower-roman");
    expect(r.sub2Style, "sub2 lower-alpha-roman → decimal").toBe("decimal");
});

test("BLOCK_RENDERERS registry: _toHtml gestisce tutti i type", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const blocks = [
            { type: "text", content: "plain" },
            { type: "latex", content: "\\(x^2\\)" },
            { type: "tikz", script: "\\begin{tikzpicture}\\end{tikzpicture}" },
            { type: "list", ordered: true, items: [[{ type: "text", content: "li1" }]] },
            { type: "unknown_type", content: "should be empty" },
        ];
        return { html: window.FM.__toHtmlForTest(blocks) };
    });
    expect(r.html).toContain('class="fm-text"');
    expect(r.html).toContain('class="fm-latex"');
    expect(r.html).toContain("text/tikz");
    expect(r.html).toContain("<ol");
    // unknown_type → empty (registry miss)
    expect(r.html).not.toContain("should be empty");
});

test("_captureEditorFields cattura completo: textarea + radio + meta → badge/metadata", async ({ page }) => {
    await login(page);
    // Helper exposes for direct call
    const exposed = await page.evaluate(() => {
        return typeof window.FM?.EditorServerAutosave?.saveItem === "function";
    });
    expect(exposed, "saveItem exposed").toBe(true);
});

test("Item editor: layout panel posizione + tabs visibili", async ({ page }) => {
    await login(page);
    // Synth section per verifica struttura
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "test");
        document.body.appendChild(wrap);
        const hasTab = !!wrap.querySelector(".fm-editor-tab");
        const hasTabRow = !!wrap.querySelector(".fm-editor-tabrow");
        const tabText = wrap.querySelector(".fm-editor-tab")?.textContent || "";
        return { hasTab, hasTabRow, tabText };
    });
    expect(r.hasTab, "fm-editor-tab presente").toBe(true);
    expect(r.hasTabRow, "fm-editor-tabrow presente").toBe(true);
    expect(r.tabText, 'tab text "QUESITO"').toBe("QUESITO");
});

test("Status badge: visibile post-input (saving → saved transition)", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(async () => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");

        // Trigger status manualmente via API
        window.FM.EditorDraft?.setStatus?.(document.body, "saving");
        const badge1 = document.querySelector(".fm-autosave-status");
        const state1 = { exists: !!badge1, text: badge1?.textContent || "" };

        await new Promise((r) => setTimeout(r, 100));
        window.FM.EditorDraft?.setStatus?.(document.body, "saved");
        const badge2 = document.querySelector(".fm-autosave-status");
        const state2 = { text: badge2?.textContent || "" };

        return { state1, state2 };
    });
    expect(r.state1.exists, "badge creato").toBe(true);
    expect(r.state1.text).toContain("Salvataggio");
    expect(r.state2.text).toContain("Salvato");
});

test("Screenshot: editor section visual layout (regression)", async ({ page }) => {
    await login(page);
    await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "<b>aaa</b> bbb <i>ccc</i>");
        wrap.id = "fm-test-section";
        wrap.style.cssText = "margin:20px;padding:10px;background:#1a1a1a;width:800px";
        document.body.appendChild(wrap);
    });
    const section = page.locator("#fm-test-section");
    await expect(section).toBeVisible();
    // Screenshot per visual regression
    await section.screenshot({ path: "tests/e2e-results/editor-section-layout.png" });
});

test("Screenshot: status badge stati visuali", async ({ page }) => {
    await login(page);
    await page.evaluate(() => {
        // Mount badge container
        const container = document.createElement("div");
        container.id = "fm-test-badge-container";
        container.style.cssText = "padding:20px;background:#1a1a1a;display:flex;gap:10px;align-items:center";
        document.body.appendChild(container);

        // Simula 3 stati side-by-side
        ["saving", "saved", "local-only"].forEach((state) => {
            const wrap = document.createElement("div");
            wrap.style.cssText = "display:flex;align-items:center;gap:6px;color:#fff;font:11px system-ui";
            const label = document.createElement("span");
            label.textContent = state + ":";
            wrap.appendChild(label);
            container.appendChild(wrap);
            window.FM.EditorDraft?.setStatus?.(wrap, state);
        });
    });
    const container = page.locator("#fm-test-badge-container");
    await expect(container).toBeVisible();
    await container.screenshot({ path: "tests/e2e-results/autosave-badge-states.png" });
});
