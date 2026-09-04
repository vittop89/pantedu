/**
 * Test parametrizzato di tutti i 13 preset list dropdown.
 * Verifica per ogni preset:
 *   1. data-fm-list-style attribute persisted on <ol>/<ul>
 *   2. tag corretto (ol vs ul)
 *   3. NESSUN type="A/a/I" (preset CSS-driven)
 *   4. Round-trip: insert → buildBlocks (list_preset) → _toHtml mantiene preset
 *
 * Comportamento atteso (sub-elenchi gerarchici):
 *   • ○ ■            (default ul: disc → circle → square)
 *   ➤ ♦ ●            (arrow-bullet)
 *   ★ ○ ■            (star-circle)
 *   1. a. i.         (default ol: decimal → lower-alpha → lower-roman)
 *   A. 1. a.         (alpha-decimal)
 *   a. i. 1.         (lower-alpha-roman)
 *   I. A. 1.         (roman-alpha)
 *   01. a. i.        (decimal-zero)
 *   1) a) i)         (paren)
 *   A) 1) a)         (alpha-paren)
 *   a) i) 1)         (lower-alpha-paren)
 *   I) A) 1)         (roman-paren)
 *   01) a) i)        (decimal-zero-paren)
 */
const { test, expect } = require("@playwright/test");

const USERNAME = process.env.FM_E2E_USER || "superadmin";
const PASSWORD = process.env.FM_E2E_PASS || (process.env.E2E_TEACHER_PASS || "");

const PRESETS = [
    { kind: "ul",                 tag: "ul", listStyle: "" },
    { kind: "ul-arrow",           tag: "ul", listStyle: "arrow-bullet" },
    { kind: "ul-star",            tag: "ul", listStyle: "star-circle" },
    { kind: "ol",                 tag: "ol", listStyle: "" },
    { kind: "ol-Alpha",           tag: "ol", listStyle: "alpha-decimal" },
    { kind: "ol-alpha",           tag: "ol", listStyle: "lower-alpha-roman" },
    { kind: "ol-Roman",           tag: "ol", listStyle: "roman-alpha" },
    { kind: "ol-zero",            tag: "ol", listStyle: "decimal-zero" },
    { kind: "ol-paren",           tag: "ol", listStyle: "paren" },
    { kind: "ol-Alpha-paren",     tag: "ol", listStyle: "alpha-paren" },
    { kind: "ol-alpha-paren",     tag: "ol", listStyle: "lower-alpha-paren" },
    { kind: "ol-Roman-paren",     tag: "ol", listStyle: "roman-paren" },
    { kind: "ol-zero-paren",      tag: "ol", listStyle: "decimal-zero-paren" },
];

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

for (const preset of PRESETS) {
    test(`Preset ${preset.kind} → tag=${preset.tag} listStyle=${preset.listStyle || "(default)"}`, async ({ page }) => {
        await login(page);
        const r = await page.evaluate((cfg) => {
            const wrap = window.FM.__buildSectionForTest("Quesito", "");
            document.body.appendChild(wrap);
            const field = wrap.querySelector(".fm-editor-field");
            field.focus();
            window.FM.__insertListSnippetForTest({ _focusedTextarea: field }, cfg.kind);
            const list = field.querySelector(`${cfg.tag}.fm-dsa-li-list`);
            return {
                tag: list?.tagName.toLowerCase(),
                preset: list?.getAttribute("data-fm-list-style") || "",
                type: list?.getAttribute("type"),
            };
        }, preset);
        expect(r.tag, `tag = ${preset.tag}`).toBe(preset.tag);
        expect(r.preset, `data-fm-list-style = ${preset.listStyle || "(empty)"}`).toBe(preset.listStyle);
        expect(r.type, `no type attr (preset CSS-driven)`).toBeFalsy();
    });
}

test("Round-trip ALL presets: insert → buildBlocks → _toHtml preserva data-fm-list-style", async ({ page }) => {
    await login(page);
    const r = await page.evaluate((presets) => {
        const out = {};
        for (const p of presets) {
            const ta = document.createElement("textarea");
            ta.dataset.field = "quesito";
            document.body.appendChild(ta);
            window.FM.__insertListSnippetForTest({ _focusedTextarea: ta }, p.kind);
            const blocks = window.FM.__buildBlocksFromTextareaForTest(ta);
            const list = blocks.find((b) => b.type === "list");
            const html = window.FM.__toHtmlForTest(blocks);
            out[p.kind] = {
                blockPreset: list?.list_preset || "",
                htmlHasPreset: p.listStyle ? html.includes(`data-fm-list-style="${p.listStyle}"`) : true,
            };
            ta.remove();
        }
        return out;
    }, PRESETS);

    for (const p of PRESETS) {
        expect(r[p.kind].blockPreset, `${p.kind}: block.list_preset preserved`).toBe(p.listStyle);
        expect(r[p.kind].htmlHasPreset, `${p.kind}: HTML emette data-fm-list-style`).toBe(true);
    }
});
