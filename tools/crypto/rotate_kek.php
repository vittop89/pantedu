<?php
/**
 * Phase 25.D5 — Annual KEK rotation per teacher.
 *
 * Strategia (vedi ADR-006):
 *   1. rotate(teacher) → crea nuova row in teacher_keys con key_version++.
 *      Vecchie row restano valide finché ci sono body_*_kv che le referenziano.
 *   2. Re-encrypt: per ogni body_html / body_pt con kv = old_kv, decrypt
 *      e re-encrypt con new_kv (UPDATE row). Verify byte-byte.
 *   3. Prune (--prune-old-kv): dopo re-encrypt completato senza failure,
 *      DELETE FROM teacher_keys WHERE teacher_id=? AND key_version < new_kv.
 *
 * Modalità:
 *   - default: rotate-only (crea new kv, lascia old kv attiva).
 *     Body re-encrypted lazy alla prossima scrittura naturale.
 *   - --reencrypt: rotate + batch re-encrypt eager (sicuro).
 *   - --prune-old-kv: rotate + reencrypt + delete old wrapped_kek
 *     (richiede --reencrypt per evitare data loss).
 *
 * Usage:
 *   php tools/crypto/rotate_kek.php --teacher=77 --reencrypt
 *   php tools/crypto/rotate_kek.php --all --reencrypt --prune-old-kv
 *   php tools/crypto/rotate_kek.php --teacher=77 --dry-run --reencrypt
 *
 * Cron annuale (raccomandato):
 *   0 3 1 1 *  php tools/crypto/rotate_kek.php --all --reencrypt --prune-old-kv > /var/log/kek-rotation.log
 */

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Services\Crypto\TeacherCryptoService;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: solo CLI.\n");
    exit(1);
}

/**
 * Re-encrypt batch: per ogni teacher_content row con kv vecchio, decrypt + encrypt
 * con newKv + UPDATE. Verify byte-byte. Ritorna count righe re-cifrate.
 *
 * Definita BEFORE il main loop per evitare hoisting issue su PHP 8.3 con
 * exit() statements al top-level.
 */
function reencryptRows(\PDO $db, TeacherCryptoService $crypto, int $tid, int $newKv): int
{
    $count = 0;
    $batch = 100;
    $lastId = 0;
    while (true) {
        $stmt = $db->prepare(
            "SELECT id, body_html_ct, body_html_iv, body_html_tag, body_html_kv,
                    body_pt_ct,   body_pt_iv,   body_pt_tag,   body_pt_kv
             FROM teacher_content
             WHERE teacher_id = ?
               AND id > ?
               AND ((body_html_ct IS NOT NULL AND body_html_kv < ?)
                 OR (body_pt_ct   IS NOT NULL AND body_pt_kv   < ?))
             ORDER BY id ASC
             LIMIT $batch"
        );
        $stmt->execute([$tid, $lastId, $newKv, $newKv]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) break;

        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $lastId = $id;
            $cols = []; $args = [];

            // body_html re-encrypt
            if (!empty($row['body_html_ct']) && (int)$row['body_html_kv'] < $newKv) {
                $plain = $crypto->decrypt($tid, [
                    'ciphertext' => $row['body_html_ct'],
                    'iv'         => $row['body_html_iv'],
                    'tag'        => $row['body_html_tag'],
                    'kv'         => (int)$row['body_html_kv'],
                ]);
                $env = $crypto->encrypt($tid, $plain);
                if ((int)$env['kv'] !== $newKv) {
                    throw new \RuntimeException("encrypt did not use newKv $newKv (got {$env['kv']})");
                }
                if ($crypto->decrypt($tid, $env) !== $plain) {
                    throw new \RuntimeException("roundtrip body_html mismatch id=$id");
                }
                $cols[] = 'body_html_ct=?';  $args[] = $env['ciphertext'];
                $cols[] = 'body_html_iv=?';  $args[] = $env['iv'];
                $cols[] = 'body_html_tag=?'; $args[] = $env['tag'];
                $cols[] = 'body_html_kv=?';  $args[] = $env['kv'];
            }

            // body_pt re-encrypt
            if (!empty($row['body_pt_ct']) && (int)$row['body_pt_kv'] < $newKv) {
                $plain = $crypto->decrypt($tid, [
                    'ciphertext' => $row['body_pt_ct'],
                    'iv'         => $row['body_pt_iv'],
                    'tag'        => $row['body_pt_tag'],
                    'kv'         => (int)$row['body_pt_kv'],
                ]);
                $env = $crypto->encrypt($tid, $plain);
                if ($crypto->decrypt($tid, $env) !== $plain) {
                    throw new \RuntimeException("roundtrip body_pt mismatch id=$id");
                }
                $cols[] = 'body_pt_ct=?';  $args[] = $env['ciphertext'];
                $cols[] = 'body_pt_iv=?';  $args[] = $env['iv'];
                $cols[] = 'body_pt_tag=?'; $args[] = $env['tag'];
                $cols[] = 'body_pt_kv=?';  $args[] = $env['kv'];
            }

            if ($cols) {
                $args[] = $id;
                $sql = 'UPDATE teacher_content SET ' . implode(',', $cols) . ' WHERE id = ?';
                $up = $db->prepare($sql);
                $up->execute($args);
                $count++;
            }
        }
    }
    return $count;
}

