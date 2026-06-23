<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use DOMElement;
use RuntimeException;

/**
 * Phase 15 — parser PHP content legacy → JSON contract moderno.
 *
 * Input: contenuto di un file `.php` scheletro esercizio/verifica come
 * quelli in `eser/` e `verifiche/php/`, che contiene:
 *   <body>
 *     <div id="fm-upbar"></div>
 *     <div id="header_page">...</div>
 *     <div class="fm-pagestyle">
 *       <div class="fm-titolo"><h1>TITLE</h1></div>
 *       <div class="fm-draggable-container">
 *         <div class="fm-groupcollex" id="...">
 *           <button class="fm-collapsible">Group</button>
 *           <div class="content">
 *             <div class="fm-scrollbarhide">
 *               <div class="fm-testo"><div>intro</div></div>
 *               <ol class="fm-collexercise">
 *                 <div class="fm-collection__item {source} diff{N}" id="...">
 *                   <div class="fm-titolo-quesito" style="background-color:X">LABEL</div>
 *                   <li class="fm-li-inline">
 *                     <div class="fm-collection">QUESTION</div>
 *                     <div class="fm-sol">SOLUTION</div>
 *                   </li>
 *                 </div>
 *                 ...
 *               </ol>
 *             </div>
 *           </div>
 *         </div>
 *       </div>
 *     </div>
 *   </body>
 *
 * Output: struttura JSON (array PHP) pronto per `json_encode`, schema
 * `pantedu.content.v1`. HTML tags rimossi; estratti LaTeX + TikZ +
 * testo narrativo + metadata (difficulty, source, tags).
 *
 * Idempotente: stessa input → stesso output. Parsing tollerante: se un
 * elemento manca, lo salta.
 */
final class PhpContentParser
{
    public function __construct(
        private readonly array $scope = [],   // teacher_id, institute_id, subject, indirizzo, classe, topic_num
    ) {
    }

    public function parse(string $html, string $sourceHref = ''): array
    {
        $doc = $this->loadHtml($html);
        $xp  = new DOMXPath($doc);

        $title = $this->extractTitle($xp);
        $meta  = $this->extractMeta($xp);

        $groups = [];
        foreach ($xp->query('//div[contains(@class,"fm-groupcollex")]') as $problem) {
            /** @var DOMElement $problem */
            $groups[] = $this->parseGroup($xp, $problem);
        }

        return [
            '$schema'       => 'pantedu.content.v1',
            'id'            => $this->deriveId($sourceHref, $title),
            'kind'          => $this->scope['kind'] ?? 'esercizio',
            'title'         => $title,
            'scope'         => array_filter($this->scope, fn($v) => $v !== null && $v !== ''),
            'source'        => 'legacy_php',
            'original_href' => $sourceHref,
            'groups'        => $groups,
            'meta'          => $meta,
            'generated_at'  => date('c'),
        ];
    }

    // ───────────── internals ─────────────

    private function loadHtml(string $html): DOMDocument
    {
        // Rimuovi PHP blocks (es. include_once). Uso concatenazione per
        // evitare che il parser PHP interpreti il pattern come open tag.
        $phpOpen = '<' . '?php';
        $phpClose = '?' . '>';
        $html = preg_replace('/' . preg_quote($phpOpen, '/') . '[\s\S]*?' . preg_quote($phpClose, '/') . '/i', '', $html) ?? $html;
        $doc  = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<' . '?xml encoding="UTF-8"?' . '>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $doc;
    }

    private function extractTitle(DOMXPath $xp): string
    {
        $n = $xp->query('//div[contains(@class,"fm-pagestyle")]//div[contains(@class,"fm-titolo")]//h1')->item(0);
        return $n ? trim($n->textContent) : '';
    }

    private function extractMeta(DOMXPath $xp): array
    {
        $meta = [];
        // Fonte delle citazioni (header_page)
        $headerText = '';
        $hp = $xp->query('//div[@id="header_page"]')->item(0);
        if ($hp) {
            $headerText = trim($hp->textContent);
        }
        if ($headerText !== '') {
            $meta['header_note'] = preg_replace('/\s+/', ' ', $headerText);
            // Estrai "Fonte delle citazioni" se presente
            if (preg_match('/Fonte delle citazioni:\s*(.+?)(?:$|\n)/u', $headerText, $m)) {
                $meta['source_citation'] = trim($m[1]);
            }
        }
        return $meta;
    }

