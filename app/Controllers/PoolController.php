<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\TeacherContentRepository;
use App\Services\Crypto\TeacherCryptoService;
use App\Services\Maps\MapBlobStore;
use App\Services\Sharing\SharedContentPolicy;
use App\Support\TeacherContextResolver;
use PDO;
use Throwable;

/**
 * G22.S21 Fase C — Pool browse + recover (catalog ownership refactor).
 *
 * Endpoint:
 *   GET  /api/teacher/pool/materials       — browse materiali condivisi di colleghi
 *   POST /api/teacher/pool/recover/{id}    — clona contenuto nel proprio account
 *
 * Eligibilita': content e' visibile se
 *   - teacher_content.shared_with_pool = 1, OPPURE
 *   - curriculum_entries(kind=materie, subject_id).shared_with_pool = 1
 * AND owner_teacher e actor sono nello stesso istituto.
 */
final class PoolController
{
    /**
     * GET /api/teacher/pool/materials
     * Query params:
     *   - content_type: mappa|esercizio|verifica|...  (opzionale, filtro)
     *   - subject_code: MAT|FIS|... (opzionale)
     *   - owner_id: int (opzionale)
     *
     * Risposta: { ok: true, items: [...] }
     */
    public function materials(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $actor = (int)(Auth::user()['id'] ?? 0);
        if ($actor <= 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }

        // G22.S22 — Pool su TUTTI gli istituti dell'attore (non solo il primo).
        // L'institute_id usato come scope nel response e' indicativo (primo).
        $stmt = Database::connection()->prepare(
            'SELECT institute_id FROM teacher_institutes WHERE user_id = ? ORDER BY institute_id'
        );
        $stmt->execute([$actor]);
        $actorInstitutes = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (!$actorInstitutes) {
            return Response::json(['ok' => true, 'items' => []]);
        }

        $contentType = (string)($req->query['content_type'] ?? '');
        $subjectCode = (string)($req->query['subject_code'] ?? '');
        $ownerId     = (int)($req->query['owner_id'] ?? 0);

        // G22.S25 — Carica id gruppi dei quali sono membro (per eligibility
        // grant target_type=group).
        $stmt = Database::connection()->prepare(
            'SELECT group_id FROM share_group_members WHERE member_user_id = ?'
        );
        $stmt->execute([$actor]);
        $actorGroups = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        // Match: la materia (ce.institute_id) deve essere in uno degli istituti
        // dell'attore E l'owner del contenuto deve essere collegato a quello
        // stesso istituto. Uso DISTINCT su tc.id per evitare duplicati da
        // istituti in comune multipli.
        $instPlaceholders = implode(',', array_fill(0, count($actorInstitutes), '?'));
        // G22.S25 — Eligibility estesa con content_shares (grants espliciti):
        //   (a) shared_with_pool=1 + actor in istituto del materia
        //   (b) EXISTS grant target_type='institute' AND target_id IN actor_insts
        //   (c) EXISTS grant target_type='teacher' AND target_id = actor
        //   (d) EXISTS grant target_type='group' AND target_id IN actor_groups
        //
        // ── PARITÀ con App\Services\Sharing\SharedContentPolicy::canReadContent()
        //    (pilota #1 ContentVisibilityPolicy — sito "Pool eligibility SQL", NON assorbito).
        //
        //    Questo WHERE è la versione SET-BASED (bulk, una query) della STESSA
        //    decisione che `recover()` applica row-wise via
        //    SharedContentPolicy->canReadContent() (vedi righe ~450). Equivalenza
        //    clausola-per-clausola, behavior-preserving:
        //      • cross-institute hard-gate (shareInstitute) ⇔ qui le condizioni
        //        "ce.institute_id IN (actor_insts)" + "ti_owner.institute_id = ce.institute_id"
        //        nel $where: owner e actor devono condividere l'istituto della materia.
        //      • (shared_with_pool OR materiaShared) ⇔ "(tc.shared_with_pool=1 OR ce.shared_with_pool=1)".
        //      • hasAnyGrantFor() (institute/teacher/group) ⇔ le tre EXISTS su content_shares (b)(c)(d).
        //      • esclusione "own content" (canReadContent: actor==owner gestito a parte) ⇔ "tc.teacher_id <> ?".
        //      • archived non recuperabile ⇔ "tc.visibility <> 'archived'" nel $where.
        //
        //    Perché NON row-wise (perf): convertire questa lista a una chiamata
        //    canReadContent() per riga = O(n) round-trip DB (shareInstitute +
        //    hasAnyGrantFor per ogni candidato). Sul target rurale 3G è una
        //    regressione; il SET-based resta. Modifiche all'eligibility vanno
        //    applicate a ENTRAMBI i siti per mantenere la parità.
        $groupClause = $actorGroups
            ? "OR EXISTS (SELECT 1 FROM content_shares cs WHERE cs.content_source='teacher_content' AND cs.content_id=tc.id AND cs.target_type='group' AND cs.target_id IN (" . implode(',', array_fill(0, count($actorGroups), '?')) . "))"
            : '';
        $eligibility = "((tc.shared_with_pool = 1 OR ce.shared_with_pool = 1)"
            . " OR EXISTS (SELECT 1 FROM content_shares cs WHERE cs.content_source='teacher_content' AND cs.content_id=tc.id AND cs.target_type='institute' AND cs.target_id IN ($instPlaceholders))"
            . " OR EXISTS (SELECT 1 FROM content_shares cs WHERE cs.content_source='teacher_content' AND cs.content_id=tc.id AND cs.target_type='teacher' AND cs.target_id = ?)"
            . " $groupClause)";
        $where = [
            'tc.teacher_id <> ?',
            'tc.visibility <> "archived"',
            "ce.institute_id IN ($instPlaceholders)",
            'ti_owner.institute_id = ce.institute_id',
            $eligibility,
        ];
        $args = [
            $actor,                       // tc.teacher_id <> ?
            ...$actorInstitutes,          // ce.institute_id IN (...)
            ...$actorInstitutes,          // eligibility institute grants
            $actor,                       // eligibility teacher grant
            ...$actorGroups,              // eligibility group grants (may be empty)
        ];

        if ($contentType !== '' && \in_array($contentType, TeacherContentRepository::TYPES, true)) {
            $where[] = 'tc.content_type = ?';
            $args[] = $contentType;
        }
        if ($subjectCode !== '' && preg_match('/^[A-Z]{2,8}$/', $subjectCode)) {
            $where[] = 'ce.code = ?';
            $args[] = $subjectCode;
        }
        if ($ownerId > 0) {
            $where[] = 'tc.teacher_id = ?';
            $args[] = $ownerId;
        }

        // G22.S25 — flag already_recovered: l'attore ha già una row teacher_content
        // con source_content_id puntante a questo item.
        $sql = "SELECT DISTINCT
                    tc.id, tc.content_type, tc.title, tc.topic,
                    tc.subject_id, tc.shared_with_pool AS row_shared,
                    tc.created_at, tc.updated_at,
                    tc.teacher_id AS owner_id,
                    ce.code  AS subject_code,
                    ce.label AS subject_label,
                    ce.shared_with_pool AS materia_shared,
                    ce.institute_id AS materia_institute_id,
                    COALESCE(u.first_name, u.username, '') AS owner_first,
                    COALESCE(u.last_name, '') AS owner_last,
                    (SELECT MIN(tc_my.id) FROM teacher_content tc_my
                       WHERE tc_my.teacher_id = ? AND tc_my.source_content_id = tc.id) AS my_recovered_id
                  FROM teacher_content tc
                  JOIN curriculum_entries ce ON ce.id = tc.subject_id
                  JOIN users u ON u.id = tc.teacher_id
                  JOIN teacher_institutes ti_owner ON ti_owner.user_id = tc.teacher_id
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY tc.updated_at DESC
                 LIMIT 500";

        // Prepend $actor for the my_recovered_id subquery placeholder
        array_unshift($args, $actor);

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($args);
        $instituteId = $actorInstitutes[0]; // first for response indication
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $name = trim((string)($r['owner_first'] ?? '') . ' ' . (string)($r['owner_last'] ?? ''));
            $items[] = [
                'source'        => 'teacher_content',
                'id'            => (int)$r['id'],
                'content_type'  => (string)$r['content_type'],
                'title'         => (string)$r['title'],
                'topic'         => (string)($r['topic'] ?? ''),
                'subject_code'  => (string)$r['subject_code'],
                'subject_label' => (string)$r['subject_label'],
                'owner_id'      => (int)$r['owner_id'],
                'owner_name'    => $name !== '' ? $name : ('user#' . $r['owner_id']),
                'updated_at'    => (string)($r['updated_at'] ?? ''),
                'via_materia'   => (int)($r['materia_shared'] ?? 0) === 1,
                'already_recovered' => $r['my_recovered_id'] !== null && (int)$r['my_recovered_id'] > 0,
                'my_recovered_id'   => $r['my_recovered_id'] !== null ? (int)$r['my_recovered_id'] : null,
            ];
        }

