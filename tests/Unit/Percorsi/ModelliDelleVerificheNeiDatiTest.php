<?php

declare(strict_types=1);

namespace Tests\Unit\Percorsi;

use App\Core\Config;
use App\Services\TexBuilder\BadgeStylePresetStore;
use App\Services\Verifica\TemplateFileStore;
use PHPUnit\Framework\TestCase;

/**
 * I modelli LaTeX delle verifiche e i preset di stile del badge si scrivono
 * nella cartella dei dati d'istanza, e si leggono in cascata: prima i dati,
 * poi la base versionata che viaggia dentro l'immagine.
 *
 * Il difetto (misurato in produzione il 20 settembre 2026). `rootDir()`
 * partiva da `dirname(__DIR__, 3)`, la radice del repository — che dal
 * rilascio a container dell'8 settembre è l'immagine, immutabile. Due
 * conseguenze, tutte e due silenziose:
 *
 *  - gli scostamenti del docente (`t_<id>/…`) sono ignorati da git, quindi
 *    nell'immagine non ci sono nemmeno in lettura: erano invisibili dall'8/9,
 *    e ogni nuovo salvataggio finiva nello strato scrivibile del container e
 *    spariva al rilascio successivo;
 *  - scrivere su `_default/` copriva per giunta un file versionato con una
 *    copia che il rilascio buttava via.
 *
 * Nella cartella dei dati di produzione `storage/templates` non esiste
 * affatto: i modelli dei docenti non ci sono mai arrivati.
 */
final class ModelliDelleVerificheNeiDatiTest extends TestCase
{
    private string $dati;
    private mixed $datiPrima;

    protected function setUp(): void
    {
        $this->dati = sys_get_temp_dir() . '/pantedu-modelli-verifiche-' . bin2hex(random_bytes(6));
        mkdir($this->dati, 0775, true);
        $this->datiPrima = Config::get('app.paths.data_base');
        Config::set('app.paths.data_base', $this->dati);
        TemplateFileStore::clearReadCache();
    }

    protected function tearDown(): void
    {
        Config::set('app.paths.data_base', $this->datiPrima);
        TemplateFileStore::clearReadCache();
        $this->rimuovi($this->dati);
        // Se la correzione non ci fosse, `write()` scriverebbe nel
        // repository: la prova lo scoprirebbe, ma lascerebbe lì il file.
        // Misurato il 20/9/2026 provando queste stesse prove contro il codice
        // vecchio — `storage/templates/verifiche/t_77` è rimasta nella copia
        // di lavoro. Una prova non lascia residui nemmeno quando fallisce.
        $this->rimuovi(TemplateFileStore::rootDirCodice() . '/' . TemplateFileStore::teacherScope(77));
        $this->rimuovi(TemplateFileStore::rootDirCodice() . '/' . TemplateFileStore::teacherScope(140));
    }

    private function radiceRel(): string
    {
        return '/storage/templates/verifiche';
    }

    public function testSiScriveNellaCartellaDeiDati(): void
    {
        self::assertSame($this->dati . $this->radiceRel(), TemplateFileStore::rootDir());
        self::assertStringStartsWith(
            $this->dati,
            BadgeStylePresetStore::rootDir(),
            'anche i preset del badge, che stanno nello stesso albero',
        );
    }

    public function testIlFileDelDocenteSiLeggeDallaCartellaDeiDati(): void
    {
        $scope = TemplateFileStore::teacherScope(77);
        $rel   = 'texCommon/verifica.sty';
        $this->scrivi(
            $this->dati . $this->radiceRel() . '/' . $scope . '/' . $rel,
            '% lo scostamento del docente 77',
        );

        self::assertSame('% lo scostamento del docente 77', TemplateFileStore::read($scope, $rel));
    }

    public function testUnFileFuoriDallaCartellaDeiDatiNonSiTrova(): void
    {
        // Controprova. Senza, la prova sopra passerebbe anche se il servizio
        // cercasse dappertutto — ed è proprio «cercare nel posto sbagliato»
        // il difetto che si sta misurando.
        $altrove = $this->dati . '-altrove';
        $scope   = TemplateFileStore::teacherScope(77);
        $this->scrivi(
            $altrove . $this->radiceRel() . '/' . $scope . '/texCommon/verifica.sty',
            '% non deve arrivare qui',
        );
        try {
            self::assertNull(
                TemplateFileStore::readRaw($scope, 'texCommon/verifica.sty'),
                'nello scope del docente non deve risultare niente',
            );
            // `read()` non torna null perché ricade su `_default`, che è
            // versionato e sta nell'immagine: quello che conta è che non
            // restituisca MAI il file piantato fuori dalla cartella dei dati.
            self::assertNotSame(
                '% non deve arrivare qui',
                TemplateFileStore::read($scope, 'texCommon/verifica.sty'),
            );
        } finally {
            $this->rimuovi($altrove);
        }
    }

    public function testLaBaseVersionataSiLeggeAncoraDallImmagine(): void
    {
        // Il verso opposto, ed è quello che rende la correzione non banale:
        // `_default/**` sono 17 file in git, li porta l'immagine, e devono
        // restare leggibili anche quando nei dati non c'è niente.
        $sulCodice = TemplateFileStore::rootDirCodice()
            . '/' . TemplateFileStore::SCOPE_DEFAULT . '/versioni/main_NOR.tex';
        if (!is_file($sulCodice)) {
            self::markTestSkipped('la base versionata non è in questa copia di lavoro');
        }

        self::assertIsString(
            TemplateFileStore::read(TemplateFileStore::SCOPE_DEFAULT, 'versioni/main_NOR.tex'),
            'senza niente nei dati, si legge la copia dell immagine',
        );
    }

    public function testIlDocenteVinceSulDefaultVersionato(): void
    {
        $scope = TemplateFileStore::teacherScope(140);
        $this->scrivi(
            $this->dati . $this->radiceRel() . '/' . $scope . '/versioni/main_NOR.tex',
            '% la versione del docente 140',
        );

        self::assertSame(
            '% la versione del docente 140',
            TemplateFileStore::read($scope, 'versioni/main_NOR.tex'),
        );
    }

    public function testScrivereMetteIlFileNeiDatiENonNelRepository(): void
    {
        $scope = TemplateFileStore::teacherScope(77);
        TemplateFileStore::write($scope, 'texCommon/intestazione.tex', '% salvato dalla prova');

        $atteso = $this->dati . $this->radiceRel() . '/' . $scope . '/texCommon/intestazione.tex';
        self::assertFileExists($atteso);
        self::assertFileDoesNotExist(
            TemplateFileStore::rootDirCodice() . '/' . $scope . '/texCommon/intestazione.tex',
            'nel repository non deve comparire niente',
        );
    }

    public function testIlPresetDelBadgeSiLeggeDaiDati(): void
    {
        $this->scrivi(
            $this->dati . $this->radiceRel() . '/t_77/badge_styles/prova.json',
            json_encode(['fill' => '#123456']),
        );

        self::assertContains(
            'prova',
            BadgeStylePresetStore::listAvailable('t_77'),
            'il preset salvato nei dati deve comparire fra quelli disponibili',
        );
    }

    public function testUnPresetFuoriDaiDatiNonCompare(): void
    {
        // Controprova del punto sopra.
        $altrove = $this->dati . '-altrove';
        $this->scrivi(
            $altrove . $this->radiceRel() . '/t_77/badge_styles/fantasma.json',
            json_encode(['fill' => '#123456']),
        );
        try {
            self::assertNotContains('fantasma', BadgeStylePresetStore::listAvailable('t_77'));
        } finally {
            $this->rimuovi($altrove);
        }
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
