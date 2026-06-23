/**
 * Phase 24.69 — modal "Crea documento" risdoc:
 *   1. Select gerarchico categoria → template (separation of concerns).
 *   2. Label override dblclick è per-utente (chiave localStorage prefissata
 *      con username, no propaga ad altri docenti sullo stesso browser).
 */
const { test, expect } = require("@playwright/test");

const TEACHER = "superadmin";
const PASS    = (process.env.E2E_TEACHER_PASS || "");

async function login(page) {
    await page.goto("/login");
    await page.fill('input[name="username"]', TEACHER);
    await page.fill('input[name="password"]', PASS);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/),
        page.click('button[type="submit"]'),
    ]);
}

test("Phase 24.69 — modal mostra select categoria + select template separati", async ({ page }) => {
    test.setTimeout(60_000);
    await login(page);
    await page.goto("/");
    await page.waitForLoadState("domcontentloaded");
    await page.evaluate(() => {
        document.querySelector('.fm-sb-sec[data-sidepage="risdoc"]')?.click();
    });
    await page.waitForTimeout(2500);
    await page.evaluate(() => {
        document.querySelector("#fm-sp-risdoc .fm-db-head .js-edit-section")?.click();
    });
    await page.waitForTimeout(300);
    await page.evaluate(() => {
        document.querySelector("#fm-sp-risdoc .fm-db-block[data-edit-active='1'] .fm-section-add")?.click();
    });
    await page.waitForTimeout(800);

    const r = await page.evaluate(() => {
        const catSel = document.querySelector('select[name="template_category"]');
        const tplSel = document.querySelector('select[name="template_id"]');
        return {
            hasCatSel: !!catSel,
            hasTplSel: !!tplSel,
            catCount: catSel?.options?.length || 0,
            tplCount: tplSel?.options?.length || 0,
            firstCat: catSel?.value || "",
        };
    });
    expect(r.hasCatSel, "select categoria template").toBeTruthy();
    expect(r.hasTplSel, "select template").toBeTruthy();
    expect(r.catCount, "almeno 1 categoria").toBeGreaterThanOrEqual(1);
    expect(r.tplCount, "almeno 1 template per default cat").toBeGreaterThanOrEqual(1);
    expect(r.firstCat).toBeTruthy();
});

test("Phase 24.69 — change categoria ripopola select template", async ({ page }) => {
    test.setTimeout(60_000);
    await login(page);
    await page.goto("/");
    await page.waitForLoadState("domcontentloaded");
    await page.evaluate(() => {
        document.querySelector('.fm-sb-sec[data-sidepage="risdoc"]')?.click();
    });
    await page.waitForTimeout(2500);
    await page.evaluate(() => {
        document.querySelector("#fm-sp-risdoc .fm-db-head .js-edit-section")?.click();
    });
    await page.waitForTimeout(300);
    await page.evaluate(() => {
        document.querySelector("#fm-sp-risdoc .fm-db-block[data-edit-active='1'] .fm-section-add")?.click();
    });
    await page.waitForTimeout(800);

    const cats = await page.evaluate(() => {
        const sel = document.querySelector('select[name="template_category"]');
        return [...(sel?.options || [])].map(o => o.value);
    });
    expect(cats.length, "almeno 2 categorie distinte").toBeGreaterThanOrEqual(2);

    const beforeIds = await page.evaluate(() => {
        return [...document.querySelectorAll('select[name="template_id"] option')].map(o => o.value);
    });

    // Cambia categoria
    const otherCat = cats.find(c => c !== cats[0]) || cats[0];
    await page.evaluate((c) => {
        const sel = document.querySelector('select[name="template_category"]');
        sel.value = c;
        sel.dispatchEvent(new Event("change", { bubbles: true }));
    }, otherCat);
    await page.waitForTimeout(150);

    const afterIds = await page.evaluate(() => {
        return [...document.querySelectorAll('select[name="template_id"] option')].map(o => o.value);
    });
    // I template della seconda categoria devono essere diversi (almeno parzialmente)
    expect(JSON.stringify(afterIds) !== JSON.stringify(beforeIds), "template select ripopolato dopo change").toBeTruthy();
});

test("Phase 24.69 — label override dblclick localStorage prefissato per username", async ({ page }) => {
    test.setTimeout(60_000);
    await login(page);
    await page.goto("/");
    await page.waitForLoadState("domcontentloaded");
    await page.evaluate(() => {
        document.querySelector('.fm-sb-sec[data-sidepage="risdoc"]')?.click();
    });
    await page.waitForTimeout(2500);

    // Forza la chiave per-utente via API public
    await page.evaluate(() => {
        window.FM.RisdocSidepage.saveLabelOverride("MODELLI", "MODELLI-mio");
    });

    const keys = await page.evaluate(() => {
        return Object.keys(localStorage).filter(k => k.startsWith("fm.risdoc.catLabels"));
    });
    // Almeno una chiave prefissata con username deve esistere
    const hasUserKey = keys.some(k => k.includes("superadmin"));
    expect(hasUserKey, "chiave per-utente fm.risdoc.catLabels.<username>").toBeTruthy();

    // Cleanup
    await page.evaluate(() => {
        const keys = Object.keys(localStorage).filter(k => k.startsWith("fm.risdoc.catLabels"));
        keys.forEach(k => localStorage.removeItem(k));
    });
});
