// =============================================================================
// ADR-026 #3 (2026-05-28) — TUTTI I test(...) IN QUESTO FILE SONO test.skip().
// Motivo: usano querySelector("fm-risdoc-template") + shadowRoot del motore
// legacy ELIMINATO. Da migrare a fm-pt-document (light DOM) o eliminare.
// =============================================================================
/**
 * G22.S26 — Verifica che le checkbox spuntate da Marco compaiano
 * effettivamente nell'anteprima admin del pending.
 *
 * Test 1: anteprima admin riflette state del pending content
 *   - Setup: ultimo pending in DB ha state=x per "adeguato"
 *   - Login Vittorio, apri anteprima del pending, verifica DOM
 *
 * Test 2: Marco reload preserva spunte in admin_edit (localStorage)
 *   - Login Marco, apri /risdoc/view/16?admin_edit=1
 *   - Verifica che lo state caricato matchi quello del pending
 */
import { test, expect } from "@playwright/test";
import fs from "fs";
import path from "path";

const VITTORIO_USER = process.env.PLAYWRIGHT_TEST_USERNAME || "superadmin";
const VITTORIO_PASS = process.env.PLAYWRIGHT_TEST_PASSWORD || "";
const MARCO_USER = "marco.rossi";
const MARCO_PASS = (process.env.E2E_TEACHER_PASS || "");

const SCREEN_DIR = "tests/e2e-results/g22_s26_checkbox";

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

function ensureDir() { fs.mkdirSync(SCREEN_DIR, { recursive: true }); }

test.skip("Anteprima admin: checkbox 'adeguato' rispetta state pending", async ({ page }) => {
    if (!VITTORIO_PASS) test.skip(true, "PLAYWRIGHT_TEST_PASSWORD non set");
    ensureDir();

    await login(page, VITTORIO_USER, VITTORIO_PASS);

    // 1. Recupera ultimo pending e leggi state delle 3 checkbox corretto/adeguato/poco
    const r = await page.request.get("/api/admin/risdoc/pending?status=pending");
    const j = await r.json();
    expect(j.count_pending).toBeGreaterThan(0);
    const first = j.pending[0];
    const pid = first.id;
    console.log(`→ Testing pending #${pid} kind=${first.kind}`);

    const schemaRes = await page.request.get(`/api/admin/risdoc/pending/${pid}/schema`);
    expect(schemaRes.ok()).toBeTruthy();
    const schema = await schemaRes.json();

    // Extract section[1] default checkbox group with "corretto/adeguato/poco corretto non"
    const tripletGroup = schema.sections?.[1]?.default?.find(b =>
        b._type === "checkboxGroup"
        && b.items?.some(it => /poco corretto/i.test(it.label || "")),
    );
    expect(tripletGroup, "checkboxGroup triplet in pending schema").toBeTruthy();
    const expectedStates = Object.fromEntries(
        tripletGroup.items.map(it => [it.label, it.state]),
    );
    console.log(`→ Expected from pending: ${JSON.stringify(expectedStates)}`);

    // 2. Apri la pagina di anteprima direttamente (iframe-friendly)
    await page.goto(`/admin/risdoc/pending/${pid}/preview`);
    await page.waitForSelector("fm-risdoc-template");

    // Attendi che il WC monti e renderizzi (Lit + sub-components)
    await page.waitForFunction(() => {
        const wc = document.querySelector("fm-risdoc-template");
        if (!wc?.shadowRoot) return false;
        return wc.shadowRoot.innerHTML.includes("fm-risdoc-admin-layout")
            && !wc.shadowRoot.innerHTML.includes('class="error"');
    }, { timeout: 10000 });

    // Attendi PT editor render (in shadow root deep)
    await page.waitForTimeout(4000);

    // 3. Estrai stati delle checkbox renderizzate (via deep shadow query)
    const actualStates = await page.evaluate(() => {
        // Walk shadow DOM ricorsivo per trovare le checkbox dei 3 label.
        const targetLabels = ["corretto", "adeguato", "poco corretto non"];
        const found = {};
        const walk = (root) => {
            if (!root) return;
            // pt-editor renderizza item come .pt-checkbox-item con <input> + <textarea>
            const items = root.querySelectorAll?.(".pt-checkbox-item") || [];
            for (const it of items) {
                const cb = it.querySelector("input[type=checkbox]");
                const lblEl = it.querySelector(".pt-checkbox-label-input");
                const label = (lblEl?.value || "").trim();
                if (targetLabels.includes(label) && !(label in found)) {
                    found[label] = cb?.checked === true;
                }
            }
            // Recurse children + shadowRoots
            const children = root.querySelectorAll?.("*") || [];
            for (const c of children) {
                if (c.shadowRoot) walk(c.shadowRoot);
            }
        };
        walk(document);
        return found;
    });
    console.log(`→ Actual rendered states: ${JSON.stringify(actualStates)}`);

    await page.screenshot({
        path: path.join(SCREEN_DIR, "01_anteprima_checkboxes.png"),
        fullPage: false,
    });

    // 4. Verifica che ogni label trovata abbia state matching expected
    for (const [label, expState] of Object.entries(expectedStates)) {
        const expected = expState === "x";
        const actual = actualStates[label];
        if (actual === undefined) {
            console.warn(`→ Label "${label}" non trovata nel DOM renderizzato`);
            continue;
        }
        expect(actual, `${label}: expected ${expected}, got ${actual}`).toBe(expected);
    }
    console.log("✅ Anteprima rispecchia state pending");
});

