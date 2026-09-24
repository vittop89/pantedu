<?php

namespace App\Core;

final class Session
{
    public const DRIVER_FILE = 'file';
    public const DRIVER_DATABASE = 'database';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $cfg = Config::get('session', []);
        $cfg = is_array($cfg) ? $cfg : [];
        $lifetime = (int)($cfg['lifetime'] ?? 1800);
        $cli = PHP_SAPI === 'cli';
        try {
            $driver = self::driver($cfg);
        } catch (\RuntimeException $e) {
            // Da riga di comando le sessioni non servono, e app/bootstrap.php
            // parte prima di docker/verifica-avvio.php, che l'errore lo dice
            // per bene. Servendo pagine, invece, è un guasto.
            if ($cli) {
                return;
            }
            throw $e;
        }

        // Le impostazioni di sessione le mette l'applicazione, uguali ovunque
        // (ADR-039). Prima stavano in docker/php.ini: la produzione aveva la
        // modalità stretta, sviluppo e CI no, e un difetto di sessione si
        // vedeva solo da una parte.
        $storage = Config::get('app.paths.storage');
        foreach (self::impostazioni($cfg, is_string($storage) ? $storage : '') as $chiave => $valore) {
            ini_set($chiave, $valore);
        }

        // Da riga di comando, se le sessioni non si possono salvare, non si
        // prova nemmeno.
        //
        // Non è un caso teorico: `pantedu-avviso@.service` gira con
        // `ProtectSystem=strict`, che rende di sola lettura la cartella delle
        // sessioni, e ogni avviso di guasto lasciava due `PHP Warning` nel
        // giornale **prima** del messaggio vero. Righe di avvertimento in cima
        // a un allarme insegnano a non leggere gli allarmi.
        //
        // Da riga di comando la cartella non si crea: sul VPS la creerebbe
        // l'utente dei lavori notturni, e il container, che serve le pagine con
        // un altro utente, non potrebbe più scriverci. La crea l'avvio del
        // container, o la prima richiesta web.
        //
        // Servendo pagine, invece, un posto dove salvare che non c'è è un
        // guasto e si dice: prima, con la tabella che mancava, si ripiegava in
        // silenzio sui file del container, e ogni rilascio buttava fuori tutti.
        if ($driver === self::DRIVER_FILE && !self::cartellaPronta((string)session_save_path(), !$cli)) {
            if ($cli) {
                return;
            }
            throw new \RuntimeException(
                'sessioni: la cartella «' . session_save_path() . '» non esiste o non è scrivibile (SESSION_SAVE_PATH)'
            );
        }

