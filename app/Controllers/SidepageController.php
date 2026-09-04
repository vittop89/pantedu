<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use PDO;
use Throwable;

/**
 * Phase 14 — sidepage listing DB-backed per le 5 sezioni legacy.
 *
 *   GET /api/sidepage/topics?category=<mappe|verifiche|lab|eser|bes>
 *
 * Ritorna la lista di "topic" (primo segmento di path sotto la categoria)
 * con il conteggio di file disponibili in `storage_objects`.
 *
 * ACL per categoria (enforcement dentro il controller):
 *   - mappe       → pubblico
 *   - lab, eser   → student+
 *   - verifiche,
 *     bes         → admin/teacher (scoping su owner_user_id per teacher)
 *
 * Questo endpoint non restituisce dati personali: solo nomi topic e
 * conteggi aggregati per file statici migrati.
 */
final class SidepageController
{
    private const ALLOWED_CATEGORIES = ['mappe', 'verifiche', 'lab', 'eser', 'bes'];

    public function topics(Request $req): Response
    {
        $category = trim((string)($req->query['category'] ?? ''));
        if (!in_array($category, self::ALLOWED_CATEGORIES, true)) {
            return Response::json(['error' => 'invalid_category'], 400);
        }
        $err = $this->enforceCategoryAcl($category);
        if ($err) {
            return $err;
        }

        if (!Database::isAvailable()) {
            return Response::json(['ok' => true, 'topics' => []]); // graceful
        }
        try {
            $rows = $this->queryTopics($category);
            return Response::json(['ok' => true, 'category' => $category, 'topics' => $rows]);
        } catch (Throwable $e) {
            return Response::json(['error' => 'query_failed'], 500);
        }
    }

    private function enforceCategoryAcl(string $category): ?Response
    {
        switch ($category) {
            case 'mappe':
                return null; // pubblico
            case 'lab':
            case 'eser':
                if (!Auth::check()) {
                    return Response::json(['error' => 'unauthorized'], 401);
                }
                return null;
            case 'verifiche':
            case 'bes':
                if (!Auth::check()) {
                    return Response::json(['error' => 'unauthorized'], 401);
                }
                if (!in_array(Auth::role(), ['admin', 'teacher', 'collaborator'], true)) {
                    return Response::json(['error' => 'forbidden'], 403);
                }
                return null;
            default:
                return Response::json(['error' => 'invalid_category'], 400);
        }
    }

    /** @return list<array{name:string,count:int}> */
    private function queryTopics(string $category): array
    {
        // Estrae il segmento tra `/{category}/` e il successivo `/`.
        // MariaDB: SUBSTRING_INDEX(SUBSTRING_INDEX(storage_key, '/{category}/', -1), '/', 1)
        $pdo = Database::connection();
        $like = '%/' . $category . '/%';

        // Scoping teacher: se non admin e non super-admin, limita agli own
        $ownerFilter = '';
        $args = [$like];
        if (Auth::role() === 'teacher' && !Auth::isSuperAdmin()) {
            $uid = \App\Support\TeacherContextResolver::userIdFromUsername((string)(Auth::user()['username'] ?? ''));
            if ($uid > 0) {
                $ownerFilter = ' AND owner_user_id = ?';
                $args[] = $uid;
            }
        }

        $sql = "SELECT
                  SUBSTRING_INDEX(SUBSTRING_INDEX(storage_key, ?, -1), '/', 1) AS topic,
                  COUNT(*) AS n
                FROM storage_objects
                WHERE storage_key LIKE ?{$ownerFilter}
                GROUP BY topic
                ORDER BY topic
                LIMIT 500";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge(['/' . $category . '/'], $args));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $name = (string)($r['topic'] ?? '');
            if ($name === '' || $name === 'storage_key') {
                continue;
            }
            $out[] = ['name' => $name, 'count' => (int)$r['n']];
        }
        return $out;
    }
}
