<?php

declare(strict_types=1);

namespace App\Services\PdfImport;

use App\Core\Config;
use App\Repositories\PdfImportSessionRepository;
use App\Services\PdfImport\Provider\ProviderRouter;
use App\Services\PdfImport\Session\SessionStorage;
use App\Services\TexCompile\TikzRenderClient;

/**
 * Phase PDF-Import — generazione soluzioni AI (+ figure TikZ) per le row.
 *
 * Per ogni esercizio senza soluzione, chiede al modello (testo) una soluzione
 * passo-passo in LaTeX; per gli esercizi con figura, delega a FigureExtractor
 * la generazione del TikZ (+ preview SVG se il microservizio è configurato).
 *
 * Sicurezza: il testo dell'esercizio (derivato dal PDF) è incapsulato via
 * PromptGuard (LLM01) e già mascherato da PiiMasker in estrazione (LLM02). Il
 * budget token è verificato a ogni iterazione (LLM10).
 */
final class SolutionGenerator
{
    // Prompt per CATEGORIA (legacy solution_dispatcher → algebra/fisica/teoria/vf).
    // La categoria è dedotta con un'euristica (nessuna chiamata LLM extra).
    public const SYS_ALGEBRA = <<<TXT
Sei un tutor di matematica. Risolvi l'esercizio ALGEBRICO passo per passo, conciso
e corretto (equazioni/disequazioni/espressioni). Indica le condizioni di esistenza
quando servono. Usa LaTeX: \\(...\\) inline, \\[...\\] display. Decimali con la
virgola. Rispondi SOLO con la soluzione, senza ripetere la consegna né markdown.
TXT;

    public const SYS_PHYSICS = <<<TXT
Sei un tutor di fisica. Risolvi il problema: elenca i DATI con unità di misura,
scrivi la FORMULA, sostituisci e calcola, dai il RISULTATO con unità. Conciso e
corretto. Usa LaTeX: \\(...\\) inline, \\[...\\] display; unità in \\text{...}.
Rispondi SOLO con la soluzione, senza ripetere la consegna né markdown.
TXT;

    public const SYS_THEORY = <<<TXT
Sei un tutor di matematica e fisica. Rispondi alla domanda TEORICA in modo conciso
e corretto (definizione, proprietà o dimostrazione breve). Usa LaTeX \\(...\\) dove
serve. Rispondi SOLO con la risposta, senza ripetere la consegna né markdown.
TXT;

    public const SYS_VF = <<<TXT
Sei un tutor di matematica e fisica. Per CIASCUNA affermazione Vero/Falso indica se
è VERA o FALSA con una motivazione CONCISA (1-2 righe, formula > parole). Usa LaTeX
\\(...\\). Niente simboli checkbox. Rispondi SOLO con le risposte, senza markdown.
ALLA FINE, su una riga a parte, scrivi esattamente:
RISPOSTE: seguito da V o F separati da virgola, UNA per affermazione nell'ordine dato
(es. per 4 affermazioni: RISPOSTE: F,V,F,V).
TXT;

    // ── collect_solver: prompt DEDICATI per sotto-tipo algebrico (legacy). ──
    public const SYS_FRATTA = <<<TXT
Sei un tutor di matematica. Risolvi l'equazione/disequazione FRATTA passo per passo.
1) Scrivi le CONDIZIONI DI ESISTENZA (tutti i denominatori ≠ 0).
2) Riduci allo stesso denominatore (m.c.m.) e porta a forma normale.
3) Risolvi l'equazione/disequazione al numeratore (o studia il segno di N/D).
4) Confronta con le C.E. e SCARTA le soluzioni non accettabili.
Conciso e corretto. Rispondi SOLO con la soluzione, senza ripetere la consegna.
TXT;

    public const SYS_IRRAZIONALE = <<<TXT
Sei un tutor di matematica. Risolvi l'equazione/disequazione IRRAZIONALE (con radici).
1) Imposta le CONDIZIONI DI ESISTENZA (radicandi di indice pari ≥ 0) e, se serve, la
   condizione di concordanza dei segni.
2) Isola la radice ed eleva alla potenza opportuna (attento ai casi per le disequazioni).
3) Risolvi l'equazione/disequazione razionale ottenuta.
4) VERIFICA le soluzioni nelle C.E. e scarta quelle non accettabili.
Conciso e corretto. Rispondi SOLO con la soluzione, senza ripetere la consegna.
TXT;

    public const SYS_VALORE_ASSOLUTO = <<<TXT
