<?php

namespace App\Support;

/**
 * Phase 17 — magic-bytes sniffer per validare che un byte-blob sia davvero
 * del tipo dichiarato. Difesa contro upload di payload arbitrari camuffati
 * da MIME innocuo (es. PHP base64 salvato come .svg).
 *
 * Semplice: match sui primi bytes. Non sostituisce un antivirus ma blocca
 * il 99% degli abusi banali. Niente dipendenze esterne.
 */
final class MimeSniffer
{
    /**
     * Verifica che $bytes sia di uno dei tipi indicati.
     *
     *   $types: ['png', 'jpeg', 'gif', 'svg', 'pdf', 'webp']
     *
     * @throws \RuntimeException se nessun tipo combacia.
     */
    public static function assertAllowed(string $bytes, array $types): string
    {
        $detected = self::detect($bytes);
        if (!in_array($detected, $types, true)) {
            throw new \RuntimeException(
                "mime_mismatch:detected={$detected},allowed=" . implode('|', $types)
            );
        }
        return $detected;
    }

    /** Rileva il tipo dai magic bytes. Ritorna 'unknown' se nulla combacia. */
    public static function detect(string $bytes): string
    {
        if (strlen($bytes) < 4) {
            return 'unknown';
        }
        // Binary magic-bytes
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return 'png';
        }
        if (str_starts_with($bytes, "\xff\xd8\xff")) {
            return 'jpeg';
        }
        if (str_starts_with($bytes, "GIF87a") || str_starts_with($bytes, "GIF89a")) {
            return 'gif';
        }
        if (str_starts_with($bytes, "%PDF-")) {
            return 'pdf';
        }
        if (str_starts_with($bytes, "RIFF") && substr($bytes, 8, 4) === 'WEBP') {
            return 'webp';
        }
        // SVG: XML-based, può avere BOM o whitespace in testa.
        $head = ltrim(substr($bytes, 0, 512));
        if (
            preg_match('/^<\?xml[^>]*\?>\s*<svg\b/i', $head)
            || preg_match('/^<svg\b/i', $head)
        ) {
            return 'svg';
        }
        return 'unknown';
    }

    /** Estensione suggerita dal tipo (senza punto). */
    public static function extensionFor(string $type): string
    {
        return match ($type) {
            'jpeg' => 'jpg',
            'svg'  => 'svg',
            'png', 'gif', 'pdf', 'webp' => $type,
            default => 'bin',
        };
    }
}
