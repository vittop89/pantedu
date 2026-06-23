<?php

declare(strict_types=1);

namespace App\Services\TexBuilder;

/**
 * G20.0 — DTO ritornato da TexBuilder. Contiene la lista dei file
 * generati per il bundle (1 verifica = N file).
 *
 * Modalita':
 *   - 'zip': layout piatto (texCommon/, versioni/, griglie/ tutto in root ZIP)
 *   - 'vsc': layout distribuito (texCommon a istituto root, griglie a indirizzo,
 *            main+problemi a version folder)
 *   - 'flat' (G22.S4): bundle multi-file canonico (path "texCommon/...",
 *     "versioni/...", "griglie/...") destinato sia all'archivio multi-file
 *     (saveBatch tex_files manifest) sia a `flatten()` per produrre un .tex
 *     monolitico self-contained quando serve un singolo file (download /tex,
 *     legacy callers).
 *
 * Nota: i path dei file sono relativi alla root della distribuzione.
 * Per ZIP: relativi al root archive. Per VSC: relativi al institute root.
 * Per FLAT: path canonici (no prefisso) — `flatten()` inline-espande tutti i
 *   `\input{...}` e `\usepackage{texCommon/...}` per produrre un singolo .tex.
 */
final class BuildResult
{
    public const MODE_ZIP  = 'zip';
    public const MODE_VSC  = 'vsc';
    public const MODE_FLAT = 'flat';

    /** @param list<array{path:string, content:string}> $files */
    public function __construct(
        public readonly array $files,
        public readonly string $mode,
        public readonly string $variant,
        public readonly array $meta = [],
    ) {
        if (!\in_array($mode, [self::MODE_ZIP, self::MODE_VSC, self::MODE_FLAT], true)) {
            throw new \InvalidArgumentException("invalid_mode:$mode");
        }
    }

    public function findFile(string $path): ?array
    {
        foreach ($this->files as $f) {
            if ($f['path'] === $path) {
                return $f;
            }
        }
        return null;
    }

    /** Lista solo i path. */
    public function paths(): array
    {
        return array_map(fn($f) => $f['path'], $this->files);
    }

    /**
     * G22.S4 — Inline-espansione del bundle in un singolo .tex self-contained.
     *
     * Prende il file `versioni/main_{VARIANT}.tex` come root e:
     *   1. Sostituisce `\usepackage{texCommon/verifica}` con il contenuto di
     *      `texCommon/verifica.sty` dal bundle, RIPULITO dei direttivi
     *      di package (\NeedsTeXFormat, \ProvidesPackage, \endinput).
     *   2. Sostituisce ogni `\input{<path>}` con il contenuto del file
     *      corrispondente nel bundle (matching su path o path con estensione
     *      .tex aggiunta).
     *   3. Risoluzione iterativa: i file inclusi possono a loro volta avere
     *      \input — espande fino a un massimo di 5 livelli (defensive
     *      contro cicli).
     *
     * Il risultato e' un .tex compilabile da pdflatex senza file esterni,
     * adatto al salvataggio come blob cifrato singolo (saveBatch flow) o
     * all'invio diretto al VPS tex-compile-vps.
     *
     * Uso solo con MODE_FLAT. Throw se chiamato su ZIP/VSC.
     */
    public function flatten(): string
    {
        if ($this->mode !== self::MODE_FLAT) {
            throw new \RuntimeException("BuildResult::flatten() richiede MODE_FLAT, ricevuto: $this->mode");
        }
        $main = $this->findFile("versioni/main_{$this->variant}.tex");
        if ($main === null) {
            throw new \RuntimeException("flatten: main file mancante per variant '{$this->variant}'");
        }
        return self::inlineExpand($main['content'], $this->files, 0);
    }

