<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Security;

use App\Services\Security\QrCode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Verifica il generatore QR scritto per togliere di mezzo quickchart.io.
 *
 * Un QR sbagliato e' peggio di nessun QR: l'utente inquadra, l'app non
 * riconosce nulla, e non c'e' modo di capire se il problema e' il codice, la
 * fotocamera o l'app. Questi test evitano di doverlo scoprire scansionando.
 *
 * Si controllano proprieta' vere, non la coerenza del codice con se stesso:
 *
 *  · `reed_solomon_remainder_is_zero` — il polinomio (dati ‖ correzione) deve
 *    essere divisibile per il generatore. E' la definizione stessa del codice
 *    Reed-Solomon: se l'implementazione fosse sbagliata il resto non sarebbe
 *    zero, e nessuno scanner correggerebbe un solo errore.
 *
 *  · `data_bits_survive_placement_and_mask` — i codeword riletti dalla matrice
 *    nello stesso ordine a zig-zag, tolta la maschera, devono tornare identici.
 *    Copre il percorso dove si annidano i bug veri: salto della colonna 6,
 *    contabilita' dei moduli riservati, inversione della maschera.
 *
 *  · `format_info_decodes_back` — le due copie ridondanti devono contenere lo
 *    stesso valore, e quel valore deve dire livello M e la maschera scelta.
 */
final class QrCodeTest extends TestCase
{
    /**
     * L'URI nella forma che TotpService::provisioningUri produce davvero,
     * parametri compresi. La versione precedente di questa costante era
     * piu' corta e cadeva in versione 6, che NON richiede l'informazione di
     * versione: il test non copriva quindi il caso reale, che sta in 7 o 8.
     */
    private const URI = 'otpauth://totp/Pantedu:superadmin'
        . '?secret=VX3GYQ53UG7SQTR3SE3N7G5XTWQ6X2MS&issuer=Pantedu'
        . '&algorithm=SHA1&digits=6&period=30';

    /** @return array{0:object,1:\ReflectionClass<QrCode>} */
    private function fresh(): array
    {
        $rc  = new ReflectionClass(QrCode::class);
        $obj = $rc->newInstanceWithoutConstructor();
        return [$obj, $rc];
    }

    private function call(object $obj, ReflectionClass $rc, string $method, array $args = []): mixed
    {
        $m = $rc->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($obj, $args);
    }

    private function prop(object $obj, ReflectionClass $rc, string $name): mixed
    {
        $p = $rc->getProperty($name);
        $p->setAccessible(true);
        return $p->getValue($obj);
    }