    private function parseGroup(DOMXPath $xp, DOMElement $problem): array
    {
        $id    = $problem->getAttribute('id') ?: null;
        $title = '';
        $btn   = $xp->query('.//button[contains(@class,"fm-collapsible")]', $problem)->item(0);
        if ($btn) {
            $title = trim($btn->textContent);
        }

        $intro = '';
        $introNode = $xp->query('.//div[contains(@class,"fm-testo")]', $problem)->item(0);
        if ($introNode) {
            $intro = $this->cleanText($introNode->textContent);
        }

        // Phase 15 — rileva tipo dal pattern id: type_VF, type_RMulti,
        // type_Collect. Default: Collect.
        $groupKind = 'Collect';
        if ($id && preg_match('/type_(VF|RMulti|RM|Collect)/i', $id, $mm)) {
            $t = strtoupper($mm[1]);
            $groupKind = match ($t) {
                'VF' => 'VF', 'RMULTI','RM' => 'RM', default => 'Collect'
            };
        }

        $items = [];
        foreach ($xp->query('.//div[contains(@class,"fm-collection__item")]', $problem) as $cxItem) {
            $items[] = $this->parseItem($xp, $cxItem, $groupKind);
        }

        return [
            'kind'  => 'problem-group',
            'type'  => $groupKind,   // VF | RM | Collect
            'id'    => $id,
            'title' => $title,
            'intro' => $intro,
            'items' => $items,
        ];
    }

    private function parseItem(DOMXPath $xp, DOMElement $it, string $groupKind = 'Collect'): array
    {
        $id       = $it->getAttribute('id') ?: null;
        $classes  = preg_split('/\s+/', (string)$it->getAttribute('class')) ?: [];
        $diff     = 0;
        $source   = null;
        $tags     = [];
        foreach ($classes as $c) {
            if (str_starts_with($c, 'diff') && ctype_digit(substr($c, 4))) {
                $diff = (int)substr($c, 4);
            } elseif ($c === 'fm-collection__item') {
                continue;
            } elseif ($c !== '') {
                if ($source === null) {
                    $source = $c;
                } else {
                    $tags[] = $c;
                }
            }
        }

        // category label + color
        $categoryLabel = '';
        $categoryColor = null;
        $catNode = $xp->query('.//div[contains(@class,"fm-titolo-quesito")]', $it)->item(0);
        if ($catNode) {
            $categoryLabel = trim($catNode->textContent);
            $style = (string)$catNode->getAttribute('style');
            if (preg_match('/background-color:\s*([^;]+)/i', $style, $m)) {
                $categoryColor = trim($m[1]);
            }
        }

        // Question container: .fm-collection o .collexTab (RM usa collexTab)
        $questionNode = $xp->query('.//div[contains(concat(" ", normalize-space(@class), " "), " collex ") or contains(concat(" ", normalize-space(@class), " "), " collexTab ")][not(ancestor::*[contains(@class,"tabelle")])]', $it)->item(0);

        // VF: wrapsolVF contiene .fm-sol V|F + .fm-giustsol
        // RM: table.tabelle contiene options, .fm-giustsol contiene spiegazione
        // Collect: .fm-sol è la soluzione
        $base = [
            'id'             => $id,
            'source'         => $source,
            'difficulty'     => $diff,
            'tags'           => $tags,
            'category_label' => $categoryLabel,
            'category_color' => $categoryColor,
            'question'       => $questionNode ? $this->parseBlocks($questionNode) : [],
        ];

        // Badge estratto dalla prima espressione LaTeX della question
        $badge = $this->extractBadge($base['question']);
        if ($badge) {
            $base['badge']    = $badge;
            // Rimuovi il primo blocco latex (era il badge) dalla question
            $base['question'] = $this->stripLeadingBadge($base['question']);
            // Difficulty da badge override (più affidabile di class diffN)
            if (isset($badge['difficulty'])) {
                $base['difficulty'] = $badge['difficulty'];
            }
        }

        if ($groupKind === 'VF') {
            $base = array_merge($base, $this->parseVfExtras($xp, $it));
        } elseif ($groupKind === 'RM') {
            $base = array_merge($base, $this->parseRmExtras($xp, $it));
        } else {
            $solutionNode = $xp->query('.//div[contains(@class,"fm-sol") and not(contains(@class,"fm-giustsol")) and not(contains(@class,"solchecked")) and not(contains(@class,"collexerc"))]', $it)->item(0);
            $base['solution'] = $solutionNode ? $this->parseBlocks($solutionNode) : [];
        }

        return $base;
    }

