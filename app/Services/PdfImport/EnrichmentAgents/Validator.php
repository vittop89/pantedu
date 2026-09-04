<?php

declare(strict_types=1);

namespace App\Services\PdfImport\EnrichmentAgents;

/**
 * Phase PDF-Import — validazione leggera: produce flag di revisione che la UI
 * evidenzia (es. "tipo incerto", "manca testo", "risposta corretta non nota").
 * Non blocca: serve solo a guidare il docente in fase di review.
 *
 * @return list<string>
 */
final class Validator
{
    /** @return list<string> */
    public static function flags(array $item, string $inferredType): array
    {
        $flags = [];
        if (trim((string)($item['text'] ?? '')) === '') {
            $flags[] = 'missing_text';
        }
        if (
            in_array($inferredType, ['type_RMultiA', 'type_RMultiB'], true)
            && count((array)($item['sub_items'] ?? [])) < 2
        ) {
            $flags[] = 'few_options';
        }
        if ($inferredType === 'type_RMultiB') {
            // La risposta corretta non è ricavabile dall'estrazione vision.
            $flags[] = 'correct_answer_unknown';
        }
        if ($inferredType === 'type_VF') {
            $flags[] = 'vf_answers_unknown';
        }
        if (!empty($item['has_figure'])) {
            $flags[] = 'has_figure';
        }
        return $flags;
    }
}
