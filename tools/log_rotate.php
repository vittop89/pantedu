<?php
/**
 * Phase 19 — CLI: rotate log files in storage/logs/.
 *
 * Run:
 *   php tools/log_rotate.php               # rotate se supera 5MB
 *   php tools/log_rotate.php --max=1M      # custom size threshold
 *   php tools/log_rotate.php --force       # rotate anche se sotto threshold
 *
 * Cron suggerito: daily 03:00
 *   0 3 * * *  cd /path/to/pantedu && php tools/log_rotate.php >> storage/logs/rotate.log 2>&1
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\LogRotator;

$maxArg = null;
$force = false;
foreach ($argv as $a) {
    if (\preg_match('/^--max=(\d+)([KMG]?)$/i', $a, $m)) {
        $n = (int)$m[1];
        $unit = \strtoupper($m[2]);
        $maxArg = $n * match ($unit) { 'K' => 1024, 'M' => 1024 * 1024, 'G' => 1024 * 1024 * 1024, default => 1 };
    }
    if ($a === '--force') $force = true;
}

$base = \dirname(__DIR__);
$dirs = [$base . '/storage/logs', $base . '/log/errors'];

$maxBytes = $maxArg ?? ($force ? 1 : 5 * 1024 * 1024);
$rotator  = new LogRotator(maxBytes: $maxBytes);
$total    = 0;
foreach ($dirs as $dir) {
    if (!\is_dir($dir)) continue;
    $n = $rotator->rotateDirectory($dir);
    $total += $n;
    echo "Rotated $n file(s) in $dir\n";
}
echo "\nTotale ruotati: $total (threshold=" . \number_format($maxBytes) . " bytes)\n\n";

// Summary post-rotate per-directory
foreach ($dirs as $dir) {
    if (!\is_dir($dir)) continue;
    echo "== $dir ==\n";
    $files = \array_merge(
        \glob($dir . '/*.log') ?: [],
        \glob($dir . '/*.json') ?: [],
        \glob($dir . '/*.log.*') ?: [],
    );
    foreach ($files as $f) {
        $s = @\filesize($f);
        if ($s === false) continue;
        echo \sprintf("  %-50s %10s bytes\n", \basename($f), \number_format($s));
    }
    echo "\n";
}
