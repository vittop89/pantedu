<?php

declare(strict_types=1);

namespace Tests\Unit\Percorsi;

use App\Controllers\Risdoc\TemplateController;
use App\Core\Config;
use App\Core\Request;
use App\Services\Risdoc\Pt\PtToTex;
use App\Services\Risdoc\TexBuilder;
use PHPUnit\Framework\TestCase;

/**
 * I sorgenti opzioni risdoc si leggono in CASCATA: prima i dati d'istanza,
 * poi l'immagine.
 *
 * Dal 20/9/2026 `RisdocAdminController` SCRIVE questi file nella cartella dei
 * dati, perché prima li scriveva sopra l'immagine e il rilascio successivo li
 * buttava via. Chi li legge però stava ancora sulla sola immagine: il
 * pannello dell'amministratore e `/api/risdoc/curriculum-options` mostravano
 * il testo nuovo, il TeX generato usava le etichette vecchie, e un file NUOVO
 * non compariva mai nel catalogo del selettore. Nessun errore da nessuna
 * parte, e la divergenza non si sarebbe più richiusa da sola.
 *
 * Quattro lettori, quattro prove, ognuna nei due versi: con la cartella dei
 * dati VUOTA si legge l'immagine (la base versionata non deve diventare
 * illeggibile), con la copia nei dati si legge quella.
 */
final class SorgentiOpzioniRisdocInCascataTest extends TestCase
{
    /** Un file dell'albero versionato: 115 file in git, questo c'è dal 2024. */
    private const REL = 'competenze_DM2007/competenze_DM2007.json';

    private string $dati;
    private string $repository;
    private mixed $datiPrima;

    protected function setUp(): void
    {
        $this->repository = \dirname(__DIR__, 3);
        $this->dati = sys_get_temp_dir() . '/pantedu-cascata-' . bin2hex(random_bytes(6));
        mkdir($this->dati, 0775, true);
        $this->datiPrima = Config::get('app.paths.data_base');
        Config::set('app.paths.data_base', $this->dati);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        Config::set('app.paths.data_base', $this->datiPrima);
        $this->rimuovi($this->dati);
        $_SESSION = [];
    }

    /** Scrive nei dati una copia con un'etichetta riconoscibile. */
    private function copiaModificataNeiDati(string $rel, string $etichetta): void
    {
        $abs = $this->dati . '/storage/templates/risdoc/' . $rel;
        if (!is_dir(\dirname($abs))) {
            mkdir(\dirname($abs), 0775, true);
        }
        file_put_contents($abs, json_encode(
            [['titolo' => 'Asse di prova', 'contenuti' => [['label' => $etichetta, 'checked' => false]]]],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
        ));
    }

    /** @return list<string> le etichette che il lettore ha tirato fuori */
    private function etichette(array $opzioni): array
    {
        return array_values(array_map(
            static fn(array $o): string => (string)($o['label'] ?? $o['value'] ?? ''),
            $opzioni,
        ));
    }

    private function texBuilder(): TexBuilder
    {
        return new TexBuilder($this->repository . '/schemas/risdoc/glossario.json', $this->repository);
    }

    private function fetchTexBuilder(): array
    {
        $m = new \ReflectionMethod(TexBuilder::class, 'fetchOptionsSource');
        return (array)$m->invoke($this->texBuilder(), ['file' => self::REL], []);
    }

    private function fetchPtToTex(): array
    {
        $m = new \ReflectionMethod(PtToTex::class, 'fetchOptionsSource');
        return (array)$m->invoke(null, ['file' => self::REL]);
    }

    public function testTexBuilderConDatiVuotiLeggeLImmagine(): void
    {
        $etichette = $this->etichette($this->fetchTexBuilder());

        self::assertNotSame([], $etichette, 'la base versionata deve restare leggibile');
        self::assertStringContainsString('Padroneggiare gli strumenti espressivi', $etichette[0]);
    }

    public function testTexBuilderLeggeLaCopiaNeiDati(): void
    {
        $this->copiaModificataNeiDati(self::REL, 'ETICHETTA DELL ISTANZA');

        self::assertSame(['ETICHETTA DELL ISTANZA'], $this->etichette($this->fetchTexBuilder()));
    }

    public function testPtToTexConDatiVuotiLeggeLImmagine(): void
    {
        $etichette = $this->etichette($this->fetchPtToTex());

        self::assertNotSame([], $etichette);
        self::assertStringContainsString('Padroneggiare gli strumenti espressivi', $etichette[0]);
    }

