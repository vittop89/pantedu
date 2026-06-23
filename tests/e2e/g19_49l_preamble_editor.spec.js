const { test, expect } = require("@playwright/test");
test("preamble editor smoke", async ({ page }) => {
    await page.goto("/login");
    await page.locator("input[name=username]").fill("superadmin");
    await page.locator("input[name=password]").fill((process.env.E2E_TEACHER_PASS || ""));
    await page.locator("button[type=submit]").first().click();
    await page.waitForLoadState("networkidle");

    // GET preamble
    const r = await page.request.get("/api/admin/verifica/preamble");
    expect(r.ok()).toBeTruthy();
    const j = await r.json();
    console.log("status=", r.status(), "is_custom=", j.is_custom, "current_len=", j.current?.length);
    expect(j.ok).toBe(true);
    expect(j.current).toContain("\documentclass");
    expect(j.default).toContain("\documentclass");
    expect(j.is_custom).toBe(false);

    // Visita la pagina admin templates
    await page.goto("/admin/templates#verifiche");
    await page.waitForLoadState("networkidle");
    await page.waitForSelector("#fm-vt-preamble-textarea", { timeout: 5000 });
    const textareaContent = await page.locator("#fm-vt-preamble-textarea").inputValue();
    console.log("Textarea preamble length:", textareaContent.length);
    expect(textareaContent).toContain("\documentclass");
    expect(textareaContent).toContain("circledsteps");
});
