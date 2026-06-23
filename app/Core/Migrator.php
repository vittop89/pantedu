<?php

namespace App\Core;

use PDO;
use Throwable;

/**
 * Phase 20 — Migration runner (simple, no composer deps).
 *
 * Scansiona `database/migrations/NNN_name.sql` in ordine numerico,
 * esegue quelle non presenti in `schema_migrations` (tabella tracking).
 * Idempotente: rerun skippa quelle già eseguite.
 *
 * Ogni migration è un singolo file SQL con N statement `;`-separati.
 * Statement eseguiti in singola transazione (dove DDL lo permette —
 * ALTER TABLE in MySQL committa implicitamente, quindi no rollback
 * automatico; il design è fail-fast + rimedio manuale).
 */
final class Migrator
{
    public const TRACKING_TABLE = 'schema_migrations';

    /**
     * Phase 25.E3 — Advisory lock name (MySQL GET_LOCK).
     * Globale per istanza DB → previene race su multi-server (ECS/k8s)
     * dove più worker partono in contemporanea.
     */
    public const LOCK_NAME = 'pantedu.migrator';
    public const LOCK_TIMEOUT_SEC = 60;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsDir,
    ) {
    }

    /** Crea la tabella di tracking se non esiste. */
    public function ensureTrackingTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TRACKING_TABLE . ' (
                filename     VARCHAR(255) NOT NULL PRIMARY KEY,
                executed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return list<string> migration non ancora eseguite (ordered). */
    public function pending(): array
    {
        $this->ensureTrackingTable();
        $all = $this->discoverAll();
        $done = $this->executedFilenames();
        return \array_values(\array_filter($all, fn(string $f) => !\in_array($f, $done, true)));
    }

    /**
     * Esegue tutte le migration pending. Ritorna lista eseguite.
     *
     * Phase 25.E3 — Acquisisce advisory lock MySQL (GET_LOCK) prima di
     * iniziare il run, così su multi-server (ECS/k8s rolling deploy) solo
     * un worker alla volta esegue le migration. Gli altri attendono
     * fino a 60s, poi se ancora locked logggano e ritornano lista vuota
     * (deploy continua: il primo worker ha già applicato lo schema).
     *
     * dryRun bypassa il lock (read-only, nessun side-effect).
     *
     * @param bool $dryRun Se true, solo log senza apply.
     * @return list<string>
     */
    public function run(bool $dryRun = false): array
    {
        $this->ensureTrackingTable();
        $pending = $this->pending();
        if (empty($pending)) {
            return [];
        }

        if ($dryRun) {
            return \array_map(static fn(string $f) => "[DRY] $f", $pending);
        }

        // Phase 25.E3 — advisory lock per multi-server safety.
        if (!$this->acquireLock()) {
            \error_log(
                "[migrator] LOCK BUSY: another worker is migrating, skipping run "
                . "(pending=" . count($pending) . ")"
            );
            return [];
        }

        try {
            // Re-check pending DOPO acquisizione lock: il worker che teneva
            // il lock prima di noi potrebbe aver appena applicato tutto.
            $pending = $this->pending();
            $executed = [];
            foreach ($pending as $filename) {
                $this->executeFile($filename);
                $executed[] = $filename;
            }
            return $executed;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Phase 25.E3 — Acquisisce advisory lock con timeout.
     * Ritorna true se ottenuto, false se timeout.
     */
    private function acquireLock(): bool
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
        $stmt->execute([self::LOCK_NAME, self::LOCK_TIMEOUT_SEC]);
        $result = $stmt->fetchColumn();
        return $result === 1 || $result === '1';
    }

    /**
     * Phase 25.E3 — Rilascia il lock advisory (sempre, anche on exception).
     */
    private function releaseLock(): void
    {
        try {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute([self::LOCK_NAME]);
        } catch (Throwable $e) {
            \error_log("[migrator] release_lock failed: " . $e->getMessage());
        }
    }

    /** @return list<string> filenames already recorded. */
    public function executedFilenames(): array
    {
        $stmt = $this->pdo->query('SELECT filename FROM ' . self::TRACKING_TABLE . ' ORDER BY filename');
        return \array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<string> ordered filenames in migrationsDir. */
    public function discoverAll(): array
    {
        if (!\is_dir($this->migrationsDir)) {
            return [];
        }
        $files = \glob($this->migrationsDir . '/*.sql') ?: [];
        $out = \array_map(
            fn(string $p) => \basename($p),
            $files
        );
        \sort($out, SORT_NATURAL);
        return \array_values($out);
    }

    private function executeFile(string $filename): void
    {
        $path = $this->migrationsDir . '/' . $filename;
        if (!\is_file($path)) {
            throw new \RuntimeException("Migration file not found: $filename");
        }
        $sql = (string)\file_get_contents($path);
        if ($sql === '') {
            throw new \RuntimeException("Migration empty: $filename");
        }

        // Rimuove commenti `-- ...` riga-per-riga PRIMA dello split.
        // Bug precedente: `str_starts_with($s, '--')` scartava uno
        // statement valido il cui primo carattere era `-` perché
        // preceduto da blocco di commenti (es. migration 008 con
        // `SET @col := (...)` subito dopo l'header `-- ...`).
        $stripped = \implode("\n", \array_filter(
            \explode("\n", \str_replace("\r\n", "\n", $sql)),
            fn(string $l) => !\preg_match('/^\s*--/', $l)
        ));
        $statements = \array_filter(
            \array_map('trim', \preg_split('/;\s*(?:\n|$)/', $stripped) ?: []),
            fn(string $s) => $s !== ''
        );

        foreach ($statements as $stmt) {
            try {
                $this->pdo->exec($stmt);
            } catch (Throwable $e) {
                // Phase 20 — idempotente: se il DB aveva già la colonna/index/
                // FK (ALTER eseguito manualmente in Phase 18-19), skippiamo
                // lo statement e logghiamo. La migration viene comunque
                // registrata come eseguita.
                if ($this->isAlreadyAppliedError($e)) {
                    \error_log("[migrator] $filename: statement already applied, skipping — " . $e->getMessage());
                    continue;
                }
                throw new \RuntimeException(
                    "Migration FAILED: $filename — " . $e->getMessage(),
                    previous: $e
                );
            }
        }

        $ins = $this->pdo->prepare(
            'INSERT INTO ' . self::TRACKING_TABLE . ' (filename) VALUES (?)'
        );
        $ins->execute([$filename]);
    }

    /**
     * True se l'errore PDO indica che l'oggetto DDL esiste già
     * (duplicate column/index/key/table). MySQL error codes:
     *   1060 Duplicate column name
     *   1061 Duplicate key name
     *   1050 Table already exists
     *   1068 Multiple primary key defined
     *   1826 Duplicate foreign key constraint
     */
    private function isAlreadyAppliedError(Throwable $e): bool
    {
        $msg = $e->getMessage();
        //  1060 Duplicate column name, 1061 Duplicate key name, 1050 Table exists
        //  1068 Multi PK, 1826 Duplicate FK constraint
        //  121  Duplicate key on write (FK already exists, error 1005 wrapper)
        //  1091 Can't DROP column (doesn't exist) — tollerante per rollback script
        $duplicateCodes = ['1060', '1061', '1050', '1068', '1826', '121', '1091'];
        foreach ($duplicateCodes as $c) {
            if (\str_contains($msg, "errno: $c") || \str_contains($msg, "Error Code: $c")) {
                return true;
            }
        }
        return (bool)\preg_match(
            '/Duplicate (column name|key name|entry|foreign key)|already exists|Duplicate key on write/i',
            $msg
        );
    }
}
