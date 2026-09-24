<?php

declare(strict_types=1);

namespace Tests\Unit\Rendering;

use App\Services\ContractRenderer;
use App\Services\TexBuilder\Sanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Un elenco ripreso dopo un paragrafo comincia dal punto scelto, a schermo e
 * nel PDF (24/9/2026).
 *
 * Il menu «List ▸ Numerazione» della barra dell'editor scrive l'attributo
 * `start` sull'<ol>; il salvataggio lo porta nel blocco lista come `start`
 * (campo-in-blocchi.js). Questa prova fissa l'altra metà del giro, quella del
 * server: senza di lei, il menu potrebbe continuare a scrivere `start` mentre
 * la pagina o il PDF tornano a contare da a. senza che niente lo dica.
 *
 * La compilazione vera di `[label=\alph*.,start=3]` con enumitem è stata
 * misurata a mano lo stesso giorno (pdflatex, esito 0); con `start=27` la
 * compilazione si ferma («Counter too large»), ed è per questo che il menu
 * non accetta lettere oltre la z.
 */
final class ElencoRipresoTest extends TestCase
{
    /** @param array<string,mixed> $extra */
    private static function lista(array $extra, string ...$voci): array
    {
        return [
            'type' => 'list', 'ordered' => true, 'list_preset' => 'lower-alpha-roman',
            'dsa_section' => 'question',
            'items' => array_map(static fn(string $v) => [['type' => 'text', 'content' => $v]], $voci),
        ] + $extra;
    }

    #[Test]
    public function la_pagina_numera_la_seconda_lista_dal_suo_primo_punto(): void
    {
        $html = (new ContractRenderer([]))->renderContract([
            'title' => 'T',
            'groups' => [[
                'id' => 'g1', 'type' => 'type_Esercizi_1', 'title' => 'G', 'intro' => '',
                'items' => [[
                    'id' => 'q1',
                    'question' => [
                        self::lista([], 'primo', 'secondo'),
                        ['type' => 'text', 'content' => 'Un paragrafo in mezzo.'],
                        self::lista(['start' => 3], 'terzo', 'quarto'),
                    ],
                    'justification' => [],
                ]],
            ]],
        ]);

        preg_match_all('#<span class="fm-dsa-li-num">([^<]*)</span>#', $html, $segni);
        self::assertSame(['a.', 'b.', 'c.', 'd.'], $segni[1], 'i segni delle due liste, in ordine');
        self::assertMatchesRegularExpression(
            '#<ol class="fm-dsa-li-list"[^>]* start="3"#',
            $html,
            'la seconda lista porta il suo inizio nell\'HTML (i segni nativi del browser lo usano)'
        );
    }

    #[Test]
    public function il_pdf_riceve_il_primo_punto_insieme_allo_stile(): void
    {
        $tex = Sanitizer::stripHtml(
            '<ol class="fm-dsa-li-list" data-fm-list-style="lower-alpha-roman" start="3">'
            . '<li>terzo</li><li>quarto</li></ol>'
        );
        self::assertStringContainsString('\begin{enumerate}[label=\alph*.,start=3]', $tex);
    }

    /**
     * I dieci stili numerati del menu List, con il segno dei tre livelli, come
     * li mostra l'editor: D numeri, 0D numeri a due cifre, la/UA lettere,
     * lr/UR numeri romani. È l'atteso, scritto qui da capo e non letto da
     * ContractRenderer.
     *
     * @return array<string, array{string, list<string>}>
     */
    public static function stili(): array
    {
        return [
            'ol'             => ['',                   ['D.',  'la.', 'lr.']],
            'ol-Alpha'       => ['alpha-decimal',      ['UA.', 'D.',  'la.']],
            'ol-alpha'       => ['lower-alpha-roman',  ['la.', 'lr.', 'D.']],
            'ol-Roman'       => ['roman-alpha',        ['UR.', 'UA.', 'D.']],
            'ol-zero'        => ['decimal-zero',       ['0D.', 'la.', 'lr.']],
            'ol-paren'       => ['paren',              ['D)',  'la)', 'lr)']],
            'ol-Alpha-paren' => ['alpha-paren',        ['UA)', 'D)',  'la)']],
            'ol-alpha-paren' => ['lower-alpha-paren',  ['la)', 'lr)', 'D)']],
            'ol-Roman-paren' => ['roman-paren',        ['UR)', 'UA)', 'D)']],
            'ol-zero-paren'  => ['decimal-zero-paren', ['0D)', 'la)', 'lr)']],
        ];
    }

