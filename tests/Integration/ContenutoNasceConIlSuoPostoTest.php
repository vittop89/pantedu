<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use App\Repositories\TeacherContentRepository;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Un contenuto nasce con il suo posto, o non nasce (23/9/2026, revisione
 * architetturale 2026-09, rilievo A-76).
 *
 * `TeacherContentRepository::create` scriveva il contenuto, poi il posto
 * principale (`PostoPrincipale::contenuto`), poi la riga di audit, senza
 * transazione. Se il posto falliva il contenuto restava lì senza posto,
 * invisibile a chi naviga, e il nuovo tentativo con lo stesso titolo
 * rispondeva 409. Adesso le tre scritture stanno in una transazione, aperta
 * solo se chi chiama non ne ha già una: in quel caso decide lui (come
 * `ImportBundleController::insertMappa` e `MapsController::create`).
 *
 * Il guasto a metà qui è vero: la classe punta a una voce di curriculum che
 * non esiste, e l'INSERT della principale fallisce sulla chiave esterna di
 * `content_publications.classe_id` dopo che il contenuto è già stato scritto.
 *
 * Queste prove NON girano in una transazione propria — devono vedere quella
 * di `create` — quindi scrivono davvero sul database locale: nomi con una
 * marca unica, e `tearDown` cancella tutto, anche quando una prova fallisce.
 *
 * Controprova (23/9/2026): con il `create` di origin/main il guasto a metà
 * lascia il contenuto nel database (la prova lo trova per titolo), e dentro
 * la transazione di chi chiama il comportamento non cambia (non ne aveva una
 * sua).
 */
final class ContenutoNasceConIlSuoPostoTest extends TestCase
{
    private PDO $pdo;
    private string $marca = '';
    private int $scuola = 0;
    private int $materia = 0;
    private int $docente = 0;

    /** @var array<string, mixed> */
    private array $configPrima = [];

