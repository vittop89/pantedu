<?php

declare(strict_types=1);

namespace App\Services\Risdoc\Pt;

/**
 * Phase 24.35 — Portable Text → HTML server-side renderer.
 *
 * Pendant del PtToTex per uso in pagine `/studio/{type}/...` quando un
 * teacher_content ha `metadata.body_pt` popolato (PT AST salvato dal
 * section-edit modal con <fm-pt-editor>).
 *
 * Subset coperto (corrispondente al sub-PT del editor):
 *   block (style=normal) + span (marks: strong/em/underline/code) + fieldRef
 *   checkboxGroup (renderMode: all|checked-only|checked-inline)
 *   sectionHeader (level 1-4)
 *   table (columns, rows, caption, headerNote, footerNote, cell.bg)
 *   select / textField / formCheckbox  (display read-only)
 *   rawTex (escaped come `<code>`, no LaTeX exec)
 *
 * Context (`fields`, `state`) per fieldRef substitution: stessa logica PtToTex.
 */
final class PtToHtml
{
    private static array $ctx = ['fields' => [], 'state' => []];
    public static function render(array $blocks, array $context = []): string
    {
        self::$ctx = [
            'fields' => (array)($context['fields'] ?? []),
            'state'  => (array)($context['state']  ?? []),
        ];
        $parts = [];
        $n = count($blocks);
        // Esclusione sezione (👁 in output off): salta header + contenuto fino al
        // prossimo sectionHeader con level <= a quello escluso (level-aware).
        $excludeUntil = null;
        for ($i = 0; $i < $n; $i++) {
            $b = $blocks[$i];
            if (!is_array($b) || !isset($b['_type'])) {
                continue;
            }
            if (($b['_type'] ?? '') === 'sectionHeader') {
                $hlvl = (int)($b['level'] ?? 2);
                if ($excludeUntil !== null && $hlvl <= $excludeUntil) {
                    $excludeUntil = null;
                }
                if ($excludeUntil === null && !empty($b['excluded'])) {
                    $excludeUntil = $hlvl;
                    continue;
                }
            }
            if ($excludeUntil !== null) {
                continue;
            }
            // Liste: run di blocchi listItem → ul/ol annidate (data-fm-list-style).
            if (($b['_type'] ?? '') === 'block' && !empty($b['listItem'])) {
                $run = [];
                while (
                    $i < $n && is_array($blocks[$i])
                    && ($blocks[$i]['_type'] ?? '') === 'block' && !empty($blocks[$i]['listItem'])
                ) {
                    $run[] = $blocks[$i];
                    $i++;
                }
                $i--;
                $parts[] = self::renderListRun($run);
                continue;
            }
            $r = self::renderBlock($b);
            if ($r !== '') {
                $parts[] = $r;
            }
        }
        return implode("\n", $parts);
    }

    /** Run di blocchi-lista PT → ul/ol annidate (preview). Stack per-livello. */
    private static function renderListRun(array $run): string
    {
        $out = '';
        $stack = [];   // tag aperti per livello
        $open = 0;
        foreach ($run as $b) {
            $level = max(1, (int)($b['level'] ?? 1));
            $tag   = (($b['listItem'] ?? '') === 'number') ? 'ol' : 'ul';
            $style = (string)($b['listStyle'] ?? '');
            while ($open < $level) {
                $attr = $style !== '' ? ' data-fm-list-style="' . self::esc($style) . '"' : '';
                $out .= '<' . $tag . ' class="fm-pt-list"' . $attr . '>';
                $stack[] = $tag;
                $open++;
            }
            while ($open > $level) {
                $out .= '</' . array_pop($stack) . '>';
                $open--;
            }
            $inner = '';
            foreach ((array)($b['children'] ?? []) as $c) {
                if (is_array($c) && isset($c['_type'])) {
                    $inner .= self::renderInline($c);
                }
            }
            $out .= '<li>' . $inner . '</li>';
        }
        while ($open > 0) {
            $out .= '</' . array_pop($stack) . '>';
            $open--;
        }
        return $out;
    }

