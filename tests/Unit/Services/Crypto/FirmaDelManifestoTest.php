<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crypto;

use App\Services\Crypto\TeacherRecoveryService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La firma del manifest di un pacchetto: che cosa prova e che cosa no
 * (23/9/2026).
 *
 * TeacherRecoveryService::verifyManifestHmac ricalcola l'HMAC con il codice R
 * digitato da chi importa, senza database né chiave master. Prova che il
 * manifest non l'ha cambiato chi non conosce la R con cui è stato firmato;
 * non prova chi l'ha esportato (il docblock del servizio dice perché è
 * voluto: migrazione a un'altra istanza e reimport dopo la perdita della
 * chiave master). Qui, senza database: il pacchetto legittimo passa in ogni
 * forma in cui si digita il codice; con il pacchetto vero e un codice
 * sbagliato non passa; con il codice giusto e la firma di un'altra chiave non
 * passa; un manifest cambiato dopo la firma non passa; e un manifest firmato
 * con una chiave qualunque passa con quel codice, qualunque esportatore
 * dichiari (il limite scritto nel docblock, fissato qui perché non cambi
 * senza che qualcuno lo decida).
 *
 * La firma la calcola la prova da sé (HKDF sulla R, HMAC-SHA256 sul JSON con
 * le chiavi in ordine), non con il metodo del servizio: così fissa anche il
 * formato, e un cambio di derivazione, che renderebbe illeggibili i pacchetti
 * già esportati, la fa fallire. Il percorso con l'export vero e il controller
 * sta in tests/Integration/FirmaDelPacchettoTest.php.
 */
final class FirmaDelManifestoTest extends TestCase
{
    private function servizio(): TeacherRecoveryService
    {
        // Una chiave master qualunque: la verifica non la usa, ma senza il
        // servizio leggerebbe quella dell'ambiente.
        return new TeacherRecoveryService(str_repeat('ab', 32));
    }

    /** @return array<string, mixed> */
    private static function manifesto(): array
    {
        return [
            'version'           => 1,
            'exported_at'       => '2026-09-23T10:00:00+02:00',
            'exporter_user_id'  => 77,
            'exporter_username' => 'docente.prova',
            'institute_code'    => 'ZZ',
            'files'             => [
                ['path' => 'ZZ/SCI/2A/FIS/mappe/Cinematica.drawio', 'size' => 120, 'sha256' => str_repeat('a', 64), 'type' => 'mappa'],
                ['path' => 'ZZ/SCI/2A/MAT/verifiche/Funzioni/v1/MAT-funzioni-_-SOL.tex', 'size' => 4000, 'sha256' => str_repeat('b', 64), 'type' => 'verifica-tex'],
            ],
        ];
    }

    /** @param array<mixed> $v */
    private static function ordinato(array $v): array
    {
        if (array_keys($v) !== range(0, \count($v) - 1)) {
            ksort($v);
        }
        foreach ($v as $k => $figlio) {
            if (\is_array($figlio)) {
                $v[$k] = self::ordinato($figlio);
            }
        }
        return $v;
    }

