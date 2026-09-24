<?php

declare(strict_types=1);

namespace App\Services\PdfImport;

use App\Services\PdfImport\EnrichmentAgents\DifficultyRecounter;
use App\Services\PdfImport\EnrichmentAgents\Normalizer;
use App\Services\PdfImport\EnrichmentAgents\TypeClassifier;
use App\Services\PdfImport\EnrichmentAgents\Validator;

/**
 * Phase PDF-Import — mapping item estratto → "row" di revisione (contracts.json).
 *
 * ★ Pezzo più rischioso del feature: il payload deve combaciare con lo schema
 * contract di pantedu (vedi TeacherContentController::hardcodedDefaultItems):
 *   - Collect : item con question/justification/solution (blocchi text)
 *   - VF      : item con answer 'V'|'F' + options:[]
 *   - RM      : item con options:[{letter, correct:bool, content:[blocchi]}]
 *
 * I tipi del tool originale collassano così:
 *   type_Collect             → Collect
 *   type_VF                  → VF
 *   type_RMultiA/type_RMultiB→ RM
 *
 * La row porta SIA i metadati editabili (number/page/color/difficulty/type/
 * topic/container/target) SIA un `payload` strutturato col contenuto estratto;
 * il group/item pantedu vero e proprio è costruito al momento dell'insert
 * (Fase 5, ExerciseInserter) a partire da type + payload.
 */
final class ContractMapper
{
    /** Mappa un singolo item normalizzato in una row di revisione. */
    public function mapItem(array $item, int $sourcePage): array
    {
        $inferred = TypeClassifier::infer($item);
        $type     = self::toPanteduType($inferred);
        $color    = Normalizer::normalizeColor((string)($item['badge_color'] ?? ''));
        $diff     = DifficultyRecounter::recount($item['difficulty'] ?? 0);
        $flags    = Validator::flags($item, $inferred);

        return [
            'id'           => self::uuid(),
            'source_page'  => $sourcePage,
            'number'       => (string)($item['number'] ?? ''),
            'page'         => (string)($item['page_number'] ?? (string)$sourcePage),
            'badge_color'  => $color,
            'difficulty'   => $diff,
            'badge_box'    => is_array($item['badge_box'] ?? null) ? array_values($item['badge_box']) : [],
            'topic'        => '',
            'origin'       => '',  // fonte (source_key): scelta dal docente in revisione
            'container'    => (string)($item['container_name'] ?? ''),
            'target'       => self::defaultTarget($type),
            'type'         => $type,
            'payload'      => $this->buildPayload($type, $item),
            'flags'        => $flags,
            'inferred_type' => $inferred,
        ];
    }

    /** @return array<string,mixed> */
    private function buildPayload(string $type, array $item): array
    {
        $question = (string)($item['text'] ?? '');
        $shared   = (string)($item['shared_instruction'] ?? '');
        $solution = (string)($item['solution'] ?? '');
        $subs     = (array)($item['sub_items'] ?? []);

        $base = [
            'question'           => $question,
            'shared_instruction' => $shared,
            'solution'           => $solution,
            'has_figure'         => (bool)($item['has_figure'] ?? false),
            'figure_description' => (string)($item['figure_description'] ?? ''),
        ];

        return match ($type) {
            'RM' => $base + [
                'options' => array_map(static fn($s) => [
                    'letter'  => (string)($s['letter'] ?? ''),
                    'text'    => (string)($s['text'] ?? ''),
                    'correct' => false, // ignoto dall'estrazione → il docente lo imposta
                ], array_values(array_filter($subs, 'is_array'))),
            ],
            'VF', 'RM_VF' => $base + [
                'statements' => array_map(static fn($s) => [
                    'text'   => (string)($s['text'] ?? ''),
                    'answer' => 'V', // default; flag vf_answers_unknown guida la review
                ], array_values(array_filter($subs, 'is_array'))),
            ],
            default => $base + [ // Collect
                'points' => array_map(static fn($s) => [
                    'letter' => (string)($s['letter'] ?? ''),
                    'text'   => (string)($s['text'] ?? ''),
                ], array_values(array_filter($subs, 'is_array'))),
            ],
        };
    }

    public static function toPanteduType(string $inferred): string
    {
        return match ($inferred) {
            'type_VF' => 'VF',
            // RMultiA = domanda condivisa + sotto-voci da giudicare V/F → tabella
            // V/F (RM_VF). RMultiB = A/B/C/D scelta unica → RM. (Prima erano fusi.)
            'type_RMultiA' => 'RM_VF',
            'type_RMultiB' => 'RM',
            default => 'Collect',
        };
    }

    private static function defaultTarget(string $type): string
    {
        return match ($type) {
            'VF'    => 'Vero/Falso',
            'RM_VF' => 'Vero/Falso',
            'RM'    => 'Scelta multipla',
            default => 'Problemi',
        };
    }

    /** UUID v4 (stesso schema di TeacherContentController::hardcodedDefaultItems). */
    private static function uuid(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