        // G22.S23 — Aggiunge verifica_documents shared_with_pool=1 (vere
        // verifiche TEX/PDF). Distinte da teacher_content: sono blob TEX/PDF
        // generati. content_type virtuale 'verifica_doc' per distinguerle
        // lato UI dal placeholder teacher_content content_type='verifica'.
        if ($contentType === '' || $contentType === 'verifica' || $contentType === 'verifica_doc') {
            // G22.S25 — Eligibility analoga ai teacher_content per verifica_documents.
            $vdGroupClause = $actorGroups
                ? "OR EXISTS (SELECT 1 FROM content_shares cs WHERE cs.content_source='verifica_documents' AND cs.content_id=vd.id AND cs.target_type='group' AND cs.target_id IN (" . implode(',', array_fill(0, count($actorGroups), '?')) . "))"
                : '';
            $vdEligibility = "(vd.shared_with_pool = 1"
                . " OR EXISTS (SELECT 1 FROM content_shares cs WHERE cs.content_source='verifica_documents' AND cs.content_id=vd.id AND cs.target_type='institute' AND cs.target_id IN ($instPlaceholders))"
                . " OR EXISTS (SELECT 1 FROM content_shares cs WHERE cs.content_source='verifica_documents' AND cs.content_id=vd.id AND cs.target_type='teacher' AND cs.target_id = ?)"
                . " $vdGroupClause)";
            $vdWhere = [
                'vd.teacher_id <> ?',
                $vdEligibility,
                "ce.institute_id IN ($instPlaceholders)",
                'ti_owner.institute_id = ce.institute_id',
            ];
            $vdArgs = [
                $actor,
                ...$actorInstitutes,
                $actor,
                ...$actorGroups,
                ...$actorInstitutes,
            ];
            if ($subjectCode !== '' && preg_match('/^[A-Z]{2,8}$/', $subjectCode)) {
                $vdWhere[] = 'ce.code = ?';
                $vdArgs[] = $subjectCode;
            }
            if ($ownerId > 0) {
                $vdWhere[] = 'vd.teacher_id = ?';
                $vdArgs[] = $ownerId;
            }
            $vdSql = "SELECT DISTINCT
                          vd.id, vd.title, vd.variant, vd.batch_id,
                          vd.updated_at, vd.teacher_id AS owner_id,
                          ce.code  AS subject_code,
                          ce.label AS subject_label,
                          ce.institute_id AS materia_institute_id,
                          COALESCE(u.first_name, u.username, '') AS owner_first,
                          COALESCE(u.last_name, '') AS owner_last
                        FROM verifica_documents vd
                        JOIN curriculum_entries ce ON ce.id = vd.materia_id
                        JOIN users u ON u.id = vd.teacher_id
                        JOIN teacher_institutes ti_owner ON ti_owner.user_id = vd.teacher_id
                       WHERE " . implode(' AND ', $vdWhere) . "
                       ORDER BY vd.updated_at DESC
                       LIMIT 200";
            $vdStmt = Database::connection()->prepare($vdSql);
            $vdStmt->execute($vdArgs);
            foreach ($vdStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $name = trim((string)($r['owner_first'] ?? '') . ' ' . (string)($r['owner_last'] ?? ''));
                $items[] = [
                    'source'        => 'verifica_documents',
                    'id'            => (int)$r['id'],
                    'content_type'  => 'verifica_doc',
                    'title'         => trim((string)$r['title']
                        . ($r['variant'] !== '' ? ' [' . $r['variant'] . ']' : '')),
                    'topic'         => '',
                    'subject_code'  => (string)$r['subject_code'],
                    'subject_label' => (string)$r['subject_label'],
                    'owner_id'      => (int)$r['owner_id'],
                    'owner_name'    => $name !== '' ? $name : ('user#' . $r['owner_id']),
                    'updated_at'    => (string)($r['updated_at'] ?? ''),
                    'via_materia'   => false,
                ];
            }
        }

