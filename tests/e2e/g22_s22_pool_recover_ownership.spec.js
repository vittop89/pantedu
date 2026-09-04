/**
 * G22.S22 — Catalog ownership full + pool recover isolation.
 *
 * Scenario:
 *  1. Marco (teacher) apre dashboard pool, vede l'esercizio "Sistemi lineari"
 *     di Operatore (super-admin teacher) condiviso nel pool.
 *  2. Marco lo recupera nella sua materia MAT, indirizzo SCI, classe 2.
 *  3. Marco apre l'esercizio recuperato in sidepage → vede il contenuto
 *     (problemi, tikz, latex) — contract file clonato + scope aggiornato.
 *  4. Operatore fa login. Apre sidepage Esercizi MAT/SCI/2. NON deve vedere
 *     il clone di Marco (ACL post-G22.S22: super-admin teacher = teacher).
 *  5. Operatore apre "Sistemi lineari" originale → vede il contenuto
 *     (contract_key fissato).
 */
import { test, expect } from "@playwright/test";

const VITTORIO_USER = process.env.PLAYWRIGHT_TEST_USERNAME || "superadmin";
const VITTORIO_PASS = process.env.PLAYWRIGHT_TEST_PASSWORD || "";
const MARCO_USER    = "marco.rossi";
const MARCO_PASS    = (process.env.E2E_TEACHER_PASS || "");

async function dismissCookies(page) {
    await page.addInitScript(() => {
        localStorage.setItem("cookieConsent", JSON.stringify({
            necessary: true, functional: true, analytics: false, marketing: false,
            date: new Date().toISOString(),
        }));
    });
}

async function dismissCookieModal(page) {
    // Modal fm-cookie-modal: chiudi cliccando "Accetta tutti" se presente.
    const modal = page.locator('#fm-cookie-modal');
    if (await modal.count() > 0 && await modal.isVisible().catch(() => false)) {
        const acceptBtn = modal.locator('button:has-text("Accetta")').first();
        if (await acceptBtn.count() > 0) {
            await acceptBtn.click({ force: true }).catch(() => {});
        } else {
            await page.evaluate(() => document.getElementById('fm-cookie-modal')?.remove());
        }
    }
}

async function login(page, username, password) {
    await page.goto("/login");
    await page.fill('input[name="username"]', username);
    await page.fill('input[name="password"]', password);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/, { timeout: 10000 }),
        page.click('button[type="submit"]'),
    ]);
}

test.describe("G22.S22 pool recover ownership isolation", () => {

    test("Marco vede 'Sistemi lineari' di Operatore nel pool (API)", async ({ page }) => {
        if (!VITTORIO_PASS) test.skip(true, "PLAYWRIGHT_TEST_PASSWORD non set");
        await dismissCookies(page);
        await login(page, MARCO_USER, MARCO_PASS);

        // Backend pool endpoint (più affidabile della UI dashboard)
        const res = await page.request.get("/api/teacher/pool/materials?content_type=esercizio");
        expect(res.ok()).toBeTruthy();
        const j = await res.json();
        const items = j.items || [];

        // Deve includere "Sistemi lineari" di Operatore (teacher_id=77)
        const sistemi = items.find(i =>
            /Sistemi lineari/.test(i.title) && i.owner_id === 77 && !/importata/.test(i.title)
        );
        expect(sistemi, `pool items: ${items.map(i => `#${i.id} ${i.title}`).join("; ")}`).toBeTruthy();
        expect(sistemi.subject_code).toBe("MAT");
    });

    test("Operatore NON vede clone di Marco in sidepage Esercizi MAT/SCI/2", async ({ page }) => {
        if (!VITTORIO_PASS) test.skip(true, "PLAYWRIGHT_TEST_PASSWORD non set");
        await dismissCookies(page);
        await login(page, VITTORIO_USER, VITTORIO_PASS);

        // page.request usa i cookie del browser (sessione condivisa)
        const res = await page.request.get("/api/study/content.json?type=esercizio&subject=MAT&indirizzo=SCI&classe=2");
        expect(res.ok()).toBeTruthy();
        const j = await res.json();
        const rows = j.rows || [];

        // Tutte le righe devono avere teacher_id=Operatore (77) — niente cross-teacher
        // post-G22.S22 (super-admin che è anche teacher = teacher per ACL).
        const allVittorio = rows.every(r => r.teacher_id === 77);
        expect(allVittorio, `rows non-docente1: ${rows.filter(r => r.teacher_id !== 77).map(r => r.id).join(",")}`).toBeTruthy();

        // Almeno una riga deve essere "Sistemi lineari" (originale Operatore #58)
        const sistemi = rows.find(r => /Sistemi lineari/.test(r.title) && !/importata/.test(r.title));
        expect(sistemi).toBeTruthy();
    });

    test("Operatore: contract 'Sistemi lineari' caricabile (contract_key set)", async ({ page }) => {
        if (!VITTORIO_PASS) test.skip(true, "PLAYWRIGHT_TEST_PASSWORD non set");
        await dismissCookies(page);
        await login(page, VITTORIO_USER, VITTORIO_PASS);

        // Trova id Sistemi lineari via lista
        const list = await page.request.get("/api/study/content.json?type=esercizio&subject=MAT&indirizzo=SCI&classe=2");
        const j = await list.json();
        const sistemi = (j.rows || []).find(r => /^Sistemi lineari$/.test(r.title));
        expect(sistemi, "Operatore Sistemi lineari must exist").toBeTruthy();

        // Fetch detail via /api/teacher/content/{id} (include metadata completo)
        const detail = await page.request.get(`/api/teacher/content/${sistemi.id}`);
        expect(detail.ok()).toBeTruthy();
        const d = await detail.json();
        const meta = typeof d.content.metadata_json === "string"
            ? JSON.parse(d.content.metadata_json)
            : (d.content.metadata_json || d.content.metadata || {});
        expect(meta.contract_key, "contract_key set").toBeTruthy();
        expect(meta.contract_key).toContain("eser/");
        expect(meta.contract_key).toContain(".contract.json");
    });
});
