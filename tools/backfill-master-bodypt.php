<?php
declare(strict_types=1);
/**
 * ADR-026 Step 4 (motore UNICO) — backfill del master body_pt sui template
 * istituzionali. Legge tools/_master-bodypt.json (keyed per basename schema,
 * generato da backfill-master-bodypt.mjs) e popola risdoc_templates.body_pt
 * per ogni template il cui schema_path matcha un basename noto.
 *
 * ADDITIVO + reversibile: i fork preferiscono il master se presente, altrimenti
 * fallback a derivazione live (ensureTemplateSeedPt). Svuotare = UPDATE body_pt=NULL.
 *
 * Uso:
 *   php tools/backfill-master-bodypt.php            # DRY-RUN (default, non scrive)
 *   php tools/backfill-master-bodypt.php --apply     # scrive su DB
 *   php tools/backfill-master-bodypt.php --apply --only-empty  # solo dove body_pt è NULL/vuoto
 */
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

$apply     = in_array('--apply', $argv, true);
$onlyEmpty = in_array('--only-empty', $argv, true);

$mapPath = __DIR__ . '/_master-bodypt.json';
if (!is_file($mapPath)) {
    fwrite(STDERR, "ERRORE: $mapPath assente. Esegui prima: node tools/backfill-master-bodypt.mjs\n");
    exit(1);
}
$map = json_decode((string)file_get_contents($mapPath), true);
if (!is_array($map)) {
    fwrite(STDERR, "ERRORE: _master-bodypt.json non valido\n");
    exit(1);
}

$pdo  = Database::connection();
$rows = $pdo->query('SELECT id, argomento, schema_path, body_pt FROM risdoc_templates ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);

printf("MODE: %s%s\n", $apply ? 'APPLY (scrive DB)' : 'DRY-RUN (nessuna scrittura)', $onlyEmpty ? ' [solo body_pt vuoti]' : '');
printf("Template totali: %d | master generati: %d\n\n", count($rows), count($map));

$matched = 0; $skipped = 0; $applied = 0; $nomap = [];
$upd = $pdo->prepare('UPDATE risdoc_templates SET body_pt = ? WHERE id = ?');

foreach ($rows as $r) {
    $sp = (string)($r['schema_path'] ?? '');
    if ($sp === '') { continue; } // template non schema-based (es. html/tex puro)
    $base = basename($sp);
    if (!isset($map[$base])) { $nomap[] = sprintf('#%d %s (schema=%s)', $r['id'], $r['argomento'], $base); continue; }

    $matched++;
    $hasBody = !empty($r['body_pt']) && trim((string)$r['body_pt']) !== '' && trim((string)$r['body_pt']) !== 'null';
    if ($onlyEmpty && $hasBody) {
        printf("  = #%-4d %-40s SKIP (ha già body_pt, %d byte)\n", $r['id'], mb_substr((string)$r['argomento'], 0, 40), strlen((string)$r['body_pt']));
        $skipped++;
        continue;
    }

    $json   = json_encode($map[$base], JSON_UNESCAPED_UNICODE);
    $blocks = count($map[$base]);
    $tag    = $hasBody ? 'OVERWRITE' : 'SET';
    printf("  %s #%-4d %-40s %s body_pt (%d blocchi, %d byte)\n",
        $apply ? '✓' : '·', $r['id'], mb_substr((string)$r['argomento'], 0, 40), $tag, $blocks, strlen($json));

    if ($apply) { $upd->execute([$json, (int)$r['id']]); $applied++; }
}

echo "\n";
printf("Match per basename: %d | skip: %d | applicati: %d\n", $matched, $skipped, $applied);
if ($nomap) {
    printf("\n%d template senza master corrispondente (schema non in mappa):\n", count($nomap));
    foreach ($nomap as $n) echo "  - $n\n";
}
if (!$apply) echo "\n→ DRY-RUN. Per scrivere: php tools/backfill-master-bodypt.php --apply [--only-empty]\n";
