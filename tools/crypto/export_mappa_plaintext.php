<?php
/**
 * Esporta plaintext .drawio dei content_type=mappa di un teacher.
 * DA ESEGUIRE SUL VPS dove KMS_MASTER_KEY + teacher_keys row sono coerenti
 * con i blob in storage/maps_enc/.
 *
 * Output: tools/crypto/_export_mappe_77/{content_id}.drawio
 * Trasferire poi su locale + eseguire reimport_mappa_plaintext.php.
 *
 * Usage: php tools/crypto/export_mappa_plaintext.php <teacher_id>
 */
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';

$teacherId = (int)($argv[1] ?? 0);
if ($teacherId <= 0) {
    fwrite(STDERR, "Usage: php tools/crypto/export_mappa_plaintext.php <teacher_id>\n");
    exit(1);
}

$db = App\Core\Database::connection();
$store = new App\Services\Maps\MapBlobStore();

$outDir = __DIR__ . '/_export_mappe_' . $teacherId;
if (!is_dir($outDir) && !mkdir($outDir, 0755, true)) {
    fwrite(STDERR, "mkdir failed: $outDir\n");
    exit(1);
}

$stmt = $db->prepare(
    "SELECT id, teacher_id, topic, title, map_blob_path
     FROM teacher_content
     WHERE content_type = 'mappa' AND teacher_id = ? AND map_blob_path != ''"
);
$stmt->execute([$teacherId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$manifest = [];
$ok = 0; $fail = 0;
foreach ($rows as $r) {
    $id = (int)$r['id'];
    try {
        $plain = $store->get((int)$r['teacher_id'], $r['map_blob_path']);
        $file = $outDir . '/' . $id . '.drawio';
        file_put_contents($file, $plain);
        $manifest[] = [
            'id'       => $id,
            'teacher'  => (int)$r['teacher_id'],
            'topic'    => $r['topic'],
            'title'    => $r['title'],
            'blob_path'=> $r['map_blob_path'],
            'sha256'   => hash('sha256', $plain),
            'bytes'    => strlen($plain),
        ];
        $ok++;
        echo "OK  id=$id ({$r['title']}) -> {$id}.drawio (" . strlen($plain) . " bytes)\n";
    } catch (Throwable $e) {
        $fail++;
        echo "FAIL id=$id ({$r['title']}): " . $e->getMessage() . "\n";
    }
}

file_put_contents($outDir . '/_manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nDone: $ok ok, $fail fail. Output dir: $outDir\n";
echo "Transfer to local then run: php tools/crypto/reimport_mappa_plaintext.php $teacherId\n";
