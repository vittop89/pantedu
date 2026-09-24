<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\GroupController;
use App\Controllers\QuesitoController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\Contract\ContentVersionRepository;
use App\Repositories\Contract\ContractRepository;
use App\Repositories\TeacherContentRepository;
use App\Services\Contract\ContractSchemaException;
use App\Support\Storage\StorageFactory;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Tests\Unit\Contract\InMemoryStorageProvider;

/**
 * Un contratto che uscirebbe dallo schema non si salva (23/9/2026, revisione
 * architetturale 2026-09, rilievo A-76).
 *
 * ADR-005 dice che i dati si validano contro lo schema prima del
 * salvataggio. `ContractRepository::save` non lo faceva: lo schema lo
 * guardava solo ContractAggregate al caricamento, per scriverlo nel registro.
 * Adesso un errore di schema che il contratto di prima non aveva ferma la
 * scrittura: il deposito resta com'era, la versione precedente non si
 * archivia, e `group/add` e `group/{ref}` rispondono 422 `contract_schema`.
 *
 * Due cose tengono la regola utilizzabile, e sono provate qui nel verso che
 * lascia passare:
 *
 *   - lo schema dice il vero su quello che l'editor scrive: i gruppi
 *     `type_Collect`, `type_VF`, `type_RMulti` (misurati il 23/9/2026: 6.527
 *     gruppi su 6.146 contratti locali) e l'introduzione a blocchi;
 *   - un contratto già fuori schema resta modificabile finché non peggiora.
 *
 * Fixture in transazione (rollback in tearDown), deposito dei contratti in
 * memoria, archivio delle versioni sul database vero (`content_versions`).
 *
 * Controprova (23/9/2026): con il `save` di origin/main il gruppo di tipo
 * inventato si salva (la rotta risponde 200) e falliscono le tre prove del
 * rifiuto; con lo schema di origin/main (senza le forme `type_*` e
 * l'introduzione a blocchi) falliscono le due prove di ciò che l'editor
 * scrive, e quella del rifiuto, che cita l'elenco dei tipi; con il
 * GroupController di origin/main le due rotte rispondono 500 al posto di 422.
 */
final class ContrattoFuoriSchemaTest extends TestCase
{
    private PDO $pdo;
    private bool $inTx = false;
    private int $scuola = 0;
    private int $docente = 0;
    private InMemoryStorageProvider $deposito;
    private TeacherContentRepository $repo;

