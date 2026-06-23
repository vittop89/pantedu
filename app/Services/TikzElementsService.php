<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use RuntimeException;

/**
 * Builds the two per-group JSON indices used by the TikZ picker UI:
 *   storage/data/modelli_tikz_elements.json  (tex-group → elements with type)
 *   storage/data/modelli_tikz_traccia.json   (flat list of element-traccia)
 *
 * Replaces legacy app/Legacy/tikz/generate_tikz_json.php (iter-3 U1).
 */
final class TikzElementsService
{
    private const SOURCE_REL   = 'views/admin/templates/modelli_tikz.php';
    private const ELEMENTS_REL = 'storage/data/modelli_tikz_elements.json';
    private const TRACCIA_REL  = 'storage/data/modelli_tikz_traccia.json';

    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = rtrim(str_replace('\\', '/', $basePath ?? dirname(__DIR__, 2)), '/');
    }

    /**
     * Append a new tex-group element (tikz|latex) to modelli_tikz.php.
     * Auto-regenerates the JSON indices.
     *
     * @return array{group:string, label:string, created_group:bool, regen:array}
     */
    public function createElement(string $groupName, string $existingGroup, string $elementType, string $label, string $code): array
    {
        $label = trim($label);
        if ($label === '') {
            throw new RuntimeException('label_missing');
        }
        if ($code === '') {
            throw new RuntimeException('code_missing');
        }
        if (!in_array($elementType, ['tikz', 'latex'], true)) {
            throw new RuntimeException('invalid_element_type');
        }
        $target = trim($groupName) !== '' ? trim($groupName) : trim($existingGroup);
        if ($target === '') {
            throw new RuntimeException('group_missing');
        }
        $target = $this->normalizeGroupName($target);

        $srcAbs  = $this->basePath . '/' . self::SOURCE_REL;
        $content = @file_get_contents($srcAbs);
        if ($content === false) {
            throw new RuntimeException('cannot_read_modelli');
        }

        $newElement = $this->buildElementHtml($elementType, $label, $code);

        $groupPattern = '/<div class="tex-group" data-group="' . preg_quote($target, '/') . '">/';
        $groupExists  = (bool) preg_match($groupPattern, $content);
        $createdGroup = false;

        if ($groupExists) {
            $content = $this->insertIntoExistingGroup($content, $target, $newElement, $label);
        } else {
            $newGroup = '<div class="tex-group" data-group="' . $target . '">' . "\n"
                . '    <button class="fm-group-btn"></button>' . "\n"
                . '    <div class="fm-group-options">' . "\n"
                . $newElement . '    </div>' . "\n"
                . '</div>' . "\n";
            $content = rtrim($content) . "\n" . $newGroup;
            $createdGroup = true;
        }

        if (file_put_contents($srcAbs, $content, LOCK_EX) === false) {
            throw new RuntimeException('cannot_save_modelli');
        }

        $regen = $this->generateAll(self::SOURCE_REL, self::ELEMENTS_REL, self::TRACCIA_REL);
        return ['group' => $target, 'label' => $label, 'created_group' => $createdGroup, 'regen' => $regen];
    }

    /**
     * Delete an element by label, or the whole group, from modelli_tikz.php.
     * Auto-regenerates JSON indices.
     *
     * @return array{group:string, deletedLabel:string, groupRemoved:bool, regen:array}
     */
    public function deleteElement(string $groupName, string $elementLabel, bool $deleteWholeGroup): array
    {
        if ($groupName === '') {
            throw new RuntimeException('group_missing');
        }

        $srcAbs  = $this->basePath . '/' . self::SOURCE_REL;
        $content = @file_get_contents($srcAbs);
        if ($content === false) {
            throw new RuntimeException('cannot_read_modelli');
        }

        $groupMarker = '<div class="tex-group" data-group="' . $groupName . '">';
        $groupStart  = strpos($content, $groupMarker);
        if ($groupStart === false) {
            throw new RuntimeException('group_not_found');
        }

        $groupRemoved = false;
        if ($deleteWholeGroup) {
            $content = $this->stripGroupFrom($content, $groupStart, $groupMarker);
            $groupRemoved = true;
        } else {
            if ($elementLabel === '') {
                throw new RuntimeException('element_label_missing');
            }
            [$content, $groupRemoved] = $this->deleteByLabel($content, $groupStart, $groupMarker, $elementLabel);
        }

        if (file_put_contents($srcAbs, $content, LOCK_EX) === false) {
            throw new RuntimeException('cannot_save_modelli');
        }
        $regen = $this->generateAll(self::SOURCE_REL, self::ELEMENTS_REL, self::TRACCIA_REL);
        return ['group' => $groupName, 'deletedLabel' => $elementLabel, 'groupRemoved' => $groupRemoved, 'regen' => $regen];
    }

    /**
     * Edit an element at $elementIndex in $groupName. Optionally rename the
     * group (newGroupName) or move the element to another group (moveToGroup).
     * Auto-regenerates JSON indices.
     *
     * @return array{group:string, originalGroup:string, renamed:bool, moved:bool, label:string, regen:array}
     */
    public function editElement(
        string $groupName,
        int $elementIndex,
        string $newGroupName,
        string $moveToGroup,
        string $elementType,
        string $label,
        string $code,
        string $elementLabel = '',  // Phase 16: fallback lookup by label
    ): array {
        if ($groupName === '') {
            throw new RuntimeException('group_missing');
        }
        if ($label === '') {
            throw new RuntimeException('label_missing');
        }
        if ($code === '') {
            throw new RuntimeException('code_missing');
        }
        if (!in_array($elementType, ['tikz', 'latex'], true)) {
            throw new RuntimeException('invalid_element_type');
        }
        // elementIndex negative è OK se elementLabel è passato (lookup inside).

        $srcAbs = $this->basePath . '/' . self::SOURCE_REL;
        $fh = @fopen($srcAbs, 'r+');
        if ($fh === false) {
            throw new RuntimeException('cannot_open_modelli');
        }
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            throw new RuntimeException('cannot_lock_modelli');
        }
        try {
            $size = filesize($srcAbs);
            $content = $size > 0 ? (string) fread($fh, $size) : '';

            $groupStart = strpos($content, '<div class="tex-group" data-group="' . $groupName . '">');
            if ($groupStart === false) {
                throw new RuntimeException('group_not_found');
            }

            $optMarker = '<div class="fm-group-options">';
            $optStart  = strpos($content, $optMarker, $groupStart);
            if ($optStart === false) {
                throw new RuntimeException('group_options_not_found');
            }
            $optEnd = $this->findMatchingDivClose($content, $optStart + strlen($optMarker));
            if ($optEnd === -1) {
                throw new RuntimeException('group_options_close_not_found');
            }

            $optContent = substr($content, $optStart + strlen($optMarker), $optEnd - ($optStart + strlen($optMarker)));
            $groupEndPos = strpos($content, '</div>', $optEnd + 6);
            if ($groupEndPos === false) {
                throw new RuntimeException('group_close_not_found');
            }
            $groupContent = substr($content, $groupStart, $groupEndPos + 6 - $groupStart);

            $elements = $this->extractElementBlocks($optContent);

            // Phase 16 — se elementLabel passato, risolvi index cercando nella
            // DOM source order (evita mismatch JSON-sorted vs source).
            if ($elementLabel !== '') {
                $resolvedIdx = -1;
                foreach ($elements as $i => $el) {
                    if (
                        preg_match('/<div class="label_(?:tikz|latex)">([^<]+)<\/div>/', $el, $m)
                        && trim($m[1]) === $elementLabel
                    ) {
                        $resolvedIdx = $i;
                        break;
                    }
                }
                if ($resolvedIdx === -1) {
                    throw new RuntimeException('element_label_not_found');
                }
                $elementIndex = $resolvedIdx;
            }
            if ($elementIndex < 0) {
                throw new RuntimeException('invalid_element_index');
            }
            if (!isset($elements[$elementIndex])) {
                throw new RuntimeException('element_index_out_of_range');
            }

            $newElement = $this->buildEditedElementHtml($elementType, $label, $code);

            $before = '';
            for ($i = 0; $i < $elementIndex; $i++) {
                $before .= $elements[$i];
            }
            $after = '';
            for ($i = $elementIndex + 1; $i < count($elements); $i++) {
                $after .= $elements[$i];
            }

            $newOptContent   = $before . $newElement . $after;
            $newGroupContent = str_replace($optContent, $newOptContent, $groupContent);
            $newContent      = str_replace($groupContent, $newGroupContent, $content);

            $finalGroup = $groupName;
            $renamed = false;
            $moved   = false;

            if ($newGroupName !== '') {
                $norm = preg_replace('/\s+/', ' ', $newGroupName) ?? $newGroupName;
                $norm = str_replace(' ', '-', $norm);
                $finalGroup = 'gruppo-' . $norm;
                $newContent = str_replace(
                    'data-group="' . $groupName . '"',
                    'data-group="' . $finalGroup . '"',
                    $newContent,
                );
                $renamed = true;
            } elseif ($moveToGroup !== '') {
                $newOptContentNoEl   = str_replace($newElement, '', $newOptContent);
                $newGroupContentNoEl = str_replace($optContent, $newOptContentNoEl, $groupContent);
                $newContent = str_replace($groupContent, $newGroupContentNoEl, $content);

                $targetStart = strpos($newContent, '<div class="tex-group" data-group="' . $moveToGroup . '">');
                if ($targetStart === false) {
                    throw new RuntimeException('target_group_not_found');
                }
                $targetOpt = strpos($newContent, '<div class="fm-group-options">', $targetStart);
                if ($targetOpt === false) {
                    throw new RuntimeException('target_group_options_not_found');
                }
                $insertAt = $targetOpt + strlen('<div class="fm-group-options">');
                $newContent = substr_replace($newContent, $newElement, $insertAt, 0);

                $finalGroup = $moveToGroup;
                $moved = true;
            }

            ftruncate($fh, 0);
            rewind($fh);
            if (fwrite($fh, $newContent) === false) {
                throw new RuntimeException('cannot_save_modelli');
            }
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }

        $regen = $this->generateAll(self::SOURCE_REL, self::ELEMENTS_REL, self::TRACCIA_REL);
        return [
            'group'         => $finalGroup,
            'originalGroup' => $groupName,
            'renamed'       => $renamed,
            'moved'         => $moved,
            'label'         => $label,
            'regen'         => $regen,
        ];
    }

    private function buildEditedElementHtml(string $type, string $label, string $code): string
    {
        $labelClass = $type === 'tikz' ? 'label_tikz' : 'label_latex';
        if ($type === 'tikz') {
            return "\n"
                . '        <div class="element-tex">' . "\n"
                . '            <div class="' . $labelClass . '">' . htmlspecialchars($label) . '</div>' . "\n"
                . '            <script type="text/tikz" data-show-console="true">' . "\n"
                . $code . "\n"
                . '</script>' . "\n"
                . '        </div>';
        }
        return "\n"
            . '        <div class="element-tex">' . "\n"
            . '            <div class="' . $labelClass . '">' . htmlspecialchars($label) . '</div>' . "\n"
            . '            <div class="latex"><pre>' . "\n"
            . htmlspecialchars($code) . "\n"
            . '</pre></div>' . "\n"
            . '        </div>';
    }

    private function stripGroupFrom(string $content, int $groupStart, string $groupMarker): string
    {
        $groupEnd = $this->findMatchingDivClose($content, $groupStart + strlen($groupMarker));
        if ($groupEnd === -1) {
            throw new RuntimeException('group_close_not_found');
        }
        $afterAbs = $groupEnd + 6;
        $before = substr($content, 0, $groupStart);
        $after  = preg_replace('/^\s*\n/', '', substr($content, $afterAbs)) ?? '';
        return $before . $after;
    }

    /** @return array{0:string,1:bool} [updatedContent, groupRemoved] */
    private function deleteByLabel(string $content, int $groupStart, string $groupMarker, string $elementLabel): array
    {
        $optMarker   = '<div class="fm-group-options">';
        $optStart    = strpos($content, $optMarker, $groupStart);
        if ($optStart === false) {
            throw new RuntimeException('group_options_not_found');
        }
        $optEnd = $this->findMatchingDivClose($content, $optStart + strlen($optMarker));
        if ($optEnd === -1) {
            throw new RuntimeException('group_options_close_not_found');
        }

        $optContent = substr($content, $optStart + strlen($optMarker), $optEnd - ($optStart + strlen($optMarker)));
        $elements   = $this->extractElementBlocks($optContent);

        $target = mb_strtolower(trim($elementLabel), 'UTF-8');
        $found  = -1;
        foreach ($elements as $idx => $el) {
            if (preg_match('/<div class="(?:label_tikz|label_latex)">(.*?)<\/div>/s', $el, $m)) {
                $cand = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (mb_strtolower($cand, 'UTF-8') === $target) {
                    $found = $idx;
                    break;
                }
            }
        }
        if ($found === -1) {
            throw new RuntimeException('element_not_found_in_group');
        }

        array_splice($elements, $found, 1);
        $newOptContent = implode('', $elements);

        if (trim($newOptContent) === '') {
            $content = $this->stripGroupFrom($content, $groupStart, $groupMarker);
            return [$content, true];
        }
        $replaceStart = $optStart + strlen($optMarker);
        $replaceLen   = $optEnd - $replaceStart;
        return [substr_replace($content, $newOptContent, $replaceStart, $replaceLen), false];
    }

    /** @return list<string> */
    private function extractElementBlocks(string $optContent): array
    {
        $out = [];
        $offset = 0;
        $marker = '<div class="element-tex">';
        $mlen   = strlen($marker);
        while (($start = strpos($optContent, $marker, $offset)) !== false) {
            $pos = $start + $mlen;
            $depth = 1;
            $olen = strlen($optContent);
            while ($depth > 0 && $pos < $olen) {
                $nextOpen  = strpos($optContent, '<div', $pos);
                $nextClose = strpos($optContent, '</div>', $pos);
                if ($nextClose === false) {
                    break;
                }
                if ($nextOpen !== false && $nextOpen < $nextClose) {
                    $depth++;
                    $pos = $nextOpen + 4;
                } else {
                    $depth--;
                    $pos = $nextClose + 6;
                }
            }
            if ($depth !== 0) {
                break;
            }
            $out[] = substr($optContent, $start, $pos - $start);
            $offset = $pos;
        }
        return $out;
    }

    private function normalizeGroupName(string $name): string
    {
        if (str_starts_with($name, 'gruppo-')) {
            return $name;
        }
        $norm = preg_replace('/\s+/', ' ', $name) ?? $name;
        $norm = strtolower(trim($norm));
        $norm = str_replace(' ', '-', $norm);
        return 'gruppo-' . $norm;
    }

    private function buildElementHtml(string $type, string $label, string $code): string
    {
        $labelClass = $type === 'latex' ? 'label_latex' : 'label_tikz';
        if ($type === 'latex') {
            return '        <div class="element-tex">' . "\n"
                . '            <div class="' . $labelClass . '">' . htmlspecialchars($label) . '</div>' . "\n"
                . '            <div class="latex">' . "\n"
                . '                <pre>' . htmlspecialchars($code) . '</pre>' . "\n"
                . '            </div>' . "\n"
                . '        </div>' . "\n";
        }
        return '        <div class="element-tex">' . "\n"
            . '            <div class="' . $labelClass . '">' . htmlspecialchars($label) . '</div>' . "\n"
            . '            <script type="text/tikz" data-show-console="true">' . "\n" . $code . "\n" . '</script>' . "\n"
            . '        </div>' . "\n";
    }

    private function insertIntoExistingGroup(string $content, string $target, string $newElement, string $label): string
    {
        $groupMarker = '<div class="tex-group" data-group="' . $target . '">';
        $groupStart  = strpos($content, $groupMarker);
        if ($groupStart === false) {
            throw new RuntimeException('group_not_found');
        }

        $fromGroup   = substr($content, $groupStart);
        $optMarker   = '<div class="fm-group-options">';
        $optStart    = strpos($fromGroup, $optMarker);
        if ($optStart === false) {
            throw new RuntimeException('group_options_not_found');
        }

        $optEnd = $this->findMatchingDivClose($fromGroup, $optStart + strlen($optMarker));
        if ($optEnd === -1) {
            throw new RuntimeException('group_options_close_not_found');
        }

        $optContent = substr($fromGroup, $optStart + strlen($optMarker), $optEnd - ($optStart + strlen($optMarker)));
        if (preg_match_all('/<div class="label_(?:tikz|latex)">(.*?)<\/div>/s', $optContent, $matches)) {
            foreach ($matches[1] as $existingRaw) {
                $existing = trim(html_entity_decode(strip_tags($existingRaw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (mb_strtolower($existing, 'UTF-8') === mb_strtolower($label, 'UTF-8')) {
                    throw new RuntimeException('duplicate_label_in_group');
                }
            }
        }

        $texGroupClose = strpos($fromGroup, '</div>', $optEnd + 6);
        if ($texGroupClose === false) {
            throw new RuntimeException('tex_group_close_not_found');
        }

        $beforeOpt   = substr($fromGroup, 0, $optEnd);
        $afterGroup  = substr($fromGroup, $texGroupClose + 6);
        $beforeGroup = substr($content, 0, $groupStart);
        $updated     = $beforeOpt . "\n" . $newElement . "    </div>\n</div>";
        return $beforeGroup . $updated . $afterGroup;
    }

    /** Returns the offset of the closing </div> matching the div opened just before $start. */
    private function findMatchingDivClose(string $haystack, int $start): int
    {
        $pos = $start;
        $depth = 1;
        $len = strlen($haystack);
        while ($pos < $len && $depth > 0) {
            $nextOpen  = strpos($haystack, '<div', $pos);
            $nextClose = strpos($haystack, '</div>', $pos);
            if ($nextClose === false) {
                return -1;
            }
            if ($nextOpen !== false && $nextOpen < $nextClose) {
                $depth++;
                $pos = $nextOpen + 4;
            } else {
                $depth--;
                if ($depth === 0) {
                    return $nextClose;
                }
                $pos = $nextClose + 6;
            }
        }
        return -1;
    }

    /**
     * @return array{groups:int, tracce:int, elements_size:int, traccia_size:int}
     */
    public function generateAll(string $sourcePath, string $elementsTarget, string $tracciaTarget): array
    {
        $src  = $this->resolveModelliSource($sourcePath);
        $eOut = $this->resolveModelliTarget($elementsTarget);
        $tOut = $this->resolveModelliTarget($tracciaTarget);

        $content = @file_get_contents($src);
        if ($content === false) {
            throw new RuntimeException('cannot_read_modelli');
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $encoded = mb_encode_numericentity($content, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
        $dom->loadHTML($encoded, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $elements = $this->extractGroups($xpath);
        $tracce   = $this->extractTracce($dom, $xpath);

        $elementsJson = json_encode($elements, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $tracciaJson  = json_encode(['tracce' => $tracce], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (file_put_contents($eOut, $elementsJson, LOCK_EX) === false) {
            throw new RuntimeException('elements_write_failed');
        }
        if (file_put_contents($tOut, $tracciaJson, LOCK_EX) === false) {
            throw new RuntimeException('traccia_write_failed');
        }

        return [
            'groups'        => count($elements),
            'tracce'        => count($tracce),
            'elements_size' => strlen($elementsJson),
            'traccia_size'  => strlen($tracciaJson),
        ];
    }

    /** @return array<string, list<array{label:string,content:string,type:string}>> */
    private function extractGroups(DOMXPath $xpath): array
    {
        $out = [];
        foreach ($xpath->query("//div[@class='tex-group']") as $group) {
            $name = $group->getAttribute('data-group');
            if ($name === '') {
                continue;
            }

            $items = [];
            foreach ($xpath->query(".//div[@class='element-tex']", $group) as $el) {
                $labelNodes = $xpath->query(".//div[@class='label_tikz' or @class='label_latex']", $el);
                $label = $labelNodes->length > 0 ? trim($labelNodes->item(0)->textContent) : 'Senza titolo';

                $scripts = $xpath->query(".//script[@type='text/tikz']", $el);
                if ($scripts->length > 0) {
                    $items[] = ['label' => $label, 'content' => trim($scripts->item(0)->textContent), 'type' => 'tikz'];
                    continue;
                }
                $latexDivs = $xpath->query(".//div[@class='latex']", $el);
                $latex = $latexDivs->length > 0 ? trim($latexDivs->item(0)->textContent) : '';
                $items[] = ['label' => $label, 'content' => $latex, 'type' => 'latex'];
            }
            usort($items, static fn($a, $b) => strcasecmp($a['label'], $b['label']));
            $out[$name] = $items;
        }
        ksort($out);
        return $out;
    }

    /** @return list<array{label:string,content:string}> */
    private function extractTracce(DOMDocument $dom, DOMXPath $xpath): array
    {
        $out = [];
        foreach ($xpath->query("//div[@class='element-traccia']") as $el) {
            $labelNodes = $xpath->query(".//div[@class='label_latex']", $el);
            $label = $labelNodes->length > 0 ? trim($labelNodes->item(0)->textContent) : 'Senza titolo';

            $content = '';
            $latexDivs = $xpath->query(".//div[@class='latex']", $el);
            if ($latexDivs->length > 0) {
                foreach ($latexDivs->item(0)->childNodes as $child) {
                    $content .= $dom->saveHTML($child);
                }
                $content = trim($content);
                $content = preg_replace('/^\s*<pre[^>]*>\s*/i', '', $content);
                $content = preg_replace('/\s*<\/pre>\s*$/i', '', (string)$content);
                $content = trim((string)$content);
            }
            $out[] = ['label' => $label, 'content' => $content];
        }
        usort($out, static fn($a, $b) => strcasecmp($a['label'], $b['label']));
        return $out;
    }

    private function resolveModelliSource(string $relative): string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || str_contains($relative, '..')) {
            throw new RuntimeException('invalid_source_path');
        }
        // Phase 16 — accetta path legacy (modelli_tikz.php root), legacy/ archive,
        // e la path attuale views/admin/templates/modelli_tikz.php (usata da SOURCE_REL).
        $allowed = '#^(modelli_tikz\.php|views/legacy/modelli_tikz\.php|views/admin/templates/modelli_tikz\.php)$#';
        if (!preg_match($allowed, $relative)) {
            throw new RuntimeException('source_not_allowed');
        }
        $abs = $this->basePath . '/' . $relative;
        if (!is_file($abs)) {
            throw new RuntimeException('source_not_found');
        }
        return $abs;
    }

    private function resolveModelliTarget(string $relative): string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || str_contains($relative, '..')) {
            throw new RuntimeException('invalid_target_path');
        }
        if (!preg_match('#^storage/data/modelli_tikz[A-Za-z0-9_]*\.json$#', $relative)) {
            throw new RuntimeException('target_not_allowed');
        }
        return $this->basePath . '/' . $relative;
    }
}
