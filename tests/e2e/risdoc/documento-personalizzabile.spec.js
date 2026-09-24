// @ts-check
/**
 * Il documento personalizzabile: come si guarda e come si modifica.
 * Riscrittura di pt_document_unified.spec.js e
 * pt_document_editor_rework.spec.js.
 *
 * È il documento che il docente costruisce a sezioni: un titolo, un corpo, e
 * quel che serve dentro. La pagina lo rende dal server — così si legge subito,
 * senza aspettare il componente — e poi il componente si innesta e porta la
 * propria barra: modifica, anteprima, esportazioni.
 *
 * In modifica ogni sezione diventa una scheda a sé. Il difetto che questa
 * parte ha visto è il peggiore che ci sia: entrare in modifica, salvare, e
 * ritrovarsi il documento più corto di prima. Per questo il primo caso non
 * guarda l'interfaccia ma quel che resta scritto dopo un giro completo.
 *
 * Cosa cambia rispetto a prima: le tre password lette dall'ambiente non ci
 * sono più (e con loro il salto automatico quando mancavano), i documenti di
 * prova li crea e li cancella la factory invece di un blocco `try/finally`
 * scritto a mano, e delle schermate salvate a ogni passaggio non resta nulla.
 */
const { test, expect } = require("../support/test");

/** Un documento di partenza con una sezione, un testo e un blocco statico. */
const CORPO_INIZIALE = [
    { _type: "sectionHeader", title: "Introduzione", level: 2 },
    {
        _type: "block",
        style: "normal",
        children: [
            { _type: "span", text: "Testo iniziale ", marks: [] },
            { _type: "span", text: "in grassetto", marks: ["strong"] },
        ],
    },
    { _type: "staticContent", title: "Nota", level: 3, body: "<p>blocco statico</p>" },
];

/** Il seme di un documento nuovo: intestazione di sezione e corpo vuoto. */
const CORPO_NUOVO = [
    { _type: "sectionHeader", title: "Nuova sezione", level: 2 },
    { _type: "block", style: "normal", children: [{ _type: "span", text: "", marks: [] }] },
];

/**
 * Apre il documento e si assicura che sia in modifica, attendendo che la prima
 * scheda abbia montato il suo editor.
 *
 * Il componente entra in modifica **da solo** appena si accorge che chi guarda
 * puo' scrivere (`updated()`, edit-first): premere «Modifica» senza guardare
 * lo farebbe uscire. Si aspetta che abbia deciso, e si preme solo se serve.
 *
 * @param {import("@playwright/test").Page} pagina
 * @param {string} indirizzo
 */
async function apriInModifica(pagina, indirizzo) {
    await pagina.goto(indirizzo);
    await pagina.waitForFunction(
        () => !!document.querySelector("fm-pt-document .fm-doc-topbar--custom"),
        null,
        { timeout: 30_000 },
    );
    // Il comando dice in che stato e': «Modifica» da fermo, «Anteprima» mentre
    // si scrive. Si aspetta che l'apertura automatica abbia fatto il suo giro.
    await pagina.waitForFunction(
        () => {
            const host = /** @type {{ _editFirstTried?: boolean, _busy?: boolean }} */ (
                document.querySelector("fm-pt-document"));
            return host?._editFirstTried === true && host?._busy === false;
        },
        null,
        { timeout: 30_000 },
    );
    const comando = pagina.locator('fm-pt-document .fm-doc-topbar__btn[data-action="ptdoc-toggle-edit"]');
    if (((await comando.innerText()) || "").includes("Modifica")) {
        await comando.click();
    }
    await pagina.waitForFunction(
        () => {
            const scheda = document.querySelector("fm-pt-document fm-risdoc-pt-section");
            const editor = /** @type {Record<string, unknown> | null | undefined} */ (
                /** @type {unknown} */ (scheda?.shadowRoot?.querySelector("fm-risdoc-pt-editor")));
            return !!editor?.["_editor"];
        },
        null,
        { timeout: 30_000 },
    );
}

