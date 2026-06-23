/**
 * Test completo TUTTI i preset liste (11 ordered + 2 unordered + defaults):
 *
 * Per ogni preset verifica:
 *  A) CSS editor source view (`.fm-editor-field`): list-style-type computed
 *     match della convenzione `PRESET_LEVELS` PHP
 *  B) CSS editor preview view (`.fm-editor-preview`): stesso match
 *  C) Roundtrip save: _buildBlocksFromTextarea → block.list_preset preservato
 *     → _toHtml emette `data-fm-list-style` corretto
 *  D) Screenshot visual grid (regressione UI)
 *
 * Convenzione PRESET_LEVELS (mirror PHP Sanitizer):
 *   outer / sub1 / sub2
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

// Mapping preset → expected CSS list-style-type per livello (3-deep nesting).
// Mirror PHP `Sanitizer::PRESET_LEVELS` per OL, + bullet patterns per UL.
const ORDERED_PRESETS = {
    "alpha-decimal":      { outer: "upper-alpha",  sub1: "decimal",       sub2: "lower-alpha" },
    "lower-alpha-roman":  { outer: "lower-alpha",  sub1: "lower-roman",   sub2: "decimal" },
    "roman-alpha":        { outer: "upper-roman",  sub1: "upper-alpha",   sub2: "decimal" },
    "decimal-zero":       { outer: "decimal-leading-zero", sub1: "lower-alpha", sub2: "lower-roman" },
    "paren":              { outer: "decimal",      sub1: "lower-alpha",   sub2: "lower-roman" },
    "alpha-paren":        { outer: "upper-alpha",  sub1: "decimal",       sub2: "lower-alpha" },
    "lower-alpha-paren":  { outer: "lower-alpha",  sub1: "lower-roman",   sub2: "decimal" },
    "roman-paren":        { outer: "upper-roman",  sub1: "upper-alpha",   sub2: "decimal" },
    "decimal-zero-paren": { outer: "decimal-leading-zero", sub1: "lower-alpha", sub2: "lower-roman" },
};

// =============================================================================
// (A) Editor view: CSS computed style match per ogni preset
// =============================================================================
test("(A) Editor view: 9 ordered preset × 3 livelli computed list-style-type", async ({ page }) => {
    await login(page);
    const results = await page.evaluate((presets) => {
        const out = {};
        for (const [preset, expected] of Object.entries(presets)) {
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
    }, ORDERED_PRESETS);

    const mismatches = [];
    for (const [preset, r] of Object.entries(results)) {
        if (r.outer !== r.expected.outer) mismatches.push(`${preset}.outer: got '${r.outer}', exp '${r.expected.outer}'`);
        if (r.sub1 !== r.expected.sub1)   mismatches.push(`${preset}.sub1: got '${r.sub1}', exp '${r.expected.sub1}'`);
        if (r.sub2 !== r.expected.sub2)   mismatches.push(`${preset}.sub2: got '${r.sub2}', exp '${r.expected.sub2}'`);
    }
    expect(mismatches, "all preset CSS match expected").toEqual([]);
});

// =============================================================================
// (B) Preview view: CSS computed style match (preview mountato in .fm-editor-preview)
// =============================================================================
test("(B) Preview view: 9 ordered preset × 3 livelli CSS coerente con editor", async ({ page }) => {
    await login(page);
    const results = await page.evaluate((presets) => {
        const out = {};
        for (const [preset, expected] of Object.entries(presets)) {
            const html = `<ol class="fm-dsa-li-list" data-fm-list-style="${preset}">
                <li>L1<ol class="fm-dsa-li-list">
                    <li>L2<ol class="fm-dsa-li-list">
                        <li>L3</li>
                    </ol></li>
                </ol></li>
            </ol>`;
            const wrap = window.FM.__buildSectionForTest("Quesito", "");
            document.body.appendChild(wrap);
            // Mount in PREVIEW pane (not editor field)
            const pv = wrap.querySelector(".fm-editor-preview");
            pv.innerHTML = html;
            const outerOl = pv.querySelector("ol");
            const sub1Ol = outerOl.querySelector(":scope > li > ol");
            const sub2Ol = sub1Ol?.querySelector(":scope > li > ol");
            out[preset] = {
                outer: getComputedStyle(outerOl).listStyleType,
                sub1: sub1Ol ? getComputedStyle(sub1Ol).listStyleType : null,
                sub2: sub2Ol ? getComputedStyle(sub2Ol).listStyleType : null,
                liDisplay: getComputedStyle(outerOl.querySelector("li")).display,
                expected,
            };
            wrap.remove();
        }
        return out;
    }, ORDERED_PRESETS);

    const mismatches = [];
    for (const [preset, r] of Object.entries(results)) {
        if (r.liDisplay !== "list-item") mismatches.push(`${preset}.liDisplay: got '${r.liDisplay}', exp 'list-item' (marker invisibile)`);
        if (r.outer !== r.expected.outer) mismatches.push(`${preset}.outer: got '${r.outer}', exp '${r.expected.outer}'`);
        if (r.sub1 !== r.expected.sub1)   mismatches.push(`${preset}.sub1: got '${r.sub1}', exp '${r.expected.sub1}'`);
        if (r.sub2 !== r.expected.sub2)   mismatches.push(`${preset}.sub2: got '${r.sub2}', exp '${r.expected.sub2}'`);
    }
    expect(mismatches, "preview CSS match expected per tutti i preset").toEqual([]);
});

// =============================================================================
// (B2) Unordered preset (bullet)
// =============================================================================
test("(B2) Unordered preset (arrow-bullet, star-circle) CSS markers", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const out = {};
        const presets = ["arrow-bullet", "star-circle"];
        for (const preset of presets) {
            const html = `<ul class="fm-dsa-li-list" data-fm-list-style="${preset}">
                <li>L1<ul class="fm-dsa-li-list">
                    <li>L2<ul class="fm-dsa-li-list">
                        <li>L3</li>
                    </ul></li>
                </ul></li>
            </ul>`;
            const wrap = window.FM.__buildSectionForTest("Quesito", "");
            document.body.appendChild(wrap);
            const ta = wrap.querySelector(".fm-editor-field");
            ta.innerHTML = html;
            const outerUl = ta.querySelector("ul");
            const sub1 = outerUl.querySelector(":scope > li > ul");
            const sub2 = sub1?.querySelector(":scope > li > ul");
            out[preset] = {
                sub1Style: sub1 ? getComputedStyle(sub1).listStyleType : null,
                sub2Style: sub2 ? getComputedStyle(sub2).listStyleType : null,
            };
            wrap.remove();
        }
        return out;
    });
    expect(r["arrow-bullet"].sub1Style).toBe("square");
    expect(r["arrow-bullet"].sub2Style).toBe("disc");
    expect(r["star-circle"].sub1Style).toBe("circle");
    expect(r["star-circle"].sub2Style).toBe("square");
});

// =============================================================================
// (C) Roundtrip: blocks parse → preset preserved → _toHtml emit data-fm-list-style
// =============================================================================
test("(C) Roundtrip save: ogni preset preserva attributo data-fm-list-style", async ({ page }) => {
    await login(page);
    const r = await page.evaluate((presets) => {
        const out = {};
        for (const preset of Object.keys(presets)) {
            const html = `<ol class="fm-dsa-li-list" data-fm-list-style="${preset}"><li>X<ol class="fm-dsa-li-list"><li>Y</li></ol></li></ol>`;
            const wrap = window.FM.__buildSectionForTest("Quesito", "");
            document.body.appendChild(wrap);
            const ta = wrap.querySelector(".fm-editor-field");
            ta.innerHTML = html;
            // Parse blocks (mirror save flow)
            const blocks = window.FM.__buildBlocksFromTextareaForTest(ta);
            const listBlock = blocks.find((b) => b.type === "list");
            // Render back via _toHtml
            const renderedHtml = window.FM.__toHtmlForTest(blocks);
            out[preset] = {
                blockPreset: listBlock?.list_preset || null,
                renderedHasPreset: renderedHtml.includes(`data-fm-list-style="${preset}"`),
            };
            wrap.remove();
        }
        return out;
    }, ORDERED_PRESETS);
    const mismatches = [];
    for (const [preset, info] of Object.entries(r)) {
        if (info.blockPreset !== preset) mismatches.push(`${preset}: blockPreset = '${info.blockPreset}'`);
        if (!info.renderedHasPreset)     mismatches.push(`${preset}: _toHtml output non contiene data-fm-list-style`);
    }
    expect(mismatches, "roundtrip preserva preset per tutti").toEqual([]);
});

// =============================================================================
// (D) Visual screenshot grid: 11 preset side-by-side
// =============================================================================
test("(D) Visual grid screenshot: tutti i 11 preset side-by-side (regression)", async ({ page }) => {
    await login(page);
    await page.evaluate(() => {
        const presets = [
            { v: "", label: "default (1.a.i)", tag: "ol" },
            { v: "alpha-decimal", label: "A.1.a", tag: "ol" },
            { v: "lower-alpha-roman", label: "a.i.1", tag: "ol" },
            { v: "roman-alpha", label: "I.A.1", tag: "ol" },
            { v: "decimal-zero", label: "01.a.i", tag: "ol" },
            { v: "paren", label: "1)a)i)", tag: "ol" },
            { v: "alpha-paren", label: "A)1)a)", tag: "ol" },
            { v: "lower-alpha-paren", label: "a)i)1)", tag: "ol" },
            { v: "roman-paren", label: "I)A)1)", tag: "ol" },
            { v: "decimal-zero-paren", label: "01)a)i)", tag: "ol" },
            { v: "arrow-bullet", label: "➤♦●", tag: "ul" },
            { v: "star-circle", label: "★○■", tag: "ul" },
        ];
        const grid = document.createElement("div");
        grid.id = "fm-test-presets-grid";
        grid.style.cssText = "display:grid;grid-template-columns:repeat(4, 1fr);gap:14px;padding:14px;background:#1a1a1a;color:#fff;font:13px system-ui";
        for (const p of presets) {
            const cell = document.createElement("div");
            cell.style.cssText = "background:#0e0e0e;padding:10px;border-radius:4px;min-height:140px";
            const presetAttr = p.v ? ` data-fm-list-style="${p.v}"` : "";
            cell.innerHTML = `
                <div style="font-weight:700;margin-bottom:6px;color:#fbbf24">${p.label} <span style="font-weight:400;color:#888;font-size:10px">(${p.v || "default"})</span></div>
                <div class="fm-editor-field">
                    <${p.tag} class="fm-dsa-li-list"${presetAttr}>
                        <li>uno
                            <${p.tag} class="fm-dsa-li-list">
                                <li>uno.uno
                                    <${p.tag} class="fm-dsa-li-list">
                                        <li>uno.uno.uno</li>
                                    </${p.tag}>
                                </li>
                            </${p.tag}>
                        </li>
                        <li>due</li>
                    </${p.tag}>
                </div>
            `;
            grid.appendChild(cell);
        }
        document.body.appendChild(grid);
    });
    const grid = page.locator("#fm-test-presets-grid");
    await expect(grid).toBeVisible();
    await grid.screenshot({ path: "tests/e2e-results/preset-list-grid-all.png" });
});
