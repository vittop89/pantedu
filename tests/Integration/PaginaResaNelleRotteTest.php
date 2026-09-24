<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\GroupController;
use App\Controllers\QuesitoController;
use App\Core\Database;
use App\Core\Request;
use App\Repositories\Contract\ContractRepository;
use App\Repositories\TeacherContentRepository;
use App\Support\Storage\StorageFactory;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Unit\Contract\InMemoryStorageProvider;

/**
 * Lo stesso rifiuto deve dare la stessa risposta in tutte le rotte che
 * scrivono un contratto.
 *
 * `ContractRepository::save()` rifiuta la scrittura che porterebbe dentro il
 * contratto pezzi della pagina resa (`TestoDiPaginaResa`). Due rotte che
 * passano da `save()` non traducevano quel rifiuto: `clone-to-eser` lo
 * faceva cadere nel `catch (\Throwable)` e rispondeva 400 `clone_failed`,
 * `group/add` rispondeva 500 `persist_failed` — cioè «errore del server» per
 * una richiesta che il server ha capito benissimo e rifiutato apposta. Il
 * docente vedeva un guasto e non il motivo.
 *
 * Provato nei due versi: con il contenuto rovinato 422 `rendered_text` con il
 * messaggio in italiano, con il contenuto sano la rotta fa il suo lavoro.
 *
 * Il deposito dei contratti è in memoria: nessun file su disco. Fixture
 * isolata in transazione (rollback in tearDown).
 */
final class PaginaResaNelleRotteTest extends TestCase
{
    private const RIQUADRO = "[TikZ render error]\nErrore di rete: [7] Failed to connect";

    private PDO $pdo;
    private bool $inTx = false;
    private int $scuola = 0;
    private int $docente = 0;
    private InMemoryStorageProvider $deposito;
    private TeacherContentRepository $repo;
    private ContractRepository $contratti;

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
            $this->pdo->query('SELECT 1 FROM teacher_content_data LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();
        $this->inTx = true;

        // Deposito in memoria al posto di quello su disco: le rotte usano
        // ContractRepository::default(), che prende StorageFactory::default().
        $this->deposito = new InMemoryStorageProvider();
        $memo = (new ReflectionClass(StorageFactory::class))->getProperty('memo');
        $memo->setAccessible(true);
        $memo->setValue(null, $this->deposito);

        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
            ->execute(['ZZPRN001', 'SCUOLA PAGINA RESA', 'Comune Esempio']);
        $this->scuola = (int)$this->pdo->lastInsertId();
        $utente = 'zzprn_doc_' . substr((string)microtime(true), -6);
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES (?, "teacher", "Zz", "PaginaResa", ?, "x", "approved", 1, NOW())'
        )->execute([$utente, $utente . '@example.invalid']);
        $this->docente = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')
            ->execute([$this->docente, $this->scuola]);

        // Senza le voci di curriculum, `create()` scrive `subject_code` a NULL
        // e `cloneToEser()` non trova più la verifica da cui parte.
        \App\Support\CurriculumLookup::resetCache();
        $voce = $this->pdo->prepare(
            'INSERT INTO curriculum_entries (kind, institute_id, code, label, indirizzo, active, shared_with_pool, origine)
             VALUES (?, ?, ?, ?, ?, 1, 0, "istituto")'
        );
        $spunta = $this->pdo->prepare(
            'INSERT INTO curriculum_teacher (curriculum_id, user_id, active) VALUES (?, ?, 1)'
        );
        foreach ([['indirizzi', 'ZPS', null], ['classi', '2', 'ZPS'], ['materie', 'ZPM', null]] as [$kind, $code, $corso]) {
            $voce->execute([$kind, $this->scuola, $code, $code, $corso]);
            $spunta->execute([(int)$this->pdo->lastInsertId(), $this->docente]);
        }

        $_SESSION = [
            'autenticato' => true,
            'username'    => $utente,
            'user_id'     => $this->docente,
            'user_role'   => 'teacher',
        ];
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/teacher/content/0';

