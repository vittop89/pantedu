<?php

declare(strict_types=1);

namespace App\Services\Sharing;

use App\Core\Database;
use PDO;

/**
 * G22.S25 — Policy unificata per la condivisione di contenuti tra docenti.
 *
 * Sostituisce/centralizza:
 *   - PoolController::recover()       check eligibility (shared + grants)
 *   - ContentStudyController::applyAclFilter() ACL su rows visibili
 *   - ShareGrantsController::ownsContent()/validateTarget() ownership + targeting
 *   - TeacherContent/Verifica::sharePool() toggle flow
 *
 * Tipi sorgente (`source`) supportati: 'teacher_content' | 'verifica_documents'.
 *
 * Modello eligibility (OR di tutte le clausole):
 *   1. shared_with_pool = 1 (flag legacy per-row)
 *   2. curriculum_teacher.shared_with_pool = 1 sulla spunta dell'owner per la
 *      materia del contenuto (flag per-materia del docente, ADR-035; solo teacher_content)
 *   3. EXISTS grant content_shares con target_type=institute, target_id ∈ istituti_attore
 *   4. EXISTS grant content_shares con target_type=teacher, target_id = attore
 *   5. EXISTS grant content_shares con target_type=group, target_id ∈ gruppi_attore
 *
 * AND tutte le clausole sopra richiedono che `actor` e `owner` condividano
 * almeno un istituto fisico (cross-institute leak prevention).
 *
 * Nessun side-effect, nessun mutex; tutte le query sono SELECT idempotenti.
 */
final class SharedContentPolicy
{
    public const SOURCES = ['teacher_content', 'verifica_documents'];

    /**
     * Phase 25.P.1 — copyright source-type protection.
     * Source types che PERMETTONO la condivisione tra docenti.
     * 'personal'      = creato dal docente in proprio
     * 'public_domain' = oltre 70 anni dalla morte autore (art. 32 L.633)
     * 'cc_licensed'   = licenza CC compatibile con redistribution
     */
    public const SHAREABLE_SOURCE_TYPES = ['personal', 'public_domain', 'cc_licensed'];

    /**
     * Source types che BLOCCANO la condivisione (uso solo privato docente ex art. 70-bis).
     * 'book_textbook' = derivato dal libro di testo (traccia/soluzione editoriale)
     * 'mixed'         = verifica con esercizi misti personali + libro testo
     * NULL            = legacy non classificato → policy strict (no share)
     */
    public const BLOCKED_SOURCE_TYPES = ['book_textbook', 'mixed'];
    /**
     * Tipi SENZA contratto (mappe, documenti): non hanno item da cui derivare
     * una fonte, quindi source_type resta NULL per costruzione. Il blocco
     * copyright (che nasce dagli esercizi presi dal libro) non si applica:
     * trattarli come «non classificati» li rendeva incondivisibili per sempre
     * (2026-09-05).
     */
    public const CONTRACT_FREE_KINDS = ['mappa', 'document'];

    /** Verifica che l'utente sia proprietario del contenuto. */
    public function ownsContent(int $actorId, string $source, int $contentId): bool
    {
        if (!\in_array($source, self::SOURCES, true) || $actorId <= 0 || $contentId <= 0) {
            return false;
        }
        $table = $source; // teacher_content | verifica_documents
        $stmt = Database::connection()->prepare("SELECT teacher_id FROM $table WHERE id = ?");
        $stmt->execute([$contentId]);
        return (int)$stmt->fetchColumn() === $actorId;
    }

