/**
 * G20.7 — Auto-restore delle scelte al page-load.
 *
 * Flow:
 *  1. Salva scelte v2 su /studio/esercizio/sc/3/MAT/1 (verTitle, versione)
 *  2. Naviga via, poi torna alla stessa pagina
 *  3. Click su button v2 viene auto-attivato (memorizzato in localStorage)
 *  4. fm:verifica-ui-loaded triggera autoCaricaScelte → ripristina i campi
 *  5. Cleanup: salva v2 vuoto + reset localStorage
 */
const { test, expect } = require("@playwright/test");

const USERNAME = process.env.FM_E2E_USER || "superadmin";
const PASSWORD = process.env.FM_E2E_PASS || (process.env.E2E_TEACHER_PASS || "");

test("Auto-restore scelte al page-load (versione + verTitle)", async ({ page }) => {
    test.setTimeout(60000);
    await page.goto("/login");
    await page.locator("input[name=username]").fill(USERNAME);
    await page.locator("input[name=password]").fill(PASSWORD);
    await page.locator("button[type=submit]").first().click();
    await page.waitForLoadState("networkidle");

    // Naviga alla pagina target
    await page.goto("/studio/esercizio/sc/3/MAT/1");
    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(1500);

    // Activa v2 PRIMA (il click v-btn fa caricaScelte silent, sovrascriverebbe
    // il verTitle), poi setta verTitle col testTitle.
    const stamp = Date.now().toString(36).slice(-6);
    const testTitle = "AutoRestore-" + stamp;
    await page.evaluate(() => {
        const b2 = document.querySelector('.fm-version-btn[data-version="v2"]');
        if (b2) b2.click();
    });
    await page.waitForTimeout(800);
    await page.evaluate((t) => {
        const v = document.getElementById("verTitle");
        if (v) v.value = t;
    }, testTitle);

    // Click salva-scelte-btn
    const saveResp = page.waitForResponse(r => r.url().includes("/verifiche/scelte") && r.request().method() === "POST", { timeout: 10000 });
    await page.evaluate(() => {
        document.querySelector(".fm-salva-scelte-btn")?.click();
    });
    const sr = await saveResp;
    expect(sr.ok(), "save scelte HTTP ok").toBeTruthy();
    await page.waitForTimeout(800);

    // Naviga via e ritorno
    await page.goto("/teacher/dashboard");
    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(500);
    await page.goto("/studio/esercizio/sc/3/MAT/1");
    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(3500);  // attendi fm:verifica-ui-loaded + autoCarica (delay 600ms)

    // Verifica: v2 attivo, verTitle ripristinato
    const restored = await page.evaluate(() => {
        const active = document.querySelector(".fm-version-btn.fm-version-btn--active");
        return {
            activeVersion: active?.dataset.version,
            verTitleValue: document.getElementById("verTitle")?.value,
        };
    });
    expect(restored.activeVersion).toBe("v2");
    expect(restored.verTitleValue).toBe(testTitle);

    // Cleanup: salva v2 con verTitle vuoto per non sporcare il DB
    await page.evaluate(() => {
        const v = document.getElementById("verTitle");
        if (v) v.value = "";
        document.querySelector(".fm-salva-scelte-btn")?.click();
    });
    await page.waitForTimeout(800);
    await page.evaluate(() => localStorage.removeItem("fm-last-version-by-path"));
});
