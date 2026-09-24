// @ts-check
/**
 * La pagina di studio di una verifica: quel che arriva già dal server e quel
 * che si può fare sopra.
 * Prima parte della riscrittura di verifiche_studio_smoke.spec.js.
 *
 * Una verifica si guarda dalla stessa pagina di un esercizio, ma con un altro
 * scopo: il docente ci arriva per scegliere che cosa mettere nella prova. Per
 * questo i comandi — la casella A, il punteggio, l'origine, il colore — devono
 * esserci già al primo caricamento, senza che nessuno li vada a costruire dopo:
 * era così che funzionava prima, con una chiamata in più e un pezzo di pagina
 * che compariva in ritardo.
 *
 * Cosa cambia rispetto a prima: niente login nella spec, la verifica nasce dal
 * test invece di essere la prima che si trova nella terna del dump locale,
 * niente schermate salvate a mano, e delle attese a tempo non resta nulla.
 */
const { test, expect } = require("../support/test");

test.describe("Verifiche — pagina di studio", () => {
    test("l'elenco degli argomenti porta titoli leggibili, non impronte", async ({
        contentFactory, teacherPage, env,
    }) => {
        const verifica = await contentFactory.exercise({
            contentType: "verifica", groups: 1, itemsPerGroup: 1, publish: true, terna: env.terna,
        });

        const { indirizzo, classe, materia } = env.terna;
        await teacherPage.goto(`/studio/verifica/${indirizzo}/${classe}/${materia}`);
        const voci = teacherPage.locator(".fm-study-topics a");
        await expect(voci.first(), "l'elenco ha almeno una voce").toBeVisible({ timeout: 30_000 });

        const titoli = await voci.allInnerTexts();
        expect(titoli, "la verifica appena creata è elencata").toContain(verifica.title);
        for (const titolo of titoli) {
            // Un tempo l'argomento poteva arrivare come impronta esadecimale:
            // otto caratteri illeggibili al posto del titolo.
            expect(titolo.trim(), `«${titolo}» non è un'impronta`).not.toMatch(/^[0-9a-f]{8}$/);
        }
    });

    test("i comandi della scelta arrivano già dal server, uno per quesito", async ({
        contentFactory, studioEsercizio, teacherPage,
    }) => {
        const verifica = await contentFactory.exercise({
            contentType: "verifica", groups: 2, itemsPerGroup: 3, publish: true,
        });
        await studioEsercizio.vaiA(verifica.studioUrl);

        const conteggi = await teacherPage.evaluate(() => {
            const quanti = (/** @type {string} */ selettore) => document.querySelectorAll(selettore).length;
            return {
                quesiti: quanti(".fm-collection__item"),
                riquadri: quanti(".fm-collection__item > .fm-check-in"),
                casella: quanti(".fm-collection__item > .fm-check-in .fm-checkbox-ain"),
                punteggio: quanti(".fm-collection__item > .fm-check-in .fm-input-pt"),
                origine: quanti(".fm-collection__item > .fm-check-in .origin"),
                colore: quanti(".fm-collection__item > .fm-check-in .fm-color-select"),
                modifica: quanti(".fm-collection__item > .fm-check-in .fm-edit-quesito"),
                gruppi: quanti(".fm-groupcollex"),
                sceltaDelGruppo: quanti(".fm-groupcollex > .fm-pos-check-es > .selection"),
                comandiDelGruppo: quanti(".fm-groupcollex > .fm-collapsible > .fm-checkmod"),
                spostamento: quanti(".fm-groupcollex .fm-checkmod > .fm-move-btn"),
                // Il riquadro non deve finire sul contenitore del contratto:
                // ci stava, e mandava fuori posto tutta la colonna.
                riquadroFuoriPosto: quanti(".fm-contract-wrap > .fm-check-in"),
            };
        });

        expect(conteggi.quesiti, "i sei quesiti creati sono resi").toBe(6);
        for (const [nome, valore] of /** @type {[string, number][]} */ ([
            ["riquadri", conteggi.riquadri],
            ["caselle A", conteggi.casella],
            ["punteggi", conteggi.punteggio],
            ["selettori dell'origine", conteggi.origine],
            ["selettori del colore", conteggi.colore],
            ["comandi di modifica", conteggi.modifica],
        ])) {
            expect(valore, `${nome}: uno per quesito`).toBe(conteggi.quesiti);
        }
        expect(conteggi.sceltaDelGruppo, "la scelta del gruppo, una per gruppo").toBe(conteggi.gruppi);
        expect(conteggi.comandiDelGruppo, "e i suoi comandi").toBe(conteggi.gruppi);
        expect(conteggi.spostamento, "con lo spostamento dentro i comandi").toBe(conteggi.gruppi);
        expect(conteggi.riquadroFuoriPosto, "nessun riquadro sul contenitore").toBe(0);
    });

    test("il titolo della sezione le sta subito sopra, e premendolo si apre e si chiude", async ({
        contentFactory, studioEsercizio, teacherPage,
    }) => {
        // L'apertura dipende dal fatto che il contenuto sia il fratello
        // successivo del titolo: infilarci in mezzo un elemento la rompe.
        const verifica = await contentFactory.exercise({
            contentType: "verifica", groups: 2, itemsPerGroup: 1, publish: true,
        });
        await studioEsercizio.vaiA(verifica.studioUrl);

        const fratelli = await teacherPage
            .locator(".fm-groupcollex > .fm-collapsible")
            .evaluateAll((titoli) => titoli.map((t) => t.nextElementSibling?.className ?? null));
        expect(fratelli.length, "i due gruppi hanno il loro titolo").toBe(2);
        for (const classe of fratelli) {
            expect(classe, "subito dopo il titolo c'è il contenuto").toContain("content");
        }

        const gruppo = studioEsercizio.gruppo(0);
        const contenuto = teacherPage.locator(".fm-groupcollex > .content").first();
        const altezza = () => contenuto.evaluate((el) => Math.round(el.getBoundingClientRect().height));

        await gruppo.apri();
        await expect(gruppo.quesiti.first(), "aprendo si vedono i quesiti").toBeVisible({ timeout: 15_000 });
        expect(await altezza(), "e il contenuto occupa il suo spazio").toBeGreaterThan(0);

        await gruppo.chiudi();
        await expect
            .poll(altezza, { message: "chiudendo il contenuto si richiude del tutto", timeout: 15_000 })
            .toBe(0);
    });

    test("al docente la pagina della verifica si apre già in modalità verifica", async ({
        contentFactory, studioEsercizio, teacherPage,
    }) => {
        const verifica = await contentFactory.exercise({
            contentType: "verifica", groups: 1, itemsPerGroup: 2, publish: true,
        });
        await studioEsercizio.vaiA(verifica.studioUrl);

        await expect(teacherPage.locator("body")).toHaveClass(/fm-verifica-mode/, { timeout: 30_000 });
        await expect(
            teacherPage.locator("#infoVer"),
            "il pannello delle informazioni di stampa è già in pagina",
        ).toBeAttached({ timeout: 30_000 });
        await expect(
            teacherPage.locator(".fm-scelte-verifica-wrapper"),
            "e con lui il salvataggio delle scelte",
        ).toBeAttached({ timeout: 30_000 });
    });

    test("il colore scelto per un quesito si vede subito nel suo titolo", async ({
        contentFactory, studioEsercizio, teacherPage,
    }) => {
        const verifica = await contentFactory.exercise({
            contentType: "verifica", groups: 1, itemsPerGroup: 2, publish: true,
        });
        await studioEsercizio.vaiA(verifica.studioUrl);
        await studioEsercizio.gruppo(0).apri();

        const quesito = teacherPage.locator(".fm-collection__item").first();
        const titolo = quesito.locator(".fm-titolo-quesito").first();
        const prima = await titolo.evaluate((el) => el.style.backgroundColor);

        await quesito.locator(".fm-color-select").first().selectOption("red");
        await expect
            .poll(() => titolo.evaluate((el) => el.style.backgroundColor), {
                message: "il titolo prende il colore scelto",
                timeout: 15_000,
            })
            .toBe("red");
        expect(prima, "che prima era un altro").not.toBe("red");
    });

    test("nel tema scuro il gruppo e il suo titolo restano distinti dal fondo della pagina", async ({
        contentFactory, studioEsercizio, teacherPage,
    }) => {
        const verifica = await contentFactory.exercise({
            contentType: "verifica", groups: 1, itemsPerGroup: 2, publish: true,
        });
        await studioEsercizio.vaiA(verifica.studioUrl);

        await teacherPage.evaluate(() => document.body.classList.add("fm-dark"));
        const colori = await teacherPage.evaluate(() => {
            const fondo = (/** @type {Element | null} */ elemento) =>
                elemento ? getComputedStyle(elemento).backgroundColor : null;
            return {
                pagina: fondo(document.body),
                gruppo: fondo(document.querySelector(".fm-groupcollex")),
                titolo: fondo(document.querySelector(".fm-collapsible")),
            };
        });

        expect(colori.gruppo, "il gruppo si stacca dalla pagina").not.toBe(colori.pagina);
        expect(colori.titolo, "e il titolo si stacca dal gruppo").not.toBe(colori.gruppo);
    });
});
