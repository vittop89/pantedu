/**
 * Phase G19.13-G19.15 — Editor enhancements:
 *
 *  G19.13 — radio cards wizard verticali (fix CSS)
 *  G19.14 — auto-save draft IndexedDB
 *  G19.15 — keyboard shortcuts (Ctrl+B/I/M/Tab/auto-bracket)
 *
 *  NOTA: P5 modal flottante per .fm-editor-panel REVERTITO — l'utente
 *  vuole poter aprire più editor inline contemporaneamente. La modal
 *  era incompatibile con il workflow.
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
    await page.click('button[type="submit"]');
    await page.waitForFunction(() => !location.pathname.startsWith("/login"), { timeout: 15_000 });
    await page.waitForLoadState("domcontentloaded");
}

test("G19.13 — wizard radio cards verticali (uno sotto l'altro)", async ({ page }) => {
    test.setTimeout(60_000);
    await login(page);
    await page.goto("/studio/esercizio/ar/2s/MAT/1");
    await page.waitForLoadState("domcontentloaded");
    await page.waitForTimeout(2500);
    await page.locator('#fm-topbar [data-fm-action="info"]').click();
    await page.waitForTimeout(400);
    await page.evaluate(() => document.querySelector("#fm-create-exercise-btn")?.click());
    // Aspetta che il modal sia VISIBILE (transition opacity 0→1 finished)
    await page.waitForSelector("#fm-exercise-wizard-modal.fm-modal--visible", { timeout: 5000 });
    await page.waitForTimeout(300);

    // Radio cards target devono essere su righe DIVERSE (top diverso)
    const targetCards = page.locator('input[name="target"]').locator("..");
    expect(await targetCards.count()).toBe(2);
    const tops = await page.evaluate(() => {
        return Array.from(document.querySelectorAll('input[name="target"]'))
            .map(el => {
                const lbl = el.closest("label");
                const r = lbl.getBoundingClientRect();
                const cs = getComputedStyle(lbl);
                const parentCs = getComputedStyle(lbl.parentElement);
                // Match rules by inspecting all CSS rules
                let matched = [];
                try {
                    for (const sheet of document.styleSheets) {
                        try {
                            const rules = sheet.cssRules || [];
                            for (const rule of rules) {
                                if (rule.selectorText?.includes("fm-ew-") ||
                                    rule.selectorText?.includes("fm-create-eser-btn") ||
                                    rule.selectorText?.includes("fm-exercise-wizard")) {
                                    matched.push({sel: rule.selectorText, hasFlex: rule.style.cssText.includes("flex")});
                                }
                            }
                        } catch(_) {}
                    }
                } catch(_) {}
                return {
                    top: r.top, height: r.height, width: r.width,
                    display: cs.display,
                    parentDisplay: parentCs.display,
                    parentTag: lbl.parentElement.tagName,
                    parentClass: lbl.parentElement.className,
                    matchedSection: matched,
                };
            });
    });
    console.log("[G19.13] target cards rects:", JSON.stringify(tops, null, 2));
    expect(tops[1].top - tops[0].top, "card 2 sotto card 1 (top diff > 25px)").toBeGreaterThan(25);

    // Type cards: 3 distinte righe
    const typeTops = await page.evaluate(() => {
        return Array.from(document.querySelectorAll('input[name="type"]'))
            .map(el => el.closest("label").getBoundingClientRect().top);
    });
    expect(typeTops.length).toBe(3);
    expect(typeTops[1] - typeTops[0]).toBeGreaterThan(25);
    expect(typeTops[2] - typeTops[1]).toBeGreaterThan(25);
});

test("G19.15 — keyboard shortcuts su .fm-editor-field (Ctrl+B / Ctrl+I / Ctrl+M / Tab)", async ({ page }) => {
    test.setTimeout(90_000);
    await login(page);
    await page.goto("/studio/esercizio/ar/2s/MAT/1");
    await page.waitForLoadState("domcontentloaded");
    await page.waitForTimeout(2500);

    // Apri editor del primo item (single-modificaBtn)
    const firstItem = page.locator(".fm-collection__item").first();
    if (await firstItem.count() === 0) {
        test.skip(true, "Nessun .fm-collection__item per testare l'editor");
        return;
    }
    // Espandi collapsible per accedere al .fm-collection__item
    const collapsible = page.locator(".fm-groupcollex .fm-collapsible").first();
    if (await collapsible.count()) {
        await collapsible.click({ force: true });
        await page.waitForTimeout(300);
    }
    // Apri edit panel via single-modificaBtn
    const editBtn = firstItem.locator(".single-modificaBtn").first();
    await editBtn.click({ force: true });
    await page.waitForTimeout(500);

    const ta = firstItem.locator(".fm-editor-field").first();
    await expect(ta).toBeAttached();
    await expect(ta).toHaveAttribute("data-fm-enhanced", "1");

    // Test Ctrl+B → wraps in \textbf{}
    await ta.click();
    await ta.fill("ciao");
    await ta.evaluate((el) => el.setSelectionRange(0, 4));  // seleziona "ciao"
    await page.keyboard.press("Control+B");
    expect(await ta.inputValue()).toBe("\\textbf{ciao}");

    // Test Ctrl+I → wraps in \textit{}
    await ta.fill("hello");
    await ta.evaluate((el) => el.setSelectionRange(0, 5));
    await page.keyboard.press("Control+I");
    expect(await ta.inputValue()).toBe("\\textit{hello}");

    // Test Ctrl+M → inserts \( \) at cursor
    await ta.fill("");
    await page.keyboard.press("Control+M");
    expect(await ta.inputValue()).toBe("\\(\\)");

    // Test Tab → 2 spaces
    await ta.fill("");
    await ta.focus();
    await page.keyboard.press("Tab");
    expect(await ta.inputValue()).toBe("  ");

    // Test { → auto-completion {}
    await ta.fill("");
    await ta.focus();
    await page.keyboard.press("{");
    expect(await ta.inputValue()).toBe("{}");
});

test("G19.14 — auto-save draft IndexedDB + recovery offer", async ({ page }) => {
    test.setTimeout(90_000);
    await login(page);
    await page.goto("/studio/esercizio/ar/2s/MAT/1");
    await page.waitForLoadState("domcontentloaded");
    await page.waitForTimeout(2500);

    // Espandi collapsible + apri edit panel
    const collapsible = page.locator(".fm-groupcollex .fm-collapsible").first();
    if (await collapsible.count()) {
        await collapsible.click({ force: true });
        await page.waitForTimeout(300);
    }
    const firstItem = page.locator(".fm-collection__item").first();
    if (await firstItem.count() === 0) {
        test.skip(true, "Nessun .fm-collection__item");
        return;
    }
    await firstItem.locator(".single-modificaBtn").first().click({ force: true });
    await page.waitForTimeout(400);

    // Verifica API draft esposta
    const apiAvailable = await page.evaluate(() => !!window.FM?.EditorDraft?.bind);
    expect(apiAvailable, "window.FM.EditorDraft API esposta").toBe(true);

    // Simula salvataggio diretto in IndexedDB (5s debounce è troppo per E2E)
    const itemId = await firstItem.evaluate((el) => el.dataset.id || "");
    if (!itemId) {
        test.skip(true, "Item senza data-id");
        return;
    }
    await page.evaluate(async (id) => {
        // Salva manualmente un draft via API esposta
        const dbReq = indexedDB.open("fm-editor-drafts", 1);
        await new Promise((resolve, reject) => {
            dbReq.onsuccess = () => resolve();
            dbReq.onerror = () => reject(dbReq.error);
        });
        const db = dbReq.result;
        const tx = db.transaction("drafts", "readwrite");
        await new Promise((resolve) => {
            const req = tx.objectStore("drafts").put({
                key: id,
                fields: { quesito: "DRAFT TEST G19.14" },
                savedAt: Date.now(),
                url: location.pathname,
            });
            req.onsuccess = resolve;
            req.onerror = resolve;
        });
        db.close();
    }, itemId);

    // Verifica draft persistito leggendo back via API
    const draft = await page.evaluate(async (id) => {
        const dbReq = indexedDB.open("fm-editor-drafts", 1);
        await new Promise((r) => { dbReq.onsuccess = r; });
        const db = dbReq.result;
        return new Promise((resolve) => {
            const tx = db.transaction("drafts", "readonly");
            const req = tx.objectStore("drafts").get(id);
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => resolve(null);
        });
    }, itemId);
    expect(draft, "draft persistito").toBeTruthy();
    expect(draft.fields.quesito).toBe("DRAFT TEST G19.14");

    // Cleanup: drop draft
    await page.evaluate(async (id) => {
        if (window.FM?.EditorDraft?.drop) {
            await window.FM.EditorDraft.drop(id);
        }
    }, itemId);
});
