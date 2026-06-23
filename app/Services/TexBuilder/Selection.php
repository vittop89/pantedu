<?php

namespace App\Services\TexBuilder;

use RuntimeException;

/**
 * DTO per il payload "scelte verifica" inviato dal client.
 * Schema atteso (JSON):
 *
 * {
 *   "version":      "A" | "B",
 *   "verTitle":     "Verifica Cinematica",
 *   "selectedIIS":  "ar",
 *   "selectedCLS":  "2s",
 *   "selectedMATER":"MAT",
 *   "anno":         "2026",
 *   "sezione":      "DSA-DIS",
 *   "problems": [
 *     {
 *       "filePath":   "/eser/ar/eser_ar2s/MAT/3_MAT-Equazioni_intere-ar2s.php",
 *       "problemId":  "problem-12",
 *       "position":   1,
 *       "text":       "Risolvi le seguenti equazioni",
 *       "items": [
 *         { "html": "...", "points": 2.0, "includeSolution": false }
 *       ]
 *     }
 *   ],
 *   "options": { "includeSolutions": false, "includeTitlePage": true }
 * }
 */
final class Selection
{
    public string $version    = 'A';
    public string $verTitle   = '';
    public string $iis        = '';
    public string $cls        = '';
    public string $mater      = '';
    public string $anno       = '';
    public string $sezione    = '';
    /** @var list<array{filePath:string,problemId:string,position:int,text:string,items:list<array>}> */
    public array $problems   = [];
    public array $options    = ['includeSolutions' => false, 'includeTitlePage' => true];

    public static function fromArray(array $data): self
    {
        $s = new self();
        $s->version  = self::oneOf($data['version'] ?? 'A', ['A', 'B'], 'version');
        $s->verTitle = self::str($data['verTitle']      ?? '', 'verTitle', 200);
        $s->iis      = self::str($data['selectedIIS']   ?? '', 'selectedIIS', 8);
        $s->cls      = self::str($data['selectedCLS']   ?? '', 'selectedCLS', 8);
        $s->mater    = self::str($data['selectedMATER'] ?? '', 'selectedMATER', 8);
        $s->anno     = self::str($data['anno']          ?? '', 'anno', 8);
        $s->sezione  = self::str($data['sezione']       ?? '', 'sezione', 80, allowEmpty: true);

        $problems = $data['problems'] ?? [];
        if (!\is_array($problems)) {
            throw new RuntimeException('problems_not_array');
        }
        $position = 1;
        foreach ($problems as $p) {
            if (!\is_array($p)) {
                continue;
            }
            $items = [];
            foreach (($p['items'] ?? []) as $it) {
                if (!\is_array($it)) {
                    continue;
                }
                // G27.dsa — marker DSA item-level F/GF: TableRenderer
                // prefigge "(*F*) "/"(*GF*) " al testo se non vuoto.
                $rawMark = (string)($it['mark'] ?? '');
                $mark = \in_array($rawMark, ['F', 'GF'], true) ? $rawMark : '';
                $items[] = [
                    'html'             => (string)($it['html']    ?? ''),
                    // G19.49h — sol estratto dal client da `.fm-sol/.fm-giustsol`
                    'solution'         => (string)($it['solution'] ?? ''),
                    'points'           => (float) ($it['points']  ?? 1.0),
                    'includeSolution'  => (bool)  ($it['includeSolution'] ?? false),
                    // G27.badge — carry badge fields (page/ex_num/bg_color/
                    // difficulty) + source_key per BadgeRenderer (variant SOL).
                    // Source_key arriva tipicamente in `origin` (legacy field)
                    // o `source` (nuova nomenclatura). Badge null se item
                    // non ha metadata badge: BadgeRenderer skippa gracefully.
                    'badge'            => \is_array($it['badge'] ?? null) ? $it['badge'] : null,
                    'origin'           => (string)($it['origin'] ?? $it['source'] ?? ''),
                    'mark'             => $mark,
                ];
            }
            // G27.vf.fix — accetta sia formato canonico ("VF", "RMulti", "Collect")
            // sia legacy/contract ("type_VF", "type_RMulti", "type_Collect"). Senza
            // normalizzazione, payload con `type=type_VF` cadeva nel default Collect
            // → renderVF mai chiamato → tabella V/F mancante nel TeX.
            $rawType = (string)($p['type'] ?? self::typeFromId($p['problemId'] ?? ''));
            $rawType = preg_replace('/^type_/i', '', $rawType) ?? $rawType;
            if (preg_match('/^RM/i', $rawType)) {
                $rawType = 'RMulti';
            }
            $type = self::oneOfLoose($rawType, ['Collect', 'RMulti', 'VF'], 'Collect');
            $s->problems[] = [
                'filePath'  => self::str($p['filePath']  ?? '', 'filePath', 500),
                'problemId' => self::str($p['problemId'] ?? '', 'problemId', 200),
                'position'  => (int)($p['position'] ?? $position++),
                'type'      => $type,
                'text'      => (string)($p['text']  ?? ''),
                'items'     => $items,
            ];
        }
        usort($s->problems, fn($a, $b) => $a['position'] <=> $b['position']);

        if (\is_array($data['options'] ?? null)) {
            $s->options = array_merge($s->options, $data['options']);
        }
        return $s;
    }

    public function sectionCode(): string
    {
        return $this->iis . $this->cls;
    }

    private static function str(string $v, string $field, int $max, bool $allowEmpty = false): string
    {
        $v = trim($v);
        if ($v === '' && !$allowEmpty) {
            throw new RuntimeException("missing:$field");
        }
        if (\strlen($v) > $max) {
            throw new RuntimeException("too_long:$field");
        }
        return $v;
    }

    private static function oneOf(string $v, array $allowed, string $field): string
    {
        if (!\in_array($v, $allowed, true)) {
            throw new RuntimeException("invalid:$field");
        }
        return $v;
    }

    private static function oneOfLoose(string $v, array $allowed, string $default): string
    {
        return \in_array($v, $allowed, true) ? $v : $default;
    }

    /**
     * Estrae il tipo da un problemId tipo "Xyz-type_Collect_ver_2-or_foo".
     * Fallback "Collect" se non trovato.
     */
    public static function typeFromId(string $id): string
    {
        if (preg_match('#type_([A-Za-z]+)#', $id, $m)) {
            return $m[1];
        }
        return 'Collect';
    }
}
