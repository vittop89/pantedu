/**
 * G22.S15 — smoke test che la modal CM6 apre, monta CM6, render preview,
 * salva, chiude.
 */
const { test, expect } = require("@playwright/test");

const BASE_URL = process.env.FM_E2E_BASE_URL || "http://localhost";

test("openTikzModal: open + CM6 + preview render + save", async ({ context, page }) => {
    // Mock /tikz/render → SVG fittizio (no auth, no VPS)
    await context.route("**/tikz/render*", async (route) => {
        if (route.request().method() === "GET") {
            return route.fulfill({ status: 404, contentType: "application/json", body: '{"ok":false}' });
        }
        return route.fulfill({
            status: 200,
            contentType: "image/svg+xml",
            body: '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="100" height="50"><rect x="5" y="5" width="80" height="30" fill="green"/><text x="10" y="25" fill="white">SMOKE</text></svg>',
        });
    });
    await context.route("**/auth/csrf", (route) =>
        route.fulfill({ status: 200, contentType: "application/json", body: '{"token":"test"}' }));

    const errors = [];
    page.on("pageerror", (e) => errors.push(e.message));
    page.on("console", (msg) => {
        if (msg.type() === "error" && !/favicon|MIME|gas-client/i.test(msg.text())) {
            errors.push("[console] " + msg.text());
        }
    });

    await page.goto(BASE_URL + "/");
    await page.waitForFunction(() => window.FM?.LatexRender, { timeout: 10000 });

    // Crea un textarea con TikZ + apri modal
    const result = await page.evaluate(async () => {
        const ta = document.createElement("textarea");
        ta.id = "test-tikz-textarea";
        ta.value = `<script type="text/tikz">
% ==================================================
% .....FIGURA TIKZ.....
\\begin{tikzpicture}
\\draw[->] (0,0) -- (2,2);
\\end{tikzpicture}
</` + `script>`;
        document.body.appendChild(ta);

        // Carica via manifest Vite (stesso pattern di verifica-preview-modal)
        const manifest = await fetch("/build/manifest.json").then((r) => r.json());
        const entry = manifest["js/entries/tikz-editor-modal.js"];
        await import("/build/" + entry.file);
        window.FM.openTikzModal(ta);

        // Aspetta che CM monti
        await new Promise((r) => setTimeout(r, 500));

        const backdrop = document.querySelector(".fm-tikz-modal-backdrop");
        const cmEditor = backdrop?.querySelector(".cm-editor");
        const previewSvg = backdrop?.querySelector(".fm-tikz-modal-preview svg");

        return {
            modalOpen: !!backdrop,
            cmMounted: !!cmEditor,
            previewSvgPresent: !!previewSvg,
            cmContent: cmEditor?.querySelector(".cm-content")?.textContent?.slice(0, 200),
        };
    });

    console.log("Modal smoke result:", JSON.stringify(result, null, 2));
    expect(result.modalOpen).toBe(true);
    expect(result.cmMounted).toBe(true);

    // Aspetta render (debounce 500ms)
    await page.waitForTimeout(900);
    const previewOk = await page.evaluate(() => {
        return !!document.querySelector(".fm-tikz-modal-preview svg");
    });
    console.log("Preview SVG inline after debounce:", previewOk);
    expect(previewOk).toBe(true);

    // Click salva
    await page.click('.fm-tikz-modal-toolbar button[data-act="save"]');
    await page.waitForTimeout(200);

    const saved = await page.evaluate(() => {
        const ta = document.querySelector("#test-tikz-textarea");
        const modal = document.querySelector(".fm-tikz-modal-backdrop");
        return {
            modalClosed: !modal,
            taValueIncludesScript: ta.value.includes("<script") && ta.value.includes("\\begin{tikzpicture}"),
        };
    });
    console.log("After save:", saved);
    expect(saved.modalClosed).toBe(true);
    expect(saved.taValueIncludesScript).toBe(true);

    // Filtra errori non critici
    const critical = errors.filter((e) => !/Failed to load resource.*404|404.*Not Found/i.test(e));
    if (critical.length) console.log("Errors:", critical);
    expect(critical).toEqual([]);
});
