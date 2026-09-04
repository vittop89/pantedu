<?php

declare(strict_types=1);

namespace App\Services\TexBuilder;

/**
 * Formattatore TEX: indenta + chiude righe con `\\` quando appropriato.
 *
 * Responsabilita':
 *   - Indenta basandosi su nesting di `\begin{}` / `\end{}`
 *   - Per ambienti "speciali" (tikzpicture, array, cases, align, ...) NON
 *     aggiunge `\\` a fine riga (sarebbero interpretati come row break)
 *   - Per ambienti normali: appende `\\` a riga di testo che non ha
 *     gia' markup di line-break/begin/end/quad/hline/etc.
 *   - Cleanup post: rimuove `\\` doppi prima di `\item` / `\end{enumerate}`
 *   - Collassa righe vuote multiple
 *   - Trim righe vuote head/tail
 */
final class Formatter
{
    private const SPECIAL_ENVIRONMENTS = [
        'tikzpicture', 'array', 'cases', 'align', 'align*', 'alignat',
        'alignat*', 'eqnarray', 'eqnarray*', 'gather', 'gather*',
        'multline', 'multline*', 'split', 'tabular', 'tabularx',
        'longtable', 'matrix', 'pmatrix', 'bmatrix', 'vmatrix',
        'Vmatrix', 'smallmatrix',
    ];

    public static function format(string $latex, string $indentStr = '    '): string
    {
        $beginLinePattern   = '/^\s*\\\\begin\{[^}]+\}/';
        $endLinePattern     = '/^\s*\\\\end\{[^}]+\}/';
        $beginGlobalPattern = '/\\\\begin\{[^}]+\}/';
        $endGlobalPattern   = '/\\\\end\{[^}]+\}/';

        $lines = preg_split('/\r?\n/', $latex) ?: [];

        $indent           = 0;
        $result           = [];
        $insideTikz       = false;
        $tikzBlockDepth   = 0;
        $inPreamble       = true; // G19.49k — niente `\\` nel preambolo
        $envCounters      = [];
        foreach (self::SPECIAL_ENVIRONMENTS as $env) {
            $envCounters[$env] = 0;
        }

        $count = \count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line    = $lines[$i];
            $trimmed = trim($line);
            // G19.49k — flip a body mode dopo `\begin{document}`
            if ($inPreamble && str_contains($trimmed, '\begin{document}')) {
                $inPreamble = false;
            }

            // Aggiorna counter ambienti speciali (begin/end nella stessa riga
            // si compensano).
            foreach (self::SPECIAL_ENVIRONMENTS as $env) {
                $bMatches = preg_match_all('/\\\\begin\{' . preg_quote($env, '/') . '\}/', $trimmed);
                $eMatches = preg_match_all('/\\\\end\{' . preg_quote($env, '/') . '\}/', $trimmed);
                $envCounters[$env] += ($bMatches ?: 0) - ($eMatches ?: 0);
            }
            $insideSpecialEnv = false;
            foreach ($envCounters as $c) {
                if ($c > 0) {
                    $insideSpecialEnv = true;
                    break;
                }
            }
            $wasInsideTikz = $insideTikz;
            $insideTikz    = $envCounters['tikzpicture'] > 0;
            if ($wasInsideTikz !== $insideTikz) {
                $tikzBlockDepth = 0;
            }

            // Decremento depth per chiusura graffa in TikZ
            if ($insideTikz && str_starts_with($trimmed, '}')) {
                $tikzBlockDepth = max(0, $tikzBlockDepth - 1);
            }

            $endCount   = preg_match_all($endGlobalPattern, $line);
            $beginCount = preg_match_all($beginGlobalPattern, $line);

            // Linea che inizia con \end → riduci indent PRIMA del print
            if (preg_match($endLinePattern, $line)) {
                $indent = max(0, $indent - ($endCount ?: 0));
            }

            // Skip righe vuote o con solo backslash
            if ($trimmed !== '' && $trimmed !== '\\') {
                $totalIndent = $indent;
                if ($insideTikz) {
                    $totalIndent += $tikzBlockDepth;
                }

                // Cerca prossima riga non vuota per decidere se aggiungere `\\`
                $nextNonEmpty = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    $cand = trim($lines[$j]);
                    if ($cand !== '' && $cand !== '\\') {
                        $nextNonEmpty = $cand;
                        break;
                    }
                }
                $nextStartsWithBegin = (bool)preg_match('/^\s*\\\\begin\{/', $nextNonEmpty);

                // Aggiunge `\\` a fine riga SOLO se:
                //   - non in special env
                //   - no marker di linebreak/begin/end gia' presenti
                //   - linea successiva non e' un \begin
                $hasLineBreakMarker = str_contains($trimmed, '\\\\')
                    || str_contains($trimmed, '\newpage')
                    || str_contains($trimmed, '\begin')
                    || str_contains($trimmed, '\end')
                    || preg_match('/\s*\\\\quad(\s|$)/', $trimmed)
                    || str_contains($trimmed, 'hline')
                    || str_contains($trimmed, 'hlinedash')
                    // G19.49j — comandi di spaziatura/struttura: NON
                    // appendere `\\` (gia' producono il line break o
                    // sarebbero nocivi: `\medskip\\` extra blank,
                    // `\fcolorbox` puo' rompere center).
                    || str_contains($trimmed, '\medskip')
                    || str_contains($trimmed, '\bigskip')
                    || str_contains($trimmed, '\smallskip')
                    || str_contains($trimmed, '\vspace')
                    || str_contains($trimmed, '\nopagebreak')
                    || str_contains($trimmed, '\fcolorbox')
                    || str_contains($trimmed, '\colorbox')
                    || str_contains($trimmed, '\noindent');

                // G19.49k — `\\` SOLO fuori dal preambolo
                if (!$inPreamble && !$insideSpecialEnv && !$hasLineBreakMarker && !$nextStartsWithBegin) {
                    $result[] = str_repeat($indentStr, $totalIndent) . $trimmed . '\\\\';
                } else {
                    $result[] = str_repeat($indentStr, $totalIndent) . $trimmed;
                }
            }

