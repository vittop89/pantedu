// @ts-check
/**
 * Dopo il recupero dal pool, il collega vede la verifica correlata nell'esercizio.
 * Riscrittura di g22_s25_docente2_browser_related_verifica.spec.js, fetta 3.
 *
 * Quando un docente apre un esercizio, l'app carica in fondo alla pagina i
 * quesiti della verifica abbinata (stessa materia, argomento uguale al titolo).
 * Qui si verifica che questo valga anche per una coppia recuperata dal pool:
 * il collega prende esercizio e verifica dal pool e, aprendo la sua copia
 * dell'esercizio, deve trovare la verifica correlata.
 *
 * Cosa cambia rispetto a prima: la coppia è creata dalla spec, il collega ha la
 * sua sessione dalla fixture, le copie recuperate sono cancellate alla fine, e
 * al posto dei dump diagnostici (console, rete, HTML salvato su file) c'è la
 * diagnostica automatica allegata al report solo quando il test fallisce.
 */
const { test, expect } = require("../support/test");

test.describe("Condivisione — verifica correlata dopo il recupero", () => {
    test("il collega apre la copia dell'esercizio e trova la verifica correlata", async ({
        contentFactory, shareFactory, teacher2ShareFactory, teacher2Api, studioEsercizioCollega,
    }) => {
        const coppia = await contentFactory.pair();
        await shareFactory.setPoolSharing(coppia.esercizio.id, true, { ripristina: false });

        // Il collega recupera entrambi: l'esercizio e la verifica che gli è abbinata.
        // Il recupero assegna solo la materia e lascia la copia «da categorizzare»
        // (senza indirizzo né classe): il docente le dà una collocazione, come
        // farebbe dalla pagina dei contenuti da categorizzare, altrimenti la
        // pagina di studio di quella terna non la elenca.
        const { indirizzo, classe, materia } = coppia.esercizio.terna;
        /** @type {number[]} */
        const copie = [];
        for (const [tipo, id] of /** @type {const} */ ([["esercizio", coppia.esercizio.id], ["verifica", coppia.verificaId]])) {
            const pool = await teacher2Api.share.poolMaterials({ content_type: tipo });
            const voce = pool.items.find((i) => i.source === "teacher_content" && i.id === id);
            expect(voce, `${tipo} ${id} nel pool del collega`).toBeTruthy();
            const copiaId = await teacher2ShareFactory.recoverFromPool(id, materia);
            await teacher2Api.content.update(copiaId, { indirizzo, classe, visibility: "published" });
            copie.push(copiaId);
        }

        const [copiaEsercizio] = copie;
        expect(copiaEsercizio, "copia dell'esercizio").toBeGreaterThan(0);
        if (copiaEsercizio === undefined) return;
        const copia = (await teacher2Api.content.get(copiaEsercizio)).content;
        expect(copia.title, "la copia porta il titolo dell'originale").toContain(coppia.esercizio.title);

        await studioEsercizioCollega.vaiA(
            `/studio/esercizio/${indirizzo}/${classe}/${materia}/${encodeURIComponent(String(copia.topic))}?ids=${copiaEsercizio}`,
        );

        // La sezione della verifica correlata è caricata in fondo alla pagina.
        const correlata = studioEsercizioCollega.page.locator("#type_verAll");
        await expect(correlata, "sezione della verifica correlata").toBeAttached({ timeout: 30_000 });
        await expect(correlata.locator(".fm-contract-wrap[data-id]").first(), "almeno una verifica correlata").toBeAttached({ timeout: 30_000 });
    });
});
