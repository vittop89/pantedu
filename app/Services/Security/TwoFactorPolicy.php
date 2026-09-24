<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Core\Database;
use App\Support\TwoFactorEnforcement;
use Throwable;

/**
 * Politica di verifica in due passaggi: chi la deve usare, chi ce l'ha attiva,
 * e come si verifica un codice al login.
 *
 * PERCHE' ESISTE (2026-09-01)
 *
 * L'infrastruttura TOTP era completa da Phase 25.J.4 — TotpService, iscrizione
 * con QR, codici di backup, colonne in `users` — ma nessuno la interrogava
 * fuori da TotpController, cioe' dalla pagina che serve ad attivarla.
 * AuthController non nominava il TOTP e non esisteva alcun middleware.
 *
 * Il risultato non era "2FA assente": era peggio. Un utente arrivava su
 * /me/2fa, inquadrava il QR, salvava i dieci codici di backup, leggeva
 * "2FA attivato" — e al login successivo gli veniva chiesta solo la password.
 * Il secondo fattore veniva scritto in tabella e ignorato, mentre l'utente
 * credeva di essere protetto e regolava di conseguenza le proprie abitudini.
 *
 * Anche `security.totp_required_roles` era codice morto: configurabile da env,
 * documentato nei commenti, letto da nessuna riga del progetto.
 *
 * Questa classe e' il punto unico in cui quelle decisioni vivono, cosi' che
 * login, middleware e pagina di profilo non possano rispondere in modo diverso
 * alla stessa domanda.
 */
final class TwoFactorPolicy
{
    /**
     * Il PDO si puo' iniettare: i test montano uno SQLite in memoria, come
     * fa gia' TosAcceptanceServiceTest. In esercizio resta null e si usa la
     * connessione condivisa.
     */
    public function __construct(private ?\PDO $db = null)
    {
    }

    private function db(): \PDO
    {
        return $this->db ??= Database::connection();
    }

    /**
     * L'utente ha completato l'iscrizione e vuole il secondo fattore.
     *
     * Indipendente dal master switch `security.totp_enabled`: chi ha attivato
     * la 2FA se la vede chiedere comunque. Spegnere l'interruttore globale
     * impedisce di imporla, non di usarla — togliere in silenzio una
     * protezione che l'utente crede attiva e' esattamente il difetto che
     * questa classe esiste per chiudere.
     */
    public function enabledFor(string $username): bool
    {
        $row = $this->row($username);
        return $row !== null && (bool)($row['totp_enabled'] ?? false);
    }

    /**
     * Il ruolo e' fra quelli per cui la 2FA e' obbligatoria.
     *
     * La decisione viene da TwoFactorEnforcement, che antepone l'override
     * scritto dal pannello admin alle variabili d'ambiente: accendere
     * l'obbligo e' una decisione, e come tale va presa da chi amministra e
     * registrata, non nascosta in un file sul VPS.
     */
    public function requiredForRole(string $role): bool
    {
        return TwoFactorEnforcement::isRequiredFor($role);
    }

    /**
     * Il ruolo la impone ma l'utente non l'ha ancora attivata: va accompagnato
     * all'iscrizione prima di poter usare il resto dell'applicativo.
     */
    public function mustEnrol(string $username, string $role): bool
    {
        return $this->requiredForRole($role) && !$this->enabledFor($username);
    }

    /**
     * Metodo scelto dall'utente: 'app', 'email', o null se non ne ha uno.
     *
     * Il fallback ad 'app' per chi ha totp_enabled senza metodo dichiarato
     * copre le iscrizioni fatte prima della migration 096, quando l'app era
     * l'unica strada possibile.
     */
    public function methodFor(string $username): ?string
    {
        $row = $this->row($username);
        if ($row === null || !(bool)($row['totp_enabled'] ?? false)) {
            return null;
        }
        $m = (string)($row['two_factor_method'] ?? '');
        return in_array($m, ['app', 'email'], true) ? $m : 'app';
    }

    /**
     * Verifica il codice col metodo che l'utente ha scelto.
     *
     * Un solo punto d'ingresso per il login: chi chiama non deve sapere se
     * il codice arriva da un'app o da una casella di posta, e soprattutto non
     * deve poter accettare per sbaglio un codice del metodo sbagliato.
     */
    public function verifyCode(string $username, string $code): bool
    {
        return match ($this->methodFor($username)) {
            'app'   => $this->verifyTotp($username, $code),
            'email' => (new EmailSecondFactor())->verify($username, $code),
            default => false,
        };
    }

