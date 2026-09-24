// @ts-check
/**
 * Scegliere che cosa aprire dai pannelli della barra laterale: uno alla volta,
 * o più d'uno insieme.
 * Riscrittura di sidepage_click_ids_filter.spec.js,
 * sidepage_esercizio_multiarg.spec.js e della parte del cestino di
 * sidepage_surgical_crud.spec.js.
 *
 * Ogni voce del pannello apre il proprio contenuto e basta: l'indirizzo porta
 * `?ids=` con l'identificativo, e la pagina rende solo quello. Tenendo premuto
 * Ctrl le voci si sommano, e premendo di nuovo si tolgono: è il modo in cui il
 * docente mette insieme i pezzi di una prova presi da esercizi diversi.
 *
 * Con più di un esercizio aperto la pagina si mette in una modalità sua, dove
 * gli esercizi restano da parte e si guardano le verifiche che ne sono nate;
 * una casella li fa ricomparire.
 *
 * Il cestino di una voce, infine, la toglie senza ricaricare tutto il pannello:
 * era il difetto che si vedeva come un lampeggio, con la sezione che si
 * chiudeva e riapriva perdendo la modifica.
 *
 * Cosa cambia rispetto a prima: nessuna password nella spec — c'era, letta
 * dall'ambiente e scritta nel modulo di accesso — niente schermate salvate a
 * mano, i contenuti su cui si lavora nascono dal test invece di essere i primi
 * due che si trovano nella terna, e delle attese a tempo non resta nulla.
 */
const { test, expect } = require("../support/test");

