// @ts-check
/**
 * Come il server rende un documento: la struttura scelta e il corpo.
 * Riscrittura di risdoc_pt_layout_render.spec.js e risdoc_pt_studio_preview.spec.js.
 *
 * La stessa rotta serve documenti fatti in due modi. Con la struttura
 * «esercizi» la pagina arriva con l'impalcatura dello studio — l'intestazione e
 * il contenitore dei gruppi trascinabili — perché lì dentro si lavora sui
 * quesiti. Con la struttura libera quell'impalcatura non serve e non c'è: c'è
 * l'involucro del documento, che la sostituisce.
 *
 * Il corpo strutturato, in entrambi i casi, lo rende il server: titoli,
 * grassetti e caselle arrivano già disegnati nell'HTML.
 *
 * Cosa cambia rispetto a prima: niente login né gettone a mano, i documenti
 * nascono dalla factory e la loro cancellazione è registrata, e l'indirizzo
 * della pagina viene dalla terna del setup invece che scritto a mano.
 */
const { test, expect } = require("../support/test");

const CORPO = [
    { _type: "sectionHeader", level: 1, text: "Esercizi per studenti" },
    { _type: "block", style: "normal", children: [{ _type: "span", text: "CORPO_DEL_DOCUMENTO", marks: [] }] },
];

/** Indirizzo della pagina di un documento nella terna del setup. */
const indirizzoDocumento = (/** @type {{indirizzo: string, classe: string, materia: string}} */ terna, /** @type {string} */ topic) =>
    `/studio/document/${terna.indirizzo}/${terna.classe}/${terna.materia}/${encodeURIComponent(topic)}`;

test.describe("Risdoc — come il server rende il documento", () => {
    test("con la struttura libera la pagina non porta l'impalcatura dello studio", async ({ contentFactory, teacherApi, env }) => {
        const documento = await contentFactory.document({
            terna: env.terna,
            visibility: "published",
            metadata: { layout: "custom" },
            bodyPt: CORPO,
        });

        const risposta = await teacherApi.http.request.get(indirizzoDocumento(env.terna, documento.topic));
        expect(risposta.ok(), "la pagina si apre").toBe(true);
        const html = await risposta.text();

        expect(html, "niente intestazione dello studio").not.toContain('id="header_page"');
        expect(html, "niente contenitore dei gruppi trascinabili").not.toContain("fm-draggable-container");
        expect(html, "c'è l'involucro del documento a struttura libera").toContain("fm-pt-custom-page");
        expect(html, "e la pagina dichiara la struttura che ha").toContain('data-layout="custom"');
        expect(html, "il corpo è reso").toContain("CORPO_DEL_DOCUMENTO");
    });

    test("con la struttura «esercizi» l'impalcatura dello studio resta", async ({ contentFactory, teacherApi, env }) => {
        const documento = await contentFactory.document({
            terna: env.terna,
            visibility: "published",
            metadata: { layout: "exercises" },
            bodyPt: CORPO,
        });

        const risposta = await teacherApi.http.request.get(indirizzoDocumento(env.terna, documento.topic));
        expect(risposta.ok(), "la pagina si apre").toBe(true);
        const html = await risposta.text();

        expect(html, "c'è l'intestazione dello studio").toContain('id="header_page"');
        expect(html, "e il contenitore dei gruppi trascinabili").toContain("fm-draggable-container");
        expect(html, "niente involucro della struttura libera").not.toContain("fm-pt-custom-page");
        expect(html, "il corpo è reso lo stesso").toContain("CORPO_DEL_DOCUMENTO");
    });

    test("titoli, grassetti e caselle del corpo arrivano già disegnati", async ({ contentFactory, teacherApi, env }) => {
        const documento = await contentFactory.document({
            terna: env.terna,
            visibility: "published",
            bodyPt: [
                { _type: "sectionHeader", title: "TITOLO_DI_SEZIONE", level: 2 },
                {
                    _type: "block",
                    style: "normal",
                    children: [
                        { _type: "span", text: "Frase con ", marks: [] },
                        { _type: "span", text: "PAROLA_IN_NERO", marks: ["strong"] },
                        { _type: "span", text: " dentro.", marks: [] },
                    ],
                },
                {
                    // «all»: si vedono tutte le voci, spuntate e no.
                    _type: "checkboxGroup",
                    renderMode: "all",
                    items: [
                        { state: "x", label: "VOCE_SPUNTATA" },
                        { state: "_", label: "VOCE_LIBERA" },
                    ],
                },
            ],
        });

        const risposta = await teacherApi.http.request.get(indirizzoDocumento(env.terna, documento.topic));
        expect(risposta.ok(), "la pagina si apre").toBe(true);
        const html = await risposta.text();

        expect(html, "il contenuto è dichiarato come corpo strutturato").toContain('data-source="pt"');
        expect(html, "il titolo di sezione").toContain("TITOLO_DI_SEZIONE");
        expect(html, "il grassetto è reso come tale").toMatch(/<strong>PAROLA_IN_NERO<\/strong>/);
        expect(html, "la voce spuntata").toContain("VOCE_SPUNTATA");
        expect(html, "e quella libera").toContain("VOCE_LIBERA");
        expect(html, "la spunta si vede accanto alla voce giusta").toMatch(/☑[^<]*<\/span>\s*VOCE_SPUNTATA/);
    });
});
