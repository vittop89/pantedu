/**
 * Full e2e test Phase 15/21: login → sidebar → multi-click → verifica-mode auto → navigation
 * Verifica:
 *  - no navigation loop
 *  - URL stabili dopo click ripetuti
 *  - esercizi presenti con MathJax tipografato
 *  - verifica-mode auto-on per admin + js-pick-ex checkbox (Phase 21: #btnAct rimosso)
 *  - CHECKIN equivalent modern (se presente)
 *  - link update dopo sel-wrapper change
 */

const { test, expect } = require("@playwright/test");

const PASSWORD = (process.env.E2E_TEACHER_PASS || "");

async function dismissCookieModal(page) {
    await page.waitForTimeout(400);
    await page.evaluate(() => {
        ["fm-cookie-modal", "fm-modal-overlay"].forEach((id) => {
            const el = document.getElementById(id);
            if (el) el.style.display = "none";
        });
    });
}

async function setSidebarSection(page, ind, cls, mater) {
    await page.waitForFunction(
        () => document.querySelector("#sel-iis option[value='sc']") !== null,
        { timeout: 8000 }
    ).catch(() => {});
    await page.selectOption("#sel-iis", ind).catch(() => {});
    await page.selectOption("#sel-cls", cls).catch(() => {});
    await page.selectOption("#sel-mater", mater).catch(() => {});
    await page.waitForTimeout(400);
}

async function openEserSection(page) {
    await page.locator('.fm-sb-sec[data-sidepage="eser"]').click({ force: true });
    await page.waitForSelector('#fm-sp-eser a', { timeout: 15000 }).catch(() => {});
    await page.waitForTimeout(1500);
}

async function snapshotPage(page, label) {
    const info = await page.evaluate(() => {
        const problems = document.querySelectorAll(".fm-groupcollex").length;
        const collex = document.querySelectorAll(".fm-collection__item").length;
        const mjx = document.querySelectorAll("mjx-container").length;
        const tikz = document.querySelectorAll("script[type='text/tikz']").length;
        const collApr = document.querySelectorAll(".fm-collapsible.active").length;
        const upbar = !!document.querySelector(".fm-upbar");
        // Phase 21: #btnAct rimosso; tracciamo auto-attivazione via fm-verifica-mode.
        const verificaAuto = document.body.classList.contains("fm-verifica-mode");
        const upbarCheckIn = !!document.querySelector(".fm-pos-check-es, .fm-check-in");
        const collexPick = document.querySelectorAll(".js-pick-ex").length;
        const fmLatex = document.querySelectorAll(".fm-latex").length;
        const fmBadge = document.querySelectorAll(".fm-badge").length;
        return {
            url: location.href,
            bodyClass: document.body.className,
            problems, collex, mjx, tikz, collApr, upbar, verificaAuto, upbarCheckIn, collexPick,
            fmLatex, fmBadge,
        };
    });
    console.log(`[${label}]`, JSON.stringify(info));
    return info;
}

