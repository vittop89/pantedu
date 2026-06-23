<?php

namespace Tests\Unit;

use App\Services\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    #[Test]
    public function starts_unblocked(): void
    {
        $rl = new RateLimiter(maxAttempts: 3, lockoutSeconds: 60);
        $this->assertFalse($rl->isBlocked());
        $this->assertSame(3, $rl->attemptsLeft());
    }

    #[Test]
    public function blocks_after_max_attempts(): void
    {
        $rl = new RateLimiter(maxAttempts: 3, lockoutSeconds: 60);
        $rl->hit(); $rl->hit(); $rl->hit();
        $this->assertTrue($rl->isBlocked());
        $this->assertGreaterThan(0, $rl->remainingSeconds());
    }

    #[Test]
    public function reset_clears_state(): void
    {
        $rl = new RateLimiter(maxAttempts: 2, lockoutSeconds: 60);
        $rl->hit(); $rl->hit();
        $this->assertTrue($rl->isBlocked());
        $rl->reset();
        $this->assertFalse($rl->isBlocked());
    }

    #[Test]
    public function unblocks_after_lockout_elapsed(): void
    {
        $rl = new RateLimiter(key: 'test_k', maxAttempts: 2, lockoutSeconds: 1);
        $rl->hit(); $rl->hit();
        $this->assertTrue($rl->isBlocked());

        // Simulate elapsed time by rewriting session state
        $_SESSION['test_k']['last'] = time() - 10;
        $this->assertFalse($rl->isBlocked());
    }

    #[Test]
    public function separate_keys_track_separately(): void
    {
        $a = new RateLimiter(key: 'a', maxAttempts: 2);
        $b = new RateLimiter(key: 'b', maxAttempts: 2);
        $a->hit(); $a->hit();
        $this->assertTrue($a->isBlocked());
        $this->assertFalse($b->isBlocked());
    }
}
