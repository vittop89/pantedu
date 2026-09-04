/**
 * G27.batch-sync UI — il file tree del preview-editor mostra UNA SOLA entry
 * per `versioni/tikz_preamble.tex` (e altri file shared) anche se la batch
 * ha più varianti.
 *
 * Test lato user (UI reale): apre la verifica nel modal preview e conta
 * le occorrenze visibili di tikz_preamble.te.
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

test("file tree mostra 1 sola entry tikz_preamble (no duplicati cross-variant)", async ({ page }) => {
    test.setTimeout(120_000);
    await login(page);
    const csrf = await fetchCsrf(page);

    // saveBatch fresh con 4 varianti A/B × SOL/NOR
    const payload = {
        version: "A",
        verTitle: `FILETREE_DEDUP_${Date.now()}`,
        selectedIIS: "ar", selectedCLS: "2s", selectedMATER: "MAT",
        anno: "2025-26", sezione: "B",
        problems: [{
            filePath: "/eser/ar/ar2s/MAT/1",
            problemId: "problem-tree",
            position: 1, type: "Collect",
            text: "Test:",
            items: [{ html: "<p>Test</p>", points: 1.0, includeSolution: false }],
        }],
        materia: "MAT", title: "FILETREE DEDUP",
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
    expect(docs.length).toBe(4);

    // Carica preview-editor bundle e apri modal con i 4 docs reali
    await page.goto("/login");
    await page.evaluate(async () => {
        const m = await fetch("/build/manifest.json").then(r => r.json());
        const entry = m["js/entries/verifica-preview-editor.js"];
        await import(`/build/${entry.file}`);
    });
    await page.evaluate((docs) => {
        // Passa i docs reali al modal — il modal fetch-erà tex-files
        // dal server per ognuno (autenticato via session cookie).
        window.FM.VerificaPreview.openPreview(docs);
    }, docs);

    // Aspetta che il filetree sia popolato (i fetch tex-files completino)
    await page.waitForSelector('.fm-vp-filetree__item', { timeout: 15000 });
    await page.waitForTimeout(2000);  // assicura tutti i 4 fetch completi

    // Conta entries con "tikz_preamble" visibili nel filetree
    const tikzEntries = await page.locator('.fm-vp-filetree__item').filter({ hasText: /tikz_preamble/i }).count();
    console.log(`tikz_preamble entries visibili: ${tikzEntries}`);
    expect(tikzEntries).toBe(1);  // Prima del fix erano 2-4 (1 per variante)

    // Verifica anche che fonti_SOL.tex sia 1 sola entry (era duplicato A_SOL+B_SOL)
    const fontiEntries = await page.locator('.fm-vp-filetree__item').filter({ hasText: /fonti_SOL/i }).count();
    console.log(`fonti_SOL entries visibili: ${fontiEntries}`);
    expect(fontiEntries).toBe(1);
});
