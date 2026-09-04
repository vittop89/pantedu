// Test E2E: dopo la normalizzazione TikZ tools/tikz_normalize.php
// verifica che gli script <script type="text/tikz"> presenti su una
// pagina /studio/esercizio vengano risolti via VPS (200/304) e
// sostituiti con <svg> inline. Conta errori 422 (compile_failed).
const { test, expect } = require("@playwright/test");

const URLS = [
    "/studio/esercizio/SCI/2/FIS/2.0",   // Moti nel piano (TikZ + shadings)
    "/studio/esercizio/SCI/2/MAT/2.0",   // Sistemi lineari
    "/studio/esercizio/ART/4/MAT/4.0",   // Trigonometria (legacy pgf→tikz)
];

test.describe("TikZ post-normalize render", () => {
    test.beforeEach(async ({ page }) => {
        await page.goto("/login");
        await page.fill('input[name="username"]', "superadmin");
        await page.fill('input[name="password"]', (process.env.E2E_TEACHER_PASS || ""));
        await Promise.all([
            page.waitForURL(/^(?!.*\/login).*/, { timeout: 10000 }),
            page.click('button[type="submit"]'),
        ]);
    });

    for (const url of URLS) {
        test(`render TikZ blocks on ${url}`, async ({ page }) => {
            const renderCalls = [];
            page.on("response", async (r) => {
                const u = r.url();
                if (u.includes("/tikz/render")) {
                    renderCalls.push({ status: r.status(), method: r.request().method(), url: u });
                }
            });
            const consoleErr = [];
            page.on("console", (m) => {
                if (m.type() === "error") consoleErr.push(m.text());
            });

            await page.goto(url, { waitUntil: "domcontentloaded" });
            // Snapshot iniziale prima che tikzjax/render-client consumi gli script
            const tikzInitial = await page.locator('script[type="text/tikz"]').count();
            console.log(`  tikz scripts (init): ${tikzInitial}`);

            // Attendi rendering (tikzjax WASM o VPS pipeline)
            await page.waitForTimeout(20000);

            const tikzScriptCount = await page.locator('script[type="text/tikz"]').count();
            const svgCount = await page.locator('svg').count();
            const compileErrors = renderCalls.filter(c => c.status === 422);
            const okRenders = renderCalls.filter(c => c.status === 200 && c.method === "POST");
            const cacheHits = renderCalls.filter(c => c.status === 200 && c.method === "GET");
            const tikzErrors = consoleErr.filter(e => /tikz|pdflatex|compile/i.test(e));
            // Cerca errori di rendering visibili nel DOM (overlay errore tikzjax)
            const errOverlays = await page.locator('.tikz-error, [data-tikz-error]').count();
            const url2 = page.url();
            const verifPanels = await page.locator('.fm-related-verifica, .fm-contract-wrap, .fm-groupcollex').count();

            console.log(`URL=${url}`);
            console.log(`  page url:        ${url2}`);
            console.log(`  related panels:  ${verifPanels}`);
            console.log(`  tikz init:       ${tikzInitial}`);
            console.log(`  tikz remaining:  ${tikzScriptCount}`);
            console.log(`  svg total:       ${svgCount}`);
            console.log(`  render calls:    ${renderCalls.length}`);
            console.log(`  POST 200:        ${okRenders.length}`);
            console.log(`  GET 200 (cache): ${cacheHits.length}`);
            console.log(`  compile errors:  ${compileErrors.length}`);
            console.log(`  console tikz err:${tikzErrors.length}`);
            console.log(`  err overlays:    ${errOverlays}`);
            if (compileErrors.length) {
                console.log("  --- 422 details ---");
                for (const e of compileErrors.slice(0, 3)) console.log(`    ${e.url}`);
            }
            if (tikzErrors.length) {
                console.log("  --- console err ---");
                for (const e of tikzErrors.slice(0, 3)) console.log(`    ${e}`);
            }

            // Hard fail: nessun 422 (compile error VPS) e nessun overlay tikz-error
            expect(compileErrors.length, `${url}: nessun TikZ deve fallire compile (422)`).toBe(0);
            expect(errOverlays, `${url}: nessun overlay errore tikzjax/render`).toBe(0);
        });
    }
});
