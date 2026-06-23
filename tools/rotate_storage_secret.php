<?php
/**
 * Phase 18 — Storage signing secret rotation.
 *
 * Genera un nuovo secret crypto-safe (32 bytes → 64 hex chars) e lo
 * scrive in `.env.new` (NON sovrascrive `.env` direttamente).
 *
 * Post-rotation:
 *   1. rivedere diff manuale: `diff .env .env.new`
 *   2. backup: `cp .env .env.pre-phase18`
 *   3. sostituzione: `mv .env.new .env`
 *   4. restart worker/php-fpm
 *   5. invalidazione: i signed URL già emessi con il vecchio secret
 *      falliranno la verify — i client devono ri-request.
 *
 * Run:
 *   php tools/rotate_storage_secret.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$envPath    = \dirname(__DIR__) . '/.env';
$envNewPath = \dirname(__DIR__) . '/.env.new';

if (!\is_file($envPath)) {
    fwrite(STDERR, ".env non trovato: $envPath\n");
    exit(1);
}

$envContent = (string)\file_get_contents($envPath);
$newSecret  = \bin2hex(\random_bytes(32));

$pattern = '/^STORAGE_SIGNING_SECRET=.*$/m';
if (\preg_match($pattern, $envContent)) {
    $updated = \preg_replace($pattern, 'STORAGE_SIGNING_SECRET=' . $newSecret, $envContent);
} else {
    $updated = \rtrim($envContent, "\n") . "\nSTORAGE_SIGNING_SECRET=$newSecret\n";
}

\file_put_contents($envNewPath, $updated);

echo "Nuovo STORAGE_SIGNING_SECRET generato in $envNewPath\n";
echo "Prefix (verifica visiva): " . substr($newSecret, 0, 8) . "...\n\n";
echo "Next steps:\n";
echo "  1. diff .env .env.new\n";
echo "  2. cp .env .env.pre-phase18    # backup\n";
echo "  3. mv .env.new .env            # apply\n";
echo "  4. riavvia worker/php-fpm/apache\n";
echo "  5. avvisa utenti: signed URL pregressi sono invalidati\n";
