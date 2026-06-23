<?php

declare(strict_types=1);

namespace App\Services\GeoGebra;

use App\Services\TexCompile\SvgToPdfClient;
use RuntimeException;
use Throwable;

/**
 * G22.S15.bis Fase 4 — Pre-processor TeX per blocchi GeoGebra.
 *
 * Cerca nel sorgente TeX un pattern marker:
 *
 *     \fmgeogebra{base64-svg}{label-opzionale}
 *
 * Per ogni match:
 *   1. Decodifica il base64 in stringa SVG
 *   2. POST /svg-to-pdf al VPS → PDF binario vettoriale
 *   3. Salva il PDF come `geogebra/N.pdf` nel bundle files (in memoria)
 *   4. Sostituisce nel TeX il marker con `\includegraphics{geogebra/N.pdf}`
 *   5. Aggiunge `\graphicspath{{geogebra/}}` se non presente nel master
 *
 * Output: nuovo TeX + lista di nuovi files PDF da appendere al bundle.
 *
 * Pronto per essere wirato in VerificaCompileJobService::execute() PRIMA
 * del client->compileBundle() / compile(). Il wiring richiede l'aggiunta
 * di un bottone GeoGebra nell'editor TeX delle verifiche che inserisca
 * il marker `\fmgeogebra{...}{...}`.
 */
final class GeoGebraTexPreProcessor
{
    // Pattern: \fmgeogebra[opzioni]{base64-svg}{label}
    //   opzioni: es. "width=8cm" o "width=0.8\linewidth" (passato as-is a \includegraphics)
    private const MARKER_RE = '/\\\\fmgeogebra(?:\[([^\]]*)\])?\{([^}]*)\}\{([^}]*)\}/';

    public function __construct(
        private readonly SvgToPdfClient $svgClient,
    ) {
    }

    /**
     * Pre-processa un singolo file TeX. Ritorna il nuovo content + i file
     * PDF da aggiungere al bundle (paths relativi).
     *
     * @return array{content:string, generatedFiles:array<string,string>}
     *   generatedFiles: map ['geogebra/1.pdf' => '<binary PDF>']
     */
    public function processSingle(string $texSource, string $docId = 'pre'): array
    {
        $generated = [];
        $idx = 0;

        $newContent = preg_replace_callback(
            self::MARKER_RE,
            function (array $m) use (&$generated, &$idx, $docId) {
                $idx++;
                // $m[1] = optional width (es. "width=8cm"), $m[2] = base64 svg, $m[3] = label
                $optArg = trim($m[1] ?? '');
                $svgB64 = trim($m[2] ?? '');
                if ($svgB64 === '') {
                    return '\\textbf{[GeoGebra: SVG mancante]}';
                }
                $svgRaw = base64_decode($svgB64, true);
                if ($svgRaw === false || $svgRaw === '') {
                    return '\\textbf{[GeoGebra: base64 invalido]}';
                }
                try {
                    $r = $this->svgClient->convert($svgRaw, "{$docId}-ggb-{$idx}");
                } catch (Throwable $e) {
                    return '\\textbf{[GeoGebra: errore conversione: ' . self::escTexComment($e->getMessage()) . ']}';
                }
                if (!$r['ok'] || $r['pdf'] === null) {
                    return '\\textbf{[GeoGebra: rsvg-convert fallito: ' . self::escTexComment((string)($r['log'] ?? '')) . ']}';
                }
                $relPath = "geogebra/{$idx}.pdf";
                $generated[$relPath] = (string)$r['pdf'];
                // Costruisce options per \includegraphics:
                //   - se utente ha specificato `[width=...]` lo usa + keepaspectratio
                //   - altrimenti default `width=\linewidth,keepaspectratio`
                $imgOpts = $optArg !== ''
                    ? $optArg . ',keepaspectratio'
                    : 'width=\\linewidth,keepaspectratio';
                return '\\includegraphics[' . $imgOpts . ']{' . "geogebra/{$idx}" . '}';
            },
            $texSource,
        );

        if ($newContent === null) {
            // Errore nel regex (raro)
            return ['content' => $texSource, 'generatedFiles' => []];
        }

        // Inietta `\graphicspath{{geogebra/}}` se ci sono PDF generati e
        // non c'è già un graphicspath che include la cartella geogebra.
        if (!empty($generated) && !str_contains($newContent, 'graphicspath') && !preg_match('/{geogebra\/?}/', $newContent)) {
            // Inserisci dopo \usepackage{graphicx} o dopo \documentclass
            $newContent = self::injectGraphicsPath($newContent);
        }

        return ['content' => $newContent, 'generatedFiles' => $generated];
    }

