<?php
/**
 * db_apply_schema.php
 *
 * Applica `database/schema.sql` al DB configurato in .env.
 * Idempotente: tutte le CREATE sono `IF NOT EXISTS`.
 *
 * Uso:
 *   php tools/db_apply_schema.php              # legge .env
 *   DB_HOST=... DB_NAME=... php tools/db_apply_schema.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

if (!\App\Core\Config::get('database.enabled')) {
    fwrite(STDERR, "DB_ENABLED=false — abilita nel .env prima di procedere.\n");
    exit(1);
}

$sql = file_get_contents(__DIR__ . '/../database/schema.sql');
if ($sql === false) {
    fwrite(STDERR, "schema.sql non trovato\n");
    exit(1);
}

try {
    $pdo = Database::connection();
    // Esegui come multi-statement (PDO non lo fa nativamente → split semplice)
    $statements = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql)));
    foreach ($statements as $stmt) {
        if ($stmt === '' || str_starts_with($stmt, '--')) continue;
        $pdo->exec($stmt);
    }
    echo "Schema applicato con successo a " . \App\Core\Config::get('database.name') . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERRORE: " . $e->getMessage() . "\n");
    exit(2);
}
