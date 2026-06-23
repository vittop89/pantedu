<?php

namespace App\Services\Risdoc;

use App\Support\Storage\StorageFactory;
use App\Support\TeacherContextResolver;

/**
 * ADR-029 — logica template-default condivisa tra creazione gruppi
 * (GroupController::groupAdd) e template-CRUD (ContentTemplateController).
 * Estratta da TeacherContentController per rompere l'accoppiamento che
 * impediva lo split dei due controller (vedi ADR-029). Stateless/static:
 * l'unica dipendenza di contesto è TeacherContextResolver::firstInstituteId.
 *
 * Per ogni `type` (VF / RM / Collect) fornisce items, intro, titolo di
 * default; preferisce il template personale del docente (storage) se
 * presente, altrimenti cade sui default hard-coded.
 */
final class TemplateDefaults
{
    public static function itemsForType(string $type, int $tid = 0): array
    {
        $norm = self::normalizeType($type);
        if ($tid > 0) {
            $tpl = self::loadTeacherTemplate($tid, $norm);
            if ($tpl !== null) {
                return $tpl;
            }
        }
        return self::hardcodedItems($norm);
    }

    public static function introForType(string $type, int $tid = 0): string
    {
        $norm = self::normalizeType($type);
        if ($tid > 0) {
            $bytes = self::readRaw($tid);
            if ($bytes !== null) {
                $data = json_decode($bytes, true);
                $intro = $data[$norm]['intro'] ?? null;
                if (is_string($intro) && $intro !== '') {
                    return $intro;
                }
            }
        }
        return match ($norm) {
            'VF'      => 'Rispondi correttamente Vero o Falso.',
            'RM'      => 'Rispondi crociando la casella corretta',
            default   => 'Risolvi',
        };
    }

    public static function titleForType(string $type, int $tid = 0): string
    {
        $norm = self::normalizeType($type);
        if ($tid > 0) {
            $bytes = self::readRaw($tid);
            if ($bytes !== null) {
                $data = json_decode($bytes, true);
                $title = $data[$norm]['title'] ?? null;
                if (is_string($title) && $title !== '') {
                    return $title;
                }
            }
        }
        return match ($norm) {
            'VF'      => 'VoF d',
            'RM'      => 'RM',
            default   => 'Equazioni',
        };
    }

    public static function normalizeType(string $type): string
    {
        if (preg_match('/^(type_)?VF/i', $type)) {
            return 'VF';
        }
        if (preg_match('/^(type_)?RM/i', $type)) {
            return 'RM';
        }
        return 'Collect';
    }

