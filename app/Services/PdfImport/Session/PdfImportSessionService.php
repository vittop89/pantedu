<?php

declare(strict_types=1);

namespace App\Services\PdfImport\Session;

use App\Core\Config;
use App\Repositories\PdfImportSessionRepository;
use App\Services\PdfImport\ContractMapper;
use App\Services\PdfImport\ExtractionPipeline;
use App\Services\PdfImport\LlmAuditLog;
use App\Services\PdfImport\Provider\ProviderInterface;
use App\Services\PdfImport\Provider\ProviderRouter;

/**
 * Phase PDF-Import — orchestrazione estrazione per-pagina (FSM a livello
 * sessione, mirror di VerificaCompileJobService).
 *
 * Unità di lavoro = singola pagina (resumable/retryable). L'avanzamento è
 * guidato sia inline (kickoff dopo upload + advance ad ogni poll) sia dal
 * worker cron (processBatch). Quando tutte le pagine sono estratte, finalize()
 * costruisce contracts.json via ContractMapper.
 */
final class PdfImportSessionService
{
    private const TERMINAL = ['extracted', 'reviewing', 'inserting', 'inserted', 'failed', 'cancelled'];

    public function __construct(
        private readonly PdfImportSessionRepository $repo = new PdfImportSessionRepository(),
        private readonly ProviderRouter $router = new ProviderRouter(),
        private readonly ExtractionPipeline $pipeline = new ExtractionPipeline(),
        private readonly ?SessionStorage $storage = null,
        private readonly ContractMapper $mapper = new ContractMapper(),
    ) {
    }

    private function storage(): SessionStorage
    {
        return $this->storage ?? SessionStorage::default();
    }

    /** Subito dopo l'upload: pesca la sessione ed estrae la prima pagina inline. */
    public function kickoff(int $sessionId): void
    {
        $picked = $this->repo->pickSpecific($sessionId);
        if ($picked === null) {
            return; // già in lavorazione o non in stato rasterized/retry
        }
        $this->safeProcess($picked, 1);
    }

    /**
     * Lancia il worker di estrazione in BACKGROUND (processo detached) così il
     * lavoro LLM non blocca la richiesta web: i poll diventano veloci e il log si
     * aggiorna live. Idempotente: il lock FSM (pickNext) evita doppi processi.
     * Se exec è disabilitato o async è off, non fa nulla (fallback: advance inline).
     */
    public function dispatchWorker(): bool
    {
        if (!(bool)Config::get('pdf_import.async_extraction', true)) {
            return false;
        }
        if (!function_exists('exec')) {
            return false;
        }
        $base = dirname(__DIR__, 4);
        $script = $base . '/tools/cron/process_pdf_import_jobs.php';
        if (!is_file($script)) {
            return false;
        }
        $php = (string)Config::get('pdf_import.php_cli', '/usr/bin/php');
        // setsid + redirect completi (stdin/out/err) → processo DETACHED che
        // sopravvive alla fine della richiesta php-fpm.
        $run = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' > /dev/null 2>&1 < /dev/null &';
        @exec('cd ' . escapeshellarg($base) . ' && setsid ' . $run);
        return true;
    }

    /** Su ogni poll: avanza l'estrazione di al più $maxPages pagine. */
    // 1 pagina per poll: ogni richiesta web resta sotto il fastcgi_read_timeout
    // di nginx (estrazione multi-pagina = tanti poll brevi, non un poll lungo).
    public function advance(int $sessionId, int $maxPages = 1): void
    {
        $s = $this->repo->find($sessionId);
        if ($s === null || in_array($s['status'], self::TERMINAL, true)) {
            return;
        }
        if (in_array($s['status'], ['rasterized', 'retry'], true)) {
            $s = $this->repo->pickSpecific($sessionId);
            if ($s === null) {
                return;
            }
        }
        $this->safeProcess($s, $maxPages);
    }

    /** Worker cron: pesca FIFO e processa N sessioni per tick. */
    public function processBatch(int $maxSessions = 3): array
    {
        $this->repo->resetStuckExtracting(150); // recupero worker crashato (async)
        $out = [];
        for ($i = 0; $i < $maxSessions; $i++) {
            $s = $this->repo->pickNext();
            if ($s === null) {
                break;
            }
            $err = $this->safeProcess($s, 1000); // processa tutte le pagine rimaste
            $final = $this->repo->find((int)$s['id']);
            $out[] = [
                'session_id' => (int)$s['id'],
                'status'     => $final['status'] ?? 'unknown',
                'error'      => $err,
            ];
        }
        return $out;
    }

