// @ts-check
/**
 * Recupero di un contenuto dal pool e confini fra docenti.
 * Riscrittura di g22_s22_pool_recover_ownership.spec.js, fetta 3.
 *
 * Verifica, con la stessa copertura di prima:
 *   1. il collega vede nel pool l'esercizio condiviso, con la materia giusta;
 *   2. chi lo recupera ne ottiene una copia propria;
 *   3. l'autore, guardando la sua terna, vede solo i propri contenuti: la copia
 *      del collega non compare (un super-amministratore che è anche docente
 *      resta un docente per i permessi);
 *   4. l'esercizio condiviso conserva il riferimento al proprio contratto.
 *
 * Cosa cambia rispetto a prima: l'esercizio è creato dalla spec invece di
 * cercare «Sistemi lineari» nel dump locale, gli identificativi dei due docenti
 * si ricavano dal curriculum invece di essere scritti a mano (77 e 140), e la
 * copia recuperata dal collega viene cancellata alla fine.
 */
const { test, expect } = require("../support/test");

test.describe("Condivisione — recupero dal pool e confini fra docenti", () => {
    test("il collega vede e recupera l'esercizio condiviso, l'autore non vede la copia", async ({
        contentFactory, shareFactory, teacher2ShareFactory, teacherApi, teacher2Api,
    }) => {
        const esercizio = await contentFactory.exercise({ publish: true });
        await shareFactory.setPoolSharing(esercizio.id, true, { ripristina: false });

        const idDocente = await teacherApi.curriculum.teacherId();
        const idCollega = await teacher2Api.curriculum.teacherId();
        expect(idDocente, "i due docenti sono utenti diversi").not.toBe(idCollega);

        // 1. Il collega lo trova nel pool, con l'autore e la materia giusti.
        const pool = await teacher2Api.share.poolMaterials({ content_type: "esercizio" });
        const voce = pool.items.find((i) => i.source === "teacher_content" && i.id === esercizio.id);
        expect(voce, `l'esercizio ${esercizio.id} deve comparire nel pool del collega`).toBeTruthy();
        expect(voce?.owner_id).toBe(idDocente);
        expect(voce?.subject_code).toBe(esercizio.terna.materia);
        expect(voce?.already_recovered, "non ancora recuperato").toBeFalsy();

        // 2. Il collega lo recupera: ne ottiene una copia propria.
        const copiaId = await teacher2ShareFactory.recoverFromPool(esercizio.id, esercizio.terna.materia);
        expect(copiaId).toBeGreaterThan(0);
        expect(copiaId).not.toBe(esercizio.id);
        const copia = await teacher2Api.content.get(copiaId);
        expect(copia.content.id).toBe(copiaId);

        // 3. L'autore, nella sua terna, vede solo i propri contenuti.
        const { indirizzo, classe, materia } = esercizio.terna;
        /** @type {{ rows?: ReadonlyArray<{ id: number, teacher_id: number, title: string }> }} */
        const studio = await teacherApi.http.getJson("/api/study/content.json", {
            type: "esercizio", subject: materia, indirizzo, classe,
        });
        const righe = studio.rows ?? [];
        const altrui = righe.filter((r) => r.teacher_id !== idDocente);
        expect(altrui, `righe di altri docenti: ${altrui.map((r) => r.id).join(", ")}`).toEqual([]);
        expect(righe.some((r) => r.id === esercizio.id), "il proprio esercizio c'è").toBe(true);
        expect(righe.some((r) => r.id === copiaId), "la copia del collega non c'è").toBe(false);
    });

    test("l'esercizio condiviso conserva il riferimento al proprio contratto", async ({ contentFactory, teacherApi }) => {
        const esercizio = await contentFactory.exercise({ publish: true });

        const dettaglio = await teacherApi.content.get(esercizio.id);
        const metadati = dettaglio.content.metadata;
        const oggetto = typeof metadati === "string" ? JSON.parse(metadati) : metadati;
        const chiave = oggetto?.contract_key;
        expect(chiave, "contract_key presente nei metadati").toBeTruthy();
        expect(String(chiave)).toContain("eser");
        expect(String(chiave)).toContain(".contract.json");
    });
});
