<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\VerificaController;
use App\Core\Database;
use App\Core\Request;
use App\Domain\ContentVisibilityPolicy;
use App\Domain\ViewerContext;
use App\Repositories\Sharing\PoolRepository;
use App\Repositories\VerificaDocumentRepository;
use App\Support\PostoPrincipale;
use App\Services\Sharing\SharedContentPolicy;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR-037, fase 3 — le verifiche nelle pubblicazioni: i trigger e i lettori.
 *
 * Due scuole con le stesse sigle (corso ZVS, anno 2, sezioni 2A e 2B,
 * materia ZVM). Il docente lavora in tutte e due, con gli incarichi in 2A e
 * 2B della A e in 2A della B; un collega per scuola; uno studente in 2A della
 * A, uno in 2B della A e uno in 2A della B. Una verifica di due varianti nasce
 * nella A, su ZVS/2A/ZVM.
 *
 * Si prova, ogni volta nei due versi:
 *   - i trigger: la principale nasce in bozza, segue le etichette tenendo lo
 *     stato, se ne va senza etichette, cade con la verifica; la verifica di
 *     allineamento passa e scatta;
 *   - condividere con i colleghi non pubblica agli studenti (ADR-032);
 *   - studenti, credenziali, barra del docente, pool, permessi fra colleghi e
 *     grant di istituto guardano la scuola della pubblicazione, non le sigle;
 *   - il controller dell'elenco di studio passa da lì per studenti e docenti.
 *
 * Fixture isolata in transazione (rollback in tearDown).
 */
final class VerifichePubblicazioniTest extends TestCase
{
    private PDO $pdo;
    private bool $inTx = false;
    private int $scuolaA = 0;
    private int $scuolaB = 0;
    private int $docente = 0;
    private int $collegaA = 0;
    private int $collegaB = 0;
    /** @var array<string,array{id:int,username:string}> */
    private array $studenti = [];
    /** @var array<string,int> "A:2A" → id */
    private array $voce = [];
    /** Le due varianti della verifica nata nella A. @var list<int> */
    private array $varianti = [];

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
            // La 119 si riconosce dal registro delle migrazioni: la sua procedura di
            // ricalcolo l'ha tolta la 122 (ADR-037, fase 4c-2), e sondare quella
            // avrebbe fatto saltare queste prove in silenzio.
            $this->pdo->query('SELECT 1 FROM schema_migrations WHERE filename LIKE "119\_%"')->fetchColumn()
                ?: throw new \RuntimeException('migrazione 119 non applicata');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB o migrazione 119 non disponibili: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();
        $this->inTx = true;
        $_SESSION = [];
        \App\Support\CurriculumLookup::resetCache();
        \App\Support\ClassAccessGrant::resetCache();

