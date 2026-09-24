// @ts-check
/**
 * Le zone di accesso che il server scrive sulla pagina (14/9/2026).
 *
 * Il client sceglie gli endpoint chiedendo una zona (`data-fm-zones` sul body,
 * `Auth::zone()`), non il ruolo. Prima confrontava `data-fm-role` con "admin",
 * ma in database l'amministratore è `administrator`: intestazione ed elenco
 * delle verifiche gli arrivavano da studente. Le decisioni dei moduli sono
 * provate con vitest (tests/js-unit/ruolo-amministratore.test.js) e le zone per
 * ruolo con PHPUnit (tests/Unit/Core/AuthZoneTest.php); qui si guarda che la
 * pagina vera, disegnata dal layout per ciascuno dei tre utenti, le porti.
 */
const { test, expect } = require("../support/test");

/** @param {import("@playwright/test").Page} pagina */
async function zoneDi(pagina) {
    await pagina.goto("/?home=1");
    const valore = await pagina.locator("body").getAttribute("data-fm-zones");
    return String(valore ?? "").split(/\s+/).filter(Boolean);
}

test.describe("Sicurezza — le zone di accesso sulla pagina", () => {
    test("chi non è entrato ha solo la zona pubblica", async ({ page }) => {
        expect(await zoneDi(page), "nessuna zona oltre a quella pubblica").toEqual(["public"]);
    });

    test("il docente ha la zona del docente, e non quella dell'amministratore", async ({ teacherPage }) => {
        const zone = await zoneDi(teacherPage);
        expect(zone, "la zona del docente c'è").toContain("teacher");
        expect(zone, "quella dell'amministratore no").not.toContain("admin");
        // ADR-040 — la zona dell'istituto è dell'amministratore di istituto.
        expect(zone, "e nemmeno quella dell'istituto").not.toContain("istituto");
    });

    test("l'amministratore ha la sua zona e anche quella del docente", async ({ adminPage }) => {
        const zone = await zoneDi(adminPage);
        expect(zone, "la zona dell'amministratore").toContain("admin");
        expect(zone, "e quella del docente, che le rotte del docente gli aprono").toContain("teacher");
    });
});
