<?php

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Read-side per la tabella exercises (M6).
 *
 * Filtri tipici: scope (indirizzo/classe/materia), topic, difficulty, tags
 * (LIKE su JSON column), full-text su body_html via FULLTEXT index.
 */
final class ExerciseRepository
{
    /**
     * @param array{
     *   indirizzo?: string, classe?: string, materia?: string, topic?: string,
     *   difficulty?: int|int[], tag?: string, q?: string, limit?: int, offset?: int
     * } $filters
     * @return list<array>
     */
    public function search(array $filters = []): array
    {
        $where = [];
        $args = [];
        foreach (['indirizzo', 'classe', 'materia', 'topic'] as $col) {
            if (!empty($filters[$col])) {
                $where[] = "$col = ?";
                $args[]  = (string)$filters[$col];
            }
        }
        if (isset($filters['difficulty'])) {
            $diffs = (array)$filters['difficulty'];
            $place = implode(',', array_fill(0, count($diffs), '?'));
            $where[] = "difficulty IN ($place)";
            foreach ($diffs as $d) {
                $args[] = (int)$d;
            }
        }
        if (!empty($filters['tag'])) {
            $where[] = 'JSON_CONTAINS(tags, JSON_QUOTE(?))';
            $args[]  = (string)$filters['tag'];
        }
        if (!empty($filters['q'])) {
            $where[] = 'MATCH(body_html) AGAINST(? IN NATURAL LANGUAGE MODE)';
            $args[]  = (string)$filters['q'];
        }
        $sql = 'SELECT id, indirizzo, classe, materia, topic, title, difficulty, tags, source, created_at FROM exercises';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY indirizzo, classe, materia, topic, difficulty';
        $limit  = max(1, min(500, (int)($filters['limit']  ?? 100)));
        $offset = max(0, (int)($filters['offset'] ?? 0));
        $sql .= " LIMIT $limit OFFSET $offset";

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['tags'] = $r['tags'] ? json_decode($r['tags'], true) : [];
        }
        return $rows;
    }

    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM exercises WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return null;
        }
        $r['tags'] = $r['tags'] ? json_decode($r['tags'], true) : [];
        return $r;
    }
}