    #[Test]
    public function svg_is_well_formed_and_square(): void
    {
        $svg = QrCode::svg(self::URI);

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringEndsWith('</svg>', $svg);
        self::assertStringContainsString('role="img"', $svg, 'serve un nome accessibile');
        self::assertStringContainsString('aria-label=', $svg);

        preg_match('/width="(\d+)" height="(\d+)"/', $svg, $m);
        self::assertNotEmpty($m, 'dimensioni assenti');
        self::assertSame($m[1], $m[2], 'un QR e\' quadrato');

        // Nessuna richiesta di rete: e' il motivo per cui questa classe esiste.
        // L'unico URL ammesso e' il namespace SVG, che non e' un fetch.
        self::assertStringNotContainsString('quickchart', $svg);
        self::assertStringNotContainsString('<image', $svg, 'nessuna immagine remota');
        self::assertStringNotContainsString('href', $svg, 'nessun riferimento esterno');
        self::assertSame(
            1,
            preg_match_all('~https?://~', $svg),
            'l unico URL deve essere xmlns; qualsiasi altro sarebbe una fuga di rete'
        );
        self::assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $svg);
    }

    #[Test]
    public function the_secret_never_leaves_the_page(): void
    {
        // L'SVG contiene moduli, non testo: il segreto non deve comparire in
        // chiaro nel markup ne' finire in un attributo.
        $svg = QrCode::svg(self::URI);
        self::assertStringNotContainsString('VX3GYQ53UG7SQTR3SE3N7G5XTWQ6X2MS', $svg);
        self::assertStringNotContainsString('otpauth', $svg);
    }

    #[Test]
    public function reed_solomon_remainder_is_zero(): void
    {
        [$obj, $rc] = $this->fresh();

        $data   = array_map(fn ($i) => ($i * 37 + 11) % 256, range(0, 15));
        $eccLen = 10;
        $ecc    = $this->call($obj, $rc, 'reedSolomon', [$data, $eccLen]);

        self::assertCount($eccLen, $ecc);

        // Dividendo (dati ‖ correzione) per il generatore il resto deve essere
        // nullo: e' cio' che permette allo scanner di correggere gli errori.
        [$exp, $log] = $this->call($obj, $rc, 'galoisTables');
        $gen = [1];
        for ($i = 0; $i < $eccLen; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);
            foreach ($gen as $j => $c) {
                $next[$j]     ^= $c;
                $next[$j + 1] ^= $c === 0 ? 0 : $exp[($log[$c] + $i) % 255];
            }
            $gen = $next;
        }

        $rem = array_merge($data, $ecc);
        for ($i = 0, $n = count($data); $i < $n; $i++) {
            $f = $rem[$i];
            if ($f === 0) {
                continue;
            }
            foreach ($gen as $j => $c) {
                if ($c !== 0) {
                    $rem[$i + $j] ^= $exp[($log[$c] + $log[$f]) % 255];
                }
            }
        }
        self::assertSame(
            array_fill(0, $eccLen, 0),
            array_slice($rem, count($data)),
            'il resto non e\' zero: la correzione errori non funzionerebbe'
        );
    }

    #[Test]
    public function finder_and_timing_patterns_are_in_place(): void
    {
        [$obj, $rc] = $this->fresh();
        $this->call($obj, $rc, 'build', [self::URI]);
        $m    = $this->prop($obj, $rc, 'modules');
        $size = $this->prop($obj, $rc, 'size');

        self::assertSame(17 + 4 * $this->prop($obj, $rc, 'version'), $size);

        // Il cuore 3x3 di ciascun pattern di posizionamento e' pieno, e
        // l'anello attorno e' bianco su tutti e quattro i lati.
        foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$ox, $oy]) {
            self::assertSame(1, $m[$oy + 3][$ox + 3], 'centro del finder');
            self::assertSame(0, $m[$oy + 1][$ox + 1], 'anello bianco');
            self::assertSame(1, $m[$oy][$ox], 'angolo del finder');
        }

        // Temporizzazione: alternanza esatta sulla riga e sulla colonna 6.
        for ($i = 8; $i < $size - 8; $i++) {
            self::assertSame($i % 2 === 0 ? 1 : 0, $m[6][$i], "timing orizzontale in $i");
            self::assertSame($i % 2 === 0 ? 1 : 0, $m[$i][6], "timing verticale in $i");
        }

        // Modulo scuro: sempre nero, per definizione.
        self::assertSame(1, $m[$size - 8][8]);
    }

    #[Test]
    public function format_info_decodes_back(): void
    {
        [$obj, $rc] = $this->fresh();
        $this->call($obj, $rc, 'build', [self::URI]);
        $m    = $this->prop($obj, $rc, 'modules');
        $size = $this->prop($obj, $rc, 'size');

        // Ricompone i 15 bit dalla prima copia e toglie la maschera 0x5412.
        $bits = 0;
        for ($i = 0; $i < 15; $i++) {
            if ($i < 6)        { $b = $m[8][$i]; }
            elseif ($i === 6)  { $b = $m[8][7]; }
            elseif ($i === 7)  { $b = $m[8][8]; }
            elseif ($i === 8)  { $b = $m[7][8]; }
            else               { $b = $m[14 - $i][8]; }
            $bits |= $b << $i;
        }
        $raw   = $bits ^ 0x5412;
        $level = ($raw >> 13) & 0b11;
        $mask  = ($raw >> 10) & 0b111;

        self::assertSame(0b00, $level, 'livello di correzione M');
        self::assertGreaterThanOrEqual(0, $mask);
        self::assertLessThanOrEqual(7, $mask);

        // La seconda copia deve dire la stessa cosa: e' la ridondanza che
        // permette la lettura quando un angolo del simbolo e' rovinato.
        $bits2 = 0;
        for ($i = 0; $i < 15; $i++) {
            $b = $i < 8 ? $m[8][$size - 1 - $i] : $m[$size - 15 + $i][8];
            $bits2 |= $b << $i;
        }
        self::assertSame($bits, $bits2, 'le due copie del format info divergono');
    }

    #[Test]
    public function data_bits_survive_placement_and_mask(): void
    {
        [$obj, $rc] = $this->fresh();

        $bytes = array_values(unpack('C*', self::URI));
        $ver   = $this->call($obj, $rc, 'pickVersion', [count($bytes)]);
        $rc->getProperty('version')->setValue($obj, $ver);
        $rc->getProperty('size')->setValue($obj, 17 + 4 * $ver);

        $data = $this->call($obj, $rc, 'encodeData', [$bytes]);
        $full = $this->call($obj, $rc, 'addErrorCorrection', [$data]);

        $this->call($obj, $rc, 'initMatrix');
        $this->call($obj, $rc, 'placeFunctionPatterns');
        $this->call($obj, $rc, 'placeData', [$full]);

        $reserved = $this->prop($obj, $rc, 'reserved');
        $size     = $this->prop($obj, $rc, 'size');

        foreach ([0, 3, 5, 7] as $mask) {
            $masked = $this->call($obj, $rc, 'applyMask', [$mask]);

            // Rilettura nello stesso ordine a zig-zag, togliendo la maschera.
            $bits = '';
            $up   = true;
            for ($right = $size - 1; $right > 0; $right -= 2) {
                if ($right === 6) {
                    $right = 5;
                }
                for ($v = 0; $v < $size; $v++) {
                    $y = $up ? $size - 1 - $v : $v;
                    foreach ([$right, $right - 1] as $x) {
                        if ($reserved[$y][$x]) {
                            continue;
                        }
                        $flip = match ($mask) {
                            0 => ($y + $x) % 2 === 0,
                            3 => ($y + $x) % 3 === 0,
                            5 => (($y * $x) % 2 + ($y * $x) % 3) === 0,
                            default => ((($y + $x) % 2 + ($y * $x) % 3) % 2) === 0,
                        };
                        $bits .= (string)($masked[$y][$x] ^ ($flip ? 1 : 0));
                    }
                }
                $up = !$up;
            }

            $read = [];
            foreach (str_split(substr($bits, 0, count($full) * 8), 8) as $byte) {
                $read[] = bindec($byte);
            }
            self::assertSame($full, $read, "i codeword non sopravvivono alla maschera $mask");
        }
    }

    #[Test]
    public function version_information_is_present_and_correct(): void
    {
        // Dalla versione 7 in su lo standard impone 18 bit di informazione di
        // versione, replicati in due blocchi 3x6. Ometterli non rende il
        // simbolo "meno robusto": lo rende illeggibile ai lettori conformi, e
        // quelle aree finiscono occupate dai dati, sfasando tutto il resto.
        //
        // E' esattamente cio' che era successo: gli altri test passavano perche'
        // rileggevano la matrice con la STESSA mappa di moduli riservati con
        // cui la scrivevano. Un errore condiviso fra scrittura e lettura non si
        // vede. Qui si confronta con la tabella D.1 della norma, che e' una
        // fonte esterna.
        $riferimento = [7 => 0x07C94, 8 => 0x085BC, 9 => 0x09A99, 10 => 0x0A4D3];

        [$obj, $rc] = $this->fresh();
        $this->call($obj, $rc, 'build', [self::URI]);
        $m       = $this->prop($obj, $rc, 'modules');
        $size    = $this->prop($obj, $rc, 'size');
        $version = $this->prop($obj, $rc, 'version');

        self::assertGreaterThanOrEqual(7, $version, 'un URI otpauth sta in versione 7 o piu alta');

        // Blocco in alto a destra.
        $bits = 0;
        for ($i = 0; $i < 18; $i++) {
            $bits |= $m[intdiv($i, 3)][$size - 11 + $i % 3] << $i;
        }
        self::assertSame(
            $riferimento[$version],
            $bits,
            'i 18 bit di versione non corrispondono alla tabella D.1 della norma'
        );

        // Blocco speculare in basso a sinistra: deve dire la stessa cosa.
        $bits2 = 0;
        for ($i = 0; $i < 18; $i++) {
            $bits2 |= $m[$size - 11 + $i % 3][intdiv($i, 3)] << $i;
        }
        self::assertSame($bits, $bits2, 'le due copie dell informazione di versione divergono');

        // I sei bit alti sono il numero di versione, in chiaro.
        self::assertSame($version, $bits >> 12);
    }

    #[Test]
    public function short_payloads_carry_no_version_block(): void
    {
        // Sotto la versione 7 l'informazione di versione non esiste, e quelle
        // aree sono dati a tutti gli effetti: scriverla la' sarebbe un errore
        // speculare a quello di ometterla sopra la 7.
        [$obj, $rc] = $this->fresh();
        $this->call($obj, $rc, 'build', ['ciao']);
        self::assertLessThan(7, $this->prop($obj, $rc, 'version'));

        $reserved = $this->prop($obj, $rc, 'reserved');
        $size     = $this->prop($obj, $rc, 'size');
        self::assertFalse(
            $reserved[0][$size - 11],
            'in versione < 7 l area dell informazione di versione non va riservata'
        );
    }

    #[Test]
    public function version_grows_with_the_payload(): void
    {
        [$obj, $rc] = $this->fresh();

        self::assertSame(1, $this->call($obj, $rc, 'pickVersion', [10]));
        $long = $this->call($obj, $rc, 'pickVersion', [strlen(self::URI)]);
        self::assertGreaterThan(1, $long, 'un URI otpauth non sta in versione 1');
        self::assertLessThanOrEqual(10, $long);

        $this->expectException(\InvalidArgumentException::class);
        $this->call($obj, $rc, 'pickVersion', [5000]);
    }
}