$dryRun     = in_array('--dry-run', $argv, true);
$reencrypt  = in_array('--reencrypt', $argv, true);
$pruneOldKv = in_array('--prune-old-kv', $argv, true);
$all        = in_array('--all', $argv, true);
$teacher    = null;

foreach ($argv as $arg) {
    if (preg_match('/^--teacher=(\d+)$/', $arg, $m)) $teacher = (int)$m[1];
}

if (!$all && $teacher === null) {
    fwrite(STDERR, "ERROR: specifica --teacher=ID o --all\n");
    exit(1);
}

if ($pruneOldKv && !$reencrypt) {
    fwrite(STDERR, "ERROR: --prune-old-kv richiede --reencrypt (altrimenti perdi i dati).\n");
    exit(1);
}

$crypto = new TeacherCryptoService();
if (!$crypto->isConfigured()) {
    fwrite(STDERR, "ERROR: KMS_MASTER_KEY mancante.\n");
    exit(1);
}

$db = Database::connection();

// Lista teacher target
if ($all) {
    $teachers = $db->query(
        "SELECT DISTINCT teacher_id FROM teacher_keys ORDER BY teacher_id"
    )->fetchAll(PDO::FETCH_COLUMN);
} else {
    $teachers = [$teacher];
}

echo "═══════════════════════════════════════════════════════════════════\n";
echo "  KEK ROTATION (Phase 25.D5)\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  Modo:         " . ($dryRun ? "DRY-RUN" : "LIVE") . "\n";
echo "  Teachers:     " . count($teachers) . " da ruotare\n";
echo "  Re-encrypt:   " . ($reencrypt ? "yes (eager)" : "no (lazy)") . "\n";
echo "  Prune old kv: " . ($pruneOldKv ? "yes" : "no") . "\n";
echo "\n";

if (!$dryRun && $teachers) {
    echo "  Conferma con ENTER (Ctrl+C per annullare): ";
    fgets(STDIN);
}

$totalRotated   = 0;
$totalReencrypted = 0;
$totalPruned    = 0;
$totalFailed    = 0;
$errors         = [];

foreach ($teachers as $tid) {
    $tid = (int)$tid;
    echo "→ teacher_id=$tid\n";
    try {
        if ($dryRun) {
            // Calcola cosa farebbe
            $maxKv = (int)$db->query(
                "SELECT MAX(key_version) FROM teacher_keys WHERE teacher_id=$tid"
            )->fetchColumn();
            $newKv = $maxKv + 1;
            echo "    [dry] would rotate kv $maxKv → $newKv\n";
            if ($reencrypt) {
                $count = (int)$db->query(
                    "SELECT COUNT(*) FROM teacher_content
                     WHERE teacher_id=$tid AND (body_html_kv < $newKv OR body_pt_kv < $newKv)"
                )->fetchColumn();
                echo "    [dry] would re-encrypt $count rows\n";
            }
            $totalRotated++;
            continue;
        }

        // 1. Rotate: crea new kv
        $newKv = $crypto->rotate($tid, accessorId: 0, reason: 'annual_rotation');
        echo "    rotated → kv=$newKv\n";
        $totalRotated++;

        // 2. Re-encrypt body con kv < newKv → kv = newKv
        if ($reencrypt) {
            $reencRows = reencryptRows($db, $crypto, $tid, $newKv);
            echo "    re-encrypted: $reencRows rows\n";
            $totalReencrypted += $reencRows;
        }

        // 3. Prune: cancella row teacher_keys con key_version < newKv
        // (sicuro solo se reencrypt completato senza fail)
        if ($pruneOldKv) {
            $del = $db->prepare(
                "DELETE FROM teacher_keys WHERE teacher_id=? AND key_version<?"
            );
            $del->execute([$tid, $newKv]);
            $pruned = $del->rowCount();
            echo "    pruned old kv: $pruned rows\n";
            $totalPruned += $pruned;
        }
    } catch (\Throwable $e) {
        $totalFailed++;
        $msg = "tid=$tid: " . $e->getMessage();
        $errors[] = $msg;
        echo "    ✗ FAIL: $msg\n";
    }
}

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "  RISULTATO\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  Rotated:       $totalRotated teachers\n";
echo "  Re-encrypted:  $totalReencrypted rows\n";
echo "  Pruned old kv: $totalPruned rows\n";
echo "  Failed:        $totalFailed teachers\n";
if ($errors) {
    echo "  Errori (primi 10):\n";
    foreach (array_slice($errors, 0, 10) as $err) echo "    - $err\n";
}
exit($totalFailed > 0 ? 1 : 0);
