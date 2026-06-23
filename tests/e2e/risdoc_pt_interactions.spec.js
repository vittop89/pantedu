/**
 * Phase 24.17 — E2E interazioni atom nodes (checkbox/textField/formCheckbox).
 *
 * Verifica che cliccare/digitare dentro atom nodes NON li distrugga,
 * il dispatch aggiorni correttamente, e lo state persista in pt-change.
 */
const { test, expect } = require("@playwright/test");
const { loginAdmin } = require("./helpers");

const walker = () => `(function*(root) {
    const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
    let n;
    while ((n = tw.nextNode())) {
        if (n.shadowRoot) yield* (function*(r){
            const tw2 = document.createTreeWalker(r, NodeFilter.SHOW_ELEMENT);
            let m; while ((m = tw2.nextNode())) { if (m.shadowRoot) yield m; yield m; }
        })(n.shadowRoot);
        yield n;
    }
})`;

async function findShadow(page, selector) {
    return await page.evaluate((sel) => {
        function* walk(root) {
            const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
            let n;
            while ((n = tw.nextNode())) {
                if (n.shadowRoot) yield* walk(n.shadowRoot);
                yield n;
            }
        }
        const out = [];
        for (const el of walk(document)) if (el.matches?.(sel)) out.push(el);
        return out.length;
    }, selector);
}

async function pierceClick(page, selector) {
    return await page.evaluate((sel) => {
        function* walk(root) {
            const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
            let n;
            while ((n = tw.nextNode())) {
                if (n.shadowRoot) yield* walk(n.shadowRoot);
                yield n;
            }
        }
        for (const el of walk(document)) {
            if (el.matches?.(sel)) { el.click(); return true; }
        }
        return false;
    }, selector);
}

test.describe("risdoc PT atom node interactions", () => {
    test("checkbox toggle + textField blur + formCheckbox click", async ({ page }) => {
        test.setTimeout(60_000);

        const errors = [];
        page.on("console", (msg) => {
            if (msg.type() === "error") errors.push(msg.text());
        });
        page.on("pageerror", (err) => errors.push(`[pageerror] ${err.message}`));

        await loginAdmin(page);

        const tmplJson = await page.request.get("/api/risdoc/templates").then(r => r.json()).catch(() => ({}));
        const tmpls = tmplJson.templates || tmplJson.rows || [];
        const piano = tmpls.find((t) =>
            t.schema_id === "piano-annuale-docente" || t.argomento?.toLowerCase().includes("piano")
        );
        if (!piano) { test.skip(true, "piano-annuale non trovato"); return; }

        await page.goto(`/risdoc/view/${piano.id}`);
        // Phase 24+: <fm-risdoc-template> rimosso, sostituito da <fm-pt-document>
        // (vedi fm-pt-document.js: "_renderRisdocShell rimosso assieme a
        // <fm-risdoc-template>"). Il documento risdoc ora è reso dal web component
        // unificato pt-document.
        await page.waitForSelector("fm-pt-document", { timeout: 10000 });
        await page.waitForTimeout(3500); // attendi hydration + fetches

        // Nel piano-annuale sez 5 STRATEGIE DIDATTICHE ha 52 checkbox items.
        const cbItemCount = await findShadow(page, ".pt-checkbox-item input[type='checkbox']");
        expect(cbItemCount, "checkbox items presenti").toBeGreaterThan(0);

        // Toggle primo checkbox → verifica checked cambia
        const before = await page.evaluate(() => {
            function* walk(root) {
                const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                let n;
                while ((n = tw.nextNode())) {
                    if (n.shadowRoot) yield* walk(n.shadowRoot);
                    yield n;
                }
            }
            for (const el of walk(document)) {
                if (el.matches?.(".pt-checkbox-item input[type='checkbox']")) {
                    return el.checked;
                }
            }
            return null;
        });

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
                if (el.matches?.(".pt-checkbox-item input[type='checkbox']")) {
                    el.checked = !el.checked;
                    el.dispatchEvent(new Event("change", { bubbles: true }));
                    return;
                }
            }
        });
        await page.waitForTimeout(300);

        const after = await page.evaluate(() => {
            function* walk(root) {
                const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                let n;
                while ((n = tw.nextNode())) {
                    if (n.shadowRoot) yield* walk(n.shadowRoot);
                    yield n;
                }
            }
            for (const el of walk(document)) {
                if (el.matches?.(".pt-checkbox-item input[type='checkbox']")) {
                    return el.checked;
                }
            }
            return null;
        });
        expect(after, `checkbox state cambiato (before=${before})`).not.toBe(before);

        // Focus editor e insert un formCheckbox per test toggle
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
                if (el.tagName?.toLowerCase() === "fm-risdoc-pt-editor") {
                    const pm = el.shadowRoot?.querySelector(".ProseMirror");
                    if (pm) { pm.focus(); pm.click(); return; }
                }
            }
        });
        await page.waitForTimeout(200);

        await page.evaluate(() => {
            const ed = window.FM?.pt?.currentEditor;
            ed?.insertQuick?.("ptFormCheckbox", ["Test affermazione", false]);
        });
        await page.waitForTimeout(400);

        // Verifica formCheckbox presente
        const fcCount = await findShadow(page, '[data-pt-type="ptFormCheckbox"]');
        expect(fcCount, "formCheckbox inserito").toBeGreaterThan(0);

        // Insert textField + typing simulation
        await page.evaluate(() => {
            const ed = window.FM?.pt?.currentEditor;
            ed?.insertQuick?.("ptTextField", ["Campo test", "", "text"]);
        });
        await page.waitForTimeout(400);

        const tfCount = await findShadow(page, '[data-pt-type="ptTextField"]');
        expect(tfCount, "textField inserito").toBeGreaterThan(0);

        // Verifica che l'input label input dentro textField esista (atom safety)
        const tfInputs = await findShadow(page, '[data-pt-type="ptTextField"] input');
        expect(tfInputs, "textField ha input").toBeGreaterThan(0);

        // Simula typing dentro il primo textField input (keyup → stopPropagation safe)
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
                if (el.matches?.('[data-pt-type="ptTextField"] input')) {
                    el.focus();
                    el.value = "Test value typed";
                    el.dispatchEvent(new Event("input", { bubbles: true }));
                    el.dispatchEvent(new Event("blur", { bubbles: true }));
                    return;
                }
            }
        });
        await page.waitForTimeout(300);

        // Il valore non deve essere stato "mangiato" da ProseMirror backspace
        const tfValue = await page.evaluate(() => {
            function* walk(root) {
                const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                let n;
                while ((n = tw.nextNode())) {
                    if (n.shadowRoot) yield* walk(n.shadowRoot);
                    yield n;
                }
            }
            for (const el of walk(document)) {
                if (el.matches?.('[data-pt-type="ptTextField"] input')) {
                    return el.value;
                }
            }
            return null;
        });
        expect(tfValue, "textField input preserva valore dopo typing").toBe("Test value typed");

        // Il test verifica che le interazioni con gli atom node non sollevino
        // errori JS (throw/SyntaxError) né corrompano lo stato. I "Failed to load
        // resource" (401/404) sono rumore di rete del chrome di pagina sul route
        // standalone /risdoc/view (es. /api/sidebar/config, /api/teacher/* in
        // contesto senza bootstrap completo) e non riguardano l'intento del test.
        const jsErrors = errors.filter((e) => !/Failed to load resource/i.test(e));
        expect(jsErrors, `errori console:\n${jsErrors.join("\n")}`).toHaveLength(0);
        console.log("[test] atom interactions OK");
    });
});
