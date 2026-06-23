<?php

declare(strict_types=1);

namespace App\Services\Gdpr\Export;

/**
 * Phase 25.R.23 — DTO output di un singolo exporter (es. ProfileExporter).
 *
 * Una section può contenere:
 *   - 0..N files (mappe decifrate, PDF compilati, ecc.)
 *   - summary metadata (rows_count, errors, scope, etc.)
 *
 * Esempio:
 *   $section = new ExportSection('mappe', 'content/mappe');
 *   $section->addFile('01KRX.drawio', $xml, 'application/xml');
 *   $section->setSummary(['count' => 5, 'total_size_bytes' => 12345]);
 */
final class ExportSection
{
    /** @var list<ExportFile> */
    public array $files = [];

    /** @var array<string,mixed> Metadati di summary (count, error, etc.) */
    public array $summary = [];

    public function __construct(
        /** Identificatore exporter (es. 'profile', 'mappe', 'verifiche'). */
        public readonly string $key,
        /** Cartella destinazione nel bundle (es. 'content/mappe'). */
        public readonly string $folder,
        /** Label umano (es. "Mappe didattiche"). */
        public readonly string $label = '',
    ) {
    }

    public function addFile(string $relativePath, string $content, string $mimeType = 'application/octet-stream'): void
    {
        $this->files[] = ExportFile::make(
            relativePath: $this->folder !== '' ? $this->folder . '/' . ltrim($relativePath, '/') : ltrim($relativePath, '/'),
            content: $content,
            mimeType: $mimeType,
        );
    }

    public function addJsonFile(string $relativePath, mixed $data): void
    {
        $body = (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->addFile($relativePath, $body, 'application/json');
    }

    public function setSummary(array $summary): void
    {
        $this->summary = $summary;
    }

    public function mergeSummary(array $partial): void
    {
        $this->summary = array_merge($this->summary, $partial);
    }

    public function fileCount(): int
    {
        return count($this->files);
    }

    public function totalSize(): int
    {
        $sum = 0;
        foreach ($this->files as $f) {
            $sum += $f->size();
        }
        return $sum;
    }
}
