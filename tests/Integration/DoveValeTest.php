<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\TeacherContentController;
use App\Core\Database;
use App\Core\Request;
use App\Domain\ContentVisibilityPolicy;
use App\Domain\ViewerContext;
use App\Repositories\TeacherContentRepository;
use App\Services\Contenuti\DoveVale;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR-037, fase 2 — «Dove vale»: le pubblicazioni scelte dal docente.
 *
 * Due scuole: la A chiama la materia ZDM, la B la chiama ZDMX, con lo stesso
 * corso e la stessa sezione 2A. Il docente lavora in tutte e due e ha spuntato
 * le voci che usa (non la 2B della B); un collega lavora nella A. Un contenuto
 * del docente nasce nella A su ZDS/2/ZDM, pubblicato.
 *
 * Si prova: S1 (solo il proprietario), S2 (solo nelle sue scuole), S3 (voci
 * della scuola, attive, spuntate, con il corso giusto), i doppioni, la
 * principale che non si toglie, il registro (S6), il limite del profilo in
 * modalità Istituto, e i lettori: la pubblicazione nella B con la sua sigla di
 * materia arriva agli studenti e alla barra della B, e non con la sigla
 * della riga.
 *
 * Fixture isolata in transazione (rollback in tearDown).
 */
final class DoveValeTest extends TestCase
{
    private PDO $pdo;
    private DoveVale $doveVale;
    private bool $inTx = false;
    private int $scuolaA = 0;
    private int $scuolaB = 0;
    private int $altraScuola = 0;
    private int $docente = 0;
    private int $collega = 0;
    private int $contenuto = 0;
    /** @var array<string,int> "A:ZDS" → id */
    private array $voce = [];

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
            $this->pdo->query('SELECT origine FROM content_publications LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB o migrazione 117 non disponibili: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();
        $this->inTx = true;
        $_SESSION = [];
        \App\Support\CurriculumLookup::resetCache();

        $ist = $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)');
        $ist->execute(['ZZDVA001', 'SCUOLA A DOVE VALE', 'Comune Esempio']);
        $this->scuolaA = (int)$this->pdo->lastInsertId();
        $ist->execute(['ZZDVB001', 'SCUOLA B DOVE VALE', 'Comune Esempio']);
        $this->scuolaB = (int)$this->pdo->lastInsertId();
        $ist->execute(['ZZDVC001', 'SCUOLA NON SUA', 'Altro Comune']);
        $this->altraScuola = (int)$this->pdo->lastInsertId();

        $voce = $this->pdo->prepare(
            'INSERT INTO curriculum_entries (kind, institute_id, code, label, indirizzo, active, shared_with_pool, origine)
             VALUES (?, ?, ?, ?, ?, ?, 0, "istituto")'
        );
        foreach ([
            ['A', $this->scuolaA, 'indirizzi', 'ZDS', null, 1], ['A', $this->scuolaA, 'indirizzi', 'ZDT', null, 1],
            ['A', $this->scuolaA, 'classi', '2', 'ZDS', 1], ['A', $this->scuolaA, 'classi', '2A', 'ZDS', 1],
            ['A', $this->scuolaA, 'materie', 'ZDM', null, 1],
            ['B', $this->scuolaB, 'indirizzi', 'ZDS', null, 1], ['B', $this->scuolaB, 'classi', '2A', 'ZDS', 1],
            ['B', $this->scuolaB, 'classi', '2B', 'ZDS', 1], ['B', $this->scuolaB, 'classi', '3S', null, 0],
            ['B', $this->scuolaB, 'materie', 'ZDMX', null, 1],
            ['C', $this->altraScuola, 'indirizzi', 'ZDS', null, 1], ['C', $this->altraScuola, 'classi', '2A', 'ZDS', 1],
            ['C', $this->altraScuola, 'materie', 'ZDM', null, 1],
        ] as [$s, $iid, $kind, $code, $corso, $attiva]) {
            $voce->execute([$kind, $iid, $code, $code, $corso, $attiva]);
            $this->voce["$s:$code"] = (int)$this->pdo->lastInsertId();
        }