    private static function renderBlock(array $b): string
    {
        return match ($b['_type']) {
            'block'          => self::renderTextBlock($b),
            'checkboxGroup'  => self::renderCheckboxGroup($b),
            'sectionHeader'  => self::renderSectionHeader($b),
            'table'          => self::renderTable($b),
            'select'         => self::renderSelect($b),
            'textField'      => self::renderTextField($b),
            'formCheckbox'   => self::renderFormCheckbox($b),
            'rawTex'         => '<pre class="fm-pt-rawtex"><code>'
                                . self::esc((string)($b['content'] ?? ''))
                                . '</code></pre>',
            // G23 page-doc block types
            'glossaryTable'  => self::renderGlossaryTable($b),
            'staticContent'  => self::renderStaticContent($b),
            'accordion'      => self::renderAccordion($b),
            'linkListPdf'    => self::renderLinkListPdf($b),
            'citationNorma'  => self::renderCitationNorma($b),
            default          => '',
        };
    }

    /**
     * G23 page-doc — glossaryTable: HTML semantico con caption + th[scope].
     * Sort/search runtime via vanilla JS hook (no inline script qui).
     */
    private static function renderGlossaryTable(array $b): string
    {
        $cols    = is_array($b['columns'] ?? null) ? $b['columns'] : [];
        $entries = is_array($b['entries'] ?? null) ? $b['entries'] : [];
        if (count($cols) === 0) {
            return '';
        }
        $name = (string)($b['name'] ?? '');
        $searchable = ($b['searchable'] ?? true) !== false;

        $search = $searchable
            ? '<input type="search" class="pt-glossary-search" placeholder="Cerca…" aria-label="Cerca nel glossario">'
            : '';

        $thead = '<thead><tr>';
        foreach ($cols as $c) {
            $key = self::headerToKey((string)$c);
            $thead .= '<th scope="col" class="pt-glossary-th" data-col-key="' . self::esc($key) . '">'
                   .  self::esc((string)$c) . '</th>';
        }
        $thead .= '</tr></thead>';

        $tbody = '';
        foreach ($entries as $e) {
            if (!is_array($e)) {
                continue;
            }
            $tbody .= '<tr>';
            foreach ($cols as $c) {
                $key = self::headerToKey((string)$c);
                $tbody .= '<td>' . self::esc((string)($e[$key] ?? '')) . '</td>';
            }
            $tbody .= '</tr>';
        }

        $nameAttr = $name !== '' ? ' data-name="' . self::esc($name) . '"' : '';
        $count    = count($entries);
        return '<div class="pt-glossary-table"' . $nameAttr . '>'
             . $search
             . '<table>'
             . '<caption class="pt-glossary-caption">Glossario (' . $count . ' voci)</caption>'
             . $thead
             . '<tbody>' . $tbody . '</tbody>'
             . '</table>'
             . '</div>';
    }

    /**
     * G23 page-doc — staticContent: body HTML pre-sanitizzato (assume input
     * passato attraverso HtmlSanitizer::forPageDoc() prima di persistenza).
     * Nesting items ricorsivo.
     */
    private static function renderStaticContent(array $b): string
    {
        $level = max(2, min(4, (int)($b['level'] ?? 2)));
        $title = (string)($b['title'] ?? '');
        $body  = (string)($b['body']  ?? '');
        $items = is_array($b['items'] ?? null) ? $b['items'] : [];

        $heading = $title !== ''
            ? "<h{$level}>" . self::esc($title) . "</h{$level}>"
            : '';

        // body è già sanitizzato server-side da HtmlSanitizer::forPageDoc().
        // Defensive: se feature flag disabled o input legacy, ripassa sanitize.
        $bodySafe = $body !== ''
            ? \App\Services\Security\HtmlSanitizer::forPageDoc($body)
            : '';
        $bodyHtml = $bodySafe !== '' ? '<div class="pt-static-content__body">' . $bodySafe . '</div>' : '';

        $nested = '';
        foreach ($items as $it) {
            if (is_array($it) && (string)($it['_type'] ?? '') === 'staticContent') {
                $nested .= self::renderStaticContent($it);
            }
        }

        return '<section class="pt-static-content" data-level="' . $level . '">'
             . $heading . $bodyHtml . $nested
             . '</section>';
    }

