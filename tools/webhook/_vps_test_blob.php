<?php
// Test MapBlobStore->get() for a known row from teacher_content.
// Run as: sudo -u www-data php /tmp/test_blob.php
require_once '/var/www/pantedu/app/bootstrap.php';

$pdo = App\Core\Database::connection();
$row = $pdo->query("
    SELECT id, teacher_id, map_blob_path, topic, title
    FROM teacher_content
    WHERE content_type='mappa' AND map_blob_path IS NOT NULL AND teacher_id > 0
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo "No map row found\n";
    exit;
}

echo "Test row: id={$row['id']} teacher_id={$row['teacher_id']}\n";
echo "Blob path: {$row['map_blob_path']}\n";
echo "Topic: {$row['topic']}\n";

$store = new App\Services\Maps\MapBlobStore();

// Check the absolute path
$rootDir = (string)App\Core\Config::get('app.paths.storage') . '/maps_enc';
echo "Root dir: $rootDir\n";
echo "Full path: $rootDir/{$row['map_blob_path']}\n";
echo "File exists: " . (is_file("$rootDir/{$row['map_blob_path']}") ? 'YES' : 'NO') . "\n";
echo "Readable: " . (is_readable("$rootDir/{$row['map_blob_path']}") ? 'YES' : 'NO') . "\n";

try {
    $xml = $store->get((int)$row['teacher_id'], (string)$row['map_blob_path']);
    echo "Decrypt OK. Size: " . strlen($xml) . " bytes\n";
    echo "First 200 chars: " . substr($xml, 0, 200) . "\n";
} catch (Throwable $e) {
    echo "EXCEPTION: " . get_class($e) . ": " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