    /**
     * Badge struct:
     *   source_key (derivata dal testo array interno, es. mmb_v2_ed3 da
     *     "Matematica multimediale.blu - Vol.2 Ed.3"),
     *   page, ex_num, difficulty (1-4 da count \bullet / \circ), bg_color.
     *
     * @param list<array> $questionBlocks
     * @return array|null
     */
    private function extractBadge(array $questionBlocks): ?array
    {
        if (!$questionBlocks) {
            return null;
        }
        $first = $questionBlocks[0];
        if (($first['type'] ?? '') !== 'latex') {
            return null;
        }
        $tex = (string)($first['content'] ?? '');
        if (!str_contains($tex, '\\begin{array}') || !str_contains($tex, 'P-')) {
            return null;
        }

        $badge = [];
        if (preg_match('/\\\\small\{\\\\text\{([^}]+)\}\}/', $tex, $m)) {
            $badge['book'] = trim(preg_replace('/\s+/', ' ', $m[1]));
        }
        // Tutti i \tiny{\text{...}} in ordine → [0]=volume, [1]=authors
        if (preg_match_all('/\\\\tiny\{\\\\text\{([^}]+)\}\}/', $tex, $m)) {
            if (isset($m[1][0])) {
                $badge['volume']  = trim(preg_replace('/\s+/', ' ', $m[1][0]));
            }
            if (isset($m[1][1])) {
                $badge['authors'] = trim(preg_replace('/\s+/', ' ', $m[1][1]));
            }
        }
        if (preg_match('/P-\}(\d+)/', $tex, $m)) {
            $badge['page'] = $m[1];
        }
        if (preg_match('/background:\s*(\w+)/i', $tex, $m)) {
            $badge['bg_color'] = strtolower($m[1]);
        }
        if (preg_match('/\\\\large\s*\*?([^}*]+)\*?\}/', $tex, $m)) {
            $badge['ex_num'] = trim($m[1]);
        }
        // Difficulty: conta \bullet e \circ subito dopo \huge.
        // Uso regex più robusto: dopo \huge, cattura sequenza di \bullet/\circ
        // (con eventuali spazi) fino al primo char non matching.
        if (preg_match('/\\\\huge\s*((?:\\\\bullet|\\\\circ|\s)+)/', $tex, $m)) {
            $seq = $m[1];
            $filled = substr_count($seq, '\\bullet');
            $empty  = substr_count($seq, '\\circ');
            $badge['difficulty'] = $filled;
            $badge['difficulty_max'] = $filled + $empty;
        }
        // source_key: canonicalizza book+volume in slug stabile
        if (!empty($badge['book']) && !empty($badge['volume'])) {
            $k = strtolower($badge['book'] . ' ' . $badge['volume']);
            $k = preg_replace('/[^a-z0-9]+/', '_', $k) ?? $k;
            $k = trim($k, '_');
            $badge['source_key'] = $k;
        }
        return $badge ?: null;
    }

    /**
     * Rimuove il badge dal primo blocco latex, preservando il resto.
     *
     * Il badge termina tipicamente con `...{\large NNN}}}}\quad`.
     * Il contenuto didattico può seguire (testo narrativo o altra LaTeX
     * matematica) e andare fino a `\)`.
     *
     * Usa un matcher che individua l'ultimo `\quad` subito dopo il numero
     * esercizio (\large NNN) e le chiusure di graffe, poi preserva tutto
     * ciò che segue come nuovo blocco latex (rewrapped in \( ... \) se
     * necessario).
     */
    private function stripLeadingBadge(array $blocks): array
    {
        if (!$blocks || ($blocks[0]['type'] ?? '') !== 'latex') {
            return $blocks;
        }
        $content = (string)($blocks[0]['content'] ?? '');
        if (!str_contains($content, '\\begin{array}') || !str_contains($content, 'P-')) {
            return $blocks;
        }

        // Cerca fine badge: \large NNN }...} \quad (pattern stabile)
        if (
            !preg_match(
                '/\\\\large\s*[^}]+\}\s*\}+\s*\}*\s*(\\\\quad)/s',
                $content,
                $m,
                PREG_OFFSET_CAPTURE
            )
        ) {
            array_shift($blocks);
            return $blocks;
        }
        $endBadge = $m[1][1] + 6;   // offset dopo "\quad"
        $rest = trim(substr($content, $endBadge));

        // Se il resto è vuoto o solo la chiusura \), rimuovi blocco
        if ($rest === '' || $rest === '\\)' || $rest === ')') {
            array_shift($blocks);
            return $blocks;
        }

        // Preserva come latex wrappato: aggiunge \( se mancante e \) se mancante.
        if (!str_starts_with($rest, '\\(') && !str_starts_with($rest, '\\[')) {
            $rest = '\\(' . $rest;
        }
        if (!str_ends_with($rest, '\\)') && !str_ends_with($rest, '\\]')) {
            $rest .= '\\)';
        }
        $blocks[0] = ['type' => 'latex', 'content' => $rest];
        return $blocks;
    }

