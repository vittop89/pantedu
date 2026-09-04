/**
 * Phase 24.47 — verifica edit toggle PER-SEZIONE.
 *   - Click su .js-edit-section in .fm-db-head di MODELLI → solo
 *     <ul.fm-db-block[data-section="MODELLI"]> riceve data-edit-active="1".
 *     Le altre categorie (RISORSE) restano inalterate.
 *   - + Nuovo (.fm-section-add) visibile solo nella sezione attiva.
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

test("edit toggle scoped al .fm-db-block (non al .fm-sb-panel)", async ({ page }) => {
    test.setTimeout(60_000);
    await loginTeacher(page);
    await page.goto("/");
    await page.waitForLoadState("domcontentloaded");

    await page.evaluate(() => {
        document.querySelector('.fm-sb-sec[data-sidepage="risdoc"]')?.click();
    });
    await page.waitForTimeout(2500);

    // Snapshot iniziale: nessuna sezione attiva
    const beforeAny = await page.evaluate(() => {
        return document.querySelectorAll(
            "#fm-sp-risdoc .fm-db-block[data-edit-active='1']"
        ).length;
    });
    expect(beforeAny, "no section active initial").toBe(0);

    // Click sul .js-edit-section della prima .fm-db-head (categoria 1)
    const cat1 = await page.evaluate(() => {
        const head = document.querySelector("#fm-sp-risdoc .fm-db-head");
        const ul = head?.closest("ul.fm-db-block");
        const section = ul?.dataset?.section;
        head?.querySelector(".js-edit-section")?.click();
        return { section };
    });
    await page.waitForTimeout(200);

    const afterToggle = await page.evaluate(() => {
        const blocks = [...document.querySelectorAll("#fm-sp-risdoc ul.fm-db-block")];
        return blocks.map((b) => ({
            section: b.dataset.section,
            kind: b.dataset.sectionKind,
            active: b.dataset.editActive === "1",
            addBtn: !!b.querySelector(".fm-section-add"),
        }));
    });

    const activeBlocks = afterToggle.filter((b) => b.active);
    expect(activeBlocks.length, "esattamente 1 block attivo").toBe(1);
    expect(activeBlocks[0].section, "il block attivo è la categoria cliccata").toBe(cat1.section);

    // tutti i block hanno il bottone .fm-section-add (visibilità via CSS)
    expect(afterToggle.every((b) => b.addBtn), "ogni block ha .fm-section-add").toBeTruthy();
    expect(afterToggle.every((b) => b.kind === "category"), "data-section-kind=category").toBeTruthy();
});

test("db-sidepage (eser/lab/...) mantiene .fm-section-add per-materia + data-section=subj", async ({ page }) => {
    test.setTimeout(60_000);
    await loginTeacher(page);
    await page.goto("/");
    await page.waitForLoadState("domcontentloaded");
    await page.evaluate(() => {
        const sel = document.getElementById("sel-mater");
        if (sel) sel.value = "MAT";
        sel?.dispatchEvent(new Event("change", { bubbles: true }));
        document.querySelector('.fm-sb-sec[data-sidepage="eser"]')?.click();
    });
    await page.waitForTimeout(2500);

    const blocks = await page.evaluate(() => {
        return [...document.querySelectorAll("#fm-sp-eser ul.fm-db-block")].map((b) => ({
            section: b.dataset.section,
            kind: b.dataset.sectionKind,
            addBtn: !!b.querySelector(".fm-section-add"),
            addType: b.querySelector(".fm-section-add")?.dataset?.fmType,
            addSubj: b.querySelector(".fm-section-add")?.dataset?.fmSubj,
        }));
    });
    // Almeno un block (MAT) renderizzato
    expect(blocks.length, "almeno 1 block").toBeGreaterThanOrEqual(1);
    const mat = blocks.find((b) => b.section === "MAT");
    expect(mat, "block MAT presente").toBeTruthy();
    expect(mat.kind).toBe("subject");
    expect(mat.addBtn).toBeTruthy();
    expect(mat.addType).toBe("esercizio");
    expect(mat.addSubj).toBe("MAT");
});
