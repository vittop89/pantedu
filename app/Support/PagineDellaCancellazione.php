<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\Gdpr\DeletionRequestService;
use App\Services\Gdpr\RichiestaDiCancellazione;

/**
 * Le risposte della cancellazione dell'account (24/9/2026): quando rispondere
 * con una pagina invece che con JSON, le frasi dell'esito, a chi scrivere, e
 * la pagina nel layout del sito. Le usa SelfServiceController; stanno qui
 * perché il controller restasse sotto la soglia delle dimensioni
 * (tools/ci/dimensioni.json) e perché sono presentazione, non logica.
 *
 * @phpstan-import-type Esito from RichiestaDiCancellazione
 */
final class PagineDellaCancellazione
{
    /**
     * Pagina o JSON? Pagina solo se il client chiede HTML (Accept con
     * text/html: il browser che apre il collegamento dell'email, il modulo di
     * «I tuoi dati», la navigazione del sito) e non chiede JSON
     * (Request::wantsJson, il criterio dei middleware). «Non chiede JSON» da
     * solo non basta: fetch() e il client delle prove end-to-end mandano un
     * Accept generico, e continuano a ricevere JSON.
     */
    public static function vuolePagina(Request $req): bool
    {
        $accept = strtolower((string)($req->headers['accept'] ?? ''));
        return !$req->wantsJson() && str_contains($accept, 'text/html');
    }

    /**
     * Le frasi della risposta alla richiesta, secondo l'esito: mai «inviata»
     * se l'email non è partita. Quando la richiesta non è valida, a chi
     * scrivere lo aggiungono il JSON (in testo) e la pagina (con i
     * collegamenti).
     *
     * @param Esito $esito
     * @return list<string>
     */
    public static function righeDellaRichiesta(array $esito): array
    {
        $giorniCollegamento = DeletionRequestService::TOKEN_EXPIRY_DAYS;
        $giorniRipensamento = DeletionRequestService::COOLING_OFF_DAYS;
        if ($esito['esito'] === RichiestaDiCancellazione::GIA_CONFERMATA) {
            $quando = strtotime((string)$esito['eseguita_il']);
            return [
                'La cancellazione del tuo account è già confermata'
                . ($quando !== false ? ': sarà eseguita il ' . date('d/m/Y', $quando) : '')
                . '. Non ne abbiamo registrata un\'altra.',
                'Se hai cambiato idea, fino ad allora la puoi annullare dalla pagina «I tuoi dati» '
                . '(/privacy/your-data), con il pulsante «Annulla la richiesta di cancellazione».',
            ];
        }
        if ($esito['esito'] === RichiestaDiCancellazione::INVIATA) {
            return [
                "Ti abbiamo scritto a {$esito['indirizzo']}: apri il collegamento dell'email entro "
                . "$giorniCollegamento giorni e premi il pulsante della pagina per confermare la cancellazione.",
                "Dopo la conferma la cancellazione viene eseguita fra $giorniRipensamento giorni, e fino ad "
                . 'allora la puoi annullare dalla pagina «I tuoi dati». Finché non confermi, non succede niente.',
            ];
        }
        $perche = match ($esito['esito']) {
            RichiestaDiCancellazione::SENZA_POSTA     => 'questa installazione non manda posta',
            RichiestaDiCancellazione::SENZA_INDIRIZZO => 'il tuo account non ha un indirizzo email valido',
            RichiestaDiCancellazione::SENZA_RADICE    => 'manca l\'indirizzo del sito per il collegamento',
            default                                   => 'il servizio di posta non l\'ha accettata',
        };
        if ($esito['valida']) {
            // Solo fuori dalla produzione, o con EXPOSE_DELETION_DEBUG_TOKEN.
            return [
                "L'email di conferma non è partita: $perche.",
                'In questo ambiente di prova la richiesta resta valida e il collegamento di conferma è nella '
                . 'risposta; in produzione la richiesta sarebbe stata ritirata.',
            ];
        }
        $righe = [
            "Non siamo riusciti a mandarti l'email di conferma ($perche), quindi la richiesta di "
            . 'cancellazione non è stata registrata.',
        ];
        if ($esito['precedente']) {
            $righe[] = 'La richiesta che avevi fatto prima resta valida: il collegamento della sua email '
                . 'funziona fino alla scadenza.';
        }
        return $righe;
    }

