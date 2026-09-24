<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Tikz;

use App\Core\Config;
use App\Services\Crypto\TeacherCryptoService;
use App\Services\TexCompile\TikzRenderClient;
use App\Services\Tikz\TikzRenderService;
use PHPUnit\Framework\TestCase;

/**
 * La cache delle figure TikZ sta nella cartella dei dati d'istanza
 * (`app.paths.data_base`, cioè PANTEDU_DATA_PATH), non nella radice del
 * repository.
 *
 * Il 19/9/2026 in produzione: dall'8/9 l'applicazione gira in un container, e
 * il servizio costruiva la cartella da `dirname(__DIR__, 3)`, la radice del
 * repository dentro l'immagine. Le figure compilate finivano nello strato
 * scrivibile del container (misurato con `docker diff`) e si perdevano a ogni
 * rilascio, e le 481 figure già compilate nella cartella dei dati non si
 * leggevano più: ogni visita ricompilava.
 */
final class CacheTikzNeiDatiTest extends TestCase
{
    private string $dati;
    private mixed $datiPrima;

    protected function setUp(): void
    {
        $this->dati = sys_get_temp_dir() . '/pantedu-cache-tikz-' . bin2hex(random_bytes(6));
        mkdir($this->dati, 0775, true);
        $this->datiPrima = Config::get('app.paths.data_base');
        Config::set('app.paths.data_base', $this->dati);
    }

    protected function tearDown(): void
    {
        Config::set('app.paths.data_base', $this->datiPrima);
        $this->rimuovi($this->dati);
    }

    private function servizio(?string $base = null): TikzRenderService
    {
        return new TikzRenderService(
            new TikzRenderClient('http://127.0.0.1:1', 'segreto-di-prova'),
            new TeacherCryptoService(bin2hex(random_bytes(32))),
            $base,
        );
    }

    public function testSenzaCartellaEsplicitaLeggeLaCacheDallaCartellaDeiDati(): void
    {
        $hash = hash('sha256', 'figura-' . bin2hex(random_bytes(8)));
        $cartella = $this->dati . '/storage/cache/tikz/public/' . substr($hash, 0, 2);
        mkdir($cartella, 0775, true);
        file_put_contents($cartella . '/' . $hash . '.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        self::assertSame(
            '<svg xmlns="http://www.w3.org/2000/svg"/>',
            $this->servizio()->lookup(TikzRenderService::SCOPE_PUBLIC, 0, $hash),
            'la figura nella cartella dei dati deve essere trovata',
        );
    }

    public function testUnaFiguraFuoriDallaCartellaDeiDatiNonSiTrova(): void
    {
        // Controprova: la stessa figura in un'altra cartella non conta. Se il
        // servizio cercasse ovunque, la prova sopra passerebbe anche sbagliando.
        $altrove = $this->dati . '-altrove';
        $hash = hash('sha256', 'figura-' . bin2hex(random_bytes(8)));
        $cartella = $altrove . '/storage/cache/tikz/public/' . substr($hash, 0, 2);
        mkdir($cartella, 0775, true);
        file_put_contents($cartella . '/' . $hash . '.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        try {
            self::assertNull($this->servizio()->lookup(TikzRenderService::SCOPE_PUBLIC, 0, $hash));
        } finally {
            $this->rimuovi($altrove);
        }
    }

    public function testUnaCartellaEsplicitaVinceSullaConfigurazione(): void
    {
        // Gli strumenti da riga di comando (tools/tex-compile-vps/smoke_php_e2e.php)
        // passano la cartella: deve restare quella.
        $esplicita = $this->dati . '-esplicita';
        $hash = hash('sha256', 'figura-' . bin2hex(random_bytes(8)));
        $cartella = $esplicita . '/storage/cache/tikz/public/' . substr($hash, 0, 2);
        mkdir($cartella, 0775, true);
        file_put_contents($cartella . '/' . $hash . '.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        try {
            self::assertNotNull($this->servizio($esplicita)->lookup(TikzRenderService::SCOPE_PUBLIC, 0, $hash));
            self::assertNull($this->servizio()->lookup(TikzRenderService::SCOPE_PUBLIC, 0, $hash));
        } finally {
            $this->rimuovi($esplicita);
        }
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
