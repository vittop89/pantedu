/**
 * Aprire una pagina pretendendo che sia davvero quella pagina.
 *
 * PERCHÉ ESISTE
 *   Il 20 settembre 2026 quindici immagini attese della regressione visiva —
 *   cinque pagine per tre misure — ritraevano la pagina «404 — Not Found».
 *   Non una: quindici, tutte identiche a gruppi di cinque, perché erano lo
 *   stesso errore fotografato tre volte. La causa era banale: la spec
 *   chiedeva `/area_docente/profilo` dove la rotta è `/area-docente/profilo`,
 *   e `/privacy` dove la rotta è `/privacy/informativa`.
 *
 *   Il guaio non è il refuso, è che nessuno se ne è accorto per una
 *   settimana: `toMatchSnapshot` confrontava un 404 con un 404 e rispondeva
 *   «uguali». Verde pieno, zero pagine guardate — la forma di guasto che
 *   questo progetto insegue.
 *
 *   Un confronto a pixel non può accorgersene da solo: due immagini uguali
 *   sono uguali, qualunque cosa ritraggano. Deve dirglielo qualcun altro,
 *   prima dello scatto. Questo.
 *
 * COSA GUARDA, E PERCHÉ DUE COSE E NON UNA
 *   1. il codice HTTP della navigazione, che dev'essere 200;
 *   2. che in pagina non ci sia il titolo delle pagine d'errore.
 *
 *   Servono tutti e due perché nessuno dei due copre l'altro. Il codice non
 *   vede gli errori che l'applicazione rende con 200 (un controller che
 *   risponde «non hai i permessi» dentro una pagina regolare); il titolo non
 *   vede una pagina d'errore scritta senza quel marcatore, né un redirect
 *   finito altrove. Presi insieme, il caso di settembre cade su entrambi.
 *
 * COSA NON GUARDA
 *   Il contenuto degli iframe. `page.locator` cerca nel frame principale, e
 *   così deve essere: la pagina sotto esame è quella esterna. Ma vuol dire
 *   che un errore *dentro* un riquadro incorporato passa.
 *
 *   Il caso che restava aperto — `osservazione-desktop` e
 *   `osservazione-tablet`, che ritraevano il 404 dell'applicazione dentro
 *   l'iframe di Grafana — è stato chiuso il 20 settembre 2026, ma **non**
 *   insegnando a questa guardia a scendere nei frame figli. Si era valutato:
 *   non serviva a niente. Guardare dentro quel riquadro avrebbe reso la prova
 *   rossa in CI, dove Grafana legittimamente non gira (`/grafana/` è una
 *   `location` dell'nginx di produzione, non una rotta dell'applicazione:
 *   `routes/web.php` non la nomina), e un'opzione senza chiamanti è codice
 *   che invecchia senza che nessuno se ne accorga.
 *
 *   La cura è stata togliere dalla fotografia quel che non è fotografabile —
 *   `preparaLoScatto` nasconde il contenuto del riquadro e ne tiene la
 *   cornice — e dare all'incorporamento una prova che lo misura invece di
 *   ritrarlo: `tests/e2e/admin/osservazione-e-il-riquadro-di-grafana.spec.js`.
 *
 *   Resta vero il principio: se un giorno una pagina fotografata incorpora un
 *   riquadro servito **dall'applicazione stessa**, lì un controllo sui frame
 *   figli avrebbe senso e questa guardia è il posto dove aggiungerlo.
 */
import type { Page, Response } from "@playwright/test";

/**
 * Il marcatore delle pagine d'errore dell'applicazione.
 *
 * `views/errors/generic.php` è il solo posto del repository che usi questa
 * classe, e ci passano tutte: 404, 403, 500. Si guarda la struttura e non il
 * testo perché il testo è tradotto e cambia; la classe no.
 */
export const SELETTORE_PAGINA_DI_ERRORE = ".fm-error-title";

/**
 * Apre `percorso` e restituisce la risposta, a patto che sia una pagina vera.
 * Altrimenti solleva, dicendo che cosa è arrivato davvero.
 *
 * @param pagina  la scheda su cui navigare (già autenticata se serve)
 * @param percorso  il percorso da aprire, relativo alla base della suite
 */
export async function apriPaginaVera(pagina: Page, percorso: string): Promise<Response> {
    const risposta = await pagina.goto(percorso, { waitUntil: "domcontentloaded" });

    // `goto` restituisce null quando non c'è stata una vera navigazione (per
    // esempio un salto a un'ancora della stessa pagina). Qui vorrebbe dire
    // che si sta fotografando quello che c'era prima.
    if (risposta === null) {
        throw new Error(
            `la pagina «${percorso}» non è una pagina vera: la navigazione non ha prodotto nessuna risposta`,
        );
    }

    const stato = risposta.status();
    if (stato !== 200) {
        throw new Error(
            `la pagina «${percorso}» non è una pagina vera: ha risposto ${stato}, ` +
                `e l'indirizzo finale è ${risposta.url()}`,
        );
    }

    const errore = pagina.locator(SELETTORE_PAGINA_DI_ERRORE);
    if ((await errore.count()) > 0) {
        const titolo = (await errore.first().innerText()).trim();
        throw new Error(
            `la pagina «${percorso}» non è una pagina vera: ha risposto 200 ` +
                `ma mostra la pagina d'errore «${titolo}»`,
        );
    }

    return risposta;
}
