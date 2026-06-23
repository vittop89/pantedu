<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
use App\Core\Database;

$kms = hex2bin($_ENV['KMS_MASTER_KEY']);
$pdo = Database::connection();
$rows = $pdo->query("SELECT id, topic, title, map_blob_path FROM teacher_content WHERE teacher_id=77 AND map_blob_path IS NOT NULL ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$wk = $pdo->prepare('SELECT wrapped_kek FROM teacher_keys WHERE teacher_id=77 AND key_version=?');
$wk->execute([1]);
$wrapped = $wk->fetchColumn();
$tkek = hash_hkdf('sha256', $kms, 32, '1', 'pantedu-teacher-kek-v1|77');
$kek = openssl_decrypt(substr($wrapped, 12, 32), 'aes-256-gcm', $tkek, OPENSSL_RAW_DATA, substr($wrapped, 0, 12), substr($wrapped, 12+32, 16));

if ($kek === false) {
    echo "FATAL: KEK unwrap failed (KMS o wrapped_kek wrong)\n";
    exit(1);
}

$root = dirname(__DIR__, 2);
$alive = 0; $deadDecrypt = 0; $missingFile = 0;
$deadIds = []; $missingIds = [];

foreach ($rows as $r) {
    $f = $root . '/storage/maps_enc/' . $r['map_blob_path'];
    if (!is_file($f)) {
        $missingFile++;
        $missingIds[] = $r['id'];
        continue;
    }
    $raw = file_get_contents($f);
    $iv = substr($raw, 2, 12);
    $tag = substr($raw, 2 + 12, 16);
    $ct = substr($raw, 2 + 12 + 16);
    $pt = openssl_decrypt($ct, 'aes-256-gcm', $kek, OPENSSL_RAW_DATA, $iv, $tag);
    if ($pt === false) {
        $deadDecrypt++;
        $deadIds[] = $r['id'];
    } else {
        $alive++;
    }
}

echo "total=" . count($rows) . " alive=$alive dead_decrypt=$deadDecrypt missing_file=$missingFile\n";
echo "dead_ids: " . implode(',', array_slice($deadIds, 0, 20)) . (count($deadIds) > 20 ? "..." : "") . "\n";
echo "missing_ids: " . implode(',', array_slice($missingIds, 0, 20)) . (count($missingIds) > 20 ? "..." : "") . "\n";
