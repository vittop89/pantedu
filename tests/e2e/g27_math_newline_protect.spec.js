/**
 * G27.math-protect — Sanitizer NON deve convertire newline → \\ dentro math
 * blocks ($...$, \begin{cases}...\end{cases}). Pattern utente reale che
 * causava "Missing }" pdflatex error in solution con frazioni multi-line.
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

test("math multi-line in solution: \\\\ NON deve apparire dentro $...\\begin{cases}...$", async ({ page }) => {
    test.setTimeout(180_000);
    await login(page);
    const csrf = await fetchCsrf(page);

    // Pattern math con newline (HTML wrapping inside math) che il legacy
    // sanitizer convertiva a `\\` rompendo \dfrac{...} args.
    const mathSolution = `Risolvo: $\\begin{cases}
\\dfrac{2y-2\\cdot
2(x+1)}{2y(x+1)}=\\dfrac{7x+1}{2y(x+1)}\\\\
y-3x=0
\\end{cases}$ Quindi sostituisco.`;

    const payload = {
        version: "A",
        verTitle: `MATH_PROT_${Date.now()}`,
        selectedIIS: "sc", selectedCLS: "1s", selectedMATER: "MAT",
        anno: "2025-26", sezione: "B",
        problems: [{
            filePath: "/eser/sc/sc1s/MAT/1",
            problemId: "math-prot",
            position: 1, type: "Collect",
            text: "Risolvi:",
            items: [{
                html: "Equazione frazione.",
                solution: mathSolution,
                points: 1.0,
                includeSolution: true,
            }],
        }],
        materia: "MAT", title: "MATH PROT",
        dsa: false, compensa: false,
        includeGriglia: true, includeMisure: true,
        nPrint: 0, nPrintDSA: 0, nPrintDIS: 0,
        tipologia: "scritto",
        variants: ['A_SOL'],
    };
    const r = await page.request.post("/api/verifica/save-tex-batch?force=1", {
        data: payload,
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
    });
    expect(r.status()).toBe(200);
    const docs = (await r.json()).docs || [];
    const aSol = docs.find(d => d.variant === "A_SOL");
    expect(aSol).toBeTruthy();

    // Estrai esercizi_SOL.tex; verifica che dentro il math block NON ci sia
    // una sostituzione newline→\\ che rompe \dfrac.
    const filesR = await page.request.get(`/api/verifica/${aSol.id}/tex-files`);
    const files = (await filesR.json()).files;
    const eserc = files.find(f => f.path === "versioni/esercizi_SOL.tex");
    expect(eserc).toBeTruthy();
    // Pattern bug: `\dfrac{...\cdot \\\n` o `\dfrac{...\\\n2(x+1)}` — dfrac
    // arg interrotto da \\.
    const mathBugPattern = /\\dfrac\{[^}]*\\\\(?:\s*\n\s*)\d/;
    expect(mathBugPattern.test(eserc.content)).toBe(false);
    // Pattern sano: math intatto con \dfrac{2y-2\cdot ... 2(x+1)}{...}
    expect(eserc.content).toContain("\\dfrac{");

    // Compile end-to-end: deve avere ok=true (senza math protect → 422 errore)
    let compiled = false;
    for (let attempt = 0; attempt < 4; attempt++) {
        if (attempt > 0) await page.waitForTimeout(20_000);
        const compR = await page.request.post(`/api/verifica/${aSol.id}/compile`, {
            data: { engine: 'pdflatex' },
            headers: { "X-CSRF-Token": csrf, "Content-Type": "application/json" },
            timeout: 90_000,
        });
        if (compR.status() === 200 && (await compR.json()).ok) { compiled = true; break; }
    }
    expect(compiled, "compile success (math protected)").toBe(true);
});
