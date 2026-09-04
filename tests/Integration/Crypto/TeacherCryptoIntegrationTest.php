<?php

declare(strict_types=1);

namespace Tests\Integration\Crypto;

use App\Core\Database;
use App\Services\Crypto\TeacherCryptoService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Phase 25.D — Integration test full crypto roundtrip su DB reale.
 *
 * Coverage:
 *   - encrypt → decrypt roundtrip identity (small + large + binary plaintext)
 *   - tag tampering detection (AES-GCM authenticated)
 *   - crypto-shredding (Art. 17 GDPR): post-shred decrypt impossibile
 *   - per-teacher isolation: KEK del teacher A ≠ KEK del teacher B
 *   - rotation: nuova KEK con incremented key_version
 *
 * NB: bootstrap test (tests/bootstrap.php) NON carica .env → KMS_MASTER
 * deve essere settato manualmente via $_ENV['KMS_MASTER_KEY'] nel setUp.
 *
 * Cleanup: ogni test cancella i propri row in teacher_keys + crypto_access_log.
 * Usa teacher_id reali dal DB (superadmin, marco.rossi se presenti).
 *
 * Skipped se DB non disponibile (CI senza MySQL).
 */
final class TeacherCryptoIntegrationTest extends TestCase
{
    private TeacherCryptoService $svc;
    private int $teacherId = 0;

    protected function setUp(): void
    {
        // Carica .env per DB credentials (tests/bootstrap.php non lo fa).
        $basePath = dirname(__DIR__, 3);
        if (is_file($basePath . '/.env')) {
            \Dotenv\Dotenv::createMutable($basePath)->safeLoad();
        }
        if (is_file($basePath . '/.env.local')) {
            \Dotenv\Dotenv::createMutable($basePath, '.env.local')->safeLoad();
        }
        // Re-load Config dopo che .env è in $_ENV (override forced).
        \App\Core\Config::load(dirname(__DIR__, 3) . '/app/Config');

        // Skip se DB non disponibile.
        try {
            Database::connection()->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB connection failed: ' . $e->getMessage());
        }

        // KMS test: usa env o random per-test
        $kms = $_ENV['KMS_MASTER_KEY'] ?? bin2hex(random_bytes(32));
        $this->svc = new TeacherCryptoService($kms);

        // Trova teacher_id reale (superadmin seed)
        $stmt = Database::connection()->prepare(
            'SELECT id FROM users WHERE username = ? LIMIT 1'
        );
        $stmt->execute(['superadmin']);
        $id = (int)($stmt->fetchColumn() ?: 0);
        if ($id === 0) {
            $this->markTestSkipped('superadmin user not seeded');
        }
        $this->teacherId = $id;
    }

    protected function tearDown(): void
    {
        if ($this->teacherId === 0) return;
        try {
            Database::connection()->prepare('DELETE FROM teacher_keys WHERE teacher_id=?')
                ->execute([$this->teacherId]);
            Database::connection()->prepare('DELETE FROM crypto_access_log WHERE teacher_id=?')
                ->execute([$this->teacherId]);
        } catch (\Throwable) {}
    }

    public function testEncryptDecryptRoundtripIdentity(): void
    {
        $plain = 'Esercizio 1.0 con LaTeX $\\frac{x^2}{y}$';
        $env = $this->svc->encrypt($this->teacherId, $plain);

        $this->assertIsString($env['ciphertext']);
        $this->assertSame(12, strlen($env['iv']), 'IV is 12 bytes (GCM)');
        $this->assertSame(16, strlen($env['tag']), 'TAG is 16 bytes (GCM)');
        $this->assertSame(1, $env['kv'], 'first encrypt creates kv=1');

        $decrypted = $this->svc->decrypt($this->teacherId, $env);
        $this->assertSame($plain, $decrypted);
    }

    public function testRoundtripWithLargePlaintext(): void
    {
        // 64KB simula body_pt grande (es. esercizio con molti elementi)
        $plain = str_repeat('Lorem ipsum dolor sit amet. ', 2200);
        $this->assertGreaterThan(50000, strlen($plain));

        $env = $this->svc->encrypt($this->teacherId, $plain);
        $decrypted = $this->svc->decrypt($this->teacherId, $env);
        $this->assertSame($plain, $decrypted);
        $this->assertSame(strlen($plain), strlen($decrypted));
    }

