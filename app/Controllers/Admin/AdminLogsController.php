<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use PDO;
use Throwable;

/**
 * Phase 25.R.25 — Pannello unificato log per super-admin.
 *
 * Aggrega read-only le tabelle/file di audit:
 *   - content_action_log     (eventi docente: create/publish/delete contenuti)
 *   - content_versions       (snapshot edit contenuti)
 *   - privileged_access_log  (admin che legge dati altrui)
 *   - crypto_access_log      (operazioni crypto per teacher_id)
 *   - teacher_recovery_audit (uso recovery keys)
 *   - crypto_custody_events  (KMS lifecycle + authority cooperation)
 *
 * Route:
 *   GET /admin/logs               UI principale (tabs per ogni source)
 *   GET /admin/logs/api/{table}   JSON paginato + filtri
 *
 * Filtri comuni: ?since=YYYY-MM-DD&until=YYYY-MM-DD&actor=N&teacher_id=N&limit=100
 */
final class AdminLogsController
{
    // Phase 25.R.25 — Tab list ordinata per importanza forense
    private const TABS = [
        'content_action_log'     => 'Eventi contenuti',
        'content_versions'       => 'Versioni contenuti',
        'privileged_access_log'  => 'Accessi privilegiati admin',
        'crypto_access_log'      => 'Operazioni crypto',
        'crypto_custody_events'  => 'KMS custody + autorità',
    ];

    // Audit 25.R.31 — defense-in-depth (il middleware super_admin_required già
    // gatea le route). $json=true → 403 JSON per gli endpoint API (prima HTML
    // anche su apiQuery, shape incoerente per il client).
    private function guard(bool $json = false): ?Response
    {
        if (!Auth::isSuperAdmin()) {
            return $json
                ? Response::json(['error' => 'forbidden'], 403)
                : Response::html('<h1>403 Forbidden</h1>', 403);
        }
        return null;
    }

    public function page(Request $req): Response
    {
        if ($g = $this->guard()) {
            return $g;
        }

        $tab = (string)($req->query['tab'] ?? 'content_action_log');
        if (!isset(self::TABS[$tab])) {
            $tab = 'content_action_log';
        }

        $view = View::default();
        $body = $view->render('admin/logs_index', [
            'tabs'    => self::TABS,
            'current' => $tab,
            'csrf'    => \App\Core\Csrf::token(),
            'user'    => Auth::user() ?? [],
        ]);
        return Response::html($view->render('layout/shell', [
            'title' => 'Admin Logs — Pantedu',
            'body'  => $body,
        ]));
    }

    /** GET /admin/logs/api/{table}?since=&until=&teacher_id=&actor=&limit= */
    public function apiQuery(Request $req, array $params): Response
    {
        if ($g = $this->guard(true)) {
            return $g;
        }
        $table = (string)($params['table'] ?? '');
        if (!isset(self::TABS[$table])) {
            return Response::json(['error' => 'invalid_table'], 400);
        }

        $limit  = max(1, min(500, (int)($req->query['limit']  ?? 100)));
        $since  = trim((string)($req->query['since']  ?? ''));
        $until  = trim((string)($req->query['until']  ?? ''));
        $tid    = (int)($req->query['teacher_id'] ?? 0);
        $actor  = (int)($req->query['actor']  ?? 0);

        // Ogni tabella ha schema diverso → query custom + colonne unificate
        $rows = match ($table) {
            'content_action_log'     => $this->queryContentActions($limit, $since, $until, $tid, $actor),
            'content_versions'       => $this->queryContentVersions($limit, $since, $until, $tid, $actor),
            'privileged_access_log'  => $this->queryPrivilegedAccess($limit, $since, $until, $actor),
            'crypto_access_log'      => $this->queryCryptoAccess($limit, $since, $until, $tid, $actor),
            'crypto_custody_events'  => $this->queryCustodyEvents($limit, $since, $until, $tid, $actor),
            default                  => [],
        };

        return Response::json([
            'ok'     => true,
            'table'  => $table,
            'count'  => count($rows),
            'limit'  => $limit,
            'rows'   => $rows,
        ]);
    }

    /** @return list<array> */
    private function queryContentActions(int $limit, string $since, string $until, int $tid, int $actor): array
    {
        $w = [];
        $a = [];
        if ($since !== '') {
            $w[] = 'occurred_at >= ?';
            $a[] = $since . ' 00:00:00';
        }
        if ($until !== '') {
            $w[] = 'occurred_at <= ?';
            $a[] = $until . ' 23:59:59';
        }
        if ($tid > 0) {
            $w[] = 'teacher_id = ?';
            $a[] = $tid;
        }
        if ($actor > 0) {
            $w[] = 'actor_user_id = ?';
            $a[] = $actor;
        }
        $sql = 'SELECT id, occurred_at, teacher_id, actor_user_id, content_id,
                       content_type, action, details_json, ip_address
                FROM content_action_log';
        if ($w) {
            $sql .= ' WHERE ' . implode(' AND ', $w);
        }
        $sql .= ' ORDER BY occurred_at DESC LIMIT ?';
        $a[] = $limit;
        return $this->run($sql, $a);
    }

