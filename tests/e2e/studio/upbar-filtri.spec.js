// @ts-check
/**
 * Barra dei filtri sopra i quesiti: mostra, nascondi, spunta tutto.
 * Riscrittura di studio_eser_upbar.spec.js.
 *
 * Questa è la spec che ha motivato l'unica modifica all'applicazione prevista
 * dal piano del refactoring: le sei caselle della barra avevano l'etichetta
 * accanto ma non collegata, e per spuntarle la spec storica cambiava la
 * proprietà `checked` da codice e inviava l'evento a mano — cioè verificava il
 * gestore, non il comando. Ora le etichette hanno `for` e si clicca come farebbe
 * il docente.
 *
 * Un secondo fatto emerso riscrivendo: all'apertura della pagina la barra è
 * resa ma nascosta, e la mostra il comando «filtri» della barra degli
 * strumenti. Le spec storiche non la aprivano affatto: cambiando lo stato da
 * codice funzionavano anche su elementi invisibili.
 *
 * Cosa cambia rispetto a prima: niente login nella spec, niente attese di
 * quattrocento millisecondi dopo ogni comando (si aspetta l'effetto), e
 * l'esercizio su cui si lavora nasce dal test invece di essere la copia fissa
 * numero 1291.
 *
 * Una cosa della spec storica non è stata ripresa perché non esiste più per chi
 * usa l'applicazione, e la spec la azionava solo perché cambiava lo stato da
 * codice: l'interruttore rotondo che ripiegava tutta la barra, tolto dal
 * markup il 7 settembre 2026 perché il cassetto «filtri» della barra degli
 * strumenti lo aveva già sostituito (voce 40 del debito).
 *
 * I comandi «ShowChecked-A» e «ShowChecked-R» invece esistono, ma solo per gli
 * amministratori: `_upbar_loader.php` li toglie dal markup a chiunque altro,
 * insieme al filtro ORIGINE. La spec storica li azionava con un
 * `getElementById` che tornava `null` su un gestore che usciva subito.
 * L'ultimo blocco qui sotto li verifica dove esistono davvero, e verifica che
 * al docente non arrivino (voce 41).
 */
const { test, expect } = require("../support/test");

test.describe("Studio esercizio — barra dei filtri", () => {
    test.beforeEach(async ({ contentFactory, studioEsercizio }) => {
        const esercizio = await contentFactory.exercise({ groups: 1, itemsPerGroup: 2, publish: true });
        await studioEsercizio.vaiA(esercizio.studioUrl);
        await studioEsercizio.upbar.apri();
    });

    test("«CheckAll» spunta tutti i quesiti, per la verifica e per il recupero", async ({ studioEsercizio }) => {
        const upbar = studioEsercizio.upbar;
        const iniziali = await upbar.conteggi();
        expect(iniziali.totale, "la pagina ha dei quesiti").toBeGreaterThan(0);
        expect(iniziali.spuntatiA, "nessuno spuntato all'apertura").toBe(0);

        await upbar.imposta("CheckAll-A", true);
        await expect
            .poll(async () => (await upbar.conteggi()).spuntatiA, { message: "quesiti spuntati per la verifica", timeout: 15_000 })
            .toBe(iniziali.totale);

        await upbar.imposta("CheckAll-R", true);
        await expect
            .poll(async () => (await upbar.conteggi()).spuntatiR, { message: "quesiti spuntati per il recupero", timeout: 15_000 })
            .toBe(iniziali.totale);
    });

    test("«HideAll Eser» nasconde i quesiti e il comando inverso li rimostra", async ({ studioEsercizio }) => {
        const upbar = studioEsercizio.upbar;
        const iniziali = await upbar.conteggi();
        expect(iniziali.visibili, "i quesiti sono visibili").toBeGreaterThan(0);

        await upbar.imposta("HideAll Eser", true);
        await expect
            .poll(async () => (await upbar.conteggi()).visibili, { message: "quesiti nascosti", timeout: 15_000 })
            .toBe(0);

        await upbar.imposta("HideAll Eser", false);
        await expect
            .poll(async () => (await upbar.conteggi()).visibili, { message: "quesiti di nuovo visibili", timeout: 15_000 })
            .toBeGreaterThan(0);
    });

    test("il filtro DIFFICOLTÀ si apre; ORIGINE al docente non compare", async ({ studioEsercizio }) => {
        const upbar = studioEsercizio.upbar;
        const voci = upbar.voci("sel-dif");
        await expect(voci, "il menu parte chiuso").toBeHidden();

        await upbar.tendina("sel-dif").click();
        await expect(voci, "il menu della difficoltà si apre").toBeVisible();
        await expect(voci.getByRole("link"), "quattro livelli: tutti e i tre pallini").toHaveCount(4);

        // Premendo altrove il menu si richiude: è il gestore «click fuori».
        // L'etichetta DIFFICOLTÀ punta a un `div`, quindi il clic non fa altro.
        await upbar.root.locator('label[for="sel-dif"]').click();
        await expect(voci, "il menu si richiude").toBeHidden();

        // Il filtro ORIGINE lo mette solo il caricatore della barra agli
        // amministratori: al docente la pagina arriva senza.
        await expect(upbar.tendina("sel-origin"), "ORIGINE non c'è per il docente").toHaveCount(0);
    });

    // «senza errori» non è più scritto qui: la fixture della diagnostica fa
    // fallire qualunque test che lasci errori JavaScript in pagina.
    test("tutti i comandi della barra si premono senza errori", async ({ studioEsercizio }) => {
        const upbar = studioEsercizio.upbar;

        // «HideAll Probl» è un bottone e cambia scritta: è il suo effetto visibile.
        const problemi = upbar.comandoProblemi();
        await expect(problemi).toHaveText("HideAll Probl");
        await problemi.click();
        await expect(problemi, "il comando si inverte").toHaveText("ShowAll Probl");
        await problemi.click();
        await expect(problemi, "e torna com'era").toHaveText("HideAll Probl");

        // Le quattro caselle che il docente ha davvero: si spuntano e si
        // tolgono dall'etichetta.
        for (const comando of /** @type {const} */ (["HideAll Eser", "HideAll Soluz", "CheckAll-A", "CheckAll-R"])) {
            await upbar.imposta(comando, true);
            await upbar.imposta(comando, false);
        }
    });
});

