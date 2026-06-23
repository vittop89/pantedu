<?php

declare(strict_types=1);

/**
 * G23 page-doc — Seed extractor da repo fismapant master branch.
 *
 * One-shot script CLI per popolare `schemas/risdoc/_pt/seeds/*.pt.json` dai
 * file PHP/HTML legacy in `storage/templates/strcomp/ALTRO/` di fismapant.
 *
 * Mapping HTML → PT block types:
 *   <table>thead+tbody/tr/td   → glossaryTable
 *   <h2.section-title>          → staticContent (level=2)
 *   <ul.link-list> con <a PDF>  → linkListPdf
 *   <a href="...DPR/DM/L_...">  → citationNorma (extracted from inline links)
 *   <details>/<summary> nested  → accordion
 *
 * Usage:
 *   php tools/scripts/extract-seed-from-fismapant.php \
 *       --repo=C:/Users/vitto/progetti_vscode/fismapant \
 *       --out=schemas/risdoc/_pt/seeds \
 *       --only=glossario   (or "all" for all 4 templates)
 *
 * POC (G23 Sprint 1): supporta solo `glossario`. Altri template
 * (legislazione, verifiche-e-recuperi, cosa-sono-strumenti-compensativi)
 * delegati a sprint successivi (richiedono static-content/accordion/link-list
 * implementati).
 */

const SOURCES = [
    'glossario' => [
        'src'    => 'storage/templates/strcomp/ALTRO/0.1_SBA-Glossario-ALTRO.php',
        'block'  => 'glossaryTable',
        'output' => 'glossario.pt.json',
    ],
    'legislazione' => [
        'src'    => 'storage/templates/strcomp/ALTRO/0.0_SBA-Legislazione-ALTRO.php',
        'block'  => 'linkListPdf',
        'output' => 'legislazione.pt.json',
    ],
    'verifiche-e-recuperi' => [
        'src'    => 'storage/templates/strcomp/ALTRO/1.0_SBA-Verifiche_e_Recuperi-ALTRO.php',
        'block'  => 'staticContent+accordion',
        'output' => 'verifiche-e-recuperi.pt.json',
    ],
    'cosa-sono-strumenti-compensativi' => [
        'src'    => 'storage/templates/strcomp/STRCOMP/0.0_SBA-Cosa_sono-STRCOMP.php',
        'block'  => 'staticContent+accordion',
        'output' => 'cosa-sono-strumenti-compensativi.pt.json',
    ],
];

