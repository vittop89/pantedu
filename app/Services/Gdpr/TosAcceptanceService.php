<?php

declare(strict_types=1);

namespace App\Services\Gdpr;

use App\Core\Database;
use PDO;

/**
 * Phase 25.P — Service per gestione click-acceptance ToS/AUP multi-tenancy.
 *
 * Pre-requisito per Scenario B/C (estensione pantedu ad altri docenti dello
 * stesso istituto o adozione istituzionale).
 *
 * Vedi:
 *   - database/migrations/056_tos_aup_acceptance.sql
 *   - docs/legal/tos_docente.md
 *   - docs/legal/aup.md
 *   - docs/todo/multitenancy_responsibility_framework.md §3.1
 *
 * Pattern:
 *   - getCurrentVersion()    → versione corrente ToS/AUP (da Config / costanti)
 *   - hasAccepted(uid)       → true se utente ha accettato l'ultima versione
 *   - recordAcceptance(uid)  → INSERT acceptance con metadata IP+UA
 *   - listHistory(uid)       → cronologia accettazioni utente
 *
 * NOTA: questo service NON è ancora integrato nel flusso login.
 * L'integrazione (middleware enforce ToS-required) sarà fatta quando
 * si attiva concretamente Scenario B/C.
 *
 * Usage previsto (middleware):
 *   $svc = new TosAcceptanceService();
 *   if (! $svc->hasAccepted($userId)) {
 *       // redirect a /tos-acceptance form
 *   }
 */
class TosAcceptanceService
{
    public const TOS_VERSION_CURRENT = '1.0';
    public const AUP_VERSION_CURRENT = '1.0';

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    /**
     * Versione ToS corrente (immutable constant per release).
     */
    public function getCurrentTosVersion(): string
    {
        return self::TOS_VERSION_CURRENT;
    }

    /**
     * Versione AUP corrente.
     */
    public function getCurrentAupVersion(): string
    {
        return self::AUP_VERSION_CURRENT;
    }

    /**
     * Ritorna true se l'utente ha accettato la versione corrente di ToS+AUP.
     */
    public function hasAccepted(int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM user_tos_acceptance '
            . 'WHERE user_id = :uid '
            . 'AND tos_version = :tos_v '
            . 'AND aup_version = :aup_v'
        );
        $stmt->execute([
            ':uid' => $userId,
            ':tos_v' => self::TOS_VERSION_CURRENT,
            ':aup_v' => self::AUP_VERSION_CURRENT,
        ]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Registra accettazione ToS+AUP da parte dell'utente.
     * Idempotent: se già presente, no-op.
     *
     * @param int $userId
     * @param string $ip IPv4/IPv6 da cui è stata fatta l'accettazione
     * @param string|null $userAgent
     * @return bool true se inserita, false se già presente
     */
    public function recordAcceptance(int $userId, string $ip, ?string $userAgent = null): bool
    {
        if ($this->hasAccepted($userId)) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO user_tos_acceptance '
            . '(user_id, tos_version, aup_version, accepted_at, accepted_ip, user_agent) '
            . 'VALUES (:uid, :tos_v, :aup_v, NOW(), :ip, :ua)'
        );
        $stmt->execute([
            ':uid' => $userId,
            ':tos_v' => self::TOS_VERSION_CURRENT,
            ':aup_v' => self::AUP_VERSION_CURRENT,
            ':ip' => substr($ip, 0, 45),
            ':ua' => $userAgent !== null ? substr($userAgent, 0, 512) : null,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Cronologia delle accettazioni di un utente (per audit).
     *
     * @return list<array{tos_version: string, aup_version: string, accepted_at: string, accepted_ip: string, user_agent: ?string}>
     */
    public function listHistory(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT tos_version, aup_version, accepted_at, accepted_ip, user_agent '
            . 'FROM user_tos_acceptance '
            . 'WHERE user_id = :uid '
            . 'ORDER BY accepted_at DESC'
        );
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Statistiche aggregate (per admin) — quanti utenti hanno accettato
     * l'ultima versione, quanti ancora no.
     *
     * @return array{total_users: int, accepted: int, pending: int}
     */
    public function aggregateStats(): array
    {
        $total = (int)$this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $accepted = (int)$this->pdo->query(
            'SELECT COUNT(DISTINCT user_id) FROM user_tos_acceptance '
            . 'WHERE tos_version = ' . $this->pdo->quote(self::TOS_VERSION_CURRENT)
        )->fetchColumn();

        return [
            'total_users' => $total,
            'accepted' => $accepted,
            'pending' => max(0, $total - $accepted),
        ];
    }
}
