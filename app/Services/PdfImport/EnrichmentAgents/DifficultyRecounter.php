<?php

declare(strict_types=1);

namespace App\Services\PdfImport\EnrichmentAgents;

/**
 * Phase PDF-Import — normalizzazione difficoltà (clamp 0-4).
 * Euristica pura. Se il modello ha dato un valore fuori range lo riporta nel range.
 */
final class DifficultyRecounter
{
    public static function recount(mixed $raw): int
    {
        $n = (int)$raw;
        return max(0, min(4, $n));
    }
}