    /**
     * Verifica se `actor` ha ALMENO UN grant esplicito sul contenuto.
     * Considera tutti i target_type (institute/teacher/group) e usa
     * gli istituti/gruppi dell'attore caricati on-the-fly.
     */
    public function hasAnyGrantFor(int $actorId, string $source, int $contentId): bool
    {
        if (!\in_array($source, self::SOURCES, true) || $actorId <= 0 || $contentId <= 0) {
            return false;
        }
        $pdo = Database::connection();
        // institute grants
        $stmt = $pdo->prepare(
            'SELECT 1 FROM content_shares cs
              JOIN teacher_institutes ti ON ti.institute_id = cs.target_id AND ti.user_id = ?
             WHERE cs.content_source = ? AND cs.content_id = ? AND cs.target_type = "institute"
             LIMIT 1'
        );
        $stmt->execute([$actorId, $source, $contentId]);
        if ($stmt->fetchColumn()) {
            return true;
        }
        // teacher grant diretto
        $stmt = $pdo->prepare(
            'SELECT 1 FROM content_shares
              WHERE content_source = ? AND content_id = ? AND target_type = "teacher" AND target_id = ?
              LIMIT 1'
        );
        $stmt->execute([$source, $contentId, $actorId]);
        if ($stmt->fetchColumn()) {
            return true;
        }
        // group membership grant
        $stmt = $pdo->prepare(
            'SELECT 1 FROM content_shares cs
              JOIN share_group_members m ON m.group_id = cs.target_id AND m.member_user_id = ?
             WHERE cs.content_source = ? AND cs.content_id = ? AND cs.target_type = "group"
             LIMIT 1'
        );
        $stmt->execute([$actorId, $source, $contentId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Combina eligibility: owner, oppure (stesso istituto + (shared_with_pool
     * OR materia_shared_with_pool OR grant esplicito)).
     *
     * @param int    $actorId
     * @param string $source           teacher_content | verifica_documents
     * @param int    $contentId
     * @param int    $ownerId          teacher_id del contenuto
     * @param bool   $sharedWithPool   flag row-level
     * @param bool   $materiaShared    flag della spunta dell'owner sulla materia (curriculum_teacher; solo teacher_content)
     */
    public function canReadContent(
        int $actorId,
        string $source,
        int $contentId,
        int $ownerId,
        bool $sharedWithPool,
        bool $materiaShared = false,
    ): bool {
        if ($actorId === $ownerId && $actorId > 0) {
            return true;
        }
        if ($actorId <= 0 || $ownerId <= 0) {
            return false;
        }
        if (!\in_array($source, self::SOURCES, true)) {
            return false;
        }

        // Cross-institute hard-gate. ADR-037, fase 1 (2026-09-13): la scuola in
        // comune dev'essere una in cui il documento e' PUBBLICATO. Prima
        // bastava che i due docenti avessero una scuola in comune, anche se il
        // documento stava in un'altra. Dalla fase 3 vale anche per le verifiche.
        if (!\App\Support\Pubblicazioni::inScuolaComune($contentId, $actorId, $ownerId, $source)) {
            return false;
        }

        // 24/9/2026 — a chi non l'ha creato, il materiale tratto dal libro non
        // arriva per nessuna strada di condivisione (singola, materia intera o
        // permesso esplicito): è per uso personale del docente (Termini 1.6).
        // Prima la materia condivisa lo lasciava leggere e recuperare.
        if (!$this->isShareableBySource($source, $contentId)) {
            return false;
        }

        if ($sharedWithPool || $materiaShared) {
            return true;
        }
        return $this->hasAnyGrantFor($actorId, $source, $contentId);
    }

    /**
     * Pilota #1 — Adapter per {@see \App\Domain\ContentVisibilityPolicy}.
     *
     * Espone {@see canReadContent()} nella forma `aclReader` che il gate unico
     * compone via {@see \App\Domain\ContentVisibilityPolicy::filterByAcl()} /
     * {@see \App\Domain\ContentVisibilityPolicy::passesAcl()}, così il core resta
     * DB-free e l'ACL cross-docente NON è re-implementata.
     *
     * Il closure restituito ha firma `fn(int $ownerId, int $contentId, bool $sharedWithPool): bool`
     * ed è BEHAVIOR-PRESERVING rispetto alla chiamata inline di
     * ContentStudyController::applyAclFilter() (righe 224-248):
     *   $policy->canReadContent($actorId, 'teacher_content', $contentId, $ownerId, $pool)
     *
     * @param int    $actorId attore (docente loggato)
     * @param string $source  teacher_content | verifica_documents (default: come applyAclFilter)
     * @return callable(int,int,bool):bool
     */
    public function aclReaderFor(int $actorId, string $source = 'teacher_content'): callable
    {
        return fn(int $ownerId, int $contentId, bool $sharedWithPool): bool
            => $this->canReadContent($actorId, $source, $contentId, $ownerId, $sharedWithPool);
    }

    /**
     * Valida che `target` sia un destinatario lecito per un grant settato da `actor`
     * sul contenuto `contentId/source`.
     *
     *   - institute: actor deve appartenere all'istituto AND il contenuto
     *     deve essere in quell'istituto (no cross-institute leak via grant).
     *   - teacher:   target deve essere docente attivo nello STESSO istituto
     *     dell'actor (no leak verso altre scuole).
     *   - group:     actor deve essere owner del gruppo.
     */
    public function validateTarget(
        int $actorId,
        string $source,
        int $contentId,
        string $type,
        int $targetId,
    ): bool {
        if ($targetId <= 0 || !\in_array($source, self::SOURCES, true)) {
            return false;
        }
        $pdo = Database::connection();
        if ($type === 'institute') {
            // (a) actor appartiene
            $stmt = $pdo->prepare(
                'SELECT 1 FROM teacher_institutes WHERE user_id=? AND institute_id=? LIMIT 1'
            );
            $stmt->execute([$actorId, $targetId]);
            if (!$stmt->fetchColumn()) {
                return false;
            }
            // (b) il documento è scoped su quell'istituto. ADR-037: ha una
            // pubblicazione in quell'istituto, in qualunque stato (fase 1 per i
            // contenuti, fase 3 per le verifiche).
            return \App\Support\Pubblicazioni::haPubblicazione($contentId, $targetId, null, $source);
        }
        if ($type === 'teacher') {
            if ($targetId === $actorId) {
                return false;
            }
            $stmt = $pdo->prepare(
                "SELECT 1 FROM users u
                   JOIN teacher_institutes ti ON ti.user_id = u.id
                   JOIN teacher_institutes mine ON mine.institute_id = ti.institute_id AND mine.user_id = ?
                  WHERE u.id = ? AND u.role = 'teacher' AND u.deleted_at IS NULL
                  LIMIT 1"
            );
            $stmt->execute([$actorId, $targetId]);
            return (bool)$stmt->fetchColumn();
        }
        if ($type === 'group') {
            $stmt = $pdo->prepare('SELECT 1 FROM share_groups WHERE id=? AND owner_user_id=? LIMIT 1');
            $stmt->execute([$targetId, $actorId]);
            return (bool)$stmt->fetchColumn();
        }
        return false;
    }

    /**
     * Phase 25.P.1 — Verifica che un contenuto sia ELIGIBLE alla condivisione
     * in base al suo source_type (protezione copyright ex art. 70-bis L.633/1941).
     *
     * Returns:
     *   true  = source_type ∈ SHAREABLE_SOURCE_TYPES → condivisione OK
     *   false = source_type ∈ BLOCKED_SOURCE_TYPES o NULL → blocco copyright
     *   Mappe e documenti (CONTRACT_FREE_KINDS) non hanno fonte: sempre true.
     *
     * @param string $source teacher_content | verifica_documents
     * @param int    $contentId
     * @return bool          true = può essere condiviso, false = blocco copyright
     */
    public function isShareableBySource(string $source, int $contentId): bool
    {
        if (!\in_array($source, self::SOURCES, true) || $contentId <= 0) {
            return false;
        }
        $row = $this->sourceRow($source, $contentId);
        if ($row === null) {
            return false;
        }
        if (!self::copyrightApplies($row['kind'])) {
            return true;
        }
        if ($row['source_type'] === null) {
            // Legacy non classificato → strict policy: NO share
            return false;
        }
        return \in_array((string)$row['source_type'], self::SHAREABLE_SOURCE_TYPES, true);
    }

    /** Il blocco copyright vale solo per i tipi che hanno un contratto (esercizi, verifiche). */
    public static function copyrightApplies(?string $kind): bool
    {
        return !\in_array((string)$kind, self::CONTRACT_FREE_KINDS, true);
    }

    /**
     * source_type + tipo (content_type per teacher_content; le verifica_documents
     * sono sempre verifiche) della riga, o null se non esiste.
     *
     * @return array{source_type: ?string, kind: ?string}|null
     */
    private function sourceRow(string $source, int $contentId): ?array
    {
        // $source è già validato contro SOURCES dai chiamanti.
        $kindCol = $source === 'teacher_content' ? 'content_type' : "'verifica'";
        $stmt = Database::connection()->prepare(
            "SELECT source_type, {$kindCol} AS kind FROM {$source} WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$contentId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'source_type' => isset($row['source_type']) ? (string)$row['source_type'] : null,
            'kind'        => isset($row['kind']) ? (string)$row['kind'] : null,
        ];
    }

    /**
     * Phase 25.P.3 — Messaggio leggibile per UI/UX (mostrato al docente).
     */
    public function humanReadableBlockReason(string $reasonCode): string
    {
        return match (true) {
            $reasonCode === 'source_unclassified' =>
                'Questo contenuto non è ancora classificato per fonte. Aggiungi almeno '
                . 'un esercizio (o lascia il contract vuoto e crea esercizi propri) per '
                . 'abilitare la condivisione.',
            $reasonCode === 'copyright_protected_book_textbook' =>
                'Questo contenuto deriva dal libro di testo. Per la tutela del diritto '
                . 'd\'autore (art. 70-bis L. 633/1941), puoi conservarlo per uso '
                . 'personale ma non condividerlo con altri docenti tramite l\'applicativo. '
                . 'Per condividerlo, crea un esercizio nuovo personale equivalente.',
            $reasonCode === 'copyright_protected_mixed' =>
                'Questo contenuto contiene un mix di esercizi personali e dal libro di '
                . 'testo. Per cautela ex art. 70-bis L. 633/1941, la condivisione è '
                . 'bloccata. Per condividerlo, isola gli esercizi personali in un nuovo '
                . 'contenuto (senza riferimenti al libro).',
            $reasonCode === 'invalid_content' =>
                'Contenuto non valido o non identificato.',
            default =>
                'Condivisione non permessa per questo contenuto.',
        };
    }

    /**
     * Phase 25.P.1 — Reason del blocco condivisione (per UI/UX feedback).
     *
     * @return string|null null se shareable, string motivazione se bloccato.
     */
    public function shareBlockReason(string $source, int $contentId): ?string
    {
        if (!\in_array($source, self::SOURCES, true) || $contentId <= 0) {
            return 'invalid_content';
        }
        $row = $this->sourceRow($source, $contentId);
        if ($row === null) {
            return 'invalid_content';
        }
        if (!self::copyrightApplies($row['kind'])) {
            return null;
        }
        $sourceType = $row['source_type'];

        if ($sourceType === null) {
            return 'source_unclassified';
        }
        if (\in_array((string)$sourceType, self::BLOCKED_SOURCE_TYPES, true)) {
            return 'copyright_protected_' . (string)$sourceType;
        }
        return null;
    }

    /** Istituti dell'attore (cached request-scoped). */
    public function actorInstitutes(int $actorId): array
    {
        if ($actorId <= 0) {
            return [];
        }
        if (isset(self::$instituteCache[$actorId])) {
            return self::$instituteCache[$actorId];
        }
        $stmt = Database::connection()->prepare(
            'SELECT institute_id FROM teacher_institutes WHERE user_id = ? ORDER BY institute_id'
        );
        $stmt->execute([$actorId]);
        return self::$instituteCache[$actorId] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Gruppi di cui l'attore è membro. */
    public function actorGroups(int $actorId): array
    {
        if ($actorId <= 0) {
            return [];
        }
        if (isset(self::$groupCache[$actorId])) {
            return self::$groupCache[$actorId];
        }
        $stmt = Database::connection()->prepare(
            'SELECT group_id FROM share_group_members WHERE member_user_id = ?'
        );
        $stmt->execute([$actorId]);
        return self::$groupCache[$actorId] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Caches request-scoped (private static, no TTL: vivono solo nella request). */
    private static array $instituteCache = [];
    private static array $groupCache = [];

    /**
     * Toggle shared_with_pool su una row, owner-checked. Centralizza il flow
     * duplicato fra TeacherContentController::sharePool e VerificaController::sharePool.
     *
     * @return array{ok:bool, error?:string, counterpart?:array{id:int,type:string}}
     */
    public function toggleSharePool(int $actorId, string $source, int $contentId, bool $enabled): array
    {
        if (!\in_array($source, self::SOURCES, true)) {
            return ['ok' => false, 'error' => 'invalid_source'];
        }
        if (!$this->ownsContent($actorId, $source, $contentId)) {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        $righe = $this->righeCondivise($source, $contentId);

        // Phase 25.P.3 — copyright share-block (art. 70-bis L. 633/1941):
        // se attivare condivisione (enabled=true) e source_type ∈ blocked,
        // rifiuta con motivazione. Disattivare condivisione (enabled=false)
        // è sempre permesso (riportare a stato sicuro). Per una verifica vale
        // per ogni variante: una sola tratta dal libro blocca tutta la verifica,
        // invece di condividerne una parte.
        if ($enabled) {
            foreach ($righe as $riga) {
                $reason = $this->shareBlockReason($source, $riga);
                if ($reason !== null) {
                    return [
                        'ok' => false,
                        'error' => 'copyright_block',
                        'reason' => $reason,
                        'message' => $this->humanReadableBlockReason($reason),
                    ];
                }
            }
        }

        $table = $source . '_data';
        $pdo = Database::connection();
        $in = implode(',', array_fill(0, count($righe), '?'));
        $pdo->prepare("UPDATE {$table} SET shared_with_pool = ? WHERE teacher_id = ? AND id IN ({$in})")
            ->execute([$enabled ? 1 : 0, $actorId, ...$righe]);

        $counterpart = null;
        // Propagation esercizio↔verifica solo per teacher_content
        // (verifica_documents non ha controparte esercizio nello stesso modello).
        if ($source === 'teacher_content') {
            $counterpart = $this->propagateExerciseVerificaCounterpart($actorId, $contentId, $enabled);
        }
        return ['ok' => true, 'counterpart' => $counterpart, 'righe' => count($righe)];
    }

    /**
     * Le righe a cui si applica una condivisione (il pulsante del pool, i
     * grant, il ritiro). Un contenuto è una riga. Una verifica sono tutte le sue
     * varianti e versioni (VerificaDocumentRepository::righeDellaVerifica): fino
     * al 14/9/2026 si condivideva la sola variante da cui si apriva il modale,
     * mentre il modale diceva «tutto il pacchetto», e ai colleghi arrivava un
     * pezzo di verifica.
     *
     * @return list<int> almeno `$contentId`
     */
    public function righeCondivise(string $source, int $contentId): array
    {
        if ($source !== 'verifica_documents') {
            return [$contentId];
        }
        $righe = (new \App\Repositories\VerificaDocumentRepository())->righeDellaVerifica($contentId);
        return $righe !== [] ? $righe : [$contentId];
    }

    /**
     * Propaga shared_with_pool alla controparte esercizio↔verifica.
     * Relazione: esercizio.title == verifica.topic (stesso subject_code+teacher_id).
     */
    private function propagateExerciseVerificaCounterpart(int $teacherId, int $contentId, bool $enabled): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT content_type, subject_code, title, topic
               FROM teacher_content
              WHERE id = ? AND teacher_id = ? LIMIT 1'
        );
        $stmt->execute([$contentId, $teacherId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $type = (string)$row['content_type'];
        $subj = (string)$row['subject_code'];
        if ($subj === '') {
            return null;
        }

        if ($type === 'esercizio') {
            $title = (string)$row['title'];
            if ($title === '') {
                return null;
            }
            $stmt = $pdo->prepare(
                "SELECT id FROM teacher_content
                  WHERE teacher_id = ? AND content_type = 'verifica'
                    AND subject_code = ? AND topic = ?
                  LIMIT 1"
            );
            $stmt->execute([$teacherId, $subj, $title]);
            $counterId = (int)$stmt->fetchColumn();
            if ($counterId > 0) {
                $pdo->prepare(
                    'UPDATE teacher_content_data SET shared_with_pool=? WHERE id=? AND teacher_id=?'
                )->execute([$enabled ? 1 : 0, $counterId, $teacherId]);
                return ['id' => $counterId, 'type' => 'verifica'];
            }
        } elseif ($type === 'verifica') {
            $topic = (string)$row['topic'];
            if ($topic === '') {
                return null;
            }
            $stmt = $pdo->prepare(
                "SELECT id FROM teacher_content
                  WHERE teacher_id = ? AND content_type = 'esercizio'
                    AND subject_code = ? AND title = ?
                  LIMIT 1"
            );
            $stmt->execute([$teacherId, $subj, $topic]);
            $counterId = (int)$stmt->fetchColumn();
            if ($counterId > 0) {
                $pdo->prepare(
                    'UPDATE teacher_content_data SET shared_with_pool=? WHERE id=? AND teacher_id=?'
                )->execute([$enabled ? 1 : 0, $counterId, $teacherId]);
                return ['id' => $counterId, 'type' => 'esercizio'];
            }
        }
        return null;
    }
}
