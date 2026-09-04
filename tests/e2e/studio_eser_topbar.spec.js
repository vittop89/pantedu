/**
 * Studio/esercizio — TOPBAR.
 * Verifica presenza + comportamento di ogni pulsante della topbar:
 * apertura del pannello/modale corretto e assenza di errori JS.
 */
const { test } = require("@playwright/test");
const H = require("./studio-eser-helpers");
const { expect } = H;

async function closeOverlays(page) {
    await page.keyboard.press("Escape").catch(() => {});
    await page.evaluate(() => {
        document.querySelectorAll(".fm-modal-close,.fm-header-cancel,.fm-dialog-actions button,[class*='cancel']").forEach((b) => {
            if (b.offsetParent !== null) b.dispatchEvent(new MouseEvent("click", { bubbles: true }));
        });
        document.querySelectorAll("[class*='modal']:not(.fm-d-none),.fm-dialog-backdrop,.fm-header-editor").forEach((m) => { m.style.display = "none"; });
    });
    await page.waitForTimeout(300);
}

test.describe("studio/esercizio — topbar", () => {
    test.beforeEach(async ({ page }) => {
        await H.loginTeacher(page);
        await H.gotoEser(page, "1291");
    });

    test("tutti i pulsanti topbar presenti", async ({ page }) => {
        const present = await page.evaluate(() => {
            const has = (sel) => !!document.querySelector(sel);
            const byText = (re) => Array.from(document.querySelectorAll(".fm-topbar button,.fm-topbar a")).some((b) => re.test((b.textContent || "") + (b.title || "")));
            return {
                texpdf: byText(/TEX\/PDF/),
                zip: byText(/ZIP/),
                editor: byText(/⚙Editor/),
                filtri: byText(/filtri/),
                crea: has("#fm-create-exercise-btn"),
                modHeader: has("#modHeaderBtn"),
                savePrint: has("#savePrintInfoBtn"),
                loadPrint: has("#loadPrintInfoBtn"),
                salvaScelte: has(".fm-salva-scelte-btn"),
                caricaScelte: has(".fm-carica-scelte-btn"),
                versions: document.querySelectorAll(".fm-version-btn").length,
                randomToggle: has("#fm-random-toggle"),
                randomPick: has("#fm-random-pick"),
                info: byText(/Info/),
            };
        });
        expect(present.texpdf, "TEX/PDF").toBe(true);
        expect(present.zip, "ZIP").toBe(true);
        expect(present.editor, "⚙Editor").toBe(true);
        expect(present.filtri, "filtri").toBe(true);
        expect(present.crea, "+Crea").toBe(true);
        expect(present.modHeader, "modHeader").toBe(true);
        expect(present.savePrint, "savePrintInfo").toBe(true);
        expect(present.loadPrint, "loadPrintInfo").toBe(true);
        expect(present.salvaScelte, "salva-scelte").toBe(true);
        expect(present.caricaScelte, "carica-scelte").toBe(true);
        expect(present.versions, "v1/v2/v3").toBe(3);
        expect(present.randomToggle, "random-toggle").toBe(true);
        expect(present.randomPick, "random-pick").toBe(true);
        expect(present.info, "Info").toBe(true);
    });

    test("opener mostrano il pannello corretto, senza errori JS", async ({ page }) => {
        const errors = H.trackJsErrors(page);
        const openAndAssert = async (clickSel, expectSel, label) => {
            await page.locator(clickSel).first().dispatchEvent("click");
            await page.waitForTimeout(900);
            await expect(page.locator(expectSel).first(), label).toBeVisible({ timeout: 6000 });
            await closeOverlays(page);
        };
        await openAndAssert("button:has-text('filtri')", "#sel-dif, #sel-origin", "filtri → dropdown difficoltà/origine");
        await openAndAssert("button:has-text('⚙Editor')", ".fm-vd-templates-modal", "Editor → modale template TEX");
        await openAndAssert("#fm-create-exercise-btn", ".fm-modal-actions, [class*='create']", "Crea → modale creazione");
        await openAndAssert("#modHeaderBtn", ".fm-header-editor, #header_page.fm-header-editing", "modHeader → editor intestazione");
        await openAndAssert(".fm-topbar__btn:has-text('Info')", "#infoVer, #wrapInfoSchool", "Info → pannello info verifica");
        expect(errors, "errori JS topbar:\n" + errors.join("\n")).toEqual([]);
    });

    test("versioni v1/v2/v3 togglano lo stato attivo", async ({ page }) => {
        const errors = H.trackJsErrors(page);
        const activeIdx = () => page.evaluate(() => Array.from(document.querySelectorAll(".fm-version-btn")).findIndex((b) => /--active|active/.test(b.className)));
        const start = await activeIdx();
        // clicca v2 (indice 1) e v3 (indice 2), verifica che l'attivo cambi
        await page.locator(".fm-version-btn").nth(1).dispatchEvent("click");
        await page.waitForTimeout(600);
        const afterV2 = await activeIdx();
        expect(afterV2, `attivo dopo v2 (start=${start})`).toBe(1);
        await page.locator(".fm-version-btn").nth(2).dispatchEvent("click");
        await page.waitForTimeout(600);
        expect(await activeIdx(), "attivo dopo v3").toBe(2);
        expect(errors, errors.join("\n")).toEqual([]);
    });

    test("modalità random toggle cambia stato", async ({ page }) => {
        const errors = H.trackJsErrors(page);
        const state = () => page.evaluate(() => {
            const b = document.getElementById("fm-random-toggle");
            return (b.getAttribute("aria-pressed") || "") + "|" + /active|on|--on/.test(b.className) + "|" + (document.body.className.includes("fm-random") ? "body-random" : "");
        });
        const before = await state();
        await page.locator("#fm-random-toggle").dispatchEvent("click");
        await page.waitForTimeout(500);
        const after = await state();
        expect(after, `stato random invariato (before=${before})`).not.toBe(before);
        expect(errors, errors.join("\n")).toEqual([]);
    });
});
