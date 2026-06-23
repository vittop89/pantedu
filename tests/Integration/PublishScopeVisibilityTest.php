<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Repositories\TeacherContentRepository;
use PHPUnit\Framework\TestCase;

/**
 * Migration 069 — sicurezza del matching scope-aware studente.
 *
 * Verifica che TeacherContentRepository::search con student_scope=true espona
 * allo studente di una (indirizzo,classe) ESATTAMENTE i documenti dovuti:
 *   - publish_scope='class'    → solo la propria (indirizzo,classe);
 *   - publish_scope='general'  → tutti, MA solo nella stessa materia;
 *   - publish_scope='classes'  → solo se la coppia è tra i content_target_classes.
 *
 * Test negativi inclusi: lo studente di una classe NON-target NON deve vedere
 * un documento 'classes'/'class' altrui, né un 'general' di altra materia.
 */
final class PublishScopeVisibilityTest extends TestCase
{
    private TeacherContentRepository $repo;
    private int $teacherId = 0;
    private array $createdIds = [];
    /** Codici DINAMICI letti dal DB (no legacy hardcoded). */
    private string $indA = '';
    private string $indB = '';
    private string $clsA = '';
    private string $clsB = '';
    private string $subjA = '';
    private string $subjB = '';

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
            Database::connection()->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB connection failed: ' . $e->getMessage());
        }
        $stmt = Database::connection()->prepare('SELECT id FROM users WHERE username=? LIMIT 1');
        $stmt->execute(['superadmin']);
        $id = (int)($stmt->fetchColumn() ?: 0);
        if ($id === 0) {
            $this->markTestSkipped('superadmin user not seeded');
        }
        $this->teacherId = $id;
        $this->repo = new TeacherContentRepository();

        // Codici DINAMICI dal catalogo del docente (NON legacy hardcoded):
        // servono almeno 2 indirizzi, 2 classi, 2 materie risolvibili.
        $db = Database::connection();
        $pick = static function (string $kind) use ($db, $id): array {
            $st = $db->prepare(
                'SELECT DISTINCT code FROM curriculum_entries
                  WHERE kind = ? AND (owner_user_id = ? OR owner_user_id IS NULL)
                  ORDER BY code LIMIT 2'
            );
            $st->execute([$kind, $id]);
            return $st->fetchAll(\PDO::FETCH_COLUMN);
        };
        $inds = $pick('indirizzi');
        $clss = $pick('classi');
        $subs = $pick('materie');
        if (count($inds) < 2 || count($clss) < 2 || count($subs) < 2) {
            $this->markTestSkipped('catalogo insufficiente (servono ≥2 indirizzi/classi/materie)');
        }
        [$this->indA, $this->indB] = $inds;
        [$this->clsA, $this->clsB] = $clss;
        [$this->subjA, $this->subjB] = $subs;
    }

    protected function tearDown(): void
    {
        if ($this->createdIds) {
            $in = implode(',', array_map('intval', $this->createdIds));
            // DELETE sulla tabella BASE → cascade pulisce content_target_classes.
            Database::connection()->exec("DELETE FROM teacher_content_data WHERE id IN ($in)");
        }
    }

    /**
     * Crea un teacher_content published e ritorna [id, indirizzo, classe]
     * con i CODICI realmente risolti dal repo (es. 'sc'→'SCI'), così le
     * asserzioni usano gli stessi codici che vedrebbe la query studente.
     */
    private function seed(string $subj, string $ind, string $cls, string $scope): array
    {
        $id = $this->repo->create([
            'teacher_id'   => $this->teacherId,
            'content_type' => 'esercizio',
            'subject_code' => $subj,
            'indirizzo'    => $ind,
            'classe'       => $cls,
            'topic'        => 'SCOPE_' . uniqid(),
            'title'        => "Scope $scope $subj $ind $cls",
            'body_html'    => '<p>x</p>',
            'visibility'   => 'published',
        ]);
        $this->createdIds[] = $id;
        if ($scope !== 'class') {
            Database::connection()
                ->prepare('UPDATE teacher_content_data SET publish_scope=? WHERE id=?')
                ->execute([$scope, $id]);
        }
        $row = Database::connection()
            ->query("SELECT indirizzo, classe FROM teacher_content WHERE id=$id")
            ->fetch(\PDO::FETCH_ASSOC);
        return [$id, (string)$row['indirizzo'], (string)$row['classe']];
    }

    private function addTarget(int $contentId, string $ind, string $cls): void
    {
        Database::connection()
            ->prepare('INSERT INTO content_target_classes (content_id, indirizzo, classe) VALUES (?,?,?)')
            ->execute([$contentId, $ind, $cls]);
    }

    /** Esegue la search come la vedrebbe uno studente di (ind,cls,subj). */
    private function studentSees(string $subj, string $ind, string $cls): array
    {
        $rows = $this->repo->search([
            'content_type' => 'esercizio',
            'subject_code' => $subj,
            'indirizzo'    => $ind,
            'classe'       => $cls,
            'visibility'   => 'published',
            'student_scope' => true,
            'limit'        => 500,
        ]);
        return array_map(static fn($r) => (int)$r['id'], $rows);
    }

    // Tutti i codici provengono dal catalogo dinamico del docente (setUp):
    // nessun literal legacy. seed() rilegge i codici risolti dalla view.

    public function testClassScopeOnlyOwnSection(): void
    {
        [$id, $ind, $cls] = $this->seed($this->subjA, $this->indA, $this->clsA, 'class');
        $this->assertContains($id, $this->studentSees($this->subjA, $ind, $cls), 'propria classe vede');
        $this->assertNotContains($id, $this->studentSees($this->subjA, $ind, $this->clsB), 'altra classe NON vede');
    }

    public function testGeneralVisibleAllSameSubjectOnly(): void
    {
        [$id, $ind, $cls] = $this->seed($this->subjA, $this->indA, $this->clsA, 'general');
        $this->assertContains($id, $this->studentSees($this->subjA, $ind, $cls), 'stessa classe vede');
        $this->assertContains($id, $this->studentSees($this->subjA, $this->indB, $this->clsB), 'altra classe, stessa materia vede');
        $this->assertNotContains($id, $this->studentSees($this->subjB, $ind, $cls), 'altra materia NON vede');
    }

    public function testClassesScopeOnlyTargets(): void
    {
        // I target sono codici memorizzati VERBATIM in content_target_classes:
        // rispecchiano ciò che la UI invia (sempre dai codici dinamici del DB).
        [$id] = $this->seed($this->subjA, $this->indA, $this->clsA, 'classes');
        $this->addTarget($id, $this->indB, $this->clsB);
        $this->assertContains($id, $this->studentSees($this->subjA, $this->indB, $this->clsB), 'classe target vede');
        // Non-target: stessa indB ma classe diversa (clsA non è target).
        $this->assertNotContains($id, $this->studentSees($this->subjA, $this->indB, $this->clsA), 'classe non-target NON vede');
        // Propria (indA,clsA) NON dà accesso: lo scope 'classes' conta solo i target.
        $this->assertNotContains($id, $this->studentSees($this->subjA, $this->indA, $this->clsA), 'propria coppia non-target NON vede');
        // Coppia target ma materia diversa NON vede (gate subject_code).
        $this->assertNotContains($id, $this->studentSees($this->subjB, $this->indB, $this->clsB), 'target ma altra materia NON vede');
    }
}