    /**
     * Retention: cancella PRIMA i file storage (pagine derivate + JSON, cifrati)
     * poi le righe, per le sessioni più vecchie del TTL (qualsiasi stato, incluse
     * quelle abbandonate). Limita l'esposizione di materiale coperto da copyright.
     */
    public function purgeOld(?int $days = null): int
    {
        $days = $days ?? (int)\App\Core\Config::get('pdf_import.retention_days', 7);
        $storage = $this->storage();
        foreach ($this->repo->listPurgeable($days) as $s) {
            if (($s['storage_prefix'] ?? '') !== '') {
                try {
                    $storage->deleteSession($s['storage_prefix']);
                } catch (\Throwable) {
                /* best-effort */
                }
            }
        }
        return $this->repo->purgeOlderThan($days);
    }

    /**
     * Processa fino a $maxPages pagine; gestisce errori con markFailedOrRetry.
     * Ritorna il messaggio d'errore (o null).
     */
    private function safeProcess(array $session, int $maxPages): ?string
    {
        $id = (int)$session['id'];
        try {
            for ($i = 0; $i < $maxPages; $i++) {
                $session = $this->repo->find($id);
                if ($session === null) {
                    return 'missing';
                }
                if (in_array($session['status'], self::TERMINAL, true)) {
                    return null;
                }
                if (!$this->processNextPage($session)) {
                    break; // niente più pagine (finalizzato dentro processNextPage)
                }
            }
            return null;
        } catch (\Throwable $e) {
            $outcome = $this->repo->markFailedOrRetry($id, $e->getMessage());
            LlmAuditLog::record(
                $this->storage(),
                (string)($session['storage_prefix'] ?? ''),
                (int)($session['teacher_id'] ?? 0),
                [
                    'op' => $outcome === PdfImportSessionRepository::STATUS_FAILED ? 'estrazione fallita' : 'ritento',
                    'status' => $outcome === PdfImportSessionRepository::STATUS_FAILED ? 'errore' : 'retry',
                    'error' => mb_substr($e->getMessage(), 0, 160),
                ]
            );
            return $e->getMessage();
        }
    }

