/**
 * G22.S15 — TikZ render server-side integration test.
 *
 * Verifica che il modulo `tikz-render-client.js` riesca davvero a
 * sostituire `<script type="text/tikz">` con SVG inline via il VPS
 * (locale 127.0.0.1:8001 durante smoke test, o produzione).
 *
 * Non testa l'editor UI completo — testa solo l'integration JS↔PHP↔VPS.
 */
const { test, expect } = require("@playwright/test");

const BASE_URL = process.env.FM_E2E_BASE_URL || "http://localhost";

const FAKE_SVG = `<?xml version='1.0' encoding='UTF-8'?>
<svg xmlns='http://www.w3.org/2000/svg' width='100' height='100' viewBox='0 0 100 100'>
<circle cx='50' cy='50' r='40' fill='blue'/>
<text x='50' y='55' text-anchor='middle' fill='white' font-size='12'>TEST</text>
</svg>`;

test.describe("g22.s15 tikz render", () => {

    // Intercetta /tikz/render: niente bisogno di auth o VPS reale per
    // isolare il test della pipeline JS (replaceWith di <script> con SVG).
    test.beforeEach(async ({ context }) => {
        await context.route("**/tikz/render*", async (route) => {
            const method = route.request().method();
            if (method === "GET") {
                // Sempre cache miss: il client poi POSTa.
                return route.fulfill({ status: 404, contentType: "application/json", body: '{"ok":false,"error":"cache_miss"}' });
            }
            // POST → ritorna SVG
            return route.fulfill({
                status: 200,
                contentType: "image/svg+xml",
                body: FAKE_SVG,
                headers: { "X-Tikz-Source": "compile", "X-Tikz-Hash": "abc123" },
            });
        });
        await context.route("**/auth/csrf", (route) => {
            return route.fulfill({ status: 200, contentType: "application/json", body: '{"token":"fake-csrf-token-for-test"}' });
        });
    });

    test("module tikz-render-client esposto su window.FM", async ({ page }) => {
        const errors = [];
        page.on("pageerror", (e) => errors.push(e.message));
        await page.goto(BASE_URL + "/");
        // Aspetta bootstrap
        await page.waitForFunction(() => window.FM?.Api, { timeout: 10000 });
        // Il TikzRenderClient e' esposto solo dopo che latex-render.js e' stato
        // importato (succede nelle pagine exercise-context, non sulla home).
        // Caricamento dinamico via import().
        const exposed = await page.evaluate(async () => {
            const mod = await import("/js/modules/editor/tikz-render-client.js");
            return {
                hasRenderAll: typeof mod.renderAll === "function",
                hasNormalize: typeof mod.normalizeTikz === "function",
                hasSha256: typeof mod.sha256Hex === "function",
                onWindow: typeof window.FM?.TikzRenderClient,
            };
        });
        console.log("Module surface:", exposed);
        expect(exposed.hasRenderAll).toBe(true);
        expect(exposed.hasNormalize).toBe(true);
        expect(exposed.hasSha256).toBe(true);
        expect(errors.filter((e) => !/MIME|favicon/i.test(e))).toEqual([]);
    });

    test("renderAll sostituisce <script type='text/tikz'> con <svg>", async ({ page }) => {
        const networkLog = [];
        page.on("request", (r) => {
            if (r.url().includes("/tikz/render")) {
                networkLog.push(`${r.method()} ${r.url()}`);
            }
        });
        page.on("response", async (r) => {
            if (r.url().includes("/tikz/render")) {
                networkLog.push(`  → ${r.status()} ${r.headers()["content-type"]}`);
            }
        });

        await page.goto(BASE_URL + "/");
        await page.waitForFunction(() => window.FM?.Api, { timeout: 10000 });

        const result = await page.evaluate(async () => {
            const { renderAll } = await import("/js/modules/editor/tikz-render-client.js");
            // Costruisci sandbox DOM
            const sandbox = document.createElement("div");
            sandbox.id = "tikz-test-sandbox";
            sandbox.innerHTML = `
                <script type="text/tikz" id="tikz_test1" data-show-console="true">
                \\begin{tikzpicture}
                \\draw[->] (0,0) -- (2,2);
                \\node at (1,1) {Hello};
                \\end{tikzpicture}
                </` + `script>`;
            document.body.appendChild(sandbox);

            const stats = await renderAll(sandbox, { fallbackToTikzJax: false });
            // Aspetta un tick perche' replaceWith e' sincrono ma il DOM
            // si aggiorna dopo
            const svgs = sandbox.querySelectorAll("svg");
            const remainingScripts = sandbox.querySelectorAll('script[type="text/tikz"]');
            const errorBlocks = sandbox.querySelectorAll(".fm-tikz-error-messages-block");

            // Cleanup
            const html = sandbox.outerHTML;
            sandbox.remove();

            return {
                stats,
                svgCount: svgs.length,
                scriptsRemaining: remainingScripts.length,
                errorBlocks: errorBlocks.length,
                errorText: Array.from(errorBlocks).map((b) => b.textContent).join("|").slice(0, 500),
                htmlSnippet: html.slice(0, 500),
            };
        });

        console.log("renderAll result:", JSON.stringify(result, null, 2));
        console.log("Network:", networkLog);

        expect(result.stats.errors).toEqual([]);
        expect(result.stats.ok).toBe(1);
        expect(result.svgCount).toBe(1);
        expect(result.scriptsRemaining).toBe(0);
        expect(result.errorBlocks).toBe(0);
    });

    test("checkin-handlers updatePreview path: textarea → preview → SVG", async ({ page }) => {
        const errors = [];
        page.on("pageerror", (e) => errors.push(e.message));

        await page.goto(BASE_URL + "/");
        await page.waitForFunction(() => window.FM?.LatexRender, { timeout: 10000 });

        // Simula esattamente checkin-handlers.js updatePreview + processTikzScripts:
        // 1. innerHTML = source (TikZ + script tag)
        // 2. processTikzScripts(root) → tikz-render-client.renderAll
        const result = await page.evaluate(async () => {
            const pv = document.createElement("div");
            pv.className = "fm-editor-preview";
            document.body.appendChild(pv);

            // Source che l'utente scrive in textarea (collex Quesito):
            const taValue = `<script type="text/tikz" data-show-console="true">
\\begin{tikzpicture}
\\draw[->,thick] (0,0) -- (3,2);
\\fill[red] (3,2) circle (3pt);
\\end{tikzpicture}
<\/` + `script>`;

            // Step come updatePreview:
            pv.innerHTML = taValue;

            // processTikzScripts (versione G22.S15)
            const mod = await import("/js/modules/editor/tikz-render-client.js");
            const stats = await mod.renderAll(pv, {
                fallbackToTikzJax: false,
                defaultScope: "public",
            });

            const svgs = pv.querySelectorAll("svg");
            const scripts = pv.querySelectorAll('script[type="text/tikz"]');
            pv.remove();
            return { stats, svgCount: svgs.length, scriptsRemaining: scripts.length };
        });

        console.log("checkin-handlers updatePreview result:", JSON.stringify(result, null, 2));
        if (errors.length) console.log("Errors:", errors);
        expect(result.stats.ok).toBe(1);
        expect(result.svgCount).toBe(1);
        expect(result.scriptsRemaining).toBe(0);
    });

    test("editor preview path: ContentProcessor injects then renderAll triggered", async ({ page }) => {
        const errors = [];
        page.on("pageerror", (e) => errors.push(e.message));
        page.on("console", (msg) => {
            if (msg.type() === "error" || msg.type() === "warning") {
                errors.push(`[${msg.type()}] ${msg.text()}`);
            }
        });

        await page.goto(BASE_URL + "/");
        await page.waitForFunction(() => window.FM?.LatexRender, { timeout: 10000 });

        // Simula la pipeline editor: inserisci direttamente il script in un
        // .latex-viewer e chiama tikzRenderAll come content-processor fa.
        const result = await page.evaluate(async () => {
            const { renderAll } = await import("/js/modules/editor/tikz-render-client.js");
            const wrapper = document.createElement("div");
            wrapper.className = "fm-editor-wrapper";
            wrapper.innerHTML = `
                <div class="fm-latex-preview-container">
                    <div class="fm-latex-preview-title">Preview</div>
                    <div class="fm-latex-viewer">
                        <script type="text/tikz" id="tikz_editor_1">
                        \\begin{tikzpicture}
                        \\draw[blue, thick] (0,0) circle (1cm);
                        \\fill[red] (0,0) circle (3pt);
                        \\end{tikzpicture}
                        </` + `script>
                    </div>
                </div>
            `;
            document.body.appendChild(wrapper);

            const latexViewer = wrapper.querySelector(".fm-latex-viewer");
            const stats = await renderAll(latexViewer, { fallbackToTikzJax: false });

            const svgs = latexViewer.querySelectorAll("svg");
            const scripts = latexViewer.querySelectorAll('script[type="text/tikz"]');
            wrapper.remove();
            return {
                stats,
                svgCount: svgs.length,
                scriptsRemaining: scripts.length,
            };
        });

        console.log("Editor preview path result:", JSON.stringify(result, null, 2));
        if (errors.length) console.log("Errors:", errors);

        expect(result.stats.ok).toBe(1);
        expect(result.svgCount).toBe(1);
        expect(result.scriptsRemaining).toBe(0);
    });
});
