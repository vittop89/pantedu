<?php

declare(strict_types=1);

namespace App\Services\TexBuilder;

/**
 * Renderer di tabelle / liste esercizio in LaTeX.
 * Dispatcher su `type` del problem:
 *   - Collect : enumerazione `\Alph*)` (A) B) C)) con punti + soluzione box
 *   - RMulti  : enumerazione `\arabic*})` (1) 2) 3)) — la tabella celle
 *               a/b/c/d viene resa dal Sanitizer dall'`<table class="fm-rm-table">`
 *               embedded nel `$item['html']` (nessun placeholder hardcoded).
 *   - VF      : tabularx con colonne Affermazione | Vero | Falso
 */
final class TableRenderer
{
    /**
     * G27.badge — opzionale: se passato, renderEnumerate prefigge `\badge{...}`
     * a ciascun item per la variante SOL. Se null, comportamento legacy
     * (nessun badge inline → quelli storici nei .tex restano backup).
     *
     * G27.dsa.scope — emitDsaMarks: emette i prefissi `(*F*) `/`(*GF*) ` solo
     * se true. Setting controllato dal kind nel TexBuilder: DSA/DIS → true,
     * NOR/SOL → false. Le marche sono utili solo nelle versioni per studenti
     * con bisogni speciali (DSA/disturbi); su NOR e SOL inquinano l'output.
     */
    public function __construct(
        private readonly ?BadgeRenderer $badges = null,
        private readonly bool $emitDsaMarks = false,
    ) {
    }

    /**
     * @param array{type?:string,items?:array<int,array<string,mixed>>} $problem
     */
    public function render(array $problem, bool $includeSol = false): string
    {
        $type  = (string)($problem['type'] ?? 'Collect');
        $items = (array)($problem['items'] ?? []);
        return match ($type) {
            'VF'     => $this->renderVF($items, $includeSol),
            // G27.dsa — RM passa flag isRm=true → renderSolutionBlock label
            // "Giustifica" invece di "Soluzione" (la cella RM non chiede una
            // soluzione vera, ma una giustificazione della scelta).
            'RMulti' => $this->renderEnumerate($items, $includeSol, '\\textbf{\\arabic*})', true),
            default  => $this->renderEnumerate($items, $includeSol, '\\textbf{\\Alph*)}', false),
        };
    }

    /**
     * Renderer enumerate generico (Collect / RMulti):
     *   `\begin{enumerate}[label=$labelTpl,leftmargin=1.5em]`
     *     `\item (Np) $text` + opzionale soluzione box / linea vuota
     *   `\end{enumerate}`
     *
     * Il `$text` può contenere `<table class="fm-rm-table">` che il Sanitizer
     * converte in `\begin{tabular}...`. depthOffset=1 perché siamo dentro
     * a `\begin{enumerate}` outer.
     *
     * @param array<int,array<string,mixed>> $items
     */
    private function renderEnumerate(array $items, bool $includeSol, string $labelTpl, bool $isRm = false): string
    {
        if (!$items) {
            return '';
        }
        $lines = ["\\begin{enumerate}[label={$labelTpl},leftmargin=1.5em]"];
        foreach ($items as $item) {
            // G27.badge — il badge inline nell'html del contract (span .fm-badge)
            // è SEMPRE rimosso quando l'item ha un badge strutturato: è una
            // sorgente legacy/duplicata (causava il DOPPIO badge specchiato). Il
            // badge "vero" (macro \badge) è info DOCENTE → emesso SOLO in SOL;
            // la stampa studente (NOR) NON deve avere fonte+badge.
            $hasBadge = is_array($item['badge'] ?? null);
            $html     = (string)($item['html'] ?? '');
            if ($hasBadge) {
                $html = BadgeRenderer::stripBadgeSpan($html);
            }
            $badge = ($includeSol && $this->badges !== null)
                ? $this->badges->render($item)
                : '';
            $text = Sanitizer::latexPassthrough($html, $includeSol, 1);
            if ($hasBadge) {
                $text = BadgeRenderer::stripLegacyInline($text);
            }
            $pt     = self::pt($item);
            $prefix = $badge !== '' ? "{$badge} " : '';
            // G27.dsa — marker F/GF item-level: prefigge "(*F*) "/"(*GF*) "
            // al testo, dopo il badge. Mark vuoto = nessun prefisso. Vedi
            // dsa-marks.js (client) + Selection::fromArray per il flow.
            // G27.dsa.scope — emesso SOLO se emitDsaMarks=true (kind DSA|DIS).
            // Su NOR/SOL i marker non devono comparire (richiesta utente:
            // i marker sono indicazioni operative per varianti BES, non per
            // versione standard ne' soluzionario).
            $mark = (string)($item['mark'] ?? '');
            $markPrefix = ($this->emitDsaMarks && ($mark === 'F' || $mark === 'GF'))
                ? "(*{$mark}*) "
                : '';
            $lines[] = "  \\item ({$pt} p) {$prefix}{$markPrefix}{$text}";
            $lines[] = self::renderItemSolution($item, $includeSol, $isRm);
        }
        $lines[] = '\\end{enumerate}';
        return implode("\n", array_filter($lines, static fn($l) => $l !== ''));
    }

