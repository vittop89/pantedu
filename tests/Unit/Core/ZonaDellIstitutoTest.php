<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Auth;
use App\Core\Config;
use App\Support\DeploymentMode;
use App\Support\DeploymentScenario;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR-040 — le zone dell'amministratore di istituto, nei due versi.
 *
 * Nello scenario 3 sta nella zona pubblica e in quella dell'istituto, e in
 * nessun'altra: tutto ciò che è riservato ad amministratori, docenti e
 * studenti gli resta chiuso per costruzione. Fuori dallo scenario 3 non ha
 * nessuna zona, nemmeno con una sessione aperta prima che lo scenario
 * cambiasse. L'amministratore della piattaforma non cambia.
 */
final class ZonaDellIstitutoTest extends TestCase
{
    private string $tmp;
    /** @var array<string, mixed> */
    private array $configPrima = [];

    protected function setUp(): void
    {
        Config::load(dirname(__DIR__, 3) . '/app/Config');
        $this->configPrima = $this->items();
        $this->tmp = sys_get_temp_dir() . '/pantedu_zona_istituto_' . uniqid();
        mkdir($this->tmp . '/config', 0750, true);
        $this->setConfig('app.paths.storage', $this->tmp);
        $this->setConfig('app.deployment_mode', 'single');
        $this->setConfig('app.instance_acn_qualified', true);
        $_SESSION = ['autenticato' => true, 'is_super_admin' => false];
    }

    protected function tearDown(): void
    {
        $prop = (new \ReflectionClass(Config::class))->getProperty('items');
        $prop->setAccessible(true);
        $prop->setValue(null, $this->configPrima);
        DeploymentScenario::resetCache();
        DeploymentMode::resetCache();
        @rmdir($this->tmp . '/config');
        @rmdir($this->tmp);
        $_SESSION = [];
    }

    #[Test]
    public function nello_scenario_3_l_amministratore_di_istituto_ha_solo_la_sua_zona(): void
    {
        $this->scenario(DeploymentScenario::INSTITUTE);
        $_SESSION['user_role'] = 'institute_admin';

        self::assertSame(['public', 'istituto'], Auth::zone());
        foreach (['student', 'teacher', 'admin'] as $zona) {
            self::assertFalse(Auth::hasAccess($zona), "la zona «{$zona}» deve restargli chiusa");
        }
    }

    #[Test]
    public function fuori_dallo_scenario_3_non_ha_nessuna_zona(): void
    {
        foreach ([DeploymentScenario::PERSONAL, DeploymentScenario::COLLEAGUES] as $scenario) {
            $this->scenario($scenario);
            $_SESSION['user_role'] = 'institute_admin';
            self::assertSame([], Auth::zone(), "scenario {$scenario}");
            self::assertFalse(Auth::amministratoreDiIstitutoFuoriScenario('teacher'), 'la regola vale solo per il suo ruolo');
            self::assertTrue(Auth::amministratoreDiIstitutoFuoriScenario('institute_admin'));
        }
    }

    #[Test]
    public function l_amministratore_della_piattaforma_non_cambia(): void
    {
        foreach ([DeploymentScenario::COLLEAGUES, DeploymentScenario::INSTITUTE] as $scenario) {
            $this->scenario($scenario);
            $_SESSION['user_role'] = 'administrator';
            self::assertSame(
                ['public', 'student', 'teacher', 'admin', 'istituto'],
                Auth::zone(),
                "scenario {$scenario}",
            );
        }
    }

    #[Test]
    public function il_vecchio_valore_admin_non_apre_niente(): void
    {
        $this->scenario(DeploymentScenario::INSTITUTE);
        $_SESSION['user_role'] = 'admin';
        self::assertSame([], Auth::zone());
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
