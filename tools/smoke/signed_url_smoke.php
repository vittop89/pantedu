<?php
/**
 * signed_url_smoke.php — Phase 14
 *
 * Genera un signed URL per una key data (o una key a caso da storage_objects)
 * e ne verifica firma + decodifica senza passare per HTTP.
 *
 * Uso:
 *   php tools/smoke/signed_url_smoke.php
 *   php tools/smoke/signed_url_smoke.php institutes/1/private/1/eser/foo/bar.html
 */

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Support\Storage\StorageFactory;

$key = $argv[1] ?? null;
if ($key === null) {
    if (!Database::isAvailable()) {
        fwrite(STDERR, "DB non disponibile e nessuna key passata.\n");
        exit(1);
    }
    $stmt = Database::connection()->query(
        "SELECT storage_key FROM storage_objects ORDER BY RAND() LIMIT 1"
    );
    $key = (string)($stmt->fetchColumn() ?: '');
    if ($key === '') {
        fwrite(STDERR, "Nessun oggetto in storage_objects. Esegui prima migrate_legacy_to_storage.\n");
        exit(1);
    }
}

$provider = StorageFactory::default();
$secret = (string)Config::get('storage.signing_secret', '');
if ($secret === '') {
    fwrite(STDERR, "STORAGE_SIGNING_SECRET non impostato in .env\n");
    exit(1);
}

$url = $provider->signedUrl($key, 120);
echo "key:       $key\n";
echo "signedUrl: $url\n";

// Parse-back e verifica HMAC
$parts = parse_url($url);
parse_str((string)($parts['query'] ?? ''), $q);
$payload = (string)($q['t'] ?? '');
$sig     = (string)($q['s'] ?? '');
$expected = hash_hmac('sha256', $payload, $secret);
$ok = hash_equals($expected, $sig);
echo "hmac_ok:   " . ($ok ? 'yes' : 'NO') . "\n";

$decoded = json_decode((string)base64_decode($payload, true) ?: '', true);
echo "payload:   " . json_encode($decoded) . "\n";

if ($ok && $provider->exists($key)) {
    $bytes = $provider->get($key);
    echo "bytes:     " . strlen($bytes) . " B (sha256=" . substr(hash('sha256', $bytes), 0, 16) . "...)\n";
    echo "OK\n";
    exit(0);
}
echo "FAIL\n";
exit(2);