    /**
     * Renderer della soluzione/spazio-soluzione per un singolo item.
     * - $includeSol=true + solution present → box "Soluzione" con content multilinea
     * - $includeSol=false + includeSolution=true → linea sottostante per scrivere a mano
     * - altrimenti → stringa vuota (rimossa da array_filter del caller)
     *
     * @param array<string,mixed> $item
     */
    private static function renderItemSolution(array $item, bool $includeSol, bool $isRm = false): string
    {
        if ($includeSol && !empty($item['solution'])) {
            $sol = Sanitizer::latexPassthrough((string)$item['solution'], true, 1);
            return self::renderSolutionBlock($sol, $isRm);
        }
        if (!$includeSol && !empty($item['includeSolution'])) {
            return '    \\\\\\rule{0.6\\linewidth}{0.4pt}';
        }
        return '';
    }

    /**
     * Blocco soluzione: etichetta in riquadro grigio centrato, contenuto
     * multi-line sotto. Usa `\fcolorbox{gray}{gray!10}{...}` con xcolor
     * (gia' nel preamble standard).
     *
     * G27.dsa — $isRm controlla l'etichetta del riquadro: per RM la cella
     * non chiede una soluzione vera ma una giustificazione della scelta
     * dell'opzione corretta → label "Giustifica" invece di "Soluzione".
     */
    private static function renderSolutionBlock(string $sol, bool $isRm = false): string
    {
        // Spezza i passaggi multipli: ogni `\n\n` diventa `\\` (line break LaTeX)
        // per render multi-riga. Single newline dentro lo stesso passaggio
        // viene preservata come spazio (TeX comprime whitespace).
        $solMultiline = (string)preg_replace('/\n{2,}/', "\\\\\n", $sol);
        $label = $isRm ? 'Giustifica' : 'Soluzione';

        return <<<TEX

    \\medskip
    \\begin{center}
        \\fcolorbox{gray!50}{gray!10}{\\textbf{{$label}}}
    \\end{center}
    \\nopagebreak
    {$solMultiline}
    \\medskip
TEX;
    }

