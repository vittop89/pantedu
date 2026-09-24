// @ts-check
/**
 * Produzione delle varianti TeX di una verifica e cache del PDF.
 * Riscrittura di verifica_tex_production.spec.js (G22.S3), fetta 2 del
 * refactoring E2E.
 *
 * Verifica, con la stessa copertura di prima:
 *   1. il salvataggio del batch produce le 8 varianti (A/B × SOL/NOR/DSA/DIS)
 *      con le sezioni giuste per tipo;
 *   2. il filtro sulle versioni restituisce solo quelle chieste;
 *   3. un secondo salvataggio con lo stesso titolo dà 409, con `force` sovrascrive;
 *   4. la cache del PDF indirizzata dal contenuto: stesso TeX → compilazione
 *      servita dalla cache; TeX diverso → nessun colpo di cache.
 *
 * Cosa cambia rispetto a prima: niente login nella spec (fixture di sessione),
 * niente `deleteAllVerifiche` nel beforeEach (cancellava, o meglio credeva di
 * cancellare, tutte le verifiche del docente: leggeva `docs` da una risposta
 * che ha `items`); ogni verifica nasce dalla factory con un titolo unico e
 * viene cancellata nel teardown anche se il test fallisce.
 */
const { test, expect } = require("../support/test");

/** PDF minimo valido: basta la firma %PDF- perché il caricamento lo accetti. */
const pdfFinto = () => Buffer.from("%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n", "ascii");

