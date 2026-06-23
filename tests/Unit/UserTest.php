<?php

namespace Tests\Unit;

use App\Domain\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    #[Test]
    public function builds_from_array_with_defaults(): void
    {
        $u = User::fromArray('alice', []);

        $this->assertSame('alice', $u->username);
        $this->assertSame('student', $u->role);
        $this->assertFalse($u->active);
        $this->assertSame('', $u->passwordHash);
    }

    #[Test]
    public function role_helpers_are_mutually_exclusive(): void
    {
        $admin = User::fromArray('a', ['role' => 'administrator', 'active' => true]);
        $coll  = User::fromArray('c', ['role' => 'collaborator',  'active' => true]);
        $stud  = User::fromArray('s', ['role' => 'student',       'active' => true]);

        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($admin->isStudent());
        $this->assertTrue($coll->isCollaborator());
        $this->assertTrue($stud->isStudent());
    }

    #[Test]
    public function admin_and_collaborator_can_access_any_section(): void
    {
        $admin = User::fromArray('a', ['role' => 'administrator', 'active' => true]);
        $coll  = User::fromArray('c', ['role' => 'collaborator',  'active' => true]);

        $this->assertTrue($admin->canAccessSection('ar5s'));
        $this->assertTrue($coll->canAccessSection('sc2s'));
    }

    #[Test]
    public function student_can_access_only_own_course(): void
    {
        $s = User::fromArray('s', ['role' => 'student', 'active' => true, 'course' => 'ar5s']);

        $this->assertTrue($s->canAccessSection('ar5s'));
        $this->assertFalse($s->canAccessSection('sc5s'));
        $this->assertFalse($s->canAccessSection(''));
    }

    #[Test]
    public function verify_password_rejects_empty_hash(): void
    {
        $u = User::fromArray('x', ['role' => 'student', 'active' => true]);
        $this->assertFalse($u->verifyPassword('anything'));
    }

    #[Test]
    public function verify_password_matches_bcrypt(): void
    {
        $hash = password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]);
        $u    = User::fromArray('x', ['password_hash' => $hash, 'active' => true]);

        $this->assertTrue($u->verifyPassword('secret'));
        $this->assertFalse($u->verifyPassword('wrong'));
    }
}