/** Il corpo che il componente ha in mano in questo momento. */
async function corpoInModifica(/** @type {import("@playwright/test").Page} */ pagina) {
    return pagina.evaluate(() => {
        const componente = /** @type {Record<string, () => unknown> | null} */ (
            /** @type {unknown} */ (document.querySelector("fm-pt-document")));
        return JSON.stringify(componente?.["_currentBodyPt"]?.() ?? null);
    });
}

test.describe("Risorse docente — documento personalizzabile", () => {
    test("la pagina arriva già scritta dal server, e il componente porta la sua barra", async ({
        contentFactory, teacherPage,
    }) => {
        const documento = await contentFactory.document({
            bodyPt: CORPO_INIZIALE,
            metadata: { layout: "custom" },
        });
        // Che la pagina arrivi già scritta si chiede al server, non al DOM.
        // Il senso dell'affermazione è che il documento si legge senza
        // aspettare il componente — anche a JavaScript spento — e quello lo
        // dice l'HTML della risposta. Guardarlo nel DOM vivo era una corsa
        // persa: per un docente il componente entra in modifica da solo dopo
        // circa un secondo e sostituisce il corpo reso dal server con le
        // schede dell'editor. Qui la lettura arrivava prima, sul runner dopo.
        const paginaDalServer = await teacherPage.request.get(documento.studioUrl);
        expect(paginaDalServer.status(), "la pagina risponde").toBe(200);
        expect(
            await paginaDalServer.text(),
            "il corpo è già scritto dal server, non aspetta il componente",
        ).toContain("pt-static-content");

        await teacherPage.goto(documento.studioUrl);

        const componente = teacherPage.locator("fm-pt-document");
        await expect(componente, "il componente è in pagina").toBeAttached({ timeout: 30_000 });
        await expect(componente, "e sa quale documento è").toHaveAttribute("doc-id", String(documento.id));
        await expect(componente, "il docente può modificarlo").toHaveAttribute("can-edit", "1");

        const barra = componente.locator(".fm-doc-topbar--custom");
        await expect(barra, "la barra del documento compare").toBeVisible({ timeout: 30_000 });

        // La coppia di comandi a destra dipende dallo stato: in lettura è
        // «Modifica», in modifica diventa «Salva» + «Anteprima»
        // (`fm-pt-document.js`, dove la barra si costruisce). Per un docente il
        // componente entra in modifica **da solo**, quindi lo stato in cui la
        // barra si ferma è quello: si aspetta che ci sia arrivata, invece di
        // leggerla mentre cambia. Prima la prova chiedeva «Modifica» e la
        // trovava solo perché arrivava prima del passaggio — qui sì, sul
        // runner no.
        await expect(
            barra.getByRole("button", { name: /Salva/ }),
            "la barra si ferma sullo stato di modifica, che è quello del docente",
        ).toBeVisible({ timeout: 30_000 });

        const comandi = (await barra.locator(".fm-doc-topbar__btn").allInnerTexts()).join(" | ");
        for (const comando of ["Anteprima", "Salva", "TeX", "Export JSON", "Import JSON"]) {
            expect(comandi, `il comando «${comando}»`).toContain(comando);
        }
        await expect(
            barra.locator('.fm-doc-topbar__btn[data-action="ptdoc-toggle-render"]'),
            "e il comando che lo pubblica come pagina statica",
        ).toBeAttached();

        // Due barre sarebbero una sopra l'altra: quella dello studio resta giù.
        expect(
            await teacherPage.evaluate(() => {
                const studio = document.getElementById("fm-topbar");
                return !studio || studio.hidden;
            }),
            "la barra dello studio resta nascosta",
        ).toBe(true);
    });

    test("modifica, salvataggio e ricaricamento non fanno perdere niente", async ({
        contentFactory, teacherApi, teacherPage,
    }) => {
        const documento = await contentFactory.document({
            bodyPt: CORPO_INIZIALE,
            metadata: { layout: "custom" },
        });
        await apriInModifica(teacherPage, documento.studioUrl);

        const schede = teacherPage.locator("fm-pt-document fm-risdoc-pt-section");
        expect(await schede.count(), "almeno una scheda di sezione").toBeGreaterThanOrEqual(1);
        const prima = await corpoInModifica(teacherPage);
        expect(prima, "la sezione c'è").toContain("Introduzione");
        expect(prima, "e il blocco statico anche").toContain("blocco statico");

        // Si cambia il titolo della prima sezione scrivendoci dentro: senza una
        // modifica vera il server non riscrive niente e risponde che non c'è
        // nulla da aggiornare. Il campo sta in due shadow annidati, e il
        // selettore ci arriva perché sono aperti.
        const titoloDellaSezione = teacherPage.locator(".pt-section-title-input").first();
        await expect(titoloDellaSezione, "il titolo della sezione si può scrivere").toBeVisible({ timeout: 30_000 });
        await titoloDellaSezione.fill("Introduzione [modificato]");
        await titoloDellaSezione.press("Tab");

        await teacherPage.locator('fm-pt-document .fm-doc-topbar__btn:has-text("Salva")').click();

        // Si guarda il risultato sul server, non la singola richiesta: il
        // documento si salva da solo quando si lascia un campo, e «Salva» può
        // trovare che non c'è più niente da scrivere.
        const salvato = async () =>
            JSON.stringify((await teacherApi.content.metadata(documento.id))["body_pt"] ?? null);
        await expect
            .poll(salvato, { message: "sul server arriva il titolo modificato", timeout: 30_000 })
            .toContain("Introduzione [modificato]");
        expect(await salvato(), "e il blocco statico non è andato perso").toContain("blocco statico");

        await apriInModifica(teacherPage, documento.studioUrl);
        const dopo = await corpoInModifica(teacherPage);
        expect(dopo, "riaprendo, il titolo modificato è lì").toContain("Introduzione [modificato]");
        expect(dopo, "e il blocco statico pure").toContain("blocco statico");
    });

    test("il browser non tiene da parte una copia vecchia del documento", async ({
        contentFactory, teacherApi, teacherPage,
    }) => {
        // Il componente carica il corpo da /api/teacher/content/{id}, e da lì
        // lo rilegge anche prima di ogni salvataggio parziale per non perdere
        // il resto dei metadati. Se quell'indirizzo dichiara una finestra di
        // validità, il browser risponde da sé senza chiedere niente: chi
        // ricarica dopo aver salvato rivede il documento di prima, e il
        // salvataggio successivo ci riscrive sopra quello vecchio.
        const documento = await contentFactory.document({
            bodyPt: CORPO_INIZIALE,
            metadata: { layout: "custom" },
        });
        await teacherPage.goto(documento.studioUrl);
        await teacherPage.waitForFunction(() => !!window.FM?.["DomUtils"], null, { timeout: 30_000 });

        /** Legge il dettaglio dalla pagina, per la stessa strada del componente. */
        const leggiDallaPagina = () =>
            teacherPage.evaluate(async (id) => {
                const utils = /** @type {any} */ (window.FM?.["DomUtils"]);
                const risposta = /** @type {Response} */ (
                    await utils.wafFetch(`/api/teacher/content/${id}`, {
                        headers: { Accept: "application/json" },
                    }));
                return {
                    cacheControl: risposta.headers.get("cache-control") || "",
                    corpo: JSON.stringify(await risposta.json()),
                };
            }, documento.id);

        // Una prima lettura, perché il browser abbia la sua copia in mano.
        // Senza, la prova sul contenuto potrebbe passare per il motivo
        // sbagliato: nessuna copia da riusare.
        await leggiDallaPagina();

        // Il documento cambia passando dal server, come farebbe un salvataggio
        // appena concluso o un'altra scheda aperta sullo stesso documento.
        const metadati = await teacherApi.content.metadata(documento.id);
        const corpo = /** @type {Array<Record<string, unknown>>} */ (metadati["body_pt"]);
        const intestazione = corpo[0];
        if (!intestazione) throw new Error("il documento di prova non ha l'intestazione di sezione");
        intestazione["title"] = "Introduzione [dal server]";
        await teacherApi.content.updateMetadata(documento.id, { ...metadati, body_pt: corpo });

        const letto = await leggiDallaPagina();

        // Due prove che non possono essere verdi insieme per caso: la prima
        // non dipende dal tempo trascorso, la seconda guarda il risultato.
        expect(letto.cacheControl, "il dettaglio non si dichiara valido per un po'")
            .not.toMatch(/max-age=(?!0\b)\d+/);
        expect(letto.corpo, "il browser legge il documento aggiornato")
            .toContain("Introduzione [dal server]");
    });

    test("premere «Salva» quando non c'è niente da cambiare non è un errore", async ({
        contentFactory, teacherApi,
    }) => {
        // Il documento si salva da solo quando si lascia un campo: il «Salva»
        // che segue non trova niente da riscrivere. Il server contava le righe
        // cambiate e rispondeva «non trovato», e chi guardava vedeva un
        // fallimento dove non c'era (voce 61 del debito, corretta).
        const documento = await contentFactory.document({
            bodyPt: CORPO_INIZIALE,
            metadata: { layout: "custom" },
        });

        const primo = await teacherApi.content.update(documento.id, { title: "Titolo scritto una volta" });
        expect(primo.ok, "il primo salvataggio riesce").toBe(true);

        const secondo = await teacherApi.http.send(
            "POST",
            `/api/teacher/content/${documento.id}/update`,
            { form: { title: "Titolo scritto una volta" } },
        );
        expect(secondo.status, "e anche il secondo, identico al primo").toBe(200);
        expect(secondo.body?.ok, "con esito positivo").toBe(true);
    });

    test("un contenuto che non è del docente resta «non trovato»", async ({
        contentFactory, teacher2Api,
    }) => {
        // L'altra faccia della stessa correzione: rispondere bene a un
        // salvataggio che non cambia niente non deve far passare quello di chi
        // non ha il contenuto.
        const documento = await contentFactory.document({ metadata: { layout: "custom" } });
        const esito = await teacher2Api.http.send(
            "POST",
            `/api/teacher/content/${documento.id}/update`,
            { form: { title: "Provo a cambiare un documento non mio" } },
        );
        expect(esito.status, "il collega non lo trova").toBe(404);
    });

    test("«Nuova sezione» aggiunge una scheda e ci mette dentro il cursore", async ({
        contentFactory, teacherPage,
    }) => {
        const documento = await contentFactory.document({
            bodyPt: CORPO_NUOVO,
            metadata: { layout: "custom" },
        });
        await apriInModifica(teacherPage, documento.studioUrl);

        const schede = teacherPage.locator("fm-pt-document fm-risdoc-pt-section");
        const quantePrima = await schede.count();

        // La barra delle sezioni vive nel proprio shadow: si preme da lì.
        await teacherPage.evaluate(() => {
            const barra = document.querySelector("fm-pt-document fm-risdoc-pt-toolbar");
            const bottone = [...(barra?.shadowRoot?.querySelectorAll("button") ?? [])]
                .find((b) => /Nuova sezione/.test(b.textContent ?? ""));
            if (bottone instanceof HTMLElement) bottone.click();
        });

        await expect(schede, "la scheda in più c'è").toHaveCount(quantePrima + 1, { timeout: 30_000 });
        await expect
            .poll(
                () => teacherPage.evaluate(() => {
                    const schede = [...document.querySelectorAll("fm-pt-document fm-risdoc-pt-section")];
                    const ultima = schede[schede.length - 1];
                    const editor = ultima?.shadowRoot?.querySelector("fm-risdoc-pt-editor");
                    return editor?.shadowRoot?.activeElement?.classList?.contains("pt-section-title-input") ?? false;
                }),
                { message: "e il cursore è nel titolo della nuova scheda", timeout: 15_000 },
            )
            .toBe(true);
    });

    test("il documento nuovo comincia con l'intestazione di sezione, non con un blocco statico", async ({
        contentFactory, teacherPage,
    }) => {
        // Il seme del documento nuovo era HTML grezzo: chi lo apriva si
        // trovava un blocco che non si poteva modificare a sezioni.
        const documento = await contentFactory.document({
            bodyPt: CORPO_NUOVO,
            metadata: { layout: "custom" },
        });
        await apriInModifica(teacherPage, documento.studioUrl);

        const esito = await teacherPage.evaluate(() => {
            const scheda = document.querySelector("fm-pt-document fm-risdoc-pt-section");
            const barra = document.querySelector("fm-pt-document fm-risdoc-pt-toolbar");
            const etichette = [...(barra?.shadowRoot?.querySelectorAll("button") ?? [])]
                .map((b) => (b.textContent ?? "").trim());
            return {
                primoBlocco: (() => {
                    const contenuto = /** @type {{ section?: { default?: { _type?: string }[] } } | null | undefined} */ (
                        /** @type {unknown} */ (scheda));
                    return (contenuto?.section?.default ?? [])[0]?._type;
                })(),
                haSezioneInLinea: etichette.some((l) => /^§ Sezione$/.test(l)),
                haNuovaSezione: etichette.some((l) => /Nuova sezione/.test(l)),
            };
        });
        expect(esito.primoBlocco, "si comincia con l'intestazione di sezione").toBe("sectionHeader");
        expect(esito.haSezioneInLinea, "la barra offre la sezione in linea").toBe(true);
        expect(esito.haNuovaSezione, "e la sezione come scheda a sé").toBe(true);
    });

    test("l'anteprima chiude le schede, e la pubblicazione statica resta dopo il ricaricamento", async ({
        contentFactory, teacherPage,
    }) => {
        const documento = await contentFactory.document({
            bodyPt: CORPO_INIZIALE,
            metadata: { layout: "custom" },
        });
        await apriInModifica(teacherPage, documento.studioUrl);

        await teacherPage.locator('fm-pt-document .fm-doc-topbar__btn[data-action="ptdoc-toggle-edit"]').click();
        await expect(
            teacherPage.locator("fm-pt-document fm-risdoc-pt-section"),
            "tornando in anteprima le schede si chiudono",
        ).toHaveCount(0, { timeout: 30_000 });
        await expect(
            teacherPage.locator('fm-pt-document .fm-doc-topbar__btn:has-text("Modifica")'),
            "e il comando torna a offrire la modifica",
        ).toBeVisible();

        const salvataggio = teacherPage.waitForResponse(
            (r) => r.url().includes(`/api/teacher/content/${documento.id}/update`) && r.request().method() === "POST",
            { timeout: 30_000 },
        );
        await teacherPage.locator('fm-pt-document .fm-doc-topbar__btn[data-action="ptdoc-toggle-render"]').click();
        expect((await salvataggio).ok(), "la scelta viene salvata").toBe(true);

        await teacherPage.goto(documento.studioUrl);
        await expect(
            teacherPage.locator("fm-pt-document"),
            "ricaricando, il documento è servito come pagina statica",
        ).toHaveAttribute("render-mode", "html", { timeout: 30_000 });
        await expect(
            teacherPage.locator(".fm-pt-custom-page"),
            "e l'involucro lo dichiara",
        ).toHaveAttribute("data-render-mode", "html");
    });

    test("l'esportazione in HTML restituisce il documento scritto", async ({ contentFactory, teacherApi }) => {
        const documento = await contentFactory.document({
            bodyPt: CORPO_INIZIALE,
            metadata: { layout: "custom" },
        });
        const esportato = await teacherApi.http.getText(`/api/teacher/content/${documento.id}/export-html`);
        expect(esportato, "il blocco statico è nell'esportazione").toContain("pt-static-content");
        expect(esportato, "e il testo scritto pure").toContain("blocco statico");
    });
});
