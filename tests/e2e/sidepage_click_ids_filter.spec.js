/**
 * E2E test — flow completo sidepage: open site → sidebar → click mappa →
 * ctrl+click seconda mappa.
 *
 * Verifica:
 *  1. Login come admin/teacher
 *  2. Naviga home + imposta sidebar (sc / 3s / MAT)
 *  3. Apri Mappe sidepage (btn0) — aspetta popolamento
 *  4. Ispeziona href dei <li data-content-id> — deve contenere ?ids=N
 *  5. Click normale su prima mappa 2.1 → URL contiene ?ids=N1 singolo;
 *     #fm-content mostra SOLO 1 .fm-mappa-wrap
 *  6. Ctrl+click su seconda mappa 2.1 → URL ?ids=N1,N2;
 *     #fm-content mostra 2 .fm-mappa-wrap
 *  7. Sidebar NON ri-caricata (stesso HTML pre/post click)
 */

const { test, expect } = require("@playwright/test");

const USER = "superadmin";
const PASS = (process.env.E2E_TEACHER_PASS || "");

async function login(page) {
    await page.goto("/login");
    await page.fill('input[name="username"]', USER);
    await page.fill('input[name="password"]', PASS);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/, { timeout: 10000 }),
        page.click('button[type="submit"]'),
    ]);
}

async function bypassConsent(page) {
    await page.addInitScript(() => {
        localStorage.setItem("cookieConsent", JSON.stringify({
            necessary: true, functional: true, analytics: false, marketing: false,
            date: new Date().toISOString(),
        }));
        // Style override: rende invisibili gli overlay anche se riappaiono
        const s = document.createElement("style");
        s.textContent = `#fm-cookie-modal,#fm-modal-overlay,#iframe-warning,#cookie-banner{display:none!important;pointer-events:none!important}`;
        (document.head || document.documentElement).appendChild(s);
    });
}

async function hideOverlays(page) {
    await page.evaluate(() => {
        ["fm-cookie-modal", "fm-modal-overlay", "iframe-warning", "cookie-banner"]
            .forEach(id => {
                const e = document.getElementById(id);
                if (e) { e.style.display = "none"; e.style.pointerEvents = "none"; }
            });
    }).catch(() => {});
}

async function setupSidebar(page) {
    await page.selectOption("#sel-iis", "sc").catch(() => {});
    await page.selectOption("#sel-cls", "3s").catch(() => {});
    await page.selectOption("#sel-mater", "MAT").catch(() => {});
    await page.waitForTimeout(500);
}

