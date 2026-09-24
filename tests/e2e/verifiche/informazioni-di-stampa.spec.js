// @ts-check
/**
 * Informazioni di stampa della verifica: salvataggio, elenco, cancellazione.
 *
 * Riunisce g20_07_print_info_delete_legacy (la cancellazione funziona sia per
 * i salvataggi con la chiave a cinque campi sia per quelli vecchi a tre) e la
 * parte via API di g19_18_print_info_load_modal (salvataggi distinti per
 * sezione) e di g19_print_info_scelte (giro completo di salvataggio e
 * rilettura).
 *
 * Cosa cambia rispetto a prima: niente login nella spec, i dati salvati
 * vengono cancellati anche quando il test fallisce, e i nomi delle materie di
 * prova nascono dal generatore dei nomi invece che da un frammento di
 * timestamp ritagliato a mano.
 */
const { test, expect } = require("../support/test");

test.describe("Verifiche — informazioni di stampa", () => {
    test("il salvataggio ritorna nell'elenco e si cancella, con la chiave nuova e con quella vecchia", async ({ teacherApi, cleanup, naming }) => {
        // Chiave a cinque campi: indirizzo, classe, materia, sezione, Istituto.
        const materiaCompleta = `PI${naming.unique("a").slice(-6)}`.toUpperCase();
        // Chiave a tre campi: senza sezione né Istituto, come i salvataggi vecchi.
        const materiaSemplice = `PL${naming.unique("b").slice(-6)}`.toUpperCase();

        for (const materia of [materiaCompleta, materiaSemplice]) {
            cleanup.add("teacher", `cancella le informazioni di stampa di ${materia}`, async () => {
                const elenco = await teacherApi.printInfo.list();
                for (const voce of elenco.items.filter((i) => i.materia === materia)) {
                    await teacherApi.printInfo.delete(voce.page_key);
                }
            });
        }

        await teacherApi.printInfo.save({
            indirizzo: "ar", classe: "9", materia: materiaCompleta,
            sezione: "Z", istituto: "Istituto di prova", anno: "2099", nPrint: "1",
        });
        await teacherApi.printInfo.save({
            indirizzo: "ar", classe: "9", materia: materiaSemplice, nPrint: "1",
        });

        const elenco = await teacherApi.printInfo.list();
        const completa = elenco.items.find((i) => i.materia === materiaCompleta);
        const semplice = elenco.items.find((i) => i.materia === materiaSemplice);
        expect(completa, "salvataggio con tutti i campi").toBeTruthy();
        expect(semplice, "salvataggio con i soli campi essenziali").toBeTruthy();
        // La chiave normalizza il nome dell'Istituto togliendo gli spazi.
        expect(completa?.page_key, "chiave a cinque campi").toBe(`ar_9_${materiaCompleta}_Z_Istitutodiprova`);
        expect(semplice?.page_key, "chiave a tre campi").toBe(`ar_9_${materiaSemplice}`);

        for (const chiave of [completa?.page_key, semplice?.page_key]) {
            expect(chiave).toBeTruthy();
            if (!chiave) continue;
            const esito = await teacherApi.printInfo.delete(chiave);
            expect(esito.status, `cancellazione di ${chiave}`).toBe(200);
            expect(esito.body?.deleted).toBe(true);
        }

        const dopo = await teacherApi.printInfo.list();
        expect(dopo.items.find((i) => i.materia === materiaCompleta), "cancellata").toBeFalsy();
        expect(dopo.items.find((i) => i.materia === materiaSemplice), "cancellata").toBeFalsy();
    });

    test("due sezioni della stessa terna restano salvataggi distinti", async ({ teacherApi, cleanup, naming }) => {
        const materia = `PS${naming.unique("c").slice(-6)}`.toUpperCase();
        cleanup.add("teacher", `cancella le informazioni di stampa di ${materia}`, async () => {
            const elenco = await teacherApi.printInfo.list();
            for (const voce of elenco.items.filter((i) => i.materia === materia)) {
                await teacherApi.printInfo.delete(voce.page_key);
            }
        });

        const primo = await teacherApi.printInfo.save({
            indirizzo: "ar", classe: "2", materia, sezione: "A", istituto: "Esempio", nPrint: "10",
        });
        const secondo = await teacherApi.printInfo.save({
            indirizzo: "ar", classe: "2", materia, sezione: "B", istituto: "Esempio", nPrint: "20",
        });
        expect(primo.key, "chiavi diverse per sezioni diverse").not.toBe(secondo.key);

        const elenco = await teacherApi.printInfo.list();
        const dellaMateria = elenco.items.filter((i) => i.materia === materia);
        expect(dellaMateria, "due salvataggi distinti").toHaveLength(2);
        expect(dellaMateria.map((i) => i.sezione).sort()).toEqual(["A", "B"]);
    });
});
