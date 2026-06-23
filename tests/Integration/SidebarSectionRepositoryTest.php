<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Repositories\SidebarSectionRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * ADR-027 — resolveFor: merge globale→istituto→override docente + filtro ruolo.
 * DB-gated, transazionale (rollback in tearDown).
 */
final class SidebarSectionRepositoryTest extends TestCase
{
    private PDO $pdo;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $base = dirname(__DIR__, 2);
        if (is_file($base . '/.env')) \Dotenv\Dotenv::createMutable($base)->safeLoad();
        if (is_file($base . '/.env.local')) \Dotenv\Dotenv::createMutable($base, '.env.local')->safeLoad();
        \App\Core\Config::load($base . '/app/Config');
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1 FROM sidebar_sections LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('sidebar_sections non disponibile (migration 070?): ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();
        $this->inTx = true;
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function test_global_defaults_for_unknown_institute(): void
    {
        $rows = (new SidebarSectionRepository())->resolveFor(999999, null);
        $keys = array_map(fn($s) => $s['section_key'], $rows);
        $this->assertSame(['mappe','lab','eser','verif','bes','risdoc'], $keys, 'merge ritorna i 6 default globali ordinati');
    }

    public function test_student_role_excludes_risdoc(): void
    {
        $repo = new SidebarSectionRepository();
        $student = array_map(fn($s) => $s['section_key'], $repo->forRender(999999, null, 'student'));
        $teacher = array_map(fn($s) => $s['section_key'], $repo->forRender(999999, null, 'teacher'));
        $this->assertNotContains('risdoc', $student, 'risdoc nascosto agli studenti');
        $this->assertContains('risdoc', $teacher, 'risdoc visibile ai docenti');
    }

    public function test_institute_row_overrides_global(): void
    {
        // riga-istituto per 'mappe' con label diversa → deve vincere sul globale
        $this->pdo->prepare(
            "INSERT INTO sidebar_sections
               (institute_id, section_key, label, position, loader_kind, group_mode,
                allowed_content_types, default_content_type, visible_roles)
             VALUES (999998,'mappe','Mappe Istituto',0,'db','subject','[\"mappa\"]','mappa','[\"student\",\"teacher\",\"admin\"]')"
        )->execute();
        $rows = (new SidebarSectionRepository())->resolveFor(999998, null);
        $mappe = array_values(array_filter($rows, fn($s) => $s['section_key'] === 'mappe'))[0];
        $this->assertSame('Mappe Istituto', $mappe['label'], "la riga-istituto vince sul globale per stessa key");
        $this->assertCount(6, $rows, 'le altre 5 ereditate dal globale + 1 istituto = 6');
    }

    public function test_teacher_override_applies(): void
    {
        $repo = new SidebarSectionRepository();
        // id della riga globale 'mappe'
        $globalId = (int)$this->pdo->query(
            "SELECT id FROM sidebar_sections WHERE institute_id=0 AND section_key='mappe'"
        )->fetchColumn();
        $this->assertGreaterThan(0, $globalId);
        // serve un teacher_id valido (FK users)
        $tid = (int)$this->pdo->query("SELECT id FROM users LIMIT 1")->fetchColumn();
        if ($tid <= 0) { $this->markTestSkipped('nessun utente seedato'); }
        $this->pdo->prepare(
            'INSERT INTO sidebar_section_overrides (section_id, teacher_id, label, color)
             VALUES (?,?,?,?)'
        )->execute([$globalId, $tid, 'Mie Mappe', '#123456']);
        $rows = $repo->resolveFor(0, $tid);
        $mappe = array_values(array_filter($rows, fn($s) => $s['section_key'] === 'mappe'))[0];
        $this->assertSame('Mie Mappe', $mappe['label'], 'override docente sul label');
        $this->assertSame('#123456', $mappe['color'], 'override docente sul colore');
    }
}
