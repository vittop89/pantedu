// @ts-check
/**
 * Barra laterale del docente: terna, pannelli, contenuti elencati.
 * Riscrittura di sidebar.spec.js e studio_eser_verifiche_sidebar.spec.js.
 *
 * La barra laterale è il punto da cui il docente sceglie indirizzo, classe e
 * materia e apre i pannelli con i propri contenuti. I pannelli si popolano dal
 * database quando la terna cambia, e l'app lo dichiara con un evento: le spec
 * storiche aspettavano invece un tempo fisso.
 *
 * Cosa cambia rispetto a prima: niente login nella spec, attese sui segnali
 * dell'app, e i contenuti cercati nei pannelli sono creati dal test.
 */
const { test, expect } = require("../support/test");

test.describe("Studio — barra laterale", () => {
    test("i selettori della terna sono popolati dal curriculum del docente", async ({ homeDocente, teacherPage, teacherApi, env }) => {
        await homeDocente.vaiA();

        const materie = await teacherApi.curriculum.materie();
        expect(materie.length, "il docente ha delle materie").toBeGreaterThan(0);

        const selettore = teacherPage.locator("#sel-mater");
        await expect(selettore).toBeVisible();
        const codici = await selettore.locator("option").evaluateAll((opzioni) =>
            opzioni.map((o) => (o instanceof HTMLOptionElement ? o.value : "")));
        expect(codici, "la materia della terna scoperta è fra le opzioni").toContain(env.terna.materia);
    });

    test("scegliere la terna popola il pannello degli esercizi", async ({ contentFactory, homeDocente, env, naming }) => {
        const topic = naming.unique("in-sidebar");
        await contentFactory.exercise({ topic, title: topic, terna: env.terna, publish: true });

        await homeDocente.vaiA();
        await homeDocente.scegliTerna(env.terna.indirizzo, env.terna.classe, env.terna.materia);
        const pannello = await homeDocente.apriSidepage("esercizi");

        // Nelle voci del pannello il titolo non è testo: sta nel collegamento,
        // accanto ai comandi di modifica. Si cerca per indirizzo, non per testo.
        const collegamento = pannello.locator(`li a[href*="${encodeURIComponent(topic)}"]`).first();
        await expect(collegamento, "l'esercizio appena creato è elencato").toBeVisible({ timeout: 30_000 });
    });

    test("il pannello delle verifiche elenca i contenuti di quel tipo", async ({ contentFactory, homeDocente, env, naming }) => {
        const titolo = naming.unique("verifica-sidebar");
        await contentFactory.createVerifica({ titolo, terna: env.terna });

        await homeDocente.vaiA();
        await homeDocente.scegliTerna(env.terna.indirizzo, env.terna.classe, env.terna.materia);
        const pannello = await homeDocente.apriSidepage("verifiche");

        await expect(pannello.locator(`li a[href*="${encodeURIComponent(titolo)}"]`).first(), "la verifica è elencata")
            .toBeVisible({ timeout: 30_000 });
    });

    test("le classi da scegliere sono le cinque nuove, senza le sigle vecchie", async ({ homeDocente, teacherPage }) => {
        // Le classi si chiamavano «1s», «2s», «1b»: quelle sigle sono state
        // ritirate e restano solo nel curriculum come voci spente. Se tornassero
        // fra le scelte, il docente costruirebbe indirizzi che non esistono più.
        await homeDocente.vaiA();
        const voci = await teacherPage.locator("#sel-cls option").evaluateAll((opzioni) =>
            opzioni
                .filter((o) => o instanceof HTMLOptionElement && !o.disabled)
                .map((o) => /** @type {HTMLOptionElement} */ (o).value));

        expect(voci.filter((v) => /^[1-5]$/.test(v)).sort(), "le cinque classi").toEqual(["1", "2", "3", "4", "5"]);
        expect(voci.filter((v) => /^[1-5][sb]$/.test(v)), "e nessuna sigla vecchia").toEqual([]);
    });

    test("aprire un contenuto dal pannello porta alla sua pagina senza perdere la barra", async ({
        contentFactory, homeDocente, teacherPage, env, naming,
    }) => {
        // I collegamenti dei pannelli passano dal router interno
        // (`a.linkref` → `App.handleLinkrefClick`). Verso una pagina di studio
        // il router ricarica per intero, perché quella pagina ha bisogno di
        // MathJax e dell'editor: la cosa da controllare è che si arrivi al
        // contenuto scelto e che la barra laterale, con la sua terna, sia
        // ancora al suo posto dall'altra parte.
        const topic = naming.unique("aperto-da-pannello");
        const esercizio = await contentFactory.exercise({ topic, title: topic, terna: env.terna, publish: true });

        await homeDocente.vaiA();
        await homeDocente.scegliTerna(env.terna.indirizzo, env.terna.classe, env.terna.materia);
        const pannello = await homeDocente.apriSidepage("esercizi");

        const collegamento = pannello.locator(`li a.linkref[href*="${encodeURIComponent(topic)}"]`).first();
        await expect(collegamento).toBeVisible({ timeout: 30_000 });
        await collegamento.click();

        await teacherPage.waitForURL(new RegExp(`ids=${esercizio.id}`), { timeout: 30_000 });
        await expect(teacherPage.locator("nav.sidebar"), "la barra laterale è ancora lì").toBeVisible();
        await expect(teacherPage.locator("#sel-mater"), "e ricorda la materia scelta")
            .toHaveValue(env.terna.materia, { timeout: 30_000 });
        await expect(
            teacherPage.locator('.fm-contract-wrap[data-kind="esercizio"]').first(),
            "l'esercizio scelto è quello reso",
        ).toBeAttached({ timeout: 30_000 });
    });

    test("la home espone il proprio spazio dei nomi, e non più i globali di una volta", async ({
        homeDocente, teacherPage,
    }) => {
        // La barra laterale e i pannelli si appoggiano a `window.FM`: se il
        // bootstrap non arriva in fondo, la pagina si vede ma non fa niente.
        // `window.Api`, il client in jQuery, è stato tolto il 5 settembre 2026:
        // se ricompare vuol dire che è tornato dentro un bundle.
        await homeDocente.vaiA();
        const spazio = await teacherPage.evaluate(() => ({
            api: typeof window.FM?.Api,
            endpoints: typeof window.FM?.Endpoints,
            vecchioClient: typeof (/** @type {Record<string, unknown>} */ (/** @type {unknown} */ (window)))["Api"],
        }));
        expect(spazio, "FM.Api e FM.Endpoints ci sono, il vecchio client no").toEqual({
            api: "object",
            endpoints: "object",
            vecchioClient: "undefined",
        });
    });
});
