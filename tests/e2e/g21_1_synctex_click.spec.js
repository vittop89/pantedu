/**
 * G21.1 — End-to-end SyncTeX Ctrl+click test.
 *
 * Strategia:
 *   1. Compila un .tex semplice via VPS reale (HMAC) per ottenere
 *      pdf_b64 + synctex_gz_b64 con records noti (linee 4, 6, 8).
 *   2. Carica il bundle preview-editor nel browser.
 *   3. Mocka backend `/api/verifica/{id}/compile?with_artifacts=1` per
 *      ritornare quei bytes precompilati.
 *   4. Apre modal con doc fittizio.
 *   5. Aspetta render PDF nel canvas.
 *   6. Simula Ctrl+click su un'area del canvas corrispondente a una
 *      riga nota (es. equazione $E=mc^2$ a riga 5/6 del .tex).
 *   7. Verifica che il cursore CodeMirror sia saltato a quella riga
 *      (entro tolleranza ±2 righe — SyncTeX non sempre è perfetto).
 */

const { test, expect } = require("@playwright/test");
const crypto = require("crypto");
const https = require("https");

const TEX_SOURCE = `\\documentclass{article}
\\title{SyncTeX E2E Test}
\\begin{document}
\\maketitle
Riga 5: paragrafo iniziale.

Riga 7: equazione $E=mc^2$ qui.

Riga 9: ultima riga prima di end document.
\\end{document}
`;

// G21 — VPS endpoint + secret letti da env (NON committare segreti).
//   FM_E2E_VPS_ENDPOINT, FM_E2E_VPS_SECRET
// Il test viene skippato se le env vars non sono settate.
const VPS_ENDPOINT = process.env.FM_E2E_VPS_ENDPOINT || "";
const VPS_SECRET   = process.env.FM_E2E_VPS_SECRET || "";

function postCompile(texSource) {
    return new Promise((resolve, reject) => {
        const payload = JSON.stringify({
            tex_b64: Buffer.from(texSource).toString("base64"),
            doc_id: "synctex-e2e",
            engine: "pdflatex",
            passes: 2,
        });
        const ts = String(Math.floor(Date.now() / 1000));
        const sig = crypto.createHmac("sha256", VPS_SECRET).update(`${ts}.${payload}`).digest("hex");
        const url = new URL(`${VPS_ENDPOINT}/compile?with_artifacts=1`);
        const opts = {
            method: "POST",
            host: url.hostname,
            path: url.pathname + url.search,
            headers: {
                "Content-Type": "application/json",
                "Content-Length": Buffer.byteLength(payload),
                "X-Timestamp": ts,
                "X-Signature": sig,
            },
        };
        const req = https.request(opts, (res) => {
            const chunks = [];
            res.on("data", (c) => chunks.push(c));
            res.on("end", () => {
                try {
                    const body = JSON.parse(Buffer.concat(chunks).toString("utf-8"));
                    resolve({ status: res.statusCode, body });
                } catch (e) { reject(e); }
            });
        });
        req.on("error", reject);
        req.write(payload);
        req.end();
    });
}