        session_name($cfg['name'] ?? 'PANTEDU_SID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => (bool)($cfg['secure']   ?? true),
            'httponly' => (bool)($cfg['httponly'] ?? true),
            'samesite' => $cfg['samesite'] ?? 'Lax',
        ]);

        if ($driver === self::DRIVER_DATABASE && !self::installaGestoreDb($lifetime)) {
            if ($cli) {
                return;
            }
            throw new \RuntimeException(
                'sessioni: SESSION_DRIVER=database, ma il database non risponde o la tabella sessions non c\'è'
            );
        }

        session_start();

        self::enforceTimeout($lifetime);
        self::enforceAbsoluteLifetime((int)($cfg['absolute_lifetime'] ?? 43200));
    }

    /**
     * Il modo di salvare le sessioni, dalla configurazione (SESSION_DRIVER).
     * Un valore sconosciuto è un errore: prima si decideva guardando se
     * esisteva la tabella, e nessuno sapeva quale dei due modi fosse in uso.
     *
     * @param array<string,mixed> $cfg la configurazione `session`
     */
    public static function driver(array $cfg): string
    {
        $driver = strtolower(trim((string)($cfg['driver'] ?? self::DRIVER_FILE)));
        if ($driver === '') {
            return self::DRIVER_FILE;
        }
        if (!in_array($driver, [self::DRIVER_FILE, self::DRIVER_DATABASE], true)) {
            throw new \RuntimeException("sessioni: SESSION_DRIVER «{$driver}» sconosciuto: file o database");
        }
        return $driver;
    }

    /**
     * Le impostazioni ini delle sessioni, uguali in ogni ambiente (ADR-039).
     *
     * - Modalità stretta: un id che il server non conosce non si accetta, se
     *   ne dà uno nuovo. In produzione c'era già (docker/php.ini); in sviluppo
     *   e in CI no. Solo cookie e niente id nell'indirizzo sono già i valori
     *   di PHP.
     * - La pulizia delle sessioni scadute tiene i file per tutta l'inattività
     *   concessa: con il valore di PHP (1440 s) una sessione ferma da 25
     *   minuti poteva sparire prima dei 30 di SESSION_LIFETIME.
     * - La cartella, per il modo a file: SESSION_SAVE_PATH, o `storage/sessions`
     *   dei dati d'istanza. Nel container stava nella /tmp del container, e
     *   ogni rilascio, che cambia container, buttava fuori tutti: misurato il
     *   14/9/2026, 214 sessioni nella /tmp del container partito alle 11:47.
     *
     * @param array<string,mixed> $cfg     la configurazione `session`
     * @param string              $storage la cartella `storage` dei dati d'istanza
     * @return array<string,string>
     */
    public static function impostazioni(array $cfg, string $storage): array
    {
        // Niente use_only_cookies e use_trans_sid: da PHP 8.4 cambiarli è
        // deprecato, e i loro valori predefiniti sono già quelli sicuri.
        $ini = [
            'session.use_strict_mode'  => '1',
            'session.cookie_httponly'  => '1',
            'session.gc_maxlifetime'   => (string)max(60, (int)($cfg['lifetime'] ?? 1800)),
            'session.gc_probability'   => '1',
            'session.gc_divisor'       => '100',
        ];
        if (self::driver($cfg) === self::DRIVER_FILE) {
            $cartella = trim((string)($cfg['save_path'] ?? ''));
            $ini['session.save_path'] = $cartella !== '' ? $cartella : rtrim($storage, '/') . '/sessions';
        }
        return $ini;
    }

    /**
     * La cartella delle sessioni c'è ed è scrivibile? Se manca e si può, la
     * crea leggibile solo da chi serve le pagine: i nomi dei file sono gli id
     * di sessione.
     */
    public static function cartellaPronta(string $cartella, bool $puoCrearla): bool
    {
        if ($cartella === '') {
            return false;
        }
        if (!is_dir($cartella)) {
            if (!$puoCrearla) {
                return false;
            }
            if (!@mkdir($cartella, 0700, true) && !is_dir($cartella)) {
                return false;
            }
        }
        return is_writable($cartella);
    }

    /**
     * Phase 17 — il gestore su database, se SESSION_DRIVER=database. Senza la
     * tabella, o senza database, dice di no: decide chi chiama.
     */
    private static function installaGestoreDb(int $lifetime): bool
    {
        if (!Config::get('database.enabled', false)) {
            return false;
        }
        try {
            $pdo = Database::connection();
            $probe = $pdo->query("SHOW TABLES LIKE 'sessions'");
            if (!$probe || !$probe->fetch()) {
                return false;
            }
            session_set_save_handler(new DbSessionHandler($pdo, $lifetime), true);
            // lazy_write: aggiorna solo last_access se i dati non cambiano
            ini_set('session.lazy_write', '1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function enforceTimeout(int $timeout): void
    {
        $now = time();
        if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity']) > $timeout) {
            self::destroy();
            session_start();
        }
        $_SESSION['last_activity'] = $now;
    }

    /**
     * La durata massima di una sessione autenticata, contata dal login
     * (2026-09-14).
     *
     * Fino a quel giorno l'id si ruotava ogni cinque minuti con
     * session_regenerate_id(true), che cancella subito la sessione vecchia: le
     * richieste già partite con l'id vecchio la trovavano vuota e, con
     * `session.use_strict_mode = 1` come in docker/php.ini, ricevevano un id
     * nuovo e vuoto. Se quella risposta arrivava per ultima, l'utente era
     * fuori. Misurato quel giorno, a file con la modalità stretta: 7 richieste
     * su 8 perse alla prima raffica e sessione persa, tre giri su tre; senza
     * rotazione, 320 su 320 (voce 101 del registro del debito).
     *
     * L'id ora cambia solo quando cambia chi si è: login e secondo fattore
     * (Auth::establishSession), cambio di ruolo (self::regenerate), come chiede
     * OWASP ASVS V3. Quello che la rotazione limitava, la vita di un id rubato,
     * lo limita questo tetto, oltre all'inattività di enforceTimeout.
     */
    private static function enforceAbsoluteLifetime(int $max): void
    {
        if (self::oltreLaDurata($_SESSION['login_time'] ?? null, $max, time())) {
            self::destroy();
            session_start();
        }
    }

    /** Una sessione con questo login è andata oltre la durata massima? Senza login, o senza tetto, no. */
    public static function oltreLaDurata(mixed $loginTime, int $max, int $adesso): bool
    {
        if ($max <= 0 || !is_numeric($loginTime)) {
            return false;
        }
        return $adesso - (int)$loginTime > $max;
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $p['path'],
                $p['domain'],
                $p['secure'],
                $p['httponly']
            );
        }
        session_destroy();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Rilascia il lock di sessione (session_write_close) senza distruggerla.
     * Da chiamare nelle richieste LUNGHE (es. estrazione LLM PDF-Import) DOPO
     * i controlli auth/owner e PRIMA del lavoro lento: evita che la richiesta
     * tenga il lock per decine di secondi bloccando/race-ando le altre richieste
     * della stessa sessione. $_SESSION resta leggibile ma non più
     * persistito in questa richiesta (nessuna scrittura post-close attesa).
     */
    public static function close(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /**
     * Phase 19 — Rigenera manualmente il session ID e resetta lo stato
     * rate-limit precedente della sessione. Da chiamare dopo privilege
     * change (approve registration, setRole) per prevenire session fixation.
     * Preserva i dati in $_SESSION (session_regenerate_id(true) cancella
     * il file vecchio ma tiene i dati attivi).
     */
    public static function regenerate(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        session_regenerate_id(true);
    }
}