    /** @return list<array> */
    private function queryContentVersions(int $limit, string $since, string $until, int $tid, int $actor): array
    {
        $w = [];
        $a = [];
        if ($since !== '') {
            $w[] = 'created_at >= ?';
            $a[] = $since . ' 00:00:00';
        }
        if ($until !== '') {
            $w[] = 'created_at <= ?';
            $a[] = $until . ' 23:59:59';
        }
        if ($actor > 0) {
            $w[] = 'actor_user_id = ?';
            $a[] = $actor;
        }
        $sql = 'SELECT id, content_id, version, actor_user_id, actor_name,
                       change_summary, created_at,
                       LENGTH(snapshot_json) AS snapshot_size
                FROM content_versions';
        if ($w) {
            $sql .= ' WHERE ' . implode(' AND ', $w);
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ?';
        $a[] = $limit;
        return $this->run($sql, $a);
    }

    /** @return list<array>  Schema reale: user_id (actor) + created_at + actor_name/role */
    private function queryPrivilegedAccess(int $limit, string $since, string $until, int $actor): array
    {
        $w = [];
        $a = [];
        if ($since !== '') {
            $w[] = 'created_at >= ?';
            $a[] = $since . ' 00:00:00';
        }
        if ($until !== '') {
            $w[] = 'created_at <= ?';
            $a[] = $until . ' 23:59:59';
        }
        if ($actor > 0) {
            $w[] = 'user_id = ?';
            $a[] = $actor;
        }
        $sql = 'SELECT id, created_at, user_id, actor_name, actor_role, action,
                       resource_type, resource_id, reason, outcome, ip_address
                FROM privileged_access_log';
        if ($w) {
            $sql .= ' WHERE ' . implode(' AND ', $w);
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ?';
        $a[] = $limit;
        return $this->run($sql, $a);
    }

    /** @return list<array>  Schema reale: accessor_id (actor) + accessed_at + operation enum */
    private function queryCryptoAccess(int $limit, string $since, string $until, int $tid, int $actor): array
    {
        $w = [];
        $a = [];
        if ($since !== '') {
            $w[] = 'accessed_at >= ?';
            $a[] = $since . ' 00:00:00';
        }
        if ($until !== '') {
            $w[] = 'accessed_at <= ?';
            $a[] = $until . ' 23:59:59';
        }
        if ($tid > 0) {
            $w[] = 'teacher_id = ?';
            $a[] = $tid;
        }
        if ($actor > 0) {
            $w[] = 'accessor_id = ?';
            $a[] = $actor;
        }
        $sql = 'SELECT id, accessed_at, accessor_id, teacher_id, operation,
                       table_name, row_id, reason, outcome
                FROM crypto_access_log';
        if ($w) {
            $sql .= ' WHERE ' . implode(' AND ', $w);
        }
        $sql .= ' ORDER BY accessed_at DESC LIMIT ?';
        $a[] = $limit;
        return $this->run($sql, $a);
    }

    /** @return list<array> */
    private function queryCustodyEvents(int $limit, string $since, string $until, int $tid, int $actor): array
    {
        $w = [];
        $a = [];
        if ($since !== '') {
            $w[] = 'occurred_at >= ?';
            $a[] = $since . ' 00:00:00';
        }
        if ($until !== '') {
            $w[] = 'occurred_at <= ?';
            $a[] = $until . ' 23:59:59';
        }
        if ($tid > 0) {
            $w[] = 'teacher_id = ?';
            $a[] = $tid;
        }
        if ($actor > 0) {
            $w[] = 'actor_user_id = ?';
            $a[] = $actor;
        }
        $sql = 'SELECT id, occurred_at, event_type, teacher_id, actor_user_id,
                       authority_name, authority_ref, legal_basis,
                       LEFT(description, 200) AS description_preview
                FROM crypto_custody_events';
        if ($w) {
            $sql .= ' WHERE ' . implode(' AND ', $w);
        }
        $sql .= ' ORDER BY occurred_at DESC LIMIT ?';
        $a[] = $limit;
        return $this->run($sql, $a);
    }

    /** @return list<array> */
    private function run(string $sql, array $args): array
    {
        try {
            $stmt = Database::connection()->prepare($sql);
            $stmt->execute($args);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('[AdminLogsController] query failed: ' . $e->getMessage());
            return [];
        }
    }
}
