<?php

declare(strict_types=1);

namespace App\Services\PdfImport\EnrichmentAgents;

/**
 * Phase PDF-Import — inferenza del tipo esercizio (euristica pura, no LLM).
 *
 * Mappa un item grezzo su uno dei tipi del tool originale:
 *   type_RMultiB : sotto-voci MAIUSCOLE (A,B,C,D) = scelta multipla pura
 *   type_RMultiA : consegna condivisa + sotto-voci minuscole da valutare
 *   type_VF      : affermazioni Vero/Falso
 *   type_Collect : problema (eventuali sotto-problemi a,b,c)
 *
 * Deterministico → testabile, zero costo token. L'output è poi rimappato da
 * ContractMapper sui tipi pantedu (Collect/VF/RM).
 */
final class TypeClassifier
{
    public static function infer(array $item): string
    {
        $subs = (array)($item['sub_items'] ?? []);
        $hasUpper = false;
        $hasLower = false;
        foreach ($subs as $s) {
            $letter = trim((string)($s['letter'] ?? ''));
            if ($letter === '') {
                continue;
            }
            if (preg_match('/[A-Z]/', $letter)) {
                $hasUpper = true;
            }
            if (preg_match('/[a-z]/', $letter)) {
                $hasLower = true;
            }
        }

        $haystack = mb_strtolower(
            (string)($item['text'] ?? '') . ' '
            . (string)($item['shared_instruction'] ?? '') . ' '
            . (string)($item['container_name'] ?? '')
        );
        // Copre vero/vera/veri/vere, falso/falsa/falsi/false, "v/f".
        $looksVF = (bool)preg_match('/\b(ver[oaie]|fals[oaie]|v\s*\/\s*f)\b/u', $haystack);

        if ($hasUpper && !$hasLower) {
            return 'type_RMultiB';
        }
        if ($looksVF) {
            return 'type_VF';
        }
        if ($hasUpper && $hasLower) {
            return 'type_RMultiA';
        }
        return 'type_Collect';
    }
}
