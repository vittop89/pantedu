<?php

declare(strict_types=1);

namespace App\Services\Gdpr;

use App\Core\Config;
use App\Core\Database;
use DateTimeImmutable;
use PDO;
use PDOException;
use Throwable;

/**
 * Phase 25.P — click-acceptance ToS/AUP con versioning e preavviso.
 *
 * Vedi:
 *   - database/migrations/056_tos_aup_acceptance.sql
 *   - database/migrations/094_legal_document_versions.sql
 *   - docs/legal/tos_docente.md §8 — preavviso 30 giorni
 *   - docs/legal/aup.md §6 — accettazione
 *
 * Modello temporale
 * -----------------
 * Una versione ha due date distinte: `published_at` (quando il documento
 * è stato reso disponibile) ed `effective_from` (quando diventa vincolante).
 * La distanza fra le due è il preavviso. In quella finestra:
 *
 *   - la versione **efficace** resta la precedente: nessuno viene bloccato;
 *   - la versione **pendente** è mostrata in banner e notificata via email;
 *   - l'utente PUÒ accettare in anticipo (recordAcceptance su quella pendente).
 *
 * Superato `effective_from`, la versione diventa efficace e il middleware
 * blocca chi non l'ha accettata.
 *
 * Le costanti sotto restano solo come fallback per installazioni in cui la
 * migration 094 non è ancora girata o il DB non è raggiungibile: in quel
 * caso il servizio degrada al comportamento pre-094 invece di esplodere.
 */
class TosAcceptanceService
{
    public const TOS_VERSION_CURRENT = '1.0';
    public const AUP_VERSION_CURRENT = '1.0';

    private ?PDO $pdo;

    /** @var array{tos: string, aup: string}|null Cache per-request. */
    private ?array $effectiveCache = null;

    /** @var list<array<string,mixed>>|null Cache per-request. */
    private ?array $pendingCache = null;

    /** @var array<string,string>|null Cache per-request di requiredFrom(). */
    private ?array $requiredFromCache = null;

    /**
     * effective_from delle versioni vincolanti, popolata da effectiveVersions().
     * Vuota se il registro non è disponibile (fallback alle costanti).
     *
     * @var array<string, string>
     */
    private array $effectiveFrom = [];

    public function __construct(?PDO $pdo = null)
    {
        if ($pdo !== null) {
            $this->pdo = $pdo;
            return;
        }
        // Il middleware gira su OGNI request: un DB assente non deve
        // trasformarsi in un 500 su tutto il sito.
        try {
            $this->pdo = Database::connection();
        } catch (Throwable) {
            $this->pdo = null;
        }
    }

    // -----------------------------------------------------------------
    // Versioni
    // -----------------------------------------------------------------

