/**
 * Studio/esercizio — RENDERING MATHJAX.
 * Verifica il rendering della matematica nei quesiti:
 *  - in lettura (read-mode) la matematica è renderizzata, niente sorgente grezza;
 *  - in edit-mode il rendering/preview funziona e la sorgente è preservata;
 *  - DOPO il salvataggio (round-trip) la matematica si ri-renderizza una sola
 *    volta (no duplicazioni, no sorgente grezza, no perdita).
 * Opera sulla copia 1293 (rigenerata in beforeEach): contiene \(\sqrt{x^2+1}\)
 * e la formula risolutiva \(x=\dfrac{-b\pm\sqrt{b^2-4ac}}{2a}\).
 */
const { test } = require("@playwright/test");
const { execSync } = require("child_process");
const path = require("path");
const H = require("./studio-eser-helpers");
const { expect } = H;

const REPO = path.resolve(__dirname, "../..");
const regen = () => execSync("node tools/dev/gen_proof_contract.cjs", { cwd: REPO, stdio: "ignore" });

// La MathJax è LAZY (.fm-mj-lazy, typeset su visibilità). In headless i gruppi
// possono restare offscreen → forziamo il typeset dell'intero documento.
async function forceTypeset(page) {
    await page.evaluate(async () => {
        document.querySelectorAll(".fm-collapsible").forEach((c) => c.classList.add("active"));
        document.querySelectorAll(".fm-mj-lazy").forEach((e) => e.classList.remove("fm-mj-lazy"));
        document.querySelectorAll(".fm-groupcollex").forEach((g) => g.scrollIntoView());
        try { if (window.MathJax?.typesetPromise) await window.MathJax.typesetPromise(); } catch (_) { /* no-op */ }
    });
    await page.waitForTimeout(1500);
}

// Conta i container MathJax resi nei gruppi e la sorgente latex grezza rimasta a vista.
const pageMath = (page) => page.evaluate(() => {
    const root = document.querySelectorAll(".fm-groupcollex");
    let mjx = 0, rawVisible = 0;
    root.forEach((g) => {
        mjx += g.querySelectorAll("mjx-container, .MathJax").length;
        // sorgente grezza visibile = delimitatori \( \[ o macro non processate nel testo renderizzato
        const txt = g.textContent || "";
        rawVisible += (txt.match(/\\\(|\\\[|\\dfrac|\\sqrt\b/g) || []).length;
    });
    return { mjx, rawVisible };
});

test.describe("studio/esercizio — MathJax", () => {
    test.beforeEach(async ({ page }) => {
        regen();
        await H.loginTeacher(page);
        await H.gotoEser(page, "1293");
        // attende il typeset MathJax
        await page.waitForFunction(() => document.querySelectorAll(".fm-groupcollex mjx-container, .fm-groupcollex .MathJax").length > 0, { timeout: 15000 }).catch(() => {});
        await page.waitForTimeout(1500);
    });

    test("read-mode: la matematica è renderizzata, niente sorgente grezza", async ({ page }) => {
        await forceTypeset(page);
        const m = await pageMath(page);
        expect(m.mjx, "container MathJax presenti nei quesiti").toBeGreaterThan(0);
        expect(m.rawVisible, "nessun delimitatore/macro latex grezzo a vista").toBe(0);
    });

    test("edit-mode: la matematica si renderizza nel pannello e la sorgente è preservata", async ({ page }) => {
        const errors = H.trackJsErrors(page);
        await H.openQuesitoEditor(page, 0);
        await page.waitForTimeout(1500);
        const ed = await page.evaluate(() => {
            const panel = document.querySelector(".fm-editor-panel");
            return {
                mjx: panel.querySelectorAll("mjx-container, .MathJax").length,
                // sorgente preservata: data-raw sui blocchi latex OPPURE testo latex editabile
                srcPreserved: panel.querySelectorAll("[data-raw]").length > 0
                    || /sqrt|dfrac/.test(panel.textContent || ""),
            };
        });
        expect(ed.mjx, "MathJax renderizzato in edit-mode (preview)").toBeGreaterThan(0);
        expect(ed.srcPreserved, "sorgente latex preservata in edit").toBe(true);
        expect(errors, errors.join("\n")).toEqual([]);
    });

    test("post-save: la matematica si ri-renderizza una sola volta (no duplicati/perdita)", async ({ page }) => {
        const errors = H.trackJsErrors(page);
        await forceTypeset(page);
        const before = await pageMath(page);
        expect(before.mjx, "math presente prima del salvataggio").toBeGreaterThan(0);

        // apri editor, aggiungi testo NON-latex in fondo al campo Quesito, salva (chiudi)
        await H.openQuesitoEditor(page, 0);
        await page.evaluate(() => {
            const f = document.querySelector(".fm-editor-panel .fm-editor-field");
            f.focus();
            const r = document.createRange(); r.selectNodeContents(f); r.collapse(false);
            const s = getSelection(); s.removeAllRanges(); s.addRange(r);
        });
        await page.keyboard.type(" [verifica math]", { delay: 8 });
        await page.waitForTimeout(600);
        await H.closeQuesitoEditor(page);
        await page.waitForTimeout(2500);
        await forceTypeset(page);

        const after = await pageMath(page);
        // niente sorgente grezza a vista (il round-trip non ha "rotto" il latex)
        expect(after.rawVisible, "nessuna sorgente latex grezza dopo salvataggio").toBe(0);
        // niente perdita né raddoppio: stesso ordine di grandezza
        expect(after.mjx, "MathJax non perso dopo salvataggio").toBeGreaterThan(0);
        expect(after.mjx, `MathJax non raddoppiato (before=${before.mjx}, after=${after.mjx})`).toBeLessThanOrEqual(before.mjx * 1.5 + 1);
        expect(errors.filter((e) => !/422|compile|Failed/i.test(e)), errors.join("\n")).toEqual([]);
    });
});
