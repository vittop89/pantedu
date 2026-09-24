// @ts-check
/**
 * Condivisione mirata di un contenuto: grant diretto a un collega e grant a un
 * gruppo di colleghi. Riscrittura di g22_s25_granular_share.spec.js, fetta 2
 * del refactoring E2E.
 *
 * Verifica, con la stessa copertura di prima: un contenuto non condiviso nel
 * pool diventa visibile al collega quando riceve un grant esplicito (diretto o
 * tramite un gruppo) e sparisce di nuovo quando il grant è revocato.
 *
 * Cosa cambia rispetto a prima: l'esercizio è creato dalla spec invece di
 * mutare la coppia scoperta dal setup (che altre spec leggono), l'identificativo
 * del collega si chiede all'app invece di essere scritto a mano, i due ruoli
 * arrivano dalle fixture di sessione, e gruppo, grant ed esercizio sono
 * cancellati dal registro di pulizia anche se il test fallisce.
 */
const { test, expect } = require("../support/test");

/**
 * Cerca il contenuto fra i materiali del pool visti da un docente.
 * @param {import("../support/test").TeacherApi} api
 * @param {number} id
 */
async function vistoNelPool(api, id) {
    const materiali = await api.share.poolMaterials({ content_type: "esercizio" });
    return materiali.items.find((voce) => voce.source === "teacher_content" && voce.id === id);
}

test.describe("Condivisione — grant espliciti", () => {
    test("un grant diretto rende visibile l'esercizio al collega, la revoca lo nasconde", async ({ contentFactory, shareFactory, teacher2Api, env }) => {
        const esercizio = await contentFactory.exercise({ publish: true });
        const collega = await shareFactory.colleagueId(env.users.teacher2.username);

        // Senza grant e senza condivisione nel pool il collega non lo vede.
        expect(await vistoNelPool(teacher2Api, esercizio.id), "prima del grant").toBeFalsy();

        const grant = await shareFactory.grant("teacher_content", esercizio.id, [
            { target_type: "teacher", target_id: collega },
        ]);
        expect(grant.count).toBe(1);
        expect(await vistoNelPool(teacher2Api, esercizio.id), "dopo il grant diretto").toBeTruthy();

        await shareFactory.grant("teacher_content", esercizio.id, []);
        expect(await vistoNelPool(teacher2Api, esercizio.id), "dopo la revoca").toBeFalsy();
    });

    test("un grant a un gruppo rende visibile l'esercizio ai suoi membri", async ({ contentFactory, shareFactory, teacher2Api, env }) => {
        const esercizio = await contentFactory.exercise({ publish: true });
        const collega = await shareFactory.colleagueId(env.users.teacher2.username);

        const gruppo = await shareFactory.group();
        expect(await shareFactory.setMembers(gruppo, [collega]), "membri del gruppo").toBe(1);

        expect(await vistoNelPool(teacher2Api, esercizio.id), "prima del grant").toBeFalsy();
        await shareFactory.grant("teacher_content", esercizio.id, [
            { target_type: "group", target_id: gruppo },
        ]);
        expect(await vistoNelPool(teacher2Api, esercizio.id), "dopo il grant al gruppo").toBeTruthy();
    });
});
