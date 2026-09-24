<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\PoolController;
use App\Controllers\ShareGrantsController;
use App\Core\Database;
use App\Core\Request;
use App\Repositories\Sharing\PoolRepository;
use App\Repositories\VerificaDocumentRepository;
use App\Services\Contenuti\CopiaVerifica;
use App\Services\Sharing\SharedContentPolicy;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La condivisione di una verifica con i colleghi (14/9/2026).
 *
 * Una verifica, per chi la usa, sono tutte le sue varianti e versioni. Fino a
 * questa correzione il pulsante di condivisione cambiava la sola variante da
 * cui si apriva il modale, i grant lo stesso, il pool elencava ogni variante
 * come una voce, e «Recupera» mandava l'id della verifica all'API dei
 * contenuti, che recuperava un altro contenuto o niente.
 *
 * Una scuola, un docente e un collega che la spuntano; una verifica «Frazioni»
 * in due versioni (tre varianti in tutto) e un'altra verifica di una riga.
 *
 * Fixture isolata in transazione (rollback in tearDown); i blob stanno in un
 * deposito in memoria.
 */
final class VerificheCondivisioneTest extends TestCase
{
    private PDO $pdo;
    private bool $inTx = false;
    private int $scuola = 0;
    private int $docente = 0;
    private int $collega = 0;
    /** @var array<string,int> */
    private array $voce = [];
    /** Le tre righe di «Frazioni»: due varianti della v01 e una della v02. @var list<int> */
    private array $frazioni = [];
    private int $altra = 0;
    private string $titolo = '';

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

        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
            ->execute(['ZZCVA001', 'SCUOLA CONDIVISIONE VERIFICHE', 'Comune Esempio']);
        $this->scuola = (int)$this->pdo->lastInsertId();
        $voce = $this->pdo->prepare(
            'INSERT INTO curriculum_entries (kind, institute_id, code, label, indirizzo, active, shared_with_pool, origine)
             VALUES (?, ?, ?, ?, ?, 1, 0, "istituto")'
        );
        foreach ([['indirizzi', 'ZCS', null], ['classi', '2A', 'ZCS'], ['classi', '3A', 'ZCS'], ['materie', 'ZCM', null]] as [$kind, $code, $corso]) {
            $voce->execute([$kind, $this->scuola, $code, $code, $corso]);
            $this->voce[$code] = (int)$this->pdo->lastInsertId();
        }
        $this->docente = $this->utente('zzcv_doc', 'Ada', 'Proprietaria');
        $this->collega = $this->utente('zzcv_coll', 'Bruno', 'Collega');
        $spunta = $this->pdo->prepare('INSERT INTO curriculum_teacher (curriculum_id, user_id, active) VALUES (?, ?, 1)');
        foreach ($this->voce as $id) {
            $spunta->execute([$id, $this->docente]);
            $spunta->execute([$id, $this->collega]);
        }

        $repo = new VerificaDocumentRepository();
        $this->titolo = 'Frazioni ' . uniqid();
        $v01 = strtoupper(substr(bin2hex(random_bytes(13)), 0, 26));
        $v02 = strtoupper(substr(bin2hex(random_bytes(13)), 0, 26));
        foreach ([['A_SOL', $v01, 'v01'], ['A_NOR', $v01, 'v01'], ['A_SOL', $v02, 'v02']] as [$variante, $pacchetto, $versione]) {
            $this->frazioni[] = $repo->create([
                'teacher_id' => $this->docente, 'materia' => 'ZCM', 'indirizzo' => 'ZCS', 'classe' => '2A',
                'title' => "{$this->titolo} — {$variante}", 'batch_id' => $pacchetto, 'variant' => $variante,
                'version_label' => $versione, 'exercise_ids' => [41, 42], 'source_type' => 'personal',
            ]);
        }
        $this->altra = $repo->create([
            'teacher_id' => $this->docente, 'materia' => 'ZCM', 'indirizzo' => 'ZCS', 'classe' => '2A',
            'title' => 'Altra verifica ' . uniqid(), 'variant' => '', 'exercise_ids' => [], 'source_type' => 'personal',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $_SESSION = [];
        $_SERVER['CONTENT_TYPE'] = '';
        \App\Support\CurriculumLookup::resetCache();
    }

    private function utente(string $username, string $nome, string $cognome): int
    {
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES (?, "teacher", ?, ?, ?, "x", "approved", 1, NOW())'
        )->execute([$username, $nome, $cognome, $username . '@example.invalid']);
        $id = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')->execute([$id, $this->scuola]);
        return $id;
    }

