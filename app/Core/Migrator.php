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

    /**
     * Spezza un file .sql negli statement da eseguire, leggendolo davvero.
     *
     * PERCHE' NON BASTA UNO SPLIT SUI ";"
     *   Il vecchio approccio — togliere le righe che iniziano per `--`, poi
     *   `preg_split` su `;` — sbagliava in tre modi, e nessuno dei tre da'
     *   errore: produce statement diversi da quelli scritti e li esegue.
     *
     *     1. DELIMITER non esiste. E' una direttiva del CLIENT mysql, non SQL:
     *        serve a scrivere corpi di trigger e procedure che contengono `;`.
     *        Senza, una migration con dentro un trigger si sbriciola. La 038
     *        diventa 77 frammenti.
     *     2. Un `;` dentro una stringa spezza lo statement a meta'.
     *        INSERT ... VALUES ('a; b') diventa due pezzi rotti.
     *     3. Una riga che INIZIA per `--` dentro una stringa multi-riga
     *        veniva cancellata dal contenuto del dato.
     *
     *   Nessuno di questi e' teorico: sono tutti raggiungibili scrivendo una
     *   migration normale, e il modo in cui falliscono e' il peggiore — silenzioso
     *   o con un errore di sintassi che non dice dove.
     *
     * COSA FA
     *   Scorre il testo carattere per carattere sapendo dove si trova: dentro
     *   una stringa singola, doppia, un identificatore fra backtick, un
     *   commento di riga o di blocco. Spezza SOLO sul delimitatore corrente e
     *   SOLO quando e' fuori da tutto questo.
     *
     *   I commenti eseguibili di MySQL (`/*!40101 ... *&#47;`) restano nel testo:
     *   sono istruzioni, non commenti, e toglierli cambierebbe il significato
     *   di un dump.
     *
     * @return list<string> statement pronti da eseguire, senza il delimitatore
     */
    public static function splitStatements(string $sql): array
    {
        $sql = \str_replace("\r\n", "\n", $sql);
        $len = \strlen($sql);
        $delim = ';';
        $buf = '';
        $out = [];
        $i = 0;
        $inizioRiga = true;

        $chiudi = static function () use (&$buf, &$out): void {
            $t = \trim($buf);
            if ($t !== '') {
                $out[] = $t;
            }
            $buf = '';
        };

        while ($i < $len) {
            $c = $sql[$i];

            // DELIMITER: solo a inizio riga, come nel client mysql.
            if ($inizioRiga && \preg_match('/\GDELIMITER[ \t]+(\S+)[ \t]*(\n|$)/i', $sql, $m, 0, $i)) {
                $chiudi();
                $delim = $m[1];
                $i += \strlen($m[0]);
                $inizioRiga = true;
                continue;
            }

            // Commento di riga: `-- ` (MySQL vuole uno spazio dopo) oppure `#`.
            if (($c === '-' && \substr($sql, $i, 2) === '--'
                    && (($sql[$i + 2] ?? "\n") === ' ' || ($sql[$i + 2] ?? "\n") === "\t" || ($sql[$i + 2] ?? "\n") === "\n"))
                || $c === '#'
            ) {
                $fine = \strpos($sql, "\n", $i);
                $i = $fine === false ? $len : $fine + 1;
                $inizioRiga = true;
                continue;
            }

            // Commento di blocco. `/*!` NON e' un commento: e' codice
            // condizionale, e va lasciato dov'e'.
            if ($c === '/' && ($sql[$i + 1] ?? '') === '*' && ($sql[$i + 2] ?? '') !== '!') {
                $fine = \strpos($sql, '*/', $i + 2);
                $i = $fine === false ? $len : $fine + 2;
                $inizioRiga = false;
                continue;
            }

            // Stringhe e identificatori: qui dentro nulla ha significato.
            if ($c === "'" || $c === '"' || $c === '`') {
                $buf .= $c;
                $i++;
                while ($i < $len) {
                    $d = $sql[$i];
                    if ($d === '\\' && $c !== '`') {
                        // L'escape con backslash non vale per i backtick.
                        $buf .= $d . ($sql[$i + 1] ?? '');
                        $i += 2;
                        continue;
                    }
                    if ($d === $c) {
                        // Raddoppiato = letterale, non chiusura.
                        if (($sql[$i + 1] ?? '') === $c) {
                            $buf .= $c . $c;
                            $i += 2;
                            continue;
                        }
                        $buf .= $c;
                        $i++;
                        break;
                    }
                    $buf .= $d;
                    $i++;
                }
                $inizioRiga = false;
                continue;
            }

            // Il delimitatore corrente, finalmente.
            if (\substr($sql, $i, \strlen($delim)) === $delim) {
                $chiudi();
                $i += \strlen($delim);
                continue;
            }

            $buf .= $c;
            $inizioRiga = ($c === "\n");
            $i++;
        }

        $chiudi();
        return $out;
    }

    /**
     * Statement saltati perche' "gia' applicati", per file.
     *
     * Finivano solo in error_log, e la migration risultava riuscita comunque.
     * Ma "gia' applicato" e' un'ipotesi, non un fatto: se l'ipotesi e'
     * sbagliata la migration passa a vuoto e nessuno lo sa. Chi lancia deve
     * vederli.
     *
     * @var array<string, list<string>>
     */
    private array $saltati = [];

    /** @return array<string, list<string>> file → statement saltati */
    public function skipped(): array
    {
        return $this->saltati;
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

        $statements = self::splitStatements($sql);

        foreach ($statements as $stmt) {
            try {
                $this->pdo->exec($stmt);
            } catch (Throwable $e) {
                // Phase 20 — idempotente: se il DB aveva già la colonna/index/
                // FK (ALTER eseguito manualmente in Phase 18-19), skippiamo
                // lo statement e logghiamo. La migration viene comunque
                // registrata come eseguita.
                if ($this->isAlreadyAppliedError($e)) {
                    $this->saltati[$filename][] = \trim(
                        (string)\preg_replace('/\s+/', ' ', \substr($stmt, 0, 120))
                    );
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
