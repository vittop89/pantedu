<?php

declare(strict_types=1);

namespace App\Services\Verifica;

use App\Core\Config;
use App\Repositories\VerificaCompileJobRepository;
use App\Services\GeoGebra\GeoGebraTexPreProcessor;
use App\Services\TexCompile\SvgToPdfClient;
use App\Services\TexCompile\TexCompileClient;
use RuntimeException;
use Throwable;

/**
 * G22.S5 — Service della queue async per la compilazione PDF.
 *
 * Due responsabilita':
 *   1. ENQUEUE (chiamato dal controller compile-async): crea row pending,
 *      ritorna job_id. Idempotente per (teacher, doc, payload_hash).
 *   2. DEQUEUE+PROCESS (chiamato dal worker cron): pesca un job, esegue
 *      il compile via VPS, attach del PDF su success, retry/failed
 *      su error. Best-effort, non rilancia eccezioni.
 *
 * Cache S2 awareness: prima di enqueue il controller dovrebbe tentare
 * `attachCachedPdfFor` (S2 sync); solo su miss enqueue il job. Il worker
 * NON ricontrolla la cache (la verifica e' gia' fatta a request-time).
 *
 * Rate limiting al worker: il VPS impone gia' rate limit nginx 20r/min
 * + concurrent semaphore 3. Il worker processa 1 job/tick (cron 1m), che
 * naturalmente sotto i limiti. Per scalare oltre, multi-worker FIFO con
 * il lock pickNext atomic e' safe.
 */
final class VerificaCompileJobService
{
    private VerificaCompileJobRepository $jobs;
    private VerificaDocumentService $docs;

    public function __construct(
        ?VerificaCompileJobRepository $jobs = null,
        ?VerificaDocumentService $docs = null,
    ) {
        $this->jobs = $jobs ?? new VerificaCompileJobRepository();
        $this->docs = $docs ?? new VerificaDocumentService();
    }

    /**
     * Enqueue idempotente di un compile job per (teacher, doc).
     *
     * @return array{ok:bool, job_id:int, dedup:bool, status:string}
     */
    public function enqueue(int $teacherId, int $docId, string $engine = 'pdflatex', int $passes = 2): array
    {
        $doc = $this->docs->find($teacherId, $docId);
        if (!$doc) {
            throw new RuntimeException('verifica_not_found');
        }

        // Payload hash: sha256(tex_sha256 + variant + engine + passes).
        // Cosi' due enqueue per la stessa verifica con la stessa sha
        // diventano dedup; ricompilare con engine diverso (xelatex)
        // crea un job nuovo (uso valido).
        $sha = (string)($doc['tex_sha256'] ?? '');
        if ($sha === '') {
            // Row legacy senza sha persistita: fallback al doc_id+ts cosi'
            // l'enqueue funziona ma niente dedup automatico.
            $sha = 'legacy_' . $docId . '_' . time();
        }
        $variant = (string)($doc['variant'] ?? '');
        $payloadHash = hash('sha256', "$sha|$variant|$engine|$passes");

        $existing = $this->jobs->findActive($teacherId, $docId, $payloadHash);
        if ($existing !== null) {
            return [
                'ok'      => true,
                'job_id'  => (int)$existing['id'],
                'dedup'   => true,
                'status'  => (string)$existing['status'],
            ];
        }

        $jobId = $this->jobs->enqueue([
            'teacher_id'   => $teacherId,
            'doc_id'       => $docId,
            'payload_hash' => $payloadHash,
            'engine'       => $engine,
            'passes'       => $passes,
        ]);
        return [
            'ok'      => true,
            'job_id'  => $jobId,
            'dedup'   => false,
            'status'  => VerificaCompileJobRepository::STATUS_PENDING,
        ];
    }

    /**
     * Worker tick: processa un singolo job in coda. Pesca FIFO via
     * `pickNext()` (atomic lock leggero). Ritorna info su esito o null
     * se nulla da fare.
     *
     * @return array{job_id:int, status:string, doc_id:int, duration_ms:int, error:?string}|null
     */
    public function processNext(): ?array
    {
        // G22.S8 — libera job stuck-running prima di pickNext (resilience).
        $this->jobs->resetStuckRunning(300);
        $job = $this->jobs->pickNext();
        if ($job === null) {
            return null;
        }
        return $this->compileAndFinalize($job);
    }

