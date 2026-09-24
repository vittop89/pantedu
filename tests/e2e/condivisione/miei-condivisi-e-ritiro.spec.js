// @ts-check
/**
 * «I miei contenuti condivisi» e il ritiro in blocco.
 * Riscrittura di g22_s24_my_shares_unshare.spec.js, fetta 3.
 *
 * Verifica che l'elenco dei propri contenuti condivisi comprenda anche quelli
 * condivisi per propagazione (la verifica abbinata all'esercizio) e che il
 * ritiro in blocco li tolga entrambi.
 *
 * Cosa cambia rispetto a prima: la coppia è creata dalla spec, quindi il test
 * non lascia la coppia condivisa dal setup in uno stato diverso da come l'ha
 * trovata, e l'elenco si verifica sui propri contenuti invece che su tutto
 * quello che il docente ha condiviso negli anni.
 */
const { test, expect } = require("../support/test");

test.describe("Condivisione — elenco dei propri contenuti e ritiro in blocco", () => {
    test("l'elenco comprende la coppia condivisa e il ritiro in blocco la toglie", async ({ contentFactory, shareFactory, teacherApi }) => {
        const coppia = await contentFactory.pair();
        await shareFactory.setPoolSharing(coppia.esercizio.id, true, { ripristina: false });

        const elenco = await teacherApi.share.myShares();
        const riferimenti = elenco.items.map((i) => `${i.source}:${i.id}`);
        expect(riferimenti, "l'esercizio condiviso è nell'elenco").toContain(`teacher_content:${coppia.esercizio.id}`);
        expect(riferimenti, "e con lui la verifica abbinata").toContain(`teacher_content:${coppia.verificaId}`);

        const ritiro = await teacherApi.share.unshare([
            { source: "teacher_content", id: coppia.esercizio.id },
            { source: "teacher_content", id: coppia.verificaId },
        ]);
        expect(ritiro.ok).toBe(true);
        expect(ritiro.total).toBeGreaterThanOrEqual(2);

        const dopo = await teacherApi.share.myShares();
        const rimasti = dopo.items.map((i) => `${i.source}:${i.id}`);
        expect(rimasti).not.toContain(`teacher_content:${coppia.esercizio.id}`);
        expect(rimasti).not.toContain(`teacher_content:${coppia.verificaId}`);
    });
});
