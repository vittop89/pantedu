// @ts-check
/**
 * La bozza che il docente ha scaricato comincia a scadere (ADR-046).
 *
 * ── Che cosa misura questa spec, e che cosa no ────────────────────────────
 *
 * Misura il pezzo che può rompersi in silenzio: la chiamata che dice al server
 * «ho scaricato», attraverso tutto ciò che sta in mezzo a una richiesta vera —
 * sessione, gettone CSRF vero (mai finto, è una regola del progetto), filtro di
 * sicurezza, limitatore, la rotta e il suo controllo di proprietà. È il punto
 * in cui un 403 si travestirebbe da successo: il client non mostra niente
 * all'utente quando quella chiamata fallisce, di proposito, e senza una prova
 * il difetto si vedrebbe solo fra quindici giorni — cioè mai, perché la riga
 * semplicemente non scadrebbe.
 *
 * **Non** misura il clic sul pulsante `⤓ PDF`: quello ha bisogno che il
 * servizio TeX abbia già compilato il documento e messo i byte in cache, e
 * legare questa verifica alla compilazione la renderebbe rossa per ragioni che
 * non c'entrano. Il legame fra il pulsante e questa chiamata è tenuto da una
 * prova di unità sul codice del modal.
 *
 * ── Niente resta nel database ─────────────────────────────────────────────
 *
 * La compilazione nasce con una chiave unica marcata dal momento della prova e
 * si cancella nel registro di pulizia, che gira anche quando la prova fallisce.
 */
const { test, expect } = require("../support/test");

/** Chiave unica: due esecuzioni in parallelo non devono sovrascriversi. */
function chiaveUnica() {
    return `combo_e2e-scadenza-${Date.now()}-${Math.floor(Math.random() * 100000)}`;
}

test.describe("Risdoc — le bozze hanno una scadenza", () => {
    test("scaricare il documento fa partire il conto, e il server lo registra", async ({ teacherApi, cleanup }) => {
        const modello = await teacherApi.risdoc.unTemplate({ origin: "risdoc" });
        const chiave = chiaveUnica();

        const salvata = await teacherApi.risdoc.saveCompilation(modello.id, {
            compilation_key: chiave,
            label: "Bozza della prova end-to-end",
            data: JSON.stringify({ state: {}, fields: {}, body_pt: [] }),
        });
        expect(salvata.ok, `salvataggio della compilazione → ${salvata.status}: ${salvata.text.slice(0, 200)}`).toBe(true);

        const id = salvata.body?.id;
        expect(typeof id, "il server restituisce l'identificativo della riga").toBe("number");
        const compilazione = /** @type {number} */ (id);
        cleanup.add("teacher", `cancella la compilazione ${compilazione}`, async () => {
            await teacherApi.risdoc.deleteCompilation(compilazione);
        });

        // Controllo positivo: prima non è segnata. Senza questa riga la prova
        // passerebbe anche se `exported_at` fosse valorizzata dalla nascita.
        const prima = await teacherApi.risdoc.compilation(compilazione);
        expect(prima.ok, "la compilazione appena salvata si rilegge").toBe(true);
        expect(prima.body?.compilation?.exported_at ?? null, "appena nata non è ancora stata scaricata").toBeNull();

        const segnata = await teacherApi.risdoc.segnaScaricata(compilazione);
        expect(
            segnata.ok,
            `la segnalazione dello scaricamento → ${segnata.status}: ${segnata.text.slice(0, 200)}`,
        ).toBe(true);
        expect(segnata.body?.scade_fra_giorni, "e dice al client quanti giorni restano").toBe(15);

        const dopo = await teacherApi.risdoc.compilation(compilazione);
        expect(dopo.body?.compilation?.exported_at ?? null, "la data dello scaricamento è scritta").not.toBeNull();
    });

    /**
     * Segnare uno scaricamento non è una modifica del docente. Se `updated_at`
     * saltasse, la lista delle compilazioni — ordinata per data di modifica —
     * si riordinerebbe sotto le sue mani, e la grazia ripartirebbe da capo a
     * ogni scaricamento invece di scadere.
     */
    test("segnare lo scaricamento non fa sembrare la bozza appena modificata", async ({ teacherApi, cleanup }) => {
        const modello = await teacherApi.risdoc.unTemplate({ origin: "risdoc" });
        const chiave = chiaveUnica();

        const salvata = await teacherApi.risdoc.saveCompilation(modello.id, {
            compilation_key: chiave,
            label: "Bozza che non deve muoversi",
            data: JSON.stringify({ state: {}, fields: {}, body_pt: [] }),
        });
        const compilazione = /** @type {number} */ (salvata.body?.id);
        expect(typeof compilazione, "identificativo della riga").toBe("number");
        cleanup.add("teacher", `cancella la compilazione ${compilazione}`, async () => {
            await teacherApi.risdoc.deleteCompilation(compilazione);
        });

        const prima = (await teacherApi.risdoc.compilation(compilazione)).body?.compilation?.updated_at;
        expect(prima, "controllo positivo: la data di modifica esiste").toBeTruthy();

        await teacherApi.risdoc.segnaScaricata(compilazione);

        const dopo = (await teacherApi.risdoc.compilation(compilazione)).body?.compilation?.updated_at;
        expect(dopo, "la data di modifica è rimasta ferma").toBe(prima);
    });

    /**
     * Il verso che protegge. Un docente non deve poter far partire il conto
     * alla rovescia sulla bozza di un collega — cioè non deve poter far
     * cancellare il suo lavoro.
     */
    test("un altro docente non può far partire il conto sulla bozza altrui", async ({ teacherApi, teacher2Api, cleanup }) => {
        const modello = await teacherApi.risdoc.unTemplate({ origin: "risdoc" });
        const chiave = chiaveUnica();

        const salvata = await teacherApi.risdoc.saveCompilation(modello.id, {
            compilation_key: chiave,
            label: "Bozza di qualcun altro",
            data: JSON.stringify({ state: {}, fields: {}, body_pt: [] }),
        });
        const compilazione = /** @type {number} */ (salvata.body?.id);
        expect(typeof compilazione, "identificativo della riga").toBe("number");
        cleanup.add("teacher", `cancella la compilazione ${compilazione}`, async () => {
            await teacherApi.risdoc.deleteCompilation(compilazione);
        });

        const tentativo = await teacher2Api.risdoc.segnaScaricata(compilazione);
        expect(tentativo.ok, "il secondo docente non deve poterla segnare").toBe(false);
        expect(
            tentativo.status,
            "e la risposta è la stessa di «non esiste»: non si rivela che quella riga c'è",
        ).toBe(404);

        const dopo = await teacherApi.risdoc.compilation(compilazione);
        expect(
            dopo.body?.compilation?.exported_at ?? null,
            "soprattutto: la riga non deve essere stata toccata",
        ).toBeNull();
    });
});
