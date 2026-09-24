// @ts-check
/**
 * Due richieste insieme sullo stesso modello: niente si sovrascrive.
 * Riscrittura di b7_concurrent_isolation.spec.js.
 *
 * Due garanzie che si vedono solo quando le richieste partono insieme:
 *  - due copie personali chieste nello stesso istante con la stessa etichetta
 *    devono nascere distinte, non collidere sulla stessa chiave;
 *  - due docenti che salvano insieme la propria modifica allo stesso file non
 *    devono sovrascriversi a vicenda.
 * Fatte una dopo l'altra funzionerebbero comunque: è la simultaneità a mettere
 * alla prova il codice che assegna le chiavi e quello che indirizza le
 * modifiche.
 *
 * Gli altri due casi della spec storica — le copie di un docente non compaiono
 * a un altro, e la modifica dell'Istituto non tocca quelle personali — sono
 * passati a `risdoc/modifiche-isolate`, dove sta il resto dell'isolamento.
 *
 * Cosa cambia rispetto a prima: niente login nelle spec e nessun contesto di
 * browser aperto e chiuso a mano, e le pulizie sono registrate invece di stare
 * in fondo al test.
 */
const { test, expect } = require("../support/test");

test.describe("Amministrazione — richieste simultanee sui modelli", () => {
    test("due copie chieste insieme con la stessa etichetta nascono distinte", async ({ adminApi, naming, cleanup }) => {
        const modello = await adminApi.risdoc.unTemplate({ origin: "risdoc" });
        const etichetta = naming.unique("stessa-etichetta");

        const [prima, seconda] = await Promise.all([
            adminApi.risdoc.createInstance(modello.id, etichetta),
            adminApi.risdoc.createInstance(modello.id, etichetta),
        ]);
        for (const chiave of [prima, seconda]) {
            cleanup.add("admin", `cancella la copia ${chiave}`, async () => {
                await adminApi.risdoc.deleteInstance(modello.id, chiave);
            });
        }

        expect(prima, "la prima copia ha una chiave").toBeTruthy();
        expect(seconda, "e anche la seconda").toBeTruthy();
        expect(prima, "e le due chiavi sono diverse: nessuna collisione silenziosa").not.toBe(seconda);

        const elencate = await adminApi.risdoc.instances(modello.id);
        const chiavi = (elencate.body?.instances ?? []).map((i) => i.instance_key);
        expect(chiavi, "tutte e due sono state salvate").toEqual(expect.arrayContaining([prima, seconda]));
    });

    test("due docenti che salvano insieme la propria modifica non si sovrascrivono", async ({
        adminApi,
        teacherApi,
        teacher2Api,
        naming,
        cleanup,
    }) => {
        const modello = await adminApi.risdoc.unTemplate({ origin: "risdoc" });
        const scheda = await adminApi.risdoc.detail(modello.id);
        const marcaturaPrimo = naming.unique("primo").toUpperCase();
        const marcaturaSecondo = naming.unique("secondo").toUpperCase();

        const chiaveSecondo = await teacher2Api.risdoc.createInstance(modello.id, naming.unique("copia-del-secondo"));
        cleanup.add("teacher2", `cancella la copia ${chiaveSecondo}`, async () => {
            await teacher2Api.risdoc.deleteInstance(modello.id, chiaveSecondo);
        });
        cleanup.add("teacher", "toglie la modifica del primo docente", async () => {
            await teacherApi.risdoc.deleteOverride(modello.id, { kind: "html", path: scheda.html_file, instance_key: "" });
        });

        const [salvataggioPrimo, salvataggioSecondo] = await Promise.all([
            teacherApi.risdoc.saveOverride(modello.id, {
                kind: "html", path: scheda.html_file, body: `<!-- ${marcaturaPrimo} -->`, instance_key: "",
            }),
            teacher2Api.risdoc.saveOverride(modello.id, {
                kind: "html", path: scheda.html_file, body: `<!-- ${marcaturaSecondo} -->`, instance_key: chiaveSecondo,
            }),
        ]);
        expect(salvataggioPrimo.ok, `salvataggio del primo → ${salvataggioPrimo.status}`).toBe(true);
        expect(salvataggioSecondo.ok, `salvataggio del secondo → ${salvataggioSecondo.status}`).toBe(true);

        const perIlPrimo = await teacherApi.risdoc.file(modello.id, { kind: "html", path: scheda.html_file });
        expect(String(perIlPrimo.body?.body), "il primo rilegge la propria").toContain(marcaturaPrimo);
        expect(String(perIlPrimo.body?.body), "e non quella dell'altro").not.toContain(marcaturaSecondo);

        const perIlSecondo = await teacher2Api.risdoc.file(modello.id, {
            kind: "html", path: scheda.html_file, instanceKey: chiaveSecondo,
        });
        expect(String(perIlSecondo.body?.body), "il secondo rilegge la propria").toContain(marcaturaSecondo);
        expect(String(perIlSecondo.body?.body), "e non quella dell'altro").not.toContain(marcaturaPrimo);
    });
});
