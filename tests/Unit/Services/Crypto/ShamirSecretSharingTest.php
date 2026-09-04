<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crypto;

use App\Services\Crypto\ShamirSecretSharing;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ShamirSecretSharingTest extends TestCase
{
    #[Test]
    public function split_and_combine_roundtrip_short_secret(): void
    {
        $svc = new ShamirSecretSharing();
        $secret = 'pantedu-test-secret';
        $shares = $svc->split($secret, threshold: 3, n: 5);

        $this->assertCount(5, $shares);
        foreach ($shares as $s) {
            $this->assertMatchesRegularExpression('/^FSS1:\d+:[0-9a-f]+$/', $s);
        }

        // Recovery con esattamente 3 share
        $recovered = $svc->combine([$shares[0], $shares[2], $shares[4]]);
        $this->assertSame($secret, $recovered);
    }

    #[Test]
    public function combine_works_with_all_n_shares(): void
    {
        $svc = new ShamirSecretSharing();
        $secret = 'all-shares-recovery-test';
        $shares = $svc->split($secret, threshold: 3, n: 5);
        $this->assertSame($secret, $svc->combine($shares));
    }

    #[Test]
    public function combine_works_with_more_than_threshold(): void
    {
        $svc = new ShamirSecretSharing();
        $secret = 'four-of-five-test';
        $shares = $svc->split($secret, threshold: 3, n: 5);
        $this->assertSame($secret, $svc->combine([$shares[0], $shares[1], $shares[3], $shares[4]]));
    }

    #[Test]
    public function combine_with_long_secret(): void
    {
        $svc = new ShamirSecretSharing();
        // KMS_MASTER_KEY simulation: 32 byte random
        $secret = bin2hex(random_bytes(32));  // 64 hex chars
        $shares = $svc->split($secret, threshold: 3, n: 5);
        $this->assertSame($secret, $svc->combine(array_slice($shares, 0, 3)));
    }

    #[Test]
    public function combine_with_binary_secret(): void
    {
        $svc = new ShamirSecretSharing();
        // Binary content including null bytes
        $secret = "\x00\x01\xff\x80\x55" . random_bytes(20);
        $shares = $svc->split($secret, threshold: 3, n: 5);
        $this->assertSame($secret, $svc->combine(array_slice($shares, 0, 3)));
    }

    #[Test]
    public function combine_below_threshold_fails_integrity(): void
    {
        $svc = new ShamirSecretSharing();
        $secret = 'pantedu-secret';
        $shares = $svc->split($secret, threshold: 3, n: 5);

        // Con 2 share su 3 threshold, integrity tag non torna → exception
        $this->expectException(RuntimeException::class);
        $svc->combine([$shares[0], $shares[1]]);
    }

    #[Test]
    public function tampered_share_fails_integrity(): void
    {
        $svc = new ShamirSecretSharing();
        $secret = 'integrity-check';
        $shares = $svc->split($secret, threshold: 3, n: 5);

        // Modifica un byte del primo share (toggle ultimo hex char)
        $tampered = $shares[0];
        $lastChar = strtolower(substr($tampered, -1));
        $newChar  = $lastChar === '0' ? '1' : '0';
        $tampered = substr($tampered, 0, -1) . $newChar;

        $this->expectException(RuntimeException::class);
        $svc->combine([$tampered, $shares[1], $shares[2]]);
    }

    #[Test]
    public function split_validates_threshold(): void
    {
        $svc = new ShamirSecretSharing();
        $this->expectException(InvalidArgumentException::class);
        $svc->split('test', threshold: 1, n: 5);
    }

    #[Test]
    public function split_validates_n_geq_threshold(): void
    {
        $svc = new ShamirSecretSharing();
        $this->expectException(InvalidArgumentException::class);
        $svc->split('test', threshold: 5, n: 3);
    }

    #[Test]
    public function split_rejects_empty_secret(): void
    {
        $svc = new ShamirSecretSharing();
        $this->expectException(InvalidArgumentException::class);
        $svc->split('', threshold: 3, n: 5);
    }

    #[Test]
    public function combine_rejects_invalid_format(): void
    {
        $svc = new ShamirSecretSharing();
        $this->expectException(InvalidArgumentException::class);
        $svc->combine(['not-a-valid-share', 'another-invalid']);
    }

    #[Test]
    public function threshold_2_works(): void
    {
        $svc = new ShamirSecretSharing();
        $secret = 'min-threshold';
        $shares = $svc->split($secret, threshold: 2, n: 3);
        $this->assertSame($secret, $svc->combine([$shares[0], $shares[1]]));
        $this->assertSame($secret, $svc->combine([$shares[0], $shares[2]]));
    }

    #[Test]
    public function different_splits_of_same_secret_are_different(): void
    {
        // Random coefficients → 2 split successivi danno share differenti
        $svc = new ShamirSecretSharing();
        $secret = 'randomness-check';
        $a = $svc->split($secret, threshold: 3, n: 5);
        $b = $svc->split($secret, threshold: 3, n: 5);
        $this->assertNotSame($a[0], $b[0]);
        $this->assertSame($secret, $svc->combine(array_slice($a, 0, 3)));
        $this->assertSame($secret, $svc->combine(array_slice($b, 0, 3)));
    }
}
