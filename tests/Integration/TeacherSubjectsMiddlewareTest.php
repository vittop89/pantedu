<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\TeacherSubjectsMiddleware;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il cancello delle materie ferma le pagine, non le chiamate dei dati (14/9/2026).
 *
 * Un docente senza materie in una scuola che le ha a catalogo viene mandato a
 * sceglierle. Il rinvio valeva anche per le chiamate dell'API: il profilo, che
 * è esente perché il docente fermo ci arrivi, chiedeva le credenziali di
 * classe e gli istituti e riceveva la pagina delle materie con 200 («Risposta
 * non JSON»). In produzione il liceo musicale ha 14 materie a catalogo e il suo
 * docente nessuna.
 *
 * Nei due versi: con il docente senza materie la navigazione è rimandata e le
 * richieste di dati passano; con una materia passa anche la navigazione.
 * DB-gated, tutto in transazione → rollback in tearDown.
 */
final class TeacherSubjectsMiddlewareTest extends TestCase
{
    private PDO $pdo;
    private int $scuola = 0;
    private int $docente = 0;
    private bool $inTx = false;
    /** @var array<string, mixed> */
    private array $serverPrima = [];

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

        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
            ->execute(['ZZCANCELLO', 'ISTITUTO CANCELLO MATERIE', 'Comune Esempio']);
        $this->scuola = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO curriculum_entries (kind, institute_id, code, label, active, shared_with_pool)
             VALUES ("materie", ?, "MUS", "Esecuzione e interpretazione", 1, 0)'
        )->execute([$this->scuola]);
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES ("zzcancello", "teacher", "Zz", "Cancello", "zzcancello@example.invalid", "x", "approved", 1, NOW())'
        )->execute();
        $this->docente = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')
            ->execute([$this->docente, $this->scuola]);

        $this->serverPrima = $_SERVER;
        $_SESSION = [
            'autenticato'          => true,
            'username'             => 'zzcancello',
            'user_role'            => 'teacher',
            'user_id'              => $this->docente,
            'is_super_admin'       => false,
            'current_institute_id' => $this->scuola,
        ];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverPrima;
        $_SESSION = [];
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /** @param array<string, string> $intestazioni */
    private function passa(string $percorso, array $intestazioni = []): Response
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $percorso;
        foreach (['HTTP_ACCEPT', 'HTTP_X_REQUESTED_WITH'] as $k) {
            unset($_SERVER[$k]);
        }
        foreach ($intestazioni as $k => $v) {
            $_SERVER[$k] = $v;
        }
        return (new TeacherSubjectsMiddleware())->handle(new Request(), static fn(): Response => Response::json(['ok' => true]));
    }

    #[Test]
    public function senza_materie_la_navigazione_nell_area_del_docente_va_a_sceglierle(): void
    {
        $esito = $this->passa('/area-docente/dashboard', ['HTTP_ACCEPT' => 'text/html']);

        $this->assertSame(302, $esito->status, 'il cancello resta per le pagine');
        $this->assertSame('/area-docente/materie', $esito->headers['Location'] ?? null);
    }

    /** @return array<string, array{0: string, 1: array<string, string>}> */
    public static function richiesteDiDati(): array
    {
        return [
            'credenziali del profilo'   => ['/api/teacher/credentials?t=1789378564031', []],
            'istituti della sincronizzazione' => ['/api/teacher/institutes', []],
            'un file .json'             => ['/teacher/drive/status.json', []],
            'una richiesta che chiede JSON' => ['/area-docente/qualcosa', ['HTTP_ACCEPT' => 'application/json']],
            'una richiesta XHR'         => ['/area-docente/altro', ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']],
        ];
    }

    /** @param array<string, string> $intestazioni */
    #[Test]
    #[DataProvider('richiesteDiDati')]
    public function senza_materie_le_richieste_di_dati_passano(string $percorso, array $intestazioni): void
    {
        $esito = $this->passa($percorso, $intestazioni);

        $this->assertSame(200, $esito->status, "{$percorso}: nessun rinvio a una pagina HTML");
    }

    #[Test]
    public function con_una_materia_passa_anche_la_navigazione(): void
    {
        $this->pdo->prepare(
            'INSERT INTO curriculum_teacher (user_id, curriculum_id, active)
             SELECT ?, id, 1 FROM curriculum_entries WHERE institute_id = ? AND kind = "materie" AND code = "MUS"'
        )->execute([$this->docente, $this->scuola]);

        $this->assertSame(200, $this->passa('/area-docente/dashboard', ['HTTP_ACCEPT' => 'text/html'])->status);
    }
}
