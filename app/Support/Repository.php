<?php

namespace App\Support;

use App\Core\Database;
use PDO;

/**
 * Minimal CRUD base. Subclasses set `$table` + `$primaryKey`.
 *
 * Sempre usa prepared statements. Il filtro WHERE in find/all accetta
 * un array associativo `['col' => $val]` — niente query-builder, niente
 * SQL raw nei caller. Se serve una query custom, il subclass la scrive.
 */
abstract class Repository
{
    protected string $table;
    protected string $primaryKey = 'id';

    protected function pdo(): PDO
    {
        return Database::connection();
    }

    public function find(int|string $id): ?array
    {
        $stmt = $this->pdo()->prepare("SELECT * FROM {$this->table} WHERE {$this->primaryKey} = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findBy(array $where): ?array
    {
        [$sql, $args] = $this->buildWhere($where);
        $stmt = $this->pdo()->prepare("SELECT * FROM {$this->table} {$sql} LIMIT 1");
        $stmt->execute($args);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function all(array $where = [], ?string $orderBy = null, ?int $limit = null): array
    {
        [$sql, $args] = $this->buildWhere($where);
        $order = $orderBy ? " ORDER BY {$orderBy}" : '';
        $lim   = $limit !== null ? " LIMIT " . (int)$limit : '';
        $stmt  = $this->pdo()->prepare("SELECT * FROM {$this->table}{$sql}{$order}{$lim}");
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    public function insert(array $data): int|string
    {
        $cols   = array_keys($data);
        $holders = array_fill(0, count($cols), '?');
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table,
            implode(',', $cols),
            implode(',', $holders),
        );
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute(array_values($data));
        return $this->pdo()->lastInsertId();
    }

    public function update(int|string $id, array $data): int
    {
        $set = implode(',', array_map(fn($c) => "$c = ?", array_keys($data)));
        $sql = "UPDATE {$this->table} SET {$set} WHERE {$this->primaryKey} = ?";
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([...array_values($data), $id]);
        return $stmt->rowCount();
    }

    public function delete(int|string $id): int
    {
        $stmt = $this->pdo()->prepare("DELETE FROM {$this->table} WHERE {$this->primaryKey} = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount();
    }

    private function buildWhere(array $where): array
    {
        if ($where === []) {
            return ['', []];
        }
        $parts = [];
        $args  = [];
        foreach ($where as $col => $val) {
            $parts[] = "$col = ?";
            $args[]  = $val;
        }
        return [' WHERE ' . implode(' AND ', $parts), $args];
    }
}
