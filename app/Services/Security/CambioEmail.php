<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Core\Config;
use App\Core\Database;
use App\Services\Mailer;
use App\Support\IndirizzoPubblico;
use App\Support\ImprontaIp;
use PDO;
use Throwable;

/**
 * Il cambio dell'email dell'account, con conferma (15/9/2026).
 *
 * PERCHÉ COSÌ
 *   L'email è il canale del recupero password e del secondo fattore via email:
 *   chi la cambia può prendersi l'account. Fino al 15/9 POST /me/profile la
 *   cambiava con il solo gettone CSRF. Adesso servono tre cose:
 *   1. la password attuale, perché una sessione rimasta aperta non basti;
 *   2. un link monouso al NUOVO indirizzo: l'email cambia solo aprendolo, così
 *      un refuso non chiude fuori nessuno e non si può mettere l'indirizzo di
 *      un altro;
 *   3. un avviso al VECCHIO indirizzo, perché chi ha l'account sappia che
 *      qualcuno ci sta provando.
 *
 * Come il recupero password (PasswordResetService): hash del token, un'ora di
 * validità, used_at invece di DELETE, IP come hash, un invio ogni due minuti.
 */
final class CambioEmail
{
    public const RICHIESTA_INVIATA = 'richiesta_inviata';
    public const PASSWORD_ERRATA   = 'password_errata';
    public const EMAIL_NON_VALIDA  = 'email_non_valida';
    public const UGUALE            = 'uguale_alla_attuale';
    public const GIA_IN_USO        = 'gia_in_uso';
    public const TROPPO_PRESTO     = 'troppo_presto';
    public const SENZA_POSTA       = 'senza_posta';
    public const ERRORE            = 'errore';

    private const TTL_SECONDS = 3600;
    private const MIN_INTERVAL_SECONDS = 120;

    /** @var (callable(): ?Mailer) */
    private $posta;

    /** @param (callable(): ?Mailer)|null $posta di norma Mailer::fromConfig */
    public function __construct(private ?PDO $pdo = null, ?callable $posta = null)
    {
        $this->posta = $posta ?? static fn(): ?Mailer => Mailer::fromConfig();
    }

    private function db(): PDO
    {
        return $this->pdo ?? Database::connection();
    }

    /**
     * Chiede il cambio: controlla password e indirizzo, scrive la richiesta,
     * manda il link al nuovo indirizzo e l'avviso al vecchio.
     *
     * @return string una delle costanti
     */
    public function richiedi(int $utente, string $password, string $nuova, string $ip): string
    {
        $nuova = trim($nuova);
        try {
            $db = $this->db();
            $st = $db->prepare('SELECT username, first_name, email, password_hash FROM users WHERE id = ? AND active = 1 LIMIT 1');
            $st->execute([$utente]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if (!\is_array($u) || $password === '' || !password_verify($password, (string)$u['password_hash'])) {
                return self::PASSWORD_ERRATA;
            }
            if ($nuova === '' || \strlen($nuova) > 255 || !filter_var($nuova, FILTER_VALIDATE_EMAIL)) {
                return self::EMAIL_NON_VALIDA;
            }
            $vecchia = trim((string)$u['email']);
            if (strcasecmp($nuova, $vecchia) === 0) {
                return self::UGUALE;
            }
            $st = $db->prepare('SELECT COUNT(*) FROM users WHERE LOWER(email) = LOWER(?) AND id <> ?');
            $st->execute([$nuova, $utente]);
            if ((int)$st->fetchColumn() > 0) {
                return self::GIA_IN_USO;
            }
            $st = $db->prepare('SELECT COUNT(*) FROM email_change_requests WHERE user_id = ? AND used_at IS NULL AND created_at > ?');
            $st->execute([$utente, date('Y-m-d H:i:s', time() - self::MIN_INTERVAL_SECONDS)]);
            if ((int)$st->fetchColumn() > 0) {
                return self::TROPPO_PRESTO;
            }
            $mailer = ($this->posta)();
            if ($mailer === null) {
                return self::SENZA_POSTA;
            }
            // 23/9/2026 — la radice prima del gettone: senza `app.url` il
            // collegamento non c'è, IndirizzoPubblico registra l'anomalia e
            // lancia, e il catch in fondo risponde ERRORE senza aver scritto
            // niente. Fino a quel giorno il ripiego era il dominio di
            // produzione, e il gettone di un'altra istanza finiva lì.
            $sito = IndirizzoPubblico::radice('cambio_email');

            $token = bin2hex(random_bytes(32));
            $ora = date('Y-m-d H:i:s');
            // Una richiesta sola alla volta: le precedenti ancora aperte decadono.
            $db->prepare('UPDATE email_change_requests SET used_at = ? WHERE user_id = ? AND used_at IS NULL')
               ->execute([$ora, $utente]);
            $db->prepare(
                'INSERT INTO email_change_requests (user_id, new_email, token_hash, expires_at, requested_ip_hash, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $utente, $nuova, hash('sha256', $token),
                date('Y-m-d H:i:s', time() + self::TTL_SECONDS),
                ImprontaIp::esadecimale($ip),
                $ora,
            ]);

            $nome = trim((string)($u['first_name'] ?? '')) ?: (string)$u['username'];
            $mins = (int)round(self::TTL_SECONDS / 60);
            $mailer->send(
                $nuova,
                'Conferma il nuovo indirizzo del tuo account Pantedu',
                "Ciao $nome,\n\n"
                . "hai chiesto di usare questo indirizzo per il tuo account Pantedu ({$u['username']}).\n"
                . "Per confermarlo apri questo link entro $mins minuti:\n\n"
                . "$sito/me/account/email/conferma?token=$token\n\n"
                . "Il link vale una volta sola. Finché non lo apri, l'account resta con l'indirizzo di prima.\n\n"
                . "Se non sei stato tu, ignora questo messaggio.\n\n— Pantedu\n"
            );
            if ($vecchia !== '' && filter_var($vecchia, FILTER_VALIDATE_EMAIL)) {
                $mailer->send(
                    $vecchia,
                    "Richiesta di cambio dell'email del tuo account Pantedu",
                    "Ciao $nome,\n\n"
                    . "è stato chiesto di cambiare l'email del tuo account Pantedu ({$u['username']})\n"
                    . 'in ' . self::mascherata($nuova) . ". Il cambio avviene solo se si apre il link mandato\n"
                    . "a quel nuovo indirizzo, entro un'ora.\n\n"
                    . "Se non sei stato tu, qualcuno conosce la tua password: cambiala subito da\n"
                    . "$sito/me/change-password" . self::eScriviA() . ".\n\n— Pantedu\n"
                );
            }
            return self::RICHIESTA_INVIATA;
        } catch (Throwable $e) {
            error_log('[CambioEmail] ' . $e->getMessage());
            return self::ERRORE;
        }
    }

