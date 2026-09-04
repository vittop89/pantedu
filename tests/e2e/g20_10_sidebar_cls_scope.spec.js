const { test, expect } = require("@playwright/test");

// Fix: il selettore Classe della sidebar deve mostrare SOLO le classi
// dell'istituto attivo, non di tutti gli istituti collegati del docente
// (app.php: all(activeIid, userId) invece di all(null, userId)).
test("Sidebar: selettore Classe scopato all'istituto attivo", async ({ page }) => {
    test.setTimeout(60000);
    const logs = [];
    page.on("console", m => logs.push(`[${m.type()}] ${m.text()}`));

    await page.goto("/login");
    await page.locator("input[name=username]").fill(process.env.E2E_TEACHER_USER || "superadmin");
    await page.locator("input[name=password]").fill(process.env.E2E_TEACHER_PASS || "");
    await page.locator("button[type=submit]").first().click();
    await page.waitForLoadState("networkidle");

    await page.goto("/"); // home: sidebar completa (sel-istituto + sel-cls)
    await page.waitForLoadState("networkidle");

    // Sidebar può essere collassata: il select è nel DOM ma nascosto.
    const istSel = page.locator("#sel-istituto");
    await expect(istSel).toBeAttached();

    // Per ogni istituto: seleziona, attendi switch server, ricarica, conta le classi.
    const options = await istSel.locator("option").evaluateAll(opts =>
        opts.map(o => ({ value: o.value, iid: o.dataset.iid, label: o.textContent.trim() })));
    console.log("Istituti:", JSON.stringify(options));
    expect(options.length).toBeGreaterThan(1); // serve multi-istituto per il test

    const counts = {};
    for (const opt of options) {
        // switch via evaluate (select nascosto) → dispatch change → POST /api/tenant/switch
        await page.evaluate((val) => {
            const s = document.getElementById("sel-istituto");
            s.value = val;
            s.dispatchEvent(new Event("change", { bubbles: true }));
        }, opt.value);
        await page.waitForTimeout(800);
        await page.reload();
        await page.waitForLoadState("networkidle");
        const clsCount = await page.locator('#sel-cls option[value]:not([disabled])').count();
        counts[opt.label] = clsCount;
        console.log(`Istituto "${opt.label}" → classi nel selettore: ${clsCount}`);
        // Scoping per-istituto: ogni istituto mostra SOLO le sue classi (max 5
        // per il docente 77), mai la somma cross-istituto. 0 è legittimo per un
        // istituto senza classi del docente.
        expect(clsCount).toBeLessThanOrEqual(5);
    }

    // Regressione anti cross-istituto: la somma dei distinti supera ogni singolo
    // conteggio → nessun istituto mostra il totale aggregato.
    const total = Object.values(counts).reduce((a, b) => a + b, 0);
    const max = Math.max(...Object.values(counts));
    console.log("Conteggi per istituto:", JSON.stringify(counts), "somma=", total, "max=", max);
    expect(max).toBeLessThan(total); // se fossero sommati, max === total

    if (logs.length) console.log("=== CONSOLE ===\n" + logs.join("\n"));
});
