/**
 * G20.7 — /api/teacher/print-info/delete con page_key legacy (3-field key
 * `{ind}_{cls}_{mat}` salvato pre-G19.18). Verifica fix per le righe
 * legacy non eliminabili dal modal Carica Print Info.
 */
const { test, expect } = require("@playwright/test");

const USERNAME = process.env.FM_E2E_USER || "superadmin";
const PASSWORD = process.env.FM_E2E_PASS || (process.env.E2E_TEACHER_PASS || "");

test("delete print_info via page_key (3-field legacy + 5-field new)", async ({ page }) => {
    test.setTimeout(60000);
    await page.goto("/login");
    await page.locator("input[name=username]").fill(USERNAME);
    await page.locator("input[name=password]").fill(PASSWORD);
    await page.locator("button[type=submit]").first().click();
    await page.waitForLoadState("networkidle");
    const csrf = await page.request.get("/auth/csrf").then(r => r.json()).then(j => j.token);

    // Setup: salva 1 record new-style (5-field key)
    const stamp = Date.now().toString(36);
    const matCode = "DEL" + stamp.slice(-4);
    await page.request.post("/api/teacher/print-info", {
        data: {
            indirizzo: "ar", classe: "9", materia: matCode,
            sezione: "Z", istituto: "TestInst", anno: "2099", nPrint: "1",
        },
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
    });

    // Inietta direttamente nel JSON un record legacy 3-field per simulare
    // un salvataggio pre-G19.18 (impossibile via UI moderna).
    // Usa lo stesso indirizzo "ar"+classe ma materia diversa (LEG{stamp}).
    const matLegacy = "LEG" + stamp.slice(-4);
    // Il backend non espone una API "scrivi raw key", quindi salviamo via API
    // poi il record sara' in 5-field. Per testare il LEGACY path, salviamo
    // SENZA istituto e SENZA sezione → makeKey ritorna 3-field.
    await page.request.post("/api/teacher/print-info", {
        data: {
            indirizzo: "ar", classe: "9", materia: matLegacy,
            anno: "2099", nPrint: "1",
            // sezione + istituto OMESSI → 3-field key 'ar_9_LEGxxxx'
        },
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
    });

    // List → deve esserci il legacy con page_key 3-field
    const list = await page.request.get("/api/teacher/print-info/list").then(r => r.json());
    const legacyItem = list.items.find(it => it.materia === matLegacy);
    const newItem    = list.items.find(it => it.materia === matCode);
    expect(legacyItem, `legacy ${matLegacy} salvato`).toBeTruthy();
    expect(newItem,    `new ${matCode} salvato`).toBeTruthy();
    expect(legacyItem.page_key).toBe(`ar_9_${matLegacy}`);          // 3-field
    expect(newItem.page_key).toBe(`ar_9_${matCode}_Z_TestInst`);   // 5-field

    // Delete legacy via page_key
    const dLeg = await page.request.post("/api/teacher/print-info/delete", {
        data: { page_key: legacyItem.page_key },
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
    }).then(r => r.json());
    expect(dLeg.ok).toBe(true);
    expect(dLeg.deleted).toBe(true);

    // Delete new via page_key
    const dNew = await page.request.post("/api/teacher/print-info/delete", {
        data: { page_key: newItem.page_key },
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
    }).then(r => r.json());
    expect(dNew.ok).toBe(true);
    expect(dNew.deleted).toBe(true);

    // Verify entrambi spariti
    const after = await page.request.get("/api/teacher/print-info/list").then(r => r.json());
    expect(after.items.find(it => it.materia === matLegacy)).toBeFalsy();
    expect(after.items.find(it => it.materia === matCode)).toBeFalsy();
});
