<?php

declare(strict_types=1);

namespace App\Services\TexBuilder;

/**
 * G20.0 — Sostituisce i placeholder `{{KEY}}` nei template TEX con i valori
 * provenienti da Selection + context istituto.
 *
 * Placeholder supportati:
 *   {{TEXCOMMON_DIR}}      → "texCommon" (zip) | "../../../../../texCommon" (vsc)
 *   {{GRIGLIE_DIR}}        → "../griglie" (zip) | "../../griglie" (vsc)
 *   {{ESERCIZI_FILE}}      → "esercizi_NOR" (senza .tex perche' \input lo aggiunge)
 *   {{TITOLO_VERIFICA}}    → titolo dell'item
 *   {{INDIRIZZO_CODE}}     → "sc"
 *   {{INDIRIZZO_LABEL}}    → "Scientifico"
 *   {{CLASSE_LABEL}}       → "3C"
 *   {{MATERIA_CODE}}       → "MAT"
 *   {{ANNO}}               → "2026"
 *   {{TEMPO_MINUTI}}       → "55"
 *   {{DOCENTE_NOME}}       → "Vittorio Pantaleo"
 *   {{ISTITUTO_NOME}}      → es. "I.I.S. di Esempio - Comune Esempio (XX)" (header_label || name)
 *   {{COPIE_NOR/SOL/DSA/DIS}} → numero copie
 *   {{COMPENSA_OPEN}}, {{COMPENSA_CLOSE}}: "" se compensa attivo, "%" altrimenti (commenta riga)
 */
final class PlaceholderResolver
{
    public function __construct(
        private readonly array $vars,
    ) {
    }

    public static function fromContext(array $context): self
    {
        return new self($context);
    }

    public function apply(string $tex): string
    {
        $out = $tex;
        foreach ($this->vars as $key => $value) {
            $needle = '{{' . $key . '}}';
            $out = str_replace($needle, (string)$value, $out);
        }
        return $out;
    }

    /** Helper: build path-prefix per `{{TEXCOMMON_DIR}}` / `{{GRIGLIE_DIR}}` per modalita'. */
    public static function pathPrefixes(string $mode): array
    {
        if ($mode === BuildResult::MODE_ZIP || $mode === BuildResult::MODE_FLAT) {
            // ZIP/FLAT layout: main_*.tex in versioni/, texCommon e griglie
            // a parent root. Paths relativi compatibili con BOTH:
            //   - flatten() inline-expansion (BuildResult::flatten() normalizza
            //     i ../ prefix prima del lookup nei files);
            //   - VPS /compile-bundle (S4.B.3): pdflatex risolve ../texCommon/X
            //     come sibling del versioni/ dir nel tmpdir.
            return [
                'TEXCOMMON_DIR' => '../texCommon',
                'GRIGLIE_DIR'   => '../griglie',
            ];
        }
        // VSC layout — main_*.tex sta in:
        //   {institute}/{ind}/{cls}/{mat}/verifiche/{titolo}/{version}/main_*.tex
        //                  6 directory levels sotto {institute}
        // Verso texCommon (a {institute} root): 6 livelli su + "texCommon"
        // Verso griglie (a {institute}/{ind}): 5 livelli su + "griglie"
        return [
            'TEXCOMMON_DIR' => '../../../../../../texCommon',
            'GRIGLIE_DIR'   => '../../../../../griglie',
        ];
    }
}
