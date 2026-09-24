<?php

declare(strict_types=1);

namespace Tests\Unit\Percorsi;

use App\Core\Config;
use App\Services\GeoGebra\GeoGebraCatalogService;
use App\Services\Shortcuts\LatexShortcutsService;
use PHPUnit\Framework\TestCase;

/**
 * Le scorciatoie LaTeX del docente e il suo catalogo GeoGebra stanno nella
 * cartella dei dati d'istanza, e una LETTURA non crea più niente.
 *
 * Il difetto (misurato in produzione il 20 settembre 2026). Tutti e due i
 * servizi avevano `dirname(__DIR__, 3)` come radice predefinita e nessun
 * chiamante ne passava un'altra: gli override finivano nella radice del
 * repository, che nel container è l'immagine, e sparivano al rilascio. Non
 * hanno nessun riscontro nel database: il file è l'unica copia.
 *
 * E c'è la firma del difetto, che è la ragione per cui ce ne siamo accorti:
 * `teacherFile()` e `teacherDir()` facevano `mkdir` anche quando le chiamava
 * una lettura. Sedici `GET /api/latex-shortcuts/effective` fra il 17 e il 20
 * settembre sono bastate a far comparire, dentro il container,
 * `storage/objects/teachers/77` — vuota. Vuota perché nessuno aveva salvato:
 * era la lettura a crearla. Adesso la cartella la fa solo chi scrive.
 */
final class ScorciatoieECatalogoNeiDatiTest extends TestCase
{
    private string $dati;
    private string $codice;
    private mixed $datiPrima;

    protected function setUp(): void
    {
        $marca = bin2hex(random_bytes(6));
        $this->dati   = sys_get_temp_dir() . '/pantedu-scorciatoie-dati-' . $marca;
        $this->codice = sys_get_temp_dir() . '/pantedu-scorciatoie-codice-' . $marca;
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

    public function testGliOverrideDelDocenteSiLeggonoDaiDati(): void
    {
        $this->scrivi(
            $this->dati . '/storage/objects/teachers/77/latex-shortcuts-overrides.json',
            json_encode(['gruppo|etichetta' => ['label' => 'dai dati']]),
        );

        self::assertSame(
            ['gruppo|etichetta' => ['label' => 'dai dati']],
            (new LatexShortcutsService())->getOverrides(77),
        );
    }

    public function testGliOverrideFuoriDaiDatiNonSiLeggono(): void
    {
        // Controprova: la stessa struttura nella radice del CODICE non conta.
        // È esattamente dove finivano prima, ed è il motivo per cui sparivano.
        $this->scrivi(
            $this->codice . '/storage/objects/teachers/77/latex-shortcuts-overrides.json',
            json_encode(['gruppo|etichetta' => ['label' => 'dal repository']]),
        );

        self::assertSame([], (new LatexShortcutsService())->getOverrides(77));
    }

    public function testLeggereNonCreaLaCartellaDelDocente(): void
    {
        // La firma del difetto, misurata: prima bastava una lettura.
        (new LatexShortcutsService())->getEffective(77);

        self::assertDirectoryDoesNotExist($this->dati . '/storage/objects/teachers/77');
    }

    public function testSalvareCreaLaCartellaNeiDati(): void
    {
        // Il verso opposto: la cartella deve nascere quando si salva davvero,
        // altrimenti il salvataggio fallirebbe e avremmo tolto troppo.
        (new LatexShortcutsService())->saveOverride(77, 'gruppo', 'etichetta', ['latex' => '\\alpha']);

        self::assertFileExists(
            $this->dati . '/storage/objects/teachers/77/latex-shortcuts-overrides.json',
        );
    }

    public function testIlSeedAdminSiLeggeAncoraDalCodice(): void
    {
        // `storage/data/latex_shortcuts_default.json` è versionato (8,7 kB in
        // git) e viaggia dentro l'immagine: finché l'amministratore non lo
        // cambia si legge di lì, anche se nei dati non c'è niente. È il verso
        // che rende la correzione non banale — spostare tutto nei dati avrebbe
        // fatto sparire il riferimento a tutti.
        if (!is_file(\dirname(__DIR__, 3) . '/storage/data/latex_shortcuts_default.json')) {
            self::markTestSkipped('il seed versionato non è in questa copia di lavoro');
        }

        self::assertNotSame(
            [],
            (new LatexShortcutsService())->getAdminDefaults(),
            'senza niente nei dati, il riferimento arriva dalla copia versionata',
        );
    }

    public function testIlRiferimentoSalvatoNeiDatiVinceSulSeed(): void
    {
        $this->scrivi(
            $this->dati . '/storage/data/latex_shortcuts_default.json',
            json_encode(['gruppo' => [['label' => 'la scelta dell istanza']]]),
        );

        self::assertSame(
            ['gruppo' => [['label' => 'la scelta dell istanza']]],
            (new LatexShortcutsService())->getAdminDefaults(),
            'quando l amministratore lo cambia, la copia nei dati vince sul seed',
        );
    }

    public function testUnaRadiceEsplicitaVinceSullaConfigurazione(): void
    {
        // Gli strumenti da riga di comando passano la cartella: deve restare
        // quella, com'è per TikzRenderService.
        $this->scrivi(
            $this->codice . '/storage/objects/teachers/77/latex-shortcuts-overrides.json',
            json_encode(['gruppo|etichetta' => ['label' => 'dalla radice esplicita']]),
        );

        self::assertSame(
            ['gruppo|etichetta' => ['label' => 'dalla radice esplicita']],
            (new LatexShortcutsService($this->codice))->getOverrides(77),
        );
    }

    public function testIlCatalogoGeoGebraSiLeggeDaiDati(): void
    {
        $this->scrivi(
            $this->dati . '/storage/objects/teachers/140/geogebra-catalog.json',
            json_encode(['items' => [['id' => 'abc', 'label' => 'dai dati']], 'version' => 1]),
        );

        $voci = (new GeoGebraCatalogService())->listLight(140);
        self::assertCount(1, $voci);
        self::assertSame('dai dati', $voci[0]['label'] ?? null);
    }

    public function testIlCatalogoFuoriDaiDatiNonSiLegge(): void
    {
        // Controprova del punto sopra.
        $this->scrivi(
            $this->codice . '/storage/objects/teachers/140/geogebra-catalog.json',
            json_encode(['items' => [['id' => 'abc', 'label' => 'dal repository']], 'version' => 1]),
        );

        self::assertSame([], (new GeoGebraCatalogService())->listLight(140));
    }

    public function testLeggereIlCatalogoNonCreaLaCartella(): void
    {
        (new GeoGebraCatalogService())->listLight(140);

        self::assertDirectoryDoesNotExist($this->dati . '/storage/objects/teachers/140');
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
