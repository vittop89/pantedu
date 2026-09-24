<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Core\Config;
use App\Support\DeploymentMode;
use App\Support\DeploymentScenario;
use App\Support\SostituzioneSuFile;
use App\Support\StudentRegistration;
use App\Support\TosEnforcement;
use App\Support\TwoFactorEnforcement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Le cinque sostituzioni a runtime del pannello e il file corrotto
 * (23/9/2026).
 *
 * Il difetto (revisione architetturale del 23/9/2026, A-38): cinque classi
 * reimplementavano percorso, cache e scrittura atomica del proprio JSON, e
 * davanti a un file corrotto facevano cose diverse. TwoFactorEnforcement,
 * TosEnforcement e DeploymentScenario lo ignoravano con una riga in
 * `error_log`; DeploymentMode e StudentRegistration lo ignoravano **in
 * silenzio**, e DeploymentMode con un modo sconosciuto nel file rispondeva
 * `single` invece di ricadere sull'ambiente.
 *
 * Adesso la meccanica sta in App\Support\SostituzioneSuFile: un file corrotto
 * vale come assente, la classe ricade sull'ambiente, e l'anomalia
 * `sostituzione_illeggibile` lo dice, con il nome del file. Qui, per ciascuna
 * classe, il file troncato e il file con un valore fuori enumerazione.
 *
 * Controprove misurate il 23/9/2026: con le cinque classi di prima falliscono
 * tutti e dieci i casi del file corrotto — nessuna scriveva l'anomalia; per
 * DeploymentMode e StudentRegistration non c'era nemmeno la riga in
 * `error_log`, e DeploymentMode col modo sconosciuto rispondeva `single`
 * invece di `institute`. Con `segnalaCorrotto()` svuotato falliscono di nuovo
 * tutti e dieci.
 */
final class SostituzioniSuFileTest extends TestCase
{
    private string $cartella = '';

    /** @var array<string, mixed> */
    private array $prima = [];

    private const CHIAVI = [
        'app.paths.storage', 'app.paths.logs', 'app.deployment_mode', 'app.deployment_scenario',
        'app.student_registration_mode', 'multitenancy.tos_enforce', 'security.totp_enabled',
        'security.totp_required_roles',
    ];

    protected function setUp(): void
    {
        $this->cartella = sys_get_temp_dir() . '/pantedu-sostituzioni-' . bin2hex(random_bytes(6));
        mkdir($this->cartella . '/storage/config', 0700, true);
        mkdir($this->cartella . '/logs', 0700, true);
        foreach (self::CHIAVI as $k) {
            $this->prima[$k] = Config::get($k);
        }
        Config::set('app.paths.storage', $this->cartella . '/storage');
        Config::set('app.paths.logs', $this->cartella . '/logs');
        self::azzera();
    }

