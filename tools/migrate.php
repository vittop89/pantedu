<?php
/**
 * Phase 20 — CLI migration runner.
 *
 * Uso:
 *   php tools/migrate.php             # esegue migration pending
 *   php tools/migrate.php --dry-run   # mostra cosa farebbe
 *   php tools/migrate.php --status    # lista eseguite + pending
 *   php tools/migrate.php --cartella=DIR   # un'altra cartella di migrazioni
 *                                          # (per le prove: tests/Integration/MigrazioneCheFallisceTest.php)
 *
 * 2026-09-14 — un errore si dice, su stderr, con il file e il messaggio del
 * database, ed esce con 1. Prima una migrazione rifiutata da MariaDB faceva
 * uscire con 255 senza scrivere niente: l'eccezione non la raccoglieva
 * nessuno, e `bootstrap.php` manda gli errori non gestiti solo in
 * `storage/logs/php_errors.log`. Chi lanciava vedeva «Migration da eseguire»
 * e basta; il rilascio, che scrive stdout e stderr nel suo registro, idem.
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
foreach ($argv as $arg) {
    if (\str_starts_with($arg, '--cartella=')) {
        $dir = \substr($arg, \strlen('--cartella='));
    }
}
if (!\is_dir($dir)) {
    fwrite(STDERR, "La cartella delle migrazioni non esiste: $dir\n");
    exit(1);
}

/** Scrive l'errore su stderr e dice che cosa è rimasto nel database. */
$fallito = static function (\Throwable $e, array $primaDelGiro, ?Migrator $migrator): never {
    fwrite(STDERR, "\nMIGRAZIONE NON RIUSCITA: " . $e->getMessage() . "\n");
    if ($migrator !== null && $primaDelGiro !== []) {
        try {
            $restano = $migrator->pendingSenzaCreare();
            $applicate = \array_values(\array_diff($primaDelGiro, $restano));
            fwrite(STDERR, '  Applicate e registrate prima dell\'errore: '
                . ($applicate === [] ? 'nessuna' : \implode(', ', $applicate)) . "\n");
        } catch (\Throwable) {
            fwrite(STDERR, "  Non riesco a rileggere quali migrazioni sono registrate.\n");
        }
        fwrite(STDERR, "  Quella che ha fallito non è registrata: al prossimo giro si riprova.\n");
        fwrite(STDERR, "  Le sue istruzioni prima dell'errore possono essere già state eseguite\n");
        fwrite(STDERR, "  (MariaDB non annulla il DDL): guardare il database prima di rilanciare.\n");
    }
    exit(1);
};

$migrator = null;
$pending = [];
try {
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
} catch (\Throwable $e) {
    $fallito($e, $pending, $migrator);
}

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
