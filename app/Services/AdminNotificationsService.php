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
        // Questi quattro file li scrivono altri, e il percorso lo si chiede a
        // loro: `AccessLogger` per il registro, `app/Config/auth.php` (cioè
        // `WafSecurityRepository`, che ci scrive, e `BlockList`, che ci legge)
        // per i due elenchi dei blocchi. Prima se li costruiva da sé partendo
        // da `app.paths.base`, che è sempre la radice del repository: nel
        // container è l'immagine, e il riepilogo per l'amministratore leggeva
        // quattro file inesistenti e rispondeva «nessuna novità». Il registro
        // degli accessi, per giunta, non stava dove lo si cercava nemmeno in
        // sviluppo (misurato il 20/9/2026: cinque accessi falliti, il
        // riepilogo ne diceva zero).
        $base = \App\Support\PercorsiDati::base(dirname(__DIR__, 2));
        return new self(
            registrationsPath: (string)(Config::get('auth.paths.registrations') ?? $base . '/storage/data/registrations.json'),
            accessLogPath:     \App\Core\AccessLogger::percorsoDelRegistro($base),
            blockedCredsPath:  (string)(Config::get('auth.paths.blocked_credentials') ?? $base . '/storage/security/blocked_credentials.json'),
            blockedIpsPath:    (string)(Config::get('auth.paths.blocked_ips') ?? $base . '/storage/security/blocked_ips.json'),
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
        // Versione efficace ORA: durante la finestra di preavviso la nuova
        // versione non è ancora vincolante e non genera "pendenti".
        $effective = (new \App\Services\Gdpr\TosAcceptanceService())->effectiveVersions();
        try {
            $pdo = Database::connection();
            // ADR-040 — chi deve accettare i termini: `admin` non esisteva, e il
            // super-amministratore il middleware lo esenta.
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM users u
                 WHERE u.role IN ('teacher','administrator','institute_admin') AND u.is_super_admin = 0
                   AND NOT EXISTS (
                       SELECT 1 FROM user_tos_acceptance a
                       WHERE a.user_id = u.id
                         AND a.tos_version = :tv AND a.aup_version = :av
                   )"
            );
            $stmt->execute([':tv' => $effective['tos'], ':av' => $effective['aup']]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $_) {
            return 0;
        }
    }

    /**
     * Le domande da decidere: solo quelle nel termine (24/9/2026). Contava
     * anche le scadute, finché il giro notturno non le cancellava, e il
     * riepilogo chiedeva di decidere domande che l'approvazione rifiuta.
     */
    private function pendingRegistrations(): int
    {
        $data = $this->readJson($this->registrationsPath);
        return is_array($data)
            ? count(\App\Services\Gdpr\ConservazioneDelleIscrizioni::domandeInTermine($data))
            : 0;
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
            // `action`, non `action_type`: `AccessLogger` scrive la prima, e
            // in `app/` nessuno ha mai scritto la seconda (misurato il
            // 20/9/2026 — l'unica occorrenza era questa riga). E il valore
            // porta il motivo attaccato — `login_failed:bad_password`, da
            // `AuthController::login()` — quindi si confronta la radice: con
            // l'uguaglianza esatta questo contatore non poteva che valere 0,
            // e il riepilogo diceva «nessun accesso fallito» qualunque cosa
            // stesse succedendo. `action_type` resta letta per i registri
            // vecchi.
            $azione = (string)($row['action'] ?? $row['action_type'] ?? '');
            $radice = explode(':', $azione, 2)[0];
            if (in_array($radice, ['login_failed', 'login_blocked', 'auth_failed'], true)) {
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