    public function testPtToTexLeggeLaCopiaNeiDati(): void
    {
        $this->copiaModificataNeiDati(self::REL, 'ETICHETTA DELL ISTANZA');

        self::assertSame(['ETICHETTA DELL ISTANZA'], $this->etichette($this->fetchPtToTex()));
    }

    public function testIlCatalogoDelSelettoreVedeUnFileNuovoCreatoDallAmministratore(): void
    {
        // Lo scenario del rilievo: l'amministratore crea un sorgente nuovo dal
        // pannello (`create`), che finisce solo nei dati. Guardando la sola
        // immagine non sarebbe mai comparso nel selettore del PT editor, e il
        // docente non avrebbe potuto sceglierlo.
        $this->comeSuperAdmin();
        $prima = $this->catalogo();
        self::assertNotContains('sonda_istanza/sonda.json', $prima['percorsi']);
        self::assertContains(self::REL, $prima['percorsi'], 'i file dell\'immagine restano nel catalogo');

        $this->copiaModificataNeiDati('sonda_istanza/sonda.json', 'nuova');
        $dopo = $this->catalogo();

        self::assertContains('sonda_istanza/sonda.json', $dopo['percorsi']);
        self::assertContains(self::REL, $dopo['percorsi']);
        self::assertSame(
            count($prima['percorsi']) + 1,
            count($dopo['percorsi']),
            'un file che sta in tutte e due le radici si conta una volta sola',
        );
    }

    public function testLaRottaLegacyServeLaCopiaNeiDatiEPoiQuellaDellImmagine(): void
    {
        // `GET /risdoc/{path*}`: è la rotta che usa il fetcher delle opzioni
        // in modalità `file`. Si prova con un percorso non .json perché la
        // ricerca dell'override per-docente vuole il database, che la suite
        // `unit` non ha: la riga che risolve il file è la stessa.
        $this->comeSuperAdmin();
        $rel = 'texCommon/main.tex';

        $dellImmagine = $this->rottaLegacy($rel);
        self::assertSame(
            (string)file_get_contents($this->repository . '/storage/templates/risdoc/' . $rel),
            $dellImmagine,
        );

        $abs = $this->dati . '/storage/templates/risdoc/' . $rel;
        mkdir(\dirname($abs), 0775, true);
        file_put_contents($abs, '% copia dell istanza');

        self::assertSame('% copia dell istanza', $this->rottaLegacy($rel));
    }

    public function testLaRottaLegacyRispondeQuattrocentoquattroSeNonCeNeDiQuaNeDiLa(): void
    {
        $this->comeSuperAdmin();
        $r = $this->rottaLegacyResponse('non/esiste/affatto.tex');

        self::assertSame(404, $r->status);
    }

    private function catalogo(): array
    {
        $r = (new TemplateController())->optionsSources($this->request());
        $corpo = json_decode((string)$r->body, true);
        self::assertIsArray($corpo, 'il catalogo deve rispondere con un JSON');

        return ['percorsi' => array_column($corpo['files'] ?? [], 'path')];
    }

    private function rottaLegacy(string $rel): string
    {
        $r = $this->rottaLegacyResponse($rel);
        self::assertSame(200, $r->status, "la rotta deve servire «{$rel}»");

        return (string)$r->body;
    }

    private function rottaLegacyResponse(string $rel): \App\Core\Response
    {
        return (new TemplateController())->legacyPath($this->request(), ['path' => $rel]);
    }

    private function request(): Request
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/risdoc/prova';

        return new Request();
    }

    /**
     * Le due rotte vogliono un docente o il super-admin. Senza database
     * `Permission::currentTeacherId()` vale 0, quindi si semina la cache di
     * sessione del super-admin come fanno gli altri unit test dei gate.
     */
    private function comeSuperAdmin(): void
    {
        $username = 'prova_' . bin2hex(random_bytes(4));
        $_SESSION['autenticato'] = true;
        $_SESSION['username']    = $username;
        $_SESSION['user_id']     = 999;
        $_SESSION['user_role']   = 'administrator';
        // I claims della sessione, letti adesso (Auth::CLAIMS_TTL_SECONDS).
        $_SESSION['is_super_admin'] = true;
        $_SESSION['claims_at']      = time();
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
