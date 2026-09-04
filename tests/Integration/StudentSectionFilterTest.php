<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Repositories\TeacherContentRepository;
use App\Services\TeacherSectionService;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il filtro per sezione visto dal lato che conta: cosa finisce in elenco.
 *
 * La regola e' che il filtro parte CHIUSO. Senza incarichi lo studente non
 * vede niente — non e' un caso limite da tollerare, e' la posizione di
 * partenza: sono gli incarichi ad aprire. L'alternativa (aprire tutto finche'
 * non si configura) fa sembrare la scuola gia' a posto proprio quando non lo
 * e', e i contenuti restano visibili a sezioni sbagliate senza che nessuno se
 * ne accorga.
 *
 * Il docente non e' toccato: le sue query filtrano per teacher_id e non
 * passano dal filtro sezione, quindi continua a vedere i propri contenuti
 * anche quando nessuno studente li vede.
 *
 * DB-gated, tutto in transazione → rollback in tearDown.
 */
final class StudentSectionFilterTest extends TestCase
{
    private PDO $pdo;
    private TeacherSectionService $sections;
    private TeacherContentRepository $repo;
    private int $instId = 0;
    private int $profA = 0;
    private int $profB = 0;
    private int $contA = 0;
    private int $contB = 0;
    private bool $inTx = false;