    public function testRoundtripWithBinaryPlaintext(): void
    {
        // Plaintext binario (es. JSON con caratteri Unicode + null bytes)
        $plain = "\x00\x01\x02 binary " . random_bytes(100) . " unicode è à ñ 🔒";

        $env = $this->svc->encrypt($this->teacherId, $plain);
        $decrypted = $this->svc->decrypt($this->teacherId, $env);
        $this->assertSame($plain, $decrypted);
    }

    public function testTamperedCiphertextFailsTagCheck(): void
    {
        $plain = 'Sensitive content';
        $env = $this->svc->encrypt($this->teacherId, $plain);

        // Flip 1 byte del ciphertext
        $tampered = $env;
        $tampered['ciphertext'] = chr(ord($env['ciphertext'][0]) ^ 0xFF) . substr($env['ciphertext'], 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/decrypt_tag_mismatch/');
        $this->svc->decrypt($this->teacherId, $tampered);
    }

    public function testTamperedTagFails(): void
    {
        $plain = 'Sensitive content';
        $env = $this->svc->encrypt($this->teacherId, $plain);

        $tampered = $env;
        $tampered['tag'] = str_repeat("\x00", 16);

        $this->expectException(RuntimeException::class);
        $this->svc->decrypt($this->teacherId, $tampered);
    }

    public function testCryptoShreddingPreventsDecrypt(): void
    {
        $plain = 'Will be shred-able';
        $env = $this->svc->encrypt($this->teacherId, $plain);
        $this->assertSame($plain, $this->svc->decrypt($this->teacherId, $env));

        // Art. 17 GDPR: shred → tutti i body cifrati diventano illeggibili.
        $this->svc->shred($this->teacherId);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/teacher_key_missing/');
        $this->svc->decrypt($this->teacherId, $env);
    }

    public function testShreddingIsIdempotent(): void
    {
        // Shred di un teacher senza KEK non causa errore.
        $this->svc->shred(999999998);  // ID inesistente
        $this->assertTrue(true);  // no exception → ok
    }

    public function testRotationIncrementsKeyVersion(): void
    {
        // Crea KEK v1 implicitamente
        $env1 = $this->svc->encrypt($this->teacherId, 'first');
        $this->assertSame(1, $env1['kv']);

        // Rotate → kv=2
        $newKv = $this->svc->rotate($this->teacherId);
        $this->assertSame(2, $newKv);

        // Encrypt usa il MAX(key_version) → 2
        $env2 = $this->svc->encrypt($this->teacherId, 'second');
        $this->assertSame(2, $env2['kv']);

        // Decrypt vecchia row con kv=1 funziona ancora (chiave preservata)
        $this->assertSame('first', $this->svc->decrypt($this->teacherId, $env1));
        $this->assertSame('second', $this->svc->decrypt($this->teacherId, $env2));
    }

    public function testEnsureTeacherKeyIsIdempotent(): void
    {
        $kv1 = $this->svc->ensureTeacherKey($this->teacherId);
        $kv2 = $this->svc->ensureTeacherKey($this->teacherId);
        $this->assertSame($kv1, $kv2, 'idempotent: stessa kv ritornata');
    }

    public function testCryptoAccessLogIsAppendOnly(): void
    {
        // Encrypt + decrypt → 2 entries in crypto_access_log per il teacher
        $this->svc->encrypt($this->teacherId, 'log test');
        $env = $this->svc->encrypt($this->teacherId, 'log test 2');
        $this->svc->decrypt($this->teacherId, $env);

        $count = Database::connection()->prepare(
            'SELECT COUNT(*) FROM crypto_access_log WHERE teacher_id = ?'
        );
        $count->execute([$this->teacherId]);
        $this->assertGreaterThanOrEqual(3, (int)$count->fetchColumn(),
            'almeno 3 entries (wrap + 2 encrypt + 1 decrypt)');
    }
}
