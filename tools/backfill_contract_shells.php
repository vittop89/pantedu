<?php
/**
 * Phase 18 — backfill contract shell per righe teacher_content
 * (esercizio/verifica/lab) create senza contract_key (pre-fix store()
 * auto-create).
 *
 * Scan righe con content_type in [esercizio, verifica, lab] e
 * metadata_json.contract_key mancante, chiama
 * ContractRepository::createEmptyShellForNewContent per ciascuna.
 *
 * Run:
 *   php tools/backfill_contract_shells.php              # dry-run
 *   php tools/backfill_contract_shells.php --apply      # esegui
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\Contract\ContractRepository;

if (!Config::get('database.enabled')) {
    fwrite(STDERR, "DB_ENABLED=false.\n");
    exit(1);
}

$apply = \in_array('--apply', $argv, true);
$pdo   = Database::connection();

$rows = $pdo->query(
    "SELECT id, teacher_id, content_type, subject_code, topic, title, metadata_json
     FROM teacher_content
     WHERE content_type IN ('esercizio', 'verifica', 'lab')
       AND (metadata_json IS NULL
            OR JSON_EXTRACT(metadata_json, '$.contract_key') IS NULL)
     ORDER BY id"
)->fetchAll(\PDO::FETCH_ASSOC) ?: [];

echo "Trovate " . count($rows) . " righe senza contract_key.\n";
if (!$rows) exit(0);

foreach ($rows as $r) {
    printf("  id=%d  t=%d  %s/%s  '%s' / '%s'\n",
        $r['id'], $r['teacher_id'], $r['content_type'], $r['subject_code'],
        $r['topic'], $r['title']
    );
}

if (!$apply) {
    echo "\nDRY-RUN. --apply per creare gli shell.\n";
    exit(0);
}

// Risolve institute_id per-teacher (first non-MIUR)
$iidStmt = $pdo->prepare(
    "SELECT i.id FROM institutes i
     INNER JOIN teacher_institutes ti ON ti.institute_id=i.id
     WHERE ti.user_id=? AND i.code NOT LIKE 'MIUR-%'
     ORDER BY i.id LIMIT 1"
);
$fallbackStmt = $pdo->prepare('SELECT institute_id FROM teacher_institutes WHERE user_id=? LIMIT 1');

$repo = ContractRepository::default();
$ok = 0; $err = 0;
foreach ($rows as $r) {
    $tid = (int)$r['teacher_id'];
    $iidStmt->execute([$tid]);
    $iid = (int)($iidStmt->fetchColumn() ?: 0);
    if ($iid === 0) {
        $fallbackStmt->execute([$tid]);
        $iid = (int)($fallbackStmt->fetchColumn() ?: 0);
    }
    try {
        $repo->createEmptyShellForNewContent((int)$r['id'], $iid);
        $ok++;
    } catch (\Throwable $e) {
        fwrite(STDERR, "  FAIL id={$r['id']}: " . $e->getMessage() . "\n");
        $err++;
    }
}

echo "\nShell creati: $ok   errori: $err\n";
