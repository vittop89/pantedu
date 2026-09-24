// @ts-check
/**
 * I due pannelli delle risorse: «BES/DSA» e «Risorse docente».
 * Riscrittura di risdoc_u4_sidebar.spec.js.
 *
 * Ognuno dei due si apre con le proprie categorie, e ognuna di quelle porta il
 * comando che ne attiva la modifica. Quel che ci sta dentro sono le risorse del
 * docente — copie personali dei modelli e documenti suoi: i modelli
 * dell'Istituto non compaiono più in linea (fase 25), si gestiscono
 * dall'amministrazione e si copiano dalla finestra «+ Nuovo». Questo test è
 * quello che se ne accorge se ricominciassero a trapelare.
 *
 * Cosa cambia rispetto a prima: niente login nella spec, niente
 * `page.evaluate` per nascondere finestre e banner o per premere i comandi, e
 * delle quattro attese a tempo (un secondo e mezzo dopo ogni apertura) non
 * resta nulla: si aspetta l'evento con cui il pannello dichiara di aver finito.
 */
const { test, expect } = require("../support/test");

test.describe("Risdoc — pannelli delle risorse nella barra laterale", () => {
    test.beforeEach(async ({ homeDocente }) => {
        await homeDocente.vaiA();
    });

    test("«BES/DSA» si apre con le sue due categorie", async ({ homeDocente }) => {
        const pannello = await homeDocente.apriSidepage("besDsa");
        const categorie = await pannello.locator(".fm-risdoc-cat").evaluateAll((elementi) =>
            elementi.map((el) => /** @type {HTMLElement} */ (el).dataset["category"]));
        expect(categorie, "le categorie della sezione BES/DSA").toEqual(["bes", "altro"]);
    });

    test("«Risorse docente» si apre con le sue, e non lascia trapelare i modelli", async ({ homeDocente }) => {
        const pannello = await homeDocente.apriSidepage("risorseDocente");
        const categorie = await pannello.locator(".fm-risdoc-cat").evaluateAll((elementi) =>
            elementi.map((el) => /** @type {HTMLElement} */ (el).dataset["category"]));
        expect(categorie, "le categorie della sezione risorse docente").toEqual(["modelli", "risorse"]);

        // Ogni voce cliccabile porta a una risorsa del docente, mai a un
        // modello dell'Istituto.
        const rimandi = await pannello
            .locator(".fm-risdoc-cat li[data-template-id] a")
            .evaluateAll((elementi) => elementi.map((el) => /** @type {HTMLAnchorElement} */ (el).href));
        for (const rimando of rimandi) {
            expect(rimando, "la voce punta a una risorsa del docente").toMatch(/\/risdoc\/view\/\d+/);
        }
    });

    test("ogni categoria porta il comando che ne attiva la modifica", async ({ homeDocente }) => {
        const pannello = await homeDocente.apriSidepage("risorseDocente");
        await expect(
            pannello.getByRole("button", { name: "Modifica sezione" }),
            "un comando per «modelli» e uno per «risorse»",
        ).toHaveCount(2);
    });
});
