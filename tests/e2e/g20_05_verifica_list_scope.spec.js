/**
 * G20.5 — E2E: /api/verifica/list filtra per indirizzo + classe.
 *
 * Bug pre-fix: la sidebar mostrava le verifiche di tutte le classi
 * mischiate (es. 1a vs 2a scientifico). Dopo G20.5 il backend accetta
 * `?indirizzo=...&classe=...` e il client `verifica-documents-sidepage.js`
 * li passa leggendo #sel-iis / #sel-cls.
 */
const { test, expect } = require("@playwright/test");

const USERNAME = process.env.FM_E2E_USER || "superadmin";
const PASSWORD = process.env.FM_E2E_PASS || (process.env.E2E_TEACHER_PASS || "");

test("/api/verifica/list rispetta indirizzo+classe", async ({ page }) => {
    test.setTimeout(60000);
    await page.goto("/login");
    await page.locator("input[name=username]").fill(USERNAME);
    await page.locator("input[name=password]").fill(PASSWORD);
    await page.locator("button[type=submit]").first().click();
    await page.waitForLoadState("networkidle");

    // Baseline: tutte le verifiche del docente.
    const all = await page.request.get("/api/verifica/list").then(r => r.json());
    expect(all.ok).toBe(true);
    const totalCount = all.items.length;
    console.log(`Totale verifiche docente (no filter): ${totalCount}`);

    // Con filtro classe=2 + indirizzo=sc → subset.
    const sc2 = await page.request.get("/api/verifica/list?indirizzo=sc&classe=2")
        .then(r => r.json());
    expect(sc2.ok).toBe(true);
    for (const it of sc2.items) {
        expect(it.indirizzo).toBe("sc");
        expect(String(it.classe)).toBe("2");
    }
    console.log(`sc/2: ${sc2.items.length} verifiche`);

    // Con classe=3 → diverso subset.
    const sc3 = await page.request.get("/api/verifica/list?indirizzo=sc&classe=3")
        .then(r => r.json());
    expect(sc3.ok).toBe(true);
    for (const it of sc3.items) {
        expect(it.indirizzo).toBe("sc");
        expect(String(it.classe)).toBe("3");
    }
    console.log(`sc/3: ${sc3.items.length} verifiche`);

    // Gli id di sc/2 e sc/3 non si mischiano.
    const ids2 = new Set(sc2.items.map(i => i.id));
    const ids3 = new Set(sc3.items.map(i => i.id));
    for (const id of ids2) {
        expect(ids3.has(id)).toBe(false);
    }

    // Con indirizzo errato → 0 (o subset legittimo).
    const fake = await page.request.get("/api/verifica/list?indirizzo=zz&classe=99")
        .then(r => r.json());
    expect(fake.ok).toBe(true);
    expect(fake.items.length).toBe(0);
});
