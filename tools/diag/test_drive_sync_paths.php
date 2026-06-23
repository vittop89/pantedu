<?php
/**
 * G19.49 — Diagnostic: verifica path Drive sync verifiche per teacher dato.
 * Stampa il folder path che VerificaSyncService::buildFolderPath produce
 * per ogni doc del docente (senza fare chiamate Drive).
 *
 * Usage: php tools/diag/test_drive_sync_paths.php [teacher_id]
 */

declare(strict_types=1);

require __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Services\Drive\VerificaSyncService;

$teacherId = (int)($argv[1] ?? 77);

$svc = new VerificaSyncService();
$ref = new ReflectionClass($svc);
$m   = $ref->getMethod('buildFolderPath');
$m->setAccessible(true);
$loadDocRow = $ref->getMethod('loadDocRow');
$loadDocRow->setAccessible(true);

$pdo = Database::connection();
$stmt = $pdo->prepare(
    'SELECT id FROM verifica_documents WHERE teacher_id = ? ORDER BY id DESC LIMIT 5'
);
$stmt->execute([$teacherId]);
$ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo "Teacher: $teacherId\n";
echo str_repeat('=', 80) . "\n";

foreach ($ids as $docId) {
    $row = $loadDocRow->invoke($svc, (int)$docId, $teacherId);
    if (!$row) continue;
    $folderPath = $m->invoke($svc, $row);
    $filename = (function ($r) {
        $title = (string)($r['title'] ?? 'verifica');
        $variant = (string)($r['variant'] ?? '');
        $version = (string)($r['version_label'] ?? '');
        $base = preg_replace('/[\\\\\\/]+/', '-', $title) ?? $title;
        $base = trim((string)preg_replace('/\s+/', '_', $base));
        if ($base === '') $base = 'verifica';
        $suffix = '';
        if ($version !== '') $suffix .= '_' . $version;
        if ($variant !== '') $suffix .= '_' . $variant;
        return $base . $suffix . '.tex';
    })($row);
    printf("[id %d] %s/%s\n", (int)$docId, $folderPath, $filename);
}
