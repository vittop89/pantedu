/**
 * Carica a richiesta un'entry di Vite (un editor pesante, un dialogo) leggendo
 * dal manifest il nome del file con l'impronta.
 *
 * 23/9/2026 (revisione architetturale, A-46) — lo stesso caricatore era
 * ricopiato tredici volte in nove file; tre copie non avevano né il
 * cache-bust né la gestione dell'errore. Dopo un rilascio un manifest vecchio
 * punta a un file che non c'è più: l'import falliva dentro un gestore di clic
 * e il pulsante semplicemente non faceva niente. Qui il manifest si legge
 * sempre fresco, le richieste contemporanee della stessa entry ne fanno una
 * sola, e un fallimento lascia una riga in console e un avviso a chi guarda,
 * poi si propaga al chiamante (che decide se fermarsi; l'avviso l'ha già
 * dato il caricatore). Dopo un fallimento, la volta successiva si riprova.
 *
 * Uso:
 *   import { caricaEntry } from "../core/carica-entry.js";
 *   await caricaEntry("js/entries/geogebra-editor.js", {
 *       pronto: () => !!window.FM?.openGeoGebraEditor,
 *   });
 *
 * `pronto` dice quando l'entry ha fatto il suo lavoro: se è già vera non si
 * scarica niente, se dopo l'import è ancora falsa è un errore.
 */

const MANIFEST = "/build/manifest.json";

/** Le entry in caricamento, per sorgente: una richiesta sola alla volta. */
const inCorso = new Map();

/** L'import vero; le prove ne passano uno finto (non c'è un /build/ in Vitest). */
const importaDalBundle = (url) => import(/* @vite-ignore */ url);

/**
 * @param {string} sorgente  la chiave nel manifest, per esempio "js/entries/geogebra-editor.js"
 * @param {{ pronto?: () => boolean, importa?: (url: string) => Promise<unknown> }} [opzioni]
 * @returns {Promise<void>}
 */
export async function caricaEntry(sorgente, { pronto = null, importa = importaDalBundle } = {}) {
    if (pronto?.()) return;
    let caricamento = inCorso.get(sorgente);
    if (!caricamento) {
        // Un avviso per caricamento fallito, non uno per chi lo aspettava.
        caricamento = scaricaEImporta(sorgente, importa)
            .catch((errore) => { avvisa(sorgente, errore); throw errore; })
            .finally(() => inCorso.delete(sorgente));
        inCorso.set(sorgente, caricamento);
    }
    await caricamento;
    if (pronto && !pronto()) {
        const errore = new Error("il modulo è arrivato ma non si è registrato");
        avvisa(sorgente, errore);
        throw errore;
    }
}

async function scaricaEImporta(sorgente, importa) {
    // Il manifest cambia a ogni rilascio e il suo nome no: senza il parametro
    // e `no-store` il browser può tenerne una copia che punta a file spariti.
    // `fetch` e non wafFetch: il manifest è un file statico, servito senza
    // passare dal PHP dove vive il WAF.
    const indirizzo = `${MANIFEST}?t=${Date.now()}`;
    const risposta = await fetch(indirizzo, { credentials: "same-origin", cache: "no-store" });
    if (!risposta.ok) throw new Error(`manifest HTTP ${risposta.status}`);
    const manifest = await risposta.json();
    const voce = manifest?.[sorgente];
    if (!voce?.file) throw new Error(`${sorgente} non è nel manifest`);
    await importa(`/build/${voce.file}`);
}

/** Una riga in console per chi indaga, un avviso per chi sta lavorando. */
function avvisa(sorgente, errore) {
    const motivo = errore?.message || String(errore);
    console.error(`[carica-entry] ${sorgente}:`, errore);
    const nome = sorgente.replace(/^.*\//, "").replace(/\.js$/, "");
    import("../ui/sync-panel.js")
        .then(({ notify }) => notify(
            "Caricamento non riuscito",
            "error",
            `${nome}: ${motivo}. Se l'applicazione è stata appena aggiornata, ricarica la pagina.`,
            8000,
        ))
        .catch(() => { /* resta la riga in console */ });
}
