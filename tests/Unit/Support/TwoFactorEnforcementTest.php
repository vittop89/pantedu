<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Core\Config;
use App\Support\TwoFactorEnforcement;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * L'obbligo del secondo fattore quando lo decide l'ambiente (nessuna
 * sostituzione dal pannello).
 *
 * 23/9/2026 (revisione architetturale, A-35). `security.totp_enabled` era
 * `(bool)` della stringa: `SECURITY_TOTP_ENABLED=false` valeva ACCESO, e con
 * dei ruoli elencati l'obbligo c'era. Letto per quel che dice, 'false'
 * spegnerebbe l'obbligo al primo rilascio senza che nessuno l'abbia deciso.
 * La contraddizione (interruttore spento, ruoli elencati) resta sul lato
 * prudente e finisce nel registro delle anomalie; senza ruoli non c'è niente
 * da obbligare, e nessuna anomalia.
 *
 * Controprova per mutazione (23/9/2026): con `if (!$enabled) return MODE_OFF;`
 * prima dei ruoli, come prima, fallisce
 * `interruttore_spento_con_ruoli_tiene_l_obbligo_e_lo_dice`; togliendo la
 * chiamata ad Anomalia, fallisce la sola parte del registro.
 */
final class TwoFactorEnforcementTest extends TestCase
{
    private string $cartella = '';
    private mixed $storagePrima = null;
    private mixed $logsPrima = null;
    private mixed $abilitatoPrima = null;
    private mixed $ruoliPrima = null;

    protected function setUp(): void
    {
        $this->cartella = sys_get_temp_dir() . '/pantedu-2fa-' . bin2hex(random_bytes(6));
        mkdir($this->cartella . '/storage/config', 0700, true);
        mkdir($this->cartella . '/logs', 0700, true);
        $this->storagePrima = Config::get('app.paths.storage');
        $this->logsPrima = Config::get('app.paths.logs');
        $this->abilitatoPrima = Config::get('security.totp_enabled');
        $this->ruoliPrima = Config::get('security.totp_required_roles');
        Config::set('app.paths.storage', $this->cartella . '/storage');
        Config::set('app.paths.logs', $this->cartella . '/logs');
        TwoFactorEnforcement::resetCache();
    }

    protected function tearDown(): void
    {
        Config::set('app.paths.storage', $this->storagePrima);
        Config::set('app.paths.logs', $this->logsPrima);
        Config::set('security.totp_enabled', $this->abilitatoPrima);
        Config::set('security.totp_required_roles', $this->ruoliPrima);
        TwoFactorEnforcement::resetCache();
        $this->svuota($this->cartella);
    }

    private function svuota(string $dir): void
    {
        foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (in_array(basename($f), ['.', '..'], true)) {
                continue;
            }
            is_dir($f) ? $this->svuota($f) : @unlink($f);
        }
        @rmdir($dir);
    }

    private function anomalie(): string
    {
        return (string)@file_get_contents($this->cartella . '/logs/anomalie.jsonl');
    }

    /** @param list<string> $ruoli */
    private function ambiente(bool $abilitato, array $ruoli): void
    {
        Config::set('security.totp_enabled', $abilitato);
        Config::set('security.totp_required_roles', $ruoli);
        TwoFactorEnforcement::resetCache();
    }

    #[Test]
    public function interruttore_spento_con_ruoli_tiene_l_obbligo_e_lo_dice(): void
    {
        $this->ambiente(false, ['super_admin', 'administrator']);

        self::assertSame(TwoFactorEnforcement::MODE_ADMINS, TwoFactorEnforcement::mode());
        self::assertTrue(TwoFactorEnforcement::isRequiredFor('administrator'));
        self::assertStringContainsString('secondo_fattore_interruttore_contraddetto', $this->anomalie());
        self::assertSame('env', TwoFactorEnforcement::snapshot()['source']);
    }

    #[Test]
    public function interruttore_spento_con_i_docenti_li_tiene_obbligati(): void
    {
        $this->ambiente(false, ['teacher']);
        self::assertSame(TwoFactorEnforcement::MODE_ALL, TwoFactorEnforcement::mode());
    }

    #[Test]
    public function interruttore_spento_senza_ruoli_non_obbliga_nessuno_e_non_dice_niente(): void
    {
        $this->ambiente(false, []);

        self::assertSame(TwoFactorEnforcement::MODE_OFF, TwoFactorEnforcement::mode());
        self::assertFalse(TwoFactorEnforcement::isRequiredFor('super_admin'));
        self::assertSame('', $this->anomalie(), 'nessuna contraddizione, nessuna anomalia');
    }

    #[Test]
    public function interruttore_acceso_con_ruoli_obbliga_senza_anomalie(): void
    {
        $this->ambiente(true, ['super_admin']);

        self::assertSame(TwoFactorEnforcement::MODE_ADMINS, TwoFactorEnforcement::mode());
        self::assertSame('', $this->anomalie());
    }

    #[Test]
    public function interruttore_acceso_senza_ruoli_non_obbliga_nessuno(): void
    {
        $this->ambiente(true, []);
        self::assertSame(TwoFactorEnforcement::MODE_OFF, TwoFactorEnforcement::mode());
    }

    #[Test]
    public function la_sostituzione_del_pannello_vince_sulla_contraddizione(): void
    {
        $this->ambiente(false, ['super_admin']);
        TwoFactorEnforcement::persistRuntime(TwoFactorEnforcement::MODE_OFF, 'prova', 'deciso dal pannello');

        self::assertSame(TwoFactorEnforcement::MODE_OFF, TwoFactorEnforcement::mode());
        self::assertSame('runtime_override', TwoFactorEnforcement::snapshot()['source']);
        self::assertSame('', $this->anomalie(), 'con una decisione del pannello non c\'è contraddizione da dire');
    }
}
