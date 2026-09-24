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

    /**
     * Prefissi numerici che compaiono due volte e che il repository accetta
     * per storia: quattro coppie (036-038 a maggio, 100 a settembre) scritte
     * su rami paralleli e applicate ovunque prima che il controllo
     * esistesse. Rinominarne una non si puo': il nome del file e' la chiave
     * in schema_migrations. Qualunque altro doppione viene rifiutato.
     * (Le chiavi con lo zero iniziale restano stringhe, '100' diventa int:
     * per questo la lookup passa sempre da (string)$prefix.)
     */
    private const KNOWN_DUPLICATE_PREFIXES = [
        '036' => ['036_curriculum_institute_scope.sql', '036_indirizzo_fk.sql'],
        '037' => ['037_classe_materia_fk.sql', '037_drop_legacy_empty_tables.sql'],
        '038' => ['038_drop_m11_legacy_tables.sql', '038_indirizzo_classe_materia_triggers.sql'],
        '100' => ['100_audit_ip_ua_hash.sql', '100_classi_indirizzo.sql'],
    ];

    /** @return list<string> migration non ancora eseguite (ordered). */
    public function pending(): array
    {
        $this->ensureTrackingTable();
        return $this->pendingSenzaCreare();
    }

    /**
     * Le migrazioni in sospeso, **senza** creare la tabella di tracciamento.
     *
     * `pending()` chiama `ensureTrackingTable()`, che è un
     * `CREATE TABLE IF NOT EXISTS`: DDL. Va benissimo per chi le migrazioni le
     * esegue — quello usa `Database::migrationConnection()` — ma non per chi
     * si limita a **guardare**.
     *
     * Il caso concreto, trovato il 9 settembre 2026: `HealthController` chiama
     * `pending()` sulla connessione dell'applicazione. Finché `pantedu_app`
     * aveva i permessi di DDL nessuno se ne accorgeva; togliendoglieli — che è
     * la cosa giusta da fare, perché è l'utente con cui il sito serve **ogni**
     * richiesta — `/health` avrebbe lanciato un'eccezione, il `catch` l'avrebbe
     * letta come «database irraggiungibile», e il cancello di salute avrebbe
     * annullato ogni rilascio.
     *
     * Un endpoint di salute che crea tabelle è comunque una stranezza: dice di
     * osservare e invece modifica.
     *
     * @return list<string>
     */
    public function pendingSenzaCreare(): array
    {
        $all = $this->discoverAll();
        $done = $this->executedFilenames();
        return \array_values(\array_filter($all, fn(string $f) => !\in_array($f, $done, true)));
    }

    /**
     * Prefissi numerici usati da piu' di un file, esclusi quelli noti.
     *
     * Il Migrator ordina per nome e traccia per nome, quindi due file
     * `NNN_a.sql` e `NNN_b.sql` funzionano; ma il prefisso smette di
     * identificare la migration e l'ordine fra i due dipende dal resto del
     * nome, cosa che nessuno guarda in un diff. Meglio fermarsi prima di
     * eseguire (revisione architetturale 2026-09, rilievo A16).
     *
     * @param list<string> $filenames
     * @return array<string, list<string>> prefisso → file che lo condividono
     */
    public static function duplicatePrefixes(array $filenames): array
    {
        $byPrefix = [];
        foreach ($filenames as $f) {
            if (\preg_match('/^(\d+)_/', $f, $m)) {
                $byPrefix[$m[1]][] = $f;
            }
        }
        $dup = [];
        foreach ($byPrefix as $prefix => $files) {
            if (\count($files) < 2) {
                continue;
            }
            $known = self::KNOWN_DUPLICATE_PREFIXES[(string)$prefix] ?? null;
            \sort($files, SORT_NATURAL);
            if ($known !== null && $files === $known) {
                continue;
            }
            $dup[(string)$prefix] = $files;
        }
        return $dup;
    }

    /** @throws \RuntimeException se fra tutte le migration ci sono prefissi doppi non noti */
    private function assertUniquePrefixes(): void
    {
        $dup = self::duplicatePrefixes($this->discoverAll());
        if ($dup === []) {
            return;
        }
        $parts = [];
        foreach ($dup as $prefix => $files) {
            $parts[] = $prefix . ': ' . \implode(', ', $files);
        }
        throw new \RuntimeException(
            'Migration con lo stesso prefisso numerico (' . \implode('; ', $parts)
            . '): rinomina quella non ancora applicata con il prefisso successivo libero.'
        );
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
        $this->assertUniquePrefixes();
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
        return $out;
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
            if (
                ($c === '-' && \substr($sql, $i, 2) === '--'
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

    /**
     * Esegue un'istruzione e consuma le righe che restituisce.
     *
     * 2026-09-07 — le migrazioni idempotenti fanno `PREPARE`/`EXECUTE` di uno
     * `SELECT` quando non c'è niente da modificare (per esempio la 009,
     * quando la colonna c'è già). Con `PDO::exec()` quelle righe restano
     * appese e l'istruzione successiva muore con «Cannot execute queries while
     * other unbuffered queries are active»: su un database creato da
     * `database/schema.sql` — dove le colonne ci sono tutte — `php
     * tools/migrate.php` non arrivava in fondo.
     */
    private static function eseguiEScarica(\PDO $pdo, string $stmt): void
    {
        $st = $pdo->query($stmt);
        if (!$st instanceof \PDOStatement) {
            return;
        }
        do {
            $st->fetchAll();
        } while ($st->nextRowset());
        $st->closeCursor();
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
                self::eseguiEScarica($this->pdo, $stmt);
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
     *
     * 23/9/2026 (A-68) — NON 1062 `Duplicate entry`. Fino a oggi c'era, ma
     * non dice che un oggetto esiste: dice che dei dati sono in conflitto.
     * Un `ADD UNIQUE` su una colonna con doppioni o un INSERT su una chiave
     * già presente venivano saltati, la migrazione registrata come eseguita e
     * tools/migrate.php usciva con 0: una chiave unica poteva mancare in
     * produzione con il database dichiarato allineato. Misurato prima di
     * toglierlo: schema.sql più tutte le 138 migrazioni su un database vuoto
     * saltano quattro istruzioni (1060, 1061, 1005/121) e nessuna per 1062.
     * Prova: tests/Integration/MigrazioneConDoppioniTest.php.
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
            '/Duplicate (column name|key name|foreign key)|already exists|Duplicate key on write/i',
            $msg
        );
    }
}
