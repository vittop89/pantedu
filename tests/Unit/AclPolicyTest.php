<?php

namespace Tests\Unit;

use App\Services\AclPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 19 — AclPolicy test (no DB). Testiamo i pure method:
 * canReadStudentsOfTeacher. (canReadMaterialOfTeacher + shareInstitute rimossi:
 * erano codice morto, sostituiti da SharedContentPolicy::canReadContent.)
 */
final class AclPolicyTest extends TestCase
{
    #[Test]
    public function students_of_teacher_only_visible_to_owner(): void
    {
        $this->assertTrue(
            AclPolicy::canReadStudentsOfTeacher(actorTeacherId: 77, ownerTeacherId: 77)
        );
        $this->assertFalse(
            AclPolicy::canReadStudentsOfTeacher(actorTeacherId: 77, ownerTeacherId: 99)
        );
    }

    #[Test]
    public function students_denied_with_zero_ids(): void
    {
        $this->assertFalse(AclPolicy::canReadStudentsOfTeacher(0, 77));
        $this->assertFalse(AclPolicy::canReadStudentsOfTeacher(77, 0));
    }
}
