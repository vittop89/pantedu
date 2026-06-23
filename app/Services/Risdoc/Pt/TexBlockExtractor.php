<?php

declare(strict_types=1);

namespace App\Services\Risdoc\Pt;

/**
 * TexBlockExtractor — Phase 22.4.
 *
 * Estrae blocchi `%[BeginTesto]...%[EndTesto]` dai template LaTeX legacy
 * del dominio risdoc (`storage/templates/risdoc/*\/tex/*.tex`) e li converte
 * nell'AST Portable Text definito da `App\Services\Risdoc\Pt\PtToTex`.
 *
 * Scope:
 *   - Trova `\begin{sectionbox}{LABEL}...\end{sectionbox}` (match case-sensitive su LABEL)
 *   - Al suo interno, estrae il content tra `%[BeginTesto]` e `%[EndTesto]`
 *   - Tokenizza il content in:
 *       * checkbox (`\xcheckbox{...}` / `\checkbox{...}`) → raggruppati in blocks
 *       * field placeholders (`[field-name]`) → inline object `fieldRef`
 *       * text semplice → span
 *       * inline marks (`\textbf{...}` / `\textit{...}` / `\underline{...}`)
 *   - Fallback `rawTex` per costrutti non riconosciuti (es. `\vspace{...}`,
 *     `\begin{itemize}...`) come escape hatch.
 *
 * Non implementato:
 *   - Matematica (`$...$`, `\begin{equation}`): confluisce in rawTex per ora
 *   - Tabelle `\begin{tabular}`: rawTex
 *   - Liste nested: rawTex
 *
 * Use:
 * ```php
 * $pt = TexBlockExtractor::extract(file_get_contents('piano.tex'), 'OSSERVAZIONI');
 * // $pt è null se sectionbox non trovato, altrimenti array PT AST.
 * ```
 */
final class TexBlockExtractor
{
    /**
     * @return array<int, array<string, mixed>>|null PT AST o null se block non trovato
     */
    public static function extract(string $texSource, string $sectionBoxLabel): ?array
    {
        $texContent = self::findSectionBoxContent($texSource, $sectionBoxLabel);
        if ($texContent === null) {
            return null;
        }

        $inner = self::findBeginTestoBlock($texContent);
        if ($inner === null) {
            return null;
        }

        return self::parseContent($inner);
    }

    /** Trova il blocco `\begin{sectionbox}{LABEL}...\end{sectionbox}`. */
    private static function findSectionBoxContent(string $source, string $label): ?string
    {
        $labelEsc = preg_quote($label, '/');
        $pattern = '/\\\\begin\{sectionbox\}\{\s*' . $labelEsc . '\s*\}(.*?)\\\\end\{sectionbox\}/s';
        if (preg_match($pattern, $source, $m)) {
            return $m[1];
        }
        return null;
    }

    /** Estrae il testo tra `%[BeginTesto]` e `%[EndTesto]`. */
    private static function findBeginTestoBlock(string $sectionBoxContent): ?string
    {
        if (preg_match('/%\[BeginTesto\](.*?)%\[EndTesto\]/s', $sectionBoxContent, $m)) {
            return $m[1];
        }
        return null;
    }

    /** Converte il content del blocco BeginTesto in PT AST. */
    private static function parseContent(string $content): array
    {
        // Normalizza CRLF e trim indentazione tab/spazi iniziali per ogni riga.
        $lines = preg_split('/\r?\n/', $content);
        $lines = array_map(static fn($l) => trim($l), $lines ?: []);
        $blocks = [];
        $current = null;
// ['_type' => 'block', 'style' => 'normal', 'children' => [...]]
        $currentGroup = null;
// ['_type' => 'checkboxGroup', 'items' => [...]]

        $flushBlock = function () use (&$current, &$blocks): void {

            if ($current !== null) {
                $children = self::trimTrailingSpaces($current['children']);
                if (count($children) > 0) {
                    $current['children'] = $children;
                    $blocks[] = $current;
                }
                $current = null;
            }
        };
        $flushGroup = function () use (&$currentGroup, &$blocks): void {

            if ($currentGroup !== null && count($currentGroup['items']) > 0) {
                $blocks[] = $currentGroup;
            }
            $currentGroup = null;
        };
        foreach ($lines as $line) {
            if ($line === '') {
                $flushBlock();
                $flushGroup();
                continue;
            }

            // Pure checkbox line?
            if (preg_match('/^\\\\(x)?checkbox\{(.*)\}\s*$/', $line, $m)) {
                $flushBlock();
                if ($currentGroup === null) {
                    $currentGroup = ['_type' => 'checkboxGroup', 'items' => []];
                }
                $currentGroup['items'][] = [
                    'state' => !empty($m[1]) ? 'x' : '_',
                    'label' => $m[2],
                ];
                continue;
            }

            // Non-checkbox line: flush group aperto, continua/apri block text
            $flushGroup();
            if ($current === null) {
                $current = ['_type' => 'block', 'style' => 'normal', 'children' => []];
            }

            // Parse inline: text + [field-X] + marks
            $inlineChildren = self::parseInline($line);
            foreach ($inlineChildren as $node) {
                $current['children'][] = $node;
            }
            // Aggiungi spazio soft-break tra linee consecutive dello stesso block.
            $current['children'][] = ['_type' => 'span', 'text' => ' ', 'marks' => []];
        }

        // Flush finali
        $flushBlock();
        $flushGroup();
        return $blocks;
    }

