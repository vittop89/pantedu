/**
 * Motivazione per le mutazioni amministrative (header `X-Audit-Reason`).
 *
 * PERCHE' ESISTE
 *
 * `RequiresAuditReasonMiddleware` pretende che ogni mutazione di un
 * super-admin porti con sé una motivazione di almeno dieci caratteri, e la
 * scrive in `privileged_access_log`. Fino al 2026-09-02 quasi nessun client la
 * mandava: in produzione 178 righe su 216 avevano come motivazione la stringa
 * letterale `MISSING_OR_INVALID_AUDIT_REASON`. La riga c'era, il motivo no —
 * cioè il registro conservava la forma e buttava via il contenuto.
 *
 * COME SI SCRIVE UNA MOTIVAZIONE
 *
 * Descrittiva dell'azione, non generica: chi legge il registro fra un anno
 * deve capire cosa è successo senza aprire il codice. Se l'interfaccia ha già
 * raccolto una nota dall'utente (la motivazione di un rifiuto, per dire),
 * quella è la parte che conta e va accodata.
 *
 *   auditReason("Rifiuto revisione #12", note)
 *     → "Rifiuto revisione #12 - formule sbagliate nell'esercizio 3"
 *
 * Il minimo di dieci caratteri lo garantisce il contesto passato dal
 * chiamante; se per qualche motivo restasse più corto, la funzione lo
 * completa invece di far rifiutare la richiesta con un 400.
 */

const MIN_LENGTH = 10;
const MAX_LENGTH = 255;

/**
 * UN'INTESTAZIONE HTTP PARLA ISO-8859-1 (21/9/2026)
 *
 * `fetch` rifiuta la richiesta — prima di mandarla — se un valore di
 * intestazione contiene un carattere oltre U+00FF: «String contains non
 * ISO-8859-1 code point». E la motivazione la costruiva questa funzione
 * mettendoci in mezzo una lineetta lunga «—» (U+2014): **ogni** mutazione con
 * un dettaglio partiva con un'intestazione che il browser non sa scrivere, e
 * moriva sul posto. Misurato in Chromium e in Node: «Salva matrice», la
 * rinomina di un gruppo, la creazione di un modello, l'approvazione di una
 * revisione con nota. Il caso senza dettaglio invece passava, ed è la ragione
 * per cui la cosa è rimasta in piedi dal 2/9/2026 senza farsi notare.
 *
 * Le lettere accentate italiane non c'entrano: à, è, ò stanno dentro
 * ISO-8859-1 e passano. Fuori stanno la punteggiatura tipografica e le emoji.
 */
const PUNTEGGIATURA_TIPOGRAFICA = [
    [/[‒-―]/g, "-"],      // lineette lunghe
    [/[‘’‛]/g, "'"], // apostrofi ricurvi
    [/[“”]/g, '"'],       // virgolette ricurve
    [/…/g, "..."],             // puntini di sospensione
    [/[   ]/g, " "], // spazi che non vanno a capo
];

/**
 * Rende una stringa scrivibile in un'intestazione: prima traduce i caratteri
 * tipografici nel loro equivalente ASCII, poi butta quello che resta fuori
 * (emoji e alfabeti altri). Meglio una parola in meno nel registro che una
 * richiesta che non parte.
 *
 * @param {string} testo
 */
export function perIntestazione(testo) {
    let out = String(testo);
    for (const [cerca, metti] of PUNTEGGIATURA_TIPOGRAFICA) {
        out = out.replace(cerca, metti);
    }
    out = [...out].filter((c) => c.codePointAt(0) <= 0xFF).join("");
    return out.replace(/\s{2,}/g, " ").trim();
}

/**
 * @param {string} context  cosa si sta facendo, in italiano leggibile
 * @param {string} [detail] nota inserita dall'utente, se l'interfaccia l'ha chiesta
 * @returns {string} motivazione pronta per l'header X-Audit-Reason
 */
export function auditReason(context, detail = "") {
    let reason = perIntestazione(String(context || ""));
    const extra = perIntestazione(String(detail || ""));
    if (extra) {
        reason = reason ? `${reason} - ${extra}` : extra;
    }
    if (!reason) {
        reason = "Operazione amministrativa dal pannello";
    }
    if (reason.length < MIN_LENGTH) {
        reason = `${reason} (pannello amministrazione)`;
    }
    return reason.slice(0, MAX_LENGTH);
}

/**
 * Header pronti per una fetch di mutazione: content-type, CSRF e motivazione.
 * Comodo dove la chiamata non ha già un oggetto headers costruito a mano.
 *
 * @param {string} csrf
 * @param {string} context
 * @param {string} [detail]
 * @param {string} [contentType]
 */
export function auditHeaders(csrf, context, detail = "", contentType = "application/x-www-form-urlencoded") {
    return {
        "Content-Type": contentType,
        "X-CSRF-Token": csrf,
        "X-Audit-Reason": auditReason(context, detail),
    };
}
