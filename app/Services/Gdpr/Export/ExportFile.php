<?php

declare(strict_types=1);

namespace App\Services\Gdpr\Export;

/**
 * Phase 25.R.23 — DTO singolo file nell'export bundle.
 *
 * Rappresenta un file (decifrato, plaintext o binario) destinato a finire
 * nel bundle ZIP. Include sha256 per chain-of-custody nel manifest.
 */
final class ExportFile
{
    public function __construct(
        /** Percorso relativo dentro lo ZIP (es. "content/mappe/01KRX.drawio"). */
        public readonly string $relativePath,
        /** Contenuto del file (plaintext o binario). */
        public readonly string $content,
        /** MIME type per il file (es. application/xml, application/pdf). */
        public readonly string $mimeType = 'application/octet-stream',
        /** SHA-256 hex del content (calcolato in __construct se vuoto). */
        public readonly string $sha256 = '',
    ) {
    }

    public static function make(string $relativePath, string $content, string $mimeType = 'application/octet-stream'): self
    {
        return new self(
            relativePath: $relativePath,
            content: $content,
            mimeType: $mimeType,
            sha256: hash('sha256', $content),
        );
    }

    public function size(): int
    {
        return strlen($this->content);
    }
}
