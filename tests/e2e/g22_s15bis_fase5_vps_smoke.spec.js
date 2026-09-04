/** Smoke test post-deploy VPS: verifica migration 034 + endpoint github. */
const { test, expect } = require("@playwright/test");

const USERNAME = "superadmin";
const PASSWORD = (process.env.E2E_TEACHER_PASS || "");

test("VPS: GitHub status risponde JSON {configured}", async ({ page }) => {
    test.setTimeout(45000);
    await page.goto("/login");
    await page.locator("input[name=username]").fill(USERNAME);
    await page.locator("input[name=password]").fill(PASSWORD);
    await page.locator("button[type=submit]").first().click();
    await page.waitForLoadState("networkidle");

    // GitHub status: se migration NON applicata → 500. Se OK → 200 + {configured:bool}
    const r = await page.request.get("/api/teacher/github/status");
    console.log("GitHub status:", r.status());
    if (r.status() !== 200) {
        const txt = await r.text();
        console.log("Body:", txt.substring(0, 500));
    }
    expect(r.status()).toBe(200);
    const j = await r.json();
    expect(j.ok).toBe(true);
    expect(typeof j.configured).toBe("boolean");
    console.log("GitHub configured:", j.configured);
});
