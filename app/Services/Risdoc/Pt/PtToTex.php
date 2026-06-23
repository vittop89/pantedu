<?php

declare(strict_types=1);

namespace App\Services\Risdoc\Pt;

/**
 * Portable Text → LaTeX walker.
 *
 * Phase 22.1 POC — supporta il subset minimo definito in
 * `schemas/risdoc/_pt/README.md`:
 *
 *   Block types:    block (style=normal), checkboxGroup, rawTex
 *   Inline types:   span (+ marks strong/em/underline/code), fieldRef
 *
 * Input: array PHP (JSON decoded) con struttura Portable Text root = array of blocks.
 * Output: stringa LaTeX con blocchi separati da doppia newline.
 *
 * Non implementato in questa phase:
 *   - sectionbox/vspace/choice/lists nested/h2-h3/tabelle
 *   - `listItem`/`level` Portable Text list handling
 *
 * Use:
 * ```php
 * $tex = PtToTex::render($ptArray);
 * ```
 */
final class PtToTex
{
    /** Context corrente del rendering (fields+state). Static = request-scoped. */
    private static array $ctx = ['fields' => [], 'state' => []];
/**
     * @param array<int, array<string, mixed>> $blocks Portable Text root array
     * @param array{fields?:array,state?:array} $context  Phase 24.29: per
     *        risolvere fieldRef inline (`[field-X]` → state[X] o fields[X]).
     */
    public static function render(array $blocks, array $context = []): string
    {
        self::$ctx = [
            'fields' => (array)($context['fields'] ?? []),
            'state'  => (array)($context['state']  ?? []),
        ];
        $parts = [];
// Phase 24.29 + 24.31 — sectionbox auto-wrap level-aware:
        //   - sectionHeader title matching whitelist apre `\begin{sectionbox}{LABEL}`
        //   - chiude SOLO al prossimo sectionHeader con level <= livello aperto
        //     (così sub-headers level più profondi restano DENTRO il box)
        //   - end-of-blocks chiude residui.
        // STACK dei livelli sectionbox aperti (supporta box annidati: un header
        // boxed più profondo apre un box DENTRO quello esterno). Bugfix: la
        // vecchia logica tracciava UN solo box → header L2+L3 stesso titolo
        // boxed (es. CONOSCENZE) apriva 2 \begin{sectionbox} ma chiudeva 1 solo
        // → \begin{tcb@savebox} non chiuso → compile fail.
        $boxStack = [];
        $closeBoxesGE = function (int $level) use (&$parts, &$boxStack) {
            // Chiude tutti i box con livello >= $level (li "termina" il nuovo header).
            while (!empty($boxStack) && end($boxStack) >= $level) {
                array_pop($boxStack);
                $parts[] = ['content' => '\\end{sectionbox}', 'inline' => false];
            }
        };
        // Esclusione sezione (👁 in output off): salta header + contenuto fino
        // al prossimo sectionHeader con level <= a quello escluso (level-aware).
        $excludeUntil = null;
        $nBlocks = count($blocks);
        for ($bi = 0; $bi < $nBlocks; $bi++) {
            $block = $blocks[$bi];
            if (!is_array($block) || !isset($block['_type'])) {
                continue;
            }
            if (($block['_type'] ?? '') === 'sectionHeader') {
                $hlvl = (int)($block['level'] ?? 2);
                if ($excludeUntil !== null && $hlvl <= $excludeUntil) {
                    $excludeUntil = null; // fine della sezione esclusa
                }
                if ($excludeUntil === null && !empty($block['excluded'])) {
                    $closeBoxesGE($hlvl); // chiude box aperti prima di saltare
                    $excludeUntil = $hlvl;
                    continue;
                }
            }
            if ($excludeUntil !== null) {
                continue; // blocco dentro una sezione esclusa
            }
            // Liste (elenchi): blocchi consecutivi con `listItem` → un'unica lista
            // annidata itemize/enumerate (enumitem) con la variante listStyle.
            if ($block['_type'] === 'block' && !empty($block['listItem'])) {
                $run = [];
                while (
                    $bi < $nBlocks
                    && is_array($blocks[$bi])
                    && ($blocks[$bi]['_type'] ?? '') === 'block'
                    && !empty($blocks[$bi]['listItem'])
                ) {
                    $run[] = $blocks[$bi];
                    $bi++;
                }
                $bi--; // il for incrementa
                $parts[] = ['content' => self::renderListRun($run), 'inline' => false];
                continue;
            }
            if ($block['_type'] === 'sectionHeader') {
                $title = (string)($block['title'] ?? '');
                $level = (int)($block['level'] ?? 2);
        // Phase 24.32 — `boxed:true` attribute esplicito ha priorità su
                // matchSectionboxLabel (whitelist auto). User-driven via UI.
                $boxLabel = !empty($block['boxed']) && $title !== ''
                    ? $title
                    : self::matchSectionboxLabel($title);
                // Un nuovo header chiude i box pari/più profondi prima di aprirne uno.
                $closeBoxesGE($level);
                if ($boxLabel !== null && $boxLabel !== '') {
                    $parts[] = ['content' => '\\begin{sectionbox}{' . TexEscape::escape($boxLabel) . '}', 'inline' => false];
                    $boxStack[] = $level;
                    continue;
        // skip standard sectionHeader render
                }
            }
            $rendered = self::renderBlock($block);
            if ($rendered === '') {
                continue;
            }
            $parts[] = [
                'content' => $rendered,
                'inline'  => self::isInlineBlock($block),
            ];
        }
        // Chiude tutti i box ancora aperti a fine documento (balance garantito).
        while (!empty($boxStack)) {
            array_pop($boxStack);
            $parts[] = ['content' => '\\end{sectionbox}', 'inline' => false];
        }
        $out = '';
        $n = count($parts);
        for ($i = 0; $i < $n; $i++) {
            if ($i > 0) {
                $useInlineSep = $parts[$i]['inline'] || $parts[$i - 1]['inline'];
                $out .= $useInlineSep ? ' ' : "\n\n";
            }
            $out .= $parts[$i]['content'];
        }
        return $out;
    }

    /**
     * Phase 24.29 — match title sectionHeader → label sectionbox legacy.
     * Ritorna LABEL (uppercase) se title matcha (case-insensitive contains),
     * null se non trasformare.
     */
    private static function matchSectionboxLabel(string $title): ?string
    {
        $t = mb_strtolower(trim($title));
        if ($t === '') {
            return null;
        }
        // Phase 24.31 — match esatto (case-insens) per le sezioni di
        // OBIETTIVI DISCIPLINARI: COMPETENZE, ABILITÀ/ABILITA, CONOSCENZE.
        // Match esatto evita false-positive (es. "elenco competenze" non
        // dovrebbe wrappare).
        $exact = [
            'competenze' => 'COMPETENZE',
            'abilità'    => 'ABILITÀ',
            'abilita'    => 'ABILITÀ',
            'conoscenze' => 'CONOSCENZE',
        ];
        if (isset($exact[$t])) {
            return $exact[$t];
        }
        // Match per substring (case-insens) per i campi storici nota-textarea
        $map = [
            'profilo della classe'         => 'OSSERVAZIONI',
            'osservazioni'                 => 'OSSERVAZIONI',
            'educazione civica'            => 'ATTIVITÀ DI EDUCAZIONE CIVICA',
            'programma svolto'             => 'CONTENUTI EFFETTIVAMENTE SVOLTI',
            'contenuti svolti'             => 'CONTENUTI EFFETTIVAMENTE SVOLTI',
            'contenuti effettivamente'     => 'CONTENUTI EFFETTIVAMENTE SVOLTI',
        ];
        foreach ($map as $needle => $label) {
            if (mb_strpos($t, $needle) !== false) {
                return $label;
            }
        }
        return null;
    }

