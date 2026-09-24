<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * L'estensione che ripulisce sessione e richiesta fra una prova e l'altra (14/9/2026).
 *
 * Si prova con un PHPUnit figlio e due prove d'esempio in un ordine fisso: la
 * prima lascia un super-amministratore in sessione e una richiesta parziale, la
 * seconda vuole la sessione vuota e la richiesta pulita. Nei due versi: con l'estensione la seconda passa, senza
 * fallisce. È la forma del difetto trovato con RisdocResolverTest.
 */
final class RichiestaPulitaFraLeProveTest extends TestCase
{
    private string $cartella = '';

    protected function setUp(): void
    {
        $this->cartella = sys_get_temp_dir() . '/pantedu-sessione-fra-prove-' . bin2hex(random_bytes(6));
        mkdir($this->cartella, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cartella . '/{,.}*', GLOB_BRACE) ?: [] as $voce) {
            if (is_file($voce)) {
                @unlink($voce);
            }
        }
        foreach (['/cache'] as $sotto) {
            if (is_dir($this->cartella . $sotto)) {
                exec('rm -rf ' . escapeshellarg($this->cartella . $sotto));
            }
        }
        @rmdir($this->cartella);
    }

    /** @return array{0: int, 1: string} esito e uscita del PHPUnit figlio */
    private function phpunit(bool $conEstensione): array
    {
        $radice = dirname(__DIR__, 3);
        $fixture = $radice . '/tests/Fixtures/phpunit-sessione';
        $estensione = $conEstensione
            ? '<extensions><bootstrap class="Tests\Support\RichiestaPulitaFraLeProve"/></extensions>'
            : '';
        $config = $this->cartella . '/phpunit.xml';
        file_put_contents($config, <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="{$radice}/vendor/autoload.php" executionOrder="default" cacheDirectory="{$this->cartella}/cache">
    <testsuites>
        <testsuite name="ordine">
            <file>{$fixture}/SporcaLaSessione.php</file>
            <file>{$fixture}/TrovaLaSessioneVuota.php</file>
        </testsuite>
    </testsuites>
    {$estensione}
</phpunit>
XML);
        $cmd = [PHP_BINARY, $radice . '/vendor/phpunit/phpunit/phpunit', '-c', $config, '--no-progress'];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $radice);
        $this->assertIsResource($proc);
        $uscita = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
        return [proc_close($proc), $uscita];
    }

    #[Test]
    public function con_l_estensione_la_prova_dopo_trova_la_sessione_vuota(): void
    {
        [$esito, $uscita] = $this->phpunit(true);

        $this->assertSame(0, $esito, $uscita);
        $this->assertStringContainsString('OK (2 tests', $uscita);
    }

    #[Test]
    public function senza_l_estensione_la_prova_dopo_eredita_il_super_amministratore(): void
    {
        [$esito, $uscita] = $this->phpunit(false);

        $this->assertNotSame(0, $esito, 'la controprova: senza estensione la sessione passa da una prova all\'altra');
        $this->assertStringContainsString('la prova di prima ha lasciato la sua sessione', $uscita);
    }
}
