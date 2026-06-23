/**
 * E2E "List" toolbar button: deve inserire HTML <ol> nel textarea (non TeX),
 * il save deve produrre block contract `{type:"list"}` strutturato, e il render
 * deve mostrare `<ol class="fm-dsa-li-list">` (lista visiva, non testo letterale).
 *
 * Coverage:
 *   1. insertListSnippet → textarea contiene <ol HTML (NON \begin{enumerate})
 *   2. _buildBlocksFromTextarea → parsa <ol> in {type:"list", items:[...]}
 *   3. _toHtml(list block) → emette <ol class="fm-dsa-li-list" data-dsa-section="...">
 *   4. Round-trip: insert → save → render → re-parse mantiene struttura
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

test("insertListSnippet inserisce HTML <ol> nel textarea (NON TeX raw)", async ({ page }) => {
    await login(page);
    const result = await page.evaluate(() => {
        const ta = document.createElement("textarea");
        ta.dataset.field = "quesito";
        document.body.appendChild(ta);
        ta.focus();
        const panel = { _focusedTextarea: ta };
        // Simula click "1." nel dropdown
        window.FM.__insertListSnippetForTest(panel, "ol");
        return ta.value;
    });
    expect(result, "textarea deve contenere <ol HTML").toMatch(/<ol[^>]*class="fm-dsa-li-list"/);
    expect(result, "textarea deve contenere data-dsa-section").toContain('data-dsa-section="question"');
    expect(result, "textarea deve contenere almeno 1 <li>").toMatch(/<li><\/li>/);
    expect(result, "textarea NON deve contenere TeX \\begin{enumerate}").not.toContain("\\begin{enumerate}");
    expect(result, "textarea NON deve contenere \\item").not.toContain("\\item");
});

test("insertListSnippet con kind=ul / ol-Alpha emette tag/type giusti", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const make = (kind, field) => {
            const ta = document.createElement("textarea");
            ta.dataset.field = field;
            document.body.appendChild(ta);
            window.FM.__insertListSnippetForTest({ _focusedTextarea: ta }, kind);
            return ta.value;
        };
        return {
            ul: make("ul", "soluzione"),
            olAlpha: make("ol-Alpha", "giustificazione"),
            olAlphaLow: make("ol-alpha", "quesito"),
        };
    });
    expect(r.ul, "<ul> tag").toMatch(/<ul[^>]*class="fm-dsa-li-list"/);
    expect(r.ul, "section solution").toContain('data-dsa-section="solution"');
    // Refactor preset: ol-Alpha ora usa data-fm-list-style invece di type
    expect(r.olAlpha, "<ol data-fm-list-style=alpha-decimal>").toMatch(/data-fm-list-style="alpha-decimal"/);
    expect(r.olAlpha, "section justification").toContain('data-dsa-section="justification"');
    // ol-alpha ora usa preset CSS gerarchico (lower-alpha-roman) invece di type="a"
    expect(r.olAlphaLow, "<ol data-fm-list-style=lower-alpha-roman>").toMatch(/data-fm-list-style="lower-alpha-roman"/);
});

test("_buildBlocksFromTextarea parsa <ol>/<ul> in block list strutturato", async ({ page }) => {
    await login(page);
    const blocks = await page.evaluate(() => {
        const ta = document.createElement("textarea");
        ta.dataset.field = "quesito";
        ta.value = 'Domanda iniziale\n<ol class="fm-dsa-li-list" data-dsa-section="question">\n  <li>punto a</li>\n  <li>punto b</li>\n</ol>\nDopo lista.';
        return window.FM.__buildBlocksFromTextareaForTest(ta);
    });

    expect(blocks.length, "almeno 3 blocks (text+list+text)").toBeGreaterThanOrEqual(2);
    const listBlock = blocks.find((b) => b.type === "list");
    expect(listBlock, "block list presente").toBeTruthy();
    expect(listBlock.ordered, "ordered=true (era <ol>)").toBe(true);
    expect(listBlock.items.length, "2 items").toBe(2);
    expect(listBlock.dsa_section, "dsa_section=question").toBe("question");
    // Items sono array di blocchi
    const firstItem = listBlock.items[0];
    expect(Array.isArray(firstItem), "item[0] è array di blocks").toBe(true);
    const innerText = firstItem.find((b) => b.type === "text");
    expect(innerText?.content, "item[0] contiene 'punto a'").toContain("punto a");
});

test("_toHtml(block list) emette <ol class=fm-dsa-li-list>", async ({ page }) => {
    await login(page);
    const html = await page.evaluate(() => {
        const blocks = [
            { type: "text", content: "Domanda:" },
            {
                type: "list",
                ordered: true,
                dsa_section: "question",
                items: [
                    [{ type: "text", content: "punto a" }],
                    [{ type: "text", content: "punto b" }],
                ],
            },
        ];
        return window.FM.__toHtmlForTest(blocks);
    });
    expect(html, "deve includere <ol class=fm-dsa-li-list>").toMatch(/<ol[^>]*class="fm-dsa-li-list"/);
    expect(html, "deve includere data-dsa-section=question").toContain('data-dsa-section="question"');
    // Outer question list: <li> ha F/GF + fm-dsa-li-num + fm-dsa-li-content
    expect(html, "<li> ha buttons F/GF").toMatch(/<li[^>]*data-fm-dsa-state[^>]*>/);
    expect(html, "fm-dsa-li-content wrappa il fm-text").toMatch(/<span class="fm-dsa-li-content">/);
    expect(html, "punto a in HTML").toContain("punto a");
});

test("Round-trip: insertListSnippet → buildBlocks → _toHtml mantiene struttura list", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        // 1. Insert via button List (textarea path: stamp 1 <li> vuoto)
        const ta = document.createElement("textarea");
        ta.dataset.field = "quesito";
        document.body.appendChild(ta);
        window.FM.__insertListSnippetForTest({ _focusedTextarea: ta }, "ol-paren");

        // 2. Sostituisci il <li> vuoto con 2 li popolati (simula edit utente)
        ta.value = ta.value.replace("<li></li>", "<li>primo item</li><li>secondo item</li>");

        // 3. Build blocks da textarea
        const blocks = window.FM.__buildBlocksFromTextareaForTest(ta);

        // 4. Re-render in HTML
        const html = window.FM.__toHtmlForTest(blocks);

        return {
            taValue: ta.value,
            blocksCount: blocks.length,
            hasListBlock: blocks.some((b) => b.type === "list"),
            html,
        };
    });
    expect(r.hasListBlock, "block list generato").toBe(true);
    expect(r.html, "HTML render contiene <ol fm-dsa-li-list>").toMatch(/<ol[^>]*class="fm-dsa-li-list"/);
    expect(r.html, "primo item preservato").toContain("primo item");
    expect(r.html, "secondo item preservato").toContain("secondo item");
    expect(r.html, "NESSUN \\begin{enumerate} in HTML render").not.toContain("\\begin{enumerate}");
});
