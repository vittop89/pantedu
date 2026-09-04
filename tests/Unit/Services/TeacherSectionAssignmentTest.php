<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Core\Database;
use App\Services\TeacherSectionService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lo scenario che ha motivato tutto: due docenti di matematica, uno in 1A e
 * uno in 1B. Lo studente di 1A deve vedere il primo e non il secondo.
 *
 * Usa un istituto di test isolato, cosi' i conteggi non dipendono dai dati
 * reali presenti in tabella.
 */
final class TeacherSectionAssignmentTest extends TestCase
{
    private \PDO $db;
    private TeacherSectionService $svc;
    private int $instId = 0;
    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        $base = dirname(__DIR__, 3);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$base/$f")) {
                \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
            }
        }
        \App\Core\Config::load($base . '/app/Config');

        try {
            $this->db = Database::connection();
            $this->db->query('SELECT 1 FROM teacher_sections LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB o migration 099 non disponibili: ' . $e->getMessage());
        }

        $this->db->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
                 ->execute(['ZZSEZ' . substr(uniqid(), -6), 'ZZ Sezioni Test', 'ZZCity']);
        $this->instId = (int)$this->db->lastInsertId();
        $this->svc = new TeacherSectionService($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->instId > 0) {
            $this->db->prepare('DELETE FROM teacher_sections WHERE institute_id = ?')->execute([$this->instId]);
            $this->db->prepare('DELETE FROM curriculum_entries WHERE institute_id = ?')->execute([$this->instId]);
            foreach ($this->userIds as $uid) {
                $this->db->prepare('DELETE FROM teacher_institutes WHERE user_id = ?')->execute([$uid]);
                $this->db->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
            }
            $this->db->prepare('DELETE FROM institutes WHERE id = ?')->execute([$this->instId]);
        }
    }

    private function docente(string $suffix): int
    {
        $u = 'zzsez_' . $suffix . '_' . substr(uniqid(), -6);
        $this->db->prepare(
            'INSERT INTO users (username, role, email, password_hash, status, active, institute_id)
             VALUES (?, "teacher", ?, "x", "active", 1, ?)'
        )->execute([$u, $u . '@example.test', $this->instId]);
        $uid = (int)$this->db->lastInsertId();
        $this->db->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')
                 ->execute([$uid, $this->instId]);
        $this->userIds[] = $uid;
        return $uid;
    }

    #[Test]
    public function lo_studente_di_1A_non_vede_il_docente_della_1B(): void
    {
        $prof1A = $this->docente('a');
        $prof1B = $this->docente('b');
        $this->svc->assign($prof1A, $this->instId, 'SCI', '1A');
        $this->svc->assign($prof1B, $this->instId, 'SCI', '1B');

        $this->assertSame([$prof1A], $this->svc->teachersForStudent($this->instId, 'SCI', '1A'));
        $this->assertSame([$prof1B], $this->svc->teachersForStudent($this->instId, 'SCI', '1B'));
    }

    #[Test]
    public function un_docente_sull_anno_raggiunge_tutte_le_sezioni(): void
    {
        $profAnno = $this->docente('anno');
        $prof1A   = $this->docente('a');
        $this->svc->assign($profAnno, $this->instId, 'SCI', '1');
        $this->svc->assign($prof1A, $this->instId, 'SCI', '1A');

        $visti1A = $this->svc->teachersForStudent($this->instId, 'SCI', '1A');
        sort($visti1A);
        $atteso = [$profAnno, $prof1A];
        sort($atteso);
        $this->assertSame($atteso, $visti1A, 'in 1A si vedono entrambi');

        $this->assertSame([$profAnno], $this->svc->teachersForStudent($this->instId, 'SCI', '1B'));
    }

    #[Test]
    public function senza_incarichi_le_sezioni_non_sono_in_uso(): void
    {
        // Serve al pannello admin per dire "qui non arriva nessuno": il
        // filtro dei contenuti e' sempre attivo, quindi una sezione senza
        // incarichi e' una sezione i cui studenti non vedono niente, e
        // l'unico modo di non farlo passare in silenzio e' mostrarlo.
        $this->assertFalse($this->svc->sectionsInUse($this->instId, 'SCI', '1A'));
        $this->assertSame([], $this->svc->teachersForStudent($this->instId, 'SCI', '1A'));

        $this->svc->assign($this->docente('x'), $this->instId, 'SCI', '1A');
        $this->assertTrue($this->svc->sectionsInUse($this->instId, 'SCI', '1A'));
    }

    #[Test]
    public function gli_incarichi_di_un_anno_non_toccano_gli_altri(): void
    {
        $this->svc->assign($this->docente('primo'), $this->instId, 'SCI', '1A');
        $this->assertTrue($this->svc->sectionsInUse($this->instId, 'SCI', '1A'));
        $this->assertFalse($this->svc->sectionsInUse($this->instId, 'SCI', '2A'));
    }

    #[Test]
    public function indirizzi_diversi_restano_separati(): void
    {
        $sci = $this->docente('sci');
        $art = $this->docente('art');
        $this->svc->assign($sci, $this->instId, 'SCI', '1A');
        $this->svc->assign($art, $this->instId, 'ART', '1A');

        $this->assertSame([$sci], $this->svc->teachersForStudent($this->instId, 'SCI', '1A'));
        $this->assertSame([$art], $this->svc->teachersForStudent($this->instId, 'ART', '1A'));
    }

    #[Test]
    public function riassegnare_non_duplica(): void
    {
        $uid = $this->docente('dup');
        $this->svc->assign($uid, $this->instId, 'SCI', '1A');
        $this->svc->assign($uid, $this->instId, 'SCI', '1A', null, 'seconda volta');
        $this->assertCount(1, $this->svc->listForInstitute($this->instId));
    }

    #[Test]
    public function un_docente_non_collegato_all_istituto_non_si_assegna(): void
    {
        $u = 'zzsez_estraneo_' . substr(uniqid(), -6);
        $this->db->prepare(
            'INSERT INTO users (username, role, email, password_hash, status, active)
             VALUES (?, "teacher", ?, "x", "active", 1)'
        )->execute([$u, $u . '@example.test']);
        $uid = (int)$this->db->lastInsertId();
        $this->userIds[] = $uid;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('teacher_not_linked_to_institute');
        $this->svc->assign($uid, $this->instId, 'SCI', '1A');
    }

    #[Test]
    public function la_revoca_toglie_l_incarico(): void
    {
        $uid = $this->docente('rev');
        $this->svc->assign($uid, $this->instId, 'SCI', '1A');
        $righe = $this->svc->listForInstitute($this->instId);
        $this->assertCount(1, $righe);

        $this->assertTrue($this->svc->revoke((int)$righe[0]['id']));
        $this->assertSame([], $this->svc->teachersForStudent($this->instId, 'SCI', '1A'));
        $this->assertFalse($this->svc->revoke(999999999));
    }

    #[Test]
    public function si_puo_togliere_un_incarico_senza_conoscerne_l_id(): void
    {
        // Il pannello toglie la spunta a una sezione: li' si sa chi/dove/cosa,
        // non il numero di riga.
        $uid = $this->docente('rs');
        $this->svc->assign($uid, $this->instId, 'SCI', '1A');
        $this->svc->assign($uid, $this->instId, 'SCI', '2A');

        $this->assertTrue($this->svc->revokeSection($uid, $this->instId, 'SCI', '1A'));
        $this->assertSame([], $this->svc->teachersForStudent($this->instId, 'SCI', '1A'));
        $this->assertSame([$uid], $this->svc->teachersForStudent($this->instId, 'SCI', '2A'), 'le altre restano');

        $this->assertFalse(
            $this->svc->revokeSection($uid, $this->instId, 'SCI', '1A'),
            'togliere due volte non e\' un errore, ma non toglie niente'
        );
    }

    #[Test]
    public function togliere_l_incarico_non_tocca_il_curriculum_del_docente(): void
    {
        // Le voci di curriculum reggono i contenuti gia' pubblicati: toglierle
        // insieme all'incarico significherebbe scollegare del lavoro. Chi non
        // insegna piu' in 1A smette di raggiungere quegli studenti, non perde
        // le proprie mappe.
        $this->vocabolario('indirizzi', 'SCI');
        $this->vocabolario('classi', '1A', 'SCI');
        $uid = $this->docente('cur');
        $this->svc->assign($uid, $this->instId, 'SCI', '1A');
        $this->assertCount(2, $this->righeDelDocente($uid));

        $this->svc->revokeSection($uid, $this->instId, 'SCI', '1A');
        $this->assertCount(2, $this->righeDelDocente($uid), 'il curriculum resta dov\'era');
    }

    /** Una voce del vocabolario dell'istituto (owner NULL): l'ancora da cui si copia. */
    private function vocabolario(string $kind, string $code, ?string $indirizzo = null): void
    {
        $this->db->prepare(
            'INSERT INTO curriculum_entries
                (kind, institute_id, owner_user_id, code, label, indirizzo, active, shared_with_pool)
             VALUES (?, ?, NULL, ?, ?, ?, 1, 0)'
        )->execute([$kind, $this->instId, $code, 'Etichetta ' . $code, $indirizzo]);
    }

    /** @return list<array{kind:string,code:string,indirizzo:?string}> */
    private function righeDelDocente(int $uid): array
    {
        $st = $this->db->prepare(
            'SELECT kind, code, indirizzo FROM curriculum_entries
              WHERE institute_id = ? AND owner_user_id = ? ORDER BY kind, code'
        );
        $st->execute([$this->instId, $uid]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    #[Test]
    public function assegnare_un_incarico_attiva_la_classe_per_il_docente(): void
    {
        // Il caso vero: l'amministratore assegna la 1A, e il docente apre la
        // sidebar e trova il selettore Classe vuoto. L'incarico stava in
        // `teacher_sections`, i selettori leggono `curriculum_entries`.
        $this->vocabolario('indirizzi', 'SCI');
        $this->vocabolario('classi', '1A', 'SCI');
        $uid = $this->docente('att');

        $prima = $this->righeDelDocente($uid);
        $this->assertSame([], $prima, 'prima non ha niente di suo');

        $this->svc->assign($uid, $this->instId, 'SCI', '1A');

        $sue = $this->righeDelDocente($uid);
        $this->assertCount(2, $sue);
        $this->assertSame('classi', $sue[0]['kind']);
        $this->assertSame('1A', $sue[0]['code']);
        $this->assertSame('SCI', $sue[0]['indirizzo'], 'la classe si porta dietro il suo corso');
        $this->assertSame('indirizzi', $sue[1]['kind']);
        $this->assertSame('SCI', $sue[1]['code']);
    }

    #[Test]
    public function riassegnare_lo_stesso_incarico_non_duplica(): void
    {
        $this->vocabolario('indirizzi', 'SCI');
        $this->vocabolario('classi', '1A', 'SCI');
        $uid = $this->docente('idem');

        $this->svc->assign($uid, $this->instId, 'SCI', '1A');
        $this->svc->assign($uid, $this->instId, 'SCI', '1A');

        $this->assertCount(2, $this->righeDelDocente($uid));
    }

    #[Test]
    public function senza_ancora_non_inventa_una_voce(): void
    {
        // Il vocabolario dell'istituto e' deciso altrove. Se la classe non
        // c'e', l'incarico si scrive comunque ma non nasce una voce di
        // curriculum dal nulla: sarebbe una classe che non esiste per la
        // scuola e comparirebbe solo a questo docente.
        $uid = $this->docente('vuoto');
        $this->svc->assign($uid, $this->instId, 'SCI', '1A');

        $this->assertSame([], $this->righeDelDocente($uid));
        $this->assertSame([$uid], $this->svc->teachersForStudent($this->instId, 'SCI', '1A'));
    }
}
