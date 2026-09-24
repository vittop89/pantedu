import { fetchCsrf, wafFetch } from "../core/dom-utils.js";

/**
 * Dice al server che il docente ha SCARICATO il documento di questa
 * compilazione (ADR-046).
 *
 * ── Perché serve una funzione apposta ─────────────────────────────────────
 *
 * Lo scaricamento avviene tutto qui, nel browser: il pulsante prende i byte
 * del PDF già in memoria e li dà al browser come file. Nessuna richiesta parte
 * — il server non può accorgersene da solo. Da questo momento comincia la
 * grazia prima della cancellazione, quindi qualcuno glielo deve dire.
 *
 * ── Le tre vie che scaricano lo stesso documento ──────────────────────────
 *
 * Il PDF dal modal, il pacchetto ZIP e il pacchetto per VSCode portano via lo
 * stesso contenuto compilato da tre pulsanti diversi. Sono tre scaricamenti
 * veri: se si segnasse solo il primo, la bozza di chi lavora con lo ZIP
 * resterebbe sul server fino alla rete di fine anno. Per questo la chiamata
 * sta qui, in un posto solo, e la fanno tutte e tre.
 *
 * ── Non è un fatto certo, e va bene così ──────────────────────────────────
 *
 * Se la rete cade, se la scheda si chiude subito, se il gettone non arriva, la
 * data non si scrive: il docente ha il PDF e la bozza resta. È il verso
 * giusto — un guasto lascia il lavoro dov'è, e la rete di fine anno la
 * raccoglie comunque. Per questo non si mostra nessun errore all'utente: non
 * ha sbagliato niente e non può farci niente.
 *
 * ── Due cose che non si possono saltare ───────────────────────────────────
 *
 * `fetchCsrf()` prende il gettone VERO da /auth/csrf (non si finge mai, è una
 * regola del progetto) e torna stringa vuota se non ci riesce, senza
 * sollevare: senza il controllo qui sotto si spedirebbe un gettone vuoto e si
 * prenderebbe un 403 scambiandolo per un successo. `wafFetch` è l'unico punto
 * che ritenta dopo la sfida del filtro di sicurezza: una fetch grezza si
 * prende il 403 e la data non si scrive, in silenzio.
 *
 * @param {number|string|null|undefined} compilationId l'id della RIGA salvata,
 *        non del modello. Falsy quando non c'è niente da segnare (modifica del
 *        master, bozza nel browser, compilazione mai salvata).
 * @returns {Promise<boolean>} true solo se il server ha confermato.
 */
export async function segnaScaricata(compilationId) {
    const id = Number(compilationId);
    if (!Number.isInteger(id) || id <= 0) return false;

    try {
        const csrf = await fetchCsrf();
        if (!csrf) return false;

        const r = await wafFetch(`/api/risdoc/compilations/${id}/scaricata`, {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams({ _csrf: csrf }).toString(),
        });
        if (!r.ok) return false;

        const j = await r.json().catch(() => null);
        return !!(j && j.ok);
    } catch {
        return false;
    }
}
