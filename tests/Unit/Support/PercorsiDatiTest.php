<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Core\Config;
use App\Support\PercorsiDati;
use PHPUnit\Framework\TestCase;

/**
 * `PercorsiDati` dice dove si scrive e dove si legge.
 *
 * Dall'8 settembre 2026 l'applicazione gira in un container e la radice del
 * repository, lì dentro, è l'immagine: immutabile. Quello che ci si scrive
 * finisce nello strato scrivibile di overlayfs e sparisce al rilascio
 * successivo, senza un errore. Questa classe è il posto unico dove si decide
 * quale delle due radici usare, e queste prove la misurano nei due versi:
 * che scelga i dati quando ci sono, e che NON li scelga quando non ci sono.
 */
final class PercorsiDatiTest extends TestCase
{
    private string $dati;
    private string $codice;
    private mixed $datiPrima;

    protected function setUp(): void
    {
        $marca = bin2hex(random_bytes(6));
        $this->dati   = sys_get_temp_dir() . '/pantedu-percorsi-dati-' . $marca;
        $this->codice = sys_get_temp_dir() . '/pantedu-percorsi-codice-' . $marca;
        mkdir($this->dati, 0775, true);
        mkdir($this->codice, 0775, true);
        $this->datiPrima = Config::get('app.paths.data_base');
        Config::set('app.paths.data_base', $this->dati);
    }

    protected function tearDown(): void
    {
        Config::set('app.paths.data_base', $this->datiPrima);
        $this->rimuovi($this->dati);
        $this->rimuovi($this->codice);
    }

    public function testLaBaseEQuellaDeiDatiNonQuellaDelCodice(): void
    {
        self::assertSame($this->dati, PercorsiDati::base($this->codice));
    }

    public function testSenzaConfigurazioneSiRicadeSullaRadiceDelCodice(): void
    {
        // Controprova: in sviluppo, con PANTEDU_DATA_PATH vuoto, il
        // comportamento di prima deve restare identico. Se questa fallisse,
        // la correzione avrebbe spostato i file anche a chi non gira in un
        // container.
        Config::set('app.paths.data_base', '');
        self::assertSame($this->codice, PercorsiDati::base($this->codice));

        Config::set('app.paths.data_base', '   ');
        self::assertSame(
            $this->codice,
            PercorsiDati::base($this->codice),
            'una configurazione fatta di soli spazi vale come vuota',
        );
    }

    public function testUnaRadiceEsplicitaVinceSullaConfigurazione(): void
    {
        // Gli strumenti da riga di comando e le prove passano la cartella:
        // deve restare quella, altrimenti non si può misurare niente.
        $esplicita = $this->codice . '/altrove';
        self::assertSame($esplicita, PercorsiDati::base($this->codice, $esplicita));
    }

    public function testLaBarraFinaleNonSiPorta(): void
    {
        Config::set('app.paths.data_base', $this->dati . '/');
        self::assertSame($this->dati, PercorsiDati::base($this->codice));
    }

    public function testInCascataPreferisceIDatiAlCodice(): void
    {
        $rel = 'storage/templates/verifiche/_default/texCommon/verifica.sty';
        $this->scrivi($this->codice . '/' . $rel, 'la copia versionata');
        $this->scrivi($this->dati . '/' . $rel, 'lo scostamento dell istanza');

        self::assertSame(
            $this->dati . '/' . $rel,
            PercorsiDati::inCascata($rel, $this->codice),
            'quando ci sono tutti e due, vince quello nei dati',
        );
    }

    public function testInCascataRicadeSulCodiceQuandoNeiDatiNonCE(): void
    {
        // È il caso vero della base versionata che viaggia nell'immagine:
        // finché l'istanza non la sovrascrive, si legge di lì.
        $rel = 'storage/templates/verifiche/_default/versioni/main_NOR.tex';
        $this->scrivi($this->codice . '/' . $rel, 'la copia versionata');

        self::assertSame(
            $this->codice . '/' . $rel,
            PercorsiDati::inCascata($rel, $this->codice),
        );
    }

    public function testInCascataTornaNullSeNonCENeDiQuaNeDiLa(): void
    {
        // Controprova: senza questa, le due prove sopra passerebbero anche se
        // il metodo restituisse sempre il primo percorso che si immagina.
        self::assertNull(
            PercorsiDati::inCascata('storage/inventato/mai-scritto.json', $this->codice),
        );
    }

    public function testLeRadiciInCascataSonoDueENellOrdineGiusto(): void
    {
        self::assertSame(
            [$this->dati . '/storage/x', $this->codice . '/storage/x'],
            PercorsiDati::radiciInCascata('storage/x', $this->codice),
            'prima i dati, poi il codice',
        );
    }

    public function testQuandoLeDueRadiciCoincidonoNeTornaUnaSola(): void
    {
        // In sviluppo senza PANTEDU_DATA_PATH le due radici sono la stessa
        // cartella: chi cicla non deve esaminarla due volte, altrimenti ogni
        // file risulta «trovato due volte» e i conti di chi elenca sballano.
        Config::set('app.paths.data_base', $this->codice);
        self::assertSame(
            [$this->codice . '/storage/x'],
            PercorsiDati::radiciInCascata('storage/x', $this->codice),
        );
    }

    private function scrivi(string $percorso, string $contenuto): void
    {
        @mkdir(\dirname($percorso), 0775, true);
        file_put_contents($percorso, $contenuto);
    }

    private function rimuovi(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
