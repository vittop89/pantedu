<?php

/**
 * Phase 25.P.3 — Backfill `teacher_content_data.source_type` per contenuti legacy.
 *
 * Scorre tutti i teacher_content esistenti con source_type=NULL, carica il
 * relativo contract, esegue ContractAggregate::classifyShareability(),
 * salva il source_type derivato.
 *
 * Uso:
 *   php tools/backfill_source_type.php             # dry-run mostra cosa farebbe
 *   php tools/backfill_source_type.php --apply     # esegue updates
 *   php tools/backfill_source_type.php --apply --type=esercizio  # solo esercizi
 *
 * Idempotente: lascia inalterate le righe già con source_type non-NULL.
 *
 * Riferimenti:
 *   - app/Services/Contract/ContractAggregate.php::classifyShareability()
 *   - database/migrations/058_teacher_content_source_type.sql
 *   - docs/legal/aup.md §2.1
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Services\Contract\ContractRepository;
use App\Services\Contract\ContractAggregate;

$apply = in_array('--apply', $argv, true);
$typeFilter = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--type=')) {
        $typeFilter = substr($a, 7);
    }
}

if (!Database::isAvailable()) {
    fwrite(STDERR, "DB non disponibile.\n");
    exit(1);
}

$pdo = Database::connection();

$where = 'source_type IS NULL';
$params = [];
if ($typeFilter !== null) {
    $where .= ' AND content_type = ?';
    $params[] = $typeFilter;
}

$stmt = $pdo->prepare("SELECT id, teacher_id, content_subtype AS content_type, title FROM teacher_content_data WHERE {$where} ORDER BY id");
$stmt->execute($params);
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

if (!$rows) {
    echo "Nessun contenuto con source_type NULL da classificare.\n";
    exit(0);
}

echo "Trovati " . count($rows) . " contenuti da classificare" . ($typeFilter ? " (type={$typeFilter})" : "") . ":\n\n";

$repo = ContractRepository::default();
$stats = ['personal' => 0, 'book_textbook' => 0, 'mixed' => 0, 'empty' => 0, 'error' => 0];

foreach ($rows as $row) {
    $id = (int)$row['id'];
    $tid = (int)$row['teacher_id'];
    $type = (string)$row['content_type'];
    $title = (string)$row['title'];

    // Solo i tipi che hanno contract: esercizio, verifica, lab
    if (!in_array($type, ['esercizio', 'verifica', 'lab'], true)) {
        continue;
    }

    try {
        $agg = $repo->load($id, $tid);
        $cls = $agg->classifyShareability();
        $st = $cls['source_type'];

        if ($st === null) {
            $stats['empty']++;
            printf("  [%d] %-12s (%s) → SKIP (contract vuoto, 0 item)\n", $id, $type, mb_substr($title, 0, 40));
            continue;
        }

        $stats[$st] = ($stats[$st] ?? 0) + 1;
        $personal = $cls['items_personal'];
        $book = $cls['items_from_book'];
        $total = $cls['items_total'];

        printf(
            "  [%d] %-12s (%s) → %s (%d/%d personali, %d/%d da libro)\n",
            $id, $type, mb_substr($title, 0, 30), $st,
            $personal, $total, $book, $total
        );

        if ($apply) {
            $pdo->prepare('UPDATE teacher_content_data SET source_type = ? WHERE id = ?')
                ->execute([$st, $id]);
        }
    } catch (\Throwable $e) {
        $stats['error']++;
        printf("  [%d] %-12s → ERROR: %s\n", $id, $type, $e->getMessage());
    }
}

echo "\n";
echo "Statistiche:\n";
echo "  personal        : " . ($stats['personal'] ?? 0) . " (condivisibili)\n";
echo "  book_textbook   : " . ($stats['book_textbook'] ?? 0) . " (NON condivisibili)\n";
echo "  mixed           : " . ($stats['mixed'] ?? 0) . " (NON condivisibili per cautela)\n";
echo "  empty (skipped) : " . ($stats['empty'] ?? 0) . " (contract vuoti)\n";
echo "  error           : " . ($stats['error'] ?? 0) . "\n";

if (!$apply) {
    echo "\nDRY-RUN. Per applicare gli update: php tools/backfill_source_type.php --apply\n";
} else {
    echo "\nUpdates applicati al database.\n";
}