    protected function setUp(): void
    {
        $base = \dirname(__DIR__, 2);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$base/$f")) {
                \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
            }
        }
        \App\Core\Config::load($base . '/app/Config');
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1 FROM content_versions LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();
        $this->inTx = true;

        $this->deposito = new InMemoryStorageProvider();
        (new ReflectionProperty(StorageFactory::class, 'memo'))->setValue(null, $this->deposito);

        $marca = substr((string)hrtime(true), -9);
        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, "Comune Esempio", 1)')
            ->execute(['ZZSCHEMA' . $marca, 'Scuola dello schema ' . $marca]);
        $this->scuola = (int)$this->pdo->lastInsertId();
        $utente = 'zzschema' . $marca;
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES (?, "teacher", "Zz", "Schema", ?, "x", "approved", 1, NOW())'
        )->execute([$utente, $utente . '@example.invalid']);
        $this->docente = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')
            ->execute([$this->docente, $this->scuola]);

        $_SESSION = [
            'autenticato' => true,
            'username'    => $utente,
            'user_id'     => $this->docente,
            'user_role'   => 'teacher',
        ];
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/teacher/content/0/group/add';

        $this->repo = new TeacherContentRepository();
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
    }

    /**
     * Un contenuto del docente con il suo contratto in deposito.
     *
     * @param array<string, mixed> $contratto
     */
    private function contenuto(array $contratto, string $tipo = 'esercizio', ?string $titolo = null, string $argomento = 'Schema'): int
    {
        $id = $this->repo->create([
            'teacher_id' => $this->docente, 'content_type' => $tipo, 'subject_code' => 'ZSM',
            'topic' => $argomento, 'title' => $titolo ?? 'Contratto ' . bin2hex(random_bytes(4)), 'visibility' => 'draft',
        ]);
        $this->deposito->put($this->chiave($id), (string)json_encode($contratto));
        $riga = $this->repo->find($id) ?: [];
        $meta = is_array($riga['metadata'] ?? null) ? $riga['metadata'] : [];
        $meta['contract_key'] = $this->chiave($id);
        $this->repo->update($id, $this->docente, ['metadata' => $meta]);
        return $id;
    }

    private function chiave(int $id): string
    {
        return sprintf('institutes/%d/private/%d/schema/%d.contract.json', $this->scuola, $this->docente, $id);
    }

    /** @return array<string, mixed> */
    private static function contratto(string $tipo = 'type_Collect', string $gruppo = 'Gruppo'): array
    {
        return [
            '$schema' => ContractRepository::SCHEMA_ID,
            'title'   => 'Contratto di prova',
            'version' => 1,
            'groups'  => [[
                'id' => 'g1', 'kind' => 'problem-group', 'type' => $tipo,
                'title' => $gruppo, 'intro' => 'Risolvi.',
                'items' => [['id' => 'i1', 'question' => [['type' => 'text', 'content' => 'Quanto fa due più due?']]]],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function scritto(int $id): array
    {
        return json_decode((string)$this->deposito->raw($this->chiave($id)), true) ?: [];
    }

    private function versioniArchiviate(int $id): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM content_versions WHERE content_id = ?');
        $st->execute([$id]);
        return (int)$st->fetchColumn();
    }

    private function contratti(): ContractRepository
    {
        return new ContractRepository($this->repo, $this->deposito, new ContentVersionRepository($this->pdo));
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private static function esito(Response $r): array
    {
        return [$r->status, json_decode((string)$r->body, true) ?: []];
    }

    // ── Dalla rotta ───────────────────────────────────────────────────────

    #[Test]
    public function un_gruppo_di_tipo_inventato_non_si_salva_e_la_rotta_risponde_422(): void
    {
        $id = $this->contenuto(self::contratto());
        $_POST = ['type' => 'Inventato', 'title' => 'Gruppo nuovo', 'intro' => 'Leggi.'];

        [$stato, $corpo] = self::esito((new GroupController())->groupAdd(new Request(''), ['id' => (string)$id]));

        $this->assertSame(422, $stato, 'rifiutato perché la richiesta è fuori schema, non per un guasto');
        $this->assertSame('contract_schema', $corpo['error'] ?? null);
        $this->assertStringContainsString('groups[1].type', implode(' ', $corpo['errors'] ?? []));
        $this->assertCount(1, $this->scritto($id)['groups'], 'il deposito resta com\'era');
        $this->assertSame(0, $this->versioniArchiviate($id), 'la versione di prima non si archivia per una scrittura rifiutata');
    }

    /** Il verso opposto: il tipo che l'editor manda davvero (`type_Collect-1` senza il suffisso) si salva. */
    #[Test]
    public function il_gruppo_che_l_editor_manda_si_salva(): void
    {
        $id = $this->contenuto(self::contratto());
        $_POST = ['type' => 'type_Collect', 'title' => 'Gruppo nuovo', 'intro' => 'Leggi.'];

        [$stato, $corpo] = self::esito((new GroupController())->groupAdd(new Request(''), ['id' => (string)$id]));

        $this->assertSame(200, $stato, (string)json_encode($corpo));
        $this->assertCount(2, $this->scritto($id)['groups']);
        $this->assertSame(1, $this->versioniArchiviate($id));
    }

    #[Test]
    public function un_tipo_inventato_nella_modifica_del_gruppo_risponde_422(): void
    {
        $id = $this->contenuto(self::contratto());
        $_POST = ['type' => 'Inventato'];

        [$stato, $corpo] = self::esito((new GroupController())->groupPatch(new Request(''), ['id' => (string)$id, 'groupRef' => 'g1']));

        $this->assertSame(422, $stato, (string)json_encode($corpo));
        $this->assertSame('contract_schema', $corpo['error'] ?? null);
        $this->assertSame('type_Collect', $this->scritto($id)['groups'][0]['type'] ?? null, 'il tipo resta quello di prima');
    }

    /** Il verso opposto: la stessa modifica con un tipo dello schema passa. */
    #[Test]
    public function un_tipo_dello_schema_nella_modifica_del_gruppo_passa(): void
    {
        $id = $this->contenuto(self::contratto());
        $_POST = ['type' => 'type_VF'];

        [$stato, $corpo] = self::esito((new GroupController())->groupPatch(new Request(''), ['id' => (string)$id, 'groupRef' => 'g1']));

        $this->assertSame(200, $stato, (string)json_encode($corpo));
        $this->assertSame('type_VF', $this->scritto($id)['groups'][0]['type'] ?? null);
    }

    /**
     * «Copia negli esercizi» di un quesito (QuesitoController, clone-to-eser)
     * salva l'esercizio con `save()`: un gruppo della verifica già fuori
     * schema, copiato in un esercizio che non lo era, è un errore nuovo.
     * Rispondeva 400 `clone_failed`, senza dire perché; ora 422 come le
     * rotte dei gruppi. Controprova (23/9/2026): con il QuesitoController di
     * cc8e633a la rotta risponde 400 `clone_failed`.
     *
     * @return array{0: int, 1: int} l'esercizio e la verifica
     */
    private function verificaEEsercizio(string $tipoDellaVerifica): array
    {
        // Senza la materia nel curriculum del docente `create()` scrive
        // `subject_code` a NULL, e cloneToEser non trova la verifica da cui
        // parte (come in PaginaResaNelleRotteTest).
        \App\Support\CurriculumLookup::resetCache();
        $this->pdo->prepare(
            'INSERT INTO curriculum_entries (kind, institute_id, code, label, indirizzo, active, shared_with_pool, origine)
             VALUES ("materie", ?, "ZSM", "ZSM", NULL, 1, 0, "istituto")'
        )->execute([$this->scuola]);
        $this->pdo->prepare('INSERT INTO curriculum_teacher (curriculum_id, user_id, active) VALUES (?, ?, 1)')
            ->execute([(int)$this->pdo->lastInsertId(), $this->docente]);

        $argomento = 'Argomento ' . bin2hex(random_bytes(4));
        // L'esercizio che fa da destinazione ha per titolo l'argomento della
        // verifica (ContractRepository::cloneToEser); il gruppo copiato ha un
        // titolo diverso, quindi entra intero.
        $esercizio = $this->contenuto(self::contratto(), 'esercizio', $argomento);
        $verifica = $this->contenuto(self::contratto($tipoDellaVerifica, 'Gruppo della verifica'), 'verifica', 'Verifica', $argomento);
        return [$esercizio, $verifica];
    }

    #[Test]
    public function un_quesito_copiato_da_un_gruppo_fuori_schema_risponde_422(): void
    {
        [$esercizio, $verifica] = $this->verificaEEsercizio('Inventato');

        [$stato, $corpo] = self::esito((new QuesitoController())->quesitoCloneToEser(new Request(''), ['id' => (string)$verifica, 'itemRef' => 'i1']));

        $this->assertSame(422, $stato, (string)json_encode($corpo));
        $this->assertSame('contract_schema', $corpo['error'] ?? null);
        $this->assertStringContainsString('groups[1].type', implode(' ', $corpo['errors'] ?? []));
        $this->assertCount(1, $this->scritto($esercizio)['groups'], 'l\'esercizio resta com\'era');
    }

    /** Il verso opposto: lo stesso quesito, da un gruppo dello schema, si copia. */
    #[Test]
    public function un_quesito_copiato_da_un_gruppo_dello_schema_entra_nell_esercizio(): void
    {
        [$esercizio, $verifica] = $this->verificaEEsercizio('type_VF');

        [$stato, $corpo] = self::esito((new QuesitoController())->quesitoCloneToEser(new Request(''), ['id' => (string)$verifica, 'itemRef' => 'i1']));

        $this->assertSame(200, $stato, (string)json_encode($corpo));
        $this->assertSame($esercizio, $corpo['eserContentId'] ?? null);
        $this->assertSame('type_VF', $this->scritto($esercizio)['groups'][1]['type'] ?? null);
    }

    // ── Dal repository ────────────────────────────────────────────────────

    #[Test]
    public function un_contratto_non_valido_non_si_salva(): void
    {
        $id = $this->contenuto(self::contratto());
        $agg = $this->contratti()->loadForTeacher($id, $this->docente);
        $this->assertNotNull($agg);
        $agg->appendGroup(['kind' => 'problem-group', 'type' => 'Inventato', 'title' => 'Fuori', 'items' => []]);
        $agg->bumpVersion();

        try {
            $this->contratti()->save($agg);
            $this->fail('save doveva rifiutare il gruppo fuori schema');
        } catch (ContractSchemaException $e) {
            $this->assertSame(['groups[1].type: Does not have a value in the enumeration '
                . '["Collect","VF","RM","Text","Mixed","type_Collect","type_VF","type_RMulti"]'], $e->errori);
        }
        $this->assertSame(1, $this->scritto($id)['version'], 'il deposito ha ancora la versione di prima');
        $this->assertSame(0, $this->versioniArchiviate($id));
    }

    /**
     * Un contratto già fuori schema (un tipo che nessuno conosce, scritto
     * prima di oggi) si modifica ancora; aggiungergli un secondo errore no.
     */
    #[Test]
    public function un_contratto_gia_fuori_schema_resta_modificabile_finche_non_peggiora(): void
    {
        $id = $this->contenuto(self::contratto('Inventato'));

        $agg = $this->contratti()->loadForTeacher($id, $this->docente);
        $this->assertNotNull($agg);
        $agg->patchMeta(['source_citation' => 'Libro di prova']);
        $agg->bumpVersion();
        $this->contratti()->save($agg);
        $this->assertSame('Libro di prova', $this->scritto($id)['meta']['source_citation'] ?? null);

        $agg = $this->contratti()->loadForTeacher($id, $this->docente);
        $this->assertNotNull($agg);
        $agg->appendGroup(['kind' => 'problem-group', 'type' => 'AncheQuesto', 'title' => 'Fuori', 'items' => []]);
        $this->expectException(ContractSchemaException::class);
        $this->contratti()->save($agg);
    }

    /** L'introduzione a blocchi, come le domande, è nello schema. */
    #[Test]
    public function l_introduzione_a_blocchi_si_salva(): void
    {
        $id = $this->contenuto(self::contratto());
        $agg = $this->contratti()->loadForTeacher($id, $this->docente);
        $this->assertNotNull($agg);
        $agg->appendGroup([
            'kind' => 'problem-group', 'type' => 'type_VF', 'title' => 'Vero o falso',
            'intro' => [['type' => 'text', 'content' => 'Segna vero o falso.']],
            'items' => [],
        ]);
        $agg->bumpVersion();

        $this->contratti()->save($agg);

        $this->assertSame('Segna vero o falso.', $this->scritto($id)['groups'][1]['intro'][0]['content'] ?? null);
    }
}
