<?php

namespace App\Policies;

use App\Domain\User;

/**
 * Access policy per tabella `exercises` — decide quali righe un utente
 * vede e se può editare.
 *
 * Regole (M6+):
 *   - admin/collaborator/teacher: full read, filtro libero per any scope
 *   - student: legge SOLO esercizi del proprio sectionCode = indirizzo.classe
 *     (es. `sc.sc2s` → vede tutto quello dove indirizzo=sc AND classe=sc2s),
 *     a prescindere dalla materia (MAT/FIS)
 *   - guest (non autenticato): nessun accesso (controllato dalla route)
 *
 * La policy produce un filtro SQL additivo che lo ExerciseRepository
 * merge-a nei propri filtri utente. Mai bypassabile dal client.
 */
final class ExerciseAccessPolicy
{
    public function __construct(private readonly ?User $user)
    {
    }

    /**
     * Vincoli forzati per l'utente corrente. Sovrascrive qualsiasi
     * filtro utente se più restrittivo (es. student che chiede
     * indirizzo diverso dal proprio → indirizzo forzato).
     *
     * @return array{indirizzo?:string,classe?:string}
     */
    public function scopeConstraints(): array
    {
        if ($this->user === null) {
            // guest: bloccato upstream; per sicurezza un constraint
            // impossibile così il repo restituisce vuoto.
            return ['indirizzo' => '__deny__'];
        }
        if ($this->canSeeAllScopes()) {
            return [];
        }
        // Studente: estrai indirizzo+classe dal sectionCode `{indirizzo}.{classe}`
        [$ind, $cls] = $this->splitSection($this->user->course);
        $out = [];
        if ($ind) {
            $out['indirizzo'] = $ind;
        }
        if ($cls) {
            $out['classe']    = $cls;
        }
        return $out;
    }

    public function canSeeAllScopes(): bool
    {
        if ($this->user === null) {
            return false;
        }
        return $this->user->isAdmin()
            || $this->user->isCollaborator()
            || $this->user->isTeacher();
    }

    public function canEdit(): bool
    {
        if ($this->user === null) {
            return false;
        }
        return $this->user->isAdmin() || $this->user->isTeacher();
    }

    /** Merge filtri utente con i vincoli della policy (i vincoli vincono). */
    public function apply(array $userFilters): array
    {
        return array_merge($userFilters, $this->scopeConstraints());
    }

    /**
     * sectionCode storici: sia `sc.sc2s` (dot-split) sia `scsc2s`
     * (concatenato). Proviamo entrambi, ritornando [indirizzo, classe]
     * o [null, null] se non parsabile.
     *
     * @return array{0:?string,1:?string}
     */
    private function splitSection(?string $sectionCode): array
    {
        if (!$sectionCode) {
            return [null, null];
        }
        if (str_contains($sectionCode, '.')) {
            [$i, $c] = explode('.', $sectionCode, 2);
            return [$i ?: null, $c ?: null];
        }
        // Formato concatenato: primo prefisso 2 char (sc|ar|cl|li|af) + resto
        if (preg_match('/^([a-z]{2,3})([a-z0-9]+)$/i', $sectionCode, $m)) {
            return [strtolower($m[1]), strtolower($m[2])];
        }
        return [null, null];
    }
}