    /**
     * Estrae la prossima pagina pending. Ritorna false quando non c'è altro da
     * fare (tutte le pagine estratte → finalize).
     */
    private function processNextPage(array $session): bool
    {
        $id       = (int)$session['id'];
        $pageCount = (int)$session['page_count'];
        $next      = (int)$session['pages_done'] + 1;
        $prefix    = (string)$session['storage_prefix'];
        $provider  = (string)$session['provider'];

        // Config (chiavi/modelli/prompt/cache) PRIVATA del docente della sessione.
        \App\Services\PdfImport\PdfImportContext::setTeacher((int)$session['teacher_id']);

        if ($next > $pageCount) {
            $this->finalize($session);
            return false;
        }

        $tid = (int)$session['teacher_id'];
        // Budget gate prima della chiamata costosa.
        $this->router->assertBudget($tid);

        $png = $this->storage()->getPagePng($prefix, $next, $tid);

        // Fase 1 (2-fasi): scan numeri badge come STEP DI POLL separato — 1 sola
        // chiamata LLM per poll (resta sotto fastcgi_read_timeout). Best-effort:
        // se fallisce, registra [] e prosegue all'estrazione al poll successivo.
        if (\App\Core\Config::get('pdf_import.number_scan', true)) {
            $scans = $this->storage()->getJson($prefix, 'numbers.json', $tid) ?? [];
            if (!array_key_exists((string)$next, $scans)) {
                $t0 = microtime(true);
                try {
                    $scan = $this->pipeline->scanNumbers(
                        $this->router->operationClient('numbers', $provider),
                        $png,
                        $next
                    );
                    $scans[(string)$next] = $scan['numbers'];
                    $this->repo->addTokens($id, (int)$scan['tokens_in'], (int)$scan['tokens_out']);
                    LlmAuditLog::record($this->storage(), $prefix, $tid, [
                        'op' => "scan numeri pag. $next", 'status' => 'ok',
                        'ms' => LlmAuditLog::ms($t0),
                        'tokens_in' => (int)$scan['tokens_in'], 'tokens_out' => (int)$scan['tokens_out'],
                        'note' => count($scan['numbers']) . ' numeri',
                    ]);
                } catch (\Throwable $e) {
                    $scans[(string)$next] = []; // non bloccare l'estrazione
                    LlmAuditLog::record($this->storage(), $prefix, $tid, [
                        'op' => "scan numeri pag. $next", 'status' => 'errore',
                        'ms' => LlmAuditLog::ms($t0),
                        'error' => mb_substr($e->getMessage(), 0, 160),
                    ]);
                }
                $this->storage()->putJson($prefix, 'numbers.json', $scans, $tid);
                return true; // estrazione vera al prossimo poll
            }
        }

        $exModel = $this->router->modelForOperation('extraction', $provider);
        // Cache (se abilitata): stesso PDF+modello+prompt → niente ri-chiamata.
        $cache = new \App\Services\PdfImport\LlmCache();
        $ckey = \App\Services\PdfImport\LlmCache::key(
            'extract',
            $exModel,
            \App\Services\PdfImport\OperationPrompts::resolve('extraction'),
            hash('sha256', $png)
        );
        $res = null;
        $fromCache = false;
        if ($cache->enabled() && ($hit = $cache->get($ckey)) !== null) {
            $d = json_decode($hit, true);
            if (is_array($d) && isset($d['items'])) {
                $res = $d;
                $fromCache = true;
            }
        }
        if (!$fromCache) {
            LlmAuditLog::record($this->storage(), $prefix, $tid, [
                'op' => "estrazione pag. $next", 'status' => 'invio',
                'model' => $exModel, 'note' => 'prompt inviato al provider',
            ]);
        }
        $t0 = microtime(true);
        try {
            if (!$fromCache) {
                $res = $this->pipeline->extractPage(
                    $this->router->operationClient('extraction', $provider),
                    $png,
                    $next
                );
                if ($cache->enabled() && ($res['raw_ok'] ?? false)) {
                    $cache->put($ckey, (string)json_encode($res));
                }
            }
        } catch (\Throwable $e) {
            LlmAuditLog::record($this->storage(), $prefix, $tid, [
                'op' => "estrazione pag. $next", 'status' => 'errore',
                'ms' => LlmAuditLog::ms($t0),
                'error' => mb_substr($e->getMessage(), 0, 160),
            ]);
            throw $e; // → safeProcess: markFailedOrRetry (ritento)
        }
        LlmAuditLog::record($this->storage(), $prefix, $tid, [
            'op' => "estrazione pag. $next",
            'status' => $fromCache ? 'cache' : ($res['raw_ok'] ? 'ok' : 'risposta non valida'),
            'ms' => LlmAuditLog::ms($t0),
            'model' => (string)($res['model'] ?? $exModel),
            'tokens_in' => (int)($res['tokens_in'] ?? 0), 'tokens_out' => (int)($res['tokens_out'] ?? 0),
            'note' => count($res['items']) . ' esercizi' . ($fromCache ? ' (cache)' : ''),
        ]);

        // STOP richiesto durante la pagina → abbandona senza salvare/finalizzare.
        $fresh = $this->repo->find($id);
        if ($fresh !== null && ($fresh['status'] ?? '') === PdfImportSessionRepository::STATUS_CANCELLED) {
            return false;
        }

        // Risposta non parsabile / 0 esercizi → RITENTA (l'LLM a volte restituisce
        // JSON malformato; un nuovo tentativo di solito riesce). Throw → FSM retry.
        if (!$res['raw_ok'] && $res['items'] === []) {
            throw new \RuntimeException('extraction_unparseable');
        }

        // Riconcilia i numeri estratti con quelli scansionati (per ordine).
        $scans = $this->storage()->getJson($prefix, 'numbers.json', $tid) ?? [];
        if (isset($scans[(string)$next]) && is_array($scans[(string)$next]) && $scans[(string)$next] !== []) {
            $res['items'] = \App\Services\PdfImport\ExtractionPipeline::reconcileNumbers(
                $res['items'],
                $scans[(string)$next]
            );
        }

        // Append a raw.json (associativo per pagina).
        $raw = $this->storage()->getJson($prefix, 'raw.json', $tid) ?? [];
        $raw[(string)$next] = [
            'page'       => $next,
            'items'      => $res['items'],
            'model'      => $res['model'],
            'tokens_in'  => $res['tokens_in'],
            'tokens_out' => $res['tokens_out'],
            'raw_ok'     => $res['raw_ok'],
        ];
        $this->storage()->putJson($prefix, 'raw.json', $raw, $tid);

        $this->repo->recordPageDone($id, (int)$res['tokens_in'], (int)$res['tokens_out']);

        // Se era l'ultima pagina, finalizza subito.
        if ($next >= $pageCount) {
            $session = $this->repo->find($id) ?? $session;
            $this->finalize($session);
            return false;
        }
        return true;
    }

