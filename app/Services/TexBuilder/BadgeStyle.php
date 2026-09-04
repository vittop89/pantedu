<?php

declare(strict_types=1);

namespace App\Services\TexBuilder;

/**
 * G27.badge.style — DTO per le preferenze stile badge.
 *
 * Cascade resolved:
 *   1. Preset admin (cascade istituto → _default)
 *      Path: storage/templates/verifiche/{scope}/badge_styles/{preset_name}.json
 *   2. Override docente
 *      Path: storage/objects/institutes/{iid}/private/{tid}/badge_style.json
 *      Schema: { preset: "compact", overrides: { fonte: {...}, badge: {...} } }
 *
 * BadgeStyle finale = preset.merge(overrides) → toLatexPreamble() → file
 * versioni/fonti_SOL.tex (prepend, prima delle \definefonte).
 *
 * Allowlist su ogni valore: solo strings note (xcolor, LaTeX size commands)
 * o dimension validate. Niente bytes raw → no iniezione LaTeX da input
 * docente o admin (che editano JSON via API).
 */
final class BadgeStyle
{
    /** Font sizes LaTeX validi (allowlist). */
    public const SIZES = [
        '\\tiny', '\\scriptsize', '\\footnotesize', '\\small',
        '\\normalsize', '\\large', '\\Large', '\\LARGE', '\\huge', '\\Huge',
    ];

    /** Colori xcolor base ammessi (allowlist) + suffisso opzionale !N. */
    private const COLOR_RE = '/^[a-zA-Z]{2,20}(![0-9]{1,3})?$/';

    /** Dimensione LaTeX (es. "1cm", "1.5cm", "8mm", "12pt", "-5pt"). */
    private const DIM_RE = '/^-?[0-9]{1,3}(\.[0-9]{1,3})?(pt|mm|cm|em|ex)$/';

    // Defaults — devono coincidere con \presetkeys{} in verifica.sty.
    public string $fonteTitleSize = '\\small';
    public string $fonteMetaSize  = '\\tiny';
    public string $fonteRowSep    = '-5pt';
    public string $fonteColSpec   = '|c|';
    public string $fonteVpad      = '0pt';   // strut padding sopra prima riga + sotto ultima (0pt = no padding)

    public string $badgeBg        = 'gray';
    public string $badgeTxt       = 'white';
    public string $badgeExSize    = '\\large';
    public string $badgeMinW      = '1cm';
    public int $badgeDiffMax   = 4;
    public string $badgeDiffSize  = '\\huge';

    /**
     * Costruisci da array piatto (preset admin OR risultato finale resolved).
     * Schema array atteso:
     *   { fonte: {title_size, meta_size, row_sep, col_spec},
     *     badge: {bg, txt, ex_size, min_width, diff_max, diff_size} }
     *
     * Valori non validi → fallback al default (silently — sanitizzazione
     * granulare per ogni campo, vedi sanitize* private methods).
     */
    public static function fromArray(array $data): self
    {
        $s = new self();
        $s->applyArray($data);
        return $s;
    }

    /**
     * Applica i valori del payload sopra l'istanza corrente. Usato sia per
     * fromArray (start da default) sia per merging override su preset
     * (start dal preset). Solo i campi presenti e validi vengono applicati.
     */
    public function applyArray(array $data): void
    {
        $f = (array)($data['fonte'] ?? []);
        $b = (array)($data['badge'] ?? []);

        if (isset($f['title_size'])) {
            $this->fonteTitleSize = self::sanitizeSize($f['title_size'], $this->fonteTitleSize);
        }
        if (isset($f['meta_size'])) {
            $this->fonteMetaSize  = self::sanitizeSize($f['meta_size'], $this->fonteMetaSize);
        }
        if (isset($f['row_sep'])) {
            $this->fonteRowSep    = self::sanitizeDim($f['row_sep'], $this->fonteRowSep);
        }
        if (isset($f['col_spec'])) {
            $this->fonteColSpec   = self::sanitizeColSpec((string)$f['col_spec'], $this->fonteColSpec);
        }
        if (isset($f['vpad'])) {
            $this->fonteVpad      = self::sanitizeDim($f['vpad'], $this->fonteVpad);
        }

        if (isset($b['bg'])) {
            $this->badgeBg        = self::sanitizeColor((string)$b['bg'], $this->badgeBg);
        }
        if (isset($b['txt'])) {
            $this->badgeTxt       = self::sanitizeColor((string)$b['txt'], $this->badgeTxt);
        }
        if (isset($b['ex_size'])) {
            $this->badgeExSize    = self::sanitizeSize($b['ex_size'], $this->badgeExSize);
        }
        if (isset($b['min_width'])) {
            $this->badgeMinW      = self::sanitizeDim($b['min_width'], $this->badgeMinW);
        }
        if (isset($b['diff_max'])) {
            $dm = (int)$b['diff_max'];
            if ($dm >= 1 && $dm <= 10) {
                $this->badgeDiffMax = $dm;
            }
        }
        if (isset($b['diff_size'])) {
            $this->badgeDiffSize  = self::sanitizeSize($b['diff_size'], $this->badgeDiffSize);
        }
    }