        return Response::json(['ok' => true, 'institute_id' => $instituteId, 'items' => $items]);
    }

    /**
     * G22.S24 — GET /api/teacher/pool/my-shares
     * Lista dei MIEI contenuti condivisi (teacher_content shared_with_pool=1
     * + verifica_documents shared_with_pool=1). Permette al docente di
     * controllare cosa ha esposto e di rimuoverlo dalla condivisione.
     */
    public function myShares(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $actor = (int)(Auth::user()['id'] ?? 0);
        if ($actor <= 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $items = [];
        $pdo = Database::connection();

        // teacher_content shared (esercizi, mappe, verifiche-container)
        $stmt = $pdo->prepare(
            "SELECT tc.id, tc.content_type, tc.title, tc.topic,
                    tc.subject_code, tc.updated_at, tc.shared_with_pool,
                    ce.label AS subject_label, ce.institute_id AS materia_institute_id,
                    COALESCE(i.name, '') AS institute_name,
                    (SELECT COUNT(*) FROM content_shares cs
                       WHERE cs.content_source='teacher_content' AND cs.content_id=tc.id) AS grants_count
               FROM teacher_content tc
               LEFT JOIN curriculum_entries ce ON ce.id = tc.subject_id
               LEFT JOIN institutes i ON i.id = ce.institute_id
              WHERE tc.teacher_id = ? AND (tc.shared_with_pool = 1 OR EXISTS (
                    SELECT 1 FROM content_shares cs
                     WHERE cs.content_source='teacher_content' AND cs.content_id=tc.id))
              ORDER BY tc.updated_at DESC"
        );
        $stmt->execute([$actor]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $items[] = [
                'source'         => 'teacher_content',
                'id'             => (int)$r['id'],
                'content_type'   => (string)$r['content_type'],
                'title'          => (string)$r['title'],
                'topic'          => (string)($r['topic'] ?? ''),
                'subject_code'   => (string)($r['subject_code'] ?? ''),
                'subject_label'  => (string)($r['subject_label'] ?? ''),
                'institute_id'   => $r['materia_institute_id'] ? (int)$r['materia_institute_id'] : null,
                'institute_name' => (string)($r['institute_name'] ?? ''),
                'updated_at'     => (string)($r['updated_at'] ?? ''),
                'shared_with_pool' => (int)($r['shared_with_pool'] ?? 0) === 1,
                'grants_count'   => (int)($r['grants_count'] ?? 0),
            ];
        }

        // verifica_documents shared (file TEX/PDF)
        $stmt = $pdo->prepare(
            "SELECT vd.id, vd.title, vd.variant, vd.updated_at, vd.shared_with_pool,
                    ce.code AS subject_code, ce.label AS subject_label,
                    ce.institute_id, COALESCE(i.name, '') AS institute_name,
                    (SELECT COUNT(*) FROM content_shares cs
                       WHERE cs.content_source='verifica_documents' AND cs.content_id=vd.id) AS grants_count
               FROM verifica_documents vd
               LEFT JOIN curriculum_entries ce ON ce.id = vd.materia_id
               LEFT JOIN institutes i ON i.id = ce.institute_id
              WHERE vd.teacher_id = ? AND (vd.shared_with_pool = 1 OR EXISTS (
                    SELECT 1 FROM content_shares cs
                     WHERE cs.content_source='verifica_documents' AND cs.content_id=vd.id))
              ORDER BY vd.updated_at DESC"
        );
        $stmt->execute([$actor]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $items[] = [
                'source'         => 'verifica_documents',
                'id'             => (int)$r['id'],
                'content_type'   => 'verifica_doc',
                'title'          => trim((string)$r['title']
                    . ($r['variant'] !== '' ? ' [' . $r['variant'] . ']' : '')),
                'topic'          => '',
                'subject_code'   => (string)($r['subject_code'] ?? ''),
                'subject_label'  => (string)($r['subject_label'] ?? ''),
                'institute_id'   => $r['institute_id'] ? (int)$r['institute_id'] : null,
                'institute_name' => (string)($r['institute_name'] ?? ''),
                'updated_at'     => (string)($r['updated_at'] ?? ''),
                'shared_with_pool' => (int)($r['shared_with_pool'] ?? 0) === 1,
                'grants_count'   => (int)($r['grants_count'] ?? 0),
            ];
        }

        return Response::json(['ok' => true, 'items' => $items, 'count' => count($items)]);
    }

    /**
     * G22.S24 — POST /api/teacher/pool/unshare
     * Body JSON: { items: [{ source: 'teacher_content'|'verifica_documents', id: int }, ...] }
     * Rimuove la condivisione per gli items dati (owner check). Bulk-safe.
     */
    public function unshare(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $actor = (int)(Auth::user()['id'] ?? 0);
        if ($actor <= 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $ctype = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        $jsonBody = [];
        if (str_contains($ctype, 'application/json')) {
            $raw = (string)file_get_contents('php://input');
            $jsonBody = json_decode($raw, true) ?: [];
        }
        $items = is_array($jsonBody['items'] ?? null) ? $jsonBody['items'] : [];
        if (!$items) {
            return Response::json(['error' => 'no_items'], 400);
        }

        $pdo = Database::connection();
        $tcIds = [];
        $vdIds = [];
        foreach ($items as $it) {
            $src = (string)($it['source'] ?? '');
            $id  = (int)($it['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if ($src === 'teacher_content') {
                $tcIds[] = $id;
            } elseif ($src === 'verifica_documents') {
                $vdIds[] = $id;
            }
        }
        $unshared = ['teacher_content' => 0, 'verifica_documents' => 0];
        if ($tcIds) {
            $ph = implode(',', array_fill(0, count($tcIds), '?'));
            $stmt = $pdo->prepare("UPDATE teacher_content_data SET shared_with_pool = 0
                                    WHERE teacher_id = ? AND id IN ($ph)");
            $stmt->execute([$actor, ...$tcIds]);
            $unshared['teacher_content'] = $stmt->rowCount();
        }
        if ($vdIds) {
            $ph = implode(',', array_fill(0, count($vdIds), '?'));
            $stmt = $pdo->prepare("UPDATE verifica_documents_data SET shared_with_pool = 0
                                    WHERE teacher_id = ? AND id IN ($ph)");
            $stmt->execute([$actor, ...$vdIds]);
            $unshared['verifica_documents'] = $stmt->rowCount();
        }
        return Response::json([
            'ok' => true,
            'unshared' => $unshared,
            'total' => $unshared['teacher_content'] + $unshared['verifica_documents'],
        ]);
    }

    /**
     * POST /api/teacher/pool/recover/{id}
     * Body:
     *   target_subject_id=INT   (mia materia)        — obbligatorio
     *   target_indirizzo_id=INT (catalog istituto)   — opzionale
     *   target_classe_id=INT    (catalog istituto)   — opzionale
     *
     * Effetto: clona il contenuto sotto il mio account, re-cifrando i blob
     * con la mia KEK. Imposta source_content_id per audit trail. Se sono
     * forniti target_indirizzo_id / target_classe_id, popola anche quelli
     * (utile per la visibilità nei select indirizzo/classe scope-aware).
     */
    public function recover(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $actor = (int)(Auth::user()['id'] ?? 0);
        if ($actor <= 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        // G22.S22 — accetta sia form-encoded ($_POST) sia JSON body
        // (Content-Type: application/json). Il framework non auto-decodifica.
        // NB: Content-Type in PHP è $_SERVER['CONTENT_TYPE'] (senza prefix HTTP_).
        $ctype = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        $jsonBody = [];
        if (str_contains($ctype, 'application/json')) {
            $raw = (string)file_get_contents('php://input');
            $jsonBody = json_decode($raw, true) ?: [];
        }
        $sourceId       = (int)($params['id'] ?? 0);
        $targetSubject  = (int)($req->post['target_subject_id']  ?? $jsonBody['target_subject_id']  ?? 0);
        $targetIndirizzo = (int)($req->post['target_indirizzo_id'] ?? $jsonBody['target_indirizzo_id'] ?? 0) ?: null;
        $targetClasse   = (int)($req->post['target_classe_id']    ?? $jsonBody['target_classe_id']    ?? 0) ?: null;
        if ($sourceId <= 0 || $targetSubject <= 0) {
            return Response::json(['error' => 'invalid_params'], 400);
        }

        $pdo = Database::connection();

        // 1) Validate source: visible to me via pool eligibility (institute + shared flag).
        $stmt = $pdo->prepare(
            "SELECT tc.*, ce.code AS subject_code, ce.shared_with_pool AS materia_shared
               FROM teacher_content tc
               JOIN curriculum_entries ce ON ce.id = tc.subject_id
              WHERE tc.id = ?
              LIMIT 1"
        );
        $stmt->execute([$sourceId]);
        $src = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$src) {
            return Response::json(['error' => 'source_not_found'], 404);
        }
        $ownerId = (int)$src['teacher_id'];
        if ($ownerId === $actor) {
            return Response::json(['error' => 'cannot_recover_own_content'], 400);
        }
        $rowShared     = (int)($src['shared_with_pool'] ?? 0) === 1;
        $materiaShared = (int)($src['materia_shared'] ?? 0) === 1;
        // G22.S25 — single eligibility check (cross-institute gate + shared/grants)
        // via policy unificata. Sostituisce 3 controlli duplicati separati.
        // Versione ROW-WISE della stessa decisione del WHERE bulk in materials()
        // (vedi blocco "PARITÀ con SharedContentPolicy::canReadContent()"):
        // qualsiasi modifica all'eligibility va replicata in entrambi i siti.
        $policy = new SharedContentPolicy();
        if (!$policy->canReadContent($actor, 'teacher_content', $sourceId, $ownerId, $rowShared, $materiaShared)) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        // 2) Validate target_subject_id: deve essere mia materia (owner_user_id=me)
        $stmt = $pdo->prepare(
            "SELECT id, code, institute_id FROM curriculum_entries
              WHERE id = ? AND kind = 'materie' AND owner_user_id = ?
              LIMIT 1"
        );
        $stmt->execute([$targetSubject, $actor]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$target) {
            return Response::json(['error' => 'target_subject_invalid'], 400);
        }
        $targetInstId = (int)$target['institute_id'];

        // 2b) Validate target_indirizzo_id / target_classe_id: devono appartenere
        // allo stesso istituto della materia target.
        $indirizzoCode = null;
        $classeCode = null;
        if ($targetIndirizzo !== null) {
            $s = $pdo->prepare(
                "SELECT code FROM curriculum_entries
                  WHERE id = ? AND kind = 'indirizzi' AND institute_id = ? AND active = 1
                  LIMIT 1"
            );
            $s->execute([$targetIndirizzo, $targetInstId]);
            $indirizzoCode = $s->fetchColumn();
            if ($indirizzoCode === false) {
                return Response::json(['error' => 'target_indirizzo_invalid'], 400);
            }
        }
        if ($targetClasse !== null) {
            $s = $pdo->prepare(
                "SELECT code FROM curriculum_entries
                  WHERE id = ? AND kind = 'classi' AND institute_id = ? AND active = 1
                  LIMIT 1"
            );
            $s->execute([$targetClasse, $targetInstId]);
            $classeCode = $s->fetchColumn();
            if ($classeCode === false) {
                return Response::json(['error' => 'target_classe_invalid'], 400);
            }
        }

        // 3) Decifra body fields nel context dell'owner via repo->find
        $repo = new TeacherContentRepository();
        $original = $repo->find($sourceId);
        if (!$original) {
            return Response::json(['error' => 'source_unreadable'], 500);
        }
        if (!empty($original['_crypto_error'])) {
            return Response::json([
                'error' => 'source_crypto_error',
                'detail' => $original['_crypto_error'],
            ], 500);
        }

        // 4) Clone via repo->create (re-cifra con KEK actor).
        $contentType = (string)$original['content_type'];
        $title       = (string)($original['title'] ?? '');
        $topic       = (string)($original['topic'] ?? '');
        $bodyHtml    = isset($original['body_html']) ? (string)$original['body_html'] : null;
        $metadata    = is_array($original['metadata'] ?? null) ? $original['metadata'] : null;

        try {
            $newId = $repo->create([
                'teacher_id'   => $actor,
                'content_type' => $contentType,
                'subject_code' => (string)$target['code'],
                // G22.S22 — eredita indirizzo/classe scelti nel popup recover.
                // Permette al contenuto clonato di apparire nei select sidebar
                // scope-aware (indirizzo/classe selezionati nel dropdown topbar).
                'indirizzo'    => $indirizzoCode ?: null,
                'classe'       => $classeCode ?: null,
                'topic'        => $topic,
                'title'        => $title . ' (importata da ' . $this->ownerNameFor($ownerId) . ')',
                'body_html'    => $bodyHtml,
                'metadata'     => $metadata,
                'visibility'   => 'draft',
            ]);
        } catch (Throwable $e) {
            return Response::json(['error' => 'clone_failed', 'detail' => $e->getMessage()], 500);
        }

        // 5) source_content_id audit + per-content shared off (private by default).
        $pdo->prepare(
            'UPDATE teacher_content_data
                SET source_content_id = ?,
                    shared_with_pool = 0
              WHERE id = ?'
        )->execute([$sourceId, $newId]);

        // 6) Per mappa: clona blob map_blob_path (decrypt owner KEK + encrypt actor KEK).
        if ($contentType === 'mappa' && !empty($original['map_blob_path'])) {
            try {
                $store = new MapBlobStore(new TeacherCryptoService());
                $plain = $store->get($ownerId, (string)$original['map_blob_path']);
                $newPath = $store->put($actor, $plain);
                $pdo->prepare(
                    'UPDATE teacher_content_data
                        SET map_blob_path = ?,
                            map_mime = ?
                      WHERE id = ?'
                )->execute([$newPath, $original['map_mime'] ?? 'application/xml', $newId]);
            } catch (Throwable $e) {
                // Rollback: cancella il row creato, segnala
                $pdo->prepare('DELETE FROM teacher_content_data WHERE id = ?')->execute([$newId]);
                return Response::json([
                    'error' => 'blob_clone_failed',
                    'detail' => $e->getMessage(),
                ], 500);
            }
        }

        // 6b) G22.S22 — Per esercizio: clona il file contract.json sotto il
        // path di Marco (institutes/{actorInst}/private/{actor}/eser/{basename})
        // e aggiorna scope dentro al JSON + metadata.contract_key sulla nuova
        // riga. Senza questo il content e' vuoto al render (legacy storage).
        // G22.S22/S25 — Clone contract.json sotto path destinatario.
        // Per esercizio: path institutes/{inst}/private/{tid}/eser/{basename}.
        // Per verifica: path institutes/{inst}/private/{tid}/verifiche/{basename}.
        // Naming convention sotto cartelle diverse per tipo (legacy).
        if ($contentType === 'esercizio' || $contentType === 'verifica') {
            try {
                $this->cloneContentContract($ownerId, $original, $actor, $newId, $target, $contentType);
            } catch (Throwable $e) {
                error_log('[pool.recover] contract clone failed for #' . $newId
                    . ': ' . $e->getMessage());
            }
        }

        return Response::json([
            'ok' => true,
            'new_id' => $newId,
            'content_type' => $contentType,
            // verifica: TEX/PDF blob non clonati (verifica_documents row separata).
            // Il contract.json (gruppi + items) è invece clonato come per gli esercizi.
            'verifica_tex_pdf_skipped' => $contentType === 'verifica',
        ]);
    }

    private function ownerNameFor(int $ownerId): string
    {
        if ($ownerId <= 0) {
            return 'collega';
        }
        $stmt = Database::connection()->prepare(
            "SELECT COALESCE(NULLIF(TRIM(CONCAT_WS(' ', first_name, last_name)), ''), username) AS n
               FROM users WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$ownerId]);
        $name = $stmt->fetchColumn();
        return $name !== false && $name !== null ? (string)$name : ('user#' . $ownerId);
    }

    private function dbReady(): bool
    {
        return Config::get('database.enabled') && Database::isAvailable();
    }

    /**
     * G22.S22 — Clona file contract.json di un esercizio sotto il path
     * del docente destinatario, aggiornando lo scope JSON interno e la
     * metadata.contract_key della nuova riga DB.
     *
     * Convenzione path legacy:
     *   institutes/{instId}/private/{teacherId}/eser/{basename}
     * dove basename = {topic}_{subj}-{titleSlug}-{ind}{cls}.contract.json
     *
     * @param int   $ownerId    teacher_id dell'esercizio sorgente
     * @param array $original   row originale (subject_code, indirizzo, classe, topic, title)
     * @param int   $actor      teacher_id destinatario
     * @param int   $newId      id della nuova teacher_content
     * @param array $target     row materia di destinazione (code, institute_id)
     */
    private function cloneContentContract(
        int $ownerId,
        array $original,
        int $actor,
        int $newId,
        array $target,
        string $contentType,
    ): void {
        $root = dirname(__DIR__, 2) . '/storage/objects';
        $slug = static fn(string $s): string => preg_replace('/[^A-Za-z0-9._-]+/', '_', $s) ?? '_';

        $ownerInstId = $this->resolveOwnerInstitute($ownerId, $original);
        if ($ownerInstId === 0) {
            throw new \RuntimeException('owner_institute_unknown');
        }
        $subjCode = (string)($original['subject_code'] ?? '');
        $indCode  = (string)($original['indirizzo']    ?? '');
        $clsCode  = (string)($original['classe']       ?? '');
        $topic    = (string)($original['topic']        ?? '');
        $title    = (string)($original['title']        ?? '');

        // G22.S25 — Convenzione naming/path diversa per type:
        //  - esercizio: {topic}_{subj}-{slug(title)}-{ind}{cls}.contract.json
        //               sotto institutes/{inst}/private/{tid}/eser/
        //  - verifica:  {subj}-{slug(title)}-ver.contract.json
        //               sotto institutes/{inst}/private/{tid}/verifiche/
        // (legacy: gli esercizi includono topic+sezione, le verifiche solo subject+title)
        $dirSlug = $contentType === 'verifica' ? 'verifiche' : 'eser';
        if ($contentType === 'verifica') {
            $basename = $subjCode . '-' . $slug($title) . '-ver.contract.json';
        } else {
            $basename = $topic . '_' . $subjCode . '-' . $slug($title) . '-' . $indCode . $clsCode . '.contract.json';
        }

        $srcRel = sprintf('institutes/%d/private/%d/%s/%s', $ownerInstId, $ownerId, $dirSlug, $basename);
        $srcAbs = $root . '/' . $srcRel;
        if (!is_file($srcAbs)) {
            throw new \RuntimeException('source_contract_not_found: ' . $srcRel);
        }
        $bytes = @file_get_contents($srcAbs);
        if ($bytes === false) {
            throw new \RuntimeException('source_contract_read_failed');
        }
        $data = json_decode($bytes, true);
        if (!is_array($data)) {
            throw new \RuntimeException('source_contract_json_invalid');
        }

        $actorInstId = (int)$target['institute_id'];
        $data['scope'] = array_merge($data['scope'] ?? [], [
            'teacher_id'   => $actor,
            'institute_id' => $actorInstId,
        ]);
        $data['_recovered_from'] = [
            'owner_teacher_id'   => $ownerId,
            'owner_institute_id' => $ownerInstId,
            'recovered_at'       => date(DATE_ATOM),
        ];

        $dstRel = sprintf('institutes/%d/private/%d/%s/%s', $actorInstId, $actor, $dirSlug, $basename);
        $dstAbs = $root . '/' . $dstRel;
        $dstDir = dirname($dstAbs);
        if (!is_dir($dstDir) && !@mkdir($dstDir, 0o775, true) && !is_dir($dstDir)) {
            throw new \RuntimeException('dst_mkdir_failed');
        }
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // G22.S25 — atomic write: tmp file + rename. Evita stato corrotto
        // se il processo fallisce a metà write (es. disco pieno, kill -9).
        $tmp = $dstAbs . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $payload) === false) {
            throw new \RuntimeException('dst_write_failed');
        }
        if (!@rename($tmp, $dstAbs)) {
            @unlink($tmp);
            throw new \RuntimeException('dst_rename_failed');
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT metadata_json FROM teacher_content WHERE id = ?');
        $stmt->execute([$newId]);
        $meta = json_decode((string)$stmt->fetchColumn(), true);
        if (!is_array($meta)) {
            $meta = [];
        }
        $meta['contract_key'] = $dstRel;
        $pdo->prepare('UPDATE teacher_content_data SET metadata_json = ? WHERE id = ?')
            ->execute([json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $newId]);
    }

    /**
     * G22.S22 — Risolve institute_id dell'esercizio sorgente: prima dai
     * FK del row (subject_id → curriculum_entries.institute_id) poi
     * fallback al primo istituto del docente proprietario.
     */
    private function resolveOwnerInstitute(int $ownerId, array $original): int
    {
        $pdo = Database::connection();
        $subjId = (int)($original['subject_id'] ?? 0);
        if ($subjId > 0) {
            $stmt = $pdo->prepare('SELECT institute_id FROM curriculum_entries WHERE id = ? LIMIT 1');
            $stmt->execute([$subjId]);
            $iid = (int)$stmt->fetchColumn();
            if ($iid > 0) {
                return $iid;
            }
        }
        $stmt = $pdo->prepare(
            'SELECT institute_id FROM teacher_institutes WHERE user_id = ? ORDER BY institute_id LIMIT 1'
        );
        $stmt->execute([$ownerId]);
        return (int)$stmt->fetchColumn();
    }
}
