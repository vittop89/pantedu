<?php

namespace Tests\Unit\Core;

use App\Core\Auth;
use App\Core\Session;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 19 — Auth test (no DB). Testa guardia session-driven
 * (check/user/role/hasRole/hasAccess) + API surface dei nuovi helper
 * refreshCurrentUserClaims.
 */
final class AuthTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    #[Test]
    public function check_false_when_not_authenticated(): void
    {
        $this->assertFalse(Auth::check());
    }

    #[Test]
    public function check_true_when_autenticato_flag_set(): void
    {
        $_SESSION['autenticato'] = true;
        $this->assertTrue(Auth::check());
    }

    #[Test]
    public function user_returns_null_when_not_checked(): void
    {
        $this->assertNull(Auth::user());
    }

    #[Test]
    public function user_returns_array_with_role_username_section(): void
    {
        $_SESSION['autenticato']            = true;
        $_SESSION['username']               = 'docente1';
        $_SESSION['user_role']              = 'teacher';
        $_SESSION['authenticated_section']  = ['ind' => 'sc', 'cls' => '2s'];
        $u = Auth::user();
        $this->assertSame('docente1', $u['username']);
        $this->assertSame('teacher', $u['role']);
        $this->assertSame(['ind' => 'sc', 'cls' => '2s'], $u['section']);
    }

    #[Test]
    public function role_default_guest(): void
    {
        $this->assertSame('guest', Auth::role());
    }

    #[Test]
    public function hasRole_matches_multiple(): void
    {
        $_SESSION['user_role'] = 'teacher';
        $this->assertTrue(Auth::hasRole('teacher', 'administrator'));
        $this->assertFalse(Auth::hasRole('student', 'administrator'));
    }

    #[Test]
    public function refresh_returns_false_without_username(): void
    {
        $this->assertFalse(Auth::refreshCurrentUserClaims());
    }

    #[Test]
    public function refresh_api_static_public(): void
    {
        $ref = new \ReflectionMethod(Auth::class, 'refreshCurrentUserClaims');
        $this->assertTrue($ref->isPublic() && $ref->isStatic());
    }
}
