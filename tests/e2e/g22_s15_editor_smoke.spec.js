/**
 * G22.S15 — smoke test che bootstrap.js carica senza errori critici
 * dopo le modifiche a content-processor.js (import tikz-render-client).
 *
 * Senza login: un import broken in content-processor → bootstrap fail
 * → window.FM non si popola anche su /.
 */
const { test, expect } = require("@playwright/test");

const BASE_URL = process.env.FM_E2E_BASE_URL || "http://localhost";

test("bootstrap importa content-processor.js senza errori", async ({ page }) => {
    const errors = [];
    const warnings = [];
    page.on("pageerror", (e) => errors.push("[pageerror] " + e.message));
    page.on("console", (msg) => {
        const text = msg.text();
        if (msg.type() === "error" && !/favicon|MIME|gas-client/i.test(text)) {
            errors.push("[console.error] " + text);
        }
        if (msg.type() === "warning") warnings.push(text);
    });

    await page.goto(BASE_URL + "/");

    // Aspetta che FM si popoli (proves bootstrap si è completato)
    const ok = await page.waitForFunction(() => {
        return window.FM && window.FM.Api && window.FM.LatexRender;
    }, { timeout: 10000 }).then(() => true).catch(() => false);

    console.log("FM populated:", ok);
    if (!ok) {
        console.log("Errors trapped:", errors);
        console.log("Page state:", await page.evaluate(() => ({
            FM_keys: window.FM ? Object.keys(window.FM) : "FM undefined",
            href: location.href,
        })));
    }
    expect(errors).toEqual([]);
    expect(ok).toBe(true);

    // Verifica content-processor caricato
    const cp = await page.evaluate(async () => {
        try {
            const m = await import("/js/modules/editor/content-processor.js");
            return { ok: true, hasContentProcessor: typeof m.ContentProcessor === "object" };
        } catch (e) {
            return { ok: false, error: e.message };
        }
    });
    console.log("ContentProcessor import:", cp);
    expect(cp.ok).toBe(true);
    expect(cp.hasContentProcessor).toBe(true);
});
