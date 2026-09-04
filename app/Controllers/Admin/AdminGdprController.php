<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\Gdpr\DataBreachRepository;
use App\Repositories\Gdpr\SubprocessorRepository;
use PDO;
use Throwable;

/**
 * Phase 25.R.4.1 — UI super-admin per governance GDPR.
 *
 * Endpoint (tutti gated da role:super_admin):
 *   /admin/data-requests        DSR log: lista dpo_requests + dettaglio + mark responded/closed
 *   /admin/data-breach          Incident register Art. 33-34 (lista + new + dettaglio + actions)
 *   /admin/subprocessors        CRUD lista responsabili esterni (DPA art. 9)
 *
 * Tutte le mutazioni passano via CSRF middleware (registrato in routes/web.php).
 */
final class AdminGdprController
{
    // ════════════════════════════════════════════════════════════════════
    // Section 1: Data Subject Requests (DSR) log — riusa tabella dpo_requests
    // ════════════════════════════════════════════════════════════════════

    /** GET /admin/data-requests */
    public function dataRequestsIndex(Request $req): Response
    {
        $statusFilter = is_string($req->query['status'] ?? null) ? $req->query['status'] : null;
        $sql = 'SELECT id, name, email, subject, is_minor_related, status,
                       created_at, acknowledged_at, responded_at, closed_at
                FROM dpo_requests';
        $args = [];
        if ($statusFilter !== null && $statusFilter !== '') {
            $sql .= ' WHERE status = ?';
            $args[] = $statusFilter;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT 200';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Aggregate stats per i tab
        $counts = Database::connection()->query(
            'SELECT status, COUNT(*) c FROM dpo_requests GROUP BY status'
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        $view = View::default();
        $body = $view->render('admin/data_requests_index', [
            'rows'         => $rows,
            'counts'       => $counts,
            'statusFilter' => $statusFilter,
            'user'         => Auth::user() ?? ['username' => '-', 'role' => 'guest'],
        ]);
        return Response::html($view->render('layout/shell', [
            'title' => 'Data Requests — Admin',
            'body'  => $body,
        ]));
    }

    /** GET /admin/data-requests/{id} */
    public function dataRequestsShow(Request $req, array $params): Response
    {
        $id = (int)($params['id'] ?? 0);
        $stmt = Database::connection()->prepare(
            'SELECT * FROM dpo_requests WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            return Response::html('<h1>404 — Not Found</h1>', 404);
        }

        $view = View::default();
        $body = $view->render('admin/data_requests_show', [
            'request' => $request,
            'csrf'    => Csrf::token(),
            'user'    => Auth::user() ?? ['username' => '-', 'role' => 'guest'],
            'id'      => $id,
        ]);
        return Response::html($view->render('layout/shell', [
            'title' => "Data Request #{$id} — Admin",
            'body'  => $body,
        ]));
    }

    /** POST /admin/data-requests/{id}/action */
    public function dataRequestsAction(Request $req, array $params): Response
    {
        $id     = (int)($params['id'] ?? 0);
        $action = (string)($req->post['action'] ?? '');
        $notes  = trim((string)($req->post['notes'] ?? ''));

        $sqlMap = [
            'mark_acknowledged' => 'UPDATE dpo_requests SET status="acknowledged", acknowledged_at=COALESCE(acknowledged_at, NOW()), dpo_notes=CONCAT(COALESCE(dpo_notes,""), "\n[acknowledged] ", ?) WHERE id=?',
            'mark_responded'    => 'UPDATE dpo_requests SET status="responded",    responded_at=NOW(), dpo_notes=CONCAT(COALESCE(dpo_notes,""), "\n[responded] ",    ?) WHERE id=?',
            'mark_closed'       => 'UPDATE dpo_requests SET status="closed",       closed_at=NOW(),    dpo_notes=CONCAT(COALESCE(dpo_notes,""), "\n[closed] ",       ?) WHERE id=?',
            'mark_spam'         => 'UPDATE dpo_requests SET status="spam",                              dpo_notes=CONCAT(COALESCE(dpo_notes,""), "\n[spam] ",         ?) WHERE id=?',
        ];
        if (!isset($sqlMap[$action])) {
            return Response::redirect("/admin/data-requests/{$id}?error=invalid_action");
        }
        if ($notes === '') {
            return Response::redirect("/admin/data-requests/{$id}?error=notes_required");
        }
        try {
            $stmt = Database::connection()->prepare($sqlMap[$action]);
            $stmt->execute([$notes, $id]);
        } catch (Throwable $e) {
            // Audit 25.R.31 — no $e->getMessage() in URL (schema/SQL leak in
            // history/referrer); messaggio generico + log server-side.
            error_log('[gdpr] data-request action failed: ' . $e->getMessage());
            return Response::redirect("/admin/data-requests/{$id}?error=" . urlencode('Operazione fallita'));
        }
        return Response::redirect("/admin/data-requests/{$id}?ok=1");
    }

    // ════════════════════════════════════════════════════════════════════
    // Section 2: Data Breach incident register — Art. 33-34 GDPR
    // ════════════════════════════════════════════════════════════════════

    /** GET /admin/data-breach */
    public function dataBreachIndex(Request $req): Response
    {
        $statusFilter = is_string($req->query['status'] ?? null) ? $req->query['status'] : null;
        $repo = new DataBreachRepository();
        $rows = $repo->listAll($statusFilter ?: null);
        $counts = $repo->statusCounts();

        $view = View::default();
        $body = $view->render('admin/data_breach_index', [
            'rows'         => $rows,
            'counts'       => $counts,
            'statusFilter' => $statusFilter,
            'user'         => Auth::user() ?? ['username' => '-', 'role' => 'guest'],
        ]);
        return Response::html($view->render('layout/shell', [
            'title' => 'Data Breach Register — Admin',
            'body'  => $body,
        ]));
    }

    /** GET /admin/data-breach/new */
    public function dataBreachNewForm(Request $req): Response
    {
        $view = View::default();
        $body = $view->render('admin/data_breach_new', [
            'csrf'  => Csrf::token(),
            'error' => $_SESSION['breach_new_error'] ?? null,
            'old'   => $_SESSION['breach_new_old']   ?? [],
            'user'  => Auth::user() ?? ['username' => '-', 'role' => 'guest'],
        ]);
        unset($_SESSION['breach_new_error'], $_SESSION['breach_new_old']);
        return Response::html($view->render('layout/shell', [
            'title' => 'Nuovo Incident — Data Breach',
            'body'  => $body,
        ]));
    }

    /** POST /admin/data-breach/new */
    public function dataBreachCreate(Request $req): Response
    {
        $repo = new DataBreachRepository();
        try {
            $id = $repo->create($req->post, (int)(Auth::user()['id'] ?? 0));
        } catch (Throwable $e) {
            $_SESSION['breach_new_error'] = $e->getMessage();
            $_SESSION['breach_new_old']   = $req->post;
            return Response::redirect('/admin/data-breach/new');
        }
        return Response::redirect("/admin/data-breach/{$id}");
    }

    /** GET /admin/data-breach/{id} */
    public function dataBreachShow(Request $req, array $params): Response
    {
        $id = (int)($params['id'] ?? 0);
        $repo = new DataBreachRepository();
        $incident = $repo->find($id);
        if (!$incident) {
            return Response::html('<h1>404 — Not Found</h1>', 404);
        }
        $view = View::default();
        $body = $view->render('admin/data_breach_show', [
            'incident' => $incident,
            'csrf'     => Csrf::token(),
            'user'     => Auth::user() ?? ['username' => '-', 'role' => 'guest'],
            'id'       => $id,
        ]);
        return Response::html($view->render('layout/shell', [
            'title' => "Incident #{$id} — Admin",
            'body'  => $body,
        ]));
    }

    /** POST /admin/data-breach/{id}/action */
    public function dataBreachAction(Request $req, array $params): Response
    {
        $id     = (int)($params['id'] ?? 0);
        $action = (string)($req->post['action'] ?? '');
        $repo = new DataBreachRepository();
        try {
            switch ($action) {
                case 'set_status':
                    $repo->updateStatus($id, (string)$req->post['status']);
                    break;
                case 'notify_garante':
                    $repo->setGaranteNotified($id, trim((string)($req->post['garante_ref'] ?? '')) ?: null);
                    break;
                case 'notify_users':
                    $repo->setUsersNotified($id, trim((string)($req->post['method'] ?? 'email')) ?: 'email');
                    break;
                default:
                    return Response::redirect("/admin/data-breach/{$id}?error=invalid_action");
            }
        } catch (Throwable $e) {
            // Audit 25.R.31 — no $e->getMessage() in URL (leak in history/referrer).
            error_log('[gdpr] data-breach action failed: ' . $e->getMessage());
            return Response::redirect("/admin/data-breach/{$id}?error=" . urlencode('Operazione fallita'));
        }
        return Response::redirect("/admin/data-breach/{$id}?ok=1");
    }

    // ════════════════════════════════════════════════════════════════════
    // Section 3: Subprocessors CRUD — DPA art. 9
    // ════════════════════════════════════════════════════════════════════

    /** GET /admin/subprocessors */
    public function subprocessorsIndex(Request $req): Response
    {
        $repo = new SubprocessorRepository();
        $rows = $repo->listAll(false);
        $view = View::default();
        $body = $view->render('admin/subprocessors_index', [
            'rows'  => $rows,
            'csrf'  => Csrf::token(),
            'flash' => $_SESSION['flash'] ?? null,
            'user'  => Auth::user() ?? ['username' => '-', 'role' => 'guest'],
        ]);
        unset($_SESSION['flash']);
        return Response::html($view->render('layout/shell', [
            'title' => 'Sub-processor — Admin',
            'body'  => $body,
        ]));
    }

    /** GET /admin/subprocessors/new */
    public function subprocessorsNewForm(Request $req): Response
    {
        return $this->subprocessorsForm(null);
    }

    /** GET /admin/subprocessors/{id}/edit */
    public function subprocessorsEditForm(Request $req, array $params): Response
    {
        $id = (int)($params['id'] ?? 0);
        $repo = new SubprocessorRepository();
        $sp = $repo->find($id);
        if (!$sp) {
            return Response::html('<h1>404 — Not Found</h1>', 404);
        }
        return $this->subprocessorsForm($sp);
    }

    /** POST /admin/subprocessors/save  (create se id vuoto, update altrimenti) */
    public function subprocessorsSave(Request $req): Response
    {
        $id   = (int)($req->post['id'] ?? 0);
        $repo = new SubprocessorRepository();
        try {
            if ($id > 0) {
                $repo->update($id, $req->post);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Sub-processor aggiornato.'];
            } else {
                $newId = $repo->create($req->post);
                $_SESSION['flash'] = ['type' => 'success', 'message' => "Sub-processor #{$newId} creato."];
            }
        } catch (Throwable $e) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Errore: ' . $e->getMessage()];
            return Response::redirect($id > 0 ? "/admin/subprocessors/{$id}/edit" : '/admin/subprocessors/new');
        }
        return Response::redirect('/admin/subprocessors');
    }

    /** POST /admin/subprocessors/{id}/delete */
    public function subprocessorsDelete(Request $req, array $params): Response
    {
        $id = (int)($params['id'] ?? 0);
        $repo = new SubprocessorRepository();
        try {
            $repo->delete($id);
            $_SESSION['flash'] = ['type' => 'success', 'message' => "Sub-processor #{$id} eliminato."];
        } catch (Throwable $e) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Errore: ' . $e->getMessage()];
        }
        return Response::redirect('/admin/subprocessors');
    }

