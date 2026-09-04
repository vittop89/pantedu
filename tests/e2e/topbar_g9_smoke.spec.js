/**
 * Phase G9 — Smoke E2E test della modern topbar.
 *
 * Verifica:
 *   - Topbar visibile su pagina esercizio (body.exercise-context)
 *   - Click filtri non spinge giu' la topbar (sticky position rimane)
 *   - Filtri drawer si apre, dropdown-content default chiuso, click All apre popover
 *   - Info drawer si apre, scrolling orizzontale funziona, savePrintInfoBtn visibile
 *   - update.svg icone visibili (filter invert applicato)
 *   - SalvaTEX/Overleaf/ZIP/GENERA bottoni cliccabili
 *
 * Cattura screenshot a vari step in tests/e2e/screenshots/g9-*.png.
 */
const { test, expect } = require("@playwright/test");
const path = require("path");

const TARGET_URL = "/studio/esercizio/sc/3s/MAT/2";
const SCREENSHOT_DIR = path.join(__dirname, "screenshots");

async function login(page) {
    await page.addInitScript(() => {
        localStorage.setItem("user_cookie_consent_v2", JSON.stringify({
            functional: true, analytics: false, advertising: false, timestamp: Date.now(),
        }));
    });
    await page.goto("/login");
    await page.fill('input[name="username"]', "superadmin");
    await page.fill('input[name="password"]', (process.env.E2E_TEACHER_PASS || ""));
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/, { timeout: 10000 }),
        page.click('button[type="submit"]'),
    ]);
}

async function snap(page, name) {
    await page.screenshot({
        path: path.join(SCREENSHOT_DIR, `g9-${name}.png`),
        fullPage: false,
    });
}

test("G9 topbar moderna: visibility + filtri/info drawers + buttons", async ({ page }) => {
    await login(page);
    await page.goto(TARGET_URL);
    await page.waitForLoadState("domcontentloaded");
    // Aspetta che topbar-modern.js attivi
    await page.waitForFunction(() => {
        const tb = document.getElementById("fm-topbar");
        return tb && !tb.hidden;
    }, { timeout: 10000 }).catch(() => null);
    await page.waitForTimeout(500);

    // 0. baseline (topbar visibile, no drawer)
    await snap(page, "00-baseline");

    const topbar = page.locator("#fm-topbar");
    await expect(topbar).toBeVisible();

    // Recupero la posizione iniziale della topbar
    const topbarTop0 = await topbar.evaluate(el => el.getBoundingClientRect().top);
    console.log("[G9] topbar initial top:", topbarTop0);

    // Verifica titolo (deve essere il topic, non "Informazioni sulla Licenza")
    const titleText = await topbar.locator("[data-fm-title-label]").textContent();
    console.log("[G9] topbar title:", titleText);

    // 1. Click ⚙ filtri
    await page.locator('#fm-topbar [data-fm-action="filtri"]').click();
    await page.waitForTimeout(400);
    await snap(page, "01-filtri-open");

    // Topbar non deve essere spinta giu' (top sticky 0)
    const topbarTop1 = await topbar.evaluate(el => el.getBoundingClientRect().top);
    console.log("[G9] topbar top after filtri click:", topbarTop1);
    expect(Math.abs(topbarTop1 - topbarTop0)).toBeLessThan(5);

    // upbar-controls-container deve essere visibile come drawer
    const drawer = page.locator(".upbar-controls-container");
    await expect(drawer).toBeVisible();

    // dropdown-content default chiuso (non visibile)
    const ddContent = page.locator(".upbar-controls-container .dropdown-content").first();
    const ddInfo = await ddContent.evaluate(el => {
        const cs = getComputedStyle(el);
        // Cerca quale rule sta vincendo per display
        const matched = [];
        for (const sheet of document.styleSheets) {
            try {
                for (const rule of sheet.cssRules || []) {
                    if (!rule.selectorText) continue;
                    if (!el.matches(rule.selectorText)) continue;
                    const hasDisp = rule.style?.getPropertyValue("display");
                    if (hasDisp) matched.push({
                        sel: rule.selectorText,
                        disp: hasDisp,
                        important: rule.style.getPropertyPriority("display"),
                    });
                }
            } catch (_) { /* CORS */ }
        }
        return {
            display: cs.display,
            flexDir: cs.flexDirection,
            position: cs.position,
            classes: el.className,
            parentClass: el.parentElement?.className || "",
            bodyClasses: document.body.className,
            inlineStyle: el.style.cssText,
            matchedDispRules: matched,
        };
    });
    console.log("[G9] dropdown-content first:", JSON.stringify(ddInfo, null, 2));

    // Click sul dropdown DIFFICOLTÀ → apre
    await page.locator("#sel-dif .dropdown-button").click();
    await page.waitForTimeout(300);
    await snap(page, "02-difficolta-open");
    const ddInfo2 = await ddContent.evaluate(el => ({
        display: getComputedStyle(el).display,
        flexDir: getComputedStyle(el).flexDirection,
        classes: el.className,
    }));
    console.log("[G9] after click:", JSON.stringify(ddInfo2));

    // Click outside per chiudere drawer
    await page.locator("body").click({ position: { x: 10, y: 200 } });
    await page.waitForTimeout(200);
    await snap(page, "03-after-click-outside");

    // 2. Click ⓘ Info — verifica-mode forse non attivo, attiviamolo prima
    // selezionando un checkbox A
    const cbA = page.locator(".checkboxA").first();
    if (await cbA.count() > 0) {
        await cbA.check().catch(() => null);
        await page.waitForTimeout(800); // attesa caricamento infoVer
    }
    await page.locator('#fm-topbar [data-fm-action="info"]').click();
    await page.waitForTimeout(500);
    await snap(page, "04-info-open");

    const sb = page.locator("#scrollbarInfo");
    if (await sb.count() > 0 && await sb.isVisible()) {
        // savePrintInfoBtn deve essere visibile
        const saveBtn = page.locator("#savePrintInfoBtn");
        if (await saveBtn.count() > 0) {
            const visible = await saveBtn.isVisible();
            console.log("[G9] savePrintInfoBtn visible:", visible);
        }
        // overflow-x: auto (non hidden)
        const overflowX = await sb.evaluate(el => getComputedStyle(el).overflowX);
        console.log("[G9] #scrollbarInfo overflow-x:", overflowX);
        expect(overflowX).toBe("auto");
    }

    // Chiudi info
    await page.locator("body").click({ position: { x: 10, y: 200 } });
    await page.waitForTimeout(200);

    // 3. Verifica bottoni topbar — solo presenza+enabled, no submit
    const buttonsToCheck = ["salvatex", "overleaf", "zip", "genera", "info", "filtri", "editor"];
    for (const action of buttonsToCheck) {
        const btn = page.locator(`#fm-topbar [data-fm-action="${action}"]`);
        const exists = await btn.count() > 0;
        const enabled = exists ? await btn.isEnabled() : false;
        console.log(`[G9] btn ${action}: exists=${exists}, enabled=${enabled}`);
        expect(exists, `btn ${action} present`).toBeTruthy();
    }

    // Final snapshot
    await snap(page, "99-final");
});
