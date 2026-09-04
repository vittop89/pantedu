<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Core\Database;
use App\Services\Mailer;
use PDO;
use Throwable;

/**
 * Secondo fattore via email: emissione, invio e verifica del codice.
 *
 * Alternativa dichiarata all'app di autenticazione, per chi non ha uno
 * smartphone. E' un fattore PIU' DEBOLE, e in questo applicativo in modo
 * particolare: il recupero password passa dalla stessa casella
 * (PasswordResetService), quindi chi ne prende il controllo ottiene entrambi
 * i fattori. La pagina di scelta lo dice, e consiglia l'app.
 *
 * Resta comunque meglio della sola password, perche' intercetta il riuso di
 * credenziali e il credential stuffing — che sono gli attacchi che capitano
 * davvero, non quelli teorici.
 *
 * Il codice si conserva come hash: dura dieci minuti, ma un dump del database
 * non deve consegnare codici spendibili.
 */
final class EmailSecondFactor
{
    /** Durata del codice. Dieci minuti: il tempo di aprire la posta. */
    private const TTL_SECONDS = 600;

    /** Tentativi ammessi sullo stesso codice prima di bruciarlo. */
    private const MAX_ATTEMPTS = 5;

    /** Intervallo minimo fra due invii allo stesso utente. */
    private const MIN_INTERVAL_SECONDS = 60;

    public function __construct(private ?Mailer $mailer = null, private ?PDO $db = null)
    {
        $this->mailer ??= self::defaultMailer();
    }

    private function db(): PDO
    {
        return $this->db ??= Database::connection();
    }

