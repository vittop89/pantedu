<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Risdoc\PendingStatus;
use App\Services\Risdoc\InstitutionalOverrideRepository;
use App\Services\Risdoc\Permission;
use App\Services\Risdoc\ReviewFlow;
use JsonException;
use PDO;

/**
 * Admin panel per risdoc per-teacher overrides (Phase 21, U8).
 *
 * GET  /admin/risdoc                                pagina HTML
 * GET  /api/admin/risdoc/templates                  lista con stats
 * GET  /api/admin/risdoc/templates/{id}             dettaglio
 * POST /api/admin/risdoc/templates/{id}/visibility  bulk toggle
 * POST /api/admin/risdoc/templates/{id}/owner       change owner
 * POST /api/admin/risdoc/templates/{id}/collaborators add/remove
 * GET  /api/admin/risdoc/teachers                   list docenti
 * GET  /api/admin/risdoc/drift                      override outdated
 *
 * Tutte le route SONO protette dal middleware 'super_admin_required'
 * (vedi routes/web.php). Non duplicare check `canManageAdmin()` qui.
 */
final class RisdocAdminController
{
    public function __construct(
        private readonly ReviewFlow $review = new ReviewFlow(),
        private readonly InstitutionalOverrideRepository $institutional = new InstitutionalOverrideRepository(),
    ) {
    }

    /**
     * G14 — pagina inline migrata in /admin/templates (tab RisDoc).
     * /admin/risdoc redirect 302 al nuovo entrypoint per back-compat.
     */
    public function page(Request $req): Response
    {
        return Response::redirect('/admin/templates#risdoc');
    }

    /** Root dei file options-source (catalogo "Da JSON" / SORGENTE OPZIONI). */
    private function optionsSourcesRoot(): string
    {
        return dirname(__DIR__, 3) . '/storage/templates/risdoc';
    }

    /**
     * Valida un path relativo options-source: SOLO file .json dentro la root,
     * niente path-traversal. La regex non ammette il punto se non in ".json"
     * → "../x.json" non matcha. Ritorna il path assoluto o null.
     */
    private function resolveOptionsSourcePath(string $rel): ?string
    {
        $rel = str_replace('\\', '/', trim($rel));
        if ($rel === '' || str_contains($rel, '..')) {
            return null;
        }
        if (!preg_match('#^[A-Za-z0-9_/-]+\.json$#', $rel)) {
            return null;
        }
        return $this->optionsSourcesRoot() . '/' . $rel;
    }

