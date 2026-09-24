<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TexCompile;

use App\Services\TexCompile\ErroriTex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Gli errori di pdflatex letti dal log (24/9/2026), nei due versi: si trovano
 * anche quando il PDF esce, e un log pulito — con gli avvisi che pdflatex
 * scrive sempre — non ne ha. Stessi log e stesse attese della prova del
 * gemello Python, `tests/tex/test_errori_tex.py`: se le due copie divergono,
 * una delle due prove diventa rossa.
 */
final class ErroriTexTest extends TestCase
{
    private static function log(string $nome): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../fixtures/log-pdflatex/' . $nome . '.log');
    }

    #[Test]
    public function trova_i_due_errori_anche_se_il_pdf_esce(): void
    {
        $errori = ErroriTex::daLog(self::log('due-errori'));

        self::assertCount(2, $errori);
        self::assertStringStartsWith("Package pgfkeys Error: I do not know the key '/tikz/kin inesistente'", $errori[0]['message']);
        self::assertSame(4, $errori[0]['line']);
        self::assertStringContainsString('(0,0) -- (1,0);', $errori[0]['context'], 'con la riga del sorgente dove TeX si è fermato');
        self::assertStringNotContainsString('Type  H <return>', $errori[0]['context'], 'senza le righe di servizio');
        self::assertSame('Undefined control sequence.', $errori[1]['message']);
        self::assertSame(5, $errori[1]['line']);
        self::assertStringContainsString('\\comandoinesistente', $errori[1]['context']);
    }

    #[Test]
    public function la_chiusura_fatale_non_conta_come_errore_in_piu(): void
    {
        $errori = ErroriTex::daLog(self::log('fatale'));

        self::assertSame(['Paragraph ended before \\pgffor@normal@list was complete.'], array_column($errori, 'message'));
        self::assertSame(136, $errori[0]['line']);
    }

    #[Test]
    public function un_log_pulito_non_ha_errori(): void
    {
        // Avvisi, «Missing character», Overfull non sono errori: se questa
        // prova fallisce, ogni anteprima del modal diventerebbe un errore.
        self::assertSame([], ErroriTex::daLog(self::log('pulito')));
        self::assertSame([], ErroriTex::daLog(''));
    }

    #[Test]
    public function l_estratto_comincia_dagli_errori_e_li_tiene_anche_in_un_log_lungo(): void
    {
        $log = self::log('due-errori');
        $testo = ErroriTex::estratto($log, ErroriTex::daLog($log));
        self::assertStringStartsWith('pdflatex ha trovato 2 errori:', $testo);
        self::assertLessThan(strpos($testo, 'Output written'), strpos($testo, 'kin inesistente'));

        $lungo = str_repeat("Package loading line\n", 2000) . $log . str_repeat("dopo\n", 2000);
        $testo = ErroriTex::estratto($lungo, ErroriTex::daLog($lungo), 4000);
        self::assertStringContainsString('kin inesistente', $testo);
        self::assertLessThanOrEqual(4200, \strlen($testo));
    }
}
