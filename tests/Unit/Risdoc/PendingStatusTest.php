<?php

declare(strict_types=1);

namespace Tests\Unit\Risdoc;

use App\Domain\Risdoc\PendingStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PendingStatusTest extends TestCase
{
    #[Test]
    public function from_valid_strings(): void
    {
        $this->assertSame(PendingStatus::PENDING,  PendingStatus::tryFromString('pending'));
        $this->assertSame(PendingStatus::APPROVED, PendingStatus::tryFromString('approved'));
        $this->assertSame(PendingStatus::REJECTED, PendingStatus::tryFromString('rejected'));
    }

    #[Test]
    public function tryFromString_normalizes_whitespace_and_case(): void
    {
        $this->assertSame(PendingStatus::PENDING, PendingStatus::tryFromString('  PENDING  '));
        $this->assertSame(PendingStatus::APPROVED, PendingStatus::tryFromString('Approved'));
    }

    #[Test]
    public function tryFromString_returns_null_for_invalid(): void
    {
        $this->assertNull(PendingStatus::tryFromString(null));
        $this->assertNull(PendingStatus::tryFromString(''));
        $this->assertNull(PendingStatus::tryFromString('all'));
        $this->assertNull(PendingStatus::tryFromString('garbage'));
    }

    #[Test]
    public function values_returns_all_string_values(): void
    {
        $this->assertSame(['pending', 'approved', 'rejected'], PendingStatus::values());
    }

    #[Test]
    public function isPending_helper(): void
    {
        $this->assertTrue(PendingStatus::PENDING->isPending());
        $this->assertFalse(PendingStatus::APPROVED->isPending());
        $this->assertFalse(PendingStatus::REJECTED->isPending());
    }
}
