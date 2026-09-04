<?php

namespace Tests\Unit;

use App\Core\Database;
use App\Services\CurriculumService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * CurriculumService — API DB (post G22.S22): indirizzi/classi/materie sono righe
 * in curriculum_entries scopate per istituto (+ owner per le materie del docente).
 * Riscritto dall'originale file-based (JSON) ormai morto. Usa un ISTITUTO DI TEST
 * ISOLATO così i count sono deterministici a prescindere dai dati di riferimento.
 */
final class CurriculumServiceTest extends TestCase
{
    private CurriculumService $svc;
    private int $instId = 0;
    private \PDO $db;
    /** @var list<int> docenti creati dai test, rimossi in tearDown */
    private array $teacherIds = [];

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
            $this->db = Database::connection();
            $this->db->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB not available: ' . $e->getMessage());
        }

        // Istituto di test isolato (code univoco per run).
        $this->db->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
                 ->execute(['ZZCURR' . substr(uniqid(), -6), 'ZZ Curriculum Test', 'ZZCity']);
        $this->instId = (int)$this->db->lastInsertId();

        // jsonPath irrilevante con DB attivo (add scrive su DB, all legge DB).
        $tmp = sys_get_temp_dir() . '/pantedu_curr_' . uniqid();
        @mkdir($tmp, 0755, true);
        $this->svc = new CurriculumService($tmp . '/curriculum.json', $tmp . '/backups');

        // Seed iniziale: 2 indirizzi attivi + 1 inattivo, 1 classe, 1 materia
        // (tutti institute-level, owner NULL).
        // NB pattern: indirizzi/materie = [A-Z]{3,6}, classi = [1-9][A-Z0-9]{0,3}.
        $this->svc->add('indirizzi', ['code' => 'SCI', 'label' => 'Scientifico', 'active' => true], $this->instId);
        $this->svc->add('indirizzi', ['code' => 'ART', 'label' => 'Artistico',   'active' => true], $this->instId);
        $this->svc->add('indirizzi', ['code' => 'CLA', 'label' => 'Classico',     'active' => false], $this->instId);
        $this->svc->add('classi',    ['code' => '1S',  'label' => 'Classe I',      'active' => true], $this->instId);
        $this->svc->add('materie',   ['code' => 'MAT', 'label' => 'Matematica',   'active' => true], $this->instId);
    }

    protected function tearDown(): void
    {
        if ($this->instId > 0) {
            $this->db->prepare('DELETE FROM curriculum_entries WHERE institute_id=?')->execute([$this->instId]);
            foreach ($this->teacherIds as $uid) {
                $this->db->prepare('DELETE FROM teacher_institutes WHERE user_id=?')->execute([$uid]);
                $this->db->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
            }
            $this->teacherIds = [];
            $this->db->prepare('DELETE FROM institutes WHERE id=?')->execute([$this->instId]);
        }
    }

    #[Test]
    public function all_returns_three_kinds(): void
    {
        $out = $this->svc->all($this->instId);
        $this->assertCount(3, $out['indirizzi']);
        $this->assertCount(1, $out['classi']);
        $this->assertCount(1, $out['materie']);
    }

    #[Test]
    public function list_active_filters_inactive(): void
    {
        $codes = array_column($this->svc->listActive('indirizzi', $this->instId), 'code');
        $this->assertContains('SCI', $codes);
        $this->assertContains('ART', $codes);
        $this->assertNotContains('CLA', $codes);
    }

    #[Test]
    public function add_appends_new_entry(): void
    {
        $rec = $this->svc->add('materie', ['code' => 'CHI', 'label' => 'Chimica', 'active' => true], $this->instId);
        $this->assertSame('CHI', $rec['code']);
        $this->assertCount(2, $this->svc->all($this->instId)['materie']);
    }

    #[Test]
    public function add_rejects_duplicate_code(): void
    {
        $this->expectException(RuntimeException::class);
        $this->svc->add('indirizzi', ['code' => 'SCI', 'label' => 'Dup', 'active' => true], $this->instId);
    }

    #[Test]
    public function add_rejects_invalid_code(): void
    {
        $this->expectException(RuntimeException::class);
        $this->svc->add('indirizzi', ['code' => 'bad/code', 'label' => 'X'], $this->instId);
    }

    #[Test]
    public function add_rejects_empty_label(): void
    {
        $this->expectException(RuntimeException::class);
        $this->svc->add('indirizzi', ['code' => 'NEWX', 'label' => ''], $this->instId);
    }

    #[Test]
    public function add_rejects_unknown_kind(): void
    {
        $this->expectException(RuntimeException::class);
        $this->svc->add('bogus', ['code' => 'x', 'label' => 'y'], $this->instId);
    }

    #[Test]
    public function add_rejects_missing_institute(): void
    {
        $this->expectException(RuntimeException::class);
        $this->svc->add('indirizzi', ['code' => 'ZZX', 'label' => 'No inst']); // institute_id null
    }

    #[Test]
    public function update_by_id_modifies_existing(): void
    {
        // 'cl' è inattivo: lo riattivo + rinomino via updateById.
        $cl = null;
        foreach ($this->svc->all($this->instId)['indirizzi'] as $r) {
            if ($r['code'] === 'CLA') { $cl = $r; break; }
        }
        $this->assertNotNull($cl);
        $rec = $this->svc->updateById((int)$cl['id'], ['active' => true, 'label' => 'Classico 2']);
        $this->assertTrue((bool)$rec['active']);
        $this->assertSame('Classico 2', $rec['label']);
        $this->assertContains('CLA', array_column($this->svc->listActive('indirizzi', $this->instId), 'code'));
    }

    #[Test]
    public function remove_by_id_deletes_entry(): void
    {
        $cl = null;
        foreach ($this->svc->all($this->instId)['indirizzi'] as $r) {
            if ($r['code'] === 'CLA') { $cl = $r; break; }
        }
        $this->assertNotNull($cl);
        $this->assertTrue($this->svc->removeById((int)$cl['id']));
        $this->assertCount(2, $this->svc->all($this->instId)['indirizzi']);
        $this->assertFalse($this->svc->removeById(999999999));
    }

    /** Docente collegato all'istituto di test; ripulito in tearDown. */
    private function makeTeacherLinkedToInstitute(): int
    {
        $u = 'zzcurr_' . substr(uniqid(), -8);
        $this->db->prepare(
            'INSERT INTO users (username, role, email, password_hash, status, active, institute_id)
             VALUES (?, "teacher", ?, "x", "active", 1, ?)'
        )->execute([$u, $u . '@example.test', $this->instId]);
        $uid = (int)$this->db->lastInsertId();
        $this->db->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')
                 ->execute([$uid, $this->instId]);
        $this->teacherIds[] = $uid;
        return $uid;
    }

    #[Test]
    public function a_teacher_cannot_invent_a_new_indirizzo_code(): void
    {
        // Il caso reale: due docenti della stessa scuola creano 'SCI' e 'SCIE'
        // entrambi "Scientifico". listActiveForInstitute deduplica per CODICE,
        // quindi in registrazione lo studente vedrebbe due voci identiche e
        // sceglierne una sbagliata lo lascerebbe senza contenuti, in silenzio.
        $uid = $this->makeTeacherLinkedToInstitute();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unknown_indirizzi_for_institute');
        $this->svc->add(
            'indirizzi',
            ['code' => 'SCIE', 'label' => 'Scientifico', 'active' => true],
            $this->instId,
            $uid
        );
    }

    #[Test]
    public function a_teacher_can_activate_an_indirizzo_the_institute_already_has(): void
    {
        $uid = $this->makeTeacherLinkedToInstitute();

        $rec = $this->svc->add(
            'indirizzi',
            ['code' => 'SCI', 'label' => 'Scientifico', 'active' => true],
            $this->instId,
            $uid
        );

        $this->assertSame('SCI', $rec['code']);
        $this->assertSame($uid, $rec['owner_user_id']);
    }

    #[Test]
    public function il_guard_vale_anche_per_materie_e_classi(): void
    {
        // Decisione del 2026-09-02: il vocabolario e' della SCUOLA per tutti e
        // tre i kind, e lo scrive l'import dei dati MIUR. Una materia inventata
        // da un docente non compare nel catalogo di nessun altro; una classe
        // inventata non corrisponde a nessuna sezione reale. In entrambi i casi
        // il contenuto resta agganciato a una voce che gli studenti non hanno,
        // e sparisce senza un errore.
        $uid = $this->makeTeacherLinkedToInstitute();

        foreach ([['materie', 'ZZM'], ['classi', '9Z']] as [$kind, $code]) {
            try {
                $this->svc->add($kind, ['code' => $code, 'label' => 'Inventata'], $this->instId, $uid);
                $this->fail("$kind: il docente non deve poter inventare $code");
            } catch (RuntimeException $e) {
                $this->assertSame("unknown_{$kind}_for_institute", $e->getMessage());
            }
        }
    }

    #[Test]
    public function il_docente_puo_attivare_materie_e_classi_che_la_scuola_ha(): void
    {
        $uid = $this->makeTeacherLinkedToInstitute();
        // Righe di istituto: e' quello che scrive l'import da /admin/institutes.
        foreach ([['materie', 'FIL', 'Filosofia'], ['classi', '4S', 'Classe IV']] as [$k, $c, $l]) {
            $this->svc->add($k, ['code' => $c, 'label' => $l], $this->instId);
        }

        $m = $this->svc->add('materie', ['code' => 'FIL', 'label' => 'Filosofia'], $this->instId, $uid);
        $c = $this->svc->add('classi',  ['code' => '4S',  'label' => 'Classe IV'],  $this->instId, $uid);

        $this->assertSame('FIL', $m['code']);
        $this->assertSame('4S', $c['code']);
        $this->assertSame($uid, $m['owner_user_id']);
    }

    #[Test]
    public function the_institute_path_can_still_create_new_indirizzi(): void
    {
        // owner NULL = percorso amministrativo: e' cosi' che nasce un
        // indirizzo nuovo, esplicitamente e non per errore di battitura.
        $rec = $this->svc->add('indirizzi', ['code' => 'AFM', 'label' => 'Tecnico'], $this->instId);
        $this->assertSame('AFM', $rec['code']);
        $this->assertNull($rec['owner_user_id']);
    }
}
