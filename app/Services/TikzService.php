<?php

namespace App\Services;

use App\Support\SafePath;
use DOMDocument;
use DOMXPath;
use RuntimeException;

/**
 * TikZ / LaTeX element storage. Replaces three legacy endpoints:
 *   - save_tikz_svg.php         → saveSvg()
 *   - get_tikz_content.php      → getContent()
 *   - ensure_tikz_json.php      → ensureJson()
 *
 * All filesystem work is funneled through SafePath + the FileService
 * roots whitelist (eser/, verifiche/, risdoc/, drafts/, and the
 * project root for modelli_tikz.php/json).
 */
final class TikzService
{
    /** @var list<string> svg allowed roots */
    private array $svgRoots;
    private string $basePath;

    public function __construct(FileService $files, ?string $basePath = null)
    {
        $all = $files->roots();
        $this->svgRoots = array_values(array_intersect_key(
            $all,
            array_flip(['eser', 'verifiche', 'risdoc', 'drafts'])
        ));
        $this->basePath = rtrim(str_replace('\\', '/', $basePath ?? dirname(__DIR__, 2)), '/');
    }

    /**
     * Saves $svgContent under $fileDir/$folderName/$fileName where
     * $fileDir is derived from the webroot $filePath.
     */
    public function saveSvg(string $filePath, string $folderName, string $fileName, string $svgContent): array
    {
        $this->assertSvgFilename($fileName);
        $this->assertFolderName($folderName);
        if (strlen($svgContent) > 10 * 1024 * 1024) {
            throw new RuntimeException('svg_too_large');
        }

        $fileDir     = dirname(ltrim(str_replace('\\', '/', $filePath), '/'));
        $relTarget   = $fileDir . '/' . $folderName . '/' . $fileName;
        $absFull     = $this->basePath . '/' . $relTarget;

        $resolved = SafePath::resolve($absFull, $this->svgRoots, mustExist: false);
        $dir      = dirname($resolved);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('cannot_create_svg_folder');
        }
        if (file_put_contents($resolved, $svgContent, LOCK_EX) === false) {
            throw new RuntimeException('svg_write_failed');
        }
        return [
            'path' => str_replace($this->basePath, '', $resolved),
            'size' => strlen($svgContent),
        ];
    }

    /** Phase 16 — elimina SVG generato precedentemente.
     *  $filePath: cartella containing svg (es. "svg" o "eser/sc/eser_sc2s/MAT/svg")
     *  $fileName: nome file (.svg). */
    public function deleteSvg(string $filePath, string $fileName): array
    {
        $this->assertSvgFilename($fileName);

        $relTarget = ltrim(str_replace('\\', '/', $filePath), '/') . '/' . $fileName;
        $absFull   = $this->basePath . '/' . $relTarget;

        $resolved = SafePath::resolve($absFull, $this->svgRoots, mustExist: true);
        if (!is_file($resolved)) {
            return ['existed' => false];
        }
        if (!@unlink($resolved)) {
            throw new RuntimeException('svg_delete_failed');
        }
        return ['existed' => true, 'path' => str_replace($this->basePath, '', $resolved)];
    }

    /**
     * Reads modelli_tikz.php (at project root), returns the TikZ code
     * for a given group + index pair.
     */
    public function getContent(string $modelliPath, string $group, int $index): string
    {
        $resolved = $this->resolveModelli($modelliPath);
        $content  = @file_get_contents($resolved);
        if ($content === false) {
            throw new RuntimeException('cannot_read_modelli');
        }

        $groupPattern = '/<div class="tex-group" data-group="' . preg_quote($group, '/') . '">(.*?)<\/div>\s*(?=<div class="tex-group"|$)/s';
        if (!preg_match($groupPattern, $content, $gm)) {
            throw new RuntimeException('group_not_found');
        }
        preg_match_all('/<div class="element-tex">(.*?)<\/div>\s*<\/div>/s', $gm[1], $els);
        if (!isset($els[1][$index])) {
            throw new RuntimeException('element_not_found');
        }

        if (!preg_match('/<script type="text\/tikz"[^>]*>(.*?)<\/script>/s', $els[1][$index], $sm)) {
            throw new RuntimeException('tikz_content_not_found');
        }
        return trim($sm[1]);
    }

    /**
     * Rebuilds modelli_tikz.json from modelli_tikz.php when needed
     * (force=true, json missing, or php newer than json).
     *
     * @return array{regenerated: bool, reason: string, groups?: int, size?: int}
     */
    public function ensureJson(string $phpPath, string $jsonPath, bool $force = false): array
    {
        $php  = $this->resolveModelli($phpPath);
        $json = $this->resolveModelli($jsonPath, mustExist: false);

        // Hash-based freshness: confronta md5 del sorgente contro il meta salvato.
        // Più robusto di filemtime (che può cambiare per tocco filesystem senza
        // modifiche reali — es. FTP sync).
        $metaPath = $json . '.meta.json';
        $currentHash = md5_file($php) ?: '';

        $needs = false;
        $reason = '';
        if ($force) {
            $needs = true;
            $reason = 'forced';
        } elseif (!is_file($json)) {
            $needs = true;
            $reason = 'json_missing';
        } elseif (!is_file($metaPath)) {
            $needs = true;
            $reason = 'meta_missing';
        } else {
            $meta = json_decode((string)@file_get_contents($metaPath), true) ?: [];
            $savedHash = (string)($meta['source_hash'] ?? '');
            if ($savedHash !== $currentHash) {
                $needs = true;
                $reason = 'hash_changed';
            } else {
                $reason = 'cached';
            }
        }
        if (!$needs) {
            return ['regenerated' => false, 'reason' => $reason, 'hash' => $currentHash];
        }

        $content = @file_get_contents($php);
        if ($content === false) {
            throw new RuntimeException('cannot_read_modelli');
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $encoded = mb_encode_numericentity($content, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
        $dom->loadHTML($encoded, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath  = new DOMXPath($dom);
        $result = [];
        foreach ($xpath->query("//div[@class='tex-group']") as $group) {
            $name = $group->getAttribute('data-group');
            if ($name === '') {
                continue;
            }
            $result[$name] = [];
            foreach ($xpath->query(".//div[@class='element-tex']", $group) as $element) {
                $labelNodes = $xpath->query(".//div[@class='label_tikz' or @class='label_latex']", $element);
                $label = $labelNodes->length > 0 ? trim($labelNodes->item(0)->textContent) : 'Senza titolo';

                $tikz = '';
                $scripts = $xpath->query(".//script[@type='text/tikz']", $element);
                if ($scripts->length > 0) {
                    $tikz = trim($scripts->item(0)->textContent);
                } else {
                    $latexDivs = $xpath->query(".//div[@class='latex']", $element);
                    if ($latexDivs->length > 0) {
                        $tikz = trim($latexDivs->item(0)->textContent);
                    }
                }
                $result[$name][] = ['label' => $label, 'content' => $tikz];
            }
        }

        $jsonStr = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($json, $jsonStr, LOCK_EX) === false) {
            throw new RuntimeException('json_write_failed');
        }
        // Persist meta con hash del sorgente: la prossima ensureJson() no-op
        // se il sorgente non cambia.
        $meta = ['source_hash' => $currentHash, 'generated_at' => date('c')];
        @file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT), LOCK_EX);
        return ['regenerated' => true, 'reason' => $reason, 'hash' => $currentHash, 'groups' => count($result), 'size' => strlen($jsonStr)];
    }

    // ───────────── helpers ─────────────

    private function assertSvgFilename(string $name): void
    {
        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
            throw new RuntimeException('invalid_svg_filename');
        }
        if (!SafePath::extensionAllowed($name, ['svg'])) {
            throw new RuntimeException('svg_extension_required');
        }
    }

    private function assertFolderName(string $name): void
    {
        // allow subpaths like "svg/MAT-foo-svg"
        if ($name === '' || str_contains($name, '..') || str_contains($name, "\0")) {
            throw new RuntimeException('invalid_folder_name');
        }
        if (!preg_match('#^[A-Za-z0-9_\-/. ]+$#', $name)) {
            throw new RuntimeException('invalid_folder_characters');
        }
    }

    private function resolveModelli(string $relative, bool $mustExist = true): string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || str_contains($relative, '..')) {
            throw new RuntimeException('invalid_modelli_path');
        }
        // Allow only modelli_tikz* files, eventualmente sotto views/legacy/
        // o storage/data/ (prefissi introdotti da Phase 9z cleanup).
        if (!preg_match('#^(views/legacy/|storage/data/)?modelli_tikz[^/]*\.(php|json)$#', $relative)) {
            throw new RuntimeException('modelli_name_not_allowed');
        }
        $abs = $this->basePath . '/' . $relative;
        if ($mustExist && !is_file($abs)) {
            throw new RuntimeException('modelli_file_missing');
        }
        return $abs;
    }
}
