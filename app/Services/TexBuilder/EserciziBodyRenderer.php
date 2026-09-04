<?php

declare(strict_types=1);

namespace App\Services\TexBuilder;

/**
 * G20.0 — Renderer del corpo "problemi_VARIANT.tex": SOLO `\item` enumerate
 * con i quesiti del docente. Niente preambolo, niente \begin/\end{document}
 * (gestiti da main_*.tex via \input).
 *
 * Riusa logica di TableRenderer (Collect/RMulti/VF) ma produce file standalone
 * pronto per `\input{esercizi_NOR}` dal main.
 */
final class EserciziBodyRenderer
{
    /**
     * G27.badge — `$tables` puo' essere costruito con `BadgeRenderer` per
     * abilitare l'emissione del prefisso `\badge{...}` su ogni \item (variante
     * SOL). Il preambolo `\definefonte{...}` per le fonti USATE e' generato
     * fuori da qui (TexBuilder genera `versioni/fonti_SOL.tex` separato che
     * `main_SOL.tex` `\input`a) — questo renderer si occupa solo del body.
     */
    public function __construct(
        private readonly TableRenderer $tables = new TableRenderer(),
    ) {
    }

    /**
     * Genera il body problemi (intero file `problemi_VARIANT.tex`).
     * Ogni problema ha header "N) testo" + lista enumerate items con
     * eventuale soluzione.
     *
     * @param Selection $sel
     * @param bool $isSolVariant true per SOL (include soluzioni inline + badge)
     */
    public function render(Selection $sel, bool $isSolVariant): string
    {
        if (!$sel->problems) {
            return "\\noindent Nessun esercizio selezionato.\n";
        }
        $blocks = [];
        foreach ($sel->problems as $i => $p) {
            $n = (int)($p['position'] ?? ($i + 1));
            if ($n <= 0) {
                $n = $i + 1;
            }
            $text  = Sanitizer::latexPassthrough((string)$p['text'], $isSolVariant);
            $items = $this->tables->render($p, $isSolVariant);
            $blocks[] = "\\noindent\\textbf{{$n})} {$text}\n\n{$items}";
        }
        $body = implode("\n\n\\bigskip\n\n", $blocks);
        // G20.0 — header commento per orientamento docente
        $header = "% esercizi_*.tex generato da TexBuilder.\n"
                . "% Modificare il sorgente del docente; i quesiti vengono\n"
                . "% riemessi in fase di re-save.\n";
        return $header . "\n" . $body . "\n";
    }
}
