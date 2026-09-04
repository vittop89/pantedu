/**
 * G20.7 — Ctrl+click su link sidepage esercizi (multiarg) non deve
 * creare duplicati di #type_verAll.
 *
 * Bug pre-fix: dom-manager dispatchava fm:navigated{multiarg:true}
 * → 2 listener async chiamavano loadRelatedVerifica in parallelo →
 * race su `getElementById('type_verAll')==null` → 2 sezioni create.
 *
 * Fix: lock-promise condivisa in loadRelatedVerifica.
 *
 * Test: simula 2 invocazioni concorrenti di window.FM.loadRelatedVerifica
 * e verifica che il numero di #type_verAll resti 0 o 1, mai 2.
 */
const { test, expect } = require("@playwright/test");

const USERNAME = process.env.FM_E2E_USER || "superadmin";
const PASSWORD = process.env.FM_E2E_PASS || (process.env.E2E_TEACHER_PASS || "");

test("loadRelatedVerifica concurrent calls -> single #type_verAll", async ({ page }) => {
    test.setTimeout(60000);
    await page.goto("/login");
    await page.locator("input[name=username]").fill(USERNAME);
    await page.locator("input[name=password]").fill(PASSWORD);
    await page.locator("button[type=submit]").first().click();
    await page.waitForLoadState("networkidle");

    await page.goto("/studio/esercizio/sc/3/MAT/1");
    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(2000);

    // Pulisci eventuale type_verAll preesistente, poi spara 5 chiamate parallele.
    const result = await page.evaluate(async () => {
        document.getElementById("type_verAll")?.remove();
        const fn = window.FM?.loadRelatedVerifica;
        if (typeof fn !== "function") return { err: "no FM.loadRelatedVerifica" };
        // 5 parallele
        const arr = [fn(), fn(), fn(), fn(), fn()];
        await Promise.all(arr);
        return { count: document.querySelectorAll("#type_verAll").length };
    });
    console.log("RESULT:", JSON.stringify(result));
    expect(result.count, "exactly one #type_verAll after concurrent calls").toBeLessThanOrEqual(1);
});
