/**
 * G23 — Diagnostic test sulla verifica reale dell'utente (prova_1).
 *
 * Login → naviga al contract → cerca RM item → ispeziona DOM cella.
 */
const { test, expect } = require("@playwright/test");

const TEACHER_USER = "superadmin";
const TEACHER_PASS = (process.env.E2E_TEACHER_PASS || "");

test("DIAGNOSTIC: ispeziona DOM cella RM in prova_1", async ({ page }) => {
    test.setTimeout(60000);
    // Login
    await page.addInitScript(() => {
        localStorage.setItem("user_cookie_consent_v2", JSON.stringify({
            functional: true, analytics: false, advertising: false, timestamp: Date.now(),
        }));
    });
    await page.goto("/login");
    await page.fill('input[name="username"]', TEACHER_USER);
    await page.fill('input[name="password"]', TEACHER_PASS);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/, { timeout: 15000 }),
        page.click('button[type="submit"]'),
    ]);

    // Trova la verifica prova_1 — provo URL guess
    const urls = [
        "/studio/verifica/sc/3s/MAT/prova_1",
        "/studio/verifica/sc/3s/MAT/prova%201",
        "/studio/verifica/sc/3s/MAT/prova-1",
    ];
    let landed = null;
    for (const u of urls) {
        const r = await page.goto(u, { waitUntil: "domcontentloaded", timeout: 10000 }).catch(() => null);
        if (r && r.status() === 200) {
            await page.waitForTimeout(2000);
            const hasContract = await page.locator(".fm-contract-wrap, .fm-rm-table").count();
            if (hasContract > 0) {
                landed = u;
                break;
            }
        }
    }
    if (!landed) {
        // Try index page to find it
        await page.goto("/studio/verifica/sc/3s/MAT", { waitUntil: "domcontentloaded" });
        const links = await page.locator("a").evaluateAll(els =>
            els.map(a => ({ href: a.href, text: a.innerText?.trim() })).filter(x => /prova/i.test(x.text || ""))
        );
        console.log("Links containing 'prova':", JSON.stringify(links.slice(0, 5)));
        test.skip(!links.length, "Nessuna prova_1 trovata");
    }

    // Aspetta caricamento contract
    await page.waitForSelector(".fm-contract-wrap, .fm-rm-table", { timeout: 15000 });
    await page.waitForTimeout(2000);

    // Ispeziona il DOM
    const dump = await page.evaluate(() => {
        const rmTables = document.querySelectorAll(".fm-rm-table");
        const items = [];
        rmTables.forEach((tbl, ti) => {
            const tds = Array.from(tbl.querySelectorAll("td.rm-option"));
            const cells = tds.map((td, ci) => ({
                idx: ci,
                outerHTML: td.outerHTML.substring(0, 500),
            }));
            items.push({ table: ti, cells });
        });
        return { tableCount: rmTables.length, items };
    });
    console.log("DOM dump:", JSON.stringify(dump, null, 2).substring(0, 3000));

    // Apri editor su primo .fm-collection__item con RM table
    const itemBtn = page.locator(".fm-collection__item .single-modificaBtn").first();
    if (await itemBtn.count() > 0) {
        await itemBtn.click();
        await page.waitForSelector(".fm-editor-panel", { timeout: 10000 });
        await page.waitForTimeout(1500);

        // Ispeziona cell editor
        const cellEditor = await page.evaluate(() => {
            const panel = document.querySelector(".fm-editor-panel");
            if (!panel) return { error: "no panel" };
            const cellTas = panel.querySelectorAll('.fm-editor-field[data-field^="rm-cell-"]');
            const cells = [];
            cellTas.forEach(ta => {
                cells.push({
                    field: ta.dataset.field,
                    row: ta.dataset.row,
                    col: ta.dataset.col,
                    value: ta.innerHTML.substring(0, 800),
                    valueLength: ta.innerHTML.length,
                });
            });
            // Anche capture il quesito field
            const ques = panel.querySelector('.fm-editor-field[data-field="quesito"]');
            return {
                cells,
                quesito: ques ? {
                    valueLength: ques.innerHTML.length,
                    value: ques.innerHTML.substring(0, 800),
                } : null,
            };
        });
        console.log("Cell editors:", JSON.stringify(cellEditor, null, 2).substring(0, 5000));

        // Capture: simula save senza inviare al server
        const captured = await page.evaluate(() => {
            const panel = document.querySelector(".fm-editor-panel");
            if (!panel || typeof window.FM?.__buildBlocksFromTextareaForTest !== "function") return { error: "no test hooks" };
            // Cattura una cella
            const ta = panel.querySelector('.fm-editor-field[data-field="rm-cell-r0c0"]');
            if (!ta) return { error: "no rm-cell-r0c0" };
            const blocks = window.FM.__buildBlocksFromTextareaForTest(ta);
            return { blocks: JSON.parse(JSON.stringify(blocks)) };
        });
        console.log("Captured blocks rm-cell-r0c0:", JSON.stringify(captured, null, 2).substring(0, 3000));
    } else {
        console.log("Nessun bottone modifica trovato");
    }
});
