<?php

declare(strict_types=1);

namespace Tests\Unit\Architettura;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il CSS scritto dentro le viste e le classi PHP non cresce senza che
 * qualcuno lo veda (23/9/2026).
 *
 * ── Perché (revisione architetturale del 23/9/2026, A-51) ──
 *
 * La guardia del CSS (`tools/ci/no-css-in-js.mjs`) guarda solo `js/`. Nelle
 * viste e nelle classi PHP che emettono HTML ci sono blocchi `<style>` e
 * attributi `style=`: stanno fuori da `@layer`, quindi vincono sul fascio
 * (css/main.bundle.css), e sono la ragione per cui la CSP non può ancora
 * rinunciare a `'unsafe-inline'` per gli stili. Questa prova non sposta il
 * CSS: fissa in `tools/ci/css-nelle-viste.json`, per ogni file `.php` (e
 * `.html`) sotto `views/` e `.php` sotto `app/` che ne ha, quanti blocchi
 * `<style>` e quanti attributi `style=` c'erano il 23/9/2026, e ricalcola i
 * due numeri a ogni corsa.
 *
 * Fallisce se un file censito ne ha di più, se ne compare uno nuovo, o se un
 * file censito ne ha di meno (per ricordare di abbassare il numero: un numero
 * più alto del vero lascerebbe spazio a CSS nuovo). Come i cricchetti di
 * ADR-049.
 *
 * Il conteggio legge il codice col tokenizer di PHP e salta commenti e
 * docblock: un commento che nomina `<style>` non è CSS. Conta l'HTML fuori da
 * `<?php` e le stringhe del codice, dove le classi scrivono il markup.
 *
 * Nei due versi: `ilConteggioVedeIlCssENonIlResto` prova la misura su esempi
 * (attributi fra virgolette doppie, singole ed escape, blocchi; non commenti,
 * `$style =`, `'style' =>`, `data-style=`); `leDifferenzeScattanoDoveDevono`
 * prova il confronto su un censimento finto (cresce, nuovo, sceso: rosso;
 * uguale: verde). Misurato anche sul repository il 23/9/2026: aggiunto un
 * `style=` a una vista censita e a una vista pulita, falliscono le due prove
 * della crescita; tolto un attributo, fallisce quella che chiede di
 * abbassare.
 */
final class CssNelleVisteTest extends TestCase
{
    private const CENSIMENTO = 'tools/ci/css-nelle-viste.json';

    private static function radice(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string, array{blocchi: int, attributi: int}> */
    private static function censiti(): array
    {
        $dati = json_decode((string)file_get_contents(self::radice() . '/' . self::CENSIMENTO), true);
        self::assertIsArray($dati, self::CENSIMENTO . ' si legge');
        self::assertIsArray($dati['file'] ?? null, self::CENSIMENTO . ' ha la chiave `file`');
        /** @var array<string, array{blocchi: int, attributi: int}> $file */
        $file = $dati['file'];
        return $file;
    }

    /**
     * Blocchi `<style>` e attributi `style=` in un sorgente PHP, commenti
     * esclusi.
     *
     * @return array{blocchi: int, attributi: int}
     */
    public static function conta(string $sorgente): array
    {
        $testo = '';
        foreach (token_get_all($sorgente) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                $testo .= $t[1];
            } else {
                $testo .= $t;
            }
        }
        return [
            'blocchi'   => preg_match_all('/<style\b/i', $testo),
            // Preceduto da uno spazio o da una virgoletta (non `$style`,
            // `->style`, `data-style`), seguito da `=` e da una virgoletta,
            // anche con l'escape di una stringa PHP (`style=\"`).
            'attributi' => preg_match_all('/(?<=[\s"\'])style\s*=\s*\\\\?["\']/i', $testo),
        ];
    }

