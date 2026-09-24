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
        $this->assertSame('administrator', Role::ADMINISTRATOR->value);
        $this->assertSame('institute_admin', Role::INSTITUTE_ADMIN->value);
    }

    /** 15/9/2026 — il collaboratore non esiste più (migrazione 128). */
    #[Test]
    public function il_collaboratore_non_esiste_piu(): void
    {
        $this->assertNull(Role::tryFromString('collaborator'));
        $this->assertSame(
            ['student', 'teacher', 'institute_admin', 'administrator'],
            array_map(static fn(Role $r): string => $r->value, Role::cases())
        );
    }

    /**
     * ADR-040 — l'alias `admin` rispondeva ADMINISTRATOR, cioè i poteri
     * dell'amministratore della piattaforma, per il valore che il wizard degli
     * istituti scriveva ai loro amministratori. Non esiste più.
     */
    #[Test]
    public function l_alias_admin_non_esiste_piu(): void
    {
        $this->assertNull(Role::tryFromString('admin'));
        $this->assertNull(Role::tryFromString('ADMIN'));
        $this->expectException(\ValueError::class);
        Role::fromString('admin');
    }

    #[Test]
    public function l_amministratore_di_istituto_non_insegna_e_non_amministra_la_piattaforma(): void
    {
        $ruolo = Role::fromString('institute_admin');
        $this->assertSame(Role::INSTITUTE_ADMIN, $ruolo);
        $this->assertTrue($ruolo->isInstituteAdmin());
        $this->assertFalse($ruolo->isAdmin());
        $this->assertFalse($ruolo->canTeach());
        $this->assertSame('Amministratore di istituto', $ruolo->label());
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
        $this->assertSame('Amministratore', Role::ADMINISTRATOR->label());
    }
}
