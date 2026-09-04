<?php
/**
 * Phase 19 — scan tutti i .contract.json in storage + valida vs
 * pantedu.content.v1 schema. Report errori raggruppati per file.
 *
 * Run:
 *   php tools/validate_contracts.php
 *   php tools/validate_contracts.php --verbose   # lista errori dettagliati
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\Contract\ContractSchemaValidator;

$verbose = \in_array('--verbose', $argv, true);
$root    = \dirname(__DIR__);

$files = \glob($root . '/storage/objects/institutes/*/private/*/**/*.contract.json', GLOB_NOSORT) ?: [];
// PHP glob no ** — use recursive iterator
$files = [];
$it = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($root . '/storage/objects', \FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $f) {
    if ($f->isFile() && \str_ends_with($f->getFilename(), '.contract.json')) {
        $files[] = (string)$f->getPathname();
    }
}

echo "Scanning " . \count($files) . " contract files...\n\n";

$validator = new ContractSchemaValidator();
$ok = 0;
$bad = 0;
$errorsByFile = [];

foreach ($files as $f) {
    $raw = \file_get_contents($f);
    if ($raw === false) continue;
    $data = \json_decode($raw, true);
    if (!\is_array($data)) {
        $bad++;
        $errorsByFile[$f] = ['invalid_json'];
        continue;
    }
    $errors = $validator->validate($data);
    if ($errors === []) $ok++;
    else {
        $bad++;
        $errorsByFile[$f] = $errors;
    }
}

echo "OK:  $ok\n";
echo "BAD: $bad\n";

if ($bad > 0) {
    echo "\n--- Files with validation errors ---\n";
    foreach ($errorsByFile as $f => $errs) {
        $rel = \str_replace($root . '/', '', \str_replace('\\', '/', $f));
        echo "  $rel\n";
        if ($verbose) {
            foreach ($errs as $e) echo "    - $e\n";
        } else {
            echo "    - " . \count($errs) . " error(s) [--verbose per dettagli]\n";
        }
    }
}