            // Linea che inizia con \begin → incrementa indent DOPO output
            if (preg_match($beginLinePattern, $line)) {
                $indent += ($beginCount ?: 0);
            } elseif (!preg_match($endLinePattern, $line)) {
                // Casi misti `\begin{a}\end{b}` non a inizio riga → bilancia
                $indent = max(0, $indent + ($beginCount ?: 0) - ($endCount ?: 0));
            }

            // Incremento depth per apertura graffa in TikZ
            if ($insideTikz && (str_ends_with($trimmed, '{') || preg_match('/\{\s*$/', $trimmed))) {
                $tikzBlockDepth++;
            }
        }

        // Cleanup: collassa righe vuote multiple
        $cleaned = [];
        $prevEmpty = false;
        foreach ($result as $l) {
            if (trim($l) === '') {
                if (!$prevEmpty) {
                    $cleaned[] = '';
                }
                $prevEmpty = true;
            } else {
                $cleaned[] = $l;
                $prevEmpty = false;
            }
        }

        $joined = implode("\n", $cleaned);
        // Rimuovi `\\` prima di \item / \end{enumerate}
        $joined = (string)preg_replace('/\\\\\\\\(?=\s*\n*\\\\item)/', '', $joined);
        $joined = (string)preg_replace('/\\\\\\\\(?=\s*\n*\\\\end\{enumerate\})/', '', $joined);
        // Rimuovi `\\` sulla stessa riga di \item o riga successiva
        $joined = (string)preg_replace('/(\\\\item)\s*\\\\\\\\/', '$1', $joined);
        $joined = (string)preg_replace('/(\\\\item[^\n]*\n)\s*\\\\\\\\\s*\n/', '$1', $joined);

        $cleaned = explode("\n", $joined);
        // Trim head/tail vuoti
        while ($cleaned && trim($cleaned[0]) === '') {
            array_shift($cleaned);
        }
        while ($cleaned && trim(end($cleaned)) === '') {
            array_pop($cleaned);
        }

        return implode("\n", $cleaned);
    }
}
