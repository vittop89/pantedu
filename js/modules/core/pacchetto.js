/**
 * Il pacchetto TeX arriva nella risposta, non per indirizzo.
 *
 * 21/9/2026 — fino a oggi l'esportazione rispondeva con un JSON contenente
 * l'indirizzo di un file che il server aveva scritto su disco, e il browser
 * andava a prenderselo con un secondo giro. Adesso il pacchetto **è** la
 * risposta: niente file da sorvegliare, niente indirizzo che qualcun altro
 * potrebbe seguire.
 *
 * Il cambio ha un effetto che il client deve gestire: la stessa rotta risponde
 * in due modi — l'archivio quando va bene, un JSON quando c'è un errore da
 * mostrare al docente (per esempio «questo documento non ha un corpo da
 * esportare»). Chi chiama non deve indovinare: lo dice `Content-Type`.
 */

/**
 * Il nome del file dall'intestazione, o quello di ripiego.
 *
 * Stessa lettura che il progetto fa già in `js/modules/core/api.js`.
 */
export function nomeDallIntestazione(disposition, diRipiego) {
    if (!disposition) return diRipiego;
    const m = /filename\s*=\s*"?([^";]+)"?/i.exec(disposition);
    if (!m) return diRipiego;
    try {
        return decodeURIComponent(m[1]) || diRipiego;
    } catch {
        return m[1] || diRipiego;
    }
}

/**
 * Legge la risposta di un'esportazione: o il pacchetto, o l'errore da mostrare.
 *
 * @param {Response} risposta
 * @param {string} nomeDiRipiego usato se il server non dice come si chiama
 * @returns {Promise<{blob: Blob, nome: string}>}
 * @throws {Error} con il messaggio del server, quando il server ne manda uno
 */
export async function pacchettoDallaRisposta(risposta, nomeDiRipiego = "pacchetto.zip") {
    const tipo = risposta.headers.get("Content-Type") || "";

    if (tipo.includes("application/json")) {
        // L'errore vero del server, non «HTTP 400»: certi messaggi li legge il
        // docente parola per parola.
        let messaggio = `esportazione non riuscita (${risposta.status})`;
        try {
            const j = await risposta.json();
            if (j && (j.error || j.message)) messaggio = String(j.error || j.message);
        } catch { /* corpo non leggibile: resta il messaggio di sopra */ }
        throw new Error(messaggio);
    }

    if (!risposta.ok) {
        throw new Error(`esportazione non riuscita (${risposta.status})`);
    }

    return {
        blob: await risposta.blob(),
        nome: nomeDallIntestazione(risposta.headers.get("Content-Disposition"), nomeDiRipiego),
    };
}

/**
 * Consegna il pacchetto a chi l'ha chiesto, e non lascia niente nemmeno qui:
 * l'indirizzo temporaneo del browser si revoca subito dopo.
 *
 * @param {{blob: Blob, nome: string}} pacchetto
 */
export function scaricaPacchetto({ blob, nome }) {
    const indirizzo = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = indirizzo;
    a.download = nome || "pacchetto.zip";
    a.style.display = "none";
    document.body.appendChild(a);
    a.click();
    a.remove();
    // Un istante di margine: alcuni browser leggono l'indirizzo dopo il click.
    setTimeout(() => URL.revokeObjectURL(indirizzo), 1000);
}
