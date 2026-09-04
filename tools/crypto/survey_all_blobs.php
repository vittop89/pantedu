<?php
/**
 * Survey esteso: testa decifratura per TUTTI i tipi di blob cifrati.
 *  - maps_enc/<tid>/*.bin       (mappe drawio)
 *  - verifiche_enc/<tid>/*.bin  (verifiche TEX/PDF)
 *  - teacher_content.body_pt_ct (column-level encryption per body plaintext)
 *  - teacher_content.body_html_ct
 *  - teacher_content.metadata_ct
 *
 * Output: stats per tipo (alive/dead/missing) + sample dei dead.
 */

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Database;

$kms = hex2bin($_ENV['KMS_MASTER_KEY']);
$pdo = Database::connection();
$root = dirname(__DIR__, 2);

// Helper: derive KEK from teacher_keys
function getKekFor(PDO $pdo, string $kms, int $tid, int $kv): ?string
{
    $stmt = $pdo->prepare('SELECT wrapped_kek FROM teacher_keys WHERE teacher_id=? AND key_version=?');
    $stmt->execute([$tid, $kv]);
    $w = $stmt->fetchColumn();
    if (!$w || strlen($w) !== 60) return null;
    $iv = substr($w, 0, 12);
    $ct = substr($w, 12, 32);
    $tag = substr($w, 12 + 32, 16);
    $tkek = hash_hkdf('sha256', $kms, 32, (string)$kv, "pantedu-teacher-kek-v1|$tid");
    $kek = openssl_decrypt($ct, 'aes-256-gcm', $tkek, OPENSSL_RAW_DATA, $iv, $tag);
    return $kek === false ? null : $kek;
}

$teacherIds = array_column($pdo->query("SELECT DISTINCT teacher_id FROM teacher_content")->fetchAll(PDO::FETCH_ASSOC), 'teacher_id');

$totals = [
    'maps'        => ['alive' => 0, 'dead' => 0, 'missing' => 0, 'orphan_db' => 0],
    'verifiche'   => ['alive' => 0, 'dead' => 0, 'missing' => 0],
    'body_pt'     => ['alive' => 0, 'dead' => 0],
    'body_html'   => ['alive' => 0, 'dead' => 0],
    'metadata'    => ['alive' => 0, 'dead' => 0],
];
$deadSamples = ['maps' => [], 'body_pt' => [], 'body_html' => [], 'metadata' => []];

