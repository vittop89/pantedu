/**
 * G20.7 — Verifica che il delete (🗑 fm-item-del) nella sidepage Verifiche
 * elimini il record verifica_documents (TEX + PDF) lato server.
 *
 * Bug pre-fix: addInlineItemActions chiamato sincrono prima che
 * verifica-documents-sidepage finisse l'append async dei <li>. Risultato:
 * niente .fm-item-actions sui li verifica → niente 🗑 visibile in edit mode.
 */
const { test, expect } = require("@playwright/test");

const USERNAME = process.env.FM_E2E_USER || "superadmin";
const PASSWORD = process.env.FM_E2E_PASS || (process.env.E2E_TEACHER_PASS || "");

test("Sidepage Verifiche: 🗑 fm-item-del elimina via /api/verifica/{id}/delete", async ({ page }) => {
    test.setTimeout(60000);

    page.on("dialog", async d => { await d.accept(); });

    await page.goto("/login");
    await page.locator("input[name=username]").fill(USERNAME);
    await page.locator("input[name=password]").fill(PASSWORD);
    await page.locator("button[type=submit]").first().click();
    await page.waitForLoadState("networkidle");
    const csrf = await page.request.get("/auth/csrf").then(r => r.json()).then(j => j.token);

    // Setup: crea una verifica freschissima da poter cancellare
    const PUB = String.raw`\(\enclose{circle}[mathcolor=red]{x}\)`;
    const tname = `G20-7-DEL-${Date.now()}`;
    const save = await page.request.post("/api/verifica/save-tex-batch?force=1", {
        data: {
            verTitle: tname, selectedIIS: "sc", selectedCLS: "3", selectedMATER: "MAT",
            anno: "2026", sezione: "NOR",
            problems: [{ filePath: "/x", problemId: "type_Collect_x", position: 1, type: "Collect",
                text: "Risolvi.", items: [{ html: `Determina ${PUB}.`, solution: String.raw`\(x = 4\)`, points: 1.0, includeSolution: false }] }],
            options: { includeSolutions: false }, versions: ["A"], title: tname, materia: "MAT",
            indirizzo: "sc", classe: "3", version_label: "g20-7-del", nPrint: 1, nPrintDSA: 0, nPrintDIS: 0, force: true,
        },
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
    }).then(r => r.json());
    expect(save.ok).toBe(true);
    // dedupeByTitle in sidepage tiene il piu' RECENTE; prendiamo l'ultimo id.
    const allIds = save.docs.map(d => d.id);
    console.log(`Created verifica ids=${allIds.join(",")} title=${tname}`);
    const docId = Math.max(...allIds);

    // Vai su una pagina esercizio per vedere la sidepage. Allinea i select
    // della sidebar (#sel-iis/#sel-cls/#sel-mater) alla verifica appena
    // creata (la sidepage `fetchVerifiche` legge dai SELECT, non da
    // sessionStorage).
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

    // Apri sidepage Verifiche via click programmatico (overlay potrebbe intercettare)
    await page.evaluate(() => {
        document.querySelector('.fm-sb-sec[data-sidepage="verif"]')?.click();
    });
    await page.waitForTimeout(2000);

    // Activa edit mode + verifica delete btn presente
    await page.evaluate(() => {
        document.querySelector('#fm-sp-verif .js-edit-section, .fm-sb-panel[data-sidepage=verif] .js-edit-section')?.click();
    });
    await page.waitForTimeout(500);

    const sidepagePresent = await page.evaluate(() => {
        const sp = document.getElementById("fm-sp-verif") || document.querySelector('.fm-sb-panel[data-sidepage=verif]');
        return sp ? {
            visible: sp.style.display,
            display: getComputedStyle(sp).display,
            childCount: sp.children.length,
            allUls: Array.from(sp.querySelectorAll("ul.fm-db-block")).map(u => ({
                materia: u.dataset.materia, type: u.dataset.type, kind: u.dataset.sectionKind,
                liCount: u.querySelectorAll("li[data-content-id]").length,
                hidden: u.style.display === "none",
            })),
            allLiTitles: Array.from(sp.querySelectorAll("li[data-content-id] .fm-vd-link")).map(a => a.textContent.trim()).slice(0, 8),
        } : null;
    });
    console.log("Sidepage state:", JSON.stringify(sidepagePresent));

    // diag: chiama l'API direttamente con stesso scope
    const apiResp = await page.request.get("/api/verifica/list?indirizzo=sc&classe=3").then(r => r.json());
    console.log("API list count:", apiResp.items?.length, "first:", apiResp.items?.[0]?.title);
    const apiHit = apiResp.items?.find(it => it.title?.includes(tname));
    console.log("API hit:", apiHit ? `id=${apiHit.id} title="${apiHit.title}" ind=${apiHit.indirizzo} cls=${apiHit.classe}` : "MISSING");

    // Trova il li per TITLE (dedupeByTitle puo' aver scelto un id differente)
    const visibleId = await page.evaluate((title) => {
        const lis = Array.from(document.querySelectorAll(
            "#fm-sp-verif li[data-fm-content-kind='verifica'], " +
            ".fm-sb-panel[data-sidepage=verif] li[data-fm-content-kind='verifica']"));
        const match = lis.find(li => li.querySelector(".fm-vd-link")?.textContent?.trim() === title);
        return match?.dataset.contentId || null;
    }, tname);
    expect(visibleId, `li con title=${tname} deve essere visibile`).toBeTruthy();
    console.log(`Visible li id=${visibleId}`);

    // Click 🗑 sulla nostra verifica
    const clicked = await page.evaluate((id) => {
        const li = document.querySelector(`li[data-content-id="${id}"][data-fm-content-kind="verifica"]`);
        const btn = li?.querySelector(".fm-item-del");
        if (!btn) return "NO_BTN";
        btn.click();
        return "OK";
    }, visibleId);
    expect(clicked, ".fm-item-del btn presente sul li").toBe("OK");
    // Conferma FM.Dialog: click su `.fm-dialog-btn[data-action="ok"]`.
    await page.waitForTimeout(400);
    const okBtn = page.locator('#fm-dialog-modal .fm-dialog-btn[data-action="ok"]');
    if (await okBtn.count() > 0) await okBtn.click();
    await page.waitForTimeout(2500);

    // GET /api/verifica/list non deve piu' avere la verifica con quel title
    const list = await page.request.get("/api/verifica/list").then(r => r.json());
    const stillThere = list.items.find(it => String(it.id) === visibleId);
    expect(stillThere, `id=${visibleId} ancora presente nel DB!`).toBeFalsy();
});
