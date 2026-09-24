<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Middleware\WafMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Lo script del controllo anti-bot non resta in cache dopo un rilascio.
 *
 * Il 22/9/2026, misurato con un browser su pantedu.eu: dopo il rilascio che
 * riduceva l'impronta del dispositivo, i visitatori ricevevano ancora lo script
 * di **prima**. `cf-cache-status: HIT`, `age: 9861`, `cache-control:
 * max-age=86400`; la stessa richiesta con un parametro in coda restituiva il
 * file nuovo. L'origine era aggiornata, la cache di bordo no.
 *
 * Non è un problema di prestazioni. L'informativa dichiarava che da quella data
 * certi valori non si leggono più, e per ventiquattro ore non era vero per chi
 * aveva la copia in cache: **una correzione sulla privacy che non arriva a chi
 * si vuole proteggere non è una correzione.**
 *
 * Questa prova guarda che l'indirizzo porti una versione, e che la versione
 * segua il file. Non prova la cache di Cloudflare, che non è qui: prova la
 * condizione senza la quale nessuna cache può essere invalidata.
 */
final class ImprontaNonRestaInCacheTest extends TestCase
{
    private static function indirizzo(): string
    {
        $m = new ReflectionMethod(WafMiddleware::class, 'indirizzoDelloScript');
        $m->setAccessible(true);

        return (string)$m->invoke(null);
    }

    #[Test]
    public function l_indirizzo_porta_una_versione(): void
    {
        $url = self::indirizzo();

        self::assertStringStartsWith('/js/waf/fingerprint.js', $url,
            'lo script resta quello');
        self::assertMatchesRegularExpression('#^/js/waf/fingerprint\.js\?v=\d+$#', $url,
            "senza una versione in coda la cache di bordo serve la copia vecchia "
            . "anche dopo un rilascio (misurato il 22/9/2026: age 9861 su max-age 86400)");
    }

    #[Test]
    public function la_versione_e_quella_del_file_servito(): void
    {
        // Il verso che conta davvero: la versione non dev'essere un numero
        // qualunque, ma seguire il file. Una costante, o un numero casuale,
        // passerebbero la prova sopra e non servirebbero — la prima non
        // cambierebbe mai, il secondo cambierebbe a ogni richiesta e
        // renderebbe la cache inutile.
        $file = dirname(__DIR__, 2) . '/js/waf/fingerprint.js';
        self::assertFileExists($file);

        $url = self::indirizzo();
        preg_match('/\?v=(\d+)$/', $url, $m);

        self::assertSame((string)filemtime($file), $m[1] ?? '',
            'la versione è il tempo di modifica del file, così cambia quando cambia lui');
    }

    #[Test]
    public function due_letture_di_seguito_danno_lo_stesso_indirizzo(): void
    {
        // Se cambiasse a ogni richiesta, nessun visitatore terrebbe mai lo
        // script in cache e il costo ricadrebbe su ogni pagina servita.
        self::assertSame(self::indirizzo(), self::indirizzo(),
            "l'indirizzo è stabile finché il file non cambia");
    }
}