    /** Helper: form (new or edit). */
    private function subprocessorsForm(?array $sp): Response
    {
        $view = View::default();
        $body = $view->render('admin/subprocessors_form', [
            'sp'   => $sp,
            'csrf' => Csrf::token(),
            'user' => Auth::user() ?? ['username' => '-', 'role' => 'guest'],
        ]);
        return Response::html($view->render('layout/shell', [
            'title' => ($sp ? 'Modifica sub-processor' : 'Nuovo sub-processor') . ' — Admin',
            'body'  => $body,
        ]));
    }

    // ════════════════════════════════════════════════════════════════════
    // Phase 25.R.22 — Authority Export Wizard
    //   GET  /admin/gdpr/authority-export        → form guidato
    //   POST /admin/gdpr/authority-export        → log request + build signed bundle + log provided + download
    // ════════════════════════════════════════════════════════════════════

    /**
     * GET /api/admin/gdpr/teacher-content-search?teacher_id=N&q=keyword&type=mappa
     *
     * Endpoint AJAX per il wizard: lista contenuti del docente, filtrabili per
     * keyword (LIKE title/topic) e tipo. Usato per popolare il campo
     * "Content ID specifici" del wizard authority-export.
     *
     * Output JSON: {ok, rows: [{id, content_type, title, topic, classe,
     * indirizzo, subject_code, created_at, ...}]}
     */
    public function teacherContentSearch(Request $req): Response
    {
        $teacherId = (int)($req->query['teacher_id'] ?? 0);
        if ($teacherId <= 0) {
            return Response::json(['ok' => false, 'error' => 'teacher_id required'], 400);
        }
        $q    = trim((string)($req->query['q']    ?? ''));
        $type = trim((string)($req->query['type'] ?? ''));

        $where = ['teacher_id = ?'];
        $args  = [$teacherId];
        if ($type !== '' && in_array($type, ['mappa','esercizio','verifica','document'], true)) {
            $where[] = 'content_type = ?';
            $args[]  = $type;
        }
        if ($q !== '') {
            $where[] = '(title LIKE ? OR topic LIKE ?)';
            $like = '%' . str_replace(['%','_'], ['\\%','\\_'], $q) . '%';
            $args[] = $like;
            $args[] = $like;
        }
        $sql = 'SELECT id, content_type, title, topic, classe, indirizzo, subject_code, created_at
                FROM teacher_content
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY content_type, created_at DESC
                LIMIT 200';

        try {
            $stmt = Database::connection()->prepare($sql);
            $stmt->execute($args);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('[gdpr] query failed: ' . $e->getMessage());
            return Response::json(['ok' => false, 'error' => 'internal_error'], 500);
        }

        return Response::json(['ok' => true, 'rows' => $rows, 'count' => count($rows)]);
    }

