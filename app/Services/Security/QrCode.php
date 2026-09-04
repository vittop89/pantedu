<?php

declare(strict_types=1);

namespace App\Services\Security;

use InvalidArgumentException;

/**
 * Generatore di QR code — SVG, byte mode, correzione errori livello M.
 *
 * PERCHE' ESISTE (2026-09-01)
 *
 * La pagina /me/2fa mostrava il QR d'iscrizione chiamando
 * `https://quickchart.io/qr?text=<otpauth-uri>`. Quell'URI **contiene il
 * segreto TOTP**: ogni docente che attivava la verifica in due passaggi lo
 * spediva, in chiaro e dentro una query string, a un servizio statunitense
 * che non compare in alcun elenco di sub-responsabili. Le query string
 * finiscono nei log dei server, nei proxy e nei referer.
 *
 * Il segreto E' il secondo fattore: chi lo possiede genera codici validi per
 * sempre. Mandarlo a un terzo per disegnare un quadrato nero e bianco e' un
 * prezzo fuori scala.
 *
 * Nessuna dipendenza, come TotpService: il QR si compone qui e la pagina
 * riceve dell'SVG inline. Nessuna richiesta di rete, niente da dichiarare al
 * DPO, e la CSP non deve concedere nulla a nessuno.
 *
 * AMBITO
 *
 * Solo cio' che serve a un URI `otpauth://`: modalita' byte, livello M,
 * versioni 1-10 (fino a 271 byte, mentre un URI tipico ne occupa ~110).
 * Non e' una libreria QR generale e non pretende di esserlo — kanji, ECI,
 * micro-QR e modalita' numerica restano fuori di proposito.
 *
 * Riferimento: ISO/IEC 18004.
 */
final class QrCode
{
    /** Livello di correzione M: ~15% di moduli recuperabili. */
    private const ECC_LEVEL_BITS = 0b00; // indicatore di livello M nel format info

    /**
     * Per ogni versione 1..10: [ codeword totali, codeword di correzione per blocco,
     *                            n. blocchi gruppo 1, n. blocchi gruppo 2 ]
     * Valori del livello M, tabelle 13-22 ISO/IEC 18004.
     *
     * @var array<int, array{0:int,1:int,2:int,3:int}>
     */
    private const VERSION_M = [
        1  => [26,   10, 1, 0],
        2  => [44,   16, 1, 0],
        3  => [70,   26, 1, 0],
        4  => [100,  18, 2, 0],
        5  => [134,  24, 2, 0],
        6  => [172,  16, 4, 0],
        7  => [196,  18, 4, 0],
        8  => [242,  22, 2, 2],
        9  => [292,  22, 3, 2],
        10 => [346,  26, 4, 1],
    ];

    /** Coordinate centrali dei pattern di allineamento, per versione. */
    private const ALIGNMENT = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    /** @var list<list<int>> matrice dei moduli: 1 nero, 0 bianco */
    private array $modules = [];
    /** @var list<list<bool>> moduli riservati (funzionali), non mascherabili */
    private array $reserved = [];
    private int $size = 0;
    private int $version = 0;

    /**
     * Restituisce l'SVG del QR per il testo dato.
     *
     * @param int $scale lato di un modulo in unita' SVG
     * @param int $quiet margine chiaro in moduli (lo standard chiede 4)
     */
    public static function svg(string $text, int $scale = 6, int $quiet = 4): string
    {
        return (new self())->render($text, $scale, $quiet);
    }