        $this->docente = $this->utente('zzdv_doc', 'teacher', null, [$this->scuolaA, $this->scuolaB]);
        $this->collega = $this->utente('zzdv_coll', 'teacher', null, [$this->scuolaA]);
        $spunta = $this->pdo->prepare('INSERT INTO curriculum_teacher (curriculum_id, user_id, active) VALUES (?, ?, 1)');
        foreach (['A:ZDS', 'A:ZDT', 'A:2', 'A:2A', 'A:ZDM', 'B:ZDS', 'B:2A', 'B:3S', 'B:ZDMX'] as $k) {
            $spunta->execute([$this->voce[$k], $this->docente]);
        }
        $this->pdo->prepare('INSERT INTO teacher_sections (user_id, institute_id, indirizzo, classe) VALUES (?, ?, "ZDS", "2A")')
            ->execute([$this->docente, $this->scuolaB]);

        $this->contenuto = (new TeacherContentRepository())->create([
            'teacher_id' => $this->docente, 'content_type' => 'document', 'subject_code' => 'ZDM',
            'indirizzo' => 'ZDS', 'classe' => '2', 'topic' => 'Dove vale', 'title' => 'Dove vale ' . uniqid(),
            'visibility' => 'published',
        ]);
        $this->doveVale = new DoveVale();
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $_SESSION = [];
        $_POST = [];
        \App\Core\Config::set('app.deployment_mode', 'single');
        \App\Support\CurriculumLookup::resetCache();
    }

    /** @param ?array{0:int,1:string,2:string} $studente @param list<int> $scuole */
    private function utente(string $username, string $role, ?array $studente, array $scuole): int
    {
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active,
                                institute_id, indirizzo, classe, created_at)
             VALUES (?, ?, "Zz", "DoveVale", ?, "x", "approved", 1, ?, ?, ?, NOW())'
        )->execute([$username, $role, $username . '@example.invalid', $studente[0] ?? null, $studente[1] ?? null, $studente[2] ?? null]);
        $id = (int)$this->pdo->lastInsertId();
        foreach ($scuole as $s) {
            $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')->execute([$id, $s]);
        }
        return $id;
    }

    private function nellaB(string $stato = 'published', string $classe = '2A'): int
    {
        return $this->doveVale->aggiungi(
            $this->contenuto,
            $this->docente,
            $this->scuolaB,
            $this->voce['B:ZDS'],
            $this->voce["B:$classe"],
            $this->voce['B:ZDMX'],
            $stato
        );
    }

    private function errore(callable $fai): string
    {
        try {
            $fai();
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }
        $this->fail('doveva rifiutare');
    }

    // ── aggiungere, elencare, togliere ───────────────────────────────────

    #[Test]
    public function il_docente_pubblica_lo_stesso_contenuto_in_un_altra_sua_scuola_con_le_voci_di_quella_scuola(): void
    {
        $id = $this->nellaB();
        $elenco = $this->doveVale->elenco($this->contenuto, $this->docente);
        $this->assertCount(2, $elenco['pubblicazioni']);
        $this->assertTrue($elenco['pubblicazioni'][0]['principale']);
        $nuova = array_values(array_filter($elenco['pubblicazioni'], static fn(array $p): bool => $p['id'] === $id))[0];
        $this->assertSame([$this->scuolaB, 'ZDS', '2A', 'ZDMX', 'docente', 'published'], [
            $nuova['scuola_id'], $nuova['indirizzo'], $nuova['classe'], $nuova['materia'], $nuova['origine'], $nuova['stato'],
        ]);

        $registro = $this->pdo->prepare(
            'SELECT details_json FROM audit_activity_log WHERE action = "pubblicazione_aggiunta" AND subject_id = ? ORDER BY id DESC LIMIT 1'
        );
        $registro->execute([(string)$this->contenuto]);
        $dettagli = json_decode((string)$registro->fetchColumn(), true);
        $this->assertSame($id, $dettagli['pubblicazione'] ?? null, 'a registro con gli id (S6)');
        $this->assertArrayNotHasKey('title', $dettagli, 'mai il contenuto');
    }

    #[Test]
    public function cambiare_stato_e_togliere_valgono_per_le_pubblicazioni_del_docente_e_non_per_la_principale(): void
    {
        $id = $this->nellaB();
        $this->doveVale->impostaStato($id, $this->docente, 'draft');
        $st = $this->pdo->prepare('SELECT visibility FROM content_publications WHERE id = ?');
        $st->execute([$id]);
        $this->assertSame('draft', $st->fetchColumn());

        $principale = (int)$this->pdo->query('SELECT id FROM content_publications WHERE primary_of_tc = ' . $this->contenuto)->fetchColumn();
        $this->assertSame('principale_non_si_toglie', $this->errore(fn() => $this->doveVale->togli($principale, $this->docente)));
        $this->assertSame('principale_non_si_toglie', $this->errore(fn() => $this->doveVale->impostaStato($principale, $this->docente, 'draft')));

        $this->doveVale->togli($id, $this->docente);
        $this->assertCount(1, $this->doveVale->elenco($this->contenuto, $this->docente)['pubblicazioni']);
    }

    // ── gli invarianti ───────────────────────────────────────────────────

    #[Test]
    public function s1_il_collega_non_tocca_le_pubblicazioni_del_docente_e_per_lui_il_contenuto_non_esiste(): void
    {
        $id = $this->nellaB();
        $this->assertSame('non_trovato', $this->errore(fn() => $this->doveVale->elenco($this->contenuto, $this->collega)));
        $this->assertSame('non_trovato', $this->errore(fn() => $this->doveVale->aggiungi(
            $this->contenuto,
            $this->collega,
            $this->scuolaA,
            $this->voce['A:ZDS'],
            $this->voce['A:2A'],
            $this->voce['A:ZDM']
        )));
        $this->assertSame('non_trovato', $this->errore(fn() => $this->doveVale->togli($id, $this->collega)));
        $this->assertSame('non_trovato', $this->errore(fn() => $this->doveVale->impostaStato($id, $this->collega, 'draft')));
    }

    #[Test]
    public function s2_non_si_pubblica_in_una_scuola_a_cui_il_docente_non_e_collegato(): void
    {
        $this->assertSame('scuola_non_tua', $this->errore(fn() => $this->doveVale->aggiungi(
            $this->contenuto,
            $this->docente,
            $this->altraScuola,
            $this->voce['C:ZDS'],
            $this->voce['C:2A'],
            $this->voce['C:ZDM']
        )));
    }

    #[Test]
    public function s3_voci_di_un_altra_scuola_spente_non_spuntate_o_di_un_altro_corso_sono_rifiutate(): void
    {
        $contenuto = $this->contenuto;
        $d = $this->docente;
        $b = $this->scuolaB;
        $v = $this->voce;
        $this->assertSame('voce_non_valida', $this->errore(fn() => $this->doveVale->aggiungi($contenuto, $d, $b, $v['B:ZDS'], $v['A:2A'], $v['B:ZDMX'])), 'classe della scuola A detta nella B');
        $this->assertSame('voce_non_valida', $this->errore(fn() => $this->doveVale->aggiungi($contenuto, $d, $b, $v['B:ZDS'], $v['B:3S'], $v['B:ZDMX'])), 'voce spenta');
        $this->assertSame('voce_non_valida', $this->errore(fn() => $this->doveVale->aggiungi($contenuto, $d, $b, $v['B:ZDMX'], $v['B:2A'], $v['B:ZDS'])), 'tipi scambiati');
        $this->assertSame('voce_non_spuntata', $this->errore(fn() => $this->doveVale->aggiungi($contenuto, $d, $b, $v['B:ZDS'], $v['B:2B'], $v['B:ZDMX'])), 'la 2B non è spuntata');
        $this->assertSame('classe_di_un_altro_corso', $this->errore(fn() => $this->doveVale->aggiungi($contenuto, $d, $this->scuolaA, $v['A:ZDT'], $v['A:2A'], $v['A:ZDM'])), 'la 2A è di ZDS');
        $this->assertSame('stato_non_valido', $this->errore(fn() => $this->doveVale->aggiungi($contenuto, $d, $b, $v['B:ZDS'], $v['B:2A'], $v['B:ZDMX'], 'pubblicato')));
    }

    #[Test]
    public function lo_stesso_posto_due_volte_no(): void
    {
        $this->nellaB();
        $this->assertSame('gia_pubblicato', $this->errore(fn() => $this->nellaB('draft')));
        $this->assertSame('gia_pubblicato', $this->errore(fn() => $this->doveVale->aggiungi(
            $this->contenuto,
            $this->docente,
            $this->scuolaA,
            $this->voce['A:ZDS'],
            $this->voce['A:2'],
            $this->voce['A:ZDM']
        )), 'dove sta già la principale');
    }

    #[Test]
    public function in_modalita_istituto_un_profilo_limitato_a_una_classe_non_pubblica_altrove(): void
    {
        \App\Core\Config::set('app.deployment_mode', 'institute');
        $this->pdo->prepare('INSERT INTO teacher_capability_overrides (user_id, capabilities) VALUES (?, ?)')
            ->execute([$this->docente, json_encode(['max_visibility' => 'class'])]);
        $this->assertSame('non_consentito', $this->errore(fn() => $this->nellaB()));

        // Il verso opposto: lo stesso profilo con «più classi» consentito pubblica.
        $this->pdo->prepare('UPDATE teacher_capability_overrides SET capabilities = ? WHERE user_id = ?')
            ->execute([json_encode(['max_visibility' => 'classes']), $this->docente]);
        $this->doveVale = new DoveVale();
        $this->assertGreaterThan(0, $this->nellaB());
    }

    // ── i lettori ────────────────────────────────────────────────────────

    #[Test]
    public function lo_studente_della_b_vede_il_contenuto_con_la_sigla_di_materia_della_b_e_non_con_quella_della_riga(): void
    {
        $repo = new TeacherContentRepository();
        $filtro = (new ContentVisibilityPolicy())->studyListFilters(ViewerContext::forStudent(1, $this->scuolaB, 'ZDS', '2A'));
        $vede = fn(string $materia): array => array_map(
            static fn(array $r): int => (int)$r['id'],
            $repo->search($filtro + ['content_type' => 'document', 'subject_code' => $materia, 'limit' => 500])
        );
        $this->assertNotContains($this->contenuto, $vede('ZDMX'), 'prima della pubblicazione nella B, niente');
        $pubblicazione = $this->nellaB();
        $this->assertContains($this->contenuto, $vede('ZDMX'), 'con la materia della B');
        $this->assertNotContains($this->contenuto, $vede('ZDM'), 'la sigla della riga, nella B, non esiste');

        $this->doveVale->impostaStato($pubblicazione, $this->docente, 'draft');
        $this->assertNotContains($this->contenuto, $vede('ZDMX'), 'in bozza nella B, lo studente della B non lo vede');
    }

    #[Test]
    public function la_barra_del_docente_nella_b_lo_elenca_con_le_sigle_della_b(): void
    {
        $repo = new TeacherContentRepository();
        $barra = fn(string $classe, string $materia): array => array_map(
            static fn(array $r): int => (int)$r['id'],
            $repo->search([
                'teacher_id' => $this->docente, 'pub_institute_id' => $this->scuolaB, 'pub_actor_id' => $this->docente,
                'indirizzo' => 'ZDS', 'classe' => $classe, 'subject_code' => $materia, 'content_type' => 'document', 'limit' => 500,
            ])
        );
        $this->assertNotContains($this->contenuto, $barra('2A', 'ZDMX'));
        $this->nellaB();
        $this->assertContains($this->contenuto, $barra('2A', 'ZDMX'));
        $this->assertNotContains($this->contenuto, $barra('2', 'ZDM'), 'le sigle della principale, nella B, non lo trovano');
    }

    // ── il modale non crea più «per più classi» ─────────────────────────

    #[Test]
    public function il_controller_rifiuta_un_contenuto_nuovo_per_piu_classi_e_indica_dove_vale(): void
    {
        $_SESSION = [
            'autenticato' => true, 'username' => 'zzdv_doc', 'user_id' => $this->docente,
            'user_role' => 'teacher', 'current_institute_id' => $this->scuolaA,
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/teacher/content';
        $_POST = [
            'type' => 'document', 'subject' => 'ZDM', 'indirizzo' => 'ZDS', 'classe' => '2',
            'topic' => 'Piu classi', 'title' => 'Piu classi ' . uniqid(), 'publish_scope' => 'classes',
        ];
        $res = (new TeacherContentController())->store(new Request());
        $this->assertSame(400, $res->status);
        $this->assertSame('usa_dove_vale', json_decode($res->body, true)['error'] ?? null);

        // Il verso opposto: un contenuto che «per più classi» lo era già si
        // salva ancora con quello scope (il modale lo rimanda com'è).
        $this->pdo->prepare('UPDATE teacher_content_data SET publish_scope = "classes" WHERE id = ?')->execute([$this->contenuto]);
        $_SERVER['REQUEST_URI'] = '/api/teacher/content/' . $this->contenuto . '/update';
        $_POST = ['title' => 'Rinominato ' . uniqid(), 'publish_scope' => 'classes'];
        $res = (new TeacherContentController())->update(new Request(), ['id' => (string)$this->contenuto]);
        $this->assertSame(200, $res->status, $res->body);
    }
}
