/**
 * Mostra/nascondi password — checkbox accanto a un campo `type="password"`.
 *
 * Perche' esiste: senza, chi sbaglia a digitare non ha modo di accorgersene e
 * l'unico segnale e' "Username o password non validi". Su una piattaforma
 * dove il recupero password passa dall'email, ogni tentativo sprecato avvicina
 * il rate limit e il ban brute-force per un errore di battitura.
 *
 * Uso nel markup — nessun id da coordinare in JS:
 *
 *   <input id="fm-login-pwd" type="password" name="password">
 *   <label class="fm-pwtoggle">
 *     <input class="fm-pwtoggle__input" type="checkbox" data-pw-toggle="fm-login-pwd">
 *     <span class="fm-pwtoggle__text">Mostra password</span>
 *   </label>
 *
 * Delegato su document: funziona anche sui campi inseriti dopo il caricamento.
 *
 * Sicurezza: il campo torna `type="password"` quando la pagina perde
 * visibilita' (cambio scheda, blocco schermo) e alla submit del form. Una
 * password lasciata in chiaro su uno schermo condiviso — un'aula, una LIM —
 * e' un rischio concreto, e il ripristino automatico costa poco.
 *
 * Vanilla ESM, nessuna dipendenza. Import a effetto collaterale da bootstrap.
 */

const TOGGLE_SEL = "[data-pw-toggle]";

/** @returns {HTMLInputElement|null} */
function fieldFor(toggle) {
    const id = toggle.getAttribute("data-pw-toggle");
    if (!id) return null;
    const el = document.getElementById(id);
    return el instanceof HTMLInputElement ? el : null;
}

function apply(toggle, visible) {
    const field = fieldFor(toggle);
    if (!field) return;
    field.type = visible ? "text" : "password";
    // Nessun aria-label qui, di proposito. Impostarlo a "Nascondi password"
    // mentre l'etichetta visibile continua a dire "Mostra password" fa fallire
    // WCAG 2.5.3 (Label in Name): il nome accessibile deve contenere il testo
    // visibile, altrimenti chi comanda a voce dice "clicca Mostra password" e
    // il controllo non risponde. Lo stato lo annuncia gia' la checkbox, che e'
    // selezionata o no — ed e' il modo in cui una checkbox lo comunica.
}

/** Rimette al sicuro ogni campo attualmente in chiaro. */
function hideAll(root = document) {
    root.querySelectorAll(TOGGLE_SEL).forEach((toggle) => {
        if (toggle.checked) {
            toggle.checked = false;
            apply(toggle, false);
        }
    });
}

document.addEventListener("change", (ev) => {
    const toggle = ev.target?.closest?.(TOGGLE_SEL);
    if (toggle) apply(toggle, toggle.checked);
});

// La scheda passa in background: non lasciare la password leggibile alle
// spalle di chi guarda lo schermo.
document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "hidden") hideAll();
});

// Inviato il form, il campo ha finito il suo lavoro: si richiude.
document.addEventListener("submit", (ev) => {
    if (ev.target instanceof HTMLElement) hideAll(ev.target);
});