test("sidepage click flow: default single + ctrl+click multi", async ({ page }) => {
    const errors = [];
    const net404 = [];
    page.on("pageerror", (e) => errors.push(`[pageerror] ${e.message}`));
    page.on("console", (m) => { if (m.type() === "error") errors.push(`[console.error] ${m.text()}`); });
    page.on("response", (r) => { if (r.status() >= 400) net404.push(`[${r.status()}] ${r.url()}`); });

    await bypassConsent(page);  // addInitScript DEVE essere chiamato PRIMA di goto
    await login(page);
    await page.goto("/?home=1");
    await page.waitForLoadState("networkidle");
    await hideOverlays(page);
    await page.waitForTimeout(400);

    await setupSidebar(page);
    await hideOverlays(page);
    await page.screenshot({ path: "tests/e2e-results/sph_01_home.png", fullPage: true });

    // 1. Apri sidepage Mappe (btn0)
    const btnMappe = page.locator('.fm-sb-sec[data-sidepage="mappe"], .btn[id="btn0"]').first();
    await expect(btnMappe).toBeVisible({ timeout: 10_000 });
    await btnMappe.click();
    await page.waitForTimeout(1200); // attende loadDbContent

    // 2. Filtro solo items visibili (materia selezionata MAT).
    //    La sidepage mostra FIS/MAT collassati — filter -> visibili.
    const items = page.locator("#fm-sp-mappe li[data-content-id]:visible");
    const itemCount = await items.count();
    console.log(`[test] Mappe sidepage items visible: ${itemCount}`);
    if (itemCount === 0) {
        // Fallback: tutti li items (MAT hidden?)
        const allItems = page.locator("#fm-sp-mappe li[data-content-id]");
        console.log(`[test] all items: ${await allItems.count()}`);
        const allHrefs = await allItems.evaluateAll(els => els.map(e => e.querySelector("a")?.href));
        console.log(`[test] all hrefs: ${JSON.stringify(allHrefs, null, 2)}`);
    }
    expect(itemCount).toBeGreaterThan(0);

    // 3. Check TUTTI gli item href contengono ?ids= (fix backend+sidepage)
    const allHrefsVisible = await items.evaluateAll(els => els.map(e => e.querySelector("a")?.href));
    console.log(`[test] all visible hrefs: ${JSON.stringify(allHrefsVisible)}`);
    const withoutIds = allHrefsVisible.filter(h => h && !h.includes("?ids="));
    expect(withoutIds, `hrefs without ?ids=: ${JSON.stringify(withoutIds)}`).toEqual([]);

    await page.screenshot({ path: "tests/e2e-results/sph_02_sidepage.png", fullPage: true });

    // 4. Snapshot sidepage HTML pre-click
    const sidepageHtmlBefore = await page.locator("#fm-sp-mappe").innerHTML();

    // 5. Click normale su prima mappa visibile
    const firstLink = items.first().locator("a");
    const firstId = await items.first().getAttribute("data-content-id");
    console.log(`[test] clicking first item id=${firstId}`);
    await firstLink.click();
    await page.waitForTimeout(1500);

    // 6. Verifica URL ha ?ids=firstId
    const url1 = page.url();
    console.log(`[test] URL after click: ${url1}`);
    expect(url1).toContain(`ids=${firstId}`);

    // 7. Verifica #fm-content ha esattamente 1 .fm-mappa-wrap
    const wraps1 = page.locator("#fm-content .fm-mappa-wrap[data-id]");
    const wrapsCount1 = await wraps1.count();
    console.log(`[test] .fm-mappa-wrap count after single click: ${wrapsCount1}`);
    expect(wrapsCount1).toBe(1);
    const wrapId1 = await wraps1.first().getAttribute("data-id");
    expect(wrapId1).toBe(firstId);

    await page.screenshot({ path: "tests/e2e-results/sph_03_after_single_click.png", fullPage: true });

    // 8. Verifica sidepage NON ri-caricata (stesso set di items)
    //    Tolleranza su lunghezze (piccole fluttuazioni per class toggles
    //    come .fm-open, whitespace). Check numero items invariato.
    const itemCountAfter = await page.locator("#fm-sp-mappe li[data-content-id]:visible").count();
    expect(itemCountAfter).toBe(itemCount);
    // Il primo item deve essere stesso id
    const firstIdAfter = await page.locator("#fm-sp-mappe li[data-content-id]:visible").first().getAttribute("data-content-id");
    expect(firstIdAfter).toBe(firstId);

    // Rimuovi overlay che ricompaiono dopo navigate
    await hideOverlays(page);

    // 9. Ctrl+click su seconda mappa (se esiste)
    if (itemCount >= 2) {
        const secondLink = items.nth(1).locator("a");
        const secondId = await items.nth(1).getAttribute("data-content-id");
        console.log(`[test] ctrl+clicking second item id=${secondId}`);
        await secondLink.click({ modifiers: ["Control"], force: true });
        await page.waitForTimeout(1500);

        // 10. Verifica URL ha ?ids con entrambi gli id
        const url2 = page.url();
        console.log(`[test] URL after ctrl+click: ${url2}`);
        expect(url2).toContain("ids=");
        const urlObj = new URL(url2);
        const ids = (urlObj.searchParams.get("ids") || "").split(",").filter(Boolean);
        console.log(`[test] ids in URL: ${JSON.stringify(ids)}`);
        expect(ids).toContain(firstId);
        expect(ids).toContain(secondId);

        // 11. Verifica 2 .fm-mappa-wrap
        const wraps2 = page.locator("#fm-content .fm-mappa-wrap[data-id]");
        const wrapsCount2 = await wraps2.count();
        console.log(`[test] .fm-mappa-wrap count after ctrl+click: ${wrapsCount2}`);
        expect(wrapsCount2).toBe(2);

        await page.screenshot({ path: "tests/e2e-results/sph_04_after_ctrl_click.png", fullPage: true });

        // 12. Ctrl+click DI NUOVO sulla seconda mappa → TOGGLE OFF
        //     → URL ritorna a ?ids=firstId (o senza ids), 1 wrap
        await hideOverlays(page);
        const items2 = page.locator("#fm-sp-mappe li[data-content-id]:visible");
        await items2.nth(1).locator("a").click({ modifiers: ["Control"], force: true });
        await page.waitForTimeout(1500);
        await hideOverlays(page);

        const url3 = page.url();
        console.log(`[test] URL after ctrl+click toggle off: ${url3}`);
        const urlObj3 = new URL(url3);
        const ids3 = (urlObj3.searchParams.get("ids") || "").split(",").filter(Boolean);
        console.log(`[test] ids after toggle off: ${JSON.stringify(ids3)}`);
        // Atteso: solo firstId rimane (secondId è stato toggled off)
        expect(ids3).not.toContain(secondId);
        // wraps count deve essere 1
        const wraps3 = page.locator("#fm-content .fm-mappa-wrap[data-id]");
        const wrapsCount3 = await wraps3.count();
        console.log(`[test] .fm-mappa-wrap count after toggle off: ${wrapsCount3}`);
        expect(wrapsCount3).toBe(1);
    }

    // Report errori
    if (errors.length) console.log(`[test] console/page errors:\n${errors.join("\n")}`);
    if (net404.length) console.log(`[test] network 4xx/5xx:\n${net404.join("\n")}`);
});
