<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\TeacherContentRepository;
use Throwable;

/**
 * REST CRUD su `teacher_content` (Phase 13).
 *
 *   GET    /api/teacher/content               → lista (filtri: type, subject, etc)
 *   POST   /api/teacher/content               → crea
 *   GET    /api/teacher/content/{id}          → detail
 *   POST   /api/teacher/content/{id}/update   → update parziale (PATCH-like)
 *   POST   /api/teacher/content/{id}/delete   → cancella
 *   POST   /api/teacher/content/{id}/publish  → visibility=published
 *   POST   /api/teacher/content/{id}/unpublish→ visibility=draft
 *
 * Autorizzazione: teacher+ (route middleware). Ogni operazione su
 * un id verifica che `teacher_id == current user id` (no cross-teacher).
 */
final class TeacherContentController
{
    private TeacherContentRepository $repo;

    public function __construct(?TeacherContentRepository $repo = null)
    {
        $this->repo = $repo ?? new TeacherContentRepository();
    }

    public function index(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }

        // ADR-027 — loader unico: ?section=<key> carica TUTTI i tipi ancorati
        // alla sezione (section_id) invece di filtrare per content_type.
        $sectionId = null;
        $sectionKey = trim((string)($req->query['section'] ?? ''));
        if ($sectionKey !== '') {
            $iid = $this->firstInstituteId($tid);
            foreach ((new \App\Repositories\SidebarSectionRepository())->resolveFor($iid, $tid) as $s) {
                if ($s['section_key'] === $sectionKey) {
                    $sectionId = $s['id'];
                    break;
                }
            }
        }

