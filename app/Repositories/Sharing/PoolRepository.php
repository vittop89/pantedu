<?php

declare(strict_types=1);

namespace App\Repositories\Sharing;

use App\Core\Database;
use PDO;

/**
 * Accesso ai dati del pool di condivisione (G22.S22-S25): materiali che un
 * docente puo' recuperare dai colleghi, le proprie condivisioni, il recupero.
 *
 * Estratto da PoolController il 2026-09-04 (revisione architetturale,
 * intervento P6): SQL copiato tale e quale, con i suoi commenti. Il
 * controller valida i filtri e decide (SharedContentPolicy per il recupero
 * riga per riga); qui si leggono e scrivono fatti.
 *
 * Le due query di eleggibilita' (`eligibleTeacherContent`,
 * `eligibleVerificaDocuments`) sono la versione SET-BASED della stessa
 * decisione che `SharedContentPolicy::canReadContent()` applica riga per
 * riga: ogni modifica all'eleggibilita' va replicata in entrambi i siti.
 */
final class PoolRepository
{
    private function pdo(): PDO
    {
        return Database::connection();
    }

    /**
     * Istituti del docente (pivot teacher_institutes), per id crescente.
     *
     * @return list<int>
     */
    public function institutesOf(int $teacherId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT institute_id FROM teacher_institutes WHERE user_id = ? ORDER BY institute_id'
        );
        $stmt->execute([$teacherId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Primo istituto del docente (id piu' basso), 0 se nessuno. */
    public function firstInstituteOf(int $teacherId): int
    {
        $stmt = $this->pdo()->prepare(
            'SELECT institute_id FROM teacher_institutes WHERE user_id = ? ORDER BY institute_id LIMIT 1'
        );
        $stmt->execute([$teacherId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Gruppi di condivisione di cui l'utente e' membro (G22.S25).
     *
     * @return list<int>
     */
    public function groupsOfMember(int $actor): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT group_id FROM share_group_members WHERE member_user_id = ?'
        );
        $stmt->execute([$actor]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Contenuti (teacher_content) dei colleghi recuperabili dall'attore.
     *
     * Match (ADR-037, fase 1, 2026-09-13): il contenuto ha una PUBBLICAZIONE
     * in uno degli istituti dell'attore, e l'owner e' collegato a quello stesso
     * istituto. Prima la scuola era quella della materia (ce.institute_id),
     * cioe' implicita nelle etichette; con le pubblicazioni un contenuto sta
     * nel pool delle scuole in cui e' pubblicato, non di tutte quelle del suo
     * autore. DISTINCT su tc.id per evitare duplicati. Eleggibilita' estesa
     * con content_shares (G22.S25):
     *   (a) shared_with_pool=1 + actor in istituto della materia
     *   (b) EXISTS grant target_type='institute' AND target_id IN actor_insts
     *   (c) EXISTS grant target_type='teacher' AND target_id = actor
     *   (d) EXISTS grant target_type='group' AND target_id IN actor_groups
     *
     * PARITA' con App\Services\Sharing\SharedContentPolicy::canReadContent():
     *   - cross-institute hard-gate (Pubblicazioni::inScuolaComune) ⇔ "EXISTS
     *     pubblicazione in actor_insts, in una scuola dell'owner";
     *   - (shared_with_pool OR materiaShared) ⇔ "(tc.shared_with_pool=1 OR ct_owner.shared_with_pool=1)",
     *     dove ct_owner e' la SPUNTA dell'owner sulla materia (curriculum_teacher,
     *     ADR-035): «condividi tutta la materia» e' del docente, non della voce
     *     della scuola;
     *   - hasAnyGrantFor() ⇔ le tre EXISTS su content_shares (b)(c)(d);
     *   - esclusione "own content" ⇔ "tc.teacher_id <> ?";
     *   - archived non recuperabile ⇔ "tc.visibility <> 'archived'".
     * Non row-wise per performance (una query, non O(n) round-trip).
     *
     * I filtri sono gia' validati dal chiamante: null = nessun filtro.
     *
     * @param list<int> $actorInstitutes  non vuoto
     * @param list<int> $actorGroups
     * @return list<array<string,mixed>>
     */
    public function eligibleTeacherContent(
        int $actor,
        array $actorInstitutes,
        array $actorGroups,
        ?string $contentType,
        ?string $subjectCode,
        ?int $ownerId
    ): array {
        $instPlaceholders = implode(',', array_fill(0, count($actorInstitutes), '?'));
        $groupClause = $actorGroups
            ? "OR EXISTS (SELECT 1 FROM content_shares cs WHERE cs.content_source='teacher_content' AND cs.content_id=tc.id AND cs.target_type='group' AND cs.target_id IN (" . implode(',', array_fill(0, count($actorGroups), '?')) . "))"
            : '';
        $eligibility = "((tc.shared_with_pool = 1 OR COALESCE(ct_owner.shared_with_pool, 0) = 1)"
            . " OR EXISTS (SELECT 1 FROM content_shares cs WHERE cs.content_source='teacher_content' AND cs.content_id=tc.id AND cs.target_type='institute' AND cs.target_id IN ($instPlaceholders))"
            . " OR EXISTS (SELECT 1 FROM content_shares cs WHERE cs.content_source='teacher_content' AND cs.content_id=tc.id AND cs.target_type='teacher' AND cs.target_id = ?)"
            . " $groupClause)";
        // 24/9/2026 — il materiale tratto dal libro non entra nel pool per
        // nessuna strada: la condivisione del singolo contenuto lo rifiuta già
        // (SharedContentPolicy::toggleSharePool), ma «Condividi tutta la
        // materia» lo faceva entrare senza quel controllo. Mappe e documenti
        // non hanno fonte; gli altri tipi solo con una fonte condivisibile.
        $liberi = "'" . implode("','", \App\Services\Sharing\SharedContentPolicy::CONTRACT_FREE_KINDS) . "'";
        $fonti  = "'" . implode("','", \App\Services\Sharing\SharedContentPolicy::SHAREABLE_SOURCE_TYPES) . "'";
        $where = [
            'tc.teacher_id <> ?',
            'tc.visibility <> "archived"',
            "(tc.content_type IN ($liberi) OR tc.source_type IN ($fonti))",
            "EXISTS (SELECT 1 FROM content_publications p
                       JOIN teacher_institutes ti_o
                         ON ti_o.user_id = tc.teacher_id AND ti_o.institute_id = p.institute_id
                      WHERE p.teacher_content_id = tc.id AND p.institute_id IN ($instPlaceholders))",
            $eligibility,
        ];
        $args = [
            $actor,                       // tc.teacher_id <> ?
            ...$actorInstitutes,          // pubblicazione in actor_insts
            ...$actorInstitutes,          // eligibility institute grants
            $actor,                       // eligibility teacher grant
            ...$actorGroups,              // eligibility group grants (may be empty)
        ];

        if ($contentType !== null) {
            $where[] = 'tc.content_type = ?';
            $args[] = $contentType;
        }
        if ($subjectCode !== null) {
            $where[] = 'ce.code = ?';
            $args[] = $subjectCode;
        }
        if ($ownerId !== null) {
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
                    COALESCE(ct_owner.shared_with_pool, 0) AS materia_shared,
                    ce.institute_id AS materia_institute_id,
                    COALESCE(u.first_name, u.username, '') AS owner_first,
                    COALESCE(u.last_name, '') AS owner_last,
                    (SELECT MIN(tc_my.id) FROM teacher_content tc_my
                       WHERE tc_my.teacher_id = ? AND tc_my.source_content_id = tc.id) AS my_recovered_id
                  FROM teacher_content tc
                  JOIN curriculum_entries ce ON ce.id = tc.subject_id
                  LEFT JOIN curriculum_teacher ct_owner
                         ON ct_owner.curriculum_id = tc.subject_id AND ct_owner.user_id = tc.teacher_id
                  JOIN users u ON u.id = tc.teacher_id
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY tc.updated_at DESC
                 LIMIT 500";

        // Prepend $actor for the my_recovered_id subquery placeholder
        array_unshift($args, $actor);

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Verifiche (verifica_documents, blob TEX/PDF) dei colleghi recuperabili
     * dall'attore: eleggibilita' analoga ai teacher_content (G22.S23/S25).
     *
     * ADR-037, fase 3 (2026-09-13): la scuola in comune e' una in cui la
     * verifica ha una PUBBLICAZIONE (la principale o una scelta dal docente) e
     * a cui sono collegati l'attore e il proprietario, come per i contenuti
     * dalla fase 1. Prima era la scuola della materia della riga. La parita'
     * con SharedContentPolicy::canReadContent() resta.
     *
     * @param list<int> $actorInstitutes  non vuoto
     * @param list<int> $actorGroups
     * @return list<array<string,mixed>>
     */
    public function eligibleVerificaDocuments(
        int $actor,
        array $actorInstitutes,
        array $actorGroups,
        ?string $subjectCode,
        ?int $ownerId
    ): array {
        $instPlaceholders = implode(',', array_fill(0, count($actorInstitutes), '?'));
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
            "EXISTS (SELECT 1 FROM content_publications p
                       JOIN teacher_institutes ti_o
                         ON ti_o.user_id = vd.teacher_id AND ti_o.institute_id = p.institute_id
                      WHERE p.verifica_document_id = vd.id AND p.institute_id IN ($instPlaceholders))",
        ];
        $vdArgs = [
            $actor,
            ...$actorInstitutes,
            $actor,
            ...$actorGroups,
            ...$actorInstitutes,
        ];
        if ($subjectCode !== null) {
            $vdWhere[] = 'ce.code = ?';
            $vdArgs[] = $subjectCode;
        }
        if ($ownerId !== null) {
            $vdWhere[] = 'vd.teacher_id = ?';
            $vdArgs[] = $ownerId;
        }
        $vdSql = "SELECT DISTINCT
                      vd.id, vd.title, vd.variant, vd.batch_id, vd.materia_id,
                      vd.updated_at, vd.teacher_id AS owner_id,
                      ce.code  AS subject_code,
                      ce.label AS subject_label,
                      ce.institute_id AS materia_institute_id,
                      COALESCE(u.first_name, u.username, '') AS owner_first,
                      COALESCE(u.last_name, '') AS owner_last
                    FROM verifica_documents vd
                    JOIN curriculum_entries ce ON ce.id = vd.materia_id
                    JOIN users u ON u.id = vd.teacher_id
                   WHERE " . implode(' AND ', $vdWhere) . "
                   ORDER BY vd.updated_at DESC
                   LIMIT 200";
        $vdStmt = $this->pdo()->prepare($vdSql);
        $vdStmt->execute($vdArgs);
        return $vdStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * I contenuti (teacher_content) che l'owner ha condiviso: flag pool o
     * almeno un grant esplicito (G22.S24).
     *
     * @return list<array<string,mixed>>
     */
    public function sharedTeacherContentOf(int $owner): array
    {
        $stmt = $this->pdo()->prepare(
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
        $stmt->execute([$owner]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Le verifiche (verifica_documents) che l'owner ha condiviso.
     *
     * @return list<array<string,mixed>>
     */
    public function sharedVerificaDocumentsOf(int $owner): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT vd.id, vd.title, vd.variant, vd.updated_at, vd.shared_with_pool, vd.materia_id,
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
        $stmt->execute([$owner]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Toglie il flag pool ai contenuti dell'owner indicati; righe toccate.
     *
     * @param list<int> $ids non vuoto
     */
    public function unshareTeacherContent(int $owner, array $ids): int
    {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo()->prepare("UPDATE teacher_content_data SET shared_with_pool = 0
                                    WHERE teacher_id = ? AND id IN ($ph)");
        $stmt->execute([$owner, ...$ids]);
        return $stmt->rowCount();
    }

    /**
     * Toglie il flag pool alle verifiche dell'owner indicate; righe toccate.
     *
     * 14/9/2026 — una verifica si ritira intera, con tutte le varianti e le
     * versioni (VerificaDocumentRepository::righeDellaVerifica), come si
     * condivide. Il filtro sul proprietario resta nell'UPDATE.
     *
     * @param list<int> $ids non vuoto
     */
    public function unshareVerificaDocuments(int $owner, array $ids): int
    {
        $docs = new \App\Repositories\VerificaDocumentRepository();
        $tutte = [];
        foreach ($ids as $id) {
            foreach ($docs->righeDellaVerifica((int)$id) ?: [(int)$id] as $riga) {
                $tutte[$riga] = true;
            }
        }
        $ids = array_keys($tutte);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo()->prepare("UPDATE verifica_documents_data SET shared_with_pool = 0
                                    WHERE teacher_id = ? AND id IN ($ph)");
        $stmt->execute([$owner, ...$ids]);
        return $stmt->rowCount();
    }

    /**
     * Riga sorgente del recupero con codice della materia e flag «condividi
     * tutta la materia» dell'owner (la sua spunta in curriculum_teacher,
     * ADR-035: non e' un attributo della voce della scuola).
     *
     * @return array<string,mixed>|null
     */
    public function sourceWithSubject(int $contentId): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT tc.*, ce.code AS subject_code, COALESCE(ct_owner.shared_with_pool, 0) AS materia_shared
               FROM teacher_content tc
               JOIN curriculum_entries ce ON ce.id = tc.subject_id
               LEFT JOIN curriculum_teacher ct_owner
                      ON ct_owner.curriculum_id = tc.subject_id AND ct_owner.user_id = tc.teacher_id
              WHERE tc.id = ?
              LIMIT 1"
        );
        $stmt->execute([$contentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Una materia del vocabolario che il docente ha spuntato (ADR-035): la
     * voce è della scuola, la spunta è sua.
     *
     * @return array{id:int|string,code:string,institute_id:int|string}|null
     */
    public function ownMateria(int $owner, int $subjectId): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT c.id, c.code, c.institute_id
               FROM curriculum_entries c
               JOIN curriculum_teacher ct ON ct.curriculum_id = c.id AND ct.user_id = ?
              WHERE c.id = ? AND c.kind = 'materie'
              LIMIT 1"
        );
        $stmt->execute([$owner, $subjectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Codice di una voce attiva del catalogo di un istituto ('indirizzi' o
     * 'classi'); null se non esiste.
     */
    public function catalogCode(int $entryId, string $kind, int $instituteId): ?string
    {
        $s = $this->pdo()->prepare(
            "SELECT code FROM curriculum_entries
              WHERE id = ? AND kind = ? AND institute_id = ? AND active = 1
              LIMIT 1"
        );
        $s->execute([$entryId, $kind, $instituteId]);
        $code = $s->fetchColumn();
        return $code === false ? null : (string)$code;
    }

    /** Provenienza del clone (source_content_id) e condivisione spenta. */
    public function markRecovered(int $newId, int $sourceId): void
    {
        $this->pdo()->prepare(
            'UPDATE teacher_content_data
                SET source_content_id = ?,
                    shared_with_pool = 0
              WHERE id = ?'
        )->execute([$sourceId, $newId]);
    }

    /** Percorso e mime del blob mappa clonato. */
    public function setMapBlob(int $contentId, string $path, string $mime): void
    {
        $this->pdo()->prepare(
            'UPDATE teacher_content_data
                SET map_blob_path = ?,
                    map_mime = ?
              WHERE id = ?'
        )->execute([$path, $mime, $contentId]);
    }

    /** Cancella la riga di contenuto (rollback manuale di un clone fallito). */
    public function deleteContentRow(int $contentId): void
    {
        $this->pdo()->prepare('DELETE FROM teacher_content_data WHERE id = ?')->execute([$contentId]);
    }

    /** Nome leggibile dell'utente (nome e cognome, altrimenti username); null se non esiste. */
    public function ownerName(int $ownerId): ?string
    {
        $stmt = $this->pdo()->prepare(
            "SELECT COALESCE(NULLIF(TRIM(CONCAT_WS(' ', first_name, last_name)), ''), username) AS n
               FROM users WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$ownerId]);
        $name = $stmt->fetchColumn();
        return $name !== false && $name !== null ? (string)$name : null;
    }

    /** metadata_json grezzo della riga ('' se assente). */
    public function metadataJson(int $contentId): string
    {
        $stmt = $this->pdo()->prepare('SELECT metadata_json FROM teacher_content WHERE id = ?');
        $stmt->execute([$contentId]);
        return (string)$stmt->fetchColumn();
    }

    public function setMetadataJson(int $contentId, string $json): void
    {
        $this->pdo()->prepare('UPDATE teacher_content_data SET metadata_json = ? WHERE id = ?')
            ->execute([$json, $contentId]);
    }

    /** Istituto di una voce del catalogo, 0 se non risolvibile. */
    public function instituteOfCatalogEntry(int $entryId): int
    {
        $stmt = $this->pdo()->prepare('SELECT institute_id FROM curriculum_entries WHERE id = ? LIMIT 1');
        $stmt->execute([$entryId]);
        return (int)$stmt->fetchColumn();
    }
}
