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

$migrator = new Migrator(Database::connection(), $dir);

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
}
