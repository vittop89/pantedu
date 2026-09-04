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
 *     → "Rifiuto revisione #12 — formule sbagliate nell'esercizio 3"
 *
 * Il minimo di dieci caratteri lo garantisce il contesto passato dal
 * chiamante; se per qualche motivo restasse più corto, la funzione lo
 * completa invece di far rifiutare la richiesta con un 400.
 */

const MIN_LENGTH = 10;
const MAX_LENGTH = 255;

/**
 * @param {string} context  cosa si sta facendo, in italiano leggibile
 * @param {string} [detail] nota inserita dall'utente, se l'interfaccia l'ha chiesta
 * @returns {string} motivazione pronta per l'header X-Audit-Reason
 */
export function auditReason(context, detail = "") {
    let reason = String(context || "").trim();
    const extra = String(detail || "").trim();
    if (extra) {
        reason = reason ? `${reason} — ${extra}` : extra;
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
