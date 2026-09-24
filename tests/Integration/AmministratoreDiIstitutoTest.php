<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\UsersAdminController;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Services\Institutes\AmministratoreDiIstituto;
use App\Support\DeploymentMode;
use App\Support\DeploymentScenario;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR-040 — l'amministratore di istituto, sul database vero, nei due versi.
 *
 * - l'identità la garantisce il database (migrazione 125): il ruolo è uno di
 *   quelli noti, e `admin_institute_id` c'è se e solo se il ruolo è
 *   `institute_admin`;
 * - si crea, entra e ha un ambito solo nello scenario 3;
 * - il suo ruolo non si cambia dal pannello degli utenti.
 *
 * Tutto dentro una transazione annullata alla fine: nessun dato resta.
 */
final class AmministratoreDiIstitutoTest extends TestCase
{
    private PDO $pdo;
    private string $tmp = '';
    /** @var array<string, mixed> */
    private array $configPrima = [];
    private int $istituto = 0;
    private int $altroIstituto = 0;

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
        $trigger = $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.TRIGGERS
              WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'trg_users_amm_istituto_bi'"
        )->fetchColumn();
        if ((int)$trigger !== 1) {
            self::fail('manca la migrazione 125 sul database di prova: APP_ENV=testing php tools/migrate.php');
        }

        $this->configPrima = $this->items();
        $this->tmp = sys_get_temp_dir() . '/pantedu_amm_istituto_' . uniqid();
        mkdir($this->tmp . '/config', 0750, true);
        $this->setConfig('app.paths.storage', $this->tmp);
        $this->setConfig('app.deployment_mode', 'single');
        $this->setConfig('app.instance_acn_qualified', true);
        $_SESSION = [];

        $this->pdo->beginTransaction();
        $ins = $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)');
        $ins->execute(['ZZAMMIST01', 'ISTITUTO DELL AMMINISTRATORE', 'Comune Esempio']);
        $this->istituto = (int)$this->pdo->lastInsertId();
        $ins->execute(['ZZAMMIST02', 'UN ALTRO ISTITUTO', 'Comune Esempio']);
        $this->altroIstituto = (int)$this->pdo->lastInsertId();
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
    }

    // ── L'identità, dal database ──────────────────────────────────────────

    #[Test]
    public function il_database_rifiuta_un_amministratore_di_istituto_a_meta(): void
    {
        $casi = [
            'institute_admin senza istituto' => ['institute_admin', null, 0],
            'un docente con un istituto da amministrare' => ['teacher', $this->istituto, 0],
            'un amministratore di istituto super-amministratore' => ['institute_admin', $this->istituto, 1],
            'il vecchio ruolo admin' => ['admin', $this->istituto, 0],
            // Questo lo rifiuta solo il vincolo sui ruoli: i trigger lo lascerebbero.
            'il vecchio ruolo admin, senza istituto' => ['admin', null, 0],
            'un ruolo inventato' => ['preside', null, 0],
            // 15/9/2026 — migrazione 128: il collaboratore non esiste più.
            'il vecchio ruolo collaborator' => ['collaborator', null, 0],
        ];
        foreach ($casi as $caso => [$ruolo, $istituto, $super]) {
            try {
                $this->inserisci('zz_rifiuto_' . substr(md5($caso), 0, 8), $ruolo, $istituto, $super);
                self::fail("il database ha accettato: {$caso}");
            } catch (\PDOException $e) {
                self::assertStringNotContainsString('Duplicate', $e->getMessage(), $caso);
            }
        }

        $id = $this->inserisci('zz_amm_valido', 'institute_admin', $this->istituto, 0);
        self::assertGreaterThan(0, $id, 'e accetta quello giusto');

        try {
            $this->pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute(['teacher', $id]);
            self::fail('il database ha accettato un docente che amministra un istituto');
        } catch (\PDOException) {
            self::assertTrue(true);
        }
    }

    // ── La creazione, solo nello scenario 3 ───────────────────────────────

    #[Test]
    public function il_wizard_lo_crea_nello_scenario_3_e_non_negli_altri(): void
    {
        $servizio = new AmministratoreDiIstituto($this->pdo);

        try {
            $servizio->crea($this->istituto, 'zz_amm_scenario2', 'Prova', 'Due', 'p2@example.test', false);
            self::fail('creato fuori dallo scenario 3');
        } catch (\DomainException $e) {
            self::assertSame(AmministratoreDiIstituto::FUORI_SCENARIO, $e->getMessage());
        }
        self::assertSame(0, $this->contaUtenti('zz_amm_scenario2'));

        $password = $servizio->crea($this->istituto, 'zz_amm_scenario3', 'Prova', 'Tre', 'p3@example.test', true);
        $riga = $this->riga('zz_amm_scenario3');
        self::assertSame('institute_admin', $riga['role']);
        self::assertSame($this->istituto, (int)$riga['admin_institute_id']);
        self::assertSame(1, (int)$riga['must_change_password']);
        self::assertSame(0, (int)$riga['is_super_admin']);
        self::assertTrue(password_verify($password, (string)$riga['password_hash']));
    }

    // ── L'accesso e l'ambito ──────────────────────────────────────────────

    #[Test]
    public function entra_solo_nello_scenario_3(): void
    {
        $password = (new AmministratoreDiIstituto($this->pdo))
            ->crea($this->istituto, 'zz_amm_accesso', 'Prova', 'Accesso', 'pa@example.test', true);

        $this->scenario(DeploymentScenario::COLLEAGUES);
        [$utente, $motivo] = Auth::attempt('zz_amm_accesso', $password, establishSession: false);
        self::assertNull($utente);
        self::assertSame(Auth::REASON_SCENARIO, $motivo);

        [, $motivoSbagliata] = Auth::attempt('zz_amm_accesso', 'password-sbagliata', establishSession: false);
        self::assertSame(Auth::REASON_INVALID, $motivoSbagliata, 'con la password sbagliata non rivela il ruolo');

        $this->scenario(DeploymentScenario::INSTITUTE);
        [$utente, $motivo] = Auth::attempt('zz_amm_accesso', $password, establishSession: false);
        self::assertNull($motivo);
        self::assertSame('zz_amm_accesso', $utente?->username);
    }

    #[Test]
    public function amministra_il_suo_istituto_e_non_un_altro_e_solo_nello_scenario_3(): void
    {
        $id = $this->inserisci('zz_amm_ambito', 'institute_admin', $this->istituto, 0);
        $_SESSION = [
            'autenticato' => true, 'username' => 'zz_amm_ambito', 'user_id' => $id,
            'user_role' => 'institute_admin', 'is_super_admin' => false,
        ];

        $this->scenario(DeploymentScenario::INSTITUTE);
        self::assertTrue(Auth::isAdminOfInstitute($this->istituto));
        self::assertFalse(Auth::isAdminOfInstitute($this->altroIstituto));
        self::assertSame($this->istituto, Auth::currentInstitute());
        self::assertFalse(Auth::setCurrentInstitute($this->altroIstituto), 'non si sposta in un altro istituto');

        // La sessione ha l'istituto in cache: cambiato lo scenario, non vale più.
        $this->scenario(DeploymentScenario::COLLEAGUES);
        self::assertFalse(Auth::isAdminOfInstitute($this->istituto));
        self::assertNull(Auth::currentInstitute());
        self::assertFalse(Auth::setCurrentInstitute($this->istituto));
    }

    #[Test]
    public function il_suo_ruolo_non_si_cambia_dal_pannello_degli_utenti(): void
    {
        $this->inserisci('zz_super_prova', 'administrator', null, 1);
        $bersaglio = $this->inserisci('zz_amm_ruolo', 'institute_admin', $this->istituto, 0);
        $docente = $this->inserisci('zz_doc_ruolo', 'teacher', null, 0);
        $_SESSION = [
            'autenticato' => true, 'username' => 'zz_super_prova',
            'user_role' => 'administrator', 'is_super_admin' => true,
        ];
        $_POST = ['role' => 'teacher'];

        $risposta = (new UsersAdminController())->setRole(new Request(), ['id' => (string)$bersaglio]);
        self::assertSame(409, $risposta->status);
        self::assertSame('institute_admin', $this->riga('zz_amm_ruolo')['role']);

        $_POST = ['role' => 'administrator'];
        $risposta = (new UsersAdminController())->setRole(new Request(), ['id' => (string)$docente]);
        self::assertSame(200, $risposta->status, 'e per un docente il cambio resta possibile');
        self::assertSame('administrator', $this->riga('zz_doc_ruolo')['role']);
    }

    // ── Aiuti ─────────────────────────────────────────────────────────────

    private function inserisci(string $username, string $ruolo, ?int $istituto, int $super): int
    {
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, is_super_admin, admin_institute_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
        )->execute([$username, $ruolo, 'Prova', 'Prova', $username . '@example.test', password_hash('x', PASSWORD_BCRYPT), 'approved', $super, $istituto]);
        return (int)$this->pdo->lastInsertId();
    }

    private function contaUtenti(string $username): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $stmt->execute([$username]);
        return (int)$stmt->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function riga(string $username): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $riga = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($riga, "l'utente {$username} c'è");
        return $riga;
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