    /** GET /admin/gdpr/authority-export */
    public function authorityExportPage(Request $req): Response
    {
        // Lista docenti per autocomplete dropdown
        $teachers = [];
        try {
            $rows = Database::connection()
                ->query("SELECT id, username,
                                TRIM(CONCAT_WS(' ', first_name, last_name)) AS full_name
                         FROM users
                         WHERE role IN ('teacher','administrator')
                           AND deleted_at IS NULL
                         ORDER BY username LIMIT 500")
                ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows ?: [] as $r) {
                $teachers[] = [
                    'id'       => (int)$r['id'],
                    'username' => (string)$r['username'],
                    'label'    => ((string)$r['full_name'] !== '')
                        ? $r['username'] . ' — ' . $r['full_name']
                        : (string)$r['username'],
                ];
            }
        } catch (Throwable) {
            // fallback: no autocomplete, user inserisce ID manualmente
        }

        $view = View::default();
        $body = $view->render('admin/gdpr_authority_export', [
            'teachers'   => $teachers,
            'eventTypes' => \App\Controllers\Admin\AdminCryptoStatusController::EVENT_TYPES,
            'csrf'       => Csrf::token(),
            'flash'      => $_SESSION['flash'] ?? null,
            'user'       => Auth::user() ?? ['username' => '-', 'role' => 'guest'],
        ]);
        unset($_SESSION['flash']);
        return Response::html($view->render('layout/shell', [
            'title' => 'Authority Export — Admin',
            'body'  => $body,
        ]));
    }

