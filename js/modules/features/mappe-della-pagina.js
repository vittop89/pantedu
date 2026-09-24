/**
 * Le mappe drawio della pagina di studio: il pulsante «Scarica .drawio» e il
 * file passato al visualizzatore delle mappe grandi (embed + postMessage).
 *
 * 23/9/2026 (revisione architetturale A-19, R-3 passo 3) — erano due funzioni
 * scritte da `StudyPageRenderer` in due `<script>` in linea, con l'XML di ogni
 * mappa dentro il JavaScript: fuori da ESLint e dalle prove, e con la CSP
 * rigorosa eseguibili solo perché il middleware timbrava il nonce su ogni
 * `<script>` del corpo. Il server adesso scrive solo i dati, in un'isola JSON
 * (`<script type="application/json" data-fm-mappe-xml>`), che il browser non
 * esegue; il comportamento sta qui, nel bundle.
 *
 * I dati si leggono quando servono (al clic, al messaggio del visualizzatore),
 * non al caricamento: la pagina di studio arriva anche dal router SPA
 * (`fm-router.js`), che cambia `#fm-content` senza ricaricare il modulo. Prima
 * ogni navigazione aggiungeva un altro ascoltatore dei messaggi, con i dati
 * della pagina di allora; adesso l'ascoltatore è uno e legge la pagina di ora.
 */

const SELETTORE_ISOLA = 'script[type="application/json"][data-fm-mappe-xml]';

/**
 * Le mappe dell'isola (o delle isole) della pagina.
 *
 * @param {ParentNode} [radice]
 * @returns {Record<string, string>} id del contenuto → XML drawio
 */
export function mappeDellaPagina(radice = document) {
    /** @type {Record<string, string>} */
    const mappe = {};
    radice.querySelectorAll(SELETTORE_ISOLA).forEach((isola) => {
        try {
            const dati = JSON.parse(isola.textContent || "{}");
            if (dati && typeof dati === "object" && !Array.isArray(dati)) {
                for (const [id, xml] of Object.entries(dati)) {
                    if (typeof xml === "string") mappe[id] = xml;
                }
            }
        } catch (_) {
            // Un'isola che non si legge non porta mappe: il pulsante lo dice.
        }
    });
    return mappe;
}

/**
 * Il nome del file scaricato: il titolo della mappa senza i caratteri che un
 * sistema operativo rifiuta, al più 80 caratteri, «mappa» se resta vuoto.
 *
 * @param {string} titolo
 */
