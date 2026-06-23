<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Core\Config;
use App\Support\DeploymentMode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase S2 (ADR-017) — DeploymentMode helper.
 *
 * Carica Config una volta in setUpBeforeClass + manipola via reflection
 * nei singoli test (no env mutation per evitare side-effect cross-test).
 */
final class DeploymentModeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Config::load(dirname(__DIR__, 3) . '/app/Config');
    }

    protected function tearDown(): void
    {
        // Reset config a default (single) tra i test
        $this->setMode('single');
        // Reset runtime cache + cleanup storage/config/deployment.json
        DeploymentMode::resetCache();
        $path = (string) Config::get('app.paths.storage') . '/config/deployment.json';
        if (is_file($path)) {
            @unlink($path);
        }
    }

    #[Test]
    public function default_is_single(): void
    {
        $this->setMode('single');
        $this->assertSame('single', DeploymentMode::current());
        $this->assertTrue(DeploymentMode::isSingle());
        $this->assertFalse(DeploymentMode::isInstitute());
    }

    #[Test]
    public function institute_mode_recognized(): void
    {
        $this->setMode('institute');
        $this->assertSame('institute', DeploymentMode::current());
        $this->assertTrue(DeploymentMode::isInstitute());
        $this->assertFalse(DeploymentMode::isSingle());
    }

    #[Test]
    public function invalid_mode_falls_back_to_single(): void
    {
        $this->setMode('saas');           // invalid
        $this->assertSame('single', DeploymentMode::current());
        $this->assertTrue(DeploymentMode::isSingle());
    }

    #[Test]
    public function dpo_contact_single_uses_app_mail_from(): void
    {
        $this->setMode('single');
        $_ENV['APP_MAIL_FROM'] = 'admin@pantedu.test';
        $this->assertSame('admin@pantedu.test', DeploymentMode::dpoContact());
    }

    #[Test]
    public function dpo_contact_institute_uses_institute_owner(): void
    {
        $this->setMode('institute');
        $this->setConfig('app.institute_owner_email', 'dpo@scuola.test');
        $this->assertSame('dpo@scuola.test', DeploymentMode::dpoContact());
    }

    #[Test]
    public function dpo_contact_institute_fallback_to_app_mail_from_when_empty(): void
    {
        $this->setMode('institute');
        $this->setConfig('app.institute_owner_email', '');
        $_ENV['APP_MAIL_FROM'] = 'admin@pantedu.test';
        $this->assertSame('admin@pantedu.test', DeploymentMode::dpoContact());
    }

    #[Test]
    public function institute_legal_name_null_in_single_mode(): void
    {
        $this->setMode('single');
        $this->setConfig('app.institute_legal_name', 'Scuola Test');
        $this->assertNull(DeploymentMode::instituteLegalName());
    }

    #[Test]
    public function institute_legal_name_returned_in_institute_mode(): void
    {
        $this->setMode('institute');
        $this->setConfig('app.institute_legal_name', 'IIS Test SRL');
        $this->assertSame('IIS Test SRL', DeploymentMode::instituteLegalName());
    }

    #[Test]
    public function institute_legal_name_null_when_empty(): void
    {
        $this->setMode('institute');
        $this->setConfig('app.institute_legal_name', '');
        $this->assertNull(DeploymentMode::instituteLegalName());
    }

    #[Test]
    public function persist_runtime_overrides_env(): void
    {
        // Env dice "single", runtime override deve vincere e diventare "institute"
        $this->setMode('single');
        DeploymentMode::persistRuntime([
            'mode'                  => 'institute',
            'institute_owner_email' => 'dpo@scuola.example',
            'institute_legal_name'  => 'IIS Test SRL',
        ]);
        DeploymentMode::resetCache();

        $this->assertTrue(DeploymentMode::isInstitute());
        $this->assertSame('dpo@scuola.example', DeploymentMode::dpoContact());
        $this->assertSame('IIS Test SRL', DeploymentMode::instituteLegalName());

        $snap = DeploymentMode::snapshot();
        $this->assertSame('runtime_override', $snap['source']);
    }

    #[Test]
    public function snapshot_returns_env_source_when_no_override(): void
    {
        $this->setMode('single');
        $snap = DeploymentMode::snapshot();
        $this->assertSame('env', $snap['source']);
        $this->assertSame('single', $snap['mode']);
    }

    #[Test]
    public function persist_rejects_invalid_mode_at_read_time(): void
    {
        // Anche se runtime contiene mode invalido, current() ricasca su 'single'
        DeploymentMode::persistRuntime([
            'mode' => 'saas',  // not in whitelist
        ]);
        DeploymentMode::resetCache();
        $this->assertTrue(DeploymentMode::isSingle());
    }

    /**
     * Setta deployment_mode via reflection sul Config interno.
     */
    private function setMode(string $mode): void
    {
        $this->setConfig('app.deployment_mode', $mode);
    }

    private function setConfig(string $key, string $value): void
    {
        $ref = new \ReflectionClass(Config::class);
        $prop = $ref->getProperty('items');
        $prop->setAccessible(true);
        $items = $prop->getValue();
        [$ns, $sub] = array_pad(explode('.', $key, 2), 2, null);
        if ($sub === null) {
            $items[$ns] = $value;
        } else {
            $items[$ns][$sub] = $value;
        }
        $prop->setValue(null, $items);
    }
}
