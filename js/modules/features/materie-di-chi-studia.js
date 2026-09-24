/**
 * Le materie di chi studia, al cambio di classe (19/9/2026).
 *
 * Per chi studia — lo studente con account e l'ospite con la credenziale di
 * classe — il server disegna nel selettore #sel-mater solo le materie con
 * almeno un materiale che può vedere, per la sua classe
 * (views/layout/app.php, App\Services\Study\MaterieConMateriali). Il selettore
 * porta il segno `data-fm-materie-di-chi-studia="1"`.
 *
 * Quando cambia la classe — lo studente guarda un anno già fatto (classi
 * frequentate), o l'URL di una pagina di studio la sceglie
 * (fm-url-state.js) — le materie con materiali possono essere altre: qui si
 * chiedono a GET /api/study/materie.json e si ridisegna il selettore. Senza
 * nessuna materia il selettore si nasconde e compare l'avviso
 * #fm-materie-avviso; con almeno una torna il selettore.
 *
 * Dopo il ridisegno si annuncia il cambio su #sel-mater: i pannelli
 * (db-sidepage.js, risdoc-sidepage.js) si ricaricano con le materie nuove.
 *
 * Chi arriva dall'indirizzo di una pagina di studio — da segnalibro, o con
 * F5 — porta nell'URL anche la materia, e fm-url-state.js prova a metterla
 * nel selettore prima che le opzioni della classe nuova esistano: quando non
 * la trova lascia il segno `data-fm-valore-atteso`, che qui si legge e si
 * consuma. Senza, la materia dell'URL si perdeva e i pannelli chiedevano i
 * contenuti di tutte le materie della classe (20/9/2026).
 *
 * Per docenti e amministratori il segno non c'è e qui non succede niente.
 */
import { riempi } from "../core/sidebar-cascade.js";

export const URL_MATERIE = "/api/study/materie.json";

/** Il selettore delle materie, se è quello di chi studia; altrimenti null. */
export function selettoreDiChiStudia(doc = document) {
    const sel = doc.getElementById("sel-mater");
    return sel && sel.dataset.fmMaterieDiChiStudia === "1" ? sel : null;
}

/**
 * Mostra il selettore o l'avviso, secondo che ci siano materie o no.
 * @param {HTMLSelectElement} sel
 * @param {boolean} ciSono
 * @param {Document} [doc]
 */
export function mostra(sel, ciSono, doc = document) {
    sel.hidden = !ciSono;
    const etichetta = doc.querySelector('label[for="sel-mater"]');
    if (etichetta) etichetta.hidden = !ciSono;
    const avviso = doc.getElementById("fm-materie-avviso");
    if (avviso) avviso.hidden = ciSono;
}

let turno = 0;

/**
 * Chiede le materie della classe e ridisegna il selettore. Una risposta
 * arrivata dopo una domanda più recente si scarta.
 *
 * @param {string} classe
 * @param {string} indirizzo
 * @param {{ fetchImpl?: typeof fetch, doc?: Document }} [opzioni]
 * @returns {Promise<boolean>} true se il selettore è stato ridisegnato
 */
export async function ricarica(classe, indirizzo, opzioni = {}) {
    const doc = opzioni.doc || document;
    const fetchImpl = opzioni.fetchImpl || fetch;
    const sel = selettoreDiChiStudia(doc);
    if (!sel || !classe) return false;

    const mio = ++turno;
    const qs = new URLSearchParams({ classe });
    if (indirizzo) qs.set("indirizzo", indirizzo);
    let dati;
    try {
        const r = await fetchImpl(`${URL_MATERIE}?${qs}`, {
            credentials: "same-origin",
            headers: { Accept: "application/json" },
        });
        if (!r.ok) return false;
        dati = await r.json();
    } catch (_) {
        // Rete giù: resta il selettore di prima, che è quello della classe
        // precedente ma non è vuoto per sbaglio.
        return false;
    }
    if (mio !== turno) return false;
    if (!dati || dati.filtrate !== true || !Array.isArray(dati.materie)) return false;

    // Il valore atteso lo lascia fm-url-state.js quando la materia dell'URL
    // non era fra le opzioni di prima: e' la materia che chi studia ha
    // chiesto, e vale piu' del niente. Si consuma qui una volta sola, o
    // tornerebbe a ogni cambio di classe successivo.
    const atteso = sel.dataset.fmValoreAtteso || "";
    delete sel.dataset.fmValoreAtteso;
    const prima = sel.value || atteso;
    riempi(sel, dati.materie, prima);
    mostra(sel, dati.materie.length > 0, doc);
    sel.dispatchEvent(new CustomEvent("change", {
        bubbles: true,
        detail: { fmSource: "materie-di-chi-studia" },
    }));
    return true;
}

function suCambio(e) {
    const t = e.target;
    if (!t || t.id !== "sel-cls") return;
    if (!selettoreDiChiStudia()) return;
    const indirizzo = document.getElementById("sel-iis")?.value || "";
    ricarica(t.value, indirizzo);
}

// Delegato sul documento e collegato subito: fm-url-state.js può cambiare la
// classe al DOMContentLoaded, e il cambio deve trovarci già in ascolto.
if (typeof document !== "undefined") {
    document.addEventListener("change", suCambio);
}