test.describe("Verifiche — produzione delle varianti TeX", () => {
    test("il salvataggio produce le otto varianti con le sezioni giuste per tipo", async ({ verificaFactory, teacherApi }) => {
        const batch = await verificaFactory.batch({ versionLabel: "v1" });
        expect(batch.docs).toHaveLength(8);
        expect(batch.docs.map((d) => d.variant).sort()).toEqual([
            "A_DIS", "A_DSA", "A_NOR", "A_SOL",
            "B_DIS", "B_DSA", "B_NOR", "B_SOL",
        ]);

        /** @type {Record<string, string>} */
        const tex = {};
        for (const doc of batch.docs) tex[doc.variant] = await teacherApi.verifica.texText(doc.tex_url);

        // La griglia di valutazione è inclusa in tutte le varianti (anche SOL).
        for (const variante of Object.keys(tex)) {
            expect(tex[variante], `griglia in ${variante}`).toContain("Griglia di Valutazione");
        }
        // Le varianti per la dislessia usano il carattere dedicato.
        for (const variante of ["A_DIS", "B_DIS"]) {
            expect(tex[variante], `font in ${variante}`).toContain("OpenDyslexic");
        }
        // Con la compensazione chiesta, DSA e DIS portano la nota in calce.
        for (const variante of ["A_DSA", "A_DIS", "B_DSA", "B_DIS"]) {
            expect(tex[variante], `compensazione in ${variante}`).toContain("Compensazione orale");
        }
        expect(tex["A_NOR"], "nessuna compensazione nella variante ordinaria").not.toContain("Compensazione orale");
    });

    test("il filtro sulle versioni restituisce solo le varianti chieste", async ({ verificaFactory }) => {
        const batch = await verificaFactory.batch({ versions: ["A"], versionLabel: "v1" });
        const varianti = batch.docs.map((d) => d.variant).sort();
        expect(varianti).toEqual(["A_DIS", "A_DSA", "A_NOR", "A_SOL"]);
    });

    test("un titolo già usato dà conflitto, con force sovrascrive", async ({ verificaFactory, teacherApi, naming }) => {
        const titolo = naming.unique("conflitto");
        const payload = verificaFactory.basePayload({ title: titolo, verTitle: titolo, version_label: "v1" });

        const primo = await teacherApi.verifica.saveTexBatchOk(payload);
        verificaFactory.registerDeletion(primo.docs.map((d) => d.id), "variante del primo salvataggio");
        const idPrimo = primo.docs.map((d) => d.id).sort();

        const duplicato = await teacherApi.verifica.saveTexBatch(payload);
        expect(duplicato.status).toBe(409);
        const conflitto = /** @type {{ error: string, conflict: { existing_ids: number[] } }} */ (duplicato.body);
        expect(conflitto.error).toBe("verifica_version_conflict");
        expect(conflitto.conflict.existing_ids.length).toBeGreaterThan(0);

        const forzato = await teacherApi.verifica.saveTexBatchOk(payload, { force: true });
        verificaFactory.registerDeletion(forzato.docs.map((d) => d.id), "variante del salvataggio forzato");
        // Le righe precedenti sono state cancellate e ricreate: gli id cambiano.
        expect(forzato.docs.map((d) => d.id).sort()).not.toEqual(idPrimo);
    });

    test("con lo stesso TeX la compilazione è servita dalla cache", async ({ verificaFactory, teacherApi, naming }) => {
        const titolo = naming.unique("cache");
        const primo = await verificaFactory.batch({ title: titolo, versionLabel: "cache-v1" });
        const primaNor = primo.docs.find((d) => d.variant === "A_NOR");
        expect(primaNor, "variante A_NOR nel primo salvataggio").toBeDefined();
        if (!primaNor) return;

        const caricamento = await teacherApi.verifica.uploadPdf(primaNor.id, "cache-test.pdf", pdfFinto());
        expect(caricamento.status).toBe(200);

        // Stesso contenuto, etichetta diversa: righe nuove con la stessa impronta del TeX.
        const secondo = await verificaFactory.batch({ title: titolo, versionLabel: "cache-v2" });
        const secondaNor = secondo.docs.find((d) => d.variant === "A_NOR");
        expect(secondaNor, "variante A_NOR nel secondo salvataggio").toBeDefined();
        if (!secondaNor) return;
        expect(secondaNor.id).not.toBe(primaNor.id);

        const compilazione = await teacherApi.verifica.compile(secondaNor.id);
        expect(compilazione.status).toBe(200);
        const esito = /** @type {{ ok: boolean, compile?: { cache_hit?: boolean, engine?: string, duration_ms?: number } }} */ (compilazione.body);
        expect(esito.ok).toBe(true);
        expect(esito.compile?.cache_hit).toBe(true);
        expect(esito.compile?.engine).toBe("cache");
        expect(esito.compile?.duration_ms).toBe(0);
    });

    test("con un TeX diverso la cache non risponde", async ({ verificaFactory, teacherApi, naming }) => {
        const titolo = naming.unique("cache-miss");
        const primo = await verificaFactory.batch({ title: `${titolo} A`, versionLabel: "v1" });
        const primaNor = primo.docs.find((d) => d.variant === "A_NOR");
        expect(primaNor).toBeDefined();
        if (!primaNor) return;
        await teacherApi.verifica.uploadPdf(primaNor.id, "miss-test.pdf", pdfFinto());

        // Testo del problema diverso → impronta del TeX diversa.
        const secondo = await verificaFactory.batch({
            title: `${titolo} B`,
            versionLabel: "v1",
            overrides: {
                problems: [{
                    filePath: "/eser/ar/ar2s/MAT/1",
                    problemId: "problem-201",
                    position: 1,
                    type: "Collect",
                    text: "DIVERSO: risolvi:",
                    items: [{ html: "Item alternativo", points: 5.0, includeSolution: false }],
                }],
            },
        });
        const secondaNor = secondo.docs.find((d) => d.variant === "A_NOR");
        expect(secondaNor).toBeDefined();
        if (!secondaNor) return;

        const compilazione = await teacherApi.verifica.compile(secondaNor.id);
        const esito = /** @type {{ ok?: boolean, compile?: { cache_hit?: boolean } }} */ (compilazione.body);
        if (compilazione.status === 200 && esito?.ok) {
            // Servizio TeX attivo: compilazione vera, nessun colpo di cache.
            expect(esito.compile?.cache_hit).toBe(false);
        } else {
            // Servizio TeX spento: l'esito è comunque un rifiuto, non una cache.
            expect([502, 503, 422]).toContain(compilazione.status);
        }
    });
});