    /** VF: estrae answer V|F + giustificazione. */
    private function parseVfExtras(DOMXPath $xp, DOMElement $it): array
    {
        $answer = null;
        $solDiv = $xp->query('.//div[contains(@class,"fm-sol") and (contains(@class," V") or contains(@class," F") or @class="fm-sol V" or @class="fm-sol F")]', $it)->item(0);
        if (!$solDiv) {
            // Fallback: cerca "sol V" o "sol F" esattamente
            foreach ($xp->query('.//div[contains(@class,"fm-sol")]', $it) as $n) {
                $cls = (string)$n->getAttribute('class');
                if (preg_match('/\bsol\s+V\b/', $cls)) {
                    $answer = 'V';
                    break;
                }
                if (preg_match('/\bsol\s+F\b/', $cls)) {
                    $answer = 'F';
                    break;
                }
            }
        } else {
            $cls = (string)$solDiv->getAttribute('class');
            $answer = preg_match('/\bsol\s+V\b/', $cls) ? 'V' : 'F';
        }
        $justifyNode = $xp->query('.//div[contains(@class,"fm-giustsol")]', $it)->item(0);
        return [
            'answer'        => $answer,
            'justification' => $justifyNode ? $this->parseBlocks($justifyNode) : [],
        ];
    }

    /** RM: estrae opzioni da table con checkboxRM.solchecked = corretta. */
    /** Phase 16 — parse RM options da 2 formati legacy:
     *
     *  FORMATO A (admin-created, nuovo):
     *    <td><input class="fm-checkbox-rm [solchecked]"><label class="fm-collection">...</label></td>
     *
     *  FORMATO B (legacy master, tableRMulti-6):
     *    <td class="fm-collection"><div>a. \(\text{V}\,\square\quad \text{F}\,\square\quad\) content</div></td>
     *
     *  Per formato B: il V/F corretto è determinato dalla giustsol che contiene
     *  pattern "aV:" / "bF:" / etc → lettera ∈ {V,F}. Match by letter tra
     *  tabella (a,b,c,d) e giustsol per impostare correct.
     */
    private function parseRmExtras(DOMXPath $xp, DOMElement $it): array
    {
        $justifyNode = $xp->query('.//div[contains(@class,"fm-giustsol")]', $it)->item(0);
        $justBlocks  = $justifyNode ? $this->parseBlocks($justifyNode) : [];

        // Costruisci mappa letter → answer (V|F) dalla giustsol
        $answerMap = $this->extractAnswerMapFromJustification($justBlocks);

        $options = [];
        foreach ($xp->query('.//table//td', $it) as $td) {
            /** @var DOMElement $td */

            // Prova formato A (input + label)
            $input = $xp->query('.//input[contains(@class,"checkboxRM")]', $td)->item(0);
            if ($input) {
                $correct = str_contains((string)$input->getAttribute('class'), 'solchecked')
                        || $input->hasAttribute('checked');
                $labelNode = $xp->query('.//label[contains(@class,"fm-collection")]', $td)->item(0);
                $options[] = [
                    'correct' => $correct,
                    'content' => $labelNode ? $this->parseBlocks($labelNode) : [],
                ];
                continue;
            }

            // Formato B: td.fm-collection con "a. V☐ F☐" prefix
            if (!str_contains((string)$td->getAttribute('class'), 'fm-collection')) {
                continue;
            }
            $raw = trim($td->textContent);
            // Pattern: "a." o "a)" o "a " all'inizio + letter
            if (!preg_match('/^([a-z])[.)\s]/iu', $raw, $lm)) {
                continue;
            }
            $letter = strtolower($lm[1]);
            $answer = $answerMap[$letter] ?? null; // V|F|null
            $correct = $answer === 'V';

            // Parse blocks → poi elimina il prefisso "a." e il blocco V☐F☐ dal content
            $contentBlocks = $this->parseBlocks($td);
            $contentBlocks = $this->cleanRmOptionContent($contentBlocks, $letter);

            $options[] = [
                'letter'  => $letter,
                'answer'  => $answer ?? '',
                'correct' => $correct,
                'content' => $contentBlocks,
            ];
        }
        return [
            'options'       => $options,
            'justification' => $justBlocks,
        ];
    }

