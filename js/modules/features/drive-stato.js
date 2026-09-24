/**
 * Lo stato di Drive per il docente: che cosa dire e quali comandi offrire
 * (ADR-038, 14/9/2026).
 *
 * Il server dà due stati. Quello dell'installazione (`istanza`: acceso,
 * spento, guasto) e quello del collegamento del docente (`stato`: attivo o
 * da_ricollegare, con il motivo e da quando). Qui diventano una frase e dei
 * comandi. È una funzione pura perché i casi si provino tutti anche dove
 * Drive è spento, come in CI, dove la suite end-to-end ne vede uno solo.
 *
 * Scollegare si offre in ogni stato in cui c'è un collegamento: l'informativa
 * promette che il docente può farlo «in qualsiasi momento».
 */

/** Perché un collegamento va rifatto, detto al docente. */
export const MOTIVI = Object.freeze({
    accesso_revocato:
        "Google non accetta più il collegamento: l'accesso è stato revocato dal tuo account Google, oppure è scaduto.",
    permessi_insufficienti:
        "al collegamento manca il permesso su Drive. Ricollega e lascia spuntata la casella di Drive.",
});

/** I messaggi al ritorno da Google (`?drive=` nell'indirizzo del cruscotto). */
export const RITORNI = Object.freeze({
    connected: "✅ Drive collegato con successo.",
    denied: "⚠️ Hai negato il consenso. Drive non collegato.",
    error: "❌ Errore durante la connessione a Drive.",
    non_disponibile: "⚠️ Drive non è disponibile su questa installazione.",
    permessi:
        "⚠️ Google non ha dato il permesso su Drive, e il collegamento non è stato salvato. Ricollega e lascia spuntata la casella di Drive.",
});

/**
 * Il testo per una chiave, solo se è una chiave vera dell'elenco: una parola
 * dall'indirizzo come «constructor» non deve trovare niente.
 *
 * @param {Readonly<Record<string, string>>} elenco
 * @param {unknown} chiave
 * @returns {string | null}
 */
export function testoPer(elenco, chiave) {
    // hasOwnProperty.call e non Object.hasOwn: il pacchetto punta a es2020.
    return typeof chiave === "string" && Object.prototype.hasOwnProperty.call(elenco, chiave) ? elenco[chiave] : null;
}

/**
 * @param {Record<string, any> | null | undefined} dati la risposta di /teacher/drive/status.json
 * @returns {{ stato: "connected" | "disconnected" | "warning" | "error", testo: string, azioni: Array<"collega" | "ricollega" | "disconnetti"> }}
 */
export function descriviDrive(dati) {
    const istanza = String(dati?.istanza || "spento");
    const collegato = Boolean(dati?.connected);
    const email = dati?.email ? ` (${dati.email})` : "";

    if (istanza !== "acceso") {
        const perche = istanza === "guasto"
            ? "Drive non è disponibile: la configurazione del server è incompleta, e l'amministratore ne riceve l'avviso."
            : "Drive non è attivo su questa installazione.";
        if (!collegato) {
            return { stato: "disconnected", testo: perche, azioni: [] };
        }
        return {
            stato: "warning",
            testo: `${perche} Il tuo collegamento${email} è ancora salvato: puoi disconnetterlo.`,
            azioni: ["disconnetti"],
        };
    }

    if (!collegato) {
        return { stato: "disconnected", testo: "Non collegato.", azioni: ["collega"] };
    }

    if (dati?.stato === "da_ricollegare") {
        const motivo = testoPer(MOTIVI, dati.motivo) || "Google ha rifiutato il collegamento.";
        const dal = dati.dal ? ` dal ${dati.dal}` : "";
        return {
            stato: "warning",
            testo: `Da ricollegare${dal}${email}: ${motivo} Finché non ricolleghi, la sincronizzazione è ferma.`,
            azioni: ["ricollega", "disconnetti"],
        };
    }

    const ultima = dati?.last_sync_at ? ` · ultima sincronizzazione ${dati.last_sync_at}` : " · mai sincronizzato";
    return { stato: "connected", testo: `Collegato${email}${ultima}`, azioni: ["disconnetti"] };
}

/**
 * Drive è acceso su questa installazione? Lo scrive il server nella barra del
 * docente (`data-fm-drive`). Senza la barra, o senza l'attributo, no.
 *
 * @param {ParentNode | null | undefined} radice
 */
export function driveAcceso(radice = globalThis.document) {
    const barra = radice?.querySelector?.(".fm-session-banner--teacher");
    return /** @type {HTMLElement | null | undefined} */ (barra)?.dataset?.fmDrive === "acceso";
}

/**
 * Che cosa dire quando il server ferma un lotto di sincronizzazione (`fermato`
 * nel resoconto): vale per tutto il docente, non per un file.
 *
 * @param {string} codice
 * @param {string | undefined} motivo
 */
export function motivoDelFermo(codice, motivo) {
    switch (codice) {
        case "drive_da_ricollegare":
            return `Drive va ricollegato: ${testoPer(MOTIVI, motivo) || "Google ha rifiutato il collegamento."} Cruscotto → ☁ Sincronizzazione → ☁ Google Drive → Ricollega.`;
        case "drive_not_connected":
            return "Drive non è collegato: Cruscotto → ☁ Sincronizzazione → ☁ Google Drive → Collega Drive.";
        case "drive_spento":
            return "Drive non è attivo su questa installazione.";
        case "drive_guasto":
            return "Drive non è disponibile: la configurazione del server è incompleta.";
        default:
            return `Sincronizzazione fermata (${codice}).`;
    }
}