    /** Costruisce contracts.json da raw.json e marca la sessione 'extracted'. */
    private function finalize(array $session): void
    {
        // Lock per-sessione attorno a build+arricchimenti di contracts.json
        // (anti lost-update vs edit del docente).
        $this->repo->withLock((int)$session['id'], fn () => $this->finalizeInner($session));
    }

    private function finalizeInner(array $session): void
    {
        $id     = (int)$session['id'];
        $prefix = (string)$session['storage_prefix'];
        $tid    = (int)$session['teacher_id'];
        \App\Services\PdfImport\PdfImportContext::setTeacher($tid); // config per-docente

        // IDEMPOTENTE: se è già stato finalizzato+arricchito, non rifare nulla
        // (evita arricchimenti ridondanti se finalize viene rieseguito).
        if (($this->storage()->getJson($prefix, 'finalized.json', $tid)['done'] ?? false) === true) {
            $this->repo->setStatus($id, PdfImportSessionRepository::STATUS_EXTRACTED);
            return;
        }

        $raw = $this->storage()->getJson($prefix, 'raw.json', $tid) ?? [];
        // Ordina per numero di pagina crescente.
        ksort($raw, SORT_NATURAL);

        $rows = [];
        foreach ($raw as $pageEntry) {
            if (!is_array($pageEntry)) {
                continue;
            }
            $page = (int)($pageEntry['page'] ?? 0);
            foreach ((array)($pageEntry['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $rows[] = $this->mapper->mapItem($item, $page);
            }
        }
        $this->storage()->putJson($prefix, 'contracts.json', $rows, $tid);

        // ── Arricchimenti automatici (come il legacy). Ogni passata RISALVA
        // contracts.json: la sessione resta 'extracting' → i poll caricano in
        // tabella in modo INCREMENTALE (man mano). Tutte best-effort: un errore
        // non blocca l'estrazione. Idempotenti: difficoltà ricalcola, topics
        // riempie solo i vuoti, translation tocca solo le righe in inglese. ──
        $settings = new \App\Services\PdfImport\PdfImportSettings();
        $auto = static fn(string $k): bool => $settings->getBool($k);
        $passes = [
            ['auto_difficulty',  'difficoltà',  fn() => (new \App\Services\PdfImport\DifficultyRefiner())->runForSession($id, $this->repo, $this->router, $this->storage())],
            ['auto_topics',      'argomenti',   fn() => $this->generateTopics($id)],
            ['auto_translation', 'traduzione',  fn() => $this->translate($id)],
        ];
        foreach ($passes as [$flag, $label, $run]) {
            if (!$auto($flag)) {
                continue;
            }
            try {
                $run();
            } catch (\Throwable $e) {
                LlmAuditLog::record($this->storage(), $prefix, $tid, [
                    'op' => "$label (auto)", 'status' => 'errore',
                    'error' => mb_substr($e->getMessage(), 0, 160),
                ]);
            }
        }

        $this->storage()->putJson($prefix, 'finalized.json', ['done' => true], $tid);
        $this->repo->setStatus($id, PdfImportSessionRepository::STATUS_EXTRACTED);
    }

    // ───────────────────────── Fase 4-5 (stub estesi nelle rispettive fasi) ─────────────────────────

    /** Fase 4 — generazione soluzioni AI. Sovrascritto nella relativa fase. */
    /** @return array{updated:int, remaining:int} */
    public function generateSolutions(int $sessionId): array
    {
        return (new \App\Services\PdfImport\SolutionGenerator())
            ->runForSession($sessionId, $this->repo, $this->router, $this->storage());
    }

    public function generateTopics(int $sessionId): int
    {
        return (new \App\Services\PdfImport\TopicGenerator())
            ->runForSession($sessionId, $this->repo, $this->router, $this->storage());
    }

    /** Crop-zoom della difficoltà. @return array{updated:int,total:int} */
    public function refineDifficulty(int $sessionId): array
    {
        return (new \App\Services\PdfImport\DifficultyRefiner())
            ->runForSession($sessionId, $this->repo, $this->router, $this->storage());
    }

    /** @return array{updated:int, remaining:int} */
    public function translate(int $sessionId): array
    {
        return (new \App\Services\PdfImport\TranslationGenerator())
            ->runForSession($sessionId, $this->repo, $this->router, $this->storage());
    }

    /** Fase 5 — insert in teacher_content. Sovrascritto nella relativa fase. */
    public function insert(int $sessionId, int $teacherId, array $ctx, array $rowIds): array
    {
        return (new \App\Services\PdfImport\ExerciseInserter())
            ->run($sessionId, $teacherId, $ctx, $rowIds, $this->repo, $this->storage());
    }
}
