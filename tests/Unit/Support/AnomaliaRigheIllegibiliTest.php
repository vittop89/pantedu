<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Core\Config;
use App\Support\Anomalia;
use PHPUnit\Framework\TestCase;

/**
 * Una riga illeggibile nel registro delle anomalie è un messaggio perso.
 *
 * Il difetto che ha fatto nascere questo test, il 9 settembre 2026. Il
 * controllo d'integrità notturno ha scritto in `anomalie.jsonl` un allarme
 * vero — «AIDE è uscito 17», cioè non era nemmeno partito — ma il numero delle
 * voci lo calcolava con `grep -c ... || echo 0`, che quando il conto è zero
 * stampa `0` **e** esce 1, quindi il ripiego ne stampa un altro. Il valore
 * diventava `0\n0` e spezzava la riga JSON in tre.
 *
 * `Anomalia::recenti()` faceva `continue` su ciascuna delle tre, e la
 * diagnostica ha annunciato «nessuna incoerenza interna nelle ultime 24 ore».
 *
 * L'allarme era stato dato e nessuno l'ha sentito: la stessa forma di guasto
 * che quel registro esiste per combattere, un piano più sotto. Da qui la
 * regola: **quello che non si riesce a leggere si conta e si dice**.
 */
final class AnomaliaRigheIllegibiliTest extends TestCase
{
    private string $cartella = '';

    protected function setUp(): void
    {
        $this->cartella = sys_get_temp_dir() . '/pantedu-anomalie-' . bin2hex(random_bytes(6));
        mkdir($this->cartella, 0775, true);
        Config::set('app.paths.logs', $this->cartella);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cartella . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cartella);
    }

    /** @param list<string> $righe */
    private function scrivi(array $righe): void
    {
        file_put_contents(Anomalia::percorso(), implode("\n", $righe) . "\n");
    }

    private function rigaValida(string $codice): string
    {
        return json_encode([
            'quando' => date('c'),
            'codice' => $codice,
            'cosa'   => 'una riga qualunque',
        ], JSON_THROW_ON_ERROR);
    }

    public function testUnaRigaRottaInCodaVieneSegnalata(): void
    {
        // Esattamente la forma prodotta dal difetto: una riga spezzata in tre
        // da un numero con dentro un ritorno a capo.
        $this->scrivi([
            $this->rigaValida('csrf_gettone_vuoto'),
            '{"quando":"2026-09-09T04:00:01+00:00","codice":"aide_differenze","cosa":"AIDE ha rilevato differenze',
            '0 voci). Dettagli in /var/log/aide/aide-daily.log","dettagli":{"rc":17,"voci":0',
            '0,"registro":"/var/log/aide/aide-daily.log"}}',
        ]);

        $codici = array_column(Anomalia::recenti(), 'codice');

        self::assertContains(
            'registro_riga_illeggibile',
            $codici,
            'tre righe non-JSON in coda al registro devono essere segnalate, non scartate in silenzio',
        );
    }

    public function testIlConteggioDelleRotteFinisceInSaltate(): void
    {
        $this->scrivi([
            'prima riga rotta',
            'seconda riga rotta',
            'terza riga rotta',
        ]);

        $voci = array_values(array_filter(
            Anomalia::recenti(),
            static fn(array $v): bool => ($v['codice'] ?? '') === 'registro_riga_illeggibile',
        ));

        self::assertCount(1, $voci, 'le righe rotte si raccolgono in una segnalazione sola');
        // La diagnostica conta `1 + saltate`, quindi tre righe rotte devono
        // leggersi come «×3» e non come «×1».
        self::assertSame(2, $voci[0]['saltate'] ?? null);
    }

    public function testUnRegistroTuttoValidoNonSegnalaNiente(): void
    {
        $this->scrivi([
            $this->rigaValida('csrf_gettone_vuoto'),
            $this->rigaValida('aide_differenze'),
        ]);

        $codici = array_column(Anomalia::recenti(), 'codice');

        self::assertNotContains('registro_riga_illeggibile', $codici);
        self::assertCount(2, $codici);
    }

    public function testUnaRigaRottaVecchiaNonSuonaPerSempre(): void
    {
        // Una riga rotta più indietro di duecento righe è archeologia: il
        // registro ruota per dimensione e prima o poi se ne va da sola. Un
        // allarme che non si può spegnere insegna a ignorare gli allarmi.
        $righe = ['questa e rotta ed e vecchissima'];
        for ($i = 0; $i < 250; $i++) {
            $righe[] = $this->rigaValida('riempitivo');
        }
        $this->scrivi($righe);

        $codici = array_column(Anomalia::recenti(), 'codice');

        self::assertNotContains('registro_riga_illeggibile', $codici);
    }
}
