<?php

declare(strict_types=1);

/**
 * Phase 25.R.23 — Verify integrity di un authority-export bundle.
 *
 * Uso (locale o VPS):
 *   php tools/gdpr/verify_export_bundle.php <path-to-extracted-dir>
 *
 * Esegue:
 *   1. Carica manifest.json
 *   2. Per ogni file in `files[]`, calcola sha256 reale e confronta col manifest
 *   3. Report: OK count, mismatch list, missing files
 *   4. Exit code 0 se tutto OK, 1 se mismatch/missing
 *
 * NON verifica HMAC (richiede KMS_MASTER_KEY off-line del data controller).
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php verify_export_bundle.php <bundle-dir>\n");
    exit(2);
}

$bundleDir = rtrim((string)$argv[1], '/\\');
if (!is_dir($bundleDir)) {
    fwrite(STDERR, "Not a directory: $bundleDir\n");
    exit(2);
}

$manifestPath = $bundleDir . '/manifest.json';
if (!is_file($manifestPath)) {
    fwrite(STDERR, "manifest.json not found in $bundleDir\n");
    exit(2);
}

$manifest = json_decode((string)file_get_contents($manifestPath), true);
if (!is_array($manifest) || !isset($manifest['files'])) {
    fwrite(STDERR, "Invalid manifest.json (no 'files' array)\n");
    exit(2);
}

echo "Bundle: $bundleDir\n";
echo "Manifest version: " . ($manifest['manifest_version'] ?? '?') . "\n";
echo "Exported at: " . ($manifest['exported_at'] ?? '?') . "\n";
echo "Exported by: " . ($manifest['exported_by'] ?? '?') . "\n";
echo "Sections: " . count($manifest['sections'] ?? []) . "\n\n";

$ok = 0; $mismatch = 0; $missing = 0;
$problems = [];

foreach ($manifest['files'] as $entry) {
    $path = $bundleDir . '/' . $entry['path'];
    if (!is_file($path)) {
        $missing++;
        $problems[] = "MISSING: " . $entry['path'];
        continue;
    }
    $actual = hash_file('sha256', $path);
    if ($actual === $entry['sha256']) {
        $ok++;
    } else {
        $mismatch++;
        $problems[] = "MISMATCH: " . $entry['path']
                    . "\n  expected: " . $entry['sha256']
                    . "\n  actual:   " . $actual;
    }
}

echo "=== INTEGRITY ===\n";
echo "  Files checked: " . ($ok + $mismatch + $missing) . "\n";
echo "  OK:            $ok\n";
echo "  Mismatches:    $mismatch\n";
echo "  Missing:       $missing\n\n";

if ($problems) {
    echo "=== PROBLEMS ===\n";
    foreach ($problems as $p) echo "  $p\n";
    exit(1);
}

// Print sections summary
echo "=== SECTIONS ===\n";
foreach ($manifest['sections'] ?? [] as $key => $section) {
    $sum = $section['summary'] ?? [];
    $sumStr = is_array($sum) ? json_encode($sum, JSON_UNESCAPED_SLASHES) : (string)$sum;
    echo sprintf("  %-22s files=%3d size=%10d %s\n",
        $key,
        $section['files_count'] ?? 0,
        $section['total_size'] ?? 0,
        substr($sumStr, 0, 100)
    );
}

echo "\n✅ ALL OK — chain-of-custody verificata.\n";
exit(0);
