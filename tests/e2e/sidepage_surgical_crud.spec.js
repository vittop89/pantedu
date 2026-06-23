/**
 * Diagnostic — CRUD chirurgico sidepage db (eser/lab/mappa): create via ➕ e
 * delete via 🗑 NON devono ricaricare il pannello (no flicker "chiude/riapre")
 * e NON devono chiudere l'edit mode della sezione.
 *
 * Verifica oggettiva del "no reload": conta le GET /api/study/content.json
 * DOPO l'azione → devono essere 0 (l'update è chirurgico, non un refetch).
 *
 * Cattura console.log + screenshot (memory feedback_e2e_console_logs).
 */
const { test, expect } = require("@playwright/test");

const TEACHER_USER = process.env.E2E_TEACHER_USER || "superadmin";
const TEACHER_PASS = process.env.E2E_TEACHER_PASS || process.env.FM_P || "";
const TEST_TITLE = `E2E-SURGICAL-${Date.now()}`;

test("create(➕)/delete(🗑) chirurgici: no reload, edit mode resta attiva", async ({ page }) => {
    test.setTimeout(120_000);

    const logs = [];
    let contentJsonGets = 0;
    page.on("console", (m) => logs.push(`[${m.type()}] ${m.text()}`));
    page.on("pageerror", (e) => logs.push(`[pageerror] ${e.message}`));
    page.on("request", (req) => {
        if (req.method() === "GET" && req.url().includes("/api/study/content.json")) contentJsonGets++;
    });

    await page.addInitScript(() => {
        localStorage.setItem("cookieConsent", JSON.stringify({
            necessary: true, functional: true, analytics: false, marketing: false,
            date: new Date().toISOString(),
        }));
        const addStyle = () => {
            const root = document.head || document.documentElement;
            if (!root) return;
            const s = document.createElement("style");
            s.textContent = `#fm-cookie-modal,#fm-modal-overlay,#iframe-warning,#cookie-banner{display:none!important;pointer-events:none!important}`;
            root.appendChild(s);
        };
        if (document.head || document.documentElement) addStyle();
        else document.addEventListener("DOMContentLoaded", addStyle);
    });

    // Login
    await page.goto("/login");
    await page.fill('input[name="username"]', TEACHER_USER);
    await page.fill('input[name="password"]', TEACHER_PASS);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/, { timeout: 10000 }),
        page.click('button[type="submit"]'),
    ]);

    await page.goto("/?home=1");
    await page.waitForLoadState("networkidle");
    await page.selectOption("#sel-iis", { index: 1 }).catch(() => {});
    await page.waitForTimeout(300);
    await page.selectOption("#sel-cls", { index: 1 }).catch(() => {});
    await page.waitForTimeout(300);
    await page.selectOption("#sel-mater", { index: 1 }).catch(() => {});
    await page.waitForTimeout(600);

    // Apri Esercizi e attendi il block
    await page.evaluate(() => document.querySelector('.fm-sb-sec[data-sidepage="eser"]')?.click());
    await page.waitForFunction(() => !!document.querySelector("#fm-sp-eser ul.fm-db-block"), null, { timeout: 8000 });
    await page.waitForTimeout(500);

    // Abilita edit mode sulla prima sezione
    const ctx = await page.evaluate(() => {
        const ul = document.querySelector("#fm-sp-eser ul.fm-db-block");
        ul?.querySelector(".fm-db-head .js-edit-section")?.click();
        return {
            section: ul?.dataset?.section || null,
            subj: ul?.dataset?.subj || null,
            active: ul?.dataset?.editActive === "1",
            items: ul?.querySelectorAll("li[data-content-id]").length || 0,
        };
    });
    console.log(`[test] edit mode: ${JSON.stringify(ctx)}`);
    expect(ctx.active, "edit mode attiva").toBeTruthy();

    // ========== CREATE via ➕ ==========
    contentJsonGets = 0; // reset contatore: da qui in poi NON deve esserci refetch
    const addDiag = await page.evaluate(() => {
        const btn = document.querySelector("#fm-sp-eser ul.fm-db-block[data-edit-active='1'] .fm-section-add");
        return {
            found: !!btn,
            bound: btn?.dataset?.fmSecAddBound || null,
            type: btn?.dataset?.fmType || null,
            subj: btn?.dataset?.fmSubj || null,
            visible: btn ? btn.offsetParent !== null : false,
        };
    });
    console.log(`[test] ➕ btn: ${JSON.stringify(addDiag)}`);
    // click via evaluate: bypassa #fm-modal-overlay (overlay invisibile che
    // intercetta i pointer events ma non blocca .click() dispatchato sul nodo).
    await page.evaluate(() => {
        document.querySelector("#fm-sp-eser ul.fm-db-block[data-edit-active='1'] .fm-section-add")?.click();
    });
    const modalAppeared = await page.waitForSelector(".fm-modal-backdrop", { timeout: 5000 }).then(() => true).catch(() => false);
    if (!modalAppeared) {
        console.log(`[test] modal NON apparso. Logs:\n${logs.join("\n")}`);
    }
    expect(modalAppeared, "modal create aperto dopo ➕").toBeTruthy();
    await page.fill('.fm-modal-backdrop input[name="title"]', TEST_TITLE);
    await page.fill('.fm-modal-backdrop input[name="topic"]', "99.9");
    await page.evaluate(() => {
        document.querySelector('.fm-modal-backdrop .fm-modal-form')
            ?.requestSubmit?.(document.querySelector('.fm-modal-backdrop .fm-modal-form button[type="submit"]'));
    });
    // attendi che la <li> compaia
    await page.waitForFunction((t) => {
        return [...document.querySelectorAll("#fm-sp-eser li[data-content-id] a")]
            .some((a) => a.textContent.trim() === t);
    }, TEST_TITLE, { timeout: 8000 }).catch(() => {});
    await page.waitForTimeout(800);

    const afterCreate = await page.evaluate((t) => {
        const sp = document.getElementById("fm-sp-eser");
        const li = [...sp.querySelectorAll("li[data-content-id]")].find((x) => x.querySelector("a")?.textContent.trim() === t);
        return {
            modalGone: !document.querySelector(".fm-modal-backdrop"),
            liPresent: !!li,
            contentId: li?.dataset?.contentId || null,
            hasInlineActions: !!li?.querySelector(".fm-item-actions"),
            editStillActive: !!sp.querySelector("ul.fm-db-block[data-edit-active='1']"),
        };
    }, TEST_TITLE);
    console.log(`[test] DOPO create: ${JSON.stringify(afterCreate)} | content.json GETs=${contentJsonGets}`);
    await page.screenshot({ path: "tests/e2e-results/surgical_after_create.png", fullPage: true });

    expect(afterCreate.modalGone, "modal chiuso").toBeTruthy();
    expect(afterCreate.liPresent, "nuovo item presente nel DOM").toBeTruthy();
    expect(afterCreate.hasInlineActions, "nuovo item ha azioni inline ✎🗑").toBeTruthy();
    expect(afterCreate.editStillActive, "edit mode resta attiva dopo create").toBeTruthy();
    expect(contentJsonGets, "create chirurgico: 0 refetch del pannello").toBe(0);

    // ========== DELETE via 🗑 ==========
    page.on("dialog", (d) => d.accept());
    const createdId = afterCreate.contentId;
    contentJsonGets = 0;
    await page.evaluate((id) => {
        document.querySelector(`#fm-sp-eser li[data-content-id="${id}"] .fm-item-del`)?.click();
    }, createdId);
    await page.waitForFunction((id) => !document.querySelector(`#fm-sp-eser li[data-content-id="${id}"]`), createdId, { timeout: 8000 }).catch(() => {});
    await page.waitForTimeout(800);

    const afterDelete = await page.evaluate((id) => {
        const sp = document.getElementById("fm-sp-eser");
        return {
            liGone: !sp.querySelector(`li[data-content-id="${id}"]`),
            editStillActive: !!sp.querySelector("ul.fm-db-block[data-edit-active='1']"),
        };
    }, createdId);
    console.log(`[test] DOPO delete: ${JSON.stringify(afterDelete)} | content.json GETs=${contentJsonGets}`);
    await page.screenshot({ path: "tests/e2e-results/surgical_after_delete.png", fullPage: true });

    console.log(`\n[test] ===== CONSOLE LOG (${logs.length}) =====\n${logs.slice(-30).join("\n")}`);

    expect(afterDelete.liGone, "item rimosso dal DOM").toBeTruthy();
    expect(afterDelete.editStillActive, "edit mode resta attiva dopo delete").toBeTruthy();
    expect(contentJsonGets, "delete chirurgico: 0 refetch del pannello").toBe(0);
});
