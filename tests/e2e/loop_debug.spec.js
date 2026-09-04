/**
 * Debug loop: traccia ogni navigazione e fetch per identificare redirect loop.
 */
const { test } = require("@playwright/test");

test("debug: traccia tutte navigazioni + request post click", async ({ page }) => {
    const navs = [];
    const reqs = [];
    const consoleLogs = [];

    page.on("framenavigated", (f) => {
        if (f === page.mainFrame()) navs.push({ t: Date.now(), url: f.url() });
    });
    page.on("request", (r) => {
        reqs.push({ t: Date.now(), method: r.method(), url: r.url(), type: r.resourceType() });
    });
    page.on("response", (r) => {
        const url = r.url();
        if (r.status() >= 300 && r.status() < 400) {
            reqs.push({ t: Date.now(), redirect: r.status(), url, location: r.headers()["location"] || null });
        }
    });
    page.on("console", (m) => consoleLogs.push(`[${m.type()}] ${m.text()}`));

    // Setup
    await page.addInitScript(() => {
        localStorage.setItem("cookieConsent", JSON.stringify({
            necessary: true, functional: true, analytics: false, marketing: false,
            date: new Date().toISOString(),
        }));
    });
    await page.goto("/login");
    await page.fill('input[name="username"]', "superadmin");
    await page.fill('input[name="password"]', (process.env.E2E_TEACHER_PASS || ""));
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/, { timeout: 10000 }),
        page.click('button[type="submit"]'),
    ]);

    await page.goto("/?home=1");
    await page.waitForLoadState("networkidle");
    await page.evaluate(() => {
        const m = document.getElementById("fm-cookie-modal");
        if (m) m.style.display = "none";
    });

    // Attendi che i select siano popolati via API /curriculum
    await page.waitForFunction(
        () => document.querySelector("#sel-iis option[value='sc']") !== null,
        { timeout: 10000 }
    ).catch(() => console.log("sel-iis not populated"));

    // Select Scientifico/2s/MAT
    const r1 = await page.selectOption("#sel-iis", "sc").catch((e) => e.message);
    const r2 = await page.selectOption("#sel-cls", "2s").catch((e) => e.message);
    const r3 = await page.selectOption("#sel-mater", "MAT").catch((e) => e.message);
    console.log("select results:", r1, "|", r2, "|", r3);
    await page.waitForTimeout(500);

    // Clear trackers
    navs.length = 0;
    reqs.length = 0;

    console.log("\n======= DIRECT NAVIGATE /eser/... con linkref stale =======");
    // Simula condizione utente: linkref settato dal click precedente
    await page.evaluate(() => sessionStorage.setItem("linkref", "/eser/sc/eser_sc2s/MAT/2.0_MAT-Sistemi_lineari-sc2s.php"));
    await page.goto("/eser/sc/eser_sc2s/MAT/2.0_MAT-Sistemi_lineari-sc2s.php");

    // Wait 10 seconds to see if loop persists
    await page.waitForTimeout(10000);

    console.log(`\n========= NAVIGATIONS (${navs.length}) =========`);
    navs.forEach((n, i) => console.log(`  ${i}: +${n.t - navs[0].t}ms → ${n.url}`));

    console.log(`\n========= REDIRECTS/3xx (${reqs.filter(r => r.redirect).length}) =========`);
    reqs.filter(r => r.redirect).forEach((r) =>
        console.log(`  [${r.redirect}] ${r.url} → ${r.location}`));

    console.log(`\n========= DOC/XHR REQUESTS after click (${reqs.filter(r => r.method).length}) =========`);
    reqs.filter(r => r.method && ["document", "xhr", "fetch"].includes(r.type)).forEach((r, i) =>
        console.log(`  ${i}: +${r.t - reqs[0].t}ms ${r.method} [${r.type}] ${r.url}`));

    console.log(`\n========= CONSOLE (last 30) =========`);
    consoleLogs.slice(-30).forEach((l) => console.log(l));
});
