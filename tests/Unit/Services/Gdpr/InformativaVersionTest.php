<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Gdpr;

use App\Core\Config;
use App\Services\Gdpr\ConsentService;
use App\Support\DeploymentMode;
use App\Support\DeploymentScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La versione con cui si archiviano i consensi è quella del testo
 * dell'informativa che l'utente vede, nello scenario attivo (23/9/2026).
 *
 * Il difetto (revisione architetturale del 23/9/2026, DOC-19): i consensi si
 * registravano con `gdpr.text_version`, la versione di
 * docs/privacy/informativa.md ricopiata in app/Config/gdpr.php. Quel testo è
 * l'informativa dello scenario 2; negli scenari 1 e 3 si servono
 * `informativa-personale.md` e `informativa-istituto.md`, con un registro di
 * versioni proprio: i consensi citavano 2.16 contro testi alla 1.2 e alla
 * 1.3. Prima ancora (4/9/2026, rilievo A3) la copia era rimasta alla 2.2
 * mentre l'informativa passava alla 2.3.
 *
 * Adesso ConsentService::currentTextVersion() legge il `versione:` del file
 * che DeploymentScenario::informativaFile() serve nello scenario attivo. La
 * prova legge il frontmatter di ciascun file per conto suo e confronta, uno
 * scenario per volta, con lo scenario impostato come lo imposta un'istanza
 * (DEPLOYMENT_SCENARIO, senza file del pannello).
 *
 * Controprova (23/9/2026): con il ConsentService di prima i casi degli
 * scenari 1 e 3 falliscono (2.16 invece di 1.2 e 1.3); quello dello scenario
 * 2 passa, ed era l'unico giusto. Lo stesso confronto, sui file, lo fa in CI
 * tools/ci/check-legal-versions.mjs (punto 7).
 */
final class InformativaVersionTest extends TestCase
{
    private string $cartella = '';

    /** @var array<string, mixed> */
    private array $prima = [];

    protected function setUp(): void
    {
        $this->cartella = sys_get_temp_dir() . '/pantedu-informativa-' . bin2hex(random_bytes(6));
        mkdir($this->cartella . '/config', 0700, true);
        foreach (['app.paths.storage', 'app.deployment_scenario', 'app.deployment_mode'] as $k) {
            $this->prima[$k] = Config::get($k);
        }
        // Nessun file del pannello: lo scenario lo dice la configurazione.
        Config::set('app.paths.storage', $this->cartella);
        DeploymentScenario::resetCache();
        DeploymentMode::resetCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->prima as $k => $v) {
            Config::set($k, $v);
        }
        DeploymentScenario::resetCache();
        DeploymentMode::resetCache();
        @rmdir($this->cartella . '/config');
        @rmdir($this->cartella);
    }

    /** Il `versione:` del frontmatter, letto qui senza passare dal codice provato. */
    private static function versioneDichiarata(string $file): string
    {
        $md = (string)file_get_contents(\dirname(__DIR__, 4) . '/docs/privacy/' . $file);
        self::assertSame(1, preg_match('/^---\R(.*?)\R---/s', $md, $fm), "{$file} ha un frontmatter");
        self::assertSame(1, preg_match('/^versione:\s*(\S+)\s*$/m', $fm[1], $m), "{$file} dichiara una versione");
        return trim($m[1], "\"'");
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string}> */
    public static function scenari(): iterable
    {
        yield 'scenario 1, uso personale' => [DeploymentScenario::PERSONAL, 'single', 'informativa-personale.md'];
        yield 'scenario 2, colleghi' => [DeploymentScenario::COLLEAGUES, 'single', 'informativa.md'];
        yield 'scenario 3, istituto' => [DeploymentScenario::INSTITUTE, 'institute', 'informativa-istituto.md'];
    }

    #[Test]
    #[DataProvider('scenari')]
    public function i_consensi_citano_il_testo_dello_scenario_attivo(string $scenario, string $modo, string $file): void
    {
        Config::set('app.deployment_scenario', $scenario);
        Config::set('app.deployment_mode', $modo);
        DeploymentScenario::resetCache();
        DeploymentMode::resetCache();
        self::assertSame($scenario, DeploymentScenario::current());
        self::assertStringEndsWith('/' . $file, DeploymentScenario::informativaFile());

        self::assertSame(
            self::versioneDichiarata($file),
            (new ConsentService())->currentTextVersion(),
            "nello scenario «{$scenario}» il consenso deve citare la versione di docs/privacy/{$file}"
        );
    }

    #[Test]
    public function la_lettura_del_frontmatter_non_indovina(): void
    {
        self::assertSame('1.2', DeploymentScenario::versioneDelFrontmatter("---\ntipo: x\nversione: 1.2\n---\ntesto"));
        self::assertSame('2.16', DeploymentScenario::versioneDelFrontmatter("---\nversione: \"2.16\"\n---\n"));
        // Una versione scritta nel corpo, fuori dal frontmatter, non conta.
        self::assertNull(DeploymentScenario::versioneDelFrontmatter("---\ntipo: x\n---\nversione: 9.9\n"));
        self::assertNull(DeploymentScenario::versioneDelFrontmatter("---\nversione:\n---\n"));
        self::assertNull(DeploymentScenario::versioneDelFrontmatter("senza frontmatter\nversione: 1.0\n"));
    }
}
