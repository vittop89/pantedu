<?php
declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\ClassKeychainCookie;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il cookie «ricorda su questo dispositivo» (piano classi, C): la parte pura,
 * firma e verifica, senza header ne' sessione.
 */
final class ClassKeychainCookieTest extends TestCase
{
    private const SECRET = 'segreto-di-prova-lungo-abbastanza';

    #[Test]
    public function il_valore_firmato_si_rilegge(): void
    {
        $exp = 2_000_000_000;
        $v = ClassKeychainCookie::payload([7, 12, 7], $exp, self::SECRET);
        $p = ClassKeychainCookie::parse($v, 1_900_000_000, self::SECRET);
        self::assertNotNull($p);
        self::assertSame([7, 12], $p['ids'], 'senza ripetizioni');
        self::assertSame($exp, $p['exp']);
    }

    #[Test]
    public function un_valore_manomesso_non_vale(): void
    {
        $v = ClassKeychainCookie::payload([7], 2_000_000_000, self::SECRET);
        [$body, $sig] = explode('.', $v, 2);
        $altro = ClassKeychainCookie::payload([8], 2_000_000_000, self::SECRET);
        [$bodyAltro] = explode('.', $altro, 2);
        self::assertNull(ClassKeychainCookie::parse($bodyAltro . '.' . $sig, 1_900_000_000, self::SECRET), 'corpo cambiato');
        self::assertNull(ClassKeychainCookie::parse($body . '.' . strrev($sig), 1_900_000_000, self::SECRET), 'firma cambiata');
        self::assertNull(ClassKeychainCookie::parse($v, 1_900_000_000, 'altro-segreto'), 'altro segreto');
        self::assertNull(ClassKeychainCookie::parse('senza-punto', 1_900_000_000, self::SECRET));
        self::assertNull(ClassKeychainCookie::parse('', 1_900_000_000, self::SECRET));
    }

    #[Test]
    public function scaduto_non_vale(): void
    {
        $v = ClassKeychainCookie::payload([7], 1_000, self::SECRET);
        self::assertNull(ClassKeychainCookie::parse($v, 1_001, self::SECRET));
        self::assertNotNull(ClassKeychainCookie::parse($v, 999, self::SECRET));
    }

    #[Test]
    public function senza_segreto_niente_firma_valida(): void
    {
        $v = ClassKeychainCookie::payload([7], 2_000_000_000, '');
        self::assertNull(ClassKeychainCookie::parse($v, 1_900_000_000, ''));
    }

    #[Test]
    public function id_non_validi_scartati(): void
    {
        $v = ClassKeychainCookie::payload([0, -3, 5], 2_000_000_000, self::SECRET);
        self::assertSame([5], ClassKeychainCookie::parse($v, 1_900_000_000, self::SECRET)['ids']);
        $vuoto = ClassKeychainCookie::payload([0], 2_000_000_000, self::SECRET);
        self::assertNull(ClassKeychainCookie::parse($vuoto, 1_900_000_000, self::SECRET), 'nessun id valido: come assente');
    }
}
