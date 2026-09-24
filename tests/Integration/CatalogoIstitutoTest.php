<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Repositories\Curriculum\CurriculumTeacherRepository;
use App\Services\CurriculumService;
use App\Support\CurriculumLookup;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * ADR-035 — il catalogo è dell'istituto, il docente lo spunta.
 *
 * Le garanzie che contano: spuntare una voce non crea nessuna copia; un
 * codice usato in un contenuto risolve alla voce della scuola e la spunta per
 * il docente; l'etichetta del docente resta sua e quella della scuola non si
 * muove; togliere la spunta non tocca la voce; il pannello conta le spunte.
 *
 * DB-gated, tutto in transazione → rollback in tearDown.
 */
final class CatalogoIstitutoTest extends TestCase
{
    private PDO $pdo;
    private CurriculumService $svc;
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
            ->execute(['ZZCATAL001', 'ISTITUTO CATALOGO', 'Comune Esempio']);
        $this->instId = (int)$this->pdo->lastInsertId();
        // ADR-041 — la prova non riguarda chi dei docenti può usare le sezioni:
        // l'istituto vale «tutti», come ogni istituto prima della migrazione 126.
        $this->pdo->prepare("UPDATE institutes SET sezioni_docenti = 'tutti' WHERE id = ?")->execute([$this->instId]);