    /**
     * Versioni vincolanti in questo istante (la più recente con
     * effective_from già passata, per ciascun documento).
     *
     * @return array{tos: string, aup: string}
     */
    public function effectiveVersions(): array
    {
        if ($this->effectiveCache !== null) {
            return $this->effectiveCache;
        }
        $out = [
            'tos' => self::TOS_VERSION_CURRENT,
            'aup' => self::AUP_VERSION_CURRENT,
        ];
        if ($this->pdo !== null) {
            try {
                $stmt = $this->pdo->query(
                    "SELECT doc_type, version, effective_from FROM legal_document_versions v
                     WHERE effective_from <= CURRENT_TIMESTAMP
                       AND effective_from = (
                           SELECT MAX(effective_from) FROM legal_document_versions
                           WHERE doc_type = v.doc_type AND effective_from <= CURRENT_TIMESTAMP
                       )"
                );
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $type = (string)($row['doc_type'] ?? '');
                    if ($type === 'tos' || $type === 'aup') {
                        $out[$type] = (string)$row['version'];
                        $this->effectiveFrom[$type] = (string)$row['effective_from'];
                    }
                }
            } catch (Throwable $e) {
                error_log('[TosAcceptanceService] effectiveVersions fallback: ' . $e->getMessage());
            }
        }
        return $this->effectiveCache = $out;
    }

    /**
     * Versioni vincolanti a una data passata. Serve a registrare
     * un'accettazione avvenuta prima: se nel frattempo i documenti sono
     * cambiati, scrivere la versione di oggi attribuirebbe all'utente
     * l'accettazione di un testo che non ha mai visto.
     *
     * @return array{tos: string, aup: string}
     */
    public function effectiveVersionsAt(string $when): array
    {
        $out = [
            'tos' => self::TOS_VERSION_CURRENT,
            'aup' => self::AUP_VERSION_CURRENT,
        ];
        if ($this->pdo === null) {
            return $out;
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT doc_type, version FROM legal_document_versions v
                 WHERE effective_from <= :w
                   AND effective_from = (
                       SELECT MAX(effective_from) FROM legal_document_versions
                       WHERE doc_type = v.doc_type AND effective_from <= :w2
                   )'
            );
            $stmt->execute([':w' => $when, ':w2' => $when]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $type = (string)($row['doc_type'] ?? '');
                if ($type === 'tos' || $type === 'aup') {
                    $out[$type] = (string)$row['version'];
                }
            }
        } catch (Throwable $e) {
            error_log('[TosAcceptanceService] effectiveVersionsAt fallback: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * `effective_from` dell'ultima versione **sostanziale** già in vigore, per
     * documento — cioè la soglia rispetto a cui si misura l'accettazione.
     *
     * Non coincide con la versione vigente. Una correzione non sostanziale
     * (un refuso, un recapito che cambia) alza il numero di versione mostrato,
     * ma i documenti promettono che solo le modifiche **sostanziali**
     * richiedono nuova accettazione: farla ripetere per un cambio di indirizzo
     * email significherebbe murare fuori tutti proprio nel caso che si era
     * dichiarato innocuo — peggio del percorso sostanziale, che almeno dà 30
     * giorni di preavviso.
     *
     * @return array<string, string> doc_type => effective_from
     */
    private function requiredFrom(): array
    {
        if ($this->requiredFromCache !== null) {
            return $this->requiredFromCache;
        }
        $out = [];
        if ($this->pdo === null) {
            return $this->requiredFromCache = $out;
        }
        try {
            $stmt = $this->pdo->query(
                "SELECT doc_type, MAX(effective_from) AS eff
                 FROM legal_document_versions
                 WHERE effective_from <= CURRENT_TIMESTAMP AND is_substantial = 1
                 GROUP BY doc_type"
            );
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $out[(string)$row['doc_type']] = (string)$row['eff'];
            }
        } catch (Throwable $e) {
            error_log('[TosAcceptanceService] requiredFrom failed: ' . $e->getMessage());
        }
        return $this->requiredFromCache = $out;
    }

    /** Versione ToS vincolante ora. */
    public function getCurrentTosVersion(): string
    {
        return $this->effectiveVersions()['tos'];
    }

    /** Versione AUP vincolante ora. */
    public function getCurrentAupVersion(): string
    {
        return $this->effectiveVersions()['aup'];
    }

    /**
     * Versioni pubblicate ma non ancora vincolanti — la finestra di preavviso.
     *
     * @return list<array{id:int, doc_type:string, version:string, published_at:string,
     *                    effective_from:string, is_substantial:bool, summary:?string,
     *                    days_remaining:int}>
     */
    public function pendingVersions(): array
    {
        if ($this->pendingCache !== null) {
            return $this->pendingCache;
        }
        if ($this->pdo === null) {
            return $this->pendingCache = [];
        }
        try {
            $stmt = $this->pdo->query(
                'SELECT id, doc_type, version, published_at, effective_from,
                        is_substantial, summary
                 FROM legal_document_versions
                 WHERE effective_from > CURRENT_TIMESTAMP
                 ORDER BY effective_from ASC, doc_type ASC'
            );
            $now = new DateTimeImmutable('now');
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $eff = new DateTimeImmutable((string)$row['effective_from']);
                $out[] = [
                    'id'             => (int)$row['id'],
                    'doc_type'       => (string)$row['doc_type'],
                    'version'        => (string)$row['version'],
                    'published_at'   => (string)$row['published_at'],
                    'effective_from' => (string)$row['effective_from'],
                    'is_substantial' => (bool)$row['is_substantial'],
                    'summary'        => $row['summary'] !== null ? (string)$row['summary'] : null,
                    'days_remaining' => (int)$now->diff($eff)->days,
                ];
            }
            return $this->pendingCache = $out;
        } catch (Throwable $e) {
            error_log('[TosAcceptanceService] pendingVersions failed: ' . $e->getMessage());
            return $this->pendingCache = [];
        }
    }

    /**
     * Preavviso da mostrare a un utente, o null se non c'è nulla in arrivo
     * (o se ha già accettato in anticipo tutto il pacchetto pendente).
     *
     * @return array{versions: list<array<string,mixed>>, days_remaining: int,
     *               effective_from: string}|null
     */
    public function noticeFor(int $userId): ?array
    {
        $pending = array_values(array_filter(
            $this->pendingVersions(),
            static fn(array $v) => $v['is_substantial']
        ));
        if ($pending === []) {
            return null;
        }
        // Chi ha già accettato in anticipo non deve vedere il banner.
        if ($this->hasAcceptedTarget($userId, $this->targetVersions())) {
            return null;
        }
        $soonest = $pending[0];
        return [
            'versions'       => $pending,
            'days_remaining' => $soonest['days_remaining'],
            'effective_from' => $soonest['effective_from'],
        ];
    }

    /**
     * Versioni che il form di accettazione propone: le pendenti se ci sono
     * (accettazione anticipata), altrimenti le efficaci.
     *
     * @return array{tos: string, aup: string}
     */
    public function targetVersions(): array
    {
        $target = $this->effectiveVersions();
        foreach ($this->pendingVersions() as $v) {
            $type = $v['doc_type'];
            if ($type === 'tos' || $type === 'aup') {
                $target[$type] = $v['version'];
            }
        }
        return $target;
    }

    // -----------------------------------------------------------------
    // Accettazione
    // -----------------------------------------------------------------

    /**
     * True se l'utente ha accettato la coppia di versioni vincolante ora.
     * È questo il check del gate: le versioni pendenti non bloccano nessuno.
     */
    public function hasAccepted(int $userId): bool
    {
        $effective = $this->effectiveVersions();
        // La soglia è l'ultima versione SOSTANZIALE in vigore, non l'ultima in
        // assoluto: vedi requiredFrom(). Se non ce n'è (registro senza versioni
        // sostanziali) si ricade sull'ultima vigente.
        $required = $this->requiredFrom() + $this->effectiveFrom;

        // Chi ha accettato in anticipo la versione in arrivo ha già accettato,
        // a maggior ragione, quella in vigore: mandarlo al gate perché la riga
        // registrata non combacia alla lettera sarebbe un blocco per nulla.
        // Il confronto è su effective_from, mai sulla stringa di versione.
        if (isset($required['tos'], $required['aup']) && $this->pdo !== null) {
            try {
                $stmt = $this->pdo->prepare(
                    'SELECT COUNT(*) FROM user_tos_acceptance a
                     JOIN legal_document_versions t
                       ON t.doc_type = \'tos\' AND t.version = a.tos_version
                     JOIN legal_document_versions p
                       ON p.doc_type = \'aup\' AND p.version = a.aup_version
                     WHERE a.user_id = :uid
                       AND t.effective_from >= :tos_eff
                       AND p.effective_from >= :aup_eff'
                );
                $stmt->execute([
                    ':uid'     => $userId,
                    ':tos_eff' => $required['tos'],
                    ':aup_eff' => $required['aup'],
                ]);
                return (int)$stmt->fetchColumn() > 0;
            } catch (Throwable $e) {
                error_log('[TosAcceptanceService] hasAccepted (ordered) failed: ' . $e->getMessage());
                return true;
            }
        }

        // Registro non disponibile: confronto esatto, comportamento pre-094.
        return $this->hasAcceptedTarget($userId, $effective);
    }

    /**
     * @param array{tos: string, aup: string} $versions
     */
    private function hasAcceptedTarget(int $userId, array $versions): bool
    {
        if ($this->pdo === null) {
            // Fail-open: senza DB non possiamo dimostrare che NON ha accettato,
            // e murare l'intero sito per un problema di infrastruttura è peggio
            // del rischio di lasciar passare una sessione.
            return true;
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM user_tos_acceptance '
                . 'WHERE user_id = :uid AND tos_version = :tos_v AND aup_version = :aup_v'
            );
            $stmt->execute([
                ':uid'   => $userId,
                ':tos_v' => $versions['tos'],
                ':aup_v' => $versions['aup'],
            ]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            error_log('[TosAcceptanceService] hasAccepted failed: ' . $e->getMessage());
            return true;
        }
    }

    /**
     * Registra l'accettazione delle versioni target (pendenti se presenti,
     * altrimenti efficaci). Idempotente.
     *
     * @return bool true se è stata inserita una nuova riga
     */
    public function recordAcceptance(int $userId, string $ip, ?string $userAgent = null): bool
    {
        if ($this->pdo === null) {
            return false;
        }
        $versions = $this->targetVersions();
        if ($this->hasAcceptedTarget($userId, $versions)) {
            return false;
        }

        // INSERT semplice, non INSERT IGNORE: era proprio l'IGNORE a nascondere
        // la collisione di chiave che mandava in loop l'accettazione di un
        // aggiornamento della sola AUP. Se una riga collide vogliamo saperlo.
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_tos_acceptance '
            . '(user_id, tos_version, aup_version, accepted_at, accepted_ip, user_agent) '
            . 'VALUES (:uid, :tos_v, :aup_v, CURRENT_TIMESTAMP, :ip, :ua)'
        );
        try {
            $stmt->execute([
                ':uid'   => $userId,
                ':tos_v' => $versions['tos'],
                ':aup_v' => $versions['aup'],
                ':ip'    => substr($ip, 0, 45),
                ':ua'    => $userAgent !== null ? substr($userAgent, 0, 512) : null,
            ]);
        } catch (PDOException $e) {
            // Doppio submit o due tab aperte: la riga c'è già ed è quello che
            // conta. Qualsiasi altro errore va invece propagato, perché
            // significa che l'accettazione NON è stata registrata.
            if (self::isDuplicateKey($e)) {
                return false;
            }
            throw $e;
        }
        return $stmt->rowCount() > 0;
    }

    /**
     * Registra un'accettazione avvenuta in passato, con le versioni vigenti
     * a quella data. Usato dal flusso di registrazione: la spunta è raccolta
     * al submit, ma l'utente esiste in `users` solo dopo l'approvazione, e
     * fino ad allora non c'è una FK a cui agganciare la riga.
     *
     * @param string $when timestamp 'Y-m-d H:i:s' dell'accettazione
     */
    public function recordHistoricAcceptance(
        int $userId,
        string $when,
        string $ip,
        ?string $userAgent = null,
    ): bool {
        if ($this->pdo === null) {
            return false;
        }
        $versions = $this->effectiveVersionsAt($when);
        if ($this->hasAcceptedTarget($userId, $versions)) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_tos_acceptance '
            . '(user_id, tos_version, aup_version, accepted_at, accepted_ip, user_agent) '
            . 'VALUES (:uid, :tos_v, :aup_v, :when, :ip, :ua)'
        );
        try {
            $stmt->execute([
                ':uid'   => $userId,
                ':tos_v' => $versions['tos'],
                ':aup_v' => $versions['aup'],
                ':when'  => $when,
                ':ip'    => substr($ip, 0, 45),
                ':ua'    => $userAgent !== null ? substr($userAgent, 0, 512) : null,
            ]);
        } catch (PDOException $e) {
            if (self::isDuplicateKey($e)) {
                return false;
            }
            throw $e;
        }
        return $stmt->rowCount() > 0;
    }

    /**
     * Violazione di vincolo di integrità: la riga esiste già.
     * SQLSTATE 23000 è comune a MySQL/MariaDB e SQLite.
     */
    private static function isDuplicateKey(PDOException $e): bool
    {
        return (string)$e->getCode() === '23000';
    }

    /**
     * Cronologia delle accettazioni di un utente (per audit).
     *
     * @return list<array{tos_version: string, aup_version: string, accepted_at: string, accepted_ip: string, user_agent: ?string}>
     */
    public function listHistory(int $userId): array
    {
        if ($this->pdo === null) {
            return [];
        }
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
     * Statistiche aggregate (per admin) sulla versione efficace.
     *
     * @return array{total_users: int, accepted: int, pending: int}
     */
    public function aggregateStats(): array
    {
        if ($this->pdo === null) {
            return ['total_users' => 0, 'accepted' => 0, 'pending' => 0];
        }
        $versions = $this->effectiveVersions();
        $total = (int)$this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT user_id) FROM user_tos_acceptance '
            . 'WHERE tos_version = :tos_v AND aup_version = :aup_v'
        );
        $stmt->execute([':tos_v' => $versions['tos'], ':aup_v' => $versions['aup']]);
        $accepted = (int)$stmt->fetchColumn();

        return [
            'total_users' => $total,
            'accepted'    => $accepted,
            'pending'     => max(0, $total - $accepted),
        ];
    }

    /** Preavviso minimo in giorni, come da ToS §8 / AUP §6. */
    public static function noticeDays(): int
    {
        return (int)Config::get('multitenancy.legal_notice_days', 30);
    }
}
