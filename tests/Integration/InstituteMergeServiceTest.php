<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Repositories\InstituteRepository;
use App\Services\InstituteMergeService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Merge/deduplicazione istituti — verifica che ogni FK institute_id venga
 * ri-puntata al canonico e che il merge di curriculum_entries sia LOSSLESS
 * (i referrer dei contenuti — teacher_content_data.subject_id ecc. — vengono
 * ri-mappati al gemello canonico, NON azzerati). Vedi [[project_institute_dedup]].
 *
 * DB-gated: skip se il DB non è disponibile. Tutto in transazione → rollback
 * in tearDown, nessuna scoria nel DB.
 */
final class InstituteMergeServiceTest extends TestCase
{
    private PDO $pdo;
    private int $teacherId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $basePath = dirname(__DIR__, 2);
        if (is_file($basePath . '/.env')) {
            \Dotenv\Dotenv::createMutable($basePath)->safeLoad();
        }
        if (is_file($basePath . '/.env.local')) {
            \Dotenv\Dotenv::createMutable($basePath, '.env.local')->safeLoad();
        }
        \App\Core\Config::load($basePath . '/app/Config');

        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB connection failed: ' . $e->getMessage());
        }
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE username=? LIMIT 1');
        $stmt->execute(['superadmin']);
        $this->teacherId = (int)($stmt->fetchColumn() ?: 0);
        if ($this->teacherId === 0) {
            $this->markTestSkipped('superadmin user not seeded');
        }

        $this->pdo->beginTransaction();
        $this->inTx = true;
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /** Inserisce un istituto e ritorna l'id. */
    private function mkInstitute(string $code, string $name = 'ZZ TEST SCHOOL', string $city = 'ZZCITY'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$code, $name, $city]);
        return (int)$this->pdo->lastInsertId();
    }

    /** Inserisce una riga curriculum (owner NULL = anchor) e ritorna l'id. */
    private function mkCurriculum(int $instituteId, string $kind, string $code): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO curriculum_entries (kind, code, label, active, institute_id, owner_user_id, shared_with_pool)
             VALUES (?, ?, ?, 1, ?, NULL, 0)'
        );
        $stmt->execute([$kind, $code, "Label $code", $instituteId]);
        return (int)$this->pdo->lastInsertId();
    }

    public function test_merge_repoints_user_and_deletes_duplicate(): void
    {
        $canon = $this->mkInstitute('ZZCANON01A');
        $dup   = $this->mkInstitute('ZZ-SYNTH-DUP');

        // sposto temporaneamente lo studente di test sul duplicato
        $this->pdo->prepare('UPDATE users SET institute_id=? WHERE id=?')
                  ->execute([$dup, $this->teacherId]);

        (new InstituteMergeService($this->pdo))->merge($canon, $dup);

        // user ri-puntato
        $stmt = $this->pdo->prepare('SELECT institute_id FROM users WHERE id=?');
        $stmt->execute([$this->teacherId]);
        $this->assertSame($canon, (int)$stmt->fetchColumn());

        // duplicato eliminato
        $this->assertNull((new InstituteRepository())->findById($dup));
    }

    public function test_merge_curriculum_is_lossless_remaps_content_referrer(): void
    {
        $canon = $this->mkInstitute('ZZCANON02A');
        $dup   = $this->mkInstitute('ZZ-SYNTH-DUP2');

        // gemelli collidenti: stessa (kind, code, owner_key=0) su canonico e duplicato
        $canonRow = $this->mkCurriculum($canon, 'materie', 'ZZT');
        $dupRow   = $this->mkCurriculum($dup,   'materie', 'ZZT');
        // riga NON collidente sul duplicato (code unico)
        $dupOnly  = $this->mkCurriculum($dup,   'materie', 'ZZUNIQUE');

        // un contenuto del docente punta alla riga collidente del duplicato
        $this->pdo->prepare(
            'INSERT INTO teacher_content_data (teacher_id, content_subtype, title, subject_id)
             VALUES (?, ?, ?, ?)'
        )->execute([$this->teacherId, 'document', 'zz merge test', $dupRow]);
        $contentId = (int)$this->pdo->lastInsertId();

        (new InstituteMergeService($this->pdo))->merge($canon, $dup);

        // la riga collidente del duplicato è stata eliminata...
        $stmt = $this->pdo->prepare('SELECT COUNT(1) FROM curriculum_entries WHERE id=?');
        $stmt->execute([$dupRow]);
        $this->assertSame(0, (int)$stmt->fetchColumn(), 'riga collidente eliminata');

        // ...ma il contenuto è stato RI-MAPPATO al gemello canonico (NON azzerato)
        $stmt = $this->pdo->prepare('SELECT subject_id FROM teacher_content_data WHERE id=?');
        $stmt->execute([$contentId]);
        $this->assertSame($canonRow, (int)$stmt->fetchColumn(), 'referrer ri-mappato al canonico, lossless');

        // la riga non collidente è stata spostata all'istituto canonico
        $stmt = $this->pdo->prepare('SELECT institute_id FROM curriculum_entries WHERE id=?');
        $stmt->execute([$dupOnly]);
        $this->assertSame($canon, (int)$stmt->fetchColumn(), 'riga unica spostata al canonico');
    }

    public function test_plan_groups_flags_synthetic_plus_real_as_safe_with_adopt_code(): void
    {
        $name = 'ZZ PLAN SCHOOL';
        $city = 'ZZPLANCITY';
        $this->mkInstitute('ZZ-PLAN-SYNTH', $name, $city);   // sintetico
        $this->mkInstitute('ZZ9876543Z',    $name, $city);   // MIUR reale (2+8)

        $plan = (new InstituteMergeService($this->pdo))->planGroups();
        $key = InstituteRepository::dedupKey($name, $city);

        $group = null;
        foreach ($plan as $g) {
            if ($g['key'] === $key) { $group = $g; break; }
        }
        $this->assertNotNull($group, 'il gruppo duplicato deve essere rilevato');
        $this->assertTrue($group['safe'], 'un solo code MIUR reale → safe');
        $this->assertSame('ZZ9876543Z', $group['adopt_code'], 'adotta il code MIUR reale');
        $this->assertCount(1, $group['duplicates']);
    }
}