    /** @return array<int,int> id → shared_with_pool */
    private function condivise(): array
    {
        $ids = [...$this->frazioni, $this->altra];
        $st = $this->pdo->prepare('SELECT id, shared_with_pool FROM verifica_documents_data WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['id']] = (int)$r['shared_with_pool'];
        }
        return $out;
    }

    private function sessione(int $id, string $username): void
    {
        $_SESSION = ['autenticato' => true, 'username' => $username, 'user_id' => $id, 'user_role' => 'teacher'];
    }

    private function codice(callable $fn): string
    {
        try {
            $fn();
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }
        return 'nessun errore';
    }

    #[Test]
    public function condividere_da_una_variante_condivide_tutta_la_verifica_e_ritirare_la_ritira_tutta(): void
    {
        $policy = new SharedContentPolicy();
        $esito = $policy->toggleSharePool($this->docente, 'verifica_documents', $this->frazioni[1], true);
        $this->assertTrue($esito['ok']);
        $this->assertSame(3, $esito['righe'], 'due varianti della v01 e la v02');
        $atteso = array_fill_keys($this->frazioni, 1) + [$this->altra => 0];
        $this->assertEquals($atteso, $this->condivise(), 'tutta «Frazioni», e non l\'altra verifica');

        $policy->toggleSharePool($this->docente, 'verifica_documents', $this->frazioni[2], false);
        $this->assertEquals(array_fill_keys($this->frazioni, 0) + [$this->altra => 0], $this->condivise(), 'ritirata da una versione, ritirata tutta');

        $this->assertSame('forbidden', $policy->toggleSharePool($this->collega, 'verifica_documents', $this->frazioni[0], true)['error'] ?? null);
    }

    #[Test]
    public function una_variante_tratta_dal_libro_blocca_tutta_la_verifica(): void
    {
        $this->pdo->prepare('UPDATE verifica_documents_data SET source_type = "book_textbook" WHERE id = ?')->execute([$this->frazioni[2]]);
        $esito = (new SharedContentPolicy())->toggleSharePool($this->docente, 'verifica_documents', $this->frazioni[0], true);
        $this->assertSame('copyright_block', $esito['error'] ?? null, 'la variante dal libro è un\'altra, ma la verifica è una');
        $this->assertSame([], array_filter($this->condivise()), 'nessuna variante condivisa a metà');
    }

    #[Test]
    public function i_grant_valgono_per_tutte_le_varianti_e_si_tolgono_da_tutte(): void
    {
        $grant = function (array $grants): array {
            $this->sessione($this->docente, 'zzcv_doc');
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['CONTENT_TYPE'] = 'application/json';
            $res = (new ShareGrantsController())->setGrants(
                new Request((string)json_encode(['grants' => $grants])),
                ['source' => 'verifica_documents', 'id' => (string)$this->frazioni[0]]
            );
            return json_decode((string)$res->body, true) ?? [];
        };
        $conta = function (): array {
            $st = $this->pdo->prepare('SELECT content_id, COUNT(*) FROM content_shares WHERE content_source = "verifica_documents" AND target_type = "teacher" AND target_id = ? GROUP BY content_id');
            $st->execute([$this->collega]);
            return array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
        };
        $j = $grant([['target_type' => 'teacher', 'target_id' => $this->collega]]);
        $this->assertTrue($j['ok'] ?? false, json_encode($j));
        $this->assertSame(3, $j['righe'] ?? null);
        $this->assertEquals(array_fill_keys($this->frazioni, 1), $conta(), 'il collega ha il grant su ogni variante');

        $grant([]);
        $this->assertSame([], $conta(), 'tolto da tutte');
    }

    #[Test]
    public function il_pool_mostra_la_verifica_una_volta_e_il_ritiro_dai_miei_condivisi_la_ritira_intera(): void
    {
        (new SharedContentPolicy())->toggleSharePool($this->docente, 'verifica_documents', $this->frazioni[0], true);
        $this->sessione($this->collega, 'zzcv_coll');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['content_type' => 'verifica_doc'];
        $res = (new PoolController())->materials(new Request());
        $_GET = [];
        $items = array_values(array_filter(
            json_decode((string)$res->body, true)['items'] ?? [],
            fn(array $i): bool => $i['source'] === 'verifica_documents' && in_array((int)$i['id'], $this->frazioni, true)
        ));
        $this->assertCount(1, $items, 'una voce, non tre');
        $this->assertSame($this->titolo, $items[0]['title'], 'con il titolo senza la variante');
        $this->assertSame(3, $items[0]['varianti']);

        $this->assertSame(3, (new PoolRepository())->unshareVerificaDocuments($this->docente, [$this->frazioni[0]]));
        $this->assertSame([], array_filter($this->condivise()));
    }

