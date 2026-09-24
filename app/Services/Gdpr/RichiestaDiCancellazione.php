<?php

declare(strict_types=1);

namespace App\Services\Gdpr;

use App\Core\Database;
use App\Services\Mailer;
use App\Support\Anomalia;
use App\Support\IndirizzoPubblico;
use App\Support\IndirizzoPubblicoMancante;
use PDO;
use Throwable;

/**
 * La richiesta di cancellazione dell'account (art. 17) con la sua email di
 * conferma (24/9/2026).
 *
 * IL DIFETTO
 *   Fino a quel giorno POST /me/request-deletion creava la richiesta e
 *   rispondeva «Email di conferma inviata. Controlla la casella…», ma
 *   nessuna email partiva: il gettone in produzione non lo riceveva nessuno,
 *   e la cancellazione promessa dall'informativa (email di conferma, poi
 *   trenta giorni di ripensamento) non si poteva completare.
 *
 * L'ORDINE, E PERCHÉ
 *   0. Se una cancellazione è già confermata (`cooling_off`), non se ne crea
 *      un'altra: la risposta dice quando sarà eseguita e come annullarla.
 *      Fino al 24/9/2026 la richiesta nuova la ritirava subito, e se poi la
 *      sua email non partiva non restava niente: la cancellazione confermata
 *      spariva senza che l'utente l'avesse annullata.
 *   1. I controlli che non hanno bisogno del gettone: c'è un mittente
 *      (`Mailer::fromConfig()`), l'account ha un indirizzo valido, c'è la
 *      radice dei collegamenti (`app.url`). Se ne manca uno la richiesta non
 *      si crea affatto.
 *   2. La richiesta e il gettone (`crea()`, che non tocca le altre): il
 *      collegamento li contiene, quindi vengono prima dell'invio.
 *   3. L'invio. Se `send()` risponde false o lancia, si ritira solo la
 *      richiesta appena creata: un gettone che nessuno ha ricevuto non deve
 *      restare valido per sette giorni. Una richiesta di prima ancora in
 *      attesa resta com'era, con il collegamento che il suo destinatario ha
 *      già ricevuto.
 *   4. Solo a email partita la richiesta nuova sostituisce quelle di prima
 *      ancora in attesa (`ritiraLeAltreInAttesa()`).
 *
 * L'ECCEZIONE DELLE PROVE
 *   Fuori dalla produzione, o con EXPOSE_DELETION_DEBUG_TOKEN, il gettone
 *   torna al chiamante nella risposta (`debug_token`): è così che la suite
 *   end-to-end, che non ha una casella di posta, percorre la cancellazione.
 *   Lì il gettone un destinatario ce l'ha, e la richiesta resta valida anche
 *   senza email; l'esito dice comunque che l'email non è partita.
 *
 * @phpstan-type Esito array{
 *     esito: string,
 *     valida: bool,
 *     gettone: ?string,
 *     indirizzo: string,
 *     eseguita_il: ?string,
 *     precedente: bool
 * }
 */
final class RichiestaDiCancellazione
{
    public const INVIATA         = 'inviata';
    public const SENZA_POSTA     = 'senza_posta';
    public const SENZA_INDIRIZZO = 'senza_indirizzo';
    public const SENZA_RADICE    = 'senza_radice';
    public const INVIO_FALLITO   = 'invio_fallito';
    public const GIA_CONFERMATA  = 'gia_confermata';

    /** Il codice dell'anomalia quando una richiesta si ritira perché l'email non è partita. */
    public const ANOMALIA = 'cancellazione_senza_email';

    private ?PDO $pdo;

    /** @var callable(): ?Mailer */
    private $posta;

    private DeletionRequestService $cancellazioni;

    /**
     * @param (callable(): ?Mailer)|null $posta di norma Mailer::fromConfig
     */
    public function __construct(
        ?PDO $pdo = null,
        ?callable $posta = null,
        ?DeletionRequestService $cancellazioni = null,
    ) {
        $this->pdo = $pdo;
        $this->posta = $posta ?? static fn(): ?Mailer => Mailer::fromConfig();
        $this->cancellazioni = $cancellazioni ?? new DeletionRequestService();
    }

