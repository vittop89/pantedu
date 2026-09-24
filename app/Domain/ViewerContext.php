<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Pilota #1 — Value object immutabile che descrive CHI sta guardando un contenuto.
 *
 * Raccoglie in un unico oggetto esplicito i dati di contesto che oggi i call-site
 * estraggono ad-hoc da Auth/SESSION/DB (ruolo, user_id risolto, istituto, scope
 * indirizzo/classe dello studente). Non tocca SESSION/Auth/Database: è puro dato.
 *
 * Usato da {@see ContentVisibilityPolicy} come parametro esplicito delle decisioni.
 */
final class ViewerContext
{
    public function __construct(
        public readonly ?Role $role = null,        // null = guest
        public readonly int $teacherId = 0,        // user_id risolto (0 = guest/non risolto)
        public readonly ?int $instituteId = null,  // istituto del viewer
        public readonly ?string $indirizzo = null, // scope classe studente (da User->course); null per teacher senza browsing
        public readonly ?string $classe = null,
        /** @var list<string> sezioni passate note («2B»): le classi frequentate (piano classi, D) */
        public readonly array $storico = [],
        /** @var list<array<string,mixed>> portachiavi: un perimetro per credenziale (piano classi, C) */
        public readonly array $grants = [],
    ) {
    }

    /**
     * Ospite con il portachiavi di credenziali di classe (scenari 1 e 2): uno
     * studente senza account, con un perimetro per docente. indirizzo e classe
     * del contesto sono quelli della prima credenziale delimitata, per chi ne
     * legge uno solo (sidebar, intestazione).
     *
     * @param list<array<string,mixed>> $grants
     */
    public static function forKeychain(array $grants): self
    {
        $ind = null;
        $cls = null;
        foreach ($grants as $g) {
            if (($g['classe'] ?? null) !== null && (string)$g['classe'] !== '') {
                $ind = $g['indirizzo'] ?? null;
                $cls = (string)$g['classe'];
                break;
            }
        }
        return new self(
            role: Role::STUDENT,
            teacherId: 0,
            instituteId: null,
            indirizzo: $ind,
            classe: $cls,
            grants: array_values($grants),
        );
    }

    /** Guest non autenticato: ruolo null, teacherId 0. */
    public static function guest(): self
    {
        return new self(role: null, teacherId: 0);
    }

    /** @param list<string> $storico */
    public static function forStudent(int $uid, ?int $inst, ?string $ind, ?string $cls, array $storico = []): self
    {
        return new self(
            role: Role::STUDENT,
            teacherId: $uid,
            instituteId: $inst,
            indirizzo: $ind,
            classe: $cls,
            storico: $storico,
        );
    }

    public static function forTeacher(int $uid, ?int $inst, ?string $ind = null, ?string $cls = null): self
    {
        return new self(
            role: Role::TEACHER,
            teacherId: $uid,
            instituteId: $inst,
            indirizzo: $ind,
            classe: $cls,
        );
    }

    public function isGuest(): bool
    {
        return $this->role === null;
    }

    public function isStudent(): bool
    {
        return $this->role?->isStudent() ?? false;
    }

    /**
     * canSeeAllScopes == ExerciseAccessPolicy::canSeeAllScopes():
     * admin | teacher vedono ogni scope (no filtro published/sezione).
     */
    public function canSeeAllScopes(): bool
    {
        return $this->role !== null
            && ($this->role->isAdmin() || $this->role->isTeacher());
    }
}
