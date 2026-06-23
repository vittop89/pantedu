const { test, expect } = require("@playwright/test");

// Fix: collegare un istituto GIÀ collegato (che nel datalist appare col `name`
// MIUR mentre nel pannello usa l'`header_label`) mostrava "✓ Collegato" pur non
// cambiando nulla — il link è INSERT IGNORE no-op. Ora il client riconosce il
// codice già presente (e il server ritorna already_linked) → feedback onesto.
test("Profilo: ricollegare un istituto già presente dà 'Già collegato'", async ({ page }) => {
    test.setTimeout(60000);
    const logs = [];
    page.on("console", m => logs.push(`[${m.type()}] ${m.text()}`));

    await page.goto("/login");
    await page.locator("input[name=username]").fill(process.env.E2E_TEACHER_USER || "superadmin");
    await page.locator("input[name=password]").fill(process.env.E2E_TEACHER_PASS || "");
    await page.locator("button[type=submit]").first().click();
    await page.waitForLoadState("networkidle");

    await page.goto("/area-docente/profilo");
    await page.waitForLoadState("networkidle");
    await page.evaluate(() => {
        for (const id of ["fm-modal-overlay", "fm-cookie-modal", "bottom-bar"]) {
            const o = document.getElementById(id); if (o) o.style.display = "none";
        }
    });

    // Attendi che la tabella istituti sia popolata (almeno una scuola collegata).
    await page.waitForFunction(() => {
        const d = document.getElementById("fm-profile-current");
        return d && /[A-Z0-9]{5,}/.test(d.textContent) && !d.textContent.includes("Caricamento");
    }, { timeout: 10000 });

    // Digita nell'autocomplete e seleziona una voce marcata "✓ già collegato".
    await page.locator("#fm-profile-search").fill("Esempio");
    await page.waitForSelector(".fm-ac__list:not([hidden]) .fm-ac__item", { timeout: 5000 });
    const alreadyOpt = page.locator(".fm-ac__item", { has: page.locator(".fm-ac__note") }).first();
    await expect(alreadyOpt).toBeVisible();
    const pickedName = (await alreadyOpt.locator(".fm-ac__label").textContent()).trim();
    console.log("Voce già collegata scelta:", pickedName);
    await alreadyOpt.click();

    await page.locator("#fm-profile-add-btn").dispatchEvent("click");
    await page.waitForTimeout(1200);
    const fb = (await page.locator("#fm-profile-add-feedback").textContent()).trim();
    console.log("Feedback:", fb);
    expect(fb.toLowerCase()).toContain("già collegato");
    expect(fb.toLowerCase()).not.toContain("✓ collegato");

    if (logs.length) console.log("=== CONSOLE ===\n" + logs.join("\n"));
});