    /**
     * Crea la richiesta e manda il collegamento di conferma all'indirizzo
     * dell'account.
     *
     * @param bool $gettoneAlChiamante il gettone torna nella risposta (prove)
     *
     * @return Esito
     *   `esito` una delle costanti; `valida` se la richiesta nuova esiste ed è
     *   in attesa di conferma; `gettone` solo con $gettoneAlChiamante e una
     *   richiesta valida; `indirizzo` dove l'email è andata, o sarebbe andata;
     *   `eseguita_il` con GIA_CONFERMATA, quando la cancellazione confermata
     *   sarà eseguita; `precedente` se, senza una richiesta nuova valida, ne
     *   resta una di prima in attesa di conferma
     */
    public function richiedi(int $utente, ?string $motivo, ?string $ip, bool $gettoneAlChiamante): array
    {
        $account = $this->account($utente);
        $indirizzo = trim((string)($account['email'] ?? ''));
        $risposta = static fn(
            string $esito,
            bool $valida,
            ?string $gettone = null,
            ?string $eseguitaIl = null,
            bool $precedente = false,
        ): array => [
            'esito' => $esito, 'valida' => $valida, 'gettone' => $gettone, 'indirizzo' => $indirizzo,
            'eseguita_il' => $eseguitaIl, 'precedente' => $precedente,
        ];

        $inCorso = $this->cancellazioni->activeRequest($utente);
        if ($inCorso !== null && $inCorso['status'] === 'cooling_off') {
            return $risposta(self::GIA_CONFERMATA, false, eseguitaIl: $inCorso['execute_after']);
        }
        // Una richiesta di prima ancora in attesa di conferma (e non scaduta).
        $precedente = $inCorso !== null;

        $mailer = null;
        $sito = '';
        $problema = null;
        try {
            $mailer = ($this->posta)();
        } catch (Throwable $e) {
            error_log('[cancellazione_account] mittente non costruito: ' . $e->getMessage());
        }
        if (!$mailer instanceof Mailer) {
            $problema = self::SENZA_POSTA;
        } elseif ($indirizzo === '' || !filter_var($indirizzo, FILTER_VALIDATE_EMAIL)) {
            $problema = self::SENZA_INDIRIZZO;
        } else {
            try {
                $sito = IndirizzoPubblico::radice('cancellazione_account');
            } catch (IndirizzoPubblicoMancante) {
                // L'anomalia l'ha già registrata IndirizzoPubblico.
                $problema = self::SENZA_RADICE;
            }
        }

        if ($problema !== null && !$gettoneAlChiamante) {
            return $risposta($problema, false, precedente: $precedente);
        }

        $nuova = $this->cancellazioni->crea($utente, $motivo, $ip);
        $risultato = $problema;
        if ($risultato === null) {
            $partita = $mailer instanceof Mailer
                && $this->manda($mailer, $indirizzo, $account, $nuova['gettone'], $sito);
            $risultato = $partita ? self::INVIATA : self::INVIO_FALLITO;
        }

        if ($risultato !== self::INVIATA && !$gettoneAlChiamante) {
            // Si ritira solo la nuova: quella di prima, se c'è, il suo
            // collegamento l'ha già recapitato.
            $this->cancellazioni->ritira($nuova['id']);
            Anomalia::registra(
                self::ANOMALIA,
                "L'email di conferma della cancellazione non è partita: la richiesta nuova è stata ritirata "
                . "(le richieste di prima restano com'erano) e all'utente si è detto di scrivere al recapito privacy del titolare.",
                ['esito' => $risultato, 'utente' => $utente, 'precedente' => $precedente],
            );
            return $risposta($risultato, false, precedente: $precedente);
        }

        // L'email è partita (o il gettone va al chiamante): adesso, e non
        // prima, la richiesta nuova sostituisce quelle di prima in attesa.
        $this->cancellazioni->ritiraLeAltreInAttesa($utente, $nuova['id']);
        return $risposta($risultato, true, $gettoneAlChiamante ? $nuova['gettone'] : null);
    }

    /**
     * Il testo dell'email: che cosa succede aprendo il collegamento, quanto
     * vale, quando si esegue la cancellazione e come si annulla, e che cosa
     * fare se non l'ha chiesta lui. Testo semplice, come il recupero password.
     *
     * @param array<string, mixed> $account
     */
    private function manda(Mailer $mailer, string $indirizzo, array $account, string $gettone, string $sito): bool
    {
        $nome = trim((string)($account['first_name'] ?? '')) ?: (string)($account['username'] ?? '');
        $utenza = (string)($account['username'] ?? '');
        $giorniCollegamento = DeletionRequestService::TOKEN_EXPIRY_DAYS;
        $giorniRipensamento = DeletionRequestService::COOLING_OFF_DAYS;

        $corpo = "Ciao $nome,\n\n"
            . "è stata chiesta la cancellazione del tuo account Pantedu ($utenza).\n\n"
            . "Per confermarla apri questo collegamento entro $giorniCollegamento giorni:\n\n"
            . "$sito/me/confirm-deletion?token=$gettone\n\n"
            . "Si apre una pagina con un pulsante: la cancellazione è confermata solo\n"
            . "quando lo premi. Aprire il collegamento, da solo, non cancella niente.\n\n"
            . "Dopo la conferma la cancellazione viene eseguita fra $giorniRipensamento giorni.\n"
            . "Fino ad allora puoi annullarla dalla pagina «I tuoi dati»:\n\n"
            . "$sito/privacy/your-data\n\n"
            . "Se non sei stato tu, basta ignorare questo messaggio: senza la conferma\n"
            . "la richiesta scade da sola e l'account resta com'è. Qualcuno però ha\n"
            . "usato il tuo account: cambia la password da $sito/me/change-password\n\n"
            . "— Pantedu\n";

        try {
            $partita = $mailer->send($indirizzo, 'Conferma la cancellazione del tuo account Pantedu', $corpo);
        } catch (Throwable $e) {
            // Il messaggio dell'eccezione, non il corpo: il corpo ha il gettone.
            error_log('[cancellazione_account] invio non riuscito: ' . $e->getMessage());
            return false;
        }
        if (!$partita) {
            error_log('[cancellazione_account] il mittente ha rifiutato l\'email di conferma');
        }
        return $partita;
    }

    /** @return array<string, mixed> */
    private function account(int $utente): array
    {
        $st = ($this->pdo ?? Database::connection())->prepare(
            'SELECT username, first_name, email FROM users WHERE id = ? LIMIT 1'
        );
        $st->execute([$utente]);
        $riga = $st->fetch(PDO::FETCH_ASSOC);
        return \is_array($riga) ? $riga : [];
    }
}
