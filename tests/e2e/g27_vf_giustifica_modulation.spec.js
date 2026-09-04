/**
 * G27.vf.modulate — porting legacy checkgiust: tabella VF mostra riga
 * tratteggiata vuota se l'item ha giustifica (NOR), oppure testo inline
 * (SOL). Senza giustifica → tabella compatta.
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

test("Selection accetta type=type_VF (fallback) e renderVF emette tabella", async ({ page }) => {
    test.setTimeout(60_000);
    await login(page);
    const csrf = await fetchCsrf(page);
    const payload = {
        version: "A",
        verTitle: `VF_TYPE_FALLBACK_${Date.now()}`,
        selectedIIS: "ar", selectedCLS: "2s", selectedMATER: "MAT",
        anno: "2025-26", sezione: "B",
        problems: [{
            filePath: "/eser/ar/ar2s/MAT/1",
            problemId: "uuid-not-containing-type",
            position: 1,
            type: "type_VF",  // formato contract con prefisso (era buggato)
            text: "VoF:",
            items: [
                { html: "Aff1.", solution: "V", points: 1.0, includeSolution: false },
                { html: "Aff2.", solution: "F", points: 1.0, includeSolution: false },
            ],
        }],
        materia: "MAT", title: "VF TYPE FALLBACK",
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
    const aNor = docs.find(d => d.variant === "A_NOR");
    expect(aNor).toBeTruthy();
    const norFiles = await getTexFiles(page, aNor.id);
    const norEserc = norFiles.find(f => f.path === "versioni/esercizi_NOR.tex");
    // Deve contenere la tabella VF (Affermazione | V | F headers bold) — non enumerate \Alph*)
    expect(norEserc.content).toContain("\\textbf{\\#} & \\textbf{Affermazione} & \\textbf{V} & \\textbf{F}");
    expect(norEserc.content).not.toContain("[label={\\textbf{\\Alph*)},leftmargin=1.5em]");
});

test("VF NOR: marker GIUSTIFICAZIONE in field 'solution' (extraction frontend) trigger righe vuote", async ({ page }) => {
    // Replica il flusso reale dom-block-extractor: html=question, solution=sol+giustsol+marker.
    test.setTimeout(60_000);
    await login(page);
    const csrf = await fetchCsrf(page);
    const payload = {
        version: "A",
        verTitle: `VF_SOL_MARKER_${Date.now()}`,
        selectedIIS: "ar", selectedCLS: "2s", selectedMATER: "MAT",
        anno: "2025-26", sezione: "B",
        problems: [{
            filePath: "/eser/ar/ar2s/MAT/1",
            problemId: "vf-sol-marker",
            position: 1, type: "type_VF",
            text: "VoF:",
            items: [
                {
                    html: "Affermazione 1.",
                    // Marker fm-sol-label nel field SOLUTION (non html)
                    solution: "<strong class=\"fm-sol-label\">GIUSTIFICAZIONE</strong> ",
                    points: 1.0,
                    includeSolution: false,
                },
            ],
        }],
        materia: "MAT", title: "VF SOL MARKER",
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
    const aNor = docs.find(d => d.variant === "A_NOR");
    const norFiles = await getTexFiles(page, aNor.id);
    const norEserc = norFiles.find(f => f.path === "versioni/esercizi_NOR.tex");
    // Anche se html non ha marker, solution sì → cdashline emesso
    expect(norEserc.content).toContain("\\cdashline{1-4}");
});

test("VF NOR: item con giustifica → riga \\cdashline; senza → tabella compatta", async ({ page }) => {
    test.setTimeout(120_000);
    await login(page);
    const csrf = await fetchCsrf(page);

    // VF problem con 2 item: uno CON giust, uno SENZA
    // html include marker `\textbf{GIUSTIFICAZIONE}` per simulare contract render
    const payload = {
        version: "A",
        verTitle: `VF_GIUST_${Date.now()}`,
        selectedIIS: "ar", selectedCLS: "2s", selectedMATER: "MAT",
        anno: "2025-26", sezione: "B",
        problems: [{
            filePath: "/eser/ar/ar2s/MAT/1",
            problemId: "problem-vf-test",
            position: 1, type: "VF",
            text: "Vero o Falso:",
            items: [
                {
                    html: "Affermazione 1 ha giustifica.<strong class=\"fm-sol-label\">GIUSTIFICAZIONE</strong> Spiega il perché.",
                    solution: "V",
                    points: 1.0,
                    includeSolution: false,
                },
                {
                    html: "Affermazione 2 senza giustifica.",
                    solution: "F",
                    points: 1.0,
                    includeSolution: false,
                },
            ],
        }],
        materia: "MAT", title: "VF GIUSTIFICA TEST",
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
    const aSol = docs.find(d => d.variant === "A_SOL");
    expect(aNor && aSol).toBeTruthy();

    // NOR: deve contenere \cdashline (riga vuota tratteggiata) per item 1
    const norFiles = await getTexFiles(page, aNor.id);
    const norEserc = norFiles.find(f => f.path === "versioni/esercizi_NOR.tex");
    expect(norEserc).toBeTruthy();
    expect(norEserc.content).toContain("\\cdashline{1-4}");
    expect(norEserc.content).toContain("|r|>{\\raggedright\\arraybackslash}p{0.78\\linewidth}|>{\\centering\\arraybackslash}p{0.04\\linewidth}|>{\\centering\\arraybackslash}p{0.04\\linewidth}|");
    // hasGiust=true sempre per VF → 2 cdashline (1 per item, indipendenti dal marker)
    const cdashCount = (norEserc.content.match(/\\cdashline\{1-4\}/g) || []).length;
    expect(cdashCount).toBe(2);

    // SOL: deve contenere riquadro grigio Giustifica + content, NON \cdashline
    const solFiles = await getTexFiles(page, aSol.id);
    const solEserc = solFiles.find(f => f.path === "versioni/esercizi_SOL.tex");
    expect(solEserc).toBeTruthy();
    expect(solEserc.content).toContain("\\fcolorbox{gray!50}{gray!10}{\\textbf{Giustifica}}");
    expect(solEserc.content).not.toContain("\\cdashline{1-4}");

    // Compile end-to-end (retry per nginx rate limit 20r/m)
    let compiled = false;
    for (let attempt = 0; attempt < 4; attempt++) {
        if (attempt > 0) await page.waitForTimeout(20_000);
        const compileR = await page.request.post(`/api/verifica/${aNor.id}/compile`, {
            headers: { "X-CSRF-Token": csrf },
            timeout: 90_000,
        });
        if (compileR.status() === 200 && (await compileR.json()).ok) {
            compiled = true; break;
        }
    }
    expect(compiled).toBe(true);
});
