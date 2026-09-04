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
 * Aggrega read-only le tabelle di audit:
 *   - audit_activity_log     operazioni di tutti i ruoli, studente compreso
 *   - content_action_log     eventi docente: create/publish/delete contenuti
 *   - content_versions       snapshot edit contenuti
 *   - privileged_access_log  admin che legge o muta dati altrui
 *   - consent_audit          consensi, compreso quello genitoriale art. 8
 *   - parent_consents        stato corrente delle richieste ai genitori
 *   - crypto_access_log      operazioni crypto per teacher_id
 *   - teacher_recovery_audit uso delle chiavi di recupero
 *   - crypto_custody_events  KMS lifecycle + authority cooperation
 *
 * Route:
 *   GET /admin/logs               UI principale (tabs per ogni source)
 *   GET /admin/logs/api/{table}   JSON paginato + filtri
 *
 * Filtri comuni: ?since=&until=&actor=N&teacher_id=N&role=&action=&limit=100
 *
 * TABELLA ASSENTE ≠ ZERO RIGHE (2026-09-02)
 *
 * `run()` ingoiava qualsiasi eccezione e tornava una lista vuota. In
 * produzione `content_versions` non esisteva — non era mai stata messa in una
 * migration — e il tab mostrava "nessun record con questi filtri", cioe' la
 * risposta sbagliata alla domanda giusta. Ora l'esistenza della tabella si
 * verifica prima, e l'assenza si dice.
 */
