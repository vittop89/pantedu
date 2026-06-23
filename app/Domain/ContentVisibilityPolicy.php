<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Pilota #1 — Gate UNICO della decisione "chi può vedere/ricevere QUESTO contenuto".
 *
 * Centralizza le regole canoniche emerse dall'audit (oggi duplicate in ~21 punti
 * fra controller/repository/exporter):
 *   - state gate         (published / draft / archived)
 *   - scope studente     (indirizzo/classe + published-only)
 *   - section visible_roles (sezione sidebar nascosta agli studenti)
 *   - ownership          (owner vede i propri draft; export owner-or-superadmin)
 *
 * Design (BEHAVIOR-PRESERVING):
 *   - Immutabile, stateless, nessuna dipendenza nel costruttore.
 *   - NIENTE SESSION / Auth / Database nel core: le decisioni prendono un
 *     {@see ViewerContext} esplicito + una "row" normalizzata (array).
 *   - I metodi-predicato sono PURI (in-memory) → golden-testabili a unità.
 *   - Le concern DB-bound (grant di sharing, sezioni nascoste) NON sono assorbite:
 *     arrivano come dati pre-calcolati (array) o come callable iniettati, così il
 *     core resta DB-free e le policy specializzate (SharedContentPolicy, …) restano
 *     la sorgente di verità per la loro SQL.
 *   - Nessun metodo lancia eccezioni: i controller continuano a emettere 403/404
 *     da soli (preserve-only). La policy ritorna solo bool / array-filtro.
 *
 * Chiavi della $row consumate: 'teacher_id', 'visibility', 'section_id', 'id'.
 * La visibility è parsata via {@see ContentVisibility::tryFromString()} (null-safe →
 * trattata come NON pubblicata).
 *
 * Non ri-dichiara mai l'enum {@see ContentVisibility}: lo COMPONE.
 */
final class ContentVisibilityPolicy
{
    public function __construct()
    {
        // stateless, immutable, no deps
    }

    // ─────────────────────────────────────────────────────────────────────
    // (A) STATE GATE — delega a ContentVisibility, non duplica.
    // ─────────────────────────────────────────────────────────────────────

    /** === $v->isVisibleToStudents() (published only). */
    public function isVisibleToStudents(ContentVisibility $v): bool
    {
        return $v->isVisibleToStudents();
    }

    // ─────────────────────────────────────────────────────────────────────
    // (B) PER-ROW READ DECISION
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Single-content endpoint. Mirror di contentSingleJson() riga 954:
     *   published OR isOwner OR canSeeAll.
     * $vis null/invalida → trattata come NON published (deny salvo owner/all).
     *
     * @param array<string,mixed> $row
     */
    public function canReadSingle(array $row, ViewerContext $ctx): bool
    {
        if ($this->isPublished($row)) {
            return true;
        }
        return $this->isOwner($row, $ctx) || $ctx->canSeeAllScopes();
    }

    /**
     * Verifica correlata. Mirror di relatedVerificaHtml() righe 314-319:
     *   $vis === 'published' || ($isOwner && $vis !== 'archived')
     *
     * NOTA: il comportamento DIVERGE volutamente da canReadSingle() —
     * qui l'owner NON vede la propria verifica archiviata. L'asimmetria è
     * bloccata dai test finché un umano non la risolve.
     *
     * @param array<string,mixed> $row
     */
    public function canReadRelatedVerifica(array $row, ViewerContext $ctx): bool
    {
        $vis = $this->visibilityOf($row);
        if ($vis === ContentVisibility::PUBLISHED) {
            return true;
        }
        return $this->isOwner($row, $ctx) && $vis !== ContentVisibility::ARCHIVED;
    }