        $ist = $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)');
        $ist->execute(['ZZVPA001', 'SCUOLA A VERIFICHE', 'Comune Esempio']);
        $this->scuolaA = (int)$this->pdo->lastInsertId();
        $ist->execute(['ZZVPB001', 'SCUOLA B VERIFICHE', 'Comune Esempio']);
        $this->scuolaB = (int)$this->pdo->lastInsertId();

        $voce = $this->pdo->prepare(
            'INSERT INTO curriculum_entries (kind, institute_id, code, label, indirizzo, active, shared_with_pool, origine)
             VALUES (?, ?, ?, ?, ?, 1, 0, "istituto")'
        );
        foreach (['A' => $this->scuolaA, 'B' => $this->scuolaB] as $s => $iid) {
            foreach ([['indirizzi', 'ZVS', null], ['classi', '2', 'ZVS'], ['classi', '2A', 'ZVS'], ['classi', '2B', 'ZVS'], ['materie', 'ZVM', null]] as [$kind, $code, $corso]) {
                $voce->execute([$kind, $iid, $code, $code, $corso]);
                $this->voce["$s:$code"] = (int)$this->pdo->lastInsertId();
            }
        }

        $this->docente  = $this->utente('zzvp_doc', 'teacher', null, [$this->scuolaA, $this->scuolaB]);
        $this->collegaA = $this->utente('zzvp_colla', 'teacher', null, [$this->scuolaA]);
        $this->collegaB = $this->utente('zzvp_collb', 'teacher', null, [$this->scuolaB]);
        foreach (['A2A' => [$this->scuolaA, '2A'], 'A2B' => [$this->scuolaA, '2B'], 'B2A' => [$this->scuolaB, '2A']] as $nome => [$s, $cls]) {
            $u = 'zzvp_stud_' . strtolower($nome);
            $this->studenti[$nome] = ['id' => $this->utente($u, 'student', [$s, 'ZVS', $cls], []), 'username' => $u];
        }
        $inc = $this->pdo->prepare('INSERT INTO teacher_sections (user_id, institute_id, indirizzo, classe) VALUES (?, ?, "ZVS", ?)');
        foreach ([[$this->scuolaA, '2A'], [$this->scuolaA, '2B'], [$this->scuolaB, '2A']] as [$s, $cls]) {
            $inc->execute([$this->docente, $s, $cls]);
        }

        // Nessuna sessione: il repository risolve le sigle nel primo istituto
        // del docente, cioè la scuola A (creata per prima).
        $titolo = 'Frazioni ' . uniqid();
        $pacchetto = strtoupper(substr(bin2hex(random_bytes(13)), 0, 26));
        foreach (['A_SOL', 'A_NOR'] as $variante) {
            $this->varianti[] = (new VerificaDocumentRepository())->create([
                'teacher_id' => $this->docente, 'materia' => 'ZVM', 'indirizzo' => 'ZVS', 'classe' => '2A',
                'title' => "{$titolo} — {$variante}", 'batch_id' => $pacchetto, 'variant' => $variante,
                'exercise_ids' => [],
            ]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $_SESSION = [];
        $_GET = [];
        \App\Support\ClassAccessGrant::resetCache();
        \App\Support\CurriculumLookup::resetCache();
    }

    /** @param ?array{0:int,1:string,2:string} $studente @param list<int> $scuole */
    private function utente(string $username, string $role, ?array $studente, array $scuole): int
    {
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active,
                                institute_id, indirizzo, classe, created_at)
             VALUES (?, ?, "Zz", "Verifiche", ?, "x", "approved", 1, ?, ?, ?, NOW())'
        )->execute([$username, $role, $username . '@example.invalid', $studente[0] ?? null, $studente[1] ?? null, $studente[2] ?? null]);
        $id = (int)$this->pdo->lastInsertId();
        foreach ($scuole as $s) {
            $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')->execute([$id, $s]);
        }
        return $id;
    }

    /** @return list<array<string,mixed>> */
    private function pubblicazioni(int $variante): array
    {
        $st = $this->pdo->prepare('SELECT * FROM content_publications WHERE verifica_document_id = ? ORDER BY is_primary DESC, id');
        $st->execute([$variante]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function statoPrincipale(string $stato): void
    {
        $this->pdo->prepare('UPDATE content_publications SET visibility = ? WHERE primary_of_vd IN (?, ?)')
            ->execute([$stato, ...$this->varianti]);
    }

    /** Aggiunge alle due varianti una pubblicazione del docente nella scuola e classe date. */
    private function pubblicaAnche(string $scuola, string $classe, string $stato = 'published'): void
    {
        $ins = $this->pdo->prepare(
            'INSERT INTO content_publications
                (verifica_document_id, institute_id, indirizzo_id, classe_id, subject_id, is_primary, origine, visibility)
             VALUES (?, ?, ?, ?, ?, 0, "docente", ?)'
        );
        foreach ($this->varianti as $v) {
            $ins->execute([$v, $scuola === 'A' ? $this->scuolaA : $this->scuolaB, $this->voce["$scuola:ZVS"], $this->voce["$scuola:$classe"], $this->voce["$scuola:ZVM"], $stato]);
        }
    }

    /** @param array<string,mixed> $filtri @return list<int> */
    private function perLoStudio(array $filtri): array
    {
        return array_map(static fn(array $r): int => (int)$r['id'], (new VerificaDocumentRepository())->listForStudy($filtri));
    }

    /** @return list<int> */
    private function daStudente(int $scuola, string $classe): array
    {
        return $this->perLoStudio((new ContentVisibilityPolicy())->studyListFilters(
            ViewerContext::forStudent(1, $scuola, 'ZVS', $classe)
        ));
    }

    /** @return list<int> */
    private function daCredenziale(?int $scuola, ?string $classe = '2A'): array
    {
        return $this->perLoStudio((new ContentVisibilityPolicy())->studyListFilters(
            ViewerContext::forKeychain([[
                'teacher_id' => $this->docente, 'institute_id' => $scuola, 'indirizzo' => 'ZVS', 'classe' => $classe,
                'credential_id' => 1, 'label' => 'prova',
            ]])
        ));
    }

    // ── i trigger ────────────────────────────────────────────────────────

    #[Test]
    public function la_verifica_nuova_ha_la_principale_in_bozza_nella_scuola_delle_sue_etichette(): void
    {
        foreach ($this->varianti as $v) {
            $pub = $this->pubblicazioni($v);
            $this->assertCount(1, $pub, 'una pubblicazione per variante');
            $this->assertSame(1, (int)$pub[0]['is_primary']);
            $this->assertSame('riga', $pub[0]['origine']);
            $this->assertSame($this->scuolaA, (int)$pub[0]['institute_id']);
            $this->assertSame($this->voce['A:2A'], (int)$pub[0]['classe_id']);
            $this->assertSame($this->voce['A:ZVM'], (int)$pub[0]['subject_id']);
            $this->assertSame('draft', $pub[0]['visibility'], 'una verifica nuova non è pubblicata a nessuno');
        }
    }

    #[Test]
    public function condividere_con_i_colleghi_non_pubblica_agli_studenti(): void
    {
        $this->pdo->prepare('UPDATE verifica_documents_data SET shared_with_pool = 1, source_type = "personal" WHERE id IN (?, ?)')->execute($this->varianti);
        $this->assertSame('draft', $this->pubblicazioni($this->varianti[0])[0]['visibility'], 'la condivisione non tocca lo stato');
        $this->assertSame([], $this->daStudente($this->scuolaA, '2A'), 'condivisa ma in bozza: lo studente non la vede');

        $this->statoPrincipale('published');
        $this->assertEqualsCanonicalizing($this->varianti, $this->daStudente($this->scuolaA, '2A'), 'pubblicata: sì');
    }

    #[Test]
    public function cambiare_le_etichette_sposta_la_principale_e_ne_conserva_lo_stato(): void
    {
        $this->statoPrincipale('published');
        // ADR-037, fase 4c-2: il posto lo scrive l'applicazione, non la riga.
        PostoPrincipale::verifica($this->pdo, $this->varianti[0], $this->voce['A:ZVS'], $this->voce['A:2B'], $this->voce['A:ZVM']);
        $pub = $this->pubblicazioni($this->varianti[0]);
        $this->assertCount(1, $pub);
        $this->assertSame($this->voce['A:2B'], (int)$pub[0]['classe_id'], 'la principale va nel posto nuovo');
        $this->assertSame('published', $pub[0]['visibility'], 'e tiene lo stato scelto dal docente');
    }

    #[Test]
    public function senza_etichette_la_principale_se_ne_va_e_cancellare_la_verifica_porta_via_tutto(): void
    {
        PostoPrincipale::verifica($this->pdo, $this->varianti[0], null, null, null);
        $this->assertSame([], $this->pubblicazioni($this->varianti[0]), 'senza scuola nessuna principale');

        PostoPrincipale::verifica($this->pdo, $this->varianti[0], $this->voce['A:ZVS'], $this->voce['A:2A'], $this->voce['A:ZVM']);
        $this->assertCount(1, $this->pubblicazioni($this->varianti[0]), 'con le etichette torna, in bozza');

        $this->pubblicaAnche('B', '2A');
        $this->assertCount(2, $this->pubblicazioni($this->varianti[0]));
        (new VerificaDocumentRepository())->delete($this->varianti[0]);
        $this->assertSame([], $this->pubblicazioni($this->varianti[0]), 'la cascata porta via anche quelle del docente');
    }

    #[Test]
    public function la_verifica_di_allineamento_passa_e_scatta_su_una_principale_fuori_posto(): void
    {
        $this->pdo->query('CALL pub_verifica_allineamento()')->closeCursor();
        $this->addToAssertionCount(1);

        // Dalla 122 (ADR-037, fase 4c-2) il posto sta solo nella pubblicazione, e
        // non c'è una riga con cui confrontarlo: il guasto che la verifica vede è
        // una principale spostata in un'altra scuola con le voci di questa.
        $this->pdo->prepare('UPDATE content_publications SET institute_id = ? WHERE primary_of_vd = ?')
            ->execute([$this->scuolaB, $this->varianti[0]]);
        try {
            $this->pdo->query('CALL pub_verifica_allineamento()')->closeCursor();
            $this->fail('la verifica di allineamento doveva fallire');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('in una scuola diversa da quella delle loro voci 1', $e->getMessage());
        }
    }

    /** La materia di una verifica resta nella riga (fase 4c-2): la principale deve dire la stessa, e deve esserci. */
    #[Test]
    public function la_verifica_di_allineamento_confronta_la_materia_della_riga_con_la_principale(): void
    {
        $this->pdo->query('CALL pub_verifica_allineamento()')->closeCursor();

        $this->pdo->prepare('UPDATE content_publications SET subject_id = NULL WHERE primary_of_vd = ?')->execute([$this->varianti[0]]);
        try {
            $this->pdo->query('CALL pub_verifica_allineamento()')->closeCursor();
            $this->fail('doveva fallire: la principale ha perso la materia della riga');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('verifiche con una materia diversa dalla principale 1', $e->getMessage());
        }

        $this->pdo->prepare('DELETE FROM content_publications WHERE primary_of_vd = ?')->execute([$this->varianti[0]]);
        try {
            $this->pdo->query('CALL pub_verifica_allineamento()')->closeCursor();
            $this->fail('doveva fallire: una verifica con la materia e senza principale');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('verifiche con la materia e senza principale 1', $e->getMessage());
        }
    }

    // ── studenti e credenziali ───────────────────────────────────────────

    #[Test]
    public function lo_studente_dell_altra_scuola_con_le_stesse_sigle_la_vede_solo_dopo_una_pubblicazione_li(): void
    {
        $this->statoPrincipale('published');
        $this->assertEqualsCanonicalizing($this->varianti, $this->daStudente($this->scuolaA, '2A'));
        $this->assertSame([], $this->daStudente($this->scuolaB, '2A'), 'stesse sigle, altra scuola: no');

        $this->pubblicaAnche('B', '2A');
        $this->assertEqualsCanonicalizing($this->varianti, $this->daStudente($this->scuolaB, '2A'), 'pubblicata anche nella B: sì');
    }

    #[Test]
    public function lo_studente_vede_la_sua_sezione_e_il_suo_anno_non_l_altra_sezione_e_senza_incarico_niente(): void
    {
        $this->statoPrincipale('published');
        $this->assertSame([], $this->daStudente($this->scuolaA, '2B'), 'la 2A non raggiunge la 2B');

        foreach ($this->varianti as $variante) {
            PostoPrincipale::verifica($this->pdo, $variante, $this->voce['A:ZVS'], $this->voce['A:2'], $this->voce['A:ZVM']);
        }
        $this->assertEqualsCanonicalizing($this->varianti, $this->daStudente($this->scuolaA, '2B'), 'l\'anno copre le sue sezioni');

        $this->pdo->prepare('DELETE FROM teacher_sections WHERE user_id = ? AND institute_id = ? AND classe = "2B"')
            ->execute([$this->docente, $this->scuolaA]);
        $this->assertSame([], $this->daStudente($this->scuolaA, '2B'), 'senza incarico nella sua sezione, niente');
    }

    #[Test]
    public function la_credenziale_della_scuola_vede_la_pubblicata_e_quella_dell_altra_scuola_no(): void
    {
        $this->assertSame([], $this->daCredenziale($this->scuolaA), 'in bozza: no');
        $this->statoPrincipale('published');
        $this->assertEqualsCanonicalizing($this->varianti, $this->daCredenziale($this->scuolaA));
        $this->assertSame([], $this->daCredenziale($this->scuolaB), 'la credenziale della B non la vede');
        $this->assertSame([], $this->daCredenziale($this->scuolaA, '2B'), 'né quella delimitata a un\'altra sezione');
    }

    // ── docenti ──────────────────────────────────────────────────────────

    #[Test]
    public function la_barra_del_docente_mostra_la_verifica_nella_sua_scuola_e_non_nell_altra(): void
    {
        $repo = new VerificaDocumentRepository();
        $barra = fn(int $scuola): array => array_map(
            static fn(array $r): int => (int)$r['id'],
            $repo->listForTeacher($this->docente, 'ZVM', null, 'ZVS', '2A', $scuola)
        );
        $this->assertEqualsCanonicalizing($this->varianti, $barra($this->scuolaA));
        $this->assertSame([], $barra($this->scuolaB), 'stesse sigle nella B: prima della fase 3 compariva');
        $this->assertCount(2, $repo->listForTeacher($this->docente, 'ZVM', null, 'ZVS', '2A'), 'senza scuola, per il proprietario, come prima');

        $this->pubblicaAnche('B', '2A', 'draft');
        $this->assertEqualsCanonicalizing($this->varianti, $barra($this->scuolaB), 'con un posto nella B, anche in bozza');
    }

    #[Test]
    public function pool_e_permessi_fra_colleghi_seguono_la_scuola_della_pubblicazione(): void
    {
        $this->pdo->prepare('UPDATE verifica_documents_data SET shared_with_pool = 1, source_type = "personal" WHERE id IN (?, ?)')->execute($this->varianti);
        $pool = new PoolRepository();
        $policy = new SharedContentPolicy();
        $nelPool = fn(int $collega, int $scuola): array => array_map(
            static fn(array $r): int => (int)$r['id'],
            $pool->eligibleVerificaDocuments($collega, [$scuola], [], null, null)
        );
        $this->assertEqualsCanonicalizing($this->varianti, $nelPool($this->collegaA, $this->scuolaA));
        $this->assertTrue($policy->canReadContent($this->collegaA, 'verifica_documents', $this->varianti[0], $this->docente, true));
        $this->assertSame([], $nelPool($this->collegaB, $this->scuolaB), 'il collega della B non la vede: la verifica non è pubblicata lì');
        $this->assertFalse($policy->canReadContent($this->collegaB, 'verifica_documents', $this->varianti[0], $this->docente, true));

        $this->pubblicaAnche('B', '2A', 'draft');
        $this->assertEqualsCanonicalizing($this->varianti, $nelPool($this->collegaB, $this->scuolaB), 'con un posto nella B, sì');
        $this->assertTrue($policy->canReadContent($this->collegaB, 'verifica_documents', $this->varianti[0], $this->docente, true));
    }

    #[Test]
    public function il_grant_di_istituto_per_una_verifica_si_accetta_solo_dove_e_pubblicata(): void
    {
        $policy = new SharedContentPolicy();
        $this->assertTrue($policy->validateTarget($this->docente, 'verifica_documents', $this->varianti[0], 'institute', $this->scuolaA));
        $this->assertFalse(
            $policy->validateTarget($this->docente, 'verifica_documents', $this->varianti[0], 'institute', $this->scuolaB),
            'il docente è anche nella B, ma lì la verifica non ha un posto'
        );
        $this->pubblicaAnche('B', '2A', 'draft');
        $this->assertTrue($policy->validateTarget($this->docente, 'verifica_documents', $this->varianti[0], 'institute', $this->scuolaB));
    }

    // ── il controller dell'elenco di studio ─────────────────────────────

    /** @return list<int> */
    private function elencoDiStudio(string $username, int $id, string $ruolo): array
    {
        $_SESSION = ['autenticato' => true, 'username' => $username, 'user_id' => $id, 'user_role' => $ruolo];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/study/verifica/list';
        $_GET = ['indirizzo' => 'ZVS', 'classe' => '2A'];
        \App\Support\CurriculumLookup::resetCache();
        $risposta = (new VerificaController())->listForStudent(new Request());
        $_GET = [];
        $this->assertSame(200, $risposta->status, (string)$risposta->body);
        $json = json_decode((string)$risposta->body, true);
        return array_map(static fn(array $i): int => (int)$i['id'], $json['items'] ?? []);
    }

    #[Test]
    public function l_elenco_di_studio_passa_dalle_pubblicazioni_per_lo_studente_e_dall_acl_per_il_collega(): void
    {
        $studente = $this->studenti['A2A'];
        $this->assertSame([], $this->elencoDiStudio($studente['username'], $studente['id'], 'student'), 'in bozza: niente');
        $this->statoPrincipale('published');
        $this->assertEqualsCanonicalizing($this->varianti, $this->elencoDiStudio($studente['username'], $studente['id'], 'student'));
        $altro = $this->studenti['B2A'];
        $this->assertSame([], $this->elencoDiStudio($altro['username'], $altro['id'], 'student'), 'lo studente della B no');

        $this->assertSame([], $this->elencoDiStudio('zzvp_colla', $this->collegaA, 'teacher'), 'pubblicata ma non condivisa: il collega no');
        $this->pdo->prepare('UPDATE verifica_documents_data SET shared_with_pool = 1, source_type = "personal" WHERE id IN (?, ?)')->execute($this->varianti);
        $this->assertEqualsCanonicalizing($this->varianti, $this->elencoDiStudio('zzvp_colla', $this->collegaA, 'teacher'), 'condivisa: sì');
    }
}