    #[Test]
    public function il_collega_recupera_la_verifica_nel_suo_account_con_i_file_riletti_e_riscritti_per_lui(): void
    {
        $deposito = new DepositoInMemoria();
        $blob = $deposito->put($this->docente, "\\documentclass{article} frazioni\n");
        $pdf = $deposito->put($this->docente, "%PDF-1.4 frazioni\n");
        $manifest = [['path' => 'main.tex', 'blob_path' => $blob, 'blob_kv' => 1, 'sha256' => str_repeat('c', 64), 'size' => 33]];
        foreach ($this->frazioni as $id) {
            $this->pdo->prepare('UPDATE verifica_documents_data SET tex_files = ? WHERE id = ?')->execute([json_encode($manifest), $id]);
        }
        (new VerificaDocumentRepository())->attachPdf($this->frazioni[0], $pdf, 1, 18, 'frazioni.pdf');
        $deposito->scritti = [];
        $deposito->scrittiPer = [];

        $copia = new CopiaVerifica(store: $deposito);
        $recupero = fn(): array => $copia->recupera($this->frazioni[0], $this->collega, $this->scuola, $this->voce['ZCS'], $this->voce['3A'], $this->voce['ZCM']);
        $this->assertSame('non_trovato', $this->codice($recupero), 'non condivisa: per il collega non esiste');

        (new SharedContentPolicy())->toggleSharePool($this->docente, 'verifica_documents', $this->frazioni[0], true);
        $ids = $recupero();
        $this->assertCount(2, $ids, 'il pacchetto della v01, con le sue due varianti');

        $repo = new VerificaDocumentRepository();
        foreach ($ids as $id) {
            $c = $repo->find($id);
            $this->assertSame($this->collega, (int)$c['teacher_id'], 'la copia è del collega');
            $this->assertSame($this->voce['3A'], (int)$c['classe_id'], 'nel posto che ha scelto');
            $this->assertStringContainsString('(importata da Ada Proprietaria)', (string)$c['title']);
            $this->assertSame([], $c['exercise_ids'], 'gli esercizi di partenza sono della proprietaria, e non passano');
            $this->assertSame(0, (int)$c['shared_with_pool'], 'e nasce privata');
            $this->assertNotSame($blob, $c['tex_files'][0]['blob_path'], 'con un blob suo');
        }
        $this->assertSame([$this->collega, $this->collega], $deposito->scrittiPer, 'riscritto con la chiave del collega, una volta per file');
        $this->assertContains($this->docente, $deposito->lettiPer, 'riletto con la chiave della proprietaria');

        $registro = $this->pdo->prepare('SELECT details_json FROM audit_activity_log WHERE action = "verifica_recuperata" ORDER BY id DESC LIMIT 1');
        $registro->execute();
        $dettagli = json_decode((string)$registro->fetchColumn(), true);
        $this->assertSame($this->docente, $dettagli['proprietario'] ?? null, 'a registro con gli id');
    }

    #[Test]
    public function non_si_recupera_la_propria_verifica_ne_una_dal_libro_ne_in_un_posto_non_proprio(): void
    {
        $copia = new CopiaVerifica(store: new DepositoInMemoria());
        (new SharedContentPolicy())->toggleSharePool($this->docente, 'verifica_documents', $this->frazioni[0], true);
        $this->assertSame('propria', $this->codice(fn() => $copia->recupera($this->frazioni[0], $this->docente, $this->scuola, $this->voce['ZCS'], $this->voce['3A'], $this->voce['ZCM'])));

        $this->pdo->prepare('UPDATE curriculum_teacher SET active = 0 WHERE user_id = ? AND curriculum_id = ?')->execute([$this->collega, $this->voce['3A']]);
        $this->assertSame('voce_non_spuntata', $this->codice(fn() => $copia->recupera($this->frazioni[0], $this->collega, $this->scuola, $this->voce['ZCS'], $this->voce['3A'], $this->voce['ZCM'])));

        // Condivisa quando si poteva, poi riclassificata dal libro: non si recupera più.
        $this->pdo->prepare('UPDATE verifica_documents_data SET source_type = "book_textbook" WHERE id = ?')->execute([$this->frazioni[1]]);
        $this->assertSame('copyright_block', $this->codice(fn() => $copia->recupera($this->frazioni[0], $this->collega, $this->scuola, $this->voce['ZCS'], $this->voce['2A'], $this->voce['ZCM'])));
    }
}
