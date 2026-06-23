/**
 * U4 e2e — sidebar btn3/btn4 populate via /api/risdoc/templates.
 * Precondizione: superadmin super-admin (vede tutti i 15 template).
 *
 * Unit 2 note — gli ID `.fm-sb-sec[data-sidepage="bes"]`/`.fm-sb-sec[data-sidepage="risdoc"]` e `#fm-sp-bes`/`#fm-sp-risdoc` sono
 * mantenuti per retrocompat; la sidebar emette anche `.fm-sb-sec
 * [data-sidepage="bes"]` / `[data-sidepage="risdoc"]`. Le asserzioni qui
 * sotto continuano a usare gli ID legacy poiché config.js/google-apps.js
 * ci girano sopra.
 */
const { test, expect } = require("@playwright/test");

test.describe("U4: risdoc sidepage", () => {
    test.beforeEach(async ({ page }) => {
        await page.addInitScript(() => {
            localStorage.setItem("cookieConsent", JSON.stringify({
                necessary: true, functional: true, analytics: false, marketing: false,
                date: new Date().toISOString(),
            }));
            sessionStorage.clear();
        });
        await page.goto("/login");
        await page.fill('input[name="username"]', "superadmin");
        await page.fill('input[name="password"]', (process.env.E2E_TEACHER_PASS || ""));
        await Promise.all([
            page.waitForURL(/^(?!.*\/login).*/),
            page.click('button[type="submit"]'),
        ]);
        await page.goto("/?home=1");
        await page.waitForLoadState("networkidle");
        await page.waitForTimeout(300);
        await page.evaluate(() => {
            document.querySelectorAll("#fm-modal-overlay,#iframe-warning,#cookie-banner,#fm-cookie-modal")
                .forEach(el => el.style.display = "none");
        });
    });

    // Phase 25 — contratto aggiornato: i template ISTITUZIONALI non compaiono
    // più inline nel sidepage docente (risdoc-sidepage.js filtra a
    // isInstance || isTeacherContent, vedi commento "Phase 25" nel modulo). Il
    // sidepage mostra SOLO le risorse del docente (istanze fork + documenti
    // liberi); i modelli istituzionali si gestiscono da /admin/templates e si
    // forkano via modal "+ Nuovo". I test verificano quindi: pannello aperto +
    // struttura categorie corretta + ASSENZA di leakage dei template
    // istituzionali (li[data-template-id] proviene solo da istanze/contenuti).
    test("btn3 (BES/DSA) apre il pannello con categorie bes + altro", async ({ page }) => {
        await page.locator('.fm-sb-sec[data-sidepage="bes"]').click({ force: true });
        await page.waitForTimeout(1500);

        const state = await page.evaluate(() => {
            const sp = document.getElementById("fm-sp-bes");
            return {
                visible: sp && getComputedStyle(sp).display !== "none",
                categories: Array.from(sp?.querySelectorAll(".fm-risdoc-cat") || [])
                    .map(c => c.dataset.category),
                hasNewCategoryBtn: !!sp?.querySelector(".js-add-category, [data-act='add-category']"),
            };
        });
        console.log("btn3 state:", JSON.stringify(state, null, 2));
        expect(state.visible).toBe(true);
        // Le due categorie di default della sezione BES/DSA sono sempre rese.
        expect(state.categories).toEqual(["bes", "altro"]);
    });

    test("btn4 (Risdoc) apre il pannello con categorie modelli + risorse", async ({ page }) => {
        await page.locator('.fm-sb-sec[data-sidepage="risdoc"]').click({ force: true });
        await page.waitForTimeout(1500);

        const state = await page.evaluate(() => {
            const sp = document.getElementById("fm-sp-risdoc");
            const links = Array.from(sp?.querySelectorAll(".fm-risdoc-cat li[data-template-id] a") || []);
            return {
                visible: sp && getComputedStyle(sp).display !== "none",
                categories: Array.from(sp?.querySelectorAll(".fm-risdoc-cat") || [])
                    .map(c => c.dataset.category),
                // Ogni voce cliccabile (se presente) è un'istanza/contenuto del
                // docente → href /risdoc/view/<id>, MAI un template istituzionale.
                allLinksAreDocResources: links.every(a => /\/risdoc\/view\/\d+/.test(a.href)),
            };
        });
        console.log("btn4 state:", JSON.stringify(state, null, 2));
        expect(state.visible).toBe(true);
        expect(state.categories).toEqual(["modelli", "risorse"]);
        expect(state.allLinksAreDocResources).toBe(true);
    });

    test("edit btn presente in ogni .fm-db-head", async ({ page }) => {
        await page.locator('.fm-sb-sec[data-sidepage="risdoc"]').click({ force: true });
        await page.waitForTimeout(1500);
        const state = await page.evaluate(() => {
            const sp = document.getElementById("fm-sp-risdoc");
            const headsWithBtn = Array.from(sp?.querySelectorAll(".fm-db-head") || [])
                .filter(h => !!h.querySelector(".js-edit-section")).length;
            return { headsWithBtn };
        });
        expect(state.headsWithBtn).toBe(2); // MODELLI + RISORSE
    });
});
