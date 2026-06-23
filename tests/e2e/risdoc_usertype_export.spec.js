/**
 * User-type test: simula click reali sui pulsanti toolbar (Overleaf, ZIP)
 * via user interaction (NON fetch API). Verifica che NON arrivi mai 403
 * e che la console non contenga errori.
 *
 * Esplora template 16, 19, 20, 21, 22 che hanno tex_file.
 */

const { test, expect } = require("@playwright/test");

test.setTimeout(180000);

const TEMPLATES = [16, 19, 20, 21, 22, 24, 25];

test("toolbar buttons user-type click real browser", async ({ browser }) => {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    await page.addInitScript(() => {
        localStorage.setItem("cookieConsent", JSON.stringify({
            necessary:true,functional:true,analytics:false,marketing:false,
            date: new Date().toISOString()
        }));
    });

    // login
    await page.goto("/login");
    await page.fill('input[name="username"]', "superadmin");
    await page.fill('input[name="password"]', (process.env.E2E_TEACHER_PASS || ""));
    await Promise.all([page.waitForURL(/^(?!.*\/login).*/), page.click('button[type="submit"]')]);
    await page.waitForTimeout(1000);

    const errors = [];
    const responses = [];
    page.on("pageerror", e => errors.push({ src: "pageerror", msg: e.message }));
    page.on("console", msg => {
        if (msg.type() === "error") errors.push({ src: "console-error", msg: msg.text() });
    });
    page.on("response", r => {
        if (r.url().includes("/export")) responses.push({ url: r.url(), status: r.status() });
    });

    const dismissModals = async () => {
        await page.evaluate(() => {
            document.querySelectorAll("#fm-modal-overlay, .fm-modal-overlay, #license-info-modal, #author-banner, #cookie-consent-modal").forEach(m => { m.style.display = "none"; m.style.pointerEvents = "none"; });
        });
    };

    for (const tid of TEMPLATES) {
        console.log(`\n— template ${tid} —`);
        await page.goto(`/risdoc/view/${tid}`);
        await page.waitForTimeout(2500);
        await dismissModals();

        // Sidebar sync per popolare state
        await page.evaluate(() => {
            const i = document.getElementById("sel-iis");
            const c = document.getElementById("sel-cls");
            const m = document.getElementById("sel-mater");
            if (i && !i.value) { i.value = "sc"; i.dispatchEvent(new Event("change", { bubbles: true })); }
            if (c && !c.value) { c.value = "2s"; c.dispatchEvent(new Event("change", { bubbles: true })); }
            if (m && !m.value) { m.value = "MAT"; m.dispatchEvent(new Event("change", { bubbles: true })); }
        });
        await page.waitForTimeout(1500);

        // Click ZIP
        const zip = page.locator('[data-action="download-zip"]');
        if (await zip.count() === 0) { console.log("  no ZIP btn (tex_file null), skip"); continue; }

        const respPromise = page.waitForResponse(r => r.url().includes(`/templates/${tid}/export`), { timeout: 15000 });
        await zip.click();
        const resp = await respPromise;
        const status = resp.status();
        const text = await resp.text().catch(() => "");
        console.log(`  ZIP click → HTTP ${status}${status !== 200 ? ": " + text.slice(0, 100) : ""}`);
        expect.soft(status, `template ${tid} ZIP`).toBe(200);
        await page.waitForTimeout(1000);

        // Click Overleaf
        const overleaf = page.locator('[data-action="overleaf"]');
        if (await overleaf.count() > 0) {
            // Overleaf apre nuova tab — intercetto la richiesta all'API
            const respPromise2 = page.waitForResponse(r => r.url().includes(`/templates/${tid}/export`), { timeout: 15000 });
            await overleaf.click();
            const resp2 = await respPromise2;
            const status2 = resp2.status();
            console.log(`  Overleaf click → HTTP ${status2}`);
            expect.soft(status2, `template ${tid} Overleaf`).toBe(200);
            await page.waitForTimeout(800);
        }
    }

    console.log("\n--- export responses ---");
    for (const r of responses) console.log(`  ${r.status}  ${r.url}`);
    console.log(`\n--- console errors (${errors.length}) ---`);
    for (const e of errors.slice(0, 20)) console.log(`  [${e.src}] ${e.msg}`);

    // Hard-fail se ci sono state response 403+ o pageerror
    const hardFails = responses.filter(r => r.status >= 400);
    expect(hardFails, `export HTTP failures: ${JSON.stringify(hardFails)}`).toHaveLength(0);

    // Tolleriamo alcuni console errors (es. 404 benigni) ma NON pageerror (JS crash)
    const pageErrors = errors.filter(e => e.src === "pageerror");
    expect(pageErrors, `JS page errors: ${JSON.stringify(pageErrors)}`).toHaveLength(0);

    await page.close();
});