        $filters = [
            'teacher_id'   => $tid,
            'content_type' => $sectionId ? null : $this->cleanType($req->query['type'] ?? null),
            'section_id'   => $sectionId,
            'subject_code' => $req->query['subject']  ?? null,
            'indirizzo'    => $req->query['indirizzo'] ?? null,
            'classe'       => $req->query['classe']    ?? null,
            'visibility'   => $req->query['visibility'] ?? null,
            'q'            => $req->query['q']         ?? null,
            'limit'        => (int)($req->query['limit']  ?? 100),
            'offset'       => (int)($req->query['offset'] ?? 0),
            // Phase 24.49 — opt-in metadata_json per consumer che hanno
            // bisogno di leggere category/layout/scope (es. risdoc-sidepage merge).
            'with_metadata' => !empty($req->query['with_metadata']),
        ];
        // Phase 17 — ETag conditional: hash (max updated_at + count) → il
        // client che già ha la lista corrente riceve 304 senza scaricare nulla.
        $sigToken = 'tc:' . $tid . ':' . $this->repo->listSignature($filters);
        $rows = $this->repo->searchLean($filters);
        return Response::json(['ok' => true, 'count' => count($rows), 'rows' => $rows])
            ->withETag($sigToken, maxAge: 30);
    }

    /**
     * GET /api/teacher/capabilities — capability effettive del docente.
     * Usato dall'UI per mostrare solo le opzioni consentite (es. il dropdown
     * "Chi può vederlo" limitato a max_visibility). Full-permissive in SINGLE.
     */
    public function capabilities(Request $req): Response
    {
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $caps = (new \App\Services\TeacherCapabilityPolicy())->effectiveFor($tid);
        return Response::json(['ok' => true, 'capabilities' => $caps]);
    }

    public function store(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }

        try {
            $type = (string)($req->post['type'] ?? '');

            // ADR-028 Fase 3 — capability per-docente: tipo di documento creabile
            // + visibilità massima. Full-permissive in SINGLE; ristretto in
            // INSTITUTE secondo il profilo del docente.
            $cap = new \App\Services\TeacherCapabilityPolicy();
            if ($type !== '' && !$cap->canCreateDocType($tid, $type)) {
                return Response::json(['error' => 'doc_type_not_allowed', 'type' => $type], 403);
            }
            $reqScope = (string)($req->post['publish_scope'] ?? 'class');
            if (!$cap->visibilityAllowed($tid, $reqScope)) {
                return Response::json(['error' => 'visibility_not_allowed', 'scope' => $reqScope], 403);
            }

            // ADR-027 Step 5-6 — risolve la sezione di creazione (se inviata) e
            // valida il tipo contro allowed_content_types. Retrocompat: senza
            // section_key → $sectionId null → flusso invariato.
            $sectionId = null;
            $sectionKey = trim((string)($req->post['section_key'] ?? ''));
            if ($sectionKey !== '') {
                $iid = $this->firstInstituteId($tid);
                $sections = (new \App\Repositories\SidebarSectionRepository())->resolveFor($iid, $tid);
                foreach ($sections as $s) {
                    if ($s['section_key'] === $sectionKey) {
                        if (!\in_array($type, $s['allowed_content_types'], true)) {
                            return Response::json(['error' => 'type_not_allowed_in_section'], 400);
                        }
                        $sectionId = $s['id'];
                        break;
                    }
                }
            }

            $id = $this->repo->create([
                'teacher_id'   => $tid,
                'content_type' => $type,
                'section_id'   => $sectionId,
                'subject_code' => (string)($req->post['subject']  ?? ''),
                'indirizzo'    => $this->blankToNull($req->post['indirizzo'] ?? null),
                'classe'       => $this->blankToNull($req->post['classe']    ?? null),
                'topic'        => (string)($req->post['topic']    ?? ''),
                'title'        => (string)($req->post['title']    ?? ''),
                'body_html'    => (string)($req->post['body_html'] ?? ''),
                'metadata'     => $this->parseJson($req->post['metadata'] ?? null),
                'visibility'   => (string)($req->post['visibility'] ?? 'draft'),
                // Migration 069 — scope di pubblicazione multi-classe.
                'publish_scope'  => (string)($req->post['publish_scope'] ?? 'class'),
                'target_classes' => $this->parseTargetClasses($req->post['target_classes'] ?? null),
            ]);

            // Phase 18 — auto-create contract shell per il FORMATO 'exercise'
            // (esercizio/verifica/lab): il renderer emette fm-draggable-container
            // vuoto. ADR-027 — branch su FORMATO, non sui nomi-tipo.
            if (TeacherContentRepository::formatOf($type) === 'exercise') {
                try {
                    $iid = $this->firstInstituteId($tid);
                    \App\Services\Contract\ContractRepository::default()
                        ->createEmptyShellForNewContent($id, $iid);
                } catch (\Throwable) {
                    // best-effort: la row esiste, il contract puo essere
                    // creato successivamente al primo save.
                }
            }

            return Response::json(['ok' => true, 'id' => $id]);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => 'invalid_request'], 400);
        } catch (Throwable $e) {
            // Phase 24.74 — titolo duplicato (uq_teach_content_title = teacher_id
            // + content_subtype + title): messaggio chiaro invece di persist_failed.
            $m = $e->getMessage();
            if (\str_contains($m, 'uq_teach_content_title') || \str_contains($m, '1062')) {
                return Response::json([
                    'error' => 'Hai già un documento con questo titolo: scegline un altro.',
                ], 409);
            }
            return Response::json(['error' => 'persist_failed', 'detail' => $m], 500);
        }
    }

    public function show(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $row = $this->repo->find((int)($params['id'] ?? 0));
        if (!$row) {
            return Response::json(['error' => 'not_found'], 404);
        }
        if (!$this->contentVisibilityPolicy()->canReadOwnDetail((int)$row['teacher_id'], $this->viewerContext($tid))) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        // Migration 069 — espone i target del fan-out per pre-popolare il modal.
        if (($row['publish_scope'] ?? 'class') === 'classes') {
            $row['target_classes'] = $this->repo->targetClasses((int)$row['id']);
        }
        // Phase 19 — ETag: invalida su updated_at → client può cacheare 10s
        $etag = (string)$row['id'] . ':' . (string)($row['updated_at'] ?? '');
        return Response::json(['ok' => true, 'content' => $row])->withETag($etag, maxAge: 10);
    }

    public function update(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $id = (int)($params['id'] ?? 0);
        if (!$id) {
            return Response::json(['error' => 'invalid_id'], 400);
        }

        $patch = [];
        foreach (['type','subject','indirizzo','classe','topic','title','body_html','visibility'] as $k) {
            if (array_key_exists($k, $req->post)) {
                $col = $k === 'type' ? 'content_type' : ($k === 'subject' ? 'subject_code' : $k);
                $val = $req->post[$k];
                if (in_array($k, ['indirizzo','classe'], true)) {
                    $val = $this->blankToNull($val);
                }
                $patch[$col] = $val;
            }
        }
        if (array_key_exists('metadata', $req->post)) {
            $patch['metadata'] = $this->parseJson($req->post['metadata']);
        }
        // Migration 069 — scope di pubblicazione. target_classes sincronizzati
        // dal repo solo quando publish_scope è presente nel patch.
        if (array_key_exists('publish_scope', $req->post)) {
            $patch['publish_scope']  = (string)$req->post['publish_scope'];
            $patch['target_classes'] = $this->parseTargetClasses($req->post['target_classes'] ?? null);
        }
        try {
            $ok = $this->repo->update($id, $tid, $patch);
            if (!$ok) {
                return Response::json(['error' => 'not_found_or_forbidden'], 404);
            }
            return Response::json(['ok' => true]);
        } catch (Throwable $e) {
            return Response::json(['error' => 'update_failed'], 500);
        }
    }

    public function destroy(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $ok = $this->repo->delete((int)($params['id'] ?? 0), $tid);
        if (!$ok) {
            return Response::json(['error' => 'not_found_or_forbidden'], 404);
        }
        return Response::json(['ok' => true]);
    }

    /**
     * Phase 25 — POST /api/teacher/content/{id}/recategorize
     * Sposta un documento in un'altra categoria (e opzionalmente in un'altra
     * sezione). Aggiorna SOLO `metadata_json.$.category` via JSON_SET +
     * eventuale `section_id`: NON tocca il body_pt (storage separato dual-write),
     * quindi è sicuro contro la perdita di contenuto.
     * Body: { category: string, section_key?: string }
     */
    public function recategorize(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $id = (int)($params['id'] ?? 0);
        $category = trim((string)($req->post['category'] ?? ''));
        // Audit 25.R.31 — cap + whitelist charset sulla categoria: prima
        // arbitraria → auto-pollution della tassonomia 'residue' con valori
        // sporchi/lunghi. Stesso vincolo dei default_categories admin.
        if (
            $id <= 0 || $category === '' || mb_strlen($category) > 32
            || !preg_match('#^[A-Za-z0-9_ -]{1,32}$#', $category)
        ) {
            return Response::json(['error' => 'invalid_params'], 400);
        }
        // Ownership — Audit 25.R.31 (L11): riusa l'helper centralizzato esistente
        // findOwnedRow (gate unico owner-or-superadmin via ContentVisibilityPolicy)
        // invece del check teacher_id inline duplicato.
        [$row, $ownErr] = $this->findOwnedRow($id, $tid);
        if ($ownErr) {
            return $ownErr;
        }

        // Sezione opzionale (migrazione cross-sezione): risolvi section_id.
        $sectionId = null;
        $changeSection = false;
        $sectionKey = trim((string)($req->post['section_key'] ?? ''));
        if ($sectionKey !== '') {
            $changeSection = true;
            $iid = $this->firstInstituteId($tid);
            foreach ((new \App\Repositories\SidebarSectionRepository())->resolveFor($iid, $tid) as $s) {
                if ($s['section_key'] === $sectionKey) {
                    if (!\in_array((string)$row['content_type'], $s['allowed_content_types'], true)) {
                        return Response::json(['error' => 'type_not_allowed_in_section'], 400);
                    }
                    $sectionId = (int)$s['id'];
                    break;
                }
            }
            if ($sectionId === null) {
                return Response::json(['error' => 'section_not_found'], 404);
            }
        }

        try {
            $pdo = \App\Core\Database::connection();
            if ($changeSection) {
                $sql = "UPDATE teacher_content
                        SET metadata_json = JSON_SET(COALESCE(metadata_json, '{}'), '$.category', :cat),
                            section_id = :sid
                        WHERE id = :id AND teacher_id = :tid";
                $st = $pdo->prepare($sql);
                $st->execute([':cat' => $category, ':sid' => $sectionId, ':id' => $id, ':tid' => $tid]);
            } else {
                $sql = "UPDATE teacher_content
                        SET metadata_json = JSON_SET(COALESCE(metadata_json, '{}'), '$.category', :cat)
                        WHERE id = :id AND teacher_id = :tid";
                $st = $pdo->prepare($sql);
                $st->execute([':cat' => $category, ':id' => $id, ':tid' => $tid]);
            }
            return Response::json(['ok' => true]);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'update_failed'], 500);
        }
    }

    /** Trova la row + ACL (owner o super-admin). @return array{0:?array,1:?Response} */
    private function findOwnedRow(int $id, int $tid): array
    {
        $row = $this->repo->find($id);
        if (!$row) {
            return [null, Response::json(['error' => 'not_found'], 404)];
        }
        // Pilota #1 — gate unico: stesso owner-OR-superadmin di canExportOwn
        // (siti 322/662). Prima era una 4a copia inline dello stesso predicato.
        $ctx = new \App\Domain\ViewerContext(
            role: \App\Domain\Role::tryFromString((string)\App\Core\Auth::role()),
            teacherId: $tid,
        );
        if (
            !(new \App\Domain\ContentVisibilityPolicy())->canExportOwn(
                (int)$row['teacher_id'],
                $ctx,
                \App\Services\AclPolicy::isSuperAdmin()
            )
        ) {
            return [null, Response::json(['error' => 'forbidden'], 403)];
        }
        return [$row, null];
    }

    // ─────── Helpers ───────

    private function teacherId(): int
    {
        $u = Auth::user();
        if (!$u) {
            return 0;
        }
        return \App\Support\TeacherContextResolver::userIdFromUsername((string)($u['username'] ?? ''));
    }

    private function dbReady(): bool
    {
        return (bool)Config::get('database.enabled') && Database::isAvailable();
    }

    private function firstInstituteId(int $teacherId): int
    {
        return \App\Support\TeacherContextResolver::firstInstituteId($teacherId);
    }

    /**
     * Pilota #1 — gate UNICO ownership/export.
     * Le rotte di questo controller sono teacher+ (middleware): il viewer è
     * sempre un docente con user_id risolto. Costruisce il ViewerContext
     * esplicito che la policy consuma (nessun SESSION/DB nel core).
     */
    private function contentVisibilityPolicy(): \App\Domain\ContentVisibilityPolicy
    {
        return new \App\Domain\ContentVisibilityPolicy();
    }

    private function viewerContext(int $teacherId): \App\Domain\ViewerContext
    {
        return \App\Domain\ViewerContext::forTeacher($teacherId, $this->firstInstituteId($teacherId));
    }

    private function cleanType(mixed $v): mixed
    {
        if ($v === null || $v === '') {
            return null;
        }
        return (string)$v;
    }

    private function blankToNull(mixed $v): ?string
    {
        $s = is_string($v) ? trim($v) : null;
        return ($s === null || $s === '') ? null : $s;
    }

    private function parseJson(mixed $v): array
    {
        if (is_array($v)) {
            return $v;
        }
        if (!is_string($v) || $v === '') {
            return [];
        }
        $d = json_decode($v, true);
        return is_array($d) ? $d : [];
    }

    /**
     * Migration 069 — normalizza target_classes a una lista di "indirizzo|classe".
     * Accetta JSON array (di stringhe "ind|cls" o oggetti {indirizzo,classe}).
     * Codici memorizzati VERBATIM (no canonicalize): provengono dai codici
     * dinamici già esposti da /api/teacher/my-classes.
     */
    private function parseTargetClasses(mixed $v): array
    {
        $arr = $this->parseJson($v);
        $out = [];
        foreach ($arr as $item) {
            if (is_array($item)) {
                $ind = trim((string)($item['indirizzo'] ?? ''));
                $cls = trim((string)($item['classe'] ?? ''));
            } else {
                [$ind, $cls] = array_pad(explode('|', (string)$item, 2), 2, '');
                $ind = trim($ind);
                $cls = trim($cls);
            }
            if ($ind !== '' && $cls !== '') {
                $out[] = ['indirizzo' => $ind, 'classe' => $cls];
            }
        }
        return $out;
    }

    /**
     * GET /api/teacher/my-classes
     * Coppie (indirizzo, classe) DISTINTE del docente — popola il multi-select
     * del fan-out. Fonte dinamica: i suoi teacher_content esistenti.
     */
    public function myClasses(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        return Response::json(['ok' => true, 'classes' => $this->repo->teacherClassPairs($tid)]);
    }
}
