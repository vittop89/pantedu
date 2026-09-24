// @ts-check
/**
 * La modifica si attiva una sezione per volta, e ogni sezione sa che cosa crea.
 * Riscrittura di risdoc_pt_section_scoped_edit.spec.js.
 *
 * I pannelli della barra laterale sono divisi in blocchi. Nelle risorse docente
 * un blocco è una categoria («modelli», «risorse»); negli esercizi è una
 * materia. Premendo «Modifica sezione» si attiva quel blocco e nessun altro, e
 * il comando «+ Nuovo» di ogni blocco sa già che cosa deve creare e dove.
 * Quando l'attivazione era del pannello intero, entrare in modifica su una
 * categoria apriva anche le altre.
 *
 * Cosa cambia rispetto a prima: niente login nella spec, niente `page.evaluate`
 * per premere i comandi o per cambiare la materia, e delle attese a tempo (due
 * volte due secondi e mezzo) non resta nulla.
 */
const { test, expect } = require("../support/test");

test.describe("Risdoc — modifica per sezione", () => {
    test("la modifica attiva solo il blocco su cui si preme", async ({ homeDocente }) => {
        await homeDocente.vaiA();
        const pannello = await homeDocente.apriSidepage("risorseDocente");

        await expect(
            pannello.locator('ul.fm-db-block[data-edit-active="1"]'),
            "all'apertura nessuna sezione è in modifica",
        ).toHaveCount(0);

        const attivo = await homeDocente.attivaModificaSezione(pannello);
        const sezioneAttiva = await attivo.getAttribute("data-section");

        const blocchi = await pannello.locator("ul.fm-db-block").evaluateAll((elementi) =>
            elementi.map((el) => {
                const he = /** @type {HTMLElement} */ (el);
                return {
                    sezione: he.dataset["section"],
                    genere: he.dataset["sectionKind"],
                    inModifica: he.dataset["editActive"] === "1",
                    haIlComando: !!he.querySelector(".fm-section-add"),
                };
            }));

        const inModifica = blocchi.filter((b) => b.inModifica);
        expect(inModifica.length, "un blocco solo è in modifica").toBe(1);
        expect(inModifica[0]?.sezione, "ed è quello su cui si è premuto").toBe(sezioneAttiva);
        expect(blocchi.every((b) => b.haIlComando), "ogni blocco ha il proprio «+ Nuovo»").toBe(true);
        expect(blocchi.every((b) => b.genere === "category"), "qui i blocchi sono categorie").toBe(true);
    });

    // 19/9/2026 — il «+» si vedeva a tutti, anche a chi studia: la regola che
    // lo nascondeva fuori dalla modifica stava in un layer del CSS che perdeva
    // contro .fm-btn. Nasconderlo anche al docente, però, vuol dire due clic
    // per ogni contenuto nuovo: dal 20/9, per scelta dell'utente, il «+» si
    // nasconde a chi studia (e a chi studia non arriva nemmeno nel DOM:
    // pubblico/cosa-vede-chi-studia) e per il docente si vede sempre. Restano
    // dentro la modifica le azioni della singola voce, dove un clic sbagliato
    // cancella qualcosa.
    for (const [chiave, descrizione] of /** @type {const} */ ([["risorseDocente", "Risorse docente"], ["esercizi", "Esercizi"]])) {
        test(`in «${descrizione}» il «+ Nuovo» si vede subito, le azioni delle voci solo dopo ✎`, async ({ homeDocente, env }) => {
            await homeDocente.vaiA();
            if (chiave === "esercizi") {
                await homeDocente.scegliTerna(env.terna.indirizzo, env.terna.classe, env.terna.materia);
            }
            const pannello = await homeDocente.apriSidepage(chiave);
            const piu = pannello.locator(".fm-section-add");

            await expect(piu.first(), "il comando si vede senza passare da ✎").toBeVisible();
            const quanti = await piu.count();
            await expect(piu.filter({ visible: true }), "e si vede in ogni blocco").toHaveCount(quanti);
            await expect(
                pannello.locator(".fm-item-actions").filter({ visible: true }),
                "le azioni delle voci invece no",
            ).toHaveCount(0);

            const blocco = await homeDocente.attivaModificaSezione(pannello);
            await expect(blocco.locator(".fm-section-add"), "dopo ✎ il «+» è ancora lì").toBeVisible();
        });
    }

    test("nel pannello degli esercizi il blocco è la materia, e il comando lo sa", async ({ homeDocente, env }) => {
        const { indirizzo, classe, materia } = env.terna;
        await homeDocente.vaiA();
        await homeDocente.scegliTerna(indirizzo, classe, materia);
        const pannello = await homeDocente.apriSidepage("esercizi");

        const blocchi = await pannello.locator("ul.fm-db-block").evaluateAll((elementi) =>
            elementi.map((el) => {
                const he = /** @type {HTMLElement} */ (el);
                const comando = /** @type {HTMLElement | null} */ (he.querySelector(".fm-section-add"));
                return {
                    sezione: he.dataset["section"],
                    genere: he.dataset["sectionKind"],
                    creaTipo: comando?.dataset["fmType"],
                    creaMateria: comando?.dataset["fmSubj"],
                };
            }));

        expect(blocchi.length, "almeno un blocco").toBeGreaterThanOrEqual(1);
        const blocco = blocchi.find((b) => b.sezione === materia);
        expect(blocco, `il blocco della materia ${materia}`).toBeTruthy();
        expect(blocco?.genere, "qui i blocchi sono materie").toBe("subject");
        expect(blocco?.creaTipo, "il comando crea un esercizio").toBe("esercizio");
        expect(blocco?.creaMateria, "nella materia del blocco").toBe(materia);
    });
});
