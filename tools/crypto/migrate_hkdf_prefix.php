<?php
/**
 * Migrate teacher_keys.wrapped_kek HKDF prefix
 * fismapant-teacher-kek-v1 -> pantedu-teacher-kek-v1.
 *
 * Idempotent: detects already-migrated rows by trying decrypt with new
 * salt first. Atomic per row (UPDATE single-row). KEK plaintext value
 * NEVER changes (only the wrapping TKEK rotates), so all encrypted
 * downstream blobs (map_blob_path, body_pt_ct, etc.) stay valid without
 * re-encrypt.
 *
 * Pre: DB snapshot. Post: revert code prefix to pantedu-* + reload php-fpm.
 *
 * Out-of-scope (kept as legacy fismapant-* due to user-file dependency):
 *   - TeacherRecoveryService::HKDF_INFO ('fismapant-recovery-key-v1')
 *     = HMAC label for user-downloaded recovery manifests
 *   - AdminCryptoStatusController 'fismapant-export-signing-v1'
 *     = HMAC label for user-downloaded export zips
 *
 * Usage: sudo -u www-data php /var/www/pantedu/tools/crypto/migrate_hkdf_prefix.php
 */

declare(strict_types=1);

$envFile = '/var/www/pantedu/.env.local';
if (!is_readable($envFile)) {
    fwrite(STDERR, "ERROR: $envFile not readable\n");
    exit(2);
}
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (preg_match('/^([A-Z_][A-Z0-9_]*)=(.*)$/i', $line, $m)) {
        $_ENV[$m[1]] = trim($m[2], "\"' ");
    }
}

$kmsHex = $_ENV['KMS_MASTER_KEY'] ?? '';
if (!preg_match('/^[0-9a-fA-F]{64}$/', $kmsHex)) {
    fwrite(STDERR, "ERROR: KMS_MASTER_KEY missing or invalid (expected 64 hex chars)\n");
    exit(2);
}
$kms = hex2bin($kmsHex);

$dbHost = $_ENV['DB_HOST'] ?? '127.0.0.1';
$dbName = $_ENV['DB_NAME'] ?? 'pantedu';
$dbUser = $_ENV['DB_USER'] ?? 'pantedu_app';
$dbPass = $_ENV['DB_PASS'] ?? '';

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: DB connect failed: " . $e->getMessage() . "\n");
    exit(2);
}

function unwrap(string $wrapped, string $tkek): ?string
{
    if (strlen($wrapped) !== 60) return null;
    $iv  = substr($wrapped, 0, 12);
    $ct  = substr($wrapped, 12, 32);
    $tag = substr($wrapped, 12 + 32, 16);
    $kek = openssl_decrypt($ct, 'aes-256-gcm', $tkek, OPENSSL_RAW_DATA, $iv, $tag);
    return $kek === false ? null : $kek;
}

function wrap(string $kek, string $tkek): string
{
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($kek, 'aes-256-gcm', $tkek, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ct === false) {
        throw new RuntimeException('encrypt failed');
    }
    return $iv . $ct . $tag;
}

$rows = $pdo->query(
    "SELECT teacher_id, key_version, wrapped_kek FROM teacher_keys"
)->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($rows) . " teacher_keys rows\n";

$migrated = 0;
$alreadyMigrated = 0;
$failed = [];

foreach ($rows as $row) {
    $tid = (int)$row['teacher_id'];
    $kv  = (int)$row['key_version'];
    $wrapped = (string)$row['wrapped_kek'];

    $tkek_old = hash_hkdf('sha256', $kms, 32, (string)$kv, "fismapant-teacher-kek-v1|$tid");
    $tkek_new = hash_hkdf('sha256', $kms, 32, (string)$kv, "pantedu-teacher-kek-v1|$tid");

    // Idempotency: try new salt first.
    if (unwrap($wrapped, $tkek_new) !== null) {
        printf("  teacher_id=%d kv=%d  ALREADY MIGRATED (new salt unwraps)\n", $tid, $kv);
        $alreadyMigrated++;
        continue;
    }

    $kek = unwrap($wrapped, $tkek_old);
    if ($kek === null) {
        printf("  teacher_id=%d kv=%d  FAILED — neither old nor new salt unwraps\n", $tid, $kv);
        $failed[] = "tid=$tid kv=$kv";
        continue;
    }

    $newWrapped = wrap($kek, $tkek_new);
    $upd = $pdo->prepare(
        "UPDATE teacher_keys SET wrapped_kek = ? WHERE teacher_id = ? AND key_version = ?"
    );
    $upd->execute([$newWrapped, $tid, $kv]);

    // Verify
    $verify = $pdo->prepare(
        "SELECT wrapped_kek FROM teacher_keys WHERE teacher_id = ? AND key_version = ?"
    );
    $verify->execute([$tid, $kv]);
    $check = $verify->fetchColumn();
    if (unwrap((string)$check, $tkek_new) === $kek) {
        printf("  teacher_id=%d kv=%d  MIGRATED OK\n", $tid, $kv);
        $migrated++;
    } else {
        printf("  teacher_id=%d kv=%d  POST-VERIFY FAIL\n", $tid, $kv);
        $failed[] = "tid=$tid kv=$kv post-verify";
    }
}

echo "\nSummary:\n";
echo "  migrated         = $migrated\n";
echo "  already_migrated = $alreadyMigrated\n";
echo "  failed           = " . count($failed) . "\n";
if ($failed) {
    echo "  failures: " . implode(', ', $failed) . "\n";
}
exit($failed ? 1 : 0);
