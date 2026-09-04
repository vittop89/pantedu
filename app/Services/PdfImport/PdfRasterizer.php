<?php

declare(strict_types=1);

namespace App\Services\PdfImport;

/**
 * Phase PDF-Import — rasterizzazione PDF → PNG per pagina.
 *
 * UNICO punto di dipendenza da un binario esterno. Backend rilevati a runtime,
 * in ordine di preferenza:
 *   1. estensione PHP Imagick (con delegate Ghostscript per i PDF)
 *   2. `pdftoppm` (poppler-utils) via proc_open
 *   3. nessuno → eccezione 'rasterizer_unavailable' (il controller risponde 503)
 *
 * Sicurezza: l'input è scritto in un file temporaneo con nome generato dal
 * server (mai dal nome utente); il comando pdftoppm è invocato con argomenti
 * separati (no shell string interpolation → no command injection).
 */
final class PdfRasterizer
{
    public function __construct(
        private readonly int $dpi = 300,
        private readonly int $maxPages = 40,
    ) {
    }

    /** True se almeno un backend di rasterizzazione è disponibile. */
    public function available(): bool
    {
        return $this->detectBackend() !== null;
    }

    public function detectBackend(): ?string
    {
        if (extension_loaded('imagick') && class_exists(\Imagick::class)) {
            return 'imagick';
        }
        if ($this->binaryExists('pdftoppm')) {
            return 'pdftoppm';
        }
        return null;
    }

    /**
     * Rasterizza i byte di un PDF in una lista di byte PNG (uno per pagina).
     *
     * @return list<string> byte grezzi PNG, indicizzati 0..N-1 (pagina i+1)
     * @throws \RuntimeException 'rasterizer_unavailable' | 'rasterize_failed' | 'pdf_no_pages'
     */
    public function rasterize(string $pdfBytes): array
    {
        $backend = $this->detectBackend();
        if ($backend === null) {
            throw new \RuntimeException('rasterizer_unavailable');
        }
        $pages = $backend === 'imagick'
            ? $this->viaImagick($pdfBytes)
            : $this->viaPdftoppm($pdfBytes);

        if ($pages === []) {
            throw new \RuntimeException('pdf_no_pages');
        }
        return array_slice($pages, 0, $this->maxPages);
    }

    /** @return list<string> */
    private function viaImagick(string $pdfBytes): array
    {
        try {
            $im = new \Imagick();
            $im->setResolution($this->dpi, $this->dpi);
            // readImageBlob con suffisso .pdf forza il delegate Ghostscript.
            $im->readImageBlob($pdfBytes, 'input.pdf');
            $im->setImageFormat('png');
            $im->setImageBackgroundColor('white');
            $im = $im->flattenImages();

            $out = [];
            foreach ($im as $i => $frame) {
                if ($i >= $this->maxPages) {
                    break;
                }
                $frame->setImageFormat('png');
                $out[] = $frame->getImageBlob();
            }
            $im->clear();
            return $out;
        } catch (\Throwable $e) {
            throw new \RuntimeException('rasterize_failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /** @return list<string> */
    private function viaPdftoppm(string $pdfBytes): array
    {
        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'pdfimp_' . bin2hex(random_bytes(8));
        if (!mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
            throw new \RuntimeException('rasterize_failed: tmpdir');
        }
        $in     = $tmpDir . DIRECTORY_SEPARATOR . 'in.pdf';
        $prefix = $tmpDir . DIRECTORY_SEPARATOR . 'page';
        try {
            file_put_contents($in, $pdfBytes);

            // Argomenti separati: niente shell, niente injection.
            $cmd = [
                'pdftoppm', '-png',
                '-r', (string)$this->dpi,
                '-f', '1',
                '-l', (string)$this->maxPages,
                $in, $prefix,
            ];
            $proc = proc_open(
                $cmd,
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            if (!\is_resource($proc)) {
                throw new \RuntimeException('rasterize_failed: proc_open');
            }
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($proc);
            if ($exit !== 0) {
                throw new \RuntimeException('rasterize_failed: pdftoppm exit ' . $exit
                    . ' ' . substr((string)$stderr, 0, 200));
            }

            // pdftoppm emette page-1.png, page-2.png, ... (o -01 con padding).
            $files = glob($prefix . '*.png') ?: [];
            natsort($files);
            $out = [];
            foreach ($files as $f) {
                $blob = @file_get_contents($f);
                if ($blob !== false) {
                    $out[] = $blob;
                }
            }
            return array_values($out);
        } finally {
            // Cleanup temp.
            foreach (glob($tmpDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }
    }

    private function binaryExists(string $bin): bool
    {
        $probe = stripos(PHP_OS, 'WIN') === 0 ? "where $bin" : "command -v $bin";
        $out = @shell_exec($probe . ' 2>&1');
        return is_string($out) && trim($out) !== '' && stripos($out, 'not found') === false;
    }
}
