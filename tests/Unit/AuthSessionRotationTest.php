<?php

namespace Tests\Unit;

use App\Core\Session;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 19 — Session rotation + claims refresh.
 * I test non avviano una vera sessione PHP (phpunit in CLI non ha
 * cookies); verifichiamo solo che le funzioni siano callable e non
 * crashino quando session inactive (no-op).
 */
final class AuthSessionRotationTest extends TestCase
{
    #[Test]
    public function regenerate_is_noop_when_session_inactive(): void
    {
        // session non avviata in CLI: regenerate deve tornare silente
        $this->expectNotToPerformAssertions();
        Session::regenerate();
    }

    #[Test]
    public function session_regenerate_is_exposed_as_public_static(): void
    {
        $ref = new \ReflectionMethod(Session::class, 'regenerate');
        $this->assertTrue($ref->isPublic());
        $this->assertTrue($ref->isStatic());
    }

    #[Test]
    public function auth_refresh_helper_exposed(): void
    {
        $ref = new \ReflectionMethod(\App\Core\Auth::class, 'refreshCurrentUserClaims');
        $this->assertTrue($ref->isPublic());
        $this->assertTrue($ref->isStatic());
    }

    #[Test]
    public function auth_refresh_returns_false_when_no_username(): void
    {
        $result = \App\Core\Auth::refreshCurrentUserClaims();
        $this->assertFalse($result);
    }
}
