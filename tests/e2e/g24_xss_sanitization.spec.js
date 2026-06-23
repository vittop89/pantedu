/**
 * G24.phase4-5 — E2E test client XSS sanitization (defense-in-depth).
 *
 * Verifica che il modulo `js/modules/security/html-sanitize-client.js`
 * applichi la stessa policy del server (HtmlSanitizer) prima del save.
 */
const { test, expect } = require("@playwright/test");

test("G24 — client sanitizeBlockContent strippa XSS vector", async ({ page }) => {
    await page.goto("/", { waitUntil: "domcontentloaded" });
    // Attendi che bootstrap carichi e import del modulo sia disponibile
    const result = await page.evaluate(async () => {
        const mod = await import("/js/modules/security/html-sanitize-client.js");
        const cases = [
            // payload, mustNotContain[], mustContain[]
            ['<script>alert(1)</script>x', ['<script', 'alert(1)'], ['x']],
            ['<a href="javascript:alert(1)">click</a>', ['javascript:'], ['click']],
            ['<span onclick="alert(1)">x</span>', ['onclick', 'alert'], ['x']],
            ['<iframe src="evil"></iframe>x', ['<iframe'], ['x']],
            ['<b>safe</b>', [], ['<b>safe</b>']],
            ['<a href="https://wiki.org/x">link</a>', [], ['href="https://wiki.org/x"', 'link']],
            ['<span style="color:red">x</span>', [], ['x']],
            ['<span style="background:url(javascript:alert(1))">x</span>', ['javascript:'], ['x']],
        ];
        const results = [];
        for (const [input, mustNot, must] of cases) {
            const out = mod.sanitizeBlockContent(input);
            results.push({
                input, out,
                mustNotOk: mustNot.every(s => !out.includes(s)),
                mustOk:    must.every(s => out.includes(s)),
            });
        }
        return results;
    });
    for (const r of result) {
        expect(r.mustNotOk, `Strip failed: input=${r.input} out=${r.out}`).toBe(true);
        expect(r.mustOk, `Preserve failed: input=${r.input} out=${r.out}`).toBe(true);
    }
});

test("G24 — sanitizeStrictText strippa TUTTO il markup", async ({ page }) => {
    await page.goto("/", { waitUntil: "domcontentloaded" });
    const out = await page.evaluate(async () => {
        const mod = await import("/js/modules/security/html-sanitize-client.js");
        return mod.sanitizeStrictText('<b>bold</b> <script>alert(1)</script> plain');
    });
    expect(out).not.toContain('<b>');
    expect(out).not.toContain('<script');
    expect(out).not.toContain('alert(1)');
    expect(out).toContain('bold');
    expect(out).toContain('plain');
});

test("G24 — _buildBlocksFromTextarea pre-sanitize text content con HTML", async ({ page }) => {
    await page.goto("/", { waitUntil: "domcontentloaded" });
    await page.waitForFunction(() => typeof window.FM?.__buildBlocksFromTextareaForTest === "function", { timeout: 10000 });

    const result = await page.evaluate(() => {
        const ta = document.createElement("div");
        ta.contentEditable = "true";
        Object.defineProperty(ta, "value", {
            get() { return ta.innerHTML; },
            set(v) { ta.innerHTML = v; },
        });
        ta.value = '<b>safe</b> <a href="javascript:alert(1)">click</a> <span onclick="alert(2)">x</span>';
        document.body.appendChild(ta);
        const blocks = window.FM.__buildBlocksFromTextareaForTest(ta);
        ta.remove();
        return JSON.stringify(blocks);
    });
    // Niente javascript: né onclick nei block content (sanitize client applicato)
    expect(result).not.toContain('javascript:');
    expect(result).not.toContain('onclick');
    // Content preservato
    expect(result).toContain('safe');
    expect(result).toContain('click');
    expect(result).toContain('x');
});
