<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crypto;

use App\Services\Crypto\TeacherRecoveryService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Il codice di recupero e la firma del manifesto (23/9/2026).
 *
 * All'importazione di un pacchetto di verifiche il docente digita il suo
 * codice di recupero, e il server accetta il manifesto solo se l'HMAC che porta
 * è quello calcolato con quel codice (`ImportBundleController`). Due funzioni
 * pure, senza database e senza la chiave del KMS: `parseRecoveryCode()`, che
 * legge il codice nelle forme in cui lo si trascrive dal PDF, e
 * `verifyManifestHmac()`. Nessuna prova le sorvegliava (revisione del
 * 23/9/2026, A-24).
 *
 * Il vettore di prova è calcolato FUORI da PHP, con la libreria standard di
 * Python (HKDF-SHA256 scritto a mano, HMAC-SHA256, JSON con le chiavi in
 * ordine): se la formula cambia, i manifesti già esportati non si importano
 * più, e questa prova lo dice. Il codice del vettore sono i byte da 0 a 31:
 * non è la chiave di nessuno.
 *
 * Il servizio si costruisce con una chiave del KMS vuota: le due funzioni non
 * la usano, e così la prova non legge l'ambiente.
 */
final class TeacherRecoveryServiceTest extends TestCase
{
    private const CODICE_HEX = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';
    private const CODICE_B32 = 'AAAQEAYEAUDAOCAJBIFQYDIOB4IBCEQTCQKRMFYYDENBWHA5DYPQ';
    private const FIRMA      = 'R+T2AuEMJyKoTlyWdVoMtr+ApkirGYTgefyIhHEs614=';

    private TeacherRecoveryService $servizio;

    protected function setUp(): void
    {
        $this->servizio = new TeacherRecoveryService('');
    }

    /** Il manifesto del vettore, com'è prima della firma. @return array<string,mixed> */
    private static function manifesto(): array
    {
        return [
            'version'           => 1,
            'exported_at'       => '2026-09-23T10:00:00+02:00',
            'exporter_user_id'  => 4242,
            'exporter_username' => 'zz.prova.recupero',
            'institute_code'    => 'ZZTEST00',
            'files'             => [
                ['path' => 'verifiche/zz/prova.json', 'size' => 1234, 'sha256' => str_repeat('ab', 32)],
                ['path' => 'verifiche/zz/città.tex', 'size' => 99, 'sha256' => str_repeat('cd', 32)],
            ],
        ];
    }

    // ── parseRecoveryCode ───────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function codiciValidi(): array
    {
        $hex = self::CODICE_HEX;
        $b32 = self::CODICE_B32;
        return [
            'esadecimale minuscolo'     => [$hex],
            'esadecimale maiuscolo'     => [strtoupper($hex)],
            'esadecimale a gruppi'      => [implode(' ', str_split($hex, 8))],
            'esadecimale coi trattini'  => [implode('-', str_split($hex, 4))],
            'esadecimale con a capo'    => ["\n  " . $hex . "\t\n"],
            'base32'                    => [$b32],
            'base32 col riempimento'    => [$b32 . '===='],
            'base32 minuscolo'          => [strtolower($b32)],
            'base32 a gruppi'           => [implode('-', str_split($b32, 4))],
        ];
    }

    #[Test]
    #[DataProvider('codiciValidi')]
    public function un_codice_trascritto_in_una_forma_ammessa_si_legge(string $codice): void
    {
        self::assertSame(hex2bin(self::CODICE_HEX), $this->servizio->parseRecoveryCode($codice));
    }

    /** @return array<string, array{string}> */
    public static function codiciNonValidi(): array
    {
        $hex = self::CODICE_HEX;
        $b32 = self::CODICE_B32;
        return [
            'vuoto'                       => [''],
            'solo spazi'                  => ['   '],
            'esadecimale di 63'           => [substr($hex, 0, 63)],
            'esadecimale di 65'           => [$hex . 'a'],
            'esadecimale con una G'       => ['g' . substr($hex, 1)],
            'esadecimale coi due punti'   => [implode(':', str_split($hex, 2))],
            'base32 di 51'                => [substr($b32, 0, 51)],
            'base32 di 53'                => [$b32 . 'A'],
            'base32 con un 1'             => ['1' . substr($b32, 1)],
            'base32 con un 8'             => [substr($b32, 0, 20) . '8' . substr($b32, 21)],
            'base64 dello stesso codice'  => [base64_encode((string)hex2bin($hex))],
        ];
    }

    #[Test]
    #[DataProvider('codiciNonValidi')]
    public function un_codice_malformato_non_si_legge(string $codice): void
    {
        self::assertNull($this->servizio->parseRecoveryCode($codice));
    }

    // ── verifyManifestHmac ──────────────────────────────────────────────

    #[Test]
    public function la_firma_calcolata_fuori_da_php_si_verifica(): void
    {
        self::assertTrue($this->servizio->verifyManifestHmac(self::CODICE_HEX, self::manifesto(), self::FIRMA));
        self::assertTrue(
            $this->servizio->verifyManifestHmac(self::CODICE_B32, self::manifesto(), self::FIRMA),
            'lo stesso codice in base32',
        );
    }

    /** Il manifesto è canonico: l'ordine delle chiavi, anche annidate, non conta. */
    #[Test]
    public function l_ordine_delle_chiavi_non_conta(): void
    {
        $m = self::manifesto();
        $rovescio = array_reverse($m, true);
        $rovescio['files'] = array_map(
            static fn(array $f): array => array_reverse($f, true),
            $rovescio['files'],
        );

        self::assertTrue($this->servizio->verifyManifestHmac(self::CODICE_HEX, $rovescio, self::FIRMA));
    }

