<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\UsersAdminController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Support\DeploymentMode;
use App\Support\DeploymentScenario;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il pannello «Utenti» degli strumenti, sul server, nei due versi (15/9/2026).
 *
 * - Il ruolo «student» si assegna solo dove esistono gli account studente (lo
 *   scenario 3): negli scenari 1 e 2 il server lo rifiuta con 409, come la
 *   pagina non lo offre (App\Support\RuoliNelPannelloUtenti).
 * - Un amministratore non disattiva, non elimina e non si toglie il ruolo da
 *   sé: la pagina non mostra più quei comandi sulla sua riga, e questa prova
 *   fissa che il server li rifiuta. Prima era provato solo il primo, e solo
 *   dalla suite end-to-end.
 *
 * Tutto in transazione; lo scenario si cambia nella configurazione in memoria e
 * si rimette com'era.
 */
final class PannelloUtentiRuoliTest extends TestCase
{
    private PDO $pdo;
    /** @var array<string, mixed> */
    private array $configPrima = [];
    private string $tmp = '';

    protected function setUp(): void
    {
        $base = dirname(__DIR__, 2);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$base/$f")) {
                \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
            }
        }
        Config::load($base . '/app/Config');
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }
        $this->configPrima = $this->items();
        $this->tmp = sys_get_temp_dir() . '/pantedu_pannello_utenti_' . uniqid();
        mkdir($this->tmp . '/config', 0750, true);
        $this->setConfig('app.paths.storage', $this->tmp);
        $this->setConfig('app.deployment_mode', 'single');
        $this->setConfig('app.instance_acn_qualified', true);
        $_SESSION = [];
        $_POST = [];
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if ($this->configPrima !== []) {
            $prop = (new \ReflectionClass(Config::class))->getProperty('items');
            $prop->setAccessible(true);
            $prop->setValue(null, $this->configPrima);
        }
        DeploymentScenario::resetCache();
        DeploymentMode::resetCache();
        if ($this->tmp !== '') {
            @rmdir($this->tmp . '/config');
            @rmdir($this->tmp);
        }
        $_SESSION = [];
        $_POST = [];
    }

    #[Test]
    public function lo_studente_si_assegna_solo_nello_scenario_con_gli_account_studente(): void
    {
        $this->entraCome($this->inserisci('zz_pannello_super', 'administrator', 1));
        $docente = $this->inserisci('zz_pannello_doc', 'teacher', 0);

        foreach ([DeploymentScenario::PERSONAL, DeploymentScenario::COLLEAGUES] as $scenario) {
            $this->scenario($scenario);
            $_POST = ['role' => 'student'];
            $risposta = (new UsersAdminController())->setRole(new Request(), ['id' => (string)$docente]);
            self::assertSame(409, $risposta->status, "nello scenario $scenario lo studente non si assegna");
            self::assertSame('ruolo_non_previsto_nello_scenario', json_decode((string)$risposta->body, true)['error'] ?? null);
            self::assertSame('teacher', $this->ruolo($docente));
        }

        $this->scenario(DeploymentScenario::INSTITUTE);
        self::assertTrue(DeploymentScenario::studentAccountsEnabled(), 'la prova deve girare con gli account studente');
        $_POST = ['role' => 'student'];
        $risposta = (new UsersAdminController())->setRole(new Request(), ['id' => (string)$docente]);
        self::assertSame(200, $risposta->status, 'nello scenario 3 sì');
        self::assertSame('student', $this->ruolo($docente));

        $_POST = ['role' => 'institute_admin'];
        $risposta = (new UsersAdminController())->setRole(new Request(), ['id' => (string)$docente]);
        self::assertSame(400, $risposta->status, 'l\'amministratore di istituto non si dà da qui');
    }

    #[Test]
    public function un_amministratore_non_si_disattiva_non_si_elimina_e_non_si_toglie_il_ruolo(): void
    {
        $this->scenario(DeploymentScenario::PERSONAL);
        $io = $this->inserisci('zz_pannello_io', 'administrator', 1);
        $altro = $this->inserisci('zz_pannello_altro', 'teacher', 0);
        $this->entraCome($io);
        $controller = new UsersAdminController();

        $_POST = ['active' => '0'];
        self::assertSame(403, $controller->setActive(new Request(), ['id' => (string)$io])->status);
        $_POST = ['role' => 'teacher'];
        self::assertSame(403, $controller->setRole(new Request(), ['id' => (string)$io])->status);
        $_POST = [];
        self::assertSame(403, $controller->delete(new Request(), ['id' => (string)$io])->status);

        $st = $this->pdo->prepare('SELECT role, active FROM users WHERE id = ?');
        $st->execute([$io]);
        self::assertSame(['role' => 'administrator', 'active' => 1], array_map(
            static fn($v) => is_numeric($v) ? (int)$v : $v,
            (array)$st->fetch(PDO::FETCH_ASSOC)
        ), 'l\'account è com\'era');

        // Controprova: sugli altri le stesse azioni riescono.
        $_POST = ['active' => '0'];
        self::assertSame(200, $controller->setActive(new Request(), ['id' => (string)$altro])->status);
        $_POST = [];
        self::assertSame(200, $controller->delete(new Request(), ['id' => (string)$altro])->status);
    }

    private function inserisci(string $username, string $ruolo, int $super): int
    {
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, is_super_admin)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)'
        )->execute([$username, $ruolo, 'Prova', 'Pannello', $username . '@example.test', 'x', 'approved', $super]);
        return (int)$this->pdo->lastInsertId();
    }

    private function entraCome(int $id): void
    {
        $st = $this->pdo->prepare('SELECT username FROM users WHERE id = ?');
        $st->execute([$id]);
        $_SESSION = [
            'autenticato' => true, 'username' => (string)$st->fetchColumn(),
            'user_role' => 'administrator', 'is_super_admin' => true,
        ];
    }

    private function ruolo(int $id): string
    {
        $st = $this->pdo->prepare('SELECT role FROM users WHERE id = ?');
        $st->execute([$id]);
        return (string)$st->fetchColumn();
    }

    private function scenario(string $scenario): void
    {
        $this->setConfig('app.deployment_scenario', $scenario);
        DeploymentScenario::resetCache();
        DeploymentMode::resetCache();
        self::assertSame($scenario, DeploymentScenario::current(), 'la prova deve girare davvero in quello scenario');
    }

    /** @return array<string, mixed> */
    private function items(): array
    {
        $prop = (new \ReflectionClass(Config::class))->getProperty('items');
        $prop->setAccessible(true);
        /** @var array<string, mixed> $valore */
        $valore = $prop->getValue();
        return $valore;
    }

    private function setConfig(string $chiave, mixed $valore): void
    {
        $prop = (new \ReflectionClass(Config::class))->getProperty('items');
        $prop->setAccessible(true);
        $items = $prop->getValue();
        [$ns, $sub] = explode('.', $chiave, 2);
        if (str_contains($sub, '.')) {
            [$a, $b] = explode('.', $sub, 2);
            $items[$ns][$a][$b] = $valore;
        } else {
            $items[$ns][$sub] = $valore;
        }
        $prop->setValue(null, $items);
    }
}
