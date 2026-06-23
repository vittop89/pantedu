<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Services\TexCompile\TexCompileClient;
use App\Services\Verifica\VerificaDocumentService;
use Throwable;

/**
 * G22.S15.bis Fase 5+ — Split di VerificaController (era 1992 righe, 22 metodi).
 *
 * Responsabilita': compilazione PDF (sync + async) + reverse SyncTeX.
 * Endpoint:
 *   POST /api/verifica/{id}/compile        → sync compile (S2 cache + bundle)
 *   POST /api/verifica/{id}/compile-async  → enqueue job + inline-process tentativo
 *   GET  /api/verifica/jobs/{jobId}        → polling status job async
 *   POST /api/verifica/{id}/synctex/edit   → reverse SyncTeX (PDF→TeX coords)
 *
 * Helper privati condivisi via VerificaSharedHelpersTrait (teacherId,
 * statusFor, latexErrorExcerpt, publicView, buildBatchFilename, ...).
 */
final class VerificaCompileController
{
    use VerificaSharedHelpersTrait;

    private VerificaDocumentService $svc;

    public function __construct(?VerificaDocumentService $svc = null)
    {
        $this->svc = $svc ?? new VerificaDocumentService();
    }

    public function compilePdf(Request $req, array $params): Response
    {
        try {
            $teacherId = $this->teacherId();
            $id = (int)($params['id'] ?? 0);

            // Body opzionale per override engine/passes/tex_override
            $body = [];
            $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
            if (stripos($ctype, 'application/json') !== false) {
                $raw = (string)file_get_contents('php://input');
                if ($raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $body = $decoded;
                    }
                }
            }
            $engine = (string)($body['engine'] ?? Config::get('tex_compile.default_engine', 'pdflatex'));
            $passes = (int)   ($body['passes'] ?? Config::get('tex_compile.default_passes', 2));
            $texOverride = isset($body['tex_override']) ? (string)$body['tex_override'] : '';
            $saveTex = (bool)($body['save_tex'] ?? false);

            $withArtifacts = ((string)($req->query['with_artifacts'] ?? '')) === '1';

            if ($texOverride !== '' && \strlen($texOverride) > 5 * 1024 * 1024) {
                return Response::json(['ok' => false, 'error' => 'tex_override_too_large'], 413);
            }

            $existingTex = $this->svc->readTex($teacherId, $id);
            $texSource = $texOverride !== '' ? $texOverride : $existingTex;

            $useBundle = ($texOverride === '');
            $bundleFiles = [];
            $bundleMain = '';
            if ($useBundle) {
                try {
                    $bundleFiles = $this->svc->readManifestFiles($teacherId, $id);
                } catch (Throwable) {
                    $bundleFiles = [];
                }
                $useBundle = !empty($bundleFiles);
                if ($useBundle) {
                    $doc = $this->svc->find($teacherId, $id);
                    $variantStr = (string)($doc['variant'] ?? '');
                    $kind = preg_match('/(SOL|NOR|DSA|DIS)$/', $variantStr, $m) ? $m[1] : 'NOR';
                    $bundleMain = "versioni/main_{$kind}.tex";
                    // DIS variant REQUIRES xelatex per fontspec+OpenDyslexic
                    // (pdflatex non supporta fontspec → font fallback a helvet
                    // senza OpenDyslexic). Override `pdflatex` (default) → xelatex.
                    // Frontend topbar sempre invia engine dal dropdown, quindi
                    // empty() check non basta: serve match esplicito su 'pdflatex'.
                    // User che SCEGLIE lualatex/xelatex esplicitamente è rispettato.
                    if ($kind === 'DIS' && $engine === 'pdflatex') {
                        $engine = 'xelatex';
                    }
                }
            }

            // S2 — content-addressed PDF cache
            if (!$withArtifacts) {
                $cached = $this->svc->attachCachedPdfFor($teacherId, $id, $texSource);
                if ($cached !== null) {
                    if ($texOverride !== '' && $saveTex) {
                        $cached = $this->svc->updateTex($teacherId, $id, $texOverride);
                    }
                    return Response::json([
                        'ok'      => true,
                        'doc'     => $this->publicView($cached),
                        'compile' => [
                            'engine'      => 'cache',
                            'duration_ms' => 0,
                            'pdf_bytes'   => (int)($cached['pdf_size'] ?? 0),
                            'cache_hit'   => true,
                        ],
                    ], 200);
                }
            }

            $timeout = (int)Config::get('tex_compile.timeout', 60);
            $client = TexCompileClient::tryDefault($timeout);
            if (!$client) {
                return Response::json([
                    'ok'    => false,
                    'error' => 'tex_compile_disabled',
                    'detail' => 'Configurazione mancante: TEX_COMPILE_ENDPOINT e/o TEX_COMPILE_SECRET non impostati.',
                ], 503);
            }

            // G27.ggb.sync — Pre-process GeoGebra ANCHE nel path sync (era
            // attivo solo in compileAsync via JobService). Senza questo, i
            // \fmgeogebra{base64-svg}{label} venivano emessi tali e quali nel
            // .tex e pdflatex li stampava come testo letterale (la macro
            // \fmgeogebra non e' definita in verifica.sty: e' un placeholder
            // PHP-side che il preprocessor deve risolvere).
            $hasGgbInTex = static fn(string $s): bool => strpos($s, '\\fmgeogebra') !== false;
            $hasGgbInBundle = function (array $files) use ($hasGgbInTex): bool {
                foreach ($files as $f) {
                    if ($hasGgbInTex((string)($f['content'] ?? ''))) {
                        return true;
                    }
                }
                return false;
            };
            $needsGgbPre = $useBundle ? $hasGgbInBundle($bundleFiles) : $hasGgbInTex($texSource);
            if ($needsGgbPre) {
                try {
                    $endpoint = (string)Config::get('tex_compile.endpoint', '');
                    $secret   = (string)Config::get('tex_compile.secret', '');
                    $caBundle = (string)Config::get('tex_compile.ca_bundle', '');
                    $svgClient = new \App\Services\TexCompile\SvgToPdfClient($endpoint, $secret, min(15, $timeout), $caBundle);
                    $pre = new \App\Services\GeoGebra\GeoGebraTexPreProcessor($svgClient);
                    if ($useBundle) {
                        $bundleFiles = $pre->processBundle($bundleFiles, "verifica_{$id}_sync");
                    } else {
                        $r = $pre->processSingle($texSource, "verifica_{$id}_sync");
                        if (!empty($r['generatedFiles'])) {
                            // Switch a bundle multi-file: main.tex + geogebra/N.pdf
                            $bundleFiles = [['path' => 'main.tex', 'content' => $r['content']]];
                            foreach ($r['generatedFiles'] as $relPath => $pdfBin) {
                                $bundleFiles[] = ['path' => $relPath, 'content' => $pdfBin];
                            }
                            $bundleMain = 'main.tex';
                            $useBundle  = true;
                        } else {
                            $texSource = $r['content'];
                        }
                    }
                } catch (Throwable $e) {
                    error_log("[geogebra-pre sync] failed (best-effort): " . $e->getMessage());
                }
            }

            // G27.compile.retry — retry automatico per 2 categorie di error
            // transient observed in produzione:
            //   1. nginx 503 Service Temporarily Unavailable (VPS overload o
            //      uvicorn worker restart) → http_status >= 500.
            //   2. pdflatex "reading image file failed" su PDF GGB nel bundle
            //      → race condition VPS quando piu' compile concorrenti
            //      sovrascrivono il temp dir/file. Bundle re-uploaded nel retry
            //      arriva pulito.
            // Backoff progressive: 0s, 8s, 20s. Max 3 tentativi totali.
            $isReadImageFailed = static fn(array $r): bool =>
                isset($r['log']) && str_contains((string)$r['log'], 'reading image file failed');
            $isTransientNetwork = static fn(array $r): bool =>
                ($r['http_status'] ?? 0) === 0 || ($r['http_status'] ?? 0) >= 500;
            // G27.compile.retry — backoff conservativo per stare sotto PHP
            // fastcgi timeout (~30s default). Max 2 retry, totale max ~10s
            // (3s + 5s sleep + ~5s × 3 chiamate = ~25s).
            // withArtifacts solo su retry: prima chiamata leggera, retry
            // include log per detect "reading image file failed".
            $callCompile = static function (bool $needArtifacts) use ($client, $useBundle, $bundleFiles, $bundleMain, $texSource, $id, $engine, $passes, $withArtifacts) {
                $effectiveArtifacts = $withArtifacts || $needArtifacts;
                if ($useBundle) {
                    return $client->compileBundle(
                        files: $bundleFiles,
                        mainPath: $bundleMain,
                        docId: 'verifica_' . $id,
                        engine: $engine,
                        passes: $passes,
                        withArtifacts: $effectiveArtifacts,
                    );
                }
                return $client->compile(
                    texSource: $texSource,
                    docId: 'verifica_' . $id,
                    engine: $engine,
                    passes: $passes,
                    withArtifacts: $effectiveArtifacts,
                );
            };
            $maxRetries = 3;
            $backoffsMs = [0, 3000, 5000];
            for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
                if ($attempt > 0 && $backoffsMs[$attempt] > 0) {
                    usleep($backoffsMs[$attempt] * 1000);
                }
                $result = $callCompile($attempt > 0);
                if (!empty($result['ok'])) {
                    break;
                }
                $shouldRetry = $isTransientNetwork($result) || $isReadImageFailed($result);
                if (!$shouldRetry) {
                    break;
                }
                error_log("[compile retry sync] attempt " . ($attempt + 1) . "/{$maxRetries} for verifica_{$id}: "
                    . ($isReadImageFailed($result) ? 'read-image-failed' : 'http=' . ($result['http_status'] ?? 0)));
            }