    /** Serializza per persistenza JSON o emissione API. */
    public function toArray(): array
    {
        return [
            '$schema' => 'pantedu.badge_style.v1',
            'fonte' => [
                'title_size' => $this->fonteTitleSize,
                'meta_size'  => $this->fonteMetaSize,
                'row_sep'    => $this->fonteRowSep,
                'col_spec'   => $this->fonteColSpec,
                'vpad'       => $this->fonteVpad,
            ],
            'badge' => [
                'bg'        => $this->badgeBg,
                'txt'       => $this->badgeTxt,
                'ex_size'   => $this->badgeExSize,
                'min_width' => $this->badgeMinW,
                'diff_max'  => $this->badgeDiffMax,
                'diff_size' => $this->badgeDiffSize,
            ],
        ];
    }

    /**
     * Emette il blocco LaTeX `\fmsetfonte{...}\fmsetbadge{...}` da prepend
     * a fonti_SOL.tex. Sanitizzazione gia' applicata: ogni valore qui e'
     * garantito allowlist-safe (no iniezione LaTeX possibile).
     */
    public function toLatexPreamble(): string
    {
        $fonteOpts = \sprintf(
            'titlesize=%s,metasize=%s,rowsep=%s,colspec=%s,vpad=%s',
            $this->fonteTitleSize,
            $this->fonteMetaSize,
            $this->fonteRowSep,
            $this->fonteColSpec,
            $this->fonteVpad,
        );
        $badgeOpts = \sprintf(
            'bg=%s,txt=%s,exsize=%s,minw=%s,diffmax=%d,diffsize=%s',
            $this->badgeBg,
            $this->badgeTxt,
            $this->badgeExSize,
            $this->badgeMinW,
            $this->badgeDiffMax,
            $this->badgeDiffSize,
        );
        return "% [G27.badge.style] preferenze stile (preset+override resolved)\n"
             . "\\fmsetfonte{{$fonteOpts}}%\n"
             . "\\fmsetbadge{{$badgeOpts}}%\n";
    }

    /** True se TUTTI i campi coincidono con i defaults della macro. */
    public function isDefault(): bool
    {
        return $this->toArray()['fonte'] === (new self())->toArray()['fonte']
            && $this->toArray()['badge'] === (new self())->toArray()['badge'];
    }

    private static function sanitizeSize(mixed $v, string $fallback): string
    {
        $v = trim((string)$v);
        return \in_array($v, self::SIZES, true) ? $v : $fallback;
    }

    private static function sanitizeColor(string $v, string $fallback): string
    {
        $v = trim($v);
        return preg_match(self::COLOR_RE, $v) ? $v : $fallback;
    }

    private static function sanitizeDim(mixed $v, string $fallback): string
    {
        $v = trim((string)$v);
        return preg_match(self::DIM_RE, $v) ? $v : $fallback;
    }

    private static function sanitizeColSpec(string $v, string $fallback): string
    {
        $v = trim($v);
        if ($v === '') {
            return $fallback;
        }
        if (preg_match('/^\|[clr]\|$/', $v)) {
            return $v;
        }
        if (preg_match('/^\|p\{[0-9]{1,3}(\.[0-9]{1,3})?(pt|mm|cm|em|ex)\}\|$/', $v)) {
            return $v;
        }
        return $fallback;
    }
}
