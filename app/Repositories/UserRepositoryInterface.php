<?php

namespace App\Repositories;

use App\Domain\User;

/**
 * Contract per repository utenti — Phase 18 DB-only (UserRepository).
 * L'interface resta per DI + test con double in-memory.
 */
interface UserRepositoryInterface
{
    public function find(string $username): ?User;

    /** @return array<string, User> */
    public function all(): array;
}
