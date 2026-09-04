<?php

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use PDO;

/**
 * Admin Analytics Service (Phase 13.5).
 *
 * Aggrega KPI per dashboard analytics admin:
 *   - users by role           (administrator/teacher/collaborator/student)
 *   - users by institute      (top istituti per #user)
 *   - content by type+author  (mappa/lab/esercizio/verifica × teacher_id)
 *   - content by visibility   (draft/published/archived)
 *   - access activity 30d     (visite per role/section)
 *   - student accesses        (chi/quando/quale risorsa, filtrabile per
 *                              teacher_id per dare al docente le statistiche
 *                              dei suoi studenti)
 *
 * Tutti i dati restano confinati al ruolo admin per default. Il metodo
 * `forTeacher()` espone subset filtrato (solo dati relativi al docente
 * loggato) per la sua propria dashboard.
 */
final class AdminAnalyticsService
{
    public function __construct(
        private readonly string $accessLogPath,
    ) {
    }

    public static function default(): self
    {
        $base = (string)Config::get('app.paths.base', dirname(__DIR__, 2));
        return new self(accessLogPath: $base . '/log/data/access_log.json');
    }

    /** Snapshot completo per admin. */
    public function snapshot(): array
    {
        $pdo = $this->dbReady() ? Database::connection() : null;
        return [
            'generated_at'     => date('c'),
            'users_by_role'    => $pdo ? $this->countGroup($pdo, 'users', 'role') : [],
            'users_by_status'  => $pdo ? $this->countGroup($pdo, 'users', 'status') : [],
            'top_institutes'   => $pdo ? $this->topInstitutes($pdo, 10) : [],
            'content_by_type'  => $pdo ? $this->contentByType($pdo) : [],
            'content_by_vis'   => $pdo ? $this->countGroup($pdo, 'teacher_content', 'visibility') : [],
            'top_authors'      => $pdo ? $this->topContentAuthors($pdo, 10) : [],
            'access_30d_role'  => $this->accessLogStats(30 * 86400, 'role'),
            'access_30d_section' => $this->accessLogStats(30 * 86400, 'section'),
            'access_24h_total' => $this->accessLogCount(86400),
            'access_7d_total'  => $this->accessLogCount(7 * 86400),
        ];
    }

    /** Dati filtrati per un singolo teacher (solo i SUOI studenti). */
    public function forTeacher(int $teacherId): array
    {
        $pdo = $this->dbReady() ? Database::connection() : null;
        if (!$pdo || $teacherId <= 0) {
            return ['generated_at' => date('c'), 'teacher_id' => $teacherId, 'error' => 'unavailable'];
        }
        return [
            'generated_at'        => date('c'),
            'teacher_id'          => $teacherId,
            'institutes'          => $this->teacherInstitutes($pdo, $teacherId),
            'content_count'       => $this->teacherContentCount($pdo, $teacherId),
            'content_by_type'     => $this->teacherContentByType($pdo, $teacherId),
            'access_codes_count'  => $this->teacherAccessCodesCount($pdo, $teacherId),
            'student_accesses_30d' => $this->studentAccessesViaTeacherCodes($teacherId),
        ];
    }

