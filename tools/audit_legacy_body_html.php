<?php
/**
 * Phase 17 — Audit dei contenuti legacy che dipendono ancora da `body_html`
 * (cioè NON hanno un contract JSON in storage). Serve come checklist per
 * la futura migration a contract-only.
 *
 * Run:  php tools/audit_legacy_body_html.php
 *
 * Output: per content_type + teacher, conta quanti righe hanno body_html ma
 * NON hanno `metadata_json.contract_key`. Queste sono le righe da migrare
 * prima di poter eliminare la colonna.
 */

require_once __DIR__ . '/../app/bootstrap.php';

if (!\App\Core\Config::get('database.enabled')) {
    fwrite(STDERR, "DB_ENABLED=false — abilita nel .env prima di procedere.\n");
    exit(1);
}
$pdo = \App\Core\Database::connection();

$sql = "SELECT
          content_type,
          COUNT(*) AS total,
          SUM(CASE WHEN JSON_EXTRACT(metadata_json, '$.contract_key') IS NOT NULL THEN 1 ELSE 0 END) AS with_contract,
          SUM(CASE WHEN body_html IS NOT NULL AND body_html <> '' THEN 1 ELSE 0 END) AS with_body_html,
          SUM(CASE WHEN
              JSON_EXTRACT(metadata_json, '$.contract_key') IS NULL
              AND body_html IS NOT NULL AND body_html <> ''
          THEN 1 ELSE 0 END) AS legacy_only
        FROM teacher_content
        GROUP BY content_type
        ORDER BY content_type";

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

printf("%-12s %8s %14s %14s %14s\n",
    'type', 'total', 'with_contract', 'with_body_html', 'legacy_only');
printf("%s\n", str_repeat('-', 70));
$totLegacy = 0;
foreach ($rows as $r) {
    printf("%-12s %8d %14d %14d %14d\n",
        $r['content_type'], $r['total'], $r['with_contract'],
        $r['with_body_html'], $r['legacy_only']);
    $totLegacy += (int)$r['legacy_only'];
}
printf("%s\n", str_repeat('-', 70));
if ($totLegacy > 0) {
    fwrite(STDERR, "\n⚠️  $totLegacy rows fallback su body_html (no contract). "
                 . "Migra prima di rimuovere la colonna.\n");
    exit(1);
}
echo "\n✅ Nessuna row legacy. body_html può essere deprecato.\n";
exit(0);