test.skip("Marco admin_edit reload: localStorage preserva spunte", async ({ page }) => {
    if (!VITTORIO_PASS) test.skip(true, "PLAYWRIGHT_TEST_PASSWORD non set");
    ensureDir();

    await login(page, MARCO_USER, MARCO_PASS);
    await page.goto("/risdoc/view/16?admin_edit=1");
    await page.waitForSelector("fm-risdoc-template");
    await page.waitForFunction(() => {
        const wc = document.querySelector("fm-risdoc-template");
        return wc?.shadowRoot?.innerHTML.includes("fm-risdoc-admin-layout");
    }, { timeout: 10000 });
    await page.waitForTimeout(3000);

    // Captura state iniziale checkbox
    const initial = await page.evaluate(() => {
        const found = {};
        const walk = (root) => {
            if (!root) return;
            const items = root.querySelectorAll?.(".pt-checkbox-item") || [];
            for (const it of items) {
                const cb = it.querySelector("input[type=checkbox]");
                const lbl = (it.querySelector(".pt-checkbox-label-input")?.value || "").trim();
                if (["corretto", "adeguato", "poco corretto non"].includes(lbl)
                    && !(lbl in found)) {
                    found[lbl] = cb?.checked === true;
                }
            }
            const children = root.querySelectorAll?.("*") || [];
            for (const c of children) if (c.shadowRoot) walk(c.shadowRoot);
        };
        walk(document);
        return found;
    });
    console.log(`→ Initial states (post-load): ${JSON.stringify(initial)}`);

    await page.screenshot({
        path: path.join(SCREEN_DIR, "02_marco_initial.png"),
        fullPage: false,
    });

    // Localstorage state dump per diagnostica
    const lsKeys = await page.evaluate(() => {
        const keys = [];
        for (let i = 0; i < localStorage.length; i++) {
            const k = localStorage.key(i);
            if (k?.startsWith("fm.risdoc.tmpl")) keys.push(k);
        }
        return keys;
    });
    console.log(`→ localStorage keys: ${JSON.stringify(lsKeys)}`);

    // Reload page
    await page.reload();
    await page.waitForSelector("fm-risdoc-template");
    await page.waitForFunction(() => {
        const wc = document.querySelector("fm-risdoc-template");
        return wc?.shadowRoot?.innerHTML.includes("fm-risdoc-admin-layout");
    }, { timeout: 10000 });
    await page.waitForTimeout(3000);

    const afterReload = await page.evaluate(() => {
        const found = {};
        const walk = (root) => {
            if (!root) return;
            const items = root.querySelectorAll?.(".pt-checkbox-item") || [];
            for (const it of items) {
                const cb = it.querySelector("input[type=checkbox]");
                const lbl = (it.querySelector(".pt-checkbox-label-input")?.value || "").trim();
                if (["corretto", "adeguato", "poco corretto non"].includes(lbl)
                    && !(lbl in found)) {
                    found[lbl] = cb?.checked === true;
                }
            }
            const children = root.querySelectorAll?.("*") || [];
            for (const c of children) if (c.shadowRoot) walk(c.shadowRoot);
        };
        walk(document);
        return found;
    });
    console.log(`→ States after reload: ${JSON.stringify(afterReload)}`);

    await page.screenshot({
        path: path.join(SCREEN_DIR, "03_marco_after_reload.png"),
        fullPage: false,
    });

    // Verifica: stessi state pre/post reload
    expect(afterReload).toEqual(initial);
    console.log("✅ Reload preserva state");
});
