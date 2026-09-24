<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Risdoc;

use App\Controllers\Risdoc\TemplateController;
use App\Services\Risdoc\TemplateResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * I file dei modelli si leggono solo dalle loro cartelle (23/9/2026,
 * revisione Risdoc A1, capitolo 0.1).
 *
 * GET /api/risdoc/templates/{id}/file passava `kind` e `path` della query a
 * `TemplateResolver::resolveSourceFilePath`, che li attaccava a una cartella
 * senza confinarli. Con kind=schema la cartella era la radice del progetto: un
 * docente autenticato leggeva qualunque file dell'applicazione. Con json e
 * image bastava un `..`.
 *
 * Nei due versi: i file legittimi si risolvono (altrimenti un resolver che
 * risponde sempre null passerebbe), e ogni forma di uscita dà null. Il file
 * preso di mira (`composer.json`) esiste davvero: il null vuol dire
 * «respinto», non «non c'è».
 *
 * Senza database: il resolver si crea senza costruttore, perché
 * resolveSourceFilePath non tocca i repository.
 */
final class TemplateResolverConfinatoTest extends TestCase
{
    private static string $radice;

    public static function setUpBeforeClass(): void
    {
        self::$radice = dirname(__DIR__, 4);
    }

    private function resolver(): TemplateResolver
    {
        return (new \ReflectionClass(TemplateResolver::class))->newInstanceWithoutConstructor();
    }

    /** @return array<string, mixed> */
    private function modello(): array
    {
        // Una cartella sorgente che esiste nel repository, con un file dentro.
        return [
            'source_dir'  => 'storage/templates/risdoc/competenze_DM2007',
            'html_file'   => 'competenze_DM2007.json',
            'tex_file'    => 'modello.tex',
            'css_file'    => 'modello.css',
            'source_hash' => str_repeat('0', 64),
        ];
    }

    #[Test]
    public function il_bersaglio_delle_prove_esiste_davvero(): void
    {
        self::assertFileExists(self::$radice . '/composer.json');
        self::assertFileExists(self::$radice . '/storage/templates/risdoc/images/stemma_REP.png');
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function legittimi(): iterable
    {
        yield 'json dei modelli'     => ['json', 'competenze_DM2007/competenze_DM2007.json', '/storage/templates/risdoc/competenze_DM2007/competenze_DM2007.json'];
        yield 'immagine istituzionale' => ['image', 'images/stemma_REP.png', '/storage/templates/risdoc/images/stemma_REP.png'];
        yield 'schema col prefisso'  => ['schema', 'schemas/risdoc/glossario.json', '/schemas/risdoc/glossario.json'];
        yield 'schema senza prefisso' => ['schema', 'glossario.json', '/schemas/risdoc/glossario.json'];
        yield 'html predefinito'     => ['html', '', '/storage/templates/risdoc/competenze_DM2007/competenze_DM2007.json'];
    }

    #[Test]
    #[DataProvider('legittimi')]
    public function un_file_legittimo_si_risolve(string $kind, string $path, string $atteso): void
    {
        $r = $this->resolver()->resolveSourceFilePath($this->modello(), $kind, $path);
        self::assertSame(realpath(self::$radice . $atteso), $r === null ? null : realpath($r));
        self::assertNotNull($r);
        self::assertFileExists($r);
    }

    /** @return iterable<string, array{string, string}> */
    public static function uscite(): iterable
    {
        yield 'schema sulla radice del progetto'   => ['schema', 'composer.json'];
        yield 'schema che risale'                   => ['schema', 'schemas/risdoc/../../composer.json'];
        yield 'json che risale'                     => ['json', '../../../composer.json'];
        yield 'immagine che risale'                 => ['image', 'images/../../../../composer.json'];
        yield 'immagine con percorso assoluto'      => ['image', '/etc/passwd'];
        yield 'json con barre rovesciate'           => ['json', '..\\..\\..\\composer.json'];
        yield 'json con byte nullo'                 => ['json', "competenze_DM2007/competenze_DM2007.json\0.png"];
        yield 'html fuori dalla cartella sorgente'  => ['html', '../../../../composer.json'];
        yield 'tex fuori dalla cartella sorgente'   => ['tex', '../../../../composer.json'];
        yield 'css fuori dalla cartella sorgente'   => ['css', '../../../../composer.json'];
        yield 'json vuoto'                          => ['json', ''];
        yield 'kind sconosciuto'                    => ['config', 'composer.json'];
    }

    /** La cartella a cui ogni kind è confinato. */
    private function cartella(string $kind): string
    {
        return match ($kind) {
            'schema'              => self::$radice . '/schemas/risdoc',
            'json', 'image'       => self::$radice . '/storage/templates/risdoc',
            default               => self::$radice . '/' . $this->modello()['source_dir'],
        };
    }

    /**
     * Respinto vuol dire: null, oppure un percorso DENTRO la cartella del kind
     * (`schema` con `composer.json` diventa `schemas/risdoc/composer.json`, che
     * non esiste e non si legge). In nessun caso il file preso di mira.
     */
    #[Test]
    #[DataProvider('uscite')]
    public function un_percorso_che_esce_dalla_cartella_non_si_risolve(string $kind, string $path): void
    {
        $r = $this->resolver()->resolveSourceFilePath($this->modello(), $kind, $path);
        if ($r === null) {
            $this->addToAssertionCount(1);
            return;
        }
        $bersaglio = realpath(self::$radice . '/composer.json');
        self::assertNotSame($bersaglio, realpath($r), "«{$path}» arriva a composer.json");
        self::assertStringStartsWith(realpath($this->cartella($kind)) . '/', str_replace('\\', '/', $r));
    }

    #[Test]
    public function la_rotta_dei_file_serve_solo_i_kind_del_client(): void
    {
        foreach (['html', 'tex', 'css', 'json', 'image', 'texCommon'] as $k) {
            self::assertTrue(TemplateController::kindAmmesso($k), "{$k} dovrebbe passare");
        }
        foreach (['schema', 'SCHEMA', '', 'config', 'json ', '../json'] as $k) {
            self::assertFalse(TemplateController::kindAmmesso($k), "«{$k}» non dovrebbe passare");
        }
    }
}
