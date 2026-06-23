/**
 * G27.ggb-zero-byte regression — POST /api/verifica/{id}/tex-files NON deve
 * sovrascrivere binari (geogebra/N.pdf, immagini) con 0 byte quando il
 * frontend rimanda i file con content='' (placeholder per is_binary=true).
 *
 * Bug originale: getTexFiles ritornava binari con content vuoto come
 * placeholder. Il preview-editor rimandava in POST tutti i file inclusi
 * binari → updateTexFiles vedeva sha256(empty) ≠ blob esistente → scriveva
 * NUOVO blob da 0 byte. Il successivo /compile falliva con
 * "pdflatex: reading image file failed".
 *
 * Fix: VerificaDocumentService::updateTexFiles riconosce content='' su path
 * binary (.pdf|.png|.jpe?g|.gif|.svg|.webp) E con entry esistente in
 * old manifest → preserva entry as-is.
 */
const { test, expect } = require("@playwright/test");

const TEACHER_USER = "superadmin";
const TEACHER_PASS = (process.env.E2E_TEACHER_PASS || "");

const SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80"><rect width="60" height="60" x="10" y="10" fill="lightyellow" stroke="darkgreen" stroke-width="2"/><text x="40" y="48" font-size="24" text-anchor="middle" fill="darkgreen">G</text></svg>';

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

test("POST tex-files con content vuoto su .pdf preserva blob esistente", async ({ page }) => {
    test.setTimeout(60_000);
    await login(page);
    const csrf = await fetchCsrf(page);

    const payload = {
        version: "A",
        verTitle: `GGB_PRESERVE_${Date.now()}`,
        selectedIIS: "ar", selectedCLS: "2s", selectedMATER: "MAT",
        anno: "2025-26", sezione: "B",
        problems: [{
            filePath: "/eser/ar/ar2s/MAT/1",
            problemId: "problem-ggb-preserve",
            position: 1,
            type: "Collect",
            text: "Test preservazione binari:",
            items: [{
                html: '<p>Item GGB: <span class="fm-geogebra-wrap" data-ggb-label="T" data-ggb-width="40%">' + SVG + '</span></p>',
                points: 1.0,
                includeSolution: false,
            }],
        }],
        materia: "MAT",
        title: "GGB PRESERVE",
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
    const sol = docs.find(d => d.variant === "A_SOL");
    expect(sol).toBeTruthy();

    // GET tex-files: binary (PDF) ritorna content='' come placeholder
    const getR = await page.request.get(`/api/verifica/${sol.id}/tex-files`);
    expect(getR.status()).toBe(200);
    const filesIn = (await getR.json()).files;
    const ggbIn = filesIn.find(f => f.path === "versioni/geogebra/1.pdf");
    expect(ggbIn).toBeTruthy();
    expect(ggbIn.is_binary).toBe(true);
    expect(ggbIn.size).toBeGreaterThan(0);
    expect(ggbIn.content).toBe("");

    // POST as-is (round-trip) — content='' per binari
    const postR = await page.request.post(`/api/verifica/${sol.id}/tex-files`, {
        data: { files: filesIn.map(f => ({ path: f.path, content: f.content })) },
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
    });
    expect(postR.status()).toBe(200);

    // Verify: binary preserved (NON sovrascritto a 0 byte)
    const verifyR = await page.request.get(`/api/verifica/${sol.id}/tex-files`);
    const filesOut = (await verifyR.json()).files;
    const ggbOut = filesOut.find(f => f.path === "versioni/geogebra/1.pdf");
    expect(ggbOut).toBeTruthy();
    expect(ggbOut.size).toBe(ggbIn.size);  // <-- regression check: prima del fix era 0
});
