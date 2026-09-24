// @ts-check
/**
 * Finestra «+ Nuovo» del pannello «Risorse docente»: le scelte che offre.
 * Riscrittura del secondo caso di page_doc_modal_user_flow.spec.js.
 *
 * È una finestra sola per tutte le categorie (ADR-024): la scelta non è più
 * fra tipi diversi di documento ma fra modi di partire — tre che portano
 * dentro qualcosa da fuori (un collegamento, un file caricato, una mappa
 * disegnata) e due che costruiscono il corpo strutturato lì, libero o con le
 * sezioni degli esercizi. Il modo «fork» e il campo `doc_kind` sono stati tolti
 * (ADR-026), e questo test è quello che se ne accorgerebbe se tornassero.
 *
 * Cosa cambia rispetto a prima: niente login nella spec, niente clic finti da
 * codice (i comandi si premono), e delle tre attese a tempo — due secondi e
 * mezzo dopo l'apertura del pannello, quattrocento millisecondi dopo la
 * modifica — non resta nulla.
 *
 * Il terzo caso della spec storica, l'ispezione degli stili in linea di
 * `.fm-modal-page-doc`, è stato eliminato senza sostituto: quella parte della
 * finestra non esiste più — lo dice il test qui sotto — e il controllo girava
 * su zero elementi, cioè non poteva fallire. È la voce 43 del debito.
 */
const { test, expect } = require("../support/test");

test("la finestra di creazione offre le strutture previste, e nient'altro", async ({ homeDocente }) => {
    await homeDocente.vaiA();
    const pannello = await homeDocente.apriSidepage("risorseDocente");
    await homeDocente.attivaModificaSezione(pannello);
    const finestra = await homeDocente.nuovoNellaSezione(pannello);

    // Cinque modi: tre portano un file da fuori (collegamento, caricamento,
    // mappa disegnata) e due costruiscono il corpo strutturato qui dentro.
    const strutture = finestra.locator('input[name="doc_mode"]');
    const valori = await strutture.evaluateAll((elementi) =>
        elementi.map((el) => /** @type {HTMLInputElement} */ (el).value));
    expect(valori.sort(), "i modi offerti dalla finestra")
        .toEqual(["custom", "drawio_native", "exercises", "link", "upload"]);
    await expect(finestra.locator('input[name="doc_mode"][value="fork"]'), "il modo «fork» è stato tolto").toHaveCount(0);
    await expect(
        finestra.locator('input[name="doc_mode"][value="custom"]'),
        "per le risorse docente parte selezionata la struttura libera",
    ).toBeChecked();

    await expect(finestra.locator('input[name="doc_kind"]'), "il vecchio campo del tipo è sparito").toHaveCount(0);
    await expect(finestra.locator(".fm-modal-page-doc"), "e con lui il riquadro che lo spiegava").toHaveCount(0);
});
