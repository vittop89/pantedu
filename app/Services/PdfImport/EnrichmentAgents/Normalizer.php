<?php

declare(strict_types=1);

namespace App\Services\PdfImport\EnrichmentAgents;

/**
 * Phase PDF-Import — normalizzazione campi (colore badge, alias comuni).
 * Euristica pura, deterministica.
 */
final class Normalizer
{
    private const COLOR_ALIASES = [
        'rosso' => 'red', 'red' => 'red',
        'blu' => 'blue', 'azzurro' => 'blue', 'blue' => 'blue',
        'verde' => 'green', 'green' => 'green',
        'arancione' => 'orange', 'arancio' => 'orange', 'orange' => 'orange',
    ];

    public static function normalizeColor(string $raw): string
    {
        $key = mb_strtolower(trim($raw));
        return self::COLOR_ALIASES[$key] ?? (in_array($key, ['red','blue','green','orange'], true) ? $key : '');
    }
}
