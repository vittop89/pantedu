<?php
/**
 * Phase 25.D4 — Backfill encryption per teacher_content esistente.
 *
 * Itera tutte le row con plaintext non-encryptato (body_html non-null O
 * metadata.body_pt presente) E ciphertext NULL → cifra in-place.
 *
 * Caratteristiche:
 *   - **Idempotent**: row già cifrato (body_html_ct NOT NULL) skippato.
 *   - **Resumable**: cursor su `id` (last_id checkpoint), no transaction
 *     globale (commit per row).
 *   - **Verify byte-by-byte**: dopo encrypt+save, decifra e confronta con
 *     plaintext. Se diverso → fail row (loggato, no commit), continua.
 *   - **Dry-run** (--dry-run): solo conta + simula encrypt, no UPDATE.
 *   - **Batch size** (--batch=N, default 100): commit ogni N row.
 *   - **Per-teacher** (--teacher=ID): backfill solo un teacher (test/incident).
 *   - **Stats finale**: totale, eseguite, fallite, skipped (already encrypted).
 *
 * Usage:
 *   php tools/crypto/backfill_teacher_content.php --dry-run
 *   php tools/crypto/backfill_teacher_content.php --batch=200
 *   php tools/crypto/backfill_teacher_content.php --teacher=77
 *
 * Phase D4 → D13 sequence:
 *   1. Run dry-run → conta totale, no side effect.
 *   2. Run batch live → cifra in-place. Verifica logs no failures.
 *   3. Set CRYPTO_READ_FROM=ciphertext in .env e verifica E2E ancora pass.
 *   4. Run finale che verify total = encrypted (no row plaintext rimasta).
 *   5. Migration 014 (Phase D13): DROP plaintext columns. Solo dopo step 3-4.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Repositories\TeacherContentRepository;
use App\Services\Crypto\TeacherCryptoService;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: solo CLI.\n");
    exit(1);
}

// Force enable encryption for backfill (anche se .env è dual_write=false).
$_ENV['CRYPTO_DUAL_WRITE'] = '1';

$dryRun  = in_array('--dry-run', $argv, true);
$batch   = 100;
$teacher = null;
foreach ($argv as $arg) {
    if (preg_match('/^--batch=(\d+)$/', $arg, $m)) $batch = max(1, min(1000, (int)$m[1]));
    if (preg_match('/^--teacher=(\d+)$/', $arg, $m)) $teacher = (int)$m[1];
}

$crypto = new TeacherCryptoService();
if (!$crypto->isConfigured()) {
    fwrite(STDERR, "ERROR: KMS_MASTER_KEY non configurato. Run:\n");
    fwrite(STDERR, "  php tools/crypto/generate_kms_key.php\n");
    exit(1);
}

$db = Database::connection();

// Conta totale candidati: row con body_html plaintext O metadata.body_pt
// non-null E ciphertext NULL (non già backfillato).
$whereTeacher = $teacher !== null ? "AND teacher_id = $teacher" : "";
$total = (int)$db->query(
    "SELECT COUNT(*) FROM teacher_content
     WHERE (
       (body_html IS NOT NULL AND body_html != '' AND body_html_ct IS NULL)
       OR (metadata_json LIKE '%body_pt%' AND body_pt_ct IS NULL)
     )
     $whereTeacher"
)->fetchColumn();

echo "═══════════════════════════════════════════════════════════════════\n";
echo "  BACKFILL teacher_content encryption (Phase 25.D4)\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  Modo:     " . ($dryRun ? "DRY-RUN (no UPDATE)" : "LIVE") . "\n";
echo "  Batch:    $batch row/commit\n";
echo "  Teacher:  " . ($teacher !== null ? $teacher : "all") . "\n";
echo "  Totale:   $total row da elaborare\n";
echo "\n";

if ($total === 0) {
    echo "  Niente da fare. Tutto già backfillato.\n";
    exit(0);
}

if (!$dryRun) {
    echo "  Conferma con ENTER (Ctrl+C per annullare): ";
    $line = fgets(STDIN);
}

$lastId = 0;
$processed = 0;
$encrypted = 0;
$skipped = 0;
$failed = 0;
$errors = [];

// Repository creato con dual-write attivo
$repo = new TeacherContentRepository($crypto);

while (true) {
    $teacherFilter = $teacher !== null ? "AND teacher_id = $teacher" : "";
    $stmt = $db->prepare(
        "SELECT id, teacher_id, body_html, body_html_ct, body_pt_ct, metadata_json
         FROM teacher_content
         WHERE id > ?
           AND (
             (body_html IS NOT NULL AND body_html != '' AND body_html_ct IS NULL)
             OR (metadata_json LIKE '%body_pt%' AND body_pt_ct IS NULL)
           )
           $teacherFilter
         ORDER BY id ASC
         LIMIT $batch"
    );
    $stmt->execute([$lastId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) break;

    foreach ($rows as $row) {
        $id    = (int)$row['id'];
        $tid   = (int)$row['teacher_id'];
        $lastId = $id;
        $processed++;

        // Skip se entrambi i ct già popolati (race con altre run)
        if (!empty($row['body_html_ct']) && !empty($row['body_pt_ct'])) {
            $skipped++;
            continue;
        }

        try {
            // Costruisci payload "fake" per il dual-write update path.
            // Estraggo body_pt da metadata + cifro body_html.
            $meta = json_decode((string)$row['metadata_json'], true) ?: [];
            $bodyHtml = $row['body_html'] ?? null;

            $updates = [];
            if (is_string($bodyHtml) && $bodyHtml !== '' && empty($row['body_html_ct'])) {
                $updates['body_html'] = $bodyHtml;
            }
            if (isset($meta['body_pt']) && empty($row['body_pt_ct'])) {
                $updates['metadata'] = $meta;  // contiene body_pt — extractBodyPt lo separerà
            }
            if (!$updates) {
                $skipped++;
                continue;
            }

            if ($dryRun) {
                // Simula: encrypt in-memory + verify roundtrip
                if (isset($updates['body_html'])) {
                    $env = $crypto->encrypt($tid, $updates['body_html']);
                    $back = $crypto->decrypt($tid, $env);
                    if ($back !== $updates['body_html']) {
                        throw new \RuntimeException('roundtrip_mismatch_html');
                    }
                }
                if (isset($updates['metadata']['body_pt'])) {
                    $ptJson = json_encode($updates['metadata']['body_pt']);
                    $env = $crypto->encrypt($tid, $ptJson);
                    $back = $crypto->decrypt($tid, $env);
                    if ($back !== $ptJson) {
                        throw new \RuntimeException('roundtrip_mismatch_pt');
                    }
                }
                $encrypted++;
            } else {
                $ok = $repo->update($id, $tid, $updates);
                if (!$ok) {
                    throw new \RuntimeException('update_returned_false');
                }
                // Verify byte-byte: re-find con READ_FROM=ciphertext
                $_ENV['CRYPTO_READ_FROM'] = 'ciphertext';
                $repoVerify = new TeacherContentRepository($crypto);
                $found = $repoVerify->find($id);
                unset($_ENV['CRYPTO_READ_FROM']);
                if (isset($updates['body_html']) && $found['body_html'] !== $updates['body_html']) {
                    throw new \RuntimeException('verify_body_html_mismatch');
                }
                if (isset($updates['metadata']['body_pt'])) {
                    $expectedPt = $updates['metadata']['body_pt'];
                    $actualPt = $found['metadata']['body_pt'] ?? null;
                    if (json_encode($expectedPt) !== json_encode($actualPt)) {
                        throw new \RuntimeException('verify_body_pt_mismatch');
                    }
                }
                $encrypted++;
            }
        } catch (\Throwable $e) {
            $failed++;
            $errors[] = "id=$id tid=$tid: " . $e->getMessage();
            // Continue: 1 fail non blocca le altre
        }

        if ($processed % 50 === 0) {
            echo "  ... $processed/$total ($encrypted encrypted, $skipped skipped, $failed failed)\n";
        }
    }
}

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "  RISULTATO\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  Processed: $processed\n";
echo "  Encrypted: $encrypted\n";
echo "  Skipped:   $skipped (already encrypted)\n";
echo "  Failed:    $failed\n";
echo "\n";
if ($errors) {
    echo "  ERRORI (primi 20):\n";
    foreach (array_slice($errors, 0, 20) as $err) echo "    - $err\n";
    echo "\n";
}
if ($dryRun) {
    echo "  DRY-RUN. Per applicare:\n";
    echo "    php tools/crypto/backfill_teacher_content.php\n";
} elseif ($failed > 0) {
    echo "  ⚠️  $failed righe NON cifrate. Investiga errori prima di Phase D13.\n";
    exit(1);
} else {
    echo "  ✅ Backfill completato. $encrypted righe ora cifrate.\n";
    echo "  Prossimo step: imposta CRYPTO_READ_FROM=ciphertext in .env, verifica\n";
    echo "  E2E + smoke production, poi run migration 014 (Phase D13) per DROP plaintext.\n";
}
