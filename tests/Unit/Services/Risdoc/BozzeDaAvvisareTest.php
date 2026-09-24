<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Risdoc;

use App\Services\Risdoc\ScadenzaDelleBozze;
use App\Services\Risdoc\SpazzataDelleBozze;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Quali bozze il giro notturno avvisa (23/9/2026, revisione Risdoc A2, 0.2).
 *
 * Prima il giro avvisava solo le righe «da avvisare». Una riga che saltava la
 * finestra del preavviso passava direttamente a «da cancellare», e la
 * cancellazione richiede l'avviso: non veniva mai né avvisata né cancellata.
 *
 * Nei due versi: la riga scaduta e muta entra fra quelle da avvisare; la riga
 * scaduta già avvisata no (aspetta i tre giorni, non riceve un secondo
 * avviso); la riga viva no. Senza database: le righe hanno la forma del
 * censimento, con lo `stato` calcolato dalla regola vera.
 */
final class BozzeDaAvvisareTest extends TestCase
{
    private const ADESSO = '2026-09-23 03:00:00';

    /** @return array<string, mixed> */
    private function riga(int $id, string $modificata, ?string $avvisata): array
    {
        return [
            'id'         => $id,
            'docente'    => 7,
            'modificata' => $modificata,
            'scaricata'  => null,
            'avvisata'   => $avvisata,
            'rimasti'    => ScadenzaDelleBozze::giorniRimasti(null, $modificata, self::ADESSO),
            'stato'      => ScadenzaDelleBozze::stato(null, $modificata, $avvisata, self::ADESSO),
        ];
    }

    /** @param list<array<string, mixed>> $righe @return list<int> */
    private static function ids(array $righe): array
    {
        return array_map(static fn(array $r): int => (int)$r['id'], $righe);
    }

    #[Test]
    public function le_righe_di_prova_hanno_lo_stato_che_si_crede(): void
    {
        // Senza questo, le prove sotto potrebbero misurare righe tutte «vive».
        self::assertSame(ScadenzaDelleBozze::DA_CANCELLARE, $this->riga(1, '2026-05-10 10:00:00', null)['stato']);
        self::assertSame(ScadenzaDelleBozze::DA_CANCELLARE, $this->riga(2, '2026-05-10 10:00:00', '2026-09-22 03:00:00')['stato']);
        self::assertSame(ScadenzaDelleBozze::VIVA, $this->riga(3, '2026-09-20 10:00:00', null)['stato']);
    }

    #[Test]
    public function una_bozza_scaduta_e_mai_avvisata_si_avvisa(): void
    {
        // Ferma dal 10 maggio: la rete del 31 agosto è passata da tre settimane,
        // e il preavviso non è mai partito.
        $muta = $this->riga(1, '2026-05-10 10:00:00', null);

        self::assertSame([1], self::ids(SpazzataDelleBozze::daAvvisare([$muta])));
        // Stanotte non si cancella: prima l'avviso.
        self::assertSame([], SpazzataDelleBozze::maturePerLaCancellazione([$muta], self::ADESSO));
    }

    #[Test]
    public function una_bozza_scaduta_gia_avvisata_non_riceve_un_secondo_avviso(): void
    {
        $avvisataIeri = $this->riga(2, '2026-05-10 10:00:00', '2026-09-22 03:00:00');

        self::assertSame([], SpazzataDelleBozze::daAvvisare([$avvisataIeri]));
        self::assertSame([], SpazzataDelleBozze::maturePerLaCancellazione([$avvisataIeri], self::ADESSO));
    }

    #[Test]
    public function tre_giorni_dopo_l_avviso_la_bozza_scaduta_si_cancella(): void
    {
        $avvisata = $this->riga(4, '2026-05-10 10:00:00', '2026-09-20 03:00:00');

        self::assertSame([4], self::ids(SpazzataDelleBozze::maturePerLaCancellazione([$avvisata], self::ADESSO)));
        self::assertSame([], SpazzataDelleBozze::daAvvisare([$avvisata]));
    }

    #[Test]
    public function una_bozza_viva_non_si_avvisa(): void
    {
        self::assertSame([], SpazzataDelleBozze::daAvvisare([$this->riga(3, '2026-09-20 10:00:00', null)]));
    }

    #[Test]
    public function una_bozza_nel_preavviso_si_avvisa_come_prima(): void
    {
        // Scaricata nove giorni fa: la grazia di quindici giorni ne lascia sei.
        $modificata = '2026-09-14 10:00:00';
        $riga = [
            'id' => 5, 'docente' => 7, 'avvisata' => null,
            'stato' => ScadenzaDelleBozze::stato($modificata, $modificata, null, self::ADESSO),
        ];
        self::assertSame(ScadenzaDelleBozze::DA_AVVISARE, $riga['stato']);
        self::assertSame([5], self::ids(SpazzataDelleBozze::daAvvisare([$riga])));
    }
}