    /** @param array<string, mixed> $payload */
    private static function firma(string $r, array $payload): string
    {
        $chiave = hash_hkdf('sha256', $r, 32, 'manifest-hmac', 'pantedu-recovery-key-v1');
        $testo = (string)json_encode(self::ordinato($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return base64_encode(hash_hmac('sha256', $testo, $chiave, true));
    }

    /** Base32 RFC 4648 senza padding, come lo mostra la pagina della chiave. */
    private static function base32(string $bin): string
    {
        $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bit = '';
        foreach (str_split($bin) as $c) {
            $bit .= str_pad(decbin(\ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bit, 5) as $pezzo) {
            $out .= $alfabeto[bindec(str_pad($pezzo, 5, '0'))];
        }
        return $out;
    }

    /** @return iterable<string, array{callable(string): string}> */
    public static function formeDelCodice(): iterable
    {
        yield 'esadecimale maiuscolo' => [static fn (string $r): string => strtoupper(bin2hex($r))];
        yield 'esadecimale minuscolo a gruppi' => [static fn (string $r): string => implode(' ', str_split(bin2hex($r), 8))];
        yield 'esadecimale con i trattini' => [static fn (string $r): string => implode('-', str_split(bin2hex($r), 16))];
        yield 'base32' => [static fn (string $r): string => self::base32($r)];
        yield 'base32 minuscolo con il padding' => [static fn (string $r): string => strtolower(self::base32($r)) . '===='];
    }

    /** @param callable(string): string $forma */
    #[Test]
    #[DataProvider('formeDelCodice')]
    public function il_pacchetto_firmato_con_la_chiave_dell_esportatore_passa(callable $forma): void
    {
        $r = random_bytes(32);
        $m = self::manifesto();

        self::assertTrue($this->servizio()->verifyManifestHmac($forma($r), $m, self::firma($r, $m)));
    }

    #[Test]
    public function il_pacchetto_vero_con_un_codice_sbagliato_non_passa(): void
    {
        $r = random_bytes(32);
        $m = self::manifesto();
        $firma = self::firma($r, $m);

        self::assertFalse($this->servizio()->verifyManifestHmac(bin2hex(random_bytes(32)), $m, $firma));
        // La chiave giusta con un solo bit diverso.
        $quasi = $r;
        $quasi[31] = \chr(\ord($quasi[31]) ^ 1);
        self::assertFalse($this->servizio()->verifyManifestHmac(bin2hex($quasi), $m, $firma));
    }

    #[Test]
    public function la_firma_non_dice_chi_ha_esportato(): void
    {
        // Il limite voluto (docblock di TeacherRecoveryService, «Verifica
        // import»): una R qualunque, che il server non conosce, firma un
        // manifest che si dice di un esportatore qualunque, e con quel codice
        // passa. È lo stesso percorso di un pacchetto portato da un'altra
        // istanza.
        $qualunque = random_bytes(32);
        $m = self::manifesto();
        $m['exporter_user_id'] = 1;
        $m['exporter_username'] = 'un.altro';

        self::assertTrue($this->servizio()->verifyManifestHmac(bin2hex($qualunque), $m, self::firma($qualunque, $m)));
    }

    #[Test]
    public function il_codice_giusto_con_la_firma_di_un_altra_chiave_non_passa(): void
    {
        $r = random_bytes(32);
        $m = self::manifesto();

        self::assertFalse($this->servizio()->verifyManifestHmac(bin2hex($r), $m, self::firma(random_bytes(32), $m)));
    }

    /** @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>}> */
    public static function modifiche(): iterable
    {
        yield 'l\'impronta di un file' => [static function (array $m): array {
            $m['files'][0]['sha256'] = str_repeat('c', 64);
            return $m;
        }];
        yield 'un file in più' => [static function (array $m): array {
            $m['files'][] = ['path' => 'ZZ/SCI/2A/FIS/mappe/Altra.drawio', 'size' => 1, 'sha256' => str_repeat('d', 64), 'type' => 'mappa'];
            return $m;
        }];
        yield 'un file in meno' => [static function (array $m): array {
            array_pop($m['files']);
            return $m;
        }];
        yield 'l\'esportatore' => [static function (array $m): array {
            $m['exporter_user_id'] = 78;
            return $m;
        }];
        yield 'la data dell\'export' => [static function (array $m): array {
            $m['exported_at'] = '2026-09-24T10:00:00+02:00';
            return $m;
        }];
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $cambia */
    #[Test]
    #[DataProvider('modifiche')]
    public function un_manifesto_cambiato_dopo_la_firma_non_passa(callable $cambia): void
    {
        $r = random_bytes(32);
        $firma = self::firma($r, self::manifesto());

        self::assertFalse($this->servizio()->verifyManifestHmac(bin2hex($r), $cambia(self::manifesto()), $firma));
    }

    #[Test]
    public function l_ordine_delle_chiavi_non_conta(): void
    {
        $r = random_bytes(32);
        $m = self::manifesto();
        $firma = self::firma($r, $m);
        $rovescio = array_reverse($m, true);
        $rovescio['files'] = array_map(static fn (array $f): array => array_reverse($f, true), $m['files']);

        self::assertTrue($this->servizio()->verifyManifestHmac(bin2hex($r), $rovescio, $firma));
    }

    /** @return iterable<string, array{string}> */
    public static function codiciMalformati(): iterable
    {
        yield 'vuoto' => [''];
        yield 'esadecimale corto' => [str_repeat('a', 63)];
        yield 'esadecimale lungo' => [str_repeat('a', 66)];
        yield 'carattere non esadecimale' => [str_repeat('a', 63) . 'g'];
        yield 'base32 corto' => [str_repeat('A', 51)];
        yield 'base32 con un 1' => [str_repeat('A', 51) . '1'];
    }

    #[Test]
    #[DataProvider('codiciMalformati')]
    public function un_codice_malformato_non_passa_e_non_si_legge(string $codice): void
    {
        $r = random_bytes(32);
        $m = self::manifesto();

        self::assertNull($this->servizio()->parseRecoveryCode($codice));
        self::assertFalse($this->servizio()->verifyManifestHmac($codice, $m, self::firma($r, $m)));
    }

    #[Test]
    public function il_codice_si_legge_in_esadecimale_e_in_base32(): void
    {
        $s = $this->servizio();

        // Vettori fissi: 32 byte a zero e 32 byte a uno.
        self::assertSame(str_repeat("\0", 32), $s->parseRecoveryCode(str_repeat('A', 52)));
        self::assertSame(str_repeat("\xFF", 32), $s->parseRecoveryCode(str_repeat('7', 51) . 'Q'));
        self::assertSame(str_repeat("\xFF", 32), $s->parseRecoveryCode(str_repeat('f', 64)));
        $r = random_bytes(32);
        self::assertSame($r, $s->parseRecoveryCode(self::base32($r)));
        self::assertSame($r, $s->parseRecoveryCode(bin2hex($r)));
    }
}