    /**
     * G22.S7 — Processa un job SPECIFICO inline (non FIFO). Usato dal
     * compileAsync endpoint per "trigger-on-request": dopo enqueue
     * processiamo subito il job nella stessa request, evitando il delay
     * cron (10 min su hosting condiviso shared).
     *
     * Se il job e' gia' in 'running' o 'done' ritorna lo stato corrente
     * senza ri-processare. Se e' 'retry' fuori backoff o 'pending', lo
     * pesca atomicamente e procede.
     *
     * @return array|null status corrente del job (o null se inesistente).
     */
    public function processJob(int $jobId): ?array
    {
        // G22.S8 — defensive: libera eventuale stuck-running su QUESTO job
        // (caso edge: precedente trigger-on-request kill-ato da PHP timeout).
        $this->jobs->resetStuckRunning(300);
        $job = $this->jobs->pickSpecific($jobId);
        if ($job === null) {
            // Race: gia' presa da un altro worker, oppure status non lockable
            // (done/failed) o retry-backoff non ancora scaduto. Ritorna lo
            // stato corrente cosi' il caller decide cosa fare.
            $current = $this->jobs->find($jobId);
            if (!$current) {
                return null;
            }
            return [
                'job_id'      => $jobId,
                'status'      => (string)$current['status'],
                'doc_id'      => (int)$current['doc_id'],
                'duration_ms' => 0,
                'error'       => $current['last_error'] ?? null,
            ];
        }
        return $this->compileAndFinalize($job);
    }

