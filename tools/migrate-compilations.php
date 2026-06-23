<?php
declare(strict_types=1);
/**
 * ADR-026 Step 5 — scrive il body_pt unificato dentro risdoc_compilations.data_json
 * (chiave additiva `body_pt`), mantenendo `fields`/`state` intatti per sicurezza
 * e reversibilità. Legge tools/_compilations-bodypt.json (da migrate-compilations.mjs).
 *
 * REVERSIBILE: per annullare basta rimuovere la chiave body_pt da data_json
 * (o ripristinare il backup storage/backups/risdoc_compilations_*.json).
 *
 * Uso:
 *   php tools/migrate-compilations.php            # DRY-RUN
 *   php tools/migrate-compilations.php --apply     # scrive
 *   php tools/migrate-compilations.php --apply --skip-existing  # non ri-scrive chi ha già body_pt
 */
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

$apply = in_array('--apply', $argv, true);
$skip  = in_array('--skip-existing', $argv, true);

$mapPath = __DIR__ . '/_compilations-bodypt.json';
if (!is_file($mapPath)) { fwrite(STDERR, "ERRORE: $mapPath assente. Esegui prima: node tools/migrate-compilations.mjs <backup>\n"); exit(1); }
$map = json_decode((string)file_get_contents($mapPath), true);
if (!is_array($map)) { fwrite(STDERR, "ERRORE: _compilations-bodypt.json non valido\n"); exit(1); }

$pdo = Database::connection();
$sel = $pdo->prepare('SELECT data_json FROM risdoc_compilations WHERE id = ?');
$upd = $pdo->prepare('UPDATE risdoc_compilations SET data_json = ? WHERE id = ?');

printf("MODE: %s%s | body_pt da migrare: %d\n\n", $apply ? 'APPLY' : 'DRY-RUN', $skip ? ' [skip-existing]' : '', count($map));

$applied = 0; $skipped = 0; $missing = 0;
foreach ($map as $id => $bodyPt) {
    $id = (int)$id;
    $sel->execute([$id]);
    $row = $sel->fetch(\PDO::FETCH_ASSOC);
    if (!$row) { printf("  ⚠ #%-5d NON trovata in DB (skip)\n", $id); $missing++; continue; }
    $data = json_decode((string)$row['data_json'], true);
    if (!is_array($data)) $data = [];

    if ($skip && isset($data['body_pt']) && is_array($data['body_pt']) && count($data['body_pt'])) {
        printf("  = #%-5d SKIP (ha già body_pt, %d blocchi)\n", $id, count($data['body_pt']));
        $skipped++;
        continue;
    }

    $data['body_pt'] = $bodyPt; // additivo: fields/state restano
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    printf("  %s #%-5d body_pt=%d blocchi | fields preservati=%d | data_json %d byte\n",
        $apply ? '✓' : '·', $id, count($bodyPt), isset($data['fields']) ? count($data['fields']) : 0, strlen($json));

    if ($apply) { $upd->execute([$json, $id]); $applied++; }
}

echo "\n";
printf("applicati: %d | skip: %d | mancanti: %d\n", $applied, $skipped, $missing);
if (!$apply) echo "\n→ DRY-RUN. Per scrivere: php tools/migrate-compilations.php --apply [--skip-existing]\n";
