<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Core\Config;
use App\Support\TosEnforcement;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Il toggle decide se il servizio è accessibile: sbagliare la precedenza
 * significa murare fuori i docenti, o lasciare aperto un gate che si crede
 * chiuso. Entrambe le direzioni vanno coperte.
 */
final class TosEnforcementTest extends TestCase
{
    private string $tmpStorage = '';
    private mixed $originalStorage = null;
    private mixed $originalFlag = null;

    protected function setUp(): void
    {
        // Storage isolato: i test non devono scrivere override nella
        // storage/ reale del progetto.
        $this->tmpStorage = sys_get_temp_dir() . '/pantedu-tos-' . bin2hex(random_bytes(6));
        mkdir($this->tmpStorage . '/config', 0770, true);

        $this->originalStorage = Config::get('app.paths.storage');
        $this->originalFlag    = Config::get('multitenancy.tos_enforce');
        $this->setConfig('storage', $this->tmpStorage);
        TosEnforcement::resetCache();
    }

    protected function tearDown(): void
    {
        $this->setConfig('storage', $this->originalStorage);
        $this->setEnv($this->originalFlag);
        TosEnforcement::resetCache();

        $f = $this->tmpStorage . '/config/tos_enforcement.json';
        if (is_file($f)) {
            unlink($f);
        }
        @rmdir($this->tmpStorage . '/config');
        @rmdir($this->tmpStorage);
    }

    private function setConfig(string $key, mixed $value): void
    {
        $prop = new ReflectionProperty(Config::class, 'items');
        $items = $prop->getValue();
        $items['app']['paths'][$key] = $value;
        $prop->setValue(null, $items);
    }

    private function setEnv(mixed $value): void
    {
        $prop = new ReflectionProperty(Config::class, 'items');
        $items = $prop->getValue();
        $items['multitenancy']['tos_enforce'] = $value;
        $prop->setValue(null, $items);
        TosEnforcement::resetCache();
    }

    private function overrideFile(): string
    {
        return $this->tmpStorage . '/config/tos_enforcement.json';
    }

    // -----------------------------------------------------------------

    #[Test]
    public function defaults_to_off(): void
    {
        $this->setEnv(false);
        $this->assertFalse(TosEnforcement::isEnabled());
        $this->assertSame('env', TosEnforcement::snapshot()['source']);
    }

    #[Test]
    public function env_can_turn_it_on_when_no_override_exists(): void
    {
        $this->setEnv(true);
        $this->assertTrue(TosEnforcement::isEnabled());
    }

    #[Test]
    public function string_false_from_env_does_not_turn_it_on(): void
    {
        // (bool)'false' varrebbe true: è il bug che aveva la prima versione.
        $this->setEnv('false');
        $this->assertFalse(TosEnforcement::isEnabled());
    }

    #[Test]
    public function runtime_override_wins_over_env(): void
    {
        $this->setEnv(false);
        TosEnforcement::persistRuntime(true, 'docente1', 'avvio Scenario B');

        $this->assertTrue(TosEnforcement::isEnabled(), 'override on batte env off');

        $snap = TosEnforcement::snapshot();
        $this->assertSame('runtime_override', $snap['source']);
        $this->assertSame('docente1', $snap['updated_by']);
        $this->assertNotNull($snap['updated_at']);
    }

    #[Test]
    public function runtime_override_can_also_turn_it_off(): void
    {
        // Il caso che conta in emergenza: env dice on, si spegne dal pannello
        // senza toccare file sul server.
        $this->setEnv(true);
        TosEnforcement::persistRuntime(false, 'docente1', 'blocco imprevisto, spengo');

        $this->assertFalse(TosEnforcement::isEnabled());
    }

    #[Test]
    public function reason_and_actor_are_persisted_for_the_audit_trail(): void
    {
        $this->setEnv(false);
        TosEnforcement::persistRuntime(true, 'docente1', 'estensione al dipartimento');

        $data = json_decode((string) file_get_contents($this->overrideFile()), true);
        $this->assertTrue($data['enabled']);
        $this->assertSame('docente1', $data['updated_by']);
        $this->assertSame('estensione al dipartimento', $data['reason']);
    }

    #[Test]
    public function clearing_the_override_falls_back_to_env(): void
    {
        $this->setEnv(true);
        TosEnforcement::persistRuntime(false, 'docente1', 'spengo');
        $this->assertFalse(TosEnforcement::isEnabled());

        $this->assertTrue(TosEnforcement::clearRuntime());
        $this->assertTrue(TosEnforcement::isEnabled(), 'torna a valere env');
        $this->assertSame('env', TosEnforcement::snapshot()['source']);
    }

    #[Test]
    public function clearing_a_missing_override_is_not_an_error(): void
    {
        $this->assertTrue(TosEnforcement::clearRuntime());
    }

    /**
     * Un JSON troncato non deve decidere se il servizio è accessibile: si
     * ricade sull'env, che è un valore che qualcuno ha scritto apposta.
     */
    #[Test]
    public function corrupt_override_falls_back_to_env_instead_of_guessing(): void
    {
        $this->setEnv(true);
        file_put_contents($this->overrideFile(), '{"enabled": tru');
        TosEnforcement::resetCache();

        $this->assertTrue(TosEnforcement::isEnabled());
        $this->assertSame('env', TosEnforcement::snapshot()['source']);
    }

    #[Test]
    public function override_without_the_enabled_key_is_ignored(): void
    {
        $this->setEnv(false);
        file_put_contents($this->overrideFile(), '{"updated_by":"tizio"}');
        TosEnforcement::resetCache();

        $this->assertFalse(TosEnforcement::isEnabled());
        $this->assertSame('env', TosEnforcement::snapshot()['source']);
    }

    #[Test]
    public function no_partial_file_is_ever_left_behind(): void
    {
        $this->setEnv(false);
        TosEnforcement::persistRuntime(true, 'docente1', 'primo');
        TosEnforcement::persistRuntime(false, 'docente1', 'secondo');

        // La scrittura è tmp+rename: nessun .tmp residuo in giro.
        $leftovers = glob($this->tmpStorage . '/config/*.tmp.*') ?: [];
        $this->assertSame([], $leftovers);

        $data = json_decode((string) file_get_contents($this->overrideFile()), true);
        $this->assertFalse($data['enabled'], 'vale l\'ultima scrittura, intera');
    }
}
