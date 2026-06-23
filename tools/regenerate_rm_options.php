<?php
/**
 * Phase 16 — aggiorna solo il campo options[] nei JSON contract esistenti,
 * leggendo dai legacy archiviati in _legacy_archive_phase15/.
 *
 * Non tocca il resto del contract (question/justification/badge/etc), solo
 * options[] per i gruppi type=RM. Idempotente: se il file legacy non esiste
 * o il parser non produce options → skip.
 *
 * Uso: php tools/regenerate_rm_options.php [--apply]
 */
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\PhpContentParser;
use App\Support\Storage\StorageFactory;

$apply = in_array('--apply', $argv, true);
$base  = (string)Config::get('app.paths.base');
$archive = $base . '/_legacy_archive_phase15';
$provider = StorageFactory::default();
$pdo = Database::connection();

echo ($apply ? '[APPLY]' : '[DRY-RUN]') . " reading from $archive\n";

// Tutti i contract con contract_key in metadata
$rows = $pdo->query(
    "SELECT id, content_type, subject_code, topic, title, metadata_json
     FROM teacher_content WHERE JSON_EXTRACT(metadata_json, '$.contract_key') IS NOT NULL"
)->fetchAll(PDO::FETCH_ASSOC);

echo "Contracts: " . count($rows) . "\n\n";
$updated = 0; $skipped = 0; $errors = 0;

foreach ($rows as $row) {
    $meta = json_decode($row['metadata_json'], true) ?: [];
    $ckey = $meta['contract_key'] ?? null;
    $legacyHref = $meta['legacy_href'] ?? null;
    if (!$ckey || !$legacyHref) { $skipped++; continue; }

    $legacyPath = $archive . '/' . ltrim($legacyHref, '/');
    if (!is_file($legacyPath)) { $skipped++; continue; }

    try {
        $contract = json_decode($provider->get($ckey), true);
        if (!is_array($contract)) { $errors++; continue; }

        // Cerca almeno un gruppo RM
        $hasRm = false;
        foreach ($contract['groups'] ?? [] as $g) {
            if (($g['type'] ?? '') === 'RM') { $hasRm = true; break; }
        }
        if (!$hasRm) { $skipped++; continue; }

        // Re-parse dal legacy per ottenere nuove options
        $html = (string)file_get_contents($legacyPath);
        $parser = new PhpContentParser([
            'teacher_id' => 77, 'institute_id' => 106,
            'kind' => $row['content_type'], 'subject' => $row['subject_code'],
            'topic' => $row['topic'],
        ]);
        $fresh = $parser->parse($html);

        // Aggiorna solo options[] dei gruppi RM, matching by group id
        $changed = 0;
        $freshMap = [];
        foreach ($fresh['groups'] ?? [] as $fg) {
            if (($fg['type'] ?? '') !== 'RM') continue;
            $gid = $fg['id'] ?? '';
            $freshMap[$gid] = $fg;
        }

        foreach ($contract['groups'] as &$g) {
            if (($g['type'] ?? '') !== 'RM') continue;
            $gid = $g['id'] ?? '';
            $fresh_g = $freshMap[$gid] ?? null;
            if (!$fresh_g) continue;
            // IMPORTANTE: NON usare `$g['items'] ?? []` nel foreach by-reference:
            // `??` crea un array temporaneo e `&$it` non si riflette su
            // $contract. Assicura che $g['items'] esista e iteralo direttamente.
            if (!isset($g['items']) || !is_array($g['items'])) continue;
            foreach ($g['items'] as $idx => &$it) {
                $fit = $fresh_g['items'][$idx] ?? null;
                if (!$fit) continue;
                $newOpts = $fit['options'] ?? [];
                $oldOpts = $it['options']  ?? [];
                if ($newOpts && count($newOpts) !== count($oldOpts)) {
                    $it['options'] = $newOpts;
                    $changed++;
                } elseif ($newOpts && !$oldOpts) {
                    $it['options'] = $newOpts;
                    $changed++;
                } elseif ($newOpts) {
                    // Stessa count: confronta hash per rilevare differenze
                    // (answer field aggiunto, correct cambiato, ecc).
                    if (json_encode($newOpts) !== json_encode($oldOpts)) {
                        $it['options'] = $newOpts;
                        $changed++;
                    }
                }
            }
            unset($it);
        }
        unset($g);

        if ($changed === 0) { $skipped++; continue; }

        $encoded = json_encode($contract, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        echo "  [{$row['id']}] {$row['title']} → $changed options arrays updated";
        if ($apply) {
            $provider->put($ckey, $encoded);
            // Update checksum in metadata
            $meta['contract_checksum'] = hash('sha256', $encoded);
            $meta['contract_size_bytes'] = (string)strlen($encoded);
            $stmt = $pdo->prepare("UPDATE teacher_content SET metadata_json=? WHERE id=?");
            $stmt->execute([json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $row['id']]);
            echo " ✓";
        }
        echo "\n";
        $updated++;
    } catch (Throwable $e) {
        echo "  [{$row['id']}] ERROR: {$e->getMessage()}\n";
        $errors++;
    }
}

echo "\n─── report ───\n";
echo "  updated  $updated\n";
echo "  skipped  $skipped\n";
echo "  errors   $errors\n";
if (!$apply) echo "\n(dry-run) riesegui con --apply per scrivere\n";
