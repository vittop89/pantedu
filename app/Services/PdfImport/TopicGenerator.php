<?php

declare(strict_types=1);

namespace App\Services\PdfImport;

use App\Repositories\PdfImportSessionRepository;
use App\Services\PdfImport\Provider\ProviderRouter;
use App\Services\PdfImport\Session\SessionStorage;

/**
 * Phase PDF-Import (feature legacy "TopicAgent") — assegna un ARGOMENTO generico
 * a ogni esercizio estratto, in UNA sola chiamata testuale, per raggruppare
 * esercizi simili (es. "Equazioni irrazionali", "Valore assoluto").
 *
 * Sicurezza: il testo (derivato dal PDF) è incapsulato via PromptGuard (LLM01) e
 * già mascherato da PiiMasker in estrazione (LLM02). Budget verificato (LLM10).
 * Non sovrascrive gli argomenti già impostati a mano dal docente.
 */
final class TopicGenerator
{
    private const MAX_ROWS = 120;

    public const SYSTEM = <<<TXT
Sei un assistente che assegna ARGOMENTI a esercizi di matematica/fisica.
Per ogni esercizio (identificato dal suo indice #i) assegna un argomento
GENERICO e conciso in italiano (2-4 parole), adatto a RAGGRUPPARE esercizi sullo
stesso tema: usa lo STESSO argomento per esercizi simili
(es. "Equazioni irrazionali", "Valore assoluto", "Disequazioni", "Radicali").
Restituisci SOLO un array JSON di oggetti {"i": <indice>, "topic": "<argomento>"},
nello stesso ordine, senza testo aggiuntivo né markdown.
TXT;

    public function runForSession(
        int $sessionId,
        PdfImportSessionRepository $repo,
        ProviderRouter $router,
        SessionStorage $storage
    ): int {
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

        // Elenco compatto degli esercizi (solo quelli senza argomento manuale).
        $list = [];
        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            if (trim((string)($row['topic'] ?? '')) !== '') {
                continue; // non toccare i manuali
            }
            if (count($list) >= self::MAX_ROWS) {
                break;
            }
            $p = (array)($row['payload'] ?? []);
            $txt = trim((string)($p['shared_instruction'] ?? '') . ' ' . (string)($p['question'] ?? ''));
            if ($txt === '') {
                $txt = (string)($row['type'] ?? '');
            }
            $list[$i] = '#' . $i . ': ' . mb_substr($txt, 0, 300);
        }
        if ($list === []) {
            return 0; // tutti già con argomento
        }

        $router->assertBudget($teacherId);
        $client = $router->operationClient('topics', (string)$session['provider']);
        $user = PromptGuard::fence(implode("\n", array_values($list)))
            . "\n\nAssegna un argomento a ciascun esercizio.";
        $t0 = microtime(true);
        try {
            $res = $client->complete(OperationPrompts::resolve('topics'), $user);
        } catch (\Throwable $e) {
            LlmAuditLog::record($storage, $prefix, $teacherId, [
                'op' => 'argomenti automatici', 'status' => 'errore',
                'ms' => LlmAuditLog::ms($t0),
                'error' => mb_substr($e->getMessage(), 0, 160),
            ]);
            throw $e;
        }
        $repo->addTokens($sessionId, (int)($res['tokens_in'] ?? 0), (int)($res['tokens_out'] ?? 0));
        LlmAuditLog::record($storage, $prefix, $teacherId, [
            'op' => 'argomenti automatici', 'status' => 'ok',
            'ms' => LlmAuditLog::ms($t0),
            'tokens_in' => (int)($res['tokens_in'] ?? 0), 'tokens_out' => (int)($res['tokens_out'] ?? 0),
            'note' => count($list) . ' esercizi',
        ]);

        // Parsing ROBUSTO: il modello può rispondere in vari formati. Accetta
        //   - [{"i":0,"topic":"…"}]  (formato richiesto)
        //   - [{"topic":"…"}, …]     (oggetti senza indice → per posizione)
        //   - ["…","…"]              (array di stringhe → per posizione)
        // La posizione mappa sull'ordine degli esercizi inviati ($listKeys).
        $parsed = ExtractionPipeline::parseJsonArray((string)($res['text'] ?? '')) ?? [];
        $listKeys = array_keys($list); // indici riga, nell'ordine inviato
        $map = [];
        $pos = 0;
        foreach ($parsed as $e) {
            if (is_array($e)) {
                $topic = trim((string)($e['topic'] ?? ($e['argomento'] ?? ($e['t'] ?? ''))));
                $idx = array_key_exists('i', $e) ? (int)$e['i']
                    : (array_key_exists('index', $e) ? (int)$e['index'] : null);
                if ($topic === '') {
                    $pos++;
                    continue;
                }
                if ($idx !== null && isset($list[$idx])) {
                    $map[$idx] = mb_substr($topic, 0, 80);
                } elseif (isset($listKeys[$pos])) {
                    $map[$listKeys[$pos]] = mb_substr($topic, 0, 80);
                }
                $pos++;
            } else {
                $topic = trim((string)$e);
                if ($topic !== '' && isset($listKeys[$pos])) {
                    $map[$listKeys[$pos]] = mb_substr($topic, 0, 80);
                }
                $pos++;
            }
        }

        $updated = 0;
        foreach ($rows as $i => &$row) {
            if (!is_array($row)) {
                continue;
            }
            if (isset($map[$i]) && trim((string)($row['topic'] ?? '')) === '') {
                $row['topic'] = $map[$i];
                $updated++;
            }
        }
        unset($row);

        $storage->putJson($prefix, 'contracts.json', array_values($rows), $teacherId);
        return $updated;
    }
}
