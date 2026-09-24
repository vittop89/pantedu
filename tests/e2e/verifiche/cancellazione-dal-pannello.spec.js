// @ts-check
/**
 * Cancellare una verifica dal pannello cancella tutte le sue varianti.
 * Riscrittura di g22_s15bis_fase5_delete_batch.spec.js.
 *
 * Una verifica generata esiste in più varianti — con e senza soluzioni, per DSA,
 * per chi ha un ingrandimento — ma nel pannello ne compare una sola riga.
 * Premendo il cestino su quella riga devono sparire tutte: prima ne spariva una
 * e le altre restavano nel database, dove il pannello le ripescava al giro
 * dopo. Con le varianti che condividono i file, cancellarne una sola rompeva
 * anche i file delle altre.
 *
 * Un fatto dell'interfaccia che questa riscrittura ha reso esplicito: le
 * verifiche generate compaiono nel pannello solo aprendolo da una pagina di
 * studio. Il modulo che le disegna non è caricato dalla home, e lì il pannello
 * elenca soltanto i contenuti di tipo verifica del docente. La spec storica
 * partiva dalla pagina di un esercizio senza dire perché.
 *
 * Cosa cambia rispetto a prima: niente login nella spec, niente `page.evaluate`
 * per premere i comandi o per riempire i selettori della terna, delle sette
 * attese a tempo non resta nulla, e la verifica di prova nasce dalla factory,
 * che ne registra la cancellazione: se il test si ferma a metà, le varianti non
 * restano nel database (era una delle cause del suo riempirsi).
 */
const { test, expect } = require("../support/test");

test("il cestino di una verifica ne cancella tutte le varianti", async ({
    verificaFactory,
    contentFactory,
    teacherApi,
    homeDocente,
    studioEsercizio,
    teacherPage,
    naming,
    env,
}) => {
    const { indirizzo, classe, materia } = env.terna;
    const titolo = naming.unique("da-cancellare");

    // Due varianti: quella con le soluzioni e quella senza.
    const salvata = await verificaFactory.batch({
        title: titolo,
        versions: ["A"],
        overrides: {
            selectedIIS: indirizzo,
            selectedCLS: classe,
            selectedMATER: materia,
            indirizzo,
            classe,
            materia,
            sezione: "NOR",
            nPrint: 1,
            nPrintDSA: 0,
            nPrintDIS: 0,
        },
    });
    const varianti = salvata.docs.map((d) => d.id);
    expect(varianti.length, "sono state generate almeno due varianti").toBeGreaterThanOrEqual(2);

    const prima = (await teacherApi.verifica.list()).items.map((v) => v.id);
    for (const id of varianti) {
        expect(prima, `la variante ${id} c'è prima della cancellazione`).toContain(id);
    }

    const esercizio = await contentFactory.exercise({ terna: env.terna, groups: 1, itemsPerGroup: 1, publish: true });
    await studioEsercizio.vaiA(esercizio.studioUrl);
    await homeDocente.scegliTerna(indirizzo, classe, materia);
    const pannello = await homeDocente.apriSidepage("verifiche");
    await homeDocente.attendiVerificheGenerate(pannello);
    await homeDocente.attivaModificaSezione(pannello);

    // Nel pannello il titolo compare una volta sola, per tutte le varianti.
    const voce = pannello.locator("li[data-fm-content-kind='verifica']").filter({ hasText: titolo }).first();
    await expect(voce, "la verifica è elencata").toBeVisible({ timeout: 30_000 });

    await voce.locator(".fm-item-del").click();
    await teacherPage.getByRole("dialog").getByRole("button", { name: /^\s*OK\s*$/ }).click();
    await expect(voce, "la riga sparisce dal pannello").toHaveCount(0, { timeout: 30_000 });

    await expect
        .poll(
            async () => {
                const rimaste = (await teacherApi.verifica.list()).items.map((v) => v.id);
                return varianti.filter((id) => rimaste.includes(id));
            },
            { message: "tutte le varianti del gruppo devono sparire, non solo quella mostrata", timeout: 30_000 },
        )
        .toEqual([]);

    // E riaprendo il pannello non torna fuori: non sono rimasti orfani.
    // Qui si aspetta che il pannello abbia FINITO di disegnare, non che una
    // verifica compaia: se quella cancellata era l'unica del docente — come
    // succede su un database seminato da zero — il pannello resta vuoto, ed è
    // giusto così.
    await studioEsercizio.vaiA(esercizio.studioUrl);
    await homeDocente.scegliTerna(indirizzo, classe, materia);
    const riaperto = await homeDocente.apriSidepage("verifiche");
    await homeDocente.attendiPannelloVerificheDisegnato(riaperto);
    await expect(
        riaperto.locator("li[data-fm-content-kind='verifica']").filter({ hasText: titolo }),
        "il titolo non ricompare dopo il ricaricamento",
    ).toHaveCount(0);
});
