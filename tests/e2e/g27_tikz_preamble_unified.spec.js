/**
 * G27.tikz.unified — saveBatch deve emettere UN SOLO `versioni/tikz_preamble.tex`
 * (no _KIND suffix), il cui contenuto è identico cross-variant.
 * Compile end-to-end deve riuscire (pdflatex risolve `\input{tikz_preamble}`).
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

test("saveBatch emette versioni/tikz_preamble.tex (no suffix) + compile OK", async ({ page }) => {
    test.setTimeout(120_000);
    await login(page);
    const csrf = await fetchCsrf(page);

    const payload = {
        version: "A",
        verTitle: `TIKZ_UNIFIED_${Date.now()}`,
        selectedIIS: "ar", selectedCLS: "2s", selectedMATER: "MAT",
        anno: "2025-26", sezione: "B",
        problems: [{
            filePath: "/eser/ar/ar2s/MAT/1",
            problemId: "problem-tikz",
            position: 1,
            type: "Collect",
            text: "Esercizio:",
            items: [{ html: "<p>Test</p>", points: 1.0, includeSolution: false }],
        }],
        materia: "MAT",
        title: "TIKZ UNIFIED",
        dsa: false, compensa: false,
        includeGriglia: true, includeMisure: true,
        nPrint: 1, nPrintDSA: 0, nPrintDIS: 0,
        tipologia: "scritto",
    };

    const r = await page.request.post("/api/verifica/save-tex-batch?force=1", {
        data: payload,
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
    });
    expect(r.status()).toBe(200);
    const docs = (await r.json()).docs || [];
    expect(docs.length).toBeGreaterThanOrEqual(2);

    // Per ogni variante: GET tex-files, verifica ESISTE tikz_preamble.tex (no suffix)
    // e NON esiste tikz_preamble_KIND.tex
    for (const d of docs) {
        const f = await page.request.get(`/api/verifica/${d.id}/tex-files`);
        const files = (await f.json()).files;
        const paths = files.map(x => x.path);
        expect(paths).toContain("versioni/tikz_preamble.tex");
        const suffixed = paths.filter(p => /tikz_preamble_(SOL|NOR|DSA|DIS)\.tex/.test(p));
        expect(suffixed).toEqual([]);
    }

    // Compile A_SOL e verifica PDF prodotto
    const sol = docs.find(d => d.variant === "A_SOL");
    expect(sol).toBeTruthy();
    const compileR = await page.request.post(`/api/verifica/${sol.id}/compile`, {
        headers: { "X-CSRF-Token": csrf },
        timeout: 60_000,
    });
    expect(compileR.status()).toBe(200);
    const compileBody = await compileR.json();
    expect(compileBody.ok).toBe(true);
    expect(compileBody.compile.pdf_bytes).toBeGreaterThan(1000);
});