    /**
     * Verifica un codice a sei cifre dell'app di autenticazione, e lo consuma.
     *
     * 23/9/2026 — un codice vale una volta (RFC 6238 §5.2, revisione 2026-09
     * rilievo A-65). Fino a quel giorno si guardava solo che il codice fosse
     * giusto: con la tolleranza di un passo per parte, lo stesso codice apriva
     * l'accesso per circa novanta secondi, e il limite dei tentativi non
     * serviva a niente, perche' il codice riusato e' giusto al primo colpo.
     *
     * Ora il codice passa solo se il suo passo e' strettamente maggiore
     * dell'ultimo accettato (users.totp_last_counter, migrazione 140). Il
     * confronto e la scrittura sono lo stesso UPDATE: due richieste parallele
     * con lo stesso codice non passano tutte e due, perche' la seconda non
     * trova piu' la riga da aggiornare. Rifiuta per la stessa ragione un
     * codice del passo precedente arrivato dopo quello del passo successivo.
     *
     * Se la scrittura fallisce l'esito e' negativo, come per i codici di
     * backup: meglio un accesso rifiutato che un codice consumato solo in
     * apparenza.
     */
    public function verifyTotp(string $username, string $code): bool
    {
        $row    = $this->row($username);
        $secret = is_array($row) ? (string)($row['totp_secret'] ?? '') : '';
        if ($secret === '' || !(bool)($row['totp_enabled'] ?? false)) {
            return false;
        }
        $passo = (new TotpService())->matchingCounter($secret, $code);
        if ($passo === null) {
            return false;
        }
        return $this->consumaPasso((int)($row['id'] ?? 0), $passo);
    }

    /**
     * Segna il passo come usato, se viene dopo l'ultimo accettato.
     *
     * rowCount() e' affidabile qui: la riga, quando passa il filtro, cambia
     * sempre valore (il passo nuovo e' maggiore del vecchio), quindi MariaDB la
     * conta fra le modificate come SQLite nelle prove.
     */
    private function consumaPasso(int $utente, int $passo): bool
    {
        if ($utente <= 0) {
            return false;
        }
        try {
            $st = $this->db()->prepare(
                'UPDATE users SET totp_last_counter = ?
                  WHERE id = ? AND totp_enabled = 1
                    AND (totp_last_counter IS NULL OR totp_last_counter < ?)'
            );
            $st->execute([$passo, $utente, $passo]);
            return $st->rowCount() === 1;
        } catch (Throwable $e) {
            // Colonna assente (migrazione 140 non applicata) o database che
            // non risponde: il codice non si puo' consumare, quindi non vale.
            error_log('[TwoFactorPolicy] passo TOTP non registrato: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Verifica un codice di backup e lo consuma.
     *
     * Il consumo e' il punto delicato: un codice di riserva che resta valido
     * dopo l'uso e' una password statica in piu', non un fattore. Viene quindi
     * rimosso dalla lista prima che la funzione ritorni true, e se la scrittura
     * in DB fallisce l'esito e' negativo — meglio un accesso rifiutato che un
     * codice bruciato solo in apparenza.
     */
    public function consumeBackupCode(string $username, string $code): bool
    {
        $row = $this->row($username);
        if (!is_array($row) || !(bool)($row['totp_enabled'] ?? false)) {
            return false;
        }
        $hashes = json_decode((string)($row['totp_backup_codes'] ?? '[]'), true);
        if (!is_array($hashes) || $hashes === []) {
            return false;
        }
        $idx = (new TotpService())->verifyBackupCode(array_values($hashes), $code);
        if ($idx < 0) {
            return false;
        }
        $remaining = array_values($hashes);
        unset($remaining[$idx]);
        $remaining = array_values($remaining);

        try {
            $st = $this->db()->prepare(
                'UPDATE users SET totp_backup_codes = ? WHERE username = ?'
            );
            $st->execute([json_encode($remaining, JSON_UNESCAPED_SLASHES), $username]);
            return $st->rowCount() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /** Quanti codici di backup restano — mostrato dopo un accesso che ne consuma uno. */
    public function backupCodesLeft(string $username): int
    {
        $row = $this->row($username);
        $h   = is_array($row) ? json_decode((string)($row['totp_backup_codes'] ?? '[]'), true) : null;
        return is_array($h) ? count($h) : 0;
    }

    /** @return array<string,mixed>|null */
    private function row(string $username): ?array
    {
        if ($username === '') {
            return null;
        }
        try {
            // `id` serve a consumare il codice (consumaPasso). Qui NON si legge
            // totp_last_counter: se la migrazione 140 mancasse, questa lettura
            // fallirebbe e la 2FA sparirebbe in silenzio per tutti; cosi' fallisce
            // solo la verifica del codice, che e' la direzione sicura.
            $st = $this->db()->prepare(
                'SELECT id, totp_enabled, totp_secret, totp_backup_codes, two_factor_method
                   FROM users WHERE username = ? LIMIT 1'
            );
            $st->execute([$username]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            // Colonne assenti (migration 055 non applicata) o DB non
            // raggiungibile: nessun secondo fattore da imporre. Il login con
            // sola password resta quello che era prima di questa classe.
            return null;
        }
    }
}