    public static function readRaw(int $tid): ?string
    {
        // firstInstituteId tocca il DB: avvolgiamo tutto per ritornare null
        // anche se DB è disabilitato o la lookup fallisce (fallback seed).
        try {
            $iid = TeacherContextResolver::firstInstituteId($tid);
            if ($iid <= 0) {
                return null;
            }
            $key = "institutes/$iid/private/$tid/templates.json";
            return (string)StorageFactory::default()->get($key);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function loadTeacherTemplate(int $tid, string $norm): ?array
    {
        $bytes = self::readRaw($tid);
        if ($bytes === null) {
            return null;
        }
        $data = json_decode($bytes, true);
        if (!is_array($data) || !isset($data[$norm])) {
            return null;
        }
        $block = $data[$norm];
        if (!is_array($block['items'] ?? null) || !$block['items']) {
            return null;
        }

        $newId = function (): string {
            $d = random_bytes(16);
            $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
            $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
        };
        $toBlocks = fn($v) => is_string($v)
            ? [['type' => 'text', 'content' => $v]]
            : (is_array($v) ? $v : []);

        $out = [];
        foreach ($block['items'] as $it) {
            $base = [
                'id'             => $newId(),
                'difficulty'     => 0,
                'tags'           => [],
                'category_label' => '',
                'category_color' => null,
                'source'         => '',
                'origin'         => 'personal',
                'color'          => 'white',
                'question'       => $toBlocks($it['question'] ?? ''),
                'justification'  => $toBlocks($it['justification'] ?? 'giustifica'),
                'body_html'      => '',
            ];
            if ($norm === 'VF') {
                $ans = (string)($it['answer'] ?? 'V');
                $base['answer'] = ($ans === 'F') ? 'F' : 'V';
                $base['options'] = [];
            } elseif ($norm === 'RM') {
                $opts = is_array($it['options'] ?? null) ? $it['options'] : [];
                $letters = 'abcdefghijklmnop';
                $base['options'] = [];
                foreach ($opts as $i => $op) {
                    $base['options'][] = [
                        'letter'  => $op['letter'] ?? $letters[$i] ?? 'a',
                        'correct' => !empty($op['correct']),
                        'content' => $toBlocks($op['content'] ?? ''),
                    ];
                }
            } else {
                $base['solution'] = $toBlocks($it['solution'] ?? 'soluzione');
            }
            $out[] = $base;
        }
        return $out ?: null;
    }

    public static function hardcodedItems(string $norm): array
    {
        $newId = function (): string {
            $d = random_bytes(16);
            $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
            $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
        };
        $baseOf = fn(string $text) => [
            'id'             => $newId(),
            'difficulty'     => 0,
            'tags'           => [],
            'category_label' => '',
            'category_color' => null,
            'source'         => '',
            'origin'         => 'personal',
            'color'          => 'white',
            'question'       => [['type' => 'text', 'content' => $text]],
            'justification'  => [['type' => 'text', 'content' => 'giustifica']],
            'body_html'      => '',
        ];
        if ($norm === 'VF') {
            return [
                $baseOf('Aff1') + ['answer' => 'V', 'options' => []],
                $baseOf('Aff2') + ['answer' => 'F', 'options' => []],
                $baseOf('Aff3') + ['answer' => 'V', 'options' => []],
            ];
        }
        if ($norm === 'RM') {
            return [$baseOf('Quesito RM') + [
                'options' => [
                    ['letter' => 'a', 'correct' => false, 'content' => [['type' => 'text', 'content' => 'Es1']]],
                    ['letter' => 'b', 'correct' => true,  'content' => [['type' => 'text', 'content' => 'Es2']]],
                    ['letter' => 'c', 'correct' => false, 'content' => [['type' => 'text', 'content' => 'Es3']]],
                    ['letter' => 'd', 'correct' => false, 'content' => [['type' => 'text', 'content' => 'Es4']]],
                ],
            ]];
        }
        return [$baseOf('Nuovo quesito') + ['solution' => [['type' => 'text', 'content' => 'soluzione']]]];
    }

    public static function seedDefault(): array
    {
        // Seed di default se il file non esiste: ritorna lo stato equivalente
        // alle costanti hard-coded (l'UI può salvarle come baseline).
        return [
            'VF' => [
                'title' => 'VoF d',
                'intro' => 'Rispondi correttamente Vero o Falso.',
                'items' => [
                    ['question' => 'Aff1', 'answer' => 'V', 'justification' => 'giustifica'],
                    ['question' => 'Aff2', 'answer' => 'F', 'justification' => 'giustifica'],
                    ['question' => 'Aff3', 'answer' => 'V', 'justification' => 'giustifica'],
                ],
            ],
            'RM' => [
                'title' => 'RM',
                'intro' => 'Rispondi crociando la casella corretta',
                'items' => [[
                    'question' => 'Quesito RM',
                    'options' => [
                        ['content' => 'Es1', 'correct' => false],
                        ['content' => 'Es2', 'correct' => true],
                        ['content' => 'Es3', 'correct' => false],
                        ['content' => 'Es4', 'correct' => false],
                    ],
                    'justification' => 'giustifica',
                ]],
            ],
            'Collect' => [
                'title' => 'Equazioni',
                'intro' => 'Risolvi',
                'items' => [
                    ['question' => 'Nuovo quesito', 'solution' => 'soluzione'],
                ],
            ],
        ];
    }
}
