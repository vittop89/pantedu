// @ts-check
/**
 * Creazione guidata di un nuovo esercizio dalla pagina di studio.
 * Riscrittura di g19_12_exercise_wizard.spec.js.
 *
 * Il comando «Crea» apre una finestra dove il docente sceglie se creare un
 * esercizio o una verifica, di che tipo, e da quale fonte: alla conferma il
 * gruppo compare nella pagina.
 *
 * Cosa cambia rispetto a prima: niente login nella spec, niente attese a tempo
 * dopo l'apertura della finestra, e l'esercizio su cui si lavora nasce dal
 * test, così il gruppo aggiunto è cancellato con lui.
 */
const { test, expect } = require("../support/test");

test.describe("Studio esercizio — creazione guidata", () => {
    test.beforeEach(async ({ contentFactory, studioEsercizio }) => {
        const esercizio = await contentFactory.exercise({ groups: 1, itemsPerGroup: 1, publish: true });
        await studioEsercizio.vaiA(esercizio.studioUrl);
        await studioEsercizio.topbar.attendiPronta();
    });

    test("la finestra si apre con le scelte previste e si chiude con Esc", async ({ studioEsercizio, teacherPage }) => {
        // Il comando sta nel pannello delle informazioni di stampa.
        await studioEsercizio.topbar.premi("info");
        await expect(teacherPage.locator("#infoVer")).toBeVisible({ timeout: 30_000 });

        const comando = teacherPage.locator("#fm-create-exercise-btn");
        await expect(comando, "il comando di creazione è nel pannello").toBeAttached();
        await comando.click({ timeout: 30_000 });

        const finestra = teacherPage.locator("#fm-exercise-wizard-modal");
        await expect(finestra, "la finestra di creazione si apre").toHaveClass(/fm-modal--visible/, { timeout: 30_000 });

        // Si sceglie che cosa creare: un esercizio (preselezionato) o una verifica.
        const destinazione = finestra.locator('input[name="target"]');
        await expect(destinazione, "due destinazioni possibili").toHaveCount(2);
        await expect(finestra.locator('input[name="target"][value="esercizio"]'), "esercizio preselezionato").toBeChecked();
        await expect(finestra.locator('input[name="target"][value="verifica"]')).not.toBeChecked();

        // Le due schede stanno una sotto l'altra: affiancate non ci stanno e il
        // testo andava a capo dentro il riquadro (era il caso di
        // g19_15_editor_enhancements, che misurava la stessa cosa).
        const altezze = await destinazione.evaluateAll((elementi) =>
            elementi.map((el) => el.closest("label")?.getBoundingClientRect().top ?? 0));
        expect(new Set(altezze).size, "le schede sono su righe diverse").toBe(2);

        // E di che tipo: raccolta di quesiti, vero/falso, risposta multipla.
        await expect(finestra.locator('input[name="type"]'), "tre tipi di gruppo").toHaveCount(3);
        await expect(finestra.locator('input[name="type"][value="type_Collect-1"]'), "raccolta preselezionata").toBeChecked();

        // L'origine si sceglie fra le fonti del docente.
        const origine = finestra.locator("select");
        await expect(origine.first(), "selettore dell'origine").toBeAttached();

        await teacherPage.keyboard.press("Escape");
        // Chiudendola, la finestra viene tolta dal documento, non solo nascosta.
        await expect(finestra, "Esc chiude la finestra").toHaveCount(0, { timeout: 15_000 });
    });

    test("confermando la creazione il nuovo gruppo compare nella pagina", async ({ studioEsercizio, teacherPage }) => {
        const gruppiPrima = await studioEsercizio.gruppi.count();

        await studioEsercizio.topbar.premi("info");
        await expect(teacherPage.locator("#infoVer")).toBeVisible({ timeout: 30_000 });
        await teacherPage.locator("#fm-create-exercise-btn").click({ timeout: 30_000 });

        const finestra = teacherPage.locator("#fm-exercise-wizard-modal");
        await expect(finestra).toHaveClass(/fm-modal--visible/, { timeout: 30_000 });

        const creazione = teacherPage.waitForResponse(
            (r) => /\/group\/add$/.test(new URL(r.url()).pathname) && r.request().method() === "POST",
            { timeout: 60_000 },
        );
        await finestra.getByRole("button", { name: /Crea|Conferma|Aggiungi/i }).first().click({ timeout: 30_000 });
        expect((await creazione).status(), "il gruppo è salvato sul server").toBe(200);

        await expect(studioEsercizio.gruppi, "un gruppo in più nella pagina").toHaveCount(gruppiPrima + 1, { timeout: 30_000 });
    });
    test("scegliendo «verifica» il gruppo nasce nella verifica correlata", async ({
        contentFactory, studioEsercizio, teacherApi, teacherPage,
    }) => {
        // Il gruppo creato con destinazione «verifica» non va nell'esercizio
        // aperto: va nella prova che gli sta accanto, quella caricata in fondo
        // alla pagina.
        const coppia = await contentFactory.pair({ gruppiNellaVerifica: 1 });
        await studioEsercizio.vaiA(coppia.esercizio.studioUrl);
        await studioEsercizio.topbar.attendiPronta();
        await expect(teacherPage.locator("#type_verAll"), "la verifica correlata è in pagina")
            .toBeAttached({ timeout: 30_000 });

        const gruppiPrima = (await teacherApi.content.contract(coppia.verificaId)).groups?.length ?? 0;

        await studioEsercizio.topbar.premi("info");
        await expect(teacherPage.locator("#infoVer")).toBeVisible({ timeout: 30_000 });
        await teacherPage.locator("#fm-create-exercise-btn").click({ timeout: 30_000 });

        const finestra = teacherPage.locator("#fm-exercise-wizard-modal");
        await expect(finestra).toHaveClass(/fm-modal--visible/, { timeout: 30_000 });
        await finestra.locator('input[name="target"][value="verifica"]').check();
        await expect(finestra.locator('input[name="target"][value="esercizio"]'), "l'altra destinazione si spegne")
            .not.toBeChecked();

        const creazione = teacherPage.waitForResponse(
            (r) => /\/group\/add$/.test(new URL(r.url()).pathname) && r.request().method() === "POST",
            { timeout: 60_000 },
        );
        await finestra.locator('[data-action="create"]').click({ timeout: 30_000 });
        const risposta = await creazione;

        expect(new URL(risposta.url()).pathname, "il gruppo va nella verifica, non nell'esercizio")
            .toContain(`/content/${coppia.verificaId}/`);
        expect(risposta.status(), "il salvataggio riesce").toBe(200);

        const gruppiDopo = (await teacherApi.content.contract(coppia.verificaId)).groups?.length ?? 0;
        expect(gruppiDopo, "e la verifica ha un gruppo in più").toBe(gruppiPrima + 1);
    });
});