    /**
     * GET /api/admin/risdoc/options-sources
     * Elenca i file .json options-source (scan ricorsivo, max depth 4),
     * con label leggibile. Admin-gated (self-contained, non dipende dal
     * catalogo teacher).
     */
    public function optionsSourcesList(Request $req): Response
    {
        $root = $this->optionsSourcesRoot();
        $files = [];
        if (is_dir($root)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );
            $it->setMaxDepth(4);
            foreach ($it as $entry) {
                if (!$entry->isFile() || strtolower($entry->getExtension()) !== 'json') {
                    continue;
                }
                $rel = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
                // escludi backup e cartelle non-options (images/texCommon)
                if (str_ends_with($rel, '.bak') || preg_match('#^(images|texCommon)/#', $rel)) {
                    continue;
                }
                $files[] = [
                    'path'  => $rel,
                    'label' => $this->optionsSourceLabel($rel),
                    'bytes' => $entry->getSize(),
                ];
            }
        }
        usort($files, fn($a, $b) => strcmp($a['path'], $b['path']));
        return Response::json(['files' => $files, 'count' => count($files)]);
    }

    /** Label leggibile da un path relativo (prima cartella = dataset). */
    private function optionsSourceLabel(string $rel): string
    {
        $base = basename($rel, '.json');
        $top = explode('/', $rel)[0] ?? $rel;
        return $top === $base ? $base : "{$top} · {$base}";
    }

    /**
     * GET /api/admin/risdoc/options-source?path=<rel>
     * Ritorna il contenuto grezzo + parsed di un file options-source.
     */
    public function optionsSourceRead(Request $req): Response
    {
        $rel = (string)$req->input('path', '');
        $abs = $this->resolveOptionsSourcePath($rel);
        if ($abs === null) {
            return Response::json(['error' => 'Path non valido'], 400);
        }
        if (!is_file($abs)) {
            return Response::json(['error' => 'File inesistente'], 404);
        }
        $raw = (string)file_get_contents($abs);
        $parsed = json_decode($raw, true);
        return Response::json([
            'path'    => $rel,
            'content' => $raw,
            'parsed'  => json_last_error() === JSON_ERROR_NONE ? $parsed : null,
            'valid'   => json_last_error() === JSON_ERROR_NONE,
            'bytes'   => strlen($raw),
            'mtime'   => @filemtime($abs) ?: null,
        ]);
    }

    /**
     * POST /api/admin/risdoc/options-source  (body JSON: {path, content, create?})
     * Valida il JSON, fa un backup .bak, riscrive il file (pretty, UTF-8).
     */
    public function optionsSourceSave(Request $req): Response
    {
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            return Response::json(['error' => 'Body JSON atteso'], 400);
        }
        $rel     = (string)($body['path'] ?? '');
        $content = (string)($body['content'] ?? '');
        $create  = !empty($body['create']);
        $abs = $this->resolveOptionsSourcePath($rel);
        if ($abs === null) {
            return Response::json(['error' => 'Path non valido'], 400);
        }
        $exists = is_file($abs);
        if (!$exists && !$create) {
            return Response::json(['error' => 'File inesistente'], 404);
        }
        $parsed = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return Response::json(['error' => 'JSON non valido: ' . json_last_error_msg()], 422);
        }
        // Normalizza (pretty, niente escape unicode/slash → leggibile e diff-friendly).
        $pretty = json_encode(
            $parsed,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        if ($pretty === false) {
            return Response::json(['error' => 'Re-encode fallito'], 500);
        }
        if ($create && !is_dir(dirname($abs))) {
            @mkdir(dirname($abs), 0775, true);
        }
        if ($exists) {
            @copy($abs, $abs . '.bak'); // backup pre-overwrite
        }
        $ok = @file_put_contents($abs, $pretty . "\n", LOCK_EX);
        if ($ok === false) {
            return Response::json(['error' => 'Scrittura fallita (permessi del file?)'], 500);
        }
        return Response::json([
            'ok'      => true,
            'path'    => $rel,
            'bytes'   => strlen($pretty),
            'created' => !$exists,
        ]);
    }

    public function templatesList(Request $req): Response
    {
        $db = Database::connection();
        // G22.S26 — owner_id rimossa (migration 047). Aggiunto pending_count
        // per badge UI "N modifiche da rivedere".
        $rows = $db->query("
            SELECT t.id, t.code, t.category, t.num_arg, t.argomento, t.discipline,
                   t.source_hash,
                   (SELECT COUNT(*) FROM risdoc_template_visibility v WHERE v.template_id=t.id AND v.visible=1) AS visible_count,
                   (SELECT COUNT(*) FROM risdoc_template_collaborators c WHERE c.template_id=t.id) AS collab_count,
                   (SELECT COUNT(*) FROM risdoc_teacher_overrides o WHERE o.template_id=t.id) AS override_count,
                   (SELECT COUNT(*) FROM risdoc_teacher_overrides o WHERE o.template_id=t.id AND o.source_version != t.source_hash) AS drift_count,
                   (SELECT COUNT(*) FROM risdoc_template_pending_changes pc WHERE pc.template_id=t.id AND pc.status='pending') AS pending_count
            FROM risdoc_templates t
            ORDER BY t.category, t.num_arg
        ")->fetchAll(PDO::FETCH_ASSOC);
        return Response::json(['ok' => true, 'templates' => $rows]);
    }

    public function templateDetail(Request $req, array $params): Response
    {
        $id = (int)($params['id'] ?? 0);
        $db = Database::connection();
        $tmpl = $db->prepare('SELECT * FROM risdoc_templates WHERE id=?');
        $tmpl->execute([$id]);
        $t = $tmpl->fetch(PDO::FETCH_ASSOC);
        if (!$t) {
            return Response::json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $vis = $db->prepare('SELECT v.teacher_id, u.username, v.visible FROM risdoc_template_visibility v JOIN users u ON u.id=v.teacher_id WHERE v.template_id=? ORDER BY u.username');
        $vis->execute([$id]);
        // G22.S26 — include requires_review nel select collab.
        $collab = $db->prepare('SELECT c.teacher_id, u.username, c.requires_review, c.invited_at FROM risdoc_template_collaborators c JOIN users u ON u.id=c.teacher_id WHERE c.template_id=? ORDER BY u.username');
        $collab->execute([$id]);

        return Response::json([
            'ok' => true,
            'template'     => $t,
            'visibility'   => $vis->fetchAll(PDO::FETCH_ASSOC),
            'collaborators' => $collab->fetchAll(PDO::FETCH_ASSOC),
        ]);
    }

    public function visibilityBulk(Request $req, array $params): Response
    {
        $id = (int)($params['id'] ?? 0);
        $teacherIds = $req->post['teacher_ids'] ?? [];
        $visible = (int)($req->post['visible'] ?? 1) === 1 ? 1 : 0;
        if (!is_array($teacherIds)) {
            $teacherIds = array_filter(array_map('intval', explode(',', (string)$teacherIds)));
        }
        if (!$teacherIds) {
            return Response::json(['ok' => false, 'error' => 'no_teachers'], 400);
        }

        $me = Permission::currentTeacherId();
        $db = Database::connection();
        $stmt = $db->prepare('INSERT INTO risdoc_template_visibility (template_id, teacher_id, visible, granted_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE visible=VALUES(visible), granted_by=VALUES(granted_by), granted_at=CURRENT_TIMESTAMP');
        $n = 0;
        foreach ($teacherIds as $tid) {
            $stmt->execute([$id, (int)$tid, $visible, $me ?: null]);
            $n++;
        }
        return Response::json(['ok' => true, 'updated' => $n, 'visible' => $visible]);
    }

    /**
     * Phase 25.B3 — set visibility_scope per un template istituzionale.
     *
     * POST body:
     *   - scope: 'public'|'institute'|'indirizzo'|'classe'|'denied'
     *   - scope_institute_id: int|null (solo se scope=institute)
     *   - scope_indirizzo:    string|null (solo se scope=indirizzo)
     *   - scope_classe:       string|null (solo se scope=classe)
     */
    public function setVisibilityScope(Request $req, array $params): Response
    {
        $id = (int)($params['id'] ?? 0);
        if ($id === 0) {
            return Response::json(['ok' => false, 'error' => 'invalid_template_id'], 400);
        }

        $scope = (string)($req->post['scope'] ?? '');
        $allowed = ['public', 'institute', 'indirizzo', 'classe', 'denied'];
        if (!in_array($scope, $allowed, true)) {
            return Response::json(['ok' => false, 'error' => 'invalid_scope', 'allowed' => $allowed], 400);
        }

        // Sanitize input scoped: solo il campo del scope corrente è popolato,
        // gli altri sono NULL per evitare configurazioni inconsistenti.
        $instId = ($scope === 'institute' && isset($req->post['scope_institute_id']))
            ? (int)$req->post['scope_institute_id'] : null;
        $ind = ($scope === 'indirizzo')
            ? trim((string)($req->post['scope_indirizzo'] ?? '')) : null;
        $cls = ($scope === 'classe')
            ? trim((string)($req->post['scope_classe'] ?? '')) : null;
        if ($scope === 'indirizzo' && $ind === '') {
            return Response::json(['ok' => false, 'error' => 'scope_indirizzo_required'], 400);
        }
        if ($scope === 'classe' && $cls === '') {
            return Response::json(['ok' => false, 'error' => 'scope_classe_required'], 400);
        }

        $db = Database::connection();
        // Audit 25.R.31 — scope=institute: prima scope_institute_id non era
        // validato (>0/esistenza) → id fantasma o 0 = regola di visibilità morta.
        if ($scope === 'institute') {
            if ($instId === null || $instId <= 0) {
                return Response::json(['ok' => false, 'error' => 'scope_institute_id_required'], 400);
            }
            $instChk = $db->prepare('SELECT 1 FROM institutes WHERE id=? LIMIT 1');
            $instChk->execute([$instId]);
            if (!$instChk->fetchColumn()) {
                return Response::json(['ok' => false, 'error' => 'institute_not_found'], 404);
            }
        }
// Verifica esistenza row PRIMA dell'UPDATE: MySQL rowCount() ritorna 0
        // se i valori UPDATE sono uguali a quelli correnti (idempotency =
        // no-op), che NON significa "row not found". Esistenza è un check
        // separato.
        $exists = $db->prepare('SELECT 1 FROM risdoc_templates WHERE id=? LIMIT 1');
        $exists->execute([$id]);
        if (!$exists->fetchColumn()) {
            return Response::json(['ok' => false, 'error' => 'template_not_found'], 404);
        }

        $stmt = $db->prepare('UPDATE risdoc_templates
             SET visibility_scope=?, scope_institute_id=?, scope_indirizzo=?, scope_classe=?
             WHERE id=?');
        $stmt->execute([$scope, $instId, $ind ?: null, $cls ?: null, $id]);
        return Response::json([
            'ok' => true,
            'visibility_scope' => $scope,
            'scope_institute_id' => $instId,
            'scope_indirizzo' => $ind ?: null,
            'scope_classe' => $cls ?: null,
        ]);
    }

    /**
     * ADR-027 — POST /api/admin/risdoc/templates/{id}/meta
     * Aggiorna nome (argomento), posizione (num_arg) e categoria/gruppo del
     * template. Tutti opzionali: aggiorna solo i campi inviati.
     */
    public function updateMeta(Request $req, array $params): Response
    {
        // Audit 25.R.31 — check super_admin rimosso: ridondante, il middleware
        // super_admin_required gatea già tutte le route /admin/risdoc/*.
        $id = (int)($params['id'] ?? 0);
        if ($id === 0) {
            return Response::json(['ok' => false, 'error' => 'invalid_template_id'], 400);
        }
        $set = [];
        $args = [];
        if (array_key_exists('argomento', $req->post)) {
            $name = trim((string)$req->post['argomento']);
            if ($name === '' || mb_strlen($name) > 200) {
                return Response::json(['ok' => false, 'error' => 'invalid_argomento'], 400);
            }
            $set[] = 'argomento = ?';
            $args[] = $name;
        }
        if (array_key_exists('num_arg', $req->post)) {
            $na = trim((string)$req->post['num_arg']);
            if (!preg_match('/^[0-9]{1,3}(\.[0-9]{1,3})?$/', $na)) {
                return Response::json(['ok' => false, 'error' => 'invalid_num_arg'], 400);
            }
            $set[] = 'num_arg = ?';
            $args[] = $na;
        }
        if (array_key_exists('category', $req->post)) {
            $cat = trim((string)$req->post['category']);
            if (!preg_match('/^[A-Za-z0-9_ -]{1,64}$/', $cat)) {
                return Response::json(['ok' => false, 'error' => 'invalid_category'], 400);
            }
            $set[] = 'category = ?';
            $args[] = $cat;
        }
        if (!$set) {
            return Response::json(['ok' => false, 'error' => 'no_fields'], 400);
        }
        $db = Database::connection();
        $exists = $db->prepare('SELECT 1 FROM risdoc_templates WHERE id=? LIMIT 1');
        $exists->execute([$id]);
        if (!$exists->fetchColumn()) {
            return Response::json(['ok' => false, 'error' => 'template_not_found'], 404);
        }
        $args[] = $id;
        $stmt = $db->prepare('UPDATE risdoc_templates SET ' . implode(', ', $set) . ' WHERE id = ?');
        $stmt->execute($args);
        return Response::json(['ok' => true]);
    }

    /**
     * ADR-027 — POST /api/admin/risdoc/templates/rename-group
     * Rinomina una partizione (category) per tutti i suoi template.
     * body: from, to. (Phase 24.58 — colonna `origin` rimossa: rinomina per
     * sola category.)
     */
    public function renameGroup(Request $req): Response
    {
        // Audit 25.R.31 — check super_admin rimosso: ridondante, il middleware
        // super_admin_required gatea già tutte le route /admin/risdoc/*.
        $from   = trim((string)($req->post['from'] ?? ''));
        $to     = trim((string)($req->post['to'] ?? ''));
        if ($from === '' || $to === '') {
            return Response::json(['ok' => false, 'error' => 'missing_fields'], 400);
        }
        if (!preg_match('/^[A-Za-z0-9_ -]{1,64}$/', $to)) {
            return Response::json(['ok' => false, 'error' => 'invalid_to'], 400);
        }
        $db = Database::connection();
        $stmt = $db->prepare('UPDATE risdoc_templates SET category = ? WHERE category = ?');
        $stmt->execute([$to, $from]);
        return Response::json(['ok' => true, 'updated' => $stmt->rowCount()]);
    }

    /**
     * Phase 24.57 — POST /api/admin/risdoc/templates/create
     * Crea un nuovo template (e quindi, se la category è nuova, una nuova
     * partizione). body: category, num_arg, argomento. Il body_pt è uno schema
     * minimo: l'admin poi lo costruisce con "✏️ Schema"
     * (/risdoc/view/{id}?admin_edit=1). Phase 24.58 — colonna `origin` rimossa.
     */
    public function createTemplate(Request $req): Response
    {
        // Audit 25.R.31 — check super_admin rimosso: ridondante, il middleware
        // super_admin_required gatea già tutte le route /admin/risdoc/*.
        $category = trim((string)($req->post['category'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_ -]{1,64}$/', $category)) {
            return Response::json(['ok' => false, 'error' => 'invalid_category'], 400);
        }
        $numArg = trim((string)($req->post['num_arg'] ?? ''));
        if (!preg_match('/^[0-9]{1,3}(\.[0-9]{1,3})?$/', $numArg)) {
            return Response::json(['ok' => false, 'error' => 'invalid_num_arg'], 400);
        }
        $argomento = trim((string)($req->post['argomento'] ?? ''));
        if ($argomento === '' || mb_strlen($argomento) > 200) {
            return Response::json(['ok' => false, 'error' => 'invalid_argomento'], 400);
        }

        $db = Database::connection();

        // code univoco: {CATEGORY}/{num_arg}_{slug}. Suffisso se collide.
        $slug = preg_replace('/[^A-Za-z0-9]+/', '_', $argomento);
        $slug = trim((string)$slug, '_');
        $catUp = strtoupper(str_replace(' ', '_', $category));
        $base  = $catUp . '/' . $numArg . '_' . $slug;
        $code  = $base;
        $chk = $db->prepare('SELECT 1 FROM risdoc_templates WHERE code=? LIMIT 1');
        for ($i = 2; $i < 50; $i++) {
            $chk->execute([$code]);
            if (!$chk->fetchColumn()) {
                break;
            }
            $code = $base . '_' . $i;
        }

        // body_pt minimo: header + placeholder editabile.
        $bodyPt = json_encode([
            ['_type' => 'sectionHeader', 'title' => $argomento, 'level' => 1,
             'selectors' => ['classe', 'sezione', 'indirizzo', 'disciplina', 'professore']],
            ['_type' => 'block', 'style' => 'normal',
             'children' => [['_type' => 'span', 'text' => 'Contenuti da definire — usa “✏️ Schema” per costruire il template.', 'marks' => []]],
             'fieldName' => 'placeholder', 'fieldType' => 'nota-textarea'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $hash = hash('sha256', $bodyPt);

        $stmt = $db->prepare(
            'INSERT INTO risdoc_templates
               (code, category, num_arg, argomento, discipline,
                source_dir, html_file, body_pt, source_hash, visibility_scope)
             VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $code, $category, $numArg, $argomento,
            'db://admin', $code . '.pt', $bodyPt, $hash, 'public',
        ]);

        return Response::json(['ok' => true, 'id' => (int)$db->lastInsertId(), 'code' => $code]);
    }

    /**
     * G22.S26 — endpoint setOwner rimosso: la colonna owner_id è stata
     * droppata nella migration 047. Per gestire i permessi usa
     * collaboratorsEdit (add/remove) + setCollaboratorReview (flag per-teacher).
     */

    public function collaboratorsEdit(Request $req, array $params): Response
    {
        $id = (int)($params['id'] ?? 0);
        $add       = $req->post['add']    ?? [];
        $remove    = $req->post['remove'] ?? [];
        // G22.S26 — review_map: { teacher_id => 0|1 } per impostare
        // requires_review sui collaboratori (esistenti + nuovi). Default 0.
        $reviewMap = $req->post['review_map'] ?? [];
        if (!is_array($add)) {
            $add    = array_filter(array_map('intval', explode(',', (string)$add)));
        }
        if (!is_array($remove)) {
            $remove = array_filter(array_map('intval', explode(',', (string)$remove)));
        }
        if (!is_array($reviewMap)) {
            $reviewMap = [];
        }
        $me = Permission::currentTeacherId();
        $db = Database::connection();
        $ins = $db->prepare(
            'INSERT INTO risdoc_template_collaborators
                (template_id, teacher_id, invited_by, requires_review)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE requires_review = VALUES(requires_review)'
        );
        foreach ($add as $tid) {
            $tid = (int)$tid;
            $rr = (int)($reviewMap[$tid] ?? $reviewMap[(string)$tid] ?? 0) === 1 ? 1 : 0;
            $ins->execute([$id, $tid, $me ?: null, $rr]);
        }
        // Update requires_review per collaboratori già esistenti (non in $add).
        $upd = $db->prepare(
            'UPDATE risdoc_template_collaborators
                SET requires_review = ?
              WHERE template_id = ? AND teacher_id = ?'
        );
        foreach ($reviewMap as $tid => $rr) {
            $tid = (int)$tid;
            if (in_array($tid, $add, true)) {
                continue;
            }
            if (in_array($tid, (array)$remove, true)) {
                continue;
            }
            $upd->execute([(int)$rr === 1 ? 1 : 0, $id, $tid]);
        }
        $del = $db->prepare('DELETE FROM risdoc_template_collaborators WHERE template_id=? AND teacher_id=?');
        foreach ($remove as $tid) {
            $del->execute([$id, (int)$tid]);
        }
        return Response::json(['ok' => true, 'added' => count($add), 'removed' => count($remove)]);
    }

    /* ─── G22.S26 — Review queue endpoints (super-admin only) ─── */

    /**
     * GET /api/admin/risdoc/pending?status=pending|approved|rejected|all&template_id=N
     * Lista pending changes (lazy: il body content NON viene incluso, solo
     * size + metadata. Per il body usa /pending/{id}/content).
     */
    public function pendingList(Request $req): Response
    {
        $statusRaw = (string)($req->query['status'] ?? 'pending');
        // 'all' è sentinella legacy → null = no filter.
        $statusEnum = $statusRaw === 'all'
            ? null
            : PendingStatus::tryFromString($statusRaw);
        if ($statusRaw !== 'all' && $statusEnum === null) {
            return Response::json([
                'ok' => false,
                'error' => 'invalid_status',
                'allowed' => [...PendingStatus::values(), 'all'],
            ], 400);
        }
        $templateId = isset($req->query['template_id'])
            ? (int)$req->query['template_id'] : null;
        $rows = $this->review->listChanges($statusEnum, $templateId);
        return Response::json([
            'ok' => true,
            'pending' => $rows,
            'count_pending' => $this->review->countPending(),
        ]);
    }

    /**
     * GET /api/admin/risdoc/pending/{id}/content
     * Restituisce il payload (text o base64) del pending change. Per
     * immagini il client può fare new Image(`data:image/*;base64,${...}`).
     */
    public function pendingContent(Request $req, array $params): Response
    {
        $pid = (int)($params['id'] ?? 0);
        if ($pid === 0) {
            return Response::json(['ok' => false, 'error' => 'invalid_id'], 400);
        }
        $c = $this->review->getChange($pid);
        if (!$c) {
            return Response::json(['ok' => false, 'error' => 'not_found'], 404);
        }
        return Response::json(['ok' => true] + $c);
    }

    /**
     * GET /admin/risdoc/pending/{id}/preview
     *
     * G22.S26 — Pagina HTML standalone che embed la shell unificata
     * fm-pt-document con schema-url puntato al pending. Iframe nel diff
     * view per "Anteprima" della proposta. Super-admin only.
     * ADR-026 #3 (2026-05-28) — migrata da fm-risdoc-template eliminato a
     * fm-pt-document (source=risdoc-template, schema-url override).
     */
    public function pendingPreviewPage(Request $req, array $params): Response
    {
        $pid = (int)($params['id'] ?? 0);
        if ($pid === 0) {
            return Response::html('<h1>400</h1><p>invalid_id</p>', 400);
        }
        $c = $this->review->getChange($pid);
        if (!$c) {
            return Response::html('<h1>404</h1><p>Pending change not found</p>', 404);
        }
        if (!in_array($c['kind'], ['schema', 'json'], true)) {
            return Response::html('<h1>400</h1><p>kind non renderizzabile (atteso schema/json)</p>', 400);
        }
        $templateId  = (int)$c['template_id'];
        $schemaUrl   = '/api/admin/risdoc/pending/' . $pid . '/schema';
        $schemaUrlH  = htmlspecialchars($schemaUrl, ENT_QUOTES);
        // Cache-bust ES module: preview admin-only, fix devono arrivare
        // senza Ctrl+F5. pid + timestamp.
        $cacheBust = $pid . '_' . time();
        $bootstrapManifest = dirname(__DIR__, 3) . '/public/build/manifest.json';
        $bootstrapTag = is_file($bootstrapManifest)
            ? \App\Support\ViteManifest::script('js/modules/bootstrap.js')
            : "<script type=\"module\" src=\"/js/modules/bootstrap.js?v={$cacheBust}\"></script>";
        $html = <<<HTML
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Anteprima pending #{$pid}</title>
    <link rel="stylesheet" href="/css/layout.css">
    <style>
        body { margin: 0; padding: 12px; background: var(--fm-c-bg, #f8fafc); }
        body.fm-dark { background: #1f2330; color: #e2e8f0; }
        .preview-banner {
            background: rgba(245,158,11,0.15); border-left: 3px solid #f59e0b;
            padding: 6px 10px; border-radius: 4px; font-size: 12px;
            color: var(--fm-c-fg, #334155); margin-bottom: 10px;
        }
        body.fm-dark .preview-banner { color: #fbbf24; background: rgba(245,158,11,0.18); }
        fm-pt-document { display: block; }
    </style>
</head>
<body>
    <div class="preview-banner">
        🛡 <strong>Anteprima pending #{$pid}</strong> — rendering della proposta non ancora approvata.
        Le modifiche dello state restano locali (non vengono salvate).
        <span style="font-size:10px;opacity:.6;margin-left:8px">build: {$cacheBust}</span>
    </div>
    <fm-pt-document source="risdoc-template" template-id="{$templateId}" schema-url="{$schemaUrlH}">
        <noscript>JavaScript richiesto.</noscript>
    </fm-pt-document>
    {$bootstrapTag}
    <script type="module" src="/js/components/pt-document/fm-pt-document.js?v={$cacheBust}"></script>
    <script type="module" src="/js/components/risdoc/index.js?v={$cacheBust}"></script>
</body>
</html>
HTML;
        $resp = Response::html($html);
        // G22.S26 — no-cache: la preview è dinamica (pending change varia
        // ogni save di Marco). Cache aggressiva del browser ha causato
        // mancato refresh post-fix del rendering checkbox.
        $resp->headers['Cache-Control'] = 'no-store, no-cache, must-revalidate, max-age=0';
        $resp->headers['Pragma'] = 'no-cache';
        return $resp;
    }

    /**
     * GET /api/admin/risdoc/pending/{id}/schema
     *
     * G22.S26 — Restituisce il JSON schema dal pending content (no envelope,
     * direct JSON payload) così fm-pt-document (source=risdoc-template) può
     * renderizzare il template come se fosse live via schema-url override.
     * Solo super-admin; solo kind in [schema, json].
     */
    public function pendingSchema(Request $req, array $params): Response
    {
        $pid = (int)($params['id'] ?? 0);
        if ($pid === 0) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        $c = $this->review->getChange($pid);
        if (!$c) {
            return Response::json(['error' => 'not_found'], 404);
        }
        if (!\in_array($c['kind'], ['schema', 'json'], true)) {
            return Response::json(['error' => 'kind_not_renderable'], 400);
        }
        // Parse + re-emit per validare/normalizzare (JSON_THROW per error path
        // tipato invece del check-then-act manuale).
        try {
            $decoded = \json_decode($c['content'], true, 512, \JSON_THROW_ON_ERROR);
            $reEncoded = \json_encode($decoded, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return Response::json(['error' => 'invalid_json', 'detail' => $e->getMessage()], 422);
        }
        return new Response(
            body: $reEncoded,
            status: 200,
            headers: ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }

    /**
     * POST /api/admin/risdoc/pending/{id}/approve   body: { note? }
     */
    public function pendingApprove(Request $req, array $params): Response
    {
        $pid = (int)($params['id'] ?? 0);
        if ($pid === 0) {
            return Response::json(['ok' => false, 'error' => 'invalid_id'], 400);
        }
        $reviewerId = Permission::currentTeacherId();
        if ($reviewerId <= 0) {
            return Response::json(['ok' => false, 'error' => 'no_reviewer'], 400);
        }
        $note = \trim((string)($req->post['note'] ?? '')) ?: null;
        $res = $this->review->approve($pid, $reviewerId, $note);
        return Response::json($res, $res['ok'] ? 200 : 400);
    }

    /**
     * POST /api/admin/risdoc/pending/{id}/reject   body: { note (required) }
     */
    public function pendingReject(Request $req, array $params): Response
    {
        $pid = (int)($params['id'] ?? 0);
        if ($pid === 0) {
            return Response::json(['ok' => false, 'error' => 'invalid_id'], 400);
        }
        $reviewerId = Permission::currentTeacherId();
        if ($reviewerId <= 0) {
            return Response::json(['ok' => false, 'error' => 'no_reviewer'], 400);
        }
        $note = (string)($req->post['note'] ?? '');
        $res = $this->review->reject($pid, $reviewerId, $note);
        return Response::json($res, $res['ok'] ? 200 : 400);
    }

    public function teachersList(Request $req): Response
    {
        $rows = Database::connection()->query("SELECT id, username, first_name, last_name, role FROM users
             WHERE role IN ('teacher','administrator','collaborator','super_admin') AND active=1
             ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
        return Response::json(['ok' => true, 'teachers' => $rows]);
    }

    public function driftList(Request $req): Response
    {
        $rows = Database::connection()->query("
            SELECT o.id, o.teacher_id, u.username, o.template_id, t.code, o.kind, o.relative_path,
                   o.source_version, t.source_hash, o.updated_at
            FROM risdoc_teacher_overrides o
            JOIN risdoc_templates t ON t.id=o.template_id
            JOIN users u            ON u.id=o.teacher_id
            WHERE o.source_version != t.source_hash
            ORDER BY o.updated_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        return Response::json(['ok' => true, 'drifted' => $rows]);
    }
}