            if (!$result['ok']) {
                $isNetwork = ($result['http_status'] ?? 0) === 0
                          || ($result['http_status'] ?? 0) >= 500;
                $errCode   = $isNetwork ? 'tex_compile_network' : 'tex_compile_failed';
                $httpCode  = $isNetwork ? 502 : 422;

                $errBody = [
                    'ok'          => false,
                    'error'       => $errCode,
                    'http_status' => $result['http_status'] ?? 0,
                    'duration_ms' => $result['duration_ms'] ?? null,
                    'engine'      => $result['engine'] ?? $engine,
                    'log_excerpt' => $this->latexErrorExcerpt($result['log'] ?? ''),
                ];
                if ($withArtifacts) {
                    $errBody['log']      = (string)($result['log'] ?? '');
                    $errBody['warnings'] = $result['warnings'] ?? [];
                    $errBody['errors']   = $result['errors'] ?? [];
                }
                return Response::json($errBody, $httpCode);
            }

            // G21.1 — save_tex pre-PDF persist (so saved PDF matches saved TeX)
            if ($texOverride !== '' && $saveTex) {
                $this->svc->updateTex($teacherId, $id, $texOverride);
            }

            $filename = 'verifica_' . $id . '.pdf';
            $doc = $this->svc->attachPdf($teacherId, $id, (string)$result['pdf'], $filename);

