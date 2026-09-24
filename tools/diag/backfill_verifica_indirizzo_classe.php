<?php
/**
 * G19.49 — Backfill `indirizzo` + `classe` per verifica_documents legacy
 * (create prima della migration 027). Strategia: per ogni doc con
 * indirizzo/classe NULL, deriva da `teacher_content` con stesso
 * teacher_id + materia (gruppi piu' frequenti).
 *
 * Usage:
 *   php tools/diag/backfill_verifica_indirizzo_classe.php [--apply] [teacher_id]
 *   - dry-run di default; aggiungi --apply per scrivere nel DB.
 *   - teacher_id opzionale per limitare a un docente.
 */

declare(strict_types=1);

require __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$apply = in_array('--apply', $argv, true);
$args = array_values(array_filter($argv, fn($a) => $a !== '--apply' && $a !== __FILE__));
$teacherFilter = isset($args[1]) ? (int)$args[1] : null;

$pdo = Database::connection();

$where = 'WHERE (indirizzo IS NULL OR classe IS NULL)';
$bind = [];
if ($teacherFilter) {
    $where .= ' AND teacher_id = ?';
    $bind[] = $teacherFilter;
}

$stmt = $pdo->prepare(
    "SELECT id, teacher_id, materia FROM verifica_documents $where ORDER BY teacher_id, id"
);
$stmt->execute($bind);
$docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo (count($docs)) . " doc legacy trovati" . ($apply ? " (APPLY)" : " (dry-run)") . "\n";
echo str_repeat('=', 80) . "\n";

$cache = []; // key = "$teacher:$materia" → ['indirizzo' => x, 'classe' => y]
$updated = 0;
$skipped = 0;

foreach ($docs as $d) {
    $key = $d['teacher_id'] . ':' . strtolower($d['materia']);
    if (!isset($cache[$key])) {
        // Trova il (indirizzo, classe) piu' frequente nei teacher_content
        // del docente per quella materia (subject_code = materia).
        $q = $pdo->prepare(
            "SELECT indirizzo, classe, COUNT(*) AS n
             FROM teacher_content
             WHERE teacher_id = ? AND subject_code = ?
               AND indirizzo IS NOT NULL AND classe IS NOT NULL
             GROUP BY indirizzo, classe
             ORDER BY n DESC LIMIT 1"
        );
        $q->execute([$d['teacher_id'], $d['materia']]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        $cache[$key] = $row ?: null;
    }
    $derived = $cache[$key];
    if (!$derived) {
        printf("  ⚠ id %d teacher=%d mat=%s → no source data, skip\n",
            $d['id'], $d['teacher_id'], $d['materia']);
        $skipped++;
        continue;
    }
    printf("  id %d teacher=%d mat=%s → %s/%s (n=%d)\n",
        $d['id'], $d['teacher_id'], $d['materia'],
        $derived['indirizzo'], $derived['classe'], $derived['n']);
    if ($apply) {
        $u = $pdo->prepare(
            'UPDATE verifica_documents SET indirizzo = ?, classe = ? WHERE id = ?'
        );
        $u->execute([$derived['indirizzo'], $derived['classe'], $d['id']]);
    }
    $updated++;
}

echo str_repeat('=', 80) . "\n";
echo $apply
    ? "Applicate $updated update, $skipped skip.\n"
    : "Dry-run: $updated da aggiornare, $skipped skip. Aggiungi --apply per scrivere.\n";
