<?php

declare(strict_types=1);

/**
 * Deduplicazione istituti — CLI controllata.
 *
 * Trova i gruppi di righe `institutes` che rappresentano la STESSA scuola
 * (dedupKey nome+città) e le fonde nella riga canonica ri-puntando ogni FK
 * institute_id (vedi InstituteMergeService).
 *
 * USO:
 *   php tools/dedup_institutes.php            # dry-run: stampa il piano
 *   php tools/dedup_institutes.php --apply    # esegue i gruppi "safe"
 *   php tools/dedup_institutes.php --apply --force   # esegue ANCHE i non-safe
 *                                                    # (gruppi con >1 code MIUR reale = possibili plessi)
 *
 * SEMPRE fare un backup DB prima di --apply (vedi project_vps_backup).
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Services\InstituteMergeService;

$apply = in_array('--apply', $argv, true);
$force = in_array('--force', $argv, true);

if (!Database::isAvailable()) {
    fwrite(STDERR, "DB non disponibile.\n");
    exit(1);
}

$svc  = new InstituteMergeService();
$plan = $svc->planGroups();

if (!$plan) {
    echo "Nessun gruppo duplicato trovato. ✓\n";
    exit(0);
}

echo "=== Piano deduplicazione (" . count($plan) . " gruppi) ===\n";
echo $apply ? ($force ? "MODE: APPLY (safe + force)\n\n" : "MODE: APPLY (solo safe)\n\n") : "MODE: DRY-RUN\n\n";

$applied = 0;
$skipped = 0;
foreach ($plan as $g) {
    $can = $g['canonical'];
    $flag = $g['safe'] ? 'SAFE' : 'NON-SAFE (>1 code MIUR reale)';
    echo "• [{$flag}] {$g['key']}\n";
    echo "    canonico → id={$can['id']} code={$can['code']} name=\"{$can['name']}\" (peso {$can['weight']})\n";
    if ($g['adopt_code']) {
        echo "    adotta code MIUR reale: {$g['adopt_code']}\n";
    }
    foreach ($g['duplicates'] as $d) {
        echo "    merge ← id={$d['id']} code={$d['code']} (peso {$d['weight']})\n";
    }

    if (!$apply) { continue; }
    if (!$g['safe'] && !$force) {
        echo "    ⏭  SKIP (non-safe, usa --force per eseguire)\n";
        $skipped++;
        continue;
    }
    foreach ($g['duplicates'] as $d) {
        try {
            $res = $svc->merge((int)$can['id'], (int)$d['id'], $g['adopt_code']);
            $movedStr = implode(', ', array_map(
                fn($k, $v) => "$k=$v",
                array_keys($res['moved']),
                array_values($res['moved'])
            ));
            echo "    ✓ merged {$d['id']}→{$can['id']} [{$movedStr}]"
               . ($res['code_set'] ? " code→{$res['code_set']}" : '') . "\n";
            $applied++;
        } catch (\Throwable $e) {
            echo "    ✗ ERRORE merge {$d['id']}→{$can['id']}: {$e->getMessage()}\n";
        }
    }
}

echo "\n=== Fatto. merges applicati: {$applied}, gruppi skippati: {$skipped} ===\n";
if (!$apply) {
    echo "(dry-run — niente modificato. Rilancia con --apply dopo backup DB.)\n";
}