    protected function setUp(): void
    {
        $base = \dirname(__DIR__, 2);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$base/$f")) {
                \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
            }
        }
        Config::load($base . '/app/Config');
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1 FROM content_publications LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }
        /** @var array<string, mixed> $prima */
        $prima = (new ReflectionProperty(Config::class, 'items'))->getValue();
        $this->configPrima = $prima;
        // Niente cifratura qui: non è quello che si prova, e vorrebbe le chiavi del docente.
        Config::set('crypto.dual_write', false);

        $this->marca = substr((string)hrtime(true), -9);
        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, "Comune Esempio", 1)')
            ->execute(['ZZPOSTO' . $this->marca, 'Scuola del posto ' . $this->marca]);
        $this->scuola = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO curriculum_entries (kind, institute_id, code, label, active, origine) VALUES ("materie", ?, ?, ?, 1, "istituto")'
        )->execute([$this->scuola, 'ZM' . substr($this->marca, -6), 'Materia di prova']);
        $this->materia = (int)$this->pdo->lastInsertId();
        $utente = 'zzposto' . $this->marca;
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES (?, "teacher", "Zz", "Posto", ?, "x", "approved", 1, NOW())'
        )->execute([$utente, $utente . '@example.invalid']);
        $this->docente = (int)$this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        // Tutto quello che la prova può aver scritto, anche se è fallita a metà.
        // content_publications va via con il contenuto (ON DELETE CASCADE).
        if ($this->docente > 0) {
            $this->pdo->prepare('DELETE FROM content_action_log WHERE teacher_id = ?')->execute([$this->docente]);
            $this->pdo->prepare('DELETE FROM teacher_content_data WHERE teacher_id = ?')->execute([$this->docente]);
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->docente]);
        }
        if ($this->scuola > 0) {
            $this->pdo->prepare('DELETE FROM curriculum_entries WHERE institute_id = ?')->execute([$this->scuola]);
            $this->pdo->prepare('DELETE FROM institutes WHERE id = ?')->execute([$this->scuola]);
        }
        (new ReflectionProperty(Config::class, 'items'))->setValue(null, $this->configPrima);
    }

    /** Una voce di curriculum che non c'è: l'id più alto più un milione. */
    private function voceCheNonCe(): int
    {
        return (int)$this->pdo->query('SELECT COALESCE(MAX(id), 0) FROM curriculum_entries')->fetchColumn() + 1_000_000;
    }

    /** @return array<string, mixed> */
    private function dati(string $titolo, ?int $classe = null): array
    {
        return [
            'teacher_id'   => $this->docente,
            'content_type' => 'esercizio',
            'subject_code' => 'ZM',
            'subject_id'   => $this->materia,
            'classe_id'    => $classe,
            'topic'        => 'Posto',
            'title'        => $titolo,
            'visibility'   => 'draft',
        ];
    }

    private function contenutiConTitolo(string $titolo): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM teacher_content_data WHERE teacher_id = ? AND title = ?');
        $st->execute([$this->docente, $titolo]);
        return (int)$st->fetchColumn();
    }

    private function righeDiAudit(): int
    {
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM content_action_log WHERE teacher_id = ? AND action = 'content_created'");
        $st->execute([$this->docente]);
        return (int)$st->fetchColumn();
    }

    #[Test]
    public function un_guasto_a_meta_non_lascia_un_contenuto_senza_posto(): void
    {
        $titolo = 'Senza posto ' . $this->marca;
        $lanciata = null;
        try {
            (new TeacherContentRepository())->create($this->dati($titolo, $this->voceCheNonCe()));
        } catch (\Throwable $e) {
            $lanciata = $e;
        }

        $this->assertInstanceOf(\PDOException::class, $lanciata, 'la principale fallisce sulla chiave esterna della classe');
        $this->assertSame(0, $this->contenutiConTitolo($titolo), 'il contenuto non resta senza il suo posto');
        $this->assertSame(0, $this->righeDiAudit(), 'nessuna riga di audit per un contenuto che non c\'è');
        $this->assertFalse($this->pdo->inTransaction(), 'la transazione di create si chiude anche nel guasto');

        // E il nuovo tentativo, con la classe giusta, non trova un doppione.
        $id = (new TeacherContentRepository())->create($this->dati($titolo));
        $this->assertGreaterThan(0, $id);
        $this->assertSame(1, $this->contenutiConTitolo($titolo));
    }

    /** Il verso opposto: senza guasto, contenuto, posto e audit ci sono tutti e tre. */
    #[Test]
    public function un_contenuto_sano_nasce_con_posto_e_audit(): void
    {
        $id = (new TeacherContentRepository())->create($this->dati('Con posto ' . $this->marca));

        $st = $this->pdo->prepare('SELECT institute_id, subject_id FROM content_publications WHERE primary_of_tc = ?');
        $st->execute([$id]);
        $posto = $st->fetch(PDO::FETCH_ASSOC);
        $this->assertSame([$this->scuola, $this->materia], [(int)($posto['institute_id'] ?? 0), (int)($posto['subject_id'] ?? 0)]);
        $this->assertSame(1, $this->righeDiAudit());
        $this->assertFalse($this->pdo->inTransaction(), 'create ha chiuso la sua transazione');
    }

    /**
     * Dentro la transazione di chi chiama, `create` non la chiude né la
     * annulla: decide chi l'ha aperta.
     */
    #[Test]
    public function dentro_la_transazione_di_chi_chiama_decide_lui(): void
    {
        $titolo = 'Nella transazione ' . $this->marca;
        $this->pdo->beginTransaction();

        (new TeacherContentRepository())->create($this->dati($titolo));
        $this->assertTrue($this->pdo->inTransaction(), 'create non ha fatto commit al posto di chi chiama');

        try {
            (new TeacherContentRepository())->create($this->dati('Guasto ' . $titolo, $this->voceCheNonCe()));
            $this->fail('la principale doveva fallire');
        } catch (\PDOException) {
        }
        $this->assertTrue($this->pdo->inTransaction(), 'né ha annullato la transazione di chi chiama');
        $this->assertSame(1, $this->contenutiConTitolo($titolo), 'il primo contenuto è ancora lì, non confermato');

        $this->pdo->rollBack();
        $this->assertSame(0, $this->contenutiConTitolo($titolo), 'chi chiama annulla, e il contenuto sparisce');
    }
}
