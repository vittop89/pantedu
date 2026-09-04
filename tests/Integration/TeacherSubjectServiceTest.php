<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Services\TeacherSubjectService;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Le materie di un docente: attivazione di una voce del vocabolario, non una
 * tabella a parte. Il punto delicato e' che togliere una materia NON deve
 * cancellare la riga — i contenuti gia' pubblicati ci puntano.
 *
 * DB-gated, tutto in transazione → rollback in tearDown.
 */
final class TeacherSubjectServiceTest extends TestCase
{
    private PDO $pdo;
    private TeacherSubjectService $svc;
    private int $instId = 0;
    private int $prof = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $base = dirname(__DIR__, 2);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$base/$f")) {
                \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
            }
        }
        \App\Core\Config::load($base . '/app/Config');

        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }

        $this->pdo->beginTransaction();
        $this->inTx = true;

        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
            ->execute(['ZZMATERIE1', 'ISTITUTO MATERIE', 'Comune Esempio']);
        $this->instId = (int)$this->pdo->lastInsertId();

        foreach ([['MAT', 'Matematica'], ['FIS', 'Fisica'], ['ITA', 'Italiano']] as [$c, $l]) {
            $this->pdo->prepare(
                'INSERT INTO curriculum_entries
                    (kind, institute_id, owner_user_id, code, label, active, shared_with_pool)
                 VALUES ("materie", ?, NULL, ?, ?, 1, 0)'
            )->execute([$this->instId, $c, $l]);
        }

        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash,
                                status, active, created_at)
             VALUES (?, "teacher", "Zz", "Prof", ?, "x", "approved", 1, NOW())'
        )->execute(['zzmat', 'zzmat@example.invalid']);
        $this->prof = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')
            ->execute([$this->prof, $this->instId]);

        $this->svc = new TeacherSubjectService();
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /** @return list<string> */
    private function codici(): array
    {
        return array_column($this->svc->forTeacher($this->prof, $this->instId), 'code');
    }

    #[Test]
    public function il_vocabolario_e_quello_dell_istituto(): void
    {
        $this->assertSame(
            ['FIS', 'ITA', 'MAT'],
            array_column($this->svc->available($this->instId), 'code')
        );
    }

    #[Test]
    public function un_docente_nuovo_non_ha_materie(): void
    {
        $this->assertSame([], $this->codici());
        $this->assertContains($this->prof, $this->svc->senzaMaterie($this->instId));
    }

    #[Test]
    public function set_e_un_elenco_completo_non_un_aggiunta(): void
    {
        $this->svc->set($this->prof, $this->instId, ['MAT', 'FIS']);
        $this->assertSame(['FIS', 'MAT'], $this->codici());

        // Rimandare solo MAT deve TOGLIERE FIS: il pannello spedisce le caselle
        // spuntate cosi' come sono, senza calcolare la differenza.
        $this->svc->set($this->prof, $this->instId, ['MAT']);
        $this->assertSame(['MAT'], $this->codici());
    }

    #[Test]
    public function togliere_una_materia_la_disattiva_e_non_la_cancella(): void
    {
        // E' la garanzia che rende reversibile un errore di spunta: i contenuti
        // gia' pubblicati puntano a quell'id, e cancellarlo li renderebbe orfani.
        $this->svc->set($this->prof, $this->instId, ['MAT']);
        $this->svc->set($this->prof, $this->instId, []);

        $st = $this->pdo->prepare(
            'SELECT active FROM curriculum_entries
              WHERE kind = "materie" AND institute_id = ? AND owner_user_id = ? AND code = "MAT"'
        );
        $st->execute([$this->instId, $this->prof]);
        $this->assertSame('0', (string)$st->fetchColumn(), 'la riga deve esistere, spenta');
    }

    #[Test]
    public function rimetterla_la_riaccende_invece_di_duplicarla(): void
    {
        $this->svc->set($this->prof, $this->instId, ['MAT']);
        $this->svc->set($this->prof, $this->instId, []);
        $this->svc->set($this->prof, $this->instId, ['MAT']);

        $this->assertSame(['MAT'], $this->codici());
        $st = $this->pdo->prepare(
            'SELECT COUNT(*) FROM curriculum_entries
              WHERE kind = "materie" AND institute_id = ? AND owner_user_id = ? AND code = "MAT"'
        );
        $st->execute([$this->instId, $this->prof]);
        $this->assertSame(1, (int)$st->fetchColumn(), 'una riga sola, non due');
    }

    #[Test]
    public function una_materia_che_l_istituto_non_ha_viene_ignorata(): void
    {
        // Senza l'anchor di istituto non c'e' niente da clonare: accettarla
        // creerebbe una materia che il docente vede e nessun altro conosce.
        $this->svc->set($this->prof, $this->instId, ['MAT', 'ZZZ']);
        $this->assertSame(['MAT'], $this->codici());
    }

    #[Test]
    public function un_docente_non_collegato_all_istituto_e_un_errore(): void
    {
        $this->pdo->prepare('DELETE FROM teacher_institutes WHERE user_id = ?')->execute([$this->prof]);
        $this->expectException(InvalidArgumentException::class);
        $this->svc->set($this->prof, $this->instId, ['MAT']);
    }

    #[Test]
    public function il_pannello_vede_le_materie_per_docente(): void
    {
        $this->svc->set($this->prof, $this->instId, ['MAT', 'ITA']);
        $perDocente = $this->svc->byInstitute($this->instId);
        $this->assertArrayHasKey($this->prof, $perDocente);
        $this->assertSame(['ITA', 'MAT'], array_column($perDocente[$this->prof], 'code'));
        $this->assertNotContains($this->prof, $this->svc->senzaMaterie($this->instId));
    }
}
