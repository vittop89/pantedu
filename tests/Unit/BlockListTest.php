<?php

namespace Tests\Unit;

use App\Services\BlockList;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlockListTest extends TestCase
{
    private function bl(): BlockList
    {
        return new BlockList(
            blockedCredentialsPath: TEST_FIXTURES . '/data/blocked_credentials.json',
            blockedIpsPath:         TEST_FIXTURES . '/data/blocked_ips.json',
        );
    }

    #[Test]
    public function detects_blocked_username(): void
    {
        $this->assertTrue($this->bl()->isUsernameBlocked('baduser'));
        $this->assertFalse($this->bl()->isUsernameBlocked('gooduser'));
    }

    #[Test]
    public function detects_blocked_ip_for_section(): void
    {
        $this->assertTrue($this->bl()->isIpBlockedForSection('10.0.0.99', 'ar5s'));
        $this->assertFalse($this->bl()->isIpBlockedForSection('10.0.0.99', 'sc5s'));
        $this->assertFalse($this->bl()->isIpBlockedForSection('1.2.3.4',   'ar5s'));
    }

    #[Test]
    public function missing_files_never_block(): void
    {
        $bl = new BlockList('/no/creds.json', '/no/ips.json');
        $this->assertFalse($bl->isUsernameBlocked('anyone'));
        $this->assertFalse($bl->isIpBlockedForSection('1.1.1.1', 'ar5s'));
    }
}
