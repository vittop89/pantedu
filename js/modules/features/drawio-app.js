/**
 * L'editor drawio che la piattaforma ospita da sé, sotto `/drawio-app/`
 * (G22.S15.bis, fase 5). Un indirizzo solo per i due punti che lo aprono: le
 * mappe esistenti (drawio-editor.js) e la mappa nuova dal modale di creazione
 * (sidepage-modal-content.js). Provato in tests/js-unit/drawio-e-modifica.test.js.
 *
 * 15/9/2026, segnalato dall'utente: «Nuova mappa drawio (vuota)» apriva un
 * riquadro bianco. Il modale di creazione era rimasto su `embed.diagrams.net`,
 * e dal 9/9 gli mandava il messaggio «carica» con destinatario la nostra
 * origine: il browser lo scartava in silenzio e drawio restava ad aspettarlo.
 * I file dell'editor stanno nell'immagine del rilascio (docker/Dockerfile).
 */

// `index.html` esplicito: una cartella senza file indice non si serve.
export const DRAWIO_APP = "/drawio-app/index.html";

/**
 * @param {{ solaLettura?: boolean }} [opzioni]
 * @returns {string}
 */
export function srcEditorDrawio({ solaLettura = false } = {}) {
    return `${DRAWIO_APP}?embed=1&proto=json&ui=kennedy&lang=it&dark=0&saveAndExit=1`
        + `&noSaveBtn=${solaLettura ? "1" : "0"}&libraries=1`;
}

/**
 * L'origine a cui scrivere all'editor: quella dell'indirizzo del riquadro, non
 * quella della pagina. Finché l'editor è ospitato qui sono la stessa; se un
 * giorno non lo fosse, il messaggio arriverebbe invece di perdersi. Mai `"*"`,
 * che consegnerebbe il diagramma a qualunque origine il riquadro si trovasse
 * ad avere.
 *
 * @param {HTMLIFrameElement} iframe
 * @returns {string}
 */
export function origineDellEditor(iframe) {
    return new URL(iframe.getAttribute("src") || "", window.location.href).origin;
}
