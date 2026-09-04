/**
 * Phase 24.41 — categorie dinamiche + label override per-teacher.
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

// Phase 24.58 DEPRECATED — teacher_content per risdoc non più creato dal modal
// (vedi risdoc_multi_instance.spec.js per il nuovo flow multi-instance).
test.skip("teacher_content title NO suffix (MAT) per teacher_content", async ({ page }) => {
    test.setTimeout(60_000);
    await loginTeacher(page);

    const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
    const title = "TestNoSuffix-" + Date.now().toString(36);
    const create = await page.request.post("/api/teacher/content", {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({
            _csrf: csrf, type: "risdoc", subject: "MAT",
            indirizzo: "sc", classe: "2s",
            topic: "NoSuffix-" + Date.now().toString(36),
            title, visibility: "draft",
            metadata: JSON.stringify({ category: "RISORSE" }),
        }).toString(),
    });
    const id = (await create.json()).id;
    try {
        await page.goto("/");
        await page.waitForLoadState("domcontentloaded");
        await page.evaluate(() => {
            document.querySelector('.fm-sb-sec[data-sidepage="risdoc"]')?.click();
        });
        await page.waitForTimeout(2500);
        const text = await page.evaluate((needle) => {
            const li = [...document.querySelectorAll("#fm-sp-risdoc li[data-user-created='1']")]
                .find((el) => el.textContent.includes(needle));
            return li?.querySelector("a")?.textContent;
        }, title);
        expect(text, "title visibile").toBeTruthy();
        expect(text, "no (MAT) suffix").not.toContain("(MAT)");
    } finally {
        await page.request.post(`/api/teacher/content/${id}/delete`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({ _csrf: csrf }).toString(),
        });
    }
});

// Phase 24.58 DEPRECATED — modal teacher_content non più usato per risdoc.
test.skip("Phase 24.48 — modal senza select category, hidden input con preCategory", async ({ page }) => {
    test.setTimeout(60_000);
    const errors = [];
    page.on("pageerror", (err) => errors.push(`[pageerror] ${err.message}`));
    page.on("console", (msg) => { if (msg.type() === "error") errors.push(`[console] ${msg.text()}`); });

    await loginTeacher(page);
    await page.goto("/");
    await page.waitForLoadState("domcontentloaded");

    await page.evaluate(() => {
        document.querySelector('.fm-sb-sec[data-sidepage="risdoc"]')?.click();
    });
    await page.waitForTimeout(2500);
    // Click ✎ della prima head per attivare quella sezione
    await page.evaluate(() => {
        document.querySelector('#fm-sp-risdoc .fm-db-head .js-edit-section')?.click();
    });
    await page.waitForTimeout(300);
    // Click + Nuovo della categoria attivata
    await page.evaluate(() => {
        document.querySelector('#fm-sp-risdoc .fm-db-block[data-edit-active="1"] .fm-section-add')?.click();
    });
    await page.waitForTimeout(500);

    const r = await page.evaluate(() => {
        const sel = document.querySelector(".fm-modal-cat-select");
        const hidden = document.querySelector(".fm-modal-form input[name='category']");
        return {
            hasModal: !!document.querySelector(".fm-modal-backdrop"),
            hasOldSelect: !!sel,
            hiddenValue: hidden?.value || "",
        };
    });
    expect(r.hasModal, "modal aperto").toBeTruthy();
    expect(r.hasOldSelect, "select rimosso (no più .fm-modal-cat-select)").toBeFalsy();
    expect(r.hiddenValue, "hidden category contiene preCategory").toBeTruthy();

    expect(errors, `pageerrors:\n${errors.join("\n")}`).toHaveLength(0);
});

test("Phase 24.48 — pulsante + Nuova categoria in cima al sidepage", async ({ page }) => {
    test.setTimeout(60_000);
    await loginTeacher(page);
    await page.goto("/");
    await page.waitForLoadState("domcontentloaded");
    await page.evaluate(() => {
        document.querySelector('.fm-sb-sec[data-sidepage="risdoc"]')?.click();
    });
    await page.waitForTimeout(2500);

    const has = await page.evaluate(() => {
        const sp = document.getElementById("fm-sp-risdoc");
        const host = sp?.querySelector(".fm-newcat-host");
        const btn = host?.querySelector(".fm-newcat-btn");
        return { host: !!host, btn: !!btn, btnText: btn?.textContent || "" };
    });
    expect(has.host).toBeTruthy();
    expect(has.btn).toBeTruthy();
    expect(has.btnText).toContain("Nuova categoria");
});

test("Phase 24.48 — custom category con scope persiste in localStorage + appare in sidepage", async ({ page }) => {
    test.setTimeout(60_000);
    await loginTeacher(page);
    await page.goto("/");
    await page.waitForLoadState("domcontentloaded");
    // Reset storage
    await page.evaluate(() => localStorage.removeItem("fm.risdoc.customCategories"));
    await page.evaluate(() => {
        document.querySelector('.fm-sb-sec[data-sidepage="risdoc"]')?.click();
    });
    await page.waitForTimeout(2500);

    // Forza scope ind=sc, cls=2s
    await page.evaluate(() => {
        const ind = document.getElementById("sel-iis"); if (ind) ind.value = "sc";
        const cls = document.getElementById("sel-cls"); if (cls) cls.value = "2s";
    });
    // Aggiungi custom cat via API + re-render
    await page.evaluate(() => {
        window.FM.RisdocSidepage.saveCustomCategories({
            STAMPA: { label: "Stampa-test", origin: "risdoc", ind: "sc", cls: "2s" },
        });
        window.FM.RisdocSidepage.loadSidepage("risdoc", {
            panelId: "fm-sp-risdoc", origin: "risdoc",
            categories: ["MODELLI", "RISORSE"],
        });
    });
    await page.waitForTimeout(1500);

    const dom = await page.evaluate(() => {
        const sp = document.getElementById("fm-sp-risdoc");
        const blocks = [...sp.querySelectorAll("ul.fm-db-block")];
        return blocks.map((b) => ({
            section: b.dataset.section,
            label: b.querySelector(".fm-db-head-label")?.textContent || "",
        }));
    });
    const stampa = dom.find((b) => b.section === "STAMPA");
    expect(stampa, "block STAMPA renderizzato").toBeTruthy();
    expect(stampa.label).toBe("Stampa-test");

    // Cleanup
    await page.evaluate(() => {
        window.FM.RisdocSidepage.deleteCustomCategory("STAMPA");
    });
});

test("category label override via dblclick persiste in localStorage", async ({ page }) => {
    test.setTimeout(60_000);
    await loginTeacher(page);

    await page.goto("/");
    await page.waitForLoadState("domcontentloaded");
    await page.evaluate(() => {
        for (const k of Object.keys(localStorage)) {
            if (k === "fm.risdoc.catLabels") localStorage.removeItem(k);
        }
        document.querySelector('.fm-sb-sec[data-sidepage="risdoc"]')?.click();
    });
    await page.waitForTimeout(2500);

    // Forza override via API helper (no dblclick interactive)
    await page.evaluate(() => {
        window.FM.RisdocSidepage.saveLabelOverride("RISORSE", "MIE RISORSE PERSONALI");
    });

    // Verifica localStorage
    const stored = await page.evaluate(() => localStorage.getItem("fm.risdoc.catLabels"));
    expect(stored).toContain("MIE RISORSE PERSONALI");

    // Verifica labelOfCategory
    const label = await page.evaluate(() => window.FM.RisdocSidepage.labelOfCategory("RISORSE"));
    expect(label).toBe("MIE RISORSE PERSONALI");

    // Cleanup
    await page.evaluate(() => localStorage.removeItem("fm.risdoc.catLabels"));
});
