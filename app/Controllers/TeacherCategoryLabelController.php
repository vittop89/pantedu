<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use PDO;

/**
 * Phase 24.76 — rinomine categoria PER-DOCENTE persistite su DB
 * (teacher_category_labels), così seguono il docente cross-dispositivo.
 * Il client (sidepage-category-labels.js) idrata da qui e mantiene
 * localStorage come cache sincrona.
 */
final class TeacherCategoryLabelController
{
    /** GET /api/teacher/category-labels → { labels: { categoryKey: label } } */
    public function list(Request $req): Response
    {
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['labels' => (object)[]]);
        }
        $db = Database::connection();
        $st = $db->prepare('SELECT category_key, label FROM teacher_category_labels WHERE teacher_id = ?');
        $st->execute([$tid]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string)$r['category_key']] = (string)$r['label'];
        }
        // no-store: risposta per-docente volatile → mai cacheata da proxy/CDN
        // (Cloudflare davanti al VPS serviva risposte stantie altrimenti).
        return Response::json(['labels' => (object)$out])->withNoCache();
    }

    /**
     * POST /api/teacher/category-labels  body: category, label
     * label vuoto → reset (rimuove l'override → torna al default).
     */
    public function save(Request $req): Response
    {
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $category = trim((string)($req->post['category'] ?? ''));
        $label    = trim((string)($req->post['label'] ?? ''));
        if ($category === '' || mb_strlen($category) > 64) {
            return Response::json(['error' => 'invalid_category'], 400);
        }
        if (mb_strlen($label) > 255) {
            return Response::json(['error' => 'invalid_label'], 400);
        }
        $db = Database::connection();
        if ($label === '') {
            $db->prepare('DELETE FROM teacher_category_labels WHERE teacher_id = ? AND category_key = ?')
               ->execute([$tid, $category]);
        } else {
            $db->prepare(
                'INSERT INTO teacher_category_labels (teacher_id, category_key, label)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE label = VALUES(label)'
            )->execute([$tid, $category, $label]);
        }
        return Response::json(['ok' => true]);
    }

    private function teacherId(): int
    {
        $u = Auth::user();
        if (!$u) {
            return 0;
        }
        return \App\Support\TeacherContextResolver::userIdFromUsername((string)($u['username'] ?? ''));
    }
}