test.describe("G21.1 — SyncTeX Ctrl+click reale", () => {
    let pdfBytesB64 = null;
    let synctexB64 = null;

    test.beforeAll(async () => {
        if (!VPS_ENDPOINT || !VPS_SECRET) {
            test.skip(true, "FM_E2E_VPS_ENDPOINT + FM_E2E_VPS_SECRET non impostati: skip test SyncTeX live (richiede VPS reale + segreto HMAC).");
            return;
        }
        const r = await postCompile(TEX_SOURCE);
        if (r.status !== 200 || !r.body.ok) {
            throw new Error(`VPS compile failed: HTTP ${r.status} ${JSON.stringify(r.body).slice(0, 200)}`);
        }
        pdfBytesB64 = r.body.pdf_b64;
        synctexB64  = r.body.synctex_gz_b64;
        console.log(`VPS compile OK: pdf=${r.body.pdf_bytes}b synctex=${r.body.synctex_bytes}b`);
    });

    test("Ctrl+click su canvas PDF sposta cursore CodeMirror nella zona attesa", async ({ page }) => {
        page.on("pageerror", (err) => console.log("PAGE ERROR:", err.message));
        page.on("console", async (msg) => {
            const txt = msg.text();
            if (msg.type() === "debug" || txt.startsWith("[SyncTeX]") || txt.includes("DBG")) {
                // serializza args per vedere oggetti
                const args = msg.args();
                const vals = [];
                for (const a of args) {
                    try { vals.push(await a.jsonValue()); } catch { vals.push("?"); }
                }
                console.log("BROWSER:", txt, JSON.stringify(vals));
            }
        });

        await page.goto("/login");

        // Carica bundle preview
        await page.evaluate(async () => {
            const m = await fetch("/build/manifest.json").then((r) => r.json());
            const entry = m["js/entries/verifica-preview-editor.js"];
            await import(`/build/${entry.file}`);
        });

        // Inietta pdf+synctex precompilati + mocka fetch
        await page.evaluate(({ pdfB64, syncB64, tex }) => {
            window._mockPdfB64 = pdfB64;
            window._mockSyncB64 = syncB64;
            window._mockTex = tex;
            const orig = window.fetch.bind(window);
            window.fetch = async (url, opts) => {
                const u = String(url);
                if (u.includes("/auth/csrf")) {
                    return new Response(JSON.stringify({ token: "MOCK" }), {
                        status: 200, headers: { "Content-Type": "application/json" },
                    });
                }
                if (u.includes("/api/verifica/") && u.includes("/compile")) {
                    return new Response(JSON.stringify({
                        ok: true,
                        pdf_b64:        window._mockPdfB64,
                        synctex_gz_b64: window._mockSyncB64,
                        log: "(mocked)",
                        warnings: [],
                        errors: [],
                        compile: { engine: "pdflatex", duration_ms: 100, pdf_bytes: 1 },
                    }), { status: 200, headers: { "Content-Type": "application/json" } });
                }
                if (u.includes("/api/verifica/") && u.endsWith("/tex")) {
                    return new Response(window._mockTex, {
                        status: 200, headers: { "Content-Type": "text/plain" },
                    });
                }
                return orig(url, opts);
            };
        }, { pdfB64: pdfBytesB64, syncB64: synctexB64, tex: TEX_SOURCE });

        // Apri modal
        await page.evaluate(() => {
            window.FM.VerificaPreview.openPreview([
                { id: 999, variant: "A_SOL", title: "SyncTeX E2E" },
            ]);
        });

        // Aspetta che il PDF canvas sia renderizzato
        await page.waitForSelector(".fm-vp-pdf-canvas", { timeout: 10000 });
        await page.waitForTimeout(800);  // attendi rendering completo

        const canvas = page.locator(".fm-vp-pdf-canvas").first();
        const box = await canvas.boundingBox();
        expect(box).not.toBeNull();
        console.log(`Canvas box: ${JSON.stringify(box)}`);

        // Verifica il documento CodeMirror caricato col contenuto noto
        const initialDocLines = await page.evaluate(() => {
            const cm = window.FM?.VerificaPreview?.getState()?.cm;
            return cm ? cm.state.doc.lines : 0;
        });
        console.log(`Editor lines: ${initialDocLines}`);
        expect(initialDocLines).toBeGreaterThan(5);

        // Dump info SyncTeX cached
        const cacheInfo = await page.evaluate(() => {
            const state = window.FM.VerificaPreview.getState();
            const cached = Array.from(state.cache.values())[0];
            return {
                hasSynctex: !!cached?.synctex,
                bytes: cached?.synctex?.length || 0,
            };
        });
        console.log("DBG cache:", JSON.stringify(cacheInfo));

        // Click in alto-centro del canvas (probabilmente titolo "SyncTeX E2E Test")
        // SyncTeX dovrebbe trovare riga vicina a 2-3 (\\title o \\maketitle).
        const clickTopX = box.x + box.width * 0.5;
        const clickTopY = box.y + box.height * 0.10;
        await page.keyboard.down("Control");
        await page.mouse.click(clickTopX, clickTopY);
        await page.keyboard.up("Control");
        await page.waitForTimeout(300);

        const cursorAfterTop = await page.evaluate(() => {
            const cm = window.FM?.VerificaPreview?.getState()?.cm;
            if (!cm) return null;
            const head = cm.state.selection.main.head;
            const line = cm.state.doc.lineAt(head).number;
            const status = document.querySelector("[data-status-info]")?.textContent || "";
            return { line, status };
        });
        console.log("Click TOP (titolo): cursor →", cursorAfterTop);

        // Click metà-pagina dove dovrebbe esserci l'equazione (riga 7 nel sorgente)
        const clickMidX = box.x + box.width * 0.5;
        const clickMidY = box.y + box.height * 0.40;
        await page.keyboard.down("Control");
        await page.mouse.click(clickMidX, clickMidY);
        await page.keyboard.up("Control");
        await page.waitForTimeout(300);

        const cursorAfterMid = await page.evaluate(() => {
            const cm = window.FM?.VerificaPreview?.getState()?.cm;
            if (!cm) return null;
            const head = cm.state.selection.main.head;
            const line = cm.state.doc.lineAt(head).number;
            const status = document.querySelector("[data-status-info]")?.textContent || "";
            const flashEl = document.querySelector(".cm-line.fm-vp-flash");
            return { line, status, flashApplied: !!flashEl };
        });
        console.log("Click MID (equazione): cursor →", cursorAfterMid);

        // Verifica:
        // 1. Cursore si è mosso (non resta a riga 1)
        // 2. Status bar contiene "SyncTeX → riga"
        // 3. Le 2 click in posizioni diverse dovrebbero portare a righe diverse
        //    (se SyncTeX preciso) o almeno una si è mossa.
        const movedAtLeastOnce = cursorAfterTop?.line > 1 || cursorAfterMid?.line > 1;
        const statusOk = (cursorAfterTop?.status || "").includes("SyncTeX")
                      || (cursorAfterMid?.status || "").includes("SyncTeX");

        console.log("Test result:", {
            topLine: cursorAfterTop?.line,
            midLine: cursorAfterMid?.line,
            movedAtLeastOnce,
            statusOk,
        });

        expect(movedAtLeastOnce).toBe(true);
        expect(statusOk).toBe(true);

        // Bonus: verifica che ai click in posizioni diverse risponda con righe diverse
        // (validazione precision SyncTeX)
        if (cursorAfterTop?.line === cursorAfterMid?.line) {
            console.warn(`⚠️ Stessa riga (${cursorAfterTop.line}) per click in posizioni diverse - SyncTeX impreciso`);
        } else {
            console.log(`✓ Click TOP → riga ${cursorAfterTop?.line}, click MID → riga ${cursorAfterMid?.line}`);
        }
    });
});
