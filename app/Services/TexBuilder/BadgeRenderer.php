<?php

declare(strict_types=1);

namespace App\Services\TexBuilder;

use App\Services\Tex\TexEscape;
use App\Support\Storage\StorageFactory;
use Throwable;

/**
 * G27.badge — Renderer del badge editoriale per la pipeline TeX (variante SOL).
 *
 * Sostituisce il LaTeX inline ripetuto a ogni esercizio (\overset/\underset/
 * \fcolorbox copincollati) con una macro `\badge[opt]{src_key}{page}{ex}{diff}`
 * definita centralmente in `verifica.sty` (G27.badge). Le fonti si registrano
 * una sola volta a inizio documento via `\definefonte{KEY}{TITLE}{VOLUME}{AUTHORS}`
 * — preambolo emesso da `renderFontiPreamble()`.
 *
 * Carica il registry per docente da:
 *   institutes/{instituteId}/private/{teacherId}/sources.registry.json
 *
 * Schema row del registry (vedi sources.registry.json):
 *   { key, book, volume, authors }
 *
 * Schema badge atteso nel contract item (vedi ContractAggregate::patchItem):
 *   item.badge  = { page, ex_num, bg_color, difficulty, difficulty_max? }
 *   item.origin = source_key (preferito) | item.source = source_key (fallback)
 */
final class BadgeRenderer
{
    /** @var array<string,array{key:string,book?:string,volume?:string,authors?:string}> */
    private array $sources;

    /** @param array<string,array> $sources key → registry row */
    public function __construct(array $sources = [])
    {
        $this->sources = $sources;
    }

    /**
     * Carica sources.registry.json del docente. Best-effort: se manca o e'
     * malformato ritorna istanza vuota (renderer no-op).
     */
    public static function loadFor(int $instituteId, int $teacherId): self
    {
        if ($instituteId <= 0 || $teacherId <= 0) {
            return new self([]);
        }
        try {
            $key = "institutes/{$instituteId}/private/{$teacherId}/sources.registry.json";
            $bytes = StorageFactory::default()->get($key);
            $reg = json_decode((string)$bytes, true) ?: [];
            $map = [];
            foreach (($reg['sources'] ?? []) as $s) {
                if (!empty($s['key'])) {
                    $map[(string)$s['key']] = $s;
                }
            }
            return new self($map);
        } catch (Throwable) {
            return new self([]);
        }
    }

    /**
     * Emette le righe `\definefonte{KEY}{TITLE}{VOLUME}{AUTHORS}`.
     *
     * @param array<int,string>|null $onlyKeys Se non null, filtra le fonti
     *        emesse alle sole key passate (intersection con il registro caricato).
     *        Tipico: passare l'output di `collectUsedKeys()` per avere un
     *        preambolo minimale (1 \definefonte per fonte effettivamente
     *        usata negli esercizi). Se null → dump completo del registro.
     *
     * Nota: le KEY non vengono escaped (devono restare valid csname tokens —
     * snake_case alphanumerico per convenzione). Title/volume/authors sono
     * TexEscape'd perche' vanno dentro `\text{...}` math mode.
     */
    public function renderFontiPreamble(?array $onlyKeys = null): string
    {
        if (!$this->sources) {
            return "% [G27.badge] registro fonti vuoto o non caricato\n";
        }
        $filter = null;
        if ($onlyKeys !== null) {
            $filter = array_flip(array_filter(array_map([self::class, 'sanitizeKey'], $onlyKeys)));
        }
        $out = "% [G27.badge] registro fonti (auto-generato da BadgeRenderer)\n";
        $emitted = 0;
        foreach ($this->sources as $key => $s) {
            $safeKey = self::sanitizeKey((string)$key);
            if ($safeKey === '') {
                continue;
            }
            if ($filter !== null && !isset($filter[$safeKey])) {
                continue;
            }
            $out .= \sprintf(
                "\\definefonte{%s}{%s}{%s}{%s}\n",
                $safeKey,
                TexEscape::escape((string)($s['book']    ?? '')),
                TexEscape::escape((string)($s['volume']  ?? '')),
                TexEscape::escape((string)($s['authors'] ?? '')),
            );
            $emitted++;
        }
        if ($emitted === 0) {
            $out .= "% [G27.badge] nessuna fonte filtrata da emettere\n";
        }
        return $out;
    }

    /**
     * Estrae le source_key distinte effettivamente usate negli items della
     * Selection (preferenza: item.origin → item.source → item.badge.source_key).
     * Skippa item senza badge field popolato (no badge → no fonte da registrare).
     *
     * @return list<string>
     */
    public function collectUsedKeys(Selection $sel): array
    {
        $seen = [];
        foreach ($sel->problems as $p) {
            foreach (((array)($p['items'] ?? [])) as $it) {
                $badge = $it['badge'] ?? null;
                if (!\is_array($badge)) {
                    continue;
                }
                $key = (string)($it['origin'] ?? $it['source'] ?? $badge['source_key'] ?? '');
                $key = self::sanitizeKey($key);
                if ($key !== '') {
                    $seen[$key] = true;
                }
            }
        }
        return array_keys($seen);
    }

