<?php
// Simple test: just check env vars + paths, no bootstrap.
echo "PHP CLI start\n";
echo "PANTEDU_DATA_PATH from env: " . (getenv('PANTEDU_DATA_PATH') ?: 'NOT SET') . "\n";

// Load .env.local manually
$envFile = '/var/www/pantedu/.env.local';
echo ".env.local readable: " . (is_readable($envFile) ? 'YES' : 'NO') . "\n";

if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/^([A-Z_][A-Z0-9_]*)=(.*)$/i', $line, $m)) {
            $_ENV[$m[1]] = trim($m[2], '"\'');
        }
    }
    echo "PANTEDU_DATA_PATH after load: " . ($_ENV['PANTEDU_DATA_PATH'] ?? 'STILL NOT SET') . "\n";
}

$storage = ($_ENV['PANTEDU_DATA_PATH'] ?? '/tmp') . '/storage/maps_enc';
echo "Storage path: $storage\n";
echo "Dir exists: " . (is_dir($storage) ? 'YES' : 'NO') . "\n";

// Pick first map
$dirs = glob($storage . '/*', GLOB_ONLYDIR);
if ($dirs) {
    $teacher_dir = $dirs[0];
    $files = glob($teacher_dir . '/*.bin');
    echo "Found {$teacher_dir} with " . count($files) . " blobs\n";
    if ($files) {
        $blob = $files[0];
        echo "Test blob: $blob\n";
        echo "Size: " . filesize($blob) . "\n";
        $raw = @file_get_contents($blob);
        echo "Read: " . ($raw === false ? 'FAIL' : strlen($raw) . ' bytes') . "\n";
    }
}
echo "DONE\n";
