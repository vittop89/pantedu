<?php
declare(strict_types=1);
/**
 * sync_sources_json_with_registry.php — DEPRECATED (G22.S15.bis).
 *
 * Era stopgap quando `sources.json` e `sources.registry.json` erano due
 * file separati. Ora `sources.registry.json` è la sola source-of-truth:
 *   - GET /api/teacher/sources.json trasforma il registry runtime
 *   - PUT /api/teacher/sources.json scrive sul registry
 * Vedi `tools/migrate_sources_json_to_registry.php` per la migrazione
 * one-shot dei docenti che avevano solo `sources.json`.
 *
 * Lo script è lasciato qui SOLO per riferimento storico — non eseguirlo
 * più: corromperebbe il registry duplicando entry. Verrà rimosso in una
 * release successiva.
 *
 * @deprecated 2026-05-10 G22.S15.bis
 */

$apply = in_array('--apply', $argv, true);

$baseDirs = [
    __DIR__ . '/../storage/objects/institutes/106/private/77',
    __DIR__ . '/../storage/objects/institutes/108/private/77',
    __DIR__ . '/../storage/objects/institutes/109/private/77',
];

$totalAdded = 0;
$filesChanged = 0;

foreach ($baseDirs as $base) {
    $sourcesPath  = "$base/sources.json";
    $registryPath = "$base/sources.registry.json";
    if (!is_file($sourcesPath) || !is_file($registryPath)) {
        echo "SKIP $base (file mancanti)\n";
        continue;
    }
    $sources  = json_decode(file_get_contents($sourcesPath), true) ?: ['sources' => []];
    $registry = json_decode(file_get_contents($registryPath), true) ?: ['sources' => []];
    if (!is_array($sources['sources'] ?? null)) $sources['sources'] = [];

    $added = 0;
    foreach (($registry['sources'] ?? []) as $r) {
        $key = $r['key'] ?? '';
        if ($key === '') continue;
        if (isset($sources['sources'][$key])) continue;

        // Estrai publisher dal volume "Vol.X Ed.Y - PUBLISHER"
        $vol = (string)($r['volume'] ?? '');
        $publisher = '';
        $shortVol = $vol;
        if (preg_match('/^(.*?)\s*-\s*([A-Z][A-Z0-9\/ ]*)\s*$/u', $vol, $m)) {
            $shortVol = trim($m[1]);
            $publisher = trim($m[2]);
        }

        $sources['sources'][$key] = [
            'code'      => $key,
            'title'     => (string)($r['book'] ?? ''),
            'volume'    => $shortVol,
            'publisher' => $publisher,
            'authors'   => (string)($r['authors'] ?? ''),
        ];
        $added++;
        $totalAdded++;
    }

    echo basename($base) . ": +$added entries\n";
    if ($added > 0 && $apply) {
        file_put_contents($sourcesPath, json_encode($sources, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $filesChanged++;
    } elseif ($added > 0) {
        $filesChanged++;
    }
}

echo "=== " . ($apply ? "APPLY" : "DRY-RUN") . " ===\n";
echo "Total entries added: $totalAdded\n";
echo "Files modified: $filesChanged\n";