    /**
     * G23 page-doc — accordion via <details>/<summary> native.
     * body_pt nested = array di PT blocks (renderizzati ricorsivamente).
     */
    private static function renderAccordion(array $b): string
    {
        $items = is_array($b['items'] ?? null) ? $b['items'] : [];
        if (count($items) === 0) {
            return '';
        }
        $allowMultiple = ($b['allow_multiple'] ?? true) !== false;

        $itemsHtml = '';
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            // Item escluso (checkbox spuntata) → omesso dall'output.
            if (!empty($it['excluded'])) {
                continue;
            }
            $title = self::esc((string)($it['title'] ?? ''));
            $open  = !empty($it['default_open']) ? ' open' : '';
            $bodyPt = is_array($it['body_pt'] ?? null) ? $it['body_pt'] : [];
            $body = '';
            foreach ($bodyPt as $sub) {
                if (is_array($sub)) {
                    $body .= self::renderBlock($sub);
                }
            }
            $itemsHtml .= '<details class="pt-accordion__item"' . $open . '>'
                       .  '<summary>' . $title . '</summary>'
                       .  '<div class="pt-accordion__body">' . $body . '</div>'
                       .  '</details>';
        }

        if ($itemsHtml === '') {
            return ''; // tutti gli item esclusi
        }
        $multipleAttr = $allowMultiple ? 'true' : 'false';
        return '<div class="pt-accordion" data-multiple="' . $multipleAttr . '">' . $itemsHtml . '</div>';
    }

    /** G23 page-doc — linkListPdf: lista link normativi gerarchici. */
    private static function renderLinkListPdf(array $b): string
    {
        $title = (string)($b['title'] ?? '');
        $items = is_array($b['items'] ?? null) ? $b['items'] : [];
        if (count($items) === 0) {
            return '';
        }
        $titleHtml = $title !== ''
            ? '<h3 class="pt-link-list-pdf__title">' . self::esc($title) . '</h3>'
            : '';
        $itemsHtml = '';
        foreach ($items as $it) {
            $itemsHtml .= self::renderLinkListItem($it);
        }
        return '<section class="pt-link-list-pdf">'
             . $titleHtml
             . '<ul class="pt-link-list-pdf__list">' . $itemsHtml . '</ul>'
             . '</section>';
    }

    private static function renderLinkListItem(mixed $item, bool $isSub = false): string
    {
        if (!is_array($item)) {
            return '';
        }
        $label = (string)($item['label'] ?? '');
        $href  = (string)($item['href']  ?? '');
        if ($label === '' || $href === '') {
            return '';
        }
        $external = !empty($item['external']) || (bool)preg_match('#^https?://#i', $href);
        $targetAttr = $external ? ' target="_blank" rel="noopener noreferrer"' : '';
        $iconHtml   = $external ? ' <span class="pt-link-list-pdf__icon" aria-hidden="true">↗</span>' : '';
        $desc = (string)($item['description'] ?? '');
        $descHtml = $desc !== ''
            ? '<p class="pt-link-list-pdf__desc">' . self::esc($desc) . '</p>'
            : '';
        $subs = is_array($item['sub_items'] ?? null) ? $item['sub_items'] : [];
        $subsHtml = '';
        if (!$isSub && count($subs) > 0) {
            $subsHtml = '<ul class="pt-link-list-pdf__sublist">';
            foreach ($subs as $s) {
                $subsHtml .= self::renderLinkListItem($s, true);
            }
            $subsHtml .= '</ul>';
        }
        return '<li>'
             . '<a href="' . self::esc($href) . '" class="pt-link-list-pdf__link"' . $targetAttr . '>'
             . self::esc($label) . $iconHtml
             . '</a>'
             . $descHtml . $subsHtml
             . '</li>';
    }

    /** G23 page-doc — citationNorma: blocco citazione legge/decreto. */
    private static function renderCitationNorma(array $b): string
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
        $articoloHtml = $articolo !== ''
            ? ' <span class="pt-citation-norma__articolo">' . self::esc($articolo) . '</span>'
            : '';
        $quoteHtml = $quote !== ''
            ? '<blockquote class="pt-citation-norma__quote">' . self::esc($quote) . '</blockquote>'
            : '';
        $titleEsc = self::esc($title !== '' ? $title : $headLabel);
        $titleHtml = '';
        if ($href !== '') {
            $titleHtml = '<p class="pt-citation-norma__title">'
                       . '<a href="' . self::esc($href) . '" target="_blank" rel="noopener noreferrer">' . $titleEsc . '</a>'
                       . '</p>';
        } elseif ($title !== '') {
            $titleHtml = '<p class="pt-citation-norma__title">' . $titleEsc . '</p>';
        }

        return '<aside class="pt-citation-norma" data-tipo="' . self::esc($tipo) . '">'
             . '<div class="pt-citation-norma__header">'
             . '<strong>' . self::esc($headLabel) . '</strong>' . $articoloHtml
             . '</div>'
             . $quoteHtml . $titleHtml
             . '</aside>';
    }

    /**
     * G23 page-doc — header label → entry key.
     * "N." → "n", "Lemma" → "lemma", "Definizione" → "definizione".
     */
    private static function headerToKey(string $header): string
    {
        $h = mb_strtolower($header, 'UTF-8');
        $h = str_replace('.', '', $h);
        $h = strtr($h, [
            'à' => 'a','á' => 'a','â' => 'a','ä' => 'a',
            'è' => 'e','é' => 'e','ê' => 'e','ë' => 'e',
            'ì' => 'i','í' => 'i','î' => 'i','ï' => 'i',
            'ò' => 'o','ó' => 'o','ô' => 'o','ö' => 'o',
            'ù' => 'u','ú' => 'u','û' => 'u','ü' => 'u',
        ]);
        $h = preg_replace('/\s+/', '_', $h);
        $h = preg_replace('/[^a-z0-9_]/', '', $h);
        $h = trim($h, '_');
        return $h;
    }

    private static function renderTextBlock(array $b): string
    {
        $children = $b['children'] ?? [];
        if (!is_array($children)) {
            return '';
        }
        $parts = [];
        foreach ($children as $c) {
            if (!is_array($c) || !isset($c['_type'])) {
                continue;
            }
            $parts[] = self::renderInline($c);
        }
        $inner = implode('', $parts);
        if ($inner === '') {
            return '';
        }
        // Allineamento paragrafo (Google Docs) → style text-align.
        $align = $b['textAlign'] ?? null;
        $style = in_array($align, ['center', 'right', 'justify', 'left'], true)
            ? ' style="text-align:' . $align . '"' : '';
        return '<p' . $style . '>' . $inner . '</p>';
    }

    private static function renderInline(array $c): string
    {
        return match ($c['_type']) {
            'span'     => self::renderSpan($c),
            'fieldRef' => self::renderFieldRef($c),
            default    => '',
        };
    }

    private static function renderSpan(array $s): string
    {
        $text = self::esc((string)($s['text'] ?? ''));
        if ($text === '') {
            return '';
        }
        $marks = is_array($s['marks'] ?? null) ? $s['marks'] : [];
        $out = $text;
        foreach (array_reverse($marks) as $m) {
            $out = match ((string)$m) {
                'strong'    => '<strong>' . $out . '</strong>',
                'em'        => '<em>' . $out . '</em>',
                'underline' => '<u>' . $out . '</u>',
                'code'      => '<code>' . $out . '</code>',
                default     => $out,
            };
        }
        return $out;
    }

    private static function renderFieldRef(array $node): string
    {
        $name = (string)($node['name'] ?? '');
        if ($name === '') {
            return '';
        }
        $val = self::$ctx['state'][$name] ?? self::$ctx['fields'][$name] ?? null;
        if (is_string($val) && $val !== '') {
            return self::esc($val);
        }
        if (is_array($val)) {
            return self::esc(implode(', ', array_map('strval', $val)));
        }
        return '<span class="fm-pt-field-missing" title="campo non valorizzato">[' . self::esc($name) . ']</span>';
    }

    private static function renderCheckboxGroup(array $b): string
    {
        $items = is_array($b['items'] ?? null) ? $b['items'] : [];
        if (count($items) === 0) {
            return '';
        }
        $mode = (string)($b['renderMode'] ?? 'all');
        // Impaginazione su N colonne (1–5); column-count via style inline.
        $nCols  = max(1, min(5, (int)($b['columns'] ?? 1)));
        $cols2  = $nCols >= 2 ? ' fm-pt-cb--multicol" style="column-count:' . $nCols : '';
        if ($mode === 'checked-only' || $mode === 'checked-inline') {
            $checked = [];
            foreach ($items as $it) {
                if (is_array($it) && (string)($it['state'] ?? '_') === 'x') {
                    $lbl = (string)($it['label'] ?? '');
                    if ($lbl !== '') {
                        $checked[] = self::esc($lbl);
                    }
                }
            }
            if (count($checked) === 0) {
                return '';
            }
            if ($mode === 'checked-inline') {
                return '<span class="fm-pt-cb-inline">' . implode(', ', $checked) . '</span>';
            }
            return '<ul class="fm-pt-cb-list' . $cols2 . '">'
                 . implode('', array_map(static fn($l) => '<li>' . $l . '</li>', $checked))
                 . '</ul>';
        }

        // mode "all" — con intestazioni di gruppo se gli item hanno `group`.
        $rows = [];
        $lastGroup = null;
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $g = (string)($it['group'] ?? '');
            if ($g !== '' && $g !== $lastGroup) {
                $rows[] = '<div class="fm-pt-cb-group-head">' . self::esc($g) . '</div>';
                $lastGroup = $g;
            }
            $sym = ((string)($it['state'] ?? '_') === 'x') ? '☑' : '☐';
            $lbl = self::esc((string)($it['label'] ?? ''));
            $rows[] = '<label class="fm-pt-cb-item"><span class="fm-pt-cb-state">' . $sym . '</span> ' . $lbl . '</label>';
        }
        return '<div class="fm-pt-checkbox-group' . $cols2 . '">' . implode(' ', $rows) . '</div>';
    }

    private static function renderSectionHeader(array $b): string
    {
        $title = (string)($b['title'] ?? '');
        if (trim($title) === '') {
            return '';
        }
        $level = max(1, min(6, (int)($b['level'] ?? 2) + 1));
// h2-h5 (h1 reserved page title)
        $tag = "h{$level}";
        $extra = '';
        $selectors = is_array($b['selectors'] ?? null) ? $b['selectors'] : [];
        if (count($selectors) > 0) {
            $resolved = array_map(static function ($n) {

                $key = (string)$n;
                $val = self::$ctx['state'][$key] ?? self::$ctx['fields'][$key] ?? '';
                return is_string($val) && $val !== ''
                    ? self::esc($val)
                    : '<span class="fm-pt-field-missing">[' . self::esc($key) . ']</span>';
            }, $selectors);
            $extra = ' <small class="fm-pt-section-selectors">' . implode(' · ', $resolved) . '</small>';
        }
        return "<{$tag} class=\"pt-section-header\">" . self::esc($title) . $extra . "</{$tag}>";
    }

    private static function renderTable(array $b): string
    {
        $cols = is_array($b['columns'] ?? null) ? $b['columns'] : [];
        $rows = is_array($b['rows']    ?? null) ? $b['rows']    : [];
        if (count($cols) === 0) {
            return '';
        }

        $colCount = count($cols);

        // ADR-031 — calcola le celle formula (per indice di colonna, come l'editor:
        // i riferimenti A1 puntano a row[colIndex]). Le celle referenziate hanno già
        // i valori della terna corrente (applyAndStrip è girato prima) → risultato
        // PER CLASSE in automatico.
        $fgrid = [];
        foreach ($rows as $ri => $row) {
            $fgrid[$ri] = [];
            for ($c = 0; $c < $colCount; $c++) {
                $cell = is_array($row) ? ($row[$c] ?? null) : null;
                if (is_array($cell) && isset($cell['formula']) && FormulaEngine::isFormula($cell['formula'])) {
                    $fgrid[$ri][$c] = ['formula' => $cell['formula']];
                } else {
                    $raw = '';
                    if (is_string($cell)) {
                        $raw = $cell;
                    } elseif (is_array($cell)) {
                        $wv = $cell['widget']['value'] ?? null;
                        $raw = is_scalar($wv) ? (string)$wv : (string)($cell['text'] ?? '');
                    }
                    $fgrid[$ri][$c] = ['raw' => $raw];
                }
            }
        }
        $fres = FormulaEngine::computeTableValues($fgrid);

        $head = '<tr>' . implode('', array_map(static fn($c) => '<th>' . self::esc((string)$c) . '</th>', $cols)) . '</tr>';
        $body = '';
        foreach ($rows as $ri => $row) {
            if (!is_array($row)) {
                continue;
            }
            $cells = '';
            $emitted = 0;
            foreach (array_values($row) as $cell) {
                if ($emitted >= $colCount) {
                    break;
                }
                if (is_string($cell)) {
                    $cells .= '<td>' . self::renderInlineText($cell) . '</td>';
                    $emitted++;
                    continue;
                }
                if (is_array($cell)) {
                    if (!empty($cell['merged'])) {
                        continue;
                    }
                    $ci = $emitted; // indice colonna corrente (no-merge: allineato a row[ci])
                    $colspan = max(1, (int)($cell['colspan'] ?? 1));
                    $colspan = min($colspan, $colCount - $emitted);
                    $rowspan = max(1, (int)($cell['rowspan'] ?? 1));
                    $css = [];
                    $bg = (string)($cell['bg'] ?? '');
                    if ($bg !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $bg)) {
                        $css[] = 'background-color:' . self::esc($bg);
                    }
                    $align = (string)($cell['align'] ?? '');
                    if (in_array($align, ['left', 'center', 'right'], true)) {
                        $css[] = 'text-align:' . $align;
                    }
                    $valign = (string)($cell['valign'] ?? '');
                    if (in_array($valign, ['top', 'middle', 'bottom'], true)) {
                        $css[] = 'vertical-align:' . $valign;
                    }
                    $style = $css ? ' style="' . implode(';', $css) . '"' : '';
                    $attrs = '';
                    if ($colspan > 1) {
                        $attrs .= ' colspan="' . $colspan . '"';
                    }
                    if ($rowspan > 1) {
                        $attrs .= ' rowspan="' . $rowspan . '"';
                    }
                    // ADR-031 — cella formula: mostra il RISULTATO calcolato.
                    if (isset($cell['formula']) && FormulaEngine::isFormula($cell['formula'])) {
                        $fr = $fres[$ri][$ci] ?? null;
                        $disp = $fr ? (string)$fr['display'] : '';
                        $errCls = ($fr && $fr['error']) ? ' fm-pt-formula--err' : '';
                        $inner = '<span class="fm-pt-formula' . $errCls . '">' . self::esc($disp) . '</span>';
                    } else {
                        $inner = self::renderCellContent($cell);
                    }
                    $cells .= '<td' . $attrs . $style . '>' . $inner . '</td>';
                    $emitted += $colspan;
                    continue;
                }
                $cells .= '<td>' . self::renderInlineText((string)$cell) . '</td>';
                $emitted++;
            }
            while ($emitted < $colCount) {
                $cells .= '<td></td>';
                $emitted++;
            }
            $body .= '<tr>' . $cells . '</tr>';
        }

        $cap = (string)($b['caption']    ?? '');
        $hn  = (string)($b['headerNote'] ?? '');
        $fn  = (string)($b['footerNote'] ?? '');

        // Larghezza tabella: "full" → occupa tutta la larghezza utile +
        // <colgroup> con le percentuali per-colonna (rispetta la max-width
        // della pagina, che lato CSS è già vincolata dal container/orient).
        $widthMode  = (($b['widthMode'] ?? 'auto') === 'full') ? 'full' : 'auto';
        $tableClass = 'fm-pt-table' . ($widthMode === 'full' ? ' fm-pt-table--full' : '');
        $colgroup   = '';
        if ($widthMode === 'full') {
            $widths = self::normalizeColWidths(
                is_array($b['colWidths'] ?? null) ? $b['colWidths'] : [],
                count($cols)
            );
            $colgroup = '<colgroup>'
                . implode('', array_map(
                    static fn($w) => '<col style="width:' . $w . '%">',
                    $widths
                ))
                . '</colgroup>';
        }

        $out = '';
        if ($hn !== '') {
            $out .= '<p class="fm-pt-table-note pt-table-note-header"><em>' . self::esc($hn) . '</em></p>';
        }
        $out .= '<table class="' . $tableClass . '">';
        if ($cap !== '') {
            $out .= '<caption>' . self::esc($cap) . '</caption>';
        }
        $out .= $colgroup . '<thead>' . $head . '</thead><tbody>' . $body . '</tbody></table>';
        if ($fn !== '') {
            $out .= '<p class="fm-pt-table-note pt-table-note-footer"><em>' . self::esc($fn) . '</em></p>';
        }
        return $out;
    }

    /**
     * Normalizza le larghezze per-colonna in percentuali che sommano a 100.
     * Se NON tutte le colonne hanno un valore valido (>0) → ripartizione equa.
     * Ritorna un array di colCount float arrotondati a 2 decimali.
     */
    public static function normalizeColWidths(array $widths, int $colCount): array
    {
        if ($colCount <= 0) {
            return [];
        }
        $vals = [];
        $allValid = true;
        for ($i = 0; $i < $colCount; $i++) {
            $v = isset($widths[$i]) && is_numeric($widths[$i]) ? (float)$widths[$i] : 0.0;
            if ($v <= 0) {
                $allValid = false;
            }
            $vals[$i] = $v;
        }
        $sum = array_sum($vals);
        if (!$allValid || $sum <= 0) {
            return array_fill(0, $colCount, round(100 / $colCount, 2));
        }
        return array_map(static fn($v) => round($v / $sum * 100, 2), $vals);
    }

    private static function renderCellContent(array $cell): string
    {
        $widget = $cell['widget'] ?? null;
        if (is_array($widget) && isset($widget['_type'])) {
            if ($widget['_type'] === 'checkbox') {
                return self::renderCellCheckbox($widget);
            }
            $val = (string)($widget['value'] ?? '');
            return match ($widget['_type']) {
                'select'    => $val !== '' ? self::esc($val) : '<u>&nbsp;&nbsp;&nbsp;</u>',
                'textField' => $val !== '' ? self::esc($val) : '<u>&nbsp;&nbsp;&nbsp;</u>',
                default     => self::renderInlineText((string)($cell['text'] ?? '')),
            };
        }
        return self::renderInlineText((string)($cell['text'] ?? ''));
    }

    /** Cella checkbox: rispetta `renderMode` del widget (come il gruppo standalone):
     *   - "all" (default)  → ☑/☐ per ogni opzione, INCOLONNATO (opz. N colonne)
     *   - "checked-only"   → solo le spuntate, in elenco
     *   - "checked-inline" → solo le spuntate, a flusso (virgole). */
    private static function renderCellCheckbox(array $widget): string
    {
        $options = is_array($widget['options'] ?? null) ? $widget['options'] : [];
        $value = $widget['value'] ?? [];
        $checked = is_array($value) ? array_map('strval', $value) : ($value !== '' ? [(string)$value] : []);
        $mode = (string)($widget['renderMode'] ?? 'all');
        if (count($options) === 0) {
            return count($checked) === 0 ? '' : self::esc(implode(', ', $checked));
        }
        $isChk = static fn(array $o): bool => in_array((string)($o['value'] ?? $o['label'] ?? ''), $checked, true);
        if ($mode === 'checked-inline') {
            $lbls = [];
            foreach ($options as $o) {
                if (is_array($o) && $isChk($o)) {
                    $lbls[] = self::esc((string)($o['label'] ?? $o['value'] ?? ''));
                }
            }
            return '<span class="fm-pt-cb-inline">' . implode(', ', $lbls) . '</span>';
        }
        $onlyChecked = ($mode === 'checked-only');
        $nCols = max(1, min(5, (int)($widget['columns'] ?? 1)));
        $colStyle = $nCols >= 2 ? ' style="column-count:' . $nCols . '"' : '';
        $rows = [];
        $lastGroup = null;
        foreach ($options as $o) {
            if (!is_array($o)) {
                continue;
            }
            if ($onlyChecked && !$isChk($o)) {
                continue;
            }
            $lbl = (string)($o['label'] ?? $o['value'] ?? '');
            $grp = (string)($o['group'] ?? '');
            if ($grp !== '' && $grp !== $lastGroup) {
                $rows[] = '<div class="fm-pt-cb-group-head">' . self::esc($grp) . '</div>';
                $lastGroup = $grp;
            }
            $sym = $onlyChecked ? '•' : ($isChk($o) ? '☑' : '☐');
            $rows[] = '<label class="fm-pt-cb-item"><span class="fm-pt-cb-state">' . $sym . '</span> ' . self::esc($lbl) . '</label>';
        }
        return '<div class="fm-pt-checkbox-group"' . $colStyle . '>' . implode('', $rows) . '</div>';
    }

    /**
     * Testo cella con i formattatori inline (strong/em/u/code prodotti dalla
     * toolbar B/I/U/code): escapa tutto, poi ripristina SOLO i 4 tag consentiti
     * (nessun attributo) → niente XSS, formattazione visibile.
     */
    public static function renderInlineText(string $text): string
    {
        $safe = self::esc($text);
        return preg_replace('#&lt;(/?)(strong|em|u|code)&gt;#', '<$1$2>', $safe) ?? $safe;
    }

    private static function renderSelect(array $b): string
    {
        $value = (string)($b['value'] ?? '');
        $label = (string)($b['label'] ?? '');
        if ($value === '' && $label === '' && (string)($b['name'] ?? '') === '') {
            return '';
        }
        $val = $value === '' ? '<u>&nbsp;&nbsp;&nbsp;</u>' : '<u>' . self::esc($value) . '</u>';
        return '<span class="fm-pt-select-rendered">'
            . ($label !== '' ? '<strong>' . self::esc($label) . ':</strong> ' : '')
            . $val . '</span>';
    }

    private static function renderTextField(array $b): string
    {
        $value = (string)($b['value'] ?? '');
        $label = (string)($b['label'] ?? '');
        if ($value === '' && $label === '' && (string)($b['name'] ?? '') === '') {
            return '';
        }
        $val = $value === '' ? '<u>&nbsp;&nbsp;&nbsp;</u>' : self::esc($value);
        return '<span class="fm-pt-textfield-rendered">'
            . ($label !== '' ? '<strong>' . self::esc($label) . ':</strong> ' : '')
            . $val . '</span>';
    }

    private static function renderFormCheckbox(array $b): string
    {
        $sym = !empty($b['checked']) ? '☑' : '☐';
        return '<label class="fm-pt-form-checkbox-rendered">' . $sym . ' '
             . self::esc((string)($b['label'] ?? '')) . '</label>';
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