    /** @return array<string, array{blocchi: int, attributi: int}> i file con CSS, oggi */
    public static function misura(): array
    {
        $radice = self::radice();
        $trovati = [];
        // Le viste anche in .html (tre file, oggi senza CSS); in app/ il PHP.
        foreach (['views' => ['php', 'html'], 'app' => ['php']] as $cartella => $estensioni) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($radice . '/' . $cartella, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if (!$f instanceof \SplFileInfo || !in_array($f->getExtension(), $estensioni, true)) {
                    continue;
                }
                $n = self::conta((string)file_get_contents($f->getPathname()));
                if ($n['blocchi'] > 0 || $n['attributi'] > 0) {
                    $trovati[str_replace($radice . '/', '', $f->getPathname())] = $n;
                }
            }
        }
        ksort($trovati);
        return $trovati;
    }

    /**
     * Il confronto fra oggi e il censimento.
     *
     * @param array<string, array{blocchi: int, attributi: int}> $oggi
     * @param array<string, array{blocchi: int, attributi: int}> $censiti
     * @return array{nuovi: list<string>, cresciuti: list<string>, scesi: list<string>}
     */
    public static function differenze(array $oggi, array $censiti): array
    {
        $out = ['nuovi' => [], 'cresciuti' => [], 'scesi' => []];
        foreach ($oggi as $file => $n) {
            if (!isset($censiti[$file])) {
                $out['nuovi'][] = "{$file}: {$n['blocchi']} blocchi <style>, {$n['attributi']} attributi style=";
                continue;
            }
            foreach (['blocchi', 'attributi'] as $k) {
                if ($n[$k] > $censiti[$file][$k]) {
                    $out['cresciuti'][] = "{$file}: {$k} da {$censiti[$file][$k]} a {$n[$k]}";
                }
            }
        }
        foreach ($censiti as $file => $registrato) {
            $n = $oggi[$file] ?? ['blocchi' => 0, 'attributi' => 0];
            foreach (['blocchi', 'attributi'] as $k) {
                if ($n[$k] < $registrato[$k]) {
                    $out['scesi'][] = "{$file}: {$k} da {$registrato[$k]} a {$n[$k]}";
                }
            }
        }
        return $out;
    }

    #[Test]
    public function nessunFileNuovoConCssInLinea(): void
    {
        $nuovi = self::differenze(self::misura(), self::censiti())['nuovi'];
        self::assertSame(
            [],
            $nuovi,
            'CSS in linea in un file che non ne aveva (' . self::CENSIMENTO . '): ' . implode('; ', $nuovi)
                . '. Le regole vanno in css/modules/_*.css, sotto @layer; uno stato dinamico con una'
                . ' classe o una variabile CSS.'
        );
    }

    #[Test]
    public function nessunFileCensitoCresce(): void
    {
        $cresciuti = self::differenze(self::misura(), self::censiti())['cresciuti'];
        self::assertSame(
            [],
            $cresciuti,
            'CSS in linea cresciuto rispetto a ' . self::CENSIMENTO . ': ' . implode('; ', $cresciuti)
        );
    }

    #[Test]
    public function unFileCheScendeChiedeDiAbbassareIlNumero(): void
    {
        $scesi = self::differenze(self::misura(), self::censiti())['scesi'];
        self::assertSame(
            [],
            $scesi,
            'CSS in linea sceso (bene): abbassa il numero in ' . self::CENSIMENTO
                . ', o togli la voce se è a zero: ' . implode('; ', $scesi)
        );
    }

    #[Test]
    public function ilConteggioVedeIlCssENonIlResto(): void
    {
        $contato = self::conta(<<<'PHP'
<div style="color:red"><span style='x'></span></div>
<style>
.a { color: blue; }
</style>
<?php
// <style> in un commento non conta, e nemmeno style="x" qui.
/** <style>anche nel docblock</style> style="y" */
echo "<p style=\"margin:0\">testo</p>";
echo '<STYLE media="print">p{}</style>';
$style = "valore";
$opzioni = ['style' => 'x'];
$el->style = 'y';
echo '<div data-style="z" class="x"></div>';
PHP);
        self::assertSame(['blocchi' => 2, 'attributi' => 3], $contato);

        self::assertSame(['blocchi' => 0, 'attributi' => 0], self::conta("<?php\n\$style = 'a';\necho 'nessuno';\n"));
    }

    #[Test]
    public function leDifferenzeScattanoDoveDevono(): void
    {
        $censiti = ['views/a.php' => ['blocchi' => 1, 'attributi' => 2]];

        self::assertSame(
            ['nuovi' => [], 'cresciuti' => [], 'scesi' => []],
            self::differenze(['views/a.php' => ['blocchi' => 1, 'attributi' => 2]], $censiti),
            'uguale al censimento: verde'
        );

        $cresciuto = self::differenze(['views/a.php' => ['blocchi' => 1, 'attributi' => 3]], $censiti);
        self::assertSame(['views/a.php: attributi da 2 a 3'], $cresciuto['cresciuti']);

        $nuovo = self::differenze(
            ['views/a.php' => ['blocchi' => 1, 'attributi' => 2], 'app/B.php' => ['blocchi' => 0, 'attributi' => 1]],
            $censiti
        );
        self::assertSame(['app/B.php: 0 blocchi <style>, 1 attributi style='], $nuovo['nuovi']);

        $sceso = self::differenze(['views/a.php' => ['blocchi' => 0, 'attributi' => 2]], $censiti);
        self::assertSame(['views/a.php: blocchi da 1 a 0'], $sceso['scesi']);

        $sparito = self::differenze([], $censiti);
        self::assertSame(['views/a.php: blocchi da 1 a 0', 'views/a.php: attributi da 2 a 0'], $sparito['scesi']);
    }
}
