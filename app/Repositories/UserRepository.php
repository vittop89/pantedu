<?php

namespace App\Repositories;

use App\Core\Database;
use App\Domain\User;
use PDO;

/**
 * Phase 18 — DB-only user repository.
 *
 * Tutti gli utenti (admin, collaborator, teacher, student) vivono ora
 * nella tabella `users`. Nessuna lettura JSON. I JSON legacy
 * (admin_users.json, collaborators.json, storage/objects/.../users.json)
 * sono archiviati dopo import one-shot via tools/import_legacy_users_to_db.php.
 */
class UserRepository implements UserRepositoryInterface
{
    /** @var array<string, User>|null */
    private ?array $cache = null;

    public function find(string $username): ?User
    {
        if ($username === '') {
            return null;
        }
        if ($this->cache !== null && isset($this->cache[$username])) {
            return $this->cache[$username];
        }
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /** @return array<string, User> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $rows = Database::connection()
            ->query('SELECT * FROM users ORDER BY username')
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[$row['username']] = $this->hydrate($row);
        }
        return $this->cache = $out;
    }

    private function hydrate(array $row): User
    {
        return User::fromArray($row['username'], [
            'role'          => $row['role'],
            'first_name'    => $row['first_name']    ?? '',
            'last_name'     => $row['last_name']     ?? '',
            'email'         => $row['email']         ?? '',
            'password_hash' => $row['password_hash'] ?? '',
            'active'        => (bool)($row['active'] ?? true),
            'created'       => $row['created_at']    ?? null,
        ], 'db');
    }
}