    /**
     * G22.S7 — Estrae la logica core di compile + attach + markDone/Retry
     * da processNext() in un metodo riusabile, cosi' processNext (FIFO
     * worker) e processJob (specific inline) condividono la stessa
     * implementazione.
     *
     * Pre-condizione: il job e' gia' stato lockato (status='running').
     *
     * @return array{job_id:int, status:string, doc_id:int, duration_ms:int, error:?string}
     */
    private function compileAndFinalize(array $job): array
    {
        $jobId    = (int)$job['id'];
        $docId    = (int)$job['doc_id'];
        $teacherId = (int)$job['teacher_id'];
        $engine   = (string)$job['engine'];
        $passes   = (int)$job['passes'];

        $started = microtime(true);
        try {
            $endpoint = (string)Config::get('tex_compile.endpoint', '');
            $secret   = (string)Config::get('tex_compile.secret', '');
            if ($endpoint === '' || $secret === '') {
                throw new RuntimeException('tex_compile_disabled');
            }
            $caBundle = (string)Config::get('tex_compile.ca_bundle', '');
            $timeout  = (int)Config::get('tex_compile.timeout', 60);
            $client   = new TexCompileClient($endpoint, $secret, $timeout, $caBundle);

            // Multi-file path (S4.B.3): leggi manifest e invoca /compile-bundle.
            // Fallback single-file (legacy row pre-S4.B.2): readTex + /compile.
            $bundleFiles = [];
            try {
                $bundleFiles = $this->docs->readManifestFiles($teacherId, $docId);
            } catch (Throwable) {
                $bundleFiles = [];
            }

            // G22.S15.bis Fase 4 — Pre-process GeoGebra: cerca pattern
            // `\fmgeogebra{base64-svg}{label}` in tutti i .tex, converte SVG
            // → PDF via VPS rsvg-convert, salva i PDF in `geogebra/N.pdf` nel
            // bundle, sostituisce i marker con `\includegraphics{geogebra/N}`.
            // Best-effort: errore preprocessor → continua col bundle originale.
            $hasGgbMarkers = function (array $files): bool {
                foreach ($files as $f) {
                    if (isset($f['content']) && strpos((string)$f['content'], '\\fmgeogebra') !== false) {
                        return true;
                    }
                }
                return false;
            };
            $hasGgbInTex   = static fn(string $s): bool => strpos($s, '\\fmgeogebra') !== false;
            $needsGgbPre   = ($bundleFiles && $hasGgbMarkers($bundleFiles));
            $singleTex     = '';
            if (!$bundleFiles) {
                $singleTex = $this->docs->readTex($teacherId, $docId);
                $needsGgbPre = $hasGgbInTex($singleTex);
            }
            if ($needsGgbPre) {
                try {
                    $svgClient = new SvgToPdfClient($endpoint, $secret, min(15, $timeout), $caBundle);
                    $pre = new GeoGebraTexPreProcessor($svgClient);
                    if ($bundleFiles) {
                        $bundleFiles = $pre->processBundle($bundleFiles, "verifica_{$docId}_job_{$jobId}");
                    } else {
                        $r = $pre->processSingle($singleTex, "verifica_{$docId}_job_{$jobId}");
                        // Single-file mode: dopo pre-process, se sono stati generati
                        // PDF dobbiamo passare a bundle multi-file. Costruiamo ad-hoc.
                        if (!empty($r['generatedFiles'])) {
                            $bundleFiles = [['path' => 'main.tex', 'content' => $r['content']]];
                            foreach ($r['generatedFiles'] as $relPath => $pdfBin) {
                                $bundleFiles[] = ['path' => $relPath, 'content' => $pdfBin];
                            }
                        } else {
                            $singleTex = $r['content'];
                        }
                    }
                } catch (Throwable $e) {
                    error_log("[geogebra-pre] failed (best-effort): " . $e->getMessage());
                }
            }

            if ($bundleFiles) {
                $variantStr = '';
                $doc = $this->docs->find($teacherId, $docId);
                if ($doc) {
                    $variantStr = (string)($doc['variant'] ?? '');
                }
                $kind = preg_match('/(SOL|NOR|DSA|DIS)$/', $variantStr, $m) ? $m[1] : 'NOR';
                $mainPath = "versioni/main_{$kind}.tex";
                // DIS variant REQUIRES xelatex per fontspec+OpenDyslexic.
                // Override engine se default (pdflatex). Job può essere
                // enqueued con engine esplicito → rispetta override utente.
                if ($kind === 'DIS' && $engine === 'pdflatex') {
                    $engine = 'xelatex';
                }
                // Se il pre-process ha appena prodotto un bundle ad-hoc da
                // singleTex (path 'main.tex'), il mainPath è 'main.tex'.
                $mainExists = false;
                foreach ($bundleFiles as $f) {
                    if (($f['path'] ?? '') === $mainPath) {
                        $mainExists = true;
                        break;
                    }
                }
                if (!$mainExists && $needsGgbPre) {
                    foreach ($bundleFiles as $f) {
                        if (($f['path'] ?? '') === 'main.tex') {
                            $mainPath = 'main.tex';
                            break;
                        }
                    }
                }
                $result = $client->compileBundle(
                    files:    $bundleFiles,
                    mainPath: $mainPath,
                    docId:    "verifica_{$docId}_job_{$jobId}",
                    engine:   $engine,
                    passes:   $passes,
                );
            } else {
                $result = $client->compile(
                    texSource: $singleTex,
                    docId:     "verifica_{$docId}_job_{$jobId}",
                    engine:    $engine,
                    passes:    $passes,
                );
            }

            if (!$result['ok']) {
                $log  = (string)($result['log'] ?? '');
                $http = (int)($result['http_status'] ?? 0);
                $err  = "http=$http " . substr($log, 0, 600);
                $finalStatus = $this->jobs->markFailedOrRetry($jobId, $err);
                return [
                    'job_id'      => $jobId,
                    'status'      => $finalStatus,
                    'doc_id'      => $docId,
                    'duration_ms' => (int)((microtime(true) - $started) * 1000),
                    'error'       => $err,
                ];
            }

            $pdfBin = (string)$result['pdf'];
            if ($pdfBin === '' || substr($pdfBin, 0, 5) !== '%PDF-') {
                $err = 'invalid_pdf_response';
                $finalStatus = $this->jobs->markFailedOrRetry($jobId, $err);
                return [
                    'job_id' => $jobId, 'status' => $finalStatus,
                    'doc_id' => $docId,
                    'duration_ms' => (int)((microtime(true) - $started) * 1000),
                    'error'  => $err,
                ];
            }
            $this->docs->attachPdf($teacherId, $docId, $pdfBin, "verifica_{$docId}.pdf");
            $this->jobs->markDone($jobId, 'engine=' . ($result['engine'] ?? $engine)
                . ' duration_ms=' . (int)($result['duration_ms'] ?? 0));

            return [
                'job_id'      => $jobId,
                'status'      => VerificaCompileJobRepository::STATUS_DONE,
                'doc_id'      => $docId,
                'duration_ms' => (int)((microtime(true) - $started) * 1000),
                'error'       => null,
            ];
        } catch (Throwable $e) {
            $finalStatus = $this->jobs->markFailedOrRetry($jobId, $e->getMessage());
            return [
                'job_id'      => $jobId,
                'status'      => $finalStatus,
                'doc_id'      => $docId,
                'duration_ms' => (int)((microtime(true) - $started) * 1000),
                'error'       => $e->getMessage(),
            ];
        }
    }

    /**
     * Worker batch: chiama processNext() in loop fino a max-N job o
     * coda vuota. Da invocare con N piccoli (5-10) per non saturare il
     * VPS rate limit nginx (20 req/min). Ritorna lista esiti.
     *
     * @return list<array>
     */
    public function processBatch(int $max = 5): array
    {
        $out = [];
        for ($i = 0; $i < $max; $i++) {
            $r = $this->processNext();
            if ($r === null) {
                break;
            }
            $out[] = $r;
        }
        return $out;
    }

    public function find(int $teacherId, int $jobId): ?array
    {
        $job = $this->jobs->find($jobId);
        if (!$job) {
            return null;
        }
        if ((int)$job['teacher_id'] !== $teacherId) {
            return null;
        }
        return $job;
    }

    public function listForTeacher(int $teacherId, int $limit = 50): array
    {
        return $this->jobs->listForTeacher($teacherId, $limit);
    }

    public function purgeOld(int $days = 7): int
    {
        return $this->jobs->purgeOlderThan($days);
    }
}
