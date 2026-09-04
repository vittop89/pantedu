<?php

namespace App\Services;

use App\Support\SafePath;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * DSA checkbox / attribute manipulation on verifiche & esercizi HTML files.
 * Replaces legacy app/Legacy/update/update_dsa_checkbox.php (iter-3 U3).
 */
final class DsaService
{
    /** @var list<string> */
    private array $roots;
    private string $basePath;

    public function __construct(FileService $files, ?string $basePath = null)
    {
        $all = $files->roots();
        $this->roots = array_values(array_intersect_key(
            $all,
            array_flip(['eser', 'verifiche', 'risdoc', 'drafts', 'lab', 'strcomp', 'didattica', 'mappe']),
        ));
        $this->basePath = rtrim(str_replace('\\', '/', $basePath ?? dirname(__DIR__, 2)), '/');
    }

    /**
     * Toggles the DSA checkbox state on a single <li> inside a problem.
     *
     * @return array{problemID:string, olIndex:int, liIndex:int, checked:string}
     */
    public function toggleCheckbox(string $filePath, string $problemID, int $olIndex, int $liIndex, string $checked): array
    {
        if ($problemID === '') {
            throw new RuntimeException('problemID_missing');
        }
        if ($olIndex < 0) {
            throw new RuntimeException('invalid_ol_index');
        }
        if ($liIndex < 0) {
            throw new RuntimeException('invalid_li_index');
        }

        $abs = $this->resolveFile($filePath);
        $html = @file_get_contents($abs);
        if ($html === false) {
            throw new RuntimeException('cannot_read_file');
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $cleanId = preg_replace('/_add\d+$/', '', $problemID) ?? $problemID;
        $problems = $xpath->query("//div[contains(@class, 'fm-groupcollex')][@id='$cleanId']");
        if ($problems === false || $problems->length === 0) {
            throw new RuntimeException('problem_not_found');
        }
        $problem = $problems->item(0);

        $ols = $xpath->query('.//ol', $problem);
        if ($ols === false || $olIndex >= $ols->length) {
            throw new RuntimeException('ol_index_out_of_range');
        }
        $ol = $ols->item($olIndex);

        $lis = $xpath->query(".//li[not(contains(@class, 'fm-li-inline'))]", $ol);
        if ($lis === false || $liIndex >= $lis->length) {
            throw new RuntimeException('li_index_out_of_range');
        }
        $li = $lis->item($liIndex);

        $containers = $xpath->query(".//span[contains(@class, 'dsa-checkbox-container')]", $li);
        $container  = $containers !== false && $containers->length > 0 ? $containers->item(0) : null;

        if ($checked === '1') {
            if ($container === null) {
                $container = $dom->createElement('span');
                $container->setAttribute('class', 'dsa-checkbox-container');
                $input = $dom->createElement('input');
                $input->setAttribute('type', 'checkbox');
                $input->setAttribute('class', 'dsa-checkbox');
                $input->setAttribute('checked', 'checked');
                $container->appendChild($input);
                if ($li->firstChild) {
                    $li->insertBefore($container, $li->firstChild);
                } else {
                    $li->appendChild($container);
                }
            } else {
                $cb = $xpath->query(".//input[@type='checkbox']", $container);
                if ($cb !== false && $cb->length > 0) {
                    $cb->item(0)->setAttribute('checked', 'checked');
                }
            }
            $this->ensureAddTextDsa($dom, $li, $container);
        } else {
            if ($container !== null) {
                $cb = $xpath->query(".//input[@type='checkbox']", $container);
                if ($cb !== false && $cb->length > 0) {
                    $cb->item(0)->removeAttribute('checked');
                }
                $this->removeAddTextDsa($container);
            }
        }

        $newHtml = (string) $dom->saveHTML();
        $newHtml = preg_replace('/^<\?xml encoding="UTF-8"\?>\s*/', '', $newHtml) ?? $newHtml;

        if (file_put_contents($abs, $newHtml, LOCK_EX) === false) {
            throw new RuntimeException('cannot_write_file');
        }
        return [
            'problemID' => $cleanId,
            'olIndex'   => $olIndex,
            'liIndex'   => $liIndex,
            'checked'   => $checked,
        ];
    }

    /**
     * Sets/removes AddTextDSA inline marker (F / GF / empty) on a specific
     * .fm-collection__item inside a problem. Empty marker removes the span.
     *
     * @return array{problemID:string, collexItemIndex:int, marker:string}
     */
    public function updateInlineMarker(string $filePath, string $problemID, int $collexItemIndex, string $marker): array
    {
        if ($problemID === '') {
            throw new RuntimeException('problemID_missing');
        }
        if ($collexItemIndex < 0) {
            throw new RuntimeException('invalid_collex_item_index');
        }
        if (!in_array($marker, ['', 'F', 'GF'], true)) {
            throw new RuntimeException('invalid_marker');
        }

        $abs = $this->resolveFile($filePath);
        $html = @file_get_contents($abs);
        if ($html === false) {
            throw new RuntimeException('cannot_read_file');
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $cleanId = preg_replace('/_add\d+$/', '', $problemID) ?? $problemID;
        $problem = $dom->getElementById($cleanId);
        if (!$problem) {
            throw new RuntimeException('problem_not_found');
        }

        $collexItems = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " collex-item ")]', $problem);
        if ($collexItems === false || $collexItemIndex >= $collexItems->length) {
            throw new RuntimeException('collex_item_out_of_range');
        }
        $item = $collexItems->item($collexItemIndex);

        $liInlines = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " li-inline ")]', $item);
        if ($liInlines === false || $liInlines->length === 0) {
            throw new RuntimeException('no_li_inline');
        }
        $liInline = $liInlines->item(0);

        $collexes = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " collex ")]', $liInline);
        if ($collexes === false || $collexes->length === 0) {
            throw new RuntimeException('no_collex');
        }
        $collex = $collexes->item(0);

        $targetDiv = $this->findOrCreateTargetDiv($dom, $xpath, $collex);
        $this->absorbLooseTextNodes($collex, $targetDiv);
        $this->removeAddTextDsaChildren($targetDiv);

        if ($marker !== '') {
            $span = $dom->createElement('span');
            $span->setAttribute('class', 'AddTextDSA has-checkbox');
            $span->setAttribute('style', 'display: inline;');
            $span->appendChild($dom->createTextNode("(*{$marker}*) "));
            $first = $this->firstSignificantChild($targetDiv);
            if ($first) {
                $targetDiv->insertBefore($span, $first);
            } else {
                $targetDiv->appendChild($span);
            }
        }

        $out = (string) $dom->saveHTML();
        if (file_put_contents($abs, $out, LOCK_EX) === false) {
            throw new RuntimeException('cannot_write_file');
        }
        return ['problemID' => $cleanId, 'collexItemIndex' => $collexItemIndex, 'marker' => $marker];
    }

    private function findOrCreateTargetDiv(DOMDocument $dom, DOMXPath $xpath, \DOMNode $collex): DOMElement
    {
        $divs = $xpath->query('./div', $collex);
        if ($divs !== false) {
            foreach ($divs as $d) {
                if (trim($d->textContent) !== '') {
                    return $d;
                }
            }
            if ($divs->length > 0) {
                return $divs->item(0);
            }
        }
        $new = $dom->createElement('div');
        while ($collex->firstChild) {
            $new->appendChild($collex->firstChild);
        }
        $collex->appendChild($new);
        return $new;
    }

    private function absorbLooseTextNodes(\DOMNode $collex, DOMElement $target): void
    {
        $children = [];
        foreach ($collex->childNodes as $c) {
            $children[] = $c;
        }
        foreach ($children as $c) {
            if ($c === $target) {
                continue;
            }
            if ($c->nodeType === XML_TEXT_NODE && trim($c->textContent) !== '') {
                $target->appendChild($c);
            }
        }
    }

    private function removeAddTextDsaChildren(DOMElement $parent): void
    {
        $children = [];
        foreach ($parent->childNodes as $c) {
            $children[] = $c;
        }
        foreach ($children as $c) {
            if (
                $c->nodeType === XML_ELEMENT_NODE
                && $c->nodeName === 'span'
                && str_contains((string)$c->getAttribute('class'), 'AddTextDSA')
            ) {
                $parent->removeChild($c);
            }
        }
    }

    private function firstSignificantChild(DOMElement $el): ?\DOMNode
    {
        foreach ($el->childNodes as $c) {
            if ($c->nodeType === XML_ELEMENT_NODE) {
                return $c;
            }
            if ($c->nodeType === XML_TEXT_NODE && trim($c->textContent) !== '') {
                return $c;
            }
        }
        return null;
    }

    private function ensureAddTextDsa(DOMDocument $dom, DOMElement $li, DOMElement $container): void
    {
        $next = $this->nextElementSibling($container);
        if ($next instanceof DOMElement && str_contains((string)$next->getAttribute('class'), 'AddTextDSA')) {
            return;
        }
        $span = $dom->createElement('span');
        $span->setAttribute('class', 'AddTextDSA');
        $span->textContent = '(*F*) ';
        if ($container->nextSibling) {
            $li->insertBefore($span, $container->nextSibling);
        } else {
            $container->parentNode?->appendChild($span);
        }
    }

    private function removeAddTextDsa(DOMElement $container): void
    {
        $next = $this->nextElementSibling($container);
        if ($next instanceof DOMElement && str_contains((string)$next->getAttribute('class'), 'AddTextDSA')) {
            $next->parentNode?->removeChild($next);
        }
    }

    private function nextElementSibling(DOMElement $node): ?\DOMNode
    {
        $n = $node->nextSibling;
        while ($n && $n->nodeType === XML_TEXT_NODE && trim($n->textContent) === '') {
            $n = $n->nextSibling;
        }
        return $n && $n->nodeType === XML_ELEMENT_NODE ? $n : null;
    }

    private function resolveFile(string $filePath): string
    {
        $rel = ltrim(str_replace('\\', '/', $filePath), '/');
        if ($rel === '' || str_contains($rel, '..')) {
            throw new RuntimeException('invalid_file_path');
        }
        $abs = $this->basePath . '/' . $rel;
        return SafePath::resolve($abs, $this->roots, mustExist: true);
    }
}
