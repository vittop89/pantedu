// @ts-check
/**
 * Una verifica prodotta in TeX si condivide nel pool e il collega la vede.
 * Riscrittura di g22_s23_verifica_share_pool.spec.js, fetta 3.
 *
 * Le verifiche vivono in una tabella propria (`verifica_documents`) e nel pool
 * compaiono con `content_type: "verifica_doc"`, distinte dai contenuti del
 * docente. Qui: il docente ne salva una, la condivide, il collega la trova,
 * poi il docente la ritira e il collega non la vede più.
 *
 * Cosa cambia rispetto a prima: niente login né contesti aperti a mano (le due
 * sessioni sono fixture), niente identificativo del proprietario scritto a mano
 * («77»), e le varianti salvate sono cancellate dal registro di pulizia anche
 * se il test fallisce a metà.
 */
const { test, expect } = require("../support/test");

test.describe("Condivisione — verifiche nel pool", () => {
    test("il collega vede la verifica condivisa e non la vede più dopo il ritiro", async ({ verificaFactory, teacherApi, teacher2Api, naming }) => {
        const titolo = naming.unique("verifica-condivisa");
        const batch = await verificaFactory.batch({ title: titolo, versions: ["A"], versionLabel: "condivisa" });
        const documento = batch.docs.find((d) => d.variant === "A_NOR") ?? batch.docs[0];
        expect(documento, "almeno una variante salvata").toBeDefined();
        if (!documento) return;

        const condivisione = await teacherApi.verifica.setPoolSharing(documento.id, true);
        expect(condivisione.status, condivisione.text.slice(0, 200)).toBe(200);
        expect(condivisione.body?.shared_with_pool).toBe(true);

        // Dal 14/9/2026 il pool mostra una voce per verifica: la variante
        // condivisa sta fra gli `ids` della voce.
        const pool = await teacher2Api.share.poolMaterials({ content_type: "verifica_doc" });
        const vista = pool.items.find((i) => i.source === "verifica_documents" && (i.ids ?? [i.id]).includes(documento.id));
        expect(vista, `il collega deve vedere la verifica ${documento.id} nel pool`).toBeTruthy();
        expect(vista?.title, "con il titolo dato dal docente").toContain(titolo);

        const ritiro = await teacherApi.verifica.setPoolSharing(documento.id, false);
        expect(ritiro.status).toBe(200);

        const poolDopo = await teacher2Api.share.poolMaterials({ content_type: "verifica_doc" });
        const ancora = poolDopo.items.find((i) => i.source === "verifica_documents" && (i.ids ?? [i.id]).includes(documento.id));
        expect(ancora, "dopo il ritiro non deve più comparire").toBeFalsy();
    });
});