            // G27.tikz.warn — estrai warning critici dal log (Undefined
            // control sequence ecc.) e includi SEMPRE nella response, anche
            // senza $withArtifacts. Cosi' UI puo' avvisare l'utente che il
            // PDF compila ma con macro mancanti (figure non disegnate).
            $compileWarnings = $this->extractCompileWarnings((string)($result['log'] ?? ''));

            $respBody = [
                'ok'      => true,
                'doc'     => $this->publicView($doc),
                'compile' => [
                    'engine'      => $result['engine'] ?? $engine,
                    'duration_ms' => $result['duration_ms'] ?? null,
                    'pdf_bytes'   => \strlen((string)$result['pdf']),
                    'cache_hit'   => false,
                ],
                'warnings' => $compileWarnings,
            ];

            if ($withArtifacts) {
                $respBody['pdf_b64']        = base64_encode((string)$result['pdf']);
                $respBody['synctex_gz_b64'] = base64_encode((string)($result['synctex_gz'] ?? ''));
                $respBody['log']            = (string)($result['log'] ?? '');
                $respBody['warnings']       = $result['warnings'] ?? [];
                $respBody['errors']         = $result['errors'] ?? [];
                $respBody['tex_saved']      = $texOverride !== '' && $saveTex;
                if (!empty($result['formatted_files']) && is_array($result['formatted_files'])) {
                    $respBody['formatted_files'] = $result['formatted_files'];
                }
            }