        $this->repo = new TeacherContentRepository();
        $this->contratti = new ContractRepository($this->repo, $this->deposito);
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        StorageFactory::reset();
        \App\Support\CurriculumLookup::resetCache();
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
    }

    /** Un contenuto del docente con il suo contratto già in deposito. */
    private function contenuto(string $tipo, string $titolo, string $topic, array $contratto): int
    {
        $id = $this->repo->create([
            'teacher_id' => $this->docente, 'content_type' => $tipo, 'subject_code' => 'ZPM',
            'indirizzo' => 'ZPS', 'classe' => '2', 'topic' => $topic, 'title' => $titolo,
            'body_html' => '<p>corpo</p>', 'visibility' => 'draft',
        ]);
        $chiave = sprintf('institutes/%d/private/%d/prova/%d.contract.json', $this->scuola, $this->docente, $id);
        $this->deposito->put($chiave, (string)json_encode($contratto));
        $riga = $this->repo->find($id) ?: [];
        $meta = is_array($riga['metadata'] ?? null) ? $riga['metadata'] : [];
        $meta['contract_key'] = $chiave;
        $this->repo->update($id, $this->docente, ['metadata' => $meta]);
        return $id;
    }

    /** @param list<array<string,mixed>> $traccia */
    private static function contratto(string $titoloGruppo, array $traccia): array
    {
        return [
            '$schema' => ContractRepository::SCHEMA_ID,
            'title'   => 'Contratto di prova',
            'version' => 1,
            'groups'  => [[
                'id' => 'g1', 'kind' => 'problem-group', 'type' => 'Collect',
                'title' => $titoloGruppo, 'intro' => 'Introduzione sana.',
                'items' => [['id' => 'i1', 'question' => $traccia]],
            ]],
        ];
    }

    /** @return array{int, array<string,mixed>} stato HTTP e corpo decodificato */
    private static function esito(\App\Core\Response $r): array
    {
        return [$r->status, json_decode($r->body, true) ?: []];
    }

    // ── POST /api/teacher/content/{id}/group/add ─────────────────────────

    #[Test]
    public function groupAddRifiutaUnIntroDiPaginaResaCon422(): void
    {
        $id = $this->contenuto('esercizio', 'Moto rettilineo', 'Moto', self::contratto('Gruppo', [
            ['type' => 'text', 'content' => 'Un testo sano.'],
        ]));
        $_POST = ['type' => 'Collect', 'title' => 'Gruppo nuovo', 'intro' => self::RIQUADRO];

        [$stato, $corpo] = self::esito((new GroupController())->groupAdd(new Request(''), ['id' => (string)$id]));

        self::assertSame(422, $stato, 'il rifiuto della guardia non è un errore del server');
        self::assertSame('rendered_text', $corpo['error'] ?? null);
        self::assertStringContainsString('pezzi della pagina', (string)($corpo['message'] ?? ''));
        self::assertNotSame([], $corpo['where'] ?? [], 'il punto del contratto va detto');
    }

    /** L'ALTRO VERSO: un intro sano aggiunge il gruppo. */
    #[Test]
    public function groupAddConUnIntroSanoAggiungeIlGruppo(): void
    {
        $id = $this->contenuto('esercizio', 'Moto uniforme', 'Moto', self::contratto('Gruppo', [
            ['type' => 'text', 'content' => 'Un testo sano.'],
        ]));
        $_POST = ['type' => 'Collect', 'title' => 'Gruppo nuovo', 'intro' => 'Leggi e rispondi.'];

        [$stato, $corpo] = self::esito((new GroupController())->groupAdd(new Request(''), ['id' => (string)$id]));

        self::assertSame(200, $stato, (string)($corpo['error'] ?? ''));
        self::assertNotSame('', (string)($corpo['groupId'] ?? ''));
        $scritto = json_decode((string)$this->deposito->raw(
            sprintf('institutes/%d/private/%d/prova/%d.contract.json', $this->scuola, $this->docente, $id)
        ), true);
        self::assertCount(2, $scritto['groups']);
    }

    // ── POST …/quesito/{ref}/clone-to-eser ───────────────────────────────

    /**
     * Clonare verso l'esercizio un quesito di una verifica già rovinata: la
     * guardia scatta (l'esercizio è un contratto diverso, quel testo lì è
     * nuovo) e il dato resta protetto. Quello che cambia è la risposta: prima
     * era 400 `clone_failed`, che non dice né perché né che cosa fare.
     */
    #[Test]
    public function cloneToEserRifiutaUnQuesitoDiPaginaResaCon422(): void
    {
        $verifica = $this->contenuto('verifica', 'Verifica rovinata', 'Cinematica', self::contratto('Esercizi', [
            ['type' => 'text', 'content' => self::RIQUADRO],
        ]));
        $this->contenuto('esercizio', 'Cinematica', '3.0', self::contratto('Esercizi', [
            ['type' => 'text', 'content' => 'Un testo sano.'],
        ]));
        $_POST = ['mode' => 'full'];

        [$stato, $corpo] = self::esito((new QuesitoController())->quesitoCloneToEser(
            new Request(''),
            ['id' => (string)$verifica, 'itemRef' => 'i1'],
        ));

        self::assertSame(422, $stato, 'atteso rendered_text, ricevuto: ' . json_encode($corpo));
        self::assertSame('rendered_text', $corpo['error'] ?? null);
        self::assertStringContainsString('pezzi della pagina', (string)($corpo['message'] ?? ''));
    }

    /** L'ALTRO VERSO: un quesito sano si clona e la rotta risponde ok. */
    #[Test]
    public function cloneToEserConUnQuesitoSanoClona(): void
    {
        $verifica = $this->contenuto('verifica', 'Verifica sana', 'Dinamica', self::contratto('Esercizi', [
            ['type' => 'text', 'content' => 'Un corpo di massa m scivola.'],
        ]));
        $esercizio = $this->contenuto('esercizio', 'Dinamica', '3.0', self::contratto('Esercizi', [
            ['type' => 'text', 'content' => 'Un testo sano.'],
        ]));
        $_POST = ['mode' => 'full'];

        [$stato, $corpo] = self::esito((new QuesitoController())->quesitoCloneToEser(
            new Request(''),
            ['id' => (string)$verifica, 'itemRef' => 'i1'],
        ));

        self::assertSame(200, $stato, (string)($corpo['error'] ?? ''));
        self::assertSame($esercizio, (int)($corpo['eserContentId'] ?? 0));
    }
}
