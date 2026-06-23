<?php

namespace App\Services\TexBuilder;

/**
 * Escape testo plain per inclusione in LaTeX.
 * Replica le regole di utilitiesPrint (functions-mod.js:13549).
 */
final class Sanitizer
{
    /** Caratteri LaTeX speciali → escape in \char */
    private const SPECIAL_MAP = [
        '\\' => '\textbackslash{}',
        '{'  => '\{',
        '}'  => '\}',
        '$'  => '\$',
        '&'  => '\&',
        '%'  => '\%',
        '#'  => '\#',
        '^'  => '\^{}',
        '_'  => '\_',
        '~'  => '\~{}',
    ];

    /**
     * Escape "leggero": rispetta backslash già presenti per macro
     * LaTeX intenzionali (es. \sqrt). Da usare per testo proveniente
     * da editor che genera già LaTeX valido.
     */
    public static function escapeMath(string $s): string
    {
        // I match `$...$` o `$$...$$` vengono lasciati intatti (math mode).
        return $s;
    }

    /**
     * Escape totale: tratta input come testo plain.
     * Da usare per nomi utente, titoli, etc.
     */
    public static function escapePlain(string $s): string
    {
        // Backslash deve essere primo, altrimenti i replace successivi
        // includono `\` aggiunti dalla mappa.
        $out = '';
        $len = mb_strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = mb_substr($s, $i, 1);
            $out .= self::SPECIAL_MAP[$c] ?? $c;
        }
        return $out;
    }

    /**
     * Estrae SOLO l'environment `\begin{tikzpicture}...\end{tikzpicture}` dal
     * body sorgente, scartando preamble (\usepackage, \usetikzlibrary,
     * \documentclass, \begin{document}, \def/\newcommand top-level). Cosi'
     * l'environment puo' essere inserito direttamente nel body main_*.tex
     * (i package sono nel preamble del main).
     *
     * Se il body NON contiene `\begin{tikzpicture}`, viene wrappato (caso:
     * docente scrive solo \draw...).
     */
    /**
     * Estrae il valore di un attributo HTML da una stringa di attributi.
     * Es: extractAttr('class="x" type="A" data-foo=bar', 'type') → "A"
     */
    private static function extractAttr(string $attrs, string $name): string
    {
        $rx = '#\b' . preg_quote($name, '#') . '\s*=\s*(["\']?)([^"\'\s>]*)\1#i';
        if (preg_match($rx, $attrs, $m)) {
            return $m[2];
        }
        return '';
    }

    /**
     * Genera options enumitem `[label=..., start=...]` per `\begin{enumerate}`/
     * `\begin{itemize}` in base a `type` (legacy A/a/I/i) e `data-fm-list-style`
     * (preset Google Docs-like).
     *
     * Mapping preset → label LaTeX (livello outer):
     *   alpha-decimal       → \Alph*.    (sub: 1.→a.)
     *   lower-alpha-roman   → \alph*.    (sub: i.→1.)
     *   roman-alpha         → \Roman*.   (sub: A.→1.)
     *   decimal-zero        → 0\arabic*. (sub: a.→i.) — leading zero via prefix
     *   paren               → \arabic*)  (sub: \alph*)→\roman*))
     *   alpha-paren         → \Alph*)    (sub: \arabic*)→\alph*))
     *   lower-alpha-paren   → \alph*)    (sub: \roman*)→\arabic*))
     *   roman-paren         → \Roman*)   (sub: \Alph*)→\arabic*))
     *   decimal-zero-paren  → 0\arabic*) (sub: \alph*)→\roman*))
     *   arrow-bullet        → $\rightarrow$  (itemize)
     *   star-circle         → $\star$        (itemize)
     *
     * Type attr (single-level legacy): A/a/I/i → \Alph*./\alph*./\Roman*./\roman*.
     */
    private static function listOptions(string $env, string $type, string $preset, string $start): string
    {
        $opts = [];
        // Label
        $label = self::listLabelOuter($env, $type, $preset);
        if ($label !== '') {
            $opts[] = 'label=' . $label;
        }
        // Start counter
        if ($start !== '' && ctype_digit($start) && (int)$start > 1) {
            $opts[] = 'start=' . (int)$start;
        }
        return implode(',', $opts);
    }

    /**
     * Mapping preset → labels per livello [outer, sub1, sub2].
     * I sub-livelli vengono settati via `\setlist[enumerate,N]{label=...}`
     * inline subito prima della `\begin{enumerate}` outer (scope locale).
     */
    private const PRESET_LEVELS = [
        // Suffisso .
        'alpha-decimal'      => ['\\Alph*.',     '\\arabic*.', '\\alph*.'],
        'lower-alpha-roman'  => ['\\alph*.',     '\\roman*.',  '\\arabic*.'],
        'roman-alpha'        => ['\\Roman*.',    '\\Alph*.',   '\\arabic*.'],
        'decimal-zero'       => ['0\\arabic*.',  '\\alph*.',   '\\roman*.'],
        // Suffisso )
        'paren'              => ['\\arabic*)',   '\\alph*)',   '\\roman*)'],
        'alpha-paren'        => ['\\Alph*)',     '\\arabic*)', '\\alph*)'],
        'lower-alpha-paren'  => ['\\alph*)',     '\\roman*)',  '\\arabic*)'],
        'roman-paren'        => ['\\Roman*)',    '\\Alph*)',   '\\arabic*)'],
        'decimal-zero-paren' => ['0\\arabic*)',  '\\alph*)',   '\\roman*)'],
        // Bullet (itemize). \fmmk{} normalizza la dimensione (vedi verifica.sty):
        // arrow-bullet = ➤ ♦ ● ; star-circle = ★ ○ ■ (coerenti con la resa a schermo).
        'arrow-bullet'       => ['\\fmmk{\\blacktriangleright}', '\\fmmk{\\blacklozenge}', '\\fmmk{\\bullet}'],
        'star-circle'        => ['\\fmmk{\\bigstar}',           '\\fmmk{\\circ}',         '\\fmmk{\\blacksquare}'],
    ];

    // Default labels usati quando il list non ha `data-fm-list-style` (legacy
    // <ol>/<ul> senza preset). Emette comunque setlist per coerenza con la
    // resa HTML/CSS (sub-livelli con marker visibili).
    private const DEFAULT_LEVELS_OL = ['\\arabic*.',   '\\alph*.',  '\\roman*.'];
    private const DEFAULT_LEVELS_UL = ['\\fmmk{\\bullet}', '\\fmmk{\\circ}', '\\fmmk{\\blacksquare}']; // ● ○ ■ uniformi (vedi \fmmk in verifica.sty/risdoc.sty)

    /** Risolve levels per outer + sub1 + sub2 da preset / type / env defaults. */
    private static function resolveLevels(string $env, string $type, string $preset): array
    {
        if ($preset !== '' && isset(self::PRESET_LEVELS[$preset])) {
            return self::PRESET_LEVELS[$preset];
        }
        // Default per env quando no preset (e no `type=A/a/I/i` legacy):
        if ($env === 'itemize') {
            return self::DEFAULT_LEVELS_UL;
        }
        // enumerate: type legacy override outer, ma sub-livelli usano default OL.
        if ($type !== '') {
            $tmap = ['A' => '\\Alph*.', 'a' => '\\alph*.', 'I' => '\\Roman*.', 'i' => '\\roman*.', '1' => '\\arabic*.'];
            $outer = $tmap[$type] ?? '\\arabic*.';
            // Use legacy outer + standard sub-levels
            return [$outer, self::DEFAULT_LEVELS_OL[1], self::DEFAULT_LEVELS_OL[2]];
        }
        return self::DEFAULT_LEVELS_OL;
    }

    /**
     * Etichetta lista (enumitem) per preset + profondità — SORGENTE UNICA
     * condivisa con PtToTex (elenchi PT custom/modelli) così la resa LaTeX è
     * identica a quella degli esercizi/verifiche. $depth è 1-based.
     */
    public static function listLabel(string $preset, bool $ordered, int $depth): string
    {
        $levels = self::resolveLevels($ordered ? 'enumerate' : 'itemize', '', $preset);
        $idx = max(0, min($depth - 1, \count($levels) - 1));
        return $levels[$idx] ?? ($ordered ? '\\arabic*.' : '\\fmmk{\\bullet}');
    }

    private static function listLabelOuter(string $env, string $type, string $preset): string
    {
        // Sempre via resolveLevels: ritorna outer label coerente con
        // PRESET_LEVELS (preset esplicito) o defaults env (no preset).
        // Outer label esplicito INLINE garantisce marker uniforme anche
        // quando la lista è nested in altro enumerate (TableRenderer).
        return self::resolveLevels($env, $type, $preset)[0];
    }

    /**
     * Genera il blocco `\setlist` per i sub-livelli di un preset gerarchico.
     * Ritorna una stringa LaTeX da emettere PRIMA della `\begin{enumerate}`
     * outer, con scope `{...}` per evitare leak globale.
     *
     * Es. per preset='alpha-decimal':
     *   \setlist[enumerate,2]{label=\arabic*.}
     *   \setlist[enumerate,3]{label=\alph*.}
     */
    private static function presetSetlistBlock(string $env, string $type, string $preset, int $depthOffset = 0): string
    {
        $levels = self::resolveLevels($env, $type, $preset);
        if (\count($levels) <= 1) {
            return '';
        }
        // depthOffset shifta enumitem level SOLO per `enumerate`: TableRenderer
        // wrappa items in \begin{enumerate}[label=\Alph*)] (depth 1 enumerate),
        // ma `itemize` non ha wrapper esterno e mantiene il proprio counter
        // indipendente da enumerate. Quindi per itemize offset=0.
        $effectiveOffset = ($env === 'itemize') ? 0 : $depthOffset;
        $out = '';
        for ($i = 1, $n = \count($levels); $i < $n; $i++) {
            $lbl = $levels[$i];
            $level = $i + 1 + $effectiveOffset;
            $out .= "\\setlist[$env,$level]{label=$lbl}\n";
        }
        return $out;
    }

    private static function extractTikzpicture(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }
        // G27.tikz.hoist — invece di scartare il preamble (\usepackage,
        // \newcommand, \newif, \newenvironment, \def, ...) presente PRIMA
        // del primo \begin{tikzpicture}, lo estraiamo e lo accumuliamo nel
        // hoister statico. TexBuilder lo prependera' al body esercizi cosi'
        // le macro custom referenziate nel tikzpicture sono definite a tempo
        // di compile. \newcommand viene riscritto a \providecommand per
        // supportare inserimento dello stesso template in N posizioni dello
        // stesso esercizio (idempotenza). Vedi collectHoistedPreamble().
        if (preg_match('#^(.*?)(\\\\begin\{tikzpicture\}.*?\\\\end\{tikzpicture\})(.*)$#s', $body, $m)) {
            $preBody  = (string)$m[1];
            $tikz     = (string)$m[2];
            $postBody = (string)$m[3];
            self::hoistPreamble($preBody . "\n" . $postBody);
            return "\n\n" . $tikz . "\n\n";
        }
        return "\n\n\\begin{tikzpicture}\n" . $body . "\n\\end{tikzpicture}\n\n";
    }

    /** @var array<string,string> hash → snippet preamble (dedup per contenuto). */
    private static array $hoistedTikzPreamble = [];

    /** @var array<string,string> nome_macro → hash del PRIMO snippet che la
     *  definisce. Usato da hoistPreamble per rilevare collisioni: se un
     *  secondo snippet (hash diverso) ridefinisce la stessa macro con corpo
     *  diverso, registra una collision warning. Vedi G27.tikz.collision. */
    private static array $hoistedMacroOwners = [];

    /** @var list<array{macro:string, owner_hash:string, conflict_hash:string}>
     *  Lista collision rilevate: stesso nome macro, hash snippet diversi. */
    private static array $hoistedCollisions = [];

    /**
     * G27.tikz.hoist — Reset accumulator. Da chiamare PRIMA di
     * `latexPassthrough` su tutti gli items di una verifica/esercizio
     * (TexBuilder::build).
     */
    public static function resetHoistedPreamble(): void
    {
        self::$hoistedTikzPreamble = [];
        self::$hoistedMacroOwners  = [];
        self::$hoistedCollisions   = [];
    }

    /**
     * G27.tikz.collision — Ritorna lista collision rilevate durante l'hoist
     * corrente: una stessa macro `\X` definita da snippet di template
     * differenti con corpo diverso. Usato da TexBuilder per surfacing nei
     * warning della response API → toast UI.
     *
     * @return list<array{macro:string,message:string}>
     */
    public static function collectHoistedCollisions(): array
    {
        $out = [];
        foreach (self::$hoistedCollisions as $c) {
            $out[] = [
                'macro'   => $c['macro'],
                'message' => "Macro '{$c['macro']}' definita da template diversi con "
                           . "corpo differente. Solo la prima definizione e' attiva "
                           . "(\\providecommand idempotente). Le altre figure che usano "
                           . "questa macro potrebbero renderizzare con la logica del primo "
                           . "template.",
            ];
        }
        return $out;
    }

    /**
     * G27.tikz.hoist — Collect: ritorna il blocco preamble dedupato pronto da
     * iniettare in main_*.tex (o all'inizio del body esercizi). Vuoto se
     * nessun TikZ con preamble custom e' stato processato.
     */
    public static function collectHoistedPreamble(): string
    {
        if (empty(self::$hoistedTikzPreamble)) {
            return '';
        }
        return "% G27.tikz.hoist — preamble macros estratti dai TikZ blocks\n"
             . implode("\n\n", self::$hoistedTikzPreamble);
    }

    /**
     * G27.tikz.hoist — Aggiunge un frammento di preamble all'accumulator
     * (no-op se vuoto/whitespace o gia' presente per hash). Sanitizza:
     *   - rimuove `\documentclass`, `\begin{document}`, `\end{document}`,
     *     `\pagestyle{...}` (scaffolding "standalone" del template)
     *   - converte `\newcommand` in `\providecommand` cosi' inserzioni
     *     ripetute dello stesso template non causano "already defined"
     */
    private static function hoistPreamble(string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        // Strip outer document scaffolding ("standalone" template)
        $text = (string)preg_replace('#\\\\documentclass[^\n]*\n?#', '', $text);
        $text = (string)preg_replace('#\\\\begin\{document\}\s*#', '', $text);
        $text = (string)preg_replace('#\\\\end\{document\}\s*#', '', $text);
        $text = (string)preg_replace('#\\\\pagestyle\{[^}]+\}\s*#', '', $text);
        // Idempotency: \newcommand → \providecommand (silenzioso se ridefinito).
        // Non tocchiamo \def/\renewcommand (intenzionalmente override) ne'
        // \newif (TeX tollera \newif{\ifX} ridefinito senza errore fatale).
        $text = (string)preg_replace('#\\\\newcommand\b#', '\\providecommand', $text);
        // Trim righe vuote multiple
        $text = trim((string)preg_replace('#\n{3,}#', "\n\n", $text));
        if ($text === '') {
            return;
        }
        // Dedup hash su versione normalizzata: rimuove commenti `%...` (TeX
        // line comments) e collapse whitespace. Cosi' due preamble identici
        // semanticamente ma con markers/commenti diversi (es. utenti che
        // aggiungono "% mio template" in cima) NON producono entry duplicate.
        $normalized = (string)preg_replace('/(?<!\\\\)%[^\n]*/', '', $text); // strip comments not escaped \%
        $normalized = (string)preg_replace('/\s+/', ' ', $normalized);
        $normalized = trim($normalized);
        if ($normalized === '') {
            return;
        }
        $hash = md5($normalized);
        self::$hoistedTikzPreamble[$hash] ??= $text;
        // G27.tikz.collision — registra ogni macro definita in questo snippet
        // e rileva collisioni: se un'altra hash ha gia' definito la stessa
        // macro, segnalo. Le ridefinizioni con stesso hash (template
        // identico) sono dedupate sopra → no collision.
        $macroPatterns = [
            // \providecommand{\X} — il rewrite \newcommand→\providecommand
            // e' gia' applicato sopra, quindi cerchiamo solo providecommand.
            '#\\\\providecommand\*?\{?\\\\(\w+)\}?#',
            // \newif\if<X> → definisce \<X>true e \<X>false ma usiamo solo "ifX"
            '#\\\\newif\\\\if(\w+)#',
            // \newenvironment{Name}
            '#\\\\newenvironment\{(\w+)\}#',
            // \def\<X> (raw def, intenzionale override → non collidiamo)
            // Skip per ora (troppo rumoroso).
        ];
        foreach ($macroPatterns as $rx) {
            if (preg_match_all($rx, $text, $mm)) {
                foreach ($mm[1] as $name) {
                    $owner = self::$hoistedMacroOwners[$name] ?? null;
                    if ($owner === null) {
                        self::$hoistedMacroOwners[$name] = $hash;
                    } elseif ($owner !== $hash) {
                        self::$hoistedCollisions[] = [
                            'macro'         => '\\' . $name,
                            'owner_hash'    => $owner,
                            'conflict_hash' => $hash,
                        ];
                    }
                }
            }
        }
    }

    /**
     * G23 — Convert `<table class="fm-rm-table">` to LaTeX `tabular`.
     *
     * Markup supportato (mirror parità con `ContractRenderer::renderRmTable()` +
     * client `js/modules/render/rm-table-view.js`):
     *
     *   <td class="rm-option" data-row="0" data-col="0">
     *     <div class="fm-wrap-check-cell">
     *       <input type="checkbox"|"radio"|"text"|"number">       (X|V|T|N)
     *       <button class="fm-rm-btn">btn</button>                   (B)
     *       <label class="fm-collection"><div class="fm-cell-content">…</div></label>
     *     </div>
     *   </td>
     *
     * Letter prefix: derivato da `data-row` + `data-col` con
     * `letter = chr(97 + r*cols + c)`. Type symbol via `RmColumnTypes::toTex()`.
     * Per backward-compat con contract legacy che hanno `<span class="rm-letter">`,
     * il valore inline override il calcolo position-based.
     *
     * @param string $innerHtml contenuto interno del <table>
     * @return string LaTeX tabular
     */
    private static function convertRmTable(string $innerHtml, string $tableAttrs = ''): string
    {
        // Estrai righe via DOMDocument (più robusto del regex per nested).
        $html = '<table>' . $innerHtml . '</table>';
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        // G23.fix8 — Estrai attributi RM da tag table (data-mpagew, data-width,
        // data-mixtr, data-mixcol, data-rows, data-cols). Permettono di
        // pilotare width del tabular + shuffle random in TeX output.
        $mpagew = preg_match('#data-mpagew=["\']1["\']#', $tableAttrs);
        $widthAttr = '';
        if (preg_match('#data-width=["\']([^"\']+)["\']#', $tableAttrs, $wm)) {
            $widthAttr = $wm[1];
        }

        // G23.fix9 — Estrai mixtr/mixcol per shuffle deterministic in TeX
        $mixtr  = preg_match('#data-mixtr=["\']1["\']#', $tableAttrs);
        $mixcol = preg_match('#data-mixcol=["\']1["\']#', $tableAttrs);

        $rows = [];
        $maxCols = 0;
        $globalCellIdx = 0;
        foreach ($doc->getElementsByTagName('tr') as $tr) {
            $cells = [];
            $cellsCorrect = []; // mirror $cells: bool per ogni cella (correct flag)
            $cellsType    = []; // mirror $cells: type char (X/V/B/T/N)
            foreach ($tr->childNodes as $td) {
                if (!($td instanceof \DOMElement) || strtolower($td->tagName) !== 'td') {
                    continue;
                }
                $tdHtml = '';
                foreach ($td->childNodes as $n) {
                    $tdHtml .= $doc->saveHTML($n);
                }

                // G23.fix9 — Detecta correct flag dalla cell class `rm-correct`
                // o dall'attributo checked dell'input.
                $tdClassAttr = $td->getAttribute('class');
                $isCorrect = (bool)preg_match('#\brm-correct\b#', $tdClassAttr)
                          || (bool)preg_match('#<input[^>]*\bchecked\b#i', $tdHtml);

                // G23 — Determina tipo cella (X/V/B/T/N) dal markup interno.
                $type = 'X';
                if (preg_match('#<input[^>]*type=["\']radio["\']#i', $tdHtml)) {
                    $type = 'V';
                } elseif (preg_match('#<button\b[^>]*\brm-btn\b[^>]*>#i', $tdHtml)) {
                    $type = 'B';
                } elseif (
                    preg_match('#<input[^>]*type=["\']text["\']#i', $tdHtml)
                       || preg_match('#<input[^>]*\brm-text\b#i', $tdHtml)
                ) {
                    $type = 'T';
                } elseif (
                    preg_match('#<input[^>]*type=["\']number["\']#i', $tdHtml)
                       || preg_match('#<input[^>]*\brm-num\b#i', $tdHtml)
                ) {
                    $type = 'N';
                }
                // G23.fix9 — Simbolo variant: checked se rm-correct, altrimenti default
                // Phase 24.78 — colonne T (text) / N (number): se l'input ha un
                // value (la soluzione del docente), renderizzalo DENTRO il simbolo
                // (T → \underline{val}, N → \boxed{val}) invece della casella vuota.
                $cellVal = '';
                if (
                    ($type === 'T' || $type === 'N')
                    && preg_match('#<input[^>]*\bvalue=["\']([^"\']*)["\']#i', $tdHtml, $vm)
                ) {
                    $cellVal = trim(html_entity_decode($vm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                }
                if ($cellVal !== '') {
                    $valTex = self::stripHtml($cellVal);
                    $sym = ($type === 'N')
                        ? '$\\boxed{' . $valTex . '}$'
                        : '$\\underline{\\text{' . $valTex . '}}$';
                } else {
                    $sym = '$' . \App\Services\Rendering\RmColumnTypes::toTex($type, $isCorrect) . '$';
                }

                // Strip markup decorativo: letter span (legacy), input/button, div.wrapCheckCell, label.fm-collection, div.cellContent.
                $content = preg_replace('#<span\s+class=["\'][^"\']*\brm-letter\b[^"\']*["\'][^>]*>[\s\S]*?</span\s*>#i', '', $tdHtml);
                $content = preg_replace('#<button\b[^>]*>[\s\S]*?</button\s*>#i', '', (string)$content);
                $content = preg_replace('#<input\b[^>]*>#i', '', (string)$content);
                $content = preg_replace('#</?div\s+class=["\'][^"\']*\b(wrapCheckCell|cellContent)\b[^"\']*["\'][^>]*>#i', '', (string)$content);
                $content = preg_replace('#</?div\s*>#i', '', (string)$content);
                $content = preg_replace('#<label\b[^>]*>([\s\S]*?)</label\s*>#i', '$1', (string)$content);
                $content = trim((string)$content);

                $contentTex = self::stripHtml((string)$content);

                // G23.fix9 — NO letter prefix `\textbf{a.}` (matchare DOM).
                // Solo simbolo + content. Symbol variant riflette correct flag.
                $cells[]        = $sym . ' ' . $contentTex;
                $cellsCorrect[] = $isCorrect;
                $cellsType[]    = $type;
                $globalCellIdx++;
            }
            if ($cells) {
                // G23.fix9 — mixcol: shuffle delle cells dentro la riga
                if ($mixcol) {
                    $idx = array_keys($cells);
                    shuffle($idx);
                    $cells = array_map(static fn($i) => $cells[$i], $idx);
                }
                $rows[] = $cells;
                if (\count($cells) > $maxCols) {
                    $maxCols = \count($cells);
                }
            }
        }
        // G23.fix9 — mixtr: shuffle dell'ordine delle righe
        if ($mixtr && count($rows) > 1) {
            shuffle($rows);
        }
        if (!$rows || $maxCols === 0) {
            return '';
        }

        // G23.fix8 — Width policy:
        //   - data-mpagew="1" (default editor RM): tabular larghezza piena
        //     → colonne `p{(0.95/N)\linewidth}` (compat preesistente).
        //   - data-width="Npx" (specificWidth in editor): converti px→cm
        //     (96dpi → 2.54cm/inch → 1px ≈ 0.0265cm). Tabular usa cm absolute.
        //   - Default (assente): full width (compat).
        // G27.rmcell.minipage — colSpec con `>{\raggedright\arraybackslash}`:
        // permette `\\` inline nelle cell senza confondersi col row terminator
        // (importante per nested itemize/enumerate dentro cell che possono
        // emettere `\\` come line break LaTeX o come `\\hline` row end).
        // `\arraybackslash` ridefinisce `\\` per significare "newline in cell"
        // invece di "end-of-row" — evita "Missing \cr inserted".
        $colPrefix = '>{\\raggedright\\arraybackslash}';
        $colSpec = '|';
        if ($widthAttr !== '' && preg_match('/^\d+$/', $widthAttr)) {
            // px → cm conversion (96dpi: 1px ≈ 0.0265cm). Riduzione 5% per margini.
            $colW = number_format(0.95 * (float)$widthAttr * 0.0265 / $maxCols, 2, '.', '');
            $colSpec .= str_repeat($colPrefix . 'p{' . $colW . 'cm}|', $maxCols);
        } else {
            $colWidth = number_format(0.95 / $maxCols, 3, '.', '');
            $colSpec .= str_repeat($colPrefix . 'p{' . $colWidth . '\\linewidth}|', $maxCols);
        }

        // G27.rmcell.minipage — wrap ogni cell content in
        // `\begin{minipage}[t]{\linewidth}...\end{minipage}`. Senza il wrap,
        // nested environments tipo `\begin{itemize}` 3+ livelli OPPURE
        // `\begin{tikzpicture}` dentro `p{X\linewidth}` cell facevano scattare
        // pdflatex con `! Missing \cr inserted` perche' il parser tabular si
        // perdeva tra le `&` e il body multilinea della cell. Minipage
        // esplicito isola il contesto (parsing locale) e permette nested env
        // arbitrari. `\linewidth` dentro tabular cell vale gia' la colWidth
        // specificata in `p{}`. Cell vuote restano vuote (no minipage).
        $wrap = static function (string $cell): string {
            $trim = trim($cell);
            if ($trim === '') {
                return '';
            }
            // Wrap SEMPRE in minipage[t]: isola contesto parsing tabular,
            // permette nested itemize/enumerate/tikz/minipage senza
            // confondere `\\` end-of-row vs line-break, e supporta cells
            // con altezza variabile.
            return "\\begin{minipage}[t]{\\linewidth}\n" . $trim . "\n\\end{minipage}";
        };
        $bodyLines = [];
        foreach ($rows as $cells) {
            while (\count($cells) < $maxCols) {
                $cells[] = '';
            }
            $wrapped = array_map($wrap, $cells);
            $bodyLines[] = implode(' & ', $wrapped) . ' \\\\\\hline';
        }
        $body = implode("\n", $bodyLines);

        return "\n\n\\noindent\\begin{tabular}{" . $colSpec . "}\\hline\n" . $body . "\n\\end{tabular}\n\n";
    }

    /**
     * Converti inline format HTML → LaTeX commands. Idempotente, loop 3
     * iterazioni per gestire nesting (es. `<b><i>X</i></b>` → `\textbf{\textit{X}}`).
     *
     * Tag map:
     *   <b>/<strong>     → \textbf{}
     *   <i>/<em>         → \textit{}
     *   <u>              → \underline{}
     *   <s>/<del>/<strike> → \sout{} (richiede ulem)
     *   <sub>            → \textsubscript{}
     *   <sup>            → \textsuperscript{}
     */
    public static function convertInlineFormat(string $s): string
    {
        $inlineMap = [
            'b|strong'     => 'textbf',
            'i|em'         => 'textit',
            'u'            => 'underline',
            's|del|strike' => 'sout',
            'sub'          => 'textsubscript',
            'sup'          => 'textsuperscript',
        ];
        for ($iter = 0; $iter < 3; $iter++) {
            $changed = false;
            foreach ($inlineMap as $tagPattern => $latexCmd) {
                $s2 = preg_replace_callback(
                    '#<(' . $tagPattern . ')\b[^>]*>([\s\S]*?)</\1\s*>#i',
                    static fn($m) => '\\' . $latexCmd . '{' . $m[2] . '}',
                    $s
                );
                if ($s2 !== null && $s2 !== $s) {
                    $s = $s2;
                    $changed = true;
                }
            }
            if (!$changed) {
                break;
            }
        }
        return $s;
    }

    /**
     * G22.S15.bis Fase 4 — PRIMA dello strip, estrae i blocchi semantici
     * (TikZ + GeoGebra + liste) e li converte in macro LaTeX per il pipeline
     * pdflatex. Senza questo step il contract HTML perderebbe le immagini
     * (script tag e span wrappers verrebbero rimossi da strip_tags), e le
     * liste perderebbero la struttura (<ul>/<li> non in allow-list).
     */
    public static function stripHtml(string $s, int $depthOffset = 0): string
    {
        // Placeholder strategy: i blocchi LaTeX estratti (tikzpicture,
        // \fmgeogebra, itemize/enumerate) contengono caratteri come `<` o `>`
        // (es. `\ifnum\x<5`) che strip_tags interpreterebbe come tag e
        // mangerebbe. Sostituisco ogni chunk con un placeholder opaco
        // PRIMA di strip_tags, restoro DOPO.
        $holds = [];
        $hold  = static function (string $latex) use (&$holds): string {
            $key = '__FMHOLD_' . count($holds) . '_' . substr(md5($latex), 0, 8) . '__';
            $holds[$key] = $latex;
            return $key;
        };

        // 0. <br> → newline LaTeX. Gestisce sia il tag reale <br>/<br/>/<br />
        //    sia l'entità escaped &lt;br&gt; (testo digitato/incollato, che
        //    sopravvive a strip_tags e finirebbe LETTERALE "<br>" nel PDF).
        //    Un \n singolo è spazio, doppio è paragrafo: niente \\ forzato
        //    (eviterebbe row-break nelle celle tabella e "no line to end" in coda).
        $s = (string)preg_replace('#<br\s*/?>#i', "\n", $s);
        $s = (string)preg_replace('#&lt;\s*br\s*/?\s*&gt;#i', "\n", $s);

        // 1a. <svg data-tikz-body="URL_ENCODED">...</svg> → tikzpicture
        //     Caso post-render JS: tikz-render-client.js sostituisce
        //     <script type="text/tikz"> con <svg data-tikz-body=...>. La
        //     sorgente originale e' preservata URL-encoded sull'SVG.
        $s = preg_replace_callback(
            '#<svg\b[^>]*\bdata-tikz-body=["\']([^"\']*)["\'][^>]*>[\s\S]*?</svg\s*>#i',
            static function ($m) use ($hold): string {
                return $hold(self::extractTikzpicture(rawurldecode((string)$m[1])));
            },
            $s,
        ) ?? $s;

        // 1b. <script type="text/tikz">...</script> → \begin{tikzpicture}...
        //     (il body e' gia' LaTeX TikZ valido). Caso pre-render (es.
        //     server-side payload o env senza JS).
        // G27.tikz.hoist — capture data-tex-packages e data-tikz-libraries
        // (popolati dal contract, vedi PhpContentParser) e li hoist nel
        // preamble accumulator: cosi' i package/libraries dichiarati sul
        // template sono garantiti nel main_*.tex anche quando il body interno
        // non li importa esplicitamente. Dedup automatico per hash.
        $s = preg_replace_callback(
            '#<script\s+type=["\']text/tikz["\']([^>]*)>([\s\S]*?)</script\s*>#i',
            static function ($m) use ($hold): string {
                $attrs = (string)$m[1];
                $pkgs = self::extractAttr($attrs, 'data-tex-packages');
                if ($pkgs !== '') {
                    foreach (explode(',', $pkgs) as $pkg) {
                        $pkg = trim($pkg);
                        if ($pkg !== '') {
                            self::hoistPreamble("\\usepackage{{$pkg}}");
                        }
                    }
                }
                $libs = self::extractAttr($attrs, 'data-tikz-libraries');
                if ($libs !== '') {
                    self::hoistPreamble("\\usetikzlibrary{{$libs}}");
                }
                return $hold(self::extractTikzpicture((string)$m[2]));
            },
            $s,
        ) ?? $s;

        // 2. <span class="fm-geogebra-wrap" data-ggb-base64="..." data-ggb-width="..." ...>SVG</span>
        //    → \fmgeogebra[width=...]{base64}{label}
        // Il preprocessor GeoGebraTexPreProcessor poi convertirà SVG→PDF
        // e sostituirà con \includegraphics{geogebra/N}.
        $s = preg_replace_callback(
            '#<span\s+class=["\']fm-geogebra-wrap["\']([^>]*)>([\s\S]*?)</span>#i',
            static function ($m) use ($hold): string {
                $attrs = (string)$m[1];
                $svgInner = (string)$m[2];

                $ggbB64 = '';
                if (preg_match('/data-ggb-base64=["\']([^"\']*)["\']/i', $attrs, $bm)) {
                    $ggbB64 = $bm[1];
                }
                $label = '';
                if (preg_match('/data-ggb-label=["\']([^"\']*)["\']/i', $attrs, $lm)) {
                    $label = html_entity_decode($lm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $label = (string)preg_replace('/[{}\\\\]/', '', $label);
                }
                $width = '';
                if (preg_match('/data-ggb-width=["\']([^"\']*)["\']/i', $attrs, $wm)) {
                    $width = $wm[1];
                }
                // SVG come base64 per il preprocessor (decode in PDF via VPS).
                // Se l'attributo data-ggb-base64 contiene il file .ggb, l'SVG
                // è nell'inner HTML — servirà b64-encodare l'SVG raw.
                // G27.ggb.xmldecl — SvgSanitizer prepende XML processing
                // instruction all'SVG (best practice). Quando l'HTML del
                // page viene parsato, la PI non e' valido tag HTML e diventa
                // un commento HTML stile <[bang]--[q]xml ... [q]--> qui
                // catturato. Senza fix il preprocessor invia un SVG che
                // inizia con commento HTML al VPS rsvg-convert → rifiuta
                // come "not avalid svg". Strippiamo il commento residuo.
                $svgClean = trim($svgInner);
                $svgClean = (string)preg_replace('#^<!--[?]xml[^>]*[?]-->\s*#i', '', $svgClean);
                $svgB64 = base64_encode($svgClean);
                if ($svgB64 === '') {
                    return "\\textbf{[GeoGebra: SVG mancante]}";
                }

                // Width → option \fmgeogebra:
                //   percentuale "60%" → "width=0.6\\linewidth"
                //   "\\linewidth"/"100%"/"" → no opt (default \\linewidth)
                //   altrimenti as-is (es. "8cm")
                $optArg = '';
                if ($width !== '' && $width !== '100%' && $width !== '\\linewidth') {
                    if (preg_match('/^(\d+(?:\.\d+)?)%$/', $width, $pm)) {
                        $pct = ((float)$pm[1]) / 100;
                        $optArg = '[width=' . $pct . '\\linewidth]';
                    } else {
                        $optArg = '[width=' . preg_replace('/[\[\]]/', '', $width) . ']';
                    }
                }
                return $hold("\n\n\\fmgeogebra" . $optArg . '{' . $svgB64 . '}{' . $label . "}\n\n");
            },
            $s,
        ) ?? $s;

        // 3. Strip residual script tags + commenti HTML
        $s = preg_replace('#<script\b[^>]*>.*?</script\s*>#si', '', $s) ?? $s;
        $s = preg_replace('#<!--.*?-->#s', '', $s) ?? $s;

        // 3b. <a href="URL">text</a> → \href{URL}{text} (LaTeX hyperref).
        //     PRIMA di strip_tags che mangerebbe il <a>. Hold del placeholder
        //     così strip_tags non tocca il \href{} risultante.
        $s = preg_replace_callback(
            '#<a\b[^>]*\bhref=["\']([^"\']*)["\'][^>]*>([\s\S]*?)</a\s*>#i',
            static function ($m) use ($hold): string {
                $url = (string)$m[1];
                $txt = trim(strip_tags((string)$m[2]));
                if ($txt === '') {
                    $txt = $url;
                }
                $url = str_replace(
                    ['\\', '{', '}', '#', '%', '&', '$', '_'],
                    ['\\\\', '\\{', '\\}', '\\#', '\\%', '\\&', '\\$', '\\_'],
                    $url
                );
                return $hold('\\href{' . $url . '}{' . $txt . '}');
            },
            $s
        ) ?? $s;

        // 3c. <span class="dots"></span> → \dots oppure contenuto se non vuoto
        //     (legacy mapping per "soluzione/risposta breve" — class era "fm-sol",
        //     ora rinominata in "dots" per coerenza semantica).
        $s = preg_replace_callback(
            '#<span\b[^>]*\bclass=["\'][^"\']*\bdots\b[^"\']*["\'][^>]*>([\s\S]*?)</span\s*>#i',
            static function ($m) use ($hold): string {
                $inner = trim(strip_tags((string)$m[1]));
                if ($inner === '') {
                    return $hold('\\dots');
                }
                // wrap content in math underline o highlight: legacy = sottolineato
                return $hold('\\underline{' . $inner . '}');
            },
            $s
        ) ?? $s;

        // 3d. <span class="fm-add-text-dsa">text</span> → testo solo in variante DSA.
        //     Per ora: drop content in NOR (default), keep in SOL/DSA via flag.
        //     Il flag $keepPublisherBadges è leveraged: se true (= SOL/full),
        //     preserva il content come testo plain; altrimenti strip.
        $s = preg_replace_callback(
            '#<span\b[^>]*\bclass=["\'][^"\']*\bAddTextDSA\b[^"\']*["\'][^>]*>([\s\S]*?)</span\s*>#i',
            static function ($m): string {
                // Drop: il content DSA va emesso solo nelle varianti DSA, gestito
                // a livello di pipeline (variant_kind=DSA). Per ora drop in tutte.
                return '';
            },
            $s
        ) ?? $s;

        // 4. DSA UI residues. Rimuovi PRIMA della conversione list cosi'
        //    il <li> contiene solo il content reale.
        //    Step 1: strip <button> NON di tipo `.rm-btn` (G23: rm-btn è dato
        //    semantico per la cella RM colonna B, processato da convertRmTable).
        $s = preg_replace_callback(
            '#<button\b([^>]*)>[\s\S]*?</button\s*>#i',
            static function ($m): string {
                $attrs = (string)$m[1];
                // Preserva <button class="fm-rm-btn"> per convertRmTable
                if (preg_match('#\bclass=["\'][^"\']*\brm-btn\b#i', $attrs)) {
                    return $m[0];
                }
                return '';
            },
            $s
        ) ?? $s;
        //    Step 2: strip span/div/aside per classe specifica.
        //    Backref \1 garantisce match same-tag (span con span, etc.).
        $dsaClasses = [
            'span'  => ['fm-dsa-li-buttons', 'fm-dsa-li-num'],
            'div'   => ['fm-dsa-wrapper'],
            'aside' => ['fm-source-badge'],
        ];
        foreach ($dsaClasses as $tag => $classes) {
            foreach ($classes as $cls) {
                $s = preg_replace(
                    '#<(' . $tag . ')\b[^>]*\bclass=["\'][^"\']*\b' . preg_quote($cls, '#') . '\b[^"\']*["\'][^>]*>[\s\S]*?</\1\s*>#i',
                    '',
                    $s
                ) ?? $s;
            }
        }

        // 4b. <table class="fm-rm-table">...</table> → LaTeX tabular.
        //     Tabella risposta multipla con celle a/b/c/d (checkbox + content).
        //     Estrae cells row-by-row, sanitizza ricorsivamente il content,
        //     emette `\begin{tabular}{|p{...}|...|}` con $\square$ checkbox.
        //     G23.fix8 — passa gli attributi del <table> al converter per
        //     leggere data-mpagew/data-width/data-mixtr/data-mixcol.
        $s = preg_replace_callback(
            '#<table\b([^>]*\bclass=["\'][^"\']*\brm-table\b[^"\']*["\'][^>]*)>([\s\S]*?)</table\s*>#i',
            static function ($m) use ($hold): string {
                return $hold(self::convertRmTable((string)$m[2], (string)$m[1]));
            },
            $s,
        ) ?? $s;

        // 5. Liste HTML → ambienti LaTeX. Conversione PRE strip_tags + hold
        //    cosi' i caratteri TeX speciali interni (es. `<` in `\ifnum<`)
        //    non vengono parsati come tag HTML. Annidamento via iterazione.
        // Bottom-up matching per liste annidate: il pattern usa negative
        // lookahead `(?!<(?:ul|ol)\b)` per matchare SOLO le liste innermost
        // (body NON contiene altri <ul>/<ol> open). Ogni innermost viene
        // convertita a placeholder hold con il LABEL custom corrispondente
        // al preset+livello. Al pass successivo l'outer ha solo placeholder
        // testo nel body → matcha pulito.
        //
        // Per i preset gerarchici (data-fm-list-style="<NAME>") usiamo
        // self::listLabelForLevel($preset, $level). Il livello viene
        // calcolato in base alla profondità di annidamento (0=outer, 1=sub, ...).
        //
        // NB: tracking del livello è tricky perché lavoriamo bottom-up.
        // Soluzione: usiamo un prefix marker `__FMLIST_LVL<N>__` nel placeholder
        // della inner list per comunicare il "depth" alla outer list parent.
        for ($depth = 0; $depth < 8; $depth++) {
            $before = $s;
            $s = preg_replace_callback(
                '#<(ul|ol)\b([^>]*)>((?:(?!<(?:ul|ol)\b)[\s\S])*?)</\1\s*>#i',
                static function ($m) use ($hold, $depthOffset): string {
                    $tag    = strtolower($m[1]);
                    $attrs  = (string)$m[2];
                    $body   = (string)$m[3];

                    // Estrai attributi rilevanti
                    $type   = self::extractAttr($attrs, 'type');
                    $preset = self::extractAttr($attrs, 'data-fm-list-style');
                    $start  = self::extractAttr($attrs, 'start');

                    // Determina livello via marker FMLIST_LVL nel body (innerlist
                    // ha già scritto il proprio livello nel placeholder).
                    // Per il PRIMO pass (innermost), il body NON contiene alcun
                    // FMLIST marker → siamo a livello "leaf" (level=2 per default,
                    // assumendo max 3 livelli; se cambia, propaghiamo al rimando).
                    // Approccio piu' robusto: livello determinato dall'outer
                    // quando applica il replace: il prefix marker dice "tu sei
                    // livello N rispetto al mio outer".
                    //
                    // V1 SIMPLIFIED: applichiamo SOLO il label dell'outer level
                    // (livello 0). Sub-list usano il default LaTeX (continuano
                    // il counter parent + label di default enumitem).
                    // Se il preset specifica label per sub-livelli, generiamo
                    // un \setlist[enumerate,N]{label=...} block PRIMA della list.
                    //
                    // Per evitare set globale in preamble, usiamo enumitem
                    // [label=...] inline solo per il livello corrente.
                    $env = $tag === 'ol' ? 'enumerate' : 'itemize';

                    // Convert <li> children — applica conversione inline format PRIMA di strip_tags.
                    $bodyConverted = preg_replace_callback(
                        '#<li\b[^>]*>([\s\S]*?)</li\s*>#i',
                        static function ($lm): string {
                            $content = self::convertInlineFormat((string)$lm[1]);
                            $content = strip_tags(trim($content), '<br>');
                            $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            // Safeguard: dopo decode, eventuali <div>/<p>/<span> derivati
                            // da data-raw escapato (roundtrip legacy) appaiono come tag
                            // letterali → strip + sostituisci con newline-ish.
                            $content = (string)preg_replace('#<(div|p)\b[^>]*>([\s\S]*?)</\1\s*>#i', '$2 ', $content);
                            $content = (string)preg_replace('#<span\b[^>]*>([\s\S]*?)</span\s*>#i', '$1', $content);
                            $content = str_replace(["\xc2\xa0"], ' ', $content);
                            return "\n\\item " . $content;
                        },
                        $body
                    ) ?? $body;

                    // Inline label `[label=...]` SOLO se preset esplicito o legacy `type` attr.
                    // Sub-list senza preset NON emette inline → eredita setlist parent
                    // (override inline annullerebbe setlist[itemize/enumerate,N]).
                    // Per liste interamente default (preset='', no type): enumitem
                    // defaults applicati (nested standard).
                    $hasFmhold = (bool)preg_match('/__FMHOLD_/', $bodyConverted);
                    $emitInline = ($preset !== '') || ($type !== '');
                    $optsArr = [];
                    if ($emitInline) {
                        $label = self::listLabelOuter($env, $type, $preset);
                        if ($label !== '') {
                            $optsArr[] = 'label=' . $label;
                        }
                    }
                    if ($start !== '' && ctype_digit($start) && (int)$start > 1) {
                        $optsArr[] = 'start=' . (int)$start;
                    }
                    $opts = implode(',', $optsArr);
                    $optsStr = $opts !== '' ? "[$opts]" : '';
                    $tex = "\n\\begin{" . $env . "}" . $optsStr . $bodyConverted . "\n\\end{" . $env . "}\n";

                    // Wrap in \begingroup + \setlist solo se OUTER (has FMHOLD) E
                    // preset esplicito (per default lista lascio enumitem defaults,
                    // evitando setlist che leakerebbe globalmente).
                    if ($preset !== '' && $hasFmhold) {
                        $setlistBlock = self::presetSetlistBlock($env, $type, $preset, $depthOffset);
                        if ($setlistBlock !== '') {
                            $tex = "\n\\begingroup\n" . $setlistBlock . ltrim($tex) . "\\endgroup\n";
                        }
                    }
                    return $hold($tex);
                },
                $s
            ) ?? $s;
            if ($s === $before) {
                break;
            }
        }

        // Pre-strip: converti inline format HTML → LaTeX commands (via helper).
        $s = self::convertInlineFormat($s);
        $s = strip_tags($s, '<br><p>');

        // 6. Decode HTML entities (&gt; &lt; &amp; &quot; &nbsp; ...). Il
        //    contract HTML render usa htmlspecialchars per text content;
        //    il LaTeX vuole i caratteri letterali (>, <, &, ", spazio).
        //    NBSP U+00A0 → spazio normale (TeX non gestisce U+00A0).
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = str_replace(["\xc2\xa0"], ' ', $s);

        // 6b. Converti <br>, <p>, e singole newline del content user in LaTeX
        //     line break `\\`. Eseguito PRIMA del restore placeholders così
        //     non tocca i LaTeX chunks (tikz/itemize/geogebra) che restano
        //     intatti dentro __FMHOLD_*__. Le doppie newline (paragrafi)
        //     restano blank-line → LaTeX le interpreta come `\par`.
        //
        //     <p>X</p> → "X\n\n" (paragrafo LaTeX = blank line separator).
        //     <br>      → " \\ \n" (line break LaTeX).
        // ISOLA blocchi math ($...$, \(...\), \[...\], $$...$$) PRIMA delle
        // conversioni br/newline → \\: dentro math un `\\` interrompe argomento
        // di \dfrac/\frac etc → "Missing }" errore pdflatex.
        $mathHold = [];
        $isolateMath = static function (string $s) use (&$mathHold): string {
            $out = (string)preg_replace_callback(
                // ordine: \[\] (display), $$ $$, \(\), $ $ (inline)
                '#(\\\\\[[\s\S]*?\\\\\]|\$\$[\s\S]*?\$\$|\\\\\([\s\S]*?\\\\\)|(?<!\$)\$(?!\$)[\s\S]*?(?<!\\\\)\$)#',
                function ($m) use (&$mathHold) {
                    // Pulizia interna math: <br> → spazio (no \\), \n singoli
                    // → spazio. Math LaTeX non vuole break interni a macro.
                    $clean = preg_replace('#<br\s*/?>#i', ' ', $m[0]) ?? $m[0];
                    $clean = (string)preg_replace("/\n+/", ' ', $clean);
                    $idx = count($mathHold);
                    $mathHold[$idx] = $clean;
                    return "\x02M{$idx}\x02";
                },
                $s,
            );
            return $out ?? $s;
        };
        $restoreMath = static function (string $s) use (&$mathHold): string {
            foreach ($mathHold as $idx => $orig) {
                $s = str_replace("\x02M{$idx}\x02", $orig, $s);
            }
            $mathHold = [];
            return $s;
        };
        $s = $isolateMath($s);
        $s = (string)preg_replace('#<p\b[^>]*>([\s\S]*?)</p\s*>#i', "$1\n\n", $s);
        $s = (string)preg_replace('#<br\s*/?>#i', " \\\\\\\\\n", $s);
        // G27 — CAUSA RADICE del `\\` orfano: un `\n` singolo adiacente al
        // placeholder di un blocco (__FMHOLD_..__ = geogebra/tikz/immagine/lista)
        // NON va convertito in `\\`. Quei blocchi diventano paragrafi
        // (\n\n..\n\n) al restore, quindi il `\\` finirebbe a bordo paragrafo
        // → "There's no line here to end". Le lookaround escludono i `\n`
        // attaccati a un placeholder (che inizia con __FMHOLD_ e finisce con __).
        $s = (string)preg_replace("/(?<!\n)(?<!__)\n(?!\n)(?!__FMHOLD_)/", " \\\\\\\\\n", $s);
        $s = $restoreMath($s);

        // G27.text.escape — Escape caratteri LaTeX speciali nel TEXT plain
        // (residuo dopo strip_tags + entity decode). Senza, `_` in titoli/
        // text content (es. "test_ver", "type_RMulti") veniva interpretato
        // da pdflatex come math subscript silenziosamente, producendo
        // visualmente "test<sub>ver</sub>" nel PDF.
        // Isoliamo PRIMA i placeholder `__FMHOLD_*__` (che contengono `_` da
        // NON escapare, altrimenti il restore non li trova piu') con un
        // marker `\x01N\x01` temporaneo, applichiamo l'escape, poi
        // ripristiniamo. Senza isolamento, `__FMHOLD_0_xxx__` diventava
        // `\_\_FMHOLD\_0\_xxx\_\_` e il for-loop di restore (che cerca
        // `__FMHOLD_*__` esatto) falliva, lasciando i placeholder visibili
        // come testo nel PDF.
        $hldMap = [];
        $s = (string)preg_replace_callback('/__FMHOLD_\d+_[a-f0-9]+__/', function ($m) use (&$hldMap) {
            $idx = count($hldMap);
            $hldMap[$idx] = $m[0];
            return "\x01H{$idx}\x01";
        }, $s);
        $s = (string)preg_replace('/(?<!\\\\)_/', '\\_', $s);
        $s = (string)preg_replace('/(?<!\\\\)&/', '\\&', $s);
        $s = (string)preg_replace('/(?<!\\\\)#/', '\\#', $s);
        $s = (string)preg_replace('/(?<!\\\\)%/', '\\%', $s);
        // Restore placeholder originali (intatti, cosi' il for-loop sotto li trova).
        foreach ($hldMap as $idx => $orig) {
            $s = str_replace("\x01H{$idx}\x01", $orig, $s);
        }

        // 7. Restore placeholder LaTeX chunks (tikzpicture, \fmgeogebra,
        //    itemize/enumerate). Loop perche' un chunk puo' contenere altri
        //    placeholder (es. itemize annidato che conteneva tikzpicture).
        for ($i = 0; $i < 8 && $holds; $i++) {
            $changed = false;
            foreach ($holds as $key => $val) {
                if (strpos($s, $key) !== false) {
                    $s = str_replace($key, $val, $s);
                    unset($holds[$key]);
                    $changed = true;
                }
            }
            if (!$changed) {
                break;
            }
        }

        // G27 — rimuovi `\\` ORFANI a bordo paragrafo (adiacenti a una blank
        // line DOPO il restore dei blocchi: es. immagine geogebra/tikz wrappata
        // in \n\n...\n\n). Un `\\` a inizio/fine paragrafo non ha una riga da
        // terminare → "! LaTeX Error: There's no line here to end." (non fatale
        // ma riempie il log e spaventa l'utente). Nasce dalla conversione
        // single-newline→`\\` (punto 6b) quando il `\n` è adiacente a un blocco.
        // I `\\` di tabella/riga sono seguiti da newline SINGOLO (non blank
        // line) → il regex non li tocca.
        $s = (string)preg_replace('/(\n[ \t]*\n[ \t]*)\\\\\\\\(?=[ \t]*\n)/', '$1', $s); // inizio paragrafo
        $s = (string)preg_replace('/\\\\\\\\([ \t]*\n[ \t]*\n)/', '$1', $s);             // fine paragrafo

        return trim($s);
    }

    /**
     * G19.1 — pass-through per contenuti LaTeX raw.
     *
     * G19.49h — `$keepPublisherBadges` (default false) controlla se
     * preservare i badge editoriali (publisher/textbook cards). False per
     * varianti NOR/DSA/DIS (stampa studente, no riferimenti libro), true
     * per SOL (versione docente, badge utili come ref).
     */
    /**
     * @param int $depthOffset Offset enumitem level per liste annidate dentro
     *   un `\begin{enumerate}` esterno (es. TableRenderer wraps in A) B) C)
     *   → offset=1 perché il preset outer è già a depth 2).
     */
    public static function latexPassthrough(string $s, bool $keepPublisherBadges = false, int $depthOffset = 0): string
    {
        $s = self::stripHtml($s, $depthOffset);
        $s = self::normalizeMathJax($s, $keepPublisherBadges);
        $s = (string)preg_replace("/\n{3,}/", "\n\n", $s);
        // Cleanup `\\` orfani inseriti dalla conversione `\n → \\` quando
        // adiacenti a strutture LaTeX (block-end, group-end, list env).
        // LaTeX error: "There's no line here to end" se `\\` segue \end{X} o \endgroup.
        // G27.tabular.fix — escludi `\end{minipage}` dal cleanup: in tabular
        // cells lo schema legittimo e' `\end{minipage} \\\hline` dove `\\` e'
        // il row-terminator. Strippandolo, l'`\hline` finale orfano non
        // chiudeva la tabella → bordo bottom mancante visibile in PDF.
        $s = (string)preg_replace('/(\\\\(?:end\{(?!minipage\b)[^}]+\}|endgroup|begingroup|par))\s*\\\\\\\\\s*/m', "$1\n", $s);
        // Anche `\\` dopo `\begin{X}` (apertura ambiente) o all'inizio stringa.
        $s = (string)preg_replace('/(\\\\begin\{[^}]+\})\s*\\\\\\\\\s*/m', "$1\n", $s);
        $s = (string)preg_replace('/^\s*\\\\\\\\\s*/', '', $s);
        return trim($s);
    }

    /**
     * G19.49g/h — Converte sintassi MathJax-only in equivalenti LaTeX
     * compilabili.
     *
     * @param bool $keepPublisherBadges true: preserva i badge editoriali
     *   (publisher/textbook reference cards) — usato per varianti SOL.
     *   false (default): strippa interamente i `\(... \begin{array}{|c|}\hline
     *   \small{\text{...}} ... \end{array} ...\)` — usato per NOR/DSA/DIS.
     *
     * Trasformazioni sempre applicate:
     *   - `\(...\)` → `$...$` (math inline delimiter LaTeX puro)
     *   - `\enclose{shape}[mathcolor=C]{X}` → `\Circled[inner color=C, outer color=C]{X}`
     *   - `\bbox[opts]{X}` → `{X}`
     *   - `\mathmakebox[W][A]{X}` → `{X}`
     *   - `\lower{NNNpt}` → `` (strip)
     */
    public static function normalizeMathJax(string $s, bool $keepPublisherBadges = false): string
    {
        // 1. Strip publisher badge SE non flag (NOR/DSA/DIS).
        //    Pattern: `\(...\begin{array}{|c|}\hline\small{\text{...}}...\end{array}...\)`
        if (!$keepPublisherBadges) {
            $s = preg_replace(
                '#\\\\\(\s*\\\\begin\{array\}\{\|c\|\}\\\\hline\\\\small.*?\\\\end\{array\}.*?\\\\\)#s',
                '',
                $s
            ) ?? $s;
        }

        // 2. \enclose{circle}[mathcolor=COLOR]{X} →
        //    \Circled[inner color=COLOR, outer color=COLOR]{X}
        $s = preg_replace(
            '#\\\\enclose\{[a-zA-Z]+\}\[mathcolor=([a-zA-Z]+)\]\{([^{}]+)\}#',
            '\\Circled[inner color=$1, outer color=$1]{$2}',
            $s
        ) ?? $s;

        // 3. \bbox[opts]{X}: estrae `background:COLOR` (se presente) e
        //    converte in `\colorbox{COLOR}{X}` per preservare il box
        //    verde/giallo del badge editoriale. Senza background drop il
        //    wrapper. Iterato per nesting multipli.
        for ($i = 0; $i < 5; $i++) {
            $next = preg_replace_callback(
                '#\\\\bbox\[([^\]]*)\]\{#',
                static function ($m) {
                    $opts = $m[1];
                    if (preg_match('/background(?:-color)?\s*:\s*([a-zA-Z]+)/', $opts, $bg)) {
                        return '\\colorbox{' . $bg[1] . '}{';
                    }
                    return '{';
                },
                $s
            );
            if ($next === null || $next === $s) {
                break;
            }
            $s = $next;
        }

        // 4. \mathmakebox[W][A]{X} → {X} (drop wrapper, mantieni content)
        $s = preg_replace(
            '#\\\\mathmakebox\[[^\]]*\]\[[^\]]*\]\{#',
            '{',
            $s
        ) ?? $s;

        // 5. \lower{[-+]NNNpt} → strip
        $s = preg_replace('#\\\\lower\{[-+]?\d*\.?\d+pt\}#', '', $s) ?? $s;

        // 6. `\(...\)` → `$...$` (LaTeX inline math). Conversione semplice
        //    sui marker (no parsing balanciato — sufficiente perche'
        //    `\(` e `\)` non hanno significato fuori dai delimiter math).
        $s = preg_replace('#\\\\\(#', '$', $s) ?? $s;
        $s = preg_replace('#\\\\\)#', '$', $s) ?? $s;

        return $s;
    }
}
