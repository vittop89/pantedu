const { test } = require("@playwright/test");

test("debug infoVer auto-injection (Phase 21: verifica-mode auto-on)", async ({ page }) => {
    test.setTimeout(60000);
    const logs = [];
    page.on("console", (m) => logs.push(`[${m.type()}] ${m.text()}`));
    page.on("pageerror", (e) => logs.push(`[pageerror] ${e.message}`));

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

    // Direct test of /Elementi_Riservati.html endpoint
    const resp = await page.evaluate(async () => {
        try {
            const r = await fetch("/Elementi_Riservati.html", { credentials: "same-origin" });
            const text = await r.text();
            return {
                status: r.status,
                redirected: r.redirected,
                url: r.url,
                hasScrollbarInfo: text.includes('id="scrollbarInfo"'),
                hasInfoVer: text.includes('id="infoVer"'),
                size: text.length,
                preview: text.slice(0, 300),
            };
        } catch (e) { return { error: e.message }; }
    });
    console.log("fetch Elementi_Riservati:", JSON.stringify(resp, null, 2));

    // Go direct to studio — verifica-mode auto-on per admin (ensureVerificaMode).
    await page.goto("/studio/esercizio/sc/2s/MAT/2.0");
    await page.waitForTimeout(3000);

    const snapshot = await page.evaluate(() => ({
        hasScrollbarInfo: !!document.getElementById("scrollbarInfo"),
        hasInfoVer: !!document.getElementById("infoVer"),
        hasAdminAccess: document.body.classList.contains("fm-admin-access"),
        hasVerifMode: document.body.classList.contains("fm-verifica-mode"),
        hasUIComp: typeof window.UIComp !== "undefined",
        hasInsertCheckPos: typeof window.UIComp?.InsertCheckPos === "function",
        hasInfoVerChildren: document.getElementById("infoVer")?.children?.length || 0,
    }));
    console.log("snapshot (auto-activated):", JSON.stringify(snapshot));

    console.log("\n========= CONSOLE LOGS =========");
    logs.filter(l => !/\[debug\]/.test(l)).slice(-40).forEach(l => console.log(l));
});