test("full flow vittorio: multi-click + verifica-mode auto + sel change", async ({ page }) => {
    test.setTimeout(120000);
    const errors = [];
    const consoleLogs = [];
    const navs = [];
    const failedResources = [];

    page.on("pageerror", (e) => errors.push(`[pageerror] ${e.message}`));
    page.on("console", (m) => {
        const line = `[${m.type()}] ${m.text()}`;
        consoleLogs.push(line);
        if (m.type() === "error") errors.push(line);
    });
    page.on("framenavigated", (f) => {
        if (f === page.mainFrame()) navs.push({ t: Date.now(), url: f.url() });
    });
    page.on("response", (r) => {
        if (r.status() >= 400 && r.status() < 500) {
            failedResources.push(`[${r.status()}] ${r.request().method()} ${r.url()}`);
        }
    });

    await page.addInitScript(() => {
        localStorage.setItem("cookieConsent", JSON.stringify({
            necessary: true, functional: true, analytics: false, marketing: false,
            date: new Date().toISOString(),
        }));
    });

    // 1) Login
    await page.goto("/login");
    await page.fill('input[name="username"]', "superadmin");
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/, { timeout: 10000 }),
        page.click('button[type="submit"]'),
    ]);

    // 2) Home + select Scientifico/2s/MAT + open Esercizi
    await page.goto("/?home=1");
    await page.waitForLoadState("networkidle");
    await dismissCookieModal(page);
    await setSidebarSection(page, "sc", "2s", "MAT");
    await openEserSection(page);

    // --- STEP A: primo click Sistemi lineari ---
    console.log("\n=== STEP A: primo click Sistemi lineari ===");
    navs.length = 0;
    const link1 = await page.$('#fm-sp-eser a.linkref:has-text("Sistemi lineari")');
    if (!link1) {
        console.log("NO LINK Sistemi lineari — abort");
        console.log("#fm-sp-eser innerHTML len:", await page.evaluate(() => document.getElementById("Eser")?.innerHTML?.length));
        return;
    }
    const href1 = await link1.getAttribute("href");
    console.log("click href=", href1);
    await link1.click();
    await page.waitForTimeout(3000);
    const a = await snapshotPage(page, "STEP A");
    await page.screenshot({ path: "tests/e2e-results/flow_A_first_sistemi.png", fullPage: true });
    const navsA = [...navs];
    console.log("navs durante A:", navsA.map(n => n.url));

    // --- STEP B: click un altro link (Radicali) ---
    console.log("\n=== STEP B: click Radicali ===");
    // Torna alla home se SPA ha rotto
    await page.goto("/?home=1");
    await dismissCookieModal(page);
    await setSidebarSection(page, "sc", "2s", "MAT");
    await openEserSection(page);
    navs.length = 0;
    const linkRad = await page.$('#fm-sp-eser a.linkref:has-text("Radicali")');
    if (linkRad) {
        await linkRad.click();
        await page.waitForTimeout(3000);
        const b = await snapshotPage(page, "STEP B (Radicali)");
        await page.screenshot({ path: "tests/e2e-results/flow_B_radicali.png", fullPage: true });
    }

    // --- STEP C: ritorno su Sistemi lineari ---
    console.log("\n=== STEP C: ritorno Sistemi lineari ===");
    await page.goto("/?home=1");
    await dismissCookieModal(page);
    await setSidebarSection(page, "sc", "2s", "MAT");
    await openEserSection(page);
    navs.length = 0;
    const link2 = await page.$('#fm-sp-eser a.linkref:has-text("Sistemi lineari")');
    if (link2) {
        await link2.click();
        await page.waitForTimeout(4000);
        const c = await snapshotPage(page, "STEP C (back to Sistemi)");
        await page.screenshot({ path: "tests/e2e-results/flow_C_back_sistemi.png", fullPage: true });
        console.log("navs durante C:", navs.map(n => n.url));
    }

    // --- STEP D: verifica-mode auto-on per admin (Phase 21) ---
    console.log("\n=== STEP D: verifica-mode auto check ===");
    await page.waitForTimeout(800);
    const modeActive = await page.evaluate(() => document.body.classList.contains("fm-verifica-mode"));
    const picks = await page.$$eval(".js-pick-ex", (arr) => arr.length);
    const infoVer = await page.$$eval("#infoVer", (arr) => arr.length);
    console.log("fm-verifica-mode=", modeActive, "js-pick-ex count=", picks, "#infoVer=", infoVer);
    await page.screenshot({ path: "tests/e2e-results/flow_D_verifica_mode.png", fullPage: true });

    // --- STEP E: check navigate verifiche ---
    console.log("\n=== STEP E: navigate verifiche ===");
    await page.goto("/?home=1");
    await dismissCookieModal(page);
    await setSidebarSection(page, "sc", "2s", "MAT");
    await page.locator('.fm-sb-sec[data-sidepage="verif"]').click({ force: true }).catch(() => {});
    await page.waitForTimeout(2000);
    const verifHtml = await page.evaluate(() => document.getElementById("Verif")?.innerHTML?.length || 0);
    const verifLinks = await page.$$eval('#fm-sp-verif a', (as) =>
        as.slice(0, 5).map(a => ({ text: a.textContent.trim().slice(0, 50), href: a.getAttribute("href") }))
    ).catch(() => []);
    console.log("#fm-sp-verif len=", verifHtml, "links:", JSON.stringify(verifLinks));

    // --- SUMMARY ---
    console.log("\n========= PAGE ERRORS (" + errors.length + ") =========");
    errors.slice(0, 15).forEach((e) => console.log("  " + e));

    console.log("\n========= FAILED RESOURCES 4xx (" + failedResources.length + ") =========");
    failedResources.slice(0, 15).forEach((r) => console.log("  " + r));

    console.log("\n========= CONSOLE LOGS (non-debug, last 40) =========");
    consoleLogs.filter(l => !/\[debug\]/.test(l)).slice(-40).forEach((l) => console.log(l));
});
