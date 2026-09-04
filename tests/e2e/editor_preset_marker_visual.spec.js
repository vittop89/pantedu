/**
 * Verifica VISUAL che ogni preset in edit mode (.fm-editor-field) produca
 * il marker corretto via CSS list-style-type.
 *
 * Test injects layout.css completa via addStyleTag, poi computa
 * `getComputedStyle(ol).listStyleType` per ogni preset.
 */
const { test, expect } = require("@playwright/test");
const fs = require("fs");
const path = require("path");

const USERNAME = process.env.FM_E2E_USER || "superadmin";
const PASSWORD = process.env.FM_E2E_PASS || (process.env.E2E_TEACHER_PASS || "");

// preset → expected list-style-type for OUTER level (computed by browser)
const EXPECTED = [
    { preset: "",                   expect: "decimal" },           // default ol
    { preset: "alpha-decimal",      expect: "upper-alpha" },
    { preset: "lower-alpha-roman",  expect: "lower-alpha" },
    { preset: "roman-alpha",        expect: "upper-roman" },
    { preset: "decimal-zero",       expect: "decimal-leading-zero" },
    { preset: "paren",              expect: "decimal" },           // suffisso ) via ::marker
    { preset: "alpha-paren",        expect: "upper-alpha" },
    { preset: "lower-alpha-paren",  expect: "lower-alpha" },
    { preset: "roman-paren",        expect: "upper-roman" },
    { preset: "decimal-zero-paren", expect: "decimal-leading-zero" },
];

const CSS_PATH = path.resolve(__dirname, "..", "..", "css", "layout.css");
const FULL_CSS = fs.readFileSync(CSS_PATH, "utf8");

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
    // Inietta layout.css completa per i test computed style.
    await page.addStyleTag({ content: FULL_CSS });
}

for (const { preset, expect: expectedType } of EXPECTED) {
    test(`Preset ${preset || "(default)"} → list-style-type=${expectedType}`, async ({ page }) => {
        await login(page);
        const result = await page.evaluate((cfg) => {
            const wrap = window.FM.__buildSectionForTest("Quesito", "");
            document.body.appendChild(wrap);
            const field = wrap.querySelector(".fm-editor-field");
            // Inserisci ol con preset (struttura sintetica, no F/GF buttons)
            const presetAttr = cfg.preset ? ` data-fm-list-style="${cfg.preset}"` : "";
            field.innerHTML = `<ol class="fm-dsa-li-list" data-dsa-section="question"${presetAttr}><li>uno</li><li>due</li><li>tre</li></ol>`;
            const ol = field.querySelector("ol.fm-dsa-li-list");
            return getComputedStyle(ol).listStyleType;
        }, { preset });
        expect(result, `preset=${preset || "(default)"}`).toBe(expectedType);
    });
}
