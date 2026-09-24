<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Core\Config;
use App\Support\Anomalia;
use PHPUnit\Framework\TestCase;

/**
 * «Visto» deve smettere di suonare per quello che si è guardato, e **solo**
 * per quello.
 *
 * La distinzione che questi test difendono è l'unica che conta: un livello
 * d'acqua non è un interruttore. Se segnare `aide_differenze` come visto
 * zittisse anche la prossima occorrenza, non sarebbe un modo di dire «ho
 * guardato» — sarebbe un modo di non guardare più, cioè esattamente il difetto
 * che l'intero registro delle anomalie esiste per combattere.
 */
final class AnomaliaVisteTest extends TestCase
{
    private string $cartella = '';

    protected function setUp(): void
    {
        $this->cartella = sys_get_temp_dir() . '/pantedu-visti-' . bin2hex(random_bytes(6));
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

    private function riga(string $codice, string $quando): string
    {
        return json_encode([
            'quando' => $quando,
            'codice' => $codice,
            'cosa'   => 'occorrenza di prova',
        ], JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $righe */
    private function scrivi(array $righe): void
    {
        file_put_contents(Anomalia::percorso(), implode("\n", $righe) . "\n");
    }

    /** Filtra come fa la diagnostica: fuori quello che è sotto il livello d'acqua. */
    private function nuove(): array
    {
        $visti = Anomalia::vistiFinoA();
        return array_values(array_filter(
            Anomalia::recenti(),
            static function (array $v) use ($visti): bool {
                $soglia = $visti[(string)($v['codice'] ?? '')] ?? null;
                return $soglia === null
                    || (strtotime((string)$v['quando']) ?: 0) > (strtotime($soglia) ?: 0);
            },
        ));
    }

    public function testSenzaNienteDiSegnatoTuttoConta(): void
    {
        $ora = date('c');
        $this->scrivi([$this->riga('aide_differenze', $ora)]);

        self::assertCount(1, $this->nuove());
        self::assertSame([], Anomalia::vistiFinoA());
    }

    public function testQuelloCheSiEGuardatoNonSuonaPiu(): void
    {
        $ora = date('c');
        $this->scrivi([$this->riga('aide_differenze', $ora)]);

        self::assertTrue(Anomalia::segnaVisto('aide_differenze', $ora, 'prova', 'aggiornamento di pacchetti, differenze guardate una per una'));

        self::assertSame([], $this->nuove(), 'dopo «visto» quell\'occorrenza non conta più come nuova');
    }

    public function testUnaOCCORRENZANUOVATornaASuonare(): void
    {
        $prima = date('c', time() - 3600);
        $this->scrivi([$this->riga('aide_differenze', $prima)]);
        Anomalia::segnaVisto('aide_differenze', $prima, 'prova', 'aggiornamento di pacchetti, differenze guardate una per una');
        self::assertSame([], $this->nuove());

        // Arriva una seconda occorrenza, dopo il livello d'acqua.
        $dopo = date('c');
        $this->scrivi([
            $this->riga('aide_differenze', $prima),
            $this->riga('aide_differenze', $dopo),
        ]);

        $nuove = $this->nuove();
        self::assertCount(1, $nuove, 'una nuova occorrenza dopo il livello d\'acqua deve tornare a suonare');
        self::assertSame($dopo, $nuove[0]['quando']);
    }

    public function testSegnareUnCodiceNonNeZittisceUnAltro(): void
    {
        $ora = date('c');
        $this->scrivi([
            $this->riga('aide_differenze', $ora),
            $this->riga('csrf_gettone_vuoto', $ora),
        ]);
        Anomalia::segnaVisto('aide_differenze', $ora, 'prova', 'aggiornamento di pacchetti, differenze guardate una per una');

        $codici = array_column($this->nuove(), 'codice');
        self::assertSame(['csrf_gettone_vuoto'], $codici);
    }

    public function testUnFileDeiVistiRottoFaSuonareTuttoNonTacere(): void
    {
        // Sbagliare dalla parte del rumore, mai da quella del silenzio: un
        // livello d'acqua illeggibile non deve poter nascondere un'anomalia.
        $ora = date('c');
        $this->scrivi([$this->riga('aide_differenze', $ora)]);
        Anomalia::segnaVisto('aide_differenze', $ora, 'prova', 'aggiornamento di pacchetti, differenze guardate una per una');
        file_put_contents(Anomalia::percorsoVisti(), 'questo non e JSON');

        self::assertSame([], Anomalia::vistiFinoA());
        self::assertCount(1, $this->nuove());
    }

    public function testIlFileDeiVistiPrendePermessiDalRegistroAccanto(): void
    {
        // Chi scrive («pantedu», dall'host) e chi legge («www-data», dentro il
        // container) non sono lo stesso utente. Con la umask predefinita il
        // file usciva leggibile solo al primo, e i livelli d'acqua non
        // arrivavano a chi doveva usarli.
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('permessi POSIX: la prova ha senso solo su Unix');
        }

        $registro = Anomalia::percorso();
        file_put_contents($registro, $this->riga('aide_differenze', date('c')) . "
");
        chmod($registro, 0640);

        Anomalia::segnaVisto('aide_differenze', date('c'), 'prova', 'aggiornamento di pacchetti, differenze guardate una per una');

        clearstatcache();
        self::assertSame(
            0640,
            fileperms(Anomalia::percorsoVisti()) & 0777,
            'i livelli devono essere leggibili da chi legge il registro accanto',
        );
    }

    public function testIlFileRegistraChiEQuando(): void
    {
        $ora = date('c');
        Anomalia::segnaVisto('aide_differenze', $ora, 'docente1', 'aggiornamento di pacchetti, differenze guardate una per una');

        $letto = json_decode((string)file_get_contents(Anomalia::percorsoVisti()), true);

        self::assertSame($ora, $letto['aide_differenze']['fino_a']);
        self::assertSame('docente1', $letto['aide_differenze']['da']);
        self::assertNotEmpty($letto['aide_differenze']['segnato']);
    }

    // ── La motivazione (2026-09-22) ──────────────────────────────────────
    //
    // Chi e quando dicevano *che* qualcuno aveva guardato, non *che cosa
    // aveva visto*. La regola di progetto chiede la seconda cosa, e queste
    // prove la difendono nelle due direzioni: senza motivazione non si scrive
    // niente, con la motivazione si rilegge anche dopo mesi.

    public function testSenzaMotivazioneNonSegnaENonZittisce(): void
    {
        $ora = date('c');
        $this->scrivi([$this->riga('aide_differenze', $ora)]);

        self::assertFalse(
            Anomalia::segnaVisto('aide_differenze', $ora, 'docente1', ''),
            'senza motivazione non si segna',
        );
        self::assertFileDoesNotExist(
            Anomalia::percorsoVisti(),
            'un rifiuto non deve lasciare un file a metà',
        );
        self::assertCount(
            1,
            $this->nuove(),
            "l'anomalia deve continuare a suonare: è il punto di tutta la regola",
        );
    }

    public function testUnaMotivazioneTroppoCortaVieneRifiutata(): void
    {
        $ora = date('c');

        self::assertFalse(Anomalia::segnaVisto('aide_differenze', $ora, 'docente1', 'ok'));
        self::assertFalse(Anomalia::segnaVisto('aide_differenze', $ora, 'docente1', '   visto   '));
        self::assertSame([], Anomalia::vistiFinoA());
    }

    public function testLaMotivazioneSiRilegge(): void
    {
        $ora    = date('c');
        $perche = 'le otto differenze sono i pacchetti aggiornati il 21/9, guardate una per una';

        self::assertTrue(Anomalia::segnaVisto('aide_differenze', $ora, 'docente1', $perche));

        $esteso = Anomalia::vistiPerEsteso();
        self::assertSame($perche, $esteso['aide_differenze']['perche']);
        self::assertSame('docente1', $esteso['aide_differenze']['da']);
    }

    public function testLeMotivazioniPrecedentiRestanoNelloStorico(): void
    {
        $prima = date('c', time() - 7200);
        $dopo  = date('c');

        Anomalia::segnaVisto('aide_differenze', $prima, 'docente1', 'la prima volta: pacchetti aggiornati');
        Anomalia::segnaVisto('aide_differenze', $dopo, 'docente1', 'la seconda volta: timer disattivato a mano');

        $esteso = Anomalia::vistiPerEsteso();
        self::assertStringContainsString('la seconda volta', $esteso['aide_differenze']['perche']);
        self::assertCount(1, $esteso['aide_differenze']['storico'], 'la motivazione vecchia non si butta');
        self::assertStringContainsString('la prima volta', $esteso['aide_differenze']['storico'][0]['perche']);
    }

    /**
     * Un file scritto prima del 22/9/2026 non ha la motivazione. Deve leggersi
     * lo stesso — e il vuoto deve distinguersi, perché «non registrata» e
     * «scritta male» non sono la stessa cosa per chi rilegge.
     */
    public function testUnaVoceAnterioreSiLeggeSenzaMotivazione(): void
    {
        file_put_contents(Anomalia::percorsoVisti(), json_encode([
            'aide_differenze' => ['fino_a' => '2026-09-09T11:04:00+00:00', 'segnato' => '2026-09-09T11:10:00+00:00', 'da' => 'docente1'],
        ]));

        $esteso = Anomalia::vistiPerEsteso();
        self::assertSame('2026-09-09T11:04:00+00:00', $esteso['aide_differenze']['fino_a']);
        self::assertSame('', $esteso['aide_differenze']['perche']);
        self::assertSame([], $esteso['aide_differenze']['storico']);
        self::assertArrayHasKey(
            'aide_differenze',
            Anomalia::vistiFinoA(),
            'il livello d\'acqua antico deve continuare a valere: non si riapre il passato',
        );
    }
}
