<?php

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * PDO connection singleton.
 *
 * Honors `database.enabled`: if false, `connection()` throws and
 * callers are expected to fall back to JSON. Use `isAvailable()` for
 * a non-throwing probe.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static ?PDO $maintPdo = null;
    private static ?PDO $migratorPdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        if (!Config::get('database.enabled')) {
            throw new RuntimeException('Database disabled via config');
        }

        $driver  = Config::get('database.driver');
        $host    = Config::get('database.host');
        $port    = Config::get('database.port');
        $name    = Config::get('database.name');
        $user    = Config::get('database.user');
        $pass    = Config::get('database.pass');
        $charset = Config::get('database.charset');

        $dsn = "{$driver}:host={$host};port={$port};dbname={$name};charset={$charset}";

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                // G22.S9 — fix MariaDB 11.x + PHP 8.4 native driver: senza
                // buffered queries il Migrator (con fetchColumn lazy) lascia
                // cursor aperto fra statement → "Cannot execute queries while
                // other unbuffered queries are active". Buffered = fetch tutto
                // in memoria, costo trascurabile per query del nostro size.
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('DB connection failed: ' . $e->getMessage(), 0, $e);
        }
        return self::$pdo;
    }

    public static function isAvailable(): bool
    {
        if (!Config::get('database.enabled')) {
            return false;
        }
        try {
            self::connection();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Connessione per i lavori di manutenzione (purga log, anonimizzazione).
     *
     * PERCHE' ESISTE (2026-09-02)
     *
     * Le tabelle di audit devono essere in sola aggiunta: se chi entra nel sito
     * puo' anche riscrivere il registro che racconta cosa ha fatto, quel
     * registro non prova piu' niente. La misura sta scritta da tempo in
     * informativa, registro art. 30 e DPIA — ma non era applicata, perche'
     * togliere il permesso di cancellare all'utente del database avrebbe rotto
     * la purga dei log, che il GDPR impone (art. 5(1)(e)).
     *
     * Da qui i due utenti: quello del sito puo' solo aggiungere righe e
     * rileggerle; questo, usato SOLO dai job pianificati, conserva il permesso
     * di cancellare le righe scadute.
     *
     * Le credenziali si leggono da `DB_MAINT_USER` / `DB_MAINT_PASS`. Se non ci
     * sono si ricade sulla connessione ordinaria: un'installazione che non ha
     * separato gli utenti continua a funzionare come prima, e i job girano
     * come giravano.
     */
    public static function maintenanceConnection(): PDO
    {
        $user = (string)($_ENV['DB_MAINT_USER'] ?? '');
        $pass = (string)($_ENV['DB_MAINT_PASS'] ?? '');
        if ($user === '') {
            return self::connection();
        }
        if (self::$maintPdo instanceof PDO) {
            return self::$maintPdo;
        }

        $dsn = sprintf(
            '%s:host=%s;port=%s;dbname=%s;charset=%s',
            (string)Config::get('database.driver'),
            (string)Config::get('database.host'),
            (string)Config::get('database.port'),
            (string)Config::get('database.name'),
            (string)Config::get('database.charset'),
        );
        try {
            self::$maintPdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('DB maintenance connection failed: ' . $e->getMessage(), 0, $e);
        }
        return self::$maintPdo;
    }

    /**
     * Connessione per le migration (DDL).
     *
     * PERCHE' (2026-09-02)
     *
     * I trigger che rendono append-only le tabelle di audit non proteggono se
     * stessi: chi ha le credenziali dell'applicativo puo' cancellarli e poi
     * alterare il registro. Il rimedio e' togliere all'utente del sito il
     * privilegio `TRIGGER` — che pero' serve alle migration (la 038 ne crea),
     * e il migratore girava proprio con quelle credenziali.
     *
     * Da qui il terzo utente: `pantedu_app` scrive e legge i dati e non puo'
     * piu' toccare la struttura; `pantedu_migrator` ha i diritti DDL e lo usa
     * solo tools/migrate.php, che gira dal deploy e non e' raggiungibile dal
     * web. Senza `DB_MIGRATOR_USER` si ricade sulla connessione ordinaria:
     * un'installazione che non ha separato gli utenti continua a migrare come
     * prima.
     */
    public static function migrationConnection(): PDO
    {
        $user = (string)($_ENV['DB_MIGRATOR_USER'] ?? '');
        $pass = (string)($_ENV['DB_MIGRATOR_PASS'] ?? '');
        if ($user === '') {
            return self::connection();
        }
        if (self::$migratorPdo instanceof PDO) {
            return self::$migratorPdo;
        }

        $dsn = sprintf(
            '%s:host=%s;port=%s;dbname=%s;charset=%s',
            (string)Config::get('database.driver'),
            (string)Config::get('database.host'),
            (string)Config::get('database.port'),
            (string)Config::get('database.name'),
            (string)Config::get('database.charset'),
        );
        try {
            self::$migratorPdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('DB migration connection failed: ' . $e->getMessage(), 0, $e);
        }
        return self::$migratorPdo;
    }

    /** Test helper — reset singleton between tests. */
    public static function reset(): void
    {
        self::$pdo = null;
        self::$maintPdo = null;
        self::$migratorPdo = null;
    }
}
