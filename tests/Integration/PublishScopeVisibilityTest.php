<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Repositories\TeacherContentRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Migration 069 — sicurezza del matching scope-aware studente.
 *
 * Verifica che TeacherContentRepository::search con student_scope=true espona
 * allo studente di una (indirizzo,classe) ESATTAMENTE i documenti dovuti:
 *   - publish_scope='class'    → solo la propria (indirizzo,classe);
 *   - publish_scope='general'  → tutti, MA solo nella stessa materia;
 *   - publish_scope='classes'  → solo dove il docente l'ha pubblicato (una pubblicazione
 *     del docente: i bersagli di content_target_classes fino alle migrazioni 118 e 120).
 *
 * Test negativi inclusi: lo studente di una classe NON-target NON deve vedere
 * un documento 'classes'/'class' altrui, né un 'general' di altra materia.
 *
 * Il test NON passa institute_id, di proposito: la query dello studente reale
 * (ContentVisibilityPolicy::studyListFilters) mette in AND due filtri
 * indipendenti — gli incarichi (migration 099) decidono QUALI DOCENTI
 * raggiungono lo studente, publish_scope A QUALI CLASSI è destinato il singolo
 * contenuto. Il primo è coperto da StudentSectionFilterTest; qui si isola il
 * secondo, che senza institute_id è l'unico ad agire.
 *
 * Fixture ISOLATA in transazione (rollback in tearDown), come in
 * StudentSectionFilterTest: istituto, catalogo anchor e docente sono creati
 * qui. La versione precedente usava il docente seedato (docente.uno) e
 * due codici qualsiasi del catalogo copiato dal dev; ma in pantedu_test quel
 * docente non è collegato ad alcun istituto, e senza istituto
 * CurriculumLookup::idFromCodeForTeacher non risolve i codici → il repository
 * scrive subject_id/indirizzo_id/classe_id NULL, la VIEW espone NULL e nessuna
 * query studente trova il contenuto. I tre casi cadevano sul primo
 * assertContains per la fixture, non per la clausola publish_scope.
 */
final class PublishScopeVisibilityTest extends TestCase
{
    private PDO $pdo;
    private TeacherContentRepository $repo;
    private int $teacherId = 0;
    private bool $inTx = false;
    /** Codici del catalogo creato dalla fixture (istituto dedicato, anchor owner NULL). */
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
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1 FROM content_publications LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB o migrazione 117 non disponibili: ' . $e->getMessage());
        }

        $this->pdo->beginTransaction();
        $this->inTx = true;

        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
            ->execute(['ZZSCOPE01', 'ISTITUTO PUBLISH SCOPE', 'Comune Esempio']);
        $instId = (int)$this->pdo->lastInsertId();

        // Catalogo a livello istituto (owner NULL): è l'anchor da cui il
        // repository clona la riga per-docente quando risolve i codici in id.
        [$this->indA, $this->indB]   = ['ZSA', 'ZSB'];
        [$this->clsA, $this->clsB]   = ['1A', '1B'];
        [$this->subjA, $this->subjB] = ['ZMA', 'ZMB'];
        $ins = $this->pdo->prepare(
            'INSERT INTO curriculum_entries
                (kind, institute_id, code, label, active, shared_with_pool)
             VALUES (?, ?, ?, ?, 1, 0)'
        );
        foreach ([
            ['indirizzi', $this->indA], ['indirizzi', $this->indB],
            ['classi',    $this->clsA], ['classi',    $this->clsB],
            ['materie',   $this->subjA], ['materie',  $this->subjB],
        ] as [$kind, $code]) {
            $ins->execute([$kind, $instId, $code, $code]);
        }

        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash,
                                status, active, created_at)
             VALUES (?, "teacher", "Zz", "Scope", ?, "x", "approved", 1, NOW())'
        )->execute(['zzscope', 'zzscope@example.invalid']);
        $this->teacherId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')
            ->execute([$this->teacherId, $instId]);

        $this->repo = new TeacherContentRepository();
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * Crea un teacher_content published e ritorna [id, indirizzo, classe]
     * con i CODICI realmente risolti dal repo, così le asserzioni usano gli
     * stessi codici che vedrebbe la query studente.
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
        if ($scope !== 'class') {
            $this->pdo
                ->prepare('UPDATE teacher_content_data SET publish_scope=? WHERE id=?')
                ->execute([$scope, $id]);
            // ADR-037, fase 4c-2: lo stato della principale lo porta l'applicazione.
            \App\Support\PostoPrincipale::statoDelContenuto($this->pdo, $id);
        }
        $row = $this->pdo
            ->query("SELECT indirizzo, classe FROM teacher_content WHERE id=$id")
            ->fetch(PDO::FETCH_ASSOC);
        return [$id, (string)$row['indirizzo'], (string)$row['classe']];
    }

    /**
     * ADR-037, fase 2 — il bersaglio è una pubblicazione scelta dal docente
     * (origine 'docente') nello stato del contenuto: è ciò in cui la migrazione
     * 118 ha convertito i bersagli di content_target_classes, ed è ciò che
     * aggiunge «Dove vale».
     */
    private function addTarget(int $contentId, string $ind, string $cls): void
    {
        $st = $this->pdo->prepare(
            "INSERT INTO content_publications
                (teacher_content_id, institute_id, indirizzo_id, classe_id, subject_id, is_primary, origine, visibility)
             SELECT d.id, s.institute_id, ci.id, cc.id, d.subject_id, 0, 'docente', d.visibility
               FROM teacher_content d
               JOIN curriculum_entries s  ON s.id = d.subject_id
               JOIN curriculum_entries ci ON ci.kind = 'indirizzi' AND ci.code = ? AND ci.institute_id = s.institute_id
               JOIN curriculum_entries cc ON cc.kind = 'classi' AND cc.code = ? AND cc.institute_id = s.institute_id
              WHERE d.id = ?"
        );
        $st->execute([$ind, $cls, $contentId]);
        $this->assertSame(1, $st->rowCount(), 'il bersaglio si risolve nel catalogo della scuola');
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

    // Tutti i codici provengono dalla fixture (setUp): nessun literal legacy.
    // seed() rilegge i codici risolti dalla view.

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
        // Il posto è una pubblicazione del docente sulle voci della scuola
        // (addTarget): è ciò in cui la 118 ha convertito i bersagli.
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
