<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Core\Config;
use App\Core\Database;
use App\Services\Mailer;
use PDO;
use Throwable;

/**
 * Recupero password via email: emissione, verifica e consumo del token.
 *
 * PERCHE' ESISTE (2026-09-01)
 *
 * Non esisteva alcuna via di rientro: la pagina di login diceva "Contatta
 * l'amministratore", e l'amministratore e' una persona sola. Introdotta la
 * verifica in due passaggi, la sproporzione peggiorava — piu' modi di restare
 * chiusi fuori, nessuno di rientrare da soli.
 *
 * DUE PROPRIETA' DA NON PERDERE
 *
 * 1. **La risposta non cambia mai.** Che l'indirizzo esista o no, il modulo
 *    risponde con la stessa frase e nello stesso tempo. Un messaggio diverso
 *    ("indirizzo non registrato") trasformerebbe la pagina in un oracolo per
 *    scoprire chi ha un account: informazione utile a chi prepara un attacco
 *    mirato, e in ogni caso un dato personale che non ha ragione di uscire.
 *
 * 2. **Il token non e' un secondo fattore.** Chi reimposta la password NON
 *    salta la verifica in due passaggi: al login successivo il codice viene
 *    chiesto comunque. Chi controlla la casella email non deve poter entrare
 *    in un account protetto da 2FA — sarebbe il modo piu' rapido per rendere
 *    inutile la 2FA stessa.
 */
final class PasswordResetService
{
    /** Validita' del link. Un'ora: abbastanza per accorgersi dell'email. */
    private const TTL_SECONDS = 3600;

    /**
     * Intervallo minimo fra due invii allo stesso account: evita che il
     * modulo diventi uno strumento per riempire la casella di qualcun altro.
     */
    private const MIN_INTERVAL_SECONDS = 120;

    /**
     * Il PDO si puo' iniettare (test con SQLite in memoria); in esercizio
     * resta null e si usa la connessione condivisa.
     */
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
     * Emette un token e invia il link, se l'indirizzo corrisponde a un account
     * attivo. Non comunica nulla al chiamante: l'esito e' volutamente lo
     * stesso in ogni caso (vedi proprieta' 1).
     */
    public function request(string $email, string $ip): void
    {
        $email = trim($email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        try {
            $db = $this->db();
            $st = $db->prepare(
                'SELECT id, username FROM users WHERE email = ? AND active = 1 LIMIT 1'
            );
            $st->execute([$email]);
            $user = $st->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                return;
            }
            $uid = (int)$user['id'];

            // Richiesta ravvicinata: il link precedente e' ancora valido.
            // Le date si calcolano in PHP, non in SQL: `NOW() + INTERVAL` e'
            // sintassi MySQL e legava il servizio a un solo motore, test
            // compresi.
            $st = $db->prepare(
                'SELECT COUNT(*) FROM password_resets
                  WHERE user_id = ? AND used_at IS NULL AND created_at > ?'
            );
            $st->execute([$uid, date('Y-m-d H:i:s', time() - self::MIN_INTERVAL_SECONDS)]);
            if ((int)$st->fetchColumn() > 0) {
                return;
            }

            $token = bin2hex(random_bytes(32));
            // created_at si scrive esplicitamente invece di lasciarlo al
            // DEFAULT CURRENT_TIMESTAMP: quel valore lo mette il database col
            // PROPRIO fuso, mentre la finestra qui sopra lo confronta con una
            // soglia calcolata da PHP. Bastano due ore di scarto perche' il
            // controllo anti-ripetizione non scatti mai — e nessuno se ne
            // accorga, perche' il sintomo e' solo un'email in piu'.
            $st = $db->prepare(
                'INSERT INTO password_resets (user_id, token_hash, expires_at, requested_ip_hash, created_at)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $st->execute([
                $uid,
                hash('sha256', $token),
                date('Y-m-d H:i:s', time() + self::TTL_SECONDS),
                // Hash e non IP in chiaro: vedi migration 095 e registro art. 30 §B.6.
                $ip !== '' ? hash('sha256', $ip) : null,
                self::now(),
            ]);

            $this->sendLink($email, (string)$user['username'], $token);
        } catch (Throwable) {
            // Nemmeno un errore interno deve distinguersi da un indirizzo
            // sconosciuto: il chiamante riceve comunque la stessa risposta.
        }
    }

    /** @return int|null user_id se il token e' valido, non scaduto e non usato */
    public function verify(string $token): ?int
    {
        if ($token === '' || !ctype_xdigit($token)) {
            return null;
        }
        try {
            $st = $this->db()->prepare(
                'SELECT user_id FROM password_resets
                  WHERE token_hash = ? AND used_at IS NULL AND expires_at > ? LIMIT 1'
            );
            $st->execute([hash('sha256', $token), self::now()]);
            $uid = $st->fetchColumn();
            return $uid === false ? null : (int)$uid;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Imposta la nuova password e brucia il token.
     *
     * La password si scrive PRIMA di marcare il token usato, e gli altri token
     * pendenti dello stesso utente vengono invalidati: se qualcuno aveva
     * chiesto piu' link, quelli non ancora aperti smettono di valere.
     */
    public function consume(string $token, string $plainPassword): bool
    {
        $uid = $this->verify($token);
        if ($uid === null) {
            return false;
        }
        try {
            $db = $this->db();
            $db->beginTransaction();

            $st = $db->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?');
            $st->execute([PasswordPolicy::hash($plainPassword), $uid]);

            $db->prepare('UPDATE password_resets SET used_at = ? WHERE token_hash = ?')
               ->execute([self::now(), hash('sha256', $token)]);

            // Gli altri link pendenti dello stesso utente decadono: la
            // password e' cambiata, e un link ancora buono sarebbe una seconda
            // chiave in circolazione senza che nessuno se lo aspetti.
            $db->prepare(
                'UPDATE password_resets SET used_at = ?
                  WHERE user_id = ? AND used_at IS NULL'
            )->execute([self::now(), $uid]);

            $db->commit();
            return true;
        } catch (Throwable) {
            try {
                $this->db()->rollBack();
            } catch (Throwable) {
            }
            return false;
        }
    }

    private function sendLink(string $email, string $username, string $token): void
    {
        if (!$this->mailer instanceof Mailer) {
            return;
        }
        $base = rtrim((string)Config::get('app.url', ''), '/');
        $link = ($base !== '' ? $base : 'https://pantedu.eu') . '/password/reset?token=' . $token;
        $mins = (int)round(self::TTL_SECONDS / 60);

        $body = "Ciao $username,\n\n"
              . "hai chiesto di reimpostare la password del tuo account Pantedu.\n"
              . "Apri questo link entro $mins minuti:\n\n"
              . "$link\n\n"
              . "Il link vale una volta sola.\n\n"
              . "Se non sei stato tu, ignora questo messaggio: la password resta\n"
              . "quella di prima e nessuno ha avuto accesso al tuo account.\n\n"
              . "Se hai attivato la verifica in due passaggi, ti verra' chiesta\n"
              . "comunque al prossimo accesso.\n\n"
              . "— Pantedu\n";

        $this->mailer->send($email, 'Reimposta la password di Pantedu', $body);
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
