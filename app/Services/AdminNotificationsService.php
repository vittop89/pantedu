<?php

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use PDO;

/**
 * Aggrega counters e badge per la dashboard admin (Phase 13).
 *
 * Restituisce contatori "actionable" per:
 *   - pending_registrations  → utenti in attesa di approvazione
 *   - blocked_credentials    → credenziali bloccate per riuso anomalo
 *   - blocked_ips            → IP bloccati per accessi sospetti
 *   - failed_logins_24h      → login falliti ultime 24h (da access_log.json)
 *   - new_teacher_content    → content teacher creati ultime 24h (DB)
 *
 * Used by:
 *   - GET /api/admin/notifications → polled by sidebar banner badge
 *   - GET /admin/dashboard          → rendered as widget tiles
 */
final class AdminNotificationsService
{
    public function __construct(
        private readonly string $registrationsPath,
        private readonly string $accessLogPath,
        private readonly string $blockedCredsPath,
        private readonly string $blockedIpsPath,
    ) {
    }

    public static function default(): self
    {
        $base = (string)Config::get('app.paths.base', dirname(__DIR__, 2));
        return new self(
            registrationsPath: (string)(Config::get('auth.paths.registrations') ?? $base . '/storage/data/registrations.json'),
            accessLogPath:     $base . '/log/data/access_log.json',
            blockedCredsPath:  $base . '/log/data/blocked_credentials.json',
            blockedIpsPath:    $base . '/log/data/blocked_ips.json',
        );
    }

    public function summary(?int $instituteId = null): array
    {
        $pending  = $this->pendingRegistrations();
        $blocCred = $this->countList($this->blockedCredsPath);
        $blocIps  = $this->countList($this->blockedIpsPath);
        $failed   = $this->failedLogins24h();
        $newCnt   = $this->newTeacherContent24h($instituteId);
        $takedown = $this->pendingTakedowns();
        $tosOld   = $this->oldTosUsers();
        $anomalies = AnomalyDetectionService::default()->summary();

        // Counter "actionable": elementi che richiedono attenzione admin.
        $total = $pending + $failed + (int)$anomalies['active'] + $takedown;

        return [
            'total'                  => $total,
            'pending_registrations'  => $pending,
            'blocked_credentials'    => $blocCred,
            'blocked_ips'            => $blocIps,
            'failed_logins_24h'      => $failed,
            'new_teacher_content_24h' => $newCnt,
            'pending_takedowns'      => $takedown,
            'tos_outdated_users'     => $tosOld,
            'anomalies_total'        => (int)$anomalies['total'],
            'anomalies_active'       => (int)$anomalies['active'],
            'excessive_access'       => (int)$anomalies['excessive_access'],
            'credential_sharing'     => (int)$anomalies['credential_sharing'],
            'generated_at'           => date('c'),
        ];
    }

    /**
     * Phase 25.Q — segnalazioni Notice & Takedown pendenti
     * (status='new' o 'under_review'). Visibili a super-admin.
     */
    private function pendingTakedowns(): int
    {
        if (!Config::get('database.enabled') || !Database::isAvailable()) {
            return 0;
        }
        try {
            $stmt = Database::connection()->query(
                "SELECT COUNT(*) FROM takedown_requests WHERE status IN ('new', 'under_review')"
            );
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $_) {
            return 0;
        }
    }

    /**
     * Phase 25.Q — utenti teacher/admin che NON hanno accettato la versione
     * corrente del ToS (no row in user_tos_acceptance per la version attiva).
     * Versione attiva letta da config `tos.current_version` (default '1.0').
     */
    private function oldTosUsers(): int
    {
        if (!Config::get('database.enabled') || !Database::isAvailable()) {
            return 0;
        }
        $currentVersion = \App\Services\Gdpr\TosAcceptanceService::TOS_VERSION_CURRENT;
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM users u
                 WHERE u.role IN ('teacher','admin')
                   AND NOT EXISTS (
                       SELECT 1 FROM user_tos_acceptance a
                       WHERE a.user_id = u.id AND a.tos_version = :v
                   )"
            );
            $stmt->execute([':v' => $currentVersion]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $_) {
            return 0;
        }
    }

    private function pendingRegistrations(): int
    {
        $data = $this->readJson($this->registrationsPath);
        return is_array($data['pending'] ?? null) ? count($data['pending']) : 0;
    }

    private function countList(string $path): int
    {
        $data = $this->readJson($path);
        if (!is_array($data)) {
            return 0;
        }
        return count($data);
    }

    private function failedLogins24h(): int
    {
        $log = $this->readJson($this->accessLogPath);
        if (!is_array($log)) {
            return 0;
        }
        $cutoff = time() - 86400;
        $n = 0;
        foreach ($log as $row) {
            $t = strtotime((string)($row['timestamp'] ?? ''));
            if ($t < $cutoff) {
                continue;
            }
            $action = (string)($row['action_type'] ?? '');
            if (in_array($action, ['login_failed', 'login_blocked', 'auth_failed'], true)) {
                $n++;
            }
        }
        return $n;
    }

    private function newTeacherContent24h(?int $instituteId = null): int
    {
        if (!Config::get('database.enabled') || !Database::isAvailable()) {
            return 0;
        }
        try {
            if ($instituteId !== null) {
                $stmt = Database::connection()->prepare(
                    "SELECT COUNT(*) FROM teacher_content
                     WHERE created_at > NOW() - INTERVAL 1 DAY
                       AND teacher_id IN (SELECT user_id FROM teacher_institutes WHERE institute_id = :iid)"
                );
                $stmt->execute([':iid' => $instituteId]);
                return (int)$stmt->fetchColumn();
            }
            $stmt = Database::connection()->query(
                "SELECT COUNT(*) FROM teacher_content WHERE created_at > NOW() - INTERVAL 1 DAY"
            );
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $_) {
            return 0;
        }
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
