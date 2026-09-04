<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Auth;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `actor_role` nei log di audit deve dire se chi ha agito era super-admin.
 * Prima veniva da users.role: un super-admin con role='teacher' finiva a
 * registro come un docente qualunque, indistinguibile da chi quelle
 * operazioni non può nemmeno tentarle.
 */
final class AuthActorRoleTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    #[Test]
    public function super_admin_is_reported_as_such(): void
    {
        $_SESSION = [
            'autenticato'    => true,
            'username'       => 'superadmin',
            'user_role'      => 'teacher',
            'is_super_admin' => true,
        ];

        $this->assertSame('super_admin', Auth::actorRole());
        $this->assertSame('teacher', Auth::role(), 'il ruolo di base resta quello vero');
    }

    #[Test]
    public function ordinary_teacher_keeps_their_role(): void
    {
        $_SESSION = [
            'autenticato'    => true,
            'username'       => 'marco.rossi',
            'user_role'      => 'teacher',
            'is_super_admin' => false,
        ];

        $this->assertSame('teacher', Auth::actorRole());
    }

    #[Test]
    public function administrator_without_the_flag_is_not_promoted(): void
    {
        // role=administrator NON implica super-admin: sono due cose distinte.
        $_SESSION = [
            'autenticato'    => true,
            'username'       => 'admin',
            'user_role'      => 'administrator',
            'is_super_admin' => false,
        ];

        $this->assertSame('administrator', Auth::actorRole());
    }

    #[Test]
    public function unauthenticated_falls_back_to_guest_role(): void
    {
        $this->assertSame('guest', Auth::actorRole());
    }
}