    /**
     * G27.badge.fix — strip difensivo del LaTeX badge inline legacy:
     *   $\begin{array}{|c|}\hline ... \hline\end{array}\quad\overset{...}\quad$
     * da rimuovere quando l'item ha gia' un campo `badge` strutturato (cosi'
     * il BadgeRenderer emette la macro \badge senza duplicare l'output).
     *
     * Idempotente: se il pattern non matcha, ritorna $tex invariato. Match
     * non-greedy fino al primo `\quad$` dopo l'`\overset`.
     */
    public static function stripLegacyInline(string $tex): string
    {
        // Legacy fonte→badge ($...$): $\begin{array}{|c|}\hline...\end{array}\quad\overset...\quad$
        $tex = (string)preg_replace(
            '/\\$\\\\begin\\{array\\}\\{\\|c\\|\\}\\\\hline[\\s\\S]*?\\\\hline\\\\end\\{array\\}\\s*\\\\quad\\s*\\\\overset[\\s\\S]*?\\\\quad\\$\\s*/u',
            '',
            $tex,
        );
        // Moderno badge→fonte (delim \(...\)): firma \overset{\color{red}...
        $tex = (string)preg_replace(
            '/\\\\\\(\\s*\\\\overset\\{\\\\color\\{red\\}[\\s\\S]*?\\\\\\)\\s*/u',
            '',
            $tex,
        );
        // Moderno badge→fonte (delim $...$).
        $tex = (string)preg_replace(
            '/\\$\\s*\\\\overset\\{\\\\color\\{red\\}[\\s\\S]*?\\$\\s*/u',
            '',
            $tex,
        );
        return $tex;
    }

    /**
     * Rimuove lo span badge inline dal body HTML del contract:
     *   <span class="fm-badge-row"><span class="fm-badge fm-latex">\(...\)</span></span>
     * Da chiamare quando l'item ha un campo `badge` strutturato (→ il
     * BadgeRenderer emette la macro \badge): evita il DOPPIO rendering
     * (badge inline dal contract + macro \badge). Format-agnostico: rimuove
     * qualunque LaTeX contenuto nello span. Idempotente: no-op se assente.
     */
    public static function stripBadgeSpan(string $html): string
    {
        // Wrapper completo .fm-badge-row (contiene solo lo span .fm-badge).
        $html = (string)preg_replace(
            '/<span\\s+class="fm-badge-row">[\\s\\S]*?<\\/span>\\s*<\\/span>/u',
            '',
            $html,
        );
        // Span .fm-badge nudo (senza wrapper row): match preciso su "fm-badge"
        // seguito da spazio o quote (NON "fm-badge-row").
        $html = (string)preg_replace(
            '/<span\\s+class="fm-badge[ "][^>]*>[\\s\\S]*?<\\/span>/u',
            '',
            $html,
        );
        return $html;
    }

    /**
     * Emette `\badge[opt]{key}{page}{ex}{diff}` se l'item ha campi badge
     * minimi (almeno src_key OPPURE page+ex_num); altrimenti '' (no-op).
     *
     * @param array<string,mixed> $item Contract item
     */
    public function render(array $item): string
    {
        $badge = $item['badge'] ?? null;
        if (!is_array($badge)) {
            return '';
        }
        $srcKey  = self::sanitizeKey((string)($item['origin'] ?? $item['source'] ?? $badge['source_key'] ?? ''));
        $page    = (string)($badge['page']   ?? '');
        $exNum   = (string)($badge['ex_num'] ?? '');
        $diff    = (int)   ($badge['difficulty'] ?? 0);
        $diffMax = (int)   ($badge['difficulty_max'] ?? 4);
        $bg      = self::sanitizeColor((string)($badge['bg_color'] ?? 'gray'));

        if ($srcKey === '' && $page === '' && $exNum === '') {
            return '';
        }
        if ($diffMax < 1) {
            $diffMax = 4;
        }
        $diff = max(0, min($diff, $diffMax));

        $opts = [];
        if ($bg !== 'gray') {
            $opts[] = "bg={$bg}";
        }
        if ($diffMax !== 4) {
            $opts[] = "diffmax={$diffMax}";
        }
        $optStr = $opts ? '[' . implode(',', $opts) . ']' : '';

        return \sprintf(
            '\badge%s{%s}{%s}{%s}{%d}',
            $optStr,
            $srcKey,
            TexEscape::escape($page),
            TexEscape::escape($exNum),
            $diff,
        );
    }

    /**
     * Sanitizza source_key: solo [a-zA-Z0-9_-] ammessi (compatibili con
     * csname LaTeX). Stringhe non-conformi → '' (skip emissione).
     */
    private static function sanitizeKey(string $k): string
    {
        $k = trim($k);
        if ($k === '') {
            return '';
        }
        return preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $k) ? $k : '';
    }

    /**
     * Allowlist colori xcolor: nomi base + opzionale modificatore !N (es. red!50).
     * Hex (#RRGGBB) richiederebbe \definecolor a runtime → fallback gray.
     */
    private static function sanitizeColor(string $c): string
    {
        $c = trim($c);
        if ($c === '') {
            return 'gray';
        }
        if (preg_match('/^[a-zA-Z]{2,20}(![0-9]{1,3})?$/', $c)) {
            return $c;
        }
        return 'gray';
    }
}
