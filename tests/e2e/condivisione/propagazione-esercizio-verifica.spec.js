// @ts-check
/**
 * La condivisione nel pool si propaga fra esercizio e verifica abbinati.
 * Riscrittura di g22_s23_share_pool_propagation.spec.js, fetta 3.
 *
 * L'app considera abbinati un esercizio e una verifica della stessa materia
 * quando l'argomento della verifica coincide con il titolo dell'esercizio:
 * chi apre l'esercizio vede anche i quesiti della verifica, e il comando
 * «Condividi» deve agire su entrambi.
 *
 * Cosa cambia rispetto a prima: la coppia è creata dalla spec invece di essere
 * quella scoperta dal setup, che altre spec leggono e che restava condivisa o
 * no a seconda di come finiva questo test.
 */
const { test, expect } = require("../support/test");

test.describe("Condivisione — propagazione fra esercizio e verifica", () => {
    test("condividere l'esercizio condivide la verifica abbinata, e ritirare la verifica ritira l'esercizio", async ({ contentFactory, shareFactory, teacherApi }) => {
        const coppia = await contentFactory.pair();

        // Condivisione dell'esercizio: la risposta dichiara il contenuto abbinato.
        const esercizioCondiviso = await shareFactory.setPoolSharing(coppia.esercizio.id, true, { ripristina: false });
        expect(esercizioCondiviso.shared_with_pool).toBe(true);
        expect(esercizioCondiviso.counterpart_id, "la verifica abbinata è dichiarata").toBe(coppia.verificaId);
        expect(esercizioCondiviso.counterpart_type).toBe("verifica");

        const verifica = await teacherApi.content.get(coppia.verificaId);
        expect(verifica.content.shared_with_pool, "la verifica risulta condivisa").toBeTruthy();

        // Ritiro dalla verifica: l'esercizio la segue.
        const verificaRitirata = await shareFactory.setPoolSharing(coppia.verificaId, false, { ripristina: false });
        expect(verificaRitirata.shared_with_pool).toBe(false);
        expect(verificaRitirata.counterpart_id).toBe(coppia.esercizio.id);
        expect(verificaRitirata.counterpart_type).toBe("esercizio");

        const esercizio = await teacherApi.content.get(coppia.esercizio.id);
        expect(esercizio.content.shared_with_pool, "l'esercizio risulta ritirato").toBeFalsy();
    });
});