    /** POST /admin/gdpr/authority-export */
    public function authorityExportSubmit(Request $req): Response
    {
        // Phase 25.R.23 — bundle può raggiungere 100+ MB (mappe + verifiche decifrate).
        // Bump runtime per evitare OOM / timeout su docenti con molti contenuti.
        @ini_set('memory_limit', '1024M');
        @set_time_limit(300);  // 5 minuti

        // Validazione input richiesta autorità (campi obbligatori GDPR chain-of-custody)
        $authorityName = trim((string)($req->post['authority_name'] ?? ''));
        $authorityRef  = trim((string)($req->post['authority_ref']  ?? ''));
        $legalBasis    = trim((string)($req->post['legal_basis']    ?? ''));
        $evidenceUrl   = trim((string)($req->post['evidence_url']   ?? ''));
        $description   = trim((string)($req->post['description']    ?? ''));
        // Test mode: NON scrive in crypto_custody_events. Utile per validare
        // workflow + bundle structure senza polluire il registro custody reale.
        $testMode      = !empty($req->post['test_mode']);

        $errors = [];
        if ($authorityName === '') {
            $errors[] = "Campo 'Autorità richiedente' obbligatorio";
        }
        if ($authorityRef === '') {
            $errors[] = "Campo 'Riferimento procedimento' obbligatorio";
        }
        if ($legalBasis === '') {
            $errors[] = "Campo 'Base giuridica' obbligatorio";
        }
        if (mb_strlen($description) < 20) {
            $errors[] = "Campo 'Descrizione' min 20 caratteri";
        }

        if (!empty($errors)) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => implode(' · ', $errors)];
            return Response::redirect('/admin/gdpr/authority-export');
        }

        // Parametri export
        $filters = \App\Controllers\Admin\AdminCryptoStatusController::parseExportFilters($req);
        $format  = in_array((string)($req->post['format'] ?? 'json'), ['csv','json'], true)
                 ? (string)$req->post['format'] : 'json';

        // STEP 1: log "authority_request" (skip in test mode)
        $actorId = (int)(Auth::user()['id'] ?? 0) ?: null;
        if (!$testMode) {
            try {
                $stmt = Database::connection()->prepare(
                    'INSERT INTO crypto_custody_events
                        (event_type, teacher_id, actor_user_id, authority_name, authority_ref,
                         description, legal_basis, evidence_url, occurred_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
                );
                $stmt->execute([
                    'authority_request',
                    $filters['teacher_id'],
                    $actorId,
                    $authorityName,
                    $authorityRef,
                    $description,
                    $legalBasis,
                    $evidenceUrl ?: null,
                ]);
            } catch (Throwable $e) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Errore log authority_request: ' . $e->getMessage()];
                return Response::redirect('/admin/gdpr/authority-export');
            }
        }

        // STEP 2: fetch + render export
        try {
            [$rows, ] = \App\Controllers\Admin\AdminCryptoStatusController::fetchCustodyEvents($filters);
            $exportBody = \App\Controllers\Admin\AdminCryptoStatusController::renderExportBody($format, $rows, $filters);
        } catch (Throwable $e) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Errore fetch eventi: ' . $e->getMessage()];
            return Response::redirect('/admin/gdpr/authority-export');
        }

        // STEP 3: log "data_provided" con sha256 del bundle PRIMA del download
        // (così il record esiste anche se il download è interrotto)
        $sha256 = hash('sha256', $exportBody);
        $providedDescription = sprintf(
            'Bundle firmato consegnato a "%s" (rif: %s). SHA-256(export.%s)=%s. %d eventi nel perimetro. Filtri: %s.',
            $authorityName,
            $authorityRef,
            $format,
            $sha256,
            count($rows),
            json_encode($filters, JSON_UNESCAPED_SLASHES)
        );
        if (!$testMode) {
            try {
                $stmt = Database::connection()->prepare(
                    'INSERT INTO crypto_custody_events
                        (event_type, teacher_id, actor_user_id, authority_name, authority_ref,
                         description, legal_basis, evidence_url, occurred_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
                );
                $stmt->execute([
                    'data_provided',
                    $filters['teacher_id'],
                    $actorId,
                    $authorityName,
                    $authorityRef,
                    $providedDescription,
                    $legalBasis,
                    $evidenceUrl ?: null,
                ]);
            } catch (Throwable $e) {
                // Audit 25.R.31 — accountability GDPR Art.5(2): se il record
                // "data_provided" NON viene scritto, NON si consegna il bundle
                // (prima procedeva → dati forniti senza traccia d'audit). Abort.
                error_log('[gdpr] data_provided log failed: ' . $e->getMessage());
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Export annullato: impossibile registrare l\'evento data_provided nell\'audit ledger. Riprova.'];
                return Response::redirect('/admin/gdpr/authority-export');
            }
        }

        // STEP 4: build + return bundle ZIP firmato
        // Phase 25.R.23 — se include_contents=1 + teacher_id specifico,
        // aggiungiamo al ZIP anche i contenuti decifrati via UserDataExportService.
        $includeContents = !empty($req->post['include_contents']);
        $contentScope    = $req->post['content_scope'] ?? [];
        if (!is_array($contentScope)) {
            $contentScope = [];
        }

        $extraSections = null;
        if ($includeContents && $filters['teacher_id'] !== null) {
            $extraSections = \App\Services\Gdpr\Export\UserDataExportService::default()->buildExport(
                new \App\Services\Gdpr\Export\ExportContext(
                    userId: (int)$filters['teacher_id'],
                    scope: \App\Services\Gdpr\Export\ExportContext::SCOPE_AUTHORITY,
                    requestorId: $actorId,
                    filters: $filters,
                    reason: "Decreto {$authorityName} ({$authorityRef})",
                ),
                $contentScope  // se vuoto → tutti gli exporters disponibili per authority
            );
        }

        return \App\Controllers\Admin\AdminCryptoStatusController::buildSignedBundleWithExtras(
            $exportBody,
            $format,
            $rows,
            $filters,
            date('Ymd_His'),
            $extraSections
        );
    }
}
