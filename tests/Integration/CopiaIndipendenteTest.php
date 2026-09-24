<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Repositories\Contract\ContractRepository;
use App\Repositories\TeacherContentRepository;
use App\Services\Contenuti\CopiaIndipendente;
use App\Services\Contenuti\DoveVale;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Contract\InMemoryStorageProvider;

/**
 * ADR-037, fase 2 — «Duplica in…»: una copia indipendente nel posto scelto.
 *
 * Si prova che la copia è un contenuto nuovo del docente (titolo libero,
 * bozza, `source_content_id` all'originale, classificazione per il diritto
 * d'autore ereditata: S5), con la principale nel posto scelto anche in
 * un'altra scuola; che il contratto di un esercizio si copia in un file suo,
 * con la scuola di arrivo nello scope; che da lì in poi originale e copia non
 * si toccano; che un collega non copia i contenuti altrui (S1) e che un posto
 * non valido non lascia righe a metà (S3).
 *
 * Il deposito dei contratti è in memoria: nessun file scritto su disco.
 * Fixture isolata in transazione (rollback in tearDown).
 */
final class CopiaIndipendenteTest extends TestCase
{
    private PDO $pdo;
    private bool $inTx = false;
    private int $scuolaA = 0;
    private int $scuolaB = 0;
    private int $docente = 0;
    private int $collega = 0;
    /** @var array<string,int> */
    private array $voce = [];
    private InMemoryStorageProvider $deposito;
    private TeacherContentRepository $repo;
    private ContractRepository $contratti;
    private CopiaIndipendente $copia;

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
        $ist->execute(['ZZCPA001', 'SCUOLA A COPIA', 'Comune Esempio']);
        $this->scuolaA = (int)$this->pdo->lastInsertId();
        $ist->execute(['ZZCPB001', 'SCUOLA B COPIA', 'Comune Esempio']);
        $this->scuolaB = (int)$this->pdo->lastInsertId();

        $voce = $this->pdo->prepare(
            'INSERT INTO curriculum_entries (kind, institute_id, code, label, indirizzo, active, shared_with_pool, origine)
             VALUES (?, ?, ?, ?, ?, 1, 0, "istituto")'
        );
        foreach ([
            ['A', $this->scuolaA, 'indirizzi', 'ZCS', null], ['A', $this->scuolaA, 'classi', '2', 'ZCS'],
            ['A', $this->scuolaA, 'materie', 'ZCM', null],
            ['B', $this->scuolaB, 'indirizzi', 'ZCS', null], ['B', $this->scuolaB, 'classi', '3B', 'ZCS'],
            ['B', $this->scuolaB, 'classi', '3C', 'ZCS'], ['B', $this->scuolaB, 'materie', 'ZCMX', null],
        ] as [$s, $iid, $kind, $code, $corso]) {
            $voce->execute([$kind, $iid, $code, $code, $corso]);
            $this->voce["$s:$code"] = (int)$this->pdo->lastInsertId();
        }
        $this->docente = $this->utente('zzcp_doc', [$this->scuolaA, $this->scuolaB]);
        $this->collega = $this->utente('zzcp_coll', [$this->scuolaA, $this->scuolaB]);
        $spunta = $this->pdo->prepare('INSERT INTO curriculum_teacher (curriculum_id, user_id, active) VALUES (?, ?, 1)');
        foreach (['A:ZCS', 'A:2', 'A:ZCM', 'B:ZCS', 'B:3B', 'B:ZCMX'] as $k) {
            $spunta->execute([$this->voce[$k], $this->docente]);
            $spunta->execute([$this->voce[$k], $this->collega]);
        }

