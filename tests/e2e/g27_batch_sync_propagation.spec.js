/**
 * G27.batch-sync — POST /tex-files su una variante propaga ai sibling-row
 * dello stesso batch i path che hanno lo stesso nome.
 *
 * Regola: stesso path = stesso content. Mantiene l'invariante della dedup
 * applicata al saveBatch iniziale, evita drift tra varianti.
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

async function getTexFiles(page, docId) {
    const r = await page.request.get(`/api/verifica/${docId}/tex-files`);
    expect(r.status()).toBe(200);
    return (await r.json()).files;
}

test("edit tikz_preamble in A_SOL si propaga a A_NOR/B_SOL/B_NOR", async ({ page }) => {
    test.setTimeout(120_000);
    await login(page);
    const csrf = await fetchCsrf(page);

    const payload = {
        version: "A",
        verTitle: `BATCH_SYNC_${Date.now()}`,
        selectedIIS: "ar", selectedCLS: "2s", selectedMATER: "MAT",
        anno: "2025-26", sezione: "B",
        problems: [{
            filePath: "/eser/ar/ar2s/MAT/1",
            problemId: "problem-sync",
            position: 1,
            type: "Collect",
            text: "Test:",
            items: [{ html: "<p>Test</p>", points: 1.0, includeSolution: false }],
        }],
        materia: "MAT",
        title: "BATCH SYNC",
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
    expect(docs.length).toBe(4);  // A_SOL, A_NOR, B_SOL, B_NOR

    const aSol = docs.find(d => d.variant === "A_SOL");
    const otherDocs = docs.filter(d => d.variant !== "A_SOL");
    expect(aSol).toBeTruthy();

    // Get original tikz_preamble.tex content from A_SOL
    const filesIn = await getTexFiles(page, aSol.id);
    const tikzIdx = filesIn.findIndex(f => f.path === "versioni/tikz_preamble.tex");
    expect(tikzIdx).toBeGreaterThanOrEqual(0);
    const originalContent = filesIn[tikzIdx].content;

    // Modify tikz_preamble.tex in A_SOL with marker content
    const newContent = originalContent + "\n% G27-BATCH-SYNC-MARKER " + Date.now();
    const modifiedFiles = filesIn.map((f, i) => ({
        path: f.path,
        content: i === tikzIdx ? newContent : f.content,
    }));

    const postR = await page.request.post(`/api/verifica/${aSol.id}/tex-files`, {
        data: { files: modifiedFiles },
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
    });
    expect(postR.status()).toBe(200);
    const postBody = await postR.json();
    expect(postBody.synced_siblings).toBe(3);  // A_NOR, B_SOL, B_NOR

    // Verify ALL siblings now have the new marker in tikz_preamble.tex
    for (const d of otherDocs) {
        const sibFiles = await getTexFiles(page, d.id);
        const sibTikz = sibFiles.find(f => f.path === "versioni/tikz_preamble.tex");
        expect(sibTikz, `${d.variant} should have tikz_preamble.tex`).toBeTruthy();
        expect(sibTikz.content, `${d.variant} should have synced content`).toBe(newContent);
    }
});

test("edit main_NOR.tex in A_NOR propaga SOLO a B_NOR (non a SOL)", async ({ page }) => {
    test.setTimeout(120_000);
    await login(page);
    const csrf = await fetchCsrf(page);

    const payload = {
        version: "A",
        verTitle: `BATCH_SYNC_VARIANT_${Date.now()}`,
        selectedIIS: "ar", selectedCLS: "2s", selectedMATER: "MAT",
        anno: "2025-26", sezione: "B",
        problems: [{
            filePath: "/eser/ar/ar2s/MAT/1",
            problemId: "problem-sync2",
            position: 1, type: "Collect",
            text: "Test:",
            items: [{ html: "<p>Test</p>", points: 1.0, includeSolution: false }],
        }],
        materia: "MAT", title: "BATCH SYNC VARIANT",
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
    const aNor = docs.find(d => d.variant === "A_NOR");
    const bNor = docs.find(d => d.variant === "B_NOR");
    const aSol = docs.find(d => d.variant === "A_SOL");
    expect(aNor && bNor && aSol).toBeTruthy();

    // Modify main_NOR.tex in A_NOR
    const filesIn = await getTexFiles(page, aNor.id);
    const mainIdx = filesIn.findIndex(f => f.path === "versioni/main_NOR.tex");
    expect(mainIdx).toBeGreaterThanOrEqual(0);
    const newMain = filesIn[mainIdx].content + "\n% A_NOR-EDIT-MARKER";
    const modifiedFiles = filesIn.map((f, i) => ({
        path: f.path,
        content: i === mainIdx ? newMain : f.content,
    }));

    const postR = await page.request.post(`/api/verifica/${aNor.id}/tex-files`, {
        data: { files: modifiedFiles },
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
    });
    expect(postR.status()).toBe(200);
    const postBody = await postR.json();
    // Synced count = ALL siblings with at least 1 matching path. tikz_preamble.tex,
    // texCommon/*, griglie/* are SHARED by path between B_NOR/B_SOL/A_SOL → all 3
    // siblings get path-touched (even if only main_NOR.tex changed for B_NOR).
    // Solo B_NOR avrà però main_NOR.tex con il nuovo content.
    expect(postBody.synced_siblings).toBeGreaterThanOrEqual(1);

    // B_NOR has main_NOR.tex with the marker
    const bNorFiles = await getTexFiles(page, bNor.id);
    const bNorMain = bNorFiles.find(f => f.path === "versioni/main_NOR.tex");
    expect(bNorMain.content).toContain("A_NOR-EDIT-MARKER");

    // A_SOL non ha main_NOR.tex (path different) → NON sincronizzato per quel file
    const aSolFiles = await getTexFiles(page, aSol.id);
    const aSolHasMainNor = aSolFiles.some(f => f.path === "versioni/main_NOR.tex");
    expect(aSolHasMainNor).toBe(false);
});
