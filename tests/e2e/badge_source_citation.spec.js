// Test E2E: il badge .fm-badge include la citazione fonte
// (book/volume/authors) quando l'item ha source_key valido.
const { test, expect } = require("@playwright/test");

test.describe("Badge fonte/citazione", () => {
    test("loginAsTeacher + verifica P-351 ex 255: badge contiene array citazione", async ({ page, context }) => {
        await page.goto("/login");
        await page.fill('input[name="username"]', "superadmin");
        await page.fill('input[name="password"]', (process.env.E2E_TEACHER_PASS || ""));
        await Promise.all([
            page.waitForURL(/^(?!.*\/login).*/, { timeout: 10000 }),
            page.click('button[type="submit"]'),
        ]);

        // Apri esercizio SCI 1 MAT — ne carica le verifiche correlate via XHR
        await page.goto("/studio/esercizio/SCI/1/MAT/4.0?ids=53", { waitUntil: "domcontentloaded" });
        // Attesa: fetch related-verifiche.html + MathJax typeset
        await page.waitForTimeout(5000);

        // Trova il primo badge con source-key
        // Debug: dump page state
        const url = page.url();
        const title = await page.title();
        const fmBadgeAny = await page.locator('.fm-badge').count();
        const probItems = await page.locator('.fm-contract-wrap, .fm-groupcollex').count();
        console.log(`URL=${url} title="${title}" fm-badge=${fmBadgeAny} contract-wrap/problem=${probItems}`);

        const badges = page.locator('.fm-badge[data-source]');
        const count = await badges.count();
        console.log(`Trovati ${count} badge con data-source`);
        if (count === 0) {
            const html = await page.content();
            console.log("Body head:\n" + html.substring(0, 2000));
        }
        expect(count).toBeGreaterThan(0);

        // Per ogni badge: verifica che data-raw contenga \begin{array}
        // (citation present) e che il rendered DOM contenga elementi mjx-mtable
        // (MathJax ha typeset l'array).
        let withCitation = 0;
        let renderedAsTable = 0;
        for (let i = 0; i < Math.min(count, 5); i++) {
            const badge = badges.nth(i);
            const dataRaw = await badge.getAttribute("data-raw") || "";
            const dataSource = await badge.getAttribute("data-source") || "";
            const hasArray = dataRaw.includes("\\begin{array}");
            if (hasArray) withCitation++;
            // MathJax typeset: cerca <mjx-mtable> nel badge
            const mtable = await badge.locator("mjx-mtable").count();
            if (mtable > 0) renderedAsTable++;
            console.log(`Badge ${i}: source=${dataSource} hasArray=${hasArray} mjx-mtable=${mtable}`);
        }
        expect(withCitation).toBeGreaterThan(0); // almeno 1 con citazione
        expect(renderedAsTable).toBeGreaterThan(0); // MathJax ha renderizzato come tabella
    });
});
