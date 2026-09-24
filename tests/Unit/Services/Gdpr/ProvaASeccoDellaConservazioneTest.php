<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Gdpr;

use App\Services\Gdpr\ModoDelGiroDiConservazione;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La prova a secco del giro della conservazione non dipende dall'ambiente
 * (24/9/2026).
 *
 * In produzione GDPR_RETENTION_ENABLED sta in `.env.local`: lo script lanciato
 * «senza flag» applicava. `--dry-run` deve vincere su tutto; senza, resta il
 * comportamento di prima (la variabile o `--apply` fanno applicare). Sul codice
 * di prima, che `--dry-run` non lo conosceva, cadono i due casi con la
 * variabile accesa.
 */
final class ProvaASeccoDellaConservazioneTest extends TestCase
{
    #[Test]
    public function con_dry_run_si_prova_anche_con_la_variabile_accesa(): void
    {
        self::assertTrue(ModoDelGiroDiConservazione::provaASecco(true, ['anonymize_expired.php', '--dry-run']));
        self::assertTrue(
            ModoDelGiroDiConservazione::provaASecco(true, ['anonymize_expired.php', '--apply', '--dry-run']),
            '--dry-run vince anche su --apply'
        );
    }

    #[Test]
    public function senza_dry_run_la_variabile_o_apply_fanno_applicare(): void
    {
        self::assertFalse(ModoDelGiroDiConservazione::provaASecco(true, ['anonymize_expired.php']));
        self::assertFalse(ModoDelGiroDiConservazione::provaASecco(false, ['anonymize_expired.php', '--apply']));
    }

    #[Test]
    public function senza_variabile_e_senza_flag_si_prova(): void
    {
        self::assertTrue(ModoDelGiroDiConservazione::provaASecco(false, ['anonymize_expired.php']));
    }

    #[Test]
    public function lo_script_decide_con_questa_funzione(): void
    {
        $script = (string)file_get_contents(\dirname(__DIR__, 4) . '/tools/gdpr/anonymize_expired.php');
        self::assertStringContainsString('ModoDelGiroDiConservazione::provaASecco(', $script);
        self::assertStringNotContainsString("in_array('--apply', \$argv, true))", $script, 'la decisione vecchia non resta accanto');
    }
}
