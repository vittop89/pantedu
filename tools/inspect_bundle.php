<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$id = (int)($argv[1] ?? 1494);
$svc = new \App\Services\Verifica\VerificaDocumentService();
$files = $svc->readManifestFiles(77, $id);
if (!$files) {
    echo "NO MANIFEST (legacy single-blob)\n";
    $tex = $svc->readTex(77, $id);
    echo "tex size=" . strlen($tex) . "\n";
    exit;
}
echo "manifest files (" . count($files) . "):\n";
foreach ($files as $f) {
    $missing = !empty($f['missing']) ? ' [MISSING]' : '';
    echo "  - " . $f['path'] . " (" . strlen((string)$f['content']) . " bytes)" . $missing . "\n";
}
