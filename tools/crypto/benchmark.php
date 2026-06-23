<?php
/**
 * Phase 25.D9 — Performance benchmark TeacherCryptoService + ClasseKeyService.
 *
 * Misura latency di:
 *   - HKDF derivation (KMS → TKEK)
 *   - encrypt() (HKDF + KEK unwrap + AES-GCM)
 *   - decrypt() (HKDF + KEK unwrap + AES-GCM)
 *   - encrypt+decrypt roundtrip
 *
 * Su 3 size: small (1KB), medium (10KB), large (100KB).
 *
 * Target ADR-006: < 100ms p99 totale per request → encrypt+decrypt < 50ms.
 *
 * Usage:
 *   php tools/crypto/benchmark.php
 *   php tools/crypto/benchmark.php --iter=1000 --size=large
 *   php tools/crypto/benchmark.php --classe   # ClasseKeyService benchmark
 *
 * Output: tabella ASCII con p50/p95/p99/max + verdetto vs target.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Services\Crypto\ClasseKeyService;
use App\Services\Crypto\TeacherCryptoService;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: solo CLI.\n");
    exit(1);
}

$iter = 200;
$size = 'all';  // small | medium | large | all
$classe = false;
foreach ($argv as $arg) {
    if (preg_match('/^--iter=(\d+)$/', $arg, $m)) $iter = max(10, min(10000, (int)$m[1]));
    if (preg_match('/^--size=(small|medium|large|all)$/', $arg, $m)) $size = $m[1];
    if ($arg === '--classe') $classe = true;
}

$crypto = new TeacherCryptoService();
if (!$crypto->isConfigured()) {
    fwrite(STDERR, "ERROR: KMS_MASTER_KEY mancante.\n");
    exit(1);
}

$db = Database::connection();
$tid = (int)$db->query("SELECT id FROM users WHERE username='superadmin' LIMIT 1")->fetchColumn();
if ($tid === 0) {
    fwrite(STDERR, "ERROR: utente di test 'superadmin' non trovato.\n");
    exit(1);
}

// Cleanup pre-bench: rimuovi teacher_keys esistente del docente test
$db->prepare('DELETE FROM teacher_keys WHERE teacher_id=?')->execute([$tid]);

$payloads = [
    'small'  => str_repeat('a', 1_024),         // 1KB
    'medium' => str_repeat('b', 10_240),        // 10KB
    'large'  => str_repeat('c', 102_400),       // 100KB
];

if ($size !== 'all') {
    $payloads = [$size => $payloads[$size]];
}

echo "═══════════════════════════════════════════════════════════════════\n";
echo "  CRYPTO BENCHMARK (Phase 25.D9)\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  Iterations: $iter per size\n";
echo "  Service:    " . ($classe ? "ClasseKeyService" : "TeacherCryptoService") . "\n";
echo "  Target:     encrypt + decrypt p99 < 50ms (50% del request budget 100ms)\n";
echo "\n";

if ($classe) {
    $svcCls = new ClasseKeyService();
    $classeKeyId = $svcCls->getOrCreateActiveKey('__bench__', '1z', '2099/2100');
    register_shutdown_function(function() use ($db, $classeKeyId) {
        $db->prepare('DELETE FROM classe_keys WHERE id=?')->execute([$classeKeyId]);
    });
}

foreach ($payloads as $sizeName => $payload) {
    echo "─────── Size: $sizeName (" . strlen($payload) . " bytes) ───────\n";

    // Warmup (10 iter no-measure)
    for ($i = 0; $i < 10; $i++) {
        if ($classe) {
            $env = $svcCls->encrypt($classeKeyId, $payload);
            $svcCls->decrypt($classeKeyId, $env);
        } else {
            $env = $crypto->encrypt($tid, $payload);
            $crypto->decrypt($tid, $env);
        }
    }

    // Benchmark encrypt
    $encryptTimes = [];
    for ($i = 0; $i < $iter; $i++) {
        $t0 = hrtime(true);
        if ($classe) {
            $env = $svcCls->encrypt($classeKeyId, $payload);
        } else {
            $env = $crypto->encrypt($tid, $payload);
        }
        $encryptTimes[] = (hrtime(true) - $t0) / 1_000_000.0;  // → ms
    }

    // Benchmark decrypt (riusa l'ultimo env)
    $decryptTimes = [];
    for ($i = 0; $i < $iter; $i++) {
        $t0 = hrtime(true);
        if ($classe) {
            $svcCls->decrypt($classeKeyId, $env);
        } else {
            $crypto->decrypt($tid, $env);
        }
        $decryptTimes[] = (hrtime(true) - $t0) / 1_000_000.0;
    }

    // Roundtrip
    $rtTimes = [];
    for ($i = 0; $i < $iter; $i++) {
        $t0 = hrtime(true);
        if ($classe) {
            $env = $svcCls->encrypt($classeKeyId, $payload);
            $svcCls->decrypt($classeKeyId, $env);
        } else {
            $env = $crypto->encrypt($tid, $payload);
            $crypto->decrypt($tid, $env);
        }
        $rtTimes[] = (hrtime(true) - $t0) / 1_000_000.0;
    }

    printRow('encrypt',  $encryptTimes);
    printRow('decrypt',  $decryptTimes);
    printRow('roundtrip', $rtTimes);
    echo "\n";
}

// Cleanup
$db->prepare('DELETE FROM teacher_keys WHERE teacher_id=?')->execute([$tid]);
$db->prepare('DELETE FROM crypto_access_log WHERE teacher_id=?')->execute([$tid]);

echo "═══════════════════════════════════════════════════════════════════\n";
echo "  Verdetto:\n";
$lastP99rt = $rtTimes ? percentile($rtTimes, 0.99) : 0;
if ($lastP99rt < 50.0) {
    echo "  ✅ Roundtrip p99 = " . sprintf('%.2f', $lastP99rt) . " ms < 50ms target. OK.\n";
} elseif ($lastP99rt < 100.0) {
    echo "  ⚠️  Roundtrip p99 = " . sprintf('%.2f', $lastP99rt) . " ms (50-100ms range). Tollerabile.\n";
} else {
    echo "  🚨 Roundtrip p99 = " . sprintf('%.2f', $lastP99rt) . " ms > 100ms target. INVESTIGATE.\n";
    echo "     Verifica: hash_hkdf cost, openssl_encrypt overhead, DB roundtrip\n";
    echo "     teacher_keys.wrapped_kek lookup (eventuale prepared statement cache).\n";
}

function printRow(string $label, array $times): void
{
    $p50 = percentile($times, 0.50);
    $p95 = percentile($times, 0.95);
    $p99 = percentile($times, 0.99);
    $max = max($times);
    $mean = array_sum($times) / count($times);
    printf(
        "  %-10s  mean %5.2fms  p50 %5.2fms  p95 %5.2fms  p99 %5.2fms  max %5.2fms\n",
        $label, $mean, $p50, $p95, $p99, $max
    );
}

function percentile(array $times, float $q): float
{
    $sorted = $times;
    sort($sorted);
    $idx = (int)floor($q * (count($sorted) - 1));
    return $sorted[$idx];
}
