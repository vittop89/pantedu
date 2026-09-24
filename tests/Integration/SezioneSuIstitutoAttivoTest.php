<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\TeacherContentController;
use App\Core\Database;
use App\Core\Request;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La sezione della barra con cui nasce un contenuto si risolve nella scuola
 * attiva del docente, la stessa in cui il repository risolve indirizzo,
 * classe e materia. Fino al 13 settembre 2026 `TeacherContentController`
 * la cercava nel PRIMO istituto collegato (rilettura degli scenari, §1.2):
 * per un docente di due scuole, con la seconda attiva, una sezione propria
 * della seconda non si trovava e il contenuto nasceva senza sezione.
 *
 * Il controller si chiama direttamente con la sessione costruita a mano
 * (niente middleware, niente CSRF). Fixture isolata in transazione: due
 * istituti, il docente collegato a entrambi, una sezione che esiste solo
 * nel secondo, la materia in entrambi. Tipo `document`, che non crea file
 * su disco.
 */
final class SezioneSuIstitutoAttivoTest extends TestCase
{
    private PDO $pdo;
    private int $docente = 0;
    private int $prima = 0;
    private int $seconda = 0;
    private int $sezioneDellaSeconda = 0;
    private bool $inTx = false;
    private const CHIAVE = 'zzsezseconda';

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
            $this->pdo->query('SELECT section_id FROM teacher_content_data LIMIT 1');
            $this->pdo->query('SELECT 1 FROM sidebar_sections LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB o migrazioni 070/071 non disponibili: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();
        $this->inTx = true;
        \App\Support\CurriculumLookup::resetCache();

        $ins = $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)');
        $ins->execute(['ZZSEZ01', 'PRIMA SCUOLA', 'Comune Esempio']);
        $this->prima = (int)$this->pdo->lastInsertId();
        $ins->execute(['ZZSEZ02', 'SECONDA SCUOLA', 'Comune Esempio']);
        $this->seconda = (int)$this->pdo->lastInsertId();

        $voce = $this->pdo->prepare(
            'INSERT INTO curriculum_entries (kind, institute_id, code, label, active, shared_with_pool, origine)
             VALUES (?, ?, ?, ?, 1, 0, "istituto")'
        );
        $voce->execute(['materie', $this->prima, 'ZSM', 'Materia']);
        $voce->execute(['materie', $this->seconda, 'ZSM', 'Materia']);

        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash,
                                status, active, created_at)
             VALUES (?, "teacher", "Zz", "Sezione", ?, "x", "approved", 1, NOW())'
        )->execute(['zzsezione', 'zzsezione@example.invalid']);
        $this->docente = (int)$this->pdo->lastInsertId();
        $ti = $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)');
        $ti->execute([$this->docente, $this->prima]);
        $ti->execute([$this->docente, $this->seconda]);

        $this->pdo->prepare(
            'INSERT INTO sidebar_sections
                (institute_id, section_key, label, position, loader_kind, group_mode,
                 allowed_content_types, default_content_type, visible_roles, active, is_default)
             VALUES (?, ?, ?, 99, "db", "subject", ?, "document", ?, 1, 0)'
        )->execute([$this->seconda, self::CHIAVE, 'Solo nella seconda', '["document"]', '["teacher","student"]']);
        $this->sezioneDellaSeconda = (int)$this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $_SESSION = [];
        $_POST = [];
        \App\Support\CurriculumLookup::resetCache();
    }

    private function sessioneNellaScuola(int $istituto): void
    {
        $_SESSION = [
            'autenticato'          => true,
            'username'             => 'zzsezione',
            'user_id'              => $this->docente,
            'user_role'            => 'teacher',
            'current_institute_id' => $istituto,
        ];
    }

    private function crea(): array
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/api/teacher/content';
        $_POST = [
            'type'        => 'document',
            'subject'     => 'ZSM',
            'topic'       => 'Sezione attiva',
            'title'       => 'Sezione attiva ' . uniqid(),
            'section_key' => self::CHIAVE,
            'visibility'  => 'draft',
        ];
        $res = (new TeacherContentController())->store(new Request());
        $body = json_decode($res->body, true);
        $this->assertIsArray($body);
        $this->assertTrue($body['ok'] ?? false, 'creazione riuscita: ' . json_encode($body));
        // ADR-037, fase 4c-2: la materia sta nella principale, e la vista la dice.
        $st = $this->pdo->prepare('SELECT section_id, subject_id FROM teacher_content WHERE id = ?');
        $st->execute([(int)$body['id']]);
        $riga = $st->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($riga);
        return $riga;
    }

    #[Test]
    public function con_la_seconda_scuola_attiva_la_sua_sezione_si_trova(): void
    {
        $this->sessioneNellaScuola($this->seconda);
        $riga = $this->crea();
        $this->assertSame($this->sezioneDellaSeconda, (int)$riga['section_id'], 'prima: NULL, cercata nella prima scuola');
        $st = $this->pdo->prepare('SELECT institute_id FROM curriculum_entries WHERE id = ?');
        $st->execute([(int)$riga['subject_id']]);
        $this->assertSame($this->seconda, (int)$st->fetchColumn(), 'la materia è risolta nella stessa scuola della sezione');
    }

    #[Test]
    public function con_la_prima_scuola_attiva_la_sezione_della_seconda_non_si_trova(): void
    {
        $this->sessioneNellaScuola($this->prima);
        $riga = $this->crea();
        $this->assertNull($riga['section_id'], 'una chiave di un altra scuola non risolve');
        $st = $this->pdo->prepare('SELECT institute_id FROM curriculum_entries WHERE id = ?');
        $st->execute([(int)$riga['subject_id']]);
        $this->assertSame($this->prima, (int)$st->fetchColumn());
    }
}