    /**
     * Il nuovo indirizzo di una richiesta valida (non scaduta, non usata), o null.
     */
    public function nuovaDelToken(string $token): ?string
    {
        $r = $this->richiesta($token);
        return $r !== null ? (string)$r['new_email'] : null;
    }

    /**
     * Conferma: cambia l'email e brucia il link. Null se il link non vale o se
     * nel frattempo l'indirizzo è stato preso da un altro account.
     *
     * @return array{utente:int,vecchia:string,nuova:string}|null
     */
    public function conferma(string $token): ?array
    {
        $r = $this->richiesta($token);
        if ($r === null) {
            return null;
        }
        $db = $this->db();
        $mia = !$db->inTransaction();
        try {
            if ($mia) {
                $db->beginTransaction();
            }
            $utente = (int)$r['user_id'];
            $nuova = (string)$r['new_email'];
            $st = $db->prepare('SELECT COUNT(*) FROM users WHERE LOWER(email) = LOWER(?) AND id <> ?');
            $st->execute([$nuova, $utente]);
            if ((int)$st->fetchColumn() > 0) {
                if ($mia) {
                    $db->rollBack();
                }
                return null;
            }
            $st = $db->prepare('SELECT email FROM users WHERE id = ?');
            $st->execute([$utente]);
            $vecchia = (string)($st->fetchColumn() ?: '');
            $db->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$nuova, $utente]);
            $ora = date('Y-m-d H:i:s');
            $db->prepare('UPDATE email_change_requests SET used_at = ? WHERE user_id = ? AND used_at IS NULL')
               ->execute([$ora, $utente]);
            if ($mia) {
                $db->commit();
            }
            return ['utente' => $utente, 'vecchia' => $vecchia, 'nuova' => $nuova];
        } catch (Throwable $e) {
            if ($mia && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[CambioEmail] ' . $e->getMessage());
            return null;
        }
    }

    /** «m***@esempio.it»: all'indirizzo vecchio non si scrive quello nuovo per intero. */
    public static function mascherata(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }
        return mb_substr($email, 0, 1) . '***' . substr($email, $at);
    }

    /** @return array{user_id:int,new_email:string}|null */
    private function richiesta(string $token): ?array
    {
        if (\strlen($token) !== 64 || !ctype_xdigit($token)) {
            return null;
        }
        try {
            $st = $this->db()->prepare(
                'SELECT user_id, new_email FROM email_change_requests
                  WHERE token_hash = ? AND used_at IS NULL AND expires_at > ? LIMIT 1'
            );
            $st->execute([hash('sha256', $token), date('Y-m-d H:i:s')]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return \is_array($r) ? ['user_id' => (int)$r['user_id'], 'new_email' => (string)$r['new_email']] : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * « e scrivi a <casella dei diritti>», o niente se l'istanza non ne ha
     * una: fino al 23/9/2026 il ripiego era la casella del DPO di produzione.
     */
    private static function eScriviA(): string
    {
        $dpo = trim((string)Config::get('mail.dpo_email', ''));
        return $dpo !== '' ? " e scrivi a $dpo" : '';
    }
}