    /**
     * 409 se la cancellazione è già confermata o all'account manca
     * l'indirizzo, 503 se mancano la posta o la configurazione.
     */
    public static function statoDelRifiuto(string $esito): int
    {
        $conflitto = [RichiestaDiCancellazione::SENZA_INDIRIZZO, RichiestaDiCancellazione::GIA_CONFERMATA];
        return \in_array($esito, $conflitto, true) ? 409 : 503;
    }

    /**
     * Il recapito privacy del titolare (`mail.dpo_email`: DPO_EMAIL, o
     * CONTACT_EMAIL), come le altre pagine dei diritti. Vuota, resta il modulo
     * per le richieste sulla privacy (/dpo-contact), che c'è sempre e salva la richiesta anche quando la
     * posta non parte.
     */
    public static function casellaDpo(): string
    {
        return trim((string)Config::get('mail.dpo_email', ''));
    }

    public static function comeScrivereAlDpo(): string
    {
        $dpo = self::casellaDpo();
        return $dpo !== ''
            ? "scrivi a $dpo, oppure usa il modulo per le richieste sulla privacy (/dpo-contact)"
            : 'usa il modulo per le richieste sulla privacy (/dpo-contact)';
    }

    /** @return array{error: string, message: string} */
    public static function gettoneNonValido(): array
    {
        return [
            'error'   => 'token_invalid_or_expired',
            'message' => 'Il token è non valido, già usato, o scaduto. Avvia una nuova richiesta.',
        ];
    }

    /** @param bool $gettoneNellIndirizzo la pagina del collegamento (GET con ?token=) */
    public static function collegamentoNonValido(bool $gettoneNellIndirizzo = false): Response
    {
        return self::pagina('Collegamento non valido', 'errore', [
            'Il collegamento non vale più: è scaduto, è già stato usato, oppure la richiesta è stata '
            . 'annullata o sostituita da una più recente.',
            'Se vuoi ancora cancellare l\'account, chiedilo di nuovo dalla pagina «I tuoi dati».',
        ], 400, ['gettoneNellIndirizzo' => $gettoneNellIndirizzo]);
    }

    /**
     * Una pagina nel layout del sito (layout/shell, come «Il mio account»),
     * con il messaggio e il ritorno a «I tuoi dati». Con
     * `gettoneNellIndirizzo` (la pagina del collegamento dell'email, che ha il
     * gettone nella query string) la pagina non manda il Referer:
     * PaginaConGettone.
     *
     * @param list<string> $righe paragrafi di testo semplice: la vista li escapa
     * @param array{
     *     gettone?: string,
     *     contatto?: bool,
     *     collegamentoDiProva?: ?string,
     *     gettoneNellIndirizzo?: bool
     * } $altro
     */
    public static function pagina(
        string $titolo,
        string $esito,
        array $righe,
        int $stato = 200,
        array $altro = [],
    ): Response {
        $view = View::default();
        $layout = [
            'title' => $titolo . ' — Pantedu',
            'body'  => $view->render('profile/cancellazione', [
                'titolo'              => $titolo,
                'esito'               => $esito,
                'righe'               => $righe,
                'csrf'                => Csrf::token(),
                'gettone'             => $altro['gettone'] ?? null,
                'contatto'            => (bool)($altro['contatto'] ?? false),
                'dpo'                 => self::casellaDpo(),
                'collegamentoDiProva' => $altro['collegamentoDiProva'] ?? null,
            ]),
            'modal' => true,
        ];
        return ($altro['gettoneNellIndirizzo'] ?? false)
            ? PaginaConGettone::html($layout, $stato)
            : Response::html($view->render('layout/shell', $layout), $stato);
    }
}
