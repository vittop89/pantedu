<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Repositories\Sharing\PoolRepository;
use App\Repositories\TeacherContentRepository;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PoolRepository (P6, 2026-09-04) — il SQL estratto da PoolController deve
 * dare gli stessi fatti: un contenuto col flag pool e' recuperabile dai
 * colleghi dello stesso istituto e compare fra le condivisioni del suo
 * owner; tolto il flag, sparisce da entrambe le liste.
 *
 * Fixture isolata in transazione (rollback in tearDown): un istituto con
 * la materia anchor, due docenti (owner e collega), un contenuto creato
 * dal repository dei contenuti come farebbe l'app.
 */
final class PoolRepositoryTest extends TestCase
{
    private PDO $pdo;
    private bool $inTx = false;
    private PoolRepository $pool;
    private int $inst = 0;
    private int $owner = 0;
    private int $actor = 0;
    private int $contentId = 0;
    private int $subjectId = 0;

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
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1 FROM content_shares LIMIT 1');
            $this->pdo->query('SELECT 1 FROM teacher_content LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB o migrazioni non disponibili: ' . $e->getMessage());
        }

        $this->pdo->beginTransaction();
        $this->inTx = true;

        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
            ->execute(['ZZPOOL01', 'ISTITUTO POOL', 'Comune Esempio']);
        $this->inst = (int)$this->pdo->lastInsertId();

        // Il vocabolario dell'istituto: il repository dei contenuti lo spunta
        // per il docente quando risolve il codice materia (ADR-035).
        $ins = $this->pdo->prepare(
            'INSERT INTO curriculum_entries
                (kind, institute_id, code, label, active, shared_with_pool)
             VALUES (?, ?, ?, ?, 1, 0)'
        );
        foreach ([['materie', 'ZPL'], ['indirizzi', 'ZPI'], ['classi', '1Z']] as [$kind, $code]) {
            $ins->execute([$kind, $this->inst, $code, $code]);
        }

        $insUser = $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash,
                                status, active, created_at)
             VALUES (?, "teacher", ?, "Pool", ?, "x", "approved", 1, NOW())'
        );
        $insPivot = $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)');
        $insUser->execute(['zzpool_owner', 'Olga', 'zzpool_owner@example.invalid']);
        $this->owner = (int)$this->pdo->lastInsertId();
        $insPivot->execute([$this->owner, $this->inst]);
        $insUser->execute(['zzpool_actor', 'Aldo', 'zzpool_actor@example.invalid']);
        $this->actor = (int)$this->pdo->lastInsertId();
        $insPivot->execute([$this->actor, $this->inst]);

        $this->contentId = (new TeacherContentRepository())->create([
            'teacher_id'   => $this->owner,
            'content_type' => 'esercizio',
            'subject_code' => 'ZPL',
            'indirizzo'    => 'ZPI',
            'classe'       => '1Z',
            'topic'        => 'POOL_' . uniqid(),
            'title'        => 'Esercizio nel pool',
            'body_html'    => '<p>x</p>',
            'visibility'   => 'published',
        ]);
        // Un esercizio del docente (`personal`): è l'unico che l'app lascia
        // condividere. Senza fonte, o dal libro, resta fuori dal pool (vedi
        // con_la_materia_condivisa_un_esercizio_dal_libro_resta_del_suo_docente).
        $this->pdo->prepare('UPDATE teacher_content_data SET shared_with_pool = 1, source_type = "personal" WHERE id = ?')
            ->execute([$this->contentId]);
        $this->subjectId = (int)$this->pdo
            ->query('SELECT subject_id FROM teacher_content WHERE id = ' . $this->contentId)
            ->fetchColumn();

        $this->pool = new PoolRepository();
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    #[Test]
    public function institutes_and_groups_of_the_actor(): void
    {
        self::assertSame([$this->inst], $this->pool->institutesOf($this->actor));
        self::assertSame($this->inst, $this->pool->firstInstituteOf($this->actor));
        self::assertSame(0, $this->pool->firstInstituteOf(999999999));
        self::assertSame([], $this->pool->groupsOfMember($this->actor));
    }

    #[Test]
    public function shared_content_is_eligible_for_colleagues_until_unshared(): void
    {
        $rows = $this->pool->eligibleTeacherContent($this->actor, [$this->inst], [], null, null, null);
        $ids = array_map(static fn(array $r) => (int)$r['id'], $rows);
        self::assertContains($this->contentId, $ids);
        $row = $rows[array_search($this->contentId, $ids, true)];
        self::assertSame('ZPL', $row['subject_code']);
        self::assertSame($this->owner, (int)$row['owner_id']);
        self::assertNull($row['my_recovered_id']);

        // filtri: tipo, materia, owner
        self::assertSame([], $this->pool->eligibleTeacherContent($this->actor, [$this->inst], [], 'mappa', null, null));
        self::assertSame([], $this->pool->eligibleTeacherContent($this->actor, [$this->inst], [], null, 'ZZZ', null));
        self::assertNotSame([], $this->pool->eligibleTeacherContent($this->actor, [$this->inst], [], 'esercizio', 'ZPL', $this->owner));
        // l'owner non vede i propri contenuti nel pool
        self::assertNotContains(
            $this->contentId,
            array_map(static fn(array $r) => (int)$r['id'], $this->pool->eligibleTeacherContent($this->owner, [$this->inst], [], null, null, null))
        );
        self::assertSame([], $this->pool->eligibleVerificaDocuments($this->actor, [$this->inst], [], null, null));

        $mine = $this->pool->sharedTeacherContentOf($this->owner);
        self::assertSame([$this->contentId], array_map(static fn(array $r) => (int)$r['id'], $mine));
        self::assertSame(0, (int)$mine[0]['grants_count']);
        self::assertSame([], $this->pool->sharedVerificaDocumentsOf($this->owner));

        self::assertSame(0, $this->pool->unshareTeacherContent($this->actor, [$this->contentId]), 'solo l\'owner');
        self::assertSame(1, $this->pool->unshareTeacherContent($this->owner, [$this->contentId]));
        self::assertSame([], $this->pool->sharedTeacherContentOf($this->owner));
        self::assertNotContains(
            $this->contentId,
            array_map(static fn(array $r) => (int)$r['id'], $this->pool->eligibleTeacherContent($this->actor, [$this->inst], [], null, null, null))
        );
    }

    #[Test]
    public function recovery_lookups_read_the_same_facts_as_before(): void
    {
        $src = $this->pool->sourceWithSubject($this->contentId);
        self::assertNotNull($src);
        self::assertSame('ZPL', $src['subject_code']);
        self::assertSame($this->owner, (int)$src['teacher_id']);
        self::assertNull($this->pool->sourceWithSubject(999999999));

        self::assertNotNull($this->pool->ownMateria($this->owner, $this->subjectId));
        self::assertNull($this->pool->ownMateria($this->actor, $this->subjectId), 'materia di un altro docente');
        self::assertSame($this->inst, $this->pool->instituteOfCatalogEntry($this->subjectId));

        $indId = (int)$this->pdo->query("SELECT id FROM curriculum_entries WHERE kind='indirizzi' AND code='ZPI' AND institute_id={$this->inst}")->fetchColumn();
        self::assertSame('ZPI', $this->pool->catalogCode($indId, 'indirizzi', $this->inst));
        self::assertNull($this->pool->catalogCode($indId, 'classi', $this->inst), 'kind sbagliato');
        self::assertNull($this->pool->catalogCode($indId, 'indirizzi', $this->inst + 1), 'istituto sbagliato');

        self::assertSame('Olga Pool', $this->pool->ownerName($this->owner));
        self::assertNull($this->pool->ownerName(999999999));

        $this->pool->setMetadataJson($this->contentId, '{"contract_key":"k"}');
        self::assertSame('{"contract_key":"k"}', $this->pool->metadataJson($this->contentId));

        // Il collega recupera il contenuto: il clone punta alla sorgente
        // (FK source_content_id) e la lista del pool lo segnala come gia' recuperato.
        $clone = (new TeacherContentRepository())->create([
            'teacher_id'   => $this->actor,
            'content_type' => 'esercizio',
            'subject_code' => 'ZPL',
            'topic'        => 'POOL_' . uniqid(),
            'title'        => 'Esercizio nel pool (importata da Olga Pool)',
            'body_html'    => '<p>x</p>',
            'visibility'   => 'draft',
        ]);
        $this->pool->markRecovered($clone, $this->contentId);
        $row = $this->pdo->query('SELECT source_content_id, shared_with_pool FROM teacher_content WHERE id = ' . $clone)->fetch(PDO::FETCH_ASSOC);
        self::assertSame($this->contentId, (int)$row['source_content_id']);
        self::assertSame(0, (int)$row['shared_with_pool']);
        $rows = $this->pool->eligibleTeacherContent($this->actor, [$this->inst], [], null, null, $this->owner);
        self::assertSame($clone, (int)$rows[0]['my_recovered_id']);

        $this->pool->deleteContentRow($clone);
        self::assertNull($this->pool->sourceWithSubject($clone));
    }
    #[Test]
    public function condividere_tutta_la_materia_e_la_spunta_del_docente_non_la_voce_della_scuola(): void
    {
        // ADR-035: «Condividi tutta la materia» sta su curriculum_teacher (la
        // spunta dell'owner), non su curriculum_entries (la voce della scuola).
        // Il 13/9/2026 il pool leggeva ancora la voce: la materia condivisa dal
        // profilo non faceva entrare nessun contenuto.
        $this->pdo->prepare('UPDATE teacher_content_data SET shared_with_pool = 0 WHERE id = ?')
            ->execute([$this->contentId]);
        $ids = static fn(array $rows): array => array_map(static fn(array $r) => (int)$r['id'], $rows);
        self::assertNotContains($this->contentId, $ids($this->pool->eligibleTeacherContent($this->actor, [$this->inst], [], null, null, null)), 'riga non condivisa, materia non condivisa: fuori');

        $spunta = $this->pdo->prepare('UPDATE curriculum_teacher SET shared_with_pool = ? WHERE curriculum_id = ? AND user_id = ?');
        $spunta->execute([1, $this->subjectId, $this->owner]);
        self::assertSame(1, $spunta->rowCount(), "la spunta dell'owner sulla materia esiste (la mette il repository dei contenuti)");
        $rows = $this->pool->eligibleTeacherContent($this->actor, [$this->inst], [], null, null, null);
        self::assertContains($this->contentId, $ids($rows), "materia condivisa dall'owner: il contenuto entra nel pool");
        $row = $rows[array_search($this->contentId, $ids($rows), true)];
        self::assertSame(1, (int)$row['materia_shared']);
        self::assertSame(0, (int)$row['row_shared']);
        $src = $this->pool->sourceWithSubject($this->contentId);
        self::assertSame(1, (int)($src['materia_shared'] ?? 0), 'e il recupero legge lo stesso fatto');

        // La voce della scuola col flag acceso non basta: non e' del docente.
        $spunta->execute([0, $this->subjectId, $this->owner]);
        $this->pdo->prepare('UPDATE curriculum_entries SET shared_with_pool = 1 WHERE id = ?')->execute([$this->subjectId]);
        self::assertNotContains($this->contentId, $ids($this->pool->eligibleTeacherContent($this->actor, [$this->inst], [], null, null, null)), 'il flag sulla voce della scuola non condivide niente');
        self::assertSame(0, (int)($this->pool->sourceWithSubject($this->contentId)['materia_shared'] ?? 1));
    }

    #[Test]
    public function con_la_materia_condivisa_un_esercizio_dal_libro_resta_del_suo_docente(): void
    {
        // 24/9/2026 — la condivisione del singolo esercizio rifiuta quelli
        // tratti dal libro (SharedContentPolicy::toggleSharePool, copyright_block),
        // ma «Condividi tutta la materia» li faceva entrare nel pool senza
        // passare da quel controllo, e il recupero guardava solo se il collega
        // poteva leggerli. Il materiale del libro è per uso personale del
        // docente (Termini 1.6, §2.1): non arriva ai colleghi per nessuna strada.
        $ids = static fn(array $rows): array => array_map(static fn(array $r) => (int)$r['id'], $rows);
        $fonte = $this->pdo->prepare('UPDATE teacher_content_data SET shared_with_pool = 0, source_type = ? WHERE id = ?');
        $this->pdo->prepare('UPDATE curriculum_teacher SET shared_with_pool = 1 WHERE curriculum_id = ? AND user_id = ?')
            ->execute([$this->subjectId, $this->owner]);
        $policy = new \App\Services\Sharing\SharedContentPolicy();
        $leggibile = fn(): bool => $policy->canReadContent($this->actor, 'teacher_content', $this->contentId, $this->owner, false, true);

        // Il verso che deve lasciar passare: un esercizio del docente.
        $fonte->execute(['personal', $this->contentId]);
        self::assertContains($this->contentId, $ids($this->pool->eligibleTeacherContent($this->actor, [$this->inst], [], null, null, null)), 'esercizio proprio in una materia condivisa: entra');
        self::assertTrue($leggibile(), 'e il collega lo può leggere e recuperare');

        // Il verso che deve fermare: dal libro, misto, o senza fonte dichiarata.
        foreach (['book_textbook', 'mixed', null] as $tipo) {
            $fonte->execute([$tipo, $this->contentId]);
            $etichetta = $tipo ?? 'senza fonte';
            self::assertNotContains($this->contentId, $ids($this->pool->eligibleTeacherContent($this->actor, [$this->inst], [], null, null, null)), "esercizio {$etichetta} in una materia condivisa: resta fuori dal pool");
            self::assertFalse($leggibile(), "esercizio {$etichetta}: il collega non lo legge e non lo recupera");
            self::assertTrue(
                $policy->canReadContent($this->owner, 'teacher_content', $this->contentId, $this->owner, false, true),
                'il docente che l\'ha creato continua a vederlo'
            );
        }
    }
}
