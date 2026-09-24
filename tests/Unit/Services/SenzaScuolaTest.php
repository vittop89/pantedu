<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\SenzaScuola;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il docente senza scuola: la regola, e l'elenco che sta in un posto solo.
 *
 * ── Perché queste prove ───────────────────────────────────────────────────
 *
 * Lo stesso elenco di ciò che resta spento serve in tre momenti: al modulo
 * d'iscrizione, alle tredici rotte che senza scuola non hanno niente da
 * mostrare, e al profilo quando si scollega l'ultima. Scritto tre volte, dopo
 * un mese ce ne sono tre versioni e una sola è vera.
 *
 * E la regola sull'obbligatorietà è quella che decide se un'iscrizione passa:
 * se smettesse di distinguere lo scenario 3, o si chiuderebbe la porta a chi
 * non ha scuola, o si aprirebbe un account che lì non può fare niente.
 */
final class SenzaScuolaTest extends TestCase
{
    #[Test]
    public function l_elenco_di_cio_che_si_perde_non_e_vuoto_e_dice_il_perche(): void
    {
        $perse = SenzaScuola::cosaSiPerde();

        self::assertGreaterThanOrEqual(5, \count($perse), 'le cose che si spengono sono parecchie');
        foreach ($perse as $v) {
            self::assertArrayHasKey('cosa', $v);
            self::assertArrayHasKey('perche', $v);
            self::assertNotSame('', trim($v['cosa']), 'una voce senza nome non serve a niente');
            self::assertNotSame(
                '',
                trim($v['perche']),
                "«{$v['cosa']}» non dice perché: un elenco di divieti senza ragioni si subisce, non si capisce"
            );
        }
    }

    /**
     * Si dice anche che cosa resta. Un elenco di sole perdite fa credere che
     * senza scuola non si possa fare niente, e non è vero: si scrive, si
     * compila, si esporta.
     */
    #[Test]
    public function si_dice_anche_che_cosa_continua_a_funzionare(): void
    {
        $resta = SenzaScuola::cosaResta();

        self::assertNotEmpty($resta);
        self::assertStringContainsString(
            'esercizi',
            implode(' ', $resta),
            'la cosa principale — scrivere — deve restare detta'
        );
    }

    /** L'avviso nomina davvero le cose che si perdono, non una frase generica. */
    #[Test]
    public function l_avviso_nomina_le_cose_che_si_perdono(): void
    {
        $avviso = SenzaScuola::avviso();

        foreach (SenzaScuola::cosaSiPerde() as $v) {
            self::assertStringContainsString(
                $v['cosa'],
                $avviso,
                "l'avviso non nomina «{$v['cosa']}»: l'elenco e la frase divergerebbero"
            );
        }
        self::assertStringContainsString('profilo', $avviso, 'e deve dire dove si rimedia');
    }

    /**
     * La risposta delle API porta lo stato **e** resta leggibile da chi
     * guardava `error`. Cambiare quel codice avrebbe rotto in silenzio tredici
     * rotte.
     */
    #[Test]
    public function la_risposta_delle_api_spiega_senza_rompere_i_client_di_prima(): void
    {
        $r = SenzaScuola::risposta();

        self::assertSame('institute_not_found', $r['error'], 'chi leggeva questo codice continua a leggerlo');
        self::assertTrue($r['senza_scuola'], 'e chi vuole capire ha lo stato');
        self::assertNotSame('', trim((string)$r['messaggio']));
        self::assertSame('/area-docente/profilo', $r['dove'], 'dove si rimedia');
    }

    /**
     * Controllo positivo sulla forma dell'elenco: se un giorno diventasse una
     * sola voce, la congiunzione non deve produrre una frase storta.
     */
    #[Test]
    public function l_avviso_e_una_frase_italiana(): void
    {
        $avviso = SenzaScuola::avviso();

        self::assertStringEndsWith('.', $avviso);
        self::assertStringContainsString(' e ', $avviso, 'l\'ultimo elemento va congiunto, non elencato');
        self::assertStringNotContainsString(', e ', $avviso, 'niente virgola prima della congiunzione');
    }
}
