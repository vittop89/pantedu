/**
 * Phase 24.49 — verifica end-to-end: l'utente rinomina MODELLI in
 * "MODELLI-prova" via dblclick override, clicca + Nuovo sotto MODELLI-prova,
 * crea un documento → DEVE finire nel block MODELLI (label "MODELLI-prova"),
 * NON in RISORSE.
 */
const { test, expect } = require("@playwright/test");

const TEACHER_USER = "superadmin";
const TEACHER_PASS = (process.env.E2E_TEACHER_PASS || "");

async function loginTeacher(page) {
    await page.goto("/login");
    await page.fill('input[name="username"]', TEACHER_USER);
    await page.fill('input[name="password"]', TEACHER_PASS);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/),
        page.click('button[type="submit"]'),
    ]);
}

// Phase 24.58 DEPRECATED — il + Nuovo per risdoc/bes/strcomp non crea più
// teacher_content body_pt; ora apre openInstanceModal (fork multi-instance).
// Coverage del nuovo flow: risdoc_multi_instance.spec.js.
test.skip("create via + Nuovo MODELLI-prova → metadata.category=MODELLI", async ({ page }) => {
    test.setTimeout(60_000);
    await loginTeacher(page);
    await page.goto("/");
    await page.waitForLoadState("domcontentloaded");

    // Forza override label MODELLI → "MODELLI-prova"
    await page.evaluate(() => {
        localStorage.removeItem("fm.risdoc.catLabels");
        localStorage.setItem("fm.risdoc.catLabels", JSON.stringify({ MODELLI: "MODELLI-prova" }));
    });

    // Setta select globali PRIMA del modal (createContent li legge runtime)
    await page.selectOption("#sel-iis", "sc").catch(() => {});
    await page.selectOption("#sel-cls", "2s").catch(() => {});
    await page.selectOption("#sel-mater", "MAT").catch(() => {});
    await page.waitForTimeout(300);

    await page.evaluate(() => {
        document.querySelector('.fm-sb-sec[data-sidepage="risdoc"]')?.click();
    });
    await page.waitForTimeout(2500);

    // Verifica label override applicata
    const headerText = await page.evaluate(() => {
        const ul = document.querySelector("#fm-sp-risdoc ul.fm-db-block[data-section='MODELLI']");
        return ul?.querySelector(".fm-db-head-label")?.textContent || "";
    });
    expect(headerText, "label override visibile").toBe("MODELLI-prova");

    // Click ✎ del .fm-db-head di MODELLI per attivare la sezione
    await page.evaluate(() => {
        const head = document.querySelector("#fm-sp-risdoc ul.fm-db-block[data-section='MODELLI'] .fm-db-head");
        head?.querySelector(".js-edit-section")?.click();
    });
    await page.waitForTimeout(300);

    // Click + Nuovo sotto MODELLI
    await page.evaluate(() => {
        document.querySelector(
            "#fm-sp-risdoc ul.fm-db-block[data-section='MODELLI'] .fm-section-add"
        )?.click();
    });
    await page.waitForTimeout(500);

    // Verifica che il modal abbia hidden category=MODELLI
    const hiddenCat = await page.evaluate(() => {
        return document.querySelector('.fm-modal-form input[name="category"]')?.value;
    });
    expect(hiddenCat, "preCategory MODELLI nel modal").toBe("MODELLI");

    // Compila + crea (selezioni globali settate PRIMA di aprire il modal
    // sopra; il modal è già aperto e chiama createContent che legge i select)
    const title = "RoutingTest-" + Date.now().toString(36);
    await page.fill('.fm-modal-form input[name="title"]', title);
    await page.fill('.fm-modal-form input[name="topic"]', "1.0");

    // Submit click sul button[type=submit] del modal
    await page.click('.fm-modal-form button[type="submit"]');
    // Attendi closeModal (success path)
    await page.waitForFunction(
        () => !document.querySelector(".fm-modal-backdrop"),
        { timeout: 5000 }
    );

    // Trova l'item creato via API. La list `/api/teacher/content` usa
    // searchLean (no metadata_json); fetchiamo il single record.
    const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
    const list = await (await page.request.get("/api/teacher/content?type=risdoc")).json();
    const rows = list.rows || list.content || [];
    const summary = rows.find((r) => r.title === title);
    expect(summary, "item creato presente in DB").toBeTruthy();
    const detail = await (await page.request.get(`/api/teacher/content/${summary.id}`)).json();
    const created = detail.content || {};

    let meta = created.metadata || {};
    if (!Object.keys(meta).length) {
        try { meta = JSON.parse(created.metadata_json || "{}"); } catch {/**/}
    }
    expect(meta.category, "metadata.category=MODELLI (NON RISORSE)").toBe("MODELLI");

    // Phase 24.49 — verifica RENDERING: l'item DEVE comparire nel block
    // MODELLI (label "MODELLI-prova"), NON in RISORSE.
    await page.waitForTimeout(500);
    const blockSection = await page.evaluate((needle) => {
        const sp = document.getElementById("fm-sp-risdoc");
        const lis = sp?.querySelectorAll("li[data-user-created='1']") || [];
        for (const li of lis) {
            if ((li.textContent || "").includes(needle)) {
                return li.closest("ul.fm-db-block")?.dataset?.section;
            }
        }
        return null;
    }, title);
    expect(blockSection, "item visualizzato nel block MODELLI (NON RISORSE)").toBe("MODELLI");

    // Cleanup
    await page.request.post(`/api/teacher/content/${summary.id}/delete`, {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({ _csrf: csrf }).toString(),
    });
    await page.evaluate(() => localStorage.removeItem("fm.risdoc.catLabels"));
});
