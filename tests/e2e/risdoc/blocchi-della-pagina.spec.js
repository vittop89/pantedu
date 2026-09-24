// @ts-check
/**
 * I cinque tipi di blocco del corpo strutturato: salvati e riletti integri.
 * Riscrittura di page_doc_blocks_all.spec.js e page_doc_glossary_table.spec.js.
 *
 * Il corpo di un documento è una lista di blocchi. Ognuno ha una forma sua —
 * la tabella del glossario ha colonne e voci, la fisarmonica ha sezioni che si
 * aprono, l'elenco di rimandi ha voci con sotto-voci — e ognuno deve tornare
 * dal database come è stato salvato, senza che campi si perdano per strada.
 * Quando si perdono non si vede subito: il documento si apre lo stesso, con
 * dentro un blocco a metà.
 *
 * L'ultimo test guarda la pagina resa dal server: i valori delle celle non
 * devono poter uscire dalla tabella e diventare marcatura.
 *
 * Cosa cambia rispetto a prima: niente login né gettone di sicurezza a mano in
 * ogni test, i documenti nascono dalla factory e la loro cancellazione è
 * registrata (prima era un `finally` che saltava se la creazione falliva), e i
 * metadati si leggono con una chiamata sola invece che con la decodifica
 * ripetuta in ogni test.
 */
const { test, expect } = require("../support/test");

/** Legge il primo blocco del corpo strutturato di un documento appena creato. */
async function primoBlocco(/** @type {any} */ teacherApi, /** @type {number} */ id) {
    const metadati = await teacherApi.content.metadata(id);
    const corpo = metadati["body_pt"];
    expect(Array.isArray(corpo), "il corpo strutturato è una lista di blocchi").toBe(true);
    return /** @type {Record<string, any>} */ (corpo[0]);
}