final class AdminLogsController
{
    // Tab list ordinata per importanza forense
    private const TABS = [
        'audit_activity_log'     => 'Attività (tutti i ruoli)',
        'content_action_log'     => 'Eventi contenuti',
        'content_versions'       => 'Versioni contenuti',
        'privileged_access_log'  => 'Accessi privilegiati admin',
        'consent_audit'          => 'Consensi',
        'parent_consents'        => 'Consenso genitori',
        'crypto_access_log'      => 'Operazioni crypto',
        'teacher_recovery_audit' => 'Chiavi di recupero',
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

        $tab = (string)($req->query['tab'] ?? 'audit_activity_log');
        if (!isset(self::TABS[$tab])) {
            $tab = 'audit_activity_log';
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

    /** GET /admin/logs/api/{table}?since=&until=&teacher_id=&actor=&role=&action=&limit= */
    public function apiQuery(Request $req, array $params): Response
    {
        if ($g = $this->guard(true)) {
            return $g;
        }
        $table = (string)($params['table'] ?? '');
        if (!isset(self::TABS[$table])) {
            return Response::json(['error' => 'invalid_table'], 400);
        }

        // Una tabella che manca e' un guasto di installazione, non un filtro
        // troppo stretto: il client deve poterlo distinguere.
        if (!$this->tableExists($table)) {
            return Response::json([
                'ok'      => true,
                'table'   => $table,
                'count'   => 0,
                'rows'    => [],
                'warning' => 'table_missing',
                'message' => "La tabella `$table` non esiste su questa istanza: "
                           . 'nessun evento e\' stato registrato. Esegui `php tools/migrate.php`.',
            ]);
        }

        $limit  = max(1, min(500, (int)($req->query['limit']  ?? 100)));
        $since  = trim((string)($req->query['since']  ?? ''));
        $until  = trim((string)($req->query['until']  ?? ''));
        $tid    = (int)($req->query['teacher_id'] ?? 0);
        $actor  = (int)($req->query['actor']  ?? 0);
        $role   = trim((string)($req->query['role']   ?? ''));
        $action = trim((string)($req->query['action'] ?? ''));

        // Ogni tabella ha schema diverso → query custom + colonne unificate
        $rows = match ($table) {
            'audit_activity_log'     => $this->queryActivity($limit, $since, $until, $actor, $role, $action),
            'content_action_log'     => $this->queryContentActions($limit, $since, $until, $tid, $actor),
            'content_versions'       => $this->queryContentVersions($limit, $since, $until, $tid, $actor),
            'privileged_access_log'  => $this->queryPrivilegedAccess($limit, $since, $until, $actor),
            'consent_audit'          => $this->queryConsentAudit($limit, $since, $until, $actor),
            'parent_consents'        => $this->queryParentConsents($limit, $since, $until, $actor),
            'crypto_access_log'      => $this->queryCryptoAccess($limit, $since, $until, $tid, $actor),
            'teacher_recovery_audit' => $this->queryRecoveryAudit($limit, $since, $until, $actor),
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

    /**
     * Registro delle operazioni di tutti i ruoli (migration 098).
     *
     * @return list<array>
     */
    private function queryActivity(int $limit, string $since, string $until, int $actor, string $role, string $action): array
    {
        [$w, $a] = $this->window('occurred_at', $since, $until);
        if ($actor > 0) {
            $w[] = 'actor_user_id = ?';
            $a[] = $actor;
        }
        if ($role !== '') {
            $w[] = 'actor_role = ?';
            $a[] = $role;
        }
        if ($action !== '') {
            $w[] = 'action = ?';
            $a[] = $action;
        }
        // ip_hash e' binario: in JSON ci va esadecimale, altrimenti
        // json_encode fallisce sull'intera risposta.
        $sql = 'SELECT id, occurred_at, actor_user_id, actor_name, actor_role,
                       action, method, path, status, outcome,
                       subject_type, subject_id, details_json,
                       LEFT(HEX(ip_hash), 16) AS ip_hash_short, request_id
                FROM audit_activity_log';
        return $this->run($this->compose($sql, $w, 'occurred_at', $limit), [...$a, $limit]);
    }

    /** @return list<array> */
    private function queryContentActions(int $limit, string $since, string $until, int $tid, int $actor): array
    {
        [$w, $a] = $this->window('occurred_at', $since, $until);
        if ($tid > 0) {
            $w[] = 'teacher_id = ?';
            $a[] = $tid;
        }
        if ($actor > 0) {
            $w[] = 'actor_user_id = ?';
            $a[] = $actor;
        }
        $sql = 'SELECT id, occurred_at, teacher_id, actor_user_id, content_id,
                       content_type, action, details_json,
                       LEFT(HEX(ip_hash), 16) AS ip_hash_short
                FROM content_action_log';
        return $this->run($this->compose($sql, $w, 'occurred_at', $limit), [...$a, $limit]);
    }

    /** @return list<array> */
    private function queryContentVersions(int $limit, string $since, string $until, int $tid, int $actor): array
    {
        [$w, $a] = $this->window('created_at', $since, $until);
        if ($actor > 0) {
            $w[] = 'actor_user_id = ?';
            $a[] = $actor;
        }
        $sql = 'SELECT id, content_id, version, actor_user_id, actor_name,
                       change_summary, created_at,
                       LENGTH(snapshot_json) AS snapshot_size
                FROM content_versions';
        return $this->run($this->compose($sql, $w, 'created_at', $limit), [...$a, $limit]);
    }

    /** @return list<array>  Schema reale: user_id (actor) + created_at + actor_name/role */
    private function queryPrivilegedAccess(int $limit, string $since, string $until, int $actor): array
    {
        [$w, $a] = $this->window('created_at', $since, $until);
        if ($actor > 0) {
            $w[] = 'user_id = ?';
            $a[] = $actor;
        }
        $sql = 'SELECT id, created_at, user_id, actor_name, actor_role, action,
                       resource_type, resource_id, reason, outcome,
                       LEFT(HEX(ip_hash), 16) AS ip_hash_short
                FROM privileged_access_log';
        return $this->run($this->compose($sql, $w, 'created_at', $limit), [...$a, $limit]);
    }

    /**
     * Consensi, compreso quello genitoriale (art. 8). Append-only.
     *
     * @return list<array>
     */
    private function queryConsentAudit(int $limit, string $since, string $until, int $actor): array
    {
        [$w, $a] = $this->window('accessed_at', $since, $until);
        if ($actor > 0) {
            $w[] = 'user_id = ?';
            $a[] = $actor;
        }
        $sql = 'SELECT id, accessed_at, user_id, consent_type, event, text_version,
                       LEFT(HEX(ip_hash), 16) AS ip_hash_short
                FROM consent_audit';
        return $this->run($this->compose($sql, $w, 'accessed_at', $limit), [...$a, $limit]);
    }

    /**
     * Stato corrente delle richieste di consenso ai genitori.
     *
     * Non e' un log ma la tabella di stato: sta qui perche' e' la risposta
     * alla domanda "questo minore ha il consenso, e da quando". L'email del
     * genitore si mostra mascherata: il registro serve a sapere che c'e', non
     * a farne una rubrica.
     *
     * @return list<array>
     */
    private function queryParentConsents(int $limit, string $since, string $until, int $actor): array
    {
        [$w, $a] = $this->window('requested_at', $since, $until);
        if ($actor > 0) {
            $w[] = 'student_user_id = ?';
            $a[] = $actor;
        }
        $sql = "SELECT id, requested_at, student_user_id,
                       CONCAT(LEFT(parent_email, 2), '***@',
                              SUBSTRING_INDEX(parent_email, '@', -1)) AS parent_email_masked,
                       status, confirmed_at, revoked_at, expires_at
                FROM parent_consents";
        return $this->run($this->compose($sql, $w, 'requested_at', $limit), [...$a, $limit]);
    }

    /** @return list<array>  Schema reale: accessor_id (actor) + accessed_at + operation enum */
    private function queryCryptoAccess(int $limit, string $since, string $until, int $tid, int $actor): array
    {
        [$w, $a] = $this->window('accessed_at', $since, $until);
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
        return $this->run($this->compose($sql, $w, 'accessed_at', $limit), [...$a, $limit]);
    }

    /** @return list<array> Uso delle chiavi di recupero docente (migration 098). */
    private function queryRecoveryAudit(int $limit, string $since, string $until, int $actor): array
    {
        [$w, $a] = $this->window('created_at', $since, $until);
        if ($actor > 0) {
            $w[] = 'user_id = ?';
            $a[] = $actor;
        }
        $sql = 'SELECT id, created_at, user_id, action, success, note,
                       LEFT(HEX(ip_hash), 16) AS ip_hash_short
                FROM teacher_recovery_audit';
        return $this->run($this->compose($sql, $w, 'created_at', $limit), [...$a, $limit]);
    }

    /** @return list<array> */
    private function queryCustodyEvents(int $limit, string $since, string $until, int $tid, int $actor): array
    {
        [$w, $a] = $this->window('occurred_at', $since, $until);
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
        return $this->run($this->compose($sql, $w, 'occurred_at', $limit), [...$a, $limit]);
    }

    /**
     * Finestra temporale comune a tutti i tab. Il nome della colonna arriva
     * da questo file, mai dalla query string.
     *
     * @return array{0: list<string>, 1: list<mixed>}
     */
    private function window(string $col, string $since, string $until): array
    {
        $w = [];
        $a = [];
        if ($since !== '') {
            $w[] = "$col >= ?";
            $a[] = $since . ' 00:00:00';
        }
        if ($until !== '') {
            $w[] = "$col <= ?";
            $a[] = $until . ' 23:59:59';
        }
        return [$w, $a];
    }

    /** @param list<string> $where */
    private function compose(string $sql, array $where, string $orderCol, int $limit): string
    {
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        return $sql . " ORDER BY $orderCol DESC, id DESC LIMIT ?";
    }

    /** La tabella esiste su questa istanza? */
    private function tableExists(string $table): bool
    {
        try {
            $stmt = Database::connection()->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $stmt->execute([$table]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            \error_log('[AdminLogsController] tableExists fallita: ' . $e->getMessage());
            return false;
        }
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
