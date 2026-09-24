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
        // allow: owner OR any all-scope role (administrator|teacher).
        // NOTE: per riga 954, $canSeeAll è role-based → il PREDICATO lascia passare
        // un ALTRO teacher. Dal 2026-09-13 il controller del dettaglio aggiunge
        // l'ACL delle condivisioni (passesAcl), come l'elenco: la prova del
        // comportamento dell'endpoint è Tests\Integration\DettaglioPerIdTest.
        $this->assertTrue($this->policy->canReadSingle($this->row('draft'), $this->otherTeacher()));
        $this->assertTrue($this->policy->canReadSingle($this->row('draft'), $this->ownerTeacher()));
        $this->assertTrue($this->policy->canReadSingle($this->row('draft'), $this->admin()));
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
        // ADR-037, fase 1 (2026-09-13) — chi vede tutti gli scope non ha vincoli
        // di classe, di stato o di sezione, ma con un istituto nel contesto
        // naviga in quella scuola: i contenuti la cui pubblicazione principale
        // sta li' (piu' i propri senza scuola).
        foreach (['teacher' => $this->ownerTeacher(), 'admin' => $this->admin()] as $name => $ctx) {
            $f = $this->policy->studyListFilters($ctx, [3, 4]);
            $this->assertSame(
                ['pub_institute_id' => 1, 'pub_actor_id' => $ctx->teacherId],
                $f,
                "{$name}: solo la scuola del contesto"
            );
        }
    }

    #[Test]
    public function studyListFilters_chi_vede_tutto_senza_istituto_non_ha_vincoli(): void
    {
        // Il super-amministratore arriva senza istituto: vede tutto, come prima.
        $super = new ViewerContext(role: Role::ADMINISTRATOR, teacherId: 5);
        $this->assertSame([], $this->policy->studyListFilters($super, [3, 4]));
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

    // ───────── l'anno copre le sezioni (piano classi-credenziali-scenari, A) ─────────

    #[Test]
    public function studyListFilters_student_su_un_anno_vede_solo_quell_anno(): void
    {
        $f = $this->policy->studyListFilters($this->student(), []);
        $this->assertSame('2', $f['classe']);
        $this->assertSame(['2'], $f['classi']);
    }

    #[Test]
    public function studyListFilters_student_su_una_sezione_vede_anche_l_anno(): void
    {
        $f = $this->policy->studyListFilters(ViewerContext::forStudent(7, 1, 'sc', '3A'), []);
        $this->assertSame('3A', $f['classe'], 'la classe esatta resta per chi la legge ancora');
        $this->assertSame(['3A', '3'], $f['classi']);
        $this->assertTrue($f['student_scope']);
    }

    #[Test]
    public function studyListFilters_senza_classe_nessun_insieme(): void
    {
        $f = $this->policy->studyListFilters(ViewerContext::forStudent(7, 1, 'sc', null), []);
        $this->assertArrayNotHasKey('classe', $f);
        $this->assertArrayNotHasKey('classi', $f);
    }

    // ───────── classi frequentate (piano classi-credenziali-scenari, D) ─────────

    #[Test]
    public function studyListFilters_un_anno_precedente_chiesto_apre_l_archivio(): void
    {
        $f = $this->policy->studyListFilters(ViewerContext::forStudent(7, 1, 'sc', '3A'), [], '2');
        $this->assertSame('2', $f['classe']);
        $this->assertSame(['2'], $f['classi'], 'al livello di anno: nessuna sezione a caso');
        $this->assertTrue($f['archivio']);
        $this->assertSame('published', $f['visibility']);
    }

    #[Test]
    public function studyListFilters_la_sezione_passata_nota_rende_l_archivio_preciso(): void
    {
        $f = $this->policy->studyListFilters(ViewerContext::forStudent(7, 1, 'sc', '3A', ['2B']), [], '2');
        $this->assertSame('2B', $f['classe']);
        $this->assertSame(['2B', '2'], $f['classi']);
        $this->assertTrue($f['archivio']);
    }

    #[Test]
    public function studyListFilters_la_propria_classe_chiesta_non_e_archivio(): void
    {
        $f = $this->policy->studyListFilters(ViewerContext::forStudent(7, 1, 'sc', '3A'), [], '3A');
        $this->assertSame('3A', $f['classe']);
        $this->assertSame(['3A', '3'], $f['classi']);
        $this->assertArrayNotHasKey('archivio', $f);
    }

    #[Test]
    public function studyListFilters_una_richiesta_non_ammessa_ricade_sulla_propria_classe(): void
    {
        foreach (['4', '3B', '2B', 'XYZ'] as $richiesta) {
            $f = $this->policy->studyListFilters(ViewerContext::forStudent(7, 1, 'sc', '3A'), [], $richiesta);
            $this->assertSame('3A', $f['classe'], "richiesta «{$richiesta}»");
            $this->assertArrayNotHasKey('archivio', $f, "richiesta «{$richiesta}»");
        }
    }

    #[Test]
    public function studyListFilters_chi_vede_tutto_ignora_la_richiesta(): void
    {
        // La classe chiesta non diventa un vincolo: resta solo la scuola.
        $this->assertSame(
            ['pub_institute_id' => 1, 'pub_actor_id' => self::OWNER_ID],
            $this->policy->studyListFilters($this->ownerTeacher(), [], '2')
        );
    }

    // ───────── portachiavi: un perimetro per credenziale (piano classi, C) ─────────

    private function keychain(): ViewerContext
    {
        return ViewerContext::forKeychain([
            ['teacher_id' => 11, 'indirizzo' => 'sc', 'classe' => '3A', 'credential_id' => 1, 'label' => 'Rossi 3A'],
            ['teacher_id' => 12, 'indirizzo' => 'sc', 'classe' => '3',  'credential_id' => 2, 'label' => 'Verdi terza'],
            ['teacher_id' => 13, 'indirizzo' => null, 'classe' => null, 'credential_id' => 3, 'label' => 'Bianchi tutto'],
        ]);
    }

    #[Test]
    public function keychain_senza_richiesta_ogni_credenziale_vale_per_la_propria_classe(): void
    {
        $ctx = $this->keychain();
        $this->assertSame('3A', $ctx->classe, 'lo scope della sidebar e\' la prima credenziale delimitata');
        $f = $this->policy->studyListFilters($ctx, []);
        $this->assertTrue($f['student_scope']);
        $this->assertSame([
            ['teacher_id' => 11, 'indirizzo' => 'sc', 'classi' => ['3A', '3'], 'archivio' => false, 'institute_id' => null],
            ['teacher_id' => 12, 'indirizzo' => 'sc', 'classi' => ['3'],       'archivio' => false, 'institute_id' => null],
            ['teacher_id' => 13, 'indirizzo' => null, 'classi' => [],          'archivio' => false, 'institute_id' => null],
        ], $f['grants']);
    }

    #[Test]
    public function keychain_la_scuola_della_credenziale_arriva_al_perimetro(): void
    {
        // ADR-037, fase 1 — una credenziale con la scuola porta la scuola: il
        // repository cerca le pubblicazioni li', non in tutte le scuole del
        // docente. Senza scuola (credenziali create prima del 13/9) resta null.
        $ctx = ViewerContext::forKeychain([
            ['teacher_id' => 11, 'indirizzo' => 'sc', 'classe' => '3A', 'institute_id' => 106, 'credential_id' => 1, 'label' => 'Rossi 3A'],
            ['teacher_id' => 12, 'indirizzo' => 'sc', 'classe' => '3',  'institute_id' => null, 'credential_id' => 2, 'label' => 'Verdi terza'],
        ]);
        $f = $this->policy->studyListFilters($ctx, []);
        $this->assertSame(106, $f['grants'][0]['institute_id']);
        $this->assertNull($f['grants'][1]['institute_id']);
    }

    #[Test]
    public function keychain_guardando_la_sezione_la_credenziale_di_anno_da_solo_l_anno(): void
    {
        $f = $this->policy->studyListFilters($this->keychain(), [], '3A', 'sc');
        $this->assertSame(['3A', '3'], $f['grants'][0]['classi'], 'Rossi: la sua sezione');
        $this->assertSame(['3'], $f['grants'][1]['classi'], 'Verdi autorizza l\'anno, non la sezione');
        $this->assertSame(['3A', '3'], $f['grants'][2]['classi'], 'Bianchi non delimitata: segue la classe chiesta');
        $this->assertSame('sc', $f['grants'][2]['indirizzo']);
    }

    #[Test]
    public function keychain_un_anno_precedente_apre_l_archivio_per_ogni_credenziale(): void
    {
        $f = $this->policy->studyListFilters($this->keychain(), [], '2', 'sc');
        foreach ([0, 1] as $i) {
            $this->assertSame(['2'], $f['grants'][$i]['classi']);
            $this->assertTrue($f['grants'][$i]['archivio']);
        }
        $this->assertFalse($f['grants'][2]['archivio'], 'la non delimitata non ha un anno da cui derivare l\'archivio');
    }

    #[Test]
    public function keychain_una_classe_fuori_perimetro_esclude_la_credenziale(): void
    {
        $f = $this->policy->studyListFilters($this->keychain(), [], '3B', 'sc');
        $ids = array_column($f['grants'], 'teacher_id');
        $this->assertNotContains(11, $ids, 'Rossi e\' della 3A');
        $this->assertContains(12, $ids, 'Verdi copre tutta la terza');
        $this->assertContains(13, $ids);
        $f4 = $this->policy->studyListFilters($this->keychain(), [], '4', 'sc');
        $this->assertSame([13], array_column($f4['grants'], 'teacher_id'), 'anno futuro: solo la non delimitata');
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
        ];
    }
}
