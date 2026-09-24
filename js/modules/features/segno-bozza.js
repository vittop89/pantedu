/**
 * Il segno delle bozze nelle sidepage del docente (16/9/2026, richiesta
 * dell'utente): una voce in bozza cambia stile e mostra un occhio barrato, cioè
 * «non visibile». Lo vede solo chi modifica: studenti e visitatori ricevono solo
 * i contenuti pubblicati, quindi da loro una bozza non arriva.
 *
 * Usato da db-sidepage.js (Mappe, Laboratorio, Esercizi, Verifiche) e da
 * risdoc-sidepage.js (BES/DSA, Risorse docente). Stile in
 * css/modules/_db-sidepage.css; prove in tests/js-unit/segno-bozza.test.js e
 * tests/e2e/area-docente/bozze-in-sidebar.spec.js.
 */

export const CLASSE_BOZZA = "fm-item--bozza";

export const TITOLO_BOZZA = "Bozza: non la vedono gli studenti né chi visita il sito senza accesso";

// Disegnato qui: un occhio e una barra.
const OCCHIO_BARRATO = '<svg class="fm-item-bozza__icona" viewBox="0 0 16 16" width="12" height="12" aria-hidden="true" focusable="false">'
    + '<path d="M1.5 8S4 3.5 8 3.5 14.5 8 14.5 8 12 12.5 8 12.5 1.5 8 1.5 8Z" fill="none" stroke="currentColor" stroke-width="1.4"/>'
    + '<circle cx="8" cy="8" r="2" fill="currentColor"/>'
    + '<path d="M2.5 13.5 13.5 2.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>'
    + "</svg>";

/**
 * @param {{ visibility?: string }|null|undefined} riga
 * @returns {boolean}
 */
export function eBozza(riga) {
    return String(riga?.visibility || "") === "draft";
}

/**
 * Il segno da mettere all'inizio del collegamento: l'icona per chi guarda, il
 * testo per chi usa un lettore di schermo (il nome del collegamento diventa
 * «Bozza, non visibile: …»).
 *
 * @returns {string}
 */
export function segnoBozzaHtml() {
    return `<span class="fm-item-bozza" title="${TITOLO_BOZZA}">${OCCHIO_BARRATO}`
        + '<span class="fm-sr-only">Bozza, non visibile: </span></span>';
}
