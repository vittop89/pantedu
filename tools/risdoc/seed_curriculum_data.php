<?php
/**
 * ADR-025 (B) — Seed `risdoc_curriculum_data` (righe GLOBALI institute_id=0)
 * dai file statici options_source folder-structured:
 *   storage/templates/risdoc/{dataset}/{IIS}/{mat}/{IIS}_{cls}_{mat}.json
 *
 * Idempotente (salta chiavi già presenti come globali). I file restano come
 * default/fallback; questo seed popola il DB così l'admin parte dal baseline.
 *
 * Uso:  php tools/risdoc/seed_curriculum_data.php [--dry-run]
 */

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Config;
use App\Services\Risdoc\CurriculumDataRepository;

if (!Config::get('database.enabled')) {
    fwrite(STDERR, "DB_ENABLED=false\n");
    exit(1);
}

$dryRun = in_array('--dry-run', $argv, true);
$root   = dirname(__DIR__, 2);
$base   = $root . '/storage/templates/risdoc';
$repo   = new CurriculumDataRepository();

// File folder-structured: .../{IIS}/{mat}/{IIS}_{cls}_{mat}.json
// IIS = [A-Z][A-Za-z]+ (es. SCI/ART), mat = lowercase, cls = alfanumerico.
$glob = glob($base . '/*/*/*/*/*.json') ?: [];
$ins = 0; $skip = 0; $bad = 0;

foreach ($glob as $abs) {
    $rel = substr($abs, strlen($base) + 1);          // dataset.../IIS/mat/file.json
    $parts = explode('/', str_replace('\\', '/', $rel));
    if (count($parts) < 4) { continue; }
    $file = array_pop($parts);                        // SCI_2_mat.json
    $mat  = array_pop($parts);                        // mat
    $ind  = array_pop($parts);                        // SCI
    $dataset = implode('/', $parts);                  // obiettivi_disciplinari_LG2010/abilita
    // Filename atteso: {IND}_{cls}_{mat}.json
    if (!preg_match('/^' . preg_quote($ind, '/') . '_([A-Za-z0-9]+)_' . preg_quote($mat, '/') . '\.json$/', $file, $m)) {
        continue; // non folder-structured (es. *_minime.json) → gestito come src.file
    }
    $cls = $m[1];
    $materia = strtoupper($mat);                       // canonico UPPER nel DB
    if ($repo->hasGlobal($dataset, $ind, $cls, $materia)) { $skip++; continue; }
    $decoded = json_decode((string)file_get_contents($abs), true);
    if (!is_array($decoded)) { $bad++; continue; }
    if ($dryRun) {
        echo "WOULD INSERT $dataset $ind $cls $materia (" . count($decoded) . " opt)\n";
    } else {
        $repo->save(0, $dataset, $ind, $cls, $materia, $decoded, null);
    }
    $ins++;
}

echo sprintf("Seed curriculum_data: %s=%d skip=%d bad=%d (totale globali ora=%d)\n",
    $dryRun ? 'would-insert' : 'inserted', $ins, $skip, $bad,
    $dryRun ? -1 : $repo->countGlobal());
