// @ts-check
/**
 * Il documento personalizzabile che diventa TeX, PDF e archivio. @tex
 * Riscrittura di pt_document_texpdf.spec.js.
 *
 * La barra del documento porta gli stessi comandi della verifica: «TeX/PDF»
 * apre l'anteprima con il sorgente e il documento compilato, «ZIP» ne prepara
 * l'archivio, «VSCode» lo apre nell'editor locale. Il sorgente non è scritto a
 * mano: nasce dal corpo strutturato del documento.
 *
 * Cosa cambia rispetto a prima: le tre password lette dall'ambiente non ci
 * sono più, i documenti di prova li cancella la factory, e l'anteprima si apre
 * per davvero invece di essere sostituita con una funzione finta che ne
 * registrava soltanto la chiamata.
 */
const { test, expect } = require("../support/test");

const CORPO = [
    { _type: "sectionHeader", title: "Capitolo uno", level: 2 },
    { _type: "block", style: "normal", children: [{ _type: "span", text: "Contenuto della sezione.", marks: [] }] },
];

/** @param {import("@playwright/test").Page} pagina */
async function attendiLaBarra(pagina) {
    await pagina.waitForFunction(
        () => !!document.querySelector("fm-pt-document .fm-doc-topbar--custom"),
        null,
        { timeout: 30_000 },
    );
}

test.describe("Risorse docente — documento personalizzabile in TeX", () => {
    test("la barra porta i comandi del documento, e «ZIP» ne prepara l'archivio", async ({
        contentFactory, teacherPage,
    }) => {
        const documento = await contentFactory.document({ bodyPt: CORPO, metadata: { layout: "custom" } });
        await teacherPage.goto(documento.studioUrl);
        await attendiLaBarra(teacherPage);

        const barra = teacherPage.locator("fm-pt-document .fm-doc-topbar--custom");
        const comandi = (await barra.locator(".fm-doc-topbar__btn").allInnerTexts()).join(" | ");
        expect(comandi, "il comando TeX/PDF").toContain("TeX/PDF");
        expect(comandi, "e quello dell'archivio").toContain("ZIP");
        await expect(
            barra.locator('.fm-doc-topbar__btn[data-action="ptdoc-vscode"] .fm-doc-topbar__logo'),
            "e quello per aprirlo nell'editor locale, con il suo segno",
        ).toBeAttached();

        const archivio = teacherPage.waitForResponse(
            (r) => r.url().includes(`/content/${documento.id}/export`) && r.request().method() === "POST",
            { timeout: 60_000 },
        );
        await barra.locator(".fm-doc-topbar__btn").filter({ hasText: /^\s*ZIP\s*$/ }).first().click();
        expect((await archivio).ok(), "l'archivio viene preparato").toBe(true);
    });

    test("«TeX/PDF» apre l'anteprima sul documento @tex", async ({ contentFactory, teacherPage }) => {
        const documento = await contentFactory.document({ bodyPt: CORPO, metadata: { layout: "custom" } });
        await teacherPage.goto(documento.studioUrl);
        await attendiLaBarra(teacherPage);

        // Il modulo dell'anteprima non è caricato dalla pagina del documento:
        // lo carica il comando, al momento. Era il difetto per cui il comando
        // funzionava solo dove l'editor era già stato caricato.
        await teacherPage.locator('fm-pt-document .fm-doc-topbar__btn[data-action="ptdoc-tex"]').click();
        await expect(
            teacherPage.locator(".fm-vp-modal"),
            "l'anteprima si apre anche qui",
        ).toBeVisible({ timeout: 120_000 });
        await expect(
            teacherPage.locator(".fm-vp-editor-host .cm-editor"),
            "con il sorgente del documento",
        ).toBeVisible({ timeout: 120_000 });
    });

    test("il sorgente del documento nasce dal suo corpo strutturato @tex", async ({
        contentFactory, teacherApi,
    }) => {
        test.setTimeout(240_000);
        const documento = await contentFactory.document({ bodyPt: CORPO, metadata: { layout: "custom" } });

        const pacchetto = await teacherApi.http.postForm(`/api/teacher/content/${documento.id}/tex-files`, {});
        expect(pacchetto["ok"], "il pacchetto viene costruito").toBe(true);

        const file = /** @type {{ path: string, content: string }[]} */ (pacchetto["files"] ?? []);
        const percorsi = file.map((f) => f.path);
        expect(percorsi.some((p) => /\.tex$/.test(p) && p !== "main.tex"), "c'è il file del corpo").toBe(true);
        expect(
            file.some((f) => /Capitolo uno/.test(f.content) && /Contenuto della sezione/.test(f.content)),
            "e dentro c'è quel che il docente ha scritto",
        ).toBe(true);

        const principale = file.find((f) => f.path === "main.tex");
        expect(principale, "e il documento principale che lo include").toBeDefined();
        expect(principale?.content ?? "", "che richiama il corpo").toMatch(/\\input\{/);
    });
});
