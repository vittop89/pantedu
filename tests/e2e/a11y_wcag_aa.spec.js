/**
 * WCAG 2.1 AA accessibility tests via axe-core/playwright.
 *
 * Phase C.4 — integration test for AgID compliance roadmap.
 *
 * Strategy:
 *   - Esegue axe-core sulle pagine pubbliche principali (no auth)
 *   - Asserta zero violazioni di livello serious/critical sulle WCAG AA
 *   - Le pagine auth-protected sono coperte da spec separate (TODO)
 *
 * NB: i risultati axe rilevano molti issue legacy gia' nei tag WCAG
 * 2.1 AA. Phase C.1-C.3 hanno ridotto i critical: questo test guard
 * impedisce REGRESSIONI future. Le issue esistenti documentate in
 * docs/legal/accessibility.md sono tag-bypassate via `disableRules`.
 *
 * Setup:
 *   npm install --save-dev @axe-core/playwright
 *
 * Run:
 *   FM_E2E_BASE_URL=http://localhost:8080 npx playwright test a11y_wcag_aa
 */

const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;

const PUBLIC_PAGES = [
    { path: "/",                       label: "Home / landing" },
    { path: "/login",                  label: "Login form" },
    { path: "/register",               label: "Register form" },
    { path: "/legal/tos",              label: "Termini di Servizio" },
    { path: "/legal/aup",              label: "Acceptable Use Policy" },
    { path: "/privacy/informativa",    label: "Informativa privacy" },
    { path: "/accessibility",          label: "Dichiarazione accessibilita'" },
    { path: "/security",               label: "Sicurezza tecnica" },
    { path: "/dpo-contact",            label: "Contatto DPO" },
    { path: "/segnalazione-contenuti", label: "Segnalazione contenuti" },
    { path: "/tos-acceptance",         label: "Accettazione ToS" },
    { path: "/.well-known/security.txt", label: "security.txt", skipAxe: true },
];

// Regole legacy note (vedi docs/legal/accessibility.md sezione "Contenuti
// non accessibili"). Le includiamo per non far fallire CI mentre vengono
// affrontate in Fase D. Quando riusciamo a fixarle, rimuoviamo da qui.
const KNOWN_LEGACY_DISABLED_RULES = [
    // "color-contrast" — alcuni elementi UI hanno contrast borderline
    // sotto verifica con palette token Phase C.3. Riattivare dopo audit
    // contrast manuale completato.
    // "color-contrast",
];

for (const page of PUBLIC_PAGES) {
    test(`a11y: ${page.label} (${page.path})`, async ({ page: pwPage }) => {
        const response = await pwPage.goto(page.path);
        expect(response, `failed loading ${page.path}`).toBeTruthy();
        // Allow 200, 302 (redirect-to-login is OK for some legacy paths).
        expect(response.status()).toBeLessThan(400);

        if (page.skipAxe) return;

        // Attendi il settle delle animazioni (es. fade-in del modale cookie):
        // axe su uno stato mid-animazione calcola colori "blended" e segnala
        // contrasti che a riposo sono conformi. Scansioniamo lo stato reale.
        await pwPage.waitForLoadState("networkidle").catch(() => {});
        await pwPage.waitForTimeout(900);

        let builder = new AxeBuilder({ page: pwPage })
            .withTags([
                "wcag2a", "wcag2aa", "wcag21a", "wcag21aa",
                "wcag22a", "wcag22aa",   // WCAG 2.2 — target-size, focus-not-obscured, ecc.
                "best-practice",
            ]);

        if (KNOWN_LEGACY_DISABLED_RULES.length > 0) {
            builder = builder.disableRules(KNOWN_LEGACY_DISABLED_RULES);
        }

        const results = await builder.analyze();

        const critical = results.violations.filter(v =>
            v.impact === "critical" || v.impact === "serious"
        );

        if (critical.length > 0) {
            const summary = critical.map(v => ({
                id: v.id,
                impact: v.impact,
                description: v.description,
                helpUrl: v.helpUrl,
                nodes: v.nodes.length,
                sample: v.nodes[0]?.html?.slice(0, 200),
            }));
            console.error(`Critical/serious a11y violations on ${page.path}:`,
                JSON.stringify(summary, null, 2));
        }

        expect(critical, `Critical/serious a11y violations on ${page.path}`)
            .toHaveLength(0);
    });
}

test("a11y: skip link is keyboard-accessible", async ({ page }) => {
    await page.goto("/");
    // Tab into the skip link
    await page.keyboard.press("Tab");
    const focused = await page.evaluate(() => {
        const el = document.activeElement;
        return el ? {
            tag: el.tagName.toLowerCase(),
            href: el.getAttribute("href"),
            text: el.textContent?.trim().slice(0, 60),
            klass: el.className,
        } : null;
    });
    expect(focused).not.toBeNull();
    expect(focused.tag).toBe("a");
    expect(focused.href).toMatch(/#fm-content/);
    expect(focused.text).toMatch(/salta al contenuto/i);
});

test("a11y: dark mode toggle has aria-pressed", async ({ page }) => {
    await page.goto("/");
    const btn = page.locator(".fm-sb-dark, .fm-darkmode-mini").first();
    await expect(btn).toBeVisible({ timeout: 3000 });
    await expect(btn).toHaveAttribute("aria-pressed", /true|false/);
    await expect(btn).toHaveAttribute("aria-label", /modalit.*scura/i);
});
