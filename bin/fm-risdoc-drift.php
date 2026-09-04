<?php
/**
 * bin/fm-risdoc-drift.php — ricalcola source_hash dei template risdoc.
 *
 * Scansiona storage/templates/ per ciascuna riga risdoc_templates,
 * ricalcola sha256 del bundle (HTML + TeX + CSS) e aggiorna
 * source_hash se cambiato. Non tocca gli override — il client
 * rileva il drift confrontando override.source_version con
 * templates.source_hash corrente.
 *
 * Uso (cron suggerito ogni notte):
 *   php bin/fm-risdoc-drift.php
 *   php bin/fm-risdoc-drift.php --dry-run
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

$dry = in_array('--dry-run', $argv, true);
$db  = Database::connection();
$root = dirname(__DIR__);

$rows = $db->query('SELECT id, code, source_dir, html_file, tex_file, css_file, source_hash FROM risdoc_templates ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

$changed = 0; $unchanged = 0; $missing = 0; $affected = 0;

foreach ($rows as $r) {
    $srcDir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$r['source_dir']);
    $htmlPath = $srcDir . DIRECTORY_SEPARATOR . $r['html_file'];
    if (!is_file($htmlPath)) {
        echo "[miss] {$r['code']} (html not found)\n";
        $missing++;
        continue;
    }
    $parts = [(string)file_get_contents($htmlPath)];
    if ($r['tex_file']) {
        $texDir = preg_replace('#/php$#', '/tex', str_replace('\\', '/', (string)$r['source_dir']));
        $texPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $texDir) . DIRECTORY_SEPARATOR . $r['tex_file'];
        $parts[] = is_file($texPath) ? (string)file_get_contents($texPath) : '';
    }
    if ($r['css_file']) {
        $cssDir = preg_replace('#/php$#', '/css', str_replace('\\', '/', (string)$r['source_dir']));
        $cssPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cssDir) . DIRECTORY_SEPARATOR . $r['css_file'];
        $parts[] = is_file($cssPath) ? (string)file_get_contents($cssPath) : '';
    }
    $newHash = hash('sha256', implode("\x1e", $parts));
    if ($newHash === $r['source_hash']) {
        $unchanged++;
        continue;
    }
    // count overrides che diventano drifted
    $n = $db->prepare('SELECT COUNT(*) FROM risdoc_teacher_overrides WHERE template_id=? AND source_version=?');
    $n->execute([$r['id'], $r['source_hash']]);
    $drifting = (int)$n->fetchColumn();
    $affected += $drifting;

    echo "[chg] {$r['code']}  hash {$r['source_hash']} → {$newHash}  (override non-drifted che diventano drifted: {$drifting})\n";
    if (!$dry) {
        $upd = $db->prepare('UPDATE risdoc_templates SET source_hash=? WHERE id=?');
        $upd->execute([$newHash, $r['id']]);
    }
    $changed++;
}

echo "\n=== drift scan summary ===\n";
echo "templates scanned: " . count($rows) . "\n";
echo "unchanged: {$unchanged}\n";
echo "changed hash: {$changed}\n";
echo "missing html: {$missing}\n";
echo "override che ora risultano drifted (totali): {$affected}\n";
if ($dry) echo "(dry-run — no writes)\n";