    /**
     * Cross-teacher inspection (Feature 5): admin può vedere il content
     * di TUTTI i docenti per audit/security/copyright check, anche quello
     * draft. Ogni record ha label di rischio se contiene URL esterni
     * sospetti o keyword copyright-protected.
     */
    public function crossTeacherSearch(string $q = '', ?string $type = null, int $limit = 100): array
    {
        if (!$this->dbReady()) {
            return ['ok' => false, 'rows' => [], 'error' => 'db_unavailable'];
        }
        $pdo = Database::connection();
        $where = [];
        $args = [];
        if ($q !== '') {
            // Audit 25.R.31 — LIKE '%q%' su body_html (TEXT, cross-docente) =
            // full-table scan. Per query corte (<3 char) cerca solo su
            // title/topic; il body_html (costoso) solo da 3 caratteri.
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            if (mb_strlen($q) >= 3) {
                $where[] = '(tc.title LIKE ? OR tc.topic LIKE ? OR tc.body_html LIKE ?)';
                array_push($args, $like, $like, $like);
            } else {
                $where[] = '(tc.title LIKE ? OR tc.topic LIKE ?)';
                array_push($args, $like, $like);
            }
        }
        if ($type !== null && $type !== '') {
            $where[] = 'tc.content_type = ?';
            $args[] = $type;
        }
        $sql = "SELECT tc.id, tc.teacher_id, tc.content_type, tc.subject_code,
                       tc.indirizzo, tc.classe, tc.topic, tc.title, tc.visibility,
                       tc.created_at, tc.updated_at,
                       u.username AS teacher_username,
                       LENGTH(tc.body_html) AS body_size,
                       tc.body_html
                FROM teacher_content tc
                LEFT JOIN users u ON u.id = tc.teacher_id";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY tc.updated_at DESC LIMIT " . max(1, min(500, $limit));
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Aggiungi flag rischio (euristica)
        foreach ($rows as &$r) {
            $body = (string)($r['body_html'] ?? '');
            $r['risk_flags'] = $this->copyrightRisk($body);
            // Risparmia banda: snip body
            $r['body_snippet'] = mb_substr(strip_tags($body), 0, 200);
            unset($r['body_html']);
        }
        return ['ok' => true, 'rows' => $rows];
    }

    // ─────────── Helpers ───────────

    private function dbReady(): bool
    {
        return (bool)Config::get('database.enabled') && Database::isAvailable();
    }