    private static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Emette un codice e lo spedisce.
     *
     * @param string $purpose 'login' oppure 'enrol'
     * @return bool false se non c'e' un indirizzo, se l'invio fallisce, o se
     *              un codice e' stato appena spedito (in quest'ultimo caso
     *              quello precedente e' ancora valido: non e' un errore)
     */
    public function issue(string $username, string $purpose = 'login'): bool
    {
        try {
            $db = $this->db();
            $st = $db->prepare('SELECT id, email FROM users WHERE username = ? AND active = 1 LIMIT 1');
            $st->execute([$username]);
            $user = $st->fetch(PDO::FETCH_ASSOC);
            if (!$user || empty($user['email'])) {
                return false;
            }
            $uid = (int)$user['id'];

            // Invio ravvicinato: il codice precedente e' ancora buono. Senza
            // questo, ogni ricarica della pagina spedirebbe una mail.
            $st = $db->prepare(
                'SELECT COUNT(*) FROM two_factor_email_codes
                  WHERE user_id = ? AND used_at IS NULL AND created_at > ?'
            );
            $st->execute([$uid, date('Y-m-d H:i:s', time() - self::MIN_INTERVAL_SECONDS)]);
            if ((int)$st->fetchColumn() > 0) {
                return true;
            }

            // Sei cifre, generate con random_int: mt_rand qui sarebbe
            // prevedibile, e il codice e' l'intero fattore.
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            // I codici pendenti dello stesso scopo decadono: ne vale uno solo,
            // altrimenti l'ultimo arrivato non invalida i precedenti.
            $db->prepare(
                'UPDATE two_factor_email_codes SET used_at = ?
                  WHERE user_id = ? AND purpose = ? AND used_at IS NULL'
            )->execute([self::now(), $uid, $purpose]);

            $db->prepare(
                'INSERT INTO two_factor_email_codes
                    (user_id, code_hash, purpose, expires_at, created_at)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([
                $uid,
                hash('sha256', $code),
                $purpose,
                date('Y-m-d H:i:s', time() + self::TTL_SECONDS),
                self::now(),
            ]);

            $sent = $this->send((string)$user['email'], $username, $code);
            if (!$sent) {
                // Invio fallito: si brucia subito la riga. Lasciarla in piedi
                // significherebbe un codice valido che nessuno ha ricevuto, e
                // — peggio — la finestra anti-ripetizione consumata, che
                // bloccherebbe per un minuto il ritentativo di chi ha appena
                // visto l'errore.
                $db->prepare('UPDATE two_factor_email_codes SET used_at = ? WHERE user_id = ? AND used_at IS NULL')
                   ->execute([self::now(), $uid]);
            }
            return $sent;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Verifica un codice e lo consuma.
     *
     * I tentativi si contano sulla riga: cinque errori bruciano il codice, per
     * non lasciare sei cifre indovinabili per dieci minuti interi.
     */
    public function verify(string $username, string $code, string $purpose = 'login'): bool
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';
        if ($code === '') {
            return false;
        }
        try {
            $db = $this->db();
            $st = $db->prepare(
                'SELECT c.id, c.code_hash, c.attempts
                   FROM two_factor_email_codes c
                   JOIN users u ON u.id = c.user_id
                  WHERE u.username = ? AND c.purpose = ? AND c.used_at IS NULL
                    AND c.expires_at > ?
                  ORDER BY c.id DESC LIMIT 1'
            );
            $st->execute([$username, $purpose, self::now()]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return false;
            }

            if ((int)$row['attempts'] >= self::MAX_ATTEMPTS) {
                $db->prepare('UPDATE two_factor_email_codes SET used_at = ? WHERE id = ?')
                   ->execute([self::now(), (int)$row['id']]);
                return false;
            }

            // hash_equals e non ===: il confronto di un segreto non deve
            // dipendere in durata dai caratteri che coincidono.
            if (!hash_equals((string)$row['code_hash'], hash('sha256', $code))) {
                $db->prepare('UPDATE two_factor_email_codes SET attempts = attempts + 1 WHERE id = ?')
                   ->execute([(int)$row['id']]);
                return false;
            }

            $db->prepare('UPDATE two_factor_email_codes SET used_at = ? WHERE id = ?')
               ->execute([self::now(), (int)$row['id']]);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Indirizzo a cui verrebbe spedito, mascherato per mostrarlo in pagina. */
    public function maskedAddress(string $username): ?string
    {
        try {
            $st = $this->db()->prepare('SELECT email FROM users WHERE username = ? LIMIT 1');
            $st->execute([$username]);
            $email = (string)($st->fetchColumn() ?: '');
            if ($email === '' || !str_contains($email, '@')) {
                return null;
            }
            [$local, $domain] = explode('@', $email, 2);
            // Si mostra quanto basta a riconoscere la propria casella e non
            // abbastanza da rivelarla a chi guarda lo schermo alle spalle.
            $head = mb_substr($local, 0, 2);
            return $head . str_repeat('•', max(1, mb_strlen($local) - 2)) . '@' . $domain;
        } catch (Throwable) {
            return null;
        }
    }

    private function send(string $to, string $username, string $code): bool
    {
        if (!$this->mailer instanceof Mailer) {
            return false;
        }
        $min  = (int)round(self::TTL_SECONDS / 60);
        $body = "Ciao $username,\n\n"
              . "il codice per completare l'accesso a Pantedu e':\n\n"
              . "    $code\n\n"
              . "Vale $min minuti e una volta sola.\n\n"
              . "Se non stai accedendo tu, qualcuno conosce la tua password:\n"
              . "cambiala appena puoi da https://pantedu.eu/me/change-password\n"
              . "Il codice da solo non basta per entrare, quindi il tuo account\n"
              . "e' ancora chiuso — ma la password non e' piu' un segreto.\n\n"
              . "— Pantedu\n";

        return $this->mailer->send($to, "Codice di accesso Pantedu: $code", $body);
    }

    private static function defaultMailer(): ?Mailer
    {
        $from = (string)($_ENV['APP_MAIL_FROM'] ?? '');
        if ($from === '') {
            return null;
        }
        return new Mailer($from, (string)($_ENV['APP_MAIL_FROM_NAME'] ?? 'Pantedu'));
    }
}
