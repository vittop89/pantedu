<?php
/**
 * Phase 18 — Cleanup rows teacher_content con (indirizzo IS NULL AND classe IS NULL).
 *
 * Policy post-Phase 18:
 *   NULL scope = riga "admin all-view only" (NON visibile nelle route
 *   scoped /studio/*). Se esiste una riga duplicata con scope definito
 *   stesso (teacher_id, content_type, subject_code, topic, title) →
 *   la NULL viene ELIMINATA (duplicato obsoleto da import legacy).
 *   Altrimenti → LOGGATA per review manuale (serve assegnare scope
 *   oppure confermare che è admin-only legittima).
 *
 * Modalità:
 *   --dry-run  (default)  → mostra cosa farebbe, non modifica
 *   --apply               → applica la cancellazione
 *
 * Run:
 *   php tools/cleanup_orphan_content.php
 *   php tools/cleanup_orphan_content.php --apply
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Core\Config;

if (!Config::get('database.enabled')) {
    fwrite(STDERR, "DB_ENABLED=false.\n");
    exit(1);
}

$apply = in_array('--apply', $argv, true);
$pdo   = Database::connection();

$orphans = $pdo->query(
    "SELECT id, teacher_id, content_type, subject_code, topic, title
     FROM teacher_content
     WHERE indirizzo IS NULL AND classe IS NULL
     ORDER BY content_type, subject_code, id"
)->fetchAll(\PDO::FETCH_ASSOC);

if (!$orphans) {
    echo "Nessuna riga orfana NULL,NULL. OK.\n";
    exit(0);
}

echo "Trovate " . count($orphans) . " righe NULL,NULL.\n";
echo str_repeat('-', 80) . "\n";

$toDelete  = [];
$toReview  = [];

$dupStmt = $pdo->prepare(
    'SELECT id, indirizzo, classe FROM teacher_content
     WHERE teacher_id = :t AND content_type = :c AND subject_code = :s
       AND topic = :topic AND title = :title
       AND (indirizzo IS NOT NULL OR classe IS NOT NULL)'
);

foreach ($orphans as $r) {
    $dupStmt->execute([
        ':t'     => $r['teacher_id'],
        ':c'     => $r['content_type'],
        ':s'     => $r['subject_code'],
        ':topic' => $r['topic'],
        ':title' => $r['title'],
    ]);
    $dups = $dupStmt->fetchAll(\PDO::FETCH_ASSOC);

    if ($dups) {
        $r['_dup_ids'] = array_map(static fn($d) => (int)$d['id'], $dups);
        $toDelete[] = $r;
    } else {
        $toReview[] = $r;
    }
}

echo "\n[DA ELIMINARE] NULL con duplicato scoped (sicuro):\n";
if (!$toDelete) echo "  (nessuna)\n";
foreach ($toDelete as $r) {
    printf(
        "  id=%d  t=%d  %s/%s  '%s' / '%s'  → dup=[%s]\n",
        $r['id'], $r['teacher_id'],
        $r['content_type'], $r['subject_code'],
        $r['topic'], $r['title'],
        implode(',', $r['_dup_ids'])
    );
}

echo "\n[DA REVIEW] NULL senza duplicato (review manuale — assegnare scope o confermare admin-only):\n";
if (!$toReview) echo "  (nessuna)\n";
foreach ($toReview as $r) {
    printf(
        "  id=%d  t=%d  %s/%s  '%s' / '%s'\n",
        $r['id'], $r['teacher_id'],
        $r['content_type'], $r['subject_code'],
        $r['topic'], $r['title']
    );
}

echo "\n" . str_repeat('-', 80) . "\n";
echo "Delete: " . count($toDelete) . "  Review: " . count($toReview) . "\n";

if (!$apply) {
    echo "\nDRY-RUN. Per applicare: php tools/cleanup_orphan_content.php --apply\n";
    exit(0);
}

if (!$toDelete) {
    echo "\nNulla da eliminare.\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    $del = $pdo->prepare('DELETE FROM teacher_content WHERE id = ?');
    foreach ($toDelete as $r) {
        $del->execute([$r['id']]);
    }
    $pdo->commit();
    echo "\nEliminate " . count($toDelete) . " righe duplicate NULL.\n";
    if ($toReview) {
        echo "ATTENZIONE: " . count($toReview) . " righe restano NULL senza scope — review manuale.\n";
    }
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "ERRORE: " . $e->getMessage() . "\n");
    exit(1);
}