    /**
     * VF: tabularx con colonne Affermazione | Vero | Falso. Soluzione (se
     * richiesta) appare come riga aggiuntiva sotto l'affermazione.
     *
     * @param array<int,array<string,mixed>> $items
     */
    private function renderVF(array $items, bool $includeSol): string
    {
        if (!$items) {
            return '';
        }
        // G27.vf.minipage — vedi commento storico: tabular p{} + minipage \hsize
        // per supportare nested env (tikz, enumerate, geogebra) nelle cell.
        // wrapCell + vspace(4pt) finale = padding basso visivo dentro la cella
        // (oltre a \arraystretch globale). Migliora respiro dopo l'ultima riga
        // dell'affermazione/soluzione, soprattutto con content multi-riga.
        $wrapCell = static function (string $cell): string {
            $trim = trim($cell);
            if ($trim === '') {
                return '';
            }
            return "\\begin{minipage}[t]{\\hsize}\n" . $trim . "\\vspace{4pt}\n\\end{minipage}";
        };
        // Sanitizer::latexPassthrough emette `\textbf{GIUSTIFICAZIONE}` come
        // separator. Split estrae affermazione (prima) + just block (dopo).
        // Ritorna 3 valori: [affermazione, just-content, hasMarker].
        // hasMarker=true anche se content è vuoto → "checkgiust" attivo a
        // livello problem ma item senza testo just personalizzato → emettere
        // comunque righe vuote per scrittura studente (porting legacy NOR).
        $splitJust = static function (string $html): array {
            $pos = strpos($html, '\\textbf{GIUSTIFICAZIONE}');
            if ($pos === false) {
                return [trim($html), '', false];
            }
            $aff = trim(substr($html, 0, $pos));
            $just = trim(substr($html, $pos));
            // Cleanup: rimuovi il label dal testo just (resta solo il content)
            $just = trim((string)preg_replace('/^\\\\textbf\{GIUSTIFICAZIONE\}\s*/', '', $just));
            return [$aff, $just, true];
        };
        // G27.vf.modulate (porting legacy checkgiust) — modulazione tabella
        // per item:
        //   - includeSol=true  → riga affermazione + (se just presente)
        //                        riga \multicolumn{4}{l}{\textit{Giust:} text}
        //   - includeSol=false → riga affermazione + (se just presente)
        //                        riga vuota tratteggiata per scrittura studente
        //                        (\cdashline). Se NO just: tabella compatta.
        // G27.vf.borders — tabella con bordi completi (|...|) + \hline tra
        // righe. Riproduce stile master `tabularx{|X|c|c|}` con griglia visibile.
        // G27.vf.padding — \arraystretch=1.4 (vertical padding) + \tabcolsep
        // 6pt (orizzontale) per più aria nelle celle. Wrappato in {} per
        // localizzare il setting senza side-effect su altre tabelle del doc.
        // G27.vf.solspan — riga soluzione si differenzia: \multicolumn{4} che
        // spans tutte le colonne + sfondo gray!10 (\rowcolor) per visibilità.
        $rows = [];
        foreach ($items as $i => $item) {
            $text = Sanitizer::latexPassthrough((string)($item['html'] ?? ''), $includeSol);
            [$aff, $just, $hasGiust] = $splitJust($text);
            // VF problem REQUIRES giust always (porting legacy: ContractRenderer
            // emette sempre `<strong class="fm-sol-label">GIUSTIFICAZIONE</strong>`
            // per VF item). Frontend `dom-block-extractor.extractItemHtml` però
            // usa RAW_SELECTOR restrictive che NON include `<strong>` → marker
            // perso prima di arrivare al backend. Assumiamo quindi default-true
            // per VF (= renderVF context): righe vuote scrittura sempre presenti
            // in NOR/DSA/DIS, testo "Giust:" sempre presente in SOL.
            $hasGiust = true;
            $affWrapped = $wrapCell($aff);
            // Etichetta affermazione: lettera minuscola (a), b), c)…) invece di
            // numero arabico. Cap a-z (26 affermazioni max — limite ragionevole).
            $label = chr(ord('a') + ($i % 26)) . ')';
            // V/F header dice già che colonne sono Vero|Falso → body cells
            // mostrano SOLO i \square (no V/F prefix ridondante).
            // $\square$ in math mode: \square è un comando math → fuori da $...$
            // dà "Missing $ inserted" e rompe il compile della tabella VF.
            $rows[] = "  $label & $affWrapped & \$\\square\$ & \$\\square\$ \\\\";
            $rows[] = "  \\hline";
            // SOL variante: UNA sola riga merged "Giustifica" (prefix riquadro
            // grigio stile RM `renderSolutionBlock`) + content = solution field
            // (che frontend extracter già contiene risposta V/F + giust text).
            // Niente riga "Sol:" + "Giust:" separate (era duplicato).
            // NOR/DSA/DIS: righe vuote tratteggiate per scrittura studente.
            if ($includeSol && !empty($item['solution'])) {
                $sol = Sanitizer::latexPassthrough((string)$item['solution'], true);
                $giustPrefix = '\\fcolorbox{gray!50}{gray!10}{\\textbf{Giustifica}}\\quad ';
                // \vspace*{1pt} (con asterisco) forza padding top anche all'inizio
                // del minipage box; \vspace senza * verrebbe scartato a inizio vbox.
                $giustWrapped = $wrapCell("\\vspace*{1pt}\n" . $giustPrefix . trim($sol));
                $rows[] = "  \\multicolumn{4}{|l|}{" . $giustWrapped . "} \\\\";
                $rows[] = "  \\hline";
            } elseif (!$includeSol && $hasGiust) {
                // 2 righe vuote (spazio scrittura) + tratteggio interno
                $rows[] = "  \\multicolumn{4}{|c|}{} \\\\";
                $rows[] = "  \\cdashline{1-4}";
                $rows[] = "  \\multicolumn{4}{|c|}{} \\\\";
                $rows[] = "  \\hline";
            }
        }
        $body = implode("\n", $rows);
        return <<<TEX
{\\renewcommand{\\arraystretch}{1.4}\\setlength{\\tabcolsep}{6pt}%
\\noindent\\begin{tabular}{|r|>{\\raggedright\\arraybackslash}p{0.78\\linewidth}|>{\\centering\\arraybackslash}p{0.04\\linewidth}|>{\\centering\\arraybackslash}p{0.04\\linewidth}|}
\\hline
\\textbf{\\#} & \\textbf{Affermazione} & \\textbf{V} & \\textbf{F} \\\\
\\hline
$body
\\end{tabular}}
TEX;
    }

    /**
     * @param array<string,mixed> $item
     */
    private static function pt(array $item): string
    {
        return number_format((float)($item['points'] ?? 1.0), 1, '.', '');
    }
}
