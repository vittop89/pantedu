<?php

declare(strict_types=1);

namespace App\Services\Rendering;

/**
 * G23 — Single source of truth per i tipi colonna delle tabelle RM
 * (Risposta Multipla). Mirror di `js/modules/render/rm-table-view.js`
 * `COL_TYPES` export.
 *
 * Tipi supportati: X (checkbox) | V (radio) | B (button) | T (text) | N (number).
 *
 * Centralizza:
 *   - mapping char → HTML input snippet (per ContractRenderer)
 *   - mapping char → LaTeX symbol (per TexBuilder\Sanitizer)
 *   - parsing typecell string `|X|V|B|...|` → array di char
 */
final class RmColumnTypes
{
    /** Tipi validi (ordine = ordine UI dropdown). */
    public const TYPES = ['X', 'V', 'B', 'T', 'N', 'F'];

    /**
     * Definizioni canoniche per ciascun tipo.
     *   html_input: tag/attributi per HTML render
     *   tex:        simbolo LaTeX per cell prefix (UNCHECKED state)
     *   tex_checked: simbolo per CHECKED state (filled box / dotted circle)
     *   desc:       descrizione UI
     */
    private const DEFS = [
        'X' => ['html_input' => 'checkbox', 'tex' => '\\square',  'tex_checked' => '\\blacksquare', 'desc' => 'Checkbox (multipla)'],
        'V' => ['html_input' => 'radio',    'tex' => '\\bigcirc', 'tex_checked' => '\\odot',        'desc' => 'Radio (esclusiva)'],
        'B' => ['html_input' => 'button',   'tex' => '\\fbox{btn}', 'tex_checked' => '\\fbox{btn}', 'desc' => 'Button'],
        'T' => ['html_input' => 'text',     'tex' => '\\underline{\\ \\ \\ \\ }', 'tex_checked' => '\\underline{\\ \\ \\ \\ }', 'desc' => 'Text input'],
        'N' => ['html_input' => 'number',   'tex' => '\\boxed{\\#}', 'tex_checked' => '\\boxed{\\#}', 'desc' => 'Number input'],
        // F = Vero/Falso: due caselle V□ F□. La soluzione (V o F) usa il flag
        // `correct` dell'opzione (correct=true → affermazione VERA → V).
        'F' => ['html_input' => 'vf',       'tex' => '\\text{V}\\,\\square\\quad \\text{F}\\,\\square', 'tex_checked' => '\\text{V}\\,\\blacksquare\\quad \\text{F}\\,\\square', 'desc' => 'Vero/Falso'],
    ];

    /** Normalizza un type char a uno dei TYPES validi (default 'X'). */
    public static function normalize(?string $type): string
    {
        $t = strtoupper((string)$type);
        return in_array($t, self::TYPES, true) ? $t : 'X';
    }

    /** Parse typecell string `|X|V|...|` → array di char ['X','V',...]. */
    public static function parseTypecell(string $typecell, int $expectedCols = 0): array
    {
        $types = [];
        if ($typecell !== '' && preg_match_all('/[XVBTNF]/i', $typecell, $m)) {
            foreach ($m[0] as $c) {
                $types[] = strtoupper($c);
            }
        }
        if ($expectedCols > 0) {
            // Pad mancanti con X / truncate eccedenti
            while (count($types) < $expectedCols) {
                $types[] = 'X';
            }
            $types = array_slice($types, 0, $expectedCols);
        }
        return $types;
    }

    /**
     * Renderizza l'input HTML per una cella RM. Markup unificato con
     * `js/modules/render/rm-table-view.js::colTypeToInput()`.
     *
     * @param string $type   Char tipo colonna (X|V|B|T|N).
     * @param bool   $checked Stato `checked` per X/V (input.correct = true).
     * @param array  $opts    Extra opts: label (B), value (T/N).
     */
    public static function toHtml(string $type, bool $checked = false, array $opts = []): string
    {
        $t = self::normalize($type);
        $checkedAttr = $checked ? ' checked' : '';

        return match ($t) {
            'X' => '<input type="checkbox" class="checkbox fm-checkbox-rm' . ($checked ? ' solchecked' : '') . '"' . $checkedAttr . '>',
            'V' => '<input type="radio" class="checkbox fm-checkbox-rm' . ($checked ? ' solchecked' : '') . '"' . $checkedAttr . '>',
            'B' => '<button type="button" class="fm-rm-btn">' . htmlspecialchars((string)($opts['label'] ?? 'btn'), ENT_QUOTES, 'UTF-8') . '</button>',
            'T' => '<input type="text" class="fm-rm-text"' . (isset($opts['value']) ? ' value="' . htmlspecialchars((string)$opts['value'], ENT_QUOTES, 'UTF-8') . '"' : '') . '>',
            'N' => '<input type="number" class="fm-rm-num"' . (isset($opts['value']) ? ' value="' . htmlspecialchars((string)$opts['value'], ENT_QUOTES, 'UTF-8') . '"' : '') . '>',
            // Vero/Falso: la casella V è la checkbox interattiva (.fm-checkbox-rm
            // → toggle `correct`); la F è visiva e si evidenzia via CSS quando V
            // NON è checked. correct=true ⇒ risposta V; correct=false ⇒ F.
            'F' => '<span class="fm-rm-vf">'
                . '<label class="fm-rm-vf__opt fm-rm-vf__opt--v" title="Vero">'
                . '<input type="checkbox" class="checkbox fm-checkbox-rm fm-rm-vf__input' . ($checked ? ' solchecked' : '') . '"' . $checkedAttr . '>'
                . '<span class="fm-rm-vf__lbl">V</span></label>'
                . '<span class="fm-rm-vf__opt fm-rm-vf__opt--f" aria-hidden="true"><span class="fm-rm-vf__box"></span><span class="fm-rm-vf__lbl">F</span></span>'
                . '</span>',
            default => '<input type="checkbox" class="checkbox fm-checkbox-rm"' . $checkedAttr . '>',
        };
    }

    /** Simbolo LaTeX per il prefix cella nel TeX rendering.
     *  $checked=true → variant "filled" (per correct answer in soluzione PDF). */
    public static function toTex(string $type, bool $checked = false): string
    {
        $def = self::DEFS[self::normalize($type)];
        return $checked ? $def['tex_checked'] : $def['tex'];
    }

    /** Descrizione tipo per UI (tooltip). */
    public static function describe(string $type): string
    {
        return self::DEFS[self::normalize($type)]['desc'];
    }

    /** Tutti i tipi con metadata (per UI dropdowns). */
    public static function all(): array
    {
        return array_map(
            fn(string $t) => array_merge(['key' => $t], self::DEFS[$t]),
            self::TYPES
        );
    }
}