    private static function segno(string $codice, int $n): string
    {
        $romano = static function (int $x): string {
            $s = '';
            foreach ([1000 => 'm', 900 => 'cm', 500 => 'd', 400 => 'cd', 100 => 'c', 90 => 'xc', 50 => 'l',
                40 => 'xl', 10 => 'x', 9 => 'ix', 5 => 'v', 4 => 'iv', 1 => 'i'] as $v => $r) {
                for (; $x >= $v; $x -= $v) {
                    $s .= $r;
                }
            }
            return $s;
        };
        $tipo = substr($codice, 0, -1);
        $corpo = match ($tipo) {
            'D'  => (string)$n,
            '0D' => str_pad((string)$n, 2, '0', STR_PAD_LEFT),
            'la' => chr(96 + $n),
            'UA' => strtoupper(chr(96 + $n)),
            'lr' => $romano($n),
            'UR' => strtoupper($romano($n)),
            default => throw new \LogicException("segno sconosciuto: $codice"),
        };
        return $corpo . substr($codice, -1);
    }

    /** Il primo punto di prova per quel segno: c per le lettere, iv per i romani, 7 per i numeri. */
    private static function partenza(string $codice): int
    {
        return match (substr($codice, 0, -1)) {
            'la', 'UA' => 3,
            'lr', 'UR' => 4,
            default    => 7,
        };
    }

    /**
     * Tre liste annidate, ognuna col suo inizio e due punti; la seconda e la
     * terza stanno nel primo punto della lista sopra.
     *
     * @param list<string> $livelli
     */
    private static function annidate(string $preset, array $livelli, int $d = 0): array
    {
        $voci = [];
        foreach ([0, 1] as $i) {
            $voce = [['type' => 'text', 'content' => "l{$d}n{$i}"]];
            if ($i === 0 && $d < 2) {
                $voce[] = self::annidate($preset, $livelli, $d + 1);
            }
            $voci[] = $voce;
        }
        return ['type' => 'list', 'ordered' => true, 'dsa_section' => $d === 0 ? 'question' : 'sub',
            'start' => self::partenza($livelli[$d]), 'items' => $voci]
            + ($d === 0 && $preset !== '' ? ['list_preset' => $preset] : []);
    }

    /** @param list<string> $livelli */
    #[Test]
    #[DataProvider('stili')]
    public function ogni_stile_a_ogni_livello_parte_dal_punto_scelto(string $preset, array $livelli): void
    {
        $html = (new ContractRenderer([]))->renderContract([
            'title' => 'T',
            'groups' => [[
                'id' => 'g1', 'type' => 'type_Esercizi_1', 'title' => 'G', 'intro' => '',
                'items' => [[
                    'id' => 'q1', 'question' => [self::annidate($preset, $livelli)], 'justification' => [],
                ]],
            ]],
        ]);

        // In ordine di lettura: 1° del livello 1, 1° e 2° del livello 3 dentro
        // il 1° del livello 2, 2° del livello 2, 2° del livello 1.
        $atteso = [];
        foreach ([[0, 0], [1, 0], [2, 0], [2, 1], [1, 1], [0, 1]] as [$d, $i]) {
            $atteso[] = self::segno($livelli[$d], self::partenza($livelli[$d]) + $i);
        }
        preg_match_all('#<span class="fm-dsa-li-num">([^<]*)</span>#', $html, $segni);
        self::assertSame($atteso, $segni[1], 'i segni che la pagina mostra');

        // Nel PDF l'inizio di ogni livello arriva a enumitem. Che cosa stampa
        // lo misura la spec e2e liste-numerazione (@pdflatex), che compila.
        $tex = Sanitizer::stripHtml($html);
        $attesi = array_count_values(array_map(static fn(string $c) => 'start=' . self::partenza($c), $livelli));
        preg_match_all('/start=\d+/', $tex, $trovati);
        self::assertEquals($attesi, array_count_values($trovati[0]), "l'inizio di ognuno dei tre livelli, e nessun altro");
    }

    #[Test]
    public function senza_start_la_lista_riparte_da_capo(): void
    {
        // La controprova: la stessa lista senza l'attributo non porta start.
        $tex = Sanitizer::stripHtml(
            '<ol class="fm-dsa-li-list" data-fm-list-style="lower-alpha-roman"><li>primo</li></ol>'
        );
        self::assertStringContainsString('\begin{enumerate}[label=\alph*.]', $tex);
        self::assertStringNotContainsString('start=', $tex);
    }
}
