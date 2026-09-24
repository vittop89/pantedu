// @ts-check
/**
 * Scostamento fra una copia personale e il modello da cui è nata.
 * Riscrittura di risdoc_u10_drift.spec.js.
 *
 * Quando l'Istituto cambia un modello, le copie che i docenti se ne erano fatti
 * restano com'erano: l'applicazione se ne accorge confrontando la versione da
 * cui la copia è partita con quella corrente, e nell'editor mette un avviso.
 *
 * Questo test verifica il contratto di quella risposta e il caso senza
 * scostamento: nessuna copia segnalata, nessun avviso nell'editor. Lo
 * scostamento vero non si può provocare dall'esterno — vorrebbe dire scrivere
 * a mano la versione di partenza di una copia, e nessuna rotta lo permette —
 * ed è la voce 44 del debito.
 *
 * Cosa cambia rispetto a prima: niente login nella spec, la modifica di prova
 * viene tolta dal registro di pulizia invece che da due righe in fondo al test
 * (che non giravano se il test falliva prima), e l'attesa di ottocento
 * millisecondi prima di guardare l'avviso è sostituita dall'attesa che
 * l'editor sia in pagina. I due casi storici erano lo stesso caso scritto due
 * volte: qui è uno.
 */
const { test, expect } = require("../support/test");

test("senza scostamenti la risposta è vuota e l'editor non mette avvisi", async ({ teacherApi, teacherPage, cleanup }) => {
    const modello = await teacherApi.risdoc.unTemplate({ origin: "risdoc" });

    // Una modifica personale sul modello: è la condizione in cui lo
    // scostamento potrebbe esserci, e non c'è perché la copia è appena nata.
    const modifica = await teacherApi.risdoc.saveOverride(modello.id, {
        kind: "html",
        path: "",
        body: "<h1>modifica di prova</h1>",
    });
    expect(modifica.ok, `modifica personale → ${modifica.status}`).toBe(true);
    cleanup.add("teacher", `toglie la modifica di prova al modello ${modello.id}`, async () => {
        await teacherApi.risdoc.deleteOverride(modello.id, { kind: "html", path: "" });
    });

    const scostamento = await teacherApi.risdoc.drift(modello.id);
    expect(scostamento.ok, `la rotta risponde → ${scostamento.status}`).toBe(true);
    const risposta = scostamento.body ?? {};
    expect(risposta["ok"], "la risposta è positiva").toBe(true);
    expect(risposta, "dichiara la versione corrente del modello").toHaveProperty("current_source_hash");
    expect(Array.isArray(risposta["drifted"]), "e l'elenco delle copie scostate").toBe(true);
    expect(risposta["drifted"], "che qui è vuoto").toEqual([]);

    await teacherPage.goto(`/risdoc/edit/${modello.id}`);
    await expect(
        teacherPage.locator(".fm-re-drift-banner"),
        "senza scostamenti l'editor non mette l'avviso",
    ).toBeHidden();
});
