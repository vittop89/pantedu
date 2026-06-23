<?php

namespace Tests\Unit;

use App\Services\HashGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HashGeneratorTest extends TestCase
{
    #[Test]
    public function generates_verifiable_hash(): void
    {
        $gen  = new HashGenerator();
        $hash = $gen->generate('secret-password', cost: 4);
        $this->assertTrue($gen->verify('secret-password', $hash));
        $this->assertFalse($gen->verify('wrong', $hash));
    }

    #[Test]
    public function rejects_too_short_password(): void
    {
        $this->expectException(RuntimeException::class);
        (new HashGenerator())->generate('abc', cost: 4);
    }

    #[Test]
    public function rejects_oversized_password(): void
    {
        $this->expectException(RuntimeException::class);
        (new HashGenerator())->generate(str_repeat('a', 5000), cost: 4);
    }

    #[Test]
    public function hash_is_bcrypt(): void
    {
        $hash = (new HashGenerator())->generate('testpass', cost: 4);
        $this->assertMatchesRegularExpression('#^\$2[ay]\$04\$#', $hash);
    }
}
