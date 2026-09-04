/**
 * Phase 24.22 — E2E orientation toggle + popover clamp.
 *
 * Verifica:
 *   - toolbar ha button orientation (portrait/landscape)
 *   - click toggla state
 *   - export zip con landscape produce sty con `landscape,` al posto di `%[landscape]`
 *   - popover cell non esce dallo schermo (clamp left/top applicato)
 */
const { test, expect } = require("@playwright/test");
const { loginAdmin } = require("./helpers");
const AdmZip = require("adm-zip");

async function fetchCsrf(page) {
    const r = await page.request.get("/auth/csrf");
    const j = await r.json();
    return j.token || "";
}

test.describe("risdoc PT orientation + popover clamp", () => {
    test("toolbar toggle orientation + export TeX landscape", async ({ page }) => {
        test.setTimeout(60_000);
        await loginAdmin(page);

        const tmplJson = await page.request.get("/api/risdoc/templates").then(r => r.json()).catch(() => ({}));
        const piano = (tmplJson.templates || []).find(t =>
            String(t.argomento || "").toLowerCase().includes("piano_annuale")
        );
        if (!piano) { test.skip(true, "no piano"); return; }

        await page.goto(`/risdoc/view/${piano.id}`);
        await page.waitForSelector("fm-risdoc-template", { timeout: 10000 });
        await page.waitForTimeout(3000);

        // Verifica button orientation presente
        const orientBtnCount = await page.evaluate(() => {
            function* walk(root) {
                const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                let n;
                while ((n = tw.nextNode())) {
                    if (n.shadowRoot) yield* walk(n.shadowRoot);
                    yield n;
                }
            }
            let count = 0;
            for (const el of walk(document)) if (el.matches?.(".orient-toggle")) count++;
            return count;
        });
        expect(orientBtnCount, "orient-toggle button visibile").toBeGreaterThan(0);

        // Click toggla state
        await page.evaluate(() => {
            function* walk(root) {
                const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                let n;
                while ((n = tw.nextNode())) {
                    if (n.shadowRoot) yield* walk(n.shadowRoot);
                    yield n;
                }
            }
            for (const el of walk(document)) {
                if (el.matches?.(".orient-toggle")) { el.click(); return; }
            }
        });
        await page.waitForTimeout(300);

        // Verifica state è landscape ora
        const isLandscape = await page.evaluate(() => {
            function* walk(root) {
                const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                let n;
                while ((n = tw.nextNode())) {
                    if (n.shadowRoot) yield* walk(n.shadowRoot);
                    yield n;
                }
            }
            for (const el of walk(document)) {
                if (el.matches?.(".orient-toggle.is-landscape")) return true;
            }
            return false;
        });
        expect(isLandscape, "button class is-landscape dopo click").toBeTruthy();

        // Export ZIP con landscape
        const csrf = await fetchCsrf(page);
        const body = new URLSearchParams({
            _csrf: csrf,
            mode: "zip",
            form_state: JSON.stringify({
                fields: {},
                state: { indirizzo: "sc", classe: "2s", disciplina: "MAT", pageOrientation: "landscape" },
            }),
        });
        const exp = await page.request.post(`/api/risdoc/templates/${piano.id}/export`, {
            headers: {
                "Content-Type": "application/x-www-form-urlencoded",
                "X-Requested-With": "XMLHttpRequest",
                "Accept": "application/json",
            },
            data: body.toString(),
        });
        expect(exp.ok(), `export status ${exp.status()}`).toBeTruthy();
        const j = await exp.json();

        // Scarica ZIP + estrai sty, verifica "landscape," presente
        const zipRes = await page.request.get(j.url);
        const buf = await zipRes.body();

        const zip = new AdmZip(buf);
        const styEntry = zip.getEntries().find((e) => e.entryName.endsWith("risdoc.sty"));
        expect(styEntry, "risdoc.sty nel zip").toBeTruthy();
        const styContent = styEntry.getData().toString("utf8");
        const hasLandscape = styContent.includes("landscape,");
        const hasCommented = styContent.includes("%[landscape]");
        console.log("[test] sty has 'landscape,':", hasLandscape,
                    " 'commented':", hasCommented);
        expect(hasLandscape, "sty contiene landscape attivato").toBeTruthy();
        expect(hasCommented, "marker originale sostituito").toBeFalsy();
    });

    test("popover table cell viene clampato nel viewport", async ({ page }) => {
        test.setTimeout(60_000);
        await loginAdmin(page);

        const tmplJson = await page.request.get("/api/risdoc/templates").then(r => r.json()).catch(() => ({}));
        const piano = (tmplJson.templates || []).find(t =>
            String(t.argomento || "").toLowerCase().includes("piano_annuale")
        );
        if (!piano) { test.skip(true, "no piano"); return; }

        await page.goto(`/risdoc/view/${piano.id}`);
        await page.waitForSelector("fm-risdoc-template", { timeout: 10000 });
        await page.waitForTimeout(3000);

        // Set viewport piccolo per forzare clipping
        await page.setViewportSize({ width: 800, height: 600 });

        // Apri un popover cell (richiede aggiunta riga in tabella vuota)
        await page.evaluate(() => {
            function* walk(root) {
                const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                let n;
                while ((n = tw.nextNode())) {
                    if (n.shadowRoot) yield* walk(n.shadowRoot);
                    yield n;
                }
            }
            for (const el of walk(document)) {
                // "↓ sotto" = inserisci riga sotto/in fondo (ex "+ riga")
                if (el.matches?.(".pt-table-btn") && (el.textContent || "").includes("sotto")) {
                    el.click();
                    return;
                }
            }
        });
        await page.waitForTimeout(500);

        await page.evaluate(() => {
            function* walk(root) {
                const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                let n;
                while ((n = tw.nextNode())) {
                    if (n.shadowRoot) yield* walk(n.shadowRoot);
                    yield n;
                }
            }
            // Click l'ULTIMA cog (più a destra → più esposta a clip)
            let last = null;
            for (const el of walk(document)) {
                if (el.matches?.(".pt-table-cell-cfg")) last = el;
            }
            if (last) last.click();
        });
        await page.waitForTimeout(700); // attesa clamp raf

        const popRect = await page.evaluate(() => {
            function* walk(root) {
                const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                let n;
                while ((n = tw.nextNode())) {
                    if (n.shadowRoot) yield* walk(n.shadowRoot);
                    yield n;
                }
            }
            for (const el of walk(document)) {
                if (el.matches?.(".pt-table-cell-pop")) {
                    const r = el.getBoundingClientRect();
                    return { left: r.left, right: r.right, top: r.top, bottom: r.bottom,
                             vw: window.innerWidth, vh: window.innerHeight };
                }
            }
            return null;
        });
        expect(popRect, "popover presente").toBeTruthy();
        console.log("[test] pop rect:", JSON.stringify(popRect));
        // Popover interamente dentro viewport (margin 0)
        expect(popRect.right, "right dentro viewport").toBeLessThanOrEqual(popRect.vw);
        expect(popRect.left,  "left dentro viewport").toBeGreaterThanOrEqual(0);
    });
});
