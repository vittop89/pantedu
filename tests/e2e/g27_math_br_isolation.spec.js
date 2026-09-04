/**
 * G27.math-br — Sanitizer non deve convertire <br> dentro $...$ math in \\
 * (rompeva \dfrac{...}{...} → "Missing }" pdflatex error).
 */
const { test, expect } = require("@playwright/test");

const TEACHER_USER = "superadmin";
const TEACHER_PASS = (process.env.E2E_TEACHER_PASS || "");

async function login(page) {
    await page.addInitScript(() => {
        localStorage.setItem("user_cookie_consent_v2", JSON.stringify({
            functional: true, analytics: false, advertising: false, timestamp: Date.now(),
        }));
    });
    await page.goto("/login");
    await page.fill('input[name="username"]', TEACHER_USER);
    await page.fill('input[name="password"]', TEACHER_PASS);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/, { timeout: 10000 }),
        page.click('button[type="submit"]'),
    ]);
}
async function fetchCsrf(page) {
    return (await (await page.request.get("/auth/csrf")).json()).token;
}

test("math con <br> dentro \\dfrac: NO \\\\ generato + compile OK", async ({ page }) => {
    test.setTimeout(300_000);
    await login(page);
    const csrf = await fetchCsrf(page);

    const payload = {
        version: "A",
        verTitle: `MATH_BR_${Date.now()}`,
        selectedIIS: "ar", selectedCLS: "2s", selectedMATER: "MAT",
        anno: "2025-26", sezione: "B",
        problems: [{
            filePath: "/eser/ar/ar2s/MAT/1",
            problemId: "math-br",
            position: 1, type: "Collect",
            text: "Risolvi:",
            items: [{
                // Pattern reale: <br> dentro \dfrac argument
                html: 'Equazione: $\\dfrac{2y-2\\cdot<br>2(x+1)}{2y(x+1)}=\\dfrac{7x+1}{2y(x+1)}$',
                solution: '$\\dfrac{a+b}{c+d}<br>=\\dfrac{a-b}{c-d}$',
                points: 1.0, includeSolution: true,
            }],
        }],
        materia: "MAT", title: "MATH BR",
        dsa: false, compensa: false,
        includeGriglia: true, includeMisure: true,
        nPrint: 1, nPrintDSA: 0, nPrintDIS: 0,
        tipologia: "scritto",
    };
    const saveR = await page.request.post("/api/verifica/save-tex-batch?force=1", {
        data: payload,
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
    });
    expect(saveR.status()).toBe(200);
    const docs = (await saveR.json()).docs || [];
    const aSol = docs.find(d => d.variant === "A_SOL");
    const aNor = docs.find(d => d.variant === "A_NOR");
    expect(aSol && aNor).toBeTruthy();

    // Verifica TEX SOL: dentro $...$ NON deve esserci \\ né <br>
    const filesR = await page.request.get(`/api/verifica/${aSol.id}/tex-files`);
    const eserc = (await filesR.json()).files.find(f => f.path === "versioni/esercizi_SOL.tex");
    expect(eserc).toBeTruthy();
    // Estrai contenuti tra $ e $
    const mathBlocks = eserc.content.match(/\$[^$]+\$/g) || [];
    console.log(`[MATH] blocks found: ${mathBlocks.length}`);
    for (const m of mathBlocks) {
        expect(m, `math '${m}' contains \\\\ (rompe \\dfrac)`).not.toMatch(/\\\\/);
        expect(m, `math '${m}' contains <br> letterale`).not.toMatch(/<br/);
    }

    // Compile end-to-end SOL — non deve fallire con "Missing }"
    let compiled = false;
    for (let attempt = 0; attempt < 3; attempt++) {
        if (attempt > 0) await page.waitForTimeout(20_000);
        const compR = await page.request.post(`/api/verifica/${aSol.id}/compile`, {
            data: { engine: 'pdflatex' },
            headers: { "X-CSRF-Token": csrf, "Content-Type": "application/json" },
            timeout: 90_000,
        });
        if (compR.status() === 200 && (await compR.json()).ok) { compiled = true; break; }
    }
    expect(compiled).toBe(true);
});