Sei un tutor di matematica. Risolvi l'equazione/disequazione con VALORE ASSOLUTO.
1) Individua il/i punto/i in cui l'argomento del modulo cambia segno.
2) Distingui i CASI in base al segno dell'argomento, togliendo il modulo con il segno
   corretto in ciascun intervallo.
3) Risolvi ogni caso e tieni solo le soluzioni compatibili con l'intervallo del caso.
4) Unisci le soluzioni dei vari casi.
Conciso e corretto. Rispondi SOLO con la soluzione, senza ripetere la consegna.
TXT;

    public const SYS_SISTEMA = <<<TXT
Sei un tutor di matematica. Risolvi il SISTEMA di equazioni/disequazioni.
Scegli il metodo adatto (sostituzione, riduzione/confronto, o grafico). Mostra i
passaggi chiave e indica chiaramente la SOLUZIONE come coppia/e ordinata/e o come
insieme. Conciso e corretto. Rispondi SOLO con la soluzione, senza ripetere la consegna.
TXT;

    public const SYS_DISEQUAZIONE = <<<TXT
Sei un tutor di matematica. Risolvi la DISEQUAZIONE.
Porta a forma normale, STUDIA IL SEGNO (di prodotto/quoziente o del trinomio) e
ricava la soluzione come INTERVALLO o unione di intervalli (usa parentesi/[] corrette).
Indica eventuali condizioni di esistenza. Conciso e corretto. Rispondi SOLO con la
soluzione, senza ripetere la consegna.
TXT;

    public const SYS_ESPONENZIALE = <<<TXT
Sei un tutor di matematica. Risolvi l'equazione/disequazione ESPONENZIALE.
Se possibile riconduci a STESSA BASE; altrimenti applica il logaritmo. Per le
disequazioni ricorda di INVERTIRE il verso se la base è 0<a<1. Rispetta il dominio.
Conciso e corretto. Rispondi SOLO con la soluzione, senza ripetere la consegna.
TXT;

    public const SYS_LOGARITMICA = <<<TXT
