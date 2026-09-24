/**
 * L'avviso quando l'accesso si chiude: una finestra, non una riga in piccolo.
 *
 * Segnalazione dell'utente (21/9/2026): entrato con il login, quando la
 * sessione scadeva compariva da qualche parte un «security_check_required» in
 * caratteri piccoli — il rifiuto tecnico del WAF, non una frase per una
 * persona. Chi lo legge non sa né che cosa è successo né che cosa fare, e nel
 * frattempo quello che stava scrivendo è ancora lì.
 *
 * Quindi: una finestra che dice che cosa è successo, e soprattutto **due vie
 * d'uscita**, che sono collegamenti veri e non pulsanti che chiamano codice.
 *
 *   - «Entra di nuovo» porta al login e si ricorda la pagina, così dopo si
 *     torna dov'era;
 *   - «Vai alla pagina pubblica» porta alla home, che si apre senza account:
 *     serve a non restare mai in trappola, che è la seconda metà della
 *     segnalazione («non riesco in nessun modo ad andare su pantedu.eu»).
 *
 * Non chiude la pagina e non ricarica da sé: quello che c'è a schermo resta,
 * perché può contenere l'ultima versione di un testo che il server non ha.
 *
 * La finestra si costruisce un nodo per volta, senza `innerHTML`: qui dentro
 * finiscono l'indirizzo della pagina e testi che un domani potrebbero venire
 * dal server, e il cancello di semgrep conta le scritture di HTML crudo
 * apposta perché non si aggiungano per abitudine.
 */

import { purgeAuthCaches } from "../perf/sw-register.js";

const ID = "fm-avviso-sessione";

/** I due motivi per cui l'accesso smette di valere. */
const TESTI = {
    scaduta: {
        titolo: "Accesso scaduto",
        corpo: "La sessione è stata chiusa: può essere passato troppo tempo, "
            + "oppure sei entrato da un altro dispositivo. Quello che vedi qui "
            + "resta, ma il sito non lo sta più salvando.",
    },
    sicurezza: {
        titolo: "Verifica di sicurezza scaduta",
        corpo: "Il controllo che distingue una persona da un programma è "
            + "scaduto e non si è potuto rifare da solo. Ricaricando la pagina "
            + "si risolve; se avevi del testo non salvato, copialo prima.",
    },
};

/** Un elemento con le sue classi e il suo testo, senza HTML crudo. */
function nodo(tag, classi, testo) {
    const el = document.createElement(tag);
    el.className = classi;
    if (testo !== undefined) {
        el.textContent = testo;
    }
    return el;
}

/** Dove siamo adesso, per tornarci dopo il login. */
function paginaCorrente() {
    try {
        return location.pathname + location.search;
    } catch (_) {
        return "/";
    }
}

/**
 * Mostra l'avviso. Chiamarlo più volte non ne apre due: la prima finestra
 * resta, perché durante una sessione scaduta le richieste che falliscono sono
 * quasi sempre più d'una.
 *
 * @param {{motivo?: "scaduta"|"sicurezza"}} [opzioni]
 * @returns {HTMLElement|null} la finestra, o null se non c'è un documento
 */
export function avvisaSessioneScaduta({ motivo = "scaduta" } = {}) {
    if (typeof document === "undefined") return null;
    const gia = document.getElementById(ID);
    if (gia) return gia;

    const testo = TESTI[motivo] ?? TESTI.scaduta;

    const sfondo = nodo("div", "fm-avviso-sessione");
    sfondo.id = ID;
    sfondo.setAttribute("role", "dialog");
    sfondo.setAttribute("aria-modal", "true");
    sfondo.setAttribute("aria-labelledby", ID + "-titolo");

    const finestra = nodo("div", "fm-avviso-sessione__finestra");
    const titolo = nodo("h2", "fm-avviso-sessione__titolo", testo.titolo);
    titolo.id = ID + "-titolo";
    finestra.append(titolo, nodo("p", "fm-avviso-sessione__corpo", testo.corpo));

    const azioni = nodo("div", "fm-avviso-sessione__azioni");

    const entra = nodo("a", "fm-btn fm-btn--primary fm-avviso-sessione__entra", "Entra di nuovo");
    entra.href = "/login?redirect=" + encodeURIComponent(paginaCorrente());

    const pubblica = nodo("a", "fm-btn fm-avviso-sessione__pubblica", "Vai alla pagina pubblica");
    pubblica.href = "/";

    const resta = nodo("button", "fm-btn fm-avviso-sessione__resta", "Resta qui");
    resta.type = "button";
    resta.addEventListener("click", () => { sfondo.remove(); });

    azioni.append(entra, pubblica, resta);
    finestra.append(azioni);
    sfondo.append(finestra);

    // Le cache delle pagine e delle API possono contenere risposte di quando
    // l'accesso valeva: si buttano adesso, così il giro dopo il login parte
    // pulito (è la stessa pulizia che si fa arrivando su /login).
    purgeAuthCaches().catch(() => { /* senza service worker non c'è niente da pulire */ });

    document.body.appendChild(sfondo);
    entra.focus();
    return sfondo;
}

/** Toglie l'avviso, se c'è. Serve alle prove e al rientro dopo il login. */
export function togliAvvisoSessione() {
    document.getElementById(ID)?.remove();
}