    private function render(string $text, int $scale, int $quiet): string
    {
        $this->build($text);

        $dim  = ($this->size + 2 * $quiet) * $scale;
        $path = '';
        foreach ($this->modules as $y => $row) {
            foreach ($row as $x => $on) {
                if ($on) {
                    $px = ($x + $quiet) * $scale;
                    $py = ($y + $quiet) * $scale;
                    $path .= "M$px {$py}h{$scale}v{$scale}h-{$scale}z";
                }
            }
        }

        // shape-rendering=crispEdges: senza, gli scanner soffrono i bordi
        // sfumati dall'antialiasing su schermi a bassa densita'.
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $dim . '" height="' . $dim . '"'
             . ' viewBox="0 0 ' . $dim . ' ' . $dim . '" shape-rendering="crispEdges"'
             . ' role="img" aria-label="Codice QR per la configurazione della verifica in due passaggi">'
             . '<rect width="' . $dim . '" height="' . $dim . '" fill="#fff"/>'
             . '<path d="' . $path . '" fill="#000"/>'
             . '</svg>';
    }

    private function build(string $text): void
    {
        $bytes = array_values(unpack('C*', $text) ?: []);
        $this->version = $this->pickVersion(count($bytes));
        $this->size    = 17 + 4 * $this->version;

        $data = $this->encodeData($bytes);
        $full = $this->addErrorCorrection($data);

        $this->initMatrix();
        $this->placeFunctionPatterns();
        $this->placeData($full);

        // Si prova ogni maschera e si tiene quella con penalita' minore: e' cio'
        // che rende il simbolo leggibile, evitando zone uniformi che uno
        // scanner scambia per pattern di posizionamento.
        $best = null;
        $bestPenalty = PHP_INT_MAX;
        $bestMask = 0;
        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = $this->applyMask($mask);
            $this->writeFormatInfo($candidate, $mask);
            $p = $this->penalty($candidate);
            if ($p < $bestPenalty) {
                $bestPenalty = $p;
                $best = $candidate;
                $bestMask = $mask;
            }
        }
        unset($bestMask);
        $this->modules = $best ?? $this->modules;
    }

    private function pickVersion(int $byteLen): int
    {
        foreach (self::VERSION_M as $v => [$total, $eccPerBlock, $g1, $g2]) {
            $blocks   = $g1 + $g2;
            $dataCw   = $total - $eccPerBlock * $blocks;
            $countBits = $v < 10 ? 8 : 16;          // byte mode, versioni 1-9: 8 bit
            $needBits  = 4 + $countBits + $byteLen * 8;
            if ($needBits <= $dataCw * 8) {
                return $v;
            }
        }
        throw new InvalidArgumentException('Testo troppo lungo per un QR versione 10 livello M');
    }

    /** @param list<int> $bytes @return list<int> codeword di dati */
    private function encodeData(array $bytes): array
    {
        [$total, $eccPerBlock, $g1, $g2] = self::VERSION_M[$this->version];
        $dataCw = $total - $eccPerBlock * ($g1 + $g2);

        $bits = '0100';                                     // indicatore: byte mode
        $bits .= str_pad(decbin(count($bytes)), 8, '0', STR_PAD_LEFT);
        foreach ($bytes as $b) {
            $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        }
        // Terminatore, poi allineamento al byte.
        $bits .= str_repeat('0', min(4, $dataCw * 8 - strlen($bits)));
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - strlen($bits) % 8);
        }
        // Riempimento alternato prescritto dallo standard.
        $pad = ['11101100', '00010001'];
        $i = 0;
        while (strlen($bits) < $dataCw * 8) {
            $bits .= $pad[$i++ % 2];
        }

        $out = [];
        foreach (str_split($bits, 8) as $byte) {
            $out[] = bindec($byte);
        }
        return $out;
    }

    /**
     * Interleaving dei blocchi dati + codeword di correzione Reed-Solomon.
     *
     * @param list<int> $data
     * @return list<int>
     */
    private function addErrorCorrection(array $data): array
    {
        [$total, $eccPerBlock, $g1, $g2] = self::VERSION_M[$this->version];
        $blocks   = $g1 + $g2;
        $dataCw   = $total - $eccPerBlock * $blocks;
        $shortLen = intdiv($dataCw, $blocks);

        $dataBlocks = [];
        $eccBlocks  = [];
        $offset = 0;
        for ($b = 0; $b < $blocks; $b++) {
            $len = $shortLen + ($b >= $g1 ? 1 : 0);
            $blk = array_slice($data, $offset, $len);
            $offset += $len;
            $dataBlocks[] = $blk;
            $eccBlocks[]  = $this->reedSolomon($blk, $eccPerBlock);
        }

        $out = [];
        $maxData = max(array_map('count', $dataBlocks));
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($dataBlocks as $blk) {
                if (isset($blk[$i])) {
                    $out[] = $blk[$i];
                }
            }
        }
        for ($i = 0; $i < $eccPerBlock; $i++) {
            foreach ($eccBlocks as $blk) {
                $out[] = $blk[$i];
            }
        }
        return $out;
    }

    /** @param list<int> $data @return list<int> */
    private function reedSolomon(array $data, int $eccLen): array
    {
        [$exp, $log] = $this->galoisTables();

        // Polinomio generatore: prodotto di (x - alpha^i).
        $gen = [1];
        for ($i = 0; $i < $eccLen; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);
            foreach ($gen as $j => $coef) {
                $next[$j]     ^= $coef;
                $next[$j + 1] ^= $coef === 0 ? 0 : $exp[($log[$coef] + $i) % 255];
            }
            $gen = $next;
        }

        $rem = array_merge($data, array_fill(0, $eccLen, 0));
        for ($i = 0; $i < count($data); $i++) {
            $factor = $rem[$i];
            if ($factor === 0) {
                continue;
            }
            $lf = $log[$factor];
            foreach ($gen as $j => $coef) {
                if ($coef !== 0) {
                    $rem[$i + $j] ^= $exp[($log[$coef] + $lf) % 255];
                }
            }
        }
        return array_slice($rem, count($data));
    }

    /** @return array{0:list<int>,1:array<int,int>} tavole exp/log di GF(256), polinomio 0x11d */
    private function galoisTables(): array
    {
        static $exp = null, $log = null;
        if ($exp !== null && $log !== null) {
            return [$exp, $log];
        }
        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11d;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }
        return [$exp, $log];
    }

    private function initMatrix(): void
    {
        $this->modules  = array_fill(0, $this->size, array_fill(0, $this->size, 0));
        $this->reserved = array_fill(0, $this->size, array_fill(0, $this->size, false));
    }

    private function placeFunctionPatterns(): void
    {
        $n = $this->size;

        // Tre pattern di posizionamento agli angoli, con separatore.
        foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$ox, $oy]) {
            for ($y = -1; $y <= 7; $y++) {
                for ($x = -1; $x <= 7; $x++) {
                    $px = $ox + $x;
                    $py = $oy + $y;
                    if ($px < 0 || $py < 0 || $px >= $n || $py >= $n) {
                        continue;
                    }
                    $inRing = ($x >= 0 && $x <= 6 && ($y === 0 || $y === 6))
                           || ($y >= 0 && $y <= 6 && ($x === 0 || $x === 6));
                    $inCore = $x >= 2 && $x <= 4 && $y >= 2 && $y <= 4;
                    $this->modules[$py][$px]  = ($inRing || $inCore) ? 1 : 0;
                    $this->reserved[$py][$px] = true;
                }
            }
        }

        // Pattern di temporizzazione.
        for ($i = 8; $i < $n - 8; $i++) {
            $bit = ($i % 2 === 0) ? 1 : 0;
            $this->modules[6][$i] = $bit;
            $this->modules[$i][6] = $bit;
            $this->reserved[6][$i] = true;
            $this->reserved[$i][6] = true;
        }

        // Pattern di allineamento (assenti in versione 1).
        $centers = self::ALIGNMENT[$this->version];
        foreach ($centers as $cy) {
            foreach ($centers as $cx) {
                // Si saltano quelli che finirebbero sui pattern di posizionamento.
                if (($cx === 6 && $cy === 6)
                    || ($cx === 6 && $cy === $n - 7)
                    || ($cx === $n - 7 && $cy === 6)) {
                    continue;
                }
                for ($y = -2; $y <= 2; $y++) {
                    for ($x = -2; $x <= 2; $x++) {
                        $on = (abs($x) === 2 || abs($y) === 2 || ($x === 0 && $y === 0)) ? 1 : 0;
                        $this->modules[$cy + $y][$cx + $x]  = $on;
                        $this->reserved[$cy + $y][$cx + $x] = true;
                    }
                }
            }
        }

        // Informazione di versione: obbligatoria dalla versione 7 in su
        // (ISO 18004 §6.10). Sono 18 bit — sei di versione piu' dodici di
        // correzione BCH — replicati in due blocchi 3x6, accanto al riquadro
        // in alto a destra e a quello in basso a sinistra.
        //
        // Ometterla non produce un simbolo "un po' meno robusto": lo rende
        // illeggibile ai lettori conformi, e per giunta quelle aree finiscono
        // occupate dai dati, sfasando tutto il resto della lettura. Un URI
        // otpauth tipico sta in versione 7 o 8, quindi il caso non e' un
        // dettaglio marginale: e' il caso normale.
        if ($this->version >= 7) {
            $bch = $this->version << 12;
            for ($i = 17; $i >= 12; $i--) {
                if ($bch & (1 << $i)) {
                    $bch ^= 0x1F25 << ($i - 12);
                }
            }
            $bits = ($this->version << 12) | ($bch & 0xFFF);

            for ($i = 0; $i < 18; $i++) {
                $bit = ($bits >> $i) & 1;
                $a = $n - 11 + $i % 3;
                $b = intdiv($i, 3);
                // In alto a destra e, speculare, in basso a sinistra.
                $this->modules[$b][$a]  = $bit;
                $this->reserved[$b][$a] = true;
                $this->modules[$a][$b]  = $bit;
                $this->reserved[$a][$b] = true;
            }
        }

        // Modulo scuro, sempre nero (ISO 18004 §6.9.1).
        $this->modules[$n - 8][8]  = 1;
        $this->reserved[$n - 8][8] = true;

        // Aree riservate alle informazioni di formato.
        for ($i = 0; $i < 9; $i++) {
            if (!$this->reserved[8][$i]) {
                $this->reserved[8][$i] = true;
            }
            if (!$this->reserved[$i][8]) {
                $this->reserved[$i][8] = true;
            }
        }
        for ($i = 0; $i < 8; $i++) {
            $this->reserved[8][$n - 1 - $i]  = true;
            $this->reserved[$n - 1 - $i][8]  = true;
        }
    }

    /** @param list<int> $codewords */
    private function placeData(array $codewords): void
    {
        $n   = $this->size;
        $bits = '';
        foreach ($codewords as $cw) {
            $bits .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
        }

        $i  = 0;
        $up = true;
        for ($right = $n - 1; $right > 0; $right -= 2) {
            if ($right === 6) {
                $right = 5; // la colonna 6 e' di temporizzazione: si scavalca
            }
            for ($v = 0; $v < $n; $v++) {
                $y = $up ? $n - 1 - $v : $v;
                foreach ([$right, $right - 1] as $x) {
                    if ($this->reserved[$y][$x]) {
                        continue;
                    }
                    $this->modules[$y][$x] = isset($bits[$i]) ? (int)$bits[$i] : 0;
                    $i++;
                }
            }
            $up = !$up;
        }
    }

    /** @return list<list<int>> copia mascherata della matrice */
    private function applyMask(int $mask): array
    {
        $out = $this->modules;
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->reserved[$y][$x]) {
                    continue;
                }
                $flip = match ($mask) {
                    0 => ($y + $x) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($y + $x) % 3 === 0,
                    4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
                    5 => (($y * $x) % 2 + ($y * $x) % 3) === 0,
                    6 => ((($y * $x) % 2 + ($y * $x) % 3) % 2) === 0,
                    default => ((($y + $x) % 2 + ($y * $x) % 3) % 2) === 0,
                };
                if ($flip) {
                    $out[$y][$x] ^= 1;
                }
            }
        }
        return $out;
    }

    /** @param list<list<int>> $m */
    private function writeFormatInfo(array &$m, int $mask): void
    {
        $n    = $this->size;
        $data = (self::ECC_LEVEL_BITS << 3) | $mask;

        // BCH(15,5) con polinomio generatore 0x537, poi maschera 0x5412.
        $bch = $data << 10;
        for ($i = 14; $i >= 10; $i--) {
            if ($bch & (1 << $i)) {
                $bch ^= 0x537 << ($i - 10);
            }
        }
        $bits = (($data << 10) | $bch) ^ 0x5412;

        for ($i = 0; $i < 15; $i++) {
            $bit = ($bits >> $i) & 1;
            // Copia 1: attorno al pattern in alto a sinistra.
            if ($i < 6) {
                $m[8][$i] = $bit;
            } elseif ($i === 6) {
                $m[8][7] = $bit;
            } elseif ($i === 7) {
                $m[8][8] = $bit;
            } elseif ($i === 8) {
                $m[7][8] = $bit;
            } else {
                $m[14 - $i][8] = $bit;
            }
            // Copia 2: ridondante, permette la lettura se un angolo e' rovinato.
            if ($i < 8) {
                $m[8][$n - 1 - $i] = $bit;
            } else {
                $m[$n - 15 + $i][8] = $bit;
            }
        }
        $m[$n - 8][8] = 1; // il modulo scuro non si tocca
    }

    /** @param list<list<int>> $m Penalita' ISO 18004 §6.8.3: piu' bassa, piu' leggibile. */
    private function penalty(array $m): int
    {
        $n = $this->size;
        $score = 0;

        // N1 — sequenze di 5+ moduli uguali, in riga e in colonna.
        foreach ([true, false] as $byRow) {
            for ($a = 0; $a < $n; $a++) {
                $run = 1;
                for ($b = 1; $b < $n; $b++) {
                    $cur  = $byRow ? $m[$a][$b]     : $m[$b][$a];
                    $prev = $byRow ? $m[$a][$b - 1] : $m[$b - 1][$a];
                    if ($cur === $prev) {
                        $run++;
                    } else {
                        if ($run >= 5) {
                            $score += 3 + ($run - 5);
                        }
                        $run = 1;
                    }
                }
                if ($run >= 5) {
                    $score += 3 + ($run - 5);
                }
            }
        }

        // N2 — blocchi 2x2 dello stesso colore.
        for ($y = 0; $y < $n - 1; $y++) {
            for ($x = 0; $x < $n - 1; $x++) {
                $v = $m[$y][$x];
                if ($v === $m[$y][$x + 1] && $v === $m[$y + 1][$x] && $v === $m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }

        // N3 — sequenze che imitano un pattern di posizionamento (1:1:3:1:1).
        $needle  = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];
        $needleR = array_reverse($needle);
        for ($a = 0; $a < $n; $a++) {
            for ($b = 0; $b <= $n - 11; $b++) {
                $row = $col = [];
                for ($k = 0; $k < 11; $k++) {
                    $row[] = $m[$a][$b + $k];
                    $col[] = $m[$b + $k][$a];
                }
                foreach ([$row, $col] as $seq) {
                    if ($seq === $needle || $seq === $needleR) {
                        $score += 40;
                    }
                }
            }
        }

        // N4 — scostamento dal 50% di moduli scuri.
        $dark = 0;
        foreach ($m as $row) {
            $dark += array_sum($row);
        }
        $ratio = (int)floor(abs($dark * 100 / ($n * $n) - 50) / 5);
        $score += $ratio * 10;

        return $score;
    }
}