test.describe("Studio esercizio — i filtri riservati agli amministratori", () => {
    test("al docente il server non manda i comandi riservati", async ({
        contentFactory, studioEsercizio, teacherPage,
    }) => {
        const esercizio = await contentFactory.exercise({ groups: 1, itemsPerGroup: 2, publish: true });
        await studioEsercizio.vaiA(esercizio.studioUrl);
        await studioEsercizio.upbar.apri();

        // Non «nascosti»: proprio assenti dal documento. Li toglie
        // `_upbar_loader.php` prima di mandare la pagina, quindi non c'è
        // foglio di stile o console che li possa rimettere.
        await expect(teacherPage.locator("#showAllA"), "niente ShowChecked-A").toHaveCount(0);
        await expect(teacherPage.locator("#showAllB"), "niente ShowChecked-R").toHaveCount(0);
        await expect(teacherPage.locator("#sel-origin"), "e niente filtro ORIGINE").toHaveCount(0);
    });

    test("l'amministratore li ha, e «ShowChecked-A» mostra solo i quesiti spuntati", async ({
        contentFactory, studioEsercizioAdmin, adminPage,
    }) => {
        const esercizio = await contentFactory.exercise({ groups: 2, itemsPerGroup: 2, publish: true });
        await studioEsercizioAdmin.vaiA(esercizio.studioUrl);
        const upbar = studioEsercizioAdmin.upbar;

        await expect(adminPage.locator("#showAllA"), "ShowChecked-A c'è").toHaveCount(1);
        await expect(adminPage.locator("#sel-origin"), "e c'è il filtro ORIGINE").toHaveCount(1);

        const iniziali = await upbar.conteggi();
        expect(iniziali.totale, "l'esercizio ha quesiti").toBeGreaterThan(1);
        expect(iniziali.visibili, "che si vedono tutti").toBe(iniziali.totale);

        // Si spunta un quesito solo, come farebbe chi compone una verifica.
        const gruppo = studioEsercizioAdmin.gruppo(0);
        await gruppo.apri();
        await gruppo.controlliQuesito(0).approfondimento.check();
        await expect
            .poll(async () => (await upbar.conteggi()).spuntatiA, { message: "un quesito spuntato", timeout: 15_000 })
            .toBe(1);

        // Il cassetto dei filtri si apre adesso: un clic in pagina lo
        // richiude, e prima bisognava premere una casella del quesito.
        await upbar.apri();

        // Acceso il filtro resta in pagina solo quello.
        await upbar.imposta("ShowChecked-A", true);
        await expect
            .poll(async () => (await upbar.conteggi()).visibili, {
                message: "resta visibile solo il quesito spuntato",
                timeout: 15_000,
            })
            .toBe(1);

        // Spento, tornano tutti.
        await upbar.imposta("ShowChecked-A", false);
        await expect
            .poll(async () => (await upbar.conteggi()).visibili, {
                message: "spento il filtro si rivede tutto",
                timeout: 15_000,
            })
            .toBe(iniziali.totale);
    });
});
