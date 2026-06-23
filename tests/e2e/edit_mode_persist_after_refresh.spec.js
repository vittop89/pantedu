/**
 * Diagnostic — l'edit mode di sezione NON deve chiudersi dopo save/delete di un
 * item (refreshSidepage ri-renderizza il pannello). Deve chiudersi SOLO col ✓.
 *
 * Fix: restoreEditState() ripristina lo stato al re-render.
 * Cattura console.log + screenshot (memory feedback_e2e_console_logs).
 */
const { test, expect } = require("@playwright/test");

const TEACHER_USER = process.env.E2E_TEACHER_USER || "superadmin";
const TEACHER_PASS = process.env.E2E_TEACHER_PASS || process.env.FM_P || "";

async function openSidepage(page, key) {
    await page.evaluate((k) => {
        document.querySelector(`.fm-sb-sec[data-sidepage="${k}"]`)?.click();
    }, key);
    // attendi che il pannello abbia un .fm-db-head (render async via fetch)
    await page.waitForFunction(
        (k) => !!document.querySelector(`#fm-sp-${k} .fm-db-head, #fm-sp-${k} ul.fm-db-block`),
        key,
        { timeout: 8000 }
    ).catch(() => {});
    await page.waitForTimeout(600);
}

test("edit mode sezione persiste dopo refreshSidepage (save/delete item)", async ({ page }) => {
    test.setTimeout(120_000);

    const logs = [];
    page.on("console", (m) => logs.push(`[${m.type()}] ${m.text()}`));
    page.on("pageerror", (e) => logs.push(`[pageerror] ${e.message}`));

    await page.addInitScript(() => {
        localStorage.setItem("cookieConsent", JSON.stringify({
            necessary: true, functional: true, analytics: false, marketing: false,
            date: new Date().toISOString(),
        }));
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

    // --- DIAGNOSI: scansiona ogni sidepage e trova una sezione con item ---
    const keys = ["eser", "lab", "verif", "risdoc", "bes", "mappa"];
    let target = null;
    for (const key of keys) {
        await openSidepage(page, key);
        const info = await page.evaluate((k) => {
            const sp = document.getElementById(`fm-sp-${k}`);
            if (!sp) return { key: k, exists: false };
            const heads = sp.querySelectorAll(".fm-db-head .js-edit-section").length;
            const blocks = sp.querySelectorAll("ul.fm-db-block").length;
            const items = sp.querySelectorAll("li[data-content-id]").length;
            const firstSection = sp.querySelector("ul.fm-db-block")?.dataset?.section || null;
            return { key: k, exists: true, heads, blocks, items, firstSection };
        }, key);
        console.log(`[diag] ${JSON.stringify(info)}`);
        if (info.exists && info.heads > 0 && !target) target = info.key;
        if (info.exists && info.items > 0) { target = info.key; break; }
    }
    console.log(`[diag] target sidepage = ${target}`);
    expect(target, "almeno una sidepage con .js-edit-section").toBeTruthy();

    // Riapri il target e abilita edit mode sulla prima sezione che ha item (o la prima)
    await openSidepage(page, target);
    const enabled = await page.evaluate((k) => {
        const sp = document.getElementById(`fm-sp-${k}`);
        // preferisci un block con item
        let block = sp.querySelector("ul.fm-db-block:has(li[data-content-id])")
                 || sp.querySelector("ul.fm-db-block");
        const btn = block?.querySelector(".fm-db-head .js-edit-section");
        btn?.click();
        return {
            section: block?.dataset?.section || null,
            active: block?.dataset?.editActive === "1",
            items: block?.querySelectorAll("li[data-content-id]").length || 0,
        };
    }, target);
    console.log(`[test] dopo ✎ toggle: ${JSON.stringify(enabled)}`);
    expect(enabled.active, "edit mode attiva dopo ✎").toBeTruthy();

    const type = await page.evaluate((k) => {
        const sp = document.getElementById(`fm-sp-${k}`);
        return sp?.querySelector(".fm-section-add")?.dataset?.fmType
            || sp?.dataset?.type || null;
    }, target);

    // --- TEST 1: refresh programmatico (stesso path di save/delete) ---
    await page.evaluate(({ k, t }) => {
        const def = window.FM?.SidepageRegistry?.byKey?.(k);
        if (def?.loader === "risdoc") {
            window.FM?.RisdocSidepage?.loadSidepage?.(def.key, { panelId: def.panel, origin: def.origin, categories: def.categories });
        } else {
            window.FM?.loadDbSidepageContent?.(k, t || def?.type);
        }
    }, { k: target, t: type });
    await page.waitForTimeout(1800);

    const afterRefresh = await page.evaluate((k) => {
        const sp = document.getElementById(`fm-sp-${k}`);
        const ul = sp.querySelector("ul.fm-db-block[data-edit-active='1']");
        const btn = ul?.querySelector(".fm-db-head .js-edit-section")
                 || sp.querySelector(".fm-db-head .js-edit-section");
        return {
            anyActive: !!ul,
            activeSection: ul?.dataset?.section || null,
            btnHasActiveClass: !!btn?.classList?.contains("btn-esactive"),
            btnLabel: btn?.querySelector("strong")?.textContent || null,
            actionsVisible: !!sp.querySelector("ul.fm-db-block[data-edit-active='1'] .fm-section-add"),
        };
    }, target);
    console.log(`[test] DOPO refreshSidepage: ${JSON.stringify(afterRefresh)}`);
    await page.screenshot({ path: "tests/e2e-results/edit_persist_after_refresh.png", fullPage: true });

    console.log(`\n[test] ===== CONSOLE LOG (${logs.length}) =====\n${logs.slice(-40).join("\n")}`);

    expect(afterRefresh.anyActive, "edit mode persiste dopo refreshSidepage").toBeTruthy();
    expect(afterRefresh.activeSection, "stessa sezione resta attiva").toBe(enabled.section);
    expect(afterRefresh.btnLabel, "toggle mostra ✓").toBe("✓");
});