    /**
     * L'ordine degli elementi di una lista, invece, conta: è un altro manifesto.
     */
    #[Test]
    public function l_ordine_dei_file_conta(): void
    {
        $m = self::manifesto();
        $m['files'] = array_reverse($m['files']);

        self::assertFalse($this->servizio->verifyManifestHmac(self::CODICE_HEX, $m, self::FIRMA));
    }

    /** @return array<string, array{callable(array<string,mixed>): array<string,mixed>}> */
    public static function manomissioni(): array
    {
        return [
            'una cifra della dimensione' => [static function (array $m): array {
                $m['files'][0]['size'] = 1235;
                return $m;
            }],
            'un carattere del percorso' => [static function (array $m): array {
                $m['files'][1]['path'] = 'verifiche/zz/citta.tex';
                return $m;
            }],
            'un byte dell\'impronta' => [static function (array $m): array {
                $m['files'][0]['sha256'][0] = 'b';
                return $m;
            }],
            'la data di esportazione' => [static function (array $m): array {
                $m['exported_at'] = '2026-09-23T10:00:01+02:00';
                return $m;
            }],
            'un file in più' => [static function (array $m): array {
                $m['files'][] = ['path' => 'verifiche/zz/altro.json', 'size' => 1, 'sha256' => str_repeat('ef', 32)];
                return $m;
            }],
            'un file in meno' => [static function (array $m): array {
                array_pop($m['files']);
                return $m;
            }],
            'un campo in più' => [static function (array $m): array {
                $m['note'] = '';
                return $m;
            }],
            'la firma dentro il manifesto' => [static function (array $m): array {
                $m['hmac'] = self::FIRMA;
                return $m;
            }],
            'un numero scritto come testo' => [static function (array $m): array {
                $m['exporter_user_id'] = '4242';
                return $m;
            }],
        ];
    }

    /** @param callable(array<string,mixed>): array<string,mixed> $manometti */
    #[Test]
    #[DataProvider('manomissioni')]
    public function un_manifesto_manomesso_non_si_verifica(callable $manometti): void
    {
        self::assertFalse($this->servizio->verifyManifestHmac(self::CODICE_HEX, $manometti(self::manifesto()), self::FIRMA));
    }

    /** Ogni byte della firma, con un bit rovesciato: nessuno deve passare. */
    #[Test]
    public function una_firma_con_un_bit_cambiato_non_si_verifica(): void
    {
        $grezza = (string)base64_decode(self::FIRMA, true);
        self::assertSame(32, strlen($grezza));

        for ($i = 0; $i < 32; $i++) {
            $alterata = $grezza;
            $alterata[$i] = chr(ord($alterata[$i]) ^ 0x01);
            self::assertFalse(
                $this->servizio->verifyManifestHmac(self::CODICE_HEX, self::manifesto(), base64_encode($alterata)),
                "byte {$i}",
            );
        }
    }

    /** Un codice diverso di un solo byte, in ciascuna posizione, non firma lo stesso manifesto. */
    #[Test]
    public function un_codice_con_un_byte_cambiato_non_verifica(): void
    {
        $grezzo = (string)hex2bin(self::CODICE_HEX);
        for ($i = 0; $i < 32; $i++) {
            $altro = $grezzo;
            $altro[$i] = chr(ord($altro[$i]) ^ 0x80);
            self::assertFalse(
                $this->servizio->verifyManifestHmac(bin2hex($altro), self::manifesto(), self::FIRMA),
                "byte {$i}",
            );
        }
    }

    /**
     * La firma è in base64 standard, come la produce
     * `signManifestForExporter()`: la variante per gli URL, senza
     * riempimento, o vuota, non passa. E un codice che non si legge non solleva:
     * risponde no.
     */
    #[Test]
    public function firme_in_altra_forma_e_codici_illeggibili_non_passano(): void
    {
        $m = self::manifesto();
        self::assertFalse($this->servizio->verifyManifestHmac(self::CODICE_HEX, $m, strtr(self::FIRMA, '+/', '-_')), 'base64 per gli URL');
        self::assertFalse($this->servizio->verifyManifestHmac(self::CODICE_HEX, $m, rtrim(self::FIRMA, '=')), 'senza riempimento');
        self::assertFalse($this->servizio->verifyManifestHmac(self::CODICE_HEX, $m, ''), 'vuota');
        self::assertFalse($this->servizio->verifyManifestHmac('', $m, self::FIRMA), 'codice vuoto');
        self::assertFalse($this->servizio->verifyManifestHmac('non-un-codice', $m, self::FIRMA), 'codice illeggibile');
    }

    /**
     * Il confronto è a tempo costante, come dichiara il commento del metodo.
     *
     * Il tempo non si misura in una prova unitaria senza farne una lotteria; si
     * controlla la cosa che lo garantisce: il confronto passa da
     * `hash_equals()`, con la firma attesa per prima, e non da `===`. Un
     * confronto con `===` farebbe passare tutte le prove qui sopra, e solo
     * questa lo ferma.
     */
    #[Test]
    public function il_confronto_passa_da_hash_equals(): void
    {
        $metodo = new ReflectionMethod(TeacherRecoveryService::class, 'verifyManifestHmac');
        $righe  = file((string)$metodo->getFileName());
        self::assertIsArray($righe);
        $corpo = implode('', array_slice(
            $righe,
            (int)$metodo->getStartLine() - 1,
            (int)$metodo->getEndLine() - (int)$metodo->getStartLine() + 1,
        ));

        self::assertMatchesRegularExpression('/hash_equals\(\s*\$expected\s*,\s*\$hmacB64\s*\)/', $corpo);
        self::assertDoesNotMatchRegularExpression('/\$hmacB64\s*[!=]==?|[!=]==?\s*\$hmacB64|strcmp\(/', $corpo);
    }
}
