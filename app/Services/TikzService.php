<?php

namespace App\Services;

use App\Support\SafePath;
use DOMDocument;
use DOMXPath;
use RuntimeException;

/**
 * TikZ / LaTeX element storage: salvataggio e cancellazione degli SVG
 * (era save_tikz_svg.php). getContent() ed ensureJson(), che leggevano un
 * modelli_tikz.php non piu' nel repository, sono stati tolti il 2026-09-05:
 * i modelli TikZ vivono nei JSON di storage/data.
 *
 * All filesystem work is funneled through SafePath + the FileService
 * roots whitelist (eser/, verifiche/, risdoc/, drafts/).
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
}
