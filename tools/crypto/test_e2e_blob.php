<?php
/**
 * E2E test decifratura blob mappa — diagnostica decrypt_tag_mismatch.
 *
 * Uso:  php tools/crypto/test_e2e_blob.php [content_id]
 *       content_id = teacher_content.id (default: 236)
 *
 * Test step-by-step:
 *   1. Verifica .env + .env.local → KMS_MASTER_KEY presente e valida (64-hex)
 *   2. Query teacher_keys per (teacher_id, key_version) → wrapped_kek
 *   3. HKDF derive TKEK con KMS_MASTER + teacher_id + key_version
 *   4. Unwrap wrapped_kek con TKEK → KEK (32 bytes)
 *   5. Query teacher_content → map_blob_path
 *   6. Read storage/maps_enc/{teacher_id}/{ulid}.bin → unpack kv/iv/tag/ct
 *   7. Decrypt blob con KEK → plaintext (XML drawio)
 *
 * Stampa risultato di ogni step con dettagli su fallimento.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/bootstrap.php';

use App\Core\Database;

$contentId = (int)($argv[1] ?? 236);

echo "=== E2E decrypt test per teacher_content.id=$contentId ===\n\n";

// ── STEP 1: KMS_MASTER_KEY ─────────────────────────────────
$kmsHex = $_ENV['KMS_MASTER_KEY'] ?? '';
echo "[1] KMS_MASTER_KEY in env: ";
if (!preg_match('/^[0-9a-fA-F]{64}$/', $kmsHex)) {
    echo "❌ INVALID (len=" . strlen($kmsHex) . ", first 16: " . substr($kmsHex, 0, 16) . "...)\n";
    exit(1);
}
echo "✅ OK (64 hex, first 8: " . substr($kmsHex, 0, 8) . ")\n";
$kmsMaster = hex2bin($kmsHex);

// ── STEP 2: query teacher_content ──────────────────────────
$pdo = Database::connection();
$stmt = $pdo->prepare(
    'SELECT id, teacher_id, topic, title, map_blob_path, body_pt_kv
     FROM teacher_content WHERE id = ?'
);
$stmt->execute([$contentId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

echo "\n[2] teacher_content row id=$contentId: ";
if (!$row) {
    echo "❌ NOT FOUND in DB\n";
    exit(1);
}
echo "✅ OK\n";
echo "    teacher_id    = " . $row['teacher_id'] . "\n";
echo "    topic         = " . $row['topic'] . "\n";
echo "    title         = " . $row['title'] . "\n";
echo "    map_blob_path = " . $row['map_blob_path'] . "\n";

$ownerTid = (int)$row['teacher_id'];
$blobPath = (string)$row['map_blob_path'];

// ── STEP 3: query teacher_keys ─────────────────────────────
$stmt = $pdo->prepare(
    'SELECT key_version, LENGTH(wrapped_kek) AS klen, wrapped_kek
     FROM teacher_keys WHERE teacher_id = ? ORDER BY key_version DESC'
);
$stmt->execute([$ownerTid]);
$keys = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "\n[3] teacher_keys rows for teacher_id=$ownerTid: ";
if (!$keys) {
    echo "❌ NESSUNA ROW (crypto-shredded?)\n";
    exit(1);
}
echo count($keys) . " row(s) trovate\n";
foreach ($keys as $k) {
    echo "    key_version=" . $k['key_version'] . ", wrapped_kek len=" . $k['klen']
       . ", first 8 bytes hex=" . bin2hex(substr($k['wrapped_kek'], 0, 8)) . "\n";
}

// Usa la più alta (corrispondente a quella usata per encrypt)
$activeKey = $keys[0];
$keyVersion = (int)$activeKey['key_version'];
$wrappedKek = $activeKey['wrapped_kek'];

// ── STEP 4: HKDF derive TKEK ───────────────────────────────
$salt = 'pantedu-teacher-kek-v1|' . $ownerTid;
$info = (string)$keyVersion;
$tkek = hash_hkdf('sha256', $kmsMaster, 32, $info, $salt);
echo "\n[4] HKDF derive TKEK:\n";
echo "    salt = '$salt'\n";
echo "    info = '$info'\n";
echo "    TKEK first 8 hex = " . bin2hex(substr($tkek, 0, 8)) . "\n";

// ── STEP 5: unwrap KEK ─────────────────────────────────────
$IV_LEN = 12; $TAG_LEN = 16; $KEK_LEN = 32;
$expectedLen = $IV_LEN + $KEK_LEN + $TAG_LEN;

if (strlen($wrappedKek) !== $expectedLen) {
    echo "\n[5] ❌ wrapped_kek length errata: " . strlen($wrappedKek) . " (expected $expectedLen)\n";
    exit(1);
}
$iv  = substr($wrappedKek, 0, $IV_LEN);
$ct  = substr($wrappedKek, $IV_LEN, $KEK_LEN);
$tag = substr($wrappedKek, $IV_LEN + $KEK_LEN, $TAG_LEN);

$kek = openssl_decrypt($ct, 'aes-256-gcm', $tkek, OPENSSL_RAW_DATA, $iv, $tag);
echo "\n[5] Unwrap wrapped_kek con TKEK: ";
if ($kek === false) {
    echo "❌ FALLITO (openssl_decrypt → false)\n";
    echo "    Cause possibili:\n";
    echo "    - KMS_MASTER_KEY local ≠ VPS (TKEK derivata diversa)\n";
    echo "    - wrapped_kek su DB local cifrato con KMS diversa (DB out-of-sync)\n";
    echo "    Soluzione: sync teacher_keys row da VPS (mysqldump --where='teacher_id=$ownerTid')\n";
    exit(1);
}
echo "✅ OK (KEK 32 bytes, first 8 hex = " . bin2hex(substr($kek, 0, 8)) . ")\n";

// ── STEP 6: read blob file ─────────────────────────────────
$blobFile = $root . '/storage/maps_enc/' . $blobPath;
echo "\n[6] Read blob file: $blobFile\n";
if (!is_file($blobFile)) {
    echo "    ❌ FILE NON ESISTE\n";
    exit(1);
}
$raw = file_get_contents($blobFile);
echo "    ✅ OK (size=" . strlen($raw) . " bytes)\n";

$KV_LEN = 2;
$bkv  = unpack('n', substr($raw, 0, $KV_LEN))[1];
$biv  = substr($raw, $KV_LEN, $IV_LEN);
$btag = substr($raw, $KV_LEN + $IV_LEN, $TAG_LEN);
$bct  = substr($raw, $KV_LEN + $IV_LEN + $TAG_LEN);

echo "    blob kv         = $bkv\n";
echo "    blob iv  hex(8) = " . bin2hex(substr($biv, 0, 8)) . "\n";
echo "    blob tag hex(8) = " . bin2hex(substr($btag, 0, 8)) . "\n";
echo "    blob ct  size   = " . strlen($bct) . " bytes\n";

// Coerenza kv blob vs key disponibile?
if ($bkv !== $keyVersion) {
    echo "    ⚠ blob.kv=$bkv ≠ teacher_keys.key_version=$keyVersion\n";
    echo "    Probabile rotation pending. Cerca row per kv=$bkv...\n";
    $found = null;
    foreach ($keys as $k) { if ((int)$k['key_version'] === $bkv) $found = $k; }
    if (!$found) { echo "    ❌ Nessuna teacher_keys row per kv=$bkv\n"; exit(1); }
    $keyVersion = $bkv;
    $wrappedKek = $found['wrapped_kek'];
    // Re-derive TKEK + unwrap con kv corretto
    $tkek = hash_hkdf('sha256', $kmsMaster, 32, (string)$bkv, $salt);
    $iv  = substr($wrappedKek, 0, $IV_LEN);
    $ct  = substr($wrappedKek, $IV_LEN, $KEK_LEN);
    $tag = substr($wrappedKek, $IV_LEN + $KEK_LEN, $TAG_LEN);
    $kek = openssl_decrypt($ct, 'aes-256-gcm', $tkek, OPENSSL_RAW_DATA, $iv, $tag);
    if ($kek === false) { echo "    ❌ Unwrap kv=$bkv fallito\n"; exit(1); }
    echo "    ✅ Reswitched a kv=$bkv\n";
}

// ── STEP 7: decrypt blob con KEK ───────────────────────────
$plaintext = openssl_decrypt($bct, 'aes-256-gcm', $kek, OPENSSL_RAW_DATA, $biv, $btag);
echo "\n[7] Decrypt blob con KEK: ";
if ($plaintext === false) {
    echo "❌ FALLITO (decrypt_tag_mismatch)\n";
    echo "    KEK valido (unwrap OK) MA blob non decifra.\n";
    echo "    Cause possibili:\n";
    echo "    - Blob file local è copia VPS, ma è stato cifrato con KEK DIVERSA\n";
    echo "      (forse KEK rotation pending non sincronizzata)\n";
    echo "    - Corruption durante trasferimento file (SFTP binary mode?)\n";
    echo "    - blob.kv non matchi alcuna riga teacher_keys (ma sopra l'abbiamo check)\n";
    echo "\n    DEBUG: file md5sum:\n";
    echo "    " . md5_file($blobFile) . "\n";
    echo "    Confronta con stesso file su VPS — se differiscono → corruption SFTP.\n";
    exit(1);
}
echo "✅ OK!\n";
echo "    plaintext size = " . strlen($plaintext) . " bytes\n";
echo "    first 200 chars: " . substr($plaintext, 0, 200) . "...\n";

echo "\n=== TUTTO OK ✅ blob decifrabile. Se controller continua a fallire, ===\n";
echo "=== probabile caching opcache PHP. Restart Apache. ===\n";
