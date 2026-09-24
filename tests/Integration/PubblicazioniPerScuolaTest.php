<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\ContentStudyController;
use App\Controllers\MapsController;
use App\Core\Database;
use App\Core\Request;
use App\Repositories\Sharing\PoolRepository;
use App\Repositories\TeacherContentRepository;
use App\Services\Maps\MapPermissionService;
use App\Services\Sharing\SharedContentPolicy;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR-037, fase 1, invariante S4 — chi guarda da una scuola arriva solo a ciò
 * che è pubblicato in quella scuola.
 *
 * Due scuole con **le stesse sigle** (corso ZQS, anno 2, sezioni 2A e 2B,
 * materia ZQM): è il caso in cui prima un contenuto nato nella prima compariva
 * anche nella seconda. Il docente è in tutte e due, con un incarico sulla 2A di
 * ciascuna, così il filtro degli incarichi non distingue: distingue solo la
 * scuola della pubblicazione. Ogni pubblico nei due versi: la scuola del
 * contenuto vede, l'altra no.
 *
 * Fixture isolata in transazione (rollback in tearDown).
 */
final class PubblicazioniPerScuolaTest extends TestCase
{
    private PDO $pdo;
    private TeacherContentRepository $repo;
    private bool $inTx = false;
    private int $scuolaA = 0;
    private int $scuolaB = 0;
    private int $docente = 0;
    private int $collegaA = 0;
    private int $collegaB = 0;
    /** @var array<string,array{id:int,username:string}> */
    private array $studenti = [];
    /** Contenuto «per classe» nato nella scuola A, su SCI/2/MAT, pubblicato. */
    private int $contenuto = 0;
    /** Contenuto «per più classi» nella scuola A: riga sull'anno, bersaglio 2A. */
    private int $perPiuClassi = 0;

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
        $_SESSION = [];
        \App\Support\CurriculumLookup::resetCache();

