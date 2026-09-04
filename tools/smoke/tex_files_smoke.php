<?php

/**
 * G22.S10 — Smoke test multi-file manifest CRUD.
 *
 * Usa una verifica esistente per:
 *   1. Leggere manifest via service (readManifestFiles)
 *   2. Modificare 1 file (es: aggiungere whitespace)
 *   3. Salvare via updateTexFiles
 *   4. Rileggere e verificare diff
 *   5. Ripristinare contenuto originale
 *
 * Run: php tools/smoke/tex_files_smoke.php <docId>
 */

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Services\Verifica\VerificaDocumentService;

$docId = (int)($argv[1] ?? 0);
if ($docId <= 0) {
    fwrite(STDERR, "Usage: php tools/smoke/tex_files_smoke.php <docId>\n");
    exit(1);
}

if (!Database::isAvailable()) {
    fwrite(STDERR, "DB non disponibile\n");
    exit(1);
}

// Risolvi teacher_id dalla row.
$pdo = Database::connection();
$row = $pdo->prepare('SELECT teacher_id FROM verifica_documents WHERE id = ?');
$row->execute([$docId]);
$teacherId = (int)$row->fetchColumn();
if (!$teacherId) {
    fwrite(STDERR, "doc $docId not found\n");
    exit(1);
}

$svc = new VerificaDocumentService();

echo "=== READ manifest ===\n";
$files = $svc->readManifestFiles($teacherId, $docId);
if (!$files) {
    echo "(legacy single-blob, no manifest — skipping)\n";
    exit(0);
}
foreach ($files as $f) {
    echo sprintf("  %s (%d bytes)\n", $f['path'], strlen($f['content']));
}

echo "\n=== UPDATE manifest (no-op modify: aggiungi 1 commento al main.tex) ===\n";
$snapshot = [];
foreach ($files as $f) {
    $snapshot[] = ['path' => $f['path'], 'content' => $f['content']];
}
$modified = [];
foreach ($files as $f) {
    if ($f['path'] === 'main.tex') {
        $modified[] = [
            'path' => 'main.tex',
            'content' => "% smoke test marker " . time() . "\n" . $f['content'],
        ];
    } else {
        $modified[] = $f;
    }
}

$start = microtime(true);
$doc = $svc->updateTexFiles($teacherId, $docId, $modified);
$dur = (microtime(true) - $start) * 1000;
echo sprintf("  saved in %.1fms; new tex_sha256=%s; tex_size=%d\n",
    $dur, $doc['tex_sha256'], $doc['tex_size']);

echo "\n=== READ-after-write verifica ===\n";
$files2 = $svc->readManifestFiles($teacherId, $docId);
foreach ($files2 as $f) {
    $oldSize = 0;
    foreach ($files as $orig) if ($orig['path'] === $f['path']) $oldSize = strlen($orig['content']);
    echo sprintf("  %s: %d bytes (was %d, diff %+d)\n",
        $f['path'], strlen($f['content']), $oldSize, strlen($f['content']) - $oldSize);
}

echo "\n=== RESTORE original ===\n";
$svc->updateTexFiles($teacherId, $docId, $snapshot);
echo "  ok\n";

echo "\nALL GOOD ✓\n";
