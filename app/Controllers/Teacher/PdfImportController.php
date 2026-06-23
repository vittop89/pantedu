<?php

declare(strict_types=1);

namespace App\Controllers\Teacher;

use App\Controllers\VerificaSharedHelpersTrait;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\PdfImportSessionRepository;
use App\Services\PdfImport\ProviderKeyStore;
use App\Services\PdfImport\PdfRasterizer;
use App\Services\PdfImport\Provider\ProviderRouter;
use App\Services\PdfImport\Session\PdfImportSessionService;
use App\Services\PdfImport\Session\SessionStorage;
use App\Support\TeacherContextResolver;
use Throwable;

/**
 * Phase PDF-Import — API REST del tool di estrazione esercizi da PDF.
 *
 * Teacher-only (route group auth+role:teacher). Mutazioni sotto csrf+rate.
 * Reimplementazione PHP-nativa, hardenata (LLM-PY-001): chiavi solo server-side,
 * upload validato (magic bytes+size), budget/rate per docente, SHA-256.
 */
final class PdfImportController
{
    use VerificaSharedHelpersTrait;

    private const EDITABLE_FIELDS = [
        'number', 'page', 'badge_color', 'difficulty', 'type', 'topic', 'container', 'target', 'origin',
    ];

    public function __construct(
        private readonly PdfImportSessionRepository $sessions = new PdfImportSessionRepository(),
        private readonly ProviderRouter $router = new ProviderRouter(),
    ) {
    }

    // ───────────────────────── createSession (upload) ─────────────────────────

