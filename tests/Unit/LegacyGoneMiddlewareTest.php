<?php

namespace Tests\Unit;

use App\Core\Request;
use App\Core\Response;
use App\Middleware\LegacyGoneMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Le rotte legacy rispondono 410, o 302 verso /studio quando l'URL è di una
 * forma nota. Dal 23/9/2026 (revisione architetturale A-54) la destinazione si
 * calcola dal solo URL, senza database: che la risposta non cambi quando il
 * contenuto esiste lo prova tests/Integration/LegacyGoneSenzaOracoloTest.php.
 */
final class LegacyGoneMiddlewareTest extends TestCase
{
    private function mkReq(string $path, bool $wantsJson = false): Request
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = $path;
        foreach (array_keys($_SERVER) as $k) {
            if (str_starts_with($k, 'HTTP_')) unset($_SERVER[$k]);
        }
        if ($wantsJson) $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_GET  = [];
        $_POST = [];
        return new Request();
    }

    #[Test]
    public function unknown_legacy_root_returns_410(): void
    {
        $mw = new LegacyGoneMiddleware();
        $res = $mw->handle($this->mkReq('/strcomp_bes_altro/random/unknown.php'),
            fn($r) => Response::html('should not be reached'));
        $this->assertSame(410, $res->status);
    }

    #[Test]
    public function returns_json_410_when_client_wants_json(): void
    {
        $mw = new LegacyGoneMiddleware();
        $res = $mw->handle($this->mkReq('/lab/random/unknown', true),
            fn($r) => Response::html('should not be reached'));
        $this->assertSame(410, $res->status);
        $this->assertStringContainsString('gone', (string)$res->body);
    }

    /** @return iterable<string, array{string, string}> */
    public static function formeNote(): iterable
    {
        yield 'esercizio' => ['/eser/sc/eser_sc2s/MAT/1_MAT-Topic.php', '/studio/esercizio/sc/2s/MAT/Topic'];
        yield 'esercizio con la sezione in coda' => [
            '/eser/sc/eser_sc2s/MAT/2.0_MAT-Sistemi_lineari-sc2s.php', '/studio/esercizio/sc/2s/MAT/Sistemi%20lineari',
        ];
        yield 'mappa' => ['/mappe/li/mappe_li3/FIS/4_FIS-Moto_rettilineo.php', '/studio/mappa/li/3/FIS/Moto%20rettilineo'];
        yield 'didattica' => ['/didattica/sc/didattica_sc1/ITA/1_ITA-Poesia.php', '/studio/document/sc/1/ITA/Poesia'];
        yield 'verifica' => ['/verifiche/sc/sc2s/MAT/3_MAT-Equazioni.php', '/studio/verifica/sc/2s/MAT/Equazioni'];
    }

    #[Test]
    #[DataProvider('formeNote')]
    public function una_forma_nota_va_a_studio_senza_chiedere_al_database(string $percorso, string $destinazione): void
    {
        $mw = new LegacyGoneMiddleware();
        $res = $mw->handle($this->mkReq($percorso),
            fn($r) => Response::html('should not be reached'));

        $this->assertSame(302, $res->status);
        $this->assertSame($destinazione, $res->headers['Location'] ?? null);
    }
}
