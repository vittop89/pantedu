<?php
/**
 * Cleanup orphan blob files: cancella file .bin in storage/verifiche_enc/{tid}/
 * che non sono più referenziati da alcuna verifica_documents row.
 *
 * Uso:
 *   php tools/crypto/cleanup_orphan_blobs.php [--apply]
 *
 * DRY-RUN default. Conta orfani + size totale recuperabile.
 */

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Database;

$apply = in_array('--apply', $argv, true);
echo "=== Orphan blob cleanup (verifiche_enc) ===\n";
echo "Mode: " . ($apply ? "🔥 APPLY" : "🔍 DRY-RUN") . "\n\n";

$pdo = Database::connection();
$root = dirname(__DIR__, 2);
$vencRoot = $root . '/storage/verifiche_enc';

// Raccogli tutti i blob_path referenziati
$referenced = [];
$stmt = $pdo->query("SELECT tex_blob_path, pdf_blob_path, tex_files FROM verifica_documents");
foreach ($stmt as $r) {
    if (!empty($r['tex_blob_path'])) $referenced[$r['tex_blob_path']] = true;
    if (!empty($r['pdf_blob_path'])) $referenced[$r['pdf_blob_path']] = true;
    if (!empty($r['tex_files'])) {
        $files = json_decode($r['tex_files'], true);
        foreach (($files ?: []) as $f) {
            if (!empty($f['blob_path'])) $referenced[$f['blob_path']] = true;
        }
    }
}
echo "Blob referenziati nel DB: " . count($referenced) . "\n";

// Scan filesystem
$orphans = [];
$totalSize = 0;
foreach (glob($vencRoot . '/*', GLOB_ONLYDIR) ?: [] as $teacherDir) {
    foreach (glob($teacherDir . '/*.bin') ?: [] as $f) {
        $relPath = basename($teacherDir) . '/' . basename($f);
        if (!isset($referenced[$relPath])) {
            $orphans[] = $f;
            $totalSize += filesize($f);
        }
    }
}
echo "Blob orfani trovati: " . count($orphans) . " (size " . round($totalSize / 1024 / 1024, 1) . " MiB)\n\n";

if (!$apply) {
    echo "🔍 Dry-run. Per cancellare, rilancia con --apply\n";
    exit(0);
}

$deleted = 0;
foreach ($orphans as $f) {
    if (@unlink($f)) $deleted++;
}
echo "🔥 Cancellati $deleted file orfani (recuperati " . round($totalSize / 1024 / 1024, 1) . " MiB)\n";
