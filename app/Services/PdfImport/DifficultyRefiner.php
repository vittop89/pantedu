<?php

declare(strict_types=1);

namespace App\Services\PdfImport;

use App\Repositories\PdfImportSessionRepository;
use App\Services\PdfImport\Provider\ProviderRouter;
use App\Services\PdfImport\Session\SessionStorage;

/**
 * Phase PDF-Import — rilevamento DIFFICOLTÀ (replica dell'agente legacy).
 *
 * Il legacy (pdf-scraping-tools/agents/difficulty_agent.py) NON usava CV/pixel ma
 * un agente LLM vision DEDICATO che analizza l'INTERA pagina e restituisce
 * {numero_esercizio: difficoltà}, con un prompt mirato a CONTARE i pallini. Una
 * passata separata (non mischiata all'estrazione) per pagina → il modello si
 * concentra solo sui pallini → conta meglio. Le difficoltà vengono mappate alle
 * righe PER NUMERO di esercizio.
 */
final class DifficultyRefiner
{
    /** Prompt dedicato (adattato dall'agente legacy). */
    public const SYSTEM = <<<TXT
Sei un agente specializzato nel rilevare il LIVELLO DI DIFFICOLTÀ dai badge degli
esercizi (libri scolastici italiani). Ogni badge mostra il NUMERO dell'esercizio
in un riquadro colorato e, accanto/sopra/sotto, una fila di pallini.

La difficoltà = quanti pallini sono PIENI/COLORATI:
  1 pallino pieno = 1   2 pieni = 2   3 pieni = 3   4 pieni = 4
- Alcuni libri mostrano SOLO i pallini pieni (es. ●● = 2): conta quelli che vedi.
- Altri usano 3-4 posizioni con i vuoti visibili (es. ●●○○): conta SOLO i PIENI/
  COLORATI, ignora quelli VUOTI (○) o GRIGI/spenti.
- I pallini possono essere cerchi/quadratini di qualsiasi colore (rosso, nero, …):
  conta quelli ATTIVI (colorati/pieni), non quelli spenti/vuoti.

COMPITO: per OGNI badge visibile nella pagina, conta i pallini pieni e dai la
difficoltà. Guarda con attenzione anche i badge piccoli. Se per un esercizio non
vedi alcun pallino, OMETTILO (non inventare).

Rispondi SOLO con JSON valido, nient'altro:
{"difficulties": {"244": 2, "245": 4, "335": 1, "367": 3}}
Chiavi = numero esercizio (stringa di cifre); valori = interi 1-4.
TXT;

    public function runForSession(
        int $sessionId,
        PdfImportSessionRepository $repo,
        ProviderRouter $router,
        SessionStorage $storage
    ): array {
        $session = $repo->find($sessionId);
        if ($session === null) {
            throw new \RuntimeException('session_not_found');
        }
        $prefix = (string)$session['storage_prefix'];
        $tid    = (int)$session['teacher_id'];
        $rows = $storage->getJson($prefix, 'contracts.json', $tid);
        if (!is_array($rows) || $rows === []) {
            throw new \RuntimeException('contracts_not_ready');
        }

        // Righe per pagina (per chiamare l'agente UNA volta per pagina).
        $byPage = [];
        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $page = (int)($row['source_page'] ?? 0);
            if ($page >= 1) {
                $byPage[$page][] = $i;
            }
        }
        if ($byPage === []) {
            return ['updated' => 0, 'total' => 0, 'method' => 'llm'];
        }

        $client = $router->operationClient('difficulty', (string)$session['provider']);
        $system = OperationPrompts::resolve('difficulty');
        $model  = $router->modelForOperation('difficulty', (string)$session['provider']);
        $updated = 0;
        $total = 0;
        foreach ($byPage as $page => $idxs) {
            $total += count($idxs);
            try {
                $png = $storage->getPagePng($prefix, $page, $tid);
            } catch (\Throwable) {
                continue;
            }
            $router->assertBudget($tid);
            $t0 = microtime(true);
            try {
                $res = $client->extract($png, $system, 'Conta i pallini di difficoltà per OGNI badge della pagina. Solo JSON {"difficulties":{...}}.');
            } catch (\Throwable $e) {
                LlmAuditLog::record($storage, $prefix, $tid, [
                    'op' => "difficoltà pag. $page", 'status' => 'errore', 'model' => $model,
                    'ms' => LlmAuditLog::ms($t0),
                    'error' => mb_substr($e->getMessage(), 0, 160),
                ]);
                continue;
            }
            $repo->addTokens($sessionId, (int)($res['tokens_in'] ?? 0), (int)($res['tokens_out'] ?? 0));
            $map = self::parseDifficulties((string)($res['text'] ?? ''));
            $n = 0;
            foreach ($idxs as $i) {
                $num = (string)preg_replace('/\D+/', '', (string)($rows[$i]['number'] ?? ''));
                if ($num !== '' && isset($map[$num])) {
                    $rows[$i]['difficulty'] = max(0, min(4, (int)$map[$num]));
                    $updated++;
                    $n++;
                }
            }
            LlmAuditLog::record($storage, $prefix, $tid, [
                'op' => "difficoltà pag. $page", 'status' => 'ok', 'model' => $model,
                'ms' => LlmAuditLog::ms($t0),
                'tokens_in' => (int)($res['tokens_in'] ?? 0), 'tokens_out' => (int)($res['tokens_out'] ?? 0),
                'note' => "$n badge",
            ]);
        }

        $storage->putJson($prefix, 'contracts.json', array_values($rows), $tid);
        return ['updated' => $updated, 'total' => $total, 'method' => 'llm'];
    }

    /**
     * Parsa {"difficulties":{"244":2,...}} (gestisce fences/oggetto annidato).
     * @return array<string,int>
     */
    private static function parseDifficulties(string $text): array
    {
        $d = LlmJson::decodeArray($text);
        if ($d === []) {
            return [];
        }
        $src = isset($d['difficulties']) && is_array($d['difficulties']) ? $d['difficulties'] : $d;
        $out = [];
        foreach ($src as $k => $v) {
            $num = (string)preg_replace('/\D+/', '', (string)$k);
            if ($num !== '' && is_numeric($v)) {
                $out[$num] = (int)$v;
            }
        }
        return $out;
    }
}
