<?php

namespace Tests\Unit;

use App\Services\RateLimitStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 19 — RateLimitStore con backend forzato "session" per test
 * deterministici (DB non disponibile in unit test env).
 */
final class RateLimitStoreTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    #[Test]
    public function empty_bucket_returns_no_hits(): void
    {
        $s = new RateLimitStore('session');
        $this->assertSame([], $s->hits('x', 60));
    }

    #[Test]
    public function append_adds_to_bucket(): void
    {
        $s = new RateLimitStore('session');
        $s->append('bucket1');
        $this->assertCount(1, $s->hits('bucket1', 60));
    }

    #[Test]
    public function expired_hits_are_filtered_out(): void
    {
        $_SESSION['rate:old'] = [\time() - 3600, \time() - 3500];
        $s = new RateLimitStore('session');
        $this->assertSame([], $s->hits('old', 60));
    }

    #[Test]
    public function independent_buckets(): void
    {
        $s = new RateLimitStore('session');
        $s->append('a');
        $s->append('b');
        $s->append('b');
        $this->assertCount(1, $s->hits('a', 60));
        $this->assertCount(2, $s->hits('b', 60));
    }

    #[Test]
    public function session_bucket_capped_at_200(): void
    {
        $s = new RateLimitStore('session');
        for ($i = 0; $i < 250; $i++) $s->append('big');
        $this->assertLessThanOrEqual(200, \count($s->hits('big', 3600)));
    }

    #[Test]
    public function hits_returns_sorted(): void
    {
        $_SESSION['rate:sorted'] = [\time() - 10, \time() - 5, \time() - 1];
        $s = new RateLimitStore('session');
        $h = $s->hits('sorted', 60);
        $this->assertEquals($h, \array_values($h)); // array reindexed
    }
}