    /** Estrae da giustsol una mappa letter → V|F.
     *  Supporta 2 pattern legacy:
     *    A) testo flat "aV: ..." / "bF: ..." / "a.V:" / "a)F:" ...
     *    B) lista HTML `<ol>` con `<li>V. ...</li>`, `<li>F. ...</li>` →
     *       letter derivata dalla posizione (a=1°, b=2°, c=3°, ...).
     */
    private function extractAnswerMapFromJustification(array $blocks): array
    {
        $map = [];

        // Pattern B: lista ordinata → letter implicita da posizione
        foreach ($blocks as $b) {
            if (($b['type'] ?? '') !== 'list') {
                continue;
            }
            $items = $b['items'] ?? [];
            foreach ($items as $idx => $item) {
                // item è lista di blocks (parseBlocks output); concatena i text
                $liBuf = '';
                foreach ($item as $sub) {
                    $t = $sub['type'] ?? '';
                    if ($t === 'text' || $t === 'latex') {
                        $liBuf .= ($sub['content'] ?? '') . ' ';
                    }
                }
                if (preg_match('/^\s*(V|F)[.)\-]\s/iu', $liBuf, $lm)) {
                    $letter = \chr(97 + $idx);
                    $map[$letter] = strtoupper($lm[1]);
                }
            }
        }

        // Pattern A: testo flat con "aV:", "bF:", ecc
        $buf = '';
        foreach ($blocks as $b) {
            $t = $b['type'] ?? '';
            if ($t === 'text' || $t === 'latex') {
                $buf .= ($b['content'] ?? '') . ' ';
            }
        }
        if (preg_match_all('/(?:^|\s)([a-z])[.)\-]?\s*(V|F)\s*:/isu', $buf, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $letter = strtolower($m[1]);
                // Preferisci pattern A se esplicito (fallback B se già presente)
                $map[$letter] = strtoupper($m[2]);
            }
        }
        return $map;
    }

    /** Rimuove dal primo blocco text il prefisso "a." / "b)" / ... e
     *  il pattern LaTeX `\(\text{V}\,\square\quad \text{F}\,\square\quad\)`
     *  (o varianti con solo V o solo F) per isolare il contenuto puro. */
    private function cleanRmOptionContent(array $blocks, string $letter): array
    {
        if (!$blocks) {
            return $blocks;
        }
        $first = &$blocks[0];
        if (($first['type'] ?? '') !== 'text') {
            return $blocks;
        }
        $c = (string)($first['content'] ?? '');
        // Rimuovi prefix "a." o "a)" o "a-" opzionalmente seguito da spazi
        $c = preg_replace('/^' . preg_quote($letter, '/') . '[.)\-]?\s*/iu', '', $c) ?? $c;
        $first['content'] = trim($c);
        // Rimuovi prossimo blocco latex se contiene solo V/F checkboxes
        if (isset($blocks[1]) && ($blocks[1]['type'] ?? '') === 'latex') {
            $lx = (string)($blocks[1]['content'] ?? '');
            if (
                preg_match('/\\\\text\{(?:V|F)\}.*\\\\square/u', $lx)
                && !preg_match('/[A-Za-z]{2,}/', preg_replace('/\\\\[a-zA-Z]+|\\\\text\{[VF]\}|\\\\square|\\\\quad|\\\\,|\\s|\(|\)/', '', $lx))
            ) {
                array_splice($blocks, 1, 1);
            }
        }
        // Se il primo text è diventato vuoto, rimuovilo
        if ($first['content'] === '') {
            array_shift($blocks);
        }
        return $blocks;
    }

