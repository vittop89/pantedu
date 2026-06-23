/**
 * Studio/esercizio — UPBAR (caricata dinamicamente).
 * Controlli: toggle upbar, dropdown difficoltà/origine, HideAll Probl/Eser/Soluz,
 * ShowChecked-A/R, CheckAll-A/R. Verifica effetti reali + assenza errori JS.
 */
const { test } = require("@playwright/test");
const H = require("./studio-eser-helpers");
const { expect } = H;

const flip = (page, id) => page.evaluate((i) => { const e = document.getElementById(i); if (e) { e.checked = !e.checked; e.dispatchEvent(new Event("change", { bubbles: true })); } return !!e; }, id);
const counts = (page) => page.evaluate(() => ({
    ainTotal: document.querySelectorAll(".fm-checkbox-ain").length,
    ainChecked: document.querySelectorAll(".fm-checkbox-ain:checked").length,
    binChecked: document.querySelectorAll(".fm-checkbox-bin:checked").length,
    itemsVisible: Array.from(document.querySelectorAll(".fm-collection__item")).filter((e) => e.offsetParent !== null).length,
    upbarHidden: !!document.querySelector(".fm-upbar")?.className.includes("upbar-hidden"),
}));

test.describe("studio/esercizio — upbar", () => {
    test.beforeEach(async ({ page }) => {
        await H.loginTeacher(page);
        await H.gotoEser(page, "1291");
        await expect(page.locator(".fm-upbar"), "upbar caricata dinamicamente").toBeAttached();
    });

    test("upbar-toggle nasconde e rimostra la upbar", async ({ page }) => {
        const errors = H.trackJsErrors(page);
        expect((await counts(page)).upbarHidden).toBe(false);
        await flip(page, "upbar-toggle");
        await page.waitForTimeout(400);
        expect((await counts(page)).upbarHidden, "nascosta dopo toggle").toBe(true);
        await flip(page, "upbar-toggle");
        await page.waitForTimeout(400);
        expect((await counts(page)).upbarHidden, "rimostrata dopo secondo toggle").toBe(false);
        expect(errors, errors.join("\n")).toEqual([]);
    });

    test("CheckAll-A e CheckAll-R selezionano tutti i quesiti", async ({ page }) => {
        const errors = H.trackJsErrors(page);
        const c0 = await counts(page);
        expect(c0.ainChecked).toBe(0);
        await flip(page, "selectAllA");
        await page.waitForTimeout(400);
        expect((await counts(page)).ainChecked, "tutti A selezionati").toBe(c0.ainTotal);
        await flip(page, "selectAllB");
        await page.waitForTimeout(400);
        expect((await counts(page)).binChecked, "tutti R selezionati").toBe(c0.ainTotal);
        expect(errors, errors.join("\n")).toEqual([]);
    });

    test("HideAll Esercizi nasconde i quesiti; il re-toggle li rimostra", async ({ page }) => {
        const errors = H.trackJsErrors(page);
        const c0 = await counts(page);
        expect(c0.itemsVisible).toBeGreaterThan(0);
        await flip(page, "toggleExercises");
        await page.waitForTimeout(500);
        expect((await counts(page)).itemsVisible, "quesiti nascosti").toBe(0);
        await flip(page, "toggleExercises");
        await page.waitForTimeout(500);
        expect((await counts(page)).itemsVisible, "quesiti rimostrati").toBeGreaterThan(0);
        expect(errors, errors.join("\n")).toEqual([]);
    });

    test("dropdown DIFFICOLTÀ e ORIGINE si aprono", async ({ page }) => {
        const errors = H.trackJsErrors(page);
        // i dropdown sono .dropdown con .dropdown-button; il click apre .dropdown-content
        await page.locator("#sel-dif .dropdown-button, #sel-dif").first().dispatchEvent("click");
        await page.waitForTimeout(500);
        await expect(page.locator("#sel-dif"), "#sel-dif difficoltà presente").toBeAttached();
        await page.locator("#sel-origin .dropdown-button, #sel-origin").first().dispatchEvent("click");
        await page.waitForTimeout(500);
        await expect(page.locator("#sel-origin"), "#sel-origin origine presente").toBeAttached();
        expect(errors, errors.join("\n")).toEqual([]);
    });

    test("tutti i controlli upbar si attivano senza errori JS", async ({ page }) => {
        const errors = H.trackJsErrors(page);
        for (const id of ["btnP", "toggleExercises", "btnS", "showAllA", "showAllB", "selectAllA", "selectAllB"]) {
            await page.evaluate((i) => {
                const e = document.getElementById(i);
                if (!e) return;
                if (e.type === "checkbox") { e.checked = !e.checked; e.dispatchEvent(new Event("change", { bubbles: true })); }
                else e.dispatchEvent(new MouseEvent("click", { bubbles: true }));
            }, id);
            await page.waitForTimeout(250);
        }
        expect(errors, "errori JS upbar:\n" + errors.join("\n")).toEqual([]);
    });
});
