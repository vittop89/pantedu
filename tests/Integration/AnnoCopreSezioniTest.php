<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Repositories\TeacherContentRepository;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Piano classi-credenziali-scenari, A — l'anno copre le sue sezioni anche nel
 * filtro dei contenuti, non solo negli incarichi.
 *
 * I docenti etichettano per anno («1»); lo studente, o l'ospite con
 * credenziale, puo' stare su una sezione («1A»). Prima il confronto era alla
 * lettera e da «1A» non si vedeva nulla oltre ai «generali». Ora:
 *   - da «1A» si vedono i contenuti «1A» e quelli «1»;
 *   - da «1» si vedono solo i contenuti «1» (nessuna sezione a caso);
 *   - lo stesso vale per i bersagli del fan-out (publish_scope='classes').
 *
 * Come PublishScopeVisibilityTest: nessun institute_id, cosi' agisce solo la
 * clausola sulle classi; fixture isolata in transazione (rollback in tearDown).
 */
final class AnnoCopreSezioniTest extends TestCase
{
    private PDO $pdo;
    private TeacherContentRepository $repo;
    private int $teacherId = 0;
    private bool $inTx = false;

    private const IND  = 'ZAC';
    private const SUBJ = 'ZMC';

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
            $this->pdo->query('SELECT 1 FROM content_publications LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB o migrazione 117 non disponibili: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();
        $this->inTx = true;

        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
            ->execute(['ZZANNO01', 'ISTITUTO ANNO E SEZIONI', 'Comune Esempio']);
        $instId = (int)$this->pdo->lastInsertId();
        // ADR-041 — la prova non riguarda chi dei docenti può usare le sezioni:
        // l'istituto vale «tutti», come ogni istituto prima della migrazione 126.
        $this->pdo->prepare("UPDATE institutes SET sezioni_docenti = 'tutti' WHERE id = ?")->execute([$instId]);

        // Catalogo a livello istituto (owner NULL): anno «1» e sezioni «1A», «1B»
        // convivono, come nelle scuole vere.
        $ins = $this->pdo->prepare(
            'INSERT INTO curriculum_entries
                (kind, institute_id, code, label, indirizzo, active, shared_with_pool)
             VALUES (?, ?, ?, ?, ?, 1, 0)'
        );
        // ADR-042 — gli anni hanno il corso; le sezioni 1A e 1B restano senza.
        foreach ([
            ['indirizzi', self::IND, null],
            ['classi', '1', self::IND], ['classi', '1A', null], ['classi', '1B', null], ['classi', '2', self::IND],
            ['materie', self::SUBJ, null],
        ] as [$kind, $code, $corso]) {
            $ins->execute([$kind, $instId, $code, $code, $corso]);
        }

        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash,
                                status, active, created_at)
             VALUES (?, "teacher", "Zz", "Anno", ?, "x", "approved", 1, NOW())'
        )->execute(['zzanno', 'zzanno@example.invalid']);
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

    /** Crea un contenuto pubblicato e ritorna [id, classe risolta]. */
    private function seed(string $cls, string $scope = 'class', string $type = 'esercizio', bool $archivio = false): array
    {
        $id = $this->repo->create([
            'teacher_id'   => $this->teacherId,
            'content_type' => $type,
            'archive_visible' => $archivio,
            'subject_code' => self::SUBJ,
            'indirizzo'    => self::IND,
            'classe'       => $cls,
            'topic'        => 'ANNO_' . uniqid(),
            'title'        => "Anno $scope $cls",
            'body_html'    => '<p>x</p>',
            'visibility'   => 'published',
        ]);
        if ($scope !== 'class') {
            $this->pdo
                ->prepare('UPDATE teacher_content_data SET publish_scope=? WHERE id=?')
                ->execute([$scope, $id]);
            // ADR-037, fase 4c-2: senza trigger, lo stato della principale lo
            // porta l'applicazione, come fa il repository.
            \App\Support\PostoPrincipale::statoDelContenuto($this->pdo, $id);
        }
        $row = $this->pdo
            ->query("SELECT classe FROM teacher_content WHERE id=$id")
            ->fetch(PDO::FETCH_ASSOC);
        return [$id, (string)$row['classe']];
    }

    /**
     * ADR-037, fase 2 — il bersaglio del fan-out è una pubblicazione scelta dal
     * docente (origine 'docente'), nello stato del contenuto: è ciò in cui la
     * migrazione 118 ha convertito i bersagli, ed è ciò che aggiunge «Dove vale».
     */
    private function addTarget(int $contentId, string $cls): void
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
        $st->execute([self::IND, $cls, $contentId]);
        $this->assertSame(1, $st->rowCount(), 'il bersaglio si risolve nel catalogo della scuola');
    }

    /** La search come la vede chi guarda da $cls (studente con account o ospite con credenziale). */
    private function vistoDa(string $cls, array $extra = [], string $type = 'esercizio'): array
    {
        $rows = $this->repo->search($extra + [
            'content_type'  => $type,
            'subject_code'  => self::SUBJ,
            'indirizzo'     => self::IND,
            'classe'        => $cls,
            'visibility'    => 'published',
            'student_scope' => true,
            'limit'         => 500,
        ]);
        return array_map(static fn($r) => (int)$r['id'], $rows);
    }

    #[Test]
    public function da_una_sezione_si_vede_il_contenuto_dell_anno(): void
    {
        [$id, $cls] = $this->seed('1');
        $this->assertSame('1', $cls, 'la fixture etichetta per anno, come fanno i docenti');
        $this->assertContains($id, $this->vistoDa('1A'), 'da 1A si vede il contenuto «1»');
        $this->assertContains($id, $this->vistoDa('1'), 'da 1 si vede il contenuto «1»');
        $this->assertNotContains($id, $this->vistoDa('2'), 'un altro anno non lo vede');
    }

    #[Test]
    public function da_un_anno_non_si_vede_la_sezione(): void
    {
        [$id] = $this->seed('1A');
        $this->assertContains($id, $this->vistoDa('1A'), 'la sezione vede il suo');
        $this->assertNotContains($id, $this->vistoDa('1'), 'chi non ha dichiarato la sezione non riceve quella di 1A');
        $this->assertNotContains($id, $this->vistoDa('1B'), 'un\'altra sezione non lo vede');
    }

    #[Test]
    public function il_fan_out_sull_anno_raggiunge_le_sezioni(): void
    {
        [$id] = $this->seed('2', 'classes');
        $this->addTarget($id, '1');
        $this->assertContains($id, $this->vistoDa('1A'), 'bersaglio «1» raggiunge 1A');
        $this->assertContains($id, $this->vistoDa('1'), 'bersaglio «1» raggiunge 1');
        $this->assertNotContains($id, $this->vistoDa('2'), 'la classe propria del contenuto non conta: contano i bersagli');
    }

    #[Test]
    public function il_fan_out_su_una_sezione_non_raggiunge_l_anno(): void
    {
        [$id] = $this->seed('2', 'classes');
        $this->addTarget($id, '1A');
        $this->assertContains($id, $this->vistoDa('1A'));
        $this->assertNotContains($id, $this->vistoDa('1'));
        $this->assertNotContains($id, $this->vistoDa('1B'));
    }

    #[Test]
    public function nell_archivio_le_verifiche_restano_fuori_salvo_quelle_marcate(): void
    {
        // Piano classi, D — chi guarda «Seconda» dalla terza vede esercizi e
        // documenti di seconda, ma non le verifiche: un docente le riusa con
        // la classe piu' giovane. Entra solo quella marcata dal docente.
        [$esercizio] = $this->seed('2');
        [$verifica]  = $this->seed('2', 'class', 'verifica');
        [$marcata]   = $this->seed('2', 'class', 'verifica', true);
        $archivio = ['archivio' => true];
        $this->assertContains($esercizio, $this->vistoDa('2', $archivio), 'esercizio in archivio');
        $this->assertNotContains($verifica, $this->vistoDa('2', $archivio, 'verifica'), 'verifica fuori dall\'archivio');
        $this->assertContains($marcata, $this->vistoDa('2', $archivio, 'verifica'), 'verifica marcata «visibile anche dopo l\'anno»');
        $this->assertContains($verifica, $this->vistoDa('2', [], 'verifica'), 'senza archivio, la classe propria la vede');
    }

    #[Test]
    public function la_marcatura_si_toglie_con_un_aggiornamento(): void
    {
        [$marcata] = $this->seed('2', 'class', 'verifica', true);
        $this->assertTrue($this->repo->update($marcata, $this->teacherId, ['archive_visible' => false]));
        $this->assertNotContains($marcata, $this->vistoDa('2', ['archivio' => true], 'verifica'));
    }

    #[Test]
    public function l_insieme_esplicito_del_gate_sostituisce_quello_derivato(): void
    {
        // E' il punto d'aggancio degli anni frequentati (D): chi passa
        // `classi` decide lui l'insieme, il repository non lo ricalcola.
        [$idUno] = $this->seed('1');
        [$idDue] = $this->seed('2');
        $visti = $this->vistoDa('1A', ['classi' => ['1A', '1', '2']]);
        $this->assertContains($idUno, $visti);
        $this->assertContains($idDue, $visti, 'l\'anno «2» e\' nell\'insieme esplicito');
        $this->assertNotContains($idDue, $this->vistoDa('1A'), 'senza insieme esplicito, «2» resta fuori');
    }
}
