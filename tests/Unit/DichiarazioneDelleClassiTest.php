<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * «Questi dati li dichiari tu» si dice nei soli scenari 1 e 2.
 *
 * Il docente che sceglie scuola, indirizzo e classe deve sapere che sta
 * **dichiarando**, non comunicando un dato della scuola. Non è solo
 * trasparenza: tutto l'impianto verso il DPO poggia su quel fatto, e un fatto
 * che l'interessato ignora è un fatto debole — se il docente crede di star
 * comunicando l'organizzazione dell'Istituto, quella diventa la sostanza,
 * comunque la si descriva altrove.
 *
 * Ma vale **solo fuori dallo Scenario 3**. Lì l'Istituto è Titolare, quei dati
 * li fornisce e li conferma davvero, e la stessa frase sarebbe falsa: sarebbe
 * il difetto che questo progetto insegue, con il segno invertito.
 *
 * Questa prova guarda che il testo stia **dentro** la condizione, nei due
 * punti in cui compare. Non rende la pagina — in sviluppo lo scenario è il 1 e
 * il modulo di iscrizione non si mostra affatto — ma verifica l'unica cosa che
 * può rompersi in silenzio: qualcuno che sposta il testo fuori dal guardiano.
 */
final class DichiarazioneDelleClassiTest extends TestCase
{
    /** @return array<string, array{0:string, 1:string}> vista => [frase, guardia] */
    public static function viste(): array
    {
        return [
            'modulo di iscrizione' => ['li dichiari tu', 'views/auth/register.php'],
            'profilo del docente'  => ['Le spunte sono tue, non della scuola', 'views/area_docente/profilo.php'],
        ];
    }

    #[Test]
    public function la_frase_c_e_in_tutti_e_due_i_punti(): void
    {
        foreach (self::viste() as $nome => [$frase, $file]) {
            $s = (string)file_get_contents(dirname(__DIR__, 2) . '/' . $file);
            self::assertStringContainsString($frase, $s,
                "$nome: il docente deve sapere che sta dichiarando");
        }
    }

    #[Test]
    public function e_sta_dentro_la_condizione_sullo_scenario(): void
    {
        foreach (self::viste() as $nome => [$frase, $file]) {
            $s = (string)file_get_contents(dirname(__DIR__, 2) . '/' . $file);

            $guardia = strpos($s, 'DeploymentScenario::isInstitute()');
            $testo   = strpos($s, $frase);
            $fine    = strpos($s, 'endif;', (int)$guardia);

            self::assertIsInt($guardia, "$nome: manca la condizione sullo scenario");
            self::assertIsInt($fine, "$nome: la condizione non si chiude");
            self::assertGreaterThan($guardia, $testo,
                "$nome: il testo deve venire DOPO l'apertura della condizione");
            self::assertLessThan($fine, $testo,
                "$nome: il testo deve stare DENTRO la condizione — nello Scenario 3 sarebbe falso, perché lì l'Istituto è Titolare e quei dati li fornisce davvero");
        }
    }

    #[Test]
    public function la_condizione_e_negata(): void
    {
        // Il verso che si dimentica: `if (isInstitute())` invece di
        // `if (!isInstitute())` mostrerebbe la frase ESATTAMENTE dove è falsa,
        // e le prove sopra passerebbero lo stesso.
        foreach (self::viste() as $nome => [, $file]) {
            $s = (string)file_get_contents(dirname(__DIR__, 2) . '/' . $file);
            self::assertMatchesRegularExpression(
                '/if\s*\(\s*!\s*\\\\?App\\\\Support\\\\DeploymentScenario::isInstitute\(\)\s*\)/',
                $s,
                "$nome: la condizione deve essere negata — «non nello Scenario 3»"
            );
        }
    }

    #[Test]
    public function anche_l_informativa_lo_dice(): void
    {
        // L'informativa vale per lo Scenario 2 e lo dichiara in testa, quindi
        // non le serve una condizione: le serve dirlo.
        $s = (string)file_get_contents(dirname(__DIR__, 2) . '/docs/privacy/informativa.md');

        self::assertStringContainsString('**Questi dati li dichiari tu**', $s,
            "l'informativa è il posto in cui l'art. 13 vuole che stia");
        self::assertStringContainsString('non li conferma e non li può correggere', $s);
    }
}