    /**
     * Pre-processa un bundle multi-file. Modifica i contenuti dei file e
     * aggiunge i PDF generati al bundle (key = path relativo).
     *
     * @param list<array{path:string, content:string}> $files
     * @return list<array{path:string, content:string}>  bundle modificato
     */
    public function processBundle(array $files, string $docId = 'pre'): array
    {
        $out = [];
        $allGenerated = [];
        // Dedup: stesso SVG (stesso hash) nella stessa cartella sibling
        // riusa lo stesso PDF. Map: "geoDir|sha256" → idx allocato.
        // Necessario perchè stesso \fmgeogebra può apparire in NOR/SOL/DSA.tex
        // dentro versioni/ — un solo PDF in versioni/geogebra/1.pdf.
        $svgCache = [];
        $globalIdx = 0;

        foreach ($files as $f) {
            if (!isset($f['path'], $f['content'])) {
                $out[] = $f;
                continue;
            }
            $path = (string)$f['path'];
            $content = (string)$f['content'];
            // Skip files che non contengono il marker (fast path)
            if (strpos($content, '\\fmgeogebra') === false) {
                $out[] = $f;
                continue;
            }
            // G22.S15.bis Fase 4 — i PDF vanno salvati in directory di
            // sibling del file TeX che contiene il marker, perchè pdflatex
            // risolve `\includegraphics{geogebra/N}` relativo al main TeX.
            // Es: file = "versioni/esercizi_NOR.tex" → PDF in
            // "versioni/geogebra/N.pdf" → main "versioni/main_NOR.tex" → da
            // versioni/ cerca "geogebra/N.pdf" = MATCH.
            $dir = dirname($path);
            $geoDir = ($dir === '.' || $dir === '') ? 'geogebra' : $dir . '/geogebra';

            // Process incrementando un counter globale tra file
            $localIdx = 0;
            $newContent = preg_replace_callback(
                self::MARKER_RE,
                function (array $m) use (&$allGenerated, &$svgCache, &$globalIdx, &$localIdx, $docId, $geoDir) {
                    $localIdx++;
                    $optArg = trim($m[1] ?? '');
                    $svgB64 = trim($m[2] ?? '');
                    if ($svgB64 === '') {
                        return '\\textbf{[GeoGebra: SVG mancante]}';
                    }

                    // Dedup per geoDir + hash del base64. Se già convertito
                    // riusa l'idx esistente senza chiamare il VPS.
                    $cacheKey = $geoDir . '|' . hash('sha256', $svgB64);
                    if (isset($svgCache[$cacheKey])) {
                        $idx = $svgCache[$cacheKey];
                        $imgOpts = $optArg !== ''
                            ? $optArg . ',keepaspectratio'
                            : 'width=\\linewidth,keepaspectratio';
                        return '\\includegraphics[' . $imgOpts . ']{' . "geogebra/{$idx}" . '}';
                    }

                    $svgRaw = base64_decode($svgB64, true);
                    if ($svgRaw === false || $svgRaw === '') {
                        return '\\textbf{[GeoGebra: base64 invalido]}';
                    }
                    $globalIdx++;
                    $idx = $globalIdx;
                    try {
                        $r = $this->svgClient->convert($svgRaw, "{$docId}-ggb-{$idx}");
                    } catch (Throwable $e) {
                        return '\\textbf{[GeoGebra: errore: ' . self::escTexComment($e->getMessage()) . ']}';
                    }
                    if (!$r['ok'] || $r['pdf'] === null) {
                        return '\\textbf{[GeoGebra: rsvg fallito]}';
                    }
                    $relPath = "{$geoDir}/{$idx}.pdf";
                    $allGenerated[$relPath] = (string)$r['pdf'];
                    $svgCache[$cacheKey] = $idx;
                    $imgOpts = $optArg !== ''
                        ? $optArg . ',keepaspectratio'
                        : 'width=\\linewidth,keepaspectratio';
                    // \includegraphics{geogebra/N} — sempre relativo al file
                    // TeX corrente (pdflatex risolve da working dir = main dir).
                    return '\\includegraphics[' . $imgOpts . ']{' . "geogebra/{$idx}" . '}';
                },
                $content,
            );
            if ($newContent === null) {
                $out[] = $f;
                continue;
            }
            // Iniezione graphicspath se questo è il main file
            if ($localIdx > 0 && self::looksLikeMainTex($path) && !str_contains($newContent, 'graphicspath')) {
                $newContent = self::injectGraphicsPath($newContent);
            }
            $out[] = ['path' => $path, 'content' => $newContent];
        }

        // Aggiungi i PDF generati al bundle (path già normalizzati con dir)
        foreach ($allGenerated as $relPath => $pdfBin) {
            $out[] = ['path' => $relPath, 'content' => $pdfBin];
        }
        return $out;
    }

    private static function looksLikeMainTex(string $path): bool
    {
        $base = strtolower(basename($path));
        return $base === 'main.tex' || str_starts_with($base, 'main_');
    }

    /** Inserisce \graphicspath{{geogebra/}{../geogebra/}{../../geogebra/}}
     *  dopo il primo \usepackage{graphicx} o dopo \documentclass.
     *
     *  Multipath necessario perchè il main TeX può essere in:
     *    - bundle root (main.tex)            → cerca `geogebra/`
     *    - versioni/main_X.tex               → cerca `../geogebra/` (parent dir)
     *    - sub-sub-dir (raro)                → cerca `../../geogebra/`
     *  pdflatex cerca i path in ordine; il primo che trova vince. */
    private static function injectGraphicsPath(string $tex): string
    {
        $multiPath = '\\graphicspath{{geogebra/}{../geogebra/}{../../geogebra/}}';
        if (preg_match('/(\\\\usepackage(?:\[[^\]]*\])?\{graphicx\})/', $tex, $m, PREG_OFFSET_CAPTURE)) {
            $insertAt = $m[1][1] + strlen($m[1][0]);
            return substr($tex, 0, $insertAt) . "\n" . $multiPath . substr($tex, $insertAt);
        }
        if (preg_match('/(\\\\documentclass(?:\[[^\]]*\])?\{[^}]+\})/', $tex, $m, PREG_OFFSET_CAPTURE)) {
            $insertAt = $m[1][1] + strlen($m[1][0]);
            return substr($tex, 0, $insertAt)
                . "\n\\usepackage{graphicx}\n" . $multiPath
                . substr($tex, $insertAt);
        }
        return $tex;
    }

    private static function escTexComment(string $s): string
    {
        $s = preg_replace('/[^\x20-\x7E]+/', ' ', $s) ?? $s;
        return substr($s, 0, 80);
    }
}