    /**
     * Estrae blocchi strutturati da un nodo (es. .fm-collection o .fm-sol).
     *
     * Strategia deep-recursive:
     *   1. Estrae TikZ scripts dal sub-tree
     *   2. Cerca tutti i <ol>/<ul> dovunque nested → block list
     *   3. I nodi tra/intorno alle liste vengono serializzati in testo
     *      flat e splittati in text/latex via delimitatori \(..\) \[..\]
     *
     * Mantiene l'ordine DOM originale tra liste e testo.
     */
    private function parseBlocks(DOMElement $root): array
    {
        $clone = $root->cloneNode(true);

        // 0. Rimuovi label visivi `.fm-sol-label` ("SOLUZIONE"/"GIUSTIFICAZIONE"):
        //    sono header del renderer, non content editabile/parseable.
        $xpath = new \DOMXPath($clone->ownerDocument);
        foreach ($xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " fm-sol-label ")]', $clone) as $lbl) {
            $lbl->parentNode?->removeChild($lbl);
        }

        // 1. TikZ → block separato (rimossi dal clone)
        $tikzBlocks = [];
        foreach ($this->findTikzScripts($clone) as $ts) {
            $tikzBlocks[] = [
                'type'         => 'tikz',
                'script'       => $this->cleanTikz((string)$ts->textContent),
                'tex_packages' => (string)$ts->getAttribute('data-tex-packages') ?: null,
                'tikz_libs'    => (string)$ts->getAttribute('data-tikz-libraries') ?: null,
            ];
            $ts->parentNode?->removeChild($ts);
        }

        // 2. Deep-recursive collection: interleave text/latex/list/tikz
        //    in ordine DOM. Al primo livello mi bastano i direct children;
        //    per ogni child, ricorro a recursiveCollect.
        $blocks = [];
        // TikZ prima (coerente con behavior originale: appare ad inizio sol)
        foreach ($tikzBlocks as $b) {
            $blocks[] = $b;
        }

        $textBuf = '';
        $flushText = function () use (&$textBuf, &$blocks): void {
            if ($textBuf === '') {
                return;
            }
            foreach ($this->splitLatexRegions($textBuf) as $p) {
                $c = $this->cleanText($p['content']);
                if ($c !== '') {
                    $blocks[] = ['type' => $p['type'], 'content' => $c];
                }
            }
            $textBuf = '';
        };

        $walk = function (\DOMNode $node) use (&$walk, &$textBuf, &$blocks, $flushText): void {
            foreach ($node->childNodes as $child) {
                if ($child->nodeType === XML_TEXT_NODE) {
                    $textBuf .= (string)$child->nodeValue;
                    continue;
                }
                if ($child->nodeType !== XML_ELEMENT_NODE) {
                    continue;
                }
                /** @var DOMElement $child */
                $tag = strtolower($child->tagName);
                if (\in_array($tag, ['ol','ul'], true)) {
                    $flushText();
                    $blocks[] = $this->parseList($child);
                    continue;
                }
                if (\in_array($tag, ['br','hr'], true)) {
                    $textBuf .= "\n";
                    continue;
                }
                $isBlock = \in_array($tag, ['div','p','li','section','article','header','footer','h1','h2','h3','h4'], true);
                if ($isBlock) {
                    $textBuf .= "\n";
                }
                $walk($child);
                if ($isBlock) {
                    $textBuf .= "\n";
                }
            }
        };
        $walk($clone);
        $flushText();

        return $this->mergeAdjacentText($blocks);
    }

    /**
     * Parsa un <ol>/<ul> in block type=list con items strutturati.
     * Supporta nesting (ol > li > ol > li). Ogni item è una lista di
     * blocchi (text/latex/tikz/list) ottenuti da parseBlocks sul <li>.
     */
    private function parseList(DOMElement $list): array
    {
        $ordered = strtolower($list->tagName) === 'ol';
        $style   = (string)$list->getAttribute('type') ?: null;   // es. "A","1","i"
        $start   = (string)$list->getAttribute('start') ?: null;
        $items   = [];
        foreach ($list->childNodes as $li) {
            if ($li->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            if (strtolower($li->tagName) !== 'li') {
                continue;
            }
            /** @var DOMElement $li */
            $items[] = $this->parseBlocks($li);
        }
        return [
            'type'       => 'list',
            'ordered'    => $ordered,
            'list_style' => $style,
            'start'      => $start !== null ? (int)$start : null,
            'items'      => $items,
        ];
    }

    /** Converte DOM in testo flat: <br>/<div>/<p>/<li> → \n, resto concatenato. */
    private function serializeFlat(\DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $c) {
            if ($c->nodeType === XML_TEXT_NODE) {
                $out .= (string)$c->nodeValue;
                continue;
            }
            if ($c->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            /** @var DOMElement $c */
            $tag = strtolower($c->tagName);
            if (\in_array($tag, ['br', 'hr'], true)) {
                $out .= "\n";
                continue;
            }
            $isBlock = \in_array($tag, ['div','p','li','ol','ul','section','article','header','footer','h1','h2','h3','h4'], true);
            if ($isBlock) {
                $out .= "\n";
            }
            $out .= $this->serializeFlat($c);
            if ($isBlock) {
                $out .= "\n";
            }
        }
        return $out;
    }

    /**
     * Divide il testo flat in regioni alternate text/latex basandosi
     * su delimitatori `\(...\)` (inline) e `\[...\]` (display).
     * I match possono attraversare newline.
     *
     * @return list<array{type:string,content:string}>
     */
    private function splitLatexRegions(string $text): array
    {
        $out = [];
        // Regex: \(...\) o \[...\] non-greedy; supporta newline.
        $pattern = '/(\\\\\(.*?\\\\\))|(\\\\\[.*?\\\\\])/s';
        $lastPos = 0;
        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $m) {
                [$expr, $off] = $m;
                if ($off > $lastPos) {
                    $before = substr($text, $lastPos, $off - $lastPos);
                    if (trim($before) !== '') {
                        $out[] = ['type' => 'text', 'content' => $before];
                    }
                }
                $out[] = ['type' => 'latex', 'content' => $expr];
                $lastPos = $off + strlen($expr);
            }
        }
        if ($lastPos < strlen($text)) {
            $tail = substr($text, $lastPos);
            if (trim($tail) !== '') {
                $out[] = ['type' => 'text', 'content' => $tail];
            }
        }
        if (!$out && trim($text) !== '') {
            $out[] = ['type' => 'text', 'content' => $text];
        }
        return $out;
    }

    /** @return DOMElement[] */
    private function findTikzScripts(\DOMNode $root): array
    {
        $out = [];
        if (
            $root instanceof DOMElement
            && strtolower($root->tagName) === 'script'
            && strtolower((string)$root->getAttribute('type')) === 'text/tikz'
        ) {
            $out[] = $root;
            return $out;
        }
        foreach ($root->childNodes as $c) {
            if ($c->nodeType === XML_ELEMENT_NODE) {
                $out = array_merge($out, $this->findTikzScripts($c));
            }
        }
        return $out;
    }

    private function detectType(string $s): string
    {
        // LaTeX: inline \( ... \) oppure display \[ ... \] oppure $...$
        if (preg_match('/\\\\\(|\\\\\[|\\\\begin\{/', $s)) {
            return 'latex';
        }
        return 'text';
    }

    /** Collassa blocchi text adiacenti in uno unico. */
    private function mergeAdjacentText(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $b) {
            if ($out && $b['type'] === 'text' && end($out)['type'] === 'text') {
                $out[count($out) - 1]['content'] .= ' ' . $b['content'];
            } else {
                $out[] = $b;
            }
        }
        return $out;
    }

    private function cleanText(string $s): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = preg_replace('/\xC2\xA0/', ' ', $s) ?? $s; // nbsp → space
        $s = preg_replace('/[ \t]+/', ' ', $s) ?? $s;
        $s = preg_replace('/\n{3,}/', "\n\n", $s) ?? $s;
        return trim($s);
    }

    private function cleanTikz(string $s): string
    {
        // <br> → \n per ripristinare script originale leggibile
        $s = preg_replace('/<br\s*\/?>/i', "\n", $s) ?? $s;
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        // Collassa righe vuote consecutive
        $s = preg_replace('/\n{3,}/', "\n\n", $s) ?? $s;
        return trim($s);
    }

    private function deriveId(string $href, string $title): string
    {
        $base = pathinfo($href, PATHINFO_FILENAME) ?: preg_replace('/[^a-zA-Z0-9]+/', '_', $title);
        return (string)$base;
    }
}
