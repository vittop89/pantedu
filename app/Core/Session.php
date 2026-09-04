<?php

namespace App\Core;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $cfg = Config::get('session', []);
        session_name($cfg['name'] ?? 'PANTEDU_SID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => (bool)($cfg['secure']   ?? true),
            'httponly' => (bool)($cfg['httponly'] ?? true),
            'samesite' => $cfg['samesite'] ?? 'Lax',
        ]);

        // Phase 17 — DB-backed session handler (se DB disponibile + tabella).
        // Abilita scaling multi-instance + robustness contro corruzione fs.
        // Fallback trasparente al default filesystem se: DB off, tabella
        // assente, o qualsiasi altro errore.
        $lifetime = (int)($cfg['lifetime'] ?? 1800);
        if (Config::get('database.enabled', false) && self::tryDbSessionHandler($lifetime)) {
            // handler installato; prosegui con start normale
        }

        session_start();

        self::enforceTimeout($lifetime);
        self::rotateId((int)($cfg['regenerate_interval'] ?? 300));
    }

    private static function tryDbSessionHandler(int $lifetime): bool
    {
        try {
            $pdo = Database::connection();
            // Probe: tabella `sessions` presente?
            $probe = $pdo->query("SHOW TABLES LIKE 'sessions'");
            if (!$probe || !$probe->fetch()) {
                return false;
            }
            $handler = new DbSessionHandler($pdo, $lifetime);
            session_set_save_handler($handler, true);
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

    private static function rotateId(int $interval): void
    {
        $now = time();
        if (!isset($_SESSION['last_regeneration'])) {
            $_SESSION['last_regeneration'] = $now;
            return;
        }
        if ($now - $_SESSION['last_regeneration'] > $interval) {
            session_regenerate_id(true);
            $_SESSION['last_regeneration'] = $now;
        }
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
     * (incluso il rinnovo id sessione). $_SESSION resta leggibile ma non più
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
        $_SESSION['last_regeneration'] = time();
    }
}