        $ins = $this->pdo->prepare(
            'INSERT INTO curriculum_entries (kind, institute_id, code, label, indirizzo, active, origine)
             VALUES (?, ?, ?, ?, ?, 1, ?)'
        );
        $ins->execute(['indirizzi', $this->instId, 'SCI', 'Scientifico', null, 'miur']);
        $ins->execute(['classi',    $this->instId, '2',   'Seconda',     'SCI', 'miur']);
        $ins->execute(['classi',    $this->instId, '2A',  'Classe 2A',   'SCI', 'miur']);
        $ins->execute(['materie',   $this->instId, 'MAT', 'Matematica',  null, 'miur']);
        $ins->execute(['materie',   $this->instId, 'SCN', 'Scienze naturali', null, 'docente']);

        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES (?, "teacher", "Zz", "Prof", ?, "x", "approved", 1, NOW())'
        )->execute(['zzcatal', 'zzcatal@example.invalid']);
        $this->prof = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')
            ->execute([$this->prof, $this->instId]);

        $tmp = sys_get_temp_dir() . '/pantedu_catal_' . uniqid();
        $this->svc = new CurriculumService($tmp . '/curriculum.json');
        CurriculumLookup::resetCache();
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        CurriculumLookup::resetCache();
    }

    /** Righe oltre la prima per (kind, code): dalla fase 3 l'indice unico le vieta, e qui si misura lo stesso. */
    private function doppioni(): int
    {
        $st = $this->pdo->prepare("SELECT COUNT(*) - COUNT(DISTINCT CONCAT(kind, '|', code)) FROM curriculum_entries WHERE institute_id = ?");
        $st->execute([$this->instId]);
        return (int)$st->fetchColumn();
    }

    private function idVoce(string $kind, string $code): int
    {
        $st = $this->pdo->prepare('SELECT id FROM curriculum_entries WHERE kind = ? AND code = ? AND institute_id = ?');
        $st->execute([$kind, $code, $this->instId]);
        return (int)$st->fetchColumn();
    }

    #[Test]
    public function spuntare_una_voce_non_crea_copie(): void
    {
        $rec = $this->svc->add('materie', ['code' => 'MAT', 'label' => 'Matematica'], $this->instId, $this->prof);

        $this->assertSame($this->idVoce('materie', 'MAT'), $rec['id'], 'l\'id e\' quello della voce della scuola');
        $this->assertSame($this->prof, $rec['owner_user_id']);
        $this->assertSame(0, $this->doppioni(), 'una voce sola per codice');
        $this->assertTrue((new CurriculumTeacherRepository())->esiste($rec['id'], $this->prof, true), 'la spunta c\'e\', attiva');

        $mie = $this->svc->all($this->instId, $this->prof);
        $this->assertSame(['MAT'], array_column($mie['materie'], 'code'), 'il profilo elenca le spunte');
        $this->assertSame([], $mie['classi'], 'e solo quelle');
    }

    #[Test]
    public function spuntarla_due_volte_e_un_duplicato(): void
    {
        $this->svc->add('materie', ['code' => 'MAT', 'label' => 'Matematica'], $this->instId, $this->prof);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('duplicate_code');
        $this->svc->add('materie', ['code' => 'MAT', 'label' => 'Matematica'], $this->instId, $this->prof);
    }

    #[Test]
    public function l_etichetta_del_docente_resta_sua_e_quella_della_scuola_non_si_muove(): void
    {
        $rec = $this->svc->add('materie', ['code' => 'MAT', 'label' => 'Mate'], $this->instId, $this->prof);
        $this->assertSame('Mate', $rec['label']);
        $this->assertSame('Matematica', $rec['label_istituto']);

        $scuola = $this->svc->getById($rec['id']);
        $this->assertNotNull($scuola);
        $this->assertSame('Matematica', $scuola['label'], 'la voce della scuola e\' intatta');

        // Rimettere l'etichetta della scuola toglie l'override.
        $rec = $this->svc->updateById($rec['id'], ['label' => 'Matematica'], $this->prof);
        $this->assertNull($rec['label_override']);
        $this->assertSame('Matematica', $rec['label']);
    }

    #[Test]
    public function togliere_la_spunta_non_tocca_la_voce(): void
    {
        $rec = $this->svc->add('classi', ['code' => '2A', 'label' => 'Classe 2A'], $this->instId, $this->prof);
        $this->assertTrue($this->svc->removeById($rec['id'], $this->prof));
        $this->assertNotNull($this->svc->getById($rec['id']), 'la voce della scuola c\'e\' ancora');
        $this->assertSame([], $this->svc->all($this->instId, $this->prof)['classi']);
    }

    #[Test]
    public function un_codice_usato_in_un_contenuto_risolve_alla_voce_della_scuola_e_la_spunta(): void
    {
        $id = CurriculumLookup::ensureEntryForTeacher('classi', $this->prof, '2A', $this->instId);
        $this->assertSame($this->idVoce('classi', '2A'), $id);
        $this->assertSame(0, $this->doppioni());
        $this->assertTrue((new CurriculumTeacherRepository())->esiste((int)$id, $this->prof, true));

        // Un codice che la scuola non ha non si inventa.
        $this->assertNull(CurriculumLookup::ensureEntryForTeacher('classi', $this->prof, '9Z', $this->instId));
        $this->assertSame(0, $this->doppioni());
    }

    #[Test]
    public function id_from_code_ignora_il_docente_e_da_sempre_la_voce_della_scuola(): void
    {
        $atteso = $this->idVoce('materie', 'MAT');
        $this->assertSame($atteso, CurriculumLookup::idFromCode('materie', 'MAT', $this->instId));
        $this->assertSame($atteso, CurriculumLookup::idFromCode('materie', 'mat', $this->instId, $this->prof));
    }

    #[Test]
    public function la_condivisione_nel_pool_sta_sulla_spunta(): void
    {
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES (?, "teacher", "Zz", "Collega", ?, "x", "approved", 1, NOW())'
        )->execute(['zzcollega', 'zzcollega@example.invalid']);
        $collega = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')
            ->execute([$collega, $this->instId]);

        $rec = $this->svc->add('materie', ['code' => 'MAT', 'label' => 'Matematica'], $this->instId, $collega);
        $this->assertSame([], $this->svc->listSharedFromColleagues($this->instId, $this->prof), 'niente condiviso ancora');

        $this->svc->updateById($rec['id'], ['shared_with_pool' => 'true'], $collega);
        $condivise = $this->svc->listSharedFromColleagues($this->instId, $this->prof);
        $this->assertCount(1, $condivise);
        $this->assertSame('MAT', $condivise[0]['code']);
        $this->assertSame($collega, $condivise[0]['owner_user_id']);
        $this->assertSame([], $this->svc->listSharedFromColleagues($this->instId, $collega), 'non a se stesso');
    }

    #[Test]
    public function il_vocabolario_sa_da_dove_viene_ogni_voce_e_il_pannello_conta_le_spunte(): void
    {
        $mat = $this->svc->add('materie', ['code' => 'MAT', 'label' => 'Matematica'], $this->instId, $this->prof);
        $voci = $this->svc->all($this->instId, null)['materie'];
        $perCodice = array_column($voci, 'origine', 'code');
        $this->assertSame('miur', $perCodice['MAT']);
        $this->assertSame('docente', $perCodice['SCN']);

        $this->svc->updateById($this->idVoce('materie', 'SCN'), ['origine' => 'istituto']);
        $this->assertSame('istituto', $this->svc->getById($this->idVoce('materie', 'SCN'))['origine'] ?? null);

        $conta = (new CurriculumTeacherRepository())->docentiPerVoce($this->instId);
        $this->assertSame(1, $conta[$mat['id']] ?? 0);
        $this->assertArrayNotHasKey($this->idVoce('materie', 'SCN'), $conta);
    }
}
