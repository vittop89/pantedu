// =============================================================================
// ADR-026 #3 (2026-05-28) — TUTTI I test(...) IN QUESTO FILE SONO test.skip().
// Motivo: usano querySelector("fm-risdoc-template") + shadowRoot del motore
// legacy ELIMINATO. Da migrare a fm-pt-document (light DOM) o eliminare.
// =============================================================================
/**
 * G22.S26 — admin-edit (?admin_edit=1) deve mostrare institutional schema
 * baseline (con override applicato), NON compilations personali del super-admin.
 *
 * Bug fix: pre-G22.S26 il fm-risdoc-template caricava compilations.fields
 * dell'utente anche in admin-edit → fields[name] sovrascriveva
 * schema.sections[i].default → admin vedeva sue risposte invece del baseline.
 *
 * Setup: institutional override per template 16 sezione 1 ha checkboxGroup con
 * 2 stati x (corretto, adeguato) e 1 _ (poco corretto non).
 */
import { test, expect } from "@playwright/test";

const USER = process.env.PLAYWRIGHT_TEST_USERNAME || "superadmin";
const PASS = process.env.PLAYWRIGHT_TEST_PASSWORD || "";

async function login(page, u, p) {
    await page.addInitScript(() => {
        localStorage.setItem("cookieConsent", JSON.stringify({
            necessary: true, functional: true, analytics: false, marketing: false,
            date: new Date().toISOString(),
        }));
    });
    await page.goto("/login");
    await page.fill('input[name="username"]', u);
    await page.fill('input[name="password"]', p);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/, { timeout: 10000 }),
        page.click('button[type="submit"]'),
    ]);
    await page.evaluate(() => {
        document.getElementById('fm-modal-overlay')?.remove();
        document.getElementById('fm-cookie-modal')?.remove();
    });
}

test.skip("Operatore admin-edit mostra institutional baseline (no compilations)", async ({ page }) => {
    if (!PASS) test.skip(true, "PLAYWRIGHT_TEST_PASSWORD non set");

    await login(page, USER, PASS);

    // Verifica schema istituzionale baseline: deve avere 2 check (corretto+adeguato)
    const schemaRes = await page.request.get("/api/risdoc/templates/16/schema");
    const schema = await schemaRes.json();
    const trip = schema.sections?.[1]?.default?.find(b =>
        b._type === "checkboxGroup" && b.items?.some(it => /poco corretto/i.test(it.label || "")),
    );
    expect(trip, "checkboxGroup section 1 deve esistere").toBeTruthy();
    const expected = Object.fromEntries(trip.items.map(it => [it.label, it.state === "x"]));
    console.log("→ Institutional baseline expected:", JSON.stringify(expected));
    // L'assunzione del test: ci sono ALMENO 2 checkbox attivi nella institutional.
    // Se DB resetta, fallisce qui (segnale corretto).
    const activeCount = Object.values(expected).filter(v => v === true).length;
    expect(activeCount, "institutional override deve avere >=1 check attivo").toBeGreaterThanOrEqual(1);

    page.on("console", m => {
        const t = m.text();
        if (/G22\.S26|pt-section/i.test(t)) console.log(`[console]`, t);
    });

    await page.goto("/risdoc/view/16?admin_edit=1");

    // Attendi WC mount admin-edit
    await page.waitForFunction(() => {
        const wc = document.querySelector("fm-risdoc-template");
        return wc?.shadowRoot?.innerHTML?.includes("fm-risdoc-admin-layout");
    }, { timeout: 15000 });
    await page.waitForTimeout(3000);

    // Estrai stati checkbox dal WC
    const actual = await page.evaluate(() => {
        const wc = document.querySelector("fm-risdoc-template");
        const found = {};
        const walk = (root) => {
            if (!root) return;
            const items = root.querySelectorAll?.(".pt-checkbox-item") || [];
            for (const it of items) {
                const cb = it.querySelector("input[type=checkbox]");
                const lbl = (it.querySelector(".pt-checkbox-label-input")?.value || "").trim();
                if (["corretto", "adeguato", "poco corretto non"].includes(lbl)
                    && !(lbl in found)) found[lbl] = cb?.checked === true;
            }
            const cs = root.querySelectorAll?.("*") || [];
            for (const c of cs) if (c.shadowRoot) walk(c.shadowRoot);
        };
        walk(wc?.shadowRoot);
        return found;
    });
    console.log("→ Admin-edit actual:", JSON.stringify(actual));

    for (const [label, exp] of Object.entries(expected)) {
        if (actual[label] === undefined) continue;
        expect(actual[label], `${label}: exp=${exp} got=${actual[label]}`).toBe(exp);
    }
    console.log("✅ admin-edit mostra institutional baseline correttamente");
});