    /**
     * Espande ricorsivamente \usepackage{texCommon/...} e \input{...} con i
     * contenuti dei file passati. $depth previene infinite loops.
     *
     * @param list<array{path:string, content:string}> $files
     */
    private static function inlineExpand(string $tex, array $files, int $depth): string
    {
        if ($depth > 5) {
            return $tex;
        }

        $byPath = [];
        foreach ($files as $f) {
            $byPath[$f['path']] = $f['content'];
        }

        // 1. \usepackage{[../]texCommon/<name>} → inline cleaned .sty content.
        // Match sia "texCommon/X" (canonical) sia "../texCommon/X" (relative
        // to versioni/), che e' il pattern emesso da MODE_ZIP/FLAT per VPS
        // bundle compile.
        $tex = preg_replace_callback(
            '/\\\\usepackage(?:\\[[^\\]]*\\])?\\{(?:\\.\\.\\/)?texCommon\\/([^}]+)\\}/',
            static function ($m) use ($byPath) {
                $name  = $m[1];
                $candidates = ["texCommon/{$name}.sty", "texCommon/{$name}"];
                foreach ($candidates as $path) {
                    if (isset($byPath[$path])) {
                        return self::cleanStyContent($byPath[$path]);
                    }
                }
                return $m[0];
            },
            $tex,
        ) ?? $tex;

        // 2. \input{<path>} → inline content. Path puo' essere:
        //   - assoluto al bundle root: "texCommon/intestazione", "griglie/sc_MAT"
        //   - relativo a versioni/: "../texCommon/intestazione", "../griglie/X"
        //   - relativo al main_*.tex: "esercizi_NOR" (= "versioni/esercizi_NOR")
        // LaTeX cerca .tex automaticamente quando manca.
        $tex = preg_replace_callback(
            '/\\\\input\\{([^}]+)\\}/',
            static function ($m) use ($byPath, $depth) {
                $path = $m[1];
                $stripped = preg_replace('#^\\.\\./#', '', $path);
                $candidates = [
                    $path, "{$path}.tex",
                    $stripped, "{$stripped}.tex",
                    "versioni/{$path}", "versioni/{$path}.tex",
                ];
                foreach ($candidates as $p) {
                    if (isset($byPath[$p])) {
                        return self::inlineExpand($byPath[$p], array_map(
                            static fn($k, $v) => ['path' => $k, 'content' => $v],
                            array_keys($byPath),
                            array_values($byPath),
                        ), $depth + 1);
                    }
                }
                return $m[0];
            },
            $tex,
        ) ?? $tex;

        return $tex;
    }

    /**
     * Pulisce il contenuto di un .sty package per inline-inclusion nel
     * preamble del documento principale: rimuove direttivi che non hanno
     * senso fuori da un .sty file:
     *   - \NeedsTeXFormat{...}
     *   - \ProvidesPackage{...}[...]
     *   - \endinput (terminatore package)
     *
     * \RequirePackage{...} resta: e' equivalente a \usepackage in preamble.
     *
     * G27.badge — wrap in \makeatletter ... \makeatother. Nei file .sty il
     * carattere `@` e' automaticamente trattato come letter (catcode 11),
     * permettendo control sequence con `@` come `\define@key`, `\fmf@KEY`,
     * ecc. Nel preamble di un .tex monolitico questo automatismo non c'e':
     * senza wrap, `\define@key` viene parsato come `\define` + `@key` (es.
     * tutti i .sty che usano xkeyval/xparse internamente fallirebbero).
     */
    private static function cleanStyContent(string $sty): string
    {
        $sty = preg_replace('/^\\\\NeedsTeXFormat\\{[^}]*\\}\\s*$/m', '', $sty) ?? $sty;
        $sty = preg_replace('/^\\\\ProvidesPackage\\{[^}]*\\}(?:\\[[^\\]]*\\])?\\s*$/m', '', $sty) ?? $sty;
        $sty = preg_replace('/^\\\\endinput\\s*$/m', '', $sty) ?? $sty;
        return "\\makeatletter\n" . $sty . "\n\\makeatother\n";
    }
}
