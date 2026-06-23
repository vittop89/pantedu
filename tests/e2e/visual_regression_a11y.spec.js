/**
 * Visual regression test per WCAG 1.4.4 (resize text) e 1.4.10 (reflow).
 * Phase C.4 — screenshot diff fra:
 *   - viewport 1280x720 zoom 100% (baseline)
 *   - viewport 1280x720 zoom 200% (resize text)
 *   - viewport 320x568 (mobile small / reflow)
 *
 * Cattura screenshot in tests/e2e/visual_regression_a11y.spec.js-snapshots/.
 * Al primo run li salva come reference. Run successivi: se diverso > soglia,
 * fallisce.
 *
 * Run:
 *   # Primo run: salva snapshot di reference
 *   FM_E2E_BASE_URL=https://pantedu.eu npm run e2e:visual:update
 *
 *   # Run successivi: diff vs reference
 *   FM_E2E_BASE_URL=https://pantedu.eu npm run e2e:visual
 */

const { test, expect } = require("@playwright/test");

const PAGES = [
    { path: "/",                       label: "home" },
    { path: "/login",                  label: "login" },
    { path: "/accessibility",          label: "accessibility-statement" },
];

const VIEWPORTS = [
    { name: "desktop-100", width: 1280, height: 720, zoom: 1 },
    { name: "desktop-200", width: 1280, height: 720, zoom: 2 },
    { name: "mobile-320",  width: 320,  height: 568, zoom: 1 },
];

for (const page of PAGES) {
    for (const vp of VIEWPORTS) {
        test(`visual regression: ${page.label} @ ${vp.name}`, async ({ page: pwPage }) => {
            await pwPage.setViewportSize({ width: vp.width, height: vp.height });

            if (vp.zoom !== 1) {
                await pwPage.addInitScript((z) => {
                    document.documentElement.style.fontSize = `${z * 100}%`;
                }, vp.zoom);
            }

            await pwPage.goto(page.path);
            await pwPage.waitForLoadState("networkidle", { timeout: 10000 });

            await pwPage.addStyleTag({ content: `
                *, *::before, *::after {
                    animation-duration: 0s !important;
                    transition-duration: 0s !important;
                }
            `});

            expect(await pwPage.screenshot({ fullPage: true })).toMatchSnapshot(
                `${page.label}-${vp.name}.png`,
                { maxDiffPixelRatio: 0.05 }
            );
        });
    }
}

test("WCAG 1.4.10 Reflow — no horizontal scroll at 320px viewport", async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 568 });
    await page.goto("/");
    await page.waitForLoadState("networkidle", { timeout: 10000 });

    const overflow = await page.evaluate(() => {
        return {
            bodyScrollWidth: document.body.scrollWidth,
            windowInnerWidth: window.innerWidth,
            hasHorizontalScroll: document.documentElement.scrollWidth > window.innerWidth + 1,
        };
    });

    expect(overflow.hasHorizontalScroll,
        `Horizontal scroll detected at 320px: body=${overflow.bodyScrollWidth}, window=${overflow.windowInnerWidth}`
    ).toBe(false);
});
