<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\ContentStudyController;
use App\Core\Database;
use App\Core\Request;
use App\Repositories\TeacherContentRepository;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il dettaglio di un contenuto per id (`GET /api/study/content/{id}.json`)
 * applica le stesse regole dell'elenco (ADR-037, invariante S4: «nemmeno per
 * id»).
 *
 * Fino al 13 settembre 2026 bastava essere un docente per leggere per id il
 * contenuto di un qualunque altro docente, bozze comprese, corpo decifrato
 * compreso: il controllo era «pubblicato, o proprietario, o un ruolo che vede
 * tutto», e il ruolo docente vede tutto. L'elenco invece passava dall'ACL
 * delle condivisioni. Lo stesso per gli studenti con account: un contenuto
 * pubblicato di un docente di un'altra scuola si leggeva per id.
 *
 * Il controller si chiama direttamente con la sessione costruita a mano.
 * Fixture isolata in transazione: due scuole; il proprietario e un collega
 * nella prima, un docente nella seconda; uno studente per scuola; un
 * amministratore.
 */
final class DettaglioPerIdTest extends TestCase
{
    private PDO $pdo;
    private bool $inTx = false;
    /** @var array<string,array{id:int,username:string,role:string}> */
    private array $utenti = [];
    private int $scuola = 0;
    private int $altraScuola = 0;

    protected function setUp(): void
    {
        $basePath = dirname(__DIR__, 2);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$basePath/$f")) {
                \Dotenv\Dotenv::createMutable($basePath, $f)->safeLoad();
            }
        }
        \App\Core\Config::load($basePath . '/app/Config');
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT shared_with_pool FROM teacher_content_data LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();
        $this->inTx = true;
        \App\Support\CurriculumLookup::resetCache();

        $ins = $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)');
        $ins->execute(['ZZDETT01', 'SCUOLA DEL PROPRIETARIO', 'Comune Esempio']);
        $this->scuola = (int)$this->pdo->lastInsertId();
        $ins->execute(['ZZDETT02', 'ALTRA SCUOLA', 'Comune Esempio']);
        $this->altraScuola = (int)$this->pdo->lastInsertId();

        $voce = $this->pdo->prepare(
            'INSERT INTO curriculum_entries (kind, institute_id, code, label, active, shared_with_pool, origine)
             VALUES ("materie", ?, "ZDM", "Materia", 1, 0, "istituto")'
        );
        $voce->execute([$this->scuola]);
        $voce->execute([$this->altraScuola]);

        $this->utenti['proprietario'] = $this->utente('zzdett_prop', 'teacher', $this->scuola);
        $this->utenti['collega']      = $this->utente('zzdett_coll', 'teacher', $this->scuola);
        $this->utenti['lontano']      = $this->utente('zzdett_lont', 'teacher', $this->altraScuola);
        $this->utenti['studente']     = $this->utente('zzdett_stud', 'student', $this->scuola);
        $this->utenti['studente_altrove'] = $this->utente('zzdett_stal', 'student', $this->altraScuola);
        $this->utenti['amministratore'] = $this->utente('zzdett_admin', 'administrator', null);
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $_SESSION = [];
        \App\Support\CurriculumLookup::resetCache();
    }

    /** @return array{id:int,username:string,role:string} */
    private function utente(string $username, string $role, ?int $scuola): array
    {
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash,
                                status, active, institute_id, created_at)
             VALUES (?, ?, "Zz", "Dettaglio", ?, "x", "approved", 1, ?, NOW())'
        )->execute([$username, $role, $username . '@example.invalid', $role === 'student' ? $scuola : null]);
        $id = (int)$this->pdo->lastInsertId();
        if ($role === 'teacher' && $scuola !== null) {
            $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')
                ->execute([$id, $scuola]);
        }
        return ['id' => $id, 'username' => $username, 'role' => $role];
    }

    private function contenuto(string $visibility): int
    {
        $_SESSION = [];
        return (new TeacherContentRepository())->create([
            'teacher_id'   => $this->utenti['proprietario']['id'],
            'content_type' => 'document',
            'subject_code' => 'ZDM',
            'topic'        => 'Dettaglio',
            'title'        => 'Dettaglio ' . $visibility . ' ' . uniqid(),
            'body_html'    => '<p>riservato</p>',
            'visibility'   => $visibility,
        ]);
    }

    private function statoPer(string $chi, int $id): int
    {
        $u = $this->utenti[$chi];
        $_SESSION = [
            'autenticato' => true,
            'username'    => $u['username'],
            'user_id'     => $u['id'],
            'user_role'   => $u['role'],
        ];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/api/study/content/' . $id . '.json';
        $res = (new ContentStudyController())->contentSingleJson(new Request(), ['id' => (string)$id]);
        return $res->status;
    }

    #[Test]
    public function il_proprietario_legge_la_sua_bozza(): void
    {
        $this->assertSame(200, $this->statoPer('proprietario', $this->contenuto('draft')));
    }

    #[Test]
    public function un_collega_della_stessa_scuola_non_legge_una_bozza_che_non_gli_e_condivisa(): void
    {
        $this->assertSame(403, $this->statoPer('collega', $this->contenuto('draft')));
    }

    #[Test]
    public function un_collega_non_legge_neanche_un_pubblicato_che_non_gli_e_condiviso(): void
    {
        // L'elenco dello studio non glielo mostra: il dettaglio non deve darglielo.
        $this->assertSame(403, $this->statoPer('collega', $this->contenuto('published')));
    }

    #[Test]
    public function condiviso_nel_pool_il_collega_della_stessa_scuola_lo_legge(): void
    {
        $id = $this->contenuto('draft');
        $this->pdo->prepare('UPDATE teacher_content_data SET shared_with_pool = 1 WHERE id = ?')->execute([$id]);
        $this->assertSame(200, $this->statoPer('collega', $id));
    }

    #[Test]
    public function condiviso_nel_pool_un_docente_di_un_altra_scuola_non_lo_legge(): void
    {
        $id = $this->contenuto('published');
        $this->pdo->prepare('UPDATE teacher_content_data SET shared_with_pool = 1 WHERE id = ?')->execute([$id]);
        $this->assertSame(403, $this->statoPer('lontano', $id));
    }

    #[Test]
    public function lo_studente_della_scuola_legge_il_pubblicato_e_non_la_bozza(): void
    {
        $this->assertSame(200, $this->statoPer('studente', $this->contenuto('published')));
        $this->assertSame(403, $this->statoPer('studente', $this->contenuto('draft')));
    }

    #[Test]
    public function lo_studente_di_un_altra_scuola_non_legge_il_pubblicato(): void
    {
        $this->assertSame(403, $this->statoPer('studente_altrove', $this->contenuto('published')));
    }

    #[Test]
    public function l_amministratore_legge_come_prima(): void
    {
        $this->assertSame(200, $this->statoPer('amministratore', $this->contenuto('draft')));
    }
}
