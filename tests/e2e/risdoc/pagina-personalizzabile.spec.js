// @ts-check
/**
 * Documento a struttura libera: come viene reso e con quale barra.
 * Riscrittura del primo caso di page_doc_modal_user_flow.spec.js.
 *
 * Un documento con `layout: "custom"` non è una verifica: la pagina non deve
 * portarsi dietro l'apparato delle verifiche — il pannello delle informazioni
 * di stampa, la barra degli strumenti dello studio, la modalità verifica sul
 * corpo della pagina — ma la propria barra, quella del componente che rende il
 * documento.
 *
 * Cosa cambia rispetto a prima: niente login né gettone a mano, il documento
 * nasce dalla factory e la sua cancellazione è registrata, niente attesa a
 * tempo dopo il caricamento e niente schermata salvata su disco (il config ne
 * salva una da solo quando il test fallisce).
 */
const { test, expect } = require("../support/test");

test("un documento a struttura libera si rende con la propria barra, senza l'apparato delle verifiche", async ({
        contentFactory, teacherApi, teacherPage, env, naming,
    }) => {
    const { indirizzo, classe, materia } = env.terna;
    const titolo = naming.unique("scheda");
    const documento = await contentFactory.document({
        terna: env.terna,
        title: titolo,
        metadata: { layout: "custom" },
        bodyPt: [{
            _type: "staticContent",
            title: titolo,
            level: 2,
            format: "html",
            body: "<p>Inizia a comporre la pagina con i blocchi della barra.</p>",
        }],
    });

    const metadati = await teacherApi.content.metadata(documento.id);
    expect(metadati["layout"], "la struttura scelta è registrata").toBe("custom");

    const indirizzoPagina = `/studio/document/${indirizzo}/${classe}/${materia}/${encodeURIComponent(documento.topic)}`;
    const risposta = await teacherApi.http.request.get(indirizzoPagina);
    expect(risposta.ok(), "la pagina si apre").toBe(true);
    const html = await risposta.text();
    expect(html, "la pagina non è vuota").not.toContain("Nessun item in questo topic");
    expect(html, "il blocco di testo fisso è reso dal server").toContain('class="pt-static-content"');
    expect(html, "con dentro il suo corpo").toContain("Inizia a comporre la pagina");

    await teacherPage.goto(indirizzoPagina);
    const documentoReso = teacherPage.locator("fm-pt-document");
    await expect(documentoReso, "il componente del documento è in pagina").toBeAttached({ timeout: 30_000 });

    // La barra è la sua: quella dello studio resta fuori.
    const barraPropria = documentoReso.locator(".fm-doc-topbar--custom");
    await expect(barraPropria, "il documento porta la propria barra").toBeVisible({ timeout: 30_000 });
    await expect(
        barraPropria.getByRole("button", { name: /Modifica|Anteprima/ }).first(),
        "con il comando che alterna modifica e anteprima",
    ).toBeVisible();
    await expect(
        barraPropria.locator('[data-action="ptdoc-toggle-render"]'),
        "e quello che pubblica la pagina come HTML statico",
    ).toBeAttached();
    await expect(teacherPage.locator("#fm-topbar"), "la barra dello studio resta nascosta").toBeHidden();

    // L'apparato delle verifiche non viene montato su una pagina che verifica non è.
    await expect(teacherPage.locator("#infoVer"), "niente pannello delle informazioni di stampa").toHaveCount(0);
    await expect(teacherPage.locator("#scrollbarInfo"), "niente barra delle informazioni").toHaveCount(0);
    await expect(teacherPage.locator("body"), "e niente modalità verifica").not.toHaveClass(/fm-verifica-mode/);
});