test.describe("Studio — scelta dai pannelli", () => {
    test("i collegamenti del pannello portano a un solo contenuto per volta", async ({
        contentFactory, homeDocente, env,
    }) => {
        await contentFactory.exercise({ terna: env.terna, groups: 1, itemsPerGroup: 1, publish: true });

        await homeDocente.vaiA();
        await homeDocente.scegliTerna(env.terna.indirizzo, env.terna.classe, env.terna.materia);
        const pannello = await homeDocente.apriSidepage("esercizi");

        const indirizzi = await pannello
            .locator("li[data-content-id] a")
            .evaluateAll((collegamenti) => collegamenti.map((a) => a.getAttribute("href") ?? ""));
        expect(indirizzi.length, "il pannello ha delle voci").toBeGreaterThan(0);
        for (const indirizzo of indirizzi) {
            expect(indirizzo, `«${indirizzo}» dice quale contenuto aprire`).toMatch(/[?&]ids=\d+/);
        }
    });

    test("un clic apre un contenuto, Ctrl+clic ne aggiunge un secondo e un altro lo toglie", async ({
        contentFactory, homeDocente, teacherPage, env,
    }) => {
        const primo = await contentFactory.exercise({ terna: env.terna, groups: 1, itemsPerGroup: 1, publish: true });
        const secondo = await contentFactory.exercise({ terna: env.terna, groups: 1, itemsPerGroup: 1, publish: true });

        await homeDocente.vaiA();
        await homeDocente.scegliTerna(env.terna.indirizzo, env.terna.classe, env.terna.materia);
        const pannello = await homeDocente.apriSidepage("esercizi");

        /** @param {{ id: number }} contenuto */
        const voce = (contenuto) => pannello.locator(`li[data-content-id="${contenuto.id}"] a`).first();
        /** Gli identificativi che l'indirizzo della pagina dichiara aperti. */
        const aperti = () =>
            new URL(teacherPage.url()).searchParams.get("ids")?.split(",").filter(Boolean) ?? [];

        await expect(voce(primo), "il primo esercizio è elencato").toBeVisible({ timeout: 30_000 });
        await voce(primo).click();
        await teacherPage.waitForURL(new RegExp(`ids=${primo.id}`), { timeout: 30_000 });
        expect(aperti(), "aperto solo il primo").toEqual([String(primo.id)]);
        await expect(
            teacherPage.locator('.fm-contract-wrap[data-kind="esercizio"]'),
            "e la pagina rende un contenuto solo",
        ).toHaveCount(1, { timeout: 30_000 });

        await voce(secondo).click({ modifiers: ["Control"] });
        await expect
            .poll(aperti, { message: "Ctrl+clic aggiunge il secondo", timeout: 30_000 })
            .toEqual(expect.arrayContaining([String(primo.id), String(secondo.id)]));
        await expect(
            teacherPage.locator('.fm-contract-wrap[data-kind="esercizio"]'),
            "e la pagina li rende entrambi",
        ).toHaveCount(2, { timeout: 30_000 });

        await voce(secondo).click({ modifiers: ["Control"] });
        await expect
            .poll(aperti, { message: "un altro Ctrl+clic lo toglie", timeout: 30_000 })
            .toEqual([String(primo.id)]);
        await expect(
            teacherPage.locator('.fm-contract-wrap[data-kind="esercizio"]'),
            "e resta il primo",
        ).toHaveCount(1, { timeout: 30_000 });
    });

    test("con due esercizi aperti la pagina mette da parte i quesiti e la casella li rimostra", async ({
        contentFactory, homeDocente, teacherPage, env,
    }) => {
        // Serve una verifica correlata: è la sua presenza che fa mettere da
        // parte gli esercizi, perché in quel momento si sta guardando la prova.
        const coppia = await contentFactory.pair({ terna: env.terna, gruppiNellaVerifica: 1 });
        const altro = await contentFactory.exercise({ terna: env.terna, groups: 1, itemsPerGroup: 1, publish: true });

        await homeDocente.vaiA();
        await homeDocente.scegliTerna(env.terna.indirizzo, env.terna.classe, env.terna.materia);
        const pannello = await homeDocente.apriSidepage("esercizi");

        await pannello.locator(`li[data-content-id="${coppia.esercizio.id}"] a`).first().click();
        await teacherPage.waitForURL(new RegExp(`ids=${coppia.esercizio.id}`), { timeout: 30_000 });
        await expect(teacherPage.locator("body"), "con un esercizio solo non serve").not.toHaveClass(/fm-esercizio-multiarg/);

        await pannello.locator(`li[data-content-id="${altro.id}"] a`).first().click({ modifiers: ["Control"] });
        await expect(teacherPage.locator("body"), "con due la pagina lo dichiara")
            .toHaveClass(/fm-esercizio-multiarg/, { timeout: 30_000 });
        // Uno solo: i clic in rapida successione lanciano più caricamenti
        // della verifica correlata, e il secondo non deve raddoppiarla.
        await expect(teacherPage.locator("#type_verAll"), "e va a prendere le verifiche correlate, una volta sola")
            .toHaveCount(1, { timeout: 30_000 });

        const quesiti = teacherPage.locator(".fm-draggable-container > .fm-contract-wrap:not([data-kind='verifica'])");
        expect(await quesiti.count(), "gli esercizi ci sono").toBeGreaterThan(0);
        await expect(quesiti.first(), "ma sono messi da parte").toBeHidden({ timeout: 30_000 });

        await teacherPage.locator("#fm-show-student-ex").check();
        await expect(teacherPage.locator("body"), "la casella lo dichiara").toHaveClass(/fm-show-student-ex/);
        await expect(quesiti.first(), "e gli esercizi tornano a vedersi").toBeVisible({ timeout: 30_000 });
    });

    test("il «+ Nuovo» della sezione crea il contenuto, e la sua voce compare nel pannello", async ({
        contentFactory, homeDocente, teacherPage, env, naming,
    }) => {
        await homeDocente.vaiA();
        await homeDocente.scegliTerna(env.terna.indirizzo, env.terna.classe, env.terna.materia);
        const pannello = await homeDocente.apriSidepage("esercizi");
        await homeDocente.attivaModificaSezione(pannello);
        const finestra = await homeDocente.nuovoNellaSezione(pannello);

        const titolo = naming.unique("creato-dal-pannello");
        await finestra.locator('input[name="title"]').fill(titolo);
        await finestra.locator('input[name="topic"]').fill(titolo);
        await finestra.getByRole("button", { name: /Crea|Salva|Conferma/ }).first().click();

        // Alla conferma la pagina va sul contenuto appena creato: l'indirizzo
        // ne porta l'identificativo, che serve anche per cancellarlo.
        await teacherPage.waitForURL(/[?&]ids=\d+/, { timeout: 30_000 });
        const nuovoId = Number(new URL(teacherPage.url()).searchParams.get("ids"));
        expect(Number.isInteger(nuovoId) && nuovoId > 0, "il contenuto ha un identificativo").toBe(true);
        contentFactory.registerDeletion(nuovoId, `contenuto «${titolo}» creato dal pannello`);

        const riaperto = await homeDocente.apriSidepage("esercizi");
        await expect(
            riaperto.locator(`li[data-content-id="${nuovoId}"]`),
            "la voce del nuovo contenuto è nel pannello",
        ).toBeVisible({ timeout: 30_000 });
    });

    test("il cestino toglie la voce senza ricaricare il pannello, e la modifica resta attiva", async ({
        contentFactory, homeDocente, teacherPage, env, naming,
    }) => {
        const titolo = naming.unique("da-cancellare");
        const esercizio = await contentFactory.exercise({
            terna: env.terna, title: titolo, topic: titolo, groups: 1, itemsPerGroup: 1, publish: true,
        });

        await homeDocente.vaiA();
        await homeDocente.scegliTerna(env.terna.indirizzo, env.terna.classe, env.terna.materia);
        const pannello = await homeDocente.apriSidepage("esercizi");
        await homeDocente.attivaModificaSezione(pannello);

        const voce = pannello.locator(`li[data-content-id="${esercizio.id}"]`);
        await expect(voce, "la voce è nel pannello").toBeVisible({ timeout: 30_000 });

        // Da qui in avanti il pannello non deve essere richiesto di nuovo:
        // la voce sparisce da sola, senza che tutto il blocco venga riscritto.
        let richiesteDelPannello = 0;
        teacherPage.on("request", (richiesta) => {
            if (richiesta.method() === "GET" && richiesta.url().includes("/api/study/content.json")) {
                richiesteDelPannello++;
            }
        });

        await voce.locator(".fm-item-del").click();
        await teacherPage.getByRole("dialog").getByRole("button", { name: /^\s*OK\s*$/ }).click();
        await expect(voce, "la voce sparisce").toHaveCount(0, { timeout: 30_000 });

        expect(richiesteDelPannello, "e il pannello non è stato ricaricato").toBe(0);
        await expect(
            pannello.locator('ul.fm-db-block[data-edit-active="1"]'),
            "la modifica della sezione è ancora attiva",
        ).toHaveCount(1);
    });
});
