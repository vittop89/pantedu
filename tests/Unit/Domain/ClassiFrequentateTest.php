<?php
declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\ClassiFrequentate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Le classi che uno studente puo' guardare (piano classi, D). Tutto puro.
 */
final class ClassiFrequentateTest extends TestCase
{
    /** @return list<string> */
    private static function classi(array $voci): array
    {
        return array_column($voci, 'classe');
    }

    #[Test]
    public function da_una_sezione_di_terza_si_vedono_seconda_e_prima_al_livello_di_anno(): void
    {
        $voci = ClassiFrequentate::per('3A');
        $this->assertSame(['3A', '2', '1'], self::classi($voci));
        $this->assertTrue($voci[0]['corrente']);
        $this->assertFalse($voci[1]['corrente']);
        $this->assertSame('Terza · 3A (la tua classe)', $voci[0]['label']);
        $this->assertSame('Seconda', $voci[1]['label']);
        $this->assertSame('Prima', $voci[2]['label']);
    }

    #[Test]
    public function la_prima_non_ha_archivio(): void
    {
        $this->assertSame(['1A'], self::classi(ClassiFrequentate::per('1A')));
        $this->assertSame(['1'], self::classi(ClassiFrequentate::per('1')));
    }

    #[Test]
    public function un_anno_secco_in_testa_resta_secco(): void
    {
        $voci = ClassiFrequentate::per('3');
        $this->assertSame(['3', '2', '1'], self::classi($voci));
        $this->assertSame('Terza (la tua classe)', $voci[0]['label']);
    }

    #[Test]
    public function lo_storico_mette_la_sezione_al_posto_dell_anno(): void
    {
        $voci = ClassiFrequentate::per('3A', ['2B', '1A']);
        $this->assertSame(['3A', '2B', '1A'], self::classi($voci));
        $this->assertSame('Seconda · 2B', $voci[1]['label']);
    }

    #[Test]
    public function lo_storico_ignora_anni_correnti_o_futuri_e_anni_secchi(): void
    {
        // «3B» e' dello stesso anno, «4A» e' futuro, «2» non e' una sezione:
        // nessuno dei tre puo' migliorare l'archivio.
        $this->assertSame(['3A', '2', '1'], self::classi(ClassiFrequentate::per('3A', ['3B', '4A', '2'])));
    }

    #[Test]
    public function con_due_sezioni_dello_stesso_anno_vale_la_piu_recente(): void
    {
        // Ripetenza: lo storico e' dal piu' recente, e la prima voce vince.
        $this->assertSame(['3A', '2C', '1'], self::classi(ClassiFrequentate::per('3A', ['2C', '2B'])));
    }

    #[Test]
    public function un_codice_non_riconosciuto_non_ha_classi_frequentate(): void
    {
        $this->assertSame([], ClassiFrequentate::per('XYZ'));
        $this->assertSame([], ClassiFrequentate::per(''));
        $this->assertSame([], ClassiFrequentate::per(null));
    }

    #[Test]
    public function ammette_la_propria_classe_e_gli_anni_precedenti(): void
    {
        $this->assertSame('3A', ClassiFrequentate::ammette('3A', [], '3A'));
        $this->assertSame('3A', ClassiFrequentate::ammette('3A', [], '3a'), 'senza distinzione di maiuscole');
        $this->assertSame('2', ClassiFrequentate::ammette('3A', [], '2'));
        $this->assertSame('1', ClassiFrequentate::ammette('3A', [], '1'));
    }

    #[Test]
    public function non_ammette_anni_futuri_altre_sezioni_o_l_anno_secco_della_propria(): void
    {
        $this->assertNull(ClassiFrequentate::ammette('3A', [], '4'));
        $this->assertNull(ClassiFrequentate::ammette('3A', [], '3B'));
        $this->assertNull(ClassiFrequentate::ammette('3A', [], '2B'), 'sezione passata non nota: non si indovina');
        $this->assertNull(ClassiFrequentate::ammette('3A', [], '3'), 'la propria classe si chiede come 3A, che gia\' include i 3');
        $this->assertNull(ClassiFrequentate::ammette('3A', [], ''));
    }

    #[Test]
    public function con_lo_storico_l_anno_chiesto_apre_la_sezione_nota(): void
    {
        $this->assertSame('2B', ClassiFrequentate::ammette('3A', ['2B'], '2'));
        $this->assertSame('2B', ClassiFrequentate::ammette('3A', ['2B'], '2B'));
        $this->assertNull(ClassiFrequentate::ammette('3A', ['2B'], '2C'), 'un\'altra sezione di seconda no');
    }

    #[Test]
    public function il_suffisso_legacy_non_diventa_una_sezione(): void
    {
        $this->assertSame(['2', '1'], self::classi(ClassiFrequentate::per('2s')));
        $this->assertSame('1', ClassiFrequentate::ammette('2s', [], '1'));
    }
}
