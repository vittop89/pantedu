<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Repositories\Risdoc\CompilationRepository;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Segnare che una bozza è stata scaricata: la riga giusta, e solo la propria.
 *
 * ── Che cosa difende questa prova ─────────────────────────────────────────
 *
 * `{id}` in `/api/risdoc/compilations/{id}/scaricata` è l'identificativo della
 * COMPILAZIONE, e non c'è nessun `Permission::canView()` a proteggerla — non
 * servirebbe, perché quello è un controllo di visibilità del MODELLO e
 * risponde sì a qualunque super-admin. L'unica cosa che lega la riga al
 * docente è la clausola `WHERE teacher_id = ?` del repository. Se un giorno
 * quella clausola sparisse, un docente potrebbe far partire il conto alla
 * rovescia sulle bozze di un collega — cioè farle cancellare.
 *
 * Non esisteva nessuna prova su `CompilationRepository` prima di questa.
 *
 * ── E la trappola di `updated_at` ─────────────────────────────────────────
 *
 * Misurata il 22/9/2026 nei due versi: `ON UPDATE CURRENT_TIMESTAMP` sposta
 * `updated_at` a ogni UPDATE, e senza `updated_at = updated_at` segnare uno
 * scaricamento verrebbe contato come una modifica del docente.
 */
final class BozzaSegnataScaricataTest extends TestCase
{
    private PDO $pdo;
    private CompilationRepository $repo;
    private int $mio = 0;
    private int $altro = 0;
    private int $modello = 0;

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
            $this->pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }

        $colonne = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'risdoc_compilations_data'
                AND COLUMN_NAME = 'exported_at'"
        )->fetchColumn();
        if ($colonne !== 1) {
            $this->markTestSkipped('migrazione 138 non applicata su questo database');
        }

        $this->pdo->beginTransaction();
        $this->repo = new CompilationRepository();

        $ins = $this->pdo->prepare(
            "INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active)
             VALUES (?, 'teacher', 'Zz', 'Segnata', ?, 'x', 'approved', 1)"
        );
        $ins->execute(['zz_segn_mio', 'zz_segn_mio@example.test']);
        $this->mio = (int)$this->pdo->lastInsertId();
        $ins->execute(['zz_segn_altro', 'zz_segn_altro@example.test']);
        $this->altro = (int)$this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO risdoc_templates (code, category, num_arg, argomento, source_dir, html_file, source_hash)
             VALUES (?, 'modelli', '00', 'Piano di prova', '/zz', 'zz.html', 'zz')"
        )->execute(['ZZ_SEGN_' . bin2hex(random_bytes(3))]);
        $this->modello = (int)$this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function bozza(int $docente): int
    {
        $quando = (string)$this->pdo->query("SELECT DATE_FORMAT(NOW() - INTERVAL 3 DAY, '%Y-%m-%d %H:%i:%s')")->fetchColumn();
        $this->pdo->prepare(
            'INSERT INTO risdoc_compilations_data
                 (teacher_id, template_id, compilation_key, label, data_json, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $docente,
            $this->modello,
            'combo_' . bin2hex(random_bytes(4)),
            'Bozza di prova',
            '{"campi":{}}',
            $quando,
            $quando,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /** @return array{exported_at:?string, updated_at:string} */
    private function date(int $id): array
    {
        $st = $this->pdo->prepare('SELECT exported_at, updated_at FROM risdoc_compilations_data WHERE id = ?');
        $st->execute([$id]);
        /** @var array{exported_at:?string, updated_at:string} $r */
        $r = $st->fetch(PDO::FETCH_ASSOC);

        return $r;
    }

    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function il_proprietario_segna_la_sua_bozza(): void
    {
        $id = $this->bozza($this->mio);
        self::assertNull($this->date($id)['exported_at'], 'controllo positivo: prima non è segnata');

        self::assertTrue($this->repo->segnaScaricata($this->mio, $id));
        self::assertNotNull($this->date($id)['exported_at'], 'dopo deve esserlo');
    }

    /**
     * Il verso che conta. Un docente non deve poter far partire il conto alla
     * rovescia sulla bozza di un collega.
     */
    #[Test]
    public function un_altro_docente_non_puo_segnare_la_bozza_di_qualcun_altro(): void
    {
        $id = $this->bozza($this->mio);

        self::assertFalse(
            $this->repo->segnaScaricata($this->altro, $id),
            'e la risposta è la stessa di «non esiste»: non si rivela che quella riga c\'è'
        );
        self::assertNull(
            $this->date($id)['exported_at'],
            'soprattutto: la riga non deve essere stata toccata'
        );
    }

    #[Test]
    public function una_bozza_che_non_esiste_torna_falso(): void
    {
        self::assertFalse($this->repo->segnaScaricata($this->mio, 2147483000));
    }

    #[Test]
    public function un_docente_senza_identita_non_segna_niente(): void
    {
        $id = $this->bozza($this->mio);

        self::assertFalse($this->repo->segnaScaricata(0, $id));
        self::assertNull($this->date($id)['exported_at']);
    }

    /**
     * Segnare lo scaricamento non è una modifica del docente. Se `updated_at`
     * saltasse: la lista delle compilazioni si riordinerebbe sotto le sue
     * mani, e la grazia ripartirebbe da capo a ogni scaricamento.
     */
    #[Test]
    public function segnare_non_sposta_la_data_di_modifica(): void
    {
        $id = $this->bozza($this->mio);
        $prima = $this->date($id)['updated_at'];

        $this->repo->segnaScaricata($this->mio, $id);

        self::assertSame($prima, $this->date($id)['updated_at'], 'updated_at doveva restare ferma');
    }

    /**
     * La data la scrive il database, non PHP: l'applicazione gira su
     * Europe/Rome e il database su UTC, e una data nata da PHP arriverebbe nel
     * futuro rispetto all'orologio con cui verrà confrontata.
     */
    #[Test]
    public function la_data_dello_scaricamento_viene_dal_database(): void
    {
        $id = $this->bozza($this->mio);
        $this->repo->segnaScaricata($this->mio, $id);

        $scarto = (int)$this->pdo->query(
            "SELECT ABS(TIMESTAMPDIFF(SECOND, exported_at, NOW()))
               FROM risdoc_compilations_data WHERE id = {$id}"
        )->fetchColumn();

        self::assertLessThan(
            5,
            $scarto,
            "exported_at è distante {$scarto}s dall'orologio del database: sta scrivendo PHP"
        );
    }

    /**
     * La colonna esce anche dalla lettura che usa il client, che passa dalla
     * vista. Senza la ricreazione della vista nella migrazione 138 questa
     * chiave non esisterebbe, e nessuno se ne accorgerebbe.
     */
    #[Test]
    public function il_dettaglio_della_compilazione_porta_la_data(): void
    {
        $id = $this->bozza($this->mio);
        $this->repo->segnaScaricata($this->mio, $id);

        $riga = $this->repo->find($this->mio, $id);

        self::assertIsArray($riga);
        self::assertArrayHasKey('exported_at', $riga);
        self::assertNotNull($riga['exported_at']);
    }

    #[Test]
    public function anche_la_lista_la_porta(): void
    {
        $id = $this->bozza($this->mio);
        $this->repo->segnaScaricata($this->mio, $id);

        $righe = $this->repo->listByTeacher($this->mio, $this->modello);

        self::assertNotSame([], $righe, 'controllo positivo: la lista deve trovare la riga');
        self::assertArrayHasKey('exported_at', $righe[0]);
    }
}