    public function createSession(Request $req): Response
    {
        try {
            $this->assertEnabled();
            $teacherId   = $this->bindTeacher();
            $instituteId = TeacherContextResolver::firstInstituteId($teacherId);
            if ($instituteId <= 0) {
                throw new \RuntimeException('forbidden');
            }

            $rasterizer = new PdfRasterizer(
                dpi: (int)Config::get('pdf_import.dpi', 300),
                maxPages: (int)Config::get('pdf_import.max_pages', 40),
            );
            if (!$rasterizer->available()) {
                throw new \RuntimeException('rasterizer_unavailable');
            }

            // Budget gate (LLM10) prima di accettare lavoro costoso.
            $this->router->assertBudget($teacherId);

            $provider = $this->router->resolveName(
                is_string($req->input('provider')) ? (string)$req->input('provider') : null
            );

            [$binary, $filename] = $this->readUploadedPdf($req);

            $sha = hash('sha256', $binary);
            $sessionId = $this->sessions->create([
                'teacher_id'        => $teacherId,
                'institute_id'      => $instituteId,
                'payload_sha256'    => $sha,
                'original_filename' => $filename,
                'provider'          => $provider,
            ]);

            $prefix = SessionStorage::prefixFor($instituteId, $teacherId, $sessionId);
            $storage = SessionStorage::default();

            // Copyright minimization: il PDF ORIGINALE non viene mai salvato a
            // riposo. Rasterizziamo dai byte in memoria e persistiamo solo le
            // pagine derivate (cifrate per-docente), cancellate dopo l'insert.
            $pages = $rasterizer->rasterize($binary);
            $n = 0;
            foreach ($pages as $i => $png) {
                $storage->putPagePng($prefix, $i + 1, $png, $teacherId);
                $n++;
            }
            unset($binary, $pages); // libera la copia in memoria del materiale
            $this->sessions->setRasterized($sessionId, $n, $prefix);

            // POST veloce (solo rasterize). L'estrazione LLM gira in BACKGROUND
            // (worker detached) → niente blocco, log live sui poll. Fallback: se
            // async è off / exec disabilitato, avanza sui poll (status→advance).
            $this->sessionService()->dispatchWorker();
            $session = $this->sessions->find($sessionId);
            return Response::json([
                'ok'         => true,
                'session_id' => $sessionId,
                'page_count' => $n,
                'provider'   => $provider,
                'status'     => $session['status'] ?? 'rasterized',
            ], 202)->withNoCache();
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->pdfStatusFor($e));
        }
    }

    // ───────────────────────── status (poll) ─────────────────────────

    public function status(Request $req, array $params): Response
    {
        try {
            $this->assertEnabled();
            $teacherId = $this->bindTeacher();
            $session = $this->requireOwnedSession((int)($params['id'] ?? 0), $teacherId);

            // Auth/owner già verificati: rilascia il lock di sessione PRIMA del
            // lavoro LLM (advance) così il poll lungo non blocca/race-a le altre
            // richieste (evita 401 da rotazione id sessione).
            \App\Core\Session::close();

            // Estrazione in BACKGROUND: il poll NON fa lavoro LLM (resta veloce →
            // log live). Se la sessione è ancora da avviare (rasterized/retry),
            // (ri)lancia il worker detached. Idempotente (lock FSM).
            // Fallback inline (advance) se async è off / exec non disponibile.
            $st = (string)($session['status'] ?? '');
            if ((bool)\App\Core\Config::get('pdf_import.async_extraction', true) && function_exists('exec')) {
                // (Ri)lancia il worker SOLO se serve: rasterized/retry = da avviare.
                // Su 'extracting' NON ri-lanciare ad ogni poll (spawnerebbe processi
                // inutili durante l'estrazione/finalize); lo facciamo solo se la
                // sessione è STAGNANTE (worker probabilmente morto) → self-healing.
                $needs = in_array($st, ['rasterized', 'retry'], true);
                if (!$needs && $st === 'extracting') {
                    $upd = strtotime((string)($session['updated_at'] ?? 'now')) ?: time();
                    $needs = (time() - $upd) > 120;
                }
                if ($needs) {
                    $this->sessionService()->dispatchWorker();
                }
            } else {
                $this->sessionService()->advance((int)$session['id']);
            }
            $session = $this->sessions->find((int)$session['id']) ?? $session;

            $prefix = (string)$session['storage_prefix'];
            $rows = SessionStorage::default()->getJson($prefix, 'contracts.json', $teacherId) ?? [];

            return Response::json([
                'ok'      => true,
                'session' => $this->publicSession($session),
                'rows'    => array_values($rows),
                'log'     => \App\Services\PdfImport\LlmAuditLog::read(
                    SessionStorage::default(),
                    $prefix,
                    $teacherId,
                    60
                ),
            ])->withNoCache();
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->pdfStatusFor($e));
        }
    }

    // ───────────────────────── pageImage ─────────────────────────

    public function pageImage(Request $req, array $params): Response
    {
        try {
            $this->assertEnabled();
            $teacherId = $this->bindTeacher();
            $session = $this->requireOwnedSession((int)($params['id'] ?? 0), $teacherId);

            $page = (int)($params['n'] ?? 0);
            if ($page < 1 || $page > (int)$session['page_count']) {
                throw new \RuntimeException('page_not_found');
            }
            $prefix = (string)$session['storage_prefix'];
            $storage = SessionStorage::default();
            if (!$storage->pagePngExists($prefix, $page)) {
                throw new \RuntimeException('page_not_found');
            }
            $png = $storage->getPagePng($prefix, $page, $teacherId);
            return new Response($png, 200, [
                'Content-Type'  => 'image/png',
                'Cache-Control' => 'private, max-age=600',
            ]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->pdfStatusFor($e));
        }
    }

    // ───────────────────────── previewRow (render LaTeX reale) ─────────────────────────

    public function previewRow(Request $req, array $params): Response
    {
        try {
            $this->assertEnabled();
            $teacherId = $this->bindTeacher();
            $session = $this->requireOwnedSession((int)($params['id'] ?? 0), $teacherId);

            // Accetta una o più righe: ?rows=id1,id2 (oppure ?row=id singola).
            $ids = array_filter(array_map('trim', explode(
                ',',
                (string)($req->query['rows'] ?? $req->query['row'] ?? '')
            )));
            $idset = array_fill_keys($ids, true);
            $prefix = (string)$session['storage_prefix'];
            $rows = SessionStorage::default()->getJson($prefix, 'contracts.json', $teacherId) ?? [];

            $inserter = new \App\Services\PdfImport\ExerciseInserter();
            $groups = [];
            // Preserva l'ordine di selezione del client.
            foreach ($ids as $rid) {
                foreach ($rows as $r) {
                    if (is_array($r) && (string)($r['id'] ?? '') === $rid) {
                        $groups[] = $inserter->buildGroupPublic($r);
                        break;
                    }
                }
            }
            if ($groups === []) {
                throw new \RuntimeException('row_not_found');
            }

            // Render REALE (stesso ContractRenderer dell'esercizio inserito),
            // vista studente (canEdit=false). MathJax tipesetta lato client.
            $iid = TeacherContextResolver::firstInstituteId($teacherId);
            $renderer = \App\Services\ContractRenderer::loadSourcesFor($iid, $teacherId, false);
            $html = $renderer->renderContract(['title' => '', 'groups' => $groups]);

            return Response::json(['ok' => true, 'html' => $html])->withNoCache();
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->pdfStatusFor($e));
        }
    }

    // ───────────────────────── listSessions ─────────────────────────

    public function listSessions(Request $req): Response
    {
        try {
            $this->assertEnabled();
            $teacherId = $this->bindTeacher();
            $rows = array_map(
                [$this, 'publicSession'],
                $this->sessions->listForTeacher($teacherId, 50)
            );
            return Response::json(['ok' => true, 'sessions' => $rows])->withNoCache();
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->pdfStatusFor($e));
        }
    }

    // ───────────────────────── editCell / bulkEdit ─────────────────────────

    public function editCell(Request $req, array $params): Response
    {
        try {
            $this->assertEnabled();
            $teacherId = $this->bindTeacher();
            $session = $this->requireOwnedSession((int)($params['id'] ?? 0), $teacherId);
            $body = $this->readJsonBody();

            $rowId = (string)($body['row_id'] ?? '');
            $field = (string)($body['field'] ?? '');
            $value = $body['value'] ?? '';
            if ($rowId === '' || !in_array($field, self::EDITABLE_FIELDS, true)) {
                throw new \RuntimeException('invalid_field');
            }
            $this->patchRows($session, function (array &$rows) use ($rowId, $field, $value) {
                foreach ($rows as &$r) {
                    if ((string)($r['id'] ?? '') === $rowId) {
                        $r[$field] = $this->coerceField($field, $value);
                        break;
                    }
                }
            });
            return Response::json(['ok' => true])->withNoCache();
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->pdfStatusFor($e));
        }
    }

    public function bulkEdit(Request $req, array $params): Response
    {
        try {
            $this->assertEnabled();
            $teacherId = $this->bindTeacher();
            $session = $this->requireOwnedSession((int)($params['id'] ?? 0), $teacherId);
            $body = $this->readJsonBody();

            $ids   = array_map('strval', (array)($body['row_ids'] ?? []));
            $field = (string)($body['field'] ?? '');
            $value = $body['value'] ?? '';
            if ($ids === [] || !in_array($field, self::EDITABLE_FIELDS, true)) {
                throw new \RuntimeException('invalid_field');
            }
            $idset = array_fill_keys($ids, true);
            $coerced = $this->coerceField($field, $value);
            $this->patchRows($session, function (array &$rows) use ($idset, $field, $coerced) {
                foreach ($rows as &$r) {
                    if (isset($idset[(string)($r['id'] ?? '')])) {
                        $r[$field] = $coerced;
                    }
                }
            });
            return Response::json(['ok' => true, 'count' => count($ids)])->withNoCache();
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->pdfStatusFor($e));
        }
    }

    // ───────────────────────── solutions / insert (fasi 4-5) ─────────────────────────

    public function generateSolutions(Request $req, array $params): Response
    {
        try {
            $this->assertEnabled();
            $teacherId = $this->bindTeacher();
            $session = $this->requireOwnedSession((int)($params['id'] ?? 0), $teacherId);
            $this->router->assertBudget($teacherId);
            \App\Core\Session::close(); // rilascia il lock di sessione prima del lavoro LLM
            $r = $this->sessions->withLock((int)$session['id'], fn () => $this->sessionService()->generateSolutions((int)$session['id']));
            return Response::json(['ok' => true, 'updated' => $r['updated'], 'remaining' => $r['remaining']])->withNoCache();
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->pdfStatusFor($e));
        }
    }

    public function generateTopics(Request $req, array $params): Response
    {
        try {
            $this->assertEnabled();
            $teacherId = $this->bindTeacher();
            $session = $this->requireOwnedSession((int)($params['id'] ?? 0), $teacherId);
            $this->router->assertBudget($teacherId);
            \App\Core\Session::close(); // rilascia il lock di sessione prima del lavoro LLM
            $count = $this->sessions->withLock((int)$session['id'], fn () => $this->sessionService()->generateTopics((int)$session['id']));
            return Response::json(['ok' => true, 'updated' => $count])->withNoCache();
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->pdfStatusFor($e));
        }
    }

    /** STOP: interrompe l'estrazione in corso (marca la sessione 'cancelled'). */
    public function stopSession(Request $req, array $params): Response
    {
        try {
            $this->assertEnabled();
            $teacherId = $this->bindTeacher();
            $session = $this->requireOwnedSession((int)($params['id'] ?? 0), $teacherId);
            $cancelled = $this->sessions->cancel((int)$session['id']);
            return Response::json(['ok' => true, 'cancelled' => $cancelled])->withNoCache();
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->pdfStatusFor($e));
        }
    }

    /** Secondo passaggio crop-zoom per la difficoltà (legge i pallini ingranditi). */
    public function refineDifficulty(Request $req, array $params): Response
    {
        try {
            $this->assertEnabled();
            $teacherId = $this->bindTeacher();
            $session = $this->requireOwnedSession((int)($params['id'] ?? 0), $teacherId);
            $this->router->assertBudget($teacherId);
            \App\Core\Session::close();
            $r = $this->sessions->withLock((int)$session['id'], fn () => $this->sessionService()->refineDifficulty((int)$session['id']));
            return Response::json(['ok' => true] + $r)->withNoCache();
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->pdfStatusFor($e));
        }
    }

    public function translate(Request $req, array $params): Response
    {
        try {
            $this->assertEnabled();
            $teacherId = $this->bindTeacher();
            $session = $this->requireOwnedSession((int)($params['id'] ?? 0), $teacherId);
            $this->router->assertBudget($teacherId);
            \App\Core\Session::close(); // rilascia il lock di sessione prima del lavoro LLM
            $r = $this->sessions->withLock((int)$session['id'], fn () => $this->sessionService()->translate((int)$session['id']));
            return Response::json(['ok' => true, 'updated' => $r['updated'], 'remaining' => $r['remaining']])->withNoCache();
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->pdfStatusFor($e));
        }
    }

    public function insert(Request $req, array $params): Response
    {
        try {
            $this->assertEnabled();
            $teacherId = $this->bindTeacher();
            $session = $this->requireOwnedSession((int)($params['id'] ?? 0), $teacherId);
            $body = $this->readJsonBody();

            // Target per-riga: { rowId: {content_id:int, group:string} } → inserisce
            // ogni esercizio nel gruppo della verifica scelta.
            $rowTargets = [];
            foreach ((array)($body['row_targets'] ?? []) as $rid => $t) {
                if (!is_array($t)) {
                    continue;
                }
                $cid = (int)($t['content_id'] ?? 0);
                if ($cid <= 0) {
                    continue;
                }
                $rowTargets[(string)$rid] = [
                    'content_id' => $cid,
                    'group'      => mb_substr((string)($t['group'] ?? ''), 0, 200),
                ];
            }

            // create() lavora con CODICI (subject_code/indirizzo/classe), non id.
            $ctx = [
                'indirizzo' => isset($body['indirizzo']) ? (string)$body['indirizzo'] : null,
                'classe'    => isset($body['classe']) ? (string)$body['classe'] : null,
                'subject'   => isset($body['subject']) ? (string)$body['subject'] : null,
                // Compat: target unico (vecchio flusso). Precedenza a row_targets.
                'target_content_id' => isset($body['target_content_id']) ? (int)$body['target_content_id'] : 0,
                'row_targets'       => $rowTargets,
            ];
            $rowIds = array_map('strval', (array)($body['row_ids'] ?? []));

            $ids = $this->sessionService()->insert((int)$session['id'], $teacherId, $ctx, $rowIds);
            return Response::json(['ok' => true, 'created_ids' => $ids])->withNoCache();
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->pdfStatusFor($e));
        }
    }

    // ───────────────────────── gestione chiavi provider (admin) ─────────────────────────

    /** GET — stato chiavi (mascherate) + provider configurati. Admin only. */
    public function providerKeysStatus(Request $req): Response
    {
        try {
            $this->assertEnabled();
            $this->bindTeacher();
            $store = new ProviderKeyStore();
            return Response::json([
                'ok'        => true,
                'keys'      => $store->status(),
                'available' => $this->router->availableProviders(),
            ])->withNoCache();
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->pdfStatusFor($e));
        }
    }

    /**
     * GET ?provider=openrouter — elenco modelli VISION disponibili dal provider
     * (per il selector nel popup). Per OpenRouter li recupera live (slug sempre
     * validi: la lista statica invecchiava → 404 "No endpoints found"). Cache 6h.
     */
    public function providerModels(Request $req): Response
    {
        try {
            $this->assertEnabled();
            $this->bindTeacher();
            $provider = strtolower(trim((string)($req->query['provider'] ?? '')));
            $models = $provider === 'openrouter' ? $this->openRouterVisionModels() : [];
            return Response::json(['ok' => true, 'models' => $models])->withNoCache();
        } catch (Throwable $e) {
            // Non bloccare il popup: fallback a lista vuota (il client usa la statica).
            return Response::json(['ok' => false, 'error' => $e->getMessage(), 'models' => []], 200)->withNoCache();
        }
    }

    /** @return list<array{id:string,name:string}> modelli vision OpenRouter (cache 6h). */
    private function openRouterVisionModels(): array
    {
        $storage = \App\Support\Storage\StorageFactory::default();
        $cacheKey = 'pdf-import/openrouter_models.json';
        try {
            $raw = $storage->get($cacheKey);
            $c = json_decode($raw, true);
            if (is_array($c) && (int)($c['ts'] ?? 0) > (time() - 6 * 3600) && is_array($c['models'] ?? null)) {
                return $c['models'];
            }
        } catch (\Throwable) {
/* cache miss */
        }

        $ch = curl_init('https://openrouter.ai/api/v1/models');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $data = is_string($resp) ? json_decode($resp, true) : null;
        if (!is_array($data) || !is_array($data['data'] ?? null)) {
            return [];
        }
        $out = [];
        foreach ($data['data'] as $m) {
            if (!is_array($m)) {
                continue;
            }
            $in = (array)($m['architecture']['input_modalities'] ?? []);
            if (!in_array('image', $in, true)) {
                continue; // solo modelli vision
            }
            $id = (string)($m['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $out[] = ['id' => $id, 'name' => (string)($m['name'] ?? $id)];
        }
        usort($out, static fn($a, $b) => strcmp($a['id'], $b['id']));
        try {
            $storage->put($cacheKey, json_encode(['ts' => time(), 'models' => $out], JSON_UNESCAPED_SLASHES));
        } catch (\Throwable) {
/* best-effort */
        }
        return $out;
    }

    /** GET — modelli per operazione (estrazione/numeri/argomenti/traduzione/soluzioni). */
    public function providerOperations(Request $req): Response
    {
        try {
            $this->assertEnabled();
            $this->bindTeacher($req);
            return Response::json([
                'ok'            => true,
                'operations'    => (new \App\Services\PdfImport\OperationModelStore())->status(),
                'prompts'       => \App\Services\PdfImport\OperationPrompts::status(),
                'available'     => $this->router->availableProviders(),
                'cache_enabled' => (new \App\Services\PdfImport\LlmCache())->enabled(),
                'is_admin'      => Auth::check() && Auth::hasAccess('admin'),
                // 'global' = sto gestendo il preset condiviso; 'personal' = il mio override.
                'scope'         => \App\Services\PdfImport\PdfImportContext::writeGlobal() ? 'global' : 'personal',
            ])->withNoCache();
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->pdfStatusFor($e));
        }
    }

    /** POST {operation, provider, model} — assegna un modello a un'operazione. */
    public function saveProviderOperation(Request $req): Response
    {
        try {
            $this->assertEnabled();
            $this->bindTeacher($req);
            $body = $this->readJsonBody();
            $operation = strtolower(trim((string)($body['operation'] ?? '')));
            $provider  = strtolower(trim((string)($body['provider'] ?? '')));
            $model     = trim((string)($body['model'] ?? ''));
            if ($provider !== '' && !in_array($provider, ['anthropic', 'openai', 'qwen', 'openrouter', 'ollama'], true)) {
                throw new \RuntimeException('invalid_provider');
            }
            if (strlen($model) > 120) {
                throw new \RuntimeException('invalid_model');
            }
            (new \App\Services\PdfImport\OperationModelStore())->set($operation, $provider, $model);
            return Response::json(['ok' => true])->withNoCache();
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->pdfStatusFor($e));
        }
    }

    /** POST {key, enabled} — abilita/disabilita una passata automatica (argomento/difficoltà/traduzione). */
    public function toggleSetting(Request $req): Response
    {
        try {
            $this->assertEnabled();
            $this->bindTeacher($req);
            $body = $this->readJsonBody();
            $key = (string)($body['key'] ?? '');
            $on  = filter_var($body['enabled'] ?? false, FILTER_VALIDATE_BOOL);
            (new \App\Services\PdfImport\PdfImportSettings())->setBool($key, $on);
            return Response::json(['ok' => true, 'enabled' => $on])->withNoCache();
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->pdfStatusFor($e));
        }
    }

    /** POST {enabled} — abilita/disabilita la cache delle risposte LLM (estrazione). */
    public function toggleCache(Request $req): Response
    {
        try {
            $this->assertEnabled();
            $this->bindTeacher();
            $body = $this->readJsonBody();
            $on = filter_var($body['enabled'] ?? false, FILTER_VALIDATE_BOOL);
            (new \App\Services\PdfImport\LlmCache())->setEnabled($on);
            return Response::json(['ok' => true, 'enabled' => $on])->withNoCache();
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->pdfStatusFor($e));
        }
    }

    /** POST {key, prompt} — override del prompt di sistema di un'operazione (vuoto = default). */
    public function saveProviderPrompt(Request $req): Response
    {
        try {
            $this->assertEnabled();
            $this->bindTeacher($req);
            $body = $this->readJsonBody();
            $key    = trim((string)($body['key'] ?? ''));
            $prompt = (string)($body['prompt'] ?? '');
            if (!array_key_exists($key, \App\Services\PdfImport\OperationPrompts::KEYS)) {
                throw new \RuntimeException('invalid_operation');
            }
            if (mb_strlen($prompt) > 20000) {
                throw new \RuntimeException('invalid_prompt');
            }
            (new \App\Services\PdfImport\PromptStore())->set($key, $prompt);
            return Response::json(['ok' => true])->withNoCache();
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->pdfStatusFor($e));
        }
    }

    /** POST {provider, key} — salva una chiave cloud. Admin only + csrf. */
    public function saveProviderKey(Request $req): Response
    {
        try {
            $this->assertEnabled();
            $this->bindTeacher();
            $body = $this->readJsonBody();
            $provider = strtolower(trim((string)($body['provider'] ?? '')));
            $key = trim((string)($body['key'] ?? ''));
            $model = trim((string)($body['model'] ?? ''));
            if (!in_array($provider, ['anthropic', 'openai', 'qwen', 'openrouter', 'ollama'], true)) {
                throw new \RuntimeException('invalid_provider');
            }
            $store = new ProviderKeyStore();
            // Campo chiave vuoto = cambia SOLO il modello, mantieni la chiave già
            // salvata (evita di doverla re-incollare per cambiare modello, causa
            // tipica di sovrascritture errate). Richiesta solo se non c'è chiave.
            if ($key === '') {
                $existing = $store->get($provider);
                if ($existing === null || $existing === '') {
                    throw new \RuntimeException('invalid_key');
                }
                $key = $existing;
            }
            if (strlen($key) > 500) {
                throw new \RuntimeException('invalid_key');
            }
            if (strlen($model) > 120) {
                throw new \RuntimeException('invalid_model');
            }
            $store->set($provider, $key, $model !== '' ? $model : null);
            return Response::json(['ok' => true])->withNoCache();
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->pdfStatusFor($e));
        }
    }

    /** POST {provider} — rimuove una chiave. Admin only + csrf. */
    public function clearProviderKey(Request $req): Response
    {
        try {
            $this->assertEnabled();
            $this->bindTeacher();
            $body = $this->readJsonBody();
            $provider = strtolower(trim((string)($body['provider'] ?? '')));
            if (!in_array($provider, ['anthropic', 'openai', 'qwen', 'openrouter'], true)) {
                throw new \RuntimeException('invalid_provider');
            }
            (new ProviderKeyStore())->clear($provider);
            return Response::json(['ok' => true])->withNoCache();
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->pdfStatusFor($e));
        }
    }

    // ───────────────────────── helpers ─────────────────────────

    /**
     * Risolve il docente corrente e lo imposta come CONTESTO per-docente della
     * config PDF-Import (chiavi/modelli/prompt/cache/settings sono privati di
     * ogni docente). Da chiamare a inizio di ogni azione che tocca quella config.
     */
    private function bindTeacher(?Request $req = null): int
    {
        $id = $this->teacherId();
        // Scope ESPLICITO (?scope=global) per gli endpoint config: 'global' scrive
        // il PRESET condiviso ed è permesso solo agli admin. Senza scope (o
        // 'personal') = override/lettura PERSONALE del docente. Le chiavi e la cache
        // sono SEMPRE personali (questi endpoint non passano $req).
        $writeGlobal = false;
        if ($req !== null) {
            $scope = is_string($req->input('scope')) ? (string)$req->input('scope') : '';
            $writeGlobal = $scope === 'global' && Auth::check() && Auth::hasAccess('admin');
        }
        \App\Services\PdfImport\PdfImportContext::setTeacher($id, $writeGlobal);
        return $id;
    }

    private function assertEnabled(): void
    {
        if (!Config::get('pdf_import.enabled', false)) {
            throw new \RuntimeException('feature_disabled');
        }
    }

    private function sessionService(): PdfImportSessionService
    {
        return new PdfImportSessionService($this->sessions, $this->router);
    }

    /** @return array{0:string,1:string} [binary, filename] */
    private function readUploadedPdf(Request $req): array
    {
        $maxBytes = (int)Config::get('pdf_import.max_pdf_bytes', 25 * 1024 * 1024);

        $f = $_FILES['file'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new \RuntimeException('pdf_required');
        }
        $err = (int)($f['error'] ?? UPLOAD_ERR_OK);
        if ($err !== UPLOAD_ERR_OK) {
            throw new \RuntimeException($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE
                ? 'pdf_too_large' : 'pdf_upload_error');
        }
        $tmp = (string)($f['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \RuntimeException('pdf_upload_error');
        }
        $size = (int)($f['size'] ?? @filesize($tmp));
        if ($size <= 0) {
            throw new \RuntimeException('pdf_empty');
        }
        if ($size > $maxBytes) {
            throw new \RuntimeException('pdf_too_large');
        }
        $binary = (string)file_get_contents($tmp);
        // Magic bytes: un PDF valido inizia con "%PDF-".
        if (substr($binary, 0, 5) !== '%PDF-') {
            throw new \RuntimeException('pdf_invalid');
        }
        $filename = basename((string)($f['name'] ?? 'document.pdf'));
        $filename = (string)preg_replace('/[^A-Za-z0-9._\- ]/', '_', $filename) ?: 'document.pdf';
        return [$binary, $filename];
    }

    private function requireOwnedSession(int $id, int $teacherId): array
    {
        $session = $this->sessions->findForTeacher($id, $teacherId);
        if ($session === null) {
            throw new \RuntimeException('session_not_found');
        }
        return $session;
    }

    /** Patch atomico (best-effort) di contracts.json. */
    private function patchRows(array $session, callable $mutator): void
    {
        // Lock per-sessione: read-modify-write atomico vs worker/altre richieste
        // (anti lost-update su contracts.json).
        $this->sessions->withLock((int)$session['id'], function () use ($session, $mutator): void {
            $prefix = (string)$session['storage_prefix'];
            $tid = (int)$session['teacher_id'];
            $storage = SessionStorage::default();
            $rows = $storage->getJson($prefix, 'contracts.json', $tid);
            if (!is_array($rows)) {
                throw new \RuntimeException('contracts_not_ready');
            }
            $mutator($rows);
            $storage->putJson($prefix, 'contracts.json', array_values($rows), $tid);
            $this->sessions->setStatus((int)$session['id'], PdfImportSessionRepository::STATUS_REVIEWING);
        });
    }

    private function coerceField(string $field, mixed $value): mixed
    {
        return match ($field) {
            'difficulty' => max(0, min(4, (int)$value)),
            'badge_color' => in_array((string)$value, ['red','blue','green','orange',''], true)
                ? (string)$value : '',
            'type' => in_array((string)$value, ['Collect','VF','RM_VF','RM'], true) ? (string)$value : 'Collect',
            default => is_string($value) ? mb_substr($value, 0, 500) : '',
        };
    }

    private function publicSession(array $s): array
    {
        return [
            'id'         => (int)$s['id'],
            'status'     => (string)$s['status'],
            'page_count' => (int)$s['page_count'],
            'pages_done' => (int)$s['pages_done'],
            'provider'   => (string)$s['provider'],
            'model'      => (string)$s['model'],
            'filename'   => (string)$s['original_filename'],
            'tokens_in'  => (int)$s['tokens_in'],
            'tokens_out' => (int)$s['tokens_out'],
            'last_error' => $s['last_error'] ?? null,
            'created_at' => $s['created_at'] ?? null,
        ];
    }

    /** Mapping eccezione → HTTP status specifico per il dominio pdf-import. */
    private function pdfStatusFor(Throwable $e): int
    {
        return match ($e->getMessage()) {
            'unauthenticated' => 401,
            'forbidden', 'feature_disabled' => 403,
            'session_not_found', 'page_not_found', 'target_not_found', 'row_not_found' => 404,
            'pdf_too_large' => 413,
            'pdf_required', 'pdf_empty', 'pdf_invalid', 'pdf_upload_error',
            'invalid_field', 'contracts_not_ready', 'no_rows_selected',
            'invalid_provider', 'invalid_key', 'invalid_model',
            // Errori di validazione di TeacherContentRepository::create() (nuova bozza).
            'invalid_subject_code', 'title_required', 'invalid_content_type',
            'invalid_visibility', 'teacher_id_required' => 422,
            'contract_load_failed' => 500,
            'budget_exceeded' => 429,
            'rasterizer_unavailable', 'no_provider_configured', 'provider_unavailable' => 503,
            default => 400,
        };
    }
}