Sei un tutor di matematica. Risolvi l'equazione/disequazione LOGARITMICA.
1) Imposta le CONDIZIONI DI ESISTENZA (argomenti dei logaritmi > 0; base valida).
2) Usa le proprietà dei logaritmi per ridurre a un solo logaritmo o a stessa base.
3) Risolvi e VERIFICA le soluzioni nelle C.E.
Conciso e corretto. Rispondi SOLO con la soluzione, senza ripetere la consegna.
TXT;

    /**
     * Genera soluzioni per al più $maxRows esercizi (default da config), così
     * ogni richiesta web resta sotto fastcgi_read_timeout. Il client richiama
     * finché `remaining` > 0.
     *
     * @return array{updated:int, remaining:int}
     */
    public function runForSession(
        int $sessionId,
        PdfImportSessionRepository $repo,
        ProviderRouter $router,
        SessionStorage $storage,
        int $maxRows = 0
    ): array {
        $session = $repo->find($sessionId);
        if ($session === null) {
            throw new \RuntimeException('session_not_found');
        }
        $prefix = (string)$session['storage_prefix'];
        $teacherId = (int)$session['teacher_id'];
        $rows = $storage->getJson($prefix, 'contracts.json', $teacherId);
        if (!is_array($rows) || $rows === []) {
            throw new \RuntimeException('contracts_not_ready');
        }

        $cap = $maxRows > 0
            ? $maxRows
            : max(1, (int)\App\Core\Config::get('pdf_import.solutions_per_request', 2));

        $client    = $router->operationClient('solutions', (string)$session['provider']);
        $figures   = new FigureExtractor($this->buildTikzClient());

        $updated = 0;
        $processed = 0;
        foreach ($rows as &$row) {
            if ($processed >= $cap) {
                break;
            }
            if (!is_array($row)) {
                continue;
            }
            $payload = (array)($row['payload'] ?? []);

            $needSolution = trim((string)($payload['solution'] ?? '')) === '' && self::rowHasContent($payload);
            $needFigure = !empty($payload['has_figure']) && empty($payload['tikz']);

            if (!$needSolution && !$needFigure) {
                continue;
            }
            $processed++;
            $router->assertBudget($teacherId);

            if ($needSolution) {
                $cat = self::classifyCategory($row);
                $num = (string)($row['number'] ?? '?');
                $t0 = microtime(true);
                try {
                    $res = $client->complete(self::systemFor($cat, $row), $this->solutionPrompt($row));
                } catch (\Throwable $e) {
                    LlmAuditLog::record($storage, $prefix, $teacherId, [
                        'op' => "soluzione es. $num ($cat)", 'status' => 'errore',
                        'ms' => LlmAuditLog::ms($t0),
                        'error' => mb_substr($e->getMessage(), 0, 160),
                    ]);
                    throw $e;
                }
                $sol = self::stripMarkdown(PiiMasker::mask((string)($res['text'] ?? '')));
                // Auto-correzione V/F: dal testo della soluzione ricava le risposte
                // e aggiorna i badge delle affermazioni (prima erano tutte 'V' di default).
                if ($cat === 'vf' && $sol !== '') {
                    $stmts = (array)($payload['statements'] ?? []);
                    if ($stmts !== []) {
                        [$sol, $answers] = self::extractVfAnswers($sol, count($stmts));
                        $resolved = 0;
                        foreach ($stmts as $i => $st) {
                            if (is_array($st) && ($answers[$i] ?? null) !== null) {
                                $stmts[$i]['answer'] = $answers[$i];
                                $resolved++;
                            }
                        }
                        $payload['statements'] = $stmts;
                        // Se TUTTE le affermazioni hanno una risposta, togli il flag
                        // di review "V/F?" (vf_answers_unknown).
                        if ($resolved === count($stmts) && isset($row['flags']) && is_array($row['flags'])) {
                            $row['flags'] = array_values(array_filter($row['flags'], static fn($f) => $f !== 'vf_answers_unknown'));
                        }
                    }
                }
                if ($sol !== '') {
                    $payload['solution'] = $sol;
                    $updated++;
                }
                $repo->addTokens($sessionId, (int)($res['tokens_in'] ?? 0), (int)($res['tokens_out'] ?? 0));
                LlmAuditLog::record($storage, $prefix, $teacherId, [
                    'op' => "soluzione es. $num ($cat)", 'status' => $sol !== '' ? 'ok' : 'vuota',
                    'ms' => LlmAuditLog::ms($t0),
                    'tokens_in' => (int)($res['tokens_in'] ?? 0), 'tokens_out' => (int)($res['tokens_out'] ?? 0),
                ]);
            }

            if ($needFigure) {
                $fig = $figures->generate($client, $row);
                $payload['tikz'] = $fig['tikz'];
                if (!empty($fig['svg'])) {
                    $payload['figure_svg'] = $fig['svg'];
                }
                $repo->addTokens($sessionId, (int)$fig['tokens_in'], (int)$fig['tokens_out']);
                $updated++;
            }

            $row['payload'] = $payload;
        }
        unset($row);

        $storage->putJson($prefix, 'contracts.json', array_values($rows), $teacherId);

        // Quante righe restano ancora SENZA soluzione (o con figura da generare)?
        $remaining = 0;
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $pl = (array)($r['payload'] ?? []);
            $needSol = trim((string)($pl['solution'] ?? '')) === '' && self::rowHasContent($pl);
            $needFig = !empty($pl['has_figure']) && empty($pl['tikz']);
            if ($needSol || $needFig) {
                $remaining++;
            }
        }
        return ['updated' => $updated, 'remaining' => $remaining];
    }

    /**
     * Categoria solver (euristica, no LLM): vf | physics | theory | algebra.
     * Allinea il prompt al tipo di esercizio (legacy solution_dispatcher).
     */
    public static function classifyCategory(array $row): string
    {
        if (in_array((string)($row['type'] ?? ''), ['VF', 'RM_VF'], true)) {
            return 'vf';
        }
        $p = (array)($row['payload'] ?? []);
        $t = mb_strtolower((string)($p['shared_instruction'] ?? '') . ' ' . (string)($p['question'] ?? ''));

        // Fisica: unità di misura tipiche o termini fisici inequivocabili.
        $physics = '/(\bm\/s\b|\bkm\/h\b|\bkg\b|\b°c\b|\bnewton\b|\bjoule\b|\bwatt\b|\bhz\b|'
            . 'velocit|accelerazion|\bmassa\b|\bforza\b|energia|potenza|attrito|gravit|'
            . 'densit|pressione|corrente\s+elettr|circuito|caduta\s+(libera|dei gravi)|proiettile)/u';
        if (preg_match($physics, $t)) {
            return 'physics';
        }
        // Teoria: chiede di dimostrare/spiegare/definire.
        $theory = '/\b(dimostra(re)?|spiega(re)?|perch[ée]|definisci|definizione|enuncia(re)?|'
            . 'giustifica(re)?|motiva(re)?|che\s+cosa\s+si\s+intende)\b/u';
        if (preg_match($theory, $t)) {
            return 'theory';
        }
        return 'algebra';
    }

    // Regole di formattazione LaTeX (adattate dal legacy) — appese a ogni prompt
    // soluzione. pantedu carica gli extension MathJax enclose/cancel/color/
    // mathtools/physics, quindi \dfrac \text \fcolorbox ^{\circ} rendono bene.
    private const LATEX_RULES = <<<'TXT'

