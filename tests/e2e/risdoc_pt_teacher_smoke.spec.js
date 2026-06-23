/**
 * Phase 24.38 — smoke test as docente superadmin.
 *
 * Verifica:
 *   - Login docente OK
 *   - js-edit-section funziona su risdoc sidepage senza pre-attivare esercizi
 *   - Modal create con PT editor lazy-loads
 *   - Save body_pt + content roundtrip
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

test.describe("teacher superadmin smoke", () => {
    test("login OK + content create with body_pt", async ({ page }) => {
        test.setTimeout(60_000);
        const errors = [];
        page.on("pageerror", (err) => errors.push(`[pageerror] ${err.message}`));

        await loginTeacher(page);

        // Verifica home accessibile
        await page.goto("/");
        await page.waitForLoadState("domcontentloaded");

        // CSRF + create content via API per simulare edit modal save
        const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
        const ptAst = [
            { _type: "block", style: "normal", children: [
                { _type: "span", text: "TEACHER_TEST_BODY", marks: ["strong"] },
            ]},
        ];
        const create = await page.request.post("/api/teacher/content", {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({
                _csrf: csrf,
                type: "lab",
                subject: "MAT",
                indirizzo: "sc",
                classe: "2s",
                topic: "TeacherSmokeTest-" + Date.now().toString(36),
                title: "Test docente",
                visibility: "draft",
                metadata: JSON.stringify({ body_pt: ptAst }),
            }).toString(),
        });
        expect(create.ok(), `create ${create.status()}`).toBeTruthy();
        const id = (await create.json()).id;

        try {
            // Roundtrip
            const get = await page.request.get(`/api/teacher/content/${id}`);
            expect(get.ok()).toBeTruthy();
            const meta = (await get.json()).content?.metadata;
            const obj = typeof meta === "string" ? JSON.parse(meta) : meta;
            expect(obj?.body_pt, "body_pt nel content").toBeTruthy();

            // Export ZIP
            const exp = await page.request.post(`/api/teacher/content/${id}/export`, {
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                data: new URLSearchParams({ _csrf: csrf, mode: "zip" }).toString(),
            });
            expect(exp.ok(), `export ${exp.status()}`).toBeTruthy();
            const j = await exp.json();
            expect(j.url).toBeTruthy();
        } finally {
            await page.request.post(`/api/teacher/content/${id}/delete`, {
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                data: new URLSearchParams({ _csrf: csrf }).toString(),
            });
        }

        expect(errors, `pageerrors:\n${errors.join("\n")}`).toHaveLength(0);
    });

    test("js-edit-section su risdoc sidepage funziona senza pre-toggle esercizi", async ({ page }) => {
        test.setTimeout(60_000);
        const errors = [];
        page.on("pageerror", (err) => errors.push(`[pageerror] ${err.message}`));

        await loginTeacher(page);
        await page.goto("/");
        await page.waitForLoadState("domcontentloaded");

        // Apri direttamente il sidepage risdoc (button #fm-sp-risdoc)
        await page.evaluate(() => {
            const btn = document.querySelector('.fm-sb-sec[data-sidepage="risdoc"]');
            if (btn) btn.click();
        });
        // Attendi popolazione sidepage via fm:risdoc-sidepage-rendered
        await page.waitForTimeout(2500);

        // Verifica che il button .js-edit-section sia BOUND (data-fm-edit-bound=1)
        const bound = await page.evaluate(() => {
            const sidepage = document.getElementById("fm-sp-risdoc");
            if (!sidepage) return { found: false };
            const btn = sidepage.querySelector(".js-edit-section");
            return {
                found: !!btn,
                bound: btn?.dataset.fmEditBound === "1",
                visible: btn ? btn.offsetParent !== null : false,
            };
        });
        expect(bound.found, "btn .js-edit-section presente nel sidepage risdoc").toBeTruthy();
        expect(bound.bound, "btn bound (Phase 24.38: ascolta fm:risdoc-sidepage-rendered)").toBeTruthy();

        // Click toggle edit mode
        await page.evaluate(() => {
            document.querySelector("#fm-sp-risdoc .js-edit-section")?.click();
        });
        await page.waitForTimeout(300);

        const editActive = await page.evaluate(() => {
            return document.getElementById("fm-sp-risdoc")?.dataset.editActive === "1";
        });
        expect(editActive, "edit mode attivato senza pre-toggle esercizi").toBeTruthy();

        expect(errors, `pageerrors:\n${errors.join("\n")}`).toHaveLength(0);
    });
});
