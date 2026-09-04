/**
 * Validazione toolbar risdoc Plan B:
 *  - fm-rte-toggle nascosto (shadow DOM non supportato)
 *  - fm-risdoc-btn--primary visibile + link /risdoc/edit/{id}
 *  - Overleaf button: export API → overleaf_url
 *  - ZIP button: export API → url .zip valido
 */

const { test, expect } = require("@playwright/test");

test.setTimeout(90000);

test("toolbar buttons Plan B risdoc", async ({ browser }) => {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    await page.addInitScript(() => {
        localStorage.setItem("cookieConsent", JSON.stringify({
            necessary:true,functional:true,analytics:false,marketing:false,
            date: new Date().toISOString()
        }));
    });

    // login
    await page.goto("/login");
    await page.fill('input[name="username"]', "superadmin");
    await page.fill('input[name="password"]', (process.env.E2E_TEACHER_PASS || ""));
    await Promise.all([page.waitForURL(/^(?!.*\/login).*/), page.click('button[type="submit"]')]);
    await page.waitForTimeout(1000);

    // open template 16 (Piano annuale — ha .tex)
    await page.goto("/risdoc/view/16");
    await page.waitForTimeout(2500);

    // 1. fm-rte-toggle: nascosto via CSS
    const rteBtn = await page.locator(".fm-rte-toggle").first();
    // display:none is computed → offsetParent = null OR style display:none
    const rteVisible = await rteBtn.evaluate(b => b && getComputedStyle(b).display !== "none").catch(() => false);
    expect(rteVisible, "fm-rte-toggle deve essere nascosto (display:none)").toBeFalsy();

    // 2. ADR-024 — "Modifica struttura" (.fm-doc-topbar__btn--secondary) → admin_edit
    const editLink = page.locator("a.fm-doc-topbar__btn--secondary").first();
    await expect(editLink).toBeVisible();
    const href = await editLink.getAttribute("href");
    expect(href).toContain("admin_edit=1");

    // 3. Overleaf button presente
    const overleafBtn = page.locator('[data-action="overleaf"]');
    await expect(overleafBtn).toBeVisible();

    // 4. ZIP button presente
    const zipBtn = page.locator('[data-action="download-zip"]');
    await expect(zipBtn).toBeVisible();

    // 5. Click ZIP → POST export → risposta con URL
    const csrfRes = await page.request.get("/auth/csrf");
    const { token: csrf } = await csrfRes.json();
    const resZip = await page.request.post("/api/risdoc/templates/16/export", {
        form: {
            _csrf: csrf,
            mode: "zip",
            form_state: JSON.stringify({
                fields: { profilo_classe: "Classe eterogenea, buona partecipazione." },
                state:  { classe: "2s", sezione: "A", indirizzo: "sc", disciplina: "MAT" },
            }),
        },
    });
    const jZip = await resZip.json();
    expect(resZip.status(), "ZIP export HTTP").toBe(200);
    expect(jZip.ok).toBe(true);
    expect(jZip.mode).toBe("zip");
    expect(jZip.url).toMatch(/\/api\/risdoc\/exports\/doc-[a-f0-9]{16}\.zip$/);

    // 6. POST Overleaf mode → overleaf_url valido
    const resOv = await page.request.post("/api/risdoc/templates/16/export", {
        form: {
            _csrf: csrf,
            mode: "overleaf",
            form_state: JSON.stringify({ fields: {}, state: { classe: "2s", sezione: "A" } }),
        },
    });
    const jOv = await resOv.json();
    expect(resOv.status()).toBe(200);
    expect(jOv.ok).toBe(true);
    expect(jOv.overleaf_url).toMatch(/^https:\/\/www\.overleaf\.com\/docs\?snip_uri=/);

    // 7. Verifica ZIP è effettivamente scaricabile + contiene file TeX
    const zipDl = await page.request.get(jZip.url);
    expect(zipDl.status()).toBe(200);
    const ct = zipDl.headers()["content-type"];
    expect(ct).toContain("zip");
    const buf = await zipDl.body();
    expect(buf.length).toBeGreaterThan(200);  // non vuoto
    // file di risposta è binary ZIP — non possiamo facilmente unzippare ma checkiamo il magic
    expect(buf[0]).toBe(0x50); expect(buf[1]).toBe(0x4B);  // PK header

    console.log(`ZIP ok: ${jZip.url} (${buf.length}b)`);
    console.log(`Overleaf: ${jOv.overleaf_url}`);

    await page.close();
});
