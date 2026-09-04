<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Core\Config;
use RuntimeException;

/**
 * Regole di accettazione e cifratura delle password.
 *
 * Estratte da UserProfileController::changePassword quando e' nato il
 * recupero password via email: due punti d'ingresso che scrivono la stessa
 * colonna devono applicare la stessa soglia. Duplicarle avrebbe funzionato
 * per una settimana, dopodiche' una delle due copie sarebbe rimasta indietro
 * — e la piu' debole sarebbe diventata la vera politica del sistema.
 *
 * I codici di errore sono quelli gia' usati dal cambio password, cosi' le
 * traduzioni esistenti restano valide.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 8;
    public const MAX_LENGTH = 4096;

    /**
     * @param string      $new     nuova password in chiaro
     * @param string      $confirm ripetizione
     * @param string|null $current password attuale, se il contesto ce l'ha:
     *                             serve solo a rifiutare il "cambio" nullo
     * @throws RuntimeException con codice macchina (new_too_short, mismatch, …)
     */
    public static function validate(string $new, string $confirm, ?string $current = null): void
    {
        if (strlen($new) < self::MIN_LENGTH) {
            throw new RuntimeException('new_too_short');
        }
        if (strlen($new) > self::MAX_LENGTH) {
            throw new RuntimeException('new_too_long');
        }
        if ($new !== $confirm) {
            throw new RuntimeException('mismatch');
        }
        if ($current !== null && $new === $current) {
            throw new RuntimeException('same_as_old');
        }

        // Phase 25.J — Have I Been Pwned (k-anonymity: alla API arrivano i
        // primi 5 caratteri dell'hash, mai la password). Fail-open voluto: se
        // il servizio non risponde, l'utente non resta bloccato fuori da un
        // controllo di comodo.
        if (Config::get('security.hibp_enabled', true)) {
            $pwned = (new HibpService())->pwnedCount($new);
            if ($pwned > 0) {
                throw new RuntimeException('pwned_password:' . $pwned);
            }
        }
    }

    /** Hash da scrivere in `users.password_hash`. */
    public static function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /** Messaggio leggibile per un codice di validate(). */
    public static function message(string $code): string
    {
        if (str_starts_with($code, 'pwned_password:')) {
            $n = (int)substr($code, strlen('pwned_password:'));
            return "Questa password compare in $n violazioni note. Scegline un'altra.";
        }
        return match ($code) {
            'new_too_short' => 'La password deve avere almeno ' . self::MIN_LENGTH . ' caratteri.',
            'new_too_long'  => 'Password troppo lunga.',
            'mismatch'      => 'Le due password non coincidono.',
            'same_as_old'   => 'La nuova password coincide con quella attuale.',
            default         => 'Password non valida.',
        };
    }
}
