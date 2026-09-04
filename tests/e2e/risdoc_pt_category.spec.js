/**
 * Phase 24.40 — verifica categoria sidepage risdoc + merge teacher_content.
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

// Phase 24.58 DEPRECATED — il merge teacher_content per type=risdoc nella
// sidepage è stato sostituito da multi-instance fork (risdoc_multi_instance.spec.js).
// Il teacher_content body_pt path resta solo backward-compat dei dati esistenti.
test.skip("teacher_content type=risdoc con metadata.category appare nel risdoc sidepage", async ({ page }) => {
    test.setTimeout(60_000);

    const errors = [];
    page.on("pageerror", (err) => errors.push(`[pageerror] ${err.message}`));

    await loginTeacher(page);

    // Crea content type=risdoc con metadata.category=RISORSE
    const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
    const title = "Test categoria " + Date.now().toString(36);
    const create = await page.request.post("/api/teacher/content", {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({
            _csrf: csrf,
            type: "risdoc",
            subject: "MAT",
            indirizzo: "sc",
            classe: "2s",
            topic: "CatTest-" + Date.now().toString(36),
            title,
            visibility: "draft",
            metadata: JSON.stringify({
                category: "RISORSE",
                body_pt: [{ _type: "block", style: "normal", children: [
                    { _type: "span", text: "Cat test body", marks: [] },
                ]}],
            }),
        }).toString(),
    });
    expect(create.ok(), `create ${create.status()}`).toBeTruthy();
    const id = (await create.json()).id;

    try {
        await page.goto("/");
        await page.waitForLoadState("domcontentloaded");

        // Apri risdoc sidepage
        await page.evaluate(() => {
            document.querySelector('.fm-sb-sec[data-sidepage="risdoc"]')?.click();
        });
        await page.waitForTimeout(2500); // attesa fetch + merge

        // Verifica li col title presente in categoria RISORSE
        const found = await page.evaluate((expectedTitle) => {
            const sp = document.getElementById("fm-sp-risdoc");
            const lis = sp?.querySelectorAll("ul.fm-db-block li[data-user-created='1']") || [];
            for (const li of lis) {
                const text = li.textContent || "";
                if (text.includes(expectedTitle)) {
                    const ulCat = li.closest("ul")?.dataset?.category;
                    return { found: true, category: ulCat, hasContentId: !!li.dataset.contentId };
                }
            }
            return { found: false };
        }, title);

        expect(found.found, "li teacher_content visibile in sidepage").toBeTruthy();
        expect(found.category, "in categoria RISORSE").toBe("RISORSE");
        expect(found.hasContentId, "data-content-id presente").toBeTruthy();

        expect(errors, `pageerrors:\n${errors.join("\n")}`).toHaveLength(0);
    } finally {
        await page.request.post(`/api/teacher/content/${id}/delete`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({ _csrf: csrf }).toString(),
        });
    }
});