        $ist = $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)');
        $ist->execute(['ZZPUBA01', 'SCUOLA A', 'Comune Esempio']);
        $this->scuolaA = (int)$this->pdo->lastInsertId();
        $ist->execute(['ZZPUBB01', 'SCUOLA B', 'Comune Esempio']);
        $this->scuolaB = (int)$this->pdo->lastInsertId();

        $voce = $this->pdo->prepare(
            'INSERT INTO curriculum_entries (kind, institute_id, code, label, indirizzo, active, shared_with_pool, origine)
             VALUES (?, ?, ?, ?, ?, 1, 0, "istituto")'
        );
        foreach ([$this->scuolaA, $this->scuolaB] as $s) {
            foreach ([
                ['indirizzi', 'ZQS', null], ['classi', '2', 'ZQS'],
                ['classi', '2A', 'ZQS'], ['classi', '2B', 'ZQS'], ['materie', 'ZQM', null],
            ] as [$kind, $code, $corso]) {
                $voce->execute([$kind, $s, $code, $code, $corso]);
            }
        }

        $this->docente  = $this->utente('zzpub_doc', 'teacher', null, [$this->scuolaA, $this->scuolaB]);
        $this->collegaA = $this->utente('zzpub_colla', 'teacher', null, [$this->scuolaA]);
        $this->collegaB = $this->utente('zzpub_collb', 'teacher', null, [$this->scuolaB]);
        foreach (['A2A' => [$this->scuolaA, '2A'], 'A2B' => [$this->scuolaA, '2B'], 'B2A' => [$this->scuolaB, '2A']] as $nome => [$s, $cls]) {
            $u = 'zzpub_stud_' . strtolower($nome);
            $this->studenti[$nome] = ['id' => $this->utente($u, 'student', [$s, 'ZQS', $cls], []), 'username' => $u];
        }
        $inc = $this->pdo->prepare('INSERT INTO teacher_sections (user_id, institute_id, indirizzo, classe) VALUES (?, ?, ?, ?)');
        foreach ([[$this->scuolaA, '2A'], [$this->scuolaA, '2B'], [$this->scuolaB, '2A']] as [$s, $cls]) {
            $inc->execute([$this->docente, $s, 'ZQS', $cls]);
        }

        // Nessuna sessione: il repository risolve le sigle nel primo istituto
        // del docente, cioè la scuola A (creata per prima).
        $this->repo = new TeacherContentRepository();
        $this->contenuto = $this->crea('document', 'Per classe ' . uniqid());
        // «Per più classi» dopo la migrazione 118: scope 'classes' (principale
        // in bozza) e il posto 2A come pubblicazione del docente.
        $this->perPiuClassi = $this->crea('document', 'Per piu classi ' . uniqid());
        $this->pdo->prepare('UPDATE teacher_content_data SET publish_scope = "classes" WHERE id = ?')
            ->execute([$this->perPiuClassi]);
        // ADR-037, fase 4c-2: lo stato della principale lo porta l'applicazione.
        \App\Support\PostoPrincipale::statoDelContenuto($this->pdo, $this->perPiuClassi);
        $this->pdo->prepare(
            "INSERT INTO content_publications
                (teacher_content_id, institute_id, indirizzo_id, classe_id, subject_id, is_primary, origine, visibility)
             SELECT d.id, ?, ci.id, cc.id, d.subject_id, 0, 'docente', 'published'
               FROM teacher_content d
               JOIN curriculum_entries ci ON ci.kind = 'indirizzi' AND ci.code = 'ZQS' AND ci.institute_id = ?
               JOIN curriculum_entries cc ON cc.kind = 'classi' AND cc.code = '2A' AND cc.institute_id = ?
              WHERE d.id = ?"
        )->execute([$this->scuolaA, $this->scuolaA, $this->scuolaA, $this->perPiuClassi]);
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $_SESSION = [];
        \App\Support\ClassAccessGrant::resetCache();
        \App\Support\CurriculumLookup::resetCache();
    }

    /**
     * @param ?array{0:int,1:string,2:string} $studente [istituto, indirizzo, classe]
     * @param list<int> $scuole
     */
    private function utente(string $username, string $role, ?array $studente, array $scuole): int
    {
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active,
                                institute_id, indirizzo, classe, created_at)
             VALUES (?, ?, "Zz", "Scuole", ?, "x", "approved", 1, ?, ?, ?, NOW())'
        )->execute([$username, $role, $username . '@example.invalid', $studente[0] ?? null, $studente[1] ?? null, $studente[2] ?? null]);
        $id = (int)$this->pdo->lastInsertId();
        foreach ($scuole as $s) {
            $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')->execute([$id, $s]);
        }
        return $id;
    }

    private function crea(string $tipo, string $titolo): int
    {
        return $this->repo->create([
            'teacher_id'   => $this->docente,
            'content_type' => $tipo,
            'subject_code' => 'ZQM',
            'indirizzo'    => 'ZQS',
            'classe'       => '2',
            'topic'        => $titolo,
            'title'        => $titolo,
            'visibility'   => 'published',
        ]);
    }

    /** @param array<string,mixed> $filtri @return list<int> */
    private function trova(array $filtri, string $tipo = 'document'): array
    {
        $rows = $this->repo->search($filtri + ['content_type' => $tipo, 'subject_code' => 'ZQM', 'limit' => 500]);
        return array_map(static fn(array $r): int => (int)$r['id'], $rows);
    }

    /** Il frammento che la policy emette per uno studente, come nello studio. @return array<string,mixed> */
    private function daStudente(int $scuola, string $classe): array
    {
        return (new \App\Domain\ContentVisibilityPolicy())->studyListFilters(
            \App\Domain\ViewerContext::forStudent(1, $scuola, 'ZQS', $classe)
        );
    }

    /** @param array<string,mixed> $credenziale @return array<string,mixed> */
    private function daCredenziale(array $credenziale): array
    {
        return (new \App\Domain\ContentVisibilityPolicy())->studyListFilters(
            \App\Domain\ViewerContext::forKeychain([$credenziale + ['teacher_id' => $this->docente, 'credential_id' => 1, 'label' => 'prova']])
        );
    }

    private function sessione(string $username, int $id, string $ruolo): void
    {
        $_SESSION = ['autenticato' => true, 'username' => $username, 'user_id' => $id, 'user_role' => $ruolo];
    }

    // ── studenti con account ─────────────────────────────────────────────

    #[Test]
    public function lo_studente_della_scuola_vede_il_contenuto_e_quello_dell_altra_scuola_con_le_stesse_sigle_no(): void
    {
        $this->assertContains($this->contenuto, $this->trova($this->daStudente($this->scuolaA, '2A')), 'scuola A, 2A: l\'anno copre la sezione');
        $this->assertNotContains($this->contenuto, $this->trova($this->daStudente($this->scuolaB, '2A')), 'scuola B: stesse sigle, altra scuola');
    }

    #[Test]
    public function per_piu_classi_vede_il_bersaglio_e_non_le_altre_sezioni_dell_anno(): void
    {
        $this->assertContains($this->perPiuClassi, $this->trova($this->daStudente($this->scuolaA, '2A')));
        $this->assertNotContains($this->perPiuClassi, $this->trova($this->daStudente($this->scuolaA, '2B')), 'la terna della riga (anno 2) non apre il documento alla 2B');
        $this->assertContains($this->contenuto, $this->trova($this->daStudente($this->scuolaA, '2B')), 'mentre il contenuto per classe sull\'anno 2 la 2B lo vede');
    }

    // ── credenziali di classe ────────────────────────────────────────────

    #[Test]
    public function la_credenziale_della_scuola_vede_e_quella_dell_altra_scuola_no(): void
    {
        $inA = $this->daCredenziale(['indirizzo' => 'ZQS', 'classe' => '2A', 'institute_id' => $this->scuolaA]);
        $inB = $this->daCredenziale(['indirizzo' => 'ZQS', 'classe' => '2A', 'institute_id' => $this->scuolaB]);
        $this->assertContains($this->contenuto, $this->trova($inA));
        $this->assertNotContains($this->contenuto, $this->trova($inB));
    }

    #[Test]
    public function la_credenziale_senza_scuola_vale_per_le_scuole_del_docente(): void
    {
        $senza = $this->daCredenziale(['indirizzo' => 'ZQS', 'classe' => '2A', 'institute_id' => null]);
        $this->assertContains($this->contenuto, $this->trova($senza));
    }

    // ── il docente e i colleghi ──────────────────────────────────────────

    #[Test]
    public function la_barra_del_docente_mostra_il_contenuto_solo_nella_sua_scuola_e_i_contenuti_senza_scuola_ovunque(): void
    {
        $senzaScuola = $this->repo->create([
            'teacher_id' => $this->docente, 'content_type' => 'document', 'subject_code' => 'ZZNONE',
            'topic' => 'Senza scuola', 'title' => 'Senza scuola ' . uniqid(), 'visibility' => 'draft',
        ]);
        $base = ['teacher_id' => $this->docente, 'pub_actor_id' => $this->docente];
        $inA = $this->repo->search($base + ['content_type' => 'document', 'pub_institute_id' => $this->scuolaA, 'limit' => 500]);
        $inB = $this->repo->search($base + ['content_type' => 'document', 'pub_institute_id' => $this->scuolaB, 'limit' => 500]);
        $idA = array_map(static fn(array $r): int => (int)$r['id'], $inA);
        $idB = array_map(static fn(array $r): int => (int)$r['id'], $inB);
        $this->assertContains($this->contenuto, $idA);
        $this->assertNotContains($this->contenuto, $idB, 'nella scuola B il contenuto nato in A non c\'è');
        $this->assertContains($senzaScuola, $idA);
        $this->assertContains($senzaScuola, $idB, 'un contenuto senza etichette resta al proprietario in ogni scuola');
    }

    #[Test]
    public function il_collega_di_un_altra_scuola_del_docente_non_legge_il_contenuto_condiviso(): void
    {
        $this->pdo->prepare('UPDATE teacher_content_data SET shared_with_pool = 1 WHERE id = ?')->execute([$this->contenuto]);
        $acl = new SharedContentPolicy();
        $this->assertTrue($acl->canReadContent($this->collegaA, 'teacher_content', $this->contenuto, $this->docente, true), 'collega della scuola A');
        $this->assertFalse(
            $acl->canReadContent($this->collegaB, 'teacher_content', $this->contenuto, $this->docente, true),
            'il collega della scuola B condivide una scuola con il docente, ma il contenuto non è pubblicato lì'
        );
    }

    #[Test]
    public function il_pool_elenca_il_contenuto_solo_ai_colleghi_della_scuola_in_cui_e_pubblicato(): void
    {
        $this->pdo->prepare('UPDATE teacher_content_data SET shared_with_pool = 1 WHERE id = ?')->execute([$this->contenuto]);
        $pool = new PoolRepository();
        $perA = array_map(static fn(array $r): int => (int)$r['id'], $pool->eligibleTeacherContent($this->collegaA, [$this->scuolaA], [], null, null, null));
        $perB = array_map(static fn(array $r): int => (int)$r['id'], $pool->eligibleTeacherContent($this->collegaB, [$this->scuolaB], [], null, null, null));
        $this->assertContains($this->contenuto, $perA);
        $this->assertNotContains($this->contenuto, $perB);
    }

    #[Test]
    public function il_grant_di_istituto_si_accetta_solo_per_una_scuola_in_cui_il_contenuto_e_pubblicato(): void
    {
        $acl = new SharedContentPolicy();
        $this->assertTrue($acl->validateTarget($this->docente, 'teacher_content', $this->contenuto, 'institute', $this->scuolaA));
        $this->assertFalse($acl->validateTarget($this->docente, 'teacher_content', $this->contenuto, 'institute', $this->scuolaB));
    }

    // ── per id: dettaglio, verifiche correlate, mappe ───────────────────

    #[Test]
    public function il_dettaglio_per_id_allo_studente_della_scuola_si_e_all_altra_scuola_no(): void
    {
        $leggi = function (string $chi): int {
            $s = $this->studenti[$chi];
            $this->sessione($s['username'], $s['id'], 'student');
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/api/study/content/' . $this->contenuto . '.json';
            return (new ContentStudyController())->contentSingleJson(new Request(), ['id' => (string)$this->contenuto])->status;
        };
        $this->assertSame(200, $leggi('A2A'));
        $this->assertSame(403, $leggi('B2A'));
    }

    #[Test]
    public function le_verifiche_correlate_arrivano_allo_studente_della_scuola_e_non_a_quello_dell_altra(): void
    {
        $titolo = 'Correlata ' . uniqid();
        $this->crea('verifica', $titolo);
        $chiedi = function (string $chi) use ($titolo): string {
            $s = $this->studenti[$chi];
            $this->sessione($s['username'], $s['id'], 'student');
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/api/study/related-verifiche.html';
            $_GET = ['subject' => 'ZQM', 'title' => $titolo];
            $res = (new ContentStudyController())->relatedVerificaHtml(new Request());
            $_GET = [];
            return $res->body;
        };
        $this->assertStringContainsString('type_verAll', $chiedi('A2A'));
        $this->assertStringContainsString('nessuna verifica correlata', $chiedi('B2A'));
    }

    #[Test]
    public function la_mappa_con_la_credenziale_si_vede_nella_sua_scuola_e_non_nell_altra(): void
    {
        $mappa = $this->crea('mappa', 'Mappa ' . uniqid());
        $controlla = new \ReflectionMethod(MapsController::class, 'grantCanViewMap');
        $controller = (new \ReflectionClass(MapsController::class))->newInstanceWithoutConstructor();
        $grant = fn(?int $scuola): array => ['teacher_id' => $this->docente, 'indirizzo' => 'ZQS', 'classe' => '2A', 'institute_id' => $scuola];
        $this->assertTrue($controlla->invoke($controller, $mappa, $grant($this->scuolaA)));
        $this->assertFalse($controlla->invoke($controller, $mappa, $grant($this->scuolaB)));
    }

    #[Test]
    public function la_sezione_della_barra_delimitata_a_una_scuola_vale_solo_con_contenuti_pubblicati_li(): void
    {
        $sezioni = new \App\Repositories\SidebarSectionRepository();
        $this->assertTrue($sezioni->teacherMatchesScope($this->docente, ['institute_id' => $this->scuolaA, 'indirizzo' => 'ZQS', 'classe' => '2']));
        $this->assertFalse(
            $sezioni->teacherMatchesScope($this->docente, ['institute_id' => $this->scuolaB, 'indirizzo' => 'ZQS', 'classe' => '2']),
            'il docente è anche nella scuola B, ma lì con quelle sigle non ha contenuti'
        );
        $this->assertTrue($sezioni->teacherMatchesScope($this->docente, ['indirizzo' => 'ZQS', 'classe' => '2']), 'senza scuola nello scope, come prima');
    }

    #[Test]
    public function la_mappa_pubblicata_per_classe_si_vede_dal_contesto_della_sua_scuola(): void
    {
        $mappa = $this->crea('mappa', 'Mappa contesto ' . uniqid());
        $perm = new MapPermissionService();
        $studente = $this->studenti['A2A']['id'];
        $this->assertTrue($perm->canView($mappa, $studente, ['institute_id' => $this->scuolaA, 'indirizzo' => 'ZQS', 'classe' => '2']));
        $this->assertFalse($perm->canView($mappa, $studente, ['institute_id' => $this->scuolaB, 'indirizzo' => 'ZQS', 'classe' => '2']));
    }
}