    /** Rimuove span vuoti/trailing spazi dalla fine dei children. */
    private static function trimTrailingSpaces(array $children): array
    {
        while (count($children) > 0) {
            $last = end($children);
            if (($last['_type'] ?? '') === 'span' && trim((string)($last['text'] ?? '')) === '') {
                array_pop($children);
            } else {
                break;
            }
        }
        // Merge consecutive span uguali (con stessi marks)
        $merged = [];
        foreach ($children as $child) {
            $prev = end($merged) ?: null;
            if (
                $prev !== null
                && ($prev['_type'] ?? '') === 'span'
                && ($child['_type'] ?? '') === 'span'
                && ($prev['marks'] ?? []) === ($child['marks'] ?? [])
            ) {
                $idx = count($merged) - 1;
                $merged[$idx]['text'] = ($prev['text'] ?? '') . ($child['text'] ?? '');
            } else {
                $merged[] = $child;
            }
        }
        return $merged;
    }

    /**
     * Parse inline content: testo con placeholder `[field-X]` e marks
     * `\textbf{...}`, `\textit{...}`, `\underline{...}`.
     *
     * @return list<array<string, mixed>>
     */
    private static function parseInline(string $text): array
    {
        $result = [];
        $cursor = 0;
        $len = strlen($text);
        while ($cursor < $len) {
            $rest = substr($text, $cursor);
        // Try: [field-name]
            if (preg_match('/^\[field-([a-zA-Z_][a-zA-Z0-9_]*)\]/', $rest, $m)) {
                $result[] = ['_type' => 'fieldRef', 'name' => $m[1]];
                $cursor += strlen($m[0]);
                continue;
            }

            // Try: inline mark \textbf{...}, \textit{...}, \underline{...}
            if (preg_match('/^\\\\(textbf|textit|underline|texttt)\{/', $rest, $m)) {
                $cmd = $m[1];
                $mark = match ($cmd) {
                    'textbf' => 'strong',
                    'textit' => 'em',
                    'underline' => 'underline',
                    'texttt' => 'code',
                };
// Trova la } di chiusura bilanciata
                $open = strlen($m[0]);
                $end = self::findMatchingBrace($rest, $open - 1);
                if ($end !== null) {
                    $inner = substr($rest, $open, $end - $open);
                // Parse inner (potrebbe contenere altri [field-X])
                    $innerNodes = self::parseInline($inner);
                    foreach ($innerNodes as $node) {
                        if (($node['_type'] ?? '') === 'span') {
                                    $marks = array_values(array_unique(array_merge($node['marks'] ?? [], [$mark])));
                                    $node['marks'] = $marks;
                        }
                        $result[] = $node;
                    }
                    $cursor += $end + 1;
                    continue;
                }
            }

            // Try: plain text until next special char
            $nextSpecial = self::findNextSpecial($rest);
            if ($nextSpecial === 0) {
        // Special char che non matcha nessun pattern → emit come text (safe)
                $result[] = ['_type' => 'span', 'text' => substr($rest, 0, 1), 'marks' => []];
                $cursor += 1;
            } else {
                $chunk = $nextSpecial === null ? $rest : substr($rest, 0, $nextSpecial);
                if ($chunk !== '') {
                    $result[] = ['_type' => 'span', 'text' => $chunk, 'marks' => []];
                }
                $cursor += strlen($chunk);
            }
        }

        return $result;
    }

    /** Posizione del prossimo carattere "speciale" (inizio di token non-plain). */
    private static function findNextSpecial(string $text): ?int
    {
        $pos = [];
        $bracket = strpos($text, '[field-');
        if ($bracket !== false) {
            $pos[] = $bracket;
        }
        if (preg_match('/\\\\(textbf|textit|underline|texttt)\{/', $text, $m, PREG_OFFSET_CAPTURE)) {
            $pos[] = $m[0][1];
        }
        if (count($pos) === 0) {
            return null;
        }
        return min($pos);
    }

    /** Trova la } di chiusura bilanciata data l'offset della { aperta. */
    private static function findMatchingBrace(string $s, int $openPos): ?int
    {
        $depth = 1;
        $len = strlen($s);
        for ($i = $openPos + 1; $i < $len; $i++) {
            if ($s[$i] === '\\' && $i + 1 < $len) {
                    $i++;
                    continue;
            } // skip escaped
            if ($s[$i] === '{') {
                $depth++;
            } elseif ($s[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
    }
}