test.describe("Risdoc — blocchi del corpo strutturato", () => {
    test("la tabella del glossario torna con colonne, voci e opzioni", async ({ contentFactory, teacherApi, naming }) => {
        const documento = await contentFactory.document({
            title: naming.unique("glossario"),
            metadata: { category: "ALTRO", layout: "custom" },
            bodyPt: [{
                _type: "glossaryTable",
                name: "glossario_lemmi",
                columns: ["N.", "Lemma", "Definizione", "Fonte"],
                entries: [
                    { n: 1, lemma: "Abilità", definizione: "Capacità di applicare conoscenze.", fonte: "Racc. UE 2008/C 111/01" },
                    { n: 2, lemma: "Apprendimento formale", definizione: "Erogato da istituzione strutturata.", fonte: "COM 2001/678" },
                    { n: 3, lemma: "DSA", definizione: "Disturbi Specifici di Apprendimento.", fonte: "L. 170/2010" },
                ],
                sortable: true,
                searchable: true,
            }],
        });

        const blocco = await primoBlocco(teacherApi, documento.id);
        expect(blocco._type).toBe("glossaryTable");
        expect(blocco.columns, "le quattro colonne").toEqual(["N.", "Lemma", "Definizione", "Fonte"]);
        expect(blocco.entries.length, "le tre voci").toBe(3);
        expect(blocco.entries[0].lemma, "gli accenti non si perdono").toBe("Abilità");
        expect(blocco.entries[2].fonte).toBe("L. 170/2010");
        expect(blocco.sortable, "ordinabile").toBe(true);
        expect(blocco.searchable, "ricercabile").toBe(true);
    });

    test("il blocco di testo fisso conserva la marcatura e le sotto-sezioni", async ({ contentFactory, teacherApi, naming }) => {
        const documento = await contentFactory.document({
            title: naming.unique("testo-fisso"),
            metadata: { category: "ALTRO", layout: "custom" },
            bodyPt: [{
                _type: "staticContent",
                title: "PARTE I — Norme generali",
                level: 2,
                format: "html",
                body: "<p>Testo introduttivo</p><ul><li>Punto 1</li><li>Punto 2</li></ul>",
                items: [
                    { _type: "staticContent", title: "A. Registrazione voti", level: 3, body: "<p>Sub-sezione</p>" },
                ],
            }],
        });

        const blocco = await primoBlocco(teacherApi, documento.id);
        expect(blocco._type).toBe("staticContent");
        expect(blocco.title).toBe("PARTE I — Norme generali");
        expect(blocco.level, "livello del titolo").toBe(2);
        expect(blocco.body, "la marcatura del corpo").toMatch(/<p>Testo introduttivo<\/p>/);
        expect(blocco.items.length, "una sotto-sezione").toBe(1);
        expect(blocco.items[0].title).toBe("A. Registrazione voti");
        expect(blocco.items[0].level, "la sotto-sezione ha il suo livello").toBe(3);
    });

    test("la fisarmonica conserva le sue sezioni, con dentro il loro corpo", async ({ contentFactory, teacherApi, naming }) => {
        const testo = (/** @type {string} */ t) => [{ _type: "block", style: "normal", children: [{ _type: "span", text: t, marks: [] }] }];
        const documento = await contentFactory.document({
            title: naming.unique("fisarmonica"),
            metadata: { category: "ALTRO", layout: "custom" },
            bodyPt: [{
                _type: "accordion",
                allow_multiple: false,
                items: [
                    { title: "A. Registrazione voti", body_pt: testo("Corpo A"), default_open: true },
                    { title: "B. Recuperi", body_pt: testo("Corpo B"), default_open: false },
                ],
            }],
        });

        const blocco = await primoBlocco(teacherApi, documento.id);
        expect(blocco._type).toBe("accordion");
        expect(blocco.allow_multiple, "una sezione aperta per volta").toBe(false);
        expect(blocco.items.length).toBe(2);
        expect(blocco.items[0].title).toBe("A. Registrazione voti");
        expect(blocco.items[0].default_open, "la prima si apre da sola").toBe(true);
        expect(blocco.items[0].body_pt[0].children[0].text, "il corpo annidato").toBe("Corpo A");
    });

    test("l'elenco di rimandi conserva le sotto-voci e i collegamenti esterni", async ({ contentFactory, teacherApi, naming }) => {
        const documento = await contentFactory.document({
            title: naming.unique("rimandi"),
            metadata: { category: "ALTRO", layout: "custom" },
            bodyPt: [{
                _type: "linkListPdf",
                title: "Scuola dell'infanzia e primo ciclo",
                items: [
                    {
                        label: "Indicazioni nazionali DM 254/2012",
                        href: "/strcomp_bes_altro/ALTRO/linee_guida/primo_ciclo/509.pdf",
                        external: false,
                        description: "Curricolo infanzia/primaria/secondaria I grado",
                        sub_items: [{ label: "Allegato A", href: "/allegato_a.pdf", external: false }],
                    },
                    { label: "MIUR portal", href: "https://www.mim.gov.it/", external: true },
                ],
            }],
        });

        const blocco = await primoBlocco(teacherApi, documento.id);
        expect(blocco._type).toBe("linkListPdf");
        expect(blocco.title).toBe("Scuola dell'infanzia e primo ciclo");
        expect(blocco.items.length).toBe(2);
        expect(blocco.items[0].label).toMatch(/DM 254\/2012/);
        expect(blocco.items[0].sub_items?.length, "la sotto-voce").toBe(1);
        expect(blocco.items[1].external, "il rimando esterno resta segnato come tale").toBe(true);
    });

    test("la citazione di una norma conserva tutti i suoi campi", async ({ contentFactory, teacherApi, naming }) => {
        const documento = await contentFactory.document({
            title: naming.unique("citazione"),
            metadata: { category: "ALTRO", layout: "custom" },
            bodyPt: [{
                _type: "citationNorma",
                tipo: "DM",
                numero: "5669",
                anno: 2011,
                articolo: "Art. 4 c. 2",
                title: "Linee Guida DSA",
                href: "/strcomp_bes_altro/ALTRO/linee_guida/BES/prot5669_11.pdf",
                quote: "Gli strumenti compensativi devono essere riconosciuti dalla scuola...",
            }],
        });

        const blocco = await primoBlocco(teacherApi, documento.id);
        expect(blocco._type).toBe("citationNorma");
        expect(blocco.tipo).toBe("DM");
        expect(blocco.numero).toBe("5669");
        expect(String(blocco.anno), "l'anno, comunque sia tornato").toBe("2011");
        expect(blocco.articolo).toBe("Art. 4 c. 2");
        expect(blocco.quote).toMatch(/strumenti compensativi/);
    });

    test("nella pagina resa, i valori delle celle non diventano marcatura", async ({ contentFactory, teacherApi, env }) => {
        const { indirizzo, classe, materia } = env.terna;
        const documento = await contentFactory.document({
            terna: env.terna,
            visibility: "published",
            metadata: { category: "ALTRO", layout: "custom" },
            bodyPt: [{
                _type: "glossaryTable",
                columns: ["N.", "Lemma", "Definizione", "Fonte"],
                entries: [
                    { n: 1, lemma: "<script>alert(1)</script>", definizione: "<img src=x onerror=alert(2)>", fonte: "javascript:alert(3)" },
                    { n: 2, lemma: "OK Lemma", definizione: "OK Definizione & ampersand", fonte: "Fonte normale" },
                ],
            }],
        });

        const pagina = await teacherApi.http.request.get(
            `/studio/document/${indirizzo}/${classe}/${materia}/${encodeURIComponent(documento.topic)}`,
        );
        expect(pagina.ok(), "la pagina del documento si apre").toBe(true);
        const html = await pagina.text();

        expect(html, "la tabella è resa dal server").toContain('class="pt-glossary-table"');
        expect(html, "con la sua didascalia").toMatch(/<caption[^>]*class="pt-glossary-caption"[^>]*>Glossario \(2 voci\)<\/caption>/);
        expect(html, "e le intestazioni dichiarate come tali").toMatch(/<th scope="col"[^>]*>N\.<\/th>/);

        // Quel che sta nelle celle resta testo: niente tag veri nel documento.
        expect(html, "nessuno script eseguibile").not.toMatch(/<td><script>alert\(1\)<\/script><\/td>/);
        expect(html, "nessun'immagine con gestore d'errore").not.toMatch(/<img[^>]*onerror=/);
        expect(html, "l'immagine arriva come testo").toMatch(/&lt;img src=x onerror=alert\(2\)&gt;/);
        expect(html, "e così lo script").toMatch(/&lt;script&gt;alert\(1\)&lt;\/script&gt;/);
        expect(html, "la e commerciale resta una e commerciale").toMatch(/OK Definizione &amp; ampersand/);
    });
});
