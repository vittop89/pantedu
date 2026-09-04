<?php

declare(strict_types=1);

/**
 * Cifra le compilazioni risdoc legacy (2026-09-04, migration 101).
 *
 * Le righe di risdoc_compilations_data scritte prima della migration 101
 * hanno `data_json` in chiaro e `data_ct` NULL. Questo strumento le converte
 * con lo stesso envelope dei contenuti (KEK del docente, AES-256-GCM) e
 * azzera il plaintext. Idempotente: le righe gia' cifrate non si toccano.
 *
 * Va lanciato UNA volta dopo la migration, con KMS_MASTER_KEY disponibile
 * (.env.local). Senza --apply mostra soltanto cosa farebbe.
 *
 * Uso:
 *   php tools/gdpr/encrypt_risdoc_compilations.php            # dry-run
 *   php tools/gdpr/encrypt_risdoc_compilations.php --apply
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\Crypto\TeacherCryptoService;

$apply  = in_array('--apply', $argv, true);
$crypto = new TeacherCryptoService();
if (!$crypto->isConfigured()) {
    fwrite(STDERR, "[ERRORE] KMS_MASTER_KEY non configurata: niente da cifrare senza chiave.\n");
    exit(1);
}

$pdo  = Database::connection();
$rows = $pdo->query(
    'SELECT id, teacher_id, data_json
       FROM risdoc_compilations_data
      WHERE data_json IS NOT NULL AND data_ct IS NULL
      ORDER BY id'
)->fetchAll(PDO::FETCH_ASSOC);

printf("Compilazioni in chiaro: %d%s\n\n", count($rows), $apply ? '' : ' (dry-run: aggiungi --apply)');

$upd = $pdo->prepare(
    'UPDATE risdoc_compilations_data
        SET data_ct = ?, data_iv = ?, data_tag = ?, data_kv = ?, data_json = NULL
      WHERE id = ? AND data_ct IS NULL'
);

$done = 0;
$fail = 0;
foreach ($rows as $r) {
    $id  = (int)$r['id'];
    $tid = (int)$r['teacher_id'];
    $len = strlen((string)$r['data_json']);
    if (!$apply) {
        printf("  #%-6d docente %-5d %8d byte\n", $id, $tid, $len);
        continue;
    }
    try {
        $env = $crypto->encrypt($tid, (string)$r['data_json']);
        $upd->execute([$env['ciphertext'], $env['iv'], $env['tag'], (int)$env['kv'], $id]);
        $done++;
        printf("  #%-6d docente %-5d cifrata\n", $id, $tid);
    } catch (Throwable $e) {
        $fail++;
        printf("  #%-6d docente %-5d FALLITA: %s\n", $id, $tid, $e->getMessage());
    }
}

if ($apply) {
    printf("\nCifrate %d, fallite %d.\n", $done, $fail);
    exit($fail > 0 ? 1 : 0);
}
