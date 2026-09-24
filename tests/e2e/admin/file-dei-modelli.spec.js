// @ts-check
/**
 * Amministrazione — i file dei modelli delle verifiche.
 * Riscrittura di g20_09_admin_files.spec.js.
 *
 * L'amministratore vede i file dei modelli per àmbito: quello di partenza
 * («_default»), quello dell'Istituto, quelli dei singoli docenti. La pagina
 * `/admin/templates` li mostra in un albero con il selettore dell'àmbito
 * accanto.
 *
 * Cosa cambia rispetto a prima: niente stampe di console, niente
 * `page.evaluate` per nascondere una finestra, e soprattutto le asserzioni non
 * accettano più due esiti opposti. La spec storica scriveva
 * `expect([200, 403]).toContain(status)`: passava sia se l'amministratore
 * poteva leggere i file sia se non poteva, cioè non verificava niente. Qui
 * l'amministratore deve poter leggere, e un docente no.
 */
const { test, expect } = require("../support/test");

test.describe("Amministrazione — file dei modelli", () => {
    test("l'amministratore vede gli àmbiti e i file di quello di partenza", async ({ adminApi }) => {
        const ambiti = await adminApi.http.send("GET", "/api/admin/verifica/scopes");
        expect(ambiti.status, "l'elenco degli àmbiti risponde").toBe(200);

        const file = await adminApi.http.send("GET", "/api/admin/verifica/files", { params: { scope: "_default" } });
        expect(file.status, "i file dell'àmbito di partenza rispondono").toBe(200);
        const elenco = /** @type {{ files?: unknown[] }} */ (file.body)?.files ?? [];
        expect(elenco.length, "e l'àmbito di partenza non è vuoto").toBeGreaterThan(0);
    });

    test("a un docente gli stessi elenchi sono negati", async ({ teacherApi }) => {
        const ambiti = await teacherApi.http.send("GET", "/api/admin/verifica/scopes");
        expect(ambiti.ok, "il docente non amministra i modelli di tutti").toBe(false);
        expect([401, 403], `stato inatteso: ${ambiti.status}`).toContain(ambiti.status);
    });

    test("la pagina dei modelli ha l'albero dei file e il selettore dell'àmbito", async ({ adminPage }) => {
        await adminPage.goto("/admin/templates#verifiche");
        await expect(adminPage.locator("#fm-vfiles-tree"), "l'albero dei file").toBeAttached({ timeout: 30_000 });
        await expect(adminPage.locator("#fm-vfiles-scope"), "il selettore dell'àmbito").toBeAttached();
    });
});