    protected function tearDown(): void
    {
        foreach ($this->prima as $k => $v) {
            Config::set($k, $v);
        }
        self::azzera();
        foreach ([$this->cartella . '/storage/config', $this->cartella . '/storage', $this->cartella . '/logs'] as $d) {
            foreach (glob($d . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
        }
        @rmdir($this->cartella . '/storage/config');
        @rmdir($this->cartella . '/storage');
        @rmdir($this->cartella . '/logs');
        @rmdir($this->cartella);
    }

    private static function azzera(): void
    {
        TwoFactorEnforcement::resetCache();
        TosEnforcement::resetCache();
        StudentRegistration::resetCache();
        DeploymentMode::resetCache();
        DeploymentScenario::resetCache();
    }

    private function anomalie(): string
    {
        return (string)@file_get_contents($this->cartella . '/logs/anomalie.jsonl');
    }

    private function scriviFile(string $nome, string $contenuto): void
    {
        file_put_contents($this->cartella . '/storage/config/' . $nome, $contenuto);
        self::azzera();
    }

    /**
     * Per ciascuna classe: il file, l'ambiente da impostare, e che cosa deve
     * rispondere la classe (il valore dell'ambiente, e la fonte).
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function fileCorrotti(): iterable
    {
        foreach (
            [
                'twofactor_enforcement.json' => '{"mode": "tutti"}',
                'tos_enforcement.json'       => '{"enabled": "si"}',
                'student_registration.json'  => '{"mode": "aperta"}',
                'deployment.json'            => '{"mode": "saas"}',
                'deployment_scenario.json'   => '{"scenario": "saas"}',
            ] as $file => $fuoriEnumerazione
        ) {
            yield "{$file} troncato" => [$file, '{"mode": "tr'];
            yield "{$file} fuori enumerazione" => [$file, $fuoriEnumerazione];
        }
    }

    /** @return array{valore: mixed, fonte: string} */
    private function risposta(string $file): array
    {
        return match ($file) {
            'twofactor_enforcement.json' => [
                'valore' => TwoFactorEnforcement::mode(),
                'fonte'  => TwoFactorEnforcement::snapshot()['source'],
            ],
            'tos_enforcement.json' => [
                'valore' => TosEnforcement::isEnabled(),
                'fonte'  => TosEnforcement::snapshot()['source'],
            ],
            'student_registration.json' => [
                'valore' => StudentRegistration::mode(),
                'fonte'  => StudentRegistration::snapshot()['source'],
            ],
            'deployment.json' => [
                'valore' => DeploymentMode::current(),
                'fonte'  => DeploymentMode::snapshot()['source'],
            ],
            'deployment_scenario.json' => [
                'valore' => DeploymentScenario::current(),
                'fonte'  => DeploymentScenario::snapshot()['source'],
            ],
            default => throw new \LogicException("file sconosciuto: {$file}"),
        };
    }

    /** L'ambiente di ciascun caso, scelto diverso dal predefinito di ogni classe. */
    private function ambiente(): void
    {
        Config::set('security.totp_enabled', true);
        Config::set('security.totp_required_roles', ['super_admin']);
        Config::set('multitenancy.tos_enforce', true);
        Config::set('app.student_registration_mode', StudentRegistration::REDUCED);
        Config::set('app.deployment_mode', DeploymentMode::INSTITUTE);
        Config::set('app.deployment_scenario', DeploymentScenario::INSTITUTE);
        self::azzera();
    }

    /** @return array{valore: mixed, fonte: string} */
    private static function attesa(string $file): array
    {
        return match ($file) {
            'twofactor_enforcement.json' => ['valore' => TwoFactorEnforcement::MODE_ADMINS, 'fonte' => 'env'],
            'tos_enforcement.json'       => ['valore' => true, 'fonte' => 'env'],
            'student_registration.json'  => ['valore' => StudentRegistration::REDUCED, 'fonte' => 'default'],
            'deployment.json'            => ['valore' => DeploymentMode::INSTITUTE, 'fonte' => 'env'],
            'deployment_scenario.json'   => ['valore' => DeploymentScenario::INSTITUTE, 'fonte' => 'env'],
            default                      => throw new \LogicException("file sconosciuto: {$file}"),
        };
    }

    #[Test]
    #[DataProvider('fileCorrotti')]
    public function un_file_corrotto_vale_l_ambiente_e_lo_dice(string $file, string $contenuto): void
    {
        $this->ambiente();
        $this->scriviFile($file, $contenuto);

        self::assertSame(self::attesa($file), $this->risposta($file), 'si ricade sull\'ambiente');

        $anomalie = $this->anomalie();
        self::assertStringContainsString('sostituzione_illeggibile', $anomalie, 'il file corrotto non passa in silenzio');
        self::assertStringContainsString($file, $anomalie, 'l\'anomalia dice quale file');
    }

    #[Test]
    public function un_file_valido_vince_sull_ambiente_senza_anomalie(): void
    {
        $this->ambiente();
        TwoFactorEnforcement::persistRuntime(TwoFactorEnforcement::MODE_ALL, 'prova', 'motivo');
        TosEnforcement::persistRuntime(false, 'prova', 'motivo');
        StudentRegistration::persist(StudentRegistration::ANONYMOUS, true);
        DeploymentMode::persistRuntime(['mode' => DeploymentMode::SINGLE]);
        DeploymentScenario::persist(DeploymentScenario::COLLEAGUES, 'prova', 'motivo');
        self::azzera();

        self::assertSame(TwoFactorEnforcement::MODE_ALL, TwoFactorEnforcement::mode());
        self::assertFalse(TosEnforcement::isEnabled());
        self::assertSame(StudentRegistration::ANONYMOUS, StudentRegistration::mode());
        self::assertTrue(StudentRegistration::onlySuperadminClasses());
        self::assertSame(DeploymentMode::SINGLE, DeploymentMode::current());
        self::assertSame(DeploymentScenario::COLLEAGUES, DeploymentScenario::current());
        self::assertSame('runtime_override', DeploymentMode::snapshot()['source']);
        self::assertSame('', $this->anomalie());

        // Chi ha deciso resta scritto dove lo era già.
        self::assertSame('prova', TwoFactorEnforcement::snapshot()['updated_by']);
        self::assertSame('prova', TosEnforcement::snapshot()['updated_by']);
        self::assertSame('prova', DeploymentScenario::snapshot()['updated_by']);
    }

    #[Test]
    public function la_scrittura_e_atomica_e_la_cache_si_rinnova(): void
    {
        SostituzioneSuFile::scrivi('prova_a38.json', ['valore' => 1]);
        SostituzioneSuFile::scrivi('prova_a38.json', ['valore' => 2]);
        $valido = static fn(array $d): bool => is_int($d['valore'] ?? null);

        self::assertSame(['valore' => 2], SostituzioneSuFile::leggi('prova_a38.json', $valido));
        self::assertSame([], glob($this->cartella . '/storage/config/*.tmp.*') ?: [], 'nessun .tmp residuo');
        self::assertSame(0640, fileperms(SostituzioneSuFile::percorso('prova_a38.json')) & 0777);

        // Letto una volta per richiesta: una modifica da fuori non si vede
        // finché non si dimentica.
        file_put_contents(SostituzioneSuFile::percorso('prova_a38.json'), '{"valore": 3}');
        self::assertSame(['valore' => 2], SostituzioneSuFile::leggi('prova_a38.json', $valido));
        SostituzioneSuFile::dimentica('prova_a38.json');
        self::assertSame(['valore' => 3], SostituzioneSuFile::leggi('prova_a38.json', $valido));

        self::assertTrue(SostituzioneSuFile::togli('prova_a38.json'));
        self::assertNull(SostituzioneSuFile::leggi('prova_a38.json', $valido));
        self::assertTrue(SostituzioneSuFile::togli('prova_a38.json'), 'togliere un file che non c\'è riesce');
    }

    #[Test]
    public function la_cache_non_confonde_due_cartelle(): void
    {
        SostituzioneSuFile::scrivi('prova_a38.json', ['valore' => 1]);
        $valido = static fn(array $d): bool => true;
        self::assertNotNull(SostituzioneSuFile::leggi('prova_a38.json', $valido));

        Config::set('app.paths.storage', $this->cartella . '/altrove');
        self::assertNull(SostituzioneSuFile::leggi('prova_a38.json', $valido), 'un\'altra cartella, un altro file');
        Config::set('app.paths.storage', $this->cartella . '/storage');
        SostituzioneSuFile::togli('prova_a38.json');
    }
}
