<?php
/**
 * Phase 20 — CLI migration runner.
 *
 * Uso:
 *   php tools/migrate.php             # esegue migration pending
 *   php tools/migrate.php --dry-run   # mostra cosa farebbe
 *   php tools/migrate.php --status    # lista eseguite + pending
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;

if (!Config::get('database.enabled')) {
    fwrite(STDERR, "DB_ENABLED=false — abilita nel .env.\n");
    exit(1);
}

$dryRun = \in_array('--dry-run', $argv, true);
$status = \in_array('--status', $argv, true);
$dir    = \dirname(__DIR__) . '/database/migrations';

// Connessione con i diritti DDL: e' l'unica che puo' creare o alterare
// strutture. L'utente del sito non ha piu' il privilegio TRIGGER, cosi'
// non puo' rimuovere le protezioni append-only sui log di audit.
$migrator = new Migrator(Database::migrationConnection(), $dir);

if ($status) {
    $migrator->ensureTrackingTable();
    $done    = $migrator->executedFilenames();
    $pending = $migrator->pending();
    echo "Eseguite (" . \count($done) . "):\n";
    foreach ($done as $f) echo "  ✓ $f\n";
    echo "\nPending (" . \count($pending) . "):\n";
    foreach ($pending as $f) echo "  ⧖ $f\n";
    exit(0);
}

$pending = $migrator->pending();
if (!$pending) {
    echo "Nessuna migration pending. DB aggiornato.\n";
    exit(0);
}

echo "Migration da eseguire (" . \count($pending) . "):\n";
foreach ($pending as $f) echo "  - $f\n";
echo "\n";

$executed = $migrator->run(dryRun: $dryRun);

if ($dryRun) {
    echo "DRY-RUN. Per applicare: php tools/migrate.php\n";
    foreach ($executed as $e) echo "  $e\n";
} else {
    echo "Eseguite " . \count($executed) . " migration:\n";
    foreach ($executed as $e) echo "  ✓ $e\n";

    // Gli statement saltati come "gia' applicati" finivano solo in error_log,
    // e la migration risultava riuscita comunque. Ma "gia' applicato" e'
    // un'ipotesi che il Migrator fa guardando il codice d'errore: se e'
    // sbagliata, la migration passa a vuoto e nessuno lo sa. Chi lancia deve
    // vederli, e poterli confrontare con check_migrations_applied.php.
    $saltati = $migrator->skipped();
    if ($saltati !== []) {
        $tot = \array_sum(\array_map('count', $saltati));
        echo "\nStatement saltati come gia' applicati: $tot\n";
        echo "  Sono ipotesi del runner, non conferme. Verifica gli effetti con:\n";
        echo "    php tools/dev/check_migrations_applied.php\n\n";
        foreach ($saltati as $file => $stmts) {
            echo "  $file\n";
            foreach ($stmts as $s) {
                echo "    · $s\n";
            }
        }
    }
}
