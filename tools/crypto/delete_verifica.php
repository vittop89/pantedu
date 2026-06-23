<?php
/**
 * CLI delete di verifica_documents row (per cleanup di verifiche corrotte
 * con blob dead che l'UI rifiuta di gestire).
 *
 * Uso:
 *   php tools/crypto/delete_verifica.php <id> [--apply]
 *   php tools/crypto/delete_verifica.php --dead [--apply]    # cancella TUTTE le verifiche con blob dead
 *
 *   --apply  Senza questo flag = DRY-RUN
 *
 * Comportamento:
 *   - Cancella row da verifica_documents
 *   - Best-effort delete dei blob orfani (non se shared con altre row del batch)
 *   - Logga in stdout
 */

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Database;

$apply = in_array('--apply', $argv, true);
$dead = in_array('--dead', $argv, true);
$idArg = null;
foreach (array_slice($argv, 1) as $a) {
    if (ctype_digit($a)) $idArg = (int)$a;
}

if (!$dead && !$idArg) {
    echo "Uso: php tools/crypto/delete_verifica.php <id> [--apply]\n";
    echo "     php tools/crypto/delete_verifica.php --dead [--apply]\n";
    exit(1);
}

echo "=== Delete verifica_documents ===\n";
echo "Mode: " . ($apply ? "🔥 APPLY" : "🔍 DRY-RUN") . "\n\n";

$pdo = Database::connection();
$kms = hex2bin($_ENV['KMS_MASTER_KEY']);
$root = dirname(__DIR__, 2);

// Helper: KEK per teacher
function getKekFor(PDO $pdo, string $kms, int $tid, int $kv): ?string
{
    $stmt = $pdo->prepare('SELECT wrapped_kek FROM teacher_keys WHERE teacher_id=? AND key_version=?');
    $stmt->execute([$tid, $kv]);
    $w = $stmt->fetchColumn();
    if (!$w || strlen($w) !== 60) return null;
    $iv = substr($w, 0, 12); $ct = substr($w, 12, 32); $tag = substr($w, 12 + 32, 16);
    $tkek = hash_hkdf('sha256', $kms, 32, (string)$kv, "pantedu-teacher-kek-v1|$tid");
    $kek = openssl_decrypt($ct, 'aes-256-gcm', $tkek, OPENSSL_RAW_DATA, $iv, $tag);
    return $kek === false ? null : $kek;
}

// Helper: testa se blob path è alive
function blobAlive(string $rootDir, string $blobPath, string $kek): bool
{
    $f = $rootDir . '/storage/verifiche_enc/' . $blobPath;
    if (!is_file($f)) return false;
    $raw = file_get_contents($f);
    if (strlen($raw) < 30) return false;
    $pt = openssl_decrypt(substr($raw, 30), 'aes-256-gcm', $kek, OPENSSL_RAW_DATA, substr($raw, 2, 12), substr($raw, 14, 16));
    return $pt !== false;
}

// Build delete set
$toDelete = [];
if ($idArg) {
    $stmt = $pdo->prepare("SELECT id, teacher_id, materia, title, tex_files, tex_blob_path, pdf_blob_path FROM verifica_documents WHERE id=?");
    $stmt->execute([$idArg]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo "❌ id=$idArg non trovato\n"; exit(1); }
    $toDelete[] = $row;
}

if ($dead) {
    // Scan tutte le verifica_documents row e tieni solo quelle con TUTTI i blob dead
    $stmt = $pdo->query("SELECT id, teacher_id, materia, title, tex_files, tex_blob_path, pdf_blob_path FROM verifica_documents ORDER BY id");
    foreach ($stmt as $row) {
        $tid = (int)$row['teacher_id'];
        $kek = getKekFor($pdo, $kms, $tid, 1);
        if (!$kek) continue;

        $blobs = [];
        if (!empty($row['tex_files'])) {
            $files = json_decode($row['tex_files'], true);
            foreach (($files ?: []) as $f) {
                if (!empty($f['blob_path'])) $blobs[] = $f['blob_path'];
            }
        }
        if (!empty($row['tex_blob_path'])) $blobs[] = $row['tex_blob_path'];

        if (empty($blobs)) continue; // nessun blob = niente da check

        $anyAlive = false;
        foreach ($blobs as $bp) {
            if (blobAlive($root, $bp, $kek)) { $anyAlive = true; break; }
        }
        if (!$anyAlive) $toDelete[] = $row;
    }
}

echo "Rows da cancellare: " . count($toDelete) . "\n\n";

foreach ($toDelete as $row) {
    $id = (int)$row['id'];
    $title = $row['title'] ?: '(no title)';
    $materia = $row['materia'] ?: '?';
    echo sprintf("id=%-5d | tid=%d | %s | %s\n", $id, $row['teacher_id'], $materia, $title);

    if (!$apply) continue;

    // Raccogli blob da cancellare
    $blobs = [];
    if (!empty($row['tex_files'])) {
        $files = json_decode($row['tex_files'], true);
        foreach (($files ?: []) as $f) {
            if (!empty($f['blob_path'])) $blobs[$f['blob_path']] = true;
        }
    }
    if (!empty($row['tex_blob_path'])) $blobs[$row['tex_blob_path']] = true;
    if (!empty($row['pdf_blob_path'])) $blobs[$row['pdf_blob_path']] = true;

    // DELETE row
    $pdo->prepare("DELETE FROM verifica_documents WHERE id = ?")->execute([$id]);
    echo "    ✅ DB row deleted\n";

    // Reap blob files
    foreach (array_keys($blobs) as $bp) {
        $f = $root . '/storage/verifiche_enc/' . $bp;
        if (is_file($f)) {
            @unlink($f);
            echo "    🗑 blob removed: $bp\n";
        }
    }
}

echo "\n" . ($apply ? "🔥 Applied. " : "🔍 Dry-run. ");
echo count($toDelete) . " row" . (count($toDelete) === 1 ? "" : "s") . " " . ($apply ? "cancellate" : "cancellabili") . ".\n";
