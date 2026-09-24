/**
 * I campi che un documento sa risolvere da sé.
 *
 * Segnalazione dell'utente (21/9/2026): «cliccando sul pulsante "Campo" mi esce
 * un popup con il solo `sezione`, ma ci dovrebbero essere molti più campi —
 * classe, docente, disciplina, indirizzo…».
 *
 * L'elenco delle proposte era «le chiavi che per caso stanno nello stato del
 * documento adesso»: su un modello aperto senza contesto era una voce sola. E
 * siccome lo stato porta anche roba tecnica (la spunta dell'intestazione,
 * l'orientamento della pagina, i colori), quelle chiavi finivano fra i campi
 * proponibili — inserire `[field-includeHeader]` non vuol dire niente.
 *
 * Qui stanno i nomi veri, quelli che il compilatore risolve:
 *   - i quattro del contesto (`classe`, `sezione`, `indirizzo`, `disciplina`),
 *     che arrivano dai selettori del documento;
 *   - quelli che il server ricava da solo (`nome_docente`, `istituto`, `sede`,
 *     `anno_scolastico`), in `App\Services\Risdoc\StatoDelDocumento`.
 *
 * I due elenchi devono restare d'accordo: lo misura
 * `tests/Unit/Services/Risdoc/StatoDelDocumentoTest.php`.
 */

/** Il contesto: lo scelgono i selettori del documento o la barra laterale. */
export const CAMPI_DI_CONTESTO = ["classe", "sezione", "indirizzo", "disciplina"];

/** Li ricava il server al momento della generazione, nessuno li compila. */
export const CAMPI_DERIVATI = ["nome_docente", "istituto", "sede", "anno_scolastico"];

/** Tutti quelli che si possono inserire sapendo che usciranno pieni. */
export const CAMPI_DEL_DOCUMENTO = [...CAMPI_DI_CONTESTO, ...CAMPI_DERIVATI];

/**
 * Chiavi dello stato che NON sono campi del documento: impostazioni della
 * pagina e della compilazione, non testo da stampare.
 */
const TECNICHE = new Set([
    "includeHeader", "includeHeaderHtml", "styleOverrides", "pageOrientation",
    "headerTitle", "renderMode", "terna", "ternaScoped",
]);

/**
 * I campi da proporre in «Inserisci riferimento a campo».
 *
 * @param {Record<string, unknown>} [stato] stato corrente del documento
 * @param {string[]} [selettori] selettori dichiarati dalla sezione
 * @returns {string[]} nomi, senza ripetizioni, nell'ordine in cui si leggono
 */
export function campiOfferti(stato = {}, selettori = []) {
    const fuori = new Set();
    const dentro = [];
    const aggiungi = (nome) => {
        if (typeof nome !== "string" || nome === "" || TECNICHE.has(nome)) return;
        if (fuori.has(nome)) return;
        fuori.add(nome);
        dentro.push(nome);
    };
    CAMPI_DEL_DOCUMENTO.forEach(aggiungi);
    for (const [nome, valore] of Object.entries(stato || {})) {
        // Solo quello che si può stampare: un oggetto o una lista nel testo
        // non ci va, e nello stato ce ne sono (gli scostamenti di stile).
        if (valore !== null && valore !== undefined
            && typeof valore !== "string" && typeof valore !== "number") continue;
        aggiungi(nome);
    }
    (Array.isArray(selettori) ? selettori : []).forEach(aggiungi);
    return dentro;
}
