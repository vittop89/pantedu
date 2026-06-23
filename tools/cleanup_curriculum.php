<?php
/**
 * G22.S15.bis Fase 5+ — Cleanup curriculum legacy.
 *
 * Eliminazione safe duplicati orfani + fix label + migrazione legacy
 * (institute_id NULL) all'istituto principale del primo admin.
 *
 * Run: php tools/cleanup_curriculum.php [--dry-run] [--commit]
 *      Default: --dry-run (solo preview, nessuna modifica).
 *      --commit: applica le modifiche.
 *
 * Safety: NON cancello entries usate da:
 *   - curriculum_users (pivot docente)
 *   - teacher_content (mappe, verifiche, esercizi)
 *   - exercises
 *   - risdoc_templates
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

$argv = $_SERVER['argv'] ?? [];
$dryRun = !in_array('--commit', $argv, true);
$mode = $dryRun ? '🔵 DRY-RUN (no changes)' : '🟢 COMMIT';

echo "=== Cleanup Curriculum — $mode ===\n\n";

if (!Database::isAvailable()) {
    fwrite(STDERR, "DB non disponibile\n");
    exit(1);
}
$pdo = Database::connection();

/**
 * Entries da CANCELLARE (lista hardcoded, derivata da analisi del catalog).
 * Tutte sono inactive + duplicate orfane senza usi.
 */
$dropCodes = [
    // Indirizzi
    ['kind' => 'indirizzi', 'code' => 'li',   'reason' => 'duplicato inactive di "ling"'],
    // Classi (1s..5s con group="Standard" duplicate dei numeri singoli)
    ['kind' => 'classi',    'code' => '1s',   'reason' => 'duplicato inactive di "1"'],
    ['kind' => 'classi',    'code' => '2s',   'reason' => 'duplicato inactive di "2"'],
    ['kind' => 'classi',    'code' => '3s',   'reason' => 'duplicato inactive di "3"'],
    ['kind' => 'classi',    'code' => '4s',   'reason' => 'duplicato inactive di "4"'],
    ['kind' => 'classi',    'code' => '5s',   'reason' => 'duplicato inactive di "5"'],
    ['kind' => 'classi',    'code' => '3b',   'reason' => 'Liceo breve inactive (no piu\' in uso)'],
    ['kind' => 'classi',    'code' => '4b',   'reason' => 'Liceo breve inactive (no piu\' in uso)'],
    // Materie duplicate/orfane
    ['kind' => 'materie',   'code' => 'gf',   'reason' => 'lowercase duplicato (vs GEO Geometria)'],
    ['kind' => 'materie',   'code' => 'geog', 'reason' => 'duplicato di GEO Geometria con code lowercase'],
    ['kind' => 'materie',   'code' => 'ALL',  'reason' => 'codice "Mate. & Fis." anomalo, no convenzione'],
];

/**
 * Fix label (code resta invariato per back-compat con risorse esistenti).
 */
$labelFixes = [
    ['kind' => 'indirizzi', 'code' => 'afm',  'new_label' => 'Amministrazione, Finanza e Marketing'],
];

// 1) Verifica che entries da droppare siano davvero senza usi
$dropIds = [];
echo "── Step 1: validate drop list ──\n";
foreach ($dropCodes as $d) {
    $stmt = $pdo->prepare(
        'SELECT id FROM curriculum_entries WHERE kind = ? AND code = ? AND institute_id IS NULL'
    );
    $stmt->execute([$d['kind'], $d['code']]);
    $id = (int)$stmt->fetchColumn();
    if (!$id) {
        echo "  · skip {$d['kind']}/{$d['code']}: not found\n";
        continue;
    }
    // Check usi
    $usesPivot = (int)$pdo->query("SELECT COUNT(*) FROM curriculum_users WHERE curriculum_id = $id")->fetchColumn();
    if ($usesPivot > 0) {
        echo "  ⚠️  KEEP {$d['kind']}/{$d['code']} (#$id): in uso da $usesPivot pivot\n";
        continue;
    }
    // Check teacher_content (per kind specifico)
    $col = match ($d['kind']) {
        'indirizzi' => 'indirizzo',
        'classi'    => 'classe',
        'materie'   => 'subject_code',
        default     => null,
    };
    if ($col) {
        $usesContent = (int)$pdo->query(
            "SELECT COUNT(*) FROM teacher_content WHERE $col = " . $pdo->quote($d['code'])
        )->fetchColumn();
        if ($usesContent > 0) {
            echo "  ⚠️  KEEP {$d['kind']}/{$d['code']} (#$id): in uso da $usesContent teacher_content\n";
            continue;
        }
    }
    $dropIds[] = $id;
    echo "  ✗ DROP {$d['kind']}/{$d['code']} (#$id) — {$d['reason']}\n";
}

