/**
 * Studio/esercizio — editing GRUPPO e QUESITO.
 * Opera sulla COPIA di lavoro 1293 (rigenerata prima di ogni test) per non
 * toccare contenuti reali. Le operazioni distruttive (elimina) vengono
 * annullate intercettando FM.Dialog.confirm.
 */
const { test } = require("@playwright/test");
const { execSync } = require("child_process");
const path = require("path");
const H = require("./studio-eser-helpers");
const { expect } = H;

const REPO = path.resolve(__dirname, "../..");
function regenContract() {
    execSync("node tools/dev/gen_proof_contract.cjs", { cwd: REPO, stdio: "ignore" });
}

const itemCount = (page) => page.evaluate(() => document.querySelectorAll(".fm-collection__item").length);
const firstItemId = (page) => page.evaluate(() => document.querySelector(".fm-collection__item")?.dataset?.id || "");

test.describe("studio/esercizio — gruppo + quesito", () => {
    test.beforeEach(async ({ page }) => {
        regenContract();
        await H.loginTeacher(page);
        await H.gotoEser(page, "1293");
        await expect(page.locator(".fm-collection__item").first()).toBeAttached();
    });

    test("apertura e chiusura editor quesito", async ({ page }) => {
        const errors = H.trackJsErrors(page);
        await H.openQuesitoEditor(page, 0);
        await expect(page.locator(".fm-editor-panel .fm-editor-field").first()).toBeVisible();
        await H.closeQuesitoEditor(page);
        await expect(page.locator(".fm-editor-panel")).toHaveCount(0);
        expect(errors, errors.join("\n")).toEqual([]);
    });

    test("modifica tipologia (gruppo) apre l'editor", async ({ page }) => {
        const errors = H.trackJsErrors(page);
        await page.locator(".fm-modifica-btn").first().dispatchEvent("click");
        await page.waitForTimeout(1500);
        await expect(
            page.locator(".fm-editor-panel, .fm-list-snippet-select, [class*='editor-field']").first(),
            "editor gruppo aperto",
        ).toBeVisible({ timeout: 6000 });
        expect(errors, errors.join("\n")).toEqual([]);
    });

    test("checkmod Giustifica/Soluzioni togglano senza errori", async ({ page }) => {
        const errors = H.trackJsErrors(page);
        const toggle = async (cls) => {
            const ok = await page.evaluate((c) => {
                const cb = document.querySelector(c);
                if (!cb) return false;
                cb.checked = !cb.checked; cb.dispatchEvent(new Event("change", { bubbles: true }));
                return true;
            }, cls);
            expect(ok, `${cls} presente`).toBe(true);
            await page.waitForTimeout(300);
        };
        await toggle(".checkgiust");
        await toggle(".checksol");
        expect(errors, errors.join("\n")).toEqual([]);
    });

    test("aggiungi (duplica) quesito incrementa il conteggio", async ({ page }) => {
        const errors = H.trackJsErrors(page);
        const n0 = await itemCount(page);
        await page.locator(".fm-collection__item .fm-add-btn").first().dispatchEvent("click");
        await page.waitForFunction((n) => document.querySelectorAll(".fm-collection__item").length > n, n0, { timeout: 10000 });
        expect(await itemCount(page), "quesito duplicato").toBe(n0 + 1);
        expect(errors, errors.join("\n")).toEqual([]);
    });

    test("sposta giù riordina i quesiti", async ({ page }) => {
        const errors = H.trackJsErrors(page);
        // serve un gruppo con >=2 quesiti: gruppo 2 ha 2 quesiti
        const grpItems = await page.evaluate(() => {
            const g = Array.from(document.querySelectorAll(".fm-groupcollex")).find((x) => x.querySelectorAll(".fm-collection__item").length >= 2);
            return g ? Array.from(g.querySelectorAll(".fm-collection__item")).map((i) => i.dataset.id) : [];
        });
        expect(grpItems.length, "gruppo con >=2 quesiti").toBeGreaterThanOrEqual(2);
        await page.evaluate((id) => {
            const item = document.querySelector(`.fm-collection__item[data-id="${id}"]`);
            item?.querySelector(".fm-move-down-btn")?.dispatchEvent(new MouseEvent("click", { bubbles: true }));
        }, grpItems[0]);
        await page.waitForTimeout(2000);
        const after = await page.evaluate((id) => {
            const item = document.querySelector(`.fm-collection__item[data-id="${id}"]`);
            const grp = item?.closest(".fm-groupcollex");
            return grp ? Array.from(grp.querySelectorAll(".fm-collection__item")).map((i) => i.dataset.id) : [];
        }, grpItems[0]);
        expect(after[0], "primo quesito spostato giù").not.toBe(grpItems[0]);
        expect(errors, errors.join("\n")).toEqual([]);
    });

    test("elimina quesito mostra conferma (annullata) e non rimuove", async ({ page }) => {
        const errors = H.trackJsErrors(page);
        const n0 = await itemCount(page);
        // Il guard blocca la delete dell'UNICO quesito di un gruppo PRIMA della
        // conferma → serve un quesito in un gruppo con >=2 quesiti (gruppo 2/3).
        const targetId = await page.evaluate(() => {
            const g = Array.from(document.querySelectorAll(".fm-groupcollex")).find((x) => x.querySelectorAll(".fm-collection__item").length >= 2);
            return g?.querySelector(".fm-collection__item")?.dataset?.id || null;
        });
        expect(targetId, "quesito in gruppo con >=2").toBeTruthy();
        await page.evaluate(() => {
            window.__confirmCalled = false;
            if (window.FM?.Dialog) window.FM.Dialog.confirm = async () => { window.__confirmCalled = true; return false; };
        });
        await page.evaluate((id) => {
            document.querySelector(`.fm-collection__item[data-id="${id}"] .fm-remove-btn`)?.dispatchEvent(new MouseEvent("click", { bubbles: true }));
        }, targetId);
        await page.waitForTimeout(1500);
        expect(await page.evaluate(() => window.__confirmCalled), "confirm invocata").toBe(true);
        expect(await itemCount(page), "nessuna rimozione dopo annulla").toBe(n0);
        expect(errors, errors.join("\n")).toEqual([]);
    });

    test("elimina gruppo mostra conferma (annullata) e non rimuove", async ({ page }) => {
        const errors = H.trackJsErrors(page);
        const g0 = await page.evaluate(() => document.querySelectorAll(".fm-groupcollex").length);
        await page.evaluate(() => {
            window.__confirmCalled = false;
            if (window.FM?.Dialog) window.FM.Dialog.confirm = async () => { window.__confirmCalled = true; return false; };
        });
        await page.locator(".fm-elimina-btn").first().dispatchEvent("click");
        await page.waitForTimeout(1500);
        expect(await page.evaluate(() => window.__confirmCalled), "confirm gruppo invocata").toBe(true);
        expect(await page.evaluate(() => document.querySelectorAll(".fm-groupcollex").length), "gruppo non rimosso").toBe(g0);
        expect(errors, errors.join("\n")).toEqual([]);
    });

    test("checkbox A/R, punti, e selezione quesito senza errori", async ({ page }) => {
        const errors = H.trackJsErrors(page);
        await page.evaluate(() => {
            const a = document.querySelector(".fm-checkbox-ain");
            if (a) { a.checked = true; a.dispatchEvent(new Event("change", { bubbles: true })); }
            const b = document.querySelector(".fm-checkbox-bin");
            if (b) { b.checked = true; b.dispatchEvent(new Event("change", { bubbles: true })); }
            const p = document.querySelector(".fm-input-pt");
            if (p) { p.value = "3"; p.dispatchEvent(new Event("input", { bubbles: true })); p.dispatchEvent(new Event("change", { bubbles: true })); }
        });
        await page.waitForTimeout(800);
        expect(await page.evaluate(() => document.querySelector(".fm-checkbox-ain")?.checked), "A selezionato").toBe(true);
        expect(await page.evaluate(() => document.querySelector(".fm-input-pt")?.value), "punti impostati").toBe("3");
        expect(errors, errors.join("\n")).toEqual([]);
    });
});
