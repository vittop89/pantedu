<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\ContentVisibility;
use App\Domain\ContentVisibilityPolicy;
use App\Domain\Role;
use App\Domain\ViewerContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pilota #1 — golden unit test del gate unico ContentVisibilityPolicy.
 * Tutto puro/in-memory: nessun DB, nessuna SESSION.
 */
final class ContentVisibilityPolicyTest extends TestCase
{
    private ContentVisibilityPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new ContentVisibilityPolicy();
    }

    // ───────── viewer fixtures ─────────

    private const OWNER_ID = 42;
    private const OTHER_ID = 99;

    private function guest(): ViewerContext
    {
        return ViewerContext::guest();
    }

    private function student(): ViewerContext
    {
        return ViewerContext::forStudent(7, 1, 'sc', '2');
    }

    private function ownerTeacher(): ViewerContext
    {
        return ViewerContext::forTeacher(self::OWNER_ID, 1);
    }

    private function otherTeacher(): ViewerContext
    {
        return ViewerContext::forTeacher(self::OTHER_ID, 1);
    }

    private function admin(): ViewerContext
    {
        return new ViewerContext(role: Role::ADMINISTRATOR, teacherId: 5, instituteId: 1);
    }

    private function collaborator(): ViewerContext
    {
        return new ViewerContext(role: Role::COLLABORATOR, teacherId: 6, instituteId: 1);
    }

    /** @return array<string,mixed> */
    private function row(string $vis, int $owner = self::OWNER_ID): array
    {
        return ['id' => 100, 'teacher_id' => $owner, 'visibility' => $vis, 'section_id' => 0];
    }

    // ───────── (A) state gate: delega, non duplica ─────────

    #[Test]
    public function isVisibleToStudents_delegates_to_enum(): void
    {
        foreach (ContentVisibility::cases() as $v) {
            $this->assertSame(
                $v->isVisibleToStudents(),
                $this->policy->isVisibleToStudents($v),
                "delegation mismatch for {$v->value}"
            );
        }
        $this->assertTrue($this->policy->isVisibleToStudents(ContentVisibility::PUBLISHED));
        $this->assertFalse($this->policy->isVisibleToStudents(ContentVisibility::DRAFT));
        $this->assertFalse($this->policy->isVisibleToStudents(ContentVisibility::ARCHIVED));
    }

    // ───────── (B) canReadSingle — matrix mirror riga 954 ─────────

    #[Test]
    public function canReadSingle_published_visible_to_everyone(): void
    {
        foreach ($this->allViewers() as $name => $ctx) {
            $this->assertTrue(
                $this->policy->canReadSingle($this->row('published'), $ctx),
                "published should be readable by {$name}"
            );
        }
    }

    #[Test]
    public function canReadSingle_draft_only_owner_or_all_scope(): void
    {
        // deny: guest + student (no canSeeAll, not owner)
        $this->assertFalse($this->policy->canReadSingle($this->row('draft'), $this->guest()));
        $this->assertFalse($this->policy->canReadSingle($this->row('draft'), $this->student()));
        // allow: owner OR any all-scope role (administrator|teacher|collaborator).
        // NOTE: per riga 954, $canSeeAll è role-based → un ALTRO teacher vede i draft
        // altrui dal single endpoint (comportamento corrente, preservato).
        $this->assertTrue($this->policy->canReadSingle($this->row('draft'), $this->otherTeacher()));
        $this->assertTrue($this->policy->canReadSingle($this->row('draft'), $this->ownerTeacher()));
        $this->assertTrue($this->policy->canReadSingle($this->row('draft'), $this->admin()));
        $this->assertTrue($this->policy->canReadSingle($this->row('draft'), $this->collaborator()));
    }

    #[Test]
    public function canReadSingle_archived_owner_can(): void
    {
        // owner CAN see own archived in single endpoint (asimmetria vs related).
        $this->assertTrue($this->policy->canReadSingle($this->row('archived'), $this->ownerTeacher()));
        $this->assertFalse($this->policy->canReadSingle($this->row('archived'), $this->student()));
        $this->assertTrue($this->policy->canReadSingle($this->row('archived'), $this->admin()));
    }

    #[Test]
    public function canReadSingle_invalid_visibility_treated_as_not_published(): void
    {
        $row = ['id' => 1, 'teacher_id' => self::OWNER_ID, 'visibility' => 'garbage'];
        $this->assertFalse($this->policy->canReadSingle($row, $this->student()));
        $this->assertTrue($this->policy->canReadSingle($row, $this->ownerTeacher()));
        // missing key entirely → null → not published
        $rowNoVis = ['id' => 1, 'teacher_id' => self::OWNER_ID];
        $this->assertFalse($this->policy->canReadSingle($rowNoVis, $this->student()));
    }

    // ───────── (B) canReadRelatedVerifica — asimmetria su archived ─────────

    #[Test]
    public function canReadRelatedVerifica_published_visible_to_all(): void
    {
        foreach ($this->allViewers() as $name => $ctx) {
            $this->assertTrue(
                $this->policy->canReadRelatedVerifica($this->row('published'), $ctx),
                "published related verifica should be readable by {$name}"
            );
        }
    }

    #[Test]
    public function canReadRelatedVerifica_draft_only_owner(): void
    {
        $this->assertTrue($this->policy->canReadRelatedVerifica($this->row('draft'), $this->ownerTeacher()));
        $this->assertFalse($this->policy->canReadRelatedVerifica($this->row('draft'), $this->student()));
        $this->assertFalse($this->policy->canReadRelatedVerifica($this->row('draft'), $this->otherTeacher()));
        // canSeeAll non-owner admin does NOT get a non-published related verifica
        // (related rule does not have a canSeeAll branch — owner-only).
        $this->assertFalse($this->policy->canReadRelatedVerifica($this->row('draft'), $this->admin()));
    }

    #[Test]
    public function canReadRelatedVerifica_owner_archived_is_FALSE_locked_asymmetry(): void
    {
        // LOCKS the divergence vs canReadSingle: owner + archived → FALSE here.
        $this->assertFalse(
            $this->policy->canReadRelatedVerifica($this->row('archived'), $this->ownerTeacher())
        );
        // contrast: single endpoint allows it
        $this->assertTrue(
            $this->policy->canReadSingle($this->row('archived'), $this->ownerTeacher())
        );
    }

    // ───────── (C) studyListFilters ─────────

    #[Test]
    public function studyListFilters_all_scope_no_constraints(): void
    {
        foreach (['teacher' => $this->ownerTeacher(), 'admin' => $this->admin(), 'collab' => $this->collaborator()] as $name => $ctx) {
            $f = $this->policy->studyListFilters($ctx, [3, 4]);
            $this->assertSame([], $f, "{$name} should get no constraints");
        }
    }

    #[Test]
    public function studyListFilters_student_published_scope_and_hidden(): void
    {
        $f = $this->policy->studyListFilters($this->student(), [3, 5]);
        $this->assertSame('published', $f['visibility']);
        $this->assertTrue($f['student_scope']);
        $this->assertSame('sc', $f['indirizzo']);
        $this->assertSame('2', $f['classe']);
        $this->assertSame([3, 5], $f['section_id_not_in']);
    }

    #[Test]
    public function studyListFilters_student_empty_hidden_omits_key(): void
    {
        $f = $this->policy->studyListFilters($this->student(), []);
        $this->assertArrayNotHasKey('section_id_not_in', $f);
        $this->assertSame('published', $f['visibility']);
    }

    #[Test]
    public function studyListFilters_guest_deny_constraint(): void
    {
        $f = $this->policy->studyListFilters($this->guest(), []);
        // parità ExerciseAccessPolicy guest: indirizzo='__deny__' → repo vuoto.
        $this->assertSame('__deny__', $f['indirizzo']);
        $this->assertArrayNotHasKey('visibility', $f);
        $this->assertArrayNotHasKey('student_scope', $f);
    }

    // ───────── (D) section gate ─────────

    #[Test]
    public function canSeeSection_student_needs_student_role(): void
    {
        $this->assertTrue($this->policy->canSeeSection(['student', 'teacher'], $this->student()));
        $this->assertFalse($this->policy->canSeeSection(['teacher'], $this->student()));
        // teacher/admin always allowed regardless of visible_roles
        $this->assertTrue($this->policy->canSeeSection(['teacher'], $this->ownerTeacher()));
        $this->assertTrue($this->policy->canSeeSection([], $this->admin()));
    }

    #[Test]
    public function hiddenSectionIds_returns_only_student_excluded(): void
    {
        $sections = [
            10 => ['student', 'teacher'],
            11 => ['teacher'],
            12 => ['teacher', 'administrator'],
            13 => ['student'],
        ];
        $this->assertSame([11, 12], $this->policy->hiddenSectionIds($sections, $this->student()));
        // all-scope viewer → nothing hidden
        $this->assertSame([], $this->policy->hiddenSectionIds($sections, $this->ownerTeacher()));
        // empty input → empty (try/catch fallback parity)
        $this->assertSame([], $this->policy->hiddenSectionIds([], $this->student()));
    }

    // ───────── (E) ACL delegation ─────────

    #[Test]
    public function filterByAcl_guest_and_nonteacher_pass_through(): void
    {
        $rows = [$this->row('published'), $this->row('draft')];
        $called = false;
        $reader = function () use (&$called): bool {
            $called = true;
            return false;
        };
        // guest (teacherId=0)
        $this->assertSame($rows, $this->policy->filterByAcl($rows, $this->guest(), $reader));
        // student (non-teacher)
        $this->assertSame($rows, $this->policy->filterByAcl($rows, $this->student(), $reader));
        // admin (non-teacher role) → pass-through too
        $this->assertSame($rows, $this->policy->filterByAcl($rows, $this->admin(), $reader));
        $this->assertFalse($called, 'aclReader must NOT be called for guest/non-teacher');
    }

    #[Test]
    public function filterByAcl_teacher_keeps_only_reader_true(): void
    {
        $rowA = ['id' => 1, 'teacher_id' => 10, 'visibility' => 'published', 'shared_with_pool' => 1];
        $rowB = ['id' => 2, 'teacher_id' => 20, 'visibility' => 'published', 'shared_with_pool' => 0];
        $reader = fn(int $owner, int $cid, bool $pool): bool => $cid === 1;
        $out = $this->policy->filterByAcl([$rowA, $rowB], $this->otherTeacher(), $reader);
        $this->assertSame([$rowA], $out);
    }

    #[Test]
    public function passesAcl_reader_receives_owner_id_pool(): void
    {
        $row = ['id' => 7, 'teacher_id' => 33, 'shared_with_pool' => 1];
        $seen = [];
        $reader = function (int $owner, int $cid, bool $pool) use (&$seen): bool {
            $seen = [$owner, $cid, $pool];
            return true;
        };
        $this->assertTrue($this->policy->passesAcl($row, $this->otherTeacher(), $reader));
        $this->assertSame([33, 7, true], $seen);
    }

    // ───────── (F) export / ownership ─────────

    #[Test]
    public function canExportOwn_owner_or_superadmin(): void
    {
        $this->assertTrue($this->policy->canExportOwn(self::OWNER_ID, $this->ownerTeacher(), false));
        $this->assertTrue($this->policy->canExportOwn(self::OWNER_ID, $this->otherTeacher(), true)); // super-admin
        $this->assertFalse($this->policy->canExportOwn(self::OWNER_ID, $this->otherTeacher(), false));
        // guest never
        $this->assertFalse($this->policy->canExportOwn(self::OWNER_ID, $this->guest(), false));
    }

    #[Test]
    public function canReadOwnDetail_owner_only(): void
    {
        $this->assertTrue($this->policy->canReadOwnDetail(self::OWNER_ID, $this->ownerTeacher()));
        $this->assertFalse($this->policy->canReadOwnDetail(self::OWNER_ID, $this->otherTeacher()));
        $this->assertFalse($this->policy->canReadOwnDetail(self::OWNER_ID, $this->admin())); // super-admin NOT special here
        $this->assertFalse($this->policy->canReadOwnDetail(self::OWNER_ID, $this->guest()));
    }

    // ───────── core GDPR student-safety invariants ─────────

    #[Test]
    public function student_never_sees_draft_or_archived_anywhere(): void
    {
        $student = $this->student();
        foreach (['draft', 'archived'] as $vis) {
            $this->assertFalse($this->policy->canReadSingle($this->row($vis), $student));
            $this->assertFalse($this->policy->canReadRelatedVerifica($this->row($vis), $student));
        }
        // and the list filter forces published-only
        $this->assertSame('published', $this->policy->studyListFilters($student, [])['visibility']);
    }

    /** @return array<string,ViewerContext> */
    private function allViewers(): array
    {
        return [
            'guest'        => $this->guest(),
            'student'      => $this->student(),
            'owner'        => $this->ownerTeacher(),
            'otherTeacher' => $this->otherTeacher(),
            'admin'        => $this->admin(),
            'collaborator' => $this->collaborator(),
        ];
    }
}
