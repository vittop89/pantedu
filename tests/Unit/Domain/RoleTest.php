<?php

namespace Tests\Unit\Domain;

use App\Domain\Role;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RoleTest extends TestCase
{
    #[Test]
    public function all_roles_exist(): void
    {
        $this->assertSame('student',       Role::STUDENT->value);
        $this->assertSame('teacher',       Role::TEACHER->value);
        $this->assertSame('collaborator',  Role::COLLABORATOR->value);
        $this->assertSame('administrator', Role::ADMINISTRATOR->value);
    }

    #[Test]
    public function from_string_maps_admin_alias(): void
    {
        $this->assertSame(Role::ADMINISTRATOR, Role::fromString('admin'));
        $this->assertSame(Role::ADMINISTRATOR, Role::fromString('ADMIN'));
    }

    #[Test]
    public function tryFromString_returns_null_on_invalid(): void
    {
        $this->assertNull(Role::tryFromString(null));
        $this->assertNull(Role::tryFromString(''));
        $this->assertNull(Role::tryFromString('pluto'));
        $this->assertSame(Role::TEACHER, Role::tryFromString('teacher'));
    }

    #[Test]
    public function role_predicates(): void
    {
        $this->assertTrue(Role::ADMINISTRATOR->isAdmin());
        $this->assertFalse(Role::TEACHER->isAdmin());
        $this->assertTrue(Role::TEACHER->canTeach());
        $this->assertTrue(Role::ADMINISTRATOR->canTeach());
        $this->assertFalse(Role::STUDENT->canTeach());
    }

    #[Test]
    public function labels_are_human_readable(): void
    {
        $this->assertSame('Studente',       Role::STUDENT->label());
        $this->assertSame('Docente',        Role::TEACHER->label());
        $this->assertSame('Collaboratore',  Role::COLLABORATOR->label());
        $this->assertSame('Amministratore', Role::ADMINISTRATOR->label());
    }
}
