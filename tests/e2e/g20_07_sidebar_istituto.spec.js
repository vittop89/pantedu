/**
 * G20.0 Phase 7 — sidebar sel-istituto smoke test.
 * Verifica che il dropdown istituto compaia per teacher con >0 link e che
 * il change evento popoli AppState + sessionStorage.
 */
const { test, expect } = require("@playwright/test");

test("Sidebar #sel-istituto: render + change → AppState + sessionStorage", async ({ page }) => {
    await page.goto("/login");
    await page.locator("input[name=username]").fill("superadmin");
    await page.locator("input[name=password]").fill((process.env.E2E_TEACHER_PASS || ""));
    await page.locator("button[type=submit]").first().click();
    await page.waitForLoadState("networkidle");

    // Vai alla home (sidebar renderizzata via layout/app.php)
    await page.goto("/");
    await page.waitForLoadState("networkidle");

    // Apri sidebar drawer
    await page.evaluate(() => {
        const cb = document.getElementById("IObar");
        if (cb && !cb.checked) cb.checked = true;
        const o = document.getElementById("fm-modal-overlay");
        if (o) o.style.display = "none";
    });

    // Debug: cosa vede il DOM
    const debugInfo = await page.evaluate(() => ({
        hasSidebar:    !!document.querySelector(".sidebar"),
        hasSelWrapper: !!document.querySelector(".sel-wrapper"),
        hasSelIstituto: !!document.getElementById("sel-istituto"),
        hasSelIis:     !!document.getElementById("sel-iis"),
        bodyClasses:   document.body.className,
        url:           location.href,
    }));
    console.log("DEBUG:", debugInfo);

    const selectExists = debugInfo.hasSelIstituto;
    expect(selectExists, "sel-istituto deve esistere per teacher con istituti").toBe(true);
    const sel = page.locator("#sel-istituto");

    // Conta opzioni
    const optionCount = await sel.locator("option").count();
    console.log(`Numero istituti collegati: ${optionCount}`);
    expect(optionCount).toBeGreaterThan(0);

    // Lista i codici disponibili
    const codes = await sel.locator("option").evaluateAll(opts => opts.map(o => ({
        code: o.value,
        label: o.textContent?.trim()?.slice(0, 60),
    })));
    console.log("Istituti:", codes);

    // Stato AppState dopo init
    const initialState = await page.evaluate(() => ({
        appStateActive: window.FM?.AppState?.activeInstituteCode,
        sessionStorage: sessionStorage.getItem("activeInstituteCode"),
        selectValue: document.getElementById("sel-istituto")?.value,
    }));
    console.log("Stato iniziale:", initialState);

    // Cambio: scegli XXPS00000A esplicitamente
    await sel.selectOption("XXPS00000A");
    await page.waitForTimeout(200);
    const afterChange = await page.evaluate(() => ({
        appStateActive: window.FM?.AppState?.activeInstituteCode,
        sessionStorage: sessionStorage.getItem("activeInstituteCode"),
    }));
    console.log("Dopo cambio a XXPS00000A:", afterChange);
    expect(afterChange.appStateActive).toBe("XXPS00000A");
    expect(afterChange.sessionStorage).toBe("XXPS00000A");
});
