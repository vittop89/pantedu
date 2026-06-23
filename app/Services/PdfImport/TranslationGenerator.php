<?php

declare(strict_types=1);

namespace App\Services\PdfImport;

use App\Repositories\PdfImportSessionRepository;
use App\Services\PdfImport\Provider\ProviderRouter;
use App\Services\PdfImport\Session\SessionStorage;

/**
 * Phase PDF-Import (feature legacy "translator") — traduce in ITALIANO gli
 * esercizi estratti da libri in lingua straniera, mantenendo INTATTE le formule
 * LaTeX e i numeri. Una chiamata LLM per riga (lista di stringhe → lista
 * tradotta, allineata per ordine). Best-effort + incrementale (cap per richiesta,
 * il client cicla finché remaining>0). Traduce SOLO le righe che "sembrano"
 * inglesi (euristica) → non tocca gli esercizi già in italiano.
 */
final class TranslationGenerator
{
    public const SYSTEM = <<<TXT
Traduci in ITALIANO ogni elemento dell'elenco numerato (testi di esercizi di
matematica/fisica). REGOLE FERREE:
- mantieni INTATTE le formule LaTeX \\(...\\) e \\[...\\] e tutti i numeri/simboli;
- NON tradurre nulla dentro le formule;
- mantieni l'ordine e il numero degli elementi.
Restituisci SOLO un array JSON di stringhe (le traduzioni), nello stesso ordine,
senza testo aggiuntivo né markdown.
TXT;

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

        $client = $router->operationClient('translation', (string)$session['provider']);
        $updated = 0;
        $processed = 0;

        foreach ($rows as &$row) {
            if ($processed >= $cap) {
                break;
            }
            if (!is_array($row)) {
                continue;
            }
            $p = (array)($row['payload'] ?? []);
            if (!self::looksEnglish(self::collectText($p))) {
                continue;
            }

            [$texts, $paths] = self::collectTranslatable($p);
            if ($texts === []) {
                continue;
            }
            $processed++;
            $router->assertBudget($teacherId);

            $numbered = [];
            foreach ($texts as $i => $t) {
                $numbered[] = ($i + 1) . '. ' . $t;
            }
            $user = PromptGuard::fence(implode("\n", $numbered)) . "\n\nTraduci ogni elemento.";
            $num = (string)($row['number'] ?? '?');
            $t0 = microtime(true);
            try {
                $res = $client->complete(OperationPrompts::resolve('translation'), $user);
            } catch (\Throwable $e) {
                LlmAuditLog::record($storage, $prefix, $teacherId, [
                    'op' => "traduzione es. $num", 'status' => 'errore',
                    'ms' => LlmAuditLog::ms($t0),
                    'error' => mb_substr($e->getMessage(), 0, 160),
                ]);
                throw $e;
            }
            $repo->addTokens($sessionId, (int)($res['tokens_in'] ?? 0), (int)($res['tokens_out'] ?? 0));
            LlmAuditLog::record($storage, $prefix, $teacherId, [
                'op' => "traduzione es. $num", 'status' => 'ok',
                'ms' => LlmAuditLog::ms($t0),
                'tokens_in' => (int)($res['tokens_in'] ?? 0), 'tokens_out' => (int)($res['tokens_out'] ?? 0),
            ]);

            $tr = ExtractionPipeline::parseJsonArray((string)($res['text'] ?? '')) ?? [];
            // Allinea SOLO se i conteggi combaciano (evita disallineamenti).
            if (count($tr) === count($paths)) {
                foreach ($paths as $k => $path) {
                    $val = is_string($tr[$k]) ? trim($tr[$k]) : '';
                    if ($val !== '') {
                        self::setByPath($p, $path, PiiMasker::mask($val));
                    }
                }
                $row['payload'] = $p;
                $updated++;
            }
        }
        unset($row);

        $storage->putJson($prefix, 'contracts.json', array_values($rows), $teacherId);

        $remaining = 0;
        foreach ($rows as $r) {
            if (is_array($r) && self::looksEnglish(self::collectText((array)($r['payload'] ?? [])))) {
                $remaining++;
            }
        }
        return ['updated' => $updated, 'remaining' => $remaining];
    }

    /** Testo concatenato (per il rilevamento lingua). */
    public static function collectText(array $p): string
    {
        $bits = [(string)($p['shared_instruction'] ?? ''), (string)($p['question'] ?? '')];
        foreach (['options', 'statements', 'points'] as $k) {
            foreach ((array)($p[$k] ?? []) as $s) {
                if (is_array($s)) {
                    $bits[] = (string)($s['text'] ?? '');
                }
            }
        }
        return implode(' ', $bits);
    }

    /**
     * Euristica: il testo è in inglese? Toglie il LaTeX, conta marker EN vs IT.
     * Conservativa: richiede ≥3 marker EN e più EN che IT.
     */
    public static function looksEnglish(string $text): bool
    {
        $t = (string)preg_replace('/\\\\[\(\[].*?\\\\[\)\]]/s', ' ', $text); // via formule
        $t = ' ' . mb_strtolower(strip_tags($t)) . ' ';
        $en = ['the', 'is', 'are', 'of', 'and', 'which', 'find', 'solve', 'value', 'following',
            'true', 'false', 'equation', 'if', 'then', 'each', 'for', 'with', 'number', 'answer',
            'calculate', 'determine', 'prove', 'show', 'function', 'where'];
        $it = ['il', 'la', 'le', 'di', 'che', 'quale', 'trova', 'risolvi', 'valore', 'seguenti',
            'vero', 'falso', 'equazione', 'se', 'allora', 'ogni', 'per', 'con', 'numero', 'risposta',
            'calcola', 'determina', 'dimostra', 'funzione', 'dove', 'una', 'del', 'della'];
        $cnt = static function (array $words) use ($t): int {
            $n = 0;
            foreach ($words as $w) {
                $n += substr_count($t, ' ' . $w . ' ');
            }
            return $n;
        };
        $e = $cnt($en);
        return $e >= 3 && $e > $cnt($it);
    }

    /**
     * Raccoglie i testi traducibili + i loro "path" (per riscriverli dopo).
     * @return array{0:list<string>,1:list<string>}
     */
    private static function collectTranslatable(array $p): array
    {
        $texts = [];
        $paths = [];
        $push = static function (string $path, string $val) use (&$texts, &$paths): void {
            if (trim($val) !== '') {
                $texts[] = $val;
                $paths[] = $path;
            }
        };
        $push('shared_instruction', (string)($p['shared_instruction'] ?? ''));
        $push('question', (string)($p['question'] ?? ''));
        $push('solution', (string)($p['solution'] ?? ''));
        foreach (['options', 'statements', 'points'] as $k) {
            foreach ((array)($p[$k] ?? []) as $i => $s) {
                if (is_array($s)) {
                    $push("$k.$i.text", (string)($s['text'] ?? ''));
                }
            }
        }
        return [$texts, $paths];
    }

    /** Scrive un valore in $p seguendo un path "a" o "options.0.text". */
    public static function setByPath(array &$p, string $path, string $value): void
    {
        $parts = explode('.', $path);
        if (count($parts) === 1) {
            $p[$parts[0]] = $value;
            return;
        }
        // forma "key.index.text"
        [$k, $i, $field] = [$parts[0], (int)$parts[1], $parts[2] ?? 'text'];
        if (isset($p[$k][$i]) && is_array($p[$k][$i])) {
            $p[$k][$i][$field] = $value;
        }
    }
}
