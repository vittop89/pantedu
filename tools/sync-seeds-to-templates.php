<?php

declare(strict_types=1);

/**
 * Sync dei seed PT (schemas/risdoc/_pt/seeds/*.pt.json) nel body_pt dei
 * risdoc_templates.
 *
 * Per ogni template con schema_path: legge lo schema JSON, se ha
 * `body_pt_seed_ref` carica quel file seed e aggiorna `body_pt` (solo se
 * cambiato). Idempotente. Eseguibile in locale e sul VPS via SSH (bypassa
 * HTTP/CSRF/WAF).
 *
 * Uso:
 *   php tools/sync-seeds-to-templates.php            # dry-run (mostra cosa farebbe)
 *   php tools/sync-seeds-to-templates.php --apply    # scrive nel DB
 *   php tools/sync-seeds-to-templates.php --apply --only=autorizzazione,glossario
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

$root  = dirname(__DIR__);
$apply = in_array('--apply', $argv, true);
$only  = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--only=')) {
        $only = array_filter(array_map('trim', explode(',', substr($a, 7))));
    }
}

$pdo = Database::connection();
$rows = $pdo->query(
    "SELECT id, code, schema_path, body_pt FROM risdoc_templates WHERE schema_path IS NOT NULL AND schema_path <> '' ORDER BY id"
)->fetchAll(\PDO::FETCH_ASSOC);

$updated = 0;
$skipped = 0;
$errors  = 0;

foreach ($rows as $r) {
    $id     = (int) $r['id'];
    $code   = (string) $r['code'];
    $schema = (string) $r['schema_path'];
    $schemaAbs = $root . '/' . $schema;

    if (!is_file($schemaAbs)) {
        echo "SKIP  #$id $code — schema mancante: $schema\n";
        $skipped++;
        continue;
    }
    $schemaJson = json_decode((string) file_get_contents($schemaAbs), true);
    $seedRef = $schemaJson['body_pt_seed_ref'] ?? null;
    if (!$seedRef) {
        $skipped++;
        continue; // schema senza seed (generato da sections)
    }
    if ($only !== null) {
        $base = basename($seedRef, '.pt.json');
        if (!in_array($base, $only, true)) {
            continue;
        }
    }

    $seedAbs = $root . '/' . $seedRef;
    if (!is_file($seedAbs)) {
        echo "ERR   #$id $code — seed mancante: $seedRef\n";
        $errors++;
        continue;
    }
    $seedRaw = (string) file_get_contents($seedAbs);
    $seed    = json_decode($seedRaw, true);
    if (!is_array($seed)) {
        echo "ERR   #$id $code — seed JSON invalido: $seedRef\n";
        $errors++;
        continue;
    }

    // Normalizza per confronto (re-encode entrambi con le stesse flag).
    $newJson = json_encode($seed, JSON_UNESCAPED_UNICODE);
    $curArr  = json_decode((string) $r['body_pt'], true);
    $curJson = is_array($curArr) ? json_encode($curArr, JSON_UNESCAPED_UNICODE) : null;

    $blocks = count($seed);
    if ($curJson === $newJson) {
        echo "OK    #$id $code — già aggiornato ($blocks blocchi)\n";
        $skipped++;
        continue;
    }

    if (!$apply) {
        echo "WOULD #$id $code — body_pt ← " . basename($seedRef)
            . " ($blocks blocchi, " . strlen($curJson ?? '') . "→" . strlen($newJson) . " bytes)\n";
        $updated++;
        continue;
    }

    $stmt = $pdo->prepare('UPDATE risdoc_templates SET body_pt = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$newJson, $id]);
    echo "WROTE #$id $code — body_pt ← " . basename($seedRef) . " ($blocks blocchi)\n";
    $updated++;
}

echo "\n" . ($apply ? 'APPLIED' : 'DRY-RUN') . ": updated=$updated skipped=$skipped errors=$errors\n";
exit($errors > 0 ? 1 : 0);
