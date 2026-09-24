/**
 * Il «🔗 Link esterno» del modale di creazione, quando il link è un file drawio
 * su Google Drive (15/9/2026). Logica pura, provata in
 * tests/js-unit/mappa-da-link.test.js; il modale è sidepage-modal-content.js.
 *
 * Un link così si manda a `/api/maps` con `mode=link`: il server scarica il file
 * una volta e lo salva come le altre mappe (App\Services\Maps\MappaDaLinkDrive,
 * che riconosce le stesse forme). Prima il contenuto nasceva con il solo link, e
 * la pagina di studio diceva «Mappa non disponibile localmente (orphan)».
 * Ogni altro link (un sito, un PDF) resta un collegamento.
 */

const ID = /^[A-Za-z0-9_-]{20,}$/;
const DRIVE = new Set(["drive.google.com", "drive.usercontent.google.com", "docs.google.com"]);
const DIAGRAMS = new Set(["viewer.diagrams.net", "app.diagrams.net", "embed.diagrams.net"]);

/**
 * Il link porta a un file su Google Drive (anche dentro un link di
 * diagrams.net: `#U<indirizzo>`, `#G<id>`, `?url=`)?
 *
 * @param {string} href
 * @returns {boolean}
 */
export function eLinkDrive(href) {
    let u;
    try {
        u = new URL(String(href || "").trim());
    } catch {
        return false;
    }
    if (u.protocol !== "https:" && u.protocol !== "http:") return false;
    const host = u.hostname.toLowerCase();
    if (DIAGRAMS.has(host)) {
        const frammento = u.hash.replace(/^#/, "");
        if (frammento.startsWith("U")) {
            try {
                return eLinkDrive(decodeURIComponent(frammento.slice(1)));
            } catch {
                return false;
            }
        }
        if (frammento.startsWith("G")) return ID.test(frammento.slice(1));
        const url = u.searchParams.get("url");
        return url ? eLinkDrive(url) : false;
    }
    if (!DRIVE.has(host)) return false;
    const suFile = u.pathname.match(/\/file\/d\/([A-Za-z0-9_-]{20,})(?:\/|$)/);
    return !!suFile || ID.test(u.searchParams.get("id") || "");
}

const MESSAGGI = {
    link_non_pubblico: "Il file su Drive non è pubblico: in Drive scegli «Condividi → Chiunque abbia il link» e riprova.",
    link_non_drawio: "Il link non porta a un file drawio.",
    link_non_raggiungibile: "Non riesco a scaricare il file da Drive adesso: riprova tra poco, o usa «Carica file».",
    link_non_drive: "Il link non è di un file su Google Drive.",
    payload_too_large: "Il file è troppo grande per una mappa.",
    // «Carica file» accetta solo un drawio dal 23/9/2026 (app/Services/Maps/FileDrawio.php).
    drawio_non_valido: "Il file non è un drawio: una mappa si carica solo come file .drawio (o .xml) salvato da diagrams.net.",
};

/**
 * Il messaggio per l'errore che risponde il server, o null se non è un errore
 * del link.
 *
 * @param {string} codice
 * @returns {string|null}
 */
export function messaggioErroreLink(codice) {
    return MESSAGGI[codice] || null;
}
