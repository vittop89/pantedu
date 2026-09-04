/**
 * Verifica VISIVA che dopo insertListSnippet i marker siano visibili
 * nell'editor: list-style: revert + padding-left + display:list-item.
 *
 * Test computed style sul <li> interno per garantire che NON sia
 * `list-style: none` (che nasconde i marker) né `display: flex`
 * (che rompe il list-item layout).
 */
const { test, expect } = require("@playwright/test");

const USERNAME = process.env.FM_E2E_USER || "superadmin";
const PASSWORD = process.env.FM_E2E_PASS || (process.env.E2E_TEACHER_PASS || "");

test("Editor field: list-style:revert + display:list-item su <li> dentro .fm-dsa-li-list", async ({ page }) => {
    await page.addInitScript(() => {
        localStorage.setItem("cookieConsent", JSON.stringify({
            necessary: true, functional: true, analytics: false, marketing: false,
            date: new Date().toISOString(),
        }));
    });
    await page.goto("/login");
    await page.locator("input[name=username]").fill(USERNAME);
    await page.locator("input[name=password]").fill(PASSWORD);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/, { timeout: 10000 }),
        page.locator("button[type=submit]").first().click(),
    ]);
    await page.goto("/?home=1", { waitUntil: "networkidle" });

    const result = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");
        field.focus();

        // Inserisci una <ol type="A"> con 2 <li>
        window.FM.__insertListSnippetForTest({ _focusedTextarea: field }, "ol-Alpha");

        const ol = field.querySelector("ol.fm-dsa-li-list");
        const li1 = ol?.querySelector(":scope > li");
        if (!ol || !li1) return { error: "list non trovata" };
        const olCs = getComputedStyle(ol);
        const liCs = getComputedStyle(li1);

        return {
            olListStyleType: olCs.listStyleType,
            olPaddingLeft: olCs.paddingLeft,
            liDisplay: liCs.display,
            liEmptyContentBefore: getComputedStyle(li1, "::before").content,
            olType: ol.getAttribute("type"),
        };
    });

    expect(result.error, JSON.stringify(result)).toBeUndefined();
    // Marker decimal/alpha-upper visibile (NON "none")
    expect(result.olListStyleType, "list-style-type non è 'none'").not.toBe("none");
    // Browser computed style: il valore esatto può variare tra
    // upper-alpha/lower-alpha/decimal. L'importante è che NON sia 'none'.
    expect(result.olListStyleType, "list-style-type non vuoto").toBeTruthy();
    // Padding sx per marker
    expect(parseInt(result.olPaddingLeft, 10), "padding-left > 0").toBeGreaterThan(0);
    // li deve essere list-item (NON flex come default DSA list)
    expect(result.liDisplay, "li display=list-item").toBe("list-item");
    // Placeholder per <li> vuoti
    expect(result.liEmptyContentBefore, "li:empty mostra placeholder '(vuoto)'").toContain("vuoto");

    console.log("CSS computed:", JSON.stringify(result, null, 2));
});

test("Editor field dark mode: lista visibile + colore chiaro", async ({ page }) => {
    await page.addInitScript(() => {
        localStorage.setItem("cookieConsent", JSON.stringify({
            necessary: true, functional: true, analytics: false, marketing: false,
            date: new Date().toISOString(),
        }));
    });
    await page.goto("/login");
    await page.locator("input[name=username]").fill(USERNAME);
    await page.locator("input[name=password]").fill(PASSWORD);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/, { timeout: 10000 }),
        page.locator("button[type=submit]").first().click(),
    ]);
    await page.goto("/?home=1", { waitUntil: "networkidle" });

    const result = await page.evaluate(() => {
        document.body.classList.add("fm-dark");
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");
        const cs = getComputedStyle(field);
        return {
            background: cs.backgroundColor,
            color: cs.color,
        };
    });

    // background scuro (NON #fff)
    expect(result.background, "background dark NON bianco").not.toMatch(/255,\s*255,\s*255/);
    // color chiaro (NON #000)
    expect(result.color, "text color chiaro").not.toMatch(/^rgb\(0,\s*0,\s*0\)$/);
    console.log("Dark theme:", result);
});