            return Response::json($respBody, 200);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->statusFor($e));
        }
    }

    public function compileAsync(Request $req, array $params): Response
    {
        try {
            $teacherId = $this->teacherId();
            $id = (int)($params['id'] ?? 0);

            $body = [];
            $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
            if (stripos($ctype, 'application/json') !== false) {
                $raw = (string)file_get_contents('php://input');
                if ($raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $body = $decoded;
                    }
                }
            }
            $engine = (string)($body['engine'] ?? Config::get('tex_compile.default_engine', 'pdflatex'));
            $passes = (int)   ($body['passes'] ?? Config::get('tex_compile.default_passes', 2));

            // Cache fast-path
            $existingTex = $this->svc->readTex($teacherId, $id);
            $cached = $this->svc->attachCachedPdfFor($teacherId, $id, $existingTex);
            if ($cached !== null) {
                return Response::json([
                    'ok'      => true,
                    'doc'     => $this->publicView($cached),
                    'compile' => [
                        'engine'      => 'cache',
                        'duration_ms' => 0,
                        'pdf_bytes'   => (int)($cached['pdf_size'] ?? 0),
                        'cache_hit'   => true,
                    ],
                ], 200);
            }

            // Cache miss → enqueue async + tentativo trigger-on-request inline
            $svc = new \App\Services\Verifica\VerificaCompileJobService();
            $enq = $svc->enqueue($teacherId, $id, $engine, $passes);
            $jobId = (int)$enq['job_id'];

            // G27.compile.retry — retry inline anche su path async per error
            // transient (nginx 503, "reading image file failed" race).
            // Vedi commento analogo in compilePdf sopra.
            $procResult = $svc->processJob($jobId);
            for ($attempt = 1; $attempt < 3; $attempt++) {
                if ($procResult === null) {
                    break;
                }
                $err = (string)($procResult['error'] ?? '');
                $isTransient = str_contains($err, '503')
                            || str_contains($err, 'Service Temporarily Unavailable')
                            || str_contains($err, 'reading image file failed');
                $isFailed = ($procResult['status'] ?? '') === \App\Repositories\VerificaCompileJobRepository::STATUS_FAILED
                         || ($procResult['status'] ?? '') === \App\Repositories\VerificaCompileJobRepository::STATUS_RETRY;
                if (!($isFailed && $isTransient)) {
                    break;
                }
                error_log("[compile retry async] attempt {$attempt}/3 for verifica_{$id}: " . substr($err, 0, 100));
                usleep(($attempt === 1 ? 3000 : 5000) * 1000);
                $procResult = $svc->processJob($jobId);
            }
            if (
                $procResult !== null
                && ($procResult['status'] ?? '') === \App\Repositories\VerificaCompileJobRepository::STATUS_DONE
            ) {
                $doc = $this->svc->find($teacherId, $id);
                if ($doc) {
                    return Response::json([
                        'ok'      => true,
                        'doc'     => $this->publicView($doc),
                        'compile' => [
                            'engine'      => $engine,
                            'duration_ms' => (int)($procResult['duration_ms'] ?? 0),
                            'pdf_bytes'   => (int)($doc['pdf_size'] ?? 0),
                            'cache_hit'   => false,
                            'inline'      => true,
                            'job_id'      => $jobId,
                        ],
                    ], 200);
                }
            }

            return Response::json([
                'ok'      => true,
                'async'   => true,
                'job_id'  => $jobId,
                'dedup'   => (bool)$enq['dedup'],
                'status'  => (string)($procResult['status'] ?? $enq['status']),
                'error'   => $procResult['error'] ?? null,
            ], 202);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->statusFor($e));
        }
    }

    public function getJob(Request $req, array $params): Response
    {
        try {
            $teacherId = $this->teacherId();
            $jobId = (int)($params['jobId'] ?? 0);
            $svc = new \App\Services\Verifica\VerificaCompileJobService();
            $job = $svc->find($teacherId, $jobId);
            if (!$job) {
                return Response::json(['ok' => false, 'error' => 'job_not_found'], 404);
            }

            $st = (string)($job['status'] ?? '');
            if ($st === 'pending' || $st === 'retry') {
                $proc = $svc->processJob($jobId);
                if ($proc !== null) {
                    $job = $svc->find($teacherId, $jobId) ?? $job;
                }
            }

            $resp = [
                'ok'  => true,
                'job' => [
                    'id'            => (int)$job['id'],
                    'doc_id'        => (int)$job['doc_id'],
                    'status'        => (string)$job['status'],
                    'attempts'      => (int)$job['attempts'],
                    'last_error'    => $job['last_error'] ?? null,
                    'created_at'    => $job['created_at'] ?? null,
                    'started_at'    => $job['started_at'] ?? null,
                    'completed_at'  => $job['completed_at'] ?? null,
                ],
            ];
            if ((string)$job['status'] === 'done') {
                $doc = $this->svc->find($teacherId, (int)$job['doc_id']);
                if ($doc) {
                    $resp['doc'] = $this->publicView($doc);
                }
            }
            return Response::json($resp, 200);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->statusFor($e));
        }
    }

    public function synctexEdit(Request $req, array $params): Response
    {
        try {
            $teacherId = $this->teacherId();
            $id = (int)($params['id'] ?? 0);

            // Ownership check (lancia se non proprietario)
            $this->svc->readTex($teacherId, $id);

            $raw = (string)file_get_contents('php://input');
            if ($raw === '') {
                return Response::json(['ok' => false, 'error' => 'empty_payload'], 400);
            }
            $body = json_decode($raw, true);
            if (!is_array($body) || !isset($body['synctex_gz_b64'], $body['page'], $body['x'], $body['y'])) {
                return Response::json(['ok' => false, 'error' => 'invalid_payload'], 400);
            }
            $synctexGz = base64_decode((string)$body['synctex_gz_b64'], true);
            if ($synctexGz === false || $synctexGz === '') {
                return Response::json(['ok' => false, 'error' => 'invalid_synctex_b64'], 400);
            }
            $page = (int)$body['page'];
            $x = (float)$body['x'];
            $y = (float)$body['y'];

            $client = TexCompileClient::tryDefault(15);
            if (!$client) {
                return Response::json(['ok' => false, 'error' => 'tex_compile_disabled'], 503);
            }
            $result = $client->synctexEdit($synctexGz, $page, $x, $y);

            if (!($result['ok'] ?? false)) {
                return Response::json($result, 200);
            }
            return Response::json($result, 200);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->statusFor($e));
        }
    }
}