    private function countGroup(PDO $pdo, string $table, string $col): array
    {
        $stmt = $pdo->query("SELECT $col AS k, COUNT(*) AS n FROM $table GROUP BY $col ORDER BY n DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function topInstitutes(PDO $pdo, int $limit): array
    {
        $stmt = $pdo->prepare(
            "SELECT i.id, i.code, i.name, i.city,
                    (SELECT COUNT(*) FROM users u WHERE u.institute_id = i.id) AS users_count,
                    (SELECT COUNT(*) FROM teacher_institutes ti WHERE ti.institute_id = i.id) AS teachers_count
             FROM institutes i
             WHERE i.active = 1
             ORDER BY users_count DESC, teachers_count DESC
             LIMIT $limit"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function contentByType(PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT content_type AS k, COUNT(*) AS n
             FROM teacher_content
             GROUP BY content_type"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function topContentAuthors(PDO $pdo, int $limit): array
    {
        $stmt = $pdo->prepare(
            "SELECT u.username, u.role, COUNT(tc.id) AS n,
                    SUM(CASE WHEN tc.visibility = 'published' THEN 1 ELSE 0 END) AS published_n
             FROM teacher_content tc
             INNER JOIN users u ON u.id = tc.teacher_id
             GROUP BY u.id
             ORDER BY n DESC
             LIMIT $limit"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function teacherInstitutes(PDO $pdo, int $tid): array
    {
        $stmt = $pdo->prepare(
            "SELECT i.id, i.code, i.name FROM institutes i
             INNER JOIN teacher_institutes ti ON ti.institute_id = i.id
             WHERE ti.user_id = ? AND i.active = 1"
        );
        $stmt->execute([$tid]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function teacherContentCount(PDO $pdo, int $tid): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM teacher_content WHERE teacher_id = ?');
        $stmt->execute([$tid]);
        return (int)$stmt->fetchColumn();
    }

    private function teacherContentByType(PDO $pdo, int $tid): array
    {
        $stmt = $pdo->prepare(
            "SELECT content_type AS k, visibility AS v, COUNT(*) AS n
             FROM teacher_content WHERE teacher_id = ?
             GROUP BY content_type, visibility"
        );
        $stmt->execute([$tid]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function teacherAccessCodesCount(PDO $pdo, int $tid): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM teacher_access_credentials WHERE teacher_id = ?');
        $stmt->execute([$tid]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Studenti che hanno usato gli access codes del docente nelle ultime
     * 30 giorni. Per ora è un'aggregazione su access_log.json filtrando
     * per username = access_username delle credenziali del docente.
     */
    private function studentAccessesViaTeacherCodes(int $tid): array
    {
        if (!$this->dbReady()) {
            return [];
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT access_username FROM teacher_access_credentials WHERE teacher_id = ?');
        $stmt->execute([$tid]);
        $codes = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'access_username');
        if (!$codes) {
            return [];
        }
        $codeSet = array_flip($codes);
        $log = $this->readJson($this->accessLogPath) ?? [];
        $cutoff = time() - 30 * 86400;
        $byCode = [];
        foreach ($log as $row) {
            $u = (string)($row['username'] ?? '');
            if (!isset($codeSet[$u])) {
                continue;
            }
            $t = strtotime((string)($row['timestamp'] ?? ''));
            if ($t < $cutoff) {
                continue;
            }
            $byCode[$u] ??= ['username' => $u, 'count' => 0, 'unique_ips' => [], 'last_seen' => null];
            $byCode[$u]['count']++;
            $ip = (string)($row['ip_address'] ?? '');
            if ($ip !== '') {
                $byCode[$u]['unique_ips'][$ip] = true;
            }
            $byCode[$u]['last_seen'] = max($byCode[$u]['last_seen'] ?? 0, $t);
        }
        return array_values(array_map(static function (array $r): array {
            return [
                'username'   => $r['username'],
                'count'      => $r['count'],
                'unique_ips' => count($r['unique_ips']),
                'last_seen'  => $r['last_seen'] ? date('Y-m-d H:i:s', $r['last_seen']) : null,
            ];
        }, $byCode));
    }

    private function accessLogStats(int $windowSec, string $groupBy): array
    {
        $log = $this->readJson($this->accessLogPath) ?? [];
        $cutoff = time() - $windowSec;
        $by = [];
        foreach ($log as $row) {
            $t = strtotime((string)($row['timestamp'] ?? ''));
            if ($t < $cutoff) {
                continue;
            }
            $key = $groupBy === 'role'
                ? (string)($row['role'] ?? 'unknown')
                : (string)($row['institute_code'] ?? '') . (string)($row['class_code'] ?? '');
            if ($key === '' || $key === 'unknown') {
                $key = '(none)';
            }
            $by[$key] = ($by[$key] ?? 0) + 1;
        }
        arsort($by);
        $out = [];
        foreach ($by as $k => $n) {
            $out[] = ['k' => $k, 'n' => $n];
        }
        return $out;
    }

    private function accessLogCount(int $windowSec): int
    {
        $log = $this->readJson($this->accessLogPath) ?? [];
        $cutoff = time() - $windowSec;
        $n = 0;
        foreach ($log as $row) {
            $t = strtotime((string)($row['timestamp'] ?? ''));
            if ($t >= $cutoff) {
                $n++;
            }
        }
        return $n;
    }

    /** Euristica copyright/risk flagging: keyword + URL esterni a domain noti. */
    private function copyrightRisk(string $body): array
    {
        $flags = [];
        if (preg_match('/©|copyright|all rights reserved/i', $body)) {
            $flags[] = 'copyright_marker';
        }
        if (preg_match_all('#https?://([^/\s"\']+)#i', $body, $m)) {
            $hosts = array_unique($m[1]);
            $external = array_filter($hosts, fn($h) => !preg_match('/^(localhost|pantedu\.local|127\.0\.0\.1)/i', $h));
            if ($external) {
                $flags[] = 'external_links:' . count($external);
            }
        }
        if (preg_match('/zanichelli|mondadori|deagostini|loescher|hoepli/i', $body)) {
            $flags[] = 'publisher_brand_mention';
        }
        return $flags;
    }

    private function readJson(string $path): mixed
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        return json_decode($raw, true);
    }
}