    private const IND = 'SCI';
    private const MAT = 'MAT';

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
            $this->pdo->query('SELECT 1 FROM teacher_sections LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB o migration 099 non disponibili: ' . $e->getMessage());
        }

        $this->pdo->beginTransaction();
        $this->inTx = true;

        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
            ->execute(['ZZFILTRO01', 'ISTITUTO FILTRO SEZIONI', 'Comune Esempio']);
        $this->instId = (int)$this->pdo->lastInsertId();

        // Catalogo a livello istituto: senza, il repository non sa risolvere i
        // codici in id e scrive NULL — il contenuto esiste ma non appartiene a
        // nessun indirizzo, e nessuna query studente lo trova.
        foreach ([['indirizzi', self::IND], ['classi', '1A'], ['classi', '1B'],
                  ['classi', '1C'], ['materie', self::MAT]] as [$kind, $code]) {
            $this->pdo->prepare(
                'INSERT INTO curriculum_entries
                    (kind, institute_id, owner_user_id, code, label, active, shared_with_pool)
                 VALUES (?, ?, NULL, ?, ?, 1, 0)'
            )->execute([$kind, $this->instId, $code, $code]);
        }

        $this->sections = new TeacherSectionService();
        $this->repo     = new TeacherContentRepository();

        // Due docenti dello stesso istituto e della stessa materia: e' lo
        // scenario che ha motivato il filtro (uno in 1A, uno in 1B).
        $this->profA = $this->docente('zzfa');
        $this->profB = $this->docente('zzfb');
        $this->contA = $this->contenuto($this->profA, '1A');
        $this->contB = $this->contenuto($this->profB, '1B');
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function docente(string $u): int
    {
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash,
                                status, active, created_at)
             VALUES (?, "teacher", "Zz", "Prof", ?, "x", "approved", 1, NOW())'
        )->execute([$u, $u . '@example.invalid']);
        $id = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')
            ->execute([$id, $this->instId]);
        return $id;
    }

    /**
     * teacher_content e' una VIEW: si scrive dalla base via il repository, che
     * risolve anche i codici del catalogo come farebbe la UI.
     */
    private function contenuto(int $teacherId, string $classe): int
    {
        return $this->repo->create([
            'teacher_id'   => $teacherId,
            'content_type' => 'esercizio',
            'subject_code' => self::MAT,
            'indirizzo'    => self::IND,
            'classe'       => $classe,
            'topic'        => 'SEZFILTRO_' . uniqid(),
            'title'        => 'Contenuto ' . $classe,
            'body_html'    => '<p>x</p>',
            'visibility'   => 'published',
        ]);
    }

    /** @return list<int> id visti da uno studente di (self::IND, $classe) */
    private function studenteVede(string $classe): array
    {
        $rows = $this->repo->search([
            'institute_id'  => $this->instId,
            'indirizzo'     => self::IND,
            'classe'        => $classe,
            'visibility'    => 'published',
            'student_scope' => true,
            'limit'         => 100,
        ]);
        return array_map(static fn($r) => (int)$r['id'], $rows);
    }

    /** @return list<int> id visti dal docente sui PROPRI contenuti */
    private function docenteVede(int $teacherId): array
    {
        $rows = $this->repo->search(['teacher_id' => $teacherId, 'limit' => 100]);
        return array_map(static fn($r) => (int)$r['id'], $rows);
    }

    #[Test]
    public function senza_incarichi_lo_studente_non_vede_niente(): void
    {
        $this->assertSame([], $this->studenteVede('1A'));
        $this->assertSame([], $this->studenteVede('1B'));
    }

    #[Test]
    public function senza_incarichi_il_docente_vede_comunque_i_propri(): void
    {
        // La meta' della regola che si dimentica: chiudere agli studenti non
        // deve chiudere a chi ha scritto il contenuto, altrimenti il docente
        // non puo' nemmeno rileggere quello che ha appena pubblicato.
        $this->assertContains($this->contA, $this->docenteVede($this->profA));
        $this->assertContains($this->contB, $this->docenteVede($this->profB));
    }

    #[Test]
    public function il_primo_incarico_apre_solo_la_sua_sezione(): void
    {
        $this->sections->assign($this->profA, $this->instId, self::IND, '1A');

        $this->assertSame([$this->contA], $this->studenteVede('1A'), '1A vede il proprio docente');
        $this->assertSame([], $this->studenteVede('1B'), '1B non e\' ancora coperta');
    }

    #[Test]
    public function due_docenti_in_due_sezioni_restano_separati(): void
    {
        $this->sections->assign($this->profA, $this->instId, self::IND, '1A');
        $this->sections->assign($this->profB, $this->instId, self::IND, '1B');

        $this->assertSame([$this->contA], $this->studenteVede('1A'));
        $this->assertSame([$this->contB], $this->studenteVede('1B'));
    }

    #[Test]
    public function l_incarico_sull_anno_copre_tutte_le_sezioni(): void
    {
        // "1" e' una regola, non un elenco: raggiunge 1A e 1B insieme.
        //
        // NB i filtri sono DUE e indipendenti. L'incarico dice quali docenti ti
        // raggiungono; publish_scope dice a quali classi e' destinato il singolo
        // contenuto. Un incarico sull'anno non fa vedere alla 1B un contenuto
        // pubblicato per la 1A — serve un contenuto destinato alla 1B.
        $per1B = $this->contenuto($this->profA, '1B');
        $this->assertSame([], $this->studenteVede('1B'), 'senza incarico non vede nulla');

        $this->sections->assign($this->profA, $this->instId, self::IND, '1');

        $this->assertSame([$this->contA], $this->studenteVede('1A'));
        $visti = $this->studenteVede('1B');
        $this->assertContains($per1B, $visti, 'la 1B vede il contenuto a lei destinato');
        $this->assertNotContains(
            $this->contB,
            $visti,
            'ma non quello di profB, che non ha incarichi'
        );
    }

    #[Test]
    public function spuntare_le_sezioni_una_per_una_equivale_all_anno_solo_oggi(): void
    {
        // La domanda pratica: invece di "1", non basta spuntare 1A, 1B e 1C?
        // Sulle sezioni che esistono adesso si'. Su quelle che nasceranno, no.
        $per1C = $this->contenuto($this->profA, '1C');

        $this->sections->assign($this->profA, $this->instId, self::IND, '1A');
        $this->sections->assign($this->profA, $this->instId, self::IND, '1B');
        $this->assertSame([$this->contA], $this->studenteVede('1A'));

        // La 1C e' arrivata dopo: l'elenco non la contempla.
        $this->assertSame([], $this->sections->teachersForStudent($this->instId, self::IND, '1C'));
        $this->assertSame([], $this->studenteVede('1C'));

        // L'incarico sull'anno invece la copre senza che nessuno intervenga.
        $this->sections->assign($this->profA, $this->instId, self::IND, '1');
        $this->assertSame([$this->profA], $this->sections->teachersForStudent($this->instId, self::IND, '1C'));
        $this->assertContains($per1C, $this->studenteVede('1C'));
    }
}
