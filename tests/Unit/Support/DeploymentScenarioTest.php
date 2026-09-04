<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Core\Config;
use App\Support\DeploymentMode;
use App\Support\DeploymentScenario;
use App\Support\StudentRegistration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR-032 — scenari di esercizio. Storage in una directory temporanea: i
 * file di override non devono toccare quelli dell'installazione.
 */
final class DeploymentScenarioTest extends TestCase
{
    private string $tmp;

    public static function setUpBeforeClass(): void
    {
        Config::load(dirname(__DIR__, 3) . '/app/Config');
    }

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/pantedu_scenario_' . uniqid();
        mkdir($this->tmp . '/config', 0750, true);
        $this->setConfig('app.paths.storage', $this->tmp);
        $this->setConfig('app.deployment_mode', 'single');
        $this->setConfig('app.deployment_scenario', '');
        $this->setConfig('app.instance_acn_qualified', false);
        $this->setConfig('app.instance_operator_name', 'Gestore Test');
        $this->resetAll();
    }

    protected function tearDown(): void
    {
        $this->resetAll();
        foreach (glob($this->tmp . '/config/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp . '/config');
        @rmdir($this->tmp);
    }

    #[Test]
    public function senza_configurazione_lo_scenario_e_personale(): void
    {
        self::assertSame(DeploymentScenario::PERSONAL, DeploymentScenario::current());
        self::assertTrue(DeploymentScenario::isPersonal());
        self::assertFalse(DeploymentScenario::teacherSelfSignupOpen());
        self::assertFalse(DeploymentScenario::studentAccountsEnabled());
        self::assertSame([], DeploymentScenario::allowedRegistrationRoles());
        self::assertSame('legacy_mode', DeploymentScenario::snapshot()['source']);
    }

    #[Test]
    public function il_modo_legacy_institute_diventa_scenario_3(): void
    {
        $this->setConfig('app.deployment_mode', 'institute');
        $this->resetAll();
        self::assertSame(DeploymentScenario::INSTITUTE, DeploymentScenario::current());
    }

    #[Test]
    public function la_variabile_env_vince_sul_modo_legacy(): void
    {
        $this->setConfig('app.deployment_mode', 'institute');
        $this->setConfig('app.deployment_scenario', 'colleagues');
        $this->resetAll();
        self::assertSame(DeploymentScenario::COLLEAGUES, DeploymentScenario::current());
        self::assertSame('env', DeploymentScenario::snapshot()['source']);
    }

    #[Test]
    public function nello_scenario_2_si_iscrivono_solo_i_docenti(): void
    {
        DeploymentScenario::persist(DeploymentScenario::COLLEAGUES, 'tester', 'apro ai colleghi dopo il parere del DPO');
        $this->resetAll();

        self::assertTrue(DeploymentScenario::isColleagues());
        self::assertTrue(DeploymentScenario::teacherSelfSignupOpen());
        self::assertFalse(DeploymentScenario::studentAccountsEnabled());
        self::assertSame(['teacher'], DeploymentScenario::allowedRegistrationRoles());
        // Asse legacy allineato: modo single, registrazione studenti anonima.
        self::assertTrue(DeploymentMode::isSingle());
        self::assertTrue(StudentRegistration::isAnonymous());
        self::assertSame('runtime_override', DeploymentScenario::snapshot()['source']);
        self::assertSame('tester', DeploymentScenario::snapshot()['updated_by']);
    }

    #[Test]
    public function lo_scenario_3_richiede_l_istanza_qualificata_acn(): void
    {
        $inst = ['institute_owner_email' => 'dpo@scuola.test', 'institute_legal_name' => 'IIS Test'];
        self::assertSame('institute_requires_acn_instance', DeploymentScenario::activationBlocker('institute', $inst));

        $this->setConfig('app.instance_acn_qualified', true);
        self::assertNull(DeploymentScenario::activationBlocker('institute', $inst));
        self::assertSame('invalid_email', DeploymentScenario::activationBlocker('institute', ['institute_legal_name' => 'IIS']));
        self::assertSame('invalid_name', DeploymentScenario::activationBlocker('institute', ['institute_owner_email' => 'dpo@scuola.test']));
    }

    #[Test]
    public function lo_scenario_1_e_bloccato_se_ci_sono_altri_utenti_attivi(): void
    {
        self::assertSame('personal_blocked_active_users', DeploymentScenario::activationBlocker('personal', [], 3));
        self::assertNull(DeploymentScenario::activationBlocker('personal', [], 1));
        self::assertSame('invalid_scenario', DeploymentScenario::activationBlocker('saas'));
    }

    #[Test]
    public function nello_scenario_3_il_titolare_e_l_istituto_e_il_dpa_compare_fra_i_documenti(): void
    {
        $this->setConfig('app.instance_acn_qualified', true);
        DeploymentScenario::persist(DeploymentScenario::INSTITUTE, 'tester', 'delibera del collegio', [
            'institute_owner_email' => 'dpo@scuola.test',
            'institute_legal_name'  => 'IIS Test',
        ]);
        $this->resetAll();

        self::assertTrue(DeploymentScenario::isInstitute());
        self::assertTrue(DeploymentMode::isInstitute());
        self::assertSame('IIS Test', DeploymentScenario::controllerName());
        self::assertSame('dpo@scuola.test', DeploymentScenario::dpoContact());
        self::assertStringEndsWith('informativa-istituto.md', DeploymentScenario::informativaFile());

        $keys = array_column(DeploymentScenario::legalDocuments(), 'key');
        self::assertContains('dpa', $keys);
        self::assertNotContains('dpa', array_column(DeploymentScenario::legalDocuments('colleagues'), 'key'));
    }

    #[Test]
    public function fuori_dallo_scenario_3_il_titolare_e_il_gestore(): void
    {
        self::assertSame('Gestore Test', DeploymentScenario::controllerName());
        self::assertStringEndsWith('/informativa.md', DeploymentScenario::informativaFile());
    }

    #[Test]
    public function un_override_corrotto_viene_ignorato(): void
    {
        file_put_contents($this->tmp . '/config/deployment_scenario.json', '{"scenario":"saas"}');
        $this->resetAll();
        self::assertSame(DeploymentScenario::PERSONAL, DeploymentScenario::current());
    }

    #[Test]
    public function il_reset_torna_a_env(): void
    {
        DeploymentScenario::persist(DeploymentScenario::COLLEAGUES, 'tester', 'motivazione di prova valida');
        $this->resetAll();
        self::assertTrue(DeploymentScenario::isColleagues());

        self::assertTrue(DeploymentScenario::clearRuntime());
        $this->resetAll();
        self::assertSame('runtime_override', DeploymentMode::snapshot()['source'], 'il modo legacy resta finche\' non lo si resetta a parte');
        self::assertSame(DeploymentScenario::PERSONAL, DeploymentScenario::current());
    }

    private function resetAll(): void
    {
        DeploymentScenario::resetCache();
        DeploymentMode::resetCache();
        StudentRegistration::resetCache();
    }

    private function setConfig(string $key, mixed $value): void
    {
        $ref  = new \ReflectionClass(Config::class);
        $prop = $ref->getProperty('items');
        $prop->setAccessible(true);
        $items = $prop->getValue();
        [$ns, $sub] = array_pad(explode('.', $key, 2), 2, null);
        if ($sub === null) {
            $items[$ns] = $value;
        } elseif (str_contains($sub, '.')) {
            [$a, $b] = explode('.', $sub, 2);
            $items[$ns][$a][$b] = $value;
        } else {
            $items[$ns][$sub] = $value;
        }
        $prop->setValue(null, $items);
    }
}
