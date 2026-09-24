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
 * La ricerca nei contenuti del docente sceglie la scuola (14/9/2026).
 *
 * `/api/teacher/content` guardava solo la scuola attiva. Il cruscotto ora può
 * chiedere un'altra delle scuole del docente, o «tutte». Qui il lato che conta
 * per la sicurezza, nei due versi: una scuola del docente passa, una che non è
 * sua riceve 403 (e non la scuola attiva al suo posto), «tutte» passa.
 * DB-gated, tutto in transazione → rollback in tearDown.
 */
final class RicercaPerScuolaTest extends TestCase
{
    private PDO $pdo;
    private int $sua = 0;
    private int $altrui = 0;
    private int $docente = 0;
    private bool $inTx = false;
    /** @var array<string, mixed> */
    private array $serverPrima = [];
    /** @var array<string, mixed> */
    private array $getPrima = [];

    protected function setUp(): void
    {
        $base = dirname(__DIR__, 2);
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
        $this->pdo->beginTransaction();
        $this->inTx = true;

        $crea = $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)');
        $crea->execute(['ZZRICERCA1', 'ISTITUTO RICERCA SUA', 'Comune Esempio']);
        $this->sua = (int)$this->pdo->lastInsertId();
        $crea->execute(['ZZRICERCA2', 'ISTITUTO RICERCA ALTRUI', 'Comune Esempio']);
        $this->altrui = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES ("zzricerca", "teacher", "Zz", "Ricerca", "zzricerca@example.invalid", "x", "approved", 1, NOW())'
        )->execute();
        $this->docente = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')
            ->execute([$this->docente, $this->sua]);

        $this->serverPrima = $_SERVER;
        $this->getPrima = $_GET;
        $_SESSION = [
            'autenticato'          => true,
            'username'             => 'zzricerca',
            'user_role'            => 'teacher',
            'user_id'              => $this->docente,
            'is_super_admin'       => false,
            'current_institute_id' => $this->sua,
        ];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverPrima;
        $_GET = $this->getPrima;
        $_SESSION = [];
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function cerca(string $scuola): array
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/teacher/content?institute_id=' . rawurlencode($scuola);
        $_GET = ['institute_id' => $scuola, 'limit' => '5'];
        $risposta = (new TeacherContentController())->index(new Request());
        /** @var array<string, mixed> $corpo */
        $corpo = json_decode($risposta->body, true) ?: [];
        return [$risposta->status, $corpo];
    }

    #[Test]
    public function una_scuola_del_docente_passa(): void
    {
        [$stato, $corpo] = $this->cerca((string)$this->sua);

        $this->assertSame(200, $stato);
        $this->assertTrue($corpo['ok'] ?? false);
    }

    #[Test]
    public function una_scuola_che_non_e_del_docente_riceve_403(): void
    {
        [$stato, $corpo] = $this->cerca((string)$this->altrui);

        $this->assertSame(403, $stato, 'non la scuola attiva al suo posto');
        $this->assertSame('scuola_non_tua', $corpo['error'] ?? null);
    }

    #[Test]
    public function un_valore_che_non_e_una_scuola_riceve_403(): void
    {
        $this->assertSame(403, $this->cerca('1 OR 1=1')[0]);
        $this->assertSame(403, $this->cerca('0')[0]);
    }

    #[Test]
    public function tutte_le_mie_scuole_passa(): void
    {
        [$stato, $corpo] = $this->cerca('tutte');

        $this->assertSame(200, $stato);
        $this->assertTrue($corpo['ok'] ?? false);
    }
}