REGOLE FORMATTAZIONE (importante):
- Formula > prosa: nelle formule niente frasi tipo "Calcoliamo"/"Quindi"; usa
  \(...\) per le formule inline e \[...\] per quelle in evidenza.
- Decimali con la VIRGOLA: 3{,}14 (mai 3.14).
- Unità di misura in \text{}: \text{ m}, \text{ kg}, \text{ s}; gradi: ^{\circ}
  (es. \(38\,^{\circ}\text{C}\)).
- Frazioni con \dfrac (in linea) e \tfrac (in esponenti/pedici/indici di radice).
- Evidenzia il RISULTATO finale così (dentro \(...\)):
  \(\fcolorbox{red}{yellow}{$\color{black}VALORE\,\text{UNITÀ}$}\)
TXT;

    // pantedu rende LaTeX (\(...\)), NON markdown → i marker vanno tolti, altrimenti
    // si vedono letterali (**, #, ---). NB: niente conversione a \textbf{} perché
    // fuori da math non verrebbe renderizzato.
    private const NO_MARKDOWN = "\n\nIMPORTANTE: NON usare markdown (niente **, __, #, ###, --- o elenchi con *). Solo testo semplice; le formule SEMPRE tra \\(...\\) o \\[...\\]. Per enfatizzare usa le MAIUSCOLE.";

    /**
     * Ricava le risposte V/F dalla soluzione e RIMUOVE la riga "RISPOSTE:" dal
     * testo mostrato. Primario: riga "RISPOSTE: V,F,…". Fallback: occorrenze
     * "Affermazione N: VERA/FALSA". Ritorna [soluzione_pulita, list<'V'|'F'|null>].
     *
     * @return array{0:string,1:array<int,?string>}
     */
    public static function extractVfAnswers(string $sol, int $count): array
    {
        $answers = array_fill(0, $count, null);

        // 1) Riga esplicita "RISPOSTE: V,F,V,F" (preferita) → poi la togliamo.
        if (preg_match('/^[ \t]*rispost[ae]\s*[:=]\s*(.+)$/imu', $sol, $m)) {
            if (preg_match_all('/[VF]/iu', $m[1], $mm)) {
                foreach ($mm[0] as $i => $ltr) {
                    if ($i < $count) {
                        $answers[$i] = (mb_strtoupper($ltr) === 'F') ? 'F' : 'V';
                    }
                }
            }
            $sol = trim((string)preg_replace('/^[ \t]*rispost[ae]\s*[:=].*$/imu', '', $sol));
        }

        // 2) Fallback: "Affermazione N: VERA/FALSA" (riempie solo i buchi).
        if (preg_match_all('/affermazion[ei]\s*(?:n[.°]?\s*)?(\d+)\s*[:\-–)\.]*\s*(ver[oaie]|fals[oaie])/iu', $sol, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $g) {
                $idx = (int)$g[1] - 1;
                if ($idx >= 0 && $idx < $count && $answers[$idx] === null) {
                    $answers[$idx] = (mb_stripos($g[2], 'ver') === 0) ? 'V' : 'F';
                }
            }
        }
        return [$sol, $answers];
    }

    /** True se la riga ha contenuto da risolvere (anche con 'question' vuoto). */
    private static function rowHasContent(array $payload): bool
    {
        return trim((string)($payload['question'] ?? '')) !== ''
            || trim((string)($payload['shared_instruction'] ?? '')) !== ''
            || !empty($payload['statements'])
            || !empty($payload['options'])
            || !empty($payload['points']);
    }

    /** Rimuove i marker markdown lasciando il testo (pantedu non li renderizza). */
    public static function stripMarkdown(string $s): string
    {
        $s = preg_replace('/\*\*(.+?)\*\*/su', '$1', $s);       // **grassetto** → testo
        $s = preg_replace('/(?<![\w\\\\])__(.+?)__(?![\w])/su', '$1', $s);
        $s = preg_replace('/^[ \t]{0,3}#{1,6}[ \t]*/mu', '', $s); // # heading → via il marker
        $s = preg_replace('/^[ \t]*-{3,}[ \t]*$/mu', '', $s);   // --- riga orizzontale → via
        $s = preg_replace('/^[ \t]*\*[ \t]+/mu', '– ', $s);     // bullet "* " → "– "
        return trim((string)$s);
    }

    /** Sotto-tipi algebrici con prompt solver dedicato (collect_solver). */
    public const ALGEBRA_SUBTYPES = ['fratta', 'irrazionale', 'valore_assoluto', 'sistema', 'disequazione', 'esponenziale', 'logaritmica'];

    /** Rileva il sotto-tipo algebrico dal testo (euristica, no LLM). */
    public static function classifySubtype(string $text): string
    {
        $t = mb_strtolower($text);
        // Ordine: i più specifici prima.
        if (preg_match('/\bvalor[ei]\s+assolut|\|[^|]{1,40}\|/u', $t)) {
            return 'valore_assoluto';
        }
        if (preg_match('/\bsistema\b|\\\\begin\{cases\}/u', $t)) {
            return 'sistema';
        }
        if (preg_match('/\blogaritm|\\\\log|\\\\ln|\blog\s*[\(_]|\bln\s*\(/u', $t)) {
            return 'logaritmica';
        }
        if (preg_match('/\besponenzial|[0-9a-z]\^\{?\s*x|e\^/u', $t)) {
            return 'esponenziale';
        }
        if (preg_match('/\birrazional|\\\\sqrt|radice|sotto\s+radice|\\\\sqrt\[/u', $t)) {
            return 'irrazionale';
        }
        if (preg_match('/\bfratt|denominator/u', $t)) {
            return 'fratta';
        }
        if (preg_match('/\bdisequazion|[<>]=?|\\\\leq|\\\\geq/u', $t)) {
            return 'disequazione';
        }
        return '';
    }

    private static function systemFor(string $cat, array $row = []): string
    {
        // collect_solver: per gli esercizi "algebra" individua il SOTTO-TIPO e usa
        // il prompt solver DEDICATO (fratta/irrazionale/…); fallback algebra.
        $key = match ($cat) {
            'vf'      => 'solutions_vf',
            'physics' => 'solutions_physics',
            'theory'  => 'solutions_theory',
            default   => 'solutions_algebra',
        };
        if ($cat === 'algebra' && $row !== []) {
            $p = (array)($row['payload'] ?? []);
            $sub = self::classifySubtype((string)($p['shared_instruction'] ?? '') . ' ' . (string)($p['question'] ?? ''));
            if ($sub !== '' && in_array($sub, self::ALGEBRA_SUBTYPES, true)) {
                $key = 'solutions_' . $sub;
            }
        }
        // VF/teoria: niente regole problemi (no risultato numerico evidenziato).
        $rules = in_array($cat, ['algebra', 'physics'], true) ? self::LATEX_RULES : '';
        return OperationPrompts::resolve($key) . $rules . self::NO_MARKDOWN;
    }

    private function solutionPrompt(array $row): string
    {
        $payload = (array)($row['payload'] ?? []);
        $type = (string)($row['type'] ?? 'Collect');
        $parts = [];
        if (($payload['shared_instruction'] ?? '') !== '') {
            $parts[] = 'Consegna: ' . $payload['shared_instruction'];
        }
        $parts[] = 'Testo: ' . (string)($payload['question'] ?? '');

        if ($type === 'RM') {
            foreach ((array)($payload['options'] ?? []) as $o) {
                $parts[] = '(' . ($o['letter'] ?? '') . ') ' . ($o['text'] ?? '');
            }
            $parts[] = 'Indica l\'opzione corretta e spiega perché.';
        } elseif ($type === 'VF') {
            foreach ((array)($payload['statements'] ?? []) as $s) {
                $parts[] = '- ' . ($s['text'] ?? '');
            }
            $parts[] = 'Per ciascuna affermazione indica Vero/Falso con motivazione.';
        } else {
            foreach ((array)($payload['points'] ?? []) as $p) {
                $parts[] = '(' . ($p['letter'] ?? '') . ') ' . ($p['text'] ?? '');
            }
        }
        // Il sotto-tipo è gestito dal PROMPT SOLVER DEDICATO (systemFor), non qui.
        return PromptGuard::fence(implode("\n", $parts)) . "\n\nFornisci la soluzione.";
    }

    private function buildTikzClient(): ?TikzRenderClient
    {
        $endpoint = (string)Config::get('tex_compile.endpoint', '');
        $secret   = (string)Config::get('tex_compile.secret', '');
        if ($endpoint === '' || $secret === '') {
            return null;
        }
        return new TikzRenderClient(
            rtrim($endpoint, '/'),
            $secret,
            (int)Config::get('tex_compile.tikz_render.timeout', 25),
            (string)Config::get('tex_compile.ca_bundle', ''),
        );
    }
}
