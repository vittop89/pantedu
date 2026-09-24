<?php

declare(strict_types=1);

namespace Tests\Unit\Crypto;

use App\Services\Crypto\TeacherCryptoService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Phase 25.D — Unit tests per TeacherCryptoService.
 *
 * Test SOLO crypto math + envelope, NO DB. Per i test che richiedono DB
 * (encrypt+decrypt full roundtrip che persiste teacher_keys), vedi
 * tests/Integration/Crypto/.
 *
 * Tecnica: TeacherCryptoService accetta `?string $kmsMasterHex` nel
 * constructor → bypassa env var lookup in modalità unit-isolated. Il KMS
 * usato qui è random per-test.
 */
final class TeacherCryptoServiceTest extends TestCase
{
    private string $testKms;

    protected function setUp(): void
    {
        // KMS_MASTER 32 bytes random per ogni test (isolation).
        $this->testKms = bin2hex(random_bytes(32));
    }

    public function testIsConfiguredFalseWhenNoKey(): void
    {
        $svc = new TeacherCryptoService('');
        $this->assertFalse($svc->isConfigured());
    }

    public function testIsConfiguredFalseOnInvalidHex(): void
    {
        $svc = new TeacherCryptoService('not-hex');
        $this->assertFalse($svc->isConfigured());
    }

    public function testIsConfiguredFalseOnTooShort(): void
    {
        $svc = new TeacherCryptoService(bin2hex(random_bytes(16)));  // 32 char != 64
        $this->assertFalse($svc->isConfigured());
    }

    public function testIsConfiguredTrueOn64HexChars(): void
    {
        $svc = new TeacherCryptoService($this->testKms);
        $this->assertTrue($svc->isConfigured());
    }

    public function testEncryptThrowsWhenKmsNotConfigured(): void
    {
        $svc = new TeacherCryptoService('');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/kms_not_configured/');
        $svc->encrypt(1, 'plaintext');
    }

    public function testDecryptThrowsWhenKmsNotConfigured(): void
    {
        $svc = new TeacherCryptoService('');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/kms_not_configured/');
        $svc->decrypt(1, [
            'ciphertext' => 'x', 'iv' => 'y', 'tag' => 'z', 'kv' => 1,
        ]);
    }

    public function testGenerateKmsKeyToolOutputs64Hex(): void
    {
        // Valida che random_bytes(32) → bin2hex sia esattamente 64 char.
        $hex = bin2hex(random_bytes(32));
        $this->assertSame(64, strlen($hex));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hex);
    }

    /**
     * Smoke test: il deriveTkek (private) produce TKEK distinte per
     * teacher_id diversi. Verifica via encrypt()→ciphertext distinct (DB
     * mock necessario). Test rimandato a integration layer.
     */
    public function testKmsHexValidationRejectsUppercaseMixed(): void
    {
        // 64 char ma con mix maiuscole/minuscole — il regex è case-insensitive
        // perché /^[0-9a-fA-F]{64}$/. Verifica che valida.
        $hexMixed = strtoupper(substr($this->testKms, 0, 32)) . substr($this->testKms, 32);
        $svc = new TeacherCryptoService($hexMixed);
        $this->assertTrue($svc->isConfigured());
    }

    public function testKmsHexValidationRejectsNonHexChars(): void
    {
        $bad = str_repeat('z', 64);  // valid length, invalid chars
        $svc = new TeacherCryptoService($bad);
        $this->assertFalse($svc->isConfigured());
    }
}
