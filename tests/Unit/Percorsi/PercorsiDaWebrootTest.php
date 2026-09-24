<?php

declare(strict_types=1);

namespace Tests\Unit\Percorsi;

use App\Controllers\FileController;
use App\Core\Config;
use App\Services\FileService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Salva, poi cancella: il percorso che l'applicazione restituisce deve saper
 * tornare indietro.
 *
 * `FileController::publicize()` risponde al client con un percorso «da
 * webroot» (`/storage/temp/x.tex`), e il client lo rimanda indietro per
 * cancellare o elencare: `splitWebrootPath()` lo deve riconoscere. Spostando
 * le radici sotto `storage/` il primo segmento è diventato `storage` per
 * tutte, e leggere il solo primo segmento — come si faceva — avrebbe fatto
 * rispondere `unknown_root_in_path` a ogni cancellazione. Qui si prova il
 * giro completo, e anche le forme vecchie che un client può avere ancora in
 * mano.
 */
final class PercorsiDaWebrootTest extends TestCase
{
    private string $dati;
    private mixed $datiPrima;
    private FileService $files;
    private FileController $controller;

    protected function setUp(): void
    {
        $this->dati = sys_get_temp_dir() . '/pantedu-webroot-' . bin2hex(random_bytes(6));
        mkdir($this->dati, 0775, true);
        $this->datiPrima = Config::get('app.paths.data_base');
        Config::set('app.paths.data_base', $this->dati);

        $repository = \dirname(__DIR__, 3);
        $_ENV['PANTEDU_DATA_PATH'] = $this->dati;
        $this->files = new FileService(require $repository . '/app/Config/filesystem.php');
        unset($_ENV['PANTEDU_DATA_PATH']);
        $this->controller = new FileController($this->files);
    }

    protected function tearDown(): void
    {
        Config::set('app.paths.data_base', $this->datiPrima);
        $this->rimuovi($this->dati);
    }

    /**
     * @return array<string,array{0:string,1:string}> etichetta => [relativo, atteso]
     */
    public static function radici(): array
    {
        return [
            'temp'           => ['temp', 'prova.tex'],
            'verifiche_temp' => ['verifiche_temp', 'prova.tex'],
            'tex_pdf'        => ['tex_pdf', 'SCI/prova.pdf'],
        ];
    }

    #[DataProvider('radici')]
    public function testIlGiroCompletoSalvaPoiCancella(string $etichetta, string $relativo): void
    {
        $assoluto = $this->files->save($etichetta, $relativo, 'x', 'any');
        $daWebroot = $this->invoca('publicize', $assoluto);

        self::assertStringStartsWith('/storage/', $daWebroot, 'le radici stanno sotto storage/');

        [$label, $rel] = $this->invoca('splitWebrootPath', $daWebroot);

        self::assertSame($etichetta, $label);
        self::assertSame($relativo, $rel);
        self::assertTrue($this->files->delete($label, $rel));
        self::assertFileDoesNotExist($assoluto);
    }

    public function testLeFormeVecchieSiRiconosconoAncora(): void
    {
        // Un client che ha in mano un percorso di prima dello spostamento non
        // deve prendere un errore.
        self::assertSame(['verifiche_temp', 'x.tex'], $this->invoca('splitWebrootPath', '/verifiche/temp/x.tex'));
        self::assertSame(['temp', 'x.tex'], $this->invoca('splitWebrootPath', '/temp/x.tex'));
        self::assertSame(['tex_pdf', 'SCI/x.pdf'], $this->invoca('splitWebrootPath', '/tex_pdf/SCI/x.pdf'));
    }

    public function testUnPercorsoFuoriDaOgniRadiceSiRifiuta(): void
    {
        // Il verso opposto: la funzione non deve rispondere «va bene» a tutto.
        $this->expectExceptionMessage('unknown_root_in_path');
        $this->invoca('splitWebrootPath', '/storage/objects/segreto.json');
    }

    /** @return mixed */
    private function invoca(string $metodo, string $argomento)
    {
        return (new \ReflectionMethod(FileController::class, $metodo))
            ->invoke($this->controller, $argomento);
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
