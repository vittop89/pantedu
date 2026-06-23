// =============================================================================
// ADR-026 #3 (2026-05-28) — TUTTI I test(...) IN QUESTO FILE SONO test.skip().
// Motivo: usano querySelector("fm-risdoc-template") + shadowRoot del motore
// legacy ELIMINATO. Da migrare a fm-pt-document (light DOM) o eliminare.
// =============================================================================
/**
 * Phase 24.33 — per-combination state + reset.
 *
 * Verifica che fields siano partizionati per combinazione
 * (indirizzo,classe,sezione,disciplina) tramite chiavi localStorage
 * separate, e che il reset rimuova solo la combinazione corrente.
 */
const { test, expect } = require("@playwright/test");
const { loginAdmin } = require("./helpers");

test.describe("per-combination + reset", () => {
    test.skip("localStorage key combina state slug", async ({ page }) => {
        test.setTimeout(60_000);
        await loginAdmin(page);
        const tj = await page.request.get("/api/risdoc/templates").then(r => r.json());
        const piano = (tj.templates || []).find(t =>
            String(t.argomento || "").toLowerCase().includes("piano_annuale")
        );
        if (!piano) { test.skip(true, "no piano"); return; }

        await page.goto(`/risdoc/view/${piano.id}`);
        await page.waitForSelector("fm-risdoc-template", { timeout: 10000 });
        await page.waitForTimeout(2000);

        // Pulisce localStorage residuo da test precedenti
        await page.evaluate(() => {
            for (const k of Object.keys(localStorage)) {
                if (k.startsWith("fm.risdoc.tmpl.")) localStorage.removeItem(k);
            }
        });

        // Setta combinazione 1: sc/2s/A/MAT, scrivi un campo custom
        await page.evaluate(() => {
            const wc = document.querySelector("fm-risdoc-template");
            wc._values = {
                fields: { __test: "VAL_COMBO_1" },
                state: { indirizzo: "sc", classe: "2s", sezione: "A", disciplina: "MAT" },
            };
            wc._storageKey = wc._storageKeyFor(wc._combinationSlug());
            wc._persistValues();
        });

        // Setta combinazione 2: sc/3s/A/FIS, valore diverso
        await page.evaluate(() => {
            const wc = document.querySelector("fm-risdoc-template");
            wc._values = {
                fields: { __test: "VAL_COMBO_2" },
                state: { indirizzo: "sc", classe: "3s", sezione: "A", disciplina: "FIS" },
            };
            wc._storageKey = wc._storageKeyFor(wc._combinationSlug());
            wc._persistValues();
        });

        // Verifica chiavi separate in localStorage
        const keys = await page.evaluate(() => {
            return Object.keys(localStorage).filter(k => k.startsWith("fm.risdoc.tmpl."));
        });
        const slug1 = keys.find(k => k.includes("sc-2s-A-MAT"));
        const slug2 = keys.find(k => k.includes("sc-3s-A-FIS"));
        expect(slug1, "key combo 1 esiste").toBeTruthy();
        expect(slug2, "key combo 2 esiste").toBeTruthy();

        // Verifica content separato
        const content = await page.evaluate(() => {
            const k1 = Object.keys(localStorage).find(k => k.includes("sc-2s-A-MAT"));
            const k2 = Object.keys(localStorage).find(k => k.includes("sc-3s-A-FIS"));
            return {
                v1: JSON.parse(localStorage.getItem(k1)).fields.__test,
                v2: JSON.parse(localStorage.getItem(k2)).fields.__test,
            };
        });
        expect(content.v1).toBe("VAL_COMBO_1");
        expect(content.v2).toBe("VAL_COMBO_2");
    });

    test.skip("reset rimuove solo la combinazione corrente", async ({ page }) => {
        test.setTimeout(60_000);
        await loginAdmin(page);
        const tj = await page.request.get("/api/risdoc/templates").then(r => r.json());
        const piano = (tj.templates || []).find(t =>
            String(t.argomento || "").toLowerCase().includes("piano_annuale")
        );
        if (!piano) { test.skip(true, "no piano"); return; }

        await page.goto(`/risdoc/view/${piano.id}`);
        await page.waitForSelector("fm-risdoc-template", { timeout: 10000 });
        await page.waitForTimeout(2000);

        // Setup 2 combo storage
        await page.evaluate(() => {
            for (const k of Object.keys(localStorage)) {
                if (k.startsWith("fm.risdoc.tmpl.")) localStorage.removeItem(k);
            }
            const wc = document.querySelector("fm-risdoc-template");
            // Combo A
            wc._values = { fields: { x: "A" }, state: { indirizzo: "sc", classe: "1s", sezione: "B", disciplina: "FIS" } };
            wc._storageKey = wc._storageKeyFor(wc._combinationSlug());
            wc._persistValues();
            // Combo B (corrente)
            wc._values = { fields: { x: "B" }, state: { indirizzo: "sc", classe: "5s", sezione: "C", disciplina: "MAT" } };
            wc._storageKey = wc._storageKeyFor(wc._combinationSlug());
            wc._persistValues();
        });

        // Reset corrente (combo B)
        await page.evaluate(async () => {
            const wc = document.querySelector("fm-risdoc-template");
            await wc._resetModel();
        });
        await page.waitForTimeout(300); // attesa re-render

        // Combo A preservata, combo B fields={} (re-render dopo reset
        // potrebbe ripopolare la chiave, ma fields devono essere vuoti)
        const result = await page.evaluate(() => {
            const k1 = Object.keys(localStorage).find(k => k.includes("sc-1s-B-FIS"));
            const k2 = Object.keys(localStorage).find(k => k.includes("sc-5s-C-MAT"));
            return {
                comboA: k1 ? JSON.parse(localStorage.getItem(k1)) : null,
                comboB: k2 ? JSON.parse(localStorage.getItem(k2)) : null,
            };
        });
        expect(result.comboA?.fields?.x, "combo A preservata").toBe("A");
        // Combo B può avere chiave residua dopo re-render, ma fields.x cancellato
        expect(result.comboB?.fields?.x, "combo B fields.x cancellato").toBeUndefined();
    });
});