foreach ($teacherIds as $tidStr) {
    $tid = (int)$tidStr;
    $kek = getKekFor($pdo, $kms, $tid, 1);
    if (!$kek) {
        echo "⚠ teacher_id=$tid: no KEK v1 (skip)\n";
        continue;
    }

    // ── 1. MAP BLOBS ───────────────────────────────────────
    $stmt = $pdo->prepare("SELECT id, map_blob_path FROM teacher_content WHERE teacher_id=? AND content_type='mappa' AND map_blob_path IS NOT NULL");
    $stmt->execute([$tid]);
    foreach ($stmt as $r) {
        $f = $root . '/storage/maps_enc/' . $r['map_blob_path'];
        if (!is_file($f)) { $totals['maps']['missing']++; continue; }
        $raw = file_get_contents($f);
        $pt = openssl_decrypt(substr($raw, 30), 'aes-256-gcm', $kek, OPENSSL_RAW_DATA, substr($raw, 2, 12), substr($raw, 14, 16));
        if ($pt === false) { $totals['maps']['dead']++; if (count($deadSamples['maps']) < 5) $deadSamples['maps'][] = "id={$r['id']}"; }
        else $totals['maps']['alive']++;
    }

    // ── 2. VERIFICHE BLOBS (filesystem only — no DB column) ──
    $vDir = $root . '/storage/verifiche_enc/' . $tid;
    if (is_dir($vDir)) {
        foreach (glob($vDir . '/*.bin') ?: [] as $f) {
            $raw = file_get_contents($f);
            if (strlen($raw) < 30) { $totals['verifiche']['dead']++; continue; }
            $pt = openssl_decrypt(substr($raw, 30), 'aes-256-gcm', $kek, OPENSSL_RAW_DATA, substr($raw, 2, 12), substr($raw, 14, 16));
            if ($pt === false) $totals['verifiche']['dead']++;
            else $totals['verifiche']['alive']++;
        }
    }

    // ── 3. body_pt_ct column (text content) ────────────────
    $stmt = $pdo->prepare("SELECT id, body_pt_ct, body_pt_iv, body_pt_tag, body_pt_kv FROM teacher_content WHERE teacher_id=? AND body_pt_ct IS NOT NULL");
    $stmt->execute([$tid]);
    foreach ($stmt as $r) {
        $localKek = (int)$r['body_pt_kv'] === 1 ? $kek : getKekFor($pdo, $kms, $tid, (int)$r['body_pt_kv']);
        if (!$localKek) { $totals['body_pt']['dead']++; continue; }
        $pt = openssl_decrypt($r['body_pt_ct'], 'aes-256-gcm', $localKek, OPENSSL_RAW_DATA, $r['body_pt_iv'], $r['body_pt_tag']);
        if ($pt === false) { $totals['body_pt']['dead']++; if (count($deadSamples['body_pt']) < 5) $deadSamples['body_pt'][] = "id={$r['id']}"; }
        else $totals['body_pt']['alive']++;
    }

    // ── 4. body_html_ct column ─────────────────────────────
    $stmt = $pdo->prepare("SELECT id, body_html_ct, body_html_iv, body_html_tag, body_html_kv FROM teacher_content WHERE teacher_id=? AND body_html_ct IS NOT NULL");
    $stmt->execute([$tid]);
    foreach ($stmt as $r) {
        $localKek = (int)$r['body_html_kv'] === 1 ? $kek : getKekFor($pdo, $kms, $tid, (int)$r['body_html_kv']);
        if (!$localKek) { $totals['body_html']['dead']++; continue; }
        $pt = openssl_decrypt($r['body_html_ct'], 'aes-256-gcm', $localKek, OPENSSL_RAW_DATA, $r['body_html_iv'], $r['body_html_tag']);
        if ($pt === false) { $totals['body_html']['dead']++; if (count($deadSamples['body_html']) < 5) $deadSamples['body_html'][] = "id={$r['id']}"; }
        else $totals['body_html']['alive']++;
    }

    // ── 5. metadata_ct column ──────────────────────────────
    $stmt = $pdo->prepare("SELECT id, metadata_ct, metadata_iv, metadata_tag, metadata_kv FROM teacher_content WHERE teacher_id=? AND metadata_ct IS NOT NULL");
    $stmt->execute([$tid]);
    foreach ($stmt as $r) {
        $localKek = (int)$r['metadata_kv'] === 1 ? $kek : getKekFor($pdo, $kms, $tid, (int)$r['metadata_kv']);
        if (!$localKek) { $totals['metadata']['dead']++; continue; }
        $pt = openssl_decrypt($r['metadata_ct'], 'aes-256-gcm', $localKek, OPENSSL_RAW_DATA, $r['metadata_iv'], $r['metadata_tag']);
        if ($pt === false) { $totals['metadata']['dead']++; if (count($deadSamples['metadata']) < 5) $deadSamples['metadata'][] = "id={$r['id']}"; }
        else $totals['metadata']['alive']++;
    }
}

echo "=== Survey decifratura — tutti i blob ===\n\n";
foreach ($totals as $type => $s) {
    $tot = array_sum($s);
    if ($tot === 0) { echo "[$type] (nessun record)\n"; continue; }
    printf("[%-10s] total=%-4d alive=%-4d dead=%-4d", $type, $tot, $s['alive'] ?? 0, $s['dead'] ?? 0);
    if (isset($s['missing'])) printf(" missing=%-4d", $s['missing']);
    if (isset($s['orphan_db'])) printf(" orphan_db=%-4d", $s['orphan_db']);
    $health = $tot > 0 ? round(($s['alive'] ?? 0) / $tot * 100) : 0;
    echo " | health=$health%\n";
    if (!empty($deadSamples[$type])) echo "    dead samples: " . implode(', ', $deadSamples[$type]) . "\n";
}