        $this->deposito = new InMemoryStorageProvider();
        $this->repo = new TeacherContentRepository();
        $this->contratti = new ContractRepository($this->repo, $this->deposito);
        $this->copia = new CopiaIndipendente($this->repo, new DoveVale(), null, $this->contratti);
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $_SESSION = [];
        \App\Support\CurriculumLookup::resetCache();
    }

    /** @param list<int> $scuole */
    private function utente(string $username, array $scuole): int
    {
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES (?, "teacher", "Zz", "Copia", ?, "x", "approved", 1, NOW())'
        )->execute([$username, $username . '@example.invalid']);
        $id = (int)$this->pdo->lastInsertId();
        foreach ($scuole as $s) {
            $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')->execute([$id, $s]);
        }
        return $id;
    }

    private function originale(string $tipo, string $titolo): int
    {
        $id = $this->repo->create([
            'teacher_id' => $this->docente, 'content_type' => $tipo, 'subject_code' => 'ZCM',
            'indirizzo' => 'ZCS', 'classe' => '2', 'topic' => 'Frazioni', 'title' => $titolo,
            'body_html' => '<p>Il testo originale</p>', 'visibility' => 'published',
        ]);
        $this->pdo->prepare('UPDATE teacher_content_data SET source_type = "personal" WHERE id = ?')->execute([$id]);
        return $id;
    }

    /** @return array<string,mixed> */
    private function riga(int $id): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, teacher_id, title, visibility, source_content_id, source_type, body_html FROM teacher_content_data WHERE id = ?'
        );
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($r);
        return $r;
    }

    private function nellaB(int $originale, ?int $chi = null, string $classe = '3B'): int
    {
        return $this->copia->duplica(
            $originale,
            $chi ?? $this->docente,
            $this->scuolaB,
            $this->voce['B:ZCS'],
            $this->voce["B:$classe"],
            $this->voce['B:ZCMX']
        );
    }

    #[Test]
    public function la_copia_e_un_contenuto_nuovo_in_bozza_nel_posto_scelto_con_la_provenienza_e_la_classificazione(): void
    {
        $titolo = 'Frazioni ' . uniqid();
        $originale = $this->originale('document', $titolo);
        $copia = $this->nellaB($originale);

        $r = $this->riga($copia);
        $this->assertSame([$this->docente, "$titolo (copia)", 'draft', $originale, 'personal'], [
            (int)$r['teacher_id'], $r['title'], $r['visibility'], (int)$r['source_content_id'], $r['source_type'],
        ]);
        $this->assertSame('<p>Il testo originale</p>', $r['body_html']);

        $st = $this->pdo->prepare('SELECT institute_id, indirizzo_id, classe_id, subject_id FROM content_publications WHERE primary_of_tc = ?');
        $st->execute([$copia]);
        $this->assertSame(
            [$this->scuolaB, $this->voce['B:ZCS'], $this->voce['B:3B'], $this->voce['B:ZCMX']],
            array_map('intval', array_values($st->fetch(PDO::FETCH_ASSOC) ?: [])),
            'la principale sta nella scuola B con le voci della B, anche se la scuola attiva è la A'
        );

        $this->assertSame("$titolo (copia 2)", $this->riga($this->nellaB($originale))['title'], 'la seconda copia ha un titolo suo');
    }

    #[Test]
    public function da_li_in_poi_originale_e_copia_non_si_toccano(): void
    {
        $originale = $this->originale('document', 'Indipendente ' . uniqid());
        $copia = $this->nellaB($originale);
        $this->repo->update($copia, $this->docente, ['title' => 'Cambiato nella copia', 'body_html' => '<p>altro</p>']);
        $o = $this->riga($originale);
        $this->assertNotSame('Cambiato nella copia', $o['title']);
        $this->assertSame('<p>Il testo originale</p>', $o['body_html']);
    }

    #[Test]
    public function il_contratto_di_un_esercizio_si_copia_in_un_file_suo_con_la_scuola_di_arrivo(): void
    {
        $originale = $this->originale('esercizio', 'Esercizio ' . uniqid());
        $this->contratti->createEmptyShellForNewContent($originale, $this->scuolaA);
        $agg = $this->contratti->load($originale);
        $this->assertNotNull($agg);
        $dati = $agg->data();
        $dati['groups'] = [['id' => 'g1', 'title' => 'Gruppo', 'items' => [['id' => 'i1', 'text' => 'quesito']]]];
        $this->deposito->put($agg->storageKey, (string)json_encode($dati));

        $copia = $this->nellaB($originale);
        $aggCopia = $this->contratti->load($copia);
        $this->assertNotNull($aggCopia, 'la copia ha il suo contratto');
        $this->assertNotSame($agg->storageKey, $aggCopia->storageKey);
        $this->assertStringEndsWith("copia-{$copia}.contract.json", $aggCopia->storageKey);
        $datiCopia = $aggCopia->data();
        $this->assertSame('quesito', $datiCopia['groups'][0]['items'][0]['text']);
        $this->assertSame($this->scuolaB, $datiCopia['scope']['institute_id']);
        $this->assertSame($originale, $datiCopia['_copiato_da']['contenuto']);

        // Cambiare il contratto della copia non tocca quello dell'originale.
        $datiCopia['groups'][0]['items'][0]['text'] = 'cambiato nella copia';
        $this->deposito->put($aggCopia->storageKey, (string)json_encode($datiCopia));
        $this->assertSame('quesito', $this->contratti->load($originale)?->data()['groups'][0]['items'][0]['text']);
    }

    #[Test]
    public function s1_un_collega_non_copia_il_contenuto_altrui(): void
    {
        $originale = $this->originale('document', 'Altrui ' . uniqid());
        try {
            $this->nellaB($originale, $this->collega);
            $this->fail('doveva rifiutare');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('non_trovato', $e->getMessage());
        }
    }

    #[Test]
    public function s3_un_posto_non_valido_non_lascia_righe_a_meta(): void
    {
        $originale = $this->originale('document', 'Posto ' . uniqid());
        $conta = fn(): int => (int)$this->pdo->query('SELECT COUNT(*) FROM teacher_content_data WHERE teacher_id = ' . $this->docente)->fetchColumn();
        $prima = $conta();
        try {
            $this->nellaB($originale, null, '3C');   // la 3C non è spuntata
            $this->fail('doveva rifiutare');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('voce_non_spuntata', $e->getMessage());
        }
        $this->assertSame($prima, $conta(), 'nessuna riga creata');
    }
}
