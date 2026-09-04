/**
 * G20.7 — /api/teacher/verifica/files accetta ?institute=<code> per
 * scope override (cambio istituto da sidebar #sel-istituto).
 *
 * - hint valido (istituto del docente) → ritorna quel codice
 * - hint vuoto → fallback al primo istituto
 * - hint errato (non legato al docente) → fallback al primo istituto
 */
const { test, expect } = require("@playwright/test");

const USERNAME = process.env.FM_E2E_USER || "superadmin";
const PASSWORD = process.env.FM_E2E_PASS || (process.env.E2E_TEACHER_PASS || "");

test("teacher/verifica/files: ?institute hint pilota institute_code", async ({ page }) => {
    test.setTimeout(60000);
    await page.goto("/login");
    await page.locator("input[name=username]").fill(USERNAME);
    await page.locator("input[name=password]").fill(PASSWORD);
    await page.locator("button[type=submit]").first().click();
    await page.waitForLoadState("networkidle");

    // Baseline: senza hint
    const noHint = await page.request.get("/api/teacher/verifica/files").then(r => r.json());
    expect(noHint.ok).toBe(true);
    expect(noHint.institute_code).toBeTruthy();
    const baseline = noHint.institute_code;
    console.log(`baseline: ${baseline}`);

    // Hint = stesso codice → invariato
    const sameHint = await page.request.get(
        `/api/teacher/verifica/files?institute=${encodeURIComponent(baseline)}`
    ).then(r => r.json());
    expect(sameHint.institute_code).toBe(baseline);

    // Hint con codice errato → fallback (non crash, e ritorna baseline)
    const fakeHint = await page.request.get(
        "/api/teacher/verifica/files?institute=ZZZZ99999X"
    ).then(r => r.json());
    expect(fakeHint.ok).toBe(true);
    // institute_code: fallback al primo istituto del docente (non null se ne ha 1+)
    expect(fakeHint.institute_code).toBe(baseline);
});
