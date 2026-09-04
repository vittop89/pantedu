/**
 * G22.S15.bis Fase 5 — il delete di una verifica (🗑 fm-item-del) deve
 * cancellare TUTTE le varianti del batch (SOL/NOR/DSA/DIS), non solo la
 * variante rappresentativa visibile in sidepage.
 *
 * Pre-fix: cancellava solo la row con id=docId clickato (di solito la SOL).
 * Le altre varianti (NOR/DSA/DIS) restavano orfane in DB → al reload il
 * sidepage le ripescava e mostrava il link attivo, mentre la SOL era andata.
 *
 * Inoltre, con Option C blob dedup (sha256), le varianti possono condividere
 * blob fisici. Cancellare una sola variante avrebbe rotto i blob shared
 * referenziati dalle altre → manifest corrotte.
 */
const { test, expect } = require("@playwright/test");

const USERNAME = process.env.FM_E2E_USER || "superadmin";
const PASSWORD = process.env.FM_E2E_PASS || (process.env.E2E_TEACHER_PASS || "");

test("Delete batch: 🗑 cancella tutte le varianti SOL/NOR del batch", async ({ page }) => {
    test.setTimeout(60000);
    page.on("dialog", async d => { await d.accept(); });

    await page.goto("/login");
    await page.locator("input[name=username]").fill(USERNAME);
    await page.locator("input[name=password]").fill(PASSWORD);
    await page.locator("button[type=submit]").first().click();
    await page.waitForLoadState("networkidle");
    const csrf = await page.request.get("/auth/csrf").then(r => r.json()).then(j => j.token);

    // Crea batch con varianti A_SOL + A_NOR (così verifichiamo delete cascade).
    const tname = `G22-S15bis-DEL-BATCH-${Date.now()}`;
    const save = await page.request.post("/api/verifica/save-tex-batch?force=1", {
        data: {
            verTitle: tname, selectedIIS: "sc", selectedCLS: "3", selectedMATER: "MAT",
            anno: "2026", sezione: "NOR",
            problems: [{
                filePath: "/x", problemId: "type_Collect_x", position: 1, type: "Collect",
                text: "Risolvi.",
                items: [{ html: `Calcola \\(2+2\\).`, solution: `\\(=4\\)`, points: 1.0, includeSolution: false }],
            }],
            options: { includeSolutions: false },
            versions: ["A"],
            title: tname, materia: "MAT", indirizzo: "sc", classe: "3",
            version_label: "g22-s15bis-del-batch",
            // SOL implicito + NOR esplicito → 2 varianti (A_SOL, A_NOR)
            nPrint: 1, nPrintDSA: 0, nPrintDIS: 0, force: true,
        },
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
    }).then(r => r.json());
    expect(save.ok, "save-tex-batch deve riuscire").toBe(true);
    expect(save.docs.length, "almeno 2 varianti generate (SOL+NOR)").toBeGreaterThanOrEqual(2);

    const allIds = save.docs.map(d => d.id);
    const variantsByVKey = save.docs.reduce((acc, d) => { acc[d.variant] = d.id; return acc; }, {});
    const batchId = save.batch_id;
    console.log(`Batch ${batchId}: variants ids=`, variantsByVKey);
    expect(batchId, "batch_id presente").toBeTruthy();

    // Verifica che esistano via /api/verifica/list (visibilità lato server)
    const beforeList = await page.request.get("/api/verifica/list").then(r => r.json());
    const beforeIds = beforeList.items.map(it => it.id);
    for (const id of allIds) {
        expect(beforeIds, `id=${id} presente prima del delete`).toContain(id);
    }

    // Vai su pagina esercizio per attivare la sidepage Verifiche
    await page.goto("/studio/esercizio/sc/3/MAT/1");
    await page.evaluate(() => {
        const setSel = (id, val) => {
            const s = document.getElementById(id);
            if (s) { s.value = val; s.dispatchEvent(new Event("change", { bubbles: true })); }
        };
        setSel("sel-iis", "sc"); setSel("sel-cls", "3"); setSel("sel-mater", "MAT");
        sessionStorage.setItem("selectedIIS", "sc");
        sessionStorage.setItem("selectedCLS", "3");
        sessionStorage.setItem("selectedMATER", "MAT");
    });
    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(1500);

    // Apri sidepage Verifiche
    await page.evaluate(() => {
        document.querySelector('.fm-sb-sec[data-sidepage="verif"]')?.click();
    });
    await page.waitForTimeout(2000);

    // Attiva edit mode
    await page.evaluate(() => {
        document.querySelector('#fm-sp-verif .js-edit-section, .fm-sb-panel[data-sidepage=verif] .js-edit-section')?.click();
    });
    await page.waitForTimeout(500);

    // Trova il li (il sidepage applica dedupeByTitle → visibile sarà UNA riga
    // sola per il title, tipicamente la più recente / la SOL rappresentante).
    const visibleId = await page.evaluate((title) => {
        const lis = Array.from(document.querySelectorAll(
            "#fm-sp-verif li[data-fm-content-kind='verifica'], " +
            ".fm-sb-panel[data-sidepage=verif] li[data-fm-content-kind='verifica']"));
        const match = lis.find(li => li.querySelector(".fm-vd-link")?.textContent?.trim() === title);
        return match?.dataset.contentId || null;
    }, tname);
    expect(visibleId, `li con title=${tname} visibile in sidepage`).toBeTruthy();
    console.log(`Sidepage mostra id=${visibleId} (rappresentante del batch)`);

    // Click 🗑 → conferma dialog
    await page.evaluate((id) => {
        const li = document.querySelector(`li[data-content-id="${id}"][data-fm-content-kind="verifica"]`);
        li?.querySelector(".fm-item-del")?.click();
    }, visibleId);
    await page.waitForTimeout(400);
    const okBtn = page.locator('#fm-dialog-modal .fm-dialog-btn[data-action="ok"]');
    if (await okBtn.count() > 0) await okBtn.click();
    await page.waitForTimeout(2500);

    // Verifica che TUTTE le varianti del batch siano sparite (non solo la
    // visibleId) — il bug pre-fix lasciava le altre orfane.
    const afterList = await page.request.get("/api/verifica/list").then(r => r.json());
    const afterIds = afterList.items.map(it => it.id);
    for (const id of allIds) {
        expect(afterIds, `id=${id} (variante del batch ${batchId}) DEVE essere cancellata`).not.toContain(id);
    }

    // Verifica che il sidepage non mostri più il title (no link "attivo" residuo)
    await page.reload();
    await page.evaluate(() => {
        const setSel = (id, val) => {
            const s = document.getElementById(id);
            if (s) { s.value = val; s.dispatchEvent(new Event("change", { bubbles: true })); }
        };
        setSel("sel-iis", "sc"); setSel("sel-cls", "3"); setSel("sel-mater", "MAT");
    });
    await page.waitForTimeout(800);
    await page.evaluate(() => {
        document.querySelector('.fm-sb-sec[data-sidepage="verif"]')?.click();
    });
    await page.waitForTimeout(2000);
    const stillVisible = await page.evaluate((title) => {
        const lis = Array.from(document.querySelectorAll(
            "#fm-sp-verif li[data-fm-content-kind='verifica'], " +
            ".fm-sb-panel[data-sidepage=verif] li[data-fm-content-kind='verifica']"));
        return lis.some(li => li.querySelector(".fm-vd-link")?.textContent?.trim() === title);
    }, tname);
    expect(stillVisible, `Title "${tname}" NON deve apparire dopo reload (orfani residui = bug)`).toBe(false);
});
