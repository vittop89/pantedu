<?php
declare(strict_types=1);
/**
 * migrate_sources_json_to_registry.php — G22.S15.bis
 *
 * Migra il legacy `storage/objects/institutes/{iid}/private/{tid}/sources.json`
 * (dict per-code, formato `{sources:{<code>:{code,title,volume,publisher,authors}}}`)
 * verso il registry canonico
 * `storage/objects/institutes/{iid}/private/{tid}/sources.registry.json`
 * (array, formato `{sources:[{key,book,volume,authors},...]}`).
 *
 * Strategia di merge:
 *   - se manca il registry → crea da zero da sources.json (ogni `code` →
 *     entry con `key=code`, `book=title`, `volume="vol - publisher"`)
 *   - se esiste il registry → unisce: aggiunge ogni `key` di sources.json
 *     che non è già in registry (no overwrite). Il registry resta canonico
 *     per le entry preesistenti.
 *
 * IDEMPOTENTE: re-run = no-op (skip entry già presenti per key).
 *
 * NON elimina `sources.json`: lasciato come backup per safety. Una volta
 * validata la migrazione, può essere rimosso manualmente.
 *
 * Privacy: opera solo su file locali, nessuna network call. Si usa solo
 * come tool admin.
 *
 * Usage:
 *   php tools/migrate_sources_json_to_registry.php           # dry-run
 *   php tools/migrate_sources_json_to_registry.php --apply   # scrive
 */

$apply = in_array('--apply', $argv, true);

$basePath = realpath(__DIR__ . '/../storage/objects/institutes');
if (!$basePath) {
    fwrite(STDERR, "Path institutes non trovato\n");
    exit(1);
}

$totalAdded   = 0;
$totalCreated = 0;
$totalSkipped = 0;
$filesChanged = 0;

foreach (new DirectoryIterator($basePath) as $instDir) {
    if (!$instDir->isDir() || $instDir->isDot()) continue;
    $iid = $instDir->getFilename();
    if (!ctype_digit($iid)) continue;
    $privatePath = $instDir->getPathname() . '/private';
    if (!is_dir($privatePath)) continue;

    foreach (new DirectoryIterator($privatePath) as $teacherDir) {
        if (!$teacherDir->isDir() || $teacherDir->isDot()) continue;
        $tid = $teacherDir->getFilename();
        if (!ctype_digit($tid)) continue;

        $base = $teacherDir->getPathname();
        $sourcesPath  = "$base/sources.json";
        $registryPath = "$base/sources.registry.json";

        if (!is_file($sourcesPath)) {
            // Niente legacy → niente da migrare
            $totalSkipped++;
            continue;
        }

        $sources = json_decode((string)file_get_contents($sourcesPath), true);
        if (!is_array($sources['sources'] ?? null)) {
            echo "WARN $iid/$tid: sources.json malformato, skip\n";
            $totalSkipped++;
            continue;
        }

        $registry = is_file($registryPath)
            ? (json_decode((string)file_get_contents($registryPath), true) ?: [])
            : [];
        $regList = is_array($registry['sources'] ?? null) ? $registry['sources'] : [];
        $existingKeys = array_flip(array_filter(array_map(
            fn($r) => is_array($r) ? (string)($r['key'] ?? '') : '',
            $regList
        )));

        $added = 0;
        foreach ($sources['sources'] as $code => $src) {
            if (!is_string($code) || $code === '' || !is_array($src)) continue;
            if (isset($existingKeys[$code])) continue;
            $shortVol  = (string)($src['volume']    ?? '');
            $publisher = (string)($src['publisher'] ?? '');
            $vol = $publisher !== '' && $shortVol !== ''
                ? "$shortVol - $publisher"
                : ($shortVol !== '' ? $shortVol : $publisher);
            $regList[] = [
                'key'     => $code,
                'book'    => (string)($src['title']   ?? ''),
                'volume'  => $vol,
                'authors' => (string)($src['authors'] ?? ''),
            ];
            $existingKeys[$code] = true;
            $added++;
        }

        if ($added === 0 && is_file($registryPath)) {
            // Registry già completo → nulla da fare
            echo "OK $iid/$tid: registry già allineato (0 entry aggiunte)\n";
            continue;
        }

        $payload = [
            '$schema'      => 'pantedu.sources.v1',
            'teacher_id'   => (int)$tid,
            'institute_id' => (int)$iid,
            'generated_at' => date('c'),
            'count'        => count($regList),
            'sources'      => array_values($regList),
        ];
        $action = is_file($registryPath) ? "MERGE +$added" : "CREATE ($added entries)";
        echo "  $iid/$tid: $action\n";
        if ($apply) {
            file_put_contents(
                $registryPath,
                (string)json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            $filesChanged++;
        }
        if (is_file($registryPath) && $added > 0) {
            $totalAdded += $added;
        } else {
            $totalCreated++;
            $totalAdded += $added;
        }
    }
}

echo "\n=== " . ($apply ? "APPLY" : "DRY-RUN") . " ===\n";
echo "Entries aggiunte: $totalAdded\n";
echo "Registry creati ex-novo: $totalCreated\n";
echo "Teacher senza sources.json (skip): $totalSkipped\n";
echo "Files scritti: $filesChanged\n";
if (!$apply) {
    echo "\nUsa --apply per scrivere le modifiche.\n";
}