function main(array $argv): int
{
    $opts = parseArgs($argv);
    $repo = $opts['repo'] ?? null;
    $out  = $opts['out']  ?? null;
    $only = $opts['only'] ?? 'glossario';

    if ($repo === null || $out === null) {
        fwrite(STDERR, "Usage: php extract-seed-from-fismapant.php --repo=PATH --out=PATH [--only=glossario|all]\n");
        return 1;
    }
    if (!is_dir($repo)) {
        fwrite(STDERR, "ERR: repo path not found: {$repo}\n");
        return 1;
    }
    if (!is_dir($out)) {
        @mkdir($out, 0755, true);
    }
    if (!is_dir($out) || !is_writable($out)) {
        fwrite(STDERR, "ERR: output dir not writable: {$out}\n");
        return 1;
    }

    $report = [];
    $sources = $only === 'all' ? array_keys(SOURCES) : [$only];

    foreach ($sources as $name) {
        if (!isset(SOURCES[$name])) {
            fwrite(STDERR, "ERR: unknown source '{$name}'\n");
            continue;
        }
        $spec = SOURCES[$name];
        $srcPath = $repo . '/' . $spec['src'];
        if (!file_exists($srcPath)) {
            $report[$name] = ['status' => 'skip', 'reason' => 'source not found', 'path' => $srcPath];
            continue;
        }

        fwrite(STDOUT, "→ Extracting {$name} from {$srcPath}\n");
        $html = file_get_contents($srcPath);

        $blocks = match ($spec['block']) {
            'glossaryTable'         => extractGlossaryTable($html),
            'linkListPdf'           => extractLinkListPdf($html),
            'staticContent+accordion' => extractStaticContentAccordion($html),
            default                 => [],
        };

        $outFile = rtrim($out, '/\\') . '/' . $spec['output'];
        file_put_contents(
            $outFile,
            json_encode($blocks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        );
        $report[$name] = [
            'status'      => 'ok',
            'output'      => $outFile,
            'block_count' => count($blocks),
            'block_types' => array_unique(array_column($blocks, '_type')),
        ];
    }

    fwrite(STDOUT, "\n=== Extraction report ===\n");
    fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    return 0;
}

function parseArgs(array $argv): array
{
    $opts = [];
    foreach (array_slice($argv, 1) as $a) {
        if (!preg_match('/^--([a-z-]+)=(.+)$/', $a, $m)) continue;
        $opts[$m[1]] = $m[2];
    }
    return $opts;
}

/**
 * Estrae <table>thead+tbody dal file glossario → block glossaryTable PT.
 * Convention colonne: ["N.", "Lemma", "Definizione", "Fonte"].
 */
function extractGlossaryTable(string $html): array
{
    $dom = htmlToDom($html);
    $xpath = new DOMXPath($dom);

    // Cerca la prima <table>
    $tables = $xpath->query('//table');
    if ($tables->length === 0) {
        fwrite(STDERR, "  WARN: no <table> found in glossario\n");
        return [];
    }
    $table = $tables->item(0);

    // thead → columns
    $columns = [];
    foreach ($xpath->query('.//thead//th', $table) as $th) {
        $columns[] = trim(textContent($th));
    }
    if (count($columns) < 2) {
        // Fallback convention
        $columns = ['N.', 'Lemma', 'Definizione', 'Fonte'];
    }

    // Detect fismapant legacy format: 2 cols [Lemmi, Definizioni] con
    // "N. Lemma" fuso nella prima colonna e "...Fonte X" inline nella
    // definizione. Se rilevato, splittiamo in 4 cols normalizzate
    // [N., Lemma, Definizione, Fonte] per allineamento al glossaryTable
    // canonico.
    $legacyFormat = (count($columns) === 2
        && stripos($columns[0], 'lemm') !== false
        && stripos($columns[1], 'definiz') !== false);
    if ($legacyFormat) {
        $columns = ['N.', 'Lemma', 'Definizione', 'Fonte'];
    }

    // tbody → entries
    $entries = [];
    foreach ($xpath->query('.//tbody/tr', $table) as $i => $tr) {
        $cells = [];
        foreach ($xpath->query('.//td', $tr) as $td) {
            $cells[] = trim(textContent($td));
        }
        if (count($cells) === 0) continue;

        if ($legacyFormat && count($cells) >= 2) {
            // Split "N. Lemma" → n + lemma
            $col0 = $cells[0];
            $n    = $i + 1;
            $lemma = $col0;
            if (preg_match('/^\s*(\d+)\.?\s*(.+)$/u', $col0, $m)) {
                $n     = (int)$m[1];
                $lemma = trim($m[2]);
            }
            // Split "definizione...Fonte X" → definizione + fonte
            $col1 = $cells[1];
            $definizione = $col1;
            $fonte = '';
            // Pattern: split su " Fonte " (case-sensitive label fismapant);
            // fallback su "Fonte:" o ultimo "Racc. UE/COM/DM/L." inline.
            if (preg_match('/^(.+?)\s+Fonte[\s:]\s*(.+)$/su', $col1, $m)) {
                $definizione = trim($m[1]);
                $fonte       = trim($m[2]);
            } elseif (preg_match('/^(.+?)(?=\s+(?:All\.II|Raccomandazione|Comunicazione|Risoluzione|Allegato|Direttiva|DM\s+\d|DPR\s+\d|D\.Lgs|L\.\s*\d|CM\s+\d|COM\s*\(|\d{4}\/C))(.+)$/su', $col1, $m)) {
                $definizione = trim($m[1]);
                $fonte       = trim($m[2]);
            }
            $entries[] = [
                'n'           => $n,
                'lemma'       => $lemma,
                'definizione' => $definizione,
                'fonte'       => $fonte,
            ];
            continue;
        }

        // Default (canonical 4 cols, o N!=2 cols)
        $entry = [];
        foreach ($columns as $ci => $col) {
            $key = headerToKey($col);
            $val = $cells[$ci] ?? '';
            if ($key === 'n') {
                $entry[$key] = (int)preg_replace('/[^0-9]/', '', $val) ?: ($i + 1);
            } else {
                $entry[$key] = $val;
            }
        }
        $entries[] = $entry;
    }

    return [[
        '_type'      => 'glossaryTable',
        'name'       => 'glossario_lemmi',
        'columns'    => $columns,
        'entries'    => $entries,
        'sortable'   => true,
        'searchable' => true,
    ]];
}

/**
 * Estrae <ul.link-list> con <a> PDF dal file legislazione → block linkListPdf PT.
 * Gruppa per <h2.section-title> precedente come title.
 */
function extractLinkListPdf(string $html): array
{
    $dom = htmlToDom($html);
    $xpath = new DOMXPath($dom);

    $blocks = [];
    // Sezioni: ogni <h2.section-title> apre un linkListPdf, gli <ul> seguenti sono items.
    foreach ($xpath->query('//h2[contains(@class, "section-title")]') as $h2) {
        $title = trim(textContent($h2));
        $items = [];
        // Naviga i sibling fino al prossimo <h2>
        $node = $h2->nextSibling;
        while ($node !== null && !(isXmlElement($node, 'h2'))) {
            if (isXmlElement($node, 'ul')) {
                // ./li (NOT .//li) per evitare duplicazione: sub_items sono
                // estratti da parseLinkListItem dentro l'item parent.
                foreach ($xpath->query('./li', $node) as $li) {
                    $item = parseLinkListItem($li, $xpath);
                    if ($item !== null) $items[] = $item;
                }
            } elseif (isXmlElement($node, 'p')) {
                // Link inline in <p>
                foreach ($xpath->query('.//a', $node) as $a) {
                    $href = $a->getAttribute('href');
                    $label = trim(textContent($a));
                    if ($href && $label) {
                        $items[] = [
                            'label'    => $label,
                            'href'     => $href,
                            'external' => (bool)preg_match('#^https?://#i', $href),
                        ];
                    }
                }
            }
            $node = $node->nextSibling;
        }
        if (count($items) > 0) {
            $blocks[] = [
                '_type' => 'linkListPdf',
                'title' => $title,
                'items' => $items,
            ];
        }
    }
    return $blocks;
}

function parseLinkListItem(DOMElement $li, DOMXPath $xpath): ?array
{
    $a = $xpath->query('.//a', $li)->item(0);
    if (!$a) return null;
    $href = $a->getAttribute('href');
    $label = trim(textContent($a));
    if (!$href || !$label) return null;

    $item = [
        'label'    => $label,
        'href'     => $href,
        'external' => (bool)preg_match('#^https?://#i', $href),
    ];

    // Sub-items: <ul> diretto dentro <li> (./ul not .//ul per stessa
    // ragione di evitare ricorsione su list multi-level).
    $subUls = $xpath->query('./ul', $li);
    if ($subUls->length > 0) {
        $subItems = [];
        foreach ($xpath->query('./li', $subUls->item(0)) as $subLi) {
            $sub = parseLinkListItem($subLi, $xpath);
            if ($sub !== null) {
                unset($sub['sub_items']);
                $subItems[] = $sub;
            }
        }
        if (count($subItems) > 0) $item['sub_items'] = $subItems;
    }
    return $item;
}

/**
 * G23 Sprint 4 — Estrazione gerarchica intelligente.
 * Mapping:
 *   <h2> top-level             → accordion items (PARTE I/II/...)
 *     <h3> sub-sezione          → accordion item nested body (A/B/C)
 *       contenuto fino a h3/h2  → text content del item
 *
 * Output blocks ordine:
 *   1. accordion principale con items[] (PARTI)
 *      Ogni item:
 *        title = h2 text
 *        body_pt = [{ _type: "accordion", items: [...subsezioni h3...] }]
 *
 * Se non ci sono <h3> sotto un h2, il body_pt è singolo block testuale.
 * Se non ci sono <h2> proprio, fallback a staticContent monolitico.
 */
function extractStaticContentAccordion(string $html): array
{
    $dom = htmlToDom($html);
    $xpath = new DOMXPath($dom);
    // Container principale del documento
    $body = $xpath->query('//body/div[contains(@class, "page-wrapper")] | //body/div[contains(@class, "container")] | //body')->item(0);
    if (!$body) {
        return [];
    }
    $titleNode = $xpath->query('.//*[contains(@class, "main-title") or contains(@class, "page-title")]', $body)->item(0);
    $rootTitle = $titleNode ? trim(textContent($titleNode)) : '';

    // Find all <h2> (PARTI) e <h3> (sub-sezioni) in document order
    $h2s = iterator_to_array($xpath->query('.//h2', $body));
    if (count($h2s) === 0) {
        // Fallback: nessuna struttura h2 → monolitico staticContent
        $bodyHtml = serializeChildren($dom, $body);
        $bodyHtml = stripStyleScript($bodyHtml);
        return [[
            '_type'  => 'staticContent',
            'title'  => $rootTitle ?: 'Documento',
            'level'  => 2,
            'format' => 'html',
            'body'   => trim($bodyHtml),
        ]];
    }

    // Estrazione: combina tutti h2/h3 in document order via SortedHeadings.
    // PHP DOM non ha compareDocumentPosition, quindi precomputo un'indice
    // posizionale via single xpath query "//h2 | //h3" (preserva doc order).
    $allHeadings = [];
    foreach ($xpath->query('.//h2 | .//h3', $body) as $idx => $h) {
        $allHeadings[] = ['idx' => $idx, 'tag' => strtolower($h->tagName), 'node' => $h];
    }
    $h2Indices = [];
    foreach ($allHeadings as $i => $h) {
        if ($h['tag'] === 'h2') $h2Indices[] = $i;
    }

    // Pre-body content (PRIMA del primo h2): staticContent intro
    $intro = collectHtmlBefore($dom, $body, $h2s[0]);
    $intro = stripStyleScript($intro);
    $blocks = [];
    if (trim(strip_tags($intro)) !== '') {
        $blocks[] = [
            '_type'  => 'staticContent',
            'title'  => $rootTitle,
            'level'  => 2,
            'format' => 'html',
            'body'   => trim($intro),
        ];
    }

    // Per ogni h2, estrai sub-h3 e content (usa indici precomputati)
    $accordionItems = [];
    foreach ($h2s as $i => $h2) {
        $h2Title = trim(textContent($h2));
        $nextH2  = $h2s[$i + 1] ?? null;
        // Find h3 between this h2 and next h2 via document order index
        $h2HeadIdx = $h2Indices[$i];
        $nextH2HeadIdx = $h2Indices[$i + 1] ?? PHP_INT_MAX;
        $h3sInScope = [];
        for ($k = $h2HeadIdx + 1; $k < $nextH2HeadIdx && $k < count($allHeadings); $k++) {
            if ($allHeadings[$k]['tag'] === 'h3') {
                $h3sInScope[] = $allHeadings[$k]['node'];
            }
        }

        if (count($h3sInScope) === 0) {
            // No h3 → body è HTML tra h2 e next h2 (o fine)
            $body_html = collectHtmlBetween($dom, $h2, $nextH2);
            $body_html = stripStyleScript($body_html);
            $accordionItems[] = [
                'title'        => $h2Title,
                'body_pt'      => htmlToStaticContentBlock($body_html),
                'default_open' => $i === 0,
            ];
        } else {
            // Ha h3 sub-sezioni → nested accordion in body_pt
            $subItems = [];
            foreach ($h3sInScope as $j => $h3) {
                $h3Title = trim(textContent($h3));
                $nextH3 = $h3sInScope[$j + 1] ?? $nextH2;
                $sub_body = collectHtmlBetween($dom, $h3, $nextH3);
                $sub_body = stripStyleScript($sub_body);
                $subItems[] = [
                    'title'        => $h3Title,
                    'body_pt'      => htmlToStaticContentBlock($sub_body),
                    'default_open' => false,
                ];
            }
            // Intro del h2 (HTML tra h2 e primo h3)
            $h2Intro = collectHtmlBetween($dom, $h2, $h3sInScope[0]);
            $h2Intro = stripStyleScript($h2Intro);
            $bodyPt = [];
            if (trim(strip_tags($h2Intro)) !== '') {
                $bodyPt[] = [
                    '_type'  => 'staticContent',
                    'level'  => 3,
                    'format' => 'html',
                    'body'   => trim($h2Intro),
                ];
            }
            $bodyPt[] = [
                '_type'          => 'accordion',
                'allow_multiple' => true,
                'items'          => $subItems,
            ];
            $accordionItems[] = [
                'title'        => $h2Title,
                'body_pt'      => $bodyPt,
                'default_open' => $i === 0,
            ];
        }
    }

    $blocks[] = [
        '_type'          => 'accordion',
        'allow_multiple' => true,
        'items'          => $accordionItems,
    ];

    return $blocks;
}

// G23 Sprint 4 — Helpers per parsing gerarchico h2/h3.

/** Serializza tutti i children di un node a HTML string. */
function serializeChildren(DOMDocument $dom, DOMNode $node): string
{
    $html = '';
    foreach ($node->childNodes as $child) {
        $html .= $dom->saveHTML($child);
    }
    return $html;
}

/** HTML degli ancestor sibling che precedono $stopNode dentro $container. */
function collectHtmlBefore(DOMDocument $dom, DOMNode $container, DOMNode $stopNode): string
{
    $html = '';
    foreach ($container->childNodes as $child) {
        if ($child->isSameNode($stopNode) || nodeContains($child, $stopNode)) break;
        $html .= $dom->saveHTML($child);
    }
    return $html;
}

/** HTML tra $startNode (esclusivo) e $endNode (esclusivo, può essere null = fino a fine). */
function collectHtmlBetween(DOMDocument $dom, DOMNode $startNode, ?DOMNode $endNode): string
{
    $html = '';
    $current = $startNode->nextSibling;
    while ($current !== null) {
        if ($endNode !== null && ($current->isSameNode($endNode) || nodeContains($current, $endNode))) break;
        $html .= $dom->saveHTML($current);
        $current = $current->nextSibling;
    }
    return $html;
}

/** True se $container contains $maybeChild (ancestor walk). */
function nodeContains(DOMNode $container, DOMNode $maybeChild): bool
{
    $node = $maybeChild;
    while ($node !== null) {
        if ($node->isSameNode($container)) return true;
        $node = $node->parentNode;
    }
    return false;
}

/** Strip <style>/<script> tags da HTML string. */
function stripStyleScript(string $html): string
{
    $html = preg_replace('#<style[\s\S]*?</style>#i', '', $html);
    $html = preg_replace('#<script[\s\S]*?</script>#i', '', $html);
    return $html;
}

/**
 * Converte HTML body in body_pt array. Per ora: 1 staticContent block.
 * Future Sprint 5: split in multipli block (citationNorma per <a href=PDF>,
 * linkListPdf per <ul.link-list> rilevati, ecc.).
 */
function htmlToStaticContentBlock(string $html): array
{
    $trimmed = trim($html);
    if ($trimmed === '') return [];
    return [[
        '_type'  => 'staticContent',
        'level'  => 4,
        'format' => 'html',
        'body'   => $trimmed,
    ]];
}

function htmlToDom(string $html): DOMDocument
{
    $dom = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    // HTML5 entity safety
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    return $dom;
}

function isXmlElement($node, string $tag): bool
{
    return $node instanceof DOMElement && strtolower($node->tagName) === strtolower($tag);
}

function textContent($node): string
{
    if (!$node) return '';
    return preg_replace('/\s+/', ' ', (string)$node->textContent);
}

function headerToKey(string $header): string
{
    $h = mb_strtolower($header, 'UTF-8');
    $h = str_replace('.', '', $h);
    $h = strtr($h, [
        'à'=>'a','á'=>'a','â'=>'a','ä'=>'a',
        'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
        'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
        'ò'=>'o','ó'=>'o','ô'=>'o','ö'=>'o',
        'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u',
    ]);
    $h = preg_replace('/\s+/', '_', $h);
    $h = preg_replace('/[^a-z0-9_]/', '', $h);
    return trim($h, '_');
}

exit(main($argv));
