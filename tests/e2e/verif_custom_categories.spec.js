/**
 * Phase 24.72 — Verifiche category-grouped + custom categories.
 *
 * Coperture:
 *   1. Sidepage `verif` raggruppa per categoria (non per materia) → almeno
 *      la default category "VERIFICHE" è presente come <ul.fm-db-block>.
 *   2. "✨ Nuova categoria" disponibile per teacher autenticato.
 *   3. Una categoria custom creata via API CustomCats appare nella sidepage.
 *   4. localStorage key è prefissata per-utente (no leak cross-utente).
 */
const { test, expect } = require("@playwright/test");

const TEACHER = "superadmin";
const PASS    = (process.env.E2E_TEACHER_PASS || "");

async function login(page) {
    await page.goto("/login");
    await page.fill('input[name="username"]', TEACHER);
    await page.fill('input[name="password"]', PASS);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/),
        page.click('button[type="submit"]'),
    ]);
}

async function openVerifSidepage(page) {
    await page.goto("/");
    await page.waitForLoadState("domcontentloaded");
    await page.waitForFunction(() => !!window.FM?.SidepageRegistry, null, { timeout: 5000 });
    // Phase 25.B1 — il bypass `sessionStorage.sidebar_authenticated` non è
    // più necessario: PasswordSidepage:false su verif (vedi config.js:66).
    await page.selectOption("#sel-iis", "sc").catch(() => {});
    await page.selectOption("#sel-cls", "2s").catch(() => {});
    await page.selectOption("#sel-mater", "MAT").catch(() => {});
    await page.waitForTimeout(150);
    await page.evaluate(() => {
        document.querySelector('.fm-sb-sec[data-sidepage="verif"]')?.click();
    });
    await page.waitForTimeout(2500);
}

test("Phase 24.72 — sidepage verif rendering category-grouped (default VERIFICHE)", async ({ page }) => {
    test.setTimeout(60_000);
    await login(page);

    // Prima cleanup eventuali residui di test precedenti
    await openVerifSidepage(page);
    await page.evaluate(() => {
        Object.keys(localStorage)
            .filter(k => k.startsWith("fm.sidepage.customCategories"))
            .forEach(k => localStorage.removeItem(k));
    });

    await openVerifSidepage(page);

    const r = await page.evaluate(() => {
        const sp = document.getElementById("fm-sp-verif");
        const blocks = sp?.querySelectorAll("ul.fm-db-block[data-category]") || [];
        const cats = Array.from(blocks).map(b => b.dataset.category);
        const headLabels = Array.from(blocks).map(b =>
            b.querySelector(".fm-db-head-label")?.textContent || "");
        return {
            blockCount: blocks.length,
            categories: cats,
            labels: headLabels,
            // Verifica che NON ci siano host materia (#MAT/#FIS/#GEO)
            // residui dalla vecchia logica subject-grouped.
            hasSubjHost: !!sp?.querySelector("#MAT, #FIS, #GEO, .fm-subj-host"),
        };
    });

    expect(r.blockCount, "almeno 1 categoria rendered").toBeGreaterThanOrEqual(1);
    expect(r.categories, "VERIFICHE default cat presente").toContain("VERIFICHE");
    expect(r.hasSubjHost, "no host materia in category-grouped").toBeFalsy();
});

test("Phase 24.72 — verif: ✨ Nuova categoria visibile per teacher", async ({ page }) => {
    test.setTimeout(60_000);
    await login(page);
    await openVerifSidepage(page);

    const r = await page.evaluate(() => {
        const sp = document.getElementById("fm-sp-verif");
        const btn = sp?.querySelector(".fm-newcat-host .fm-newcat-btn");
        return {
            hasBtn: !!btn,
            text: btn?.textContent || "",
        };
    });
    expect(r.hasBtn, "btn ✨ Nuova categoria presente").toBeTruthy();
    expect(r.text).toContain("Nuova categoria");
});

test("Phase 24.72 — categoria custom creata via API appare nella sidepage verif", async ({ page }) => {
    test.setTimeout(60_000);
    await login(page);
    await openVerifSidepage(page);

    // Attendi che il pre-fetch user-info completi (bootstrap async)
    await page.waitForFunction(() => !!window.FM?.user?.username, null, { timeout: 5000 });

    // Crea categoria custom via API pubblica del modulo CustomCats
    await page.evaluate(() => {
        window.FM.SidepageCustomCategories.create({
            key: "RECUPERO",
            label: "Recupero",
            bucket: "verifica",
            scope: "any",
        });
    });

    // Re-render via API moderna (evita toggle legacy del click handler).
    await page.evaluate(() => {
        window.FM.loadDbSidepageContent("verif", "verifica");
    });
    await page.waitForTimeout(1500);

    const r = await page.evaluate(() => {
        const sp = document.getElementById("fm-sp-verif");
        const block = sp?.querySelector('ul.fm-db-block[data-category="RECUPERO"]');
        const label = block?.querySelector(".fm-db-head-label")?.textContent || "";
        return {
            hasBlock: !!block,
            label,
            // Verifica che la categoria custom è in localStorage prefissata
            // per-utente (no fm.sidepage.customCategories globale).
            keysWithUser: Object.keys(localStorage)
                .filter(k => k.startsWith("fm.sidepage.customCategories")
                          && k.includes("superadmin")),
        };
    });

    expect(r.hasBlock, "block RECUPERO renderizzato").toBeTruthy();
    expect(r.label, "label custom").toBe("Recupero");
    expect(r.keysWithUser.length, "localStorage prefissato per-utente").toBeGreaterThanOrEqual(1);

    // Cleanup
    await page.evaluate(() => {
        window.FM.SidepageCustomCategories.remove("RECUPERO");
    });
});

test("Phase 24.72 — verif edit mode mostra +Nuovo per-categoria", async ({ page }) => {
    test.setTimeout(60_000);
    await login(page);
    await openVerifSidepage(page);

    // Toggle edit mode sulla prima categoria
    const r = await page.evaluate(() => {
        const sp = document.getElementById("fm-sp-verif");
        const block = sp?.querySelector("ul.fm-db-block[data-category]");
        const editBtn = block?.querySelector(".fm-db-head .js-edit-section");
        editBtn?.click();
        return {
            hasEditBtn: !!editBtn,
            editActive: block?.dataset?.editActive,
            hasAddBtn: !!block?.querySelector(".fm-section-add"),
            addBtnType: block?.querySelector(".fm-section-add")?.dataset?.fmType,
            addBtnPreCat: block?.querySelector(".fm-section-add")?.dataset?.fmPreCategory,
        };
    });
    expect(r.hasEditBtn, "edit btn nella head").toBeTruthy();
    expect(r.editActive, "data-edit-active=1 dopo click").toBe("1");
    expect(r.hasAddBtn, "+ Nuovo per-categoria").toBeTruthy();
    expect(r.addBtnType, "data-fm-type=verifica").toBe("verifica");
    expect(r.addBtnPreCat, "data-fm-pre-category settato").toBeTruthy();
});
