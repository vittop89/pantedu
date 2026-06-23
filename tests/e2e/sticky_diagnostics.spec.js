/**
 * Diagnostica sticky per .fm-groupcollex.fm-problem-editing.
 *
 * Obiettivi:
 *   - misurare l'altezza reale di .fm-upbar, #scrollbarInfo, #infoVer
 *   - verificare il valore di --fm-problem-sticky-top
 *   - scrollare e osservare se .fm-collapsible resta ancorato (position effettivo,
 *     getBoundingClientRect().top costante)
 *   - togglare #upbar-toggle e #scrollbarInfo-toggle e misurare cambio offset
 *
 * Output: console.log dettagliato per diagnosi. Assert soft — no regressione.
 */
const { test, expect } = require("@playwright/test");

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

async function measure(page, label) {
    const m = await page.evaluate(() => {
        const rect = (el) => (el ? el.getBoundingClientRect() : null);
        const cs = (el) => (el ? window.getComputedStyle(el) : null);
        const upbar = document.querySelector(".fm-upbar");
        const si = document.getElementById("scrollbarInfo");
        const iv = document.getElementById("infoVer");
        // Con verifica-sticky.js attivo, troviamo i .fm-collapsible.active.
        const colls = document.querySelectorAll(
            '.fm-contract-wrap[data-kind="verifica"] .fm-collapsible.active,'
            + ' [id^="type_verAll"] .fm-collapsible.active'
        );
        const first = colls[0];
        const firstCS = first ? cs(first) : null;
        const problem = first?.closest(".fm-groupcollex") || document.querySelector(".fm-groupcollex");
        return {
            scrollY: window.scrollY,
            docHeight: document.documentElement.scrollHeight,
            upbar: {
                exists: !!upbar,
                hidden: upbar?.classList.contains("upbar-hidden") ?? null,
                rect: rect(upbar),
                top: cs(upbar)?.top,
                height: cs(upbar)?.height,
                position: cs(upbar)?.position,
            },
            scrollbarInfo: {
                exists: !!si,
                hidden: si?.classList.contains("fm-scrollbar-info-hidden") ?? null,
                rect: rect(si),
                top: cs(si)?.top,
                height: cs(si)?.height,
                position: cs(si)?.position,
            },
            infoVer: {
                exists: !!iv,
                rect: rect(iv),
                display: cs(iv)?.display,
            },
            problem: {
                exists: !!problem,
                rect: rect(problem),
            },
            collapsibleActiveCount: colls.length,
            firstCollapsible: {
                exists: !!first,
                rect: rect(first),
                position: firstCS?.position,
                top: firstCS?.top,
                zIndex: firstCS?.zIndex,
            },
        };
    });
    console.log(`\n=== ${label} ===`);
    console.log(JSON.stringify(m, null, 2));
    return m;
}

test.describe("Sticky diagnostics (.fm-groupcollex.fm-problem-editing)", () => {
    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    test("misura altezze + scroll + toggle upbar/infoVer", async ({ page }) => {
        // Navigate to a verifiche page
        await page.goto("/studio/verifica/sc/2s/FIS");
        await page.locator(".fm-study-topics a").first().click();
        await page.waitForURL(/\/studio\/verifica\/sc\/2s\/FIS\/.+/);
        await page.waitForTimeout(1500); // loading async

        // Phase 21: verifica-mode auto-on per admin.
        await page.waitForSelector("#infoVer", { timeout: 8000 }).catch(() => {});
        await page.waitForTimeout(500);

        // Open editor on first item
        await page.evaluate(() => {
            const item = document.querySelector(".fm-collection__item[data-id]:not([data-id=''])");
            item?.querySelector(".fm-single-modifica-btn")?.click();
        });
        await page.waitForTimeout(400);

        // Measurement 1: initial state at scrollY=0
        const m1 = await measure(page, "INITIAL (scrollY=0, all visible)");
        expect(m1.firstCollapsible.exists).toBe(true);
        expect(m1.fm-groupcollex.exists).toBe(true);

        // Scroll down 600px
        await page.evaluate(() => window.scrollTo({ top: 600, behavior: "instant" }));
        await page.waitForTimeout(200);
        const m2 = await measure(page, "SCROLLED 600px (should be sticky)");

        // Scroll more
        await page.evaluate(() => window.scrollTo({ top: 1200, behavior: "instant" }));
        await page.waitForTimeout(200);
        const m3 = await measure(page, "SCROLLED 1200px");

        // Back to top
        await page.evaluate(() => window.scrollTo({ top: 0, behavior: "instant" }));
        await page.waitForTimeout(200);

        // Toggle upbar hidden
        await page.evaluate(() => {
            const cb = document.getElementById("upbar-toggle");
            if (cb) { cb.checked = false; cb.dispatchEvent(new Event("change", { bubbles: true })); }
        });
        await page.waitForTimeout(500);
        const m4 = await measure(page, "UPBAR HIDDEN");

        // Toggle scrollbarInfo hidden
        await page.evaluate(() => {
            const cb = document.getElementById("scrollbarInfo-toggle");
            if (cb) { cb.checked = false; cb.dispatchEvent(new Event("change", { bubbles: true })); }
        });
        await page.waitForTimeout(500);
        const m5 = await measure(page, "UPBAR + SCROLLBARINFO HIDDEN");

        // Scroll with both hidden
        await page.evaluate(() => window.scrollTo({ top: 600, behavior: "instant" }));
        await page.waitForTimeout(200);
        const m6 = await measure(page, "SCROLLED 600px (both hidden)");

        // Summary
        console.log("\n=== SUMMARY ===");
        console.log("collapsible.active count:", [m1, m2, m3, m4, m5, m6].map(m => m.collapsibleActiveCount));
        console.log("firstCollapsible.position:", [m1, m2, m3, m4, m5, m6].map(m => m.firstCollapsible.position));
        console.log("firstCollapsible.top:", [m1, m2, m3, m4, m5, m6].map(m => m.firstCollapsible.top));
        console.log("firstCollapsible.rect.top:", [m1, m2, m3, m4, m5, m6].map(m => m.firstCollapsible.rect?.top?.toFixed(1)));
        console.log("scrollbarInfo.height evolution:", [m1, m2, m3, m4, m5, m6].map(m => m.scrollbarInfo.height));
        console.log("upbar.hidden evolution:", [m1, m2, m3, m4, m5, m6].map(m => m.upbar.hidden));

        // Assert: dopo lo scroll 600px il primo collapsible deve essere fixed.
        // Il suo rect.top dipende dallo stacking: base (86 + siHeight) + H
        // della toolbar se presente. Con editor aperto la toolbar è sticky
        // al top e occupa ~40px. Tolleranza ampia per coprire i due casi.
        expect(m2.firstCollapsible.position).toBe("fixed");
        const siHeight = parseInt(m2.scrollbarInfo.height, 10);
        const minTop = 86 + siHeight;
        const maxTop = minTop + 80; // include eventuale toolbar sticky
        expect(m2.firstCollapsible.rect.top).toBeGreaterThanOrEqual(minTop - 4);
        expect(m2.firstCollapsible.rect.top).toBeLessThanOrEqual(maxTop);
    });
});
