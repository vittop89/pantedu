/**
 * Cambiare la classe di partenza ricarica subito l'elenco (15/9/2026,
 * segnalato dall'utente).
 *
 * PERCHÉ
 *   L'elenco dei materiali e il modulo «Sposta» sono quelli della classe
 *   mostrata, che viaggia nel campo nascosto `classe_da`. Cambiando il
 *   selettore senza premere «Mostra i materiali» restava l'elenco vecchio: si
 *   spuntava e si spostava dalla classe di prima credendo di partire da quella
 *   nuova. È andata così il 15/9: selettore sulla 3A, elenco e spostamento
 *   della «1».
 *
 * Adesso, al cambio, l'elenco vecchio si chiude subito (il modulo non parte più)
 * e la pagina si ricarica sulla classe scelta.
 *
 * @param {HTMLSelectElement} selettore  la tendina «Classe di partenza»
 * @param {{ modulo?: HTMLFormElement|null, invia?: HTMLButtonElement|null, elenco?: HTMLElement|null,
 *           vai?: (form: HTMLFormElement) => void }} opzioni
 */
export function collegaPartenza(selettore, { modulo = null, invia = null, elenco = null, vai = (f) => f.submit() } = {}) {
    if (!selettore) return;
    const mostrata = selettore.value;
    selettore.addEventListener("change", () => {
        if (selettore.value === mostrata) return;
        if (modulo) modulo.dataset.fmVecchio = "1";
        if (invia) invia.disabled = true;
        if (elenco) {
            elenco.setAttribute("aria-busy", "true");
            elenco.classList.add("fm-sposta-elenco--vecchio");
        }
        if (selettore.value && selettore.form) vai(selettore.form);
    });
    if (modulo) {
        // Anche se la ricarica tardasse: un elenco che non è della classe scelta non si invia.
        modulo.addEventListener("submit", (ev) => {
            if (modulo.dataset.fmVecchio === "1") {
                ev.preventDefault();
                ev.stopImmediatePropagation();
            }
        }, true);
    }
}