    /**
     * Rende un run di blocchi-lista PT (listItem+level+listStyle) come liste
     * itemize/enumerate annidate (enumitem). I `level` (1-based) controllano
     * l'annidamento; `listStyle` la variante delle etichette.
     *
     * @param list<array> $run
     */
    private static function renderListRun(array $run): string
    {
        $out = '';
        $stack = [];      // env aperti (per livello): 'itemize'|'enumerate'
        $openLevel = 0;
        foreach ($run as $b) {
            $level   = max(1, (int)($b['level'] ?? 1));
            $ordered = (($b['listItem'] ?? '') === 'number');
            $style   = (string)($b['listStyle'] ?? '');
            // Apri i livelli mancanti fino a $level.
            while ($openLevel < $level) {
                $depth = $openLevel + 1;
                $env   = $ordered ? 'enumerate' : 'itemize';
                $out  .= "\\begin{" . $env . "}" . self::listLabelOpt($ordered, $style, $depth) . "\n";
                $stack[] = $env;
                $openLevel++;
            }
            // Chiudi i livelli in eccesso fino a $level.
            while ($openLevel > $level) {
                $out .= "\\end{" . array_pop($stack) . "}\n";
                $openLevel--;
            }
            // Contenuto inline dell'item (no wrap allineamento dentro \item).
            $content = '';
            foreach ((array)($b['children'] ?? []) as $child) {
                if (is_array($child) && isset($child['_type'])) {
                    $content .= self::renderInline($child);
                }
            }
            $out .= "\\item " . $content . "\n";
        }
        while ($openLevel > 0) {
            $out .= "\\end{" . array_pop($stack) . "}\n";
            $openLevel--;
        }
        return rtrim($out);
    }

    /**
     * Opzione enumitem `[label=…]` per la variante lista a una data profondità.
     * Ordered: schemi per-livello (es. A.→1.→a.); separatore . o ) per le
     * varianti -paren. Bullet: simboli base LaTeX (no amssymb) o default itemize.
     */
    private static function listLabelOpt(bool $ordered, string $style, int $depth): string
    {
        // SORGENTE UNICA: riusa la mappatura preset→label di Sanitizer (stessa
        // resa LaTeX degli esercizi/verifiche, incl. zero-pad 0\arabic* e
        // $\rightarrow$/$\blacksquare$). Niente duplicazione locale.
        $label = \App\Services\TexBuilder\Sanitizer::listLabel($style, $ordered, $depth);
        return $label !== '' ? '[label=' . $label . ']' : '';
    }

    /** Block è inline-flow (non richiede paragraph break intorno). */
    private static function isInlineBlock(array $block): bool
    {
        if (
            ($block['_type'] ?? '') === 'checkboxGroup'
            && ($block['renderMode'] ?? 'all') === 'checked-inline'
        ) {
            return true;
        }
        return false;
    }

    private static function renderBlock(array $block): string
    {
        return match ($block['_type']) {
            'block'          => self::renderTextBlock($block),
            'checkboxGroup'  => self::renderCheckboxGroup($block),
            'rawTex'         => self::renderRawTex($block),
            'table'          => self::renderTable($block),
            'select'         => self::renderSelect($block),
            'textField'      => self::renderTextField($block),
            'formCheckbox'   => self::renderFormCheckbox($block),
            'sectionHeader'  => self::renderSectionHeader($block),
            // G23 page-doc block types (Sprint 7) — render LaTeX per PDF export
            'glossaryTable'  => self::renderGlossaryTablePt($block),
            'staticContent'  => self::renderStaticContentPt($block),
            'accordion'      => self::renderAccordionPt($block),
            'linkListPdf'    => self::renderLinkListPdfPt($block),
            'citationNorma'  => self::renderCitationNormaPt($block),
            default          => '', // _type sconosciuto → skip silenzioso
        };
    }

    /**
     * G23 page-doc — glossaryTable → tabular LaTeX longtable (multi-page safe).
     * Pattern: [|c|p{3cm}|p{6cm}|p{3cm}|] header + rows + \hline.
     */
    private static function renderGlossaryTablePt(array $b): string
    {
        $cols    = is_array($b['columns'] ?? null) ? $b['columns'] : [];
        $entries = is_array($b['entries'] ?? null) ? $b['entries'] : [];
        if (count($cols) === 0) {
            return '';
        }

        // Column spec: numeric col centered narrow, text col paragraph wide
        $colSpec = [];
        foreach ($cols as $c) {
            $key = self::headerToKeyPt((string)$c);
            $colSpec[] = $key === 'n' ? 'c' : 'p{4cm}';
        }
        $align = '|' . implode('|', $colSpec) . '|';

        $header = implode(' & ', array_map(
            static fn($c) => '\\textbf{' . TexEscape::escape((string)$c) . '}',
            $cols
        ));

        $rows = '';
        foreach ($entries as $e) {
            if (!is_array($e)) {
                continue;
            }
            $cells = [];
            foreach ($cols as $c) {
                $key = self::headerToKeyPt((string)$c);
                $cells[] = TexEscape::escape((string)($e[$key] ?? ''));
            }
            $rows .= implode(' & ', $cells) . " \\\\\n\\hline\n";
        }

        return "\\begin{longtable}{{$align}}\n"
             . "\\hline\n"
             . "{$header} \\\\\n"
             . "\\hline\n"
             . "\\endhead\n"
             . $rows
             . "\\end{longtable}";
    }

    /**
     * G23 page-doc — staticContent → \section* + body HTML stripped to LaTeX.
     * Nesting items → ricorsivo. HTML basic tags convertiti (p/strong/em/ul/ol/li).
     */
    private static function renderStaticContentPt(array $b): string
    {
        $level = max(2, min(4, (int)($b['level'] ?? 2)));
        $title = (string)($b['title'] ?? '');
        $body  = (string)($b['body']  ?? '');
        $items = is_array($b['items'] ?? null) ? $b['items'] : [];

        // Map level 2->section, 3->subsection, 4->subsubsection
        $secCmd = ['section*', 'subsection*', 'subsubsection*'][$level - 2] ?? 'section*';

        $out = '';
        if ($title !== '') {
            $out .= "\\{$secCmd}{" . TexEscape::escape($title) . "}\n\n";
        }
        if ($body !== '') {
            $out .= self::htmlToLatex($body) . "\n\n";
        }
        foreach ($items as $it) {
            if (is_array($it) && ($it['_type'] ?? '') === 'staticContent') {
                $out .= self::renderStaticContentPt($it);
            }
        }
        return rtrim($out);
    }

