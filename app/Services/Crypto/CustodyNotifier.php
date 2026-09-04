<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Core\Database;
use App\Services\Mailer;
use Throwable;

/**
 * Avvisa il docente quando l'operatore accede ai suoi contenuti cifrati.
 *
 * PERCHE' (2026-09-04)
 *   La procedura di accesso amministrativo (ToS §3(c), Informativa §11-bis)
 *   produce una riga immutabile in `crypto_custody_events`, che pero'
 *   controlla la stessa persona che ha fatto l'accesso. Un registro che
 *   legge solo chi lo scrive non e' una garanzia per l'interessato. Da qui
 *   l'avviso automatico: il docente viene a sapere dell'accesso nel momento
 *   in cui viene registrato, e puo' rileggere l'elenco da /me/custody-events.
 *
 * COSA NON FA
 *   Non avvisa per le richieste dell'autorita' (authority_request,
 *   data_provided): un provvedimento puo' vietare di informare l'interessato
 *   (segreto investigativo, art. 329 c.p.p.), e la valutazione spetta a chi
 *   gestisce la richiesta, caso per caso, non a un automatismo.
 */
final class CustodyNotifier
{
    /** Eventi che l'interessato deve conoscere subito. */
    public const NOTIFY_TYPES = ['kek_emergency_access', 'data_recovered'];

    /** Eventi che l'interessato puo' rileggere da /me/custody-events. */
    public const SUBJECT_VISIBLE_TYPES = ['kek_emergency_access', 'data_recovered'];

    private const LABELS = [
        'kek_emergency_access' => 'accesso amministrativo ai tuoi contenuti cifrati',
        'data_recovered'       => 'recupero dei tuoi contenuti cifrati',
    ];

    /**
     * Invia l'avviso. Torna false, senza eccezioni, se non c'e' nulla da
     * notificare o se l'email non parte: la registrazione dell'evento non
     * deve dipendere dal servizio di posta.
     */
    public static function notify(string $eventType, int $teacherId, string $occurredAt, ?string $legalBasis): bool
    {
        if ($teacherId <= 0 || !in_array($eventType, self::NOTIFY_TYPES, true)) {
            return false;
        }
        try {
            $st = Database::connection()->prepare(
                'SELECT email, first_name, username FROM users WHERE id = ? LIMIT 1'
            );
            $st->execute([$teacherId]);
            $u = $st->fetch(\PDO::FETCH_ASSOC);
            $to = is_array($u) ? trim((string)($u['email'] ?? '')) : '';
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                return false;
            }
            $name  = trim((string)($u['first_name'] ?? '')) ?: (string)($u['username'] ?? '');
            $label = self::LABELS[$eventType] ?? $eventType;
            $site  = rtrim((string)($_ENV['APP_URL'] ?? 'https://pantedu.eu'), '/');
            $dpo   = (string)($_ENV['DPO_EMAIL'] ?? 'operatore@example.net');

            $subject = 'Pantedu — ' . ucfirst($label);
            $body = "Ciao $name,\n\n"
                . "il $occurredAt l'operatore di Pantedu ha registrato un evento di "
                . "$label" . ($legalBasis ? " (base: $legalBasis)" : '') . ".\n\n"
                . "Questa procedura e' ammessa nei soli casi dei Termini di Servizio, §3(c), "
                . "e dell'Informativa, §11-bis, e ogni attivazione produce una riga immutabile "
                . "nel registro di custodia delle chiavi. Ti scriviamo perche' un registro che "
                . "legge solo chi lo scrive non basta: devi saperlo tu.\n\n"
                . "L'elenco degli eventi che ti riguardano: $site/me/custody-events\n\n"
                . "Se non riconosci questa operazione o vuoi chiarimenti, rispondi a questa email "
                . "o scrivi a $dpo.\n\n— Pantedu\n";

            $from     = (string)($_ENV['APP_MAIL_FROM'] ?? 'operatore@example.net');
            $fromName = (string)($_ENV['APP_MAIL_FROM_NAME'] ?? 'Pantedu');
            return (new Mailer($from, $fromName))->send($to, $subject, $body, $dpo);
        } catch (Throwable $e) {
            error_log('[CustodyNotifier] ' . $e->getMessage());
            return false;
        }
    }
}
