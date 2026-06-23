/**
 * Phase G19.12 — E2E test del Wizard "+ Crea esercizio".
 *
 * Verifica:
 *   1. Click su `#fm-create-exercise-btn` apre la modal wizard
 *   2. Radio Target = Esercizi (default checked) + Verifica
 *   3. Radio Tipo = Collect/RM/VF (default Collect)
 *   4. Origine select popolata da `/api/teacher/sources.json`
 *   5. Submit con target=esercizio + Collect → POST `/api/teacher/content/{id}/group/add`
 *      e nuovo `.fm-groupcollex` aggiunto al container `.fm-contract-render` non-verifica
 *   6. Submit con target=verifica → invia `origin=personal` (forced) + targets
 *      `#type_verAll` container
 *   7. ESC chiude la modal
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

test("G19.12 — wizard apertura, struttura, ESC chiude", async ({ page }) => {
    test.setTimeout(60_000);
    await login(page);
    await page.goto("/studio/esercizio/ar/2s/MAT/1");
    await page.waitForLoadState("domcontentloaded");
    await page.waitForTimeout(2500);

    // Apri InfoVer drawer (il button #fm-create-exercise-btn è dentro #infoVer)
    await page.locator('#fm-topbar [data-fm-action="info"]').click();
    await page.waitForTimeout(400);

    // Click sul launcher
    const launcher = page.locator("#fm-create-exercise-btn");
    await expect(launcher).toBeAttached();
    // G19.22 — click programmatico (button rilocato in topbar).
    await page.evaluate(() => document.querySelector("#fm-create-exercise-btn")?.click());
    await page.waitForTimeout(300);

    // Modal visibile
    const modal = page.locator("#fm-exercise-wizard-modal.fm-modal--visible");
    await expect(modal).toHaveCount(1);

    // Sezioni: target, type, origin, category
    const targetRadios = page.locator('#fm-exercise-wizard-modal input[name="target"]');
    expect(await targetRadios.count()).toBe(2);
    expect(await page.locator('input[name="target"][value="esercizio"]').isChecked()).toBe(true);
    expect(await page.locator('input[name="target"][value="verifica"]').isChecked()).toBe(false);

    const typeRadios = page.locator('#fm-exercise-wizard-modal input[name="type"]');
    expect(await typeRadios.count()).toBe(3);
    expect(await page.locator('input[name="type"][value="type_Collect-1"]').isChecked()).toBe(true);

    // Origine select popolata
    const origin = page.locator("#fm-ew-origin");
    await expect(origin).toBeAttached();
    const originOptionCount = await origin.locator("option").count();
    expect(originOptionCount).toBeGreaterThan(0);

    // ESC chiude
    await page.keyboard.press("Escape");
    await page.waitForTimeout(200);
    expect(await page.locator("#fm-exercise-wizard-modal").count()).toBe(0);
});

test("G19.12 — submit target=esercizio crea group via API", async ({ page }) => {
    test.setTimeout(90_000);
    await login(page);
    await page.goto("/studio/esercizio/ar/2s/MAT/1");
    await page.waitForLoadState("domcontentloaded");
    await page.waitForTimeout(2500);

    // Conta i .fm-groupcollex iniziali nel container esercizi (non-verifica)
    const initialCount = await page.evaluate(() => {
        const all = document.querySelectorAll(".fm-contract-render");
        for (const el of all) {
            if (!el.closest("#type_verAll")) {
                return el.querySelectorAll(":scope > .fm-groupcollex").length;
            }
        }
        return 0;
    });
    console.log(`[G19.12] .fm-groupcollex iniziali nel container esercizi: ${initialCount}`);

    // Apri InfoVer drawer + wizard
    await page.locator('#fm-topbar [data-fm-action="info"]').click();
    await page.waitForTimeout(400);
    await page.evaluate(() => document.querySelector("#fm-create-exercise-btn")?.click());
    await page.waitForTimeout(300);

    // Default: target=esercizio + type=Collect. Verifica origin select ha almeno
    // un'opzione valida (non placeholder).
    const validOrigin = await page.evaluate(() => {
        const sel = document.querySelector("#fm-ew-origin");
        if (!sel) return null;
        const opts = Array.from(sel.querySelectorAll("option"))
            .filter(o => !o.disabled && o.value);
        return opts.length > 0 ? opts[0].value : null;
    });
    if (!validOrigin) {
        test.skip(true, "Nessuna origine disponibile per il docente di test");
        return;
    }
    // Setta categoria
    await page.locator("#fm-ew-category").fill("G19.12 test category");

    // Intercetta POST + click Crea
    const respPromise = page.waitForResponse(
        r => /\/api\/teacher\/content\/\d+\/group\/add\b/.test(r.url())
          && r.request().method() === "POST",
        { timeout: 15_000 },
    );
    await page.locator('#fm-exercise-wizard-modal [data-action="create"]').click({ force: true });
    const resp = await respPromise;
    expect(resp.status()).toBe(200);
    const body = await resp.json();
    expect(body.ok).toBe(true);
    expect(typeof body.html).toBe("string");
    console.log(`[G19.12] group/add ok: groupId=${body.groupId}, version=${body.version}`);

    // Modal chiusa + nuovo .fm-groupcollex nel container
    await page.waitForTimeout(500);
    expect(await page.locator("#fm-exercise-wizard-modal").count()).toBe(0);
    const finalCount = await page.evaluate(() => {
        const all = document.querySelectorAll(".fm-contract-render");
        for (const el of all) {
            if (!el.closest("#type_verAll")) {
                return el.querySelectorAll(":scope > .fm-groupcollex").length;
            }
        }
        return 0;
    });
    expect(finalCount).toBe(initialCount + 1);

    // Cleanup G19.16: rimuovi la group appena creata via API
    // /api/teacher/content/{id}/group/{groupId}/delete (se l'endpoint esiste)
    // o tramite API di delete generic. Senza cleanup le altre suite di test
    // vedono lo stato modificato e falliscono (pollution).
    if (body.groupId) {
        const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
        const wrapId = await page.evaluate(() => {
            const wrap = document.querySelector(".fm-contract-wrap[data-id]");
            return wrap?.dataset?.id;
        });
        if (wrapId) {
            await page.request.post(
                `/api/teacher/content/${wrapId}/group/${encodeURIComponent(body.groupId)}/delete`,
                {
                    data: {},
                    headers: { "X-CSRF-Token": csrf, "Content-Type": "application/json" },
                },
            ).catch(() => {});
        }
    }
});

test("G19.12 — target=verifica forza origin=personal e targeta #type_verAll", async ({ page }) => {
    test.setTimeout(90_000);
    await login(page);
    await page.goto("/studio/esercizio/ar/2s/MAT/1");
    await page.waitForLoadState("domcontentloaded");
    await page.waitForTimeout(2500);

    // Apri drawer + wizard
    await page.locator('#fm-topbar [data-fm-action="info"]').click();
    await page.waitForTimeout(400);
    await page.evaluate(() => document.querySelector("#fm-create-exercise-btn")?.click());
    await page.waitForTimeout(300);

    // Switch target a verifica
    await page.locator('input[name="target"][value="verifica"]').click({ force: true });
    expect(await page.locator('input[name="target"][value="verifica"]').isChecked()).toBe(true);
    expect(await page.locator('input[name="target"][value="esercizio"]').isChecked()).toBe(false);

    // Submit + intercetta POST: deve includere origin=personal indipendentemente
    // dal valore in #fm-ew-origin (forced lato client per coerenza legacy).
    const reqPromise = page.waitForRequest(
        r => /\/api\/teacher\/content\/\d+\/group\/add\b/.test(r.url())
          && r.method() === "POST",
        { timeout: 15_000 },
    );
    await page.locator('#fm-exercise-wizard-modal [data-action="create"]').click({ force: true });
    const req = await reqPromise;
    const postData = req.postData() || "";
    console.log(`[G19.12] verifica group/add postData: ${postData}`);
    expect(postData).toContain("origin=personal");

    const resp = await req.response();
    expect(resp.status()).toBe(200);
    const body = await resp.json();
    expect(body.ok).toBe(true);

    // Cleanup G19.16: rimuovi la group verifica appena creata (pollution-safe)
    if (body.groupId) {
        const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
        const wrapId = await page.evaluate(() => {
            const wrap = document.querySelector('#type_verAll .fm-contract-wrap[data-id]');
            return wrap?.dataset?.id;
        });
        if (wrapId) {
            await page.request.post(
                `/api/teacher/content/${wrapId}/group/${encodeURIComponent(body.groupId)}/delete`,
                {
                    data: {},
                    headers: { "X-CSRF-Token": csrf, "Content-Type": "application/json" },
                },
            ).catch(() => {});
        }
    }
});
