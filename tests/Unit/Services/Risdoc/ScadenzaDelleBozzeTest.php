<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Risdoc;

use App\Services\Risdoc\ScadenzaDelleBozze;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Quando muore una bozza, e — soprattutto — quando NON deve morire.
 *
 * ── Perché le due direzioni contano qui più che altrove ──────────────────
 *
 * Questa regola cancella il lavoro di un docente. Una prova che verificasse
 * solo «scade quando deve» passerebbe identica su una funzione che risponde
 * sempre «scaduta», e quella funzione cancellerebbe tutto la prima notte.
 * Quindi per ogni caso che deve scattare ce n'è uno accanto che non deve.
 */
final class ScadenzaDelleBozzeTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────
    // La grazia dopo lo scaricamento
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function una_bozza_mai_scaricata_non_ha_scadenza_da_scaricamento(): void
    {
        self::assertNull(
            ScadenzaDelleBozze::perScaricamento(null, '2026-09-01 10:00:00'),
            'senza scaricamento la grazia non parte: la prende solo la rete di fine anno'
        );
    }

    #[Test]
    public function la_grazia_parte_dallo_scaricamento(): void
    {
        $scade = ScadenzaDelleBozze::perScaricamento('2026-09-01 10:00:00', '2026-08-20 09:00:00');

        self::assertNotNull($scade);
        self::assertSame('2026-09-16', $scade->format('Y-m-d'), '15 giorni dopo lo scaricamento');
    }

    /**
     * Il caso che rende la regola accettabile: si esporta per guardare
     * l'impaginazione, poi si continua a lavorare. Se il conto non ripartisse,
     * il documento sparirebbe mentre lo si sta ancora scrivendo.
     */
    #[Test]
    public function una_modifica_dopo_lo_scaricamento_fa_ripartire_il_conto(): void
    {
        $senzaModifica = ScadenzaDelleBozze::perScaricamento('2026-09-01 10:00:00', '2026-08-20 09:00:00');
        $conModifica   = ScadenzaDelleBozze::perScaricamento('2026-09-01 10:00:00', '2026-09-05 15:00:00');

        self::assertNotNull($senzaModifica);
        self::assertNotNull($conModifica);
        self::assertSame('2026-09-16', $senzaModifica->format('Y-m-d'));
        self::assertSame('2026-09-20', $conModifica->format('Y-m-d'), '15 giorni dalla modifica, non dallo scaricamento');
        self::assertGreaterThan($senzaModifica, $conModifica, 'modificare deve allungare la vita, mai accorciarla');
    }

    #[Test]
    public function una_stringa_vuota_vale_come_mai_scaricata(): void
    {
        self::assertNull(ScadenzaDelleBozze::perScaricamento('', '2026-09-01 10:00:00'));
        self::assertNull(ScadenzaDelleBozze::perScaricamento('   ', '2026-09-01 10:00:00'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // La rete di fine anno scolastico
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @return list<array{0:string,1:string,2:string}>
     */
    public static function casiDellaRete(): array
    {
        return [
            ['ferma a gennaio, muore il 31 agosto dello stesso anno',
                '2027-01-10 08:00:00', '2027-08-31'],
            ['ferma a maggio, muore il 31 agosto dello stesso anno',
                '2027-05-31 23:00:00', '2027-08-31'],
            ['lavorata il 1 giugno: l\'anno le appartiene, muore l\'estate dopo',
                '2027-06-01 00:00:01', '2028-08-31'],
            ['lavorata a luglio: serve per l\'anno che comincia',
                '2027-07-15 12:00:00', '2028-08-31'],
            ['lavorata a settembre: anno in corso',
                '2027-09-15 12:00:00', '2028-08-31'],
        ];
    }

    #[Test]
    #[DataProvider('casiDellaRete')]
    public function la_rete_cade_a_fine_anno_scolastico(string $caso, string $modificata, string $atteso): void
    {
        self::assertSame(
            $atteso,
            ScadenzaDelleBozze::perLaRete($modificata)->format('Y-m-d'),
            $caso
        );
    }

    /**
     * Il confine esatto. Il 31 maggio muore in agosto, il 1° giugno no: se
     * questa riga cade, la rete si è spostata di un anno senza che nessuno
     * l'abbia deciso.
     */
    #[Test]
    public function il_confine_e_la_mezzanotte_del_primo_giugno(): void
    {
        self::assertSame('2027-08-31', ScadenzaDelleBozze::perLaRete('2027-05-31 23:59:59')->format('Y-m-d'));
        self::assertSame('2028-08-31', ScadenzaDelleBozze::perLaRete('2027-06-01 00:00:00')->format('Y-m-d'));
    }

    /**
     * La rete non può uccidere in pochi giorni: è il rischio che la rendeva
     * discutibile. Il minimo garantito è tre mesi, e si misura qui.
     */
    #[Test]
    public function la_rete_lascia_vivere_almeno_tre_mesi(): void
    {
        $peggiore = '2027-05-31 23:59:59'; // il giorno più sfortunato possibile
        $giorni = ScadenzaDelleBozze::giorniRimasti(null, $peggiore, $peggiore);

        self::assertGreaterThanOrEqual(
            90,
            $giorni,
            'nel caso peggiore la rete deve lasciare almeno tre mesi: qui ne lascia ' . $giorni
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Quale delle due arriva prima
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function vince_la_scadenza_piu_vicina(): void
    {
        // Scaricata a settembre: i 15 giorni arrivano molto prima del 31 agosto.
        $scade = ScadenzaDelleBozze::scadeIl('2027-09-10 10:00:00', '2027-09-10 10:00:00');
        self::assertSame('2027-09-25', $scade->format('Y-m-d'));
        self::assertSame('scaricata', ScadenzaDelleBozze::motivo('2027-09-10 10:00:00', '2027-09-10 10:00:00'));

        // Mai scaricata: resta solo la rete.
        $soloRete = ScadenzaDelleBozze::scadeIl(null, '2027-09-10 10:00:00');
        self::assertSame('2028-08-31', $soloRete->format('Y-m-d'));
        self::assertSame('fine_anno', ScadenzaDelleBozze::motivo(null, '2027-09-10 10:00:00'));
    }

    /**
     * Il caso di confine fra le due regole: scaricata ad agosto, con i 15
     * giorni che scavalcano il 31. Deve vincere la rete, perché è prima.
     */
    #[Test]
    public function se_la_rete_arriva_prima_vince_la_rete(): void
    {
        $scade = ScadenzaDelleBozze::scadeIl('2027-08-25 10:00:00', '2027-05-01 09:00:00');

        self::assertSame('2027-08-31', $scade->format('Y-m-d'), 'la rete del 31 agosto arriva prima del 9 settembre');
        self::assertSame('fine_anno', ScadenzaDelleBozze::motivo('2027-08-25 10:00:00', '2027-05-01 09:00:00'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Lo stato: è qui che si decide se si cancella
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function una_bozza_appena_salvata_e_viva(): void
    {
        self::assertSame(
            ScadenzaDelleBozze::VIVA,
            ScadenzaDelleBozze::stato(null, '2026-09-22 10:00:00', null, '2026-09-22 10:00:00'),
            'una bozza di oggi non deve essere né avvisata né cancellata'
        );
    }

    #[Test]
    public function una_bozza_scaricata_ieri_e_viva(): void
    {
        self::assertSame(
            ScadenzaDelleBozze::VIVA,
            ScadenzaDelleBozze::stato('2026-09-21 10:00:00', '2026-09-21 10:00:00', null, '2026-09-22 10:00:00')
        );
    }

    #[Test]
    public function a_sette_giorni_dalla_fine_si_avvisa(): void
    {
        // Scaricata il 1°: scade il 16. Il 9 ne mancano sette.
        self::assertSame(
            ScadenzaDelleBozze::DA_AVVISARE,
            ScadenzaDelleBozze::stato('2026-09-01 10:00:00', '2026-09-01 10:00:00', null, '2026-09-09 10:00:00')
        );
    }

    #[Test]
    public function a_otto_giorni_dalla_fine_non_si_avvisa_ancora(): void
    {
        self::assertSame(
            ScadenzaDelleBozze::VIVA,
            ScadenzaDelleBozze::stato('2026-09-01 10:00:00', '2026-09-01 10:00:00', null, '2026-09-08 10:00:00'),
            'un avviso che parte troppo presto è un avviso che si dimentica'
        );
    }

    #[Test]
    public function chi_e_gia_stato_avvisato_non_si_riavvisa(): void
    {
        self::assertSame(
            ScadenzaDelleBozze::VIVA,
            ScadenzaDelleBozze::stato('2026-09-01 10:00:00', '2026-09-01 10:00:00', '2026-09-09 03:50:00', '2026-09-10 03:50:00'),
            'un avviso che arriva tutti i giorni dopo tre giorni non si legge più'
        );
    }

    #[Test]
    public function il_giorno_della_scadenza_si_cancella(): void
    {
        self::assertSame(
            ScadenzaDelleBozze::DA_CANCELLARE,
            ScadenzaDelleBozze::stato('2026-09-01 10:00:00', '2026-09-01 10:00:00', '2026-09-09 03:50:00', '2026-09-16 03:50:00')
        );
    }

    #[Test]
    public function il_giorno_prima_non_si_cancella(): void
    {
        self::assertNotSame(
            ScadenzaDelleBozze::DA_CANCELLARE,
            ScadenzaDelleBozze::stato('2026-09-01 10:00:00', '2026-09-01 10:00:00', '2026-09-09 03:50:00', '2026-09-15 23:59:00'),
            'cancellare un giorno prima è cancellare un giorno di troppo'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Ciò che è mal formato non è una licenza a cancellare
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function un_adesso_illeggibile_non_cancella_niente(): void
    {
        self::assertSame(
            ScadenzaDelleBozze::VIVA,
            ScadenzaDelleBozze::stato(null, '2020-01-01 00:00:00', null, 'non-una-data'),
            'senza un «adesso» leggibile non si decide: il verso prudente è tenere'
        );
    }

    #[Test]
    public function una_data_di_scaricamento_illeggibile_vale_come_mai_scaricata(): void
    {
        self::assertNull(ScadenzaDelleBozze::perScaricamento('boh', '2026-09-01 10:00:00'));
    }

    /**
     * Controllo positivo sui numeri. Se qualcuno cambia le costanti pensando
     * di cambiare una soglia, questa riga glielo fa notare: i numeri sono
     * scritti anche nei documenti legali, e vanno cambiati insieme.
     */
    #[Test]
    public function i_numeri_sono_quelli_dichiarati(): void
    {
        self::assertSame(15, ScadenzaDelleBozze::GRAZIA_GIORNI);
        self::assertSame(7, ScadenzaDelleBozze::PREAVVISO_GIORNI);
        self::assertSame(8, ScadenzaDelleBozze::RETE_MESE);
        self::assertSame(31, ScadenzaDelleBozze::RETE_GIORNO);
        self::assertSame(6, ScadenzaDelleBozze::SOGLIA_MESE);
        self::assertSame(1, ScadenzaDelleBozze::SOGLIA_GIORNO);
    }
}
