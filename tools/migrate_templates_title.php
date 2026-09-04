<?php
/**
 * Phase 20 — migration one-shot: aggiorna i templates.json per-docente
 * esistenti aggiungendo il campo `title` (default per kind) se manca.
 *
 * I templates.json salvati prima dell'introduzione del campo `title`
 * contenevano solo `intro` + `items`. Al reload la UI mostrava input
 * Titolo vuoto. Questo script completa i file esistenti con:
 *   - VF:      title = "VoF d"
 *   - RM:      title = "RM"
 *   - Collect: title = "Equazioni"
 *
 * Idempotente: se `title` è già presente ed è stringa non vuota, skip.
 *
 * Run:
 *   php tools/migrate_templates_title.php           # dry-run
 *   php tools/migrate_templates_title.php --apply   # scrive i file
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$apply = in_array('--apply', $argv, true);
$baseDir = dirname(__DIR__) . '/storage/objects';

$defaultTitles = [
    'VF'      => 'VoF d',
    'RM'      => 'RM',
    'Collect' => 'Equazioni',
];

$scanned = 0;
$changed = 0;
$errors  = 0;

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS)
);
foreach ($it as $f) {
    if (!$f->isFile() || $f->getFilename() !== 'templates.json') continue;
    $scanned++;
    $path = $f->getPathname();
    $raw = @file_get_contents($path);
    if ($raw === false) { $errors++; continue; }
    $data = json_decode($raw, true);
    if (!is_array($data)) continue;

    $dirty = false;
    foreach ($defaultTitles as $k => $title) {
        if (!isset($data[$k]) || !is_array($data[$k])) continue;
        if (empty($data[$k]['title']) || !is_string($data[$k]['title'])) {
            $data[$k]['title'] = $title;
            $dirty = true;
        }
    }
    if (!$dirty) continue;
    $changed++;
    echo sprintf("[%s] + title su sezioni mancanti\n",
        str_replace('\\', '/', substr($path, strlen($baseDir) + 1)));

    if ($apply) {
        $json = (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (@file_put_contents($path, $json) === false) {
            fwrite(STDERR, "Errore scrittura: $path\n");
            $errors++;
        }
    }
}

echo "\n── Summary ──\n";
echo "Scanned: $scanned templates.json\n";
echo "Changed: $changed\n";
echo "Errors:  $errors\n";
echo $apply ? "MODE: --apply (written)\n" : "MODE: dry-run (no write). Run --apply to persist.\n";
