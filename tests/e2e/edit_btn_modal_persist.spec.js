/**
 * E2E diagnostic — click ✎ sidepage → verifica modal/toolbar persiste.
 */

const { test, expect } = require("@playwright/test");

test("edit btn click → modal/toolbar resta visibile", async ({ page }) => {
    const errs = [];
    const actions = [];
    page.on("pageerror", (e) => errs.push(`[pageerror] ${e.message}`));
    page.on("console", (m) => { if (m.type() === "error") errs.push(`[console] ${m.text()}`); });

    await page.addInitScript(() => {
        localStorage.setItem("cookieConsent", JSON.stringify({
            necessary: true, functional: true, analytics: false, marketing: false,
            date: new Date().toISOString(),
        }));
        const s = document.createElement("style");
        s.textContent = `#fm-cookie-modal,#fm-modal-overlay,#iframe-warning,#cookie-banner{display:none!important;pointer-events:none!important}`;
        (document.head || document.documentElement).appendChild(s);
    });

    // Login
    await page.goto("/login");
    await page.fill('input[name="username"]', "superadmin");
    await page.fill('input[name="password"]', (process.env.E2E_TEACHER_PASS || ""));
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/, { timeout: 10000 }),
        page.click('button[type="submit"]'),
    ]);

    await page.goto("/?home=1");
    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(400);
    await page.selectOption("#sel-iis", "sc").catch(() => {});
    await page.selectOption("#sel-cls", "3s").catch(() => {});
    await page.selectOption("#sel-mater", "MAT").catch(() => {});
    await page.waitForTimeout(500);

    // Apri sidepage Esercizi
    await page.locator('.fm-sb-sec[data-sidepage="eser"]').first().click({ force: true });
    await page.waitForTimeout(1500);

    // Diagnostica: location del btn in DOM
    const diag = await page.evaluate(() => {
        const btns = document.querySelectorAll(".js-edit-section");
        return Array.from(btns).map((b) => ({
            sidepageId: b.closest(".fm-sb-panel")?.id || "(none)",
            parentTag: b.parentElement?.tagName,
            parentClass: b.parentElement?.className,
            visible: b.offsetParent !== null,
        }));
    });
    console.log(`[test] all .js-edit-section btns:\n${JSON.stringify(diag, null, 2)}`);

    const headDiag = await page.evaluate(() => {
        return Array.from(document.querySelectorAll(".fm-sb-panel")).map((sp) => ({
            id: sp.id,
            hasUl: !!sp.querySelector("ul.fm-db-block"),
            hasHead: !!sp.querySelector(".fm-db-head"),
            btnInHead: !!sp.querySelector(".fm-db-head .js-edit-section"),
            btnDirect: !!sp.querySelector(":scope > .js-edit-section"),
        }));
    });
    console.log(`[test] sidepage state:\n${JSON.stringify(headDiag, null, 2)}`);

    // Phase 24.47 — il toggle per-section avviene su .js-edit-section
    // INSIDE .fm-db-head (non sul root marker che è hidden via :has()).
    const editBtn = page.locator("#fm-sp-eser .fm-db-head .js-edit-section").first();
    const editBtnExists = await editBtn.count();
    console.log(`[test] edit btn count in #fm-sp-eser (anywhere): ${editBtnExists}`);
    if (editBtnExists === 0) {
        console.log("[test] *** edit btn missing in #fm-sp-eser ***");
        return;
    }

    // Listen DOM mutations post-click
    await page.evaluate(() => {
        window.__fmMutLog = [];
        const mo = new MutationObserver((muts) => {
            muts.forEach((m) => {
                m.removedNodes.forEach((n) => {
                    if (n.nodeType === 1) {
                        window.__fmMutLog.push(`REMOVED: ${n.tagName}.${n.className || ""} #${n.id || ""}`);
                    }
                });
                m.addedNodes.forEach((n) => {
                    if (n.nodeType === 1) {
                        window.__fmMutLog.push(`ADDED: ${n.tagName}.${n.className || ""} #${n.id || ""}`);
                    }
                });
            });
        });
        mo.observe(document.body, { childList: true, subtree: true });
        window.__fmMO = mo;
    });

    // Forzo visibilità #fm-sp-eser (in test potrebbe essere hidden)
    await page.evaluate(() => {
        const sp = document.getElementById("Eser");
        if (sp) { sp.style.display = "block"; sp.style.visibility = "visible"; }
    });

    // CLICK edit btn
    console.log("[test] clicking edit btn ✎...");
    await editBtn.click({ force: true });
    await page.waitForTimeout(300);

    const snap1 = await page.evaluate(() => ({
        backdrop: document.querySelector(".fm-modal-backdrop") !== null,
        // Phase 24.47 — toggle è per-section: data-edit-active vive sul ul.fm-db-block
        editActive: document.querySelector("#fm-sp-eser .fm-db-block[data-edit-active='1']") !== null,
        nuovoBtn: document.querySelector("#fm-sp-eser .fm-section-add") !== null,
    }));
    console.log(`[test] after ✎: editActive=${snap1.editActive} nuovoBtn=${snap1.nuovoBtn}`);

    // Se nuovo btn esiste, clicca per aprire modal
    if (snap1.nuovoBtn) {
        console.log("[test] clicking +Nuovo...");
        await page.locator("#fm-sp-eser .fm-section-add").first().click({ force: true });
        await page.waitForTimeout(200);
        const snapModal1 = await page.evaluate(() => ({
            backdrop: document.querySelector(".fm-modal-backdrop") !== null,
            mutations: [...(window.__fmMutLog || [])].slice(-25),
        }));
        console.log(`[test] @modal 200ms: backdrop=${snapModal1.backdrop}`);
        console.log(`[test] last 25 mutations:\n  ${snapModal1.mutations.join("\n  ")}`);

        await page.waitForTimeout(2000);
        const snapModal2 = await page.evaluate(() => ({
            backdrop: document.querySelector(".fm-modal-backdrop") !== null,
            mutations: [...(window.__fmMutLog || [])].slice(-25),
        }));
        console.log(`[test] @modal 2200ms: backdrop=${snapModal2.backdrop}`);
        console.log(`[test] recent mutations:\n  ${snapModal2.mutations.join("\n  ")}`);

        if (snapModal1.backdrop && !snapModal2.backdrop) {
            console.log("[test] *** BUG CONFIRMED: modal sparito entro 2s ***");
        }
    }

    await page.screenshot({ path: "tests/e2e-results/edit_btn_diag.png", fullPage: true });
    if (errs.length) console.log(`[test] errors:\n${errs.join("\n")}`);
});