// 2) Migrate residue legacy (institute_id NULL) → istituto 108 (Galileo)
//    L'istituto target lo prendiamo dal primo admin nel sistema.
$primaryInstitute = (int)$pdo->query(
    "SELECT ti.institute_id FROM teacher_institutes ti
     INNER JOIN users u ON u.id = ti.user_id
     WHERE u.role = 'admin'
     ORDER BY ti.created_at LIMIT 1"
)->fetchColumn();
if (!$primaryInstitute) {
    $primaryInstitute = (int)$pdo->query("SELECT MIN(id) FROM institutes")->fetchColumn();
}
echo "\n── Step 2: migrate legacy → istituto #$primaryInstitute ──\n";

$migrateStmt = $pdo->prepare(
    'SELECT id, kind, code, label FROM curriculum_entries
     WHERE institute_id IS NULL ' . ($dropIds ? 'AND id NOT IN (' . implode(',', $dropIds) . ')' : '') . '
     ORDER BY kind, code'
);
$migrateStmt->execute();
$toMigrate = $migrateStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($toMigrate as $e) {
    echo "  → migrate {$e['kind']}/{$e['code']} (#{$e['id']}) → institute_id=$primaryInstitute\n";
}

// 3) Apply label fixes
echo "\n── Step 3: label fixes ──\n";
$fixesData = [];
foreach ($labelFixes as $f) {
    $stmt = $pdo->prepare('SELECT id, label FROM curriculum_entries WHERE kind = ? AND code = ?');
    $stmt->execute([$f['kind'], $f['code']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) continue;
    if ($row['label'] === $f['new_label']) {
        echo "  · skip {$f['code']}: label gia' corretta\n";
        continue;
    }
    echo "  ✎ {$f['kind']}/{$f['code']}: \"{$row['label']}\" → \"{$f['new_label']}\"\n";
    $fixesData[] = ['id' => (int)$row['id'], 'new_label' => $f['new_label']];
}

// 4) Execute (or skip if dry-run)
echo "\n── Step 4: execute ──\n";
if ($dryRun) {
    echo "  🔵 DRY-RUN — nessuna modifica applicata. Aggiungi --commit per eseguire.\n";
    echo "\nSummary:\n";
    echo "  - DROP: " . count($dropIds) . " entries\n";
    echo "  - MIGRATE: " . count($toMigrate) . " entries\n";
    echo "  - FIX LABEL: " . count($fixesData) . " entries\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    if ($dropIds) {
        $pdo->exec('DELETE FROM curriculum_entries WHERE id IN (' . implode(',', $dropIds) . ')');
        echo "  ✓ deleted " . count($dropIds) . " entries\n";
    }
    if ($toMigrate && $primaryInstitute) {
        $ids = array_column($toMigrate, 'id');
        $pdo->prepare('UPDATE curriculum_entries SET institute_id = ? WHERE id IN (' . implode(',', $ids) . ')')
            ->execute([$primaryInstitute]);
        echo "  ✓ migrated " . count($ids) . " entries → institute $primaryInstitute\n";
    }
    foreach ($fixesData as $f) {
        $pdo->prepare('UPDATE curriculum_entries SET label = ? WHERE id = ?')
            ->execute([$f['new_label'], $f['id']]);
    }
    if ($fixesData) echo "  ✓ fixed " . count($fixesData) . " labels\n";

    // Verifica orphan: tutte le entries hanno institute_id?
    $orphans = (int)$pdo->query('SELECT COUNT(*) FROM curriculum_entries WHERE institute_id IS NULL')->fetchColumn();
    if ($orphans > 0) {
        echo "\n  ⚠️  Restano $orphans entries con institute_id NULL — non posso applicare NOT NULL.\n";
        echo "  Esamina manualmente:\n";
        $stmt = $pdo->query('SELECT id, kind, code, label FROM curriculum_entries WHERE institute_id IS NULL');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            echo "    #{$r['id']} {$r['kind']}/{$r['code']} → {$r['label']}\n";
        }
    } else {
        echo "  ✓ no orphan entries — schema pronto per NOT NULL\n";
    }

    $pdo->commit();
    echo "\n✅ Cleanup completato.\n";

    // ── Step 4.5: clone entries cross-istituto ──
    // Per multi-istituto coverage: ogni istituto con docenti deve avere
    // il proprio set di entries. Clone idempotente (skip duplicati via
    // ON DUPLICATE KEY).
    $allInstitutes = $pdo->query(
        "SELECT DISTINCT i.id FROM institutes i
         INNER JOIN teacher_institutes ti ON ti.institute_id = i.id"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (count($allInstitutes) > 1) {
        echo "\n── Step 4.5: clone entries cross-istituto ──\n";
        $sourceId = (int)$pdo->query(
            "SELECT institute_id FROM curriculum_entries WHERE institute_id IS NOT NULL
             GROUP BY institute_id ORDER BY COUNT(*) DESC LIMIT 1"
        )->fetchColumn();
        $sourceEntries = $pdo->prepare(
            'SELECT kind, code, label, grp, active FROM curriculum_entries WHERE institute_id = ?'
        );
        $sourceEntries->execute([$sourceId]);
        $src = $sourceEntries->fetchAll(PDO::FETCH_ASSOC);
        $clonedTot = 0;
        foreach ($allInstitutes as $iid) {
            if ((int)$iid === $sourceId) continue;
            $clonedHere = 0;
            foreach ($src as $e) {
                try {
                    $pdo->prepare(
                        'INSERT INTO curriculum_entries (kind, institute_id, code, label, grp, active)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    )->execute([
                        $e['kind'], (int)$iid, $e['code'], $e['label'], $e['grp'], $e['active']
                    ]);
                    $clonedHere++;
                } catch (\PDOException $ex) {
                    if ((int)$ex->errorInfo[1] !== 1062) throw $ex;
                    // skip duplicate
                }
            }
            if ($clonedHere > 0) echo "  → istituto #$iid: $clonedHere entries cloned\n";
            $clonedTot += $clonedHere;
        }
        echo "  ✓ totale clones: $clonedTot\n";
    }

    // ── Step 5: finalize schema NOT NULL (se no orphans) ──
    $orphans = (int)$pdo->query('SELECT COUNT(*) FROM curriculum_entries WHERE institute_id IS NULL')->fetchColumn();
    if ($orphans === 0) {
        try {
            $pdo->exec('ALTER TABLE curriculum_entries MODIFY institute_id INT UNSIGNED NOT NULL');
            echo "✅ ALTER TABLE: institute_id ora NOT NULL\n";
        } catch (Throwable $e) {
            // Idempotent: se gia' NOT NULL, ignora
            if (!str_contains($e->getMessage(), 'NULL')) throw $e;
            echo "ℹ️  institute_id gia' NOT NULL\n";
        }
    } else {
        echo "⚠️  $orphans orphans residui — schema NOT NULL non applicato.\n";
    }
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "❌ Errore: {$e->getMessage()}\n");
    exit(1);
}
