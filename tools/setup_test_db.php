<?php

declare(strict_types=1);

/**
 * Provisioning del DATABASE DI TEST ISOLATO (pantedu_test).
 *
 * La suite gira in APP_ENV=testing (phpunit.xml) → app/Config/database.php
 * seleziona automaticamente `pantedu_test`, così i test NON toccano mai i dati
 * di sviluppo/produzione (i test crypto cancellano/rigenerano chiavi).
 *
 * Ricetta (richiede mysqldump/mysql sul PATH o XAMPP):
 *   1. crea pantedu_test (vuoto)
 *   2. copia lo SCHEMA da pantedu_dev (struttura + viste/trigger, NO dati)
 *   3. copia i DATI delle sole tabelle di RIFERIMENTO istituzionali
 *      (schema_migrations, institutes, curriculum_entries, sidebar_sections,
 *       sidebar_section_overrides, risdoc_templates) — niente PII, niente
 *       contenuti docente, niente chiavi crypto.
 *   4. seed minimo di utenti teacher di test.
 * NB: le migration NON ricostruiscono lo schema da zero (001 assume `users`
 *     già esistente), quindi si copia lo schema dal dev.
 *
 *   php tools/setup_test_db.php
 */

require __DIR__ . '/../app/bootstrap.php';

$host   = $_ENV['DB_HOST'] ?? '127.0.0.1';
$port   = (int)($_ENV['DB_PORT'] ?? 3306);
$user   = $_ENV['DB_USER'] ?? 'root';
$pass   = $_ENV['DB_PASS'] ?? '';
$devDb  = $_ENV['DB_NAME'] ?? 'pantedu_dev';
$testDb = $_ENV['DB_NAME_TEST'] ?? 'pantedu_test';

if ($testDb === $devDb) {
    fwrite(STDERR, "ERRORE: DB_NAME_TEST coincide col DB di sviluppo ($testDb). Abort.\n");
    exit(1);
}

/** Trova un binario (mysql/mysqldump) sul PATH o nelle cartelle XAMPP/MariaDB note. */
function findBin(string $name): ?string
{
    $exe = stripos(PHP_OS, 'WIN') === 0 ? $name . '.exe' : $name;
    foreach ([
        'C:/xampp/mysql/bin',
        'C:/Program Files/MariaDB 11.8/bin',
        'C:/Program Files/MySQL/MySQL Server 8.0/bin',
        '/usr/bin', '/usr/local/bin', '/opt/homebrew/bin',
    ] as $dir) {
        if (is_file("$dir/$exe")) {
            return "$dir/$exe";
        }
    }
    // PATH fallback
    $which = stripos(PHP_OS, 'WIN') === 0 ? "where $exe" : "command -v $exe";
    $found = trim((string)@shell_exec($which));
    return $found !== '' ? strtok($found, "\r\n") : null;
}

$mysql    = findBin('mysql');
$mysqldump = findBin('mysqldump');
if ($mysql === null || $mysqldump === null) {
    fwrite(STDERR, "ERRORE: mysql/mysqldump non trovati. Installa il client MySQL/MariaDB.\n");
    exit(1);
}

$auth = sprintf('-u%s -h%s -P%d %s', escapeshellarg($user), escapeshellarg($host), $port,
    $pass !== '' ? '-p' . escapeshellarg($pass) : '');
$run = function (string $cmd): void {
    $out = []; $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    if ($code !== 0) {
        fwrite(STDERR, "  comando fallito: " . implode("\n  ", $out) . "\n");
        exit($code);
    }
};

echo "→ creo `$testDb`\n";
$run(sprintf('%s %s -e %s', escapeshellarg($mysql), $auth,
    escapeshellarg("DROP DATABASE IF EXISTS `$testDb`; CREATE DATABASE `$testDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;")));

echo "→ schema (struttura + viste/trigger, senza dati)\n";
$run(sprintf('%s %s --no-data --routines --triggers --skip-lock-tables %s | %s %s %s',
    escapeshellarg($mysqldump), $auth, escapeshellarg($devDb),
    escapeshellarg($mysql), $auth, escapeshellarg($testDb)));

echo "→ dati tabelle di riferimento (no PII/contenuti)\n";
$refTables = 'schema_migrations institutes curriculum_entries sidebar_sections sidebar_section_overrides risdoc_templates';
$run(sprintf('%s %s --no-create-info --skip-lock-tables %s %s | %s %s %s',
    escapeshellarg($mysqldump), $auth, escapeshellarg($devDb), $refTables,
    escapeshellarg($mysql), $auth, escapeshellarg($testDb)));

echo "→ seed utenti teacher di test\n";
$pdo = new PDO("mysql:host=$host;port=$port;dbname=$testDb;charset=utf8mb4", $user, $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$seed = $pdo->prepare(
    "INSERT IGNORE INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'teacher')"
);
$seed->execute(['superadmin', 'seed@test.local', password_hash('test-seed', PASSWORD_DEFAULT)]);
$seed->execute(['marco.rossi', 'seed2@test.local', password_hash('test-seed', PASSWORD_DEFAULT)]);

echo "\n✓ pantedu_test pronto. Lancia: vendor/bin/phpunit\n";
echo "  NB: i test che richiedono i template verifiche/LaTeX o stato per-test\n";
echo "  dedicato restano rossi/skip finché non provisionati (vedi roadmap).\n";