export function nomeDelFile(titolo) {
    return String(titolo || "").replace(/[\\/:*?"<>|]+/g, "_").trim().slice(0, 80) || "mappa";
}

/**
 * Scarica il .drawio della mappa del pulsante.
 *
 * @param {HTMLElement} pulsante il `.fm-mappa-download-btn`, con `data-fm-content-id`
 * @param {Record<string, string>} mappe
 * @returns {boolean} se il file è partito
 */
export function scaricaMappa(pulsante, mappe) {
    const id = pulsante.dataset.fmContentId || "";
    const xml = mappe[id];
    if (!xml) {
        window.alert("XML non disponibile in pagina (file grande in dev http).");
        return false;
    }
    const titolo = pulsante.closest(".fm-mappa-wrap")?.querySelector(".fm-titolo-quesito")?.textContent || "";
    const url = URL.createObjectURL(new Blob([xml], { type: "application/xml" }));
    const a = document.createElement("a");
    a.href = url;
    a.download = `${nomeDelFile(titolo)}.drawio`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
    return true;
}

/**
 * L'origine della pagina caricata nella cornice, dal suo `src`; null se non si
 * ricava (niente `src`, un indirizzo che non si legge, un'origine opaca).
 *
 * @param {HTMLIFrameElement} cornice
 * @returns {string|null}
 */
export function origineDellaCornice(cornice) {
    const src = cornice.getAttribute("src") || "";
    if (src === "") return null;
    try {
        const origine = new URL(src, cornice.ownerDocument?.baseURI || undefined).origin;
        return origine && origine !== "null" ? origine : null;
    } catch (_) {
        return null;
    }
}

/**
 * Manda il file a una cornice del visualizzatore (protocollo embed di drawio:
 * `{action: "load", xml}`), com'era nello script in linea.
 *
 * Solo all'origine della pagina che la cornice ha caricato, mai a `"*"`
 * (23/9/2026): con `"*"` il file della mappa sarebbe arrivato a qualunque
 * pagina la cornice mostrasse in quel momento, anche se il visualizzatore
 * fosse stato sostituito da un'altra origine (semgrep,
 * wildcard-postmessage-configuration).
 *
 * @param {HTMLIFrameElement} cornice
 * @param {string} xml
 * @returns {boolean} se il file è partito
 */
function mandaIlFile(cornice, xml) {
    const origine = origineDellaCornice(cornice);
    if (origine === null || !cornice.contentWindow) return false;
    cornice.contentWindow.postMessage(JSON.stringify({ action: "load", xml, autosave: 0 }), origine);
    return true;
}

/**
 * Risponde all'`init` del visualizzatore di una mappa grande: la cornice che
 * l'ha mandato riceve il suo file. Solo le cornici della pagina
 * (`#fm-mappa-iframe-<id>`) e solo l'`init`: gli altri messaggi non contano.
 *
 * @param {MessageEvent} evento
 * @param {Document} [doc]
 * @returns {boolean} se ha risposto
 */
export function rispondiAlVisualizzatore(evento, doc = document) {
    let dati;
    try {
        dati = JSON.parse(evento.data);
    } catch (_) {
        return false;
    }
    if (!dati || dati.event !== "init") return false;
    const mappe = mappeDellaPagina(doc);
    for (const [id, xml] of Object.entries(mappe)) {
        const cornice = /** @type {HTMLIFrameElement|null} */ (doc.getElementById(`fm-mappa-iframe-${id}`));
        if (cornice && cornice.contentWindow && cornice.contentWindow === evento.source) {
            // L'init deve venire dall'origine che la cornice ha caricato.
            if (typeof evento.origin === "string" && evento.origin !== origineDellaCornice(cornice)) {
                return false;
            }
            return mandaIlFile(cornice, xml);
        }
    }
    return false;
}

/**
 * Le cornici già in pagina quando il modulo parte ricevono subito il loro file.
 *
 * Lo script in linea stava subito dopo le cornici e si registrava mentre il
 * browser leggeva la pagina; il modulo parte dopo (è differito), e se il
 * visualizzatore, preso dalla cache, avesse già mandato il suo `init`, la mappa
 * resterebbe bianca. Un `load` mandato prima che il visualizzatore ascolti va
 * perso senza danni, e allora arriva l'`init` e risponde l'ascoltatore.
 *
 * @param {Document} [doc]
 * @returns {number} quante cornici hanno ricevuto il file
 */
export function mandaAlleCorniciGiaPronte(doc = document) {
    let quante = 0;
    for (const [id, xml] of Object.entries(mappeDellaPagina(doc))) {
        const cornice = /** @type {HTMLIFrameElement|null} */ (doc.getElementById(`fm-mappa-iframe-${id}`));
        if (cornice && mandaIlFile(cornice, xml)) {
            quante++;
        }
    }
    return quante;
}

/** @param {MouseEvent} e */
function alClic(e) {
    const bersaglio = /** @type {Element|null} */ (e.target instanceof Element ? e.target : null);
    const pulsante = /** @type {HTMLElement|null} */ (bersaglio?.closest(".fm-mappa-download-btn") ?? null);
    if (!pulsante) return;
    e.preventDefault();
    scaricaMappa(pulsante, mappeDellaPagina());
}

if (typeof window !== "undefined" && typeof document !== "undefined" && !window.__fmMappeDellaPagina) {
    window.__fmMappeDellaPagina = true;
    document.addEventListener("click", alClic);
    window.addEventListener("message", (e) => { rispondiAlVisualizzatore(e); });
    mandaAlleCorniciGiaPronte();
}