    // ─────────────────────────────────────────────────────────────────────
    // (C) STUDIO LIST FILTERS — mirror di scopedFilters() righe 968-1007.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Frammento-filtro che il repository merge-a nello scope base.
     * PURO dati gli input (gli id delle sezioni nascoste sono iniettati).
     *
     * Ritorna (chiavi presenti solo quando applicabili):
     *   ['visibility'=>'published', 'student_scope'=>true,
     *    'indirizzo'=>..., 'classe'=>..., 'section_id_not_in'=>list<int>]
     *
     * - canSeeAllScopes (admin|collaborator|teacher) → nessun vincolo (array vuoto).
     * - guest → indirizzo='__deny__' (parità ExerciseAccessPolicy::scopeConstraints
     *   guest: vincolo impossibile → repo ritorna vuoto).
     * - student → published + student_scope + indirizzo/classe + section_id_not_in.
     *
     * @param list<int> $hiddenSectionIds
     * @return array<string,mixed>
     */
    public function studyListFilters(ViewerContext $ctx, array $hiddenSectionIds = []): array
    {
        if ($ctx->canSeeAllScopes()) {
            return [];
        }

        if ($ctx->isGuest()) {
            // Parità con ExerciseAccessPolicy: guest bloccato a monte; per sicurezza
            // un constraint impossibile così il repo restituisce vuoto.
            return ['indirizzo' => '__deny__'];
        }

        // Studente (o ruolo non-all-scope): published + scope sezione.
        $out = [
            'visibility'    => ContentVisibility::PUBLISHED->value,
            'student_scope' => true,
        ];
        // Scope per ISTITUTO: lo studente vede solo i contenuti dei docenti del
        // proprio istituto (search() lo traduce via pivot teacher_institutes).
        // Chiude il leak cross-istituto (codici indirizzo/classe possono coincidere
        // tra istituti diversi).
        if ($ctx->instituteId !== null && $ctx->instituteId > 0) {
            $out['institute_id'] = $ctx->instituteId;
        }
        if ($ctx->indirizzo !== null && $ctx->indirizzo !== '') {
            $out['indirizzo'] = $ctx->indirizzo;
        }
        if ($ctx->classe !== null && $ctx->classe !== '') {
            $out['classe'] = $ctx->classe;
        }
        if ($hiddenSectionIds !== []) {
            $out['section_id_not_in'] = array_values(array_map('intval', $hiddenSectionIds));
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    // (D) SECTION GATE — visible_roles della sezione sidebar.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Lo studente è escluso se 'student' non è in visible_roles.
     * teacher/admin/collaborator sono sempre ammessi (canSeeAllScopes).
     *
     * @param list<string> $visibleRoles
     */
    public function canSeeSection(array $visibleRoles, ViewerContext $ctx): bool
    {
        if ($ctx->canSeeAllScopes()) {
            return true;
        }
        // Guest e studente: ammessi solo se 'student' è fra i ruoli visibili.
        return \in_array('student', $visibleRoles, true);
    }

    /**
     * Filtra una mappa {id => visible_roles} alla lista di id NASCOSTI allo studente.
     * Parità con hiddenSectionIdsForStudent(): per ruoli all-scope la lista è vuota.
     * Input vuoto → lista vuota (parità try/catch fallback).
     *
     * @param array<int,list<string>> $sectionsById
     * @return list<int>
     */
    public function hiddenSectionIds(array $sectionsById, ViewerContext $ctx): array
    {
        if ($ctx->canSeeAllScopes()) {
            return [];
        }
        $hidden = [];
        foreach ($sectionsById as $id => $roles) {
            if (!\in_array('student', $roles, true)) {
                $hidden[] = (int)$id;
            }
        }
        return $hidden;
    }

    // ─────────────────────────────────────────────────────────────────────
    // (E) ACL DELEGATION — il cross-teacher sharing NON è re-implementato.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Compone SharedContentPolicy via callable iniettato (core DB-free).
     *
     * @param array<string,mixed> $row
     * @param callable(int,int,bool):bool $aclReader fn(ownerId, contentId, sharedWithPool)
     */
    public function passesAcl(array $row, ViewerContext $ctx, callable $aclReader): bool
    {
        // guest / non-teacher → pass-through (filtro gestito altrove, in scope).
        if ($ctx->teacherId === 0 || $ctx->role === null || !$ctx->role->isTeacher()) {
            return true;
        }
        $ownerId   = (int)($row['teacher_id'] ?? 0);
        $contentId = (int)($row['id'] ?? 0);
        $pool      = (bool)($row['shared_with_pool'] ?? false);
        return $aclReader($ownerId, $contentId, $pool);
    }

    /**
     * Helper array usato da applyAclFilter() (righe 224-248):
     *   - guest (teacherId=0) → rows invariate
     *   - ruolo non-teacher   → rows invariate
     *   - teacher             → tiene le rows dove $aclReader(owner,id,pool) è true
     *
     * @param list<array<string,mixed>> $rows
     * @param callable(int,int,bool):bool $aclReader
     * @return list<array<string,mixed>>
     */
    public function filterByAcl(array $rows, ViewerContext $ctx, callable $aclReader): array
    {
        if ($rows === []) {
            return $rows;
        }
        if ($ctx->teacherId === 0 || $ctx->role === null || !$ctx->role->isTeacher()) {
            return $rows;
        }
        return array_values(array_filter(
            $rows,
            fn(array $r): bool => $this->passesAcl($r, $ctx, $aclReader)
        ));
    }

    // ─────────────────────────────────────────────────────────────────────
    // (F) EXPORT / OWNERSHIP byte-gate.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Mirror di TeacherContent export righe 322/662:
     *   owner OR super-admin.
     */
    public function canExportOwn(int $ownerId, ViewerContext $ctx, bool $isSuperAdmin): bool
    {
        if ($isSuperAdmin) {
            return true;
        }
        return $this->isOwnerId($ownerId, $ctx);
    }

    /**
     * Ownership stretta (detail endpoint riga 164): solo owner.
     */
    public function canReadOwnDetail(int $ownerId, ViewerContext $ctx): bool
    {
        return $this->isOwnerId($ownerId, $ctx);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers privati (puri).
    // ─────────────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $row */
    private function visibilityOf(array $row): ?ContentVisibility
    {
        $raw = $row['visibility'] ?? null;
        return ContentVisibility::tryFromString(\is_string($raw) ? $raw : null);
    }

    /** @param array<string,mixed> $row */
    private function isPublished(array $row): bool
    {
        return $this->visibilityOf($row) === ContentVisibility::PUBLISHED;
    }

    /** @param array<string,mixed> $row */
    private function isOwner(array $row, ViewerContext $ctx): bool
    {
        return $this->isOwnerId((int)($row['teacher_id'] ?? 0), $ctx);
    }

    private function isOwnerId(int $ownerId, ViewerContext $ctx): bool
    {
        return $ctx->teacherId > 0 && $ownerId === $ctx->teacherId;
    }
}
