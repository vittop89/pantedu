<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Anomalia;
use PHPUnit\Framework\TestCase;

/**
 * Il riassunto per codice deve dire **quando**, non solo quante.
 *
 * Il 13 settembre 2026 la diagnostica scriveva «aide_differenze ×1 … Già
 * segnate come viste: aide_differenze ×1». La nuova era di quella notte, la
 * vista del giorno prima, e le due scritte erano identiche: una sessione di
 * lavoro l'ha letta al contrario, e ha riferito come già guardate 171
 * differenze che non lo erano.
 */
final class AnomaliaRiassuntoTest extends TestCase
{
    private string $fusoDiPrima = 'UTC';

    protected function setUp(): void
    {
        $this->fusoDiPrima = date_default_timezone_get();
        date_default_timezone_set('Europe/Rome');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->fusoDiPrima);
    }

    public function testLaNuovaELaVistaDelloStessoCodiceNonSiConfondonoPiu(): void
    {
        // Le due righe vere del registro, 12 e 13 settembre 2026.
        $vista = ['quando' => '2026-09-12T14:06:00+00:00', 'codice' => 'aide_differenze'];
        $nuova = ['quando' => '2026-09-13T04:06:21+00:00', 'codice' => 'aide_differenze'];

        $viste = Anomalia::riassumiPerCodice([$vista]);
        $nuove = Anomalia::riassumiPerCodice([$nuova]);

        self::assertSame('aide_differenze ×1 (ultima 12/9 16:06)', $viste);
        self::assertSame('aide_differenze ×1 (ultima 13/9 06:06)', $nuove);
        self::assertNotSame($viste, $nuove);
    }

    public function testDiPiuOccorrenzeDiceLaDataDellUltima(): void
    {
        $voci = [
            ['quando' => '2026-09-13T04:06:21+00:00', 'codice' => 'aide_differenze'],
            ['quando' => '2026-09-12T04:08:52+00:00', 'codice' => 'aide_differenze'],
        ];

        self::assertSame('aide_differenze ×2 (ultima 13/9 06:06)', Anomalia::riassumiPerCodice($voci));
    }

    public function testLeSaltateContanoEIlCodicePiuFrequenteVienePrima(): void
    {
        $voci = [
            ['quando' => '2026-09-13T10:00:00+00:00', 'codice' => 'csrf_gettone_vuoto'],
            ['quando' => '2026-09-13T11:00:00+00:00', 'codice' => 'audit_segreti_letti', 'saltate' => 2],
        ];

        self::assertSame(
            'audit_segreti_letti ×3 (ultima 13/9 13:00), csrf_gettone_vuoto ×1 (ultima 13/9 12:00)',
            Anomalia::riassumiPerCodice($voci),
        );
    }

    public function testUnaDataIlleggibileNonDiventaUnaDataInventata(): void
    {
        $voci = [['quando' => 'non è una data', 'codice' => 'registro_riga_illeggibile']];

        self::assertSame('registro_riga_illeggibile ×1', Anomalia::riassumiPerCodice($voci));
    }

    public function testNienteDaRiassumere(): void
    {
        self::assertSame('', Anomalia::riassumiPerCodice([]));
    }
}