    /**
     * G23 page-doc — accordion → \section* per ogni item (PDF non interattivo).
     * body_pt nested renderizzato ricorsivamente.
     */
    private static function renderAccordionPt(array $b): string
    {
        $items = is_array($b['items'] ?? null) ? $b['items'] : [];
        if (count($items) === 0) {
            return '';
        }

        $out = '';
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            if (!empty($it['excluded'])) {
                continue; // item escluso → omesso dal TeX
            }
            $title = (string)($it['title'] ?? '');
            if ($title !== '') {
                $out .= "\\subsection*{" . TexEscape::escape($title) . "}\n\n";
            }
            $bodyPt = is_array($it['body_pt'] ?? null) ? $it['body_pt'] : [];
            foreach ($bodyPt as $sub) {
                if (is_array($sub)) {
                    $rendered = self::renderBlock($sub);
                    if ($rendered !== '') {
                        $out .= $rendered . "\n\n";
                    }
                }
            }
        }
        return rtrim($out);
    }

    /**
     * G23 page-doc — linkListPdf → \section* title + itemize con \href{url}{label}.
     * Sub_items annidati come itemize nested.
     */
    private static function renderLinkListPdfPt(array $b): string
    {
        $title = (string)($b['title'] ?? '');
        $items = is_array($b['items'] ?? null) ? $b['items'] : [];
        if (count($items) === 0) {
            return '';
        }

        $out = '';
        if ($title !== '') {
            $out .= "\\subsection*{" . TexEscape::escape($title) . "}\n\n";
        }
        $out .= "\\begin{itemize}\n";
        foreach ($items as $it) {
            $out .= self::renderLinkListItemPt($it);
        }
        $out .= "\\end{itemize}";
        return $out;
    }

    private static function renderLinkListItemPt(mixed $item): string
    {
        if (!is_array($item)) {
            return '';
        }
        $label = (string)($item['label'] ?? '');
        $href  = (string)($item['href']  ?? '');
        if ($label === '' || $href === '') {
            return '';
        }

        $hrefEsc = self::escUrl($href);
        $labelEsc = TexEscape::escape($label);
        $out = "  \\item \\href{{$hrefEsc}}{{$labelEsc}}";

        $desc = (string)($item['description'] ?? '');
        if ($desc !== '') {
            $out .= "\\\\ \\textit{" . TexEscape::escape($desc) . "}";
        }
        $subs = is_array($item['sub_items'] ?? null) ? $item['sub_items'] : [];
        if (count($subs) > 0) {
            $out .= "\n    \\begin{itemize}\n";
            foreach ($subs as $s) {
                $out .= '  ' . self::renderLinkListItemPt($s);
            }
            $out .= "    \\end{itemize}";
        }
        return $out . "\n";
    }

    /**
     * G23 page-doc — citationNorma → \begin{quote}{TIPO N/ANNO art X}\n quote \n\end{quote}.
     */
    private static function renderCitationNormaPt(array $b): string
    {
        $tipo     = (string)($b['tipo'] ?? 'altro');
        $numero   = (string)($b['numero'] ?? '');
        $anno     = isset($b['anno']) ? (string)$b['anno'] : '';
        $articolo = (string)($b['articolo'] ?? '');
        $title    = (string)($b['title'] ?? '');
        $href     = (string)($b['href'] ?? '');
        $quote    = (string)($b['quote'] ?? '');

        $headLabel = trim(implode(' ', array_filter([$tipo, $numero, $anno])));
        if ($headLabel === '') {
            $headLabel = $tipo;
        }

        $out = "\\paragraph{" . TexEscape::escape($headLabel);
        if ($articolo !== '') {
            $out .= " \\textit{" . TexEscape::escape($articolo) . "}";
        }
        $out .= "}\n\n";

        if ($quote !== '') {
            $out .= "\\begin{quote}\n"
                  . TexEscape::escape($quote)
                  . "\n\\end{quote}\n";
        }
        if ($title !== '' || $href !== '') {
            $t = $title !== '' ? $title : $headLabel;
            if ($href !== '') {
                $out .= "\\href{" . self::escUrl($href) . "}{" . TexEscape::escape($t) . "}";
            } else {
                $out .= TexEscape::escape($t);
            }
        }
        return rtrim($out);
    }

    /** G23 helper: header label → entry key (mirror PtToHtml::headerToKey). */
    private static function headerToKeyPt(string $header): string
    {
        $h = mb_strtolower($header, 'UTF-8');
        $h = str_replace('.', '', $h);
        $h = strtr($h, ['à' => 'a','á' => 'a','â' => 'a','ä' => 'a','è' => 'e','é' => 'e','ê' => 'e','ë' => 'e','ì' => 'i','í' => 'i','î' => 'i','ï' => 'i','ò' => 'o','ó' => 'o','ô' => 'o','ö' => 'o','ù' => 'u','ú' => 'u','û' => 'u','ü' => 'u']);
        $h = preg_replace('/\s+/', '_', $h);
        $h = preg_replace('/[^a-z0-9_]/', '', $h);
        return trim($h, '_');
    }

    /** G23 helper: escape URL per LaTeX \href (no escape di & dentro URL). */
    private static function escUrl(string $url): string
    {
        // LaTeX \href accetta URL grezzi, ma `%` e `#` vanno escapati.
        return str_replace(['%', '#'], ['\\%', '\\#'], $url);
    }

    /**
     * G23 helper: HTML basic → LaTeX (semplice converter per staticContent body).
     * Subset: <p>, <strong>/<b>, <em>/<i>, <ul>, <ol>, <li>, <a href>, <blockquote>,
     *         <code>, <br>, <h2>-<h4>.
     */
    private static function htmlToLatex(string $html): string
    {
        $h = $html;
        // Block-level tags
        $h = preg_replace_callback('#<h([234])[^>]*>(.+?)</h\1>#is', function ($m) {
            $cmd = ['section*', 'subsection*', 'subsubsection*'][(int)$m[1] - 2];
            return "\\{$cmd}{" . TexEscape::escape(strip_tags($m[2])) . "}\n";
        }, $h) ?? $h;
        $h = preg_replace_callback(
            '#<blockquote[^>]*>(.+?)</blockquote>#is',
            fn($m) => "\\begin{quote}\n" . TexEscape::escape(strip_tags($m[1])) . "\n\\end{quote}\n",
            $h
        ) ?? $h;
        $h = preg_replace_callback(
            '#<ul[^>]*>(.+?)</ul>#is',
            fn($m) => "\\begin{itemize}\n" . self::htmlListItems($m[1]) . "\\end{itemize}\n",
            $h
        ) ?? $h;
        $h = preg_replace_callback(
            '#<ol[^>]*>(.+?)</ol>#is',
            fn($m) => "\\begin{enumerate}\n" . self::htmlListItems($m[1]) . "\\end{enumerate}\n",
            $h
        ) ?? $h;
        // Inline tags
        $h = preg_replace_callback(
            '#<a[^>]*href="([^"]+)"[^>]*>(.+?)</a>#is',
            fn($m) => '\\href{' . self::escUrl($m[1]) . '}{' . TexEscape::escape(strip_tags($m[2])) . '}',
            $h
        ) ?? $h;
        $h = preg_replace('#<(strong|b)[^>]*>(.+?)</\1>#is', '\\\\textbf{$2}', $h) ?? $h;
        $h = preg_replace('#<(em|i)[^>]*>(.+?)</\1>#is', '\\\\textit{$2}', $h) ?? $h;
        $h = preg_replace('#<code[^>]*>(.+?)</code>#is', '\\\\texttt{$1}', $h) ?? $h;
        $h = preg_replace('#<br\s*/?>#i', "\\\\\\\\\n", $h) ?? $h;
        // Paragraph: wrap text content in \par
        $h = preg_replace_callback(
            '#<p[^>]*>(.+?)</p>#is',
            fn($m) => trim($m[1]) . "\n\n",
            $h
        ) ?? $h;
        // Final cleanup: strip residue tags + escape leftover non-LaTeX chars
        $h = strip_tags($h);
        return trim($h);
    }

    private static function htmlListItems(string $inner): string
    {
        return preg_replace_callback(
            '#<li[^>]*>(.+?)</li>#is',
            fn($m) => '  \\item ' . TexEscape::escape(strip_tags($m[1])) . "\n",
            $inner
        ) ?? '';
    }

    /** Block type `block`: paragrafo con children (span, fieldRef, ...). */
    private static function renderTextBlock(array $block): string
    {
        $children = $block['children'] ?? [];
        if (!is_array($children)) {
            return '';
        }

        $parts = [];
        foreach ($children as $child) {
            if (!is_array($child) || !isset($child['_type'])) {
                continue;
            }
            $parts[] = self::renderInline($child);
        }
        $inner = implode('', $parts);
        if ($inner === '') {
            return '';
        }
        // Allineamento paragrafo (Google Docs → LaTeX). left/justify = default
        // tipografico LaTeX (giustificato) → nessun wrap; center/right wrappati.
        $align = $block['textAlign'] ?? null;
        if ($align === 'center') {
            return "\\begin{center}\n" . $inner . "\n\\end{center}";
        }
        if ($align === 'right') {
            return "{\\raggedleft " . $inner . "\\par}";
        }
        if ($align === 'left') {
            return "{\\raggedright " . $inner . "\\par}";
        }
        return $inner;
    }

    /** Inline: span (con marks) + fieldRef. Estensibile ad altri _type. */
    private static function renderInline(array $child): string
    {
        return match ($child['_type']) {
            'span'     => self::renderSpan($child),
            'fieldRef' => self::renderFieldRef($child),
            default    => '',
        };
    }

    /** Span: escape LaTeX + applica marks standard (innermost = ultimo applicato). */
    private static function renderSpan(array $span): string
    {
        $text  = (string)($span['text'] ?? '');
        $marks = $span['marks'] ?? [];
        $esc   = TexEscape::escape($text);
        if (!is_array($marks) || count($marks) === 0) {
            return $esc;
        }

        // Ordine: il primo mark è più esterno, l'ultimo più interno.
        // Renderiamo dall'interno verso l'esterno (reverse iter).
        foreach (array_reverse($marks) as $mark) {
            $esc = self::applyMark((string)$mark, $esc);
        }
        return $esc;
    }

    private static function applyMark(string $mark, string $inner): string
    {
        return match ($mark) {
            'strong'    => '\\textbf{' . $inner . '}',
            'em'        => '\\textit{' . $inner . '}',
            'underline' => '\\underline{' . $inner . '}',
            'code'      => '\\texttt{' . $inner . '}',
            default     => $inner, // mark sconosciuto → passa inner
        };
    }

    /** Custom inline: fieldRef → `[field-name]` placeholder risolto da TexBuilder. */
    private static function renderFieldRef(array $node): string
    {
        $name = (string)($node['name'] ?? '');
        if ($name === '') {
            return '';
        }
        // Phase 24.29 — risolvi via context: state[name] > fields[name].
        // Se il valore è non-empty, escapa e ritorna inline. Altrimenti
        // fallback a "[field-name]" placeholder (compat legacy).
        $val = self::$ctx['state'][$name] ?? self::$ctx['fields'][$name] ?? null;
        if (\is_string($val) && $val !== '') {
            return TexEscape::escape($val);
        }
        if (\is_array($val)) {
            return TexEscape::escape(\implode(', ', \array_map('strval', $val)));
        }
        return '[field-' . $name . ']';
    }

    /**
     * Custom block: checkboxGroup.
     * renderMode:
     *   "all"            (default) → tutti items in colonna (itemize) con marker
     *                      box \item[\xcheckbox] (spuntato) / \item[\checkbox]
     *   "checked-only"   → solo items spuntati come itemize bullet
     *   "checked-inline" → solo items spuntati, labels separate da virgole,
     *                      inline (NO itemize, NO bullet). Utile quando il
     *                      gruppo è contiguo a testo prosastico.
     * Phase 24.19 + 24.23
     */
    private static function renderCheckboxGroup(array $block): string
    {
        $items = $block['items'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }
        $mode = (string)($block['renderMode'] ?? 'all');
        // Impaginazione su N colonne (1–5): multicols{N} se N>=2, altrimenti a colonna unica.
        $nCols = max(1, min(5, (int)($block['columns'] ?? 1)));
        $wrapCols = static fn(string $body): string => $nCols >= 2
            ? "\\begin{multicols}{" . $nCols . "}\n" . $body . "\n\\end{multicols}"
            : $body;

        // ADR-026 #2 — body_pt salvato compatto (de-idratato): items contiene
        // SOLO le selezioni. Per renderMode='all' serve fetchare il framework
        // server-side (options_source) + merge con state degli items salvati.
        // Senza questo, PDF teacher_content forkato da master rendeva box vuoti.
        if ($mode === 'all' && !empty($block['options_source'])) {
            $fetched = self::fetchOptionsSource((array)$block['options_source']);
            if (!empty($fetched)) {
                // Mappa state degli items salvati per label/value → selezioni preservate.
                $selectedKeys = [];
                foreach ($items as $it) {
                    if (!is_array($it)) {
                        continue;
                    }
                    if ((string)($it['state'] ?? '_') === 'x') {
                        if (isset($it['value'])) {
                            $selectedKeys[(string)$it['value']] = true;
                        }
                        if (isset($it['label'])) {
                            $selectedKeys[(string)$it['label']] = true;
                        }
                    }
                }
                $items = [];
                foreach ($fetched as $opt) {
                    $label = (string)($opt['label'] ?? $opt['value'] ?? '');
                    $val   = (string)($opt['value'] ?? $opt['label'] ?? '');
                    $isSel = isset($selectedKeys[$label]) || isset($selectedKeys[$val]);
                    $items[] = [
                        'state' => $isSel ? 'x' : '_',
                        'label' => $label,
                        'value' => $val,
                        'group' => (string)($opt['group'] ?? ''),
                    ];
                }
            }
        }

        if (count($items) === 0) {
            return '';
        }
        if ($mode === 'checked-only' || $mode === 'checked-inline') {
            $checkedLabels = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if ((string)($item['state'] ?? '_') !== 'x') {
                    continue;
                }
                $label = (string)($item['label'] ?? '');
                if ($label === '') {
                    continue;
                }
                $checkedLabels[] = TexEscape::escape($label);
            }
            if (count($checkedLabels) === 0) {
                return '';
            }

            if ($mode === 'checked-inline') {
        // Inline: labels unite con virgole + punto finale, no linebreak.
                return implode(', ', $checkedLabels);
            }
            // checked-only: itemize
            $lines = ['\\begin{itemize}'];
            foreach ($checkedLabels as $l) {
                $lines[] = '  \\item ' . $l;
            }
            $lines[] = '\\end{itemize}';
            return $wrapCols(implode("\n", $lines));
        }

        // Default "all": colonna (un item per riga) con marker ☑/☐ resi come
        // label itemize \item[\xcheckbox] / \item[\checkbox]. Se gli item hanno
        // `group`, emette un'intestazione \textbf{gruppo} + un itemize per gruppo.
        $lines = [];
        $lastGroup = null;
        $open = false;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $g = (string)($item['group'] ?? '');
            if ($g !== '' && $g !== $lastGroup) {
                if ($open) {
                    $lines[] = '\\end{itemize}';
                    $open = false;
                }
                $lines[] = '\\textbf{' . TexEscape::escape($g) . '}';
                $lastGroup = $g;
            }
            if (!$open) {
                $lines[] = '\\begin{itemize}';
                $open = true;
            }
            $state = (string)($item['state'] ?? '_');
            $label = TexEscape::escape((string)($item['label'] ?? ''));
            $mk    = ($state === 'x') ? '\\xcheckbox' : '\\checkbox';
            $lines[] = '  \\item[' . $mk . '] ' . $label;
        }
        if ($open) {
            $lines[] = '\\end{itemize}';
        }
        return $wrapCols(implode("\n", $lines));
    }

    /** ADR-026 #2 — Fetch JSON opzioni curriculum (file o folder per ind/cls/disc).
     *  Server-side mirror del client _options-fetcher.js. Usa self::$ctx['state']. */
    private static function fetchOptionsSource(array $src): array
    {
        $state = (array)(self::$ctx['state'] ?? []);
        $root = dirname(__DIR__, 4); // .../app/Services/Risdoc/Pt → .../app → root
        $path = null;
        if (!empty($src['file'])) {
            $path = $root . '/storage/templates/risdoc/' . ltrim((string)$src['file'], '/');
        } elseif (!empty($src['folder'])) {
            $ind = (string)($state['indirizzo']  ?? '');
            $cls = (string)($state['classe']     ?? '');
            $mat = (string)($state['disciplina'] ?? '');
            if ($ind === '' || $cls === '' || $mat === '') {
                return [];
            }
            $matL = strtolower($mat);
            $path = $root . '/storage/templates/risdoc/' . (string)$src['folder']
                  . '/' . $ind . '/' . $matL . '/'
                  . $ind . '_' . $cls . '_' . $matL . '.json';
        }
        if (!$path || !is_file($path)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (isset($node['contenuti']) && is_array($node['contenuti'])) {
                $g = (string)($node['titolo'] ?? '');
                foreach ($node['contenuti'] as $item) {
                    if (!is_array($item) || !isset($item['label'])) {
                        continue;
                    }
                    $out[] = [
                        'value' => (string)$item['label'],
                        'label' => (string)$item['label'],
                        'group' => $g,
                    ];
                }
            } elseif (isset($node['label'])) {
                $out[] = [
                    'value' => (string)$node['label'],
                    'label' => (string)$node['label'],
                ];
            }
        }
        return $out;
    }

    /** rawTex: content injected verbatim (escape hatch per costrutti non mappati). */
    private static function renderRawTex(array $block): string
    {
        return (string)($block['content'] ?? '');
    }

    /**
     * Phase 24.1 — table: `\begin{tabular}{|l|l|...|}` + header row + data rows,
     * \hline tra righe. Cells escape LaTeX. Row più corte della colonna count
     * vengono right-padded con stringhe vuote.
     */
    private static function renderTable(array $block): string
    {
        $columns = $block['columns'] ?? [];
        $rows    = $block['rows']    ?? [];
        if (!\is_array($columns) || \count($columns) === 0) {
            return '';
        }

        $colCount = \count($columns);
        // ADR-031 — calcola le formule della tabella (riferimenti = indice colonna
        // nell'array riga). $fres[$r][$c] = ['display'=>…, 'error'=>…].
        $fgrid = [];
        foreach ((\is_array($rows) ? $rows : []) as $ri => $row) {
            $fgrid[$ri] = [];
            for ($c = 0; $c < $colCount; $c++) {
                $cell = \is_array($row) ? ($row[$c] ?? null) : null;
                if (\is_array($cell) && isset($cell['formula']) && FormulaEngine::isFormula($cell['formula'])) {
                    $fgrid[$ri][$c] = ['formula' => $cell['formula']];
                } else {
                    $raw = '';
                    if (\is_string($cell)) {
                        $raw = $cell;
                    } elseif (\is_array($cell)) {
                        $wv = $cell['widget']['value'] ?? null;
                        $raw = \is_scalar($wv) ? (string)$wv : (string)($cell['text'] ?? '');
                    }
                    $fgrid[$ri][$c] = ['raw' => $raw];
                }
            }
        }
        try { $fres = FormulaEngine::computeTableValues($fgrid); } catch (\Throwable) { $fres = []; }
        // Larghezza: "full" → tabularx{\textwidth} con colonne X pesate (la
        // larghezza segue \textwidth, quindi rispetta portrait/landscape).
        // "auto" → tabular con colonne `l` (adatta al contenuto, default storico).
        $widthMode = (($block['widthMode'] ?? 'auto') === 'full') ? 'full' : 'auto';
        if ($widthMode === 'full') {
            $colSpec  = self::weightedColSpec(
                \is_array($block['colWidths'] ?? null) ? $block['colWidths'] : [],
                $colCount
            );
            $tableEnv = 'tabularx';
            $beginArg = '{\\textwidth}{' . $colSpec . '}';
        } else {
            $colSpec  = '|' . \str_repeat('l|', $colCount);
            $tableEnv = 'tabular';
            $beginArg = '{' . $colSpec . '}';
        }
        $lines = [];
        $lines[] = '\\begin{' . $tableEnv . '}' . $beginArg;
        $lines[] = '\\hline';
        $headerCells = \array_map(static fn($c) => TexEscape::escape((string)$c), \array_values($columns),);
        $lines[] = \implode(' & ', $headerCells) . ' \\\\';
        $lines[] = '\\hline';
        if (\is_array($rows)) {
            // BUG3 fix — rowspan handling via \multirow (pacchetto multirow è
            // incluso nel preamble risdoc.sty: \RequirePackage{multirow}).
            //
            // Modello dati (vedi pm-schema.js renderTable): ogni riga è un array
            // dove ogni slot corrisponde a UNA colonna di partenza; le celle
            // coperte da un merge orizzontale (colspan) o verticale (rowspan)
            // sono memorizzate come placeholder {merged:true} che occupano lo
            // slot di colonna ma NON producono contenuto.
            //
            // In `tabular` non esiste rowspan nativo: ogni riga deve emettere un
            // token per ogni colonna. Il vecchio codice faceva `continue` sulle
            // celle merged senza avanzare il cursore di colonna, perdendo la
            // fusione verticale E disallineando le colonne successive (il pad
            // finale aggiungeva celle vuote in coda anziché nella posizione
            // corretta). Qui costruiamo una griglia di occupazione 2D che
            // distingue merge orizzontali (coperti da \multicolumn → nessun
            // token extra) da merge verticali (coperti da \multirow → token
            // vuoto nelle righe sotto), preservando l'allineamento.

            $dataRows = [];
            foreach ($rows as $row) {
                if (\is_array($row)) {
                    $dataRows[] = \array_values($row);
                }
            }
            $rowCount = \count($dataRows);

            // Griglia di proprietà: grid[r][c] = ['owner'=>bool, 'kind'=>'h'|'v'|'origin'|'']
            //   origin: cella di partenza (emette contenuto)
            //   h: coperta da colspan a sinistra nella STESSA riga (nessun token)
            //   v: coperta da rowspan dall'alto (token vuoto nella riga)
            //   '': cella indipendente normale
            // Tracciamo anche per ogni origin quale span verticale è attivo,
            // così da gestire \cline anziché \hline e non tagliare i multirow.
            $grid = [];
            for ($r = 0; $r < $rowCount; $r++) {
                $grid[$r] = \array_fill(0, $colCount, null);
            }
            // vSpanRemaining[c] = righe ancora coperte da un rowspan attivo su col c
            $vSpanRemaining = \array_fill(0, $colCount, 0);

            for ($r = 0; $r < $rowCount; $r++) {
                $col = 0;
                foreach ($dataRows[$r] as $cell) {
                    // Salta colonne ancora occupate da un rowspan dall'alto.
                    while ($col < $colCount && $vSpanRemaining[$col] > 0) {
                        $grid[$r][$col] = ['kind' => 'v', 'content' => '', 'cspan' => 1];
                        $vSpanRemaining[$col]--;
                        $col++;
                    }
                    if ($col >= $colCount) {
                        break;
                    }
                    $norm = \is_array($cell) ? $cell : ['text' => (string)$cell];
                    // Placeholder {merged:true}: cella coperta da un merge della
                    // cella precedente nella STESSA riga (colspan). Se la colonna
                    // corrente è già stata riservata come 'h' dal colspan
                    // dell'origin, il placeholder è ridondante: lo si consuma
                    // SENZA avanzare $col (così la cella successiva nell'array
                    // mappa sulla prima colonna libera dopo il colspan). Questo
                    // riproduce il comportamento di pt-to-html.js renderTable
                    // (anteprima) e del vecchio serializzatore TeX.
                    if (\is_array($cell) && !empty($cell['merged'])) {
                        // No-op: la colonna coperta da questo placeholder è già
                        // stata riservata come 'h' dal colspan dell'origin (che
                        // avanza $col del proprio colspan completo). Lo si consuma
                        // senza toccare il cursore, in parità col vecchio
                        // serializzatore e con pt-to-html.js renderTable.
                        continue;
                    }
                    $colspan = \max(1, (int)($norm['colspan'] ?? 1));
                    $colspan = \min($colspan, $colCount - $col);
                    $rowspan = \max(1, (int)($norm['rowspan'] ?? 1));
                    $rowspan = \min($rowspan, $rowCount - $r);

                    // ADR-031 — cella formula: mostra il RISULTATO calcolato
                    // (TexEscape gestisce il "%" → \%). Altrimenti contenuto normale.
                    if (\is_array($norm) && isset($norm['formula']) && FormulaEngine::isFormula($norm['formula'])) {
                        $fr = $fres[$r][$col] ?? null;
                        $content = TexEscape::escape($fr ? (string)($fr['display'] ?? '') : '');
                    } else {
                        $content = self::renderCellContent($norm);
                    }
                    // Phase 24.32 — \cellcolor[HTML]{RRGGBB} se cell.bg
                    $bg = (string)($norm['bg'] ?? '');
                    if ($bg !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $bg)) {
                        $content = \sprintf('\\cellcolor[HTML]{%s} %s', strtoupper(substr($bg, 1)), $content);
                    }
                    $grid[$r][$col] = [
                        'kind'    => 'origin',
                        'content' => $content,
                        'cspan'   => $colspan,
                        'rspan'   => $rowspan,
                        'align'   => (string)($norm['align'] ?? ''),
                        'valign'  => (string)($norm['valign'] ?? ''),
                    ];
                    // Riserva le colonne extra del colspan (stessa riga) come 'h'.
                    for ($k = 1; $k < $colspan; $k++) {
                        if (($col + $k) < $colCount) {
                            $grid[$r][$col + $k] = ['kind' => 'h'];
                        }
                    }
                    // Attiva il rowspan sulle colonne coperte (per le righe sotto).
                    if ($rowspan > 1) {
                        for ($k = 0; $k < $colspan; $k++) {
                            $cc = $col + $k;
                            if ($cc < $colCount) {
                                $vSpanRemaining[$cc] = \max($vSpanRemaining[$cc], $rowspan - 1);
                            }
                        }
                    }
                    // Avanza dell'intero colspan: le colonne extra sono già
                    // marcate 'h' e i placeholder {merged:true} successivi
                    // nell'array sono no-op (vedi sopra).
                    $col += $colspan;
                }
                // Eventuali colonne residue: completa con rowspan attivi o vuoto.
                while ($col < $colCount) {
                    if ($vSpanRemaining[$col] > 0) {
                        $grid[$r][$col] = ['kind' => 'v'];
                        $vSpanRemaining[$col]--;
                    } else {
                        $grid[$r][$col] = ['kind' => ''];
                    }
                    $col++;
                }
            }

            // Emissione riga per riga dalla griglia.
            for ($r = 0; $r < $rowCount; $r++) {
                $cells = [];
                for ($c = 0; $c < $colCount; $c++) {
                    $g = $grid[$r][$c] ?? ['kind' => ''];
                    $kind = $g['kind'] ?? '';
                    if ($kind === 'h') {
                        // Coperta da colspan a sinistra: \multicolumn già emesso,
                        // nessun token & aggiuntivo.
                        continue;
                    }
                    if ($kind === 'v') {
                        // Coperta da un \multirow dall'alto: token vuoto.
                        $cells[] = '';
                        continue;
                    }
                    if ($kind === 'origin') {
                        $content = (string)($g['content'] ?? '');
                        $cspan   = (int)($g['cspan'] ?? 1);
                        $rspan   = (int)($g['rspan'] ?? 1);
                        $align   = (string)($g['align'] ?? '');
                        $valign  = (string)($g['valign'] ?? '');
                        // Rowspan → \multirow con posizione verticale opzionale
                        // ([t]/[c]/[b]); default centrato.
                        if ($rspan > 1) {
                            $vpos = ['top' => 't', 'middle' => 'c', 'bottom' => 'b'][$valign] ?? '';
                            $varg = $vpos !== '' ? '[' . $vpos . ']' : '';
                            $content = \sprintf('\\multirow%s{%d}{*}{%s}', $varg, $rspan, $content);
                        }
                        // Allineamento orizzontale via \multicolumn (anche con
                        // cspan=1 quando l'align è impostato).
                        $letter = ['left' => 'l', 'center' => 'c', 'right' => 'r'][$align] ?? '';
                        if ($cspan > 1 || $letter !== '') {
                            $cells[] = \sprintf('\\multicolumn{%d}{|%s|}{%s}', $cspan, $letter !== '' ? $letter : 'l', $content);
                        } else {
                            $cells[] = $content;
                        }
                        continue;
                    }
                    // Cella normale vuota (kind '').
                    $cells[] = (string)($g['content'] ?? '');
                }
                $lines[] = \implode(' & ', $cells) . ' \\\\';
                // \cline anziché \hline: non tracciare la linea sotto le colonne
                // ancora coperte da un rowspan attivo (la fusione verticale
                // prosegue nella riga successiva), altrimenti la riga
                // orizzontale taglierebbe la cella unita.
                $lines[] = self::tableRowRule($grid, $r, $rowCount, $colCount);
            }
        }

        $lines[] = '\\end{' . $tableEnv . '}';
        $out = \implode("\n", $lines);
        // Caption opzionale. NO float \begin{table}[h]: dentro un sectionbox dà
        // "! LaTeX Error: Not in outer par mode." (i float non sono ammessi nei
        // box). Uso \begin{center} (no float) + caption come testo centrato corsivo.
        $caption = (string)($block['caption'] ?? '');
        if ($caption !== '') {
            $out = "\\begin{center}\n" . $out
                 . "\n\n\\textit{" . TexEscape::escape($caption) . "}\n\\end{center}";
        }

        // Phase 24.19 — headerNote/footerNote come paragraph pre/post
        $headerNote = (string)($block['headerNote'] ?? '');
        $footerNote = (string)($block['footerNote'] ?? '');
        if ($headerNote !== '') {
            $out = TexEscape::escape($headerNote) . "\n\n" . $out;
        }
        if ($footerNote !== '') {
            $out = $out . "\n\n" . TexEscape::escape($footerNote);
        }

        return $out;
    }

    /**
     * Colonne pesate per tabularx (widthMode="full"): `|>{\hsize=k\hsize}X|...|`
     * con sum(k) = colCount (vincolo tabularx). k_i = colCount * pct_i/100.
     * Senza colWidths validi → tutte k=1 (colonne X uguali, larghezza piena).
     */
    private static function weightedColSpec(array $colWidths, int $colCount): string
    {
        if ($colCount <= 0) {
            return '';
        }
        // Riusa la normalizzazione percentuale di PtToHtml (sorgente unica).
        $pct = \App\Services\Risdoc\Pt\PtToHtml::normalizeColWidths($colWidths, $colCount);
        $equal = true;
        foreach ($pct as $p) {
            if (\abs($p - (100 / $colCount)) > 0.01) {
                $equal = false;
                break;
            }
        }
        $specs = [];
        if ($equal) {
            for ($i = 0; $i < $colCount; $i++) {
                $specs[] = 'X';
            }
        } else {
            // k_i = colCount * pct/100; aggiusta l'ultimo perché sum(k)=colCount.
            $ks = [];
            for ($i = 0; $i < $colCount; $i++) {
                $ks[$i] = \round($colCount * $pct[$i] / 100, 4);
            }
            $ks[$colCount - 1] = \round($colCount - \array_sum(\array_slice($ks, 0, -1)), 4);
            foreach ($ks as $k) {
                $specs[] = '>{\\hsize=' . self::texNum($k) . '\\hsize}X';
            }
        }
        return '|' . \implode('|', $specs) . '|';
    }

    /** Formatta un numero per LaTeX (no notazione scientifica, trim zeri). */
    private static function texNum(float $n): string
    {
        $s = \rtrim(\rtrim(\number_format($n, 4, '.', ''), '0'), '.');
        return $s === '' || $s === '-0' ? '0' : $s;
    }

    /**
     * BUG3 — calcola la riga orizzontale sotto la riga $r di una tabella.
     *
     * Se nessuna colonna nella riga SUCCESSIVA è coperta da un rowspan attivo
     * (kind 'v'), emette un semplice \hline. Altrimenti emette segmenti \cline
     * solo per gli intervalli di colonne NON coperti, così la linea non taglia
     * le celle unite verticalmente con \multirow. Sull'ultima riga è sempre
     * \hline (chiude la tabella).
     *
     * @param array<int,array<int,array<string,mixed>|null>> $grid
     */
    private static function tableRowRule(array $grid, int $r, int $rowCount, int $colCount): string
    {
        if ($r >= $rowCount - 1) {
            return '\\hline';
        }
        $covered = [];
        $anyCovered = false;
        for ($c = 0; $c < $colCount; $c++) {
            $kind = $grid[$r + 1][$c]['kind'] ?? '';
            $covered[$c] = ($kind === 'v');
            if ($covered[$c]) {
                $anyCovered = true;
            }
        }
        if (!$anyCovered) {
            return '\\hline';
        }
        // Costruisci segmenti \cline{a-b} sulle colonne NON coperte (1-based).
        $segments = [];
        $start = null;
        for ($c = 0; $c < $colCount; $c++) {
            if (!$covered[$c]) {
                if ($start === null) {
                    $start = $c;
                }
            } else {
                if ($start !== null) {
                    $segments[] = \sprintf('\\cline{%d-%d}', $start + 1, $c);
                    $start = null;
                }
            }
        }
        if ($start !== null) {
            $segments[] = \sprintf('\\cline{%d-%d}', $start + 1, $colCount);
        }
        return $segments === [] ? '' : \implode('', $segments);
    }

    /**
     * Phase 24.11 — contenuto di una cell tabella strutturata:
     *   {text, widget?, colspan?, rowspan?, merged?}
     *
     * Widget types:
     *   - select:    \underline{value} (o placeholder)
     *   - textField: value escaped (o placeholder)
     * Text plain: escape LaTeX del testo fallback.
     */
    private static function renderCellContent(array $cell): string
    {
        $widget = $cell['widget'] ?? null;
        if (\is_array($widget) && isset($widget['_type'])) {
            if ($widget['_type'] === 'checkbox') {
                return self::renderCellCheckboxTex($widget);
            }
            // value può essere array (multi-select o cella vuota per-classe = []):
            // (string)(array) darebbe "Array". Join per gli array, cast per gli scalari.
            $rawVal = $widget['value'] ?? '';
            $val = \is_array($rawVal)
                ? \implode(', ', \array_map(static fn($v) => (string)$v, $rawVal))
                : (string)$rawVal;
            switch ($widget['_type']) {
                case 'select':
                    return $val === ''
                        ? '\\underline{\\hspace{2cm}}'
                        : '\\underline{' . TexEscape::escape($val) . '}';
                case 'textField':
                    return $val === ''
                        ? '\\underline{\\hspace{2cm}}'
                        : TexEscape::escape($val);
            }
        }
        return self::inlineTagsToTex((string)($cell['text'] ?? ''));
    }

    /** Cella checkbox → rispetta `renderMode` del widget (come il gruppo standalone):
     *   - "all" (default)   → \begin{itemize}\item[\xcheckbox/\checkbox]lbl (incolonnato, master)
     *   - "checked-only"    → solo le voci spuntate, in elenco puntato
     *   - "checked-inline"  → solo le voci spuntate, a flusso (virgole)
     *  Senza opzioni → solo i valori spuntati a virgole. */
    private static function renderCellCheckboxTex(array $widget): string
    {
        $options = \is_array($widget['options'] ?? null) ? $widget['options'] : [];
        $value = $widget['value'] ?? [];
        $checked = \is_array($value) ? \array_map('strval', $value) : ($value !== '' ? [(string)$value] : []);
        $mode = (string)($widget['renderMode'] ?? 'all');
        if (\count($options) === 0) {
            return \count($checked) === 0
                ? ''
                : TexEscape::escape(\implode(', ', $checked));
        }
        $isChk = static fn(array $o): bool => \in_array((string)($o['value'] ?? $o['label'] ?? ''), $checked, true);
        // checked-inline → solo spuntati, a flusso
        if ($mode === 'checked-inline') {
            $lbls = [];
            foreach ($options as $o) {
                if (\is_array($o) && $isChk($o)) {
                    $lbls[] = TexEscape::escape((string)($o['label'] ?? $o['value'] ?? ''));
                }
            }
            return \implode(', ', $lbls);
        }
        $onlyChecked = ($mode === 'checked-only');
        // Margini interni cella: meno a SINISTRA (indentazione lista ridotta),
        // un po' a DESTRA (rightmargin) — richiesta utente per le celle competenze.
        $lines = ['\\begin{itemize}[leftmargin=1.4em,rightmargin=0.6em,labelsep=0.4em]'];
        $lastGroup = null;
        foreach ($options as $o) {
            if (!\is_array($o)) {
                continue;
            }
            $chk = $isChk($o);
            if ($onlyChecked && !$chk) {
                continue;
            }
            $lbl = (string)($o['label'] ?? $o['value'] ?? '');
            $grp = (string)($o['group'] ?? '');
            if ($grp !== '' && $grp !== $lastGroup) {
                $lines[] = '\\item[] \\textbf{' . TexEscape::escape($grp) . '}';
                $lastGroup = $grp;
            }
            // "solo spuntati" = bullet semplice; "tutti" = casella spuntata/vuota.
            $prefix = $onlyChecked ? '  \\item ' : '  \\item[' . ($chk ? '\\xcheckbox' : '\\checkbox') . '] ';
            $lines[] = $prefix . TexEscape::escape($lbl);
        }
        if (\count($lines) === 1) { // nessuna voce (es. checked-only senza spuntati)
            return '';
        }
        $lines[] = '\\end{itemize}';
        return \implode("\n", $lines);
    }

    /**
     * Converte i tag inline dei formattatori cella (strong/em/u/code, prodotti
     * da B/I/U/code della toolbar) in comandi LaTeX, escapando il testo fra i
     * tag. Parser a stack (gestisce annidamento + tag non chiusi).
     */
    private static function inlineTagsToTex(string $text): string
    {
        if ($text === '' || strpos($text, '<') === false) {
            return TexEscape::escape($text);
        }
        $map = ['strong' => 'textbf', 'em' => 'textit', 'u' => 'underline', 'code' => 'texttt'];
        $tokens = preg_split('#(</?(?:strong|em|u|code)>)#', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $out = '';
        $stack = 0;
        foreach ($tokens as $tok) {
            if (preg_match('#^<(/?)(strong|em|u|code)>$#', $tok, $m)) {
                if ($m[1] === '') {
                    $out .= '\\' . $map[$m[2]] . '{';
                    $stack++;
                } elseif ($stack > 0) {
                    $out .= '}';
                    $stack--;
                }
            } else {
                $out .= TexEscape::escape($tok);
            }
        }
        while ($stack-- > 0) {
            $out .= '}';
        }
        return $out;
    }

    /**
     * Phase 24.2 — select: `{label}: \underline{value}` (o solo \underline
     * se no label). Le options sono metadata editoriale — non compaiono
     * nel TeX output (sono alternative non scelte).
     */
    private static function renderSelect(array $block): string
    {
        $value = (string)($block['value'] ?? '');
        $label = (string)($block['label'] ?? '');
        $name  = (string)($block['name']  ?? '');
// Phase 24.29 — skip se SIA label SIA value SIA name vuoti
        // (placeholder ptSelect non configurato dall'utente).
        if ($value === '' && $label === '' && $name === '') {
            return '';
        }
        $valEsc = $value === '' ? '\\underline{\\hspace{3cm}}' : '\\underline{' . TexEscape::escape($value) . '}';
        if ($label !== '') {
            return TexEscape::escape($label) . ': ' . $valEsc;
        }
        return $valEsc;
    }

    /**
     * Phase 24.3 — textField: `{label}: {value}` plain (kind text/number/date
     * ignorato in TeX — è hint UI). Value vuoto → underline placeholder.
     */
    private static function renderTextField(array $block): string
    {
        $value = (string)($block['value'] ?? '');
        $label = (string)($block['label'] ?? '');
        $name  = (string)($block['name']  ?? '');
// Phase 24.29 — skip se label+value+name tutti vuoti (placeholder)
        if ($value === '' && $label === '' && $name === '') {
            return '';
        }
        $valEsc = $value === ''
            ? '\\underline{\\hspace{3cm}}'
            : TexEscape::escape($value);
        if ($label !== '') {
            return TexEscape::escape($label) . ': ' . $valEsc;
        }
        return $valEsc;
    }

    /**
     * Phase 24.4 — formCheckbox: singolo `\xcheckbox{}` / `\checkbox{}` con label.
     */
    private static function renderFormCheckbox(array $block): string
    {
        $label   = (string)($block['label']   ?? '');
        $checked = !empty($block['checked']);
        $cmd = $checked ? '\\xcheckbox' : '\\checkbox';
        return $cmd . '{' . TexEscape::escape($label) . '}';
    }

    /**
     * Phase 24.5 — sectionHeader: `\section{}` / `\subsection{}` in base a level.
     * Selectors vengono emessi come suffix dopo (field-* placeholders).
     */
    private static function renderSectionHeader(array $block): string
    {
        $title = (string)($block['title'] ?? '');
// Phase 24.29 — skip header vuoto (era duplicato col title del parent)
        if (trim($title) === '') {
            return '';
        }
        $level = (int)($block['level'] ?? 2);
        $selectors = \is_array($block['selectors'] ?? null) ? $block['selectors'] : [];

        // ── INTESTAZIONE DOCUMENTO (livello 1 con selettori anagrafici) ──
        // Il titolo-documento NON è una sezione numerata: è l'header (titolo
        // centrato + campi Prof./Classe/Sezione/Indirizzo/Disciplina), come il
        // master fismapant. (I loghi IIS arrivano da intestaLAteX_IIS.tex.)
        if ($level <= 1 && \count($selectors) > 0) {
            return self::renderDocHeader($title, $selectors);
        }

        // ── SEZIONI ──  Comandi NON numerati (star): i titoli del body_pt
        // contengono già la numerazione manuale ("1.", "2.", "2.1"…) → con i
        // comandi numerati LaTeX aggiungerebbe i suoi numeri sopra (doppio
        // "1.2 2."). Livello 1 = sezione top, 2 = sotto, ecc. (il titolo-doc è
        // l'header, quindi le sezioni vere partono da \section*).
        $cmd = match (true) {
            $level <= 2 => '\\section*',
            $level === 3 => '\\subsection*',
            $level === 4 => '\\subsubsection*',
            default => '\\paragraph*',
        };
        $out = $cmd . '{' . TexEscape::escape($title) . '}';
        // Selettori residui (non doc-header) → suffix risolto (compat).
        if (\count($selectors) > 0) {
            $parts = \array_map(static function ($name) {
                $key = (string)$name;
                $val = self::$ctx['state'][$key] ?? self::$ctx['fields'][$key] ?? '';
                return \is_string($val) && $val !== ''
                        ? TexEscape::escape($val)
                        : '[field-' . $key . ']';
            }, \array_values($selectors));
            $out .= "\n" . \implode(' ', $parts);
        }
        return $out;
    }

    /**
     * Intestazione documento (master fismapant): titolo centrato + "Anno
     * scolastico" + tabella campi a 2 colonne. I valori dei campi vengono dal
     * context (state/fields). Macro \schoolyear/tabularx/X disponibili in risdoc.sty.
     */
    private static function renderDocHeader(string $title, array $selectors): string
    {
        $val = static function (string $k): string {
            $v = self::$ctx['state'][$k] ?? self::$ctx['fields'][$k] ?? '';
            if (\is_array($v)) {
                $v = \implode(', ', \array_map('strval', $v));
            }
            return TexEscape::escape((string)$v);
        };
        $labels = [
            'professore' => 'Prof.', 'classe' => 'Classe', 'sezione' => 'Sezione',
            'indirizzo' => 'Indirizzo', 'disciplina' => 'Disciplina',
        ];
        $field = static function (?string $k) use ($val, $labels): string {
            if ($k === null || !isset($labels[$k])) {
                return '';
            }
            return '\\textbf{' . $labels[$k] . ':} ' . $val($k);
        };
        // Layout master: Prof | Classe / Sezione | Indirizzo / Disciplina | -
        $rows = [['professore', 'classe'], ['sezione', 'indirizzo'], ['disciplina', null]];
        $out  = "\\begin{center}\n"
              . "{\\fontsize{16}{20}\\selectfont\\bfseries\\sffamily " . TexEscape::escape($title) . "\\par}\n"
              . "\\vspace{0.2cm}\n"
              . "{\\fontsize{12}{16}\\selectfont\\bfseries\\sffamily Anno scolastico \\schoolyear}\n"
              . "\\end{center}\n";
        $out .= "\\begin{tabularx}{\\dimexpr\\linewidth-2\\tabcolsep\\relax}{XX}\n";
        foreach ($rows as [$l, $r]) {
            $out .= $field($l) . ' & ' . $field($r) . " \\\\\n";
        }
        $out .= "\\end{tabularx}";
        return $out;
    }
}
